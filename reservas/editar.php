<?php
session_start();
include("../config/conexion.php");

if (!isset($_SESSION["usuario"], $_SESSION["rol"])) {
    header("Location: ../login.php");
    exit();
}

$rolActual = strtolower(trim((string) $_SESSION["rol"]));

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

function formatearFechaNotificacion($fecha)
{
    $tiempo = strtotime((string) $fecha);

    return $tiempo === false
        ? (string) $fecha
        : date("d/m/Y h:i A", $tiempo);
}

if (
    !isset($_GET["id"]) ||
    !filter_var($_GET["id"], FILTER_VALIDATE_INT)
) {
    header("Location: index.php");
    exit();
}

$idReserva = (int) $_GET["id"];

if (empty($_SESSION["csrf_editar_reserva"])) {
    $_SESSION["csrf_editar_reserva"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_editar_reserva"];
$errores = [];

$consultaReserva = mysqli_prepare(
    $conn,
    "SELECT
        r.id_reserva,
        r.id_cliente,
        r.id_habitacion,
        r.fecha_entrada,
        r.fecha_salida,
        r.numero_personas,
        r.plan_alimentacion,
        r.precio_desayuno,
        r.total_alimentacion,
        r.estado,
        r.total,
        h.numero,
        h.tipo,
        h.precio,
        h.capacidad,
        h.estado AS estado_habitacion
     FROM reservas r
     INNER JOIN habitaciones h
        ON h.id_habitacion = r.id_habitacion
     WHERE r.id_reserva = ?
     LIMIT 1"
);

if (!$consultaReserva) {
    die("No se pudo consultar la reserva.");
}

mysqli_stmt_bind_param($consultaReserva, "i", $idReserva);
mysqli_stmt_execute($consultaReserva);

$resultadoReserva = mysqli_stmt_get_result($consultaReserva);
$datos = mysqli_fetch_assoc($resultadoReserva);

mysqli_stmt_close($consultaReserva);

if (!$datos) {
    header("Location: index.php");
    exit();
}

$idCliente = (int) $datos["id_cliente"];
$idHabitacion = (int) $datos["id_habitacion"];
$fechaEntrada = $datos["fecha_entrada"];
$fechaSalida = $datos["fecha_salida"];
$estado = $datos["estado"];

$numeroPersonas =
    max(
        1,
        (int) ($datos["numero_personas"] ?? 1)
    );

$planAlimentacion =
    trim(
        (string) (
            $datos["plan_alimentacion"] ??
            "Solo alojamiento"
        )
    );

$precioDesayuno = 5.00;

$totalAlimentacion =
    (float) ($datos["total_alimentacion"] ?? 0);

$total = (float) $datos["total"];

$subtotalHabitacion =
    max(
        0,
        round(
            $total - $totalAlimentacion,
            2
        )
    );

$estadosPermitidos = [
    "Pendiente",
    "Confirmada",
    "Finalizada",
    "Cancelada"
];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["actualizar"])) {
    $csrfRecibido = $_POST["csrf"] ?? "";
    $idCliente = (int) ($_POST["id_cliente"] ?? 0);
    $idHabitacion = (int) ($_POST["id_habitacion"] ?? 0);
    $fechaEntrada = trim($_POST["fecha_entrada"] ?? "");
    $fechaSalida = trim($_POST["fecha_salida"] ?? "");
    $estado = trim($_POST["estado"] ?? "");

    $numeroPersonas =
        (int) ($_POST["numero_personas"] ?? 1);

    $planAlimentacion =
        trim(
            (string) (
                $_POST["plan_alimentacion"] ??
                "Solo alojamiento"
            )
        );

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $errores[] = "La solicitud no es válida. Actualiza la página.";
    }

    if ($idCliente <= 0) {
        $errores[] = "Seleccione un cliente válido.";
    }

    if ($idHabitacion <= 0) {
        $errores[] = "Seleccione una habitación válida.";
    }

    if (!in_array($estado, $estadosPermitidos, true)) {
        $errores[] = "Seleccione un estado válido.";
    }

    if ($numeroPersonas < 1) {
        $errores[] =
            "La reserva debe tener al menos una persona.";
    }

    $planesPermitidos = [
        "Solo alojamiento",
        "Alojamiento con desayuno"
    ];

    if (
        !in_array(
            $planAlimentacion,
            $planesPermitidos,
            true
        )
    ) {
        $errores[] =
            "Seleccione un plan de alimentación válido.";
    }

    $entrada = DateTimeImmutable::createFromFormat("Y-m-d", $fechaEntrada);
    $salida = DateTimeImmutable::createFromFormat("Y-m-d", $fechaSalida);

    $entradaValida =
        $entrada &&
        $entrada->format("Y-m-d") === $fechaEntrada;

    $salidaValida =
        $salida &&
        $salida->format("Y-m-d") === $fechaSalida;

    if (!$entradaValida || !$salidaValida) {
        $errores[] = "Ingrese fechas válidas.";
    } elseif ($salida <= $entrada) {
        $errores[] =
            "La fecha de salida debe ser mayor que la fecha de entrada.";
    }

    if (empty($errores)) {
        $consultaCliente = mysqli_prepare(
            $conn,
            "SELECT id_cliente
             FROM clientes
             WHERE id_cliente = ?
             LIMIT 1"
        );

        if (!$consultaCliente) {
            $errores[] = "No se pudo comprobar el cliente.";
        } else {
            mysqli_stmt_bind_param($consultaCliente, "i", $idCliente);
            mysqli_stmt_execute($consultaCliente);

            $resultadoCliente =
                mysqli_stmt_get_result($consultaCliente);

            if (mysqli_num_rows($resultadoCliente) === 0) {
                $errores[] = "El cliente seleccionado no existe.";
            }

            mysqli_stmt_close($consultaCliente);
        }
    }

    $precioHabitacion = 0.0;
    $capacidadHabitacion = 0;

    if (empty($errores)) {
        $consultaHabitacion = mysqli_prepare(
            $conn,
            "SELECT precio, estado, capacidad
             FROM habitaciones
             WHERE id_habitacion = ?
             LIMIT 1"
        );

        if (!$consultaHabitacion) {
            $errores[] = "No se pudo comprobar la habitación.";
        } else {
            mysqli_stmt_bind_param(
                $consultaHabitacion,
                "i",
                $idHabitacion
            );

            mysqli_stmt_execute($consultaHabitacion);

            $resultadoHabitacion =
                mysqli_stmt_get_result($consultaHabitacion);

            $habitacionSeleccionada =
                mysqli_fetch_assoc($resultadoHabitacion);

            mysqli_stmt_close($consultaHabitacion);

            if (!$habitacionSeleccionada) {
                $errores[] = "La habitación seleccionada no existe.";
            } else {
                $precioHabitacion =
                    (float) $habitacionSeleccionada["precio"];

                $estadoHabitacion =
                    trim((string) $habitacionSeleccionada["estado"]);

                $capacidadHabitacion =
                    (int) $habitacionSeleccionada["capacidad"];

                if (
                    $estadoHabitacion === "Mantenimiento" &&
                    $idHabitacion !== (int) $datos["id_habitacion"]
                ) {
                    $errores[] =
                        "La habitación seleccionada está en mantenimiento.";
                }

                if (
                    $numeroPersonas >
                    $capacidadHabitacion
                ) {
                    $errores[] =
                        "La habitación admite máximo " .
                        $capacidadHabitacion .
                        " persona(s).";
                }
            }
        }
    }

    if (
        empty($errores) &&
        in_array($estado, ["Pendiente", "Confirmada"], true)
    ) {
        $consultaCruce = mysqli_prepare(
            $conn,
            "SELECT id_reserva
             FROM reservas
             WHERE id_habitacion = ?
               AND id_reserva != ?
               AND estado IN ('Pendiente', 'Confirmada')
               AND fecha_entrada < ?
               AND fecha_salida > ?
             LIMIT 1"
        );

        if (!$consultaCruce) {
            $errores[] =
                "No se pudo comprobar la disponibilidad.";
        } else {
            mysqli_stmt_bind_param(
                $consultaCruce,
                "iiss",
                $idHabitacion,
                $idReserva,
                $fechaSalida,
                $fechaEntrada
            );

            mysqli_stmt_execute($consultaCruce);

            $resultadoCruce =
                mysqli_stmt_get_result($consultaCruce);

            if (mysqli_num_rows($resultadoCruce) > 0) {
                $errores[] =
                    "La habitación ya tiene otra reserva activa en esas fechas.";
            }

            mysqli_stmt_close($consultaCruce);
        }
    }

    if (empty($errores)) {
        $dias =
            (int) $entrada
                ->diff($salida)
                ->days;

        $subtotalHabitacion =
            round(
                $dias * $precioHabitacion,
                2
            );

        $totalAlimentacion =
            $planAlimentacion ===
            "Alojamiento con desayuno"
                ? round(
                    $dias *
                    $numeroPersonas *
                    $precioDesayuno,
                    2
                )
                : 0.00;

        $total =
            round(
                $subtotalHabitacion +
                $totalAlimentacion,
                2
            );

        $actualizarReserva = mysqli_prepare(
            $conn,
            "UPDATE reservas
             SET
                id_cliente = ?,
                id_habitacion = ?,
                fecha_entrada = ?,
                fecha_salida = ?,
                numero_personas = ?,
                plan_alimentacion = ?,
                precio_desayuno = ?,
                total_alimentacion = ?,
                estado = ?,
                total = ?
             WHERE id_reserva = ?"
        );

        if (!$actualizarReserva) {
            $errores[] =
                "No se pudo preparar la actualización.";
        } else {
            mysqli_stmt_bind_param(
                $actualizarReserva,
                "iissisddsdi",
                $idCliente,
                $idHabitacion,
                $fechaEntrada,
                $fechaSalida,
                $numeroPersonas,
                $planAlimentacion,
                $precioDesayuno,
                $totalAlimentacion,
                $estado,
                $total,
                $idReserva
            );

            if (mysqli_stmt_execute($actualizarReserva)) {
                mysqli_stmt_close($actualizarReserva);

                header("Location: index.php?mensaje=actualizado");
                exit();
            }

            mysqli_stmt_close($actualizarReserva);

            $errores[] =
                "No se pudo actualizar la reserva.";
        }
    }
}

$clientes = mysqli_query(
    $conn,
    "SELECT
        id_cliente,
        nombres,
        apellidos,
        cedula
     FROM clientes
     ORDER BY nombres, apellidos"
);

$habitaciones = mysqli_prepare(
    $conn,
    "SELECT
        id_habitacion,
        numero,
        tipo,
        precio,
        capacidad,
        estado
     FROM habitaciones
     WHERE estado != 'Mantenimiento'
        OR id_habitacion = ?
     ORDER BY numero"
);

if (!$habitaciones) {
    die("No se pudieron consultar las habitaciones.");
}

$idHabitacionOriginal = (int) $datos["id_habitacion"];

mysqli_stmt_bind_param(
    $habitaciones,
    "i",
    $idHabitacionOriginal
);

mysqli_stmt_execute($habitaciones);

$resultadoHabitaciones =
    mysqli_stmt_get_result($habitaciones);

/* Notificaciones */
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
        Editar reserva - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=63"
    >

    <style>
        :root {
            --verde: #244a35;
            --verde-oscuro: #173325;
            --verde-claro: #e9f0eb;
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

        .rol-navbar {
            display: inline-flex;
            margin-top: 4px;
            color: rgba(255, 255, 255, .67);
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
            padding: 0;
            border: 1px solid rgba(255, 255, 255, .30);
            border-radius: 50%;
            background: rgba(255, 255, 255, .08);
            color: white;
            font-size: 17px;
        }

        .btn-notificaciones-admin:hover,
        .btn-notificaciones-admin:focus {
            border-color: #ead8aa;
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
            width: min(390px, calc(100vw - 24px));
            max-height: 470px;
            overflow-y: auto;
            padding: 0;
            border: 0;
            border-radius: 10px;
            box-shadow: 0 18px 46px rgba(14, 35, 23, .20);
        }

        .notificaciones-admin-cabecera {
            padding: 16px 18px;
            border-bottom: 1px solid #e8ebe7;
            background: var(--verde-oscuro);
            color: white;
        }

        .notificaciones-admin-cabecera strong {
            display: block;
            font-family: Georgia, serif;
            font-size: 17px;
        }

        .notificaciones-admin-cabecera small {
            color: rgba(255, 255, 255, .68);
            font-size: 10px;
        }

        .notificacion-pago-admin {
            display: flex;
            gap: 11px;
            padding: 14px 17px;
            border-bottom: 1px solid #edf0ec;
            background: white;
            color: #20231f;
        }

        .notificacion-pago-admin:hover {
            background: #f4f8f5;
            color: #20231f;
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
            font-size: 16px;
        }

        .notificacion-pago-contenido {
            min-width: 0;
            flex: 1;
        }

        .notificacion-pago-contenido strong {
            display: block;
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
            padding: 26px 18px;
            color: var(--texto-suave);
            text-align: center;
            font-size: 12px;
        }

        .notificaciones-admin-pie {
            padding: 12px 16px;
            border-top: 1px solid #e8ebe7;
            background: #fbfcfa;
            text-align: center;
        }

        .notificaciones-admin-pie a {
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
            max-width: 670px;
            color: rgba(255, 255, 255, .82);
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
            border: 1px solid #edc8c8;
            border-radius: 6px;
            background-color: #fff1f1;
            color: #9b3131;
            font-size: 13px;
        }

        .formulario-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 8px;
            background-color: white;
            box-shadow: var(--sombra);
        }

        .formulario-cabecera {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 24px 27px;
            border-bottom: 1px solid #e6e7e1;
            background-color: #fbfcfa;
        }

        .formulario-icono {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            flex: 0 0 48px;
            border-radius: 50%;
            background-color: var(--verde-claro);
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
            border: 1px solid #dce1dc;
            background-color: #f7f9f7;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--verde);
            background-color: white;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, .10);
        }

        .form-text {
            color: var(--texto-suave);
            font-size: 11px;
            line-height: 1.6;
        }

        .estado-opciones {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }


        .plan-opciones {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
            width: 100%;
        }

        .plan-opcion {
            position: relative;
            margin: 0;
            cursor: pointer;
        }

        .plan-opcion input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .plan-opcion-contenido {
            min-height: 78px;
            height: 100%;
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 13px;
            border: 1px solid #dce1dc;
            border-radius: 9px;
            background-color: #fbfcfa;
            transition:
                transform .18s ease,
                border-color .18s ease,
                background-color .18s ease,
                box-shadow .18s ease;
        }

        .plan-opcion:hover .plan-opcion-contenido {
            border-color: #b9c4bb;
            background-color: white;
            transform: translateY(-1px);
            box-shadow: 0 7px 18px rgba(35, 55, 42, .07);
        }

        .plan-opcion-icono {
            width: 35px;
            height: 35px;
            flex: 0 0 35px;
            display: grid;
            place-items: center;
            border-radius: 8px;
            background-color: var(--verde-claro);
            color: var(--verde);
            font-size: 16px;
        }

        .plan-opcion-texto {
            min-width: 0;
            flex: 1;
            padding-right: 8px;
        }

        .plan-opcion-texto strong {
            display: block;
            margin-bottom: 3px;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 14px;
        }

        .plan-opcion-texto small {
            display: block;
            color: var(--texto-suave);
            font-size: 9px;
            line-height: 1.4;
        }

        .plan-opcion-precio {
            padding: 4px 7px;
            border-radius: 999px;
            background-color: #f0f3ef;
            color: #5a665d;
            font-size: 8px;
            font-weight: 900;
            white-space: nowrap;
        }

        .plan-opcion-check {
            width: 19px;
            height: 19px;
            position: absolute;
            top: 6px;
            right: 6px;
            display: none;
            place-items: center;
            border-radius: 50%;
            background-color: var(--verde);
            color: white;
            font-size: 9px;
        }

        .plan-opcion input:checked + .plan-opcion-contenido {
            border: 2px solid var(--verde);
            background-color: #f4faf6;
            box-shadow: 0 8px 20px rgba(36, 74, 53, .10);
        }

        .plan-opcion input:checked +
        .plan-opcion-contenido .plan-opcion-icono {
            background-color: var(--verde);
            color: white;
        }

        .plan-opcion input:checked +
        .plan-opcion-contenido .plan-opcion-check {
            display: grid;
        }

        .plan-opcion input:checked +
        .plan-opcion-contenido .plan-opcion-precio {
            background-color: var(--verde-claro);
            color: var(--verde);
        }

        .estado-opcion {
            position: relative;
            margin: 0;
            cursor: pointer;
        }

        .estado-opcion input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .estado-opcion-contenido {
            min-height: 108px;
            height: 100%;
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 17px 16px;
            border: 1px solid #dce1dc;
            border-radius: 12px;
            background: #fbfcfa;
            transition: .18s ease;
        }

        .estado-opcion:hover .estado-opcion-contenido {
            border-color: #b7c3ba;
            background: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 9px 22px rgba(35, 55, 42, .08);
        }

        .estado-opcion-icono {
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: #edf1ed;
            color: #556057;
            font-size: 18px;
        }

        .estado-opcion-texto {
            min-width: 0;
            flex: 1;
        }

        .estado-opcion-texto strong {
            display: block;
            margin: 1px 0 5px;
            color: var(--verde-oscuro);
            font-size: 13px;
        }

        .estado-opcion-texto small {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            line-height: 1.45;
        }

        .estado-opcion-check {
            width: 24px;
            height: 24px;
            position: absolute;
            top: 9px;
            right: 9px;
            display: none;
            place-items: center;
            border-radius: 50%;
            color: white;
            font-size: 12px;
        }

        .estado-opcion input:checked + .estado-opcion-contenido {
            border-width: 2px;
            box-shadow: 0 10px 24px rgba(34, 57, 42, .10);
        }

        .estado-opcion input:checked + .estado-opcion-contenido .estado-opcion-check {
            display: grid;
        }

        .estado-pendiente input:checked + .estado-opcion-contenido {
            border-color: #c99a24;
            background: #fff9ea;
        }

        .estado-pendiente input:checked + .estado-opcion-contenido .estado-opcion-icono,
        .estado-pendiente input:checked + .estado-opcion-contenido .estado-opcion-check {
            background: #c99a24;
            color: white;
        }

        .estado-confirmada input:checked + .estado-opcion-contenido {
            border-color: #3b8156;
            background: #f1f9f3;
        }

        .estado-confirmada input:checked + .estado-opcion-contenido .estado-opcion-icono,
        .estado-confirmada input:checked + .estado-opcion-contenido .estado-opcion-check {
            background: #3b8156;
            color: white;
        }

        .estado-finalizada input:checked + .estado-opcion-contenido {
            border-color: #5477a8;
            background: #f2f6fc;
        }

        .estado-finalizada input:checked + .estado-opcion-contenido .estado-opcion-icono,
        .estado-finalizada input:checked + .estado-opcion-contenido .estado-opcion-check {
            background: #5477a8;
            color: white;
        }

        .estado-cancelada input:checked + .estado-opcion-contenido {
            border-color: #b65050;
            background: #fff4f4;
        }

        .estado-cancelada input:checked + .estado-opcion-contenido .estado-opcion-icono,
        .estado-cancelada input:checked + .estado-opcion-contenido .estado-opcion-check {
            background: #b65050;
            color: white;
        }

        .resumen-card {
            padding: 18px;
            border: 1px solid #dedfd9;
            border-radius: 8px;
            background-color: #fbfcfa;
        }

        .resumen-card strong {
            color: var(--verde-oscuro);
        }

        .calculo-card,
        .resumen-card {
            height: 100%;
        }

        .calculo-card {
            height: 100%;
            padding: 16px;
            border: 1px solid #dedfd9;
            border-radius: 8px;
            background-color: #fbfcfa;
        }

        .calculo-card small {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .calculo-card strong {
            display: block;
            margin-top: 6px;
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 25px;
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
            align-items: center;
            gap: 12px;
            margin-top: 2px;
        }

        .btn-actualizar,
        .btn-cancelar {
            min-height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 21px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 900;
            letter-spacing: .1px;
            transition:
                transform .18s ease,
                box-shadow .18s ease,
                background-color .18s ease,
                border-color .18s ease;
        }

        .btn-actualizar {
            border: 1px solid var(--verde);
            background-color: var(--verde);
            color: white;
            box-shadow: 0 8px 20px rgba(36, 74, 53, .18);
        }

        .btn-actualizar:hover {
            border-color: var(--verde-oscuro);
            background-color: var(--verde-oscuro);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 11px 24px rgba(23, 51, 37, .24);
        }

        .btn-cancelar {
            border: 1px solid #d4d9d4;
            background-color: #ffffff;
            color: #505a53;
            box-shadow: 0 5px 14px rgba(35, 48, 39, .07);
        }

        .btn-cancelar:hover {
            border-color: #b9c1ba;
            background-color: #f4f6f4;
            color: var(--verde-oscuro);
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(35, 48, 39, .10);
        }

        .btn-accion-icono {
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 28px;
            border-radius: 50%;
        }

        .btn-actualizar .btn-accion-icono {
            background-color: rgba(255, 255, 255, .14);
        }

        .btn-cancelar .btn-accion-icono {
            background-color: var(--verde-claro);
            color: var(--verde);
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
            border-top: 1px solid rgba(255, 255, 255, .10);
            color: rgba(255, 255, 255, .52);
            font-size: 12px;
        }

        @media (max-width: 991px) {
            .estado-opciones {
                grid-template-columns: repeat(2, minmax(0, 1fr));
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
                padding: 55px 0;
            }

            .formulario-cuerpo {
                padding: 22px;
            }

            .notificaciones-admin {
                margin-top: 10px;
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

            .estado-opciones,
            .plan-opciones {
                grid-template-columns: 1fr;
            }

            .estado-opcion-contenido {
                min-height: 92px;
            }

            .plan-opcion-contenido {
                min-height: 74px;
            }

            .menu-notificaciones-admin {
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
                <strong>Hotel Las 3 Palmeras</strong>
                <small>COMODIDAD Y TRANQUILIDAD</small>
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

        <div class="collapse navbar-collapse" id="menuPrincipal">
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a href="../dashboard.php" class="nav-link">
                        Inicio
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../habitaciones/index.php" class="nav-link">
                        Habitaciones
                    </a>
                </li>

                <li class="nav-item">
                    <a href="index.php" class="nav-link active">
                        Reservas
                    </a>
                </li>

                <li class="nav-item">
                    <a href="../comidas/index.php" class="nav-link">
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
                        Administración
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a href="../clientes/index.php" class="dropdown-item">
                                <i class="bi bi-people me-2"></i>
                                Clientes
                            </a>
                        </li>

                        <li>
                            <a href="../pedidos/index.php" class="dropdown-item">
                                <i class="bi bi-receipt me-2"></i>
                                Pedidos
                            </a>
                        </li>

                        <?php if ($esAdministrador) { ?>
                            <li>
                                <a href="../pagos/index.php" class="dropdown-item">
                                    <i class="bi bi-credit-card me-2"></i>
                                    Pagos
                                </a>
                            </li>

                            <li>
                                <a href="../usuarios/index.php" class="dropdown-item">
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
                            aria-label="Pagos pendientes"
                        >
                            <i class="bi bi-bell"></i>

                            <?php if ($pagosPendientes > 0) { ?>
                                <span class="contador-notificaciones-admin">
                                    <?php echo $pagosPendientes > 99 ? "99+" : $pagosPendientes; ?>
                                </span>
                            <?php } ?>
                        </button>

                        <div class="dropdown-menu dropdown-menu-end menu-notificaciones-admin">

                            <div class="notificaciones-admin-cabecera">
                                <strong>Pagos por revisar</strong>
                                <small>Pagos pendientes de aprobación</small>
                            </div>

                            <?php if (
                                $notificacionesPagos &&
                                mysqli_num_rows($notificacionesPagos) > 0
                            ) { ?>

                                <?php while (
                                    $notificacionPago =
                                        mysqli_fetch_assoc($notificacionesPagos)
                                ) { ?>

                                    <a
                                        href="../pagos/index.php"
                                        class="notificacion-pago-admin"
                                    >
                                        <div class="notificacion-pago-icono">
                                            <i class="bi bi-receipt"></i>
                                        </div>

                                        <div class="notificacion-pago-contenido">
                                            <strong>
                                                <?php
                                                echo h(
                                                    $notificacionPago["nombres"] .
                                                    " " .
                                                    $notificacionPago["apellidos"]
                                                );
                                                ?>
                                            </strong>

                                            <span>
                                                Reserva #
                                                <?php echo (int) $notificacionPago["id_reserva"]; ?>
                                                · Habitación
                                                <?php echo h($notificacionPago["numero_habitacion"]); ?>
                                            </span>

                                            <span>
                                                <?php echo h($notificacionPago["metodo_pago"]); ?>
                                            </span>

                                            <span class="notificacion-pago-monto">
                                                $<?php
                                                echo number_format(
                                                    (float) $notificacionPago["monto"],
                                                    2
                                                );
                                                ?>
                                                ·
                                                <?php
                                                echo h(
                                                    formatearFechaNotificacion(
                                                        $notificacionPago["fecha_pago"]
                                                    )
                                                );
                                                ?>
                                            </span>
                                        </div>
                                    </a>

                                <?php } ?>

                            <?php } else { ?>

                                <div class="notificaciones-admin-vacio">
                                    <i class="bi bi-check2-circle me-1"></i>
                                    No hay pagos pendientes.
                                </div>

                            <?php } ?>

                            <div class="notificaciones-admin-pie">
                                <a href="../pagos/index.php">
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
                ADMINISTRACIÓN HOTELERA
            </div>

            <h1>Editar reserva</h1>

            <p>
                Actualiza huésped, habitación, personas, desayuno,
                fechas o estado. El total se recalcula y se comprueban
                los cruces con otras reservas.
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
                    <strong>No se pudo actualizar:</strong>

                    <ul class="mt-2 mb-0">
                        <?php foreach ($errores as $error) { ?>
                            <li><?php echo h($error); ?></li>
                        <?php } ?>
                    </ul>
                </div>
            </div>
        <?php } ?>

        <div class="formulario-card">
            <div class="formulario-cabecera">
                <div class="formulario-icono">
                    <i class="bi bi-calendar2-check"></i>
                </div>

                <div>
                    <h3>
                        Reserva #<?php echo $idReserva; ?>
                    </h3>

                    <p>
                        Modifica los datos, el plan de alojamiento
                        y guarda los cambios.
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
                        <div class="col-md-6 col-lg-4">
                            <label for="id_cliente" class="form-label">
                                Cliente
                            </label>

                            <select
                                id="id_cliente"
                                name="id_cliente"
                                class="form-select"
                                required
                            >
                                <?php if ($clientes) { ?>
                                    <?php while ($cliente = mysqli_fetch_assoc($clientes)) { ?>
                                        <option
                                            value="<?php echo (int) $cliente["id_cliente"]; ?>"
                                            <?php
                                            echo $idCliente ===
                                                (int) $cliente["id_cliente"]
                                                ? "selected"
                                                : "";
                                            ?>
                                        >
                                            <?php
                                            echo h(
                                                $cliente["nombres"] .
                                                " " .
                                                $cliente["apellidos"] .
                                                " - " .
                                                $cliente["cedula"]
                                            );
                                            ?>
                                        </option>
                                    <?php } ?>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="col-md-6 col-lg-5">
                            <label for="id_habitacion" class="form-label">
                                Habitación
                            </label>

                            <select
                                id="id_habitacion"
                                name="id_habitacion"
                                class="form-select"
                                required
                            >
                                <?php while (
                                    $habitacion =
                                        mysqli_fetch_assoc(
                                            $resultadoHabitaciones
                                        )
                                ) { ?>
                                    <option
                                        value="<?php echo (int) $habitacion["id_habitacion"]; ?>"
                                        data-precio="<?php echo h($habitacion["precio"]); ?>"
                                        data-capacidad="<?php echo (int) $habitacion["capacidad"]; ?>"
                                        <?php
                                        echo $idHabitacion ===
                                            (int) $habitacion["id_habitacion"]
                                            ? "selected"
                                            : "";
                                        ?>
                                    >
                                        Hab.
                                        <?php echo h($habitacion["numero"]); ?>
                                        -
                                        <?php echo h($habitacion["tipo"]); ?>
                                        -
                                        <?php
                                        echo (int)
                                            $habitacion["capacidad"];
                                        ?>
                                        persona(s)
                                        -
                                        $<?php
                                        echo number_format(
                                            (float) $habitacion["precio"],
                                            2
                                        );
                                        ?>

                                        <?php if ($habitacion["estado"] === "Mantenimiento") { ?>
                                            - Mantenimiento
                                        <?php } ?>
                                    </option>
                                <?php } ?>
                            </select>

                            <div class="form-text">
                                La habitación actual se muestra aunque
                                haya pasado a mantenimiento.
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-3">
                            <label
                                for="numero_personas"
                                class="form-label"
                            >
                                Número de personas
                            </label>

                            <input
                                type="number"
                                id="numero_personas"
                                name="numero_personas"
                                class="form-control"
                                min="1"
                                max="<?php
                                echo max(
                                    1,
                                    (int) $datos["capacidad"]
                                );
                                ?>"
                                value="<?php echo $numeroPersonas; ?>"
                                required
                            >

                            <div
                                class="form-text"
                                id="texto_capacidad"
                            >
                                Capacidad máxima:
                                <?php
                                echo max(
                                    1,
                                    (int) $datos["capacidad"]
                                );
                                ?>
                                persona(s).
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label d-block mb-2">
                                Estado de la reserva
                            </label>

                            <?php
                            $iconosEstado = [
                                "Pendiente" => "bi-clock",
                                "Confirmada" => "bi-check-circle",
                                "Finalizada" => "bi-flag",
                                "Cancelada" => "bi-x-circle"
                            ];
                            ?>

                            <div class="estado-opciones">

                                <?php foreach ($estadosPermitidos as $estadoPermitido) { ?>

                                    <label
                                        class="estado-opcion estado-<?php echo strtolower($estadoPermitido); ?>"
                                    >
                                        <input
                                            type="radio"
                                            name="estado"
                                            value="<?php echo h($estadoPermitido); ?>"
                                            <?php echo $estado === $estadoPermitido ? "checked" : ""; ?>
                                            required
                                        >

                                        <span class="estado-opcion-contenido">

                                            <span class="estado-opcion-icono">
                                                <i
                                                    class="bi <?php echo h($iconosEstado[$estadoPermitido] ?? "bi-circle"); ?>"
                                                ></i>
                                            </span>

                                            <span class="estado-opcion-texto">
                                                <strong>
                                                    <?php echo h($estadoPermitido); ?>
                                                </strong>

                                                <small>
                                                    <?php if ($estadoPermitido === "Pendiente") { ?>
                                                        Aún espera confirmación o pago.
                                                    <?php } elseif ($estadoPermitido === "Confirmada") { ?>
                                                        Reserva aprobada para la estadía.
                                                    <?php } elseif ($estadoPermitido === "Finalizada") { ?>
                                                        La estadía del huésped ya terminó.
                                                    <?php } else { ?>
                                                        La reserva fue anulada.
                                                    <?php } ?>
                                                </small>
                                            </span>

                                            <span class="estado-opcion-check">
                                                <i class="bi bi-check-lg"></i>
                                            </span>

                                        </span>
                                    </label>

                                <?php } ?>

                            </div>

                            <div class="form-text mt-2">
                                Selecciona el estado que corresponda a la reserva.
                            </div>
                        </div>

                        <div class="col-12 col-lg-6">
                            <label class="form-label d-block mb-2">
                                Plan de alojamiento
                            </label>

                            <div class="plan-opciones">

                                <label class="plan-opcion">
                                    <input
                                        type="radio"
                                        name="plan_alimentacion"
                                        value="Solo alojamiento"
                                        <?php
                                        echo $planAlimentacion ===
                                            "Solo alojamiento"
                                                ? "checked"
                                                : "";
                                        ?>
                                        required
                                    >

                                    <span class="plan-opcion-contenido">

                                        <span class="plan-opcion-icono">
                                            <i class="bi bi-building"></i>
                                        </span>

                                        <span class="plan-opcion-texto">
                                            <strong>
                                                Solo alojamiento
                                            </strong>

                                            <small>
                                                Solo incluye la habitación.
                                            </small>
                                        </span>

                                        <span class="plan-opcion-precio">
                                            Sin adicional
                                        </span>

                                        <span class="plan-opcion-check">
                                            <i class="bi bi-check-lg"></i>
                                        </span>

                                    </span>
                                </label>

                                <label class="plan-opcion">
                                    <input
                                        type="radio"
                                        name="plan_alimentacion"
                                        value="Alojamiento con desayuno"
                                        <?php
                                        echo $planAlimentacion ===
                                            "Alojamiento con desayuno"
                                                ? "checked"
                                                : "";
                                        ?>
                                        required
                                    >

                                    <span class="plan-opcion-contenido">

                                        <span class="plan-opcion-icono">
                                            <i class="bi bi-cup-hot"></i>
                                        </span>

                                        <span class="plan-opcion-texto">
                                            <strong>
                                                Con desayuno
                                            </strong>

                                            <small>
                                                Desayuno por huésped y noche.
                                            </small>
                                        </span>

                                        <span class="plan-opcion-precio">
                                            +$<?php
                                            echo number_format(
                                                $precioDesayuno,
                                                2
                                            );
                                            ?>
                                            / persona
                                        </span>

                                        <span class="plan-opcion-check">
                                            <i class="bi bi-check-lg"></i>
                                        </span>

                                    </span>
                                </label>

                            </div>

                            <div class="form-text mt-2">
                                El total se recalcula automáticamente.
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-3">
                            <label for="fecha_entrada" class="form-label">
                                Fecha de entrada
                            </label>

                            <input
                                type="date"
                                id="fecha_entrada"
                                name="fecha_entrada"
                                class="form-control"
                                value="<?php echo h($fechaEntrada); ?>"
                                required
                            >
                        </div>

                        <div class="col-md-6 col-lg-3">
                            <label for="fecha_salida" class="form-label">
                                Fecha de salida
                            </label>

                            <input
                                type="date"
                                id="fecha_salida"
                                name="fecha_salida"
                                class="form-control"
                                value="<?php echo h($fechaSalida); ?>"
                                required
                            >
                        </div>

                        <div class="col-md-6 col-lg-3">
                            <div class="calculo-card">
                                <small>
                                    Habitación
                                </small>

                                <strong id="subtotal_habitacion">
                                    $<?php
                                    echo number_format(
                                        $subtotalHabitacion,
                                        2
                                    );
                                    ?>
                                </strong>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-3">
                            <div class="calculo-card">
                                <small>
                                    Desayuno
                                </small>

                                <strong id="total_desayuno">
                                    $<?php
                                    echo number_format(
                                        $totalAlimentacion,
                                        2
                                    );
                                    ?>
                                </strong>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-3">
                            <div class="calculo-card">
                                <small>
                                    Total recalculado
                                </small>

                                <strong id="total_estimado">
                                    $<?php
                                    echo number_format(
                                        $total,
                                        2
                                    );
                                    ?>
                                </strong>
                            </div>
                        </div>

                        <div class="col-md-6 col-lg-3">
                            <div class="resumen-card h-100">
                                <strong>Reserva actual</strong>

                                <div class="form-text mt-2">
                                    Habitación original:
                                    <?php echo h($datos["numero"]); ?>
                                    -
                                    <?php echo h($datos["tipo"]); ?>
                                </div>

                                <div class="form-text">
                                    Personas guardadas:
                                    <?php
                                    echo max(
                                        1,
                                        (int) (
                                            $datos[
                                                "numero_personas"
                                            ] ?? 1
                                        )
                                    );
                                    ?>
                                </div>

                                <div class="form-text">
                                    Plan guardado:
                                    <?php
                                    echo h(
                                        $datos[
                                            "plan_alimentacion"
                                        ] ??
                                        "Solo alojamiento"
                                    );
                                    ?>
                                </div>

                                <div class="form-text">
                                    Total guardado:
                                    $<?php
                                    echo number_format(
                                        (float) $datos["total"],
                                        2
                                    );
                                    ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="aviso">
                                <i class="bi bi-info-circle me-1"></i>

                                Pendiente y Confirmada bloquean la habitación
                                en esas fechas. Finalizada y Cancelada dejan de
                                bloquear fechas futuras.
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="botones">
                                <button
                                    type="submit"
                                    name="actualizar"
                                    class="btn-actualizar"
                                >
                                    <span class="btn-accion-icono">
                                        <i class="bi bi-check2"></i>
                                    </span>

                                    <span>Guardar cambios</span>
                                </button>

                                <a href="index.php" class="btn-cancelar">
                                    <span class="btn-accion-icono">
                                        <i class="bi bi-arrow-left"></i>
                                    </span>

                                    <span>Volver sin guardar</span>
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

            <a href="index.php" class="btn btn-outline-light btn-sm">
                Volver a reservas
            </a>
        </div>
    </div>

    <div class="footer-final">
        <div class="container d-flex justify-content-between flex-wrap gap-2">
            <span>Hotel Las 3 Palmeras © 2026</span>
            <span>Edición de reservas</span>
        </div>
    </div>
</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>
    const selectorHabitacion =
        document.getElementById("id_habitacion");

    const campoEntrada =
        document.getElementById("fecha_entrada");

    const campoSalida =
        document.getElementById("fecha_salida");

    const numeroPersonas =
        document.getElementById(
            "numero_personas"
        );

    const planesAlimentacion =
        document.querySelectorAll(
            'input[name="plan_alimentacion"]'
        );

    function obtenerPlanAlimentacion() {
        const planSeleccionado =
            document.querySelector(
                'input[name="plan_alimentacion"]:checked'
            );

        return planSeleccionado
            ? planSeleccionado.value
            : "Solo alojamiento";
    }

    const textoCapacidad =
        document.getElementById(
            "texto_capacidad"
        );

    const subtotalHabitacion =
        document.getElementById(
            "subtotal_habitacion"
        );

    const totalDesayuno =
        document.getElementById(
            "total_desayuno"
        );

    const totalEstimado =
        document.getElementById(
            "total_estimado"
        );

    const precioDesayuno =
        <?php echo json_encode($precioDesayuno); ?>;

    function actualizarCapacidad() {
        const opcion =
            selectorHabitacion.options[
                selectorHabitacion.selectedIndex
            ];

        const capacidad =
            Number(
                opcion.dataset.capacidad || 0
            );

        if (capacidad > 0) {
            numeroPersonas.max =
                capacidad;

            textoCapacidad.textContent =
                "Capacidad máxima: " +
                capacidad +
                " persona(s).";

            if (
                Number(numeroPersonas.value) >
                capacidad
            ) {
                numeroPersonas.value =
                    capacidad;
            }
        }
    }

    function calcularTotal() {
        const opcion =
            selectorHabitacion.options[
                selectorHabitacion.selectedIndex
            ];

        const precio =
            Number(
                opcion.dataset.precio || 0
            );

        const personas =
            Math.max(
                1,
                Number(numeroPersonas.value) || 1
            );

        const fechaEntrada =
            campoEntrada.value;

        const fechaSalida =
            campoSalida.value;

        if (
            precio <= 0 ||
            !fechaEntrada ||
            !fechaSalida
        ) {
            subtotalHabitacion.textContent =
                "$0.00";

            totalDesayuno.textContent =
                "$0.00";

            totalEstimado.textContent =
                "$0.00";

            return;
        }

        const entrada =
            new Date(
                fechaEntrada + "T00:00:00"
            );

        const salida =
            new Date(
                fechaSalida + "T00:00:00"
            );

        const diferencia =
            salida.getTime() -
            entrada.getTime();

        const dias =
            diferencia /
            (1000 * 60 * 60 * 24);

        if (dias <= 0) {
            subtotalHabitacion.textContent =
                "$0.00";

            totalDesayuno.textContent =
                "$0.00";

            totalEstimado.textContent =
                "Fechas inválidas";

            return;
        }

        const subtotal =
            dias * precio;

        const desayuno =
            obtenerPlanAlimentacion() ===
            "Alojamiento con desayuno"
                ? dias *
                  personas *
                  precioDesayuno
                : 0;

        const total =
            subtotal + desayuno;

        subtotalHabitacion.textContent =
            "$" + subtotal.toFixed(2);

        totalDesayuno.textContent =
            "$" + desayuno.toFixed(2);

        totalEstimado.textContent =
            "$" + total.toFixed(2);
    }

    function actualizarFechaSalida() {
        if (!campoEntrada.value) {
            return;
        }

        const entrada =
            new Date(
                campoEntrada.value +
                "T00:00:00"
            );

        entrada.setDate(
            entrada.getDate() + 1
        );

        const anio =
            entrada.getFullYear();

        const mes =
            String(
                entrada.getMonth() + 1
            ).padStart(2, "0");

        const dia =
            String(
                entrada.getDate()
            ).padStart(2, "0");

        const minimo =
            anio + "-" + mes + "-" + dia;

        campoSalida.min = minimo;

        if (
            campoSalida.value &&
            campoSalida.value < minimo
        ) {
            campoSalida.value = "";
        }
    }

    selectorHabitacion.addEventListener(
        "change",
        () => {
            actualizarCapacidad();
            calcularTotal();
        }
    );

    numeroPersonas.addEventListener(
        "input",
        calcularTotal
    );

    planesAlimentacion.forEach(
        (plan) => {
            plan.addEventListener(
                "change",
                calcularTotal
            );
        }
    );

    campoEntrada.addEventListener(
        "change",
        () => {
            actualizarFechaSalida();
            calcularTotal();
        }
    );

    campoSalida.addEventListener(
        "change",
        calcularTotal
    );

    actualizarCapacidad();
    actualizarFechaSalida();
    calcularTotal();
</script>

</body>
</html>