<?php
session_start();

include("../config/conexion.php");

if (!isset($_SESSION["usuario"], $_SESSION["rol"])) {
    header("Location: ../login.php");
    exit();
}

$rolActual = strtolower(trim((string) $_SESSION["rol"]));

if ($rolActual !== "cliente") {
    header("Location: ../dashboard.php");
    exit();
}

function h($texto)
{
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}

function resolverImagenFirebase(
    $imagen,
    $imagenPredeterminada
) {
    $imagen = trim((string) $imagen);

    if (
        $imagen === "" ||
        !filter_var($imagen, FILTER_VALIDATE_URL)
    ) {
        return $imagenPredeterminada;
    }

    $esquema = strtolower(
        (string) parse_url($imagen, PHP_URL_SCHEME)
    );

    if (!in_array($esquema, ["http", "https"], true)) {
        return $imagenPredeterminada;
    }

    return $imagen;
}

$idUsuario = (int) ($_SESSION["id_usuario"] ?? 0);

if ($idUsuario <= 0) {
    $usuarioSesion =
        trim((string) $_SESSION["usuario"]);

    $buscarUsuario = mysqli_prepare(
        $conn,
        "SELECT id_usuario
         FROM usuarios
         WHERE usuario = ?
           AND LOWER(rol) = 'cliente'
         LIMIT 1"
    );

    if ($buscarUsuario) {
        mysqli_stmt_bind_param(
            $buscarUsuario,
            "s",
            $usuarioSesion
        );

        mysqli_stmt_execute($buscarUsuario);

        $resultadoUsuario =
            mysqli_stmt_get_result($buscarUsuario);

        $filaUsuario =
            mysqli_fetch_assoc($resultadoUsuario);

        mysqli_stmt_close($buscarUsuario);

        if ($filaUsuario) {
            $idUsuario =
                (int) $filaUsuario["id_usuario"];

            $_SESSION["id_usuario"] =
                $idUsuario;
        }
    }
}

$cliente = null;

if ($idUsuario > 0) {
    $buscarCliente = mysqli_prepare(
        $conn,
        "SELECT
            id_cliente,
            nombres,
            apellidos
         FROM clientes
         WHERE id_usuario = ?
         LIMIT 1"
    );

    if ($buscarCliente) {
        mysqli_stmt_bind_param(
            $buscarCliente,
            "i",
            $idUsuario
        );

        mysqli_stmt_execute($buscarCliente);

        $resultadoCliente =
            mysqli_stmt_get_result($buscarCliente);

        $cliente =
            mysqli_fetch_assoc($resultadoCliente);

        mysqli_stmt_close($buscarCliente);
    }
}

if (!$cliente) {
    session_unset();
    session_destroy();

    header(
        "Location: ../login.php?mensaje=cuenta_no_vinculada"
    );
    exit();
}

$nombreCliente = trim(
    (string) $cliente["nombres"] .
    " " .
    (string) $cliente["apellidos"]
);

if ($nombreCliente === "") {
    $nombreCliente =
        (string) $_SESSION["usuario"];
}

$idCliente =
    (int) $cliente["id_cliente"];

/* Notificaciones */
$notificacionesPago = [];

$consultaNotificaciones = mysqli_prepare(
    $conn,
    "SELECT
        p.id_pago,
        p.id_reserva,
        p.estado_pago,
        p.observacion,
        p.monto,
        h.numero AS numero_habitacion
     FROM pagos p
     INNER JOIN reservas r
        ON r.id_reserva = p.id_reserva
     INNER JOIN habitaciones h
        ON h.id_habitacion = r.id_habitacion
     WHERE r.id_cliente = ?
       AND p.estado_pago IN ('Aceptado', 'Rechazado')
     ORDER BY p.id_pago DESC
     LIMIT 8"
);

if ($consultaNotificaciones) {
    mysqli_stmt_bind_param(
        $consultaNotificaciones,
        "i",
        $idCliente
    );

    if (mysqli_stmt_execute($consultaNotificaciones)) {
        $resultadoNotificaciones =
            mysqli_stmt_get_result($consultaNotificaciones);

        while (
            $notificacion =
                mysqli_fetch_assoc($resultadoNotificaciones)
        ) {
            $notificacionesPago[] = $notificacion;
        }
    }

    mysqli_stmt_close($consultaNotificaciones);
}

$idHabitacion = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$idHabitacion || (int) $idHabitacion <= 0) {
    header("Location: index.php#habitaciones");
    exit();
}

$idHabitacion = (int) $idHabitacion;

$consulta = mysqli_prepare(
    $conn,
    "SELECT
        id_habitacion,
        numero,
        tipo,
        precio,
        capacidad,
        estado,
        imagen
     FROM habitaciones
     WHERE id_habitacion = ?
       AND estado <> 'Mantenimiento'
     LIMIT 1"
);

if (!$consulta) {
    header(
        "Location: index.php?mensaje=error_habitacion#habitaciones"
    );
    exit();
}

mysqli_stmt_bind_param(
    $consulta,
    "i",
    $idHabitacion
);

mysqli_stmt_execute($consulta);

$resultado =
    mysqli_stmt_get_result($consulta);

$habitacion =
    mysqli_fetch_assoc($resultado);

mysqli_stmt_close($consulta);

if (!$habitacion) {
    header(
        "Location: index.php?mensaje=no_disponible#habitaciones"
    );
    exit();
}

$rutaImagen =
    resolverImagenFirebase(
        $habitacion["imagen"] ?? "",
        "../img/hotel.jpg"
    );

$estadoHabitacion =
    trim((string) $habitacion["estado"]);

$claseEstado =
    $estadoHabitacion === "Disponible"
        ? "estado-disponible"
        : "estado-ocupada";

$textoEstado =
    $estadoHabitacion === "Disponible"
        ? "Disponible"
        : "Ocupada actualmente";
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
        Habitación <?php echo h($habitacion["numero"]); ?>
        - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=60"
    >

    <style>
        :root {
            --verde: #244a35;
            --verde-oscuro: #173325;
            --verde-claro: #e9f0eb;
            --dorado: #d8b56d;
            --crema: #f7f3eb;
            --texto-suave: #687068;
            --sombra: 0 18px 45px rgba(21, 45, 32, .12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background-color: var(--crema);
            color: #20231f;
            font-family: Arial, Helvetica, sans-serif;
        }

        a {
            text-decoration: none;
        }

        .navbar-hotel {
            min-height: 82px;
            background-color: rgba(18, 39, 28, .98);
            border-bottom: 1px solid rgba(255, 255, 255, .13);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .15);
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
            color: rgba(255, 255, 255, .83);
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

        .notificaciones-cliente {
            position: relative;
        }

        .btn-notificaciones-cliente {
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

        .btn-notificaciones-cliente:hover,
        .btn-notificaciones-cliente:focus {
            border-color: rgba(240, 217, 159, .75);
            background: rgba(255, 255, 255, .15);
            color: white;
        }

        .contador-notificaciones-cliente {
            min-width: 19px;
            height: 19px;
            position: absolute;
            top: -5px;
            right: -5px;
            display: none;
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

        .menu-notificaciones-cliente {
            width: min(390px, calc(100vw - 30px));
            overflow: hidden;
            margin-top: 12px !important;
            padding: 0;
            border: 1px solid #dde2dd;
            border-radius: 12px;
            background: white;
            box-shadow: 0 18px 46px rgba(14, 35, 23, .20);
        }

        .notificaciones-cliente-cabecera {
            padding: 16px 18px;
            border-bottom: 1px solid #e8ebe7;
            background: #fbfcfa;
        }

        .notificaciones-cliente-cabecera strong {
            display: block;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 17px;
        }

        .notificaciones-cliente-cabecera small {
            display: block;
            margin-top: 2px;
            color: var(--texto-suave);
            font-size: 10px;
        }

        .notificaciones-cliente-lista {
            max-height: 350px;
            overflow-y: auto;
        }

        .notificacion-cliente-item {
            display: block;
            padding: 14px 18px;
            border-bottom: 1px solid #edf0ec;
            color: #20231f;
        }

        .notificacion-cliente-item:hover {
            background: #f4f8f5;
            color: #20231f;
        }

        .notificacion-cliente-fila {
            display: flex;
            gap: 11px;
        }

        .notificacion-cliente-icono {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            font-size: 16px;
        }

        .notificacion-cliente-icono.aceptado {
            background: #dff2e4;
            color: #21643b;
        }

        .notificacion-cliente-icono.rechazado {
            background: #fff0f0;
            color: #9d3030;
        }

        .notificacion-cliente-contenido {
            min-width: 0;
            flex: 1;
        }

        .notificacion-cliente-contenido strong {
            display: block;
            margin-bottom: 3px;
            color: var(--verde-oscuro);
            font-size: 12px;
        }

        .notificacion-cliente-contenido span {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            line-height: 1.5;
        }

        .notificacion-cliente-monto {
            margin-top: 4px;
            color: var(--verde) !important;
            font-weight: 900;
        }

        .notificaciones-cliente-vacio {
            padding: 28px 20px;
            color: var(--texto-suave);
            text-align: center;
            font-size: 12px;
        }

        .notificaciones-cliente-pie {
            padding: 12px 18px;
            border-top: 1px solid #e8ebe7;
            background: #fbfcfa;
        }

        .notificaciones-cliente-pie a {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            color: var(--verde);
            font-size: 11px;
            font-weight: 900;
        }

        .pagina-hero {
            min-height: 320px;
            display: flex;
            align-items: center;
            margin-top: 82px;
            color: white;
            background:
                linear-gradient(
                    90deg,
                    rgba(10, 29, 20, .92),
                    rgba(10, 29, 20, .56)
                ),
                url("../img/hotel.jpg") center/cover;
        }

        .pagina-hero-contenido {
            max-width: 760px;
            padding: 60px 0;
        }

        .pagina-etiqueta {
            color: #f0d99f;
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 2.5px;
        }

        .pagina-hero h1 {
            margin: 14px 0 14px;
            font-family: Georgia, serif;
            font-size: clamp(2.7rem, 6vw, 4.8rem);
            font-weight: 700;
        }

        .pagina-hero p {
            max-width: 680px;
            color: rgba(255, 255, 255, .82);
            line-height: 1.7;
        }

        .contenido-pagina {
            padding: 70px 0;
        }

        .btn-volver {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 24px;
            color: var(--verde);
            font-size: 13px;
            font-weight: 900;
        }

        .detalle-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            box-shadow: var(--sombra);
        }

        .imagen-contenedor {
            position: relative;
            height: 100%;
            min-height: 590px;
            background: #e8ece8;
        }

        .imagen-habitacion {
            width: 100%;
            height: 100%;
            min-height: 590px;
            object-fit: cover;
        }

        .numero-flotante {
            position: absolute;
            top: 20px;
            left: 20px;
            padding: 9px 13px;
            border-radius: 999px;
            background: rgba(18, 49, 32, .92);
            color: white;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 1px;
        }

        .detalle-contenido {
            padding: 36px;
        }

        .detalle-contenido h2 {
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
        }

        .estado {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
        }

        .estado-disponible {
            background: #dff2e4;
            color: #21643b;
        }

        .estado-ocupada {
            background: #fff0c7;
            color: #81600d;
        }

        .descripcion {
            color: var(--texto-suave);
            font-size: 14px;
            line-height: 1.75;
        }

        .dato {
            height: 100%;
            padding: 16px;
            border: 1px solid #e1e5df;
            border-radius: 7px;
            background: #f7f9f7;
        }

        .dato small {
            color: var(--texto-suave);
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .dato strong {
            display: block;
            margin-top: 4px;
            color: #303731;
            font-size: 14px;
        }

        .precio {
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 37px;
            font-weight: 700;
        }

        .nota-disponibilidad {
            display: flex;
            gap: 9px;
            margin-top: 18px;
            padding: 13px 15px;
            border: 1px solid #dedfd9;
            border-radius: 6px;
            background: #f7f8f5;
            color: var(--texto-suave);
            font-size: 12px;
            line-height: 1.6;
        }

        .btn-reservar {
            min-height: 50px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 1px solid var(--verde);
            border-radius: 5px;
            background: var(--verde);
            color: white;
            font-size: 13px;
            font-weight: 900;
        }

        .btn-reservar:hover {
            background: var(--verde-oscuro);
            color: white;
        }

        .footer-hotel {
            background: #13271c;
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
            border-top: 1px solid rgba(255, 255, 255, .10);
            color: rgba(255, 255, 255, .52);
            font-size: 12px;
        }

        @media (max-width: 991px) {
            .imagen-contenedor,
            .imagen-habitacion {
                min-height: 360px;
                height: 360px;
            }
        }

        @media (max-width: 767px) {
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
                padding: 52px 0;
            }

            .detalle-contenido {
                padding: 24px;
            }
        }

        @media (max-width: 420px) {
            .marca-texto {
                display: none;
            }

            .menu-notificaciones-cliente {
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
            href="index.php"
            class="navbar-brand marca-hotel p-0"
        >
            <img
                src="../img/logo.png"
                alt="Hotel Las 3 Palmeras"
                class="navbar-logo"
            >

            <span class="marca-texto">
                <strong>Hotel Las 3 Palmeras</strong>
                <small>COMODIDAD Y TRANQUILIDAD</small>
            </span>
        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#menuCliente"
            aria-controls="menuCliente"
            aria-expanded="false"
            aria-label="Abrir menú"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="menuCliente">

            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">

                <li class="nav-item">
                    <a href="index.php" class="nav-link">
                        Inicio
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="index.php#habitaciones"
                        class="nav-link active"
                    >
                        Habitaciones
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="pedir_comida.php"
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
                    >
                        Mi cuenta
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">

                        <li>
                            <a
                                href="mis_reservas.php"
                                class="dropdown-item"
                            >
                                <i class="bi bi-calendar-check me-2"></i>
                                Mis reservas
                            </a>
                        </li>

                        <li>
                            <a
                                href="mis_pedidos.php"
                                class="dropdown-item"
                            >
                                <i class="bi bi-receipt me-2"></i>
                                Mis pedidos
                            </a>
                        </li>

                        <li>
                            <a
                                href="perfil.php"
                                class="dropdown-item"
                            >
                                <i class="bi bi-person me-2"></i>
                                Mi perfil
                            </a>
                        </li>

                    </ul>

                </li>

            </ul>

            <div class="d-flex flex-wrap align-items-center gap-3">

                <div class="dropdown notificaciones-cliente">

                    <button
                        type="button"
                        id="btnNotificacionesCliente"
                        class="btn-notificaciones-cliente"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        aria-label="Notificaciones de pagos"
                        title="Notificaciones"
                    >
                        <i class="bi bi-bell"></i>

                        <span
                            id="contadorNotificacionesCliente"
                            class="contador-notificaciones-cliente"
                        ></span>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end menu-notificaciones-cliente">

                        <div class="notificaciones-cliente-cabecera">
                            <strong>Notificaciones</strong>
                            <small>Avisos importantes de tus pagos</small>
                        </div>

                        <div class="notificaciones-cliente-lista">

                            <?php if (!empty($notificacionesPago)) { ?>

                                <?php foreach (
                                    $notificacionesPago as $notificacion
                                ) { ?>

                                    <?php
                                    $notificacionAceptada =
                                        $notificacion["estado_pago"] === "Aceptado";

                                    $motivoRechazo =
                                        trim(
                                            (string) (
                                                $notificacion["observacion"] ?? ""
                                            )
                                        );
                                    ?>

                                    <a
                                        href="mis_reservas.php"
                                        class="notificacion-cliente-item"
                                        data-notificacion-pago="pago-<?php echo (int) $notificacion["id_pago"]; ?>"
                                    >
                                        <div class="notificacion-cliente-fila">

                                            <span
                                                class="notificacion-cliente-icono <?php echo $notificacionAceptada ? "aceptado" : "rechazado"; ?>"
                                            >
                                                <i
                                                    class="bi <?php echo $notificacionAceptada ? "bi-check-circle" : "bi-x-circle"; ?>"
                                                ></i>
                                            </span>

                                            <span class="notificacion-cliente-contenido">

                                                <strong>
                                                    <?php echo $notificacionAceptada ? "Pago aceptado" : "Pago rechazado"; ?>
                                                </strong>

                                                <span>
                                                    Reserva #
                                                    <?php echo (int) $notificacion["id_reserva"]; ?>
                                                    · Habitación
                                                    <?php echo h($notificacion["numero_habitacion"]); ?>
                                                </span>

                                                <span>
                                                    <?php if ($notificacionAceptada) { ?>
                                                        Tu pago fue aceptado y la reserva quedó confirmada.
                                                    <?php } else { ?>
                                                        Tu pago fue rechazado.
                                                        <?php if ($motivoRechazo !== "") { ?>
                                                            Motivo:
                                                            <?php echo h($motivoRechazo); ?>
                                                        <?php } ?>
                                                    <?php } ?>
                                                </span>

                                                <span class="notificacion-cliente-monto">
                                                    $<?php echo number_format((float) $notificacion["monto"], 2); ?>
                                                </span>

                                            </span>

                                        </div>
                                    </a>

                                <?php } ?>

                            <?php } else { ?>

                                <div class="notificaciones-cliente-vacio">
                                    <i class="bi bi-bell-slash d-block fs-4 mb-2"></i>
                                    Aún no tienes avisos de pagos.
                                </div>

                            <?php } ?>

                        </div>

                        <div class="notificaciones-cliente-pie">
                            <a href="mis_reservas.php">
                                <i class="bi bi-calendar-check"></i>
                                Ver mis reservas
                            </a>
                        </div>

                    </div>

                </div>

                <div class="usuario-navbar">
                    Bienvenido

                    <strong>
                        <?php echo h($nombreCliente); ?>
                    </strong>
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
                DETALLE DE LA HABITACIÓN
            </div>

            <h1>
                Habitación <?php echo h($habitacion["numero"]); ?>
            </h1>

            <p>
                Revisa sus características y luego selecciona
                las fechas de entrada y salida para comprobar
                su disponibilidad real.
            </p>

        </div>
    </div>
</section>

<main class="contenido-pagina">
    <div class="container">

        <a
            href="index.php#habitaciones"
            class="btn-volver"
        >
            <i class="bi bi-arrow-left"></i>
            Volver a habitaciones
        </a>

        <section class="detalle-card">

            <div class="row g-0">

                <div class="col-lg-7">

                    <div class="imagen-contenedor">

                        <img
                            src="<?php echo h($rutaImagen); ?>"
                            alt="Habitación <?php echo h($habitacion["numero"]); ?>"
                            class="imagen-habitacion"
                            loading="lazy"
                            onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                        >

                        <span class="numero-flotante">
                            HABITACIÓN <?php echo h($habitacion["numero"]); ?>
                        </span>

                    </div>

                </div>

                <div class="col-lg-5">

                    <div class="detalle-contenido">

                        <div class="pagina-etiqueta text-success">
                            <?php echo h(strtoupper((string) $habitacion["tipo"])); ?>
                        </div>

                        <h2 class="display-6 mt-2 mb-3">
                            Habitación <?php echo h($habitacion["numero"]); ?>
                        </h2>

                        <span class="estado <?php echo h($claseEstado); ?>">
                            <i class="bi bi-circle-fill"></i>
                            <?php echo h($textoEstado); ?>
                        </span>

                        <p class="descripcion mt-4">
                            Una habitación preparada para ofrecer comodidad
                            durante tu estadía en el Hotel Las 3 Palmeras.
                            La disponibilidad definitiva depende de las fechas
                            que selecciones al realizar la reserva.
                        </p>

                        <div class="row g-3 my-3">

                            <div class="col-6">

                                <div class="dato">

                                    <small>Capacidad</small>

                                    <strong>
                                        <i class="bi bi-people me-1"></i>

                                        <?php
                                        echo (int) $habitacion["capacidad"];
                                        ?>
                                        persona(s)
                                    </strong>

                                </div>

                            </div>

                            <div class="col-6">

                                <div class="dato">

                                    <small>Tipo</small>

                                    <strong>
                                        <i class="bi bi-door-open me-1"></i>
                                        <?php echo h($habitacion["tipo"]); ?>
                                    </strong>

                                </div>

                            </div>

                        </div>

                        <hr class="my-4">

                        <div class="text-muted small">
                            Precio por noche
                        </div>

                        <div class="precio">
                            $<?php
                            echo number_format(
                                (float) $habitacion["precio"],
                                2
                            );
                            ?>
                        </div>

                        <div class="nota-disponibilidad">

                            <i class="bi bi-calendar-check"></i>

                            <div>
                                El estado actual de la habitación no reemplaza
                                la revisión de fechas. El sistema comprobará
                                que no exista otra reserva cruzada.
                            </div>

                        </div>

                        <a
                            href="reservar.php?id=<?php echo $idHabitacion; ?>"
                            class="btn-reservar w-100 mt-4"
                        >
                            <i class="bi bi-calendar-plus"></i>
                            Seleccionar fechas y reservar
                        </a>

                    </div>

                </div>

            </div>

        </section>

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
                        Comodidad y tranquilidad para nuestros huéspedes.
                    </small>
                </div>

            </div>

            <a
                href="mis_reservas.php"
                class="btn btn-outline-light btn-sm"
            >
                Ver mis reservas
            </a>

        </div>

    </div>

    <div class="footer-final">

        <div class="container d-flex justify-content-between flex-wrap gap-2">

            <span>
                Hotel Las 3 Palmeras © 2026
            </span>

            <span>
                Habitación <?php echo h($habitacion["numero"]); ?>
            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>
const claveNotificacionesPago =
    "hotel_notificaciones_pago_cliente_<?php echo (int) $idCliente; ?>";

const btnNotificacionesCliente =
    document.getElementById("btnNotificacionesCliente");

const contadorNotificacionesCliente =
    document.getElementById("contadorNotificacionesCliente");

const elementosNotificacionPago =
    Array.from(
        document.querySelectorAll("[data-notificacion-pago]")
    );

function obtenerNotificacionesVistas() {
    try {
        const guardadas =
            JSON.parse(
                localStorage.getItem(
                    claveNotificacionesPago
                ) || "[]"
            );

        return Array.isArray(guardadas)
            ? guardadas
            : [];
    } catch (error) {
        return [];
    }
}

function actualizarContadorNotificaciones() {
    const vistas = obtenerNotificacionesVistas();

    const noVistas =
        elementosNotificacionPago.filter(
            function (elemento) {
                return !vistas.includes(
                    elemento.dataset.notificacionPago
                );
            }
        );

    if (!contadorNotificacionesCliente) {
        return;
    }

    if (noVistas.length === 0) {
        contadorNotificacionesCliente.style.display =
            "none";
        contadorNotificacionesCliente.textContent =
            "";
        return;
    }

    contadorNotificacionesCliente.style.display =
        "inline-flex";

    contadorNotificacionesCliente.textContent =
        noVistas.length > 99
            ? "99+"
            : String(noVistas.length);
}

function marcarNotificacionesComoVistas() {
    const vistas = obtenerNotificacionesVistas();

    const idsActuales =
        elementosNotificacionPago.map(
            function (elemento) {
                return elemento.dataset.notificacionPago;
            }
        );

    localStorage.setItem(
        claveNotificacionesPago,
        JSON.stringify(
            Array.from(
                new Set([...vistas, ...idsActuales])
            )
        )
    );

    actualizarContadorNotificaciones();
}

if (btnNotificacionesCliente) {
    btnNotificacionesCliente.addEventListener(
        "shown.bs.dropdown",
        marcarNotificacionesComoVistas
    );
}

actualizarContadorNotificaciones();
</script>

</body>

</html>