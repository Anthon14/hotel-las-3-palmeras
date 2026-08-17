<?php
session_start();

include("config/conexion.php");

function h($texto)
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

function limpiarUsuario($texto)
{
    $texto = trim((string) $texto);

    if (function_exists("iconv")) {
        $convertido = iconv(
            "UTF-8",
            "ASCII//TRANSLIT//IGNORE",
            $texto
        );

        if ($convertido !== false) {
            $texto = $convertido;
        }
    }

    $texto = strtolower($texto);
    $texto = preg_replace("/[^a-z0-9._-]+/", ".", $texto);
    $texto = preg_replace("/[._-]{2,}/", ".", $texto);
    $texto = trim($texto, "._-");

    return substr($texto, 0, 30);
}

/* Configuración inicial */
$consultaTotal = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total
     FROM usuarios"
);

if (!$consultaTotal) {
    die("No se pudo comprobar la tabla de usuarios.");
}

$filaTotal = mysqli_fetch_assoc($consultaTotal);
$totalUsuarios = (int) ($filaTotal["total"] ?? 0);

if ($totalUsuarios > 0) {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION["csrf_primer_admin"])) {
    $_SESSION["csrf_primer_admin"] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_primer_admin"];

$errores = [];
$nombre = "";
$usuario = "";

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["crear_administrador"])
) {
    $nombre = trim($_POST["nombre"] ?? "");
    $usuario = limpiarUsuario(
        $_POST["usuario"] ?? ""
    );
    $password = $_POST["password"] ?? "";
    $confirmarPassword =
        $_POST["confirmar_password"] ?? "";
    $csrfRecibido = $_POST["csrf"] ?? "";

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $errores[] =
            "La solicitud no es válida. Actualiza la página.";
    }

    $consultaTotal = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM usuarios"
    );

    $filaTotal = mysqli_fetch_assoc($consultaTotal);
    $totalUsuarios = (int) ($filaTotal["total"] ?? 0);

    if ($totalUsuarios > 0) {
        $errores[] =
            "Ya existe un usuario en el sistema.";
    }

    if ($nombre === "") {
        $errores[] =
            "El nombre completo es obligatorio.";
    }

    if (
        strlen($usuario) < 4 ||
        strlen($usuario) > 30
    ) {
        $errores[] =
            "El nombre de usuario debe tener entre 4 y 30 caracteres.";
    }

    if (strlen($password) < 8) {
        $errores[] =
            "La contraseña debe tener mínimo 8 caracteres.";
    }

    if ($password !== $confirmarPassword) {
        $errores[] =
            "Las contraseñas no coinciden.";
    }

    if (empty($errores)) {
        $passwordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        $rol = "Administrador";

        $guardar = mysqli_prepare(
            $conn,
            "INSERT INTO usuarios
                (nombre, usuario, password, rol)
             VALUES (?, ?, ?, ?)"
        );

        if (!$guardar) {
            $errores[] =
                "No se pudo preparar el registro.";
        } else {
            mysqli_stmt_bind_param(
                $guardar,
                "ssss",
                $nombre,
                $usuario,
                $passwordHash,
                $rol
            );

            if (mysqli_stmt_execute($guardar)) {
                mysqli_stmt_close($guardar);

                unset(
                    $_SESSION["csrf_primer_admin"]
                );

                $_SESSION["mensaje_exito"] =
                    "El primer administrador fue creado correctamente. Ya puedes iniciar sesión.";

                header("Location: login.php");
                exit();
            }

            $errorMysql =
                mysqli_stmt_error($guardar);

            mysqli_stmt_close($guardar);

            if (
                str_contains(
                    strtolower($errorMysql),
                    "duplicate"
                )
            ) {
                $errores[] =
                    "El nombre de usuario ya está registrado.";
            } else {
                $errores[] =
                    "No se pudo crear el administrador.";
            }
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
        Crear primer administrador - Hotel Las 3 Palmeras
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
            --crema: #f7f3eb;
            --texto-suave: #687068;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 30px 15px;
            opacity: 0;
            transform: translateY(15px);
            animation: entradaSuave .55s ease-out forwards;
            background:
                linear-gradient(
                    rgba(12, 34, 22, 0.78),
                    rgba(12, 34, 22, 0.78)
                ),
                url("img/hotel.jpg");
            background-size: cover;
            background-position: center;
            font-family: Arial, Helvetica, sans-serif;
        }

        .registro-card {
            width: min(940px, 100%);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.24);
            border-radius: 14px;
            background-color: white;
            box-shadow: 0 28px 70px rgba(0, 0, 0, 0.30);
        }

        .panel-marca {
            min-height: 100%;
            padding: 42px 36px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background-color: var(--verde-oscuro);
            color: white;
        }

        .logo {
            width: 78px;
            height: 78px;
            object-fit: contain;
            margin-bottom: 22px;
        }

        .panel-marca h1 {
            margin-bottom: 15px;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 40px;
            line-height: 1.05;
        }

        .panel-marca p {
            margin: 0;
            color: rgba(255, 255, 255, 0.70);
            font-size: 14px;
            line-height: 1.7;
        }

        .etiqueta {
            margin-bottom: 13px;
            color: #ead49b;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 2px;
        }

        .seguridad {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.14);
            color: rgba(255, 255, 255, 0.60);
            font-size: 11px;
            line-height: 1.6;
        }

        .panel-formulario {
            padding: 44px 40px;
        }

        .panel-formulario h2 {
            margin-bottom: 8px;
            color: var(--verde-oscuro);
            font-family: Georgia, "Times New Roman", serif;
            font-size: 31px;
            font-weight: 700;
        }

        .descripcion {
            margin-bottom: 28px;
            color: var(--texto-suave);
            font-size: 13px;
            line-height: 1.6;
        }

        .form-label {
            margin-bottom: 7px;
            color: #3c463f;
            font-size: 12px;
            font-weight: 900;
        }

        .form-control {
            min-height: 49px;
            border: 1px solid #dce1dc;
            border-radius: 6px;
            background-color: #f7f9f7;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: var(--verde);
            background-color: white;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, 0.10);
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
            border: 0;
            background: transparent;
            color: #657068;
            font-size: 17px;
        }

        .form-text {
            color: var(--texto-suave);
            font-size: 11px;
        }

        .mensaje-error {
            margin-bottom: 22px;
            padding: 14px 16px;
            border: 1px solid #edc8c8;
            border-radius: 6px;
            background-color: #fff1f1;
            color: #9b3131;
            font-size: 13px;
        }

        .mensaje-error ul {
            margin-bottom: 0;
        }

        .btn-crear {
            min-height: 49px;
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 5px;
            background-color: var(--verde);
            color: white;
            font-size: 13px;
            font-weight: 900;
        }

        .btn-crear:hover {
            background-color: var(--verde-oscuro);
            color: white;
        }

        .aviso {
            margin-top: 18px;
            padding: 12px 14px;
            border-radius: 6px;
            background-color: #f5f1e6;
            color: #6e5a2c;
            font-size: 11px;
            line-height: 1.6;
        }

        @media (max-width: 767px) {
            .panel-marca {
                padding: 32px 27px;
            }

            .panel-marca h1 {
                font-size: 32px;
            }

            .panel-formulario {
                padding: 34px 25px;
            }
        }
    </style>
</head>

<body>

<section class="registro-card">

    <div class="row g-0">

        <div class="col-md-5">

            <div class="panel-marca">

                <div>

                    <img
                        src="img/logo.png"
                        alt="Hotel Las 3 Palmeras"
                        class="logo"
                    >

                    <div class="etiqueta">
                        PRIMERA CONFIGURACIÓN
                    </div>

                    <h1>
                        Hotel Las 3 Palmeras
                    </h1>

                    <p>
                        Crea la primera cuenta administrativa
                        para comenzar a configurar y utilizar
                        el sistema del hotel.
                    </p>

                </div>

                <div class="seguridad">

                    <i class="bi bi-shield-lock me-1"></i>

                    Esta página solo funciona mientras
                    la tabla de usuarios esté vacía.
                    Después de crear la primera cuenta,
                    quedará bloqueada automáticamente.

                </div>

            </div>

        </div>

        <div class="col-md-7">

            <div class="panel-formulario">

                <h2>
                    Crear administrador
                </h2>

                <p class="descripcion">
                    Esta será la cuenta principal con permiso
                    para crear clientes, recepcionistas y otros
                    administradores.
                </p>

                <?php if (!empty($errores)) { ?>

                    <div class="mensaje-error">

                        <strong>
                            No se pudo crear la cuenta:
                        </strong>

                        <ul class="mt-2">

                            <?php foreach ($errores as $error) { ?>

                                <li>
                                    <?php echo h($error); ?>
                                </li>

                            <?php } ?>

                        </ul>

                    </div>

                <?php } ?>

                <form method="POST" autocomplete="off">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?php echo h($csrf); ?>"
                    >

                    <div class="mb-3">

                        <label
                            for="nombre"
                            class="form-label"
                        >
                            Nombre completo
                        </label>

                        <input
                            type="text"
                            id="nombre"
                            name="nombre"
                            class="form-control"
                            value="<?php echo h($nombre); ?>"
                            maxlength="100"
                            autocomplete="name"
                            required
                        >

                    </div>

                    <div class="mb-3">

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
                            value="<?php echo h($usuario); ?>"
                            maxlength="30"
                            autocomplete="username"
                            required
                        >

                        <div class="form-text">
                            Utiliza letras, números, punto,
                            guion o guion bajo.
                        </div>

                    </div>

                    <div class="row g-3">

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
                        name="crear_administrador"
                        class="btn-crear mt-4"
                    >
                        <i class="bi bi-person-check"></i>
                        Crear primer administrador
                    </button>

                    <div class="aviso">

                        <i class="bi bi-info-circle me-1"></i>

                        El rol se asignará automáticamente como
                        Administrador y la contraseña se guardará
                        de forma cifrada.

                    </div>

                </form>

            </div>

        </div>

    </div>

</section>

<script>
    const campoUsuario =
        document.getElementById("usuario");

    campoUsuario.addEventListener("input", () => {
        campoUsuario.value = campoUsuario.value
            .toLowerCase()
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "")
            .replace(/[^a-z0-9._-]/g, "");
    });

    document.querySelectorAll(".mostrar-password").forEach((boton) => {
        boton.addEventListener("click", () => {
            const campo = document.getElementById(boton.dataset.campo);

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