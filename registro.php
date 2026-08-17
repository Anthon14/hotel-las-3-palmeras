<?php
session_start();
include("config/conexion.php");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function h($texto)
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

if (isset($_SESSION["usuario"], $_SESSION["rol"])) {
    $rol = strtolower(trim($_SESSION["rol"]));

    if ($rol === "cliente") {
        header("Location: cliente/index.php");
        exit();
    }

    header("Location: dashboard.php");
    exit();
}

/* Registro de clientes */
if (empty($_SESSION["csrf_registro"])) {
    $_SESSION["csrf_registro"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_registro"];

$datos = [
    "nombres" => trim($_POST["nombres"] ?? ""),
    "apellidos" => trim($_POST["apellidos"] ?? ""),
    "cedula" => trim($_POST["cedula"] ?? ""),
    "telefono" => trim($_POST["telefono"] ?? ""),
    "correo" => trim($_POST["correo"] ?? ""),
    "usuario" => trim($_POST["usuario"] ?? "")
];

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfRecibido = $_POST["csrf"] ?? "";
    $password = $_POST["password"] ?? "";
    $confirmarPassword = $_POST["confirmar_password"] ?? "";

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $error = "La solicitud no es válida. Actualiza la página.";
    } elseif (
        in_array("", $datos, true) ||
        $password === "" ||
        $confirmarPassword === ""
    ) {
        $error = "Complete todos los campos.";
    } elseif (!preg_match("/^[0-9]{10}$/", $datos["cedula"])) {
        $error = "La cédula debe contener exactamente 10 números.";
    } elseif (!preg_match("/^[0-9]{10}$/", $datos["telefono"])) {
        $error = "El teléfono debe contener exactamente 10 números.";
    } elseif (!filter_var($datos["correo"], FILTER_VALIDATE_EMAIL)) {
        $error = "Ingrese un correo electrónico válido.";
    } elseif (!preg_match("/^[a-zA-Z0-9._-]{4,30}$/", $datos["usuario"])) {
        $error = "El usuario debe tener entre 4 y 30 caracteres y no debe contener espacios.";
    } elseif (strlen($password) < 8) {
        $error = "La contraseña debe tener mínimo 8 caracteres.";
    } elseif ($password !== $confirmarPassword) {
        $error = "Las contraseñas no coinciden.";
    } else {
        try {
            $consultaUsuario = mysqli_prepare(
                $conn,
                "SELECT id_usuario
                 FROM usuarios
                 WHERE usuario = ?
                 LIMIT 1"
            );

            mysqli_stmt_bind_param(
                $consultaUsuario,
                "s",
                $datos["usuario"]
            );

            mysqli_stmt_execute($consultaUsuario);
            $resultadoUsuario = mysqli_stmt_get_result($consultaUsuario);

            if (mysqli_num_rows($resultadoUsuario) > 0) {
                $error = "El nombre de usuario ya está registrado.";
            }

            mysqli_stmt_close($consultaUsuario);

            if ($error === "") {
                $consultaCliente = mysqli_prepare(
                    $conn,
                    "SELECT id_cliente, cedula, correo
                     FROM clientes
                     WHERE cedula = ? OR correo = ?
                     LIMIT 1"
                );

                mysqli_stmt_bind_param(
                    $consultaCliente,
                    "ss",
                    $datos["cedula"],
                    $datos["correo"]
                );

                mysqli_stmt_execute($consultaCliente);
                $resultadoCliente = mysqli_stmt_get_result($consultaCliente);
                $clienteExistente = mysqli_fetch_assoc($resultadoCliente);

                if ($clienteExistente) {
                    if ($clienteExistente["cedula"] === $datos["cedula"]) {
                        $error = "La cédula ya está registrada.";
                    } else {
                        $error = "El correo electrónico ya está registrado.";
                    }
                }

                mysqli_stmt_close($consultaCliente);
            }

            if ($error === "") {
                mysqli_begin_transaction($conn);

                $nombreCompleto = trim(
                    $datos["nombres"] . " " . $datos["apellidos"]
                );

                $passwordSeguro = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $rol = "Cliente";

                $guardarUsuario = mysqli_prepare(
                    $conn,
                    "INSERT INTO usuarios
                        (nombre, usuario, password, rol)
                     VALUES (?, ?, ?, ?)"
                );

                mysqli_stmt_bind_param(
                    $guardarUsuario,
                    "ssss",
                    $nombreCompleto,
                    $datos["usuario"],
                    $passwordSeguro,
                    $rol
                );

                mysqli_stmt_execute($guardarUsuario);

                $idUsuario = mysqli_insert_id($conn);

                mysqli_stmt_close($guardarUsuario);

                $guardarCliente = mysqli_prepare(
                    $conn,
                    "INSERT INTO clientes
                        (
                            id_usuario,
                            nombres,
                            apellidos,
                            cedula,
                            telefono,
                            correo
                        )
                     VALUES (?, ?, ?, ?, ?, ?)"
                );

                mysqli_stmt_bind_param(
                    $guardarCliente,
                    "isssss",
                    $idUsuario,
                    $datos["nombres"],
                    $datos["apellidos"],
                    $datos["cedula"],
                    $datos["telefono"],
                    $datos["correo"]
                );

                mysqli_stmt_execute($guardarCliente);
                mysqli_stmt_close($guardarCliente);

                mysqli_commit($conn);

                $_SESSION["csrf_registro"] =
                    bin2hex(random_bytes(32));

                $_SESSION["mensaje_exito"] =
                    "La cuenta fue creada correctamente. Ahora inicia sesión con tu nuevo usuario.";

                header("Location: login.php");
                exit();
            }
        } catch (Throwable $e) {
            if (mysqli_errno($conn) !== 0) {
                mysqli_rollback($conn);
            }

            $error = "No se pudo crear la cuenta. Intente nuevamente.";
        }
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
        Crear cuenta - Hotel Las 3 Palmeras
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
            --dorado: #d8b56d;
            --texto: #20231f;
            --texto-suave: #6d746e;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            background:
                linear-gradient(
                    rgba(17, 45, 30, 0.68),
                    rgba(17, 45, 30, 0.68)
                ),
                url("img/hotel.jpg");

            background-size: cover;
            background-position: center;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--texto);
        }

        .pagina-registro {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 35px;
        }

        .registro-contenedor {
            width: min(1180px, 100%);
            display: grid;
            grid-template-columns: 58% 42%;
            overflow: hidden;
            border-radius: 34px;
            background-color: white;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.32);
        }

        .registro-formulario {
            padding: 45px 55px;
        }

        .marca {
            display: flex;
            align-items: center;
            gap: 13px;
            margin-bottom: 27px;
        }

        .marca img {
            width: 60px;
            height: 60px;
            object-fit: contain;
        }

        .marca strong {
            display: block;
            color: var(--verde-oscuro);
            font-family: Georgia, "Times New Roman", serif;
            font-size: 20px;
        }

        .marca small {
            color: #9b7739;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.5px;
        }

        .etiqueta {
            margin-bottom: 8px;
            color: #9b7739;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 2px;
        }

        h1 {
            margin-bottom: 9px;
            color: var(--verde-oscuro);
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(2.2rem, 4vw, 3.2rem);
            font-weight: 700;
        }

        .texto {
            margin-bottom: 25px;
            color: var(--texto-suave);
            font-size: 14px;
        }

        .alerta-error {
            display: flex;
            gap: 10px;
            margin-bottom: 22px;
            padding: 13px 15px;
            border: 1px solid #efcaca;
            border-radius: 12px;
            background-color: #fff2f2;
            color: #9b3131;
            font-size: 13px;
        }

        .form-label {
            margin-bottom: 7px;
            color: #38423b;
            font-size: 12px;
            font-weight: 800;
        }

        .form-control {
            min-height: 49px;
            border: 1px solid #dde2dd;
            border-radius: 13px;
            background-color: #f3f5f3;
            font-size: 14px;
        }

        .form-control:focus {
            border-color: var(--verde);
            background-color: white;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, 0.10);
        }

        .ayuda-campo {
            margin-top: 6px;
            color: var(--texto-suave);
            font-size: 10px;
            line-height: 1.45;
        }

        .campo-password {
            position: relative;
        }

        .campo-password .form-control {
            padding-right: 46px;
        }

        .mostrar-password {
            position: absolute;
            top: 50%;
            right: 14px;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #657068;
            font-size: 17px;
        }

        .btn-crear {
            width: 100%;
            min-height: 52px;
            margin-top: 8px;
            border: none;
            border-radius: 999px;
            background-color: var(--verde);
            color: white;
            font-size: 14px;
            font-weight: 900;
            transition: 0.2s ease;
        }

        .btn-crear:hover {
            background-color: var(--verde-oscuro);
            transform: translateY(-2px);
        }

        .enlace-login {
            margin-top: 20px;
            color: var(--texto-suave);
            text-align: center;
            font-size: 13px;
        }

        .enlace-login a {
            color: var(--verde);
            font-weight: 900;
            text-decoration: none;
        }

        .registro-imagen {
            position: relative;
            min-height: 780px;
            background:
                linear-gradient(
                    rgba(15, 39, 25, 0.10),
                    rgba(15, 39, 25, 0.68)
                ),
                url("img/hotel.jpg");

            background-size: cover;
            background-position: center;
        }

        .imagen-contenido {
            position: absolute;
            right: 35px;
            bottom: 35px;
            left: 35px;
            color: white;
        }

        .imagen-contenido span {
            display: inline-flex;
            margin-bottom: 13px;
            padding: 7px 11px;
            border: 1px solid rgba(255, 255, 255, 0.42);
            border-radius: 999px;
            background-color: rgba(255, 255, 255, 0.12);
            font-size: 11px;
            font-weight: 800;
        }

        .imagen-contenido h2 {
            margin-bottom: 12px;
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(2.2rem, 4vw, 3.6rem);
            font-weight: 700;
            line-height: 1.05;
        }

        .imagen-contenido p {
            margin: 0;
            color: rgba(255, 255, 255, 0.82);
            font-size: 14px;
            line-height: 1.7;
        }

        @media (max-width: 991px) {
            .pagina-registro {
                padding: 22px;
            }

            .registro-contenedor {
                max-width: 680px;
                grid-template-columns: 1fr;
                border-radius: 25px;
            }

            .registro-imagen {
                min-height: 290px;
                order: -1;
            }

            .registro-formulario {
                padding: 40px;
            }
        }

        @media (max-width: 575px) {
            .pagina-registro {
                display: block;
                padding: 0;
                background-color: white;
            }

            .registro-contenedor {
                width: 100%;
                min-height: 100vh;
                border-radius: 0;
                box-shadow: none;
            }

            .registro-imagen {
                min-height: 230px;
            }

            .imagen-contenido {
                right: 22px;
                bottom: 22px;
                left: 22px;
            }

            .imagen-contenido h2 {
                font-size: 2rem;
            }

            .imagen-contenido p {
                display: none;
            }

            .registro-formulario {
                padding: 32px 22px 45px;
            }

            .marca {
                margin-bottom: 23px;
            }

            .marca img {
                width: 52px;
                height: 52px;
            }

            h1 {
                font-size: 2.3rem;
            }
        }
    </style>

    <style>
    body {
        opacity: 0;
        transform: translateY(15px);
        animation: entradaSuave 0.55s ease-out forwards;
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
</style>
</head>

<body>

<main class="pagina-registro">

    <section class="registro-contenedor">

        <div class="registro-formulario">

            <div class="marca">
                <img
                    src="img/logo.png"
                    alt="Hotel Las 3 Palmeras"
                >

                <div>
                    <strong>
                        Hotel Las 3 Palmeras
                    </strong>

                    <small>
                        COMODIDAD Y TRANQUILIDAD
                    </small>
                </div>
            </div>

            <div class="etiqueta">
                REGISTRO DE CLIENTES
            </div>

            <h1>
                Crea tu cuenta
            </h1>

            <p class="texto">
                Completa tus datos para reservar habitaciones y solicitar servicios.
            </p>

            <?php if ($error !== "") { ?>

                <div class="alerta-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <span><?php echo h($error); ?></span>
                </div>

            <?php } ?>

            <form method="POST" autocomplete="on">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?php echo h($csrf); ?>"
                >

                <div class="row g-3">

                    <div class="col-md-6">
                        <label
                            for="nombres"
                            class="form-label"
                        >
                            Nombres
                        </label>

                        <input
                            type="text"
                            id="nombres"
                            name="nombres"
                            class="form-control"
                            value="<?php echo h($datos["nombres"]); ?>"
                            maxlength="80"
                            autocomplete="given-name"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label
                            for="apellidos"
                            class="form-label"
                        >
                            Apellidos
                        </label>

                        <input
                            type="text"
                            id="apellidos"
                            name="apellidos"
                            class="form-control"
                            value="<?php echo h($datos["apellidos"]); ?>"
                            maxlength="80"
                            autocomplete="family-name"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label
                            for="cedula"
                            class="form-label"
                        >
                            Cédula
                        </label>

                        <input
                            type="text"
                            id="cedula"
                            name="cedula"
                            class="form-control"
                            value="<?php echo h($datos["cedula"]); ?>"
                            maxlength="10"
                            inputmode="numeric"
                            pattern="[0-9]{10}"
                            autocomplete="off"
                            required
                        >
                    </div>

                    <div class="col-md-6">
                        <label
                            for="telefono"
                            class="form-label"
                        >
                            Teléfono
                        </label>

                        <input
                            type="text"
                            id="telefono"
                            name="telefono"
                            class="form-control"
                            value="<?php echo h($datos["telefono"]); ?>"
                            maxlength="10"
                            inputmode="numeric"
                            pattern="[0-9]{10}"
                            autocomplete="tel"
                            required
                        >
                    </div>

                    <div class="col-12">
                        <label
                            for="correo"
                            class="form-label"
                        >
                            Correo electrónico
                        </label>

                        <input
                            type="email"
                            id="correo"
                            name="correo"
                            class="form-control"
                            value="<?php echo h($datos["correo"]); ?>"
                            maxlength="120"
                            autocomplete="email"
                            required
                        >
                    </div>

                    <div class="col-12">
                        <label
                            for="usuario"
                            class="form-label"
                        >
                            Nombre de usuario
                        </label>

                        <input
                            type="text"
                            id="usuario"
                            name="usuario"
                            class="form-control"
                            value="<?php echo h($datos["usuario"]); ?>"
                            maxlength="30"
                            autocomplete="username"
                            required
                        >

                        <div class="ayuda-campo">
                            Usa entre 4 y 30 caracteres, sin espacios.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label
                            for="password"
                            class="form-label"
                        >
                            Contraseña
                        </label>

                        <div class="campo-password">
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-control"
                                minlength="8"
                                autocomplete="new-password"
                                required
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

                        <div class="ayuda-campo">
                            Debe tener mínimo 8 caracteres.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label
                            for="confirmar_password"
                            class="form-label"
                        >
                            Confirmar contraseña
                        </label>

                        <div class="campo-password">
                            <input
                                type="password"
                                id="confirmar_password"
                                name="confirmar_password"
                                class="form-control"
                                minlength="8"
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

                </div>

                <button
                    type="submit"
                    class="btn-crear"
                >
                    <i class="bi bi-person-check me-1"></i>
                    Crear mi cuenta
                </button>

            </form>

            <div class="enlace-login">
                ¿Ya tienes una cuenta?
                <a href="login.php">
                    Iniciar sesión
                </a>
            </div>

        </div>

        <div class="registro-imagen">

            <div class="imagen-contenido">

                <span>
                    <i class="bi bi-person-plus me-2"></i>
                    Nueva cuenta de cliente
                </span>

                <h2>
                    Tu próxima estadía comienza aquí
                </h2>

                <p>
                    Regístrate para consultar habitaciones, reservar, registrar pagos y pedir comidas.
                </p>

            </div>

        </div>

    </section>

</main>

<script>
    document
        .querySelectorAll(".mostrar-password")
        .forEach((boton) => {
            boton.addEventListener("click", () => {
                const campo = document.getElementById(
                    boton.dataset.campo
                );

                const mostrar = campo.type === "password";

                campo.type = mostrar ? "text" : "password";

                boton.querySelector("i").className =
                    mostrar
                        ? "bi bi-eye-slash"
                        : "bi bi-eye";
            });
        });

    document
        .querySelectorAll("#cedula, #telefono")
        .forEach((campo) => {
            campo.addEventListener("input", () => {
                campo.value = campo.value.replace(/\D/g, "");
            });
        });

    const usuario = document.getElementById("usuario");

    if (usuario) {
        usuario.addEventListener("input", () => {
            usuario.value = usuario.value
                .replace(/\s/g, "");
        });
    }
</script>

</body>
</html>