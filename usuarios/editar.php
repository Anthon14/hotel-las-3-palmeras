<?php
session_start();
include("../config/conexion.php");

if (!isset($_SESSION["usuario"], $_SESSION["rol"])) {
    header("Location: ../login.php");
    exit();
}

$rolSesion = strtolower(trim((string) $_SESSION["rol"]));

if ($rolSesion !== "administrador") {
    header("Location: ../dashboard.php");
    exit();
}

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

if (
    !isset($_GET["id"]) ||
    !filter_var($_GET["id"], FILTER_VALIDATE_INT)
) {
    header("Location: index.php");
    exit();
}

$idUsuario = (int) $_GET["id"];

if (empty($_SESSION["csrf_editar_usuario"])) {
    $_SESSION["csrf_editar_usuario"] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_editar_usuario"];
$errores = [];

$consultaUsuario = mysqli_prepare(
    $conn,
    "SELECT
        u.id_usuario,
        u.nombre,
        u.usuario,
        u.rol,
        c.id_cliente,
        c.nombres AS nombres_cliente,
        c.apellidos AS apellidos_cliente
     FROM usuarios u
     LEFT JOIN clientes c
        ON c.id_usuario = u.id_usuario
     WHERE u.id_usuario = ?
     LIMIT 1"
);

if (!$consultaUsuario) {
    die("No se pudo consultar el usuario.");
}

mysqli_stmt_bind_param(
    $consultaUsuario,
    "i",
    $idUsuario
);

mysqli_stmt_execute($consultaUsuario);

$resultadoUsuario =
    mysqli_stmt_get_result($consultaUsuario);

$datos =
    mysqli_fetch_assoc($resultadoUsuario);

mysqli_stmt_close($consultaUsuario);

if (!$datos) {
    header("Location: index.php");
    exit();
}

$esCuentaActual =
    $idUsuario ===
    (int) ($_SESSION["id_usuario"] ?? 0);

$tieneCliente =
    !empty($datos["id_cliente"]);

$nombre = $datos["nombre"];
$usuario = $datos["usuario"];
$rol = $datos["rol"];

$rolesPermitidos = [
    "Cliente",
    "Recepcionista",
    "Administrador"
];

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["actualizar"])
) {
    $csrfRecibido = $_POST["csrf"] ?? "";
    $nombre = trim($_POST["nombre"] ?? "");
    $usuario = limpiarUsuario(
        $_POST["usuario"] ?? ""
    );
    $rol = trim($_POST["rol"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmarPassword =
        $_POST["confirmar_password"] ?? "";

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $errores[] =
            "La solicitud no es válida. Actualiza la página.";
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
            "El usuario debe tener entre 4 y 30 caracteres.";
    }

    if (
        !in_array(
            $rol,
            $rolesPermitidos,
            true
        )
    ) {
        $errores[] =
            "Seleccione un rol válido.";
    }

    if (
        $password !== "" &&
        strlen($password) < 8
    ) {
        $errores[] =
            "La nueva contraseña debe tener mínimo 8 caracteres.";
    }

    if ($password !== $confirmarPassword) {
        $errores[] =
            "Las contraseñas no coinciden.";
    }

    if (
        $rol === "Cliente" &&
        !$tieneCliente
    ) {
        $errores[] =
            "Esta cuenta no está relacionada con un huésped. No puede cambiarse a Cliente.";
    }

    if (
        $esCuentaActual &&
        $rol !== $datos["rol"]
    ) {
        $errores[] =
            "No puedes cambiar el rol de la cuenta con la que tienes la sesión iniciada.";
    }

    if (
        $datos["rol"] === "Administrador" &&
        $rol !== "Administrador"
    ) {
        $consultaAdministradores =
            mysqli_prepare(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM usuarios
                 WHERE rol = 'Administrador'
                   AND id_usuario != ?"
            );

        if (!$consultaAdministradores) {
            $errores[] =
                "No se pudo comprobar la cantidad de administradores.";
        } else {
            mysqli_stmt_bind_param(
                $consultaAdministradores,
                "i",
                $idUsuario
            );

            mysqli_stmt_execute(
                $consultaAdministradores
            );

            $resultadoAdministradores =
                mysqli_stmt_get_result(
                    $consultaAdministradores
                );

            $filaAdministradores =
                mysqli_fetch_assoc(
                    $resultadoAdministradores
                );

            mysqli_stmt_close(
                $consultaAdministradores
            );

            if (
                (int) (
                    $filaAdministradores["total"] ?? 0
                ) === 0
            ) {
                $errores[] =
                    "No puedes cambiar al último Administrador del sistema.";
            }
        }
    }

    if (empty($errores)) {
        $verificarUsuario = mysqli_prepare(
            $conn,
            "SELECT id_usuario
             FROM usuarios
             WHERE usuario = ?
               AND id_usuario != ?
             LIMIT 1"
        );

        if (!$verificarUsuario) {
            $errores[] =
                "No se pudo comprobar el nombre de usuario.";
        } else {
            mysqli_stmt_bind_param(
                $verificarUsuario,
                "si",
                $usuario,
                $idUsuario
            );

            mysqli_stmt_execute(
                $verificarUsuario
            );

            $resultadoVerificar =
                mysqli_stmt_get_result(
                    $verificarUsuario
                );

            if (
                mysqli_num_rows(
                    $resultadoVerificar
                ) > 0
            ) {
                $errores[] =
                    "El nombre de usuario ya está registrado.";
            }

            mysqli_stmt_close(
                $verificarUsuario
            );
        }
    }

    if (empty($errores)) {
        if ($password !== "") {
            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $actualizarUsuario =
                mysqli_prepare(
                    $conn,
                    "UPDATE usuarios
                     SET
                        nombre = ?,
                        usuario = ?,
                        password = ?,
                        rol = ?
                     WHERE id_usuario = ?"
                );

            if ($actualizarUsuario) {
                mysqli_stmt_bind_param(
                    $actualizarUsuario,
                    "ssssi",
                    $nombre,
                    $usuario,
                    $passwordHash,
                    $rol,
                    $idUsuario
                );
            }
        } else {
            $actualizarUsuario =
                mysqli_prepare(
                    $conn,
                    "UPDATE usuarios
                     SET
                        nombre = ?,
                        usuario = ?,
                        rol = ?
                     WHERE id_usuario = ?"
                );

            if ($actualizarUsuario) {
                mysqli_stmt_bind_param(
                    $actualizarUsuario,
                    "sssi",
                    $nombre,
                    $usuario,
                    $rol,
                    $idUsuario
                );
            }
        }

        if (!$actualizarUsuario) {
            $errores[] =
                "No se pudo preparar la actualización.";
        } elseif (
            mysqli_stmt_execute(
                $actualizarUsuario
            )
        ) {
            mysqli_stmt_close(
                $actualizarUsuario
            );

            if ($esCuentaActual) {
                $_SESSION["nombre"] = $nombre;
                $_SESSION["usuario"] = $usuario;
            }

            header(
                "Location: index.php?mensaje=actualizado"
            );
            exit();
        } else {
            mysqli_stmt_close(
                $actualizarUsuario
            );

            $errores[] =
                "No se pudo actualizar el usuario.";
        }
    }
}

/* Notificaciones */
$pagosPendientes = 0;
$notificacionesPagos = false;

$consultaCantidadPagos = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total
     FROM pagos
     WHERE estado_pago = 'Pendiente'"
);

if ($consultaCantidadPagos) {
    $filaCantidadPagos =
        mysqli_fetch_assoc($consultaCantidadPagos);

    $pagosPendientes =
        (int) ($filaCantidadPagos["total"] ?? 0);
}

$notificacionesPagos = mysqli_query(
    $conn,
    "SELECT
        p.id_pago,
        p.id_reserva,
        p.metodo_pago,
        p.monto,
        c.id_cliente,
        c.nombres,
        c.apellidos,
        h.numero AS numero_habitacion
     FROM pagos p
     INNER JOIN reservas r
        ON r.id_reserva = p.id_reserva
     INNER JOIN clientes c
        ON c.id_cliente = r.id_cliente
     INNER JOIN habitaciones h
        ON h.id_habitacion = r.id_habitacion
     WHERE p.estado_pago = 'Pendiente'
     ORDER BY
        p.fecha_pago DESC,
        p.id_pago DESC
     LIMIT 6"
);
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
        Editar usuario - Hotel Las 3 Palmeras
    </title>

    <link
        rel="icon"
        type="image/png"
        href="../img/logocircular.png?v=3"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../css/style.css?v=48"
    >

    <style>
        :root {
            --verde: #244a35;
            --verde-oscuro: #173325;
            --verde-claro: #e9f0eb;
            --crema: #f7f3eb;
            --texto-suave: #687068;
            --sombra:
                0 18px 45px
                rgba(21, 45, 32, .12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background-color: var(--crema);
            color: #20231f;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        a {
            text-decoration: none;
        }

        .navbar-hotel {
            min-height: 82px;
            background-color:
                rgba(18, 39, 28, .98);
            border-bottom:
                1px solid
                rgba(255, 255, 255, .13);
            box-shadow:
                0 8px 24px
                rgba(0, 0, 0, .15);
        }

        .marca-hotel {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .navbar-logo {
            width: 54px;
            height: 54px;
            object-fit: contain;
        }

        .marca-texto {
            line-height: 1.05;
        }

        .marca-texto strong {
            display: block;
            color: white;
            font-family: Georgia, serif;
            font-size: 18px;
        }

        .marca-texto small {
            color: #dbc58f;
            font-size: 11px;
            letter-spacing: 1.6px;
        }

        .navbar-hotel .nav-link {
            color:
                rgba(255, 255, 255, .83);
            font-size: 14px;
            font-weight: 700;
            padding: 10px 9px !important;
        }

        .navbar-hotel .nav-link:hover,
        .navbar-hotel .nav-link.active {
            color: white;
        }

        .usuario-navbar {
            color: white;
            font-size: 12px;
            line-height: 1.2;
        }

        .usuario-navbar strong {
            display: block;
            color: #ead8aa;
            font-size: 14px;
        }

        .rol-navbar {
            display: inline-flex;
            margin-top: 4px;
            color:
                rgba(255, 255, 255, .67);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .notificaciones-admin {
            position: relative;
        }

        .btn-notificaciones-admin {
            width: 42px;
            height: 42px;
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, .28);
            border-radius: 50%;
            background: rgba(255, 255, 255, .08);
            color: white;
            font-size: 17px;
        }

        .btn-notificaciones-admin:hover,
        .btn-notificaciones-admin:focus {
            border-color: rgba(240, 217, 159, .75);
            background: rgba(255, 255, 255, .15);
            color: white;
        }

        .contador-notificaciones-admin {
            min-width: 19px;
            height: 19px;
            position: absolute;
            top: -5px;
            right: -5px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
            border: 2px solid var(--verde-oscuro);
            border-radius: 999px;
            background: #cf3f3f;
            color: white;
            font-size: 9px;
            font-weight: 900;
        }

        .menu-notificaciones-admin {
            width: min(390px, calc(100vw - 30px));
            overflow: hidden;
            margin-top: 12px !important;
            padding: 0;
            border: 1px solid #dde2dd;
            border-radius: 12px;
            background: white;
            box-shadow: 0 18px 46px rgba(14, 35, 23, .20);
        }

        .notificaciones-admin-cabecera {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px 18px;
            border-bottom: 1px solid #e8ebe7;
            background: #fbfcfa;
        }

        .notificaciones-admin-cabecera strong {
            display: block;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 17px;
        }

        .notificaciones-admin-cabecera small {
            display: block;
            margin-top: 2px;
            color: var(--texto-suave);
            font-size: 10px;
        }

        .notificaciones-admin-total {
            min-width: 31px;
            height: 31px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 8px;
            border-radius: 999px;
            background: var(--verde);
            color: white;
            font-size: 11px;
            font-weight: 900;
        }

        .notificaciones-admin-lista {
            max-height: 350px;
            overflow-y: auto;
        }

        .notificacion-pago-admin {
            display: block;
            padding: 14px 18px;
            border-bottom: 1px solid #edf0ec;
            color: #20231f;
        }

        .notificacion-pago-admin:hover {
            background: #f4f8f5;
            color: #20231f;
        }

        .notificacion-pago-fila {
            display: flex;
            gap: 11px;
        }

        .notificacion-pago-icono {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: #fff0c7;
            color: #81600d;
        }

        .notificacion-pago-contenido {
            min-width: 0;
            flex: 1;
        }

        .notificacion-pago-contenido strong {
            display: block;
            margin-bottom: 3px;
            color: var(--verde-oscuro);
            font-size: 12px;
        }

        .notificacion-pago-contenido span {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            line-height: 1.5;
        }

        .notificacion-pago-monto {
            margin-top: 4px;
            color: var(--verde) !important;
            font-weight: 900;
        }

        .notificaciones-admin-vacio {
            padding: 28px 20px;
            color: var(--texto-suave);
            text-align: center;
            font-size: 12px;
        }

        .notificaciones-admin-pie {
            padding: 12px 18px;
            border-top: 1px solid #e8ebe7;
            background: #fbfcfa;
        }

        .notificaciones-admin-pie a {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            color: var(--verde);
            font-size: 11px;
            font-weight: 900;
        }

        .pagina-hero {
            min-height: 350px;
            display: flex;
            align-items: center;
            margin-top: 82px;
            color: white;
            background:
                linear-gradient(
                    90deg,
                    rgba(10, 29, 20, .92),
                    rgba(10, 29, 20, .61)
                ),
                url("../img/hotel.jpg")
                center/cover;
        }

        .pagina-hero-contenido {
            max-width: 760px;
            padding: 65px 0;
        }

        .pagina-etiqueta {
            color: #f0d99f;
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 2.5px;
        }

        .pagina-hero h1 {
            margin: 14px 0 17px;
            font-family: Georgia, serif;
            font-size:
                clamp(2.8rem, 6vw, 5rem);
            font-weight: 700;
        }

        .pagina-hero p {
            max-width: 650px;
            color:
                rgba(255, 255, 255, .82);
            line-height: 1.7;
        }

        .contenido-pagina {
            padding: 75px 0;
        }

        .mensaje-error {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
            padding: 14px 17px;
            border:
                1px solid #edc8c8;
            border-radius: 6px;
            background-color: #fff1f1;
            color: #9b3131;
            font-size: 13px;
        }

        .formulario-card {
            overflow: hidden;
            border:
                1px solid #e2e4de;
            border-radius: 8px;
            background-color: white;
            box-shadow: var(--sombra);
        }

        .formulario-cabecera {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 24px 27px;
            border-bottom:
                1px solid #e6e7e1;
            background-color: #fbfcfa;
        }

        .formulario-icono {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            flex: 0 0 48px;
            border-radius: 50%;
            background-color:
                var(--verde-claro);
            color: var(--verde);
            font-size: 21px;
        }

        .formulario-cabecera h3 {
            margin: 0;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
        }

        .formulario-cabecera p {
            margin: 4px 0 0;
            color: var(--texto-suave);
            font-size: 12px;
        }

        .formulario-cuerpo {
            padding: 29px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 900;
        }

        .form-control,
        .form-select {
            min-height: 49px;
            border:
                1px solid #dce1dc;
            background-color: #f7f9f7;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--verde);
            background-color: white;
            box-shadow:
                0 0 0 4px
                rgba(36, 74, 53, .10);
        }

        .form-text {
            color: var(--texto-suave);
            font-size: 11px;
            line-height: 1.6;
        }

        .roles-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .rol-opcion {
            position: relative;
            margin: 0;
        }

        .rol-opcion input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .rol-card {
            min-height: 92px;
            position: relative;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 13px 34px 13px 13px;
            border: 1px solid #dce1dc;
            border-radius: 10px;
            background: #f8faf8;
            cursor: pointer;
            transition: .2s ease;
        }

        .rol-card:hover {
            border-color: #bcc8be;
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(35, 55, 42, .08);
        }

        .rol-icono {
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: var(--verde-claro);
            color: var(--verde);
            font-size: 17px;
        }

        .rol-texto {
            min-width: 0;
            flex: 1;
        }

        .rol-texto strong {
            display: block;
            color: var(--verde-oscuro);
            font-size: 12px;
        }

        .rol-texto small {
            display: block;
            margin-top: 3px;
            color: var(--texto-suave);
            font-size: 9px;
            line-height: 1.4;
        }

        .rol-check {
            position: absolute;
            top: 9px;
            right: 10px;
            color: #c7cec9;
            font-size: 15px;
        }

        .rol-opcion input:checked + .rol-card {
            border: 2px solid var(--verde);
            background: #f3f9f5;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, .08);
        }

        .rol-opcion input:checked + .rol-card .rol-icono {
            background: var(--verde);
            color: white;
        }

        .rol-opcion input:checked + .rol-card .rol-check {
            color: var(--verde);
        }

        .rol-opcion input:disabled + .rol-card {
            opacity: .48;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .rol-opcion input:disabled + .rol-card:hover {
            border-color: #dce1dc;
            background: #f8faf8;
            transform: none;
            box-shadow: none;
        }

        .relacion-card {
            padding: 17px;
            border:
                1px solid #dedfd9;
            border-radius: 8px;
            background-color: #fbfcfa;
        }

        .relacion-card strong {
            color: var(--verde-oscuro);
        }

        .estado {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 8px;
            padding: 7px 11px;
            border-radius: 999px;
            background-color: #dff2e4;
            color: #21643b;
            font-size: 10px;
            font-weight: 900;
        }

        .sin-relacion {
            background-color: #fff0c7;
            color: #81600d;
        }

        .aviso {
            padding: 13px 15px;
            border-radius: 6px;
            background-color: #f5f1e6;
            color: #6e5a2c;
            font-size: 11px;
            line-height: 1.6;
        }

        .botones {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn-actualizar,
        .btn-cancelar {
            min-height: 47px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 20px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 900;
        }

        .btn-actualizar {
            border:
                1px solid var(--verde);
            background-color: var(--verde);
            color: white;
        }

        .btn-actualizar:hover {
            background-color:
                var(--verde-oscuro);
            color: white;
        }

        .btn-cancelar {
            border:
                1px solid #bdc3bd;
            background-color: white;
            color: #555d57;
        }

        .footer-hotel {
            background-color: #13271c;
            color: white;
        }

        .footer-principal {
            padding: 38px 0;
        }

        .footer-logo {
            width: 62px;
            height: 62px;
            object-fit: contain;
        }

        .footer-final {
            padding: 18px 0;
            border-top:
                1px solid
                rgba(255, 255, 255, .10);
            color:
                rgba(255, 255, 255, .52);
            font-size: 12px;
        }

        @media (max-width: 767px) {
            .roles-grid {
                grid-template-columns: 1fr;
            }

            .navbar-hotel {
                min-height: 74px;
            }

            .navbar-logo {
                width: 46px;
                height: 46px;
            }

            .pagina-hero {
                margin-top: 74px;
                text-align: center;
            }

            .contenido-pagina {
                padding: 55px 0;
            }

            .formulario-cuerpo {
                padding: 22px;
            }

            .btn-actualizar,
            .btn-cancelar {
                width: 100%;
            }
        }

        @media (max-width: 420px) {
            .marca-texto {
                display: none;
            }

            .menu-notificaciones-admin {
                position: fixed !important;
                top: 74px !important;
                left: 12px !important;
                right: 12px !important;
                width: auto !important;
                transform: none !important;
            }
        }
    </style>
</head>

<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-hotel fixed-top">

    <div class="container">

        <a
            href="../dashboard.php"
            class="navbar-brand marca-hotel p-0"
        >
            <img
                src="../img/logo.png"
                alt="Hotel Las 3 Palmeras"
                class="navbar-logo"
            >

            <span class="marca-texto">
                <strong>
                    Hotel Las 3 Palmeras
                </strong>

                <small>
                    COMODIDAD Y TRANQUILIDAD
                </small>
            </span>
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#menuPrincipal"
            aria-controls="menuPrincipal"
            aria-expanded="false"
            aria-label="Abrir menú"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div
            class="collapse navbar-collapse"
            id="menuPrincipal"
        >
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">

                <li class="nav-item">
                    <a
                        href="../dashboard.php"
                        class="nav-link"
                    >
                        Inicio
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="../habitaciones/index.php"
                        class="nav-link"
                    >
                        Habitaciones
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="../reservas/index.php"
                        class="nav-link"
                    >
                        Reservas
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="../comidas/index.php"
                        class="nav-link"
                    >
                        Comidas
                    </a>
                </li>

                <li class="nav-item dropdown">

                    <a
                        href="#"
                        class="nav-link dropdown-toggle active"
                        role="button"
                        data-bs-toggle="dropdown"
                    >
                        Administración
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">

                        <li>
                            <a
                                href="../clientes/index.php"
                                class="dropdown-item"
                            >
                                <i class="bi bi-people me-2"></i>
                                Clientes
                            </a>
                        </li>

                        <li>
                            <a
                                href="../pedidos/index.php"
                                class="dropdown-item"
                            >
                                <i class="bi bi-receipt me-2"></i>
                                Pedidos
                            </a>
                        </li>

                        <li>
                            <a
                                href="../pagos/index.php"
                                class="dropdown-item"
                            >
                                <i class="bi bi-credit-card me-2"></i>
                                Pagos
                            </a>
                        </li>

                        <li>
                            <a
                                href="index.php"
                                class="dropdown-item active"
                            >
                                <i class="bi bi-person-gear me-2"></i>
                                Usuarios
                            </a>
                        </li>

                    </ul>

                </li>

            </ul>

            <div class="d-flex flex-wrap align-items-center gap-3">

                <div class="dropdown notificaciones-admin">

                    <button
                        type="button"
                        class="btn-notificaciones-admin"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        aria-label="Notificaciones administrativas"
                        title="Pagos pendientes por revisar"
                    >
                        <i class="bi bi-bell"></i>

                        <?php if ($pagosPendientes > 0) { ?>
                            <span class="contador-notificaciones-admin">
                                <?php
                                echo $pagosPendientes > 99
                                    ? "99+"
                                    : $pagosPendientes;
                                ?>
                            </span>
                        <?php } ?>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end menu-notificaciones-admin">

                        <div class="notificaciones-admin-cabecera">

                            <div>
                                <strong>Pagos por revisar</strong>
                                <small>Pagos pendientes de aprobación</small>
                            </div>

                            <span class="notificaciones-admin-total">
                                <?php echo $pagosPendientes; ?>
                            </span>

                        </div>

                        <div class="notificaciones-admin-lista">

                            <?php if (
                                $notificacionesPagos &&
                                mysqli_num_rows($notificacionesPagos) > 0
                            ) { ?>

                                <?php while (
                                    $notificacionPago =
                                        mysqli_fetch_assoc($notificacionesPagos)
                                ) { ?>

                                    <a
                                        href="../pagos/index.php?cliente=<?php echo (int) $notificacionPago["id_cliente"]; ?>&estado=Pendiente"
                                        class="notificacion-pago-admin"
                                    >
                                        <div class="notificacion-pago-fila">

                                            <span class="notificacion-pago-icono">
                                                <i class="bi bi-receipt"></i>
                                            </span>

                                            <span class="notificacion-pago-contenido">

                                                <strong>Nuevo pago pendiente</strong>

                                                <span>
                                                    <?php
                                                    echo h(
                                                        $notificacionPago["nombres"] .
                                                        " " .
                                                        $notificacionPago["apellidos"]
                                                    );
                                                    ?>
                                                    · Reserva #
                                                    <?php echo (int) $notificacionPago["id_reserva"]; ?>
                                                </span>

                                                <span>
                                                    Habitación
                                                    <?php echo h($notificacionPago["numero_habitacion"]); ?>
                                                    ·
                                                    <?php echo h($notificacionPago["metodo_pago"]); ?>
                                                </span>

                                                <span class="notificacion-pago-monto">
                                                    $<?php
                                                    echo number_format(
                                                        (float) $notificacionPago["monto"],
                                                        2
                                                    );
                                                    ?>
                                                </span>

                                            </span>

                                        </div>
                                    </a>

                                <?php } ?>

                            <?php } else { ?>

                                <div class="notificaciones-admin-vacio">
                                    <i class="bi bi-check2-circle d-block fs-4 mb-2"></i>
                                    No hay pagos pendientes por revisar.
                                </div>

                            <?php } ?>

                        </div>

                        <div class="notificaciones-admin-pie">
                            <a href="../pagos/index.php">
                                <i class="bi bi-credit-card"></i>
                                Ir a gestión de pagos
                            </a>
                        </div>

                    </div>

                </div>

                <div class="usuario-navbar">

                    Bienvenido

                    <strong>
                        <?php echo h($_SESSION["usuario"]); ?>
                    </strong>

                    <span class="rol-navbar">

                        <i class="bi bi-shield-check me-1"></i>

                        <?php echo h($_SESSION["rol"]); ?>

                    </span>

                </div>

                <a
                    href="../logout.php"
                    class="btn btn-outline-light btn-sm rounded-pill px-3"
                >
                    <i class="bi bi-box-arrow-right me-1"></i>
                    Salir
                </a>

            </div>

        </div>

    </div>

</nav>

<section class="pagina-hero">

    <div class="container">

        <div class="pagina-hero-contenido">

            <div class="pagina-etiqueta">
                CONTROL DE ACCESOS
            </div>

            <h1>
                Editar usuario
            </h1>

            <p>
                Cambia el nombre, usuario, contraseña o permisos
                de acceso de esta cuenta.
            </p>

        </div>

    </div>

</section>

<main class="contenido-pagina">

    <div class="container">

        <?php if (!empty($errores)) { ?>

            <div class="mensaje-error">

                <i class="bi bi-exclamation-circle"></i>

                <div>

                    <strong>
                        No se pudo actualizar:
                    </strong>

                    <ul class="mt-2 mb-0">

                        <?php foreach ($errores as $error) { ?>

                            <li>
                                <?php echo h($error); ?>
                            </li>

                        <?php } ?>

                    </ul>

                </div>

            </div>

        <?php } ?>

        <div class="formulario-card">

            <div class="formulario-cabecera">

                <div class="formulario-icono">
                    <i class="bi bi-person-gear"></i>
                </div>

                <div>
                    <h3>
                        <?php echo h($datos["nombre"]); ?>
                    </h3>

                    <p>
                        Usuario número
                        <?php echo $idUsuario; ?>
                    </p>
                </div>

            </div>

            <div class="formulario-cuerpo">

                <form method="POST" autocomplete="off">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?php echo h($csrf); ?>"
                    >

                    <div class="row g-4">

                        <div class="col-md-6">

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
                                maxlength="100"
                                value="<?php echo h($nombre); ?>"
                                required
                            >

                        </div>

                        <div class="col-md-6">

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
                                maxlength="30"
                                value="<?php echo h($usuario); ?>"
                                required
                            >

                        </div>

                        <div class="col-12">

                            <div class="form-label">
                                Rol de acceso
                            </div>

                            <div class="roles-grid">

                                <label class="rol-opcion">

                                    <input
                                        type="radio"
                                        name="rol"
                                        value="Cliente"
                                        <?php echo $rol === "Cliente" ? "checked" : ""; ?>
                                        <?php
                                        echo !$tieneCliente &&
                                             $rol !== "Cliente"
                                            ? "disabled"
                                            : "";
                                        ?>
                                        required
                                    >

                                    <span class="rol-card">

                                        <span class="rol-icono">
                                            <i class="bi bi-person"></i>
                                        </span>

                                        <span class="rol-texto">
                                            <strong>Cliente</strong>
                                            <small>
                                                Acceso para huéspedes registrados.
                                            </small>
                                        </span>

                                        <span class="rol-check">
                                            <i class="bi bi-check-circle-fill"></i>
                                        </span>

                                    </span>

                                </label>

                                <label class="rol-opcion">

                                    <input
                                        type="radio"
                                        name="rol"
                                        value="Recepcionista"
                                        <?php echo $rol === "Recepcionista" ? "checked" : ""; ?>
                                        required
                                    >

                                    <span class="rol-card">

                                        <span class="rol-icono">
                                            <i class="bi bi-person-badge"></i>
                                        </span>

                                        <span class="rol-texto">
                                            <strong>Recepcionista</strong>
                                            <small>
                                                Gestiona huéspedes, reservas y servicios.
                                            </small>
                                        </span>

                                        <span class="rol-check">
                                            <i class="bi bi-check-circle-fill"></i>
                                        </span>

                                    </span>

                                </label>

                                <label class="rol-opcion">

                                    <input
                                        type="radio"
                                        name="rol"
                                        value="Administrador"
                                        <?php echo $rol === "Administrador" ? "checked" : ""; ?>
                                        required
                                    >

                                    <span class="rol-card">

                                        <span class="rol-icono">
                                            <i class="bi bi-shield-lock"></i>
                                        </span>

                                        <span class="rol-texto">
                                            <strong>Administrador</strong>
                                            <small>
                                                Acceso completo a la administración.
                                            </small>
                                        </span>

                                        <span class="rol-check">
                                            <i class="bi bi-check-circle-fill"></i>
                                        </span>

                                    </span>

                                </label>

                            </div>

                            <div class="form-text mt-2">
                                El rol Cliente solo puede usarse cuando la cuenta
                                está relacionada con un huésped.
                            </div>

                        </div>

                        <div class="col-md-6">

                            <label
                                for="password"
                                class="form-label"
                            >
                                Nueva contraseña
                            </label>

                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-control"
                                minlength="8"
                                autocomplete="new-password"
                            >

                            <div class="form-text">
                                Déjala vacía para conservar la contraseña actual.
                            </div>

                        </div>

                        <div class="col-md-6">

                            <label
                                for="confirmar_password"
                                class="form-label"
                            >
                                Confirmar nueva contraseña
                            </label>

                            <input
                                type="password"
                                id="confirmar_password"
                                name="confirmar_password"
                                class="form-control"
                                minlength="8"
                                autocomplete="new-password"
                            >

                        </div>

                        <div class="col-12">

                            <div class="relacion-card">

                                <strong>
                                    Relación con Clientes
                                </strong>

                                <?php if ($tieneCliente) { ?>

                                    <div>

                                        <span class="estado">

                                            <i class="bi bi-link-45deg"></i>

                                            Relacionado con cliente
                                            #<?php echo (int) $datos["id_cliente"]; ?>

                                        </span>

                                    </div>

                                    <div class="form-text mt-2">

                                        Huésped:
                                        <?php
                                        echo h(
                                            trim(
                                                $datos["nombres_cliente"] .
                                                " " .
                                                $datos["apellidos_cliente"]
                                            )
                                        );
                                        ?>

                                        ·

                                        <a
                                            href="../clientes/editar.php?id=<?php echo (int) $datos["id_cliente"]; ?>"
                                        >
                                            Editar datos del huésped
                                        </a>

                                    </div>

                                <?php } else { ?>

                                    <div>

                                        <span class="estado sin-relacion">

                                            <i class="bi bi-person-dash"></i>

                                            Cuenta administrativa sin huésped

                                        </span>

                                    </div>

                                    <div class="form-text mt-2">

                                        Esta cuenta puede ser Recepcionista
                                        o Administrador, pero no Cliente.

                                    </div>

                                <?php } ?>

                            </div>

                        </div>

                        <?php if ($esCuentaActual) { ?>

                            <div class="col-12">

                                <div class="aviso">

                                    <i class="bi bi-shield-lock me-1"></i>

                                    Estás editando tu propia cuenta.
                                    Puedes cambiar el nombre, usuario o
                                    contraseña, pero no tu rol mientras
                                    mantienes esta sesión iniciada.

                                </div>

                            </div>

                        <?php } ?>

                        <div class="col-12">

                            <div class="botones">

                                <button
                                    type="submit"
                                    name="actualizar"
                                    class="btn-actualizar"
                                >
                                    <i class="bi bi-check-circle"></i>
                                    Guardar cambios
                                </button>

                                <a
                                    href="index.php"
                                    class="btn-cancelar"
                                >
                                    <i class="bi bi-arrow-left"></i>
                                    Volver sin guardar
                                </a>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

        </div>

    </div>

</main>

<footer class="footer-hotel mt-5">

    <div class="footer-principal">

        <div class="container d-flex flex-wrap justify-content-between align-items-center gap-4">

            <div class="d-flex align-items-center gap-3">

                <img
                    src="../img/logo.png"
                    alt="Hotel Las 3 Palmeras"
                    class="footer-logo"
                >

                <div>

                    <h4 class="mb-1">
                        Hotel Las 3 Palmeras
                    </h4>

                    <small class="text-white-50">
                        Sistema administrativo hotelero.
                    </small>

                </div>

            </div>

            <a
                href="index.php"
                class="btn btn-outline-light btn-sm"
            >
                Volver a usuarios
            </a>

        </div>

    </div>

    <div class="footer-final">

        <div class="container d-flex justify-content-between flex-wrap gap-2">

            <span>
                Hotel Las 3 Palmeras © 2026
            </span>

            <span>
                Edición de usuarios
            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>
    const campoUsuario =
        document.getElementById("usuario");

    campoUsuario.addEventListener(
        "input",
        () => {
            campoUsuario.value =
                campoUsuario.value
                    .toLowerCase()
                    .normalize("NFD")
                    .replace(
                        /[\u0300-\u036f]/g,
                        ""
                    )
                    .replace(
                        /[^a-z0-9._-]/g,
                        ""
                    );
        }
    );
</script>

</body>

</html>