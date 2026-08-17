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

function resolverImagen(
    $imagen,
    $subcarpeta,
    $imagenPredeterminada
) {

    unset($subcarpeta);

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
    $buscarUsuario = mysqli_prepare(
        $conn,
        "SELECT id_usuario
         FROM usuarios
         WHERE usuario = ?
           AND rol = 'Cliente'
         LIMIT 1"
    );

    if ($buscarUsuario) {
        $usuarioSesion = (string) $_SESSION["usuario"];

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

$clienteActual = null;

if ($idUsuario > 0) {
    $buscarCliente = mysqli_prepare(
        $conn,
        "SELECT
            id_cliente,
            nombres,
            apellidos,
            cedula,
            telefono,
            correo
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

        $clienteActual =
            mysqli_fetch_assoc($resultadoCliente);

        mysqli_stmt_close($buscarCliente);
    }
}

if (!$clienteActual) {
    session_unset();
    session_destroy();

    header(
        "Location: ../login.php?mensaje=cuenta_no_vinculada"
    );
    exit();
}

$idCliente =
    (int) $clienteActual["id_cliente"];

$nombreCliente = trim(
    (string) $clienteActual["nombres"] .
    " " .
    (string) $clienteActual["apellidos"]
);

if ($nombreCliente === "") {
    $nombreCliente =
        (string) $_SESSION["usuario"];
}

$resumenCliente = [
    "total_reservas" => 0,
    "pedidos_activos" => 0,
    "pagos_pendientes" => 0
];

$consultaResumen = mysqli_prepare(
    $conn,
    "SELECT
        (
            SELECT COUNT(*)
            FROM reservas
            WHERE id_cliente = ?
        ) AS total_reservas,

        (
            SELECT COUNT(*)
            FROM pedidos_comida
            WHERE id_cliente = ?
              AND estado IN ('Pendiente', 'Preparando')
        ) AS pedidos_activos,

        (
            SELECT COUNT(*)
            FROM pagos p
            INNER JOIN reservas r
                ON r.id_reserva = p.id_reserva
            WHERE r.id_cliente = ?
              AND p.estado_pago = 'Pendiente'
        ) AS pagos_pendientes"
);

if ($consultaResumen) {
    mysqli_stmt_bind_param(
        $consultaResumen,
        "iii",
        $idCliente,
        $idCliente,
        $idCliente
    );

    mysqli_stmt_execute($consultaResumen);

    $resultadoResumen =
        mysqli_stmt_get_result($consultaResumen);

    $filaResumen =
        mysqli_fetch_assoc($resultadoResumen);

    if ($filaResumen) {
        $resumenCliente = $filaResumen;
    }

    mysqli_stmt_close($consultaResumen);
}

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

    mysqli_stmt_execute($consultaNotificaciones);

    $resultadoNotificaciones =
        mysqli_stmt_get_result($consultaNotificaciones);

    while (
        $notificacion =
            mysqli_fetch_assoc($resultadoNotificaciones)
    ) {
        $notificacionesPago[] = $notificacion;
    }

    mysqli_stmt_close($consultaNotificaciones);
}

$totalNotificacionesPago =
    count($notificacionesPago);

$habitaciones = mysqli_query(
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
     WHERE estado = 'Disponible'
     ORDER BY id_habitacion DESC"
);

$errorHabitaciones = "";
$totalHabitacionesDisponibles = 0;
$habitacionesDisponibles = [];

if (!$habitaciones) {
    $errorHabitaciones =
        "No se pudieron cargar las habitaciones.";
} else {
    while (
        $filaHabitacion = mysqli_fetch_assoc(
            $habitaciones
        )
    ) {
        $habitacionesDisponibles[] =
            $filaHabitacion;
    }

    $totalHabitacionesDisponibles =
        count($habitacionesDisponibles);
}

$imagenPresentacionPrincipal = "../img/hotel.jpg";
$imagenPresentacionSecundaria = "../img/hotel.jpg";

$imagenesPresentacion = [];

foreach (
    $habitacionesDisponibles as $habitacionDisponible
) {
    $imagenPresentacion = resolverImagen(
        $habitacionDisponible["imagen"] ?? "",
        "habitaciones",
        "../img/hotel.jpg"
    );

    if (
        $imagenPresentacion !== "../img/hotel.jpg" &&
        !in_array(
            $imagenPresentacion,
            $imagenesPresentacion,
            true
        )
    ) {
        $imagenesPresentacion[] =
            $imagenPresentacion;
    }
}

if (isset($imagenesPresentacion[0])) {
    $imagenPresentacionPrincipal =
        $imagenesPresentacion[0];
}

if (isset($imagenesPresentacion[1])) {
    $imagenPresentacionSecundaria =
        $imagenesPresentacion[1];
} elseif (isset($imagenesPresentacion[0])) {
    $imagenPresentacionSecundaria =
        $imagenesPresentacion[0];
}

$comidas = mysqli_query(
    $conn,
    "SELECT
        id_comida,
        nombre,
        tipo,
        descripcion,
        precio,
        estado,
        imagen
     FROM comidas
     WHERE estado = 'Disponible'
     ORDER BY id_comida DESC
     LIMIT 3"
);

$totalComidasDisponibles = 0;

if ($comidas) {
    $totalComidasDisponibles =
        mysqli_num_rows($comidas);
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
        Hotel Las 3 Palmeras
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
        href="../css/style.css?v=30"
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
            --blanco: #ffffff;

            --sombra:
                0 18px 45px
                rgba(21, 45, 32, 0.13);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 88px;
        }

        body {
            margin: 0;
            overflow-x: hidden;
            background-color: var(--crema);
            color: var(--texto);
            font-family: Arial, Helvetica, sans-serif;
        }

        a {
            text-decoration: none;
        }

        .titulo-elegante {
            font-family:
                Georgia,
                "Times New Roman",
                serif;
        }

        .navbar-hotel {
            min-height: 82px;

            background-color:
                rgba(18, 39, 28, 0.90);

            border-bottom:
                1px solid
                rgba(255, 255, 255, 0.13);

            backdrop-filter: blur(12px);

            transition:
                background-color 0.25s ease,
                box-shadow 0.25s ease;
        }

        .navbar-hotel.navbar-con-sombra {
            background-color:
                rgba(18, 39, 28, 0.98);

            box-shadow:
                0 8px 24px
                rgba(0, 0, 0, 0.18);
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

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 18px;
            letter-spacing: 0.3px;
        }

        .marca-texto small {
            color: #dbc58f;
            font-size: 11px;
            letter-spacing: 1.6px;
        }

        .navbar-hotel .nav-link {
            position: relative;
            color: rgba(255, 255, 255, 0.83);

            font-size: 14px;
            font-weight: 700;

            margin: 0 4px;

            padding:
                10px 11px !important;
        }

        .navbar-hotel .nav-link:hover,
        .navbar-hotel .nav-link.active {
            color: white;
        }

        .navbar-hotel .nav-link::after {
            content: "";

            position: absolute;

            left: 12px;
            right: 12px;
            bottom: 3px;

            height: 2px;

            background-color: var(--dorado);

            transform: scaleX(0);

            transition:
                transform 0.2s ease;
        }

        .navbar-hotel .nav-link:hover::after,
        .navbar-hotel .nav-link.active::after {
            transform: scaleX(1);
        }

        .usuario-navbar {
            color: white;

            font-size: 13px;
            line-height: 1.15;
        }

        .usuario-navbar strong {
            display: block;
            color: #ead8aa;
            font-size: 14px;
        }

        .btn-salir {
            border-radius: 999px;

            padding:
                9px 15px;

            font-weight: 700;
        }

        .notificaciones-dropdown {
            position: relative;
        }

        .btn-notificaciones {
            position: relative;

            width: 42px;
            height: 42px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            padding: 0;

            border:
                1px solid
                rgba(255, 255, 255, 0.38);

            border-radius: 50%;

            background-color:
                rgba(255, 255, 255, 0.08);

            color: white;

            font-size: 18px;
        }

        .btn-notificaciones:hover,
        .btn-notificaciones:focus {
            border-color: #ead8aa;

            background-color:
                rgba(255, 255, 255, 0.16);

            color: #f0d99f;
        }

        .notificaciones-contador {
            position: absolute;

            top: -5px;
            right: -5px;

            min-width: 19px;
            height: 19px;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 0 5px;

            border:
                2px solid
                var(--verde-oscuro);

            border-radius: 999px;

            background-color: #d9534f;
            color: white;

            font-size: 10px;
            font-weight: 900;
        }

        .menu-notificaciones {
            width: min(390px, calc(100vw - 28px));

            max-height: 470px;
            overflow-y: auto;

            padding: 0;

            border: none;
            border-radius: 9px;

            box-shadow:
                0 22px 55px
                rgba(12, 34, 22, 0.24);
        }

        .notificaciones-cabecera {
            padding: 17px 18px;

            border-bottom:
                1px solid #e9ebe6;

            background-color:
                var(--verde-oscuro);

            color: white;
        }

        .notificaciones-cabecera strong {
            display: block;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 18px;
        }

        .notificaciones-cabecera small {
            color:
                rgba(255, 255, 255, 0.68);
        }

        .notificacion-pago {
            display: flex;
            gap: 12px;

            padding: 15px 17px;

            border-bottom:
                1px solid #eceee9;

            background-color: white;
        }

        .notificacion-pago:last-of-type {
            border-bottom: none;
        }

        .notificacion-icono {
            flex: 0 0 38px;

            width: 38px;
            height: 38px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 50%;

            font-size: 17px;
        }

        .notificacion-aceptada
        .notificacion-icono {
            background-color: #e4f4e8;
            color: #26713f;
        }

        .notificacion-rechazada
        .notificacion-icono {
            background-color: #fff0f0;
            color: #a83a3a;
        }

        .notificacion-contenido {
            min-width: 0;
            flex: 1;
        }

        .notificacion-contenido strong {
            display: block;

            margin-bottom: 4px;

            color: var(--verde-oscuro);

            font-size: 13px;
        }

        .notificacion-contenido p {
            margin: 0;

            color: var(--texto-suave);

            font-size: 12px;
            line-height: 1.5;
        }

        .notificacion-motivo {
            margin-top: 6px !important;

            color: #8f3434 !important;

            font-weight: 700;
        }

        .notificaciones-vacio {
            padding: 25px 18px;

            color: var(--texto-suave);

            font-size: 13px;
            text-align: center;
        }

        .notificaciones-pie {
            padding: 12px 16px;

            border-top:
                1px solid #e9ebe6;

            background-color: #f8faf7;

            text-align: center;
        }

        .notificaciones-pie a {
            color: var(--verde-principal);

            font-size: 12px;
            font-weight: 900;
        }

        .hero-cliente {
            min-height: 100vh;

            position: relative;

            display: flex;
            align-items: center;

            overflow: visible;

            color: white;

            background:
                linear-gradient(
                    90deg,
                    rgba(10, 29, 20, 0.88) 0%,
                    rgba(10, 29, 20, 0.69) 47%,
                    rgba(10, 29, 20, 0.28) 100%
                ),
                url("../img/hotel.jpg");

            background-size: cover;
            background-position: center;
        }

        .hero-cliente::before {
            content: "";

            position: absolute;
            inset: 0;

            background:
                radial-gradient(
                    circle at 82% 27%,
                    rgba(216, 181, 109, 0.14),
                    transparent 30%
                );

            pointer-events: none;
        }

        .hero-contenido {
            position: relative;
            z-index: 2;

            max-width: 770px;

            padding-top: 145px;
            padding-bottom: 180px;
        }

        .hero-etiqueta {
            display: inline-flex;
            align-items: center;
            gap: 10px;

            margin-bottom: 20px;

            color: #f0d99f;

            font-size: 13px;
            font-weight: 800;

            letter-spacing: 3px;
        }

        .hero-etiqueta::before {
            content: "";

            width: 44px;
            height: 2px;

            background-color: var(--dorado);
        }

        .hero-titulo {
            max-width: 720px;

            margin-bottom: 24px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size:
                clamp(
                    3.2rem,
                    7.4vw,
                    6.6rem
                );

            font-weight: 700;
            line-height: 0.98;

            letter-spacing: -2px;

            text-shadow:
                0 8px 24px
                rgba(0, 0, 0, 0.27);
        }

        .hero-titulo span {
            display: block;
            color: #f0d99f;
        }

        .hero-texto {
            max-width: 650px;

            margin-bottom: 34px;

            color:
                rgba(255, 255, 255, 0.88);

            font-size:
                clamp(
                    1rem,
                    1.8vw,
                    1.2rem
                );

            line-height: 1.75;
        }

        .hero-botones {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
        }

        .btn-hotel-principal,
        .btn-hotel-claro,
        .btn-hotel-borde {
            min-height: 52px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            gap: 9px;

            border-radius: 4px;

            padding:
                13px 24px;

            font-size: 14px;
            font-weight: 800;

            letter-spacing: 0.4px;

            transition:
                transform 0.2s ease,
                background-color 0.2s ease,
                border-color 0.2s ease;
        }

        .btn-hotel-principal {
            border:
                1px solid
                var(--dorado);

            background-color:
                var(--dorado);

            color: #203026;
        }

        .btn-hotel-principal:hover {
            background-color: #e7c882;
            border-color: #e7c882;
            color: #203026;

            transform:
                translateY(-2px);
        }

        .btn-hotel-claro {
            border: 1px solid white;
            background-color: white;
            color: var(--verde-oscuro);
        }

        .btn-hotel-claro:hover {
            background-color: #f2f2f2;
            color: var(--verde-oscuro);

            transform:
                translateY(-2px);
        }

        .btn-hotel-borde {
            border:
                1px solid
                rgba(255, 255, 255, 0.68);

            background-color:
                rgba(255, 255, 255, 0.07);

            color: white;
        }

        .btn-hotel-borde:hover {
            border-color: white;

            background-color:
                rgba(255, 255, 255, 0.17);

            color: white;

            transform:
                translateY(-2px);
        }

        .hero-datos {
            display: flex;
            flex-wrap: wrap;

            gap: 28px;

            margin-top: 38px;
        }

        .hero-dato {
            display: flex;
            align-items: center;
            gap: 11px;

            color:
                rgba(255, 255, 255, 0.82);

            font-size: 13px;
        }

        .hero-dato i {
            color: #f0d99f;
            font-size: 19px;
        }

        .atajos-flotantes {
            position: absolute;
            z-index: 4;

            left: 50%;
            bottom: -63px;

            width:
                min(
                    1120px,
                    calc(100% - 40px)
                );

            transform:
                translateX(-50%);
        }

        .atajos-contenedor {
            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            background-color: white;

            border-radius: 7px;

            box-shadow: var(--sombra);

            overflow: hidden;
        }

        .atajo-card {
            min-height: 126px;

            display: flex;
            align-items: center;

            gap: 15px;

            padding:
                25px 23px;

            color: var(--texto);

            border-right:
                1px solid #e7e5df;

            transition:
                background-color 0.2s ease,
                color 0.2s ease;
        }

        .atajo-card:last-child {
            border-right: none;
        }

        .atajo-card:hover {
            background-color:
                var(--verde-principal);

            color: white;
        }

        .atajo-icono {
            flex: 0 0 48px;

            width: 48px;
            height: 48px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 50%;

            background-color:
                var(--verde-claro);

            color:
                var(--verde-principal);

            font-size: 21px;

            transition:
                background-color 0.2s ease,
                color 0.2s ease;
        }

        .atajo-card:hover .atajo-icono {
            background-color:
                rgba(255, 255, 255, 0.15);

            color: #f2d89b;
        }

        .atajo-card strong {
            display: block;

            margin-bottom: 5px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 18px;
        }

        .atajo-card small {
            color: var(--texto-suave);
            font-size: 12px;
        }

        .atajo-card:hover small {
            color:
                rgba(255, 255, 255, 0.75);
        }

        .seccion {
            padding:
                96px 0;
        }

        .seccion-primera {
            padding-top: 150px;
        }

        .seccion-blanca {
            background-color: white;
        }

        .seccion-verde-claro {
            background-color:
                var(--verde-claro);
        }

        .seccion-etiqueta {
            margin-bottom: 12px;

            color: #9b7739;

            font-size: 12px;
            font-weight: 900;

            letter-spacing: 2.4px;

            text-transform: uppercase;
        }

        .seccion-titulo {
            margin-bottom: 17px;

            color: var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size:
                clamp(
                    2.2rem,
                    4.5vw,
                    3.6rem
                );

            font-weight: 700;
            line-height: 1.1;
        }

        .seccion-texto {
            color: var(--texto-suave);

            font-size: 16px;
            line-height: 1.8;
        }

        .presentacion-imagen {
            position: relative;
            min-height: 525px;
        }

        .presentacion-imagen-principal {
            width: 86%;
            height: 455px;

            object-fit: cover;

            border-radius: 5px;

            box-shadow: var(--sombra);
        }

        .presentacion-imagen-secundaria {
            position: absolute;

            right: 0;
            bottom: 0;

            width: 47%;
            height: 230px;

            object-fit: cover;

            border:
                9px solid
                var(--crema);

            border-radius: 5px;

            box-shadow: var(--sombra);
        }

        .sello-hotel {
            position: absolute;

            right: 14%;
            top: 25px;

            width: 105px;
            height: 105px;

            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;

            border-radius: 50%;

            background-color:
                var(--dorado);

            color: #223127;

            text-align: center;

            box-shadow: var(--sombra);
        }

        .sello-hotel strong {
            font-size: 25px;
            line-height: 1;
        }

        .sello-hotel small {
            max-width: 70px;

            margin-top: 5px;

            font-size: 9px;
            font-weight: 800;
            line-height: 1.15;

            letter-spacing: 0.5px;

            text-transform: uppercase;
        }

        .lista-beneficios {
            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap:
                14px 20px;

            padding: 0;

            margin:
                28px 0 32px;

            list-style: none;
        }

        .lista-beneficios li {
            display: flex;
            align-items: center;

            gap: 10px;

            color: #3d473f;

            font-size: 14px;
            font-weight: 700;
        }

        .lista-beneficios i {
            color: #a88242;
            font-size: 18px;
        }

        .habitacion-card {
            height: 100%;

            overflow: hidden;

            border: none;
            border-radius: 6px;

            background-color: white;

            box-shadow:
                0 12px 32px
                rgba(29, 52, 38, 0.10);

            transition:
                transform 0.24s ease,
                box-shadow 0.24s ease;
        }

        .habitacion-card:hover {
            transform:
                translateY(-7px);

            box-shadow:
                0 21px 43px
                rgba(29, 52, 38, 0.17);
        }

        .habitacion-imagen-contenedor {
            position: relative;
            overflow: hidden;
        }

        .habitacion-imagen {
            width: 100%;
            height: 275px;

            object-fit: cover;

            transition:
                transform 0.45s ease;
        }

        .habitacion-card:hover
        .habitacion-imagen {
            transform:
                scale(1.045);
        }

        .estado-disponible {
            position: absolute;

            top: 17px;
            right: 17px;

            padding:
                7px 12px;

            border-radius: 3px;

            background-color:
                rgba(25, 77, 47, 0.93);

            color: white;

            font-size: 11px;
            font-weight: 900;

            letter-spacing: 0.7px;

            text-transform: uppercase;
        }

        .habitacion-card .card-body {
            padding: 25px;
        }

        .habitacion-numero {
            margin-bottom: 7px;

            color: #9b7739;

            font-size: 11px;
            font-weight: 900;

            letter-spacing: 1.8px;

            text-transform: uppercase;
        }

        .habitacion-tipo {
            color: var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 25px;
            font-weight: 700;
        }

        .habitacion-detalles {
            display: flex;
            align-items: center;

            gap: 18px;

            padding:
                16px 0;

            margin:
                16px 0;

            border-top:
                1px solid #ece9e1;

            border-bottom:
                1px solid #ece9e1;

            color: var(--texto-suave);

            font-size: 13px;
        }

        .habitacion-detalles span {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .habitacion-detalles i {
            color: #a88242;
        }

        .habitacion-precio small {
            display: block;

            color: var(--texto-suave);

            font-size: 11px;
            font-weight: 700;

            text-transform: uppercase;
        }

        .habitacion-precio strong {
            color: var(--verde-principal);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 27px;
        }

        .habitacion-acciones {
            display: flex;
            gap: 9px;

            margin-top: 19px;
        }

        .btn-tarjeta,
        .btn-tarjeta-secundario {
            flex: 1;

            min-height: 43px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border-radius: 3px;

            padding:
                10px 13px;

            font-size: 12px;
            font-weight: 900;

            transition:
                0.2s ease;
        }

        .btn-tarjeta {
            background-color:
                var(--verde-principal);

            border:
                1px solid
                var(--verde-principal);

            color: white;
        }

        .btn-tarjeta:hover {
            background-color:
                var(--verde-oscuro);

            border-color:
                var(--verde-oscuro);

            color: white;
        }

        .btn-tarjeta-secundario {
            background-color: transparent;

            border:
                1px solid #b6bcb7;

            color:
                var(--verde-principal);
        }

        .btn-tarjeta-secundario:hover {
            background-color:
                var(--verde-claro);

            border-color:
                var(--verde-principal);

            color:
                var(--verde-principal);
        }

        .servicio-card {
            height: 100%;

            padding:
                31px 25px;

            border:
                1px solid #dedfd9;

            border-radius: 5px;

            background-color: white;

            transition:
                transform 0.2s ease,
                border-color 0.2s ease,
                box-shadow 0.2s ease;
        }

        .servicio-card:hover {
            transform:
                translateY(-5px);

            border-color: #cbb174;

            box-shadow:
                0 16px 35px
                rgba(28, 53, 37, 0.10);
        }

        .servicio-icono {
            width: 55px;
            height: 55px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 20px;

            border-radius: 50%;

            background-color:
                var(--verde-claro);

            color:
                var(--verde-principal);

            font-size: 23px;
        }

        .servicio-card h3 {
            color: var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 22px;
            font-weight: 700;
        }

        .servicio-card p {
            margin: 0;

            color: var(--texto-suave);

            font-size: 14px;
            line-height: 1.7;
        }

        .comidas-promocion {
            position: relative;
            overflow: hidden;

            color: white;

            background:
                linear-gradient(
                    90deg,
                    rgba(18, 49, 33, 0.96),
                    rgba(18, 49, 33, 0.82)
                ),
                url("../img/hotel.jpg");

            background-size: cover;
            background-position: center;
        }

        .comidas-promocion::after {
            content: "";

            position: absolute;

            width: 310px;
            height: 310px;

            right: -85px;
            top: -105px;

            border:
                1px solid
                rgba(216, 181, 109, 0.21);

            border-radius: 50%;
        }

        .comidas-promocion
        .seccion-etiqueta {
            color: #f0d99f;
        }

        .comidas-promocion
        .seccion-titulo {
            color: white;
        }

        .comidas-promocion
        .seccion-texto {
            color:
                rgba(255, 255, 255, 0.76);
        }

        .comida-mini-card {
            height: 100%;

            overflow: hidden;

            border-radius: 5px;

            background-color: white;

            color: var(--texto);

            box-shadow:
                0 14px 33px
                rgba(0, 0, 0, 0.18);
        }

        .comida-mini-card img {
            width: 100%;
            height: 170px;

            object-fit: cover;
        }

        .comida-mini-card-contenido {
            padding: 18px;
        }

        .comida-mini-tipo {
            color: #9b7739;

            font-size: 10px;
            font-weight: 900;

            letter-spacing: 1.5px;

            text-transform: uppercase;
        }

        .comida-mini-card h3 {
            margin:
                6px 0 9px;

            color:
                var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 19px;
            font-weight: 700;
        }

        .comida-mini-precio {
            color:
                var(--verde-principal);

            font-size: 18px;
            font-weight: 900;
        }

        .paso-card {
            position: relative;

            height: 100%;

            padding:
                30px 25px;

            border-radius: 5px;

            background-color: white;

            box-shadow:
                0 11px 29px
                rgba(27, 50, 35, 0.08);
        }

        .paso-numero {
            width: 48px;
            height: 48px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 18px;

            border-radius: 50%;

            background-color:
                var(--dorado);

            color: #243126;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 20px;
            font-weight: 700;
        }

        .paso-card h3 {
            color:
                var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 21px;
            font-weight: 700;
        }

        .paso-card p {
            margin: 0;

            color:
                var(--texto-suave);

            font-size: 14px;
            line-height: 1.7;
        }

        .cuenta-card {
            height: 100%;

            position: relative;

            overflow: hidden;

            padding:
                31px 27px;

            border-radius: 5px;

            background-color: white;

            box-shadow:
                0 13px 31px
                rgba(27, 50, 35, 0.09);
        }

        .cuenta-card::after {
            content: "";

            position: absolute;

            right: -28px;
            bottom: -35px;

            width: 110px;
            height: 110px;

            border-radius: 50%;

            background-color:
                rgba(36, 74, 53, 0.05);
        }

        .cuenta-icono {
            width: 52px;
            height: 52px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 18px;

            border-radius: 50%;

            background-color:
                var(--verde-principal);

            color: white;

            font-size: 21px;
        }

        .cuenta-card h3 {
            color:
                var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 22px;
            font-weight: 700;
        }

        .cuenta-card p {
            min-height: 68px;

            color:
                var(--texto-suave);

            font-size: 14px;
            line-height: 1.7;
        }

        .enlace-cuenta {
            position: relative;
            z-index: 2;

            display: inline-flex;
            align-items: center;

            gap: 8px;

            color:
                var(--verde-principal);

            font-size: 13px;
            font-weight: 900;
        }

        .enlace-cuenta:hover {
            color: #966f2f;
        }

        .footer-hotel {
            background-color: #13271c;
            color: white;
        }

        .footer-principal {
            padding:
                65px 0 45px;
        }

        .footer-logo {
            width: 68px;
            height: 68px;
            object-fit: contain;
        }

        .footer-marca h2 {
            margin-bottom: 8px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 25px;
        }

        .footer-marca p,
        .footer-hotel li,
        .footer-hotel a {
            color:
                rgba(255, 255, 255, 0.66);

            font-size: 13px;
        }

        .footer-hotel a:hover {
            color: #f0d99f;
        }

        .footer-titulo {
            margin-bottom: 17px;

            color: white;

            font-size: 13px;
            font-weight: 900;

            letter-spacing: 1.2px;

            text-transform: uppercase;
        }

        .footer-lista {
            padding: 0;
            margin: 0;
            list-style: none;
        }

        .footer-lista li {
            margin-bottom: 10px;
        }

        .footer-final {
            padding:
                20px 0;

            border-top:
                1px solid
                rgba(255, 255, 255, 0.10);

            color:
                rgba(255, 255, 255, 0.52);

            font-size: 12px;
        }

        /* Responsive */

        @media (max-width: 1199px) {

            .atajo-card {
                padding:
                    22px 17px;
            }

            .atajo-card strong {
                font-size: 16px;
            }

            .presentacion-imagen-principal {
                width: 90%;
            }
        }

        @media (max-width: 991px) {
            
            .hero-cliente {
             display: block;
             min-height: auto;
              overflow: visible;
            }

            .navbar-hotel {
                background-color:
                    rgba(18, 39, 28, 0.98);
            }

            .navbar-collapse {
                padding:
                    18px 0 14px;
            }

            .navbar-hotel .nav-link {
                padding:
                    11px 0 !important;
            }

            .navbar-hotel
            .nav-link::after {
                left: 0;
                right: auto;
                width: 42px;
            }

            .usuario-navbar {
                margin-top: 12px;
            }

            .notificaciones-dropdown {
                margin-top: 10px;
            }

            .menu-notificaciones {
                width:
                    min(
                        390px,
                        calc(100vw - 24px)
                    );
            }

            .hero-cliente {
                min-height: auto;
                background-position: 62% center;
            }

            .hero-contenido {
                padding-top: 145px;
                padding-bottom: 95px;
            }

            .atajos-flotantes {
                position: relative;

                left: auto;
                bottom: auto;

                width: 100%;

                transform: none;

                background-color: white;
            }

            .atajos-contenedor {
                grid-template-columns:
                    repeat(2, 1fr);

                border-radius: 0;

                box-shadow: none;
            }

            .atajo-card:nth-child(2) {
                border-right: none;
            }

            .atajo-card:nth-child(-n + 2) {
                border-bottom:
                    1px solid #e7e5df;
            }

            .seccion-primera {
                padding-top: 90px;
            }

            .presentacion-imagen {
                margin-bottom: 45px;
            }

            .presentacion-imagen-principal {
                width: 87%;
            }
        }

        /* Responsive */

        @media (max-width: 767px) {

            html {
                scroll-padding-top: 78px;
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

            .hero-cliente {
                background:
                    linear-gradient(
                        rgba(10, 29, 20, 0.80),
                        rgba(10, 29, 20, 0.75)
                    ),
                    url("../img/hotel.jpg");

                background-size: cover;
                background-position: center;
            }

            .hero-contenido {
                padding-top: 125px;
                padding-bottom: 70px;

                text-align: center;
            }

            .hero-etiqueta {
                justify-content: center;

                font-size: 11px;
                letter-spacing: 2px;
            }

            .hero-etiqueta::before {
                width: 28px;
            }

            .hero-titulo {
                margin-left: auto;
                margin-right: auto;

                font-size:
                    clamp(
                        2.75rem,
                        14vw,
                        4.1rem
                    );

                letter-spacing: -1px;
            }

            .hero-texto {
                margin-left: auto;
                margin-right: auto;

                font-size: 15px;
                line-height: 1.65;
            }

            .hero-botones {
                justify-content: center;
            }

            .btn-hotel-principal,
            .btn-hotel-claro,
            .btn-hotel-borde {
                width: 100%;
                max-width: 330px;
            }

            .hero-datos {
                justify-content: center;
                gap: 17px;
            }

            .atajos-contenedor {
                grid-template-columns: 1fr;
            }

            .atajo-card,
            .atajo-card:nth-child(2) {
                min-height: 100px;

                border-right: none;

                border-bottom:
                    1px solid #e7e5df;
            }

            .atajo-card:last-child {
                border-bottom: none;
            }

            .seccion,
            .seccion-primera {
                padding:
                    70px 0;
            }

            .seccion-titulo {
                font-size:
                    clamp(
                        2rem,
                        10vw,
                        2.65rem
                    );
            }

            .presentacion-imagen {
                min-height: 420px;
            }

            .presentacion-imagen-principal {
                width: 92%;
                height: 350px;
            }

            .presentacion-imagen-secundaria {
                width: 54%;
                height: 170px;

                border-width: 6px;
            }

            .sello-hotel {
                right: 4%;

                width: 88px;
                height: 88px;
            }

            .sello-hotel strong {
                font-size: 21px;
            }

            .lista-beneficios {
                grid-template-columns: 1fr;
            }

            .habitacion-imagen {
                height: 235px;
            }

            .habitacion-acciones {
                flex-direction: column;
            }

            .habitacion-detalles {
                flex-wrap: wrap;
            }

            .cuenta-card p {
                min-height: auto;
            }

            .footer-principal {
                padding-top: 50px;
            }
        }

        @media (max-width: 420px) {

            .marca-texto {
                display: none;
            }

            .menu-notificaciones {
                position: fixed !important;

                top: 74px !important;
                left: 12px !important;
                right: 12px !important;

                width: auto !important;

                transform: none !important;
            }

            .hero-titulo {
                font-size: 2.75rem;
            }

            .hero-datos {
                flex-direction: column;
                align-items: center;
            }

            .presentacion-imagen {
                min-height: 360px;
            }

            .presentacion-imagen-principal {
                width: 100%;
                height: 300px;
            }

            .presentacion-imagen-secundaria {
                width: 58%;
                height: 145px;
            }

            .sello-hotel {
                top: 16px;
                right: 2%;
            }
        }

    </style>

</head>

<body>

<nav
    class="navbar navbar-expand-lg navbar-dark navbar-hotel fixed-top"
>

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
            data-bs-target="#menuCliente"
            aria-controls="menuCliente"
            aria-expanded="false"
            aria-label="Abrir menú"
        >

            <span class="navbar-toggler-icon"></span>

        </button>

        <div
            class="collapse navbar-collapse"
            id="menuCliente"
        >

            <ul
                class="navbar-nav mx-auto mb-2 mb-lg-0"
            >

                <li class="nav-item">

                    <a
                        href="#inicio"
                        class="nav-link active"
                    >
                        Inicio
                    </a>

                </li>

                <li class="nav-item">

                    <a
                        href="#habitaciones"
                        class="nav-link"
                    >
                        Habitaciones
                    </a>

                </li>

                <li class="nav-item">

                    <a
                        href="#servicios"
                        class="nav-link"
                    >
                        Servicios
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
                        aria-expanded="false"
                    >
                        Mi cuenta
                    </a>

                    <ul
                        class="dropdown-menu dropdown-menu-end"
                    >

                        <li>

                            <a
                                href="mis_reservas.php"
                                class="dropdown-item"
                            >

                                <i
                                    class="bi bi-calendar-check me-2"
                                ></i>

                                Mis reservas

                            </a>

                        </li>

                        <li>

                            <a
                                href="mis_pedidos.php"
                                class="dropdown-item"
                            >

                                <i
                                    class="bi bi-receipt me-2"
                                ></i>

                                Mis pedidos

                            </a>

                        </li>

                        <li>

                            <a
                                href="perfil.php"
                                class="dropdown-item"
                            >

                                <i
                                    class="bi bi-person me-2"
                                ></i>

                                Mi perfil

                            </a>

                        </li>

                    </ul>

                </li>

            </ul>

            <div
                class="d-flex flex-wrap align-items-center gap-3"
            >

                <div class="dropdown notificaciones-dropdown">

                    <button
                        type="button"
                        class="btn-notificaciones"
                        id="botonNotificaciones"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        aria-label="Centro de notificaciones"
                    >
                        <i
                            class="bi bi-bell"
                            title="Centro de notificaciones"
                        ></i>

                        <?php if (
                            $totalNotificacionesPago > 0
                        ) { ?>

                            <span
                                class="notificaciones-contador"
                                id="contadorNotificaciones"
                            >
                                <?php
                                echo $totalNotificacionesPago;
                                ?>
                            </span>

                        <?php } ?>

                    </button>

                    <div
                        class="dropdown-menu dropdown-menu-end menu-notificaciones"
                        aria-labelledby="botonNotificaciones"
                    >

                        <div class="notificaciones-cabecera">
                            <strong>Centro de notificaciones</strong>
                            <small>
                                Avisos importantes al ingresar a tu cuenta
                            </small>
                        </div>

                        <?php if (
                            $totalNotificacionesPago > 0
                        ) { ?>

                            <?php foreach (
                                $notificacionesPago
                                as $notificacion
                            ) { ?>

                                <?php
                                $esAceptada =
                                    $notificacion[
                                        "estado_pago"
                                    ] === "Aceptado";

                                $idNotificacion =
                                    "pago-" .
                                    (int) $notificacion[
                                        "id_pago"
                                    ];
                                ?>

                                <div
                                    class="notificacion-pago <?php echo $esAceptada ? "notificacion-aceptada" : "notificacion-rechazada"; ?>"
                                    data-notificacion-id="<?php echo h($idNotificacion); ?>"
                                >

                                    <div class="notificacion-icono">

                                        <i
                                            class="bi <?php echo $esAceptada ? "bi-check-circle" : "bi-x-circle"; ?>"
                                        ></i>

                                    </div>

                                    <div class="notificacion-contenido">

                                        <strong>
                                            Reserva
                                            #<?php
                                            echo (int)
                                                $notificacion[
                                                    "id_reserva"
                                                ];
                                            ?>
                                            · Habitación
                                            <?php
                                            echo h(
                                                $notificacion[
                                                    "numero_habitacion"
                                                ]
                                            );
                                            ?>
                                        </strong>

                                        <p>
                                            <?php if ($esAceptada) { ?>

                                                Tu pago de
                                                $<?php
                                                echo number_format(
                                                    (float)
                                                    $notificacion[
                                                        "monto"
                                                    ],
                                                    2
                                                );
                                                ?>
                                                fue aceptado y tu
                                                reserva fue confirmada.

                                            <?php } else { ?>

                                                Tu pago fue rechazado.
                                                Revisa el motivo antes
                                                de volver a registrarlo.

                                            <?php } ?>
                                        </p>

                                        <?php if (
                                            !$esAceptada &&
                                            trim(
                                                (string)
                                                $notificacion[
                                                    "observacion"
                                                ]
                                            ) !== ""
                                        ) { ?>

                                            <p class="notificacion-motivo">
                                                Motivo:
                                                <?php
                                                echo h(
                                                    $notificacion[
                                                        "observacion"
                                                    ]
                                                );
                                                ?>
                                            </p>

                                        <?php } ?>

                                    </div>

                                </div>

                            <?php } ?>

                        <?php } else { ?>

                            <div class="notificaciones-vacio">
                                <i class="bi bi-bell-slash me-1"></i>
                                No tienes notificaciones de pagos.
                            </div>

                        <?php } ?>

                        <div class="notificaciones-pie">

                            <div class="small text-muted mb-2">
                                Tus avisos estarán disponibles
                                mientras navegas por tu cuenta.
                            </div>

                            <a href="mis_reservas.php">
                                Ver mis reservas
                                <i class="bi bi-arrow-right ms-1"></i>
                            </a>

                        </div>

                    </div>

                </div>

                <div class="usuario-navbar">

                    Bienvenido

                    <strong>

                        <?php
                        echo h(
                            $nombreCliente
                        );
                        ?>

                    </strong>

                </div>

                <a
                    href="../logout.php"
                    class="btn btn-outline-light btn-sm btn-salir"
                >

                    <i
                        class="bi bi-box-arrow-right me-1"
                    ></i>

                    Salir

                </a>

            </div>

        </div>

    </div>

</nav>

<main>

    <section
        class="hero-cliente"
        id="inicio"
    >

        <div class="container">

            <div class="hero-contenido">

                <div class="hero-etiqueta">
                    BIENVENIDO, <?php echo h(strtoupper($clienteActual["nombres"])); ?>
                </div>

                <h1 class="hero-titulo">

                    Descansa, disfruta y

                    <span>
                        siéntete como en casa
                    </span>

                </h1>

                <p class="hero-texto">

                    En el Hotel Las 3 Palmeras puedes
                    revisar habitaciones, realizar reservas,
                    consultar tus pagos y solicitar comidas
                    desde una sola cuenta.

                </p>

                <div class="hero-botones">

                    <a
                        href="#habitaciones"
                        class="btn-hotel-principal"
                    >

                        <i class="bi bi-door-open"></i>

                        Ver habitaciones

                    </a>

                    <a
                        href="mis_reservas.php"
                        class="btn-hotel-claro"
                    >

                        <i
                            class="bi bi-calendar2-check"
                        ></i>

                        Mis reservas

                    </a>

                    <a
                        href="pedir_comida.php"
                        class="btn-hotel-borde"
                    >

                        <i class="bi bi-cup-hot"></i>

                        Ver comidas

                    </a>

                </div>

                <div class="hero-datos">

                    <div class="hero-dato">

                        <i
                            class="bi bi-check-circle"
                        ></i>

                        Reserva desde tu cuenta

                    </div>

                    <div class="hero-dato">

                        <i
                            class="bi bi-shield-check"
                        ></i>

                        Consulta segura de pagos

                    </div>

                    <div class="hero-dato">

                        <i class="bi bi-phone"></i>

                        Disponible en celular

                    </div>

                </div>

            </div>

        </div>

        <div class="atajos-flotantes">

            <div class="atajos-contenedor">

                <a
                    href="#habitaciones"
                    class="atajo-card"
                >

                    <span class="atajo-icono">

                        <i
                            class="bi bi-building-check"
                        ></i>

                    </span>

                    <span>

                        <strong>
                            Habitaciones
                        </strong>

                        <small>

                            <?php
                            echo $totalHabitacionesDisponibles;
                            ?>

                            disponibles

                        </small>

                    </span>

                </a>

                <a
                    href="pedir_comida.php"
                    class="atajo-card"
                >

                    <span class="atajo-icono">

                        <i
                            class="bi bi-egg-fried"
                        ></i>

                    </span>

                    <span>

                        <strong>
                            Comidas
                        </strong>

                        <small>
                            Revisa el menú del hotel
                        </small>

                    </span>

                </a>

                <a
                    href="mis_reservas.php"
                    class="atajo-card"
                >

                    <span class="atajo-icono">

                        <i
                            class="bi bi-calendar-heart"
                        ></i>

                    </span>

                    <span>

                        <strong>
                            Mis reservas
                        </strong>

                        <small>
                            <?php
                            echo (int)
                                $resumenCliente[
                                    "total_reservas"
                                ];
                            ?>
                            reserva(s) registrada(s)
                        </small>

                    </span>

                </a>

                <a
                    href="mis_pedidos.php"
                    class="atajo-card"
                >

                    <span class="atajo-icono">

                        <i
                            class="bi bi-receipt-cutoff"
                        ></i>

                    </span>

                    <span>

                        <strong>
                            Mis pedidos
                        </strong>

                        <small>
                            <?php
                            echo (int)
                                $resumenCliente[
                                    "pedidos_activos"
                                ];
                            ?>
                            pedido(s) activo(s)
                        </small>

                    </span>

                </a>

            </div>

        </div>

    </section>

    <section class="seccion seccion-primera">

        <div class="container">

            <div
                class="row align-items-center g-5"
            >

                <div class="col-lg-6">

                    <div class="presentacion-imagen">

                        <img
                            src="<?php echo h($imagenPresentacionPrincipal); ?>"
                            alt="Habitación destacada del Hotel Las 3 Palmeras"
                            class="presentacion-imagen-principal"
                            onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                        >

                        <img
                            src="<?php echo h($imagenPresentacionSecundaria); ?>"
                            alt="Otra habitación disponible del Hotel Las 3 Palmeras"
                            class="presentacion-imagen-secundaria"
                            onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                        >

                        <div class="sello-hotel">

                            <strong>
                                3
                            </strong>

                            <small>
                                Palmeras para tu descanso
                            </small>

                        </div>

                    </div>

                </div>

                <div class="col-lg-6">

                    <p class="seccion-etiqueta">
                        TU EXPERIENCIA EN EL HOTEL
                    </p>

                    <h2 class="seccion-titulo">

                        Todo lo que necesitas desde
                        una sola página

                    </h2>

                    <p class="seccion-texto">

                        Tu cuenta te permite encontrar
                        habitaciones, realizar una reserva,
                        registrar el pago y consultar el
                        estado de cada servicio sin perder
                        tiempo.

                    </p>

                    <p class="seccion-texto">

                        También puedes revisar las comidas
                        disponibles, realizar pedidos y
                        mantener actualizados tus datos
                        personales durante la estadía.

                    </p>

                    <ul class="lista-beneficios">

                        <li>

                            <i
                                class="bi bi-check2-circle"
                            ></i>

                            Habitaciones disponibles

                        </li>

                        <li>

                            <i
                                class="bi bi-check2-circle"
                            ></i>

                            Reservas desde tu cuenta

                        </li>

                        <li>

                            <i
                                class="bi bi-check2-circle"
                            ></i>

                            Consulta de pagos

                        </li>

                        <li>

                            <i
                                class="bi bi-check2-circle"
                            ></i>

                            Pedidos de comida

                        </li>

                    </ul>

                    <a
                        href="perfil.php"
                        class="btn-hotel-principal"
                    >

                        <i
                            class="bi bi-person-circle"
                        ></i>

                        Revisar mi perfil

                    </a>

                </div>

            </div>

        </div>

    </section>

    <section
        class="seccion seccion-blanca"
        id="habitaciones"
    >

        <div class="container">

            <div
                class="row align-items-end mb-5 g-3"
            >

                <div class="col-lg-8">

                    <p class="seccion-etiqueta">
                        ALOJAMIENTO
                    </p>

                    <h2
                        class="seccion-titulo mb-2"
                    >
                        Habitaciones disponibles
                    </h2>

                    <p
                        class="seccion-texto mb-0"
                    >

                        Escoge una habitación, revisa
                        sus detalles y realiza la reserva
                        directamente desde tu cuenta.

                    </p>

                </div>

                <div
                    class="col-lg-4 text-lg-end"
                >

                    <span
                        class="badge rounded-pill text-bg-light px-3 py-2"
                    >

                        <i
                            class="bi bi-door-open me-1"
                        ></i>

                        <?php
                        echo $totalHabitacionesDisponibles;
                        ?>

                        disponibles

                    </span>

                </div>

            </div>

            <?php if (
                $errorHabitaciones !== ""
            ) { ?>

                <div class="alert alert-danger">

                    <?php
                    echo h($errorHabitaciones);
                    ?>

                </div>

            <?php } ?>

            <?php if (
                $habitaciones &&
                mysqli_num_rows(
                    $habitaciones
                ) > 0
            ) { ?>

                <div class="row g-4">

                    <?php foreach (
                        $habitacionesDisponibles as $habitacion
                    ) { ?>

                        <?php

                        $rutaImagenHabitacion =
                            resolverImagen(
                                $habitacion[
                                    'imagen'
                                ] ?? "",
                                "habitaciones",
                                "../img/hotel.jpg"
                            );

                        ?>

                        <div
                            class="col-md-6 col-xl-4"
                        >

                            <article
                                class="habitacion-card"
                            >

                                <div
                                    class="habitacion-imagen-contenedor"
                                >

                                    <img
                                        src="<?php
                                        echo h(
                                            $rutaImagenHabitacion
                                        );
                                        ?>"
                                        alt="Habitación <?php
                                        echo h(
                                            $habitacion[
                                                'numero'
                                            ]
                                        );
                                        ?>"
                                        class="habitacion-imagen"
                                        loading="lazy"
                                        onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                                    >

                                    <span
                                        class="estado-disponible"
                                    >
                                        Disponible
                                    </span>

                                </div>

                                <div class="card-body">

                                    <div
                                        class="habitacion-numero"
                                    >

                                        Habitación

                                        <?php
                                        echo h(
                                            $habitacion[
                                                'numero'
                                            ]
                                        );
                                        ?>

                                    </div>

                                    <h3
                                        class="habitacion-tipo"
                                    >

                                        <?php
                                        echo h(
                                            $habitacion[
                                                'tipo'
                                            ]
                                        );
                                        ?>

                                    </h3>

                                    <div
                                        class="habitacion-detalles"
                                    >

                                        <span>

                                            <i
                                                class="bi bi-people"
                                            ></i>

                                            <?php
                                            echo (int)
                                                $habitacion[
                                                    'capacidad'
                                                ];
                                            ?>

                                            persona(s)

                                        </span>

                                        <span>

                                            <i
                                                class="bi bi-wifi"
                                            ></i>

                                            Servicio del hotel

                                        </span>

                                    </div>

                                    <div
                                        class="d-flex justify-content-between align-items-end gap-3"
                                    >

                                        <div
                                            class="habitacion-precio"
                                        >

                                            <small>
                                                Precio por noche
                                            </small>

                                            <strong>

                                                $<?php
                                                echo number_format(
                                                    (float)
                                                    $habitacion[
                                                        'precio'
                                                    ],
                                                    2
                                                );
                                                ?>

                                            </strong>

                                        </div>

                                    </div>

                                    <div
                                        class="habitacion-acciones"
                                    >

                                        <a
                                            href="ver_habitacion.php?id=<?php
                                            echo (int)
                                                $habitacion[
                                                    'id_habitacion'
                                                ];
                                            ?>"
                                            class="btn-tarjeta-secundario"
                                        >
                                            Ver detalles
                                        </a>

                                        <a
                                            href="reservar.php?id=<?php
                                            echo (int)
                                                $habitacion[
                                                    'id_habitacion'
                                                ];
                                            ?>"
                                            class="btn-tarjeta"
                                        >
                                            Reservar
                                        </a>

                                    </div>

                                </div>

                            </article>

                        </div>

                    <?php } ?>

                </div>

            <?php } else { ?>

                <div
                    class="alert alert-info text-center p-4"
                >

                    En este momento no existen
                    habitaciones disponibles.

                </div>

            <?php } ?>

        </div>

    </section>

    <section
        class="seccion seccion-verde-claro"
        id="servicios"
    >

        <div class="container">

            <div
                class="text-center mx-auto mb-5"
                style="max-width: 760px;"
            >

                <p class="seccion-etiqueta">
                    SERVICIOS PARA TU ESTADÍA
                </p>

                <h2 class="seccion-titulo">

                    Una experiencia cómoda
                    y organizada

                </h2>

                <p
                    class="seccion-texto mb-0"
                >

                    Accede a los principales
                    servicios del hotel desde cualquier
                    computadora, tablet o teléfono.

                </p>

            </div>

            <div class="row g-4">

                <div
                    class="col-sm-6 col-lg-3"
                >

                    <div class="servicio-card">

                        <div
                            class="servicio-icono"
                        >

                            <i
                                class="bi bi-door-open"
                            ></i>

                        </div>

                        <h3>
                            Habitaciones
                        </h3>

                        <p>

                            Revisa tipos, precios,
                            capacidad e imágenes antes
                            de realizar tu reserva.

                        </p>

                    </div>

                </div>

                <div
                    class="col-sm-6 col-lg-3"
                >

                    <div class="servicio-card">

                        <div
                            class="servicio-icono"
                        >

                            <i
                                class="bi bi-calendar2-check"
                            ></i>

                        </div>

                        <h3>
                            Reservas
                        </h3>

                        <p>

                            Consulta las fechas, el total,
                            el estado de la reserva y la
                            información del pago.

                        </p>

                    </div>

                </div>

                <div
                    class="col-sm-6 col-lg-3"
                >

                    <div class="servicio-card">

                        <div
                            class="servicio-icono"
                        >

                            <i
                                class="bi bi-cup-hot"
                            ></i>

                        </div>

                        <h3>
                            Comidas
                        </h3>

                        <p>

                            Encuentra desayunos, almuerzos,
                            cenas, bebidas y extras
                            disponibles.

                        </p>

                    </div>

                </div>

                <div
                    class="col-sm-6 col-lg-3"
                >

                    <div class="servicio-card">

                        <div
                            class="servicio-icono"
                        >

                            <i
                                class="bi bi-headset"
                            ></i>

                        </div>

                        <h3>
                            Atención
                        </h3>

                        <p>

                            Mantén organizada tu información
                            y consulta el estado de los
                            servicios solicitados.

                        </p>

                    </div>

                </div>

            </div>

        </div>

    </section>

    <section
        class="seccion comidas-promocion"
        id="comidas"
    >

        <div
            class="container position-relative"
            style="z-index: 2;"
        >

            <div
                class="row align-items-center g-5"
            >

                <div class="col-lg-5">

                    <p class="seccion-etiqueta">
                        SABORES DEL HOTEL
                    </p>

                    <h2 class="seccion-titulo">

                        Comidas preparadas para
                        acompañar tu estadía

                    </h2>

                    <p class="seccion-texto">

                        Consulta el menú, selecciona
                        la cantidad y agrega una
                        observación al realizar tu pedido.

                    </p>

                    <div
                        class="d-flex flex-wrap gap-3 mt-4"
                    >

                        <a
                            href="pedir_comida.php"
                            class="btn-hotel-claro"
                        >

                            <i
                                class="bi bi-menu-button-wide"
                            ></i>

                            Ver menú completo

                        </a>

                        <a
                            href="mis_pedidos.php"
                            class="btn-hotel-borde"
                        >

                            <i
                                class="bi bi-receipt"
                            ></i>

                            Mis pedidos

                        </a>

                    </div>

                </div>

                <div class="col-lg-7">

                    <?php if (
                        $comidas &&
                        $totalComidasDisponibles > 0
                    ) { ?>

                        <div class="row g-3">

                            <?php while (
                                $comida =
                                    mysqli_fetch_assoc(
                                        $comidas
                                    )
                            ) { ?>

                                <?php

                                $rutaImagenComida =
                                    resolverImagen(
                                        $comida[
                                            'imagen'
                                        ] ?? "",
                                        "comidas",
                                        "../img/hotel.jpg"
                                    );

                                ?>

                                <div class="col-md-4">

                                    <article
                                        class="comida-mini-card"
                                    >

                                        <img
                                            src="<?php
                                            echo h(
                                                $rutaImagenComida
                                            );
                                            ?>"
                                            alt="<?php
                                            echo h(
                                                $comida[
                                                    'nombre'
                                                ]
                                            );
                                            ?>"
                                            loading="lazy"
                                            onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                                        >

                                        <div
                                            class="comida-mini-card-contenido"
                                        >

                                            <div
                                                class="comida-mini-tipo"
                                            >

                                                <?php
                                                echo h(
                                                    $comida[
                                                        'tipo'
                                                    ]
                                                );
                                                ?>

                                            </div>

                                            <h3>

                                                <?php
                                                echo h(
                                                    $comida[
                                                        'nombre'
                                                    ]
                                                );
                                                ?>

                                            </h3>

                                            <div
                                                class="comida-mini-precio"
                                            >

                                                $<?php
                                                echo number_format(
                                                    (float)
                                                    $comida[
                                                        'precio'
                                                    ],
                                                    2
                                                );
                                                ?>

                                            </div>

                                        </div>

                                    </article>

                                </div>

                            <?php } ?>

                        </div>

                    <?php } else { ?>

                        <div
                            class="p-4 rounded bg-white text-dark"
                        >

                            El menú estará disponible
                            próximamente.

                        </div>

                    <?php } ?>

                </div>

            </div>

        </div>

    </section>

    <section class="seccion seccion-blanca">

        <div class="container">

            <div
                class="text-center mx-auto mb-5"
                style="max-width: 730px;"
            >

                <p class="seccion-etiqueta">
                    PROCESO SENCILLO
                </p>

                <h2 class="seccion-titulo">

                    Reserva tu habitación
                    en pocos pasos

                </h2>

                <p
                    class="seccion-texto mb-0"
                >

                    Todo el proceso queda registrado
                    en tu cuenta para que puedas
                    revisarlo cuando lo necesites.

                </p>

            </div>

            <div class="row g-4">

                <div
                    class="col-md-6 col-lg-3"
                >

                    <div class="paso-card">

                        <div class="paso-numero">
                            1
                        </div>

                        <h3>
                            Escoge
                        </h3>

                        <p>

                            Revisa las habitaciones
                            disponibles y abre sus detalles.

                        </p>

                    </div>

                </div>

                <div
                    class="col-md-6 col-lg-3"
                >

                    <div class="paso-card">

                        <div class="paso-numero">
                            2
                        </div>

                        <h3>
                            Reserva
                        </h3>

                        <p>

                            Selecciona las fechas y
                            confirma la información
                            de tu estadía.

                        </p>

                    </div>

                </div>

                <div
                    class="col-md-6 col-lg-3"
                >

                    <div class="paso-card">

                        <div class="paso-numero">
                            3
                        </div>

                        <h3>
                            Registra el pago
                        </h3>

                        <p>

                            Selecciona el método y carga
                            el comprobante cuando
                            corresponda.

                        </p>

                    </div>

                </div>

                <div
                    class="col-md-6 col-lg-3"
                >

                    <div class="paso-card">

                        <div class="paso-numero">
                            4
                        </div>

                        <h3>
                            Consulta
                        </h3>

                        <p>

                            Revisa el estado de tu reserva
                            y del pago desde tu cuenta.

                        </p>

                    </div>

                </div>

            </div>

        </div>

    </section>

    <section
        class="seccion seccion-verde-claro"
    >

        <div class="container">

            <div
                class="row align-items-end mb-5 g-3"
            >

                <div class="col-lg-8">

                    <p class="seccion-etiqueta">
                        TU CUENTA
                    </p>

                    <h2
                        class="seccion-titulo mb-2"
                    >

                        Mantén el control
                        de tu estadía

                    </h2>

                    <p
                        class="seccion-texto mb-0"
                    >

                        Consulta tus reservas, pedidos
                        e información personal desde
                        accesos rápidos.

                    </p>

                </div>

            </div>

            <div class="row g-4">

                <div class="col-md-4">

                    <div class="cuenta-card">

                        <div class="cuenta-icono">

                            <i
                                class="bi bi-calendar2-heart"
                            ></i>

                        </div>

                        <h3>
                            Mis reservas
                        </h3>

                        <p>

                            Consulta fechas, habitación,
                            total y estado del pago
                            de tus reservas.

                        </p>

                        <a
                            href="mis_reservas.php"
                            class="enlace-cuenta"
                        >

                            Ver mis reservas

                            <i
                                class="bi bi-arrow-right"
                            ></i>

                        </a>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="cuenta-card">

                        <div class="cuenta-icono">

                            <i
                                class="bi bi-receipt"
                            ></i>

                        </div>

                        <h3>
                            Mis pedidos
                        </h3>

                        <p>

                            Revisa cantidades, valores,
                            observaciones y el estado
                            de preparación.

                        </p>

                        <a
                            href="mis_pedidos.php"
                            class="enlace-cuenta"
                        >

                            Ver mis pedidos

                            <i
                                class="bi bi-arrow-right"
                            ></i>

                        </a>

                    </div>

                </div>

                <div class="col-md-4">

                    <div class="cuenta-card">

                        <div class="cuenta-icono">

                            <i
                                class="bi bi-person"
                            ></i>

                        </div>

                        <h3>
                            Mi perfil
                        </h3>

                        <p>

                            Consulta y actualiza la
                            información personal registrada
                            en el sistema.

                        </p>

                        <a
                            href="perfil.php"
                            class="enlace-cuenta"
                        >

                            Revisar mi perfil

                            <i
                                class="bi bi-arrow-right"
                            ></i>

                        </a>

                    </div>

                </div>

            </div>

        </div>

    </section>

</main>

<footer class="footer-hotel">

    <div class="footer-principal">

        <div class="container">

            <div class="row g-5">

                <div class="col-lg-5">

                    <div
                        class="d-flex align-items-center gap-3 footer-marca"
                    >

                        <img
                            src="../img/logo.png"
                            alt="Hotel Las 3 Palmeras"
                            class="footer-logo"
                        >

                        <div>

                            <h2>
                                Hotel Las 3 Palmeras
                            </h2>

                            <p class="mb-0">

                                Comodidad y tranquilidad
                                para nuestros huéspedes.

                            </p>

                        </div>

                    </div>

                </div>

                <div class="col-6 col-lg-2">

                    <h3 class="footer-titulo">
                        Servicios
                    </h3>

                    <ul class="footer-lista">

                        <li>

                            <a href="#habitaciones">
                                Habitaciones
                            </a>

                        </li>

                        <li>

                            <a href="pedir_comida.php">
                                Comidas
                            </a>

                        </li>

                        <li>

                            <a href="mis_reservas.php">
                                Reservas
                            </a>

                        </li>

                    </ul>

                </div>

                <div class="col-6 col-lg-2">

                    <h3 class="footer-titulo">
                        Mi cuenta
                    </h3>

                    <ul class="footer-lista">

                        <li>

                            <a href="mis_reservas.php">
                                Mis reservas
                            </a>

                        </li>

                        <li>

                            <a href="mis_pedidos.php">
                                Mis pedidos
                            </a>

                        </li>

                        <li>

                            <a href="perfil.php">
                                Mi perfil
                            </a>

                        </li>

                    </ul>

                </div>

                <div class="col-lg-3">

                    <h3 class="footer-titulo">
                        Sesión
                    </h3>

                    <p class="mb-3">

                        Sesión iniciada como

                        <strong class="text-white">

                            <?php
                            echo h(
                                $nombreCliente
                            );
                            ?>

                        </strong>

                    </p>

                    <a
                        href="../logout.php"
                        class="btn btn-outline-light btn-sm"
                    >
                        Cerrar sesión
                    </a>

                </div>

            </div>

        </div>

    </div>

    <div class="footer-final">

        <div
            class="container d-flex flex-wrap justify-content-between gap-2"
        >

            <span>
                Hotel Las 3 Palmeras © 2026
            </span>

            <span>
                Sistema de reservas y servicios hoteleros
            </span>

        </div>

    </div>

</footer>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const boton =
            document.getElementById("botonNotificaciones");

        const contador =
            document.getElementById("contadorNotificaciones");

        const elementos =
            Array.from(
                document.querySelectorAll(
                    "[data-notificacion-id]"
                )
            );

        const clave =
            "hotel_notificaciones_pago_cliente_<?php echo (int) $idCliente; ?>";

        let vistas = [];

        try {
            vistas =
                JSON.parse(
                    localStorage.getItem(clave) || "[]"
                );

            if (!Array.isArray(vistas)) {
                vistas = [];
            }
        } catch (error) {
            vistas = [];
        }

        const idsActuales =
            elementos.map(function (elemento) {
                return elemento.dataset.notificacionId;
            });

        const noVistas =
            idsActuales.filter(function (id) {
                return !vistas.includes(id);
            });

        function actualizarContador(cantidad) {
            if (!contador) {
                return;
            }

            if (cantidad <= 0) {
                contador.style.display = "none";
                contador.textContent = "0";
                return;
            }

            contador.style.display = "flex";
            contador.textContent = cantidad;
        }

        actualizarContador(noVistas.length);

        if (boton) {
            boton.addEventListener("click", function () {
                if (idsActuales.length === 0) {
                    return;
                }

                const nuevasVistas =
                    Array.from(
                        new Set(
                            vistas.concat(idsActuales)
                        )
                    );

                localStorage.setItem(
                    clave,
                    JSON.stringify(nuevasVistas)
                );

                vistas = nuevasVistas;

                actualizarContador(0);
            });
        }
    });
</script>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>

    const barraNavegacion =
        document.querySelector(
            '.navbar-hotel'
        );

    function actualizarBarra() {

        if (window.scrollY > 30) {

            barraNavegacion.classList.add(
                'navbar-con-sombra'
            );

        } else {

            barraNavegacion.classList.remove(
                'navbar-con-sombra'
            );
        }
    }

    actualizarBarra();

    window.addEventListener(
        'scroll',
        actualizarBarra
    );

    document
        .querySelectorAll(
            '#menuCliente a[href^="#"]'
        )
        .forEach((enlace) => {

            enlace.addEventListener(
                'click',
                () => {

                    const menu =
                        document.getElementById(
                            'menuCliente'
                        );

                    const instancia =
                        bootstrap.Collapse
                            .getInstance(menu);

                    if (instancia) {
                        instancia.hide();
                    }
                }
            );
        });

</script>

</body>

</html>