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

function tarjetaValidaLuhn($numero)
{
    $numero = preg_replace("/\D/", "", (string) $numero);

    if (
        strlen($numero) < 13 ||
        strlen($numero) > 19
    ) {
        return false;
    }

    $suma = 0;
    $duplicar = false;

    for ($i = strlen($numero) - 1; $i >= 0; $i--) {
        $digito = (int) $numero[$i];

        if ($duplicar) {
            $digito *= 2;

            if ($digito > 9) {
                $digito -= 9;
            }
        }

        $suma += $digito;
        $duplicar = !$duplicar;
    }

    return $suma % 10 === 0;
}

function fechaTarjetaVigente($fecha)
{
    if (
        !preg_match(
            '/^(0[1-9]|1[0-2])\/([0-9]{2})$/',
            (string) $fecha,
            $coincidencias
        )
    ) {
        return false;
    }

    $mes = (int) $coincidencias[1];
    $anio = 2000 + (int) $coincidencias[2];

    try {
        $ultimoDiaMes = new DateTimeImmutable(
            sprintf(
                "%04d-%02d-01 23:59:59",
                $anio,
                $mes
            )
        );

        $ultimoDiaMes =
            $ultimoDiaMes->modify("last day of this month");

        return $ultimoDiaMes >= new DateTimeImmutable();
    } catch (Throwable $excepcion) {
        return false;
    }
}

function comprobanteFirebaseValido($url)
{
    $url = trim((string) $url);

    if (
        $url === "" ||
        strlen($url) > 2048 ||
        !filter_var($url, FILTER_VALIDATE_URL)
    ) {
        return false;
    }

    $esquema = strtolower(
        (string) parse_url($url, PHP_URL_SCHEME)
    );

    $host = strtolower(
        (string) parse_url($url, PHP_URL_HOST)
    );

    $hostsPermitidos = [
        "firebasestorage.googleapis.com",
        "storage.googleapis.com"
    ];

    return
        $esquema === "https" &&
        in_array($host, $hostsPermitidos, true);
}

function guardarIntentoPago(
    $conn,
    $idReserva,
    $metodoPago,
    $monto,
    $estadoPago,
    $comprobante,
    $numeroComprobante,
    $observacion,
    $ultimoPago
) {
    $insertarPago = mysqli_prepare(
        $conn,
        "INSERT INTO pagos
            (
                id_reserva,
                metodo_pago,
                monto,
                estado_pago,
                comprobante,
                numero_comprobante,
                fecha_pago,
                observacion
            )
         VALUES
            (
                ?, ?, ?, ?, ?, ?,
                CURRENT_TIMESTAMP, ?
            )"
    );

    if (!$insertarPago) {
        throw new Exception(
            "No se pudo preparar el registro del pago."
        );
    }

    mysqli_stmt_bind_param(
        $insertarPago,
        "isdssss",
        $idReserva,
        $metodoPago,
        $monto,
        $estadoPago,
        $comprobante,
        $numeroComprobante,
        $observacion
    );

    if (mysqli_stmt_execute($insertarPago)) {
        mysqli_stmt_close($insertarPago);
        return;
    }

    $codigoError =
        mysqli_stmt_errno($insertarPago);

    mysqli_stmt_close($insertarPago);

    if (
        $codigoError === 1062 &&
        $ultimoPago !== null &&
        ($ultimoPago["estado_pago"] ?? "") === "Rechazado"
    ) {
        $idPagoAnterior =
            (int) $ultimoPago["id_pago"];

        $actualizarPago = mysqli_prepare(
            $conn,
            "UPDATE pagos
             SET
                metodo_pago = ?,
                monto = ?,
                estado_pago = ?,
                comprobante = ?,
                numero_comprobante = ?,
                fecha_pago = CURRENT_TIMESTAMP,
                observacion = ?
             WHERE id_pago = ?
               AND id_reserva = ?
               AND estado_pago = 'Rechazado'"
        );

        if (!$actualizarPago) {
            throw new Exception(
                "No se pudo preparar el nuevo intento."
            );
        }

        mysqli_stmt_bind_param(
            $actualizarPago,
            "sdssssii",
            $metodoPago,
            $monto,
            $estadoPago,
            $comprobante,
            $numeroComprobante,
            $observacion,
            $idPagoAnterior,
            $idReserva
        );

        if (!mysqli_stmt_execute($actualizarPago)) {
            mysqli_stmt_close($actualizarPago);

            throw new Exception(
                "No se pudo registrar el nuevo intento."
            );
        }

        $filasActualizadas =
            mysqli_stmt_affected_rows($actualizarPago);

        mysqli_stmt_close($actualizarPago);

        if ($filasActualizadas !== 1) {
            throw new Exception(
                "El pago cambió mientras se procesaba."
            );
        }

        return;
    }

    throw new Exception(
        "No se pudo guardar el pago."
    );
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

if ($idUsuario <= 0) {
    session_unset();
    session_destroy();

    header(
        "Location: ../login.php?mensaje=sesion_invalida"
    );
    exit();
}

if (
    !isset($_GET["id"]) ||
    !filter_var($_GET["id"], FILTER_VALIDATE_INT)
) {
    header("Location: mis_reservas.php");
    exit();
}

$idReserva = (int) $_GET["id"];

if (empty($_SESSION["csrf_pago"])) {
    $_SESSION["csrf_pago"] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_pago"];
$errores = [];

$metodoPago =
    trim((string) ($_POST["metodo_pago"] ?? ""));

$comprobanteUrl =
    trim((string) ($_POST["comprobante_url"] ?? ""));

$numeroComprobante =
    trim((string) ($_POST["numero_comprobante"] ?? ""));

$bancoTransferencia = "Banco Pichincha";
$tipoCuentaTransferencia = "Corriente";
$numeroCuentaTransferencia = "2205948173";
$titularTransferencia = "Anthony Yanchapaxi";
$cedulaTitularTransferencia = "1712345678";
$conceptoTransferencia =
    "Reserva de habitación Hotel Las 3 Palmeras - Reserva #" .
    $idReserva;

$consulta = mysqli_prepare(
    $conn,
    "SELECT
        r.id_reserva,
        r.fecha_entrada,
        r.fecha_salida,
        r.estado AS estado_reserva,
        r.total,

        c.id_cliente,
        c.nombres,
        c.apellidos,

        h.numero,
        h.tipo,
        h.capacidad,
        h.imagen,

        p.id_pago,
        p.estado_pago,
        p.observacion

     FROM reservas r

     INNER JOIN clientes c
        ON c.id_cliente = r.id_cliente

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

     WHERE r.id_reserva = ?
       AND c.id_usuario = ?

     LIMIT 1"
);

if (!$consulta) {
    header("Location: mis_reservas.php");
    exit();
}

mysqli_stmt_bind_param(
    $consulta,
    "ii",
    $idReserva,
    $idUsuario
);

mysqli_stmt_execute($consulta);

$resultado =
    mysqli_stmt_get_result($consulta);

$reserva =
    mysqli_fetch_assoc($resultado);

mysqli_stmt_close($consulta);

if (!$reserva) {
    header("Location: mis_reservas.php");
    exit();
}

$nombreCliente = trim(
    (string) $reserva["nombres"] .
    " " .
    (string) $reserva["apellidos"]
);

if ($nombreCliente === "") {
    $nombreCliente =
        (string) $_SESSION["usuario"];
}

$idCliente = (int) $reserva["id_cliente"];

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

if ($reserva["estado_reserva"] !== "Pendiente") {
    header(
        "Location: mis_reservas.php?mensaje=reserva_no_pagable"
    );
    exit();
}

if (
    !empty($reserva["id_pago"]) &&
    in_array(
        $reserva["estado_pago"],
        ["Pendiente", "Aceptado"],
        true
    )
) {
    header(
        "Location: mis_reservas.php?mensaje=pago_existente"
    );
    exit();
}

$rutaImagen =
    resolverImagen(
        $reserva["imagen"] ?? "",
        "habitaciones",
        "../img/hotel.jpg"
    );

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfRecibido =
        $_POST["csrf"] ?? "";

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $errores[] =
            "La solicitud no es válida. Actualiza la página.";
    }

    if (
        !in_array(
            $metodoPago,
            ["Tarjeta", "Transferencia"],
            true
        )
    ) {
        $errores[] =
            "Seleccione un método de pago válido.";
    }

    $titular = "";
    $numeroTarjeta = "";
    $fechaVencimiento = "";
    $codigoSeguridad = "";

    if ($metodoPago === "Tarjeta") {
        $titular =
            trim((string) ($_POST["titular"] ?? ""));

        $numeroTarjeta = preg_replace(
            "/\D/",
            "",
            (string) ($_POST["numero_tarjeta"] ?? "")
        );

        $fechaVencimiento =
            trim(
                (string) ($_POST["fecha_vencimiento"] ?? "")
            );

        $codigoSeguridad = preg_replace(
            "/\D/",
            "",
            (string) ($_POST["codigo_seguridad"] ?? "")
        );

        if ($titular === "") {
            $errores[] =
                "Ingrese el nombre del titular.";
        } elseif (strlen($titular) > 120) {
            $errores[] =
                "El nombre del titular es demasiado largo.";
        }

        if (!tarjetaValidaLuhn($numeroTarjeta)) {
            $errores[] =
                "Ingrese un número de tarjeta válido.";
        }

        if (!fechaTarjetaVigente($fechaVencimiento)) {
            $errores[] =
                "La fecha de vencimiento no es válida o ya pasó.";
        }

        if (
            strlen($codigoSeguridad) < 3 ||
            strlen($codigoSeguridad) > 4
        ) {
            $errores[] =
                "Ingrese un código de seguridad válido.";
        }
    }

    if ($metodoPago === "Transferencia") {
        if ($numeroComprobante === "") {
            $errores[] =
                "Ingrese el número del comprobante.";
        } elseif (
            !preg_match(
                "/^[0-9]{10}$/",
                $numeroComprobante
            )
        ) {
            $errores[] =
                "El número del comprobante debe tener exactamente 10 números.";
        }

        if (!comprobanteFirebaseValido($comprobanteUrl)) {
            $errores[] =
                "Debe seleccionar y subir un comprobante válido.";
        }
    }

    if (empty($errores)) {
        mysqli_begin_transaction($conn);

        try {
            $bloquearReserva = mysqli_prepare(
                $conn,
                "SELECT
                    r.id_reserva,
                    r.estado,
                    r.total
                 FROM reservas r
                 INNER JOIN clientes c
                    ON c.id_cliente = r.id_cliente
                 WHERE r.id_reserva = ?
                   AND c.id_usuario = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$bloquearReserva) {
                throw new Exception(
                    "No se pudo validar la reserva."
                );
            }

            mysqli_stmt_bind_param(
                $bloquearReserva,
                "ii",
                $idReserva,
                $idUsuario
            );

            mysqli_stmt_execute($bloquearReserva);

            $resultadoBloqueo =
                mysqli_stmt_get_result($bloquearReserva);

            $reservaBloqueada =
                mysqli_fetch_assoc($resultadoBloqueo);

            mysqli_stmt_close($bloquearReserva);

            if (!$reservaBloqueada) {
                throw new Exception(
                    "La reserva no pertenece al cliente."
                );
            }

            if ($reservaBloqueada["estado"] !== "Pendiente") {
                throw new Exception(
                    "La reserva ya no admite pagos."
                );
            }

            $consultarPagos = mysqli_prepare(
                $conn,
                "SELECT
                    id_pago,
                    estado_pago
                 FROM pagos
                 WHERE id_reserva = ?
                 ORDER BY id_pago DESC
                 FOR UPDATE"
            );

            if (!$consultarPagos) {
                throw new Exception(
                    "No se pudo revisar los pagos anteriores."
                );
            }

            mysqli_stmt_bind_param(
                $consultarPagos,
                "i",
                $idReserva
            );

            mysqli_stmt_execute($consultarPagos);

            $resultadoPagos =
                mysqli_stmt_get_result($consultarPagos);

            $ultimoPago = null;
            $existePagoActivo = false;

            while (
                $pagoAnterior =
                    mysqli_fetch_assoc($resultadoPagos)
            ) {
                if ($ultimoPago === null) {
                    $ultimoPago = $pagoAnterior;
                }

                if (
                    in_array(
                        $pagoAnterior["estado_pago"],
                        ["Pendiente", "Aceptado"],
                        true
                    )
                ) {
                    $existePagoActivo = true;
                }
            }

            mysqli_stmt_close($consultarPagos);

            if ($existePagoActivo) {
                throw new Exception(
                    "La reserva ya tiene un pago pendiente o aceptado."
                );
            }

            $monto =
                (float) $reservaBloqueada["total"];

            if ($monto <= 0) {
                throw new Exception(
                    "El total de la reserva no es válido."
                );
            }

            if ($metodoPago === "Tarjeta") {
                $estadoPago = "Aceptado";
                $comprobante = null;
                $numeroComprobantePago = null;

                $observacion =
                    "Pago con tarjeta aprobado en modo demostración.";

                guardarIntentoPago(
                    $conn,
                    $idReserva,
                    $metodoPago,
                    $monto,
                    $estadoPago,
                    $comprobante,
                    $numeroComprobantePago,
                    $observacion,
                    $ultimoPago
                );

                $confirmarReserva = mysqli_prepare(
                    $conn,
                    "UPDATE reservas
                     SET estado = 'Confirmada'
                     WHERE id_reserva = ?
                       AND estado = 'Pendiente'"
                );

                if (!$confirmarReserva) {
                    throw new Exception(
                        "No se pudo preparar la confirmación."
                    );
                }

                mysqli_stmt_bind_param(
                    $confirmarReserva,
                    "i",
                    $idReserva
                );

                if (!mysqli_stmt_execute($confirmarReserva)) {
                    mysqli_stmt_close($confirmarReserva);

                    throw new Exception(
                        "No se pudo confirmar la reserva."
                    );
                }

                $filasConfirmadas =
                    mysqli_stmt_affected_rows($confirmarReserva);

                mysqli_stmt_close($confirmarReserva);

                if ($filasConfirmadas !== 1) {
                    throw new Exception(
                        "La reserva cambió mientras se procesaba."
                    );
                }

                mysqli_commit($conn);

                $_SESSION["csrf_pago"] =
                    bin2hex(random_bytes(32));

                header(
                    "Location: mis_reservas.php?mensaje=pago_aceptado"
                );
                exit();
            }

            if ($metodoPago === "Transferencia") {
                $estadoPago = "Pendiente";
                $observacion = null;

                guardarIntentoPago(
                    $conn,
                    $idReserva,
                    $metodoPago,
                    $monto,
                    $estadoPago,
                    $comprobanteUrl,
                    $numeroComprobante,
                    $observacion,
                    $ultimoPago
                );

                mysqli_commit($conn);

                $_SESSION["csrf_pago"] =
                    bin2hex(random_bytes(32));

                header(
                    "Location: mis_reservas.php?mensaje=transferencia_pendiente"
                );
                exit();
            }

            throw new Exception(
                "El método de pago no es válido."
            );
        } catch (Throwable $excepcion) {
            mysqli_rollback($conn);

            $errores[] =
                $excepcion->getMessage() !== ""
                    ? $excepcion->getMessage()
                    : "No se pudo completar el pago.";
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
        Realizar pago - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=56"
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
                    rgba(10, 29, 20, .58)
                ),
                url("../img/hotel.jpg") center/cover;
        }

        .pagina-hero-contenido {
            max-width: 760px;
            padding: 58px 0;
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
            font-size: clamp(2.7rem, 6vw, 4.7rem);
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

        .mensaje-error,
        .mensaje-aviso {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
            padding: 14px 17px;
            border-radius: 6px;
            font-size: 13px;
        }

        .mensaje-error {
            border: 1px solid #edc8c8;
            background: #fff1f1;
            color: #9b3131;
        }

        .mensaje-aviso {
            border: 1px solid #ead79f;
            background: #fff8df;
            color: #765a18;
        }

        .pago-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            box-shadow: var(--sombra);
        }

        .imagen-columna {
            position: relative;
            min-height: 100%;
            background: #e9ece8;
        }

        .imagen-habitacion {
            width: 100%;
            height: 100%;
            min-height: 710px;
            object-fit: cover;
        }

        .reserva-badge {
            position: absolute;
            top: 20px;
            left: 20px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(20, 50, 33, .92);
            color: white;
            font-size: 10px;
            font-weight: 900;
        }

        .formulario-columna {
            padding: 34px;
        }

        .formulario-columna h2 {
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
        }

        .resumen {
            margin: 23px 0;
            padding: 18px;
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

        .dato-resumen strong {
            color: #303731;
        }

        .total {
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 31px;
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

        .metodo-pago-seccion {
            padding: 22px;
            border: 1px solid #e0e4df;
            border-radius: 14px;
            background:
                linear-gradient(
                    180deg,
                    #ffffff 0%,
                    #fafbf9 100%
                );
            box-shadow:
                0 10px 28px
                rgba(23, 51, 37, .07);
        }

        .metodo-pago-encabezado {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 18px;
        }

        .metodo-pago-etiqueta {
            display: block;
            margin-bottom: 5px;
            color: #9b7739;
            font-size: 9px;
            font-weight: 900;
            letter-spacing: 1.7px;
        }

        .metodo-pago-encabezado h3 {
            margin: 0 0 5px;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 21px;
            font-weight: 700;
        }

        .metodo-pago-encabezado p {
            margin: 0;
            color: var(--texto-suave);
            font-size: 12px;
            line-height: 1.55;
        }

        .metodo-pago-seguro {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border: 1px solid #cfe0d4;
            border-radius: 999px;
            background: #edf6ef;
            color: var(--verde);
            font-size: 10px;
            font-weight: 900;
            white-space: nowrap;
        }

        .metodo-opciones {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .metodo-opcion {
            position: relative;
        }

        .metodo-radio {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .metodo-card {
            min-height: 106px;
            display: flex;
            align-items: center;
            gap: 13px;
            position: relative;
            margin: 0;
            padding: 17px 42px 17px 16px;
            border: 1px solid #dfe4df;
            border-radius: 11px;
            background: white;
            cursor: pointer;
            transition:
                border-color .2s ease,
                box-shadow .2s ease,
                transform .2s ease,
                background-color .2s ease;
        }

        .metodo-card:hover {
            border-color: #a9bbae;
            transform: translateY(-1px);
            box-shadow:
                0 9px 20px
                rgba(23, 51, 37, .08);
        }

        .metodo-icono {
            width: 44px;
            height: 44px;
            display: grid;
            place-items: center;
            flex: 0 0 44px;
            border-radius: 10px;
            background: var(--verde-claro);
            color: var(--verde);
            font-size: 20px;
        }

        .metodo-contenido {
            min-width: 0;
        }

        .metodo-contenido strong {
            display: block;
            margin-bottom: 4px;
            color: var(--verde-oscuro);
            font-size: 13px;
            font-weight: 900;
        }

        .metodo-contenido small {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            line-height: 1.5;
        }

        .metodo-check {
            position: absolute;
            top: 13px;
            right: 13px;
            color: #c7cec9;
            font-size: 17px;
            transition: color .2s ease;
        }

        .metodo-radio:checked + .metodo-card {
            border-color: var(--verde);
            background: #f4f8f5;
            box-shadow:
                0 0 0 3px
                rgba(36, 74, 53, .08);
        }

        .metodo-radio:checked
        + .metodo-card
        .metodo-icono {
            background: var(--verde);
            color: white;
        }

        .metodo-radio:checked
        + .metodo-card
        .metodo-check {
            color: var(--verde);
        }

        .metodo-radio:focus-visible
        + .metodo-card {
            outline: 3px solid
                rgba(36, 74, 53, .18);
            outline-offset: 2px;
        }

        .metodo-box {
            padding: 20px;
            border: 1px solid #e0e3de;
            border-radius: 8px;
            background: #fafbf9;
        }

        .metodo-box h3 {
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 20px;
        }

        .datos-bancarios {
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid #d9dfd9;
            border-radius: 8px;
            background: white;
        }

        .datos-bancarios-encabezado {
            padding: 13px 16px;
            background: var(--verde);
            color: white;
            font-size: 12px;
            font-weight: 900;
        }

        .dato-bancario {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            padding: 11px 16px;
            border-bottom: 1px solid #e5e8e4;
            font-size: 12px;
        }

        .dato-bancario:last-child {
            border-bottom: 0;
        }

        .dato-bancario span {
            color: var(--texto-suave);
        }

        .dato-bancario strong {
            color: var(--verde-oscuro);
            text-align: right;
            word-break: break-word;
        }

        .btn-pagar {
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

        .btn-pagar:hover {
            background: var(--verde-oscuro);
            color: white;
        }

        .btn-volver {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--verde);
            font-size: 13px;
            font-weight: 900;
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
            .imagen-habitacion {
                min-height: 330px;
                height: 330px;
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

            .formulario-columna {
                padding: 23px;
            }

            .dato-resumen {
                display: block;
            }

            .metodo-pago-seccion {
                padding: 18px;
            }

            .metodo-pago-encabezado {
                display: block;
            }

            .metodo-pago-seguro {
                margin-top: 12px;
            }

            .metodo-opciones {
                grid-template-columns: 1fr;
            }

            .metodo-card {
                min-height: 94px;
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

<nav class="navbar navbar-dark navbar-hotel fixed-top">
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

        <div class="d-flex align-items-center gap-3">

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

            <div class="usuario-navbar d-none d-sm-block">
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
</nav>

<section class="pagina-hero">
    <div class="container">
        <div class="pagina-hero-contenido">

            <div class="pagina-etiqueta">
                PAGO DE RESERVA
            </div>

            <h1>Completa tu pago</h1>

            <p>
                Selecciona el método de pago. Las tarjetas se procesan
                únicamente como una simulación académica y las
                transferencias quedan pendientes de revisión.
            </p>

        </div>
    </div>
</section>

<main class="contenido-pagina">
    <div class="container">

        <a
            href="mis_reservas.php"
            class="btn-volver mb-4"
        >
            <i class="bi bi-arrow-left"></i>
            Volver a mis reservas
        </a>

        <?php if (!empty($errores)) { ?>

            <div class="mensaje-error">

                <i class="bi bi-exclamation-triangle"></i>

                <div>
                    <strong>Revisa la información:</strong>

                    <ul class="mt-2 mb-0">

                        <?php foreach ($errores as $error) { ?>

                            <li><?php echo h($error); ?></li>

                        <?php } ?>

                    </ul>
                </div>

            </div>

        <?php } ?>

        <?php if (
            !empty($reserva["id_pago"]) &&
            $reserva["estado_pago"] === "Rechazado"
        ) { ?>

            <div class="mensaje-aviso">

                <i class="bi bi-exclamation-circle"></i>

                <div>
                    <strong>El pago anterior fue rechazado.</strong>

                    <?php if (
                        trim((string) $reserva["observacion"]) !== ""
                    ) { ?>

                        <div class="mt-1">
                            Motivo:
                            <?php echo h($reserva["observacion"]); ?>
                        </div>

                    <?php } ?>

                    <div class="mt-1">
                        Puedes registrar un nuevo intento.
                    </div>
                </div>

            </div>

        <?php } ?>

        <section class="pago-card">

            <div class="row g-0">

                <div class="col-lg-5">

                    <div class="imagen-columna h-100">

                        <img
                            src="<?php echo h($rutaImagen); ?>"
                            alt="Habitación <?php echo h($reserva["numero"]); ?>"
                            class="imagen-habitacion"
                            onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                        >

                        <span class="reserva-badge">
                            RESERVA #<?php echo $idReserva; ?>
                        </span>

                    </div>

                </div>

                <div class="col-lg-7">

                    <div class="formulario-columna">

                        <div class="pagina-etiqueta text-success">
                            HABITACIÓN <?php echo h($reserva["numero"]); ?>
                        </div>

                        <h2 class="mt-2 mb-1">
                            <?php echo h($reserva["tipo"]); ?>
                        </h2>

                        <div class="text-muted small">
                            Capacidad:
                            <?php echo (int) $reserva["capacidad"]; ?>
                            persona(s)
                        </div>

                        <div class="resumen">

                            <div class="dato-resumen">
                                <span>Entrada</span>

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

                            <div class="dato-resumen">
                                <span>Salida</span>

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

                            <div class="dato-resumen align-items-center">
                                <span>Total de la reserva</span>

                                <strong class="total">
                                    $<?php
                                    echo number_format(
                                        (float) $reserva["total"],
                                        2
                                    );
                                    ?>
                                </strong>
                            </div>

                        </div>

                        <form
                            method="POST"
                            id="formPago"
                            action="pagar.php?id=<?php echo $idReserva; ?>"
                            autocomplete="off"
                        >

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?php echo h($csrf); ?>"
                            >

                            <div class="metodo-pago-seccion mb-4">

                                <div class="metodo-pago-encabezado">
                                    <div>
                                        <span class="metodo-pago-etiqueta">
                                            FORMA DE PAGO
                                        </span>

                                        <h3>
                                            Selecciona tu método de pago
                                        </h3>

                                        <p>
                                            Escoge una opción para continuar
                                            con el pago de la reserva.
                                        </p>
                                    </div>

                                    <div class="metodo-pago-seguro">
                                        <i class="bi bi-shield-check"></i>
                                        Proceso protegido
                                    </div>
                                </div>

                                <div class="metodo-opciones">

                                    <div class="metodo-opcion">

                                        <input
                                            type="radio"
                                            name="metodo_pago"
                                            id="metodo_tarjeta"
                                            value="Tarjeta"
                                            class="metodo-radio"
                                            <?php
                                            echo $metodoPago === "Tarjeta"
                                                ? "checked"
                                                : "";
                                            ?>
                                            required
                                        >

                                        <label
                                            for="metodo_tarjeta"
                                            class="metodo-card"
                                        >

                                            <span class="metodo-icono">
                                                <i class="bi bi-credit-card-2-front"></i>
                                            </span>

                                            <span class="metodo-contenido">
                                                <strong>Tarjeta</strong>

                                                <small>
                                                    Ingresa los datos de tu
                                                    tarjeta para completar
                                                    el pago.
                                                </small>
                                            </span>

                                            <span class="metodo-check">
                                                <i class="bi bi-check-circle-fill"></i>
                                            </span>

                                        </label>

                                    </div>

                                    <div class="metodo-opcion">

                                        <input
                                            type="radio"
                                            name="metodo_pago"
                                            id="metodo_transferencia"
                                            value="Transferencia"
                                            class="metodo-radio"
                                            <?php
                                            echo $metodoPago === "Transferencia"
                                                ? "checked"
                                                : "";
                                            ?>
                                            required
                                        >

                                        <label
                                            for="metodo_transferencia"
                                            class="metodo-card"
                                        >

                                            <span class="metodo-icono">
                                                <i class="bi bi-bank"></i>
                                            </span>

                                            <span class="metodo-contenido">
                                                <strong>
                                                    Transferencia bancaria
                                                </strong>

                                                <small>
                                                    Realiza la transferencia
                                                    y sube el comprobante
                                                    para revisión.
                                                </small>
                                            </span>

                                            <span class="metodo-check">
                                                <i class="bi bi-check-circle-fill"></i>
                                            </span>

                                        </label>

                                    </div>

                                </div>

                            </div>

                            <div
                                id="camposTarjeta"
                                class="metodo-box mb-4 d-none"
                            >

                                <h3 class="mb-3">
                                    Datos de la tarjeta
                                </h3>

                                <div class="mb-3">

                                    <label
                                        for="titular"
                                        class="form-label"
                                    >
                                        Nombre del titular
                                    </label>

                                    <input
                                        type="text"
                                        name="titular"
                                        id="titular"
                                        class="form-control"
                                        maxlength="120"
                                        value="<?php
                                        echo h(
                                            $_POST["titular"] ?? ""
                                        );
                                        ?>"
                                    >

                                </div>

                                <div class="mb-3">

                                    <label
                                        for="numero_tarjeta"
                                        class="form-label"
                                    >
                                        Número de tarjeta
                                    </label>

                                    <input
                                        type="text"
                                        name="numero_tarjeta"
                                        id="numero_tarjeta"
                                        class="form-control"
                                        maxlength="23"
                                        inputmode="numeric"
                                        placeholder="0000 0000 0000 0000"
                                    >

                                </div>

                                <div class="row g-3">

                                    <div class="col-md-6">

                                        <label
                                            for="fecha_vencimiento"
                                            class="form-label"
                                        >
                                            Vencimiento
                                        </label>

                                        <input
                                            type="text"
                                            name="fecha_vencimiento"
                                            id="fecha_vencimiento"
                                            class="form-control"
                                            maxlength="5"
                                            inputmode="numeric"
                                            placeholder="MM/AA"
                                        >

                                    </div>

                                    <div class="col-md-6">

                                        <label
                                            for="codigo_seguridad"
                                            class="form-label"
                                        >
                                            Código de seguridad
                                        </label>

                                        <input
                                            type="password"
                                            name="codigo_seguridad"
                                            id="codigo_seguridad"
                                            class="form-control"
                                            maxlength="4"
                                            inputmode="numeric"
                                            placeholder="CVV"
                                        >

                                    </div>

                                </div>

                                <div class="mensaje-aviso mt-3 mb-0">

                                    <i class="bi bi-shield-lock"></i>

                                    <div>
                                        Este pago es una simulación académica.
                                        El número, vencimiento y código de la
                                        tarjeta no se guardan en la base de datos.
                                    </div>

                                </div>

                            </div>

                            <div
                                id="camposTransferencia"
                                class="metodo-box mb-4 d-none"
                            >

                                <h3 class="mb-3">
                                    Transferencia bancaria
                                </h3>

                                <div class="datos-bancarios">

                                    <div class="datos-bancarios-encabezado">
                                        Datos para realizar la transferencia
                                    </div>

                                    <div class="dato-bancario">
                                        <span>Banco</span>
                                        <strong>
                                            <?php echo h($bancoTransferencia); ?>
                                        </strong>
                                    </div>

                                    <div class="dato-bancario">
                                        <span>Tipo de cuenta</span>
                                        <strong>
                                            <?php echo h($tipoCuentaTransferencia); ?>
                                        </strong>
                                    </div>

                                    <div class="dato-bancario">
                                        <span>Número de cuenta</span>
                                        <strong>
                                            <?php echo h($numeroCuentaTransferencia); ?>
                                        </strong>
                                    </div>

                                    <div class="dato-bancario">
                                        <span>Titular</span>
                                        <strong>
                                            <?php echo h($titularTransferencia); ?>
                                        </strong>
                                    </div>

                                    <div class="dato-bancario">
                                        <span>Cédula</span>
                                        <strong>
                                            <?php echo h($cedulaTitularTransferencia); ?>
                                        </strong>
                                    </div>

                                    <div class="dato-bancario">
                                        <span>Valor exacto</span>
                                        <strong>
                                            $<?php
                                            echo number_format(
                                                (float) $reserva["total"],
                                                2
                                            );
                                            ?>
                                        </strong>
                                    </div>

                                    <div class="dato-bancario">
                                        <span>Concepto o referencia</span>
                                        <strong>
                                            <?php echo h($conceptoTransferencia); ?>
                                        </strong>
                                    </div>

                                </div>

                                <div class="mensaje-aviso">

                                    <i class="bi bi-info-circle"></i>

                                    <div>
                                        Transfiere el valor completo. Después,
                                        escribe manualmente el número que aparece
                                        en el comprobante y sube la imagen o PDF.
                                        El Administrador comparará ambos datos.
                                    </div>

                                </div>

                                <div class="mb-3">

                                    <label
                                        for="numero_comprobante"
                                        class="form-label"
                                    >
                                        Número del comprobante
                                    </label>

                                    <input
                                        type="text"
                                        name="numero_comprobante"
                                        id="numero_comprobante"
                                        class="form-control"
                                        minlength="10"
                                        maxlength="10"
                                        inputmode="numeric"
                                        pattern="[0-9]{10}"
                                        autocomplete="off"
                                        value="<?php echo h($numeroComprobante); ?>"
                                        placeholder="Ejemplo: 4587123690"
                                    >

                                    <div class="text-muted small mt-2">
                                        Ingresa exactamente 10 números.
                                        No se permiten letras ni otros caracteres.
                                    </div>

                                </div>

                                <div class="mb-3">

                                    <label
                                        for="archivo_comprobante"
                                        class="form-label"
                                    >
                                        Subir comprobante
                                    </label>

                                    <input
                                        type="file"
                                        id="archivo_comprobante"
                                        class="form-control"
                                        accept=".jpg,.jpeg,.png,.webp,.pdf"
                                    >

                                    <div class="text-muted small mt-2">
                                        JPG, PNG, WEBP o PDF de máximo 5 MB.
                                    </div>

                                </div>

                                <input
                                    type="hidden"
                                    name="comprobante_url"
                                    id="comprobante_url"
                                    value="<?php echo h($comprobanteUrl); ?>"
                                >

                                <div
                                    id="mensajeSubida"
                                    class="small"
                                ></div>

                            </div>

                            <button
                                type="submit"
                                id="btnPagar"
                                class="btn-pagar w-100"
                            >
                                <i class="bi bi-credit-card"></i>
                                Registrar pago
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
                        Pago seguro en modo demostración.
                    </small>
                </div>

            </div>

            <a
                href="mis_reservas.php"
                class="btn btn-outline-light btn-sm"
            >
                Volver a mis reservas
            </a>

        </div>

    </div>

    <div class="footer-final">

        <div class="container d-flex justify-content-between flex-wrap gap-2">

            <span>
                Hotel Las 3 Palmeras © 2026
            </span>

            <span>
                Reserva #<?php echo $idReserva; ?>
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

    const nuevasVistas =
        Array.from(
            new Set([...vistas, ...idsActuales])
        );

    localStorage.setItem(
        claveNotificacionesPago,
        JSON.stringify(nuevasVistas)
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

<script type="module">

import {
    storage,
    ref,
    uploadBytes,
    getDownloadURL
} from "../js/firebase-config.js";

const formPago =
    document.getElementById("formPago");

const opcionesMetodoPago =
    document.querySelectorAll(
        'input[name="metodo_pago"]'
    );

function obtenerMetodoPago() {
    const seleccionado =
        document.querySelector(
            'input[name="metodo_pago"]:checked'
        );

    return seleccionado
        ? seleccionado.value
        : "";
}

const camposTarjeta =
    document.getElementById("camposTarjeta");

const camposTransferencia =
    document.getElementById("camposTransferencia");

const archivoComprobante =
    document.getElementById("archivo_comprobante");

const numeroComprobante =
    document.getElementById("numero_comprobante");

const comprobanteUrl =
    document.getElementById("comprobante_url");

const mensajeSubida =
    document.getElementById("mensajeSubida");

const btnPagar =
    document.getElementById("btnPagar");

const titular =
    document.getElementById("titular");

const numeroTarjeta =
    document.getElementById("numero_tarjeta");

const fechaVencimiento =
    document.getElementById("fecha_vencimiento");

const codigoSeguridad =
    document.getElementById("codigo_seguridad");

function mostrarMetodoPago() {
    const metodo = obtenerMetodoPago();

    camposTarjeta.classList.toggle(
        "d-none",
        metodo !== "Tarjeta"
    );

    camposTransferencia.classList.toggle(
        "d-none",
        metodo !== "Transferencia"
    );

    titular.required =
        metodo === "Tarjeta";

    numeroTarjeta.required =
        metodo === "Tarjeta";

    fechaVencimiento.required =
        metodo === "Tarjeta";

    codigoSeguridad.required =
        metodo === "Tarjeta";

    numeroComprobante.required =
        metodo === "Transferencia";

    archivoComprobante.required =
        metodo === "Transferencia" &&
        comprobanteUrl.value === "";

    if (
        metodo === "Transferencia" &&
        comprobanteUrl.value !== ""
    ) {
        mensajeSubida.className =
            "text-success small";

        mensajeSubida.textContent =
            "El comprobante ya fue subido.";
    }
}

opcionesMetodoPago.forEach(
    function (opcion) {
        opcion.addEventListener(
            "change",
            mostrarMetodoPago
        );
    }
);

numeroComprobante.addEventListener(
    "input",
    function () {
        numeroComprobante.value =
            numeroComprobante.value
                .replace(/\D/g, "")
                .slice(0, 10);
    }
);

numeroTarjeta.addEventListener(
    "input",
    function () {
        const numeros =
            numeroTarjeta.value
                .replace(/\D/g, "")
                .slice(0, 19);

        numeroTarjeta.value =
            numeros.match(/.{1,4}/g)?.join(" ") || "";
    }
);

fechaVencimiento.addEventListener(
    "input",
    function () {
        const numeros =
            fechaVencimiento.value
                .replace(/\D/g, "")
                .slice(0, 4);

        fechaVencimiento.value =
            numeros.length > 2
                ? numeros.slice(0, 2) +
                  "/" +
                  numeros.slice(2)
                : numeros;
    }
);

codigoSeguridad.addEventListener(
    "input",
    function () {
        codigoSeguridad.value =
            codigoSeguridad.value
                .replace(/\D/g, "")
                .slice(0, 4);
    }
);

archivoComprobante.addEventListener(
    "change",
    function () {
        comprobanteUrl.value = "";
        mensajeSubida.textContent = "";

        archivoComprobante.required =
            obtenerMetodoPago() === "Transferencia";
    }
);

formPago.addEventListener(
    "submit",
    async function (evento) {
        if (
            obtenerMetodoPago() !== "Transferencia" ||
            comprobanteUrl.value !== ""
        ) {
            return;
        }

        evento.preventDefault();

        const archivo =
            archivoComprobante.files[0];

        if (!archivo) {
            mensajeSubida.className =
                "text-danger small";

            mensajeSubida.textContent =
                "Seleccione un comprobante.";

            return;
        }

        const tiposPermitidos = [
            "image/jpeg",
            "image/png",
            "image/webp",
            "application/pdf"
        ];

        if (!tiposPermitidos.includes(archivo.type)) {
            mensajeSubida.className =
                "text-danger small";

            mensajeSubida.textContent =
                "El archivo seleccionado no es válido.";

            return;
        }

        const limite =
            5 * 1024 * 1024;

        if (archivo.size > limite) {
            mensajeSubida.className =
                "text-danger small";

            mensajeSubida.textContent =
                "El archivo supera los 5 MB.";

            return;
        }

        btnPagar.disabled = true;

        btnPagar.innerHTML =
            '<span class="spinner-border spinner-border-sm"></span>' +
            " Subiendo comprobante";

        mensajeSubida.className =
            "text-primary small";

        mensajeSubida.textContent =
            "Subiendo archivo a Firebase...";

        try {
            const nombreSeguro =
                archivo.name.replace(
                    /[^a-zA-Z0-9._-]/g,
                    "_"
                );

            const identificador =
                window.crypto?.randomUUID
                    ? window.crypto.randomUUID()
                    : Date.now().toString();

            const ruta =
                "comprobantes/reserva_<?php echo $idReserva; ?>/" +
                identificador +
                "_" +
                nombreSeguro;

            const referencia =
                ref(storage, ruta);

            await uploadBytes(
                referencia,
                archivo,
                {
                    contentType: archivo.type
                }
            );

            const url =
                await getDownloadURL(referencia);

            comprobanteUrl.value = url;

            archivoComprobante.required = false;

            mensajeSubida.className =
                "text-success small";

            mensajeSubida.textContent =
                "Comprobante subido correctamente.";

            formPago.submit();
        } catch (error) {
            console.error(error);

            mensajeSubida.className =
                "text-danger small";

            mensajeSubida.textContent =
                "No se pudo subir el comprobante.";

            btnPagar.disabled = false;

            btnPagar.innerHTML =
                '<i class="bi bi-credit-card"></i> Registrar pago';
        }
    }
);

mostrarMetodoPago();

</script>

</body>

</html>