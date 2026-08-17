<?php
session_start();
include("../config/conexion.php");

if (!isset($_SESSION["usuario"], $_SESSION["rol"])) {
    header("Location: ../login.php");
    exit();
}

$rolActual = strtolower(trim($_SESSION["rol"]));

if ($rolActual === "cliente") {
    header("Location: ../cliente/index.php");
    exit();
}

if (!in_array($rolActual, ["administrador", "recepcionista"], true)) {
    header("Location: ../login.php");
    exit();
}

$esAdministrador = $rolActual === "administrador";

function h($texto)
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

function responderJson($datos)
{
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(
        $datos,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );
    exit();
}

function esImagenFirebase($imagen)
{
    if (!filter_var($imagen, FILTER_VALIDATE_URL)) {
        return false;
    }

    $host = parse_url($imagen, PHP_URL_HOST);

    return in_array(
        $host,
        [
            "firebasestorage.googleapis.com",
            "storage.googleapis.com"
        ],
        true
    );
}

function eliminarImagenLocal($imagen)
{
    $imagen = trim((string) $imagen);

    if ($imagen === "" || filter_var($imagen, FILTER_VALIDATE_URL)) {
        return true;
    }

    $nombreArchivo = basename(
        str_replace("\\", "/", $imagen)
    );

    $rutas = [
        __DIR__ . "/../uploads/" . $nombreArchivo,
        __DIR__ . "/../uploads/habitaciones/" . $nombreArchivo
    ];

    foreach ($rutas as $ruta) {
        if (is_file($ruta)) {
            return unlink($ruta);
        }
    }

    return true;
}

/* Eliminación protegida */
if (empty($_SESSION["csrf_eliminar_habitacion"])) {
    $_SESSION["csrf_eliminar_habitacion"] =
        bin2hex(random_bytes(32));
}

$tokenCsrf =
    $_SESSION["csrf_eliminar_habitacion"];

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["eliminar_mysql"])
) {
    $idPost = $_POST["id"] ?? "";
    $tokenRecibido = $_POST["csrf_token"] ?? "";

    if (
        !is_string($tokenRecibido) ||
        !hash_equals($tokenCsrf, $tokenRecibido)
    ) {
        responderJson([
            "correcto" => false,
            "mensaje" => "La solicitud de eliminación no es válida."
        ]);
    }

    if (!filter_var($idPost, FILTER_VALIDATE_INT)) {
        responderJson([
            "correcto" => false,
            "mensaje" => "El ID de la habitación no es válido."
        ]);
    }

    $idEliminar = (int) $idPost;

    $consultaHabitacion = mysqli_prepare(
        $conn,
        "SELECT
            id_habitacion,
            numero,
            imagen
         FROM habitaciones
         WHERE id_habitacion = ?
         LIMIT 1"
    );

    mysqli_stmt_bind_param(
        $consultaHabitacion,
        "i",
        $idEliminar
    );

    mysqli_stmt_execute($consultaHabitacion);

    $resultadoHabitacion =
        mysqli_stmt_get_result($consultaHabitacion);

    $habitacion =
        mysqli_fetch_assoc($resultadoHabitacion);

    mysqli_stmt_close($consultaHabitacion);

    if (!$habitacion) {
        responderJson([
            "correcto" => false,
            "mensaje" => "La habitación ya no existe."
        ]);
    }

    $consultaReservas = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total
         FROM reservas
         WHERE id_habitacion = ?"
    );

    mysqli_stmt_bind_param(
        $consultaReservas,
        "i",
        $idEliminar
    );

    mysqli_stmt_execute($consultaReservas);

    $resultadoReservas =
        mysqli_stmt_get_result($consultaReservas);

    $filaReservas =
        mysqli_fetch_assoc($resultadoReservas);

    mysqli_stmt_close($consultaReservas);

    $totalReservas =
        (int) ($filaReservas["total"] ?? 0);

    if ($totalReservas > 0) {
        responderJson([
            "correcto" => false,
            "mensaje" =>
                "No se puede eliminar la habitación " .
                $habitacion["numero"] .
                " porque tiene " .
                $totalReservas .
                " reserva(s) relacionada(s). " .
                "Puedes cambiar su estado a Mantenimiento " .
                "para conservar el historial."
        ]);
    }

    $imagenActual = trim(
        (string) ($habitacion["imagen"] ?? "")
    );

    mysqli_begin_transaction($conn);

    $eliminar = mysqli_prepare(
        $conn,
        "DELETE FROM habitaciones
         WHERE id_habitacion = ?"
    );

    mysqli_stmt_bind_param(
        $eliminar,
        "i",
        $idEliminar
    );

    $resultadoEliminar =
        mysqli_stmt_execute($eliminar);

    $filasEliminadas =
        mysqli_stmt_affected_rows($eliminar);

    mysqli_stmt_close($eliminar);

    if (
        !$resultadoEliminar ||
        $filasEliminadas < 1
    ) {
        mysqli_rollback($conn);

        responderJson([
            "correcto" => false,
            "mensaje" =>
                "No se pudo eliminar la habitación. " .
                "Verifica que no tenga información relacionada."
        ]);
    }

    mysqli_commit($conn);

    $imagenLocalEliminada =
        eliminarImagenLocal($imagenActual);

    responderJson([
        "correcto" => true,

        "imagen_firebase" =>
            esImagenFirebase($imagenActual)
                ? $imagenActual
                : "",

        "advertencia" =>
            !$imagenLocalEliminada
                ? "La habitación se eliminó, pero no se pudo borrar la imagen local."
                : ""
    ]);
}

$pagosPendientes = 0;
$notificacionesPagos = false;

if ($esAdministrador) {
    $consultaPagosPendientes = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM pagos
         WHERE estado_pago = 'Pendiente'"
    );

    if ($consultaPagosPendientes) {
        $filaPagosPendientes =
            mysqli_fetch_assoc($consultaPagosPendientes);

        $pagosPendientes =
            (int) ($filaPagosPendientes["total"] ?? 0);
    }

    $notificacionesPagos = mysqli_query(
        $conn,
        "SELECT
            p.id_pago,
            p.id_reserva,
            p.metodo_pago,
            p.monto,
            p.fecha_pago,
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
         ORDER BY p.fecha_pago DESC, p.id_pago DESC
         LIMIT 6"
    );
}

if (
    !isset($_GET["id"]) ||
    !filter_var($_GET["id"], FILTER_VALIDATE_INT)
) {
    header("Location: index.php");
    exit();
}

$id = (int) $_GET["id"];

$consulta = mysqli_prepare(
    $conn,
    "SELECT
        id_habitacion,
        numero,
        tipo,
        estado,
        imagen
     FROM habitaciones
     WHERE id_habitacion = ?
     LIMIT 1"
);

mysqli_stmt_bind_param(
    $consulta,
    "i",
    $id
);

mysqli_stmt_execute($consulta);

$resultado =
    mysqli_stmt_get_result($consulta);

$habitacion =
    mysqli_fetch_assoc($resultado);

mysqli_stmt_close($consulta);

if (!$habitacion) {
    header("Location: index.php");
    exit();
}

$consultaReservas = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total
     FROM reservas
     WHERE id_habitacion = ?"
);

mysqli_stmt_bind_param(
    $consultaReservas,
    "i",
    $id
);

mysqli_stmt_execute($consultaReservas);

$resultadoReservas =
    mysqli_stmt_get_result($consultaReservas);

$filaReservas =
    mysqli_fetch_assoc($resultadoReservas);

mysqli_stmt_close($consultaReservas);

$totalReservas =
    (int) ($filaReservas["total"] ?? 0);

$puedeEliminar =
    $totalReservas === 0;
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
        Eliminar habitación - Hotel Las 3 Palmeras
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

    <style>
        :root {
            --verde-principal: #244a35;
            --verde-oscuro: #173325;
            --verde-claro: #e9f0eb;
            --dorado: #d8b56d;
            --crema: #f7f3eb;
            --texto: #20231f;
            --texto-suave: #687068;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding-top: 82px;
            background:
                linear-gradient(
                    rgba(16, 42, 27, 0.76),
                    rgba(16, 42, 27, 0.76)
                ),
                url("../img/hotel.jpg");

            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: var(--texto);
            font-family: Arial, Helvetica, sans-serif;
        }

        a {
            text-decoration: none;
        }

        .navbar-hotel {
            min-height: 82px;
            background-color: rgba(18, 39, 28, 0.98);
            border-bottom: 1px solid rgba(255, 255, 255, 0.13);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(12px);
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
            font-family: Georgia, "Times New Roman", serif;
            font-size: 18px;
        }

        .marca-texto small {
            color: #dbc58f;
            font-size: 11px;
            letter-spacing: 1.6px;
        }

        .navbar-hotel .nav-link {
            position: relative;
            margin: 0 3px;
            padding: 10px 9px !important;
            color: rgba(255, 255, 255, 0.83);
            font-size: 14px;
            font-weight: 700;
        }

        .navbar-hotel .nav-link:hover,
        .navbar-hotel .nav-link.active {
            color: white;
        }

        .navbar-hotel .nav-link::after {
            content: "";
            position: absolute;
            right: 10px;
            bottom: 3px;
            left: 10px;
            height: 2px;
            background-color: var(--dorado);
            transform: scaleX(0);
            transition: transform 0.2s ease;
        }

        .navbar-hotel .nav-link:hover::after,
        .navbar-hotel .nav-link.active::after {
            transform: scaleX(1);
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
            align-items: center;
            margin-top: 4px;
            color: rgba(255, 255, 255, 0.67);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.7px;
            text-transform: uppercase;
        }

        .btn-salir {
            padding: 9px 15px;
            border-radius: 999px;
            font-weight: 700;
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
            padding: 17px 18px;
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
            background: var(--verde-principal);
            color: white;
            font-size: 11px;
            font-weight: 900;
        }

        .notificaciones-admin-lista {
            max-height: 365px;
            overflow-y: auto;
        }

        .notificacion-pago-admin {
            display: block;
            padding: 15px 18px;
            border-bottom: 1px solid #edf0ec;
            color: #20231f;
        }

        .notificacion-pago-admin:hover {
            background: #f4f8f5;
            color: #20231f;
        }

        .notificacion-pago-fila {
            display: flex;
            align-items: flex-start;
            gap: 11px;
        }

        .notificacion-pago-icono {
            width: 39px;
            height: 39px;
            flex: 0 0 39px;
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
            margin-top: 5px;
            color: var(--verde-principal) !important;
            font-weight: 900;
        }

        .notificaciones-admin-vacio {
            padding: 28px 20px;
            color: var(--texto-suave);
            text-align: center;
            font-size: 12px;
        }

        .notificaciones-admin-vacio i {
            display: block;
            margin-bottom: 8px;
            color: var(--verde-principal);
            font-size: 26px;
        }

        .notificaciones-admin-pie {
            padding: 13px 18px;
            border-top: 1px solid #e8ebe7;
            background: #fbfcfa;
        }

        .notificaciones-admin-pie a {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            color: var(--verde-principal);
            font-size: 11px;
            font-weight: 900;
        }

        .pagina-eliminar {
            min-height: calc(100vh - 82px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 45px 20px;
        }

        .eliminar-card {
            width: min(620px, 100%);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 14px;
            background-color: white;
            box-shadow: 0 25px 65px rgba(0, 0, 0, 0.28);
        }

        .eliminar-cabecera {
            padding: 34px 30px;
            background-color: var(--verde-oscuro);
            color: white;
            text-align: center;
        }

        .eliminar-icono {
            width: 74px;
            height: 74px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.12);
            color: #f0d99f;
            font-size: 31px;
        }

        .eliminar-cabecera h1 {
            margin-bottom: 8px;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 31px;
            font-weight: 700;
        }

        .eliminar-cabecera p {
            margin: 0;
            color: rgba(255, 255, 255, 0.70);
            font-size: 13px;
        }

        .eliminar-cuerpo {
            padding: 34px 30px;
            text-align: center;
        }

        .habitacion-dato {
            margin-bottom: 24px;
            padding: 16px;
            border-radius: 8px;
            background-color: var(--verde-claro);
            color: var(--verde-principal);
        }

        .habitacion-dato strong {
            display: block;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 22px;
        }

        .habitacion-dato span {
            font-size: 12px;
        }

        .spinner-hotel {
            width: 46px;
            height: 46px;
            margin-bottom: 18px;
            color: var(--verde-principal);
        }

        #mensaje {
            margin-bottom: 20px;
            color: var(--texto-suave);
            font-size: 14px;
            line-height: 1.7;
        }

        .alerta-error {
            margin-bottom: 20px;
            padding: 15px;
            border: 1px solid #edc5c5;
            border-radius: 7px;
            background-color: #fff0f0;
            color: #973535;
            font-size: 13px;
            line-height: 1.6;
        }

        .btn-volver {
            min-height: 45px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            border: 1px solid var(--verde-principal);
            border-radius: 4px;
            background-color: var(--verde-principal);
            color: white;
            font-size: 12px;
            font-weight: 900;
        }

        .acciones-eliminar {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 22px;
        }

        .btn-confirmar-eliminar {
            min-height: 45px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            border: 1px solid #a33636;
            border-radius: 4px;
            background: #a33636;
            color: white;
            font-size: 12px;
            font-weight: 900;
        }

        .btn-confirmar-eliminar:hover {
            background: #7f2828;
        }

        .btn-volver:hover {
            background-color: var(--verde-oscuro);
            color: white;
        }

        @media (max-width: 991px) {
            .navbar-collapse {
                padding: 18px 0 14px;
            }

            .navbar-hotel .nav-link {
                padding: 11px 0 !important;
            }

            .usuario-navbar {
                margin-top: 12px;
            }
        }

        @media (max-width: 767px) {
            body {
                padding-top: 74px;
            }

            .navbar-hotel {
                min-height: 74px;
            }

            .navbar-logo {
                width: 46px;
                height: 46px;
            }

            .marca-texto strong {
                font-size: 15px;
            }

            .marca-texto small {
                font-size: 9px;
            }

            .pagina-eliminar {
                min-height: calc(100vh - 74px);
                padding: 25px 15px;
            }

            .eliminar-cabecera,
            .eliminar-cuerpo {
                padding: 28px 22px;
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

            .acciones-eliminar {
                grid-template-columns: 1fr;
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
                        href="index.php"
                        class="nav-link active"
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
                        class="nav-link dropdown-toggle"
                        role="button"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
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

                        <?php if ($esAdministrador) { ?>

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
                                    href="../usuarios/index.php"
                                    class="dropdown-item"
                                >
                                    <i class="bi bi-person-gear me-2"></i>
                                    Usuarios
                                </a>
                            </li>

                        <?php } ?>

                    </ul>

                </li>

            </ul>

            <div class="d-flex flex-wrap align-items-center gap-3">

                <?php if ($esAdministrador) { ?>

                    <div class="dropdown notificaciones-admin">

                        <button
                            type="button"
                            class="btn-notificaciones-admin"
                            data-bs-toggle="dropdown"
                            data-bs-auto-close="outside"
                            aria-expanded="false"
                            aria-label="Centro de notificaciones administrativas"
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

                                                <div class="notificacion-pago-icono">
                                                    <i class="bi bi-receipt"></i>
                                                </div>

                                                <div class="notificacion-pago-contenido">
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
                                                        <?php
                                                        echo (int)
                                                            $notificacionPago["id_reserva"];
                                                        ?>
                                                    </span>

                                                    <span>
                                                        Habitación
                                                        <?php
                                                        echo h(
                                                            $notificacionPago["numero_habitacion"]
                                                        );
                                                        ?>
                                                        ·
                                                        <?php
                                                        echo h(
                                                            $notificacionPago["metodo_pago"]
                                                        );
                                                        ?>
                                                    </span>

                                                    <span class="notificacion-pago-monto">
                                                        $<?php
                                                        echo number_format(
                                                            (float) $notificacionPago["monto"],
                                                            2
                                                        );
                                                        ?>
                                                    </span>
                                                </div>

                                            </div>
                                        </a>

                                    <?php } ?>

                                <?php } else { ?>

                                    <div class="notificaciones-admin-vacio">
                                        <i class="bi bi-check2-circle"></i>
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

                <?php } ?>

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
                    class="btn btn-outline-light btn-sm btn-salir"
                >
                    <i class="bi bi-box-arrow-right me-1"></i>
                    Salir
                </a>

            </div>

        </div>

    </div>

</nav>

<main class="pagina-eliminar">

    <section class="eliminar-card">

        <div class="eliminar-cabecera">

            <div class="eliminar-icono">

                <?php if ($puedeEliminar) { ?>

                    <i class="bi bi-trash3"></i>

                <?php } else { ?>

                    <i class="bi bi-exclamation-triangle"></i>

                <?php } ?>

            </div>

            <h1>
                Eliminar habitación
            </h1>

            <p>
                Verificación segura del registro
            </p>

        </div>

        <div class="eliminar-cuerpo">

            <div class="habitacion-dato">

                <strong>
                    Habitación <?php echo h($habitacion["numero"]); ?>
                </strong>

                <span>
                    <?php echo h($habitacion["tipo"]); ?>
                    ·
                    <?php echo h($habitacion["estado"]); ?>
                </span>

            </div>

            <?php if ($puedeEliminar) { ?>

                <p id="mensaje">
                    Esta acción eliminará definitivamente la habitación.
                    
                </p>

                <div
                    id="cargando"
                    class="spinner-border spinner-hotel d-none"
                    role="status"
                >
                    <span class="visually-hidden">
                        Eliminando...
                    </span>
                </div>

                <div
                    id="error"
                    class="alerta-error d-none"
                ></div>

                <div class="acciones-eliminar">

                    <a
                        id="btnVolver"
                        href="index.php"
                        class="btn-volver"
                    >
                        <i class="bi bi-arrow-left"></i>
                        Cancelar
                    </a>

                    <button
                        type="button"
                        id="btnEliminar"
                        class="btn-confirmar-eliminar"
                    >
                        <i class="bi bi-trash3"></i>
                        Sí, eliminar habitación
                    </button>

                </div>

            <?php } else { ?>

                <div class="alerta-error">

                    <i class="bi bi-exclamation-circle me-1"></i>

                    Esta habitación no puede eliminarse porque tiene

                    <strong>
                        <?php echo $totalReservas; ?>
                        reserva(s)
                    </strong>

                    relacionada(s). Para conservar el historial,
                    cambia su estado a Mantenimiento en lugar de eliminarla.

                </div>

                <a
                    href="index.php"
                    class="btn-volver"
                >
                    <i class="bi bi-arrow-left"></i>
                    Volver a habitaciones
                </a>

            <?php } ?>

        </div>

    </section>

</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<?php if ($puedeEliminar) { ?>

<script type="module">

import {
    storage,
    ref,
    deleteObject
} from "../js/firebase-config.js";

const idHabitacion = <?php echo json_encode($id); ?>;

const tokenCsrf = <?php
echo json_encode(
    $tokenCsrf,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);
?>;

const mensaje =
    document.getElementById("mensaje");

const errorCaja =
    document.getElementById("error");

const cargando =
    document.getElementById("cargando");

const btnVolver =
    document.getElementById("btnVolver");

const btnEliminar =
    document.getElementById("btnEliminar");

function obtenerRutaFirebase(urlImagen) {
    if (!urlImagen) {
        return "";
    }

    try {
        const url = new URL(urlImagen);

        if (url.hostname === "firebasestorage.googleapis.com") {
            const coincidencia =
                url.pathname.match(/\/o\/(.+)$/);

            return coincidencia
                ? decodeURIComponent(coincidencia[1])
                : "";
        }

        if (url.hostname === "storage.googleapis.com") {
            const partes =
                url.pathname
                    .split("/")
                    .filter(Boolean);

            return partes.length > 1
                ? decodeURIComponent(partes.slice(1).join("/"))
                : "";
        }

        return "";
    } catch (error) {
        return "";
    }
}

async function eliminarRegistroMysql() {
    mensaje.textContent =
        "Eliminando la habitación del sistema...";

    const datos = new FormData();

    datos.append(
        "eliminar_mysql",
        "1"
    );

    datos.append(
        "id",
        idHabitacion
    );

    datos.append(
        "csrf_token",
        tokenCsrf
    );

    const respuesta = await fetch(
        "eliminar.php",
        {
            method: "POST",
            body: datos
        }
    );

    const textoRespuesta =
        await respuesta.text();

    let resultado;

    try {
        resultado =
            JSON.parse(textoRespuesta);
    } catch (error) {
        console.error(
            "Respuesta de PHP:",
            textoRespuesta
        );

        throw new Error(
            "El servidor devolvió una respuesta no válida."
        );
    }

    if (!resultado.correcto) {
        throw new Error(
            resultado.mensaje ||
            "No se pudo eliminar la habitación."
        );
    }

    return resultado;
}

async function eliminarImagenFirebase(enlaceImagen) {
    if (!enlaceImagen) {
        return;
    }

    mensaje.textContent =
        "Eliminando la imagen de Firebase...";

    const rutaFirebase =
        obtenerRutaFirebase(enlaceImagen);

    if (!rutaFirebase) {
        console.warn(
            "No se pudo identificar la ruta de Firebase."
        );

        return;
    }

    try {
        const referenciaImagen =
            ref(storage, rutaFirebase);

        await deleteObject(
            referenciaImagen
        );

    } catch (error) {
        console.warn("No se pudo limpiar la imagen de Firebase.");
    }
}

async function iniciarEliminacion() {
    try {
        btnVolver.classList.add("d-none");

        const resultado =
            await eliminarRegistroMysql();

        await eliminarImagenFirebase(
            resultado.imagen_firebase
        );

        mensaje.textContent =
            "Habitación eliminada correctamente.";

        window.location.replace(
            "index.php?mensaje=eliminado"
        );

    } catch (error) {
        cargando.classList.add("d-none");
        errorCaja.classList.remove("d-none");
        errorCaja.textContent = error.message;

        mensaje.textContent =
            "No se completó la eliminación.";

        btnEliminar.disabled = false;
        btnEliminar.innerHTML =
            '<i class="bi bi-trash3"></i> Sí, eliminar habitación';
    }
}

btnEliminar.addEventListener("click", async function () {
    btnEliminar.disabled = true;
    btnEliminar.innerHTML =
        '<span class="spinner-border spinner-border-sm"></span> Eliminando';

    cargando.classList.remove("d-none");
    errorCaja.classList.add("d-none");

    await iniciarEliminacion();
});

</script>

<?php } ?>

</body>
</html>