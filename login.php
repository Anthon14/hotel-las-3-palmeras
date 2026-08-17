<?php
session_start();
include("config/conexion.php");

function h($texto)
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

function redirigirSegunRol($rol)
{
    $rol = strtolower(trim((string) $rol));

    if ($rol === "cliente") {
        header("Location: cliente/index.php");
        exit();
    }

    if (in_array($rol, ["administrador", "recepcionista"], true)) {
        header("Location: dashboard.php");
        exit();
    }

    session_unset();
    session_destroy();

    header("Location: login.php");
    exit();
}

/* Acceso al sistema */
$consultaTotalUsuarios = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total
     FROM usuarios"
);

if (!$consultaTotalUsuarios) {
    die(
        "No se pudo comprobar la tabla de usuarios. " .
        "Verifica la conexión y que la tabla usuarios exista."
    );
}

$filaTotalUsuarios = mysqli_fetch_assoc(
    $consultaTotalUsuarios
);

$totalUsuarios = (int) (
    $filaTotalUsuarios["total"] ?? 0
);

if ($totalUsuarios === 0) {
    header("Location: primer_admin.php");
    exit();
}

if (isset($_SESSION["usuario"], $_SESSION["rol"])) {
    redirigirSegunRol($_SESSION["rol"]);
}

$error = "";

$mensajeExito =
    $_SESSION["mensaje_exito"] ?? "";

unset($_SESSION["mensaje_exito"]);

$usuarioIngresado =
    trim($_POST["usuario"] ?? "");

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["login"])
) {
    $password = $_POST["password"] ?? "";

    if ($usuarioIngresado === "" || $password === "") {
        $error = "Ingrese el usuario y la contraseña.";
    } else {
        $consulta = mysqli_prepare(
            $conn,
            "SELECT
                id_usuario,
                nombre,
                usuario,
                password,
                rol
             FROM usuarios
             WHERE usuario = ?
             LIMIT 1"
        );

        if (!$consulta) {
            $error =
                "No se pudo procesar el inicio de sesión.";
        } else {
            mysqli_stmt_bind_param(
                $consulta,
                "s",
                $usuarioIngresado
            );

            mysqli_stmt_execute($consulta);

            $resultado =
                mysqli_stmt_get_result($consulta);

            $datos =
                mysqli_fetch_assoc($resultado);

            if (
                $datos &&
                password_verify(
                    $password,
                    $datos["password"]
                )
            ) {
                session_regenerate_id(true);

                $_SESSION["id_usuario"] =
                    (int) $datos["id_usuario"];

                $_SESSION["nombre"] =
                    $datos["nombre"];

                $_SESSION["usuario"] =
                    $datos["usuario"];

                $_SESSION["rol"] =
                    $datos["rol"];

                mysqli_stmt_close($consulta);

                redirigirSegunRol(
                    $datos["rol"]
                );
            }

            $error =
                "Usuario o contraseña incorrectos.";

            mysqli_stmt_close($consulta);
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
        Iniciar sesión - Hotel Las 3 Palmeras
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
            --verde-principal: #244a35;
            --verde-oscuro: #173325;
            --verde-claro: #e9f0eb;
            --dorado: #d8b56d;
            --crema: #f5f2eb;
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

        .pagina-login {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 35px;
        }

        .login-contenedor {
            width: min(1120px, 100%);
            min-height: 680px;
            display: grid;
            grid-template-columns: 43% 57%;
            overflow: hidden;
            border-radius: 36px;
            background-color: white;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.32);
        }

        .login-formulario {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 55px 65px;
            background-color: white;
        }

        .marca-login {
            display: flex;
            align-items: center;
            gap: 13px;
            margin-bottom: 42px;
        }

        .marca-login img {
            width: 64px;
            height: 64px;
            object-fit: contain;
        }

        .marca-login strong {
            display: block;
            color: var(--verde-oscuro);
            font-family: Georgia, "Times New Roman", serif;
            font-size: 21px;
            line-height: 1.1;
        }

        .marca-login small {
            color: #a37e3e;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 1.6px;
        }

        .login-etiqueta {
            margin-bottom: 10px;
            color: #9b7739;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 2px;
        }

        .login-titulo {
            margin-bottom: 12px;
            color: var(--verde-oscuro);
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(2.2rem, 4vw, 3.3rem);
            font-weight: 700;
            line-height: 1.05;
        }

        .login-texto {
            margin-bottom: 30px;
            color: var(--texto-suave);
            font-size: 14px;
            line-height: 1.7;
        }

        .alerta-error,
        .alerta-exito {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 22px;
            padding: 13px 15px;
            border-radius: 12px;
            font-size: 13px;
        }

        .alerta-error {
            border: 1px solid #efcaca;
            background-color: #fff2f2;
            color: #9b3131;
        }

        .alerta-exito {
            border: 1px solid #b9dfc3;
            background-color: #edf9f0;
            color: #24633a;
        }

        .campo-login {
            margin-bottom: 18px;
        }

        .campo-login label {
            margin-bottom: 8px;
            color: #38423b;
            font-size: 13px;
            font-weight: 800;
        }

        .campo-con-icono {
            position: relative;
        }

        .campo-con-icono > i {
            position: absolute;
            top: 50%;
            left: 17px;
            z-index: 2;
            transform: translateY(-50%);
            color: #7d877f;
            font-size: 17px;
        }

        .campo-con-icono .form-control {
            min-height: 54px;
            padding: 13px 48px;
            border: 1px solid #dde2dd;
            border-radius: 999px;
            background-color: #f3f5f3;
            font-size: 14px;
        }

        .campo-con-icono .form-control:focus {
            border-color: var(--verde-principal);
            background-color: white;
            box-shadow:
                0 0 0 4px
                rgba(36, 74, 53, 0.10);
        }

        .mostrar-password {
            position: absolute;
            top: 50%;
            right: 16px;
            z-index: 3;
            transform: translateY(-50%);
            border: none;
            background: transparent;
            color: #657068;
            font-size: 18px;
        }

        .btn-ingresar {
            width: 100%;
            min-height: 54px;
            margin-top: 8px;
            border: none;
            border-radius: 999px;
            background-color: var(--verde-principal);
            color: white;
            font-size: 14px;
            font-weight: 900;
            letter-spacing: 0.4px;
            transition: 0.2s ease;
        }

        .btn-ingresar:hover {
            background-color: var(--verde-oscuro);
            transform: translateY(-2px);
        }

        .recuperar-acceso {
            display: flex;
            justify-content: flex-end;
            margin-top: -4px;
            margin-bottom: 18px;
        }

        .recuperar-acceso a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--verde-principal);
            font-size: 12px;
            font-weight: 800;
            text-decoration: none;
        }

        .recuperar-acceso a:hover {
            color: #9b7739;
        }

        .crear-cuenta {
            margin-top: 25px;
            color: var(--texto-suave);
            text-align: center;
            font-size: 13px;
        }

        .crear-cuenta a {
            color: var(--verde-principal);
            font-weight: 900;
            text-decoration: none;
        }

        .crear-cuenta a:hover {
            color: #9b7739;
        }

        .volver-inicio {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 20px;
            color: #717a73;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
        }

        .volver-inicio:hover {
            color: var(--verde-principal);
        }

        .login-imagen {
            position: relative;
            min-height: 680px;
            overflow: hidden;
            background:
                linear-gradient(
                    rgba(11, 31, 20, 0.10),
                    rgba(11, 31, 20, 0.30)
                ),
                url("img/hotel.jpg");
            background-size: cover;
            background-position: center;
        }

        .login-imagen::after {
            content: "";
            position: absolute;
            inset: 0;
            background:
                linear-gradient(
                    180deg,
                    rgba(18, 44, 29, 0.08),
                    rgba(18, 44, 29, 0.68)
                );
        }

        .imagen-contenido {
            position: absolute;
            z-index: 2;
            right: 38px;
            bottom: 38px;
            left: 38px;
            color: white;
        }

        .imagen-etiqueta {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 14px;
            padding: 8px 12px;
            border: 1px solid
                rgba(255, 255, 255, 0.42);
            border-radius: 999px;
            background-color:
                rgba(255, 255, 255, 0.12);
            font-size: 11px;
            font-weight: 800;
            backdrop-filter: blur(8px);
        }

        .imagen-contenido h2 {
            max-width: 520px;
            margin-bottom: 12px;
            font-family:
                Georgia,
                "Times New Roman",
                serif;
            font-size:
                clamp(2.2rem, 4vw, 3.8rem);
            font-weight: 700;
            line-height: 1.05;
        }

        .imagen-contenido p {
            max-width: 500px;
            margin: 0;
            color:
                rgba(255, 255, 255, 0.82);
            font-size: 14px;
            line-height: 1.7;
        }

        @media (max-width: 991px) {
            .pagina-login {
                padding: 22px;
            }

            .login-contenedor {
                grid-template-columns: 1fr;
                max-width: 620px;
                min-height: auto;
                border-radius: 25px;
            }

            .login-imagen {
                min-height: 320px;
                order: -1;
            }

            .login-formulario {
                padding: 45px 50px;
            }

            .marca-login {
                margin-bottom: 30px;
            }
        }

        @media (max-width: 575px) {
            .pagina-login {
                display: block;
                padding: 0;
                background-color: white;
            }

            .login-contenedor {
                width: 100%;
                min-height: 100vh;
                border-radius: 0;
                box-shadow: none;
            }

            .login-imagen {
                min-height: 245px;
            }

            .imagen-contenido {
                right: 23px;
                bottom: 23px;
                left: 23px;
            }

            .imagen-contenido h2 {
                font-size: 2.15rem;
            }

            .imagen-contenido p {
                display: none;
            }

            .login-formulario {
                padding: 35px 25px 45px;
            }

            .marca-login img {
                width: 54px;
                height: 54px;
            }

            .marca-login strong {
                font-size: 18px;
            }

            .login-titulo {
                font-size: 2.35rem;
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

<main class="pagina-login">

    <section class="login-contenedor">

        <div class="login-formulario">

            <div class="marca-login">

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

            <div class="login-etiqueta">
                ACCESO AL SISTEMA
            </div>

            <h1 class="login-titulo">
                Bienvenido a  "Las 3 Palmeras"
            </h1>

            <p class="login-texto">
                Ingresa con tu usuario y contraseña
                para acceder a los servicios del hotel.
            </p>

            <?php if ($mensajeExito !== "") { ?>

                <div class="alerta-exito">

                    <i class="bi bi-check-circle"></i>

                    <span>
                        <?php echo h($mensajeExito); ?>
                    </span>

                </div>

            <?php } ?>

            <?php if ($error !== "") { ?>

                <div class="alerta-error">

                    <i class="bi bi-exclamation-circle"></i>

                    <span>
                        <?php echo h($error); ?>
                    </span>

                </div>

            <?php } ?>

            <form method="POST" autocomplete="on">

                <div class="campo-login">

                    <label for="usuario">
                        Usuario
                    </label>

                    <div class="campo-con-icono">

                        <i class="bi bi-person"></i>

                        <input
                            type="text"
                            id="usuario"
                            name="usuario"
                            class="form-control"
                            value="<?php
                            echo h($usuarioIngresado);
                            ?>"
                            placeholder="Escribe tu usuario"
                            autocomplete="username"
                            required
                            autofocus
                        >

                    </div>

                </div>

                <div class="campo-login">

                    <label for="password">
                        Contraseña
                    </label>

                    <div class="campo-con-icono">

                        <i class="bi bi-lock"></i>

                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            placeholder="Escribe tu contraseña"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="mostrar-password"
                            id="mostrarPassword"
                            aria-label="Mostrar contraseña"
                        >
                            <i class="bi bi-eye"></i>
                        </button>

                    </div>

                </div>

                <div class="recuperar-acceso">
                    <a href="recuperar_password.php">
                        <i class="bi bi-key"></i>
                        ¿Olvidaste tu contraseña?
                    </a>
                </div>

                <button
                    type="submit"
                    name="login"
                    class="btn-ingresar"
                >
                    Iniciar sesión
                </button>

            </form>

            <div class="crear-cuenta">

                ¿Todavía no tienes una cuenta?

                <a href="registro.php">
                    Crear cuenta como cliente
                </a>

            </div>

            <a
                href="index.php"
                class="volver-inicio"
            >
                <i class="bi bi-arrow-left"></i>
                Volver a la página principal
            </a>

        </div>

        <div class="login-imagen">

            <div class="imagen-contenido">

                <div class="imagen-etiqueta">

                    <i class="bi bi-geo-alt"></i>

                    Hotel Las 3 Palmeras

                </div>

                <h2>
                    Tu descanso comienza aquí
                </h2>

                <p>
                    Reserva habitaciones, consulta tus pagos
                    y solicita comidas desde una sola cuenta.
                </p>

            </div>

        </div>

    </section>

</main>

<script>
    const botonPassword =
        document.getElementById(
            "mostrarPassword"
        );

    const campoPassword =
        document.getElementById(
            "password"
        );

    const iconoPassword =
        botonPassword.querySelector("i");

    botonPassword.addEventListener(
        "click",
        () => {
            const mostrar =
                campoPassword.type === "password";

            campoPassword.type =
                mostrar ? "text" : "password";

            iconoPassword.className =
                mostrar
                    ? "bi bi-eye-slash"
                    : "bi bi-eye";

            botonPassword.setAttribute(
                "aria-label",
                mostrar
                    ? "Ocultar contraseña"
                    : "Mostrar contraseña"
            );
        }
    );
</script>

</body>
</html>