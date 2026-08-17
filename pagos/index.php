<?php
session_start();

include("../config/conexion.php");

if (!isset($_SESSION["usuario"], $_SESSION["rol"])) {
    header("Location: ../login.php");
    exit();
}

$rolActual = strtolower(trim((string) $_SESSION["rol"]));

if ($rolActual !== "administrador") {
    header("Location: ../dashboard.php");
    exit();
}

function h($texto)
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

function urlComprobanteSegura($url)
{
    $url = trim((string) $url);

    if ($url === "") {
        return "";
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return "";
    }

    $esquema = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    if (!in_array($esquema, ["http", "https"], true)) {
        return "";
    }

    return $url;
}

function urlPagos(
    int $cliente,
    string $estado = "Todos",
    int $pagina = 1,
    string $mensaje = ""
): string {
    $parametros = [
        "cliente" => $cliente,
        "estado" => $estado,
        "pagina" => max(1, $pagina)
    ];

    if ($mensaje !== "") {
        $parametros["mensaje"] = $mensaje;
    }

    return "index.php?" . http_build_query($parametros) . "#pagosRegistrados";
}

$estadosFiltroPermitidos = [
    "Todos",
    "Pendiente",
    "Aceptado",
    "Rechazado"
];

if (empty($_SESSION["csrf_pagos"])) {
    $_SESSION["csrf_pagos"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_pagos"];
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfRecibido = $_POST["csrf"] ?? "";
    $idPago = (int) ($_POST["id_pago"] ?? 0);
    $accion = trim((string) ($_POST["accion"] ?? ""));
    $observacion = trim((string) ($_POST["observacion"] ?? ""));

    $clienteRetorno =
        max(0, (int) ($_POST["filtro_cliente"] ?? 0));

    $estadoRetorno =
        trim((string) ($_POST["filtro_estado"] ?? "Todos"));

    if (
        !in_array(
            $estadoRetorno,
            $estadosFiltroPermitidos,
            true
        )
    ) {
        $estadoRetorno = "Todos";
    }

    $paginaRetorno =
        max(1, (int) ($_POST["filtro_pagina"] ?? 1));

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $error =
            "La solicitud no es válida. Actualiza la página.";
    } elseif ($idPago <= 0) {
        $error =
            "El pago seleccionado no es válido.";
    } elseif (
        !in_array(
            $accion,
            ["aceptar", "rechazar"],
            true
        )
    ) {
        $error =
            "La acción seleccionada no es válida.";
    } elseif (
        $accion === "rechazar" &&
        $observacion === ""
    ) {
        $error =
            "Escriba el motivo por el cual rechaza el pago.";
    } elseif (mb_strlen($observacion) > 255) {
        $error =
            "La observación no puede superar los 255 caracteres.";
    }

    if ($error === "") {
        mysqli_begin_transaction($conn);

        try {
            $buscarPago = mysqli_prepare(
                $conn,
                "SELECT
                    p.id_pago,
                    p.id_reserva,
                    p.monto,
                    p.estado_pago,
                    p.metodo_pago,
                    p.comprobante,
                    p.numero_comprobante,
                    r.id_cliente,
                    r.total AS total_reserva,
                    r.estado AS estado_reserva
                 FROM pagos p
                 INNER JOIN reservas r
                    ON r.id_reserva = p.id_reserva
                 WHERE p.id_pago = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$buscarPago) {
                throw new Exception(
                    "No se pudo consultar el pago."
                );
            }

            mysqli_stmt_bind_param(
                $buscarPago,
                "i",
                $idPago
            );

            mysqli_stmt_execute($buscarPago);

            $resultadoPago =
                mysqli_stmt_get_result($buscarPago);

            $pagoSeleccionado =
                mysqli_fetch_assoc($resultadoPago);

            mysqli_stmt_close($buscarPago);

            if (!$pagoSeleccionado) {
                throw new Exception(
                    "El pago seleccionado no existe."
                );
            }

            if (
                trim((string) $pagoSeleccionado["estado_pago"]) !==
                "Pendiente"
            ) {
                throw new Exception(
                    "Este pago ya fue revisado anteriormente."
                );
            }

            $idReserva =
                (int) $pagoSeleccionado["id_reserva"];

            $monto =
                (float) $pagoSeleccionado["monto"];

            $totalReserva =
                (float) $pagoSeleccionado["total_reserva"];

            $estadoReserva =
                trim((string) $pagoSeleccionado["estado_reserva"]);

            if ($accion === "aceptar") {
                if (
                    in_array(
                        $estadoReserva,
                        ["Cancelada", "Finalizada"],
                        true
                    )
                ) {
                    throw new Exception(
                        "No se puede aceptar un pago de una reserva " .
                        strtolower($estadoReserva) . "."
                    );
                }

                if (abs($monto - $totalReserva) > 0.01) {
                    throw new Exception(
                        "El monto pagado no coincide con el total de la reserva."
                    );
                }

                if (
                    $pagoSeleccionado["metodo_pago"] ===
                    "Transferencia"
                ) {
                    if (
                        trim(
                            (string)
                            $pagoSeleccionado["numero_comprobante"]
                        ) === ""
                    ) {
                        throw new Exception(
                            "La transferencia no tiene un número de comprobante registrado."
                        );
                    }

                    if (
                        urlComprobanteSegura(
                            $pagoSeleccionado["comprobante"] ?? ""
                        ) === ""
                    ) {
                        throw new Exception(
                            "La transferencia no tiene un comprobante válido para revisar."
                        );
                    }
                }

                $buscarOtroAceptado = mysqli_prepare(
                    $conn,
                    "SELECT id_pago
                     FROM pagos
                     WHERE id_reserva = ?
                       AND estado_pago = 'Aceptado'
                       AND id_pago != ?
                     LIMIT 1"
                );

                if (!$buscarOtroAceptado) {
                    throw new Exception(
                        "No se pudo comprobar el historial de pagos."
                    );
                }

                mysqli_stmt_bind_param(
                    $buscarOtroAceptado,
                    "ii",
                    $idReserva,
                    $idPago
                );

                mysqli_stmt_execute($buscarOtroAceptado);

                $resultadoOtroAceptado =
                    mysqli_stmt_get_result($buscarOtroAceptado);

                $existeOtroAceptado =
                    mysqli_num_rows($resultadoOtroAceptado) > 0;

                mysqli_stmt_close($buscarOtroAceptado);

                if ($existeOtroAceptado) {
                    throw new Exception(
                        "La reserva ya tiene otro pago aceptado."
                    );
                }

                $estadoPago = "Aceptado";

                $observacionPago =
                    "Pago revisado y aceptado por el administrador.";

                $actualizarPago = mysqli_prepare(
                    $conn,
                    "UPDATE pagos
                     SET
                        estado_pago = ?,
                        observacion = ?
                     WHERE id_pago = ?
                       AND estado_pago = 'Pendiente'"
                );

                if (!$actualizarPago) {
                    throw new Exception(
                        "No se pudo preparar la actualización."
                    );
                }

                mysqli_stmt_bind_param(
                    $actualizarPago,
                    "ssi",
                    $estadoPago,
                    $observacionPago,
                    $idPago
                );

                if (!mysqli_stmt_execute($actualizarPago)) {
                    mysqli_stmt_close($actualizarPago);

                    throw new Exception(
                        "No se pudo aceptar el pago."
                    );
                }

                if (
                    mysqli_stmt_affected_rows($actualizarPago) !== 1
                ) {
                    mysqli_stmt_close($actualizarPago);

                    throw new Exception(
                        "El pago ya fue procesado."
                    );
                }

                mysqli_stmt_close($actualizarPago);

                $actualizarReserva = mysqli_prepare(
                    $conn,
                    "UPDATE reservas
                     SET estado = 'Confirmada'
                     WHERE id_reserva = ?
                       AND estado NOT IN ('Cancelada', 'Finalizada')"
                );

                if (!$actualizarReserva) {
                    throw new Exception(
                        "No se pudo preparar la reserva."
                    );
                }

                mysqli_stmt_bind_param(
                    $actualizarReserva,
                    "i",
                    $idReserva
                );

                if (!mysqli_stmt_execute($actualizarReserva)) {
                    mysqli_stmt_close($actualizarReserva);

                    throw new Exception(
                        "No se pudo confirmar la reserva."
                    );
                }

                mysqli_stmt_close($actualizarReserva);

                mysqli_commit($conn);

                $_SESSION["csrf_pagos"] =
                    bin2hex(random_bytes(32));

                $clienteDestino =
                    $clienteRetorno > 0
                        ? $clienteRetorno
                        : (int) $pagoSeleccionado["id_cliente"];

                header(
                    "Location: " .
                    urlPagos(
                        $clienteDestino,
                        $estadoRetorno,
                        $paginaRetorno,
                        "aceptado"
                    )
                );
                exit();
            }

            if ($accion === "rechazar") {
                $estadoPago = "Rechazado";

                $actualizarPago = mysqli_prepare(
                    $conn,
                    "UPDATE pagos
                     SET
                        estado_pago = ?,
                        observacion = ?
                     WHERE id_pago = ?
                       AND estado_pago = 'Pendiente'"
                );

                if (!$actualizarPago) {
                    throw new Exception(
                        "No se pudo preparar la actualización."
                    );
                }

                mysqli_stmt_bind_param(
                    $actualizarPago,
                    "ssi",
                    $estadoPago,
                    $observacion,
                    $idPago
                );

                if (!mysqli_stmt_execute($actualizarPago)) {
                    mysqli_stmt_close($actualizarPago);

                    throw new Exception(
                        "No se pudo rechazar el pago."
                    );
                }

                if (
                    mysqli_stmt_affected_rows($actualizarPago) !== 1
                ) {
                    mysqli_stmt_close($actualizarPago);

                    throw new Exception(
                        "El pago ya fue procesado."
                    );
                }

                mysqli_stmt_close($actualizarPago);

                $consultarAceptados = mysqli_prepare(
                    $conn,
                    "SELECT COUNT(*) AS total
                     FROM pagos
                     WHERE id_reserva = ?
                       AND estado_pago = 'Aceptado'"
                );

                if (!$consultarAceptados) {
                    throw new Exception(
                        "No se pudo revisar otros pagos."
                    );
                }

                mysqli_stmt_bind_param(
                    $consultarAceptados,
                    "i",
                    $idReserva
                );

                mysqli_stmt_execute($consultarAceptados);

                $resultadoAceptados =
                    mysqli_stmt_get_result($consultarAceptados);

                $filaAceptados =
                    mysqli_fetch_assoc($resultadoAceptados);

                $totalAceptados =
                    (int) ($filaAceptados["total"] ?? 0);

                mysqli_stmt_close($consultarAceptados);

                if ($totalAceptados === 0) {
                    $actualizarReserva = mysqli_prepare(
                        $conn,
                        "UPDATE reservas
                         SET estado = 'Pendiente'
                         WHERE id_reserva = ?
                           AND estado NOT IN ('Cancelada', 'Finalizada')"
                    );

                    if (!$actualizarReserva) {
                        throw new Exception(
                            "No se pudo preparar la reserva."
                        );
                    }

                    mysqli_stmt_bind_param(
                        $actualizarReserva,
                        "i",
                        $idReserva
                    );

                    if (!mysqli_stmt_execute($actualizarReserva)) {
                        mysqli_stmt_close($actualizarReserva);

                        throw new Exception(
                            "No se pudo actualizar la reserva."
                        );
                    }

                    mysqli_stmt_close($actualizarReserva);
                }

                mysqli_commit($conn);

                $_SESSION["csrf_pagos"] =
                    bin2hex(random_bytes(32));

                $clienteDestino =
                    $clienteRetorno > 0
                        ? $clienteRetorno
                        : (int) $pagoSeleccionado["id_cliente"];

                header(
                    "Location: " .
                    urlPagos(
                        $clienteDestino,
                        $estadoRetorno,
                        $paginaRetorno,
                        "rechazado"
                    )
                );
                exit();
            }
        } catch (Throwable $excepcion) {
            mysqli_rollback($conn);

            $error =
                trim((string) $excepcion->getMessage()) !== ""
                    ? $excepcion->getMessage()
                    : "No se pudo actualizar el pago.";
        }
    }

    if ($error !== "") {
        $_GET["cliente"] =
            (string) $clienteRetorno;

        $_GET["estado"] =
            $estadoRetorno;

        $_GET["pagina"] =
            (string) $paginaRetorno;
    }
}

$clientesPagos = [];

$consultaClientesPagos = mysqli_query(
    $conn,
    "SELECT
        c.id_cliente,
        c.nombres,
        c.apellidos,
        c.cedula,
        COUNT(p.id_pago) AS total_pagos,
        SUM(
            CASE
                WHEN p.estado_pago = 'Pendiente'
                THEN 1
                ELSE 0
            END
        ) AS pendientes
     FROM clientes c
     INNER JOIN reservas r
        ON r.id_cliente = c.id_cliente
     INNER JOIN pagos p
        ON p.id_reserva = r.id_reserva
     GROUP BY
        c.id_cliente,
        c.nombres,
        c.apellidos,
        c.cedula
     ORDER BY
        pendientes DESC,
        c.nombres ASC,
        c.apellidos ASC"
);

if ($consultaClientesPagos) {
    while (
        $clientePago =
            mysqli_fetch_assoc($consultaClientesPagos)
    ) {
        $clientesPagos[] = $clientePago;
    }
}

$idClienteFiltro =
    filter_input(
        INPUT_GET,
        "cliente",
        FILTER_VALIDATE_INT
    );

$idClienteFiltro =
    $idClienteFiltro !== false &&
    $idClienteFiltro !== null
        ? (int) $idClienteFiltro
        : 0;

$clienteSeleccionado = null;

foreach ($clientesPagos as $clientePago) {
    if (
        (int) $clientePago["id_cliente"] ===
        $idClienteFiltro
    ) {
        $clienteSeleccionado = $clientePago;
        break;
    }
}

if (
    $clienteSeleccionado === null &&
    !empty($clientesPagos)
) {
    $clienteSeleccionado = $clientesPagos[0];

    $idClienteFiltro =
        (int) $clienteSeleccionado["id_cliente"];
}

$estadoFiltro =
    trim((string) ($_GET["estado"] ?? "Todos"));

if (
    !in_array(
        $estadoFiltro,
        $estadosFiltroPermitidos,
        true
    )
) {
    $estadoFiltro = "Todos";
}

$resumenPagos = [
    "total" => 0,
    "pendientes" => 0,
    "aceptados" => 0,
    "rechazados" => 0,
    "valor_aceptado" => 0.00
];

if ($idClienteFiltro > 0) {
    $consultaResumenPagos = mysqli_prepare(
        $conn,
        "SELECT
            COUNT(*) AS total,
            COALESCE(
                SUM(p.estado_pago = 'Pendiente'),
                0
            ) AS pendientes,
            COALESCE(
                SUM(p.estado_pago = 'Aceptado'),
                0
            ) AS aceptados,
            COALESCE(
                SUM(p.estado_pago = 'Rechazado'),
                0
            ) AS rechazados,
            COALESCE(
                SUM(
                    CASE
                        WHEN p.estado_pago = 'Aceptado'
                        THEN p.monto
                        ELSE 0
                    END
                ),
                0
            ) AS valor_aceptado
         FROM pagos p
         INNER JOIN reservas r
            ON r.id_reserva = p.id_reserva
         WHERE r.id_cliente = ?"
    );

    if ($consultaResumenPagos) {
        mysqli_stmt_bind_param(
            $consultaResumenPagos,
            "i",
            $idClienteFiltro
        );

        if (mysqli_stmt_execute($consultaResumenPagos)) {
            $resultadoResumenPagos =
                mysqli_stmt_get_result($consultaResumenPagos);

            $datosResumenPagos =
                mysqli_fetch_assoc($resultadoResumenPagos);

            if ($datosResumenPagos) {
                $resumenPagos = $datosResumenPagos;
            }
        }

        mysqli_stmt_close($consultaResumenPagos);
    }
}

$totalesEstado = [
    "Todos" =>
        (int) $resumenPagos["total"],
    "Pendiente" =>
        (int) $resumenPagos["pendientes"],
    "Aceptado" =>
        (int) $resumenPagos["aceptados"],
    "Rechazado" =>
        (int) $resumenPagos["rechazados"]
];

$totalPagos =
    (int) ($totalesEstado[$estadoFiltro] ?? 0);

$porPagina = 10;

$paginaActual = max(
    1,
    (int) ($_GET["pagina"] ?? 1)
);

$totalPaginas = max(
    1,
    (int) ceil($totalPagos / $porPagina)
);

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset =
    ($paginaActual - 1) * $porPagina;

$pagos = false;

if ($idClienteFiltro > 0) {
    $sqlPagos =
        "SELECT
            p.id_pago,
            p.id_reserva,
            p.metodo_pago,
            p.monto,
            p.estado_pago,
            p.comprobante,
            p.numero_comprobante,
            p.fecha_pago,
            p.observacion,

            r.id_cliente,
            r.fecha_entrada,
            r.fecha_salida,
            r.estado AS estado_reserva,
            r.total AS total_reserva,

            c.nombres,
            c.apellidos,
            c.cedula,

            h.numero,
            h.tipo,
            h.imagen AS imagen_habitacion

         FROM pagos p

         INNER JOIN reservas r
            ON p.id_reserva = r.id_reserva

         INNER JOIN clientes c
            ON r.id_cliente = c.id_cliente

         INNER JOIN habitaciones h
            ON r.id_habitacion = h.id_habitacion

         WHERE r.id_cliente = ?";

    if ($estadoFiltro !== "Todos") {
        $sqlPagos .=
            " AND p.estado_pago = ?";
    }

    $sqlPagos .=
        " ORDER BY
            CASE
                WHEN p.estado_pago = 'Pendiente' THEN 0
                WHEN p.estado_pago = 'Rechazado' THEN 1
                WHEN p.estado_pago = 'Aceptado' THEN 2
                ELSE 3
            END,
            p.id_pago DESC
          LIMIT ? OFFSET ?";

    $consultaPagos =
        mysqli_prepare($conn, $sqlPagos);

    if ($consultaPagos) {
        if ($estadoFiltro === "Todos") {
            mysqli_stmt_bind_param(
                $consultaPagos,
                "iii",
                $idClienteFiltro,
                $porPagina,
                $offset
            );
        } else {
            mysqli_stmt_bind_param(
                $consultaPagos,
                "isii",
                $idClienteFiltro,
                $estadoFiltro,
                $porPagina,
                $offset
            );
        }

        if (mysqli_stmt_execute($consultaPagos)) {
            $pagos =
                mysqli_stmt_get_result($consultaPagos);
        }

        mysqli_stmt_close($consultaPagos);
    }
}

$primerRegistro =
    $totalPagos > 0
        ? $offset + 1
        : 0;

$ultimoRegistro =
    min(
        $offset + $porPagina,
        $totalPagos
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
$pagosPendientesGlobales = 0;
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

    $pagosPendientesGlobales =
        (int) ($filaCantidadPagos["total"] ?? 0);
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
        Gestión de pagos - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=51"
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
            background: var(--crema);
            color: #20231f;
            font-family: Arial, Helvetica, sans-serif;
        }

        a {
            text-decoration: none;
        }

        .navbar-hotel {
            min-height: 82px;
            background: rgba(18, 39, 28, .98);
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
            min-height: 365px;
            display: flex;
            align-items: center;
            margin-top: 82px;
            color: white;
            background:
                linear-gradient(
                    90deg,
                    rgba(10, 29, 20, .92),
                    rgba(10, 29, 20, .62)
                ),
                url("../img/hotel.jpg") center/cover;
        }

        .pagina-hero-contenido {
            max-width: 760px;
            padding: 68px 0;
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
            padding: 70px 0;
        }

        .mensaje {
            display: flex;
            gap: 10px;
            margin-bottom: 22px;
            padding: 14px 17px;
            border-radius: 6px;
            font-size: 13px;
        }

        .mensaje-exito {
            border: 1px solid #b8ddc2;
            background: #edf8f0;
            color: #24643a;
        }

        .mensaje-aviso {
            border: 1px solid #ead79f;
            background: #fff8df;
            color: #765a18;
        }

        .mensaje-error {
            border: 1px solid #edc8c8;
            background: #fff1f1;
            color: #9b3131;
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

        .selector-huesped {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(340px, 500px);
            align-items: end;
            gap: 22px;
            margin-bottom: 22px;
            padding: 20px 22px;
            border: 1px solid #e2e4de;
            border-radius: 9px;
            background: white;
            box-shadow: var(--sombra);
        }

        .selector-huesped h3 {
            margin: 5px 0;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
        }

        .selector-huesped p {
            margin: 0;
            color: var(--texto-suave);
            font-size: 12px;
        }

        .huesped-seleccionado {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-top: 8px;
            padding: 7px 11px;
            border-radius: 999px;
            background: var(--verde-claro);
            color: var(--verde);
            font-size: 10px;
            font-weight: 900;
        }

        .selector-huesped-form {
            display: flex;
            gap: 9px;
        }

        .selector-huesped-form .form-select {
            min-height: 46px;
            flex: 1;
            border: 1px solid #dce1dc;
            background: #f7f9f7;
            font-size: 13px;
        }

        .btn-ver-pagos {
            min-height: 46px;
            padding: 10px 17px;
            border: 1px solid var(--verde);
            border-radius: 7px;
            background: var(--verde);
            color: white;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .btn-ver-pagos:hover {
            background: var(--verde-oscuro);
            color: white;
        }

        .resumen-pagos {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 24px;
        }

        .resumen-pago-card {
            padding: 18px;
            border: 1px solid #e2e4de;
            border-radius: 9px;
            background: white;
            box-shadow: var(--sombra);
        }

        .resumen-pago-card small {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .resumen-pago-card strong {
            display: block;
            margin-top: 7px;
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 27px;
        }

        .filtros-pagos {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 24px;
        }

        .filtro-pago {
            min-height: 39px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            border: 1px solid #d9ded8;
            border-radius: 999px;
            background: white;
            color: #586159;
            font-size: 11px;
            font-weight: 900;
        }

        .filtro-pago:hover {
            border-color: var(--verde);
            color: var(--verde);
        }

        .filtro-pago.activo {
            border-color: var(--verde);
            background: var(--verde);
            color: white;
        }

        .filtro-pago-total {
            min-width: 23px;
            height: 23px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 6px;
            border-radius: 999px;
            background: var(--verde-claro);
            color: var(--verde);
            font-size: 9px;
            font-weight: 900;
        }

        .filtro-pago.activo .filtro-pago-total {
            background: rgba(255, 255, 255, .16);
            color: white;
        }

        .pago-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            box-shadow: var(--sombra);
        }

        .pago-card + .pago-card {
            margin-top: 28px;
        }

        .pago-cabecera {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            padding: 23px 26px;
            border-bottom: 1px solid #e5e7e2;
            background: #fbfcfa;
        }

        .pago-cabecera h2 {
            margin: 5px 0 3px;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 24px;
        }

        .pago-etiqueta {
            color: #9b7739;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 1.7px;
        }

        .pago-cuerpo {
            padding: 27px;
        }

        .dato {
            height: 100%;
            padding: 13px;
            border: 1px solid #e2e5df;
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
            color: #303731;
            font-size: 13px;
        }

        .dato-comprobante {
            height: auto;
        }

        .habitacion-pago {
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid #e2e5df;
            border-radius: 9px;
            background: #f7f9f7;
        }

        .habitacion-pago-imagen {
            width: 100%;
            height: 230px;
            display: block;
            object-fit: cover;
            background: #eef1ed;
        }

        .habitacion-pago-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding: 13px 15px;
        }

        .habitacion-pago-info small {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .habitacion-pago-info strong {
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 17px;
        }

        .monto {
            margin-top: 21px;
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 32px;
            font-weight: 700;
        }

        .monto-diferente {
            color: #9b3131;
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

        .estado-aceptado {
            background: #dff2e4;
            color: #21643b;
        }

        .estado-rechazado {
            background: #fff0f0;
            color: #9d3030;
        }

        .estado-pendiente {
            background: #fff0c7;
            color: #81600d;
        }

        .comprobante-imagen {
            width: 100%;
            max-height: 370px;
            object-fit: contain;
            border: 1px solid #e2e4de;
            border-radius: 8px;
            background: #f1f2ef;
        }

        .comprobante-no-disponible {
            display: none;
            padding: 18px;
            border: 1px solid #edc8c8;
            border-radius: 8px;
            background: #fff1f1;
            color: #9b3131;
            font-size: 12px;
            line-height: 1.6;
            text-align: center;
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

        .form-label {
            font-size: 12px;
            font-weight: 900;
        }

        .form-control {
            border: 1px solid #dce1dc;
            background: #f7f9f7;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: var(--verde);
            background: white;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, .10);
        }

        .btn-aceptar,
        .btn-rechazar {
            min-height: 45px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: 900;
        }

        .historial {
            padding: 14px 16px;
            border: 1px solid #dedfd9;
            border-radius: 6px;
            background: #f7f8f5;
            color: #59615b;
            font-size: 12px;
            line-height: 1.6;
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
            .selector-huesped {
                grid-template-columns: 1fr;
            }

            .resumen-pagos {
                grid-template-columns: repeat(3, minmax(0, 1fr));
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

            .pago-cabecera {
                display: block;
            }

            .pago-cabecera .estado-badge {
                margin-top: 14px;
            }

            .pago-cuerpo {
                padding: 21px;
            }

            .selector-huesped-form {
                flex-direction: column;
            }

            .resumen-pagos {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .habitacion-pago-imagen {
                height: 205px;
            }

            .habitacion-pago-info {
                align-items: flex-start;
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

            .menu-notificaciones-admin {
                position: fixed !important;
                top: 74px !important;
                left: 12px !important;
                right: 12px !important;
                width: auto !important;
                transform: none !important;
            }

            .habitacion-pago-imagen {
                height: 175px;
            }

            .habitacion-pago-info {
                display: block;
            }

            .habitacion-pago-info i {
                display: none;
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
                    <a href="../reservas/index.php" class="nav-link">
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
                                href="index.php"
                                class="dropdown-item active"
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

                        <?php if ($pagosPendientesGlobales > 0) { ?>
                            <span class="contador-notificaciones-admin">
                                <?php
                                echo $pagosPendientesGlobales > 99
                                    ? "99+"
                                    : $pagosPendientesGlobales;
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
                                <?php echo $pagosPendientesGlobales; ?>
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
                                        href="<?php
                                        echo h(
                                            urlPagos(
                                                (int) $notificacionPago["id_cliente"],
                                                "Pendiente"
                                            )
                                        );
                                        ?>"
                                        class="notificacion-pago-admin"
                                    >
                                        <div class="notificacion-pago-fila">

                                            <span class="notificacion-pago-icono">
                                                <i class="bi bi-receipt"></i>
                                            </span>

                                            <span class="notificacion-pago-contenido">

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
                                                        $notificacionPago[
                                                            "numero_habitacion"
                                                        ]
                                                    );
                                                    ?>
                                                    ·
                                                    <?php
                                                    echo h(
                                                        $notificacionPago[
                                                            "metodo_pago"
                                                        ]
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
                            <a
                                href="<?php
                                echo $idClienteFiltro > 0
                                    ? h(urlPagos($idClienteFiltro, "Todos"))
                                    : "index.php";
                                ?>"
                            >
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
                ADMINISTRACIÓN HOTELERA
            </div>

            <h1>Gestión de pagos</h1>

            <p>
                Revisa los comprobantes enviados por los huéspedes,
                acepta los pagos correctos y conserva el historial
                financiero relacionado con cada reserva.
            </p>

        </div>
    </div>
</section>

<main class="contenido-pagina" id="pagosRegistrados">
    <div class="container">

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "aceptado"
        ) { ?>

            <div class="mensaje mensaje-exito">
                <i class="bi bi-check-circle"></i>
                El pago fue aceptado y la reserva quedó confirmada.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "rechazado"
        ) { ?>

            <div class="mensaje mensaje-aviso">
                <i class="bi bi-exclamation-circle"></i>
                El pago fue rechazado y se guardó la observación.
            </div>

        <?php } ?>

        <?php if ($error !== "") { ?>

            <div class="mensaje mensaje-error">
                <i class="bi bi-exclamation-triangle"></i>
                <?php echo h($error); ?>
            </div>

        <?php } ?>

        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

            <div>
                <div class="pagina-etiqueta text-success">
                    REGISTROS FINANCIEROS
                </div>

                <h2 class="mt-2 mb-1">
                    Pagos por huésped
                </h2>

                <p class="text-muted mb-0">
                    Selecciona un huésped y revisa sus pagos por estado.
                </p>
            </div>

            <?php if ($clienteSeleccionado) { ?>

                <span class="contador">
                    <i class="bi bi-credit-card"></i>
                    <?php echo (int) $resumenPagos["total"]; ?>
                    pagos
                </span>

            <?php } ?>

        </div>

        <section class="selector-huesped">

            <div>
                <div class="pagina-etiqueta text-success">
                    HUÉSPED SELECCIONADO
                </div>

                <?php if ($clienteSeleccionado) { ?>

                    <h3>
                        <?php
                        echo h(
                            $clienteSeleccionado["nombres"] .
                            " " .
                            $clienteSeleccionado["apellidos"]
                        );
                        ?>
                    </h3>

                    <p>
                        Solo se muestran los pagos relacionados
                        con las reservas de este huésped.
                    </p>

                    <span class="huesped-seleccionado">
                        <i class="bi bi-person-check"></i>
                        Cédula:
                        <?php echo h($clienteSeleccionado["cedula"]); ?>
                    </span>

                <?php } else { ?>

                    <h3>No hay huéspedes con pagos</h3>

                    <p>
                        Cuando un cliente registre un pago,
                        aparecerá en esta sección.
                    </p>

                <?php } ?>

            </div>

            <?php if (!empty($clientesPagos)) { ?>

                <form
                    method="GET"
                    action="index.php#pagosRegistrados"
                    class="selector-huesped-form"
                >

                    <select
                        name="cliente"
                        class="form-select"
                        required
                    >

                        <?php foreach (
                            $clientesPagos as $clientePago
                        ) { ?>

                            <option
                                value="<?php echo (int) $clientePago["id_cliente"]; ?>"
                                <?php
                                echo (int) $clientePago["id_cliente"] ===
                                    $idClienteFiltro
                                        ? "selected"
                                        : "";
                                ?>
                            >
                                <?php
                                echo h(
                                    $clientePago["nombres"] .
                                    " " .
                                    $clientePago["apellidos"] .
                                    " - " .
                                    $clientePago["cedula"]
                                );
                                ?>
                                (<?php echo (int) $clientePago["total_pagos"]; ?>)
                                <?php if ((int) $clientePago["pendientes"] > 0) { ?>
                                    · <?php echo (int) $clientePago["pendientes"]; ?> pendiente(s)
                                <?php } ?>
                            </option>

                        <?php } ?>

                    </select>

                    <input
                        type="hidden"
                        name="estado"
                        value="Todos"
                    >

                    <button
                        type="submit"
                        class="btn-ver-pagos"
                    >
                        <i class="bi bi-search me-1"></i>
                        Ver pagos
                    </button>

                </form>

            <?php } ?>

        </section>

        <?php if ($clienteSeleccionado) { ?>

            <section class="resumen-pagos">

                <div class="resumen-pago-card">
                    <small>Total</small>
                    <strong>
                        <?php echo (int) $resumenPagos["total"]; ?>
                    </strong>
                </div>

                <div class="resumen-pago-card">
                    <small>Pendientes</small>
                    <strong>
                        <?php echo (int) $resumenPagos["pendientes"]; ?>
                    </strong>
                </div>

                <div class="resumen-pago-card">
                    <small>Aceptados</small>
                    <strong>
                        <?php echo (int) $resumenPagos["aceptados"]; ?>
                    </strong>
                </div>

                <div class="resumen-pago-card">
                    <small>Rechazados</small>
                    <strong>
                        <?php echo (int) $resumenPagos["rechazados"]; ?>
                    </strong>
                </div>

                <div class="resumen-pago-card">
                    <small>Valor aceptado</small>
                    <strong>
                        $<?php
                        echo number_format(
                            (float) $resumenPagos["valor_aceptado"],
                            2
                        );
                        ?>
                    </strong>
                </div>

            </section>

            <?php
            $etiquetasEstado = [
                "Todos" => "Todos",
                "Pendiente" => "Pendientes",
                "Aceptado" => "Aceptados",
                "Rechazado" => "Rechazados"
            ];
            ?>

            <div class="filtros-pagos">

                <?php foreach (
                    $etiquetasEstado as $valorEstado => $textoEstado
                ) { ?>

                    <a
                        href="<?php
                        echo h(
                            urlPagos(
                                $idClienteFiltro,
                                $valorEstado
                            )
                        );
                        ?>"
                        class="filtro-pago <?php echo $estadoFiltro === $valorEstado ? "activo" : ""; ?>"
                    >
                        <?php echo h($textoEstado); ?>

                        <span class="filtro-pago-total">
                            <?php echo (int) $totalesEstado[$valorEstado]; ?>
                        </span>
                    </a>

                <?php } ?>

            </div>

        <?php } ?>

        <?php if (
            $pagos &&
            mysqli_num_rows($pagos) > 0
        ) { ?>

            <?php while (
                $pago = mysqli_fetch_assoc($pagos)
            ) { ?>

                <?php
                $urlComprobante =
                    urlComprobanteSegura(
                        $pago["comprobante"]
                    );

                $esPdf =
                    $urlComprobante !== "" &&
                    preg_match(
                        '/\.pdf($|\?)/i',
                        $urlComprobante
                    );

                $montoCoincide =
                    abs(
                        (float) $pago["monto"] -
                        (float) $pago["total_reserva"]
                    ) <= 0.01;

                $urlImagenHabitacion =
                    urlComprobanteSegura(
                        $pago["imagen_habitacion"] ?? ""
                    );

                if ($urlImagenHabitacion === "") {
                    $urlImagenHabitacion =
                        "../img/hotel.jpg";
                }
                ?>

                <article class="pago-card">

                    <div class="pago-cabecera">

                        <div>
                            <div class="pago-etiqueta">
                                PAGO #<?php echo (int) $pago["id_pago"]; ?>
                            </div>

                            <h2>
                                <?php
                                echo h(
                                    $pago["nombres"] .
                                    " " .
                                    $pago["apellidos"]
                                );
                                ?>
                            </h2>

                            <small class="text-muted">
                                Cédula:
                                <?php echo h($pago["cedula"]); ?>
                            </small>
                        </div>

                        <?php if (
                            $pago["estado_pago"] === "Aceptado"
                        ) { ?>

                            <span class="estado-badge estado-aceptado">
                                <i class="bi bi-check-circle"></i>
                                Aceptado
                            </span>

                        <?php } elseif (
                            $pago["estado_pago"] === "Rechazado"
                        ) { ?>

                            <span class="estado-badge estado-rechazado">
                                <i class="bi bi-x-circle"></i>
                                Rechazado
                            </span>

                        <?php } else { ?>

                            <span class="estado-badge estado-pendiente">
                                <i class="bi bi-clock"></i>
                                Pendiente
                            </span>

                        <?php } ?>

                    </div>

                    <div class="pago-cuerpo">

                        <div class="row g-4">

                            <div class="col-lg-7">

                                <div class="habitacion-pago">

                                    <img
                                        src="<?php echo h($urlImagenHabitacion); ?>"
                                        alt="Habitación <?php echo h($pago["numero"]); ?>"
                                        class="habitacion-pago-imagen"
                                        onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                                    >

                                    <div class="habitacion-pago-info">

                                        <div>
                                            <small>
                                                Habitación reservada
                                            </small>

                                            <strong>
                                                <?php
                                                echo h(
                                                    $pago["numero"] .
                                                    " - " .
                                                    $pago["tipo"]
                                                );
                                                ?>
                                            </strong>
                                        </div>

                                        <i class="bi bi-door-open fs-4 text-success"></i>

                                    </div>

                                </div>

                                <div class="row g-3">

                                    <div class="col-md-4">
                                        <div class="dato">
                                            <small>Reserva</small>
                                            <strong>
                                                #<?php echo (int) $pago["id_reserva"]; ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="dato">
                                            <small>Habitación</small>
                                            <strong>
                                                <?php
                                                echo h(
                                                    $pago["numero"] .
                                                    " - " .
                                                    $pago["tipo"]
                                                );
                                                ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="dato">
                                            <small>Método</small>
                                            <strong>
                                                <?php echo h($pago["metodo_pago"]); ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="dato">
                                            <small>Entrada</small>
                                            <strong>
                                                <?php echo h($pago["fecha_entrada"]); ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="dato">
                                            <small>Salida</small>
                                            <strong>
                                                <?php echo h($pago["fecha_salida"]); ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <div class="col-md-4">
                                        <div class="dato">
                                            <small>Estado reserva</small>
                                            <strong>
                                                <?php echo h($pago["estado_reserva"]); ?>
                                            </strong>
                                        </div>
                                    </div>

                                </div>

                                <div
                                    class="monto <?php echo !$montoCoincide ? "monto-diferente" : ""; ?>"
                                >
                                    $<?php
                                    echo number_format(
                                        (float) $pago["monto"],
                                        2
                                    );
                                    ?>
                                </div>

                                <div class="text-muted small">
                                    Total de la reserva:
                                    $<?php
                                    echo number_format(
                                        (float) $pago["total_reserva"],
                                        2
                                    );
                                    ?>
                                </div>

                                <?php if (!$montoCoincide) { ?>

                                    <div class="mensaje mensaje-error mt-3 mb-0">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        El monto no coincide con el total de la reserva.
                                    </div>

                                <?php } ?>

                                <div class="text-muted small mt-3">
                                    Fecha del pago:
                                    <?php echo h($pago["fecha_pago"]); ?>
                                </div>

                            </div>

                            <div class="col-lg-5">

                                <?php if (
                                    $pago["metodo_pago"] === "Transferencia"
                                ) { ?>

                                    <p class="fw-bold mb-2">
                                        Verificación de transferencia
                                    </p>

                                    <div class="dato dato-comprobante mb-3">
                                        <small>
                                            Número escrito por el cliente
                                        </small>

                                        <strong>
                                            <?php
                                            echo trim(
                                                (string) $pago["numero_comprobante"]
                                            ) !== ""
                                                ? h($pago["numero_comprobante"])
                                                : "No registrado";
                                            ?>
                                        </strong>
                                    </div>

                                    <div class="mensaje mensaje-aviso mb-3">
                                        <i class="bi bi-search"></i>

                                        Compara este número con el que aparece
                                        dentro de la imagen o PDF antes de aceptar.
                                    </div>

                                    <p class="fw-bold mb-2">
                                        Archivo del comprobante
                                    </p>

                                    <?php if ($urlComprobante === "") { ?>

                                        <div class="mensaje mensaje-error">
                                            <i class="bi bi-file-earmark-x"></i>
                                            No existe un comprobante válido.
                                        </div>

                                    <?php } elseif ($esPdf) { ?>

                                        <a
                                            href="<?php echo h($urlComprobante); ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="btn btn-outline-primary w-100 mb-3"
                                        >
                                            <i class="bi bi-file-earmark-pdf"></i>
                                            Abrir comprobante PDF
                                        </a>

                                    <?php } else { ?>

                                        <div class="comprobante-contenedor">

                                        <a
                                            href="<?php echo h($urlComprobante); ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <img
                                                src="<?php echo h($urlComprobante); ?>"
                                                alt="Comprobante de pago"
                                                class="comprobante-imagen"
                                                onerror="
                                                    this.style.display='none';
                                                    const aviso =
                                                        this.closest('.comprobante-contenedor')
                                                            ?.querySelector(
                                                                '.comprobante-no-disponible'
                                                            );

                                                    if (aviso) {
                                                        aviso.style.display='block';
                                                    }
                                                "
                                            >

                                            <div class="comprobante-no-disponible">
                                                <i class="bi bi-image-alt fs-4 d-block mb-2"></i>
                                                El archivo ya no está disponible en Firebase.
                                                La URL quedó registrada en el pago,
                                                pero el archivo original no fue encontrado.
                                            </div>
                                        </a>

                                        <a
                                            href="<?php echo h($urlComprobante); ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="btn btn-outline-primary w-100 mt-3"
                                        >
                                            <i class="bi bi-arrows-fullscreen"></i>
                                            Ver comprobante completo
                                        </a>

                                        </div>

                                    <?php } ?>

                                <?php } else { ?>

                                    <div class="mensaje mensaje-aviso">
                                        <i class="bi bi-credit-card"></i>
                                        Pago registrado con tarjeta.
                                    </div>

                                <?php } ?>

                                <?php if (
                                    $pago["estado_pago"] === "Pendiente"
                                ) { ?>

                                    <hr>

                                    <form method="POST" autocomplete="off">

                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?php echo h($_SESSION["csrf_pagos"]); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="id_pago"
                                            value="<?php echo (int) $pago["id_pago"]; ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="filtro_cliente"
                                            value="<?php echo $idClienteFiltro; ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="filtro_estado"
                                            value="<?php echo h($estadoFiltro); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="filtro_pagina"
                                            value="<?php echo $paginaActual; ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="accion"
                                            value="aceptar"
                                            class="btn btn-success btn-aceptar w-100 mb-3"
                                            <?php
                                            $numeroComprobanteValido =
                                                $pago["metodo_pago"] !== "Transferencia" ||
                                                trim(
                                                    (string) $pago["numero_comprobante"]
                                                ) !== "";

                                            $archivoComprobanteValido =
                                                $pago["metodo_pago"] !== "Transferencia" ||
                                                $urlComprobante !== "";

                                            echo (
                                                !$montoCoincide ||
                                                !$numeroComprobanteValido ||
                                                !$archivoComprobanteValido
                                            )
                                                ? "disabled"
                                                : "";
                                            ?>
                                            onclick="return confirm('¿Deseas aceptar este pago y confirmar la reserva?');"
                                        >
                                            <i class="bi bi-check-circle"></i>
                                            Aceptar pago
                                        </button>

                                        <label
                                            for="observacion_<?php echo (int) $pago["id_pago"]; ?>"
                                            class="form-label"
                                        >
                                            Motivo del rechazo
                                        </label>

                                        <textarea
                                            id="observacion_<?php echo (int) $pago["id_pago"]; ?>"
                                            name="observacion"
                                            class="form-control mb-3"
                                            rows="3"
                                            maxlength="255"
                                            placeholder="Ejemplo: el valor o el comprobante no coincide"
                                        ></textarea>

                                        <button
                                            type="submit"
                                            name="accion"
                                            value="rechazar"
                                            class="btn btn-danger btn-rechazar w-100"
                                            onclick="return confirm('¿Deseas rechazar este pago?');"
                                        >
                                            <i class="bi bi-x-circle"></i>
                                            Rechazar pago
                                        </button>

                                    </form>

                                <?php } else { ?>

                                    <hr>

                                    <div class="historial">

                                        <strong>
                                            Resultado de la revisión
                                        </strong>

                                        <div class="mt-2">
                                            <?php
                                            echo h(
                                                $pago["observacion"] !== ""
                                                    ? $pago["observacion"]
                                                    : "Sin observación registrada."
                                            );
                                            ?>
                                        </div>

                                    </div>

                                <?php } ?>

                            </div>

                        </div>

                    </div>

                </article>

            <?php } ?>

        <?php } else { ?>

            <div class="vacio">

                <div class="display-4 mb-3">
                    <i class="bi bi-credit-card"></i>
                </div>

                <h2>
                    <?php if (!$clienteSeleccionado) { ?>
                        No existen pagos registrados
                    <?php } else { ?>
                        No hay pagos en esta categoría
                    <?php } ?>
                </h2>

                <p class="text-muted mb-0">
                    <?php if (!$clienteSeleccionado) { ?>
                        Los pagos realizados por los clientes aparecerán aquí.
                    <?php } else { ?>
                        Selecciona otro estado para revisar el historial del huésped.
                    <?php } ?>
                </p>

            </div>

        <?php } ?>

        <?php if ($clienteSeleccionado && $totalPagos > 0) { ?>

            <div class="paginacion-contenedor">

                <div class="paginacion-info">
                    Mostrando
                    <?php echo $primerRegistro; ?>
                    -
                    <?php echo $ultimoRegistro; ?>
                    de
                    <?php echo $totalPagos; ?>
                    pagos
                </div>

                <?php if ($totalPaginas > 1) { ?>

                    <nav
                        class="paginacion-hotel"
                        aria-label="Paginación de pagos"
                    >

                        <?php if ($paginaActual > 1) { ?>

                            <a href="<?php echo h(urlPagos($idClienteFiltro, $estadoFiltro, $paginaActual - 1)); ?>">
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

                            <a href="<?php echo h(urlPagos($idClienteFiltro, $estadoFiltro, 1)); ?>">1</a>

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

                                <a href="<?php echo h(urlPagos($idClienteFiltro, $estadoFiltro, $pagina)); ?>">
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

                            <a href="<?php echo h(urlPagos($idClienteFiltro, $estadoFiltro, $totalPaginas)); ?>">
                                <?php echo $totalPaginas; ?>
                            </a>

                        <?php } ?>

                        <?php if ($paginaActual < $totalPaginas) { ?>

                            <a href="<?php echo h(urlPagos($idClienteFiltro, $estadoFiltro, $paginaActual + 1)); ?>">
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
                href="../dashboard.php"
                class="btn btn-outline-light btn-sm"
            >
                Volver al panel
            </a>

        </div>

    </div>

    <div class="footer-final">

        <div class="container d-flex justify-content-between flex-wrap gap-2">

            <span>
                Hotel Las 3 Palmeras © 2026
            </span>

            <span>
                Módulo de pagos
            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>