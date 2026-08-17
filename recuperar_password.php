<?php
session_start();
include("config/conexion.php");

if (isset($_SESSION["usuario"], $_SESSION["rol"])) {
    $rolSesion = strtolower(trim((string) $_SESSION["rol"]));

    if ($rolSesion === "cliente") {
        header("Location: cliente/index.php");
        exit();
    }

    if (in_array($rolSesion, ["administrador", "recepcionista"], true)) {
        header("Location: dashboard.php");
        exit();
    }
}

function h($texto)
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

/* Recuperación de acceso */
if (empty($_SESSION["csrf_recuperar_password"])) {
    $_SESSION["csrf_recuperar_password"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_recuperar_password"];
$error = "";
$usuarioIngresado = "";
$correoIngresado = "";
$cedulaIngresada = "";

$recuperacionActiva =
    !empty($_SESSION["recuperacion_usuario_id"]) &&
    !empty($_SESSION["recuperacion_expira"]) &&
    (int) $_SESSION["recuperacion_expira"] >= time();

if (!$recuperacionActiva) {
    unset(
        $_SESSION["recuperacion_usuario_id"],
        $_SESSION["recuperacion_usuario"],
        $_SESSION["recuperacion_expira"]
    );
}

$bloqueoHasta = (int) ($_SESSION["recuperacion_bloqueo_hasta"] ?? 0);

if ($bloqueoHasta > 0 && $bloqueoHasta <= time()) {
    $_SESSION["recuperacion_intentos"] = 0;
    $_SESSION["recuperacion_bloqueo_hasta"] = 0;
    $bloqueoHasta = 0;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfRecibido = $_POST["csrf"] ?? "";

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $error = "La solicitud no es válida. Actualiza la página.";
    } elseif (isset($_POST["verificar"])) {
        $usuarioIngresado = trim((string) ($_POST["usuario"] ?? ""));
        $correoIngresado = trim((string) ($_POST["correo"] ?? ""));
        $cedulaIngresada = trim((string) ($_POST["cedula"] ?? ""));

        if ($bloqueoHasta > time()) {
            $minutos = max(
                1,
                (int) ceil(($bloqueoHasta - time()) / 60)
            );

            $error =
                "Se realizaron varios intentos. Intenta nuevamente en " .
                $minutos .
                ($minutos === 1 ? " minuto." : " minutos.");
        } elseif (
            $usuarioIngresado === "" ||
            $correoIngresado === "" ||
            $cedulaIngresada === ""
        ) {
            $error = "Completa todos los datos para continuar.";
        } elseif (!filter_var($correoIngresado, FILTER_VALIDATE_EMAIL)) {
            $error = "Ingresa un correo electrónico válido.";
        } elseif (!preg_match("/^[0-9]{10}$/", $cedulaIngresada)) {
            $error = "La cédula debe tener exactamente 10 números.";
        } else {
            $consulta = mysqli_prepare(
                $conn,
                "SELECT
                    u.id_usuario,
                    u.usuario,
                    u.rol,
                    c.cedula,
                    c.correo
                 FROM usuarios u
                 INNER JOIN clientes c
                    ON c.id_usuario = u.id_usuario
                 WHERE u.usuario = ?
                 LIMIT 1"
            );

            if (!$consulta) {
                $error = "No se pudo procesar la recuperación.";
            } else {
                mysqli_stmt_bind_param(
                    $consulta,
                    "s",
                    $usuarioIngresado
                );

                mysqli_stmt_execute($consulta);

                $resultado = mysqli_stmt_get_result($consulta);
                $datos = mysqli_fetch_assoc($resultado);

                mysqli_stmt_close($consulta);

                $coincide =
                    $datos &&
                    strtolower(trim((string) $datos["rol"])) === "cliente" &&
                    hash_equals(
                        strtolower(trim((string) $datos["correo"])),
                        strtolower($correoIngresado)
                    ) &&
                    hash_equals(
                        trim((string) $datos["cedula"]),
                        $cedulaIngresada
                    );

                if ($coincide) {
                    $_SESSION["recuperacion_usuario_id"] =
                        (int) $datos["id_usuario"];

                    $_SESSION["recuperacion_usuario"] =
                        (string) $datos["usuario"];

                    $_SESSION["recuperacion_expira"] =
                        time() + 600;

                    $_SESSION["recuperacion_intentos"] = 0;
                    $_SESSION["recuperacion_bloqueo_hasta"] = 0;

                    $recuperacionActiva = true;
                } else {
                    $intentos =
                        (int) ($_SESSION["recuperacion_intentos"] ?? 0) + 1;

                    $_SESSION["recuperacion_intentos"] = $intentos;

                    if ($intentos >= 5) {
                        $_SESSION["recuperacion_bloqueo_hasta"] =
                            time() + 600;

                        $_SESSION["recuperacion_intentos"] = 0;

                        $error =
                            "Se realizaron varios intentos. Intenta nuevamente en 10 minutos.";
                    } else {
                        $error =
                            "No pudimos validar los datos ingresados.";
                    }
                }
            }
        }
    } elseif (isset($_POST["cambiar_password"])) {
        $idUsuario =
            (int) ($_SESSION["recuperacion_usuario_id"] ?? 0);

        $expira =
            (int) ($_SESSION["recuperacion_expira"] ?? 0);

        $password =
            (string) ($_POST["password"] ?? "");

        $confirmarPassword =
            (string) ($_POST["confirmar_password"] ?? "");

        if ($idUsuario <= 0 || $expira < time()) {
            unset(
                $_SESSION["recuperacion_usuario_id"],
                $_SESSION["recuperacion_usuario"],
                $_SESSION["recuperacion_expira"]
            );

            $recuperacionActiva = false;
            $error =
                "La verificación venció. Confirma nuevamente tus datos.";
        } elseif (strlen($password) < 8) {
            $error =
                "La nueva contraseña debe tener mínimo 8 caracteres.";
        } elseif ($password !== $confirmarPassword) {
            $error = "Las contraseñas no coinciden.";
        } else {
            $passwordHash =
                password_hash($password, PASSWORD_DEFAULT);

            $actualizar = mysqli_prepare(
                $conn,
                "UPDATE usuarios
                 SET password = ?
                 WHERE id_usuario = ?"
            );

            if (!$actualizar) {
                $error =
                    "No se pudo actualizar la contraseña.";
            } else {
                mysqli_stmt_bind_param(
                    $actualizar,
                    "si",
                    $passwordHash,
                    $idUsuario
                );

                if (mysqli_stmt_execute($actualizar)) {
                    mysqli_stmt_close($actualizar);

                    unset(
                        $_SESSION["recuperacion_usuario_id"],
                        $_SESSION["recuperacion_usuario"],
                        $_SESSION["recuperacion_expira"],
                        $_SESSION["recuperacion_intentos"],
                        $_SESSION["recuperacion_bloqueo_hasta"],
                        $_SESSION["csrf_recuperar_password"]
                    );

                    $_SESSION["mensaje_exito"] =
                        "Tu contraseña fue actualizada. Ya puedes iniciar sesión.";

                    header("Location: login.php");
                    exit();
                }

                mysqli_stmt_close($actualizar);

                $error =
                    "No se pudo actualizar la contraseña.";
            }
        }
    } elseif (isset($_POST["cancelar_verificacion"])) {
        unset(
            $_SESSION["recuperacion_usuario_id"],
            $_SESSION["recuperacion_usuario"],
            $_SESSION["recuperacion_expira"]
        );

        header("Location: recuperar_password.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Recuperar contraseña - Hotel Las 3 Palmeras
    </title>

    <link
        rel="icon"
        type="image/png"
        href="img/logocircular.png?v=3"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --verde: #244a35;
            --verde-oscuro: #173325;
            --verde-claro: #e9f0eb;
            --dorado: #d8b56d;
            --texto: #20231f;
            --texto-suave: #687068;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            opacity: 0;
            transform: translateY(15px);
            background:
                linear-gradient(
                    rgba(17, 45, 30, .68),
                    rgba(17, 45, 30, .68)
                ),
                url("img/hotel.jpg") center/cover fixed;
            color: var(--texto);
            font-family: Arial, Helvetica, sans-serif;
            animation: entradaSuave .55s ease-out forwards;
        }

        @keyframes entradaSuave {
            from {
                opacity: 0;
                transform: translateY(15px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        a {
            text-decoration: none;
        }

        .pagina-recuperacion {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px;
        }

        .recuperacion-contenedor {
            width: min(1050px, 100%);
            min-height: 620px;
            display: grid;
            grid-template-columns: 46% 54%;
            overflow: hidden;
            border-radius: 30px;
            background: white;
            box-shadow: 0 30px 80px rgba(0, 0, 0, .30);
        }

        .panel-info {
            position: relative;
            display: flex;
            align-items: flex-end;
            min-height: 620px;
            padding: 42px;
            background:
                linear-gradient(
                    rgba(13, 37, 24, .25),
                    rgba(13, 37, 24, .82)
                ),
                url("img/hotel.jpg") center/cover;
            color: white;
        }

        .panel-info-contenido {
            position: relative;
            z-index: 2;
        }

        .panel-logo {
            width: 68px;
            height: 68px;
            margin-bottom: 24px;
            object-fit: contain;
        }

        .panel-etiqueta {
            margin-bottom: 12px;
            color: #f0d99f;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 2px;
        }

        .panel-info h1 {
            margin-bottom: 16px;
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(2.4rem, 5vw, 4rem);
            line-height: 1.02;
        }

        .panel-info p {
            max-width: 420px;
            margin: 0;
            color: rgba(255, 255, 255, .82);
            font-size: 14px;
            line-height: 1.7;
        }

        .panel-formulario {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 52px 60px;
        }

        .form-etiqueta {
            margin-bottom: 9px;
            color: #9b7739;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 2px;
        }

        .form-titulo {
            margin-bottom: 10px;
            color: var(--verde-oscuro);
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(2rem, 4vw, 3rem);
        }

        .form-texto {
            margin-bottom: 26px;
            color: var(--texto-suave);
            font-size: 13px;
            line-height: 1.65;
        }

        .alerta-error {
            display: flex;
            gap: 9px;
            margin-bottom: 20px;
            padding: 13px 15px;
            border: 1px solid #efcaca;
            border-radius: 10px;
            background: #fff2f2;
            color: #9b3131;
            font-size: 12px;
        }

        .paso-verificado {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 22px;
            padding: 13px 15px;
            border: 1px solid #b9dfc3;
            border-radius: 10px;
            background: #edf9f0;
            color: #24633a;
            font-size: 12px;
        }

        .campo {
            margin-bottom: 16px;
        }

        .campo label {
            display: block;
            margin-bottom: 7px;
            color: #38423b;
            font-size: 12px;
            font-weight: 900;
        }

        .campo-icono {
            position: relative;
        }

        .campo-icono > i {
            position: absolute;
            top: 50%;
            left: 16px;
            transform: translateY(-50%);
            color: #7d877f;
            font-size: 16px;
        }

        .campo-icono .form-control {
            min-height: 51px;
            padding: 12px 46px;
            border: 1px solid #dde2dd;
            border-radius: 999px;
            background: #f4f6f4;
            font-size: 13px;
        }

        .campo-icono .form-control:focus {
            border-color: var(--verde);
            background: white;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, .10);
        }

        .mostrar-password {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #687068;
            font-size: 17px;
        }

        .btn-principal {
            width: 100%;
            min-height: 51px;
            margin-top: 6px;
            border: 1px solid var(--verde);
            border-radius: 999px;
            background: var(--verde);
            color: white;
            font-size: 13px;
            font-weight: 900;
        }

        .btn-principal:hover {
            background: var(--verde-oscuro);
            color: white;
        }

        .acciones-secundarias {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 19px;
        }

        .enlace-volver,
        .btn-cambiar-datos {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 0;
            background: transparent;
            color: var(--verde);
            font-size: 11px;
            font-weight: 900;
            text-decoration: none;
        }

        .ayuda-personal {
            margin-top: 24px;
            padding: 13px 15px;
            border-radius: 9px;
            background: #f7f3e9;
            color: #705b2e;
            font-size: 10px;
            line-height: 1.55;
        }

        @media (max-width: 900px) {
            .pagina-recuperacion {
                padding: 22px;
            }

            .recuperacion-contenedor {
                max-width: 620px;
                grid-template-columns: 1fr;
            }

            .panel-info {
                min-height: 285px;
                padding: 30px;
            }

            .panel-formulario {
                padding: 42px 45px;
            }
        }

        @media (max-width: 575px) {
            .pagina-recuperacion {
                display: block;
                padding: 0;
                background: white;
            }

            .recuperacion-contenedor {
                width: 100%;
                min-height: 100vh;
                border-radius: 0;
                box-shadow: none;
            }

            .panel-info {
                min-height: 225px;
                padding: 24px;
            }

            .panel-logo {
                width: 54px;
                height: 54px;
                margin-bottom: 14px;
            }

            .panel-info p {
                display: none;
            }

            .panel-formulario {
                padding: 34px 24px 42px;
            }

            .acciones-secundarias {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<main class="pagina-recuperacion">

    <section class="recuperacion-contenedor">

        <div class="panel-info">

            <div class="panel-info-contenido">

                <img
                    src="img/logo.png"
                    alt="Hotel Las 3 Palmeras"
                    class="panel-logo"
                >

                <div class="panel-etiqueta">
                    HOTEL LAS 3 PALMERAS
                </div>

                <h1>Recupera tu acceso</h1>

                <p>
                    Confirma los datos asociados a tu cuenta de cliente
                    y establece una nueva contraseña para volver a ingresar.
                </p>

            </div>

        </div>

        <div class="panel-formulario">

            <?php if (!$recuperacionActiva) { ?>

                <div class="form-etiqueta">
                    VERIFICACIÓN DE IDENTIDAD
                </div>

                <h2 class="form-titulo">
                    ¿Olvidaste tu contraseña?
                </h2>

                <p class="form-texto">
                    Ingresa los datos con los que estás registrado.
                    Deben coincidir con tu cuenta de huésped.
                </p>

                <?php if ($error !== "") { ?>

                    <div class="alerta-error">
                        <i class="bi bi-exclamation-circle"></i>
                        <span><?php echo h($error); ?></span>
                    </div>

                <?php } ?>

                <form method="POST" autocomplete="off">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?php echo h($csrf); ?>"
                    >

                    <div class="campo">

                        <label for="usuario">Usuario</label>

                        <div class="campo-icono">

                            <i class="bi bi-person"></i>

                            <input
                                type="text"
                                id="usuario"
                                name="usuario"
                                class="form-control"
                                maxlength="30"
                                value="<?php echo h($usuarioIngresado); ?>"
                                placeholder="Tu nombre de usuario"
                                autocomplete="username"
                                required
                                autofocus
                            >

                        </div>

                    </div>

                    <div class="campo">

                        <label for="correo">Correo registrado</label>

                        <div class="campo-icono">

                            <i class="bi bi-envelope"></i>

                            <input
                                type="email"
                                id="correo"
                                name="correo"
                                class="form-control"
                                maxlength="120"
                                value="<?php echo h($correoIngresado); ?>"
                                placeholder="correo@ejemplo.com"
                                autocomplete="email"
                                required
                            >

                        </div>

                    </div>

                    <div class="campo">

                        <label for="cedula">Cédula</label>

                        <div class="campo-icono">

                            <i class="bi bi-person-vcard"></i>

                            <input
                                type="text"
                                id="cedula"
                                name="cedula"
                                class="form-control"
                                maxlength="10"
                                inputmode="numeric"
                                value="<?php echo h($cedulaIngresada); ?>"
                                placeholder="10 números"
                                required
                            >

                        </div>

                    </div>

                    <button
                        type="submit"
                        name="verificar"
                        class="btn-principal"
                    >
                        <i class="bi bi-shield-check me-1"></i>
                        Verificar mis datos
                    </button>

                </form>

                <div class="acciones-secundarias">

                    <a href="login.php" class="enlace-volver">
                        <i class="bi bi-arrow-left"></i>
                        Volver a iniciar sesión
                    </a>

                </div>

            <?php } else { ?>

                <div class="form-etiqueta">
                    NUEVA CONTRASEÑA
                </div>

                <h2 class="form-titulo">
                    Crea una nueva contraseña
                </h2>

                <p class="form-texto">
                    La verificación fue correcta. Este paso estará
                    disponible durante 10 minutos.
                </p>

                <div class="paso-verificado">
                    <i class="bi bi-check-circle-fill"></i>
                    <span>
                        Cuenta verificada:
                        <strong>
                            <?php
                            echo h(
                                $_SESSION["recuperacion_usuario"] ?? ""
                            );
                            ?>
                        </strong>
                    </span>
                </div>

                <?php if ($error !== "") { ?>

                    <div class="alerta-error">
                        <i class="bi bi-exclamation-circle"></i>
                        <span><?php echo h($error); ?></span>
                    </div>

                <?php } ?>

                <form method="POST" autocomplete="off">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?php echo h($csrf); ?>"
                    >

                    <div class="campo">

                        <label for="password">
                            Nueva contraseña
                        </label>

                        <div class="campo-icono">

                            <i class="bi bi-lock"></i>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-control"
                                minlength="8"
                                placeholder="Mínimo 8 caracteres"
                                autocomplete="new-password"
                                required
                                autofocus
                            >

                            <button
                                type="button"
                                class="mostrar-password"
                                data-campo="password"
                                aria-label="Mostrar contraseña"
                            >
                                <i class="bi bi-eye"></i>
                            </button>

                        </div>

                    </div>

                    <div class="campo">

                        <label for="confirmar_password">
                            Confirmar contraseña
                        </label>

                        <div class="campo-icono">

                            <i class="bi bi-shield-lock"></i>

                            <input
                                type="password"
                                id="confirmar_password"
                                name="confirmar_password"
                                class="form-control"
                                minlength="8"
                                placeholder="Repite la contraseña"
                                autocomplete="new-password"
                                required
                            >

                            <button
                                type="button"
                                class="mostrar-password"
                                data-campo="confirmar_password"
                                aria-label="Mostrar contraseña"
                            >
                                <i class="bi bi-eye"></i>
                            </button>

                        </div>

                    </div>

                    <button
                        type="submit"
                        name="cambiar_password"
                        class="btn-principal"
                    >
                        <i class="bi bi-key me-1"></i>
                        Guardar nueva contraseña
                    </button>

                </form>

                <form method="POST" class="acciones-secundarias">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?php echo h($csrf); ?>"
                    >

                    <button
                        type="submit"
                        name="cancelar_verificacion"
                        class="btn-cambiar-datos"
                    >
                        <i class="bi bi-arrow-left"></i>
                        Verificar otros datos
                    </button>

                </form>

            <?php } ?>

            <div class="ayuda-personal">
                <i class="bi bi-info-circle me-1"></i>
                La recuperación automática está disponible para cuentas
                de Cliente vinculadas a un huésped. El personal del hotel
                debe solicitar el cambio de contraseña a un Administrador.
            </div>

        </div>

    </section>

</main>

<script>
    const cedula = document.getElementById("cedula");

    if (cedula) {
        cedula.addEventListener("input", () => {
            cedula.value = cedula.value.replace(/\D/g, "");
        });
    }

    document.querySelectorAll(".mostrar-password").forEach((boton) => {
        boton.addEventListener("click", () => {
            const campo =
                document.getElementById(boton.dataset.campo);

            if (!campo) {
                return;
            }

            const mostrar = campo.type === "password";
            campo.type = mostrar ? "text" : "password";

            const icono = boton.querySelector("i");

            if (icono) {
                icono.className =
                    mostrar ? "bi bi-eye-slash" : "bi bi-eye";
            }

            boton.setAttribute(
                "aria-label",
                mostrar
                    ? "Ocultar contraseña"
                    : "Mostrar contraseña"
            );
        });
    });
</script>

</body>
</html>