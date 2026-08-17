<?php

session_start();

include("config/conexion.php");

if (!isset($_SESSION["usuario"], $_SESSION["rol"])) {
    header("Location: login.php");
    exit();
}

$rolActual = strtolower(
    trim((string) $_SESSION["rol"])
);

if ($rolActual === "cliente") {
    header("Location: cliente/index.php");
    exit();
}

if (
    !in_array(
        $rolActual,
        ["administrador", "recepcionista"],
        true
    )
) {
    header("Location: login.php");
    exit();
}

$esAdministrador =
    $rolActual === "administrador";

function h($texto)
{
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}

function obtenerCantidad(
    mysqli $conn,
    string $consulta
): int {
    $resultado =
        mysqli_query($conn, $consulta);

    if (!$resultado) {
        return 0;
    }

    $fila =
        mysqli_fetch_assoc($resultado);

    return (int) ($fila["total"] ?? 0);
}

function resolverImagen(
    $imagen,
    $subcarpeta,
    $imagenPredeterminada
) {
    $imagen = trim((string) $imagen);

    if (
        $imagen === "" ||
        !filter_var(
            $imagen,
            FILTER_VALIDATE_URL
        )
    ) {
        return $imagenPredeterminada;
    }

    $esquema = strtolower(
        (string) parse_url(
            $imagen,
            PHP_URL_SCHEME
        )
    );

    return in_array(
        $esquema,
        ["http", "https"],
        true
    )
        ? $imagen
        : $imagenPredeterminada;
}

function formatearFecha(
    $fecha,
    $incluirHora = false
) {
    $fecha = trim((string) $fecha);

    if (
        $fecha === "" ||
        $fecha === "0000-00-00" ||
        $fecha === "0000-00-00 00:00:00"
    ) {
        return "-";
    }

    $tiempo = strtotime($fecha);

    if ($tiempo === false) {
        return $fecha;
    }

    return date(
        $incluirHora
            ? "d/m/Y h:i A"
            : "d/m/Y",
        $tiempo
    );
}

function claseReserva($estado)
{
    switch ($estado) {
        case "Confirmada":
            return "estado-verde";

        case "Pendiente":
            return "estado-amarillo";

        case "Finalizada":
            return "estado-azul";

        case "Cancelada":
            return "estado-rojo";

        default:
            return "estado-gris";
    }
}

function clasePedido($estado)
{
    switch ($estado) {
        case "Pendiente":
            return "estado-amarillo";

        case "Preparando":
            return "estado-azul";

        case "Entregado":
            return "estado-verde";

        case "Cancelado":
            return "estado-rojo";

        default:
            return "estado-gris";
    }
}

function clasePago($estado)
{
    switch ($estado) {
        case "Aceptado":
            return "estado-verde";

        case "Pendiente":
            return "estado-amarillo";

        case "Rechazado":
            return "estado-rojo";

        default:
            return "estado-gris";
    }
}

function clasePagoPedido($estado)
{
    switch ($estado) {
        case "Pagado":
            return "estado-verde";

        case "Pendiente":
            return "estado-amarillo";

        default:
            return "estado-gris";
    }
}

function claseHabitacion($estado)
{
    switch ($estado) {
        case "Disponible":
            return "estado-verde";

        case "Ocupada":
            return "estado-rojo";

        case "Mantenimiento":
            return "estado-amarillo";

        default:
            return "estado-gris";
    }
}

/* Resumen del panel */
$totalHabitaciones = obtenerCantidad(
    $conn,
    "SELECT COUNT(*) AS total
     FROM habitaciones"
);

$habitacionesDisponiblesCantidad =
    obtenerCantidad(
        $conn,
        "SELECT COUNT(*) AS total
         FROM habitaciones
         WHERE estado = 'Disponible'"
    );

$habitacionesOcupadasCantidad =
    obtenerCantidad(
        $conn,
        "SELECT COUNT(*) AS total
         FROM habitaciones
         WHERE estado = 'Ocupada'"
    );

$habitacionesMantenimientoCantidad =
    obtenerCantidad(
        $conn,
        "SELECT COUNT(*) AS total
         FROM habitaciones
         WHERE estado = 'Mantenimiento'"
    );

$totalClientes = obtenerCantidad(
    $conn,
    "SELECT COUNT(*) AS total
     FROM clientes"
);

$totalReservas = obtenerCantidad(
    $conn,
    "SELECT COUNT(*) AS total
     FROM reservas"
);

$reservasPendientes = obtenerCantidad(
    $conn,
    "SELECT COUNT(*) AS total
     FROM reservas
     WHERE estado = 'Pendiente'"
);

$reservasConDesayuno = obtenerCantidad(
    $conn,
    "SELECT COUNT(*) AS total
     FROM reservas
     WHERE plan_alimentacion =
           'Alojamiento con desayuno'
       AND estado <> 'Cancelada'"
);

$totalComidas = obtenerCantidad(
    $conn,
    "SELECT COUNT(*) AS total
     FROM comidas"
);

$comidasDisponiblesCantidad =
    obtenerCantidad(
        $conn,
        "SELECT COUNT(*) AS total
         FROM comidas
         WHERE estado = 'Disponible'"
    );

$totalPedidos = obtenerCantidad(
    $conn,
    "SELECT COUNT(*) AS total
     FROM pedidos_comida"
);

$pedidosPendientes = obtenerCantidad(
    $conn,
    "SELECT COUNT(*) AS total
     FROM pedidos_comida
     WHERE estado = 'Pendiente'"
);

$pedidosPreparando = obtenerCantidad(
    $conn,
    "SELECT COUNT(*) AS total
     FROM pedidos_comida
     WHERE estado = 'Preparando'"
);

$pagosPedidosPendientes =
    obtenerCantidad(
        $conn,
        "SELECT COUNT(*) AS total
         FROM pedidos_comida
         WHERE estado_pago = 'Pendiente'
           AND estado <> 'Cancelado'"
    );

$totalPagos = 0;
$pagosPendientes = 0;
$totalUsuarios = 0;

if ($esAdministrador) {
    $totalPagos = obtenerCantidad(
        $conn,
        "SELECT COUNT(*) AS total
         FROM pagos"
    );

    $pagosPendientes = obtenerCantidad(
        $conn,
        "SELECT COUNT(*) AS total
         FROM pagos
         WHERE estado_pago = 'Pendiente'"
    );

    $totalUsuarios = obtenerCantidad(
        $conn,
        "SELECT COUNT(*) AS total
         FROM usuarios"
    );
}

$habitaciones = mysqli_query(
    $conn,
    "SELECT
        h.id_habitacion,
        h.numero,
        h.tipo,
        h.precio,
        h.capacidad,
        h.estado,
        h.imagen,
        h.estado AS estado_actual
     FROM habitaciones h
     ORDER BY h.id_habitacion DESC
     LIMIT 6"
);

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
     ORDER BY id_comida DESC
     LIMIT 6"
);

$pedidos = mysqli_query(
    $conn,
    "SELECT
        p.id_pedido,
        p.id_reserva,
        p.cantidad,
        p.total,
        p.forma_pago,
        p.estado_pago,
        p.fecha_pago,
        p.estado,
        p.fecha_pedido,
        p.observacion,

        c.nombres,
        c.apellidos,

        co.nombre AS nombre_comida,
        co.tipo AS tipo_comida,

        r.id_reserva AS reserva_relacionada,
        h.numero AS numero_habitacion

     FROM pedidos_comida p

     INNER JOIN clientes c
        ON c.id_cliente = p.id_cliente

     INNER JOIN comidas co
        ON co.id_comida = p.id_comida

     LEFT JOIN reservas r
        ON r.id_reserva = p.id_reserva
       AND r.id_cliente = p.id_cliente

     LEFT JOIN habitaciones h
        ON h.id_habitacion = r.id_habitacion

     ORDER BY
        CASE
            WHEN p.estado = 'Pendiente' THEN 0
            WHEN p.estado = 'Preparando' THEN 1
            WHEN p.estado = 'Entregado' THEN 2
            ELSE 3
        END,
        p.fecha_pedido DESC,
        p.id_pedido DESC

     LIMIT 8"
);

$clientes = mysqli_query(
    $conn,
    "SELECT
        id_cliente,
        nombres,
        apellidos,
        cedula,
        correo,
        telefono
     FROM clientes
     ORDER BY id_cliente DESC
     LIMIT 8"
);

$reservas = mysqli_query(
    $conn,
    "SELECT
        r.id_reserva,
        r.fecha_entrada,
        r.fecha_salida,
        r.numero_personas,
        r.plan_alimentacion,
        r.precio_desayuno,
        r.total_alimentacion,
        r.estado,
        r.total,

        c.nombres,
        c.apellidos,

        h.numero AS numero_habitacion,
        h.tipo AS tipo_habitacion

     FROM reservas r

     INNER JOIN clientes c
        ON c.id_cliente = r.id_cliente

     INNER JOIN habitaciones h
        ON h.id_habitacion = r.id_habitacion

     ORDER BY r.id_reserva DESC
     LIMIT 8"
);

$pagos = false;

if ($esAdministrador) {
    $pagos = mysqli_query(
        $conn,
        "SELECT
            p.id_pago,
            p.id_reserva,
            p.metodo_pago,
            p.monto,
            p.estado_pago,
            p.fecha_pago,

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

         ORDER BY p.id_pago DESC
         LIMIT 8"
    );
}

$notificacionesPagos = false;

if ($esAdministrador) {
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

         ORDER BY
            p.fecha_pago DESC,
            p.id_pago DESC

         LIMIT 6"
    );
}

$usuarios = false;

if ($esAdministrador) {
    $usuarios = mysqli_query(
        $conn,
        "SELECT
            id_usuario,
            nombre,
            usuario,
            rol
         FROM usuarios
         ORDER BY id_usuario DESC
         LIMIT 8"
    );
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
        Panel administrativo - Hotel Las 3 Palmeras
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

    <link
        rel="stylesheet"
        href="css/style.css?v=65"
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

        .navbar-hotel {
            min-height: 82px;

            background-color:
                rgba(18, 39, 28, 0.96);

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
                rgba(18, 39, 28, 0.99);

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

            color:
                rgba(255, 255, 255, 0.83);

            font-size: 14px;
            font-weight: 700;

            margin: 0 3px;

            padding:
                10px 9px !important;
        }

        .navbar-hotel .nav-link:hover,
        .navbar-hotel .nav-link.active {
            color: white;
        }

        .navbar-hotel .nav-link::after {
            content: "";

            position: absolute;

            left: 10px;
            right: 10px;
            bottom: 3px;

            height: 2px;

            background-color:
                var(--dorado);

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

            color:
                rgba(255, 255, 255, 0.67);

            font-size: 10px;
            font-weight: 700;

            letter-spacing: 0.7px;

            text-transform: uppercase;
        }

        .btn-salir {
            border-radius: 999px;

            padding:
                9px 15px;

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

            border:
                1px solid
                rgba(255, 255, 255, 0.28);

            border-radius: 50%;

            background-color:
                rgba(255, 255, 255, 0.08);

            color: white;

            font-size: 17px;

            transition:
                background-color 0.2s ease,
                border-color 0.2s ease,
                transform 0.2s ease;
        }

        .btn-notificaciones-admin:hover,
        .btn-notificaciones-admin:focus {
            border-color:
                rgba(240, 217, 159, 0.75);

            background-color:
                rgba(255, 255, 255, 0.15);

            color: white;

            transform:
                translateY(-1px);
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

            border:
                2px solid
                var(--verde-oscuro);

            border-radius: 999px;

            background-color: #cf3f3f;
            color: white;

            font-size: 9px;
            font-weight: 900;
            line-height: 1;
        }

        .menu-notificaciones-admin {
            width: min(390px, calc(100vw - 30px));

            overflow: hidden;

            margin-top: 12px !important;

            padding: 0;

            border:
                1px solid #dde2dd;

            border-radius: 12px;

            background-color: white;

            box-shadow:
                0 18px 46px
                rgba(14, 35, 23, 0.20);
        }

        .notificaciones-admin-cabecera {
            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 12px;

            padding:
                17px 18px;

            border-bottom:
                1px solid #e8ebe7;

            background-color: #fbfcfa;
        }

        .notificaciones-admin-cabecera strong {
            display: block;

            color:
                var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 17px;
        }

        .notificaciones-admin-cabecera small {
            display: block;

            margin-top: 2px;

            color:
                var(--texto-suave);

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

            background-color:
                var(--verde-principal);

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

            padding:
                15px 18px;

            border-bottom:
                1px solid #edf0ec;

            color:
                var(--texto);

            transition:
                background-color 0.2s ease;
        }

        .notificacion-pago-admin:hover {
            background-color:
                #f4f8f5;

            color:
                var(--texto);
        }

        .notificacion-pago-admin:last-child {
            border-bottom: 0;
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

            background-color: #fff0c7;
            color: #81600d;

            font-size: 16px;
        }

        .notificacion-pago-contenido {
            min-width: 0;
            flex: 1;
        }

        .notificacion-pago-contenido strong {
            display: block;

            margin-bottom: 3px;

            color:
                var(--verde-oscuro);

            font-size: 12px;
        }

        .notificacion-pago-contenido span {
            display: block;

            color:
                var(--texto-suave);

            font-size: 10px;
            line-height: 1.5;
        }

        .notificacion-pago-monto {
            margin-top: 5px;

            color:
                var(--verde-principal) !important;

            font-weight: 900;
        }

        .notificaciones-admin-vacio {
            padding:
                28px 20px;

            color:
                var(--texto-suave);

            text-align: center;

            font-size: 12px;
        }

        .notificaciones-admin-vacio i {
            display: block;

            margin-bottom: 8px;

            color:
                var(--verde-principal);

            font-size: 26px;
        }

        .notificaciones-admin-pie {
            padding:
                13px 18px;

            border-top:
                1px solid #e8ebe7;

            background-color: #fbfcfa;
        }

        .notificaciones-admin-pie a {
            display: flex;
            align-items: center;
            justify-content: center;

            gap: 7px;

            color:
                var(--verde-principal);

            font-size: 11px;
            font-weight: 900;
        }

        .notificaciones-admin-pie a:hover {
            color:
                var(--verde-oscuro);
        }

        .hero-dashboard {
            min-height: 100vh;

            position: relative;

            display: flex;
            align-items: center;

            overflow: visible;

            color: white;

            background:
                linear-gradient(
                    90deg,
                    rgba(10, 29, 20, 0.89) 0%,
                    rgba(10, 29, 20, 0.67) 50%,
                    rgba(10, 29, 20, 0.28) 100%
                ),
                url("img/hotel.jpg");

            background-size: cover;
            background-position: center;
        }

        .hero-dashboard::before {
            content: "";

            position: absolute;
            inset: 0;

            background:
                radial-gradient(
                    circle at 81% 28%,
                    rgba(216, 181, 109, 0.15),
                    transparent 30%
                );

            pointer-events: none;
        }

        .hero-contenido {
            position: relative;
            z-index: 2;

            max-width: 820px;

            padding-top: 150px;
            padding-bottom: 185px;
        }

        .rol-portada {
            display: inline-flex;
            align-items: center;

            gap: 8px;

            margin-bottom: 20px;

            padding:
                8px 13px;

            border:
                1px solid
                rgba(240, 217, 159, 0.52);

            border-radius: 999px;

            background-color:
                rgba(255, 255, 255, 0.08);

            color: #f0d99f;

            font-size: 11px;
            font-weight: 900;

            letter-spacing: 1.3px;

            text-transform: uppercase;
        }

        .hero-etiqueta {
            display: flex;
            align-items: center;

            gap: 10px;

            margin-bottom: 17px;

            color: #f0d99f;

            font-size: 13px;
            font-weight: 900;

            letter-spacing: 3px;
        }

        .hero-etiqueta::before {
            content: "";

            width: 43px;
            height: 2px;

            background-color:
                var(--dorado);
        }

        .hero-titulo {
            max-width: 790px;

            margin-bottom: 24px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size:
                clamp(
                    3.2rem,
                    7vw,
                    6.3rem
                );

            font-weight: 700;
            line-height: 0.98;

            letter-spacing: -2px;

            text-shadow:
                0 8px 24px
                rgba(0, 0, 0, 0.29);
        }

        .hero-titulo span {
            display: block;
            color: #f0d99f;
        }

        .hero-texto {
            max-width: 690px;

            margin-bottom: 34px;

            color:
                rgba(255, 255, 255, 0.86);

            font-size:
                clamp(
                    1rem,
                    1.8vw,
                    1.18rem
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
                13px 22px;

            font-size: 13px;
            font-weight: 900;

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
            border-color: #e7c882;
            background-color: #e7c882;
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
                rgba(255, 255, 255, 0.16);

            color: white;

            transform:
                translateY(-2px);
        }

        .atajos-flotantes {
            position: absolute;
            z-index: 4;

            left: 50%;
            bottom: -64px;

            width:
                min(
                    1170px,
                    calc(100% - 40px)
                );

            transform:
                translateX(-50%);
        }

        .atajos-contenedor {
            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            overflow: hidden;

            border-radius: 7px;

            background-color: white;

            box-shadow: var(--sombra);
        }

        .atajo-card {
            min-height: 128px;

            display: flex;
            align-items: center;

            gap: 14px;

            padding:
                24px 20px;

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
        }

        .atajo-card:hover
        .atajo-icono {
            background-color:
                rgba(255, 255, 255, 0.14);

            color: #f0d99f;
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
                92px 0;
        }

        .seccion-primera {
            padding-top: 155px;
        }

        .seccion-blanca {
            background-color: white;
        }

        .seccion-verde {
            background-color:
                var(--verde-claro);
        }

        .seccion-etiqueta {
            margin-bottom: 11px;

            color: #9b7739;

            font-size: 12px;
            font-weight: 900;

            letter-spacing: 2.2px;

            text-transform: uppercase;
        }

        .seccion-titulo {
            margin-bottom: 16px;

            color:
                var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size:
                clamp(
                    2.15rem,
                    4.5vw,
                    3.55rem
                );

            font-weight: 700;
            line-height: 1.1;
        }

        .seccion-texto {
            color:
                var(--texto-suave);

            font-size: 15px;
            line-height: 1.75;
        }

        .btn-administrar {
            min-height: 45px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            gap: 8px;

            padding:
                11px 18px;

            border:
                1px solid
                var(--verde-principal);

            border-radius: 4px;

            background-color:
                var(--verde-principal);

            color: white;

            font-size: 12px;
            font-weight: 900;

            transition:
                0.2s ease;
        }

        .btn-administrar:hover {
            border-color:
                var(--verde-oscuro);

            background-color:
                var(--verde-oscuro);

            color: white;

            transform:
                translateY(-2px);
        }

        .btn-secundario {
            min-height: 40px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            gap: 7px;

            padding:
                9px 14px;

            border:
                1px solid #bcc4be;

            border-radius: 4px;

            background-color: transparent;

            color:
                var(--verde-principal);

            font-size: 12px;
            font-weight: 900;
        }

        .btn-secundario:hover {
            border-color:
                var(--verde-principal);

            background-color:
                var(--verde-claro);

            color:
                var(--verde-principal);
        }

        .resumen-card {
            height: 100%;

            padding:
                27px 23px;

            border:
                1px solid #e3e3dc;

            border-radius: 6px;

            background-color: white;

            box-shadow:
                0 12px 30px
                rgba(24, 48, 32, 0.07);

            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .resumen-card:hover {
            transform:
                translateY(-5px);

            box-shadow:
                0 19px 38px
                rgba(24, 48, 32, 0.12);
        }

        .resumen-icono {
            width: 52px;
            height: 52px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 17px;

            border-radius: 50%;

            background-color:
                var(--verde-claro);

            color:
                var(--verde-principal);

            font-size: 21px;
        }

        .resumen-numero {
            color:
                var(--verde-principal);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 34px;
            font-weight: 700;
            line-height: 1;
        }

        .resumen-card h3 {
            margin:
                8px 0 6px;

            color:
                var(--verde-oscuro);

            font-size: 15px;
            font-weight: 900;
        }

        .resumen-card p {
            margin: 0;

            color:
                var(--texto-suave);

            font-size: 12px;
            line-height: 1.55;
        }

        .resumen-fila {
            display: flex;
            flex-wrap: nowrap;
            gap: 24px;
            overflow-x: auto;
            padding-bottom: 12px;
            scrollbar-width: thin;
        }

        .resumen-columna {
            flex: 1 1 0;
            min-width: 220px;
        }

        .resumen-fila .resumen-card {
            min-height: 228px;
        }

        @media (min-width: 1200px) {
            .resumen-fila {
                overflow-x: visible;
            }

            .resumen-columna {
                min-width: 0;
            }
        }

        .contenido-card {
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

        .contenido-card:hover {
            transform:
                translateY(-6px);

            box-shadow:
                0 20px 42px
                rgba(29, 52, 38, 0.16);
        }

        .contenido-imagen {
            width: 100%;
            height: 245px;

            object-fit: cover;
        }

        .contenido-card-body {
            padding:
                23px;
        }

        .contenido-etiqueta {
            margin-bottom: 6px;

            color: #9b7739;

            font-size: 10px;
            font-weight: 900;

            letter-spacing: 1.6px;

            text-transform: uppercase;
        }

        .contenido-card h3 {
            color:
                var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 23px;
            font-weight: 700;
        }

        .contenido-card p {
            color:
                var(--texto-suave);

            font-size: 13px;
            line-height: 1.65;
        }

        .contenido-precio {
            color:
                var(--verde-principal);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 25px;
            font-weight: 700;
        }

        .estado {
            display: inline-flex;
            align-items: center;

            padding:
                6px 10px;

            border-radius: 999px;

            font-size: 10px;
            font-weight: 900;

            letter-spacing: 0.4px;

            white-space: nowrap;
        }

        .estado-verde {
            background-color: #dff2e4;
            color: #21643b;
        }

        .estado-amarillo {
            background-color: #fff0c7;
            color: #81600d;
        }

        .estado-rojo {
            background-color: #f7dede;
            color: #9d3030;
        }

        .estado-azul {
            background-color: #dceaf7;
            color: #275d87;
        }

        .estado-gris {
            background-color: #e8e8e8;
            color: #555555;
        }

        .tabla-card {
            overflow: hidden;

            border:
                1px solid #e3e4dd;

            border-radius: 6px;

            background-color: white;

            box-shadow:
                0 13px 32px
                rgba(27, 50, 35, 0.08);
        }

        .tabla-card
        .table-responsive {
            min-height: 80px;
        }

        .tabla-hotel {
            margin: 0;
        }

        .tabla-hotel thead th {
            padding:
                15px 16px;

            border: none;

            background-color:
                var(--verde-oscuro);

            color: white;

            font-size: 11px;
            font-weight: 900;

            letter-spacing: 0.7px;

            text-transform: uppercase;

            white-space: nowrap;
        }

        .tabla-hotel tbody td {
            padding:
                15px 16px;

            border-color: #ecece6;

            color: #3c443e;

            font-size: 13px;

            vertical-align: middle;
        }

        .tabla-hotel tbody tr:hover {
            background-color: #f7faf7;
        }

        .tabla-vacia {
            padding:
                34px !important;

            color:
                var(--texto-suave) !important;

            text-align: center;
        }

        .accion-tabla {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            gap: 6px;

            padding:
                7px 10px;

            border:
                1px solid #bdc7bf;

            border-radius: 4px;

            color:
                var(--verde-principal);

            font-size: 11px;
            font-weight: 900;

            white-space: nowrap;
        }

        .accion-tabla:hover {
            border-color:
                var(--verde-principal);

            background-color:
                var(--verde-claro);

            color:
                var(--verde-principal);
        }

        .footer-hotel {
            background-color: #13271c;
            color: white;
        }

        .footer-principal {
            padding:
                55px 0 40px;
        }

        .footer-logo {
            width: 66px;
            height: 66px;
            object-fit: contain;
        }

        .footer-marca h2 {
            margin-bottom: 7px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 24px;
        }

        .footer-marca p,
        .footer-hotel a {
            color:
                rgba(255, 255, 255, 0.64);

            font-size: 13px;
        }

        .footer-hotel a:hover {
            color: #f0d99f;
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

        @media (max-width: 1199px) {

            .navbar-hotel .nav-link {
                padding:
                    10px 6px !important;

                font-size: 13px;
            }

            .atajo-card {
                padding:
                    22px 16px;
            }

            .atajo-card strong {
                font-size: 16px;
            }
        }

        @media (max-width: 991px) {

            .navbar-hotel {
                background-color:
                    rgba(18, 39, 28, 0.99);
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

            .notificaciones-admin {
                margin-top: 10px;
            }

            .menu-notificaciones-admin {
                width:
                    min(
                        390px,
                        calc(100vw - 24px)
                    );
            }

            .hero-dashboard {
                display: block;
                min-height: auto;
                background-position: 60% center;
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
        }

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

            .hero-dashboard {
                background:
                    linear-gradient(
                        rgba(10, 29, 20, 0.82),
                        rgba(10, 29, 20, 0.77)
                    ),
                    url("img/hotel.jpg");

                background-size: cover;
                background-position: center;
            }

            .hero-contenido {
                padding-top: 125px;
                padding-bottom: 70px;

                text-align: center;
            }

            .rol-portada {
                margin-left: auto;
                margin-right: auto;
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
                        2.7rem,
                        14vw,
                        4rem
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
                max-width: 340px;
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
                    68px 0;
            }

            .seccion-titulo {
                font-size:
                    clamp(
                        2rem,
                        10vw,
                        2.6rem
                    );
            }

            .contenido-imagen {
                height: 225px;
            }

            .tabla-hotel tbody td,
            .tabla-hotel thead th {
                padding:
                    13px 14px;
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

            .hero-titulo {
                font-size: 2.7rem;
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
            href="dashboard.php"
            class="navbar-brand marca-hotel p-0"
        >

            <img
                src="img/logo.png"
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
            data-bs-target="#menuDashboard"
            aria-controls="menuDashboard"
            aria-expanded="false"
            aria-label="Abrir menú"
        >

            <span class="navbar-toggler-icon"></span>

        </button>

        <div
            class="collapse navbar-collapse"
            id="menuDashboard"
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
                        href="#reservas"
                        class="nav-link"
                    >
                        Reservas
                    </a>

                </li>

                <li class="nav-item">

                    <a
                        href="#comidas"
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

                    <ul
                        class="dropdown-menu dropdown-menu-end"
                    >

                        <li>

                            <a
                                href="#clientes"
                                class="dropdown-item"
                            >
                                <i
                                    class="bi bi-people me-2"
                                ></i>

                                Clientes
                            </a>

                        </li>

                        <li>

                            <a
                                href="#pedidos"
                                class="dropdown-item"
                            >
                                <i
                                    class="bi bi-receipt me-2"
                                ></i>

                                Pedidos
                            </a>

                        </li>

                        <?php if ($esAdministrador) { ?>

                            <li>

                                <a
                                    href="#pagos"
                                    class="dropdown-item"
                                >
                                    <i
                                        class="bi bi-credit-card me-2"
                                    ></i>

                                    Pagos
                                </a>

                            </li>

                            <li>

                                <a
                                    href="#usuarios"
                                    class="dropdown-item"
                                >
                                    <i
                                        class="bi bi-person-gear me-2"
                                    ></i>

                                    Usuarios
                                </a>

                            </li>

                        <?php } ?>

                    </ul>

                </li>

            </ul>

            <div
                class="d-flex flex-wrap align-items-center gap-3"
            >

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

                        <div
                            class="dropdown-menu dropdown-menu-end menu-notificaciones-admin"
                        >

                            <div class="notificaciones-admin-cabecera">

                                <div>
                                    <strong>
                                        Pagos por revisar
                                    </strong>

                                    <small>
                                        Transferencias pendientes de aprobación
                                    </small>
                                </div>

                                <span class="notificaciones-admin-total">
                                    <?php echo $pagosPendientes; ?>
                                </span>

                            </div>

                            <div class="notificaciones-admin-lista">

                                <?php if (
                                    $notificacionesPagos &&
                                    mysqli_num_rows(
                                        $notificacionesPagos
                                    ) > 0
                                ) { ?>

                                    <?php while (
                                        $notificacionPago =
                                            mysqli_fetch_assoc(
                                                $notificacionesPagos
                                            )
                                    ) { ?>

                                        <a
                                            href="pagos/index.php?cliente=<?php echo (int) $notificacionPago["id_cliente"]; ?>&estado=Pendiente"
                                            class="notificacion-pago-admin"
                                        >

                                            <div class="notificacion-pago-fila">

                                                <div class="notificacion-pago-icono">
                                                    <i class="bi bi-receipt"></i>
                                                </div>

                                                <div class="notificacion-pago-contenido">

                                                    <strong>
                                                        Nuevo pago pendiente
                                                    </strong>

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
                                                            (float)
                                                            $notificacionPago["monto"],
                                                            2
                                                        );
                                                        ?>
                                                        ·
                                                        <?php
                                                        echo h(
                                                            formatearFecha(
                                                                $notificacionPago["fecha_pago"],
                                                                true
                                                            )
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

                                        No hay pagos pendientes
                                        por revisar.

                                    </div>

                                <?php } ?>

                            </div>

                            <div class="notificaciones-admin-pie">

                                <a href="pagos/index.php">
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

                        <?php
                        echo h(
                            $_SESSION["usuario"]
                        );
                        ?>

                    </strong>

                    <span class="rol-navbar">

                        <i
                            class="bi bi-shield-check me-1"
                        ></i>

                        <?php
                        echo h(
                            $_SESSION["rol"]
                        );
                        ?>

                    </span>

                </div>

                <a
                    href="logout.php"
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

    <!-- PORTADA -->

    <section
        id="inicio"
        class="hero-dashboard"
    >

        <div class="container">

            <div class="hero-contenido">

                <div class="rol-portada">

                    <i
                        class="bi bi-shield-lock"
                    ></i>

                    Acceso de

                    <?php
                    echo h(
                        $_SESSION["rol"]
                    );
                    ?>

                </div>

                <div class="hero-etiqueta">
                    PANEL DE CONTROL DEL HOTEL
                </div>

                <h1 class="hero-titulo">

                    Administra el hotel

                    <span>
                        desde un solo lugar
                    </span>

                </h1>

                <p class="hero-texto">

                    Revisa habitaciones, reservas,
                    clientes, comidas, pedidos y los
                    movimientos recientes del Hotel
                    Las 3 Palmeras.

                </p>

                <div class="hero-botones">

                    <a
                        href="#habitaciones"
                        class="btn-hotel-principal"
                    >

                        <i
                            class="bi bi-door-open"
                        ></i>

                        Ver habitaciones

                    </a>

                    <a
                        href="#reservas"
                        class="btn-hotel-claro"
                    >

                        <i
                            class="bi bi-calendar2-check"
                        ></i>

                        Revisar reservas

                    </a>

                    <a
                        href="comidas/index.php"
                        class="btn-hotel-borde"
                    >

                        <i
                            class="bi bi-cup-hot"
                        ></i>

                        Administrar comidas

                    </a>

                    <a
                        href="pedidos/index.php"
                        class="btn-hotel-borde"
                    >

                        <i
                            class="bi bi-receipt"
                        ></i>

                        Revisar pedidos

                    </a>

                </div>

            </div>

        </div>

        <!-- ATAJOS PRINCIPALES -->

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
                            echo $habitacionesDisponiblesCantidad;
                            ?>

                            disponibles

                        </small>

                    </span>

                </a>

                <a
                    href="#reservas"
                    class="atajo-card"
                >

                    <span class="atajo-icono">

                        <i
                            class="bi bi-calendar-heart"
                        ></i>

                    </span>

                    <span>

                        <strong>
                            Reservas
                        </strong>

                        <small>

                            <?php
                            echo $reservasPendientes;
                            ?>

                            pendientes

                        </small>

                    </span>

                </a>

                <a
                    href="#pedidos"
                    class="atajo-card"
                >

                    <span class="atajo-icono">

                        <i
                            class="bi bi-receipt-cutoff"
                        ></i>

                    </span>

                    <span>

                        <strong>
                            Pedidos
                        </strong>

                        <small>

                            <?php
                            echo $pedidosPendientes;
                            ?>

                            pendientes ·

                            <?php
                            echo $pedidosPreparando;
                            ?>

                            preparando

                        </small>

                    </span>

                </a>

                <?php if ($esAdministrador) { ?>

                    <a
                        href="#pagos"
                        class="atajo-card"
                    >

                        <span class="atajo-icono">

                            <i
                                class="bi bi-credit-card-2-front"
                            ></i>

                        </span>

                        <span>

                            <strong>
                                Pagos
                            </strong>

                            <small>

                                <?php
                                echo $pagosPendientes;
                                ?>

                                por revisar

                            </small>

                        </span>

                    </a>

                <?php } else { ?>

                    <a
                        href="#clientes"
                        class="atajo-card"
                    >

                        <span class="atajo-icono">

                            <i
                                class="bi bi-people"
                            ></i>

                        </span>

                        <span>

                            <strong>
                                Clientes
                            </strong>

                            <small>

                                <?php
                                echo $totalClientes;
                                ?>

                                registrados

                            </small>

                        </span>

                    </a>

                <?php } ?>

            </div>

        </div>

    </section>

    <!-- RESUMEN GENERAL -->

    <section
        class="seccion seccion-primera"
    >

        <div class="container">

            <div
                class="row align-items-end mb-5 g-3"
            >

                <div class="col-lg-8">

                    <p class="seccion-etiqueta">
                        INFORMACIÓN GENERAL
                    </p>

                    <h2
                        class="seccion-titulo mb-2"
                    >
                        Resumen del sistema
                    </h2>

                    <p
                        class="seccion-texto mb-0"
                    >

                        Consulta rápidamente la cantidad
                        de registros y los movimientos que
                        requieren atención.

                    </p>

                </div>

            </div>

            <div class="resumen-fila">

                <div
                    class="resumen-columna"
                >

                    <div class="resumen-card">

                        <div class="resumen-icono">

                            <i
                                class="bi bi-building"
                            ></i>

                        </div>

                        <div class="resumen-numero">

                            <?php
                            echo $totalHabitaciones;
                            ?>

                        </div>

                        <h3>
                            Habitaciones
                        </h3>

                        <p>

                            <?php
                            echo $habitacionesDisponiblesCantidad;
                            ?>

                            disponibles,

                            <?php
                            echo $habitacionesOcupadasCantidad;
                            ?>

                            ocupadas y

                            <?php
                            echo $habitacionesMantenimientoCantidad;
                            ?>

                            en mantenimiento.

                        </p>

                    </div>

                </div>

                <div
                    class="resumen-columna"
                >

                    <div class="resumen-card">

                        <div class="resumen-icono">

                            <i
                                class="bi bi-calendar2-check"
                            ></i>

                        </div>

                        <div class="resumen-numero">

                            <?php
                            echo $totalReservas;
                            ?>

                        </div>

                        <h3>
                            Reservas
                        </h3>

                        <p>

                            <?php
                            echo $reservasPendientes;
                            ?>

                            pendientes y

                            <?php
                            echo $reservasConDesayuno;
                            ?>

                            con desayuno.

                        </p>

                    </div>

                </div>

                <div
                    class="resumen-columna"
                >

                    <div class="resumen-card">

                        <div class="resumen-icono">

                            <i
                                class="bi bi-people"
                            ></i>

                        </div>

                        <div class="resumen-numero">

                            <?php
                            echo $totalClientes;
                            ?>

                        </div>

                        <h3>
                            Clientes
                        </h3>

                        <p>
                            Clientes registrados en el sistema.
                        </p>

                    </div>

                </div>

                <div
                    class="resumen-columna"
                >

                    <div class="resumen-card">

                        <div class="resumen-icono">

                            <i
                                class="bi bi-cup-hot"
                            ></i>

                        </div>

                        <div class="resumen-numero">

                            <?php
                            echo $totalComidas;
                            ?>

                        </div>

                        <h3>
                            Comidas
                        </h3>

                        <p>

                            <?php
                            echo $comidasDisponiblesCantidad;
                            ?>

                            disponibles en el menú.

                        </p>

                    </div>

                </div>

                <div
                    class="resumen-columna"
                >

                    <div class="resumen-card">

                        <div class="resumen-icono">

                            <i
                                class="bi bi-receipt"
                            ></i>

                        </div>

                        <div class="resumen-numero">

                            <?php
                            echo $totalPedidos;
                            ?>

                        </div>

                        <h3>
                            Pedidos
                        </h3>

                        <p>

                            <?php
                            echo $pedidosPendientes;
                            ?>

                            pendientes,

                            <?php
                            echo $pedidosPreparando;
                            ?>

                            preparando y

                            <?php
                            echo $pagosPedidosPendientes;
                            ?>

                            pagos pendientes.

                        </p>

                    </div>

                </div>

                <?php if ($esAdministrador) { ?>

                    <div
                        class="resumen-columna"
                    >

                        <div class="resumen-card">

                            <div class="resumen-icono">

                                <i
                                    class="bi bi-credit-card"
                                ></i>

                            </div>

                            <div class="resumen-numero">

                                <?php
                                echo $totalPagos;
                                ?>

                            </div>

                            <h3>
                                Pagos
                            </h3>

                            <p>

                                <?php
                                echo $pagosPendientes;
                                ?>

                                pagos pendientes de revisión.

                            </p>

                        </div>

                    </div>

                    <div
                        class="resumen-columna"
                    >

                        <div class="resumen-card">

                            <div class="resumen-icono">

                                <i
                                    class="bi bi-person-gear"
                                ></i>

                            </div>

                            <div class="resumen-numero">

                                <?php
                                echo $totalUsuarios;
                                ?>

                            </div>

                            <h3>
                                Usuarios
                            </h3>

                            <p>
                                Cuentas con acceso al sistema.
                            </p>

                        </div>

                    </div>

                <?php } ?>

            </div>

        </div>

    </section>

    <!-- HABITACIONES -->

    <section
        id="habitaciones"
        class="seccion seccion-blanca"
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
                        Habitaciones registradas
                    </h2>

                    <p
                        class="seccion-texto mb-0"
                    >

                        Consulta las habitaciones recientes,
                        su estado, capacidad y precio.

                    </p>

                </div>

                <div
                    class="col-lg-4 text-lg-end"
                >

                    <div
                        class="d-flex flex-wrap justify-content-lg-end gap-2"
                    >

                        <a
                            href="habitaciones/index.php"
                            class="btn-administrar"
                        >

                            <i
                                class="bi bi-plus-circle"
                            ></i>

                            Administrar habitaciones

                        </a>

                    </div>

                </div>

            </div>

            <?php if (
                $habitaciones &&
                mysqli_num_rows(
                    $habitaciones
                ) > 0
            ) { ?>

                <div class="row g-4">

                    <?php while (
                        $habitacion =
                            mysqli_fetch_assoc(
                                $habitaciones
                            )
                    ) { ?>

                        <?php

                        $rutaImagenHabitacion =
                            resolverImagen(
                                $habitacion["imagen"] ?? "",
                                "habitaciones",
                                "img/hotel.jpg"
                            );

                        ?>

                        <div
                            class="col-md-6 col-xl-4"
                        >

                            <article class="contenido-card">

                                <img
                                    src="<?php
                                    echo h(
                                        $rutaImagenHabitacion
                                    );
                                    ?>"
                                    alt="Habitación <?php
                                    echo h(
                                        $habitacion["numero"]
                                    );
                                    ?>"
                                    class="contenido-imagen"
                                    loading="lazy"
                                    onerror="this.onerror=null; this.src='img/hotel.jpg';"
                                >

                                <div
                                    class="contenido-card-body"
                                >

                                    <div
                                        class="d-flex justify-content-between align-items-start gap-2"
                                    >

                                        <div>

                                            <div
                                                class="contenido-etiqueta"
                                            >

                                                Habitación

                                                <?php
                                                echo h(
                                                    $habitacion["numero"]
                                                );
                                                ?>

                                            </div>

                                            <h3>

                                                <?php
                                                echo h(
                                                    $habitacion["tipo"]
                                                );
                                                ?>

                                            </h3>

                                        </div>

                                        <span
                                            class="estado <?php
                                            echo h(
                                                claseHabitacion(
                                                    $habitacion["estado_actual"]
                                                )
                                            );
                                            ?>"
                                        >

                                            <?php
                                            echo h(
                                                $habitacion["estado_actual"]
                                            );
                                            ?>

                                        </span>

                                    </div>

                                    <p>

                                        Capacidad para

                                        <?php
                                        echo (int)
                                            $habitacion["capacidad"];
                                        ?>

                                        persona(s).

                                    </p>

                                    <div
                                        class="d-flex justify-content-between align-items-end gap-3"
                                    >

                                        <div>

                                            <small
                                                class="text-muted d-block"
                                            >
                                                Precio por noche
                                            </small>

                                            <div
                                                class="contenido-precio"
                                            >

                                                $<?php
                                                echo number_format(
                                                    (float)
                                                    $habitacion["precio"],
                                                    2
                                                );
                                                ?>

                                            </div>

                                        </div>

                                        <a
                                            href="habitaciones/editar.php?id=<?php
                                            echo (int)
                                                $habitacion["id_habitacion"];
                                            ?>"
                                            class="btn-administrar"
                                        >

                                            <i
                                                class="bi bi-pencil-square"
                                            ></i>

                                            Editar

                                        </a>

                                    </div>

                                </div>

                            </article>

                        </div>

                    <?php } ?>

                </div>

            <?php } else { ?>

                <div
                    class="alert alert-info text-center"
                >
                    No existen habitaciones registradas.
                </div>

            <?php } ?>

        </div>

    </section>

    <!-- RESERVAS -->

    <section
        id="reservas"
        class="seccion seccion-verde"
    >

        <div class="container">

            <div
                class="row align-items-end mb-4 g-3"
            >

                <div class="col-lg-8">

                    <p class="seccion-etiqueta">
                        ALOJAMIENTO
                    </p>

                    <h2
                        class="seccion-titulo mb-2"
                    >
                        Reservas recientes
                    </h2>

                    <p
                        class="seccion-texto mb-0"
                    >
                        Revisa huéspedes, personas, plan de desayuno,
                        fechas, valores y estado de las últimas reservas.
                    </p>

                </div>

                <div
                    class="col-lg-4 text-lg-end"
                >

                    <a
                        href="reservas/index.php"
                        class="btn-administrar"
                    >

                        <i
                            class="bi bi-calendar2-check"
                        ></i>

                        Administrar reservas

                    </a>

                </div>

            </div>

            <div class="tabla-card">

                <div class="table-responsive">

                    <table
                        class="table tabla-hotel align-middle"
                    >

                        <thead>

                            <tr>
                                <th>Reserva</th>
                                <th>Cliente</th>
                                <th>Habitación</th>
                                <th>Personas</th>
                                <th>Plan</th>
                                <th>Entrada</th>
                                <th>Salida</th>
                                <th>Total</th>
                                <th>Estado</th>
                                <th>Acción</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php if (
                            $reservas &&
                            mysqli_num_rows(
                                $reservas
                            ) > 0
                        ) { ?>

                            <?php while (
                                $reserva =
                                    mysqli_fetch_assoc(
                                        $reservas
                                    )
                            ) { ?>

                                <tr>

                                    <td>
                                        <strong>
                                            #<?php
                                            echo (int)
                                                $reserva["id_reserva"];
                                            ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?php
                                        echo h(
                                            $reserva["nombres"] .
                                            " " .
                                            $reserva["apellidos"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        Hab.
                                        <?php
                                        echo h(
                                            $reserva[
                                                "numero_habitacion"
                                            ]
                                        );
                                        ?>

                                        <small
                                            class="d-block text-muted"
                                        >
                                            <?php
                                            echo h(
                                                $reserva[
                                                    "tipo_habitacion"
                                                ]
                                            );
                                            ?>
                                        </small>
                                    </td>

                                    <td>
                                        <strong>
                                            <?php
                                            echo max(
                                                1,
                                                (int)
                                                $reserva[
                                                    "numero_personas"
                                                ]
                                            );
                                            ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?php if (
                                            $reserva[
                                                "plan_alimentacion"
                                            ] ===
                                            "Alojamiento con desayuno"
                                        ) { ?>

                                            <span
                                                class="estado estado-verde"
                                            >
                                                Con desayuno
                                            </span>

                                            <small
                                                class="d-block text-muted mt-1"
                                            >
                                                $<?php
                                                echo number_format(
                                                    (float)
                                                    $reserva[
                                                        "total_alimentacion"
                                                    ],
                                                    2
                                                );
                                                ?>
                                            </small>

                                        <?php } else { ?>

                                            <span
                                                class="estado estado-gris"
                                            >
                                                Solo alojamiento
                                            </span>

                                        <?php } ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo h(
                                            formatearFecha(
                                                $reserva[
                                                    "fecha_entrada"
                                                ]
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo h(
                                            formatearFecha(
                                                $reserva[
                                                    "fecha_salida"
                                                ]
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <strong>
                                            $<?php
                                            echo number_format(
                                                (float)
                                                $reserva["total"],
                                                2
                                            );
                                            ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <span
                                            class="estado <?php
                                            echo h(
                                                claseReserva(
                                                    $reserva["estado"]
                                                )
                                            );
                                            ?>"
                                        >
                                            <?php
                                            echo h(
                                                $reserva["estado"]
                                            );
                                            ?>
                                        </span>
                                    </td>

                                    <td>
                                        <a
                                            href="reservas/editar.php?id=<?php
                                            echo (int)
                                                $reserva["id_reserva"];
                                            ?>"
                                            class="accion-tabla"
                                        >
                                            <i
                                                class="bi bi-pencil-square"
                                            ></i>

                                            Editar
                                        </a>
                                    </td>

                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>
                                <td
                                    colspan="10"
                                    class="tabla-vacia"
                                >
                                    No existen reservas registradas.
                                </td>
                            </tr>

                        <?php } ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </section>

    <!-- COMIDAS -->

    <section
        id="comidas"
        class="seccion seccion-blanca"
    >

        <div class="container">

            <div
                class="row align-items-end mb-5 g-3"
            >

                <div class="col-lg-8">

                    <p class="seccion-etiqueta">
                        ALIMENTACIÓN
                    </p>

                    <h2
                        class="seccion-titulo mb-2"
                    >
                        Comidas registradas
                    </h2>

                    <p
                        class="seccion-texto mb-0"
                    >

                        Revisa el menú, los precios y el
                        estado de disponibilidad.

                    </p>

                </div>

                <div
                    class="col-lg-4 text-lg-end"
                >

                    <a
                        href="comidas/index.php"
                        class="btn-administrar"
                    >

                        <i
                            class="bi bi-cup-hot"
                        ></i>

                        Administrar comidas

                    </a>

                </div>

            </div>

            <?php if (
                $comidas &&
                mysqli_num_rows(
                    $comidas
                ) > 0
            ) { ?>

                <div class="row g-4">

                    <?php while (
                        $comida =
                            mysqli_fetch_assoc(
                                $comidas
                            )
                    ) { ?>

                        <?php

                        $rutaImagenComida =
                            resolverImagen(
                                $comida["imagen"] ?? "",
                                "comidas",
                                "img/hotel.jpg"
                            );

                        ?>

                        <div
                            class="col-md-6 col-xl-4"
                        >

                            <article class="contenido-card">

                                <img
                                    src="<?php
                                    echo h(
                                        $rutaImagenComida
                                    );
                                    ?>"
                                    alt="<?php
                                    echo h(
                                        $comida["nombre"]
                                    );
                                    ?>"
                                    class="contenido-imagen"
                                    loading="lazy"
                                    onerror="this.onerror=null; this.src='img/hotel.jpg';"
                                >

                                <div
                                    class="contenido-card-body"
                                >

                                    <div
                                        class="d-flex justify-content-between align-items-start gap-2"
                                    >

                                        <div>

                                            <div
                                                class="contenido-etiqueta"
                                            >

                                                <?php
                                                echo h(
                                                    $comida["tipo"]
                                                );
                                                ?>

                                            </div>

                                            <h3>

                                                <?php
                                                echo h(
                                                    $comida["nombre"]
                                                );
                                                ?>

                                            </h3>

                                        </div>

                                        <span
                                            class="estado <?php
                                            echo h(
                                                $comida["estado"] ===
                                                "Disponible"
                                                    ? "estado-verde"
                                                    : "estado-rojo"
                                            );
                                            ?>"
                                        >

                                            <?php
                                            echo h(
                                                $comida["estado"]
                                            );
                                            ?>

                                        </span>

                                    </div>

                                    <p>

                                        <?php

                                        $descripcion = trim(
                                            (string)
                                            $comida["descripcion"]
                                        );

                                        echo h(
                                            $descripcion !== ""
                                                ? $descripcion
                                                : "Sin descripción."
                                        );

                                        ?>

                                    </p>

                                    <div
                                        class="d-flex justify-content-between align-items-center gap-3"
                                    >

                                        <div
                                            class="contenido-precio"
                                        >

                                            $<?php
                                            echo number_format(
                                                (float)
                                                $comida["precio"],
                                                2
                                            );
                                            ?>

                                        </div>

                                        <a
                                            href="comidas/editar.php?id=<?php
                                            echo (int)
                                                $comida["id_comida"];
                                            ?>"
                                            class="btn-administrar"
                                        >

                                            <i
                                                class="bi bi-pencil-square"
                                            ></i>

                                            Editar

                                        </a>

                                    </div>

                                </div>

                            </article>

                        </div>

                    <?php } ?>

                </div>

            <?php } else { ?>

                <div
                    class="alert alert-info text-center"
                >
                    No existen comidas registradas.
                </div>

            <?php } ?>

        </div>

    </section>

    <!-- PEDIDOS -->

    <section
        id="pedidos"
        class="seccion seccion-verde"
    >

        <div class="container">

            <div
                class="row align-items-end mb-4 g-3"
            >

                <div class="col-lg-8">

                    <p class="seccion-etiqueta">
                        SOLICITUDES DE ALIMENTACIÓN
                    </p>

                    <h2
                        class="seccion-titulo mb-2"
                    >
                        Pedidos recientes
                    </h2>

                    <p
                        class="seccion-texto mb-0"
                    >
                        Consulta preparación, forma de pago,
                        habitación relacionada y estado del cobro.
                    </p>

                </div>

                <div
                    class="col-lg-4 text-lg-end"
                >

                    <a
                        href="pedidos/index.php"
                        class="btn-administrar"
                    >

                        <i
                            class="bi bi-receipt"
                        ></i>

                        Administrar pedidos

                    </a>

                </div>

            </div>

            <div class="tabla-card">

                <div class="table-responsive">

                    <table
                        class="table tabla-hotel align-middle"
                    >

                        <thead>

                            <tr>
                                <th>Pedido</th>
                                <th>Cliente</th>
                                <th>Comida</th>
                                <th>Cant.</th>
                                <th>Total</th>
                                <th>Forma de pago</th>
                                <th>Pago</th>
                                <th>Fecha</th>
                                <th>Estado</th>
                                <th>Acción</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php if (
                            $pedidos &&
                            mysqli_num_rows(
                                $pedidos
                            ) > 0
                        ) { ?>

                            <?php while (
                                $pedido =
                                    mysqli_fetch_assoc(
                                        $pedidos
                                    )
                            ) { ?>

                                <tr>

                                    <td>
                                        <strong>
                                            #<?php
                                            echo (int)
                                                $pedido["id_pedido"];
                                            ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?php
                                        echo h(
                                            $pedido["nombres"] .
                                            " " .
                                            $pedido["apellidos"]
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo h(
                                            $pedido["nombre_comida"]
                                        );
                                        ?>

                                        <small
                                            class="d-block text-muted"
                                        >
                                            <?php
                                            echo h(
                                                $pedido["tipo_comida"]
                                            );
                                            ?>
                                        </small>
                                    </td>

                                    <td>
                                        <?php
                                        echo (int)
                                            $pedido["cantidad"];
                                        ?>
                                    </td>

                                    <td>
                                        <strong>
                                            $<?php
                                            echo number_format(
                                                (float)
                                                $pedido["total"],
                                                2
                                            );
                                            ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?php
                                        echo h(
                                            $pedido["forma_pago"]
                                        );
                                        ?>

                                        <?php if (
                                            $pedido["forma_pago"] ===
                                            "Cargar a la habitación"
                                        ) { ?>

                                            <small
                                                class="d-block text-muted"
                                            >
                                                <?php if (
                                                    trim(
                                                        (string)
                                                        $pedido[
                                                            "numero_habitacion"
                                                        ]
                                                    ) !== ""
                                                ) { ?>
                                                    Hab.
                                                    <?php
                                                    echo h(
                                                        $pedido[
                                                            "numero_habitacion"
                                                        ]
                                                    );
                                                    ?>
                                                <?php } ?>

                                                <?php if (
                                                    (int)
                                                    $pedido[
                                                        "reserva_relacionada"
                                                    ] > 0
                                                ) { ?>
                                                    · Reserva #
                                                    <?php
                                                    echo (int)
                                                        $pedido[
                                                            "reserva_relacionada"
                                                        ];
                                                    ?>
                                                <?php } ?>
                                            </small>

                                        <?php } ?>
                                    </td>

                                    <td>
                                        <span
                                            class="estado <?php
                                            echo h(
                                                clasePagoPedido(
                                                    $pedido[
                                                        "estado_pago"
                                                    ]
                                                )
                                            );
                                            ?>"
                                        >
                                            <?php
                                            echo h(
                                                $pedido["estado_pago"]
                                            );
                                            ?>
                                        </span>

                                        <?php if (
                                            trim(
                                                (string)
                                                $pedido["fecha_pago"]
                                            ) !== ""
                                        ) { ?>

                                            <small
                                                class="d-block text-muted mt-1"
                                            >
                                                <?php
                                                echo h(
                                                    formatearFecha(
                                                        $pedido[
                                                            "fecha_pago"
                                                        ],
                                                        true
                                                    )
                                                );
                                                ?>
                                            </small>

                                        <?php } ?>
                                    </td>

                                    <td>
                                        <?php
                                        echo h(
                                            formatearFecha(
                                                $pedido[
                                                    "fecha_pedido"
                                                ],
                                                true
                                            )
                                        );
                                        ?>
                                    </td>

                                    <td>
                                        <span
                                            class="estado <?php
                                            echo h(
                                                clasePedido(
                                                    $pedido["estado"]
                                                )
                                            );
                                            ?>"
                                        >
                                            <?php
                                            echo h(
                                                $pedido["estado"]
                                            );
                                            ?>
                                        </span>
                                    </td>

                                    <td>
                                        <a
                                            href="pedidos/index.php"
                                            class="accion-tabla"
                                        >
                                            <i
                                                class="bi bi-gear"
                                            ></i>

                                            Gestionar
                                        </a>
                                    </td>

                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>
                                <td
                                    colspan="10"
                                    class="tabla-vacia"
                                >
                                    No existen pedidos registrados.
                                </td>
                            </tr>

                        <?php } ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </section>

    <!-- CLIENTES -->

    <section
        id="clientes"
        class="seccion seccion-blanca"
    >

        <div class="container">

            <div
                class="row align-items-end mb-4 g-3"
            >

                <div class="col-lg-8">

                    <p class="seccion-etiqueta">
                        HUÉSPEDES
                    </p>

                    <h2
                        class="seccion-titulo mb-2"
                    >
                        Clientes recientes
                    </h2>

                    <p
                        class="seccion-texto mb-0"
                    >
                        Últimos clientes registrados en el sistema.
                    </p>

                </div>

                <div
                    class="col-lg-4 text-lg-end"
                >

                    <a
                        href="clientes/index.php"
                        class="btn-administrar"
                    >

                        <i
                            class="bi bi-people"
                        ></i>

                        Administrar clientes

                    </a>

                </div>

            </div>

            <div class="tabla-card">

                <div class="table-responsive">

                    <table
                        class="table tabla-hotel align-middle"
                    >

                        <thead>

                            <tr>
                                <th>ID</th>
                                <th>Cliente</th>
                                <th>Cédula</th>
                                <th>Correo</th>
                                <th>Teléfono</th>
                                <th>Acción</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php if (
                            $clientes &&
                            mysqli_num_rows(
                                $clientes
                            ) > 0
                        ) { ?>

                            <?php while (
                                $cliente =
                                    mysqli_fetch_assoc(
                                        $clientes
                                    )
                            ) { ?>

                                <tr>

                                    <td>

                                        <?php
                                        echo (int)
                                            $cliente["id_cliente"];
                                        ?>

                                    </td>

                                    <td>

                                        <strong>

                                            <?php
                                            echo h(
                                                $cliente["nombres"] .
                                                " " .
                                                $cliente["apellidos"]
                                            );
                                            ?>

                                        </strong>

                                    </td>

                                    <td>

                                        <?php
                                        echo h(
                                            $cliente["cedula"]
                                        );
                                        ?>

                                    </td>

                                    <td>

                                        <?php
                                        echo h(
                                            $cliente["correo"]
                                        );
                                        ?>

                                    </td>

                                    <td>

                                        <?php
                                        echo h(
                                            $cliente["telefono"]
                                        );
                                        ?>

                                    </td>

                                    <td>

                                        <a
                                            href="clientes/editar.php?id=<?php
                                            echo (int)
                                                $cliente["id_cliente"];
                                            ?>"
                                            class="accion-tabla"
                                        >

                                            <i
                                                class="bi bi-pencil-square"
                                            ></i>

                                            Editar

                                        </a>

                                    </td>

                                </tr>

                            <?php } ?>

                        <?php } else { ?>

                            <tr>

                                <td
                                    colspan="6"
                                    class="tabla-vacia"
                                >
                                    No existen clientes registrados.
                                </td>

                            </tr>

                        <?php } ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </section>

    <!-- PAGOS -->

    <?php if ($esAdministrador) { ?>

        <section
            id="pagos"
            class="seccion seccion-verde"
        >

            <div class="container">

                <div
                    class="row align-items-end mb-4 g-3"
                >

                    <div class="col-lg-8">

                        <p class="seccion-etiqueta">
                            COMPROBANTES
                        </p>

                        <h2
                            class="seccion-titulo mb-2"
                        >
                            Pagos recientes
                        </h2>

                        <p
                            class="seccion-texto mb-0"
                        >

                            Revisa los métodos de pago,
                            montos y comprobantes enviados.

                        </p>

                    </div>

                    <div
                        class="col-lg-4 text-lg-end"
                    >

                        <a
                            href="pagos/index.php"
                            class="btn-administrar"
                        >

                            <i
                                class="bi bi-credit-card"
                            ></i>

                            Administrar pagos

                        </a>

                    </div>

                </div>

                <div class="tabla-card">

                    <div class="table-responsive">

                        <table
                            class="table tabla-hotel align-middle"
                        >

                            <thead>

                                <tr>
                                    <th>Pago</th>
                                    <th>Reserva</th>
                                    <th>Cliente</th>
                                    <th>Habitación</th>
                                    <th>Método</th>
                                    <th>Monto</th>
                                    <th>Estado</th>
                                    <th>Fecha</th>
                                    <th>Acción</th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php if (
                                $pagos &&
                                mysqli_num_rows(
                                    $pagos
                                ) > 0
                            ) { ?>

                                <?php while (
                                    $pago =
                                        mysqli_fetch_assoc(
                                            $pagos
                                        )
                                ) { ?>

                                    <tr>

                                        <td>

                                            <strong>

                                                #<?php
                                                echo (int)
                                                    $pago["id_pago"];
                                                ?>

                                            </strong>

                                        </td>

                                        <td>

                                            #<?php
                                            echo (int)
                                                $pago["id_reserva"];
                                            ?>

                                        </td>

                                        <td>

                                            <?php
                                            echo h(
                                                $pago["nombres"] .
                                                " " .
                                                $pago["apellidos"]
                                            );
                                            ?>

                                        </td>

                                        <td>

                                            <?php
                                            echo h(
                                                $pago["numero_habitacion"]
                                            );
                                            ?>

                                        </td>

                                        <td>

                                            <?php
                                            echo h(
                                                $pago["metodo_pago"]
                                            );
                                            ?>

                                        </td>

                                        <td>

                                            <strong>

                                                $<?php
                                                echo number_format(
                                                    (float)
                                                    $pago["monto"],
                                                    2
                                                );
                                                ?>

                                            </strong>

                                        </td>

                                        <td>

                                            <span
                                                class="estado <?php
                                                echo h(
                                                    clasePago(
                                                        $pago["estado_pago"]
                                                    )
                                                );
                                                ?>"
                                            >

                                                <?php
                                                echo h(
                                                    $pago["estado_pago"]
                                                );
                                                ?>

                                            </span>

                                        </td>

                                        <td>

                                            <?php
                                            echo h(
                                                formatearFecha(
                                                    $pago["fecha_pago"],
                                                    true
                                                )
                                            );
                                            ?>

                                        </td>

                                        <td>

                                            <a
                                                href="pagos/index.php"
                                                class="accion-tabla"
                                            >

                                                <i
                                                    class="bi bi-eye"
                                                ></i>

                                                Revisar

                                            </a>

                                        </td>

                                    </tr>

                                <?php } ?>

                            <?php } else { ?>

                                <tr>

                                    <td
                                        colspan="9"
                                        class="tabla-vacia"
                                    >
                                        No existen pagos registrados.
                                    </td>

                                </tr>

                            <?php } ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        </section>

    <?php } ?>

    <!-- USUARIOS -->

    <?php if ($esAdministrador) { ?>

        <section
            id="usuarios"
            class="seccion seccion-blanca"
        >

            <div class="container">

                <div
                    class="row align-items-end mb-4 g-3"
                >

                    <div class="col-lg-8">

                        <p class="seccion-etiqueta">
                            ACCESO AL SISTEMA
                        </p>

                        <h2
                            class="seccion-titulo mb-2"
                        >
                            Usuarios recientes
                        </h2>

                        <p
                            class="seccion-texto mb-0"
                        >

                            Administra las cuentas y los
                            roles que tienen acceso al sistema.

                        </p>

                    </div>

                    <div
                        class="col-lg-4 text-lg-end"
                    >

                        <a
                            href="usuarios/index.php"
                            class="btn-administrar"
                        >

                            <i
                                class="bi bi-person-gear"
                            ></i>

                            Administrar usuarios

                        </a>

                    </div>

                </div>

                <div class="tabla-card">

                    <div class="table-responsive">

                        <table
                            class="table tabla-hotel align-middle"
                        >

                            <thead>

                                <tr>
                                    <th>ID</th>
                                    <th>Nombre</th>
                                    <th>Usuario</th>
                                    <th>Rol</th>
                                    <th>Acción</th>
                                </tr>

                            </thead>

                            <tbody>

                            <?php if (
                                $usuarios &&
                                mysqli_num_rows(
                                    $usuarios
                                ) > 0
                            ) { ?>

                                <?php while (
                                    $usuarioSistema =
                                        mysqli_fetch_assoc(
                                            $usuarios
                                        )
                                ) { ?>

                                    <tr>

                                        <td>

                                            <?php
                                            echo (int)
                                                $usuarioSistema["id_usuario"];
                                            ?>

                                        </td>

                                        <td>

                                            <strong>

                                                <?php
                                                echo h(
                                                    $usuarioSistema["nombre"]
                                                );
                                                ?>

                                            </strong>

                                        </td>

                                        <td>

                                            <?php
                                            echo h(
                                                $usuarioSistema["usuario"]
                                            );
                                            ?>

                                        </td>

                                        <td>

                                            <span
                                                class="estado <?php
                                                echo h(
                                                    $usuarioSistema["rol"] ===
                                                    "Administrador"
                                                        ? "estado-verde"
                                                        : (
                                                            $usuarioSistema["rol"] ===
                                                            "Recepcionista"
                                                                ? "estado-amarillo"
                                                                : "estado-azul"
                                                        )
                                                );
                                                ?>"
                                            >

                                                <?php
                                                echo h(
                                                    $usuarioSistema["rol"]
                                                );
                                                ?>

                                            </span>

                                        </td>

                                        <td>

                                            <a
                                                href="usuarios/editar.php?id=<?php
                                                echo (int)
                                                    $usuarioSistema["id_usuario"];
                                                ?>"
                                                class="accion-tabla"
                                            >

                                                <i
                                                    class="bi bi-pencil-square"
                                                ></i>

                                                Editar

                                            </a>

                                        </td>

                                    </tr>

                                <?php } ?>

                            <?php } else { ?>

                                <tr>

                                    <td
                                        colspan="5"
                                        class="tabla-vacia"
                                    >
                                        No existen usuarios registrados.
                                    </td>

                                </tr>

                            <?php } ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        </section>

    <?php } ?>

</main>

<footer class="footer-hotel">

    <div class="footer-principal">

        <div class="container">

            <div
                class="d-flex flex-wrap justify-content-between align-items-center gap-4"
            >

                <div
                    class="d-flex align-items-center gap-3 footer-marca"
                >

                    <img
                        src="img/logo.png"
                        alt="Hotel Las 3 Palmeras"
                        class="footer-logo"
                    >

                    <div>

                        <h2>
                            Hotel Las 3 Palmeras
                        </h2>

                        <p class="mb-0">
                            Sistema administrativo hotelero.
                        </p>

                    </div>

                </div>

                <div
                    class="d-flex flex-wrap gap-3"
                >

                    <a href="#habitaciones">
                        Habitaciones
                    </a>

                    <a href="#reservas">
                        Reservas
                    </a>

                    <a href="#comidas">
                        Comidas
                    </a>

                    <a href="#pedidos">
                        Pedidos
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

                Sesión:

                <?php
                echo h(
                    $_SESSION["rol"]
                );
                ?>

            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>

    const barraNavegacion =
        document.querySelector(
            ".navbar-hotel"
        );

    function actualizarBarra() {

        if (window.scrollY > 30) {

            barraNavegacion.classList.add(
                "navbar-con-sombra"
            );

        } else {

            barraNavegacion.classList.remove(
                "navbar-con-sombra"
            );
        }
    }

    actualizarBarra();

    window.addEventListener(
        "scroll",
        actualizarBarra
    );

    document
        .querySelectorAll(
            '#menuDashboard a[href^="#"]'
        )
        .forEach((enlace) => {

            enlace.addEventListener(
                "click",
                () => {

                    const menu =
                        document.getElementById(
                            "menuDashboard"
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