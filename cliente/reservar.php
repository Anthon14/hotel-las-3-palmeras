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

function convertirFechaValida($fecha)
{
    $fecha = trim((string) $fecha);

    if (
        !preg_match(
            "/^\d{4}-\d{2}-\d{2}$/",
            $fecha
        )
    ) {
        return null;
    }

    $objetoFecha =
        DateTimeImmutable::createFromFormat(
            "!Y-m-d",
            $fecha
        );

    $erroresFecha =
        DateTimeImmutable::getLastErrors();

    if (!$objetoFecha) {
        return null;
    }

    if (
        is_array($erroresFecha) &&
        (
            $erroresFecha["warning_count"] > 0 ||
            $erroresFecha["error_count"] > 0
        )
    ) {
        return null;
    }

    if ($objetoFecha->format("Y-m-d") !== $fecha) {
        return null;
    }

    return $objetoFecha;
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

if ($idUsuario <= 0) {
    session_unset();
    session_destroy();

    header(
        "Location: ../login.php?mensaje=sesion_invalida"
    );
    exit();
}

$consultaCliente = mysqli_prepare(
    $conn,
    "SELECT
        id_cliente,
        nombres,
        apellidos
     FROM clientes
     WHERE id_usuario = ?
     LIMIT 1"
);

if (!$consultaCliente) {
    header(
        "Location: ../login.php?mensaje=cuenta_no_vinculada"
    );
    exit();
}

mysqli_stmt_bind_param(
    $consultaCliente,
    "i",
    $idUsuario
);

mysqli_stmt_execute($consultaCliente);

$resultadoCliente =
    mysqli_stmt_get_result($consultaCliente);

$cliente =
    mysqli_fetch_assoc($resultadoCliente);

mysqli_stmt_close($consultaCliente);

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

$consultaHabitacion = mysqli_prepare(
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

if (!$consultaHabitacion) {
    header(
        "Location: index.php?mensaje=error_habitacion#habitaciones"
    );
    exit();
}

mysqli_stmt_bind_param(
    $consultaHabitacion,
    "i",
    $idHabitacion
);

mysqli_stmt_execute($consultaHabitacion);

$resultadoHabitacion =
    mysqli_stmt_get_result($consultaHabitacion);

$habitacion =
    mysqli_fetch_assoc($resultadoHabitacion);

mysqli_stmt_close($consultaHabitacion);

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

if (empty($_SESSION["csrf_reserva"])) {
    $_SESSION["csrf_reserva"] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_reserva"];

$errores = [];
$fechaEntrada =
    trim((string) ($_POST["fecha_entrada"] ?? ""));

$fechaSalida =
    trim((string) ($_POST["fecha_salida"] ?? ""));

$numeroPersonas =
    (int) ($_POST["numero_personas"] ?? 1);

$planAlimentacion =
    trim(
        (string) (
            $_POST["plan_alimentacion"] ??
            "Solo alojamiento"
        )
    );

$precioDesayuno = 5.00;

$cantidadNoches = 0;
$subtotalHabitacion = 0.00;
$totalAlimentacion = 0.00;
$total = 0.00;

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["reservar"])
) {
    $csrfRecibido =
        $_POST["csrf"] ?? "";

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $errores[] =
            "La solicitud no es válida. Actualiza la página.";
    }

    $entrada =
        convertirFechaValida($fechaEntrada);

    $salida =
        convertirFechaValida($fechaSalida);

    if (!$entrada || !$salida) {
        $errores[] =
            "Seleccione fechas válidas de entrada y salida.";
    } else {
        $hoy =
            new DateTimeImmutable("today");

        if ($entrada < $hoy) {
            $errores[] =
                "La fecha de entrada no puede ser anterior a hoy.";
        }

        if ($salida <= $entrada) {
            $errores[] =
                "La fecha de salida debe ser posterior a la fecha de entrada.";
        }

        if (empty($errores)) {
            $cantidadNoches =
                (int) $entrada->diff($salida)->days;
        }
    }

    $capacidadHabitacion =
        (int) $habitacion["capacidad"];

    if (
        $numeroPersonas < 1 ||
        $numeroPersonas > $capacidadHabitacion
    ) {
        $errores[] =
            "El número de personas debe estar entre 1 y " .
            $capacidadHabitacion . ".";
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

    if (empty($errores)) {
        mysqli_begin_transaction($conn);

        try {
            $bloquearHabitacion = mysqli_prepare(
                $conn,
                "SELECT
                    id_habitacion,
                    precio,
                    estado
                 FROM habitaciones
                 WHERE id_habitacion = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$bloquearHabitacion) {
                throw new Exception(
                    "No se pudo validar la habitación."
                );
            }

            mysqli_stmt_bind_param(
                $bloquearHabitacion,
                "i",
                $idHabitacion
            );

            mysqli_stmt_execute($bloquearHabitacion);

            $resultadoBloqueo =
                mysqli_stmt_get_result($bloquearHabitacion);

            $habitacionBloqueada =
                mysqli_fetch_assoc($resultadoBloqueo);

            mysqli_stmt_close($bloquearHabitacion);

            if (!$habitacionBloqueada) {
                throw new Exception(
                    "La habitación ya no existe."
                );
            }

            if (
                $habitacionBloqueada["estado"] === "Mantenimiento"
            ) {
                throw new Exception(
                    "La habitación se encuentra en mantenimiento."
                );
            }

            $comprobarFechas = mysqli_prepare(
                $conn,
                "SELECT id_reserva
                 FROM reservas
                 WHERE id_habitacion = ?
                   AND estado IN ('Pendiente', 'Confirmada')
                   AND fecha_entrada < ?
                   AND fecha_salida > ?
                 LIMIT 1"
            );

            if (!$comprobarFechas) {
                throw new Exception(
                    "No se pudo revisar la disponibilidad."
                );
            }

            mysqli_stmt_bind_param(
                $comprobarFechas,
                "iss",
                $idHabitacion,
                $fechaSalida,
                $fechaEntrada
            );

            mysqli_stmt_execute($comprobarFechas);

            $resultadoFechas =
                mysqli_stmt_get_result($comprobarFechas);

            $reservaCruzada =
                mysqli_fetch_assoc($resultadoFechas);

            mysqli_stmt_close($comprobarFechas);

            if ($reservaCruzada) {
                throw new Exception(
                    "La habitación ya tiene una reserva para esas fechas."
                );
            }

            $precioNoche =
                round(
                    (float) $habitacionBloqueada["precio"],
                    2
                );

            if ($precioNoche <= 0) {
                throw new Exception(
                    "El precio de la habitación no es válido."
                );
            }

            $subtotalHabitacion =
                round(
                    $cantidadNoches * $precioNoche,
                    2
                );

            if (
                $planAlimentacion ===
                "Alojamiento con desayuno"
            ) {
                $totalAlimentacion =
                    round(
                        $cantidadNoches *
                        $numeroPersonas *
                        $precioDesayuno,
                        2
                    );
            } else {
                $totalAlimentacion = 0.00;
            }

            $total =
                round(
                    $subtotalHabitacion +
                    $totalAlimentacion,
                    2
                );

            $estadoReserva = "Pendiente";

            $guardarReserva = mysqli_prepare(
                $conn,
                "INSERT INTO reservas
                    (
                        id_cliente,
                        id_habitacion,
                        fecha_entrada,
                        fecha_salida,
                        numero_personas,
                        plan_alimentacion,
                        precio_desayuno,
                        total_alimentacion,
                        estado,
                        total
                    )
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$guardarReserva) {
                throw new Exception(
                    "No se pudo preparar la reserva."
                );
            }

            mysqli_stmt_bind_param(
                $guardarReserva,
                "iissisddsd",
                $idCliente,
                $idHabitacion,
                $fechaEntrada,
                $fechaSalida,
                $numeroPersonas,
                $planAlimentacion,
                $precioDesayuno,
                $totalAlimentacion,
                $estadoReserva,
                $total
            );

            if (!mysqli_stmt_execute($guardarReserva)) {
                mysqli_stmt_close($guardarReserva);

                throw new Exception(
                    "No se pudo registrar la reserva."
                );
            }

            $idReserva =
                mysqli_insert_id($conn);

            mysqli_stmt_close($guardarReserva);

            mysqli_commit($conn);

            $_SESSION["csrf_reserva"] =
                bin2hex(random_bytes(32));

            header(
                "Location: pagar.php?id=" .
                $idReserva .
                "&mensaje=reserva_creada"
            );
            exit();
        } catch (Throwable $excepcion) {
            mysqli_rollback($conn);

            $errores[] =
                $excepcion->getMessage() !== ""
                    ? $excepcion->getMessage()
                    : "No se pudo registrar la reserva.";
        }
    }
}

$precioNocheVista =
    round(
        (float) $habitacion["precio"],
        2
    );

if ($cantidadNoches > 0) {
    $subtotalHabitacion =
        round(
            $cantidadNoches * $precioNocheVista,
            2
        );

    if (
        $planAlimentacion ===
        "Alojamiento con desayuno"
    ) {
        $totalAlimentacion =
            round(
                $cantidadNoches *
                $numeroPersonas *
                $precioDesayuno,
                2
            );
    } else {
        $totalAlimentacion = 0.00;
    }

    $total =
        round(
            $subtotalHabitacion +
            $totalAlimentacion,
            2
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
        Reservar habitación - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=61"
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
            min-height: 315px;
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

        .mensaje-error {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
            padding: 14px 17px;
            border: 1px solid #edc8c8;
            border-radius: 6px;
            background: #fff1f1;
            color: #9b3131;
            font-size: 13px;
        }

        .reserva-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            box-shadow: var(--sombra);
        }

        .imagen-contenedor {
            position: relative;
            height: 100%;
            min-height: 650px;
            background: #e8ece8;
        }

        .imagen-habitacion {
            width: 100%;
            height: 100%;
            min-height: 650px;
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

        .formulario-contenido {
            padding: 36px;
        }

        .formulario-contenido h2 {
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
        }

        .precio {
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 34px;
            font-weight: 700;
        }

        .form-label {
            font-size: 12px;
            font-weight: 900;
        }

        .form-control,
        .form-select {
            min-height: 49px;
            border: 1px solid #dce1dc;
            background: #f7f9f7;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--verde);
            background: white;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, .10);
        }

        .plan-opciones {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .plan-opcion {
            position: relative;
            margin: 0;
            cursor: pointer;
        }

        .plan-radio {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .plan-card {
            min-height: 84px;
            height: 100%;
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 34px 12px 12px;
            border: 1px solid #dce1dc;
            border-radius: 10px;
            background: #fbfcfa;
            transition:
                border-color .18s ease,
                background-color .18s ease,
                box-shadow .18s ease,
                transform .18s ease;
        }

        .plan-card:hover {
            border-color: #b9c4bb;
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(35, 55, 42, .08);
        }

        .plan-icono {
            width: 36px;
            height: 36px;
            flex: 0 0 36px;
            display: grid;
            place-items: center;
            border-radius: 9px;
            background: var(--verde-claro);
            color: var(--verde);
            font-size: 16px;
        }

        .plan-contenido {
            min-width: 0;
            flex: 1;
        }

        .plan-contenido strong {
            display: block;
            margin-bottom: 3px;
            color: var(--verde-oscuro);
            font-size: 12px;
        }

        .plan-contenido small {
            display: block;
            color: var(--texto-suave);
            font-size: 9px;
            line-height: 1.4;
        }

        .plan-precio {
            display: inline-flex;
            margin-top: 4px;
            padding: 3px 6px;
            border-radius: 999px;
            background: #eef2ee;
            color: #5a665d;
            font-size: 8px;
            font-weight: 900;
        }

        .plan-check {
            position: absolute;
            top: 8px;
            right: 8px;
            color: #c7cec9;
            font-size: 15px;
        }

        .plan-radio:checked + .plan-card {
            border: 2px solid var(--verde);
            background: #f4faf6;
            box-shadow: 0 8px 20px rgba(36, 74, 53, .10);
        }

        .plan-radio:checked + .plan-card .plan-icono {
            background: var(--verde);
            color: white;
        }

        .plan-radio:checked + .plan-card .plan-check {
            color: var(--verde);
        }

        .plan-radio:focus-visible + .plan-card {
            outline: 3px solid rgba(36, 74, 53, .16);
            outline-offset: 2px;
        }

        .resumen-reserva {
            padding: 19px;
            border: 1px solid #dfe4de;
            border-radius: 8px;
            background: #f4f7f4;
        }

        .dato-resumen {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 8px 0;
            border-bottom: 1px solid #e0e4df;
            color: var(--texto-suave);
            font-size: 13px;
        }

        .dato-resumen:last-child {
            border-bottom: 0;
        }

        .total-estimado {
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 28px;
        }

        .nota {
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
                min-height: 340px;
                height: 340px;
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

            .formulario-contenido {
                padding: 24px;
            }

            .dato-resumen {
                display: block;
            }

            .plan-opciones {
                grid-template-columns: 1fr;
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
                NUEVA RESERVA
            </div>

            <h1>Selecciona las fechas</h1>

            <p>
                El sistema comprobará que no exista otra reserva
                para la misma habitación antes de guardar.
            </p>

        </div>
    </div>
</section>

<main class="contenido-pagina">
    <div class="container">

        <a
            href="ver_habitacion.php?id=<?php echo $idHabitacion; ?>"
            class="btn-volver"
        >
            <i class="bi bi-arrow-left"></i>
            Volver a la habitación
        </a>

        <?php if (!empty($errores)) { ?>

            <div class="mensaje-error">

                <i class="bi bi-exclamation-triangle"></i>

                <div>
                    <strong>No se pudo registrar la reserva:</strong>

                    <ul class="mt-2 mb-0">

                        <?php foreach ($errores as $error) { ?>

                            <li><?php echo h($error); ?></li>

                        <?php } ?>

                    </ul>
                </div>

            </div>

        <?php } ?>

        <section class="reserva-card">

            <div class="row g-0">

                <div class="col-lg-6">

                    <div class="imagen-contenedor">

                        <img
                            src="<?php echo h($rutaImagen); ?>"
                            alt="Habitación <?php echo h($habitacion["numero"]); ?>"
                            class="imagen-habitacion"
                            onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                        >

                        <span class="numero-flotante">
                            HABITACIÓN <?php echo h($habitacion["numero"]); ?>
                        </span>

                    </div>

                </div>

                <div class="col-lg-6">

                    <div class="formulario-contenido">

                        <div class="pagina-etiqueta text-success">
                            <?php
                            echo h(
                                strtoupper(
                                    (string) $habitacion["tipo"]
                                )
                            );
                            ?>
                        </div>

                        <h2 class="display-6 mt-2 mb-2">
                            Habitación <?php echo h($habitacion["numero"]); ?>
                        </h2>

                        <p class="text-muted">
                            Capacidad para
                            <?php echo (int) $habitacion["capacidad"]; ?>
                            persona(s).
                        </p>

                        <div class="precio mb-4">
                            $<?php
                            echo number_format(
                                $precioNocheVista,
                                2
                            );
                            ?>

                            <span class="text-muted fs-6">
                                / noche
                            </span>
                        </div>

                        <form
                            method="POST"
                            autocomplete="off"
                        >

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?php echo h($csrf); ?>"
                            >

                            <div class="mb-3">

                                <label
                                    for="fecha_entrada"
                                    class="form-label"
                                >
                                    Fecha de entrada
                                </label>

                                <input
                                    type="date"
                                    name="fecha_entrada"
                                    id="fecha_entrada"
                                    class="form-control"
                                    min="<?php echo date("Y-m-d"); ?>"
                                    value="<?php echo h($fechaEntrada); ?>"
                                    required
                                >

                            </div>

                            <div class="mb-3">

                                <label
                                    for="fecha_salida"
                                    class="form-label"
                                >
                                    Fecha de salida
                                </label>

                                <input
                                    type="date"
                                    name="fecha_salida"
                                    id="fecha_salida"
                                    class="form-control"
                                    min="<?php echo date("Y-m-d"); ?>"
                                    value="<?php echo h($fechaSalida); ?>"
                                    required
                                >

                            </div>

                            <div class="row g-3 mb-3">

                                <div class="col-md-4">

                                    <label
                                        for="numero_personas"
                                        class="form-label"
                                    >
                                        Número de personas
                                    </label>

                                    <select
                                        name="numero_personas"
                                        id="numero_personas"
                                        class="form-select"
                                        required
                                    >

                                        <?php
                                        for (
                                            $persona = 1;
                                            $persona <=
                                                (int) $habitacion["capacidad"];
                                            $persona++
                                        ) {
                                        ?>

                                            <option
                                                value="<?php echo $persona; ?>"
                                                <?php
                                                echo $numeroPersonas === $persona
                                                    ? "selected"
                                                    : "";
                                                ?>
                                            >
                                                <?php
                                                echo $persona;
                                                ?>
                                                persona(s)
                                            </option>

                                        <?php } ?>

                                    </select>

                                </div>

                                <div class="col-md-8">

                                    <div class="form-label">
                                        Plan de alojamiento
                                    </div>

                                    <div class="plan-opciones">

                                        <label class="plan-opcion">

                                            <input
                                                type="radio"
                                                name="plan_alimentacion"
                                                value="Solo alojamiento"
                                                class="plan-radio"
                                                <?php
                                                echo $planAlimentacion === "Solo alojamiento"
                                                    ? "checked"
                                                    : "";
                                                ?>
                                                required
                                            >

                                            <span class="plan-card">

                                                <span class="plan-icono">
                                                    <i class="bi bi-door-open"></i>
                                                </span>

                                                <span class="plan-contenido">
                                                    <strong>Solo alojamiento</strong>
                                                    <small>
                                                        Incluye únicamente la habitación.
                                                    </small>

                                                    <span class="plan-precio">
                                                        Sin costo adicional
                                                    </span>
                                                </span>

                                                <span class="plan-check">
                                                    <i class="bi bi-check-circle-fill"></i>
                                                </span>

                                            </span>

                                        </label>

                                        <label class="plan-opcion">

                                            <input
                                                type="radio"
                                                name="plan_alimentacion"
                                                value="Alojamiento con desayuno"
                                                class="plan-radio"
                                                <?php
                                                echo $planAlimentacion === "Alojamiento con desayuno"
                                                    ? "checked"
                                                    : "";
                                                ?>
                                                required
                                            >

                                            <span class="plan-card">

                                                <span class="plan-icono">
                                                    <i class="bi bi-cup-hot"></i>
                                                </span>

                                                <span class="plan-contenido">
                                                    <strong>Con desayuno</strong>
                                                    <small>
                                                        Desayuno por persona y por noche.
                                                    </small>

                                                    <span class="plan-precio">
                                                        +$<?php echo number_format($precioDesayuno, 2); ?>
                                                    </span>
                                                </span>

                                                <span class="plan-check">
                                                    <i class="bi bi-check-circle-fill"></i>
                                                </span>

                                            </span>

                                        </label>

                                    </div>

                                </div>

                            </div>

                            <div class="nota mb-3">

                                <i class="bi bi-cup-hot"></i>

                                <div>
                                    El desayuno cuesta
                                    <strong>
                                        $<?php
                                        echo number_format(
                                            $precioDesayuno,
                                            2
                                        );
                                        ?>
                                    </strong>
                                    por persona y por noche.
                                    Se sumará al pago de la habitación.
                                </div>

                            </div>

                            <div class="resumen-reserva mb-4">

                                <div class="dato-resumen">

                                    <span>Noches</span>

                                    <strong id="noches">
                                        <?php echo $cantidadNoches; ?>
                                    </strong>

                                </div>

                                <div class="dato-resumen">

                                    <span>Personas</span>

                                    <strong id="personas_resumen">
                                        <?php echo $numeroPersonas; ?>
                                    </strong>

                                </div>

                                <div class="dato-resumen">

                                    <span>Plan</span>

                                    <strong id="plan_resumen">
                                        <?php echo h($planAlimentacion); ?>
                                    </strong>

                                </div>

                                <div class="dato-resumen">

                                    <span>Subtotal habitación</span>

                                    <strong id="subtotal_habitacion">
                                        $<?php
                                        echo number_format(
                                            $subtotalHabitacion,
                                            2
                                        );
                                        ?>
                                    </strong>

                                </div>

                                <div class="dato-resumen">

                                    <span>Desayuno</span>

                                    <strong id="total_desayuno">
                                        $<?php
                                        echo number_format(
                                            $totalAlimentacion,
                                            2
                                        );
                                        ?>
                                    </strong>

                                </div>

                                <div class="dato-resumen align-items-center">

                                    <span>Total estimado</span>

                                    <strong
                                        class="total-estimado"
                                        id="total_estimado"
                                    >
                                        $<?php
                                        echo number_format(
                                            $total,
                                            2
                                        );
                                        ?>
                                    </strong>

                                </div>

                            </div>

                            <div class="nota">

                                <i class="bi bi-shield-check"></i>

                                <div>
                                    El total definitivo se calcula en el
                                    servidor con el precio actual de MySQL,
                                    las noches, el número de personas y el
                                    plan seleccionado.
                                </div>

                            </div>

                            <button
                                type="submit"
                                name="reservar"
                                class="btn-reservar w-100 mt-4"
                            >
                                <i class="bi bi-calendar-check"></i>
                                Registrar reserva y continuar al pago
                            </button>

                        </form>

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
                Reserva de <?php echo h($nombreCliente); ?>
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

<script>
const fechaEntrada =
    document.getElementById("fecha_entrada");

const fechaSalida =
    document.getElementById("fecha_salida");

const noches =
    document.getElementById("noches");

const totalEstimado =
    document.getElementById("total_estimado");

const numeroPersonas =
    document.getElementById("numero_personas");

const opcionesPlan =
    document.querySelectorAll(
        'input[name="plan_alimentacion"]'
    );

function obtenerPlanAlimentacion() {
    const seleccionado =
        document.querySelector(
            'input[name="plan_alimentacion"]:checked'
        );

    return seleccionado
        ? seleccionado.value
        : "Solo alojamiento";
}

const personasResumen =
    document.getElementById("personas_resumen");

const planResumen =
    document.getElementById("plan_resumen");

const subtotalHabitacion =
    document.getElementById("subtotal_habitacion");

const totalDesayuno =
    document.getElementById("total_desayuno");

const precioNoche =
    <?php
    echo json_encode(
        $precioNocheVista
    );
    ?>;

const precioDesayuno =
    <?php
    echo json_encode(
        $precioDesayuno
    );
    ?>;

function sumarUnDia(fechaTexto) {
    const fecha =
        new Date(fechaTexto + "T00:00:00");

    fecha.setDate(fecha.getDate() + 1);

    return fecha
        .toISOString()
        .slice(0, 10);
}

function calcularTotal() {
    const personas =
        parseInt(numeroPersonas.value, 10) || 1;

    const plan =
        obtenerPlanAlimentacion();

    personasResumen.textContent =
        personas;

    planResumen.textContent =
        plan;

    if (
        fechaEntrada.value === "" ||
        fechaSalida.value === ""
    ) {
        noches.textContent = "0";
        subtotalHabitacion.textContent = "$0.00";
        totalDesayuno.textContent = "$0.00";
        totalEstimado.textContent = "$0.00";
        return;
    }

    const entrada =
        new Date(fechaEntrada.value + "T00:00:00");

    const salida =
        new Date(fechaSalida.value + "T00:00:00");

    const diferencia =
        salida.getTime() - entrada.getTime();

    const cantidad =
        Math.round(
            diferencia / (1000 * 60 * 60 * 24)
        );

    if (cantidad > 0) {
        const subtotal =
            cantidad * precioNoche;

        const desayuno =
            plan === "Alojamiento con desayuno"
                ? cantidad * personas * precioDesayuno
                : 0;

        const total =
            subtotal + desayuno;

        noches.textContent =
            cantidad;

        subtotalHabitacion.textContent =
            "$" + subtotal.toFixed(2);

        totalDesayuno.textContent =
            "$" + desayuno.toFixed(2);

        totalEstimado.textContent =
            "$" + total.toFixed(2);

        fechaSalida.setCustomValidity("");
    } else {
        noches.textContent = "0";
        subtotalHabitacion.textContent = "$0.00";
        totalDesayuno.textContent = "$0.00";
        totalEstimado.textContent = "$0.00";

        fechaSalida.setCustomValidity(
            "La fecha de salida debe ser posterior."
        );
    }
}

fechaEntrada.addEventListener(
    "change",
    function () {
        if (fechaEntrada.value !== "") {
            const minimoSalida =
                sumarUnDia(fechaEntrada.value);

            fechaSalida.min =
                minimoSalida;

            if (
                fechaSalida.value !== "" &&
                fechaSalida.value < minimoSalida
            ) {
                fechaSalida.value = "";
            }
        }

        calcularTotal();
    }
);

fechaSalida.addEventListener(
    "change",
    calcularTotal
);

numeroPersonas.addEventListener(
    "change",
    calcularTotal
);

opcionesPlan.forEach(
    function (opcion) {
        opcion.addEventListener(
            "change",
            calcularTotal
        );
    }
);

if (fechaEntrada.value !== "") {
    fechaSalida.min =
        sumarUnDia(fechaEntrada.value);
}

calcularTotal();
</script>

</body>

</html>