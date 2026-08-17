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

function formatearFecha($fecha)
{
    try {
        return (new DateTimeImmutable((string) $fecha))
            ->format("d/m/Y");
    } catch (Throwable $excepcion) {
        return (string) $fecha;
    }
}

function formatearFechaHora($fecha)
{
    if (trim((string) $fecha) === "") {
        return "";
    }

    try {
        return (new DateTimeImmutable((string) $fecha))
            ->format("d/m/Y h:i A");
    } catch (Throwable $excepcion) {
        return (string) $fecha;
    }
}

function claseReserva($estado)
{
    switch ($estado) {
        case "Confirmada":
            return "estado-confirmada";

        case "Pendiente":
            return "estado-pendiente";

        case "Finalizada":
            return "estado-finalizada";

        case "Cancelada":
            return "estado-cancelada";

        default:
            return "estado-desconocido";
    }
}

function iconoReserva($estado)
{
    switch ($estado) {
        case "Confirmada":
            return "bi-check-circle";

        case "Pendiente":
            return "bi-clock";

        case "Finalizada":
            return "bi-flag";

        case "Cancelada":
            return "bi-x-circle";

        default:
            return "bi-question-circle";
    }
}

$idUsuario = (int) ($_SESSION["id_usuario"] ?? 0);

if ($idUsuario <= 0) {
    $buscarUsuario = mysqli_prepare(
        $conn,
        "SELECT id_usuario
         FROM usuarios
         WHERE usuario = ?
           AND LOWER(rol) = 'cliente'
         LIMIT 1"
    );

    if ($buscarUsuario) {
        $usuarioSesion =
            trim((string) $_SESSION["usuario"]);

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

$porPagina = 10;

$estadosPermitidos = [
    "Todas",
    "Pendiente",
    "PagoRechazado",
    "Confirmada",
    "Finalizada",
    "Cancelada"
];

$estadoFiltro =
    trim((string) ($_GET["estado"] ?? "Todas"));

if (!in_array($estadoFiltro, $estadosPermitidos, true)) {
    $estadoFiltro = "Todas";
}

$paginaActual = max(
    1,
    (int) ($_GET["pagina"] ?? 1)
);

$resumen = [
    "total" => 0,
    "pendientes" => 0,
    "rechazadas" => 0,
    "confirmadas" => 0,
    "finalizadas" => 0,
    "canceladas" => 0,
    "pagadas" => 0
];

$consultaResumen = mysqli_prepare(
    $conn,
    "SELECT
        COUNT(*) AS total,

        SUM(
            r.estado = 'Pendiente'
            AND COALESCE(
                (
                    SELECT p0.estado_pago
                    FROM pagos p0
                    WHERE p0.id_reserva = r.id_reserva
                    ORDER BY p0.id_pago DESC
                    LIMIT 1
                ),
                ''
            ) <> 'Rechazado'
        ) AS pendientes,

        SUM(
            r.estado = 'Pendiente'
            AND COALESCE(
                (
                    SELECT pr.estado_pago
                    FROM pagos pr
                    WHERE pr.id_reserva = r.id_reserva
                    ORDER BY pr.id_pago DESC
                    LIMIT 1
                ),
                ''
            ) = 'Rechazado'
        ) AS rechazadas,

        SUM(r.estado = 'Confirmada') AS confirmadas,
        SUM(r.estado = 'Finalizada') AS finalizadas,
        SUM(r.estado = 'Cancelada') AS canceladas,

        SUM(
            COALESCE(
                (
                    SELECT p1.estado_pago = 'Aceptado'
                    FROM pagos p1
                    WHERE p1.id_reserva = r.id_reserva
                    ORDER BY p1.id_pago DESC
                    LIMIT 1
                ),
                0
            )
        ) AS pagadas
     FROM reservas r
     WHERE r.id_cliente = ?"
);

if ($consultaResumen) {
    mysqli_stmt_bind_param(
        $consultaResumen,
        "i",
        $idCliente
    );

    mysqli_stmt_execute($consultaResumen);

    $resultadoResumen =
        mysqli_stmt_get_result($consultaResumen);

    $filaResumen =
        mysqli_fetch_assoc($resultadoResumen);

    if ($filaResumen) {
        $resumen["total"] =
            (int) ($filaResumen["total"] ?? 0);

        $resumen["pendientes"] =
            (int) ($filaResumen["pendientes"] ?? 0);

        $resumen["rechazadas"] =
            (int) ($filaResumen["rechazadas"] ?? 0);

        $resumen["confirmadas"] =
            (int) ($filaResumen["confirmadas"] ?? 0);

        $resumen["finalizadas"] =
            (int) ($filaResumen["finalizadas"] ?? 0);

        $resumen["canceladas"] =
            (int) ($filaResumen["canceladas"] ?? 0);

        $resumen["pagadas"] =
            (int) ($filaResumen["pagadas"] ?? 0);
    }

    mysqli_stmt_close($consultaResumen);
}

if ($estadoFiltro === "Todas") {
    $totalReservas = $resumen["total"];
} else {
    $claveResumen = [
        "Pendiente" => "pendientes",
        "PagoRechazado" => "rechazadas",
        "Confirmada" => "confirmadas",
        "Finalizada" => "finalizadas",
        "Cancelada" => "canceladas"
    ];

    $totalReservas =
        $resumen[
            $claveResumen[$estadoFiltro]
        ] ?? 0;
}

$totalPaginas = max(
    1,
    (int) ceil(
        $totalReservas / $porPagina
    )
);

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset =
    ($paginaActual - 1) * $porPagina;

$sqlReservas =
    "SELECT
        r.id_reserva,
        r.fecha_entrada,
        r.fecha_salida,
        r.numero_personas,
        r.plan_alimentacion,
        r.precio_desayuno,
        r.total_alimentacion,
        r.estado AS estado_reserva,
        r.total,

        h.numero,
        h.tipo,
        h.capacidad,
        h.imagen,

        p.id_pago,
        p.metodo_pago,
        p.monto,
        p.estado_pago,
        p.fecha_pago,
        p.observacion,

        (
            SELECT COUNT(*)
            FROM pagos px
            WHERE px.id_reserva = r.id_reserva
        ) AS total_intentos_pago

     FROM reservas r

     INNER JOIN habitaciones h
        ON h.id_habitacion = r.id_habitacion

     LEFT JOIN pagos p
        ON p.id_pago = (
            SELECT p2.id_pago
            FROM pagos p2
            WHERE p2.id_reserva = r.id_reserva
            ORDER BY p2.id_pago DESC
            LIMIT 1
        )

     WHERE r.id_cliente = ?";

if ($estadoFiltro === "Pendiente") {
    $sqlReservas .=
        " AND r.estado = 'Pendiente'
          AND COALESCE(
                (
                    SELECT pf.estado_pago
                    FROM pagos pf
                    WHERE pf.id_reserva = r.id_reserva
                    ORDER BY pf.id_pago DESC
                    LIMIT 1
                ),
                ''
              ) <> 'Rechazado'";
} elseif ($estadoFiltro === "PagoRechazado") {
    $sqlReservas .=
        " AND r.estado = 'Pendiente'
          AND COALESCE(
                (
                    SELECT pf.estado_pago
                    FROM pagos pf
                    WHERE pf.id_reserva = r.id_reserva
                    ORDER BY pf.id_pago DESC
                    LIMIT 1
                ),
                ''
              ) = 'Rechazado'";
} elseif ($estadoFiltro !== "Todas") {
    $sqlReservas .= " AND r.estado = ?";
}

$sqlReservas .=
    " ORDER BY
        r.fecha_entrada DESC,
        r.id_reserva DESC
      LIMIT ?
      OFFSET ?";

$consulta = mysqli_prepare(
    $conn,
    $sqlReservas
);

$reservas = [];
$errorConsulta = "";

if (!$consulta) {
    $errorConsulta =
        "No se pudieron preparar tus reservas.";
} else {

    if (
        $estadoFiltro === "Todas" ||
        $estadoFiltro === "Pendiente" ||
        $estadoFiltro === "PagoRechazado"
    ) {
        mysqli_stmt_bind_param(
            $consulta,
            "iii",
            $idCliente,
            $porPagina,
            $offset
        );
    } else {
        mysqli_stmt_bind_param(
            $consulta,
            "isii",
            $idCliente,
            $estadoFiltro,
            $porPagina,
            $offset
        );
    }

    if (!mysqli_stmt_execute($consulta)) {
        $errorConsulta =
            "No se pudieron consultar tus reservas.";
    } else {
        $resultadoReservas =
            mysqli_stmt_get_result($consulta);

        while (
            $filaReserva =
                mysqli_fetch_assoc($resultadoReservas)
        ) {
            $reservas[] = $filaReserva;
        }
    }

    mysqli_stmt_close($consulta);
}

$primerRegistro =
    $totalReservas > 0
        ? $offset + 1
        : 0;

$ultimoRegistro =
    min(
        $offset + $porPagina,
        $totalReservas
    );

$paginaInicio = max(
    1,
    $paginaActual - 2
);

$paginaFin = min(
    $totalPaginas,
    $paginaActual + 2
);

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
        Mis reservas - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=58"
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

        .notificaciones-dropdown {
            position: relative;
        }

        .btn-notificaciones {
            width: 42px;
            height: 42px;
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, .38);
            border-radius: 50%;
            background: rgba(255, 255, 255, .08);
            color: white;
            font-size: 18px;
        }

        .btn-notificaciones:hover,
        .btn-notificaciones:focus {
            border-color: #ead8aa;
            background: rgba(255, 255, 255, .16);
            color: #f0d99f;
        }

        .notificaciones-contador {
            min-width: 19px;
            height: 19px;
            position: absolute;
            top: -5px;
            right: -5px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
            border: 2px solid var(--verde-oscuro);
            border-radius: 999px;
            background: #d9534f;
            color: white;
            font-size: 10px;
            font-weight: 900;
        }

        .menu-notificaciones {
            width: min(390px, calc(100vw - 28px));
            max-height: 470px;
            overflow-y: auto;
            padding: 0;
            border: 0;
            border-radius: 9px;
            box-shadow: 0 22px 55px rgba(12, 34, 22, .24);
        }

        .notificaciones-cabecera {
            padding: 17px 18px;
            border-bottom: 1px solid #e9ebe6;
            background: var(--verde-oscuro);
            color: white;
        }

        .notificaciones-cabecera strong {
            display: block;
            font-family: Georgia, serif;
            font-size: 18px;
        }

        .notificaciones-cabecera small {
            color: rgba(255, 255, 255, .68);
        }

        .notificacion-pago {
            display: flex;
            gap: 12px;
            padding: 15px 17px;
            border-bottom: 1px solid #eceee9;
            background: white;
        }

        .notificacion-icono {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 17px;
        }

        .notificacion-aceptada .notificacion-icono {
            background: #e4f4e8;
            color: #26713f;
        }

        .notificacion-rechazada .notificacion-icono {
            background: #fff0f0;
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
            border-top: 1px solid #e9ebe6;
            background: #f8faf7;
            text-align: center;
        }

        .notificaciones-pie a {
            color: var(--verde);
            font-size: 12px;
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

        .filtros-reservas {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-bottom: 30px;
        }

        .filtro-reserva {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 41px;
            padding: 9px 14px;
            border: 1px solid #d9ded9;
            border-radius: 999px;
            background: white;
            color: var(--verde);
            font-size: 12px;
            font-weight: 900;
            transition: .2s ease;
        }

        .filtro-reserva:hover {
            border-color: var(--verde);
            background: var(--verde-claro);
            color: var(--verde-oscuro);
        }

        .filtro-reserva.activo {
            border-color: var(--verde);
            background: var(--verde);
            color: white;
        }

        .filtro-reserva .cantidad {
            min-width: 22px;
            height: 22px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
            border-radius: 999px;
            background: rgba(36, 74, 53, .10);
            font-size: 10px;
        }

        .filtro-reserva.activo .cantidad {
            background: rgba(255, 255, 255, .18);
        }

        .reserva-card {
            height: 100%;
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            box-shadow: var(--sombra);
        }

        .imagen-contenedor {
            position: relative;
            overflow: hidden;
        }

        .imagen-reserva {
            width: 100%;
            height: 245px;
            object-fit: cover;
        }

        .estado-reserva {
            position: absolute;
            top: 16px;
            right: 16px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
        }

        .estado-confirmada {
            background: #dff2e4;
            color: #21643b;
        }

        .estado-pendiente {
            background: #fff0c7;
            color: #81600d;
        }

        .estado-finalizada {
            background: #e5ecff;
            color: #315c9a;
        }

        .estado-cancelada {
            background: #fff0f0;
            color: #9d3030;
        }

        .estado-desconocido {
            background: #ececec;
            color: #555;
        }

        .reserva-cuerpo {
            padding: 24px;
        }

        .reserva-etiqueta {
            color: #9b7739;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 1.6px;
        }

        .reserva-titulo {
            margin: 5px 0 2px;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
        }

        .dato {
            height: 100%;
            padding: 12px;
            border: 1px solid #e1e5df;
            border-radius: 7px;
            background: #f7f9f7;
        }

        .dato small {
            color: var(--texto-suave);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .dato strong {
            display: block;
            margin-top: 3px;
            color: #313832;
            font-size: 13px;
        }

        .alimentacion-box {
            margin-top: 14px;
            padding: 15px;
            border: 1px solid #e1e5df;
            border-radius: 7px;
            background: #f7f9f7;
        }

        .alimentacion-titulo {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 11px;
            color: var(--verde-oscuro);
            font-size: 12px;
            font-weight: 900;
        }

        .alimentacion-fila {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 7px 0;
            border-bottom: 1px solid #e1e5df;
            color: var(--texto-suave);
            font-size: 12px;
        }

        .alimentacion-fila:last-child {
            border-bottom: 0;
        }

        .alimentacion-fila strong {
            color: #313832;
            text-align: right;
        }

        .precio {
            margin-top: 19px;
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 30px;
            font-weight: 700;
        }

        .pago-box {
            margin-top: 22px;
            padding: 17px;
            border: 1px solid #e1e4de;
            border-radius: 7px;
            background: #fafbf9;
        }

        .pago-titulo {
            margin-bottom: 11px;
            color: var(--verde-oscuro);
            font-size: 13px;
            font-weight: 900;
        }

        .pago-estado {
            display: flex;
            gap: 9px;
            padding: 12px 13px;
            border-radius: 6px;
            font-size: 12px;
            line-height: 1.55;
        }

        .pago-sin-registrar {
            border: 1px solid #d9dce0;
            background: #f1f3f5;
            color: #565e65;
        }

        .pago-pendiente {
            border: 1px solid #ead79f;
            background: #fff8df;
            color: #765a18;
        }

        .pago-aceptado {
            border: 1px solid #b8ddc2;
            background: #edf8f0;
            color: #24643a;
        }

        .pago-rechazado {
            border: 1px solid #edc8c8;
            background: #fff1f1;
            color: #9b3131;
        }

        .pago-detalle {
            margin-top: 11px;
            color: var(--texto-suave);
            font-size: 11px;
            line-height: 1.6;
        }

        .btn-pagar {
            min-height: 45px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            margin-top: 13px;
            border: 1px solid var(--verde);
            border-radius: 5px;
            background: var(--verde);
            color: white;
            font-size: 12px;
            font-weight: 900;
        }

        .btn-pagar:hover {
            background: var(--verde-oscuro);
            color: white;
        }

        .vacio {
            padding: 60px 25px;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            text-align: center;
            box-shadow: var(--sombra);
        }

        .paginacion-contenedor {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-top: 30px;
            padding: 18px 20px;
            border: 1px solid #e2e4de;
            border-radius: 8px;
            background: white;
            box-shadow: var(--sombra);
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
            background: white;
            color: var(--verde);
            font-size: 12px;
            font-weight: 800;
        }

        .paginacion-hotel a:hover {
            border-color: var(--verde);
            background: var(--verde-claro);
            color: var(--verde-oscuro);
        }

        .paginacion-hotel .pagina-activa {
            border-color: var(--verde);
            background: var(--verde);
            color: white;
        }

        .paginacion-hotel .pagina-deshabilitada {
            opacity: .45;
            cursor: default;
        }

        .paginacion-hotel .pagina-puntos {
            min-width: 26px;
            padding: 0 4px;
            border-color: transparent;
            background: transparent;
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

            .notificaciones-dropdown {
                margin-top: 10px;
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

            .pagina-hero h1 {
                font-size: 2.55rem;
            }

            .reserva-cuerpo {
                padding: 20px;
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
                                class="dropdown-item active"
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
                        <i class="bi bi-bell"></i>

                        <?php if ($totalNotificacionesPago > 0) { ?>

                            <span
                                class="notificaciones-contador"
                                id="contadorNotificaciones"
                            >
                                <?php echo $totalNotificacionesPago; ?>
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
                                Avisos importantes de tus pagos
                            </small>
                        </div>

                        <?php if ($totalNotificacionesPago > 0) { ?>

                            <?php foreach (
                                $notificacionesPago as $notificacion
                            ) { ?>

                                <?php
                                $esAceptada =
                                    $notificacion["estado_pago"] === "Aceptado";

                                $idNotificacion =
                                    "pago-" .
                                    (int) $notificacion["id_pago"];
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
                                            #<?php echo (int) $notificacion["id_reserva"]; ?>
                                            · Habitación
                                            <?php echo h($notificacion["numero_habitacion"]); ?>
                                        </strong>

                                        <p>
                                            <?php if ($esAceptada) { ?>

                                                Tu pago de
                                                $<?php
                                                echo number_format(
                                                    (float) $notificacion["monto"],
                                                    2
                                                );
                                                ?>
                                                fue aceptado y tu reserva
                                                fue confirmada.

                                            <?php } else { ?>

                                                Tu pago fue rechazado.
                                                Revisa el motivo antes de
                                                registrarlo nuevamente.

                                            <?php } ?>
                                        </p>

                                        <?php if (
                                            !$esAceptada &&
                                            trim(
                                                (string) $notificacion["observacion"]
                                            ) !== ""
                                        ) { ?>

                                            <p class="notificacion-motivo">
                                                Motivo:
                                                <?php echo h($notificacion["observacion"]); ?>
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
                TU ESTADÍA
            </div>

            <h1>Mis reservas</h1>

            <p>
                Revisa tus habitaciones, fechas, valores y el estado
                del pago más reciente asociado con cada reserva.
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

        <section class="row g-4 mb-5">

            <div class="col-6 col-lg-3">
                <div class="resumen-card">
                    <small>Total de reservas</small>
                    <strong><?php echo $resumen["total"]; ?></strong>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="resumen-card">
                    <small>Pendientes</small>
                    <strong><?php echo $resumen["pendientes"]; ?></strong>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="resumen-card">
                    <small>Confirmadas</small>
                    <strong><?php echo $resumen["confirmadas"]; ?></strong>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="resumen-card">
                    <small>Pagadas</small>
                    <strong><?php echo $resumen["pagadas"]; ?></strong>
                </div>
            </div>

        </section>

        <div class="filtros-reservas" aria-label="Filtrar reservas por estado">

            <a
                href="?estado=Todas"
                class="filtro-reserva <?php echo $estadoFiltro === "Todas" ? "activo" : ""; ?>"
            >
                <i class="bi bi-grid"></i>
                Todas
                <span class="cantidad"><?php echo $resumen["total"]; ?></span>
            </a>

            <a
                href="?estado=Pendiente"
                class="filtro-reserva <?php echo $estadoFiltro === "Pendiente" ? "activo" : ""; ?>"
            >
                <i class="bi bi-clock"></i>
                Pendientes
                <span class="cantidad"><?php echo $resumen["pendientes"]; ?></span>
            </a>

            <a
                href="?estado=PagoRechazado"
                class="filtro-reserva <?php echo $estadoFiltro === "PagoRechazado" ? "activo" : ""; ?>"
            >
                <i class="bi bi-x-circle"></i>
                Pago rechazado
                <span class="cantidad"><?php echo $resumen["rechazadas"]; ?></span>
            </a>

            <a
                href="?estado=Confirmada"
                class="filtro-reserva <?php echo $estadoFiltro === "Confirmada" ? "activo" : ""; ?>"
            >
                <i class="bi bi-check-circle"></i>
                Confirmadas
                <span class="cantidad"><?php echo $resumen["confirmadas"]; ?></span>
            </a>

            <a
                href="?estado=Finalizada"
                class="filtro-reserva <?php echo $estadoFiltro === "Finalizada" ? "activo" : ""; ?>"
            >
                <i class="bi bi-flag"></i>
                Finalizadas
                <span class="cantidad"><?php echo $resumen["finalizadas"]; ?></span>
            </a>

            <a
                href="?estado=Cancelada"
                class="filtro-reserva <?php echo $estadoFiltro === "Cancelada" ? "activo" : ""; ?>"
            >
                <i class="bi bi-x-circle"></i>
                Canceladas
                <span class="cantidad"><?php echo $resumen["canceladas"]; ?></span>
            </a>

        </div>

        <?php if (!empty($reservas)) { ?>

            <div class="row g-4">

                <?php foreach ($reservas as $reserva) { ?>

                    <?php
                    $rutaImagen =
                        resolverImagen(
                            $reserva["imagen"] ?? "",
                            "habitaciones",
                            "../img/hotel.jpg"
                        );

                    $estadoReserva =
                        trim((string) $reserva["estado_reserva"]);

                    $idPago =
                        (int) ($reserva["id_pago"] ?? 0);

                    $estadoPago =
                        trim((string) ($reserva["estado_pago"] ?? ""));

                    $puedePagar =
                        $estadoReserva === "Pendiente" &&
                        (
                            $idPago === 0 ||
                            $estadoPago === "Rechazado"
                        );

                    $numeroPersonasReserva =
                        max(
                            1,
                            (int) (
                                $reserva["numero_personas"] ?? 1
                            )
                        );

                    $planAlimentacion =
                        trim(
                            (string) (
                                $reserva["plan_alimentacion"] ??
                                "Solo alojamiento"
                            )
                        );

                    $precioDesayuno =
                        (float) (
                            $reserva["precio_desayuno"] ?? 0
                        );

                    $totalAlimentacion =
                        (float) (
                            $reserva["total_alimentacion"] ?? 0
                        );

                    $totalReserva =
                        (float) $reserva["total"];

                    $subtotalHabitacion =
                        max(
                            0,
                            round(
                                $totalReserva -
                                $totalAlimentacion,
                                2
                            )
                        );
                    ?>

                    <div class="col-md-6 col-xl-4">

                        <article class="reserva-card">

                            <div class="imagen-contenedor">

                                <img
                                    src="<?php echo h($rutaImagen); ?>"
                                    alt="Habitación <?php echo h($reserva["numero"]); ?>"
                                    class="imagen-reserva"
                                    loading="lazy"
                                    onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                                >

                                <span
                                    class="estado-reserva <?php echo h(claseReserva($estadoReserva)); ?>"
                                >
                                    <i
                                        class="bi <?php echo h(iconoReserva($estadoReserva)); ?>"
                                    ></i>

                                    <?php echo h($estadoReserva); ?>
                                </span>

                            </div>

                            <div class="reserva-cuerpo">

                                <div class="reserva-etiqueta">
                                    RESERVA #<?php echo (int) $reserva["id_reserva"]; ?>
                                </div>

                                <h2 class="reserva-titulo h4">
                                    Habitación <?php echo h($reserva["numero"]); ?>
                                </h2>

                                <div class="text-muted small mb-3">
                                    <?php echo h($reserva["tipo"]); ?>
                                    ·
                                    <?php echo (int) $reserva["capacidad"]; ?>
                                    persona(s)
                                </div>

                                <div class="row g-2">

                                    <div class="col-6">
                                        <div class="dato">
                                            <small>Entrada</small>

                                            <strong>
                                                <?php
                                                echo h(
                                                    formatearFecha(
                                                        $reserva["fecha_entrada"]
                                                    )
                                                );
                                                ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <div class="col-6">
                                        <div class="dato">
                                            <small>Salida</small>

                                            <strong>
                                                <?php
                                                echo h(
                                                    formatearFecha(
                                                        $reserva["fecha_salida"]
                                                    )
                                                );
                                                ?>
                                            </strong>
                                        </div>
                                    </div>

                                </div>

                                <div class="alimentacion-box">

                                    <div class="alimentacion-titulo">
                                        <i class="bi bi-cup-hot"></i>
                                        Plan de la reserva
                                    </div>

                                    <div class="alimentacion-fila">
                                        <span>Personas</span>

                                        <strong>
                                            <?php
                                            echo $numeroPersonasReserva;
                                            ?>
                                        </strong>
                                    </div>

                                    <div class="alimentacion-fila">
                                        <span>Plan</span>

                                        <strong>
                                            <?php
                                            echo h(
                                                $planAlimentacion
                                            );
                                            ?>
                                        </strong>
                                    </div>

                                    <div class="alimentacion-fila">
                                        <span>Habitación</span>

                                        <strong>
                                            $<?php
                                            echo number_format(
                                                $subtotalHabitacion,
                                                2
                                            );
                                            ?>
                                        </strong>
                                    </div>

                                    <div class="alimentacion-fila">
                                        <span>
                                            Desayuno
                                            <?php if (
                                                $totalAlimentacion > 0
                                            ) { ?>
                                                <small class="d-block">
                                                    $<?php
                                                    echo number_format(
                                                        $precioDesayuno,
                                                        2
                                                    );
                                                    ?>
                                                    por persona y noche
                                                </small>
                                            <?php } ?>
                                        </span>

                                        <strong>
                                            <?php if (
                                                $totalAlimentacion > 0
                                            ) { ?>
                                                $<?php
                                                echo number_format(
                                                    $totalAlimentacion,
                                                    2
                                                );
                                                ?>
                                            <?php } else { ?>
                                                No incluido
                                            <?php } ?>
                                        </strong>
                                    </div>

                                </div>

                                <div class="precio">
                                    $<?php
                                    echo number_format(
                                        $totalReserva,
                                        2
                                    );
                                    ?>
                                </div>

                                <div class="text-muted small">
                                    Total de la reserva
                                </div>

                                <div class="pago-box">

                                    <div class="pago-titulo">
                                        Estado del pago
                                    </div>

                                    <?php if ($idPago === 0) { ?>

                                        <div class="pago-estado pago-sin-registrar">
                                            <i class="bi bi-credit-card"></i>

                                            <div>
                                                <strong>Pago no registrado</strong>

                                                <div>
                                                    Esta reserva todavía no tiene
                                                    un pago asociado.
                                                </div>
                                            </div>
                                        </div>

                                    <?php } elseif ($estadoPago === "Pendiente") { ?>

                                        <div class="pago-estado pago-pendiente">
                                            <i class="bi bi-hourglass-split"></i>

                                            <div>
                                                <strong>
                                                    Pendiente de revisión
                                                </strong>

                                                <div>
                                                    El administrador está revisando
                                                    tu pago.
                                                </div>
                                            </div>
                                        </div>

                                    <?php } elseif ($estadoPago === "Aceptado") { ?>

                                        <div class="pago-estado pago-aceptado">
                                            <i class="bi bi-check-circle"></i>

                                            <div>
                                                <strong>Pago aceptado</strong>

                                                <div>
                                                    La reserva se encuentra pagada.
                                                </div>
                                            </div>
                                        </div>

                                    <?php } elseif ($estadoPago === "Rechazado") { ?>

                                        <div class="pago-estado pago-rechazado">
                                            <i class="bi bi-x-circle"></i>

                                            <div>
                                                <strong>Pago rechazado</strong>

                                                <?php if (
                                                    trim(
                                                        (string) $reserva["observacion"]
                                                    ) !== ""
                                                ) { ?>

                                                    <div>
                                                        Motivo:
                                                        <?php
                                                        echo h(
                                                            $reserva["observacion"]
                                                        );
                                                        ?>
                                                    </div>

                                                <?php } ?>

                                            </div>
                                        </div>

                                    <?php } else { ?>

                                        <div class="pago-estado pago-sin-registrar">
                                            <i class="bi bi-question-circle"></i>

                                            <div>
                                                <strong>
                                                    Estado no disponible
                                                </strong>
                                            </div>
                                        </div>

                                    <?php } ?>

                                    <?php if ($idPago > 0) { ?>

                                        <div class="pago-detalle">

                                            Método:
                                            <strong>
                                                <?php
                                                echo h(
                                                    $reserva["metodo_pago"] ?? "No registrado"
                                                );
                                                ?>
                                            </strong>

                                            <?php if (
                                                trim(
                                                    (string) $reserva["fecha_pago"]
                                                ) !== ""
                                            ) { ?>

                                                <br>

                                                Fecha:
                                                <strong>
                                                    <?php
                                                    echo h(
                                                        formatearFechaHora(
                                                            $reserva["fecha_pago"]
                                                        )
                                                    );
                                                    ?>
                                                </strong>

                                            <?php } ?>

                                            <br>

                                            Intentos registrados:
                                            <strong>
                                                <?php
                                                echo (int)
                                                    $reserva["total_intentos_pago"];
                                                ?>
                                            </strong>

                                        </div>

                                    <?php } ?>

                                    <?php if ($puedePagar) { ?>

                                        <a
                                            href="pagar.php?id=<?php echo (int) $reserva["id_reserva"]; ?>"
                                            class="btn-pagar w-100"
                                        >
                                            <i class="bi bi-credit-card"></i>

                                            <?php
                                            echo $estadoPago === "Rechazado"
                                                ? "Registrar un nuevo pago"
                                                : "Realizar pago";
                                            ?>
                                        </a>

                                    <?php } elseif (
                                        $estadoPago === "Rechazado" &&
                                        $estadoReserva !== "Pendiente"
                                    ) { ?>

                                        <div class="pago-detalle">
                                            La reserva no admite un nuevo pago
                                            en su estado actual.
                                        </div>

                                    <?php } ?>

                                </div>

                            </div>

                        </article>

                    </div>

                <?php } ?>

            </div>

            <?php if ($totalReservas > 0) { ?>

                <div class="paginacion-contenedor">

                    <div class="paginacion-info">
                        Mostrando
                        <?php echo $primerRegistro; ?>
                        -
                        <?php echo $ultimoRegistro; ?>
                        de
                        <?php echo $totalReservas; ?>
                        reservas
                        <?php if ($estadoFiltro === "PagoRechazado") { ?>
                            con pago rechazado
                        <?php } elseif ($estadoFiltro !== "Todas") { ?>
                            <?php echo h(strtolower($estadoFiltro)); ?>s
                        <?php } ?>
                    </div>

                    <?php if ($totalPaginas > 1) { ?>

                        <nav
                            class="paginacion-hotel"
                            aria-label="Paginación de mis reservas"
                        >

                            <?php if ($paginaActual > 1) { ?>

                                <a href="?estado=<?php echo urlencode($estadoFiltro); ?>&pagina=<?php echo $paginaActual - 1; ?>">
                                    <i class="bi bi-chevron-left"></i>
                                    <span class="d-none d-sm-inline ms-1">
                                        Anterior
                                    </span>
                                </a>

                            <?php } else { ?>

                                <span class="pagina-deshabilitada">
                                    <i class="bi bi-chevron-left"></i>
                                    <span class="d-none d-sm-inline ms-1">
                                        Anterior
                                    </span>
                                </span>

                            <?php } ?>

                            <?php if ($paginaInicio > 1) { ?>

                                <a href="?estado=<?php echo urlencode($estadoFiltro); ?>&pagina=1">1</a>

                                <?php if ($paginaInicio > 2) { ?>
                                    <span class="pagina-puntos">...</span>
                                <?php } ?>

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

                                    <a href="?estado=<?php echo urlencode($estadoFiltro); ?>&pagina=<?php echo $pagina; ?>">
                                        <?php echo $pagina; ?>
                                    </a>

                                <?php } ?>

                            <?php } ?>

                            <?php if ($paginaFin < $totalPaginas) { ?>

                                <?php if (
                                    $paginaFin <
                                    $totalPaginas - 1
                                ) { ?>
                                    <span class="pagina-puntos">...</span>
                                <?php } ?>

                                <a href="?estado=<?php echo urlencode($estadoFiltro); ?>&pagina=<?php echo $totalPaginas; ?>">
                                    <?php echo $totalPaginas; ?>
                                </a>

                            <?php } ?>

                            <?php if ($paginaActual < $totalPaginas) { ?>

                                <a href="?estado=<?php echo urlencode($estadoFiltro); ?>&pagina=<?php echo $paginaActual + 1; ?>">
                                    <span class="d-none d-sm-inline me-1">
                                        Siguiente
                                    </span>
                                    <i class="bi bi-chevron-right"></i>
                                </a>

                            <?php } else { ?>

                                <span class="pagina-deshabilitada">
                                    <span class="d-none d-sm-inline me-1">
                                        Siguiente
                                    </span>
                                    <i class="bi bi-chevron-right"></i>
                                </span>

                            <?php } ?>

                        </nav>

                    <?php } ?>

                </div>

            <?php } ?>

        <?php } elseif ($errorConsulta === "") { ?>

            <div class="vacio">

                <div class="display-4 mb-3">
                    <i class="bi bi-calendar2-x"></i>
                </div>

                <h2>
                    <?php if ($estadoFiltro === "Todas") { ?>
                        Todavía no tienes reservas
                    <?php } else { ?>
                        <?php if ($estadoFiltro === "PagoRechazado") { ?>
                            No tienes reservas con pago rechazado
                        <?php } else { ?>
                            No tienes reservas
                            <?php echo h(strtolower($estadoFiltro)); ?>s
                        <?php } ?>
                    <?php } ?>
                </h2>

                <p class="text-muted">
                    <?php if ($estadoFiltro === "Todas") { ?>
                        Revisa las habitaciones disponibles
                        y registra tu primera reserva.
                    <?php } else { ?>
                        Puedes revisar otro estado usando
                        los filtros de la parte superior.
                    <?php } ?>
                </p>

                <?php if ($estadoFiltro === "Todas") { ?>

                    <a
                        href="index.php#habitaciones"
                        class="btn-pagar px-4"
                    >
                        <i class="bi bi-door-open"></i>
                        Ver habitaciones
                    </a>

                <?php } else { ?>

                    <a
                        href="?estado=Todas"
                        class="btn-pagar px-4"
                    >
                        <i class="bi bi-grid"></i>
                        Ver todas
                    </a>

                <?php } ?>

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
                Reservas de <?php echo h($nombreCliente); ?>
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

</body>

</html>