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

function imagenSegura($imagen)
{
    $imagen = trim((string) $imagen);

    if ($imagen === "") {
        return "../img/hotel.jpg";
    }

    if (!filter_var($imagen, FILTER_VALIDATE_URL)) {
        return "../img/hotel.jpg";
    }

    $esquema = strtolower(
        (string) parse_url($imagen, PHP_URL_SCHEME)
    );

    if (!in_array($esquema, ["http", "https"], true)) {
        return "../img/hotel.jpg";
    }

    return $imagen;
}

function formatearFechaPedido($fecha)
{
    try {
        return (new DateTimeImmutable((string) $fecha))
            ->format("d/m/Y h:i A");
    } catch (Throwable $excepcion) {
        return (string) $fecha;
    }
}

function claseEstado($estado)
{
    switch ($estado) {
        case "Pendiente":
            return "estado-pendiente";

        case "Preparando":
            return "estado-preparando";

        case "Entregado":
            return "estado-entregado";

        case "Cancelado":
            return "estado-cancelado";

        default:
            return "estado-desconocido";
    }
}

function iconoEstado($estado)
{
    switch ($estado) {
        case "Pendiente":
            return "bi-clock";

        case "Preparando":
            return "bi-fire";

        case "Entregado":
            return "bi-check-circle";

        case "Cancelado":
            return "bi-x-circle";

        default:
            return "bi-question-circle";
    }
}

function claseEstadoPago($estado)
{
    switch ($estado) {
        case "Pagado":
            return "pago-pagado";

        case "Pendiente":
            return "pago-pendiente";

        default:
            return "pago-desconocido";
    }
}

function iconoEstadoPago($estado)
{
    switch ($estado) {
        case "Pagado":
            return "bi-check-circle";

        case "Pendiente":
            return "bi-hourglass-split";

        default:
            return "bi-question-circle";
    }
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

        $datosUsuario =
            mysqli_fetch_assoc($resultadoUsuario);

        mysqli_stmt_close($buscarUsuario);

        if ($datosUsuario) {
            $idUsuario =
                (int) $datosUsuario["id_usuario"];

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

$idCliente =
    (int) $cliente["id_cliente"];

$nombreCliente = trim(
    (string) $cliente["nombres"] .
    " " .
    (string) $cliente["apellidos"]
);

if ($nombreCliente === "") {
    $nombreCliente =
        (string) $_SESSION["usuario"];
}

/* Notificaciones */
$notificacionesCliente = [];

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
            $notificacionesCliente[] = $notificacion;
        }
    }

    mysqli_stmt_close($consultaNotificaciones);
}

$resumen = [
    "total" => 0,
    "pendientes" => 0,
    "preparando" => 0,
    "entregados" => 0,
    "cancelados" => 0,
    "pagos_pendientes" => 0,
    "pagados" => 0,
    "valor_acumulado" => 0.00
];

$consultaResumen = mysqli_prepare(
    $conn,
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(estado = 'Pendiente'), 0) AS pendientes,
        COALESCE(SUM(estado = 'Preparando'), 0) AS preparando,
        COALESCE(SUM(estado = 'Entregado'), 0) AS entregados,
        COALESCE(SUM(estado = 'Cancelado'), 0) AS cancelados,
        COALESCE(SUM(estado_pago = 'Pendiente'), 0) AS pagos_pendientes,
        COALESCE(SUM(estado_pago = 'Pagado'), 0) AS pagados,
        COALESCE(
            SUM(
                CASE
                    WHEN estado <> 'Cancelado' THEN total
                    ELSE 0
                END
            ),
            0
        ) AS valor_acumulado
     FROM pedidos_comida
     WHERE id_cliente = ?"
);

if ($consultaResumen) {
    mysqli_stmt_bind_param(
        $consultaResumen,
        "i",
        $idCliente
    );

    if (mysqli_stmt_execute($consultaResumen)) {
        $resultadoResumen =
            mysqli_stmt_get_result($consultaResumen);

        $datosResumen =
            mysqli_fetch_assoc($resultadoResumen);

        if ($datosResumen) {
            $resumen["total"] =
                (int) $datosResumen["total"];

            $resumen["pendientes"] =
                (int) $datosResumen["pendientes"];

            $resumen["preparando"] =
                (int) $datosResumen["preparando"];

            $resumen["entregados"] =
                (int) $datosResumen["entregados"];

            $resumen["cancelados"] =
                (int) $datosResumen["cancelados"];

            $resumen["pagos_pendientes"] =
                (int) $datosResumen["pagos_pendientes"];

            $resumen["pagados"] =
                (int) $datosResumen["pagados"];

            $resumen["valor_acumulado"] =
                (float) $datosResumen["valor_acumulado"];
        }
    }

    mysqli_stmt_close($consultaResumen);
}

$filtrosPermitidos = [
    "Todos",
    "Pendiente",
    "Preparando",
    "Entregado",
    "Cancelado"
];

$estadoFiltro =
    trim((string) ($_GET["estado"] ?? "Todos"));

if (!in_array($estadoFiltro, $filtrosPermitidos, true)) {
    $estadoFiltro = "Todos";
}

$totalesFiltro = [
    "Todos" => $resumen["total"],
    "Pendiente" => $resumen["pendientes"],
    "Preparando" => $resumen["preparando"],
    "Entregado" => $resumen["entregados"],
    "Cancelado" => $resumen["cancelados"]
];

$totalFiltrado =
    (int) ($totalesFiltro[$estadoFiltro] ?? 0);

$porPagina = 10;

$paginaActual = max(
    1,
    (int) ($_GET["pagina"] ?? 1)
);

$totalPaginas = max(
    1,
    (int) ceil($totalFiltrado / $porPagina)
);

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset =
    ($paginaActual - 1) * $porPagina;

$pedidos = [];
$errorConsulta = "";

$sqlPedidos =
    "SELECT
        p.id_pedido,
        p.cantidad,
        p.precio_unitario,
        p.total,
        p.forma_pago,
        p.estado_pago,
        p.fecha_pago,
        p.estado,
        p.observacion,
        p.fecha_pedido,
        p.id_reserva,

        c.nombre AS nombre_comida,
        c.tipo,
        c.imagen,

        r.id_reserva AS reserva_relacionada,
        r.fecha_entrada AS reserva_entrada,
        r.fecha_salida AS reserva_salida,

        h.numero AS numero_habitacion,
        h.tipo AS tipo_habitacion

     FROM pedidos_comida p

     INNER JOIN comidas c
        ON c.id_comida = p.id_comida

     LEFT JOIN reservas r
        ON r.id_reserva = p.id_reserva
       AND r.id_cliente = p.id_cliente

     LEFT JOIN habitaciones h
        ON h.id_habitacion = r.id_habitacion

     WHERE p.id_cliente = ?";

if ($estadoFiltro !== "Todos") {
    $sqlPedidos .=
        " AND p.estado = ?";
}

$sqlPedidos .=
    $estadoFiltro === "Todos"
        ? " ORDER BY
                CASE
                    WHEN p.estado = 'Pendiente' THEN 0
                    WHEN p.estado = 'Preparando' THEN 1
                    WHEN p.estado = 'Entregado' THEN 2
                    WHEN p.estado = 'Cancelado' THEN 3
                    ELSE 4
                END,
                p.fecha_pedido DESC,
                p.id_pedido DESC
            LIMIT ? OFFSET ?"
        : " ORDER BY
                p.fecha_pedido DESC,
                p.id_pedido DESC
            LIMIT ? OFFSET ?";

$consultaPedidos =
    mysqli_prepare($conn, $sqlPedidos);

if (!$consultaPedidos) {
    $errorConsulta =
        "No se pudieron preparar tus pedidos.";
} else {
    if ($estadoFiltro === "Todos") {
        mysqli_stmt_bind_param(
            $consultaPedidos,
            "iii",
            $idCliente,
            $porPagina,
            $offset
        );
    } else {
        mysqli_stmt_bind_param(
            $consultaPedidos,
            "isii",
            $idCliente,
            $estadoFiltro,
            $porPagina,
            $offset
        );
    }

    if (!mysqli_stmt_execute($consultaPedidos)) {
        $errorConsulta =
            "No se pudieron consultar tus pedidos.";
    } else {
        $resultadoPedidos =
            mysqli_stmt_get_result($consultaPedidos);

        while (
            $pedido =
                mysqli_fetch_assoc($resultadoPedidos)
        ) {
            $pedidos[] = $pedido;
        }
    }

    mysqli_stmt_close($consultaPedidos);
}

$primerRegistro =
    $totalFiltrado > 0
        ? $offset + 1
        : 0;

$ultimoRegistro =
    min(
        $offset + $porPagina,
        $totalFiltrado
    );

$paginaInicio = max(
    1,
    $paginaActual - 2
);

$paginaFin = min(
    $totalPaginas,
    $paginaActual + 2
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
        Mis pedidos - Hotel Las 3 Palmeras
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
            background-color: rgba(255, 255, 255, .08);
            color: white;
            font-size: 17px;
        }

        .btn-notificaciones-cliente:hover,
        .btn-notificaciones-cliente:focus {
            border-color: rgba(240, 217, 159, .75);
            background-color: rgba(255, 255, 255, .15);
            color: white;
        }

        .contador-notificaciones-cliente {
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
            background-color: #cf3f3f;
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
            background-color: white;
            box-shadow: 0 18px 46px rgba(14, 35, 23, .20);
        }

        .notificaciones-cliente-cabecera {
            padding: 16px 18px;
            border-bottom: 1px solid #e8ebe7;
            background-color: #fbfcfa;
        }

        .notificaciones-cliente-cabecera strong {
            display: block;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 17px;
        }

        .notificaciones-cliente-cabecera small {
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
            background-color: #f4f8f5;
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
            font-size: 15px;
        }

        .notificacion-aceptada {
            background-color: #e2f3e7;
            color: #21643b;
        }

        .notificacion-rechazada {
            background-color: #fff0f0;
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

        .notificaciones-cliente-vacio {
            padding: 28px 20px;
            color: var(--texto-suave);
            text-align: center;
            font-size: 12px;
        }

        .notificaciones-cliente-pie {
            padding: 12px 18px;
            border-top: 1px solid #e8ebe7;
            background-color: #fbfcfa;
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
            min-height: 355px;
            display: flex;
            align-items: center;
            margin-top: 82px;
            color: white;
            background:
                linear-gradient(
                    90deg,
                    rgba(10, 29, 20, .92),
                    rgba(10, 29, 20, .58)
                ),
                url("../img/hotel.jpg") center/cover;
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
            font-size: clamp(2.8rem, 6vw, 5rem);
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

        .mensaje-error {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            padding: 14px 17px;
            border: 1px solid #edc8c8;
            border-radius: 6px;
            background: #fff1f1;
            color: #9b3131;
            font-size: 13px;
        }

        .resumen-card {
            height: 100%;
            padding: 22px;
            border: 1px solid #e2e4de;
            border-radius: 8px;
            background: white;
            box-shadow: var(--sombra);
        }

        .resumen-card small {
            color: var(--texto-suave);
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .resumen-card strong {
            display: block;
            margin-top: 7px;
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 31px;
        }

        .contador {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 13px;
            border-radius: 999px;
            background: var(--verde-claro);
            color: var(--verde);
            font-size: 12px;
            font-weight: 900;
        }

        .resumen-pedidos {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 34px;
        }

        .resumen-card {
            padding: 18px;
        }

        .resumen-card strong {
            font-size: 27px;
        }

        .filtros-pedidos {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 18px;
        }

        .filtro-pedido {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 39px;
            padding: 8px 12px;
            border: 1px solid #d9ded8;
            border-radius: 999px;
            background-color: white;
            color: #586159;
            font-size: 11px;
            font-weight: 800;
        }

        .filtro-pedido:hover {
            border-color: var(--verde);
            color: var(--verde);
        }

        .filtro-pedido.activo {
            border-color: var(--verde);
            background-color: var(--verde);
            color: white;
        }

        .filtro-cantidad {
            min-width: 23px;
            height: 23px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
            border-radius: 999px;
            background-color: var(--verde-claro);
            color: var(--verde);
            font-size: 9px;
            font-weight: 900;
        }

        .filtro-pedido.activo .filtro-cantidad {
            background-color: rgba(255, 255, 255, .16);
            color: white;
        }

        .resumen-pagos {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
            color: var(--texto-suave);
            font-size: 11px;
        }

        .resumen-pagos span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .paginacion-contenedor {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-top: 28px;
            padding: 18px 20px;
            border: 1px solid #e2e4de;
            border-radius: 8px;
            background-color: white;
        }

        .paginacion-info {
            color: var(--texto-suave);
            font-size: 12px;
        }

        .paginacion-hotel {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .paginacion-hotel a,
        .paginacion-hotel span {
            min-width: 38px;
            height: 38px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 11px;
            border: 1px solid #dce1dc;
            border-radius: 5px;
            background-color: white;
            color: var(--verde);
            font-size: 12px;
            font-weight: 800;
        }

        .paginacion-hotel a:hover {
            border-color: var(--verde);
            background-color: var(--verde-claro);
        }

        .paginacion-hotel .pagina-activa {
            border-color: var(--verde);
            background-color: var(--verde);
            color: white;
        }

        .paginacion-hotel .pagina-deshabilitada {
            opacity: .45;
        }

        .btn-nuevo-pedido {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 10px 16px;
            border: 1px solid var(--verde);
            border-radius: 5px;
            background: var(--verde);
            color: white;
            font-size: 12px;
            font-weight: 900;
        }

        .btn-nuevo-pedido:hover {
            background: var(--verde-oscuro);
            color: white;
        }

        .pedido-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            box-shadow: var(--sombra);
        }

        .pedido-card + .pedido-card {
            margin-top: 28px;
        }

        .pedido-imagen {
            width: 100%;
            height: 100%;
            min-height: 330px;
            object-fit: cover;
        }

        .pedido-contenido {
            padding: 27px;
        }

        .pedido-tipo {
            color: #9b7739;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 1.7px;
        }

        .pedido-titulo {
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
        }

        .estado-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
        }

        .estado-pendiente {
            background: #fff0c7;
            color: #81600d;
        }

        .estado-preparando {
            background: #e2ecff;
            color: #285eab;
        }

        .estado-entregado {
            background: #dff2e4;
            color: #21643b;
        }

        .estado-cancelado {
            background: #fff0f0;
            color: #9d3030;
        }

        .estado-desconocido {
            background: #ececec;
            color: #555;
        }

        .detalle-box {
            height: 100%;
            padding: 16px;
            border: 1px solid #e2e5df;
            border-radius: 7px;
            background: #f7f9f7;
        }

        .dato-label {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .dato-valor {
            display: block;
            margin-top: 3px;
            color: #303731;
            font-size: 13px;
            font-weight: 700;
        }

        .pedido-total {
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 30px;
            font-weight: 700;
        }

        .observacion {
            display: flex;
            gap: 9px;
            margin-top: 16px;
            padding: 13px 15px;
            border: 1px solid #dedfd9;
            border-radius: 6px;
            background: #f7f8f5;
            color: #59615b;
            font-size: 12px;
            line-height: 1.6;
        }

        .estado-explicacion {
            margin-top: 17px;
            color: var(--texto-suave);
            font-size: 12px;
            line-height: 1.6;
        }

        .pago-pedido-box {
            margin-top: 18px;
            padding: 17px;
            border: 1px solid #dfe4de;
            border-radius: 8px;
            background: #fafbf9;
        }

        .pago-pedido-encabezado {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 13px;
        }

        .pago-pedido-titulo {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--verde-oscuro);
            font-size: 13px;
            font-weight: 900;
        }

        .pago-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
        }

        .pago-pagado {
            background: #dff2e4;
            color: #21643b;
        }

        .pago-pendiente {
            background: #fff0c7;
            color: #81600d;
        }

        .pago-desconocido {
            background: #ececec;
            color: #555;
        }

        .pago-pedido-fila {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            padding: 8px 0;
            border-bottom: 1px solid #e2e5df;
            color: var(--texto-suave);
            font-size: 12px;
        }

        .pago-pedido-fila:last-child {
            border-bottom: 0;
        }

        .pago-pedido-fila strong {
            color: #303731;
            text-align: right;
        }

        .vacio {
            padding: 60px 25px;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            text-align: center;
            box-shadow: var(--sombra);
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
            .resumen-pedidos {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .pedido-imagen {
                min-height: 275px;
                height: 275px;
            }
        }

        @media (max-width: 767px) {
            .resumen-pedidos {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .paginacion-contenedor {
                justify-content: center;
                text-align: center;
            }

            .paginacion-info {
                width: 100%;
            }

            .paginacion-hotel {
                justify-content: center;
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
                padding: 52px 0;
            }

            .pedido-contenido {
                padding: 21px;
            }
        }

        @media (max-width: 420px) {
            .marca-texto {
                display: none;
            }

            .resumen-pedidos {
                gap: 10px;
            }

            .resumen-card {
                padding: 15px;
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
                        class="nav-link"
                    >
                        Habitaciones
                    </a>
                </li>

                <li class="nav-item">
                    <a href="pedir_comida.php" class="nav-link">
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
                                class="dropdown-item active"
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
                        class="btn-notificaciones-cliente"
                        id="botonNotificacionesCliente"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        aria-label="Notificaciones"
                        title="Notificaciones"
                    >
                        <i class="bi bi-bell"></i>

                        <?php if (!empty($notificacionesCliente)) { ?>
                            <span
                                class="contador-notificaciones-cliente"
                                id="contadorNotificacionesCliente"
                            >
                                <?php echo count($notificacionesCliente); ?>
                            </span>
                        <?php } ?>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end menu-notificaciones-cliente">

                        <div class="notificaciones-cliente-cabecera">
                            <strong>Notificaciones</strong>
                            <small>Avisos importantes de tus pagos</small>
                        </div>

                        <div class="notificaciones-cliente-lista">

                            <?php if (!empty($notificacionesCliente)) { ?>

                                <?php foreach (
                                    $notificacionesCliente as $notificacion
                                ) { ?>

                                    <?php
                                    $pagoAceptado =
                                        $notificacion["estado_pago"] ===
                                        "Aceptado";

                                    $idNotificacion =
                                        "pago-" .
                                        (int) $notificacion["id_pago"];
                                    ?>

                                    <a
                                        href="mis_reservas.php"
                                        class="notificacion-cliente-item"
                                        data-notificacion-id="<?php echo h($idNotificacion); ?>"
                                    >
                                        <div class="notificacion-cliente-fila">

                                            <span
                                                class="notificacion-cliente-icono <?php echo $pagoAceptado ? "notificacion-aceptada" : "notificacion-rechazada"; ?>"
                                            >
                                                <i
                                                    class="bi <?php echo $pagoAceptado ? "bi-check-lg" : "bi-x-lg"; ?>"
                                                ></i>
                                            </span>

                                            <span class="notificacion-cliente-contenido">

                                                <strong>
                                                    <?php
                                                    echo $pagoAceptado
                                                        ? "Pago aceptado"
                                                        : "Pago rechazado";
                                                    ?>
                                                </strong>

                                                <span>
                                                    Reserva #
                                                    <?php echo (int) $notificacion["id_reserva"]; ?>
                                                    · Habitación
                                                    <?php echo h($notificacion["numero_habitacion"]); ?>
                                                </span>

                                                <span>
                                                    <?php if ($pagoAceptado) { ?>
                                                        Tu pago de
                                                        $<?php
                                                        echo number_format(
                                                            (float) $notificacion["monto"],
                                                            2
                                                        );
                                                        ?>
                                                        fue aceptado y la reserva fue confirmada.
                                                    <?php } else { ?>
                                                        Tu pago fue rechazado.
                                                        <?php if (
                                                            trim(
                                                                (string) $notificacion["observacion"]
                                                            ) !== ""
                                                        ) { ?>
                                                            <?php echo h($notificacion["observacion"]); ?>
                                                        <?php } ?>
                                                    <?php } ?>
                                                </span>

                                            </span>

                                        </div>
                                    </a>

                                <?php } ?>

                            <?php } else { ?>

                                <div class="notificaciones-cliente-vacio">
                                    <i class="bi bi-bell-slash d-block fs-4 mb-2"></i>
                                    No tienes avisos de pagos.
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
                SERVICIO DE ALIMENTACIÓN
            </div>

            <h1>Mis pedidos</h1>

            <p>
                Consulta las comidas solicitadas, su preparación,
                la forma de pago y la habitación relacionada.
            </p>

        </div>
    </div>
</section>

<main class="contenido-pagina">
    <div class="container">

        <?php if ($errorConsulta !== "") { ?>

            <div class="mensaje-error">
                <i class="bi bi-exclamation-triangle"></i>
                <?php echo h($errorConsulta); ?>
            </div>

        <?php } ?>

        <section class="resumen-pedidos">

            <div class="resumen-card">
                <small>Total</small>
                <strong><?php echo $resumen["total"]; ?></strong>
            </div>

            <div class="resumen-card">
                <small>Pendientes</small>
                <strong><?php echo $resumen["pendientes"]; ?></strong>
            </div>

            <div class="resumen-card">
                <small>Preparando</small>
                <strong><?php echo $resumen["preparando"]; ?></strong>
            </div>

            <div class="resumen-card">
                <small>Entregados</small>
                <strong><?php echo $resumen["entregados"]; ?></strong>
            </div>

            <div class="resumen-card">
                <small>Cancelados</small>
                <strong><?php echo $resumen["cancelados"]; ?></strong>
            </div>

        </section>

        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

            <div>
                <div class="pagina-etiqueta text-success">
                    HISTORIAL PERSONAL
                </div>

                <h2 class="mt-2 mb-1">
                    Pedidos registrados
                </h2>

                <p class="text-muted mb-0">
                    Selecciona un estado para ver tus pedidos ordenados.
                </p>
            </div>

            <a
                href="pedir_comida.php"
                class="btn-nuevo-pedido"
            >
                <i class="bi bi-plus-circle"></i>
                Realizar otro pedido
            </a>

        </div>

        <?php
        $etiquetasFiltro = [
            "Todos" => "Todos",
            "Pendiente" => "Pendientes",
            "Preparando" => "Preparando",
            "Entregado" => "Entregados",
            "Cancelado" => "Cancelados"
        ];
        ?>

        <div class="filtros-pedidos">

            <?php foreach (
                $etiquetasFiltro as $valorFiltro => $etiquetaFiltro
            ) { ?>

                <a
                    href="?estado=<?php echo urlencode($valorFiltro); ?>"
                    class="filtro-pedido <?php echo $estadoFiltro === $valorFiltro ? "activo" : ""; ?>"
                >
                    <?php echo h($etiquetaFiltro); ?>

                    <span class="filtro-cantidad">
                        <?php echo (int) $totalesFiltro[$valorFiltro]; ?>
                    </span>
                </a>

            <?php } ?>

        </div>

        <div class="resumen-pagos">
            <span>
                <i class="bi bi-hourglass-split"></i>
                Pagos pendientes:
                <strong><?php echo $resumen["pagos_pendientes"]; ?></strong>
            </span>

            <span>
                <i class="bi bi-check-circle"></i>
                Pagados:
                <strong><?php echo $resumen["pagados"]; ?></strong>
            </span>

            <span>
                <i class="bi bi-cash-stack"></i>
                Consumo acumulado:
                <strong>
                    $<?php echo number_format($resumen["valor_acumulado"], 2); ?>
                </strong>
            </span>
        </div>

        <?php if (!empty($pedidos)) { ?>

            <?php foreach ($pedidos as $pedido) { ?>

                <?php
                $rutaImagen =
                    imagenSegura($pedido["imagen"]);

                $estadoPedido =
                    trim((string) $pedido["estado"]);

                $fechaPedido =
                    formatearFechaPedido(
                        $pedido["fecha_pedido"]
                    );

                $formaPago =
                    trim(
                        (string) (
                            $pedido["forma_pago"] ??
                            "Pagar al recibir"
                        )
                    );

                $estadoPago =
                    trim(
                        (string) (
                            $pedido["estado_pago"] ??
                            "Pendiente"
                        )
                    );

                $fechaPago =
                    trim(
                        (string) (
                            $pedido["fecha_pago"] ?? ""
                        )
                    );

                $reservaRelacionada =
                    (int) (
                        $pedido["reserva_relacionada"] ?? 0
                    );
                ?>

                <article class="pedido-card">

                    <div class="row g-0">

                        <div class="col-lg-4">

                            <img
                                src="<?php echo h($rutaImagen); ?>"
                                alt="<?php echo h($pedido["nombre_comida"]); ?>"
                                class="pedido-imagen"
                                loading="lazy"
                                onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                            >

                        </div>

                        <div class="col-lg-8">

                            <div class="pedido-contenido">

                                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">

                                    <div>

                                        <div class="pedido-tipo">
                                            <?php
                                            echo h(
                                                strtoupper(
                                                    (string) $pedido["tipo"]
                                                )
                                            );
                                            ?>
                                        </div>

                                        <h2 class="pedido-titulo h3 mt-1 mb-1">
                                            <?php
                                            echo h($pedido["nombre_comida"]);
                                            ?>
                                        </h2>

                                        <div class="text-muted small">
                                            Pedido
                                            <strong>
                                                #<?php
                                                echo (int) $pedido["id_pedido"];
                                                ?>
                                            </strong>
                                        </div>

                                    </div>

                                    <span
                                        class="estado-badge <?php echo h(claseEstado($estadoPedido)); ?>"
                                    >
                                        <i
                                            class="bi <?php echo h(iconoEstado($estadoPedido)); ?>"
                                        ></i>

                                        <?php echo h($estadoPedido); ?>
                                    </span>

                                </div>

                                <div class="row g-3">

                                    <div class="col-sm-6 col-xl-3">

                                        <div class="detalle-box">

                                            <span class="dato-label">
                                                Cantidad
                                            </span>

                                            <span class="dato-valor">
                                                <?php
                                                echo (int) $pedido["cantidad"];
                                                ?>
                                            </span>

                                        </div>

                                    </div>

                                    <div class="col-sm-6 col-xl-3">

                                        <div class="detalle-box">

                                            <span class="dato-label">
                                                Precio unitario
                                            </span>

                                            <span class="dato-valor">
                                                $<?php
                                                echo number_format(
                                                    (float) $pedido["precio_unitario"],
                                                    2
                                                );
                                                ?>
                                            </span>

                                        </div>

                                    </div>

                                    <div class="col-sm-6 col-xl-3">

                                        <div class="detalle-box">

                                            <span class="dato-label">
                                                Fecha
                                            </span>

                                            <span class="dato-valor">
                                                <?php echo h($fechaPedido); ?>
                                            </span>

                                        </div>

                                    </div>

                                    <div class="col-sm-6 col-xl-3">

                                        <div class="detalle-box">

                                            <span class="dato-label">
                                                Total
                                            </span>

                                            <span class="pedido-total">
                                                $<?php
                                                echo number_format(
                                                    (float) $pedido["total"],
                                                    2
                                                );
                                                ?>
                                            </span>

                                        </div>

                                    </div>

                                </div>

                                <?php if (
                                    trim(
                                        (string) $pedido["observacion"]
                                    ) !== ""
                                ) { ?>

                                    <div class="observacion">

                                        <i class="bi bi-chat-left-text"></i>

                                        <div>
                                            <strong>Observación:</strong>

                                            <?php
                                            echo h($pedido["observacion"]);
                                            ?>
                                        </div>

                                    </div>

                                <?php } ?>

                                <div class="pago-pedido-box">

                                    <div class="pago-pedido-encabezado">

                                        <div class="pago-pedido-titulo">
                                            <i class="bi bi-wallet2"></i>
                                            Información del pago
                                        </div>

                                        <span
                                            class="pago-badge <?php
                                            echo h(
                                                claseEstadoPago(
                                                    $estadoPago
                                                )
                                            );
                                            ?>"
                                        >
                                            <i
                                                class="bi <?php
                                                echo h(
                                                    iconoEstadoPago(
                                                        $estadoPago
                                                    )
                                                );
                                                ?>"
                                            ></i>

                                            <?php echo h($estadoPago); ?>
                                        </span>

                                    </div>

                                    <div class="pago-pedido-fila">

                                        <span>Forma de pago</span>

                                        <strong>
                                            <?php echo h($formaPago); ?>
                                        </strong>

                                    </div>

                                    <?php if (
                                        $formaPago ===
                                        "Cargar a la habitación"
                                    ) { ?>

                                        <div class="pago-pedido-fila">

                                            <span>Reserva relacionada</span>

                                            <strong>
                                                <?php if (
                                                    $reservaRelacionada > 0
                                                ) { ?>
                                                    Reserva #
                                                    <?php
                                                    echo $reservaRelacionada;
                                                    ?>
                                                <?php } else { ?>
                                                    No disponible
                                                <?php } ?>
                                            </strong>

                                        </div>

                                        <div class="pago-pedido-fila">

                                            <span>Habitación</span>

                                            <strong>
                                                <?php if (
                                                    trim(
                                                        (string) (
                                                            $pedido[
                                                                "numero_habitacion"
                                                            ] ?? ""
                                                        )
                                                    ) !== ""
                                                ) { ?>
                                                    Habitación
                                                    <?php
                                                    echo h(
                                                        $pedido[
                                                            "numero_habitacion"
                                                        ]
                                                    );
                                                    ?>
                                                <?php } else { ?>
                                                    No disponible
                                                <?php } ?>
                                            </strong>

                                        </div>

                                    <?php } else { ?>

                                        <div class="pago-pedido-fila">

                                            <span>Entrega</span>

                                            <strong>
                                                Pago al recibir el pedido
                                            </strong>

                                        </div>

                                    <?php } ?>

                                    <div class="pago-pedido-fila">

                                        <span>Valor del consumo</span>

                                        <strong>
                                            $<?php
                                            echo number_format(
                                                (float) $pedido["total"],
                                                2
                                            );
                                            ?>
                                        </strong>

                                    </div>

                                    <?php if ($fechaPago !== "") { ?>

                                        <div class="pago-pedido-fila">

                                            <span>Fecha del pago</span>

                                            <strong>
                                                <?php
                                                echo h(
                                                    formatearFechaPedido(
                                                        $fechaPago
                                                    )
                                                );
                                                ?>
                                            </strong>

                                        </div>

                                    <?php } ?>

                                </div>

                                <div class="estado-explicacion">

                                    <?php if ($estadoPedido === "Pendiente") { ?>

                                        El pedido fue recibido y está esperando
                                        que el personal empiece a prepararlo.

                                    <?php } elseif ($estadoPedido === "Preparando") { ?>

                                        El personal del hotel está preparando
                                        actualmente tu pedido.

                                    <?php } elseif ($estadoPedido === "Entregado") { ?>

                                        El pedido fue entregado y permanece
                                        guardado como parte de tu historial.

                                    <?php } elseif ($estadoPedido === "Cancelado") { ?>

                                        El pedido fue cancelado y su valor no
                                        se incluye en el total acumulado.

                                    <?php } ?>

                                </div>

                            </div>

                        </div>

                    </div>

                </article>

            <?php } ?>

            <?php if ($totalPaginas > 1) { ?>

                <div class="paginacion-contenedor">

                    <div class="paginacion-info">
                        Mostrando
                        <?php echo $primerRegistro; ?>-<?php echo $ultimoRegistro; ?>
                        de <?php echo $totalFiltrado; ?>
                    </div>

                    <div class="paginacion-hotel">

                        <?php if ($paginaActual > 1) { ?>
                            <a
                                href="?estado=<?php echo urlencode($estadoFiltro); ?>&pagina=<?php echo $paginaActual - 1; ?>"
                            >
                                Anterior
                            </a>
                        <?php } else { ?>
                            <span class="pagina-deshabilitada">
                                Anterior
                            </span>
                        <?php } ?>

                        <?php for (
                            $pagina = $paginaInicio;
                            $pagina <= $paginaFin;
                            $pagina++
                        ) { ?>

                            <?php if ($pagina === $paginaActual) { ?>

                                <span class="pagina-activa">
                                    <?php echo $pagina; ?>
                                </span>

                            <?php } else { ?>

                                <a
                                    href="?estado=<?php echo urlencode($estadoFiltro); ?>&pagina=<?php echo $pagina; ?>"
                                >
                                    <?php echo $pagina; ?>
                                </a>

                            <?php } ?>

                        <?php } ?>

                        <?php if ($paginaActual < $totalPaginas) { ?>
                            <a
                                href="?estado=<?php echo urlencode($estadoFiltro); ?>&pagina=<?php echo $paginaActual + 1; ?>"
                            >
                                Siguiente
                            </a>
                        <?php } else { ?>
                            <span class="pagina-deshabilitada">
                                Siguiente
                            </span>
                        <?php } ?>

                    </div>

                </div>

            <?php } ?>

        <?php } elseif ($errorConsulta === "") { ?>

            <div class="vacio">

                <div class="display-4 mb-3">
                    <i class="bi bi-receipt"></i>
                </div>

                <h2>
                    <?php if ($resumen["total"] > 0) { ?>
                        No hay pedidos en esta categoría
                    <?php } else { ?>
                        Todavía no tienes pedidos
                    <?php } ?>
                </h2>

                <p class="text-muted">
                    <?php if ($resumen["total"] > 0) { ?>
                        Selecciona otro estado para consultar tu historial.
                    <?php } else { ?>
                        Consulta las comidas disponibles y realiza tu primer pedido.
                    <?php } ?>
                </p>

                <a
                    href="pedir_comida.php"
                    class="btn-nuevo-pedido px-4"
                >
                    <i class="bi bi-cup-hot"></i>
                    Ver comidas
                </a>

            </div>

        <?php } ?>

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
                href="index.php"
                class="btn btn-outline-light btn-sm"
            >
                Volver al inicio
            </a>

        </div>

    </div>

    <div class="footer-final">

        <div class="container d-flex justify-content-between flex-wrap gap-2">

            <span>
                Hotel Las 3 Palmeras © 2026
            </span>

            <span>
                Pedidos de <?php echo h($nombreCliente); ?>
            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>
    const claveNotificaciones =
        "hotel_notificaciones_pago_cliente_<?php echo (int) $idCliente; ?>";

    const elementosNotificacion =
        document.querySelectorAll("[data-notificacion-id]");

    const contadorNotificaciones =
        document.getElementById("contadorNotificacionesCliente");

    const botonNotificaciones =
        document.getElementById("botonNotificacionesCliente");

    function obtenerNotificacionesVistas() {
        try {
            const guardadas =
                JSON.parse(
                    localStorage.getItem(claveNotificaciones) || "[]"
                );

            return Array.isArray(guardadas)
                ? guardadas
                : [];
        } catch (error) {
            return [];
        }
    }

    function actualizarContadorNotificaciones() {
        if (!contadorNotificaciones) {
            return;
        }

        const vistas =
            obtenerNotificacionesVistas();

        const noVistas =
            Array.from(elementosNotificacion)
                .map(
                    (elemento) =>
                        elemento.dataset.notificacionId
                )
                .filter(
                    (id) => !vistas.includes(id)
                );

        if (noVistas.length === 0) {
            contadorNotificaciones.style.display = "none";
            return;
        }

        contadorNotificaciones.style.display = "inline-flex";
        contadorNotificaciones.textContent =
            noVistas.length > 99
                ? "99+"
                : noVistas.length;
    }

    if (botonNotificaciones) {
        botonNotificaciones.addEventListener(
            "shown.bs.dropdown",
            () => {
                const vistas =
                    obtenerNotificacionesVistas();

                elementosNotificacion.forEach(
                    (elemento) => {
                        const id =
                            elemento.dataset.notificacionId;

                        if (!vistas.includes(id)) {
                            vistas.push(id);
                        }
                    }
                );

                localStorage.setItem(
                    claveNotificaciones,
                    JSON.stringify(vistas.slice(-100))
                );

                actualizarContadorNotificaciones();
            }
        );
    }

    actualizarContadorNotificaciones();
</script>

</body>

</html>