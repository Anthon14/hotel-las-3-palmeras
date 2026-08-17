<?php
session_start();

include("../config/conexion.php");

if (!isset($_SESSION["usuario"], $_SESSION["rol"])) {
    header("Location: ../login.php");
    exit();
}

$rolActual = strtolower(trim((string) $_SESSION["rol"]));

if (!in_array($rolActual, ["administrador", "recepcionista"], true)) {
    header("Location: ../dashboard.php");
    exit();
}

$esAdministrador = $rolActual === "administrador";

function h($texto)
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

function claseEstado($estado)
{
    return match ($estado) {
        "Pendiente" => "estado-pendiente",
        "Preparando" => "estado-preparando",
        "Entregado" => "estado-entregado",
        "Cancelado" => "estado-cancelado",
        default => "estado-desconocido"
    };
}

function claseEstadoPago($estado)
{
    return match ($estado) {
        "Pagado" => "pago-pagado",
        "Pendiente" => "pago-pendiente",
        default => "pago-desconocido"
    };
}

function iconoEstadoPago($estado)
{
    return match ($estado) {
        "Pagado" => "bi-check-circle",
        "Pendiente" => "bi-hourglass-split",
        default => "bi-question-circle"
    };
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

    $esquema = strtolower((string) parse_url($imagen, PHP_URL_SCHEME));

    if (!in_array($esquema, ["http", "https"], true)) {
        return "../img/hotel.jpg";
    }

    return $imagen;
}

function fechaPedidoFormateada($fecha)
{
    try {
        return (new DateTimeImmutable((string) $fecha))
            ->format("d/m/Y h:i A");
    } catch (Throwable $excepcion) {
        return (string) $fecha;
    }
}

function fechaReservaFormateada($fecha)
{
    try {
        return (new DateTimeImmutable((string) $fecha))
            ->format("d/m/Y");
    } catch (Throwable $excepcion) {
        return (string) $fecha;
    }
}

function urlPedidos(
    int $idCliente,
    string $estado = "Todos",
    int $pagina = 1,
    string $mensaje = ""
): string {
    $parametros = [
        "cliente" => $idCliente,
        "estado" => $estado,
        "pagina" => max(1, $pagina)
    ];

    if ($mensaje !== "") {
        $parametros["mensaje"] = $mensaje;
    }

    return "index.php?" . http_build_query($parametros) . "#pedidosRegistrados";
}

if (empty($_SESSION["csrf_pedidos"])) {
    $_SESSION["csrf_pedidos"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_pedidos"];
$error = "";

$transicionesPermitidas = [
    "Pendiente" => ["Preparando", "Cancelado"],
    "Preparando" => ["Entregado", "Cancelado"],
    "Entregado" => [],
    "Cancelado" => []
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfRecibido = $_POST["csrf"] ?? "";

    $idPedido = filter_input(
        INPUT_POST,
        "id_pedido",
        FILTER_VALIDATE_INT
    );

    $accion =
        trim(
            (string) (
                $_POST["accion"] ??
                "actualizar_estado"
            )
        );

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $error =
            "La solicitud no es válida. Actualiza la página.";
    } elseif (!$idPedido) {
        $error =
            "El pedido seleccionado no es válido.";
    } elseif (
        !in_array(
            $accion,
            ["actualizar_estado", "marcar_pagado"],
            true
        )
    ) {
        $error =
            "La acción seleccionada no es válida.";
    }

    if (
        $error === "" &&
        $accion === "actualizar_estado"
    ) {
        $nuevoEstado =
            trim((string) ($_POST["estado"] ?? ""));

        $estadosPermitidos = [
            "Pendiente",
            "Preparando",
            "Entregado",
            "Cancelado"
        ];

        if (
            !in_array(
                $nuevoEstado,
                $estadosPermitidos,
                true
            )
        ) {
            $error =
                "El estado seleccionado no es válido.";
        }

        $pedidoActual = null;

        if ($error === "") {
            $consultaPedido = mysqli_prepare(
                $conn,
                "SELECT
                    id_pedido,
                    id_cliente,
                    estado,
                    estado_pago
                 FROM pedidos_comida
                 WHERE id_pedido = ?
                 LIMIT 1"
            );

            if (!$consultaPedido) {
                $error =
                    "No se pudo consultar el pedido.";
            } else {
                mysqli_stmt_bind_param(
                    $consultaPedido,
                    "i",
                    $idPedido
                );

                mysqli_stmt_execute($consultaPedido);

                $resultadoPedido =
                    mysqli_stmt_get_result($consultaPedido);

                $pedidoActual =
                    mysqli_fetch_assoc($resultadoPedido);

                mysqli_stmt_close($consultaPedido);

                if (!$pedidoActual) {
                    $error =
                        "El pedido seleccionado no existe.";
                }
            }
        }

        if (
            $error === "" &&
            $pedidoActual !== null
        ) {
            $estadoActual =
                trim((string) $pedidoActual["estado"]);

            $estadoPagoActual =
                trim(
                    (string) (
                        $pedidoActual["estado_pago"] ??
                        "Pendiente"
                    )
                );

            if ($estadoActual === $nuevoEstado) {
                header(
                    "Location: " .
                    urlPedidos(
                        (int) $pedidoActual["id_cliente"],
                        "Todos",
                        1,
                        "sin_cambios"
                    )
                );
                exit();
            }

            $siguientesEstados =
                $transicionesPermitidas[$estadoActual] ?? [];

            if (
                !in_array(
                    $nuevoEstado,
                    $siguientesEstados,
                    true
                )
            ) {
                $error =
                    "No se permite cambiar un pedido de " .
                    $estadoActual .
                    " a " .
                    $nuevoEstado .
                    ".";
            } elseif (
                $nuevoEstado === "Cancelado" &&
                $estadoPagoActual === "Pagado"
            ) {
                $error =
                    "No se puede cancelar un pedido que ya fue pagado.";
            }
        }

        if (
            $error === "" &&
            $pedidoActual !== null
        ) {
            $actualizarEstado = mysqli_prepare(
                $conn,
                "UPDATE pedidos_comida
                 SET estado = ?
                 WHERE id_pedido = ?
                   AND estado = ?"
            );

            if (!$actualizarEstado) {
                $error =
                    "No se pudo preparar la actualización.";
            } else {
                $estadoActual =
                    trim((string) $pedidoActual["estado"]);

                mysqli_stmt_bind_param(
                    $actualizarEstado,
                    "sis",
                    $nuevoEstado,
                    $idPedido,
                    $estadoActual
                );

                if (!mysqli_stmt_execute($actualizarEstado)) {
                    mysqli_stmt_close($actualizarEstado);

                    $error =
                        "No se pudo actualizar el estado del pedido.";
                } elseif (
                    mysqli_stmt_affected_rows($actualizarEstado) !== 1
                ) {
                    mysqli_stmt_close($actualizarEstado);

                    $error =
                        "El pedido cambió mientras lo estabas revisando. Actualiza la página.";
                } else {
                    mysqli_stmt_close($actualizarEstado);

                    $_SESSION["csrf_pedidos"] =
                        bin2hex(random_bytes(32));

                    header(
                        "Location: " .
                        urlPedidos(
                            (int) $pedidoActual["id_cliente"],
                            "Todos",
                            1,
                            "actualizado"
                        )
                    );
                    exit();
                }
            }
        }
    }

    if (
        $error === "" &&
        $accion === "marcar_pagado"
    ) {
        mysqli_begin_transaction($conn);

        try {
            $bloquearPago = mysqli_prepare(
                $conn,
                "SELECT
                    id_pedido,
                    id_cliente,
                    estado,
                    estado_pago,
                    forma_pago
                 FROM pedidos_comida
                 WHERE id_pedido = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$bloquearPago) {
                throw new Exception(
                    "No se pudo validar el pago del pedido."
                );
            }

            mysqli_stmt_bind_param(
                $bloquearPago,
                "i",
                $idPedido
            );

            mysqli_stmt_execute($bloquearPago);

            $resultadoPago =
                mysqli_stmt_get_result($bloquearPago);

            $pedidoPago =
                mysqli_fetch_assoc($resultadoPago);

            mysqli_stmt_close($bloquearPago);

            if (!$pedidoPago) {
                throw new Exception(
                    "El pedido seleccionado no existe."
                );
            }

            if (
                trim((string) $pedidoPago["estado"]) ===
                "Cancelado"
            ) {
                throw new Exception(
                    "No se puede registrar el pago de un pedido cancelado."
                );
            }

            if (
                trim(
                    (string) (
                        $pedidoPago["estado_pago"] ??
                        "Pendiente"
                    )
                ) === "Pagado"
            ) {
                mysqli_rollback($conn);

                header(
                    "Location: " .
                    urlPedidos(
                        (int) $pedidoPago["id_cliente"],
                        "Todos",
                        1,
                        "pago_sin_cambios"
                    )
                );
                exit();
            }

            $marcarPagado = mysqli_prepare(
                $conn,
                "UPDATE pedidos_comida
                 SET
                    estado_pago = 'Pagado',
                    fecha_pago = CURRENT_TIMESTAMP
                 WHERE id_pedido = ?
                   AND estado_pago = 'Pendiente'
                   AND estado <> 'Cancelado'"
            );

            if (!$marcarPagado) {
                throw new Exception(
                    "No se pudo preparar el registro del pago."
                );
            }

            mysqli_stmt_bind_param(
                $marcarPagado,
                "i",
                $idPedido
            );

            if (!mysqli_stmt_execute($marcarPagado)) {
                mysqli_stmt_close($marcarPagado);

                throw new Exception(
                    "No se pudo marcar el pedido como pagado."
                );
            }

            $filasActualizadas =
                mysqli_stmt_affected_rows($marcarPagado);

            mysqli_stmt_close($marcarPagado);

            if ($filasActualizadas !== 1) {
                throw new Exception(
                    "El pago cambió mientras lo estabas revisando."
                );
            }

            mysqli_commit($conn);

            $_SESSION["csrf_pedidos"] =
                bin2hex(random_bytes(32));

            header(
                "Location: " .
                urlPedidos(
                    (int) $pedidoPago["id_cliente"],
                    "Todos",
                    1,
                    "pago_actualizado"
                )
            );
            exit();
        } catch (Throwable $excepcion) {
            mysqli_rollback($conn);

            $error =
                $excepcion->getMessage() !== ""
                    ? $excepcion->getMessage()
                    : "No se pudo actualizar el pago.";
        }
    }
}

$clientesPedidos = [];

$consultaClientes = mysqli_query(
    $conn,
    "SELECT
        c.id_cliente,
        c.nombres,
        c.apellidos,
        c.cedula,
        COUNT(p.id_pedido) AS total_pedidos,
        SUM(
            CASE
                WHEN p.estado IN ('Pendiente', 'Preparando')
                THEN 1
                ELSE 0
            END
        ) AS activos
     FROM clientes c
     INNER JOIN pedidos_comida p
        ON p.id_cliente = c.id_cliente
     GROUP BY
        c.id_cliente,
        c.nombres,
        c.apellidos,
        c.cedula
     ORDER BY
        activos DESC,
        c.nombres ASC,
        c.apellidos ASC"
);

if ($consultaClientes) {
    while (
        $filaCliente =
            mysqli_fetch_assoc($consultaClientes)
    ) {
        $clientesPedidos[] = $filaCliente;
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

foreach ($clientesPedidos as $clientePedido) {
    if (
        (int) $clientePedido["id_cliente"] ===
        $idClienteFiltro
    ) {
        $clienteSeleccionado = $clientePedido;
        break;
    }
}

if (
    $clienteSeleccionado === null &&
    !empty($clientesPedidos)
) {
    $clienteSeleccionado = $clientesPedidos[0];

    $idClienteFiltro =
        (int) $clienteSeleccionado["id_cliente"];
}

$estadosFiltroPermitidos = [
    "Todos",
    "Pendiente",
    "Preparando",
    "Entregado",
    "Cancelado"
];

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

$resumenPedidos = [
    "total_pedidos" => 0,
    "pendientes" => 0,
    "preparando" => 0,
    "entregados" => 0,
    "cancelados" => 0,
    "pagos_pendientes" => 0,
    "pagados" => 0,
    "valor_total" => 0.00
];

if ($idClienteFiltro > 0) {
    $consultaResumen = mysqli_prepare(
        $conn,
        "SELECT
            COUNT(*) AS total_pedidos,
            COALESCE(
                SUM(estado = 'Pendiente'),
                0
            ) AS pendientes,
            COALESCE(
                SUM(estado = 'Preparando'),
                0
            ) AS preparando,
            COALESCE(
                SUM(estado = 'Entregado'),
                0
            ) AS entregados,
            COALESCE(
                SUM(estado = 'Cancelado'),
                0
            ) AS cancelados,
            COALESCE(
                SUM(
                    estado_pago = 'Pendiente'
                    AND estado <> 'Cancelado'
                ),
                0
            ) AS pagos_pendientes,
            COALESCE(
                SUM(estado_pago = 'Pagado'),
                0
            ) AS pagados,
            COALESCE(
                SUM(
                    CASE
                        WHEN estado <> 'Cancelado'
                        THEN total
                        ELSE 0
                    END
                ),
                0
            ) AS valor_total
         FROM pedidos_comida
         WHERE id_cliente = ?"
    );

    if ($consultaResumen) {
        mysqli_stmt_bind_param(
            $consultaResumen,
            "i",
            $idClienteFiltro
        );

        if (mysqli_stmt_execute($consultaResumen)) {
            $resultadoResumen =
                mysqli_stmt_get_result($consultaResumen);

            $datosResumen =
                mysqli_fetch_assoc($resultadoResumen);

            if ($datosResumen) {
                $resumenPedidos = $datosResumen;
            }
        }

        mysqli_stmt_close($consultaResumen);
    }
}

$totalesEstado = [
    "Todos" =>
        (int) $resumenPedidos["total_pedidos"],
    "Pendiente" =>
        (int) $resumenPedidos["pendientes"],
    "Preparando" =>
        (int) $resumenPedidos["preparando"],
    "Entregado" =>
        (int) $resumenPedidos["entregados"],
    "Cancelado" =>
        (int) $resumenPedidos["cancelados"]
];

$totalPedidos =
    (int) ($totalesEstado[$estadoFiltro] ?? 0);

$porPagina = 10;

$paginaActual = max(
    1,
    (int) ($_GET["pagina"] ?? 1)
);

$totalPaginas = max(
    1,
    (int) ceil($totalPedidos / $porPagina)
);

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset =
    ($paginaActual - 1) * $porPagina;

$pedidos = false;

if ($idClienteFiltro > 0) {
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

            c.id_cliente,
            c.nombres,
            c.apellidos,
            c.cedula,
            c.telefono,
            c.correo,

            co.id_comida,
            co.nombre AS nombre_comida,
            co.tipo,
            co.imagen,

            r.id_reserva AS reserva_relacionada,
            r.fecha_entrada AS reserva_entrada,
            r.fecha_salida AS reserva_salida,

            h.numero AS numero_habitacion,
            h.tipo AS tipo_habitacion

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
        if ($error === "") {
            $error =
                "No se pudieron consultar los pedidos.";
        }
    } else {
        if ($estadoFiltro === "Todos") {
            mysqli_stmt_bind_param(
                $consultaPedidos,
                "iii",
                $idClienteFiltro,
                $porPagina,
                $offset
            );
        } else {
            mysqli_stmt_bind_param(
                $consultaPedidos,
                "isii",
                $idClienteFiltro,
                $estadoFiltro,
                $porPagina,
                $offset
            );
        }

        if (!mysqli_stmt_execute($consultaPedidos)) {
            if ($error === "") {
                $error =
                    "No se pudieron consultar los pedidos.";
            }
        } else {
            $pedidos =
                mysqli_stmt_get_result($consultaPedidos);
        }

        mysqli_stmt_close($consultaPedidos);
    }
}

$primerRegistro =
    $totalPedidos > 0
        ? $offset + 1
        : 0;

$ultimoRegistro =
    min(
        $offset + $porPagina,
        $totalPedidos
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
$pagosPendientes = 0;
$notificacionesPagos = false;

if ($esAdministrador) {
    $consultaCantidadPagos = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM pagos
         WHERE estado_pago = 'Pendiente'"
    );

    if ($consultaCantidadPagos) {
        $filaCantidadPagos =
            mysqli_fetch_assoc($consultaCantidadPagos);

        $pagosPendientes =
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
        Pedidos de comida - Hotel Las 3 Palmeras
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
            min-height: 370px;
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

        .selector-cliente-card {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(340px, 500px);
            align-items: end;
            gap: 24px;
            margin-bottom: 28px;
            padding: 22px 24px;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            box-shadow: var(--sombra);
        }

        .selector-cliente-card h2 {
            margin: 5px 0;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
        }

        .selector-cliente-card p {
            margin: 0;
            color: var(--texto-suave);
            font-size: 12px;
        }

        .selector-cliente-form {
            display: flex;
            gap: 9px;
        }

        .selector-cliente-form .form-select {
            flex: 1;
        }

        .btn-ver-cliente {
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

        .btn-ver-cliente:hover {
            background: var(--verde-oscuro);
            color: white;
        }

        .cliente-actual {
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

        .resumen-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 34px;
        }

        .resumen-card {
            height: 100%;
            padding: 19px;
            border: 1px solid #e2e4de;
            border-radius: 9px;
            background: white;
            box-shadow: var(--sombra);
        }

        .resumen-titulo {
            color: var(--texto-suave);
            font-size: 11px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .resumen-numero {
            margin-top: 7px;
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 28px;
            font-weight: 700;
        }

        .filtros-estado {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 24px;
        }

        .filtro-estado {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            min-height: 39px;
            padding: 8px 12px;
            border: 1px solid #d9ded8;
            border-radius: 999px;
            background: white;
            color: #586159;
            font-size: 11px;
            font-weight: 900;
        }

        .filtro-estado:hover {
            border-color: var(--verde);
            color: var(--verde);
        }

        .filtro-estado.activo {
            border-color: var(--verde);
            background: var(--verde);
            color: white;
        }

        .filtro-total {
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

        .filtro-estado.activo .filtro-total {
            background: rgba(255, 255, 255, .16);
            color: white;
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
            min-height: 365px;
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

        .datos-box {
            height: 100%;
            padding: 17px;
            border: 1px solid #e2e5df;
            border-radius: 7px;
            background: #f7f9f7;
        }

        .datos-box h3 {
            color: var(--verde-oscuro);
            font-size: 14px;
        }

        .dato-label {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .dato-valor {
            color: #303731;
            font-size: 13px;
            font-weight: 700;
        }

        .pedido-total {
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 29px;
            font-weight: 700;
        }

        .formulario-estado {
            margin-top: 18px;
            padding: 17px;
            border: 1px solid #ead79f;
            border-radius: 7px;
            background: #fff9e8;
        }

        .pago-box {
            margin-top: 18px;
            padding: 17px;
            border: 1px solid #dfe4de;
            border-radius: 7px;
            background: #fafbf9;
        }

        .pago-encabezado {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 13px;
        }

        .pago-titulo {
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

        .pago-fila {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            padding: 8px 0;
            border-bottom: 1px solid #e2e5df;
            color: var(--texto-suave);
            font-size: 12px;
        }

        .pago-fila:last-of-type {
            border-bottom: 0;
        }

        .pago-fila strong {
            color: #303731;
            text-align: right;
        }

        .btn-marcar-pagado {
            min-height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            margin-top: 14px;
            border: 1px solid var(--verde);
            border-radius: 5px;
            background: var(--verde);
            color: white;
            font-size: 12px;
            font-weight: 900;
        }

        .btn-marcar-pagado:hover {
            background: var(--verde-oscuro);
            color: white;
        }

        .form-label {
            font-size: 12px;
            font-weight: 900;
        }

        .opciones-estado {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 9px;
        }

        .opcion-estado {
            position: relative;
            margin: 0;
            cursor: pointer;
        }

        .opcion-estado input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .opcion-estado-contenido {
            min-height: 46px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 9px 12px;
            border: 1px solid #dce1dc;
            border-radius: 7px;
            background: white;
            color: #586159;
            font-size: 11px;
            font-weight: 900;
            text-align: center;
            transition: .18s ease;
        }

        .opcion-estado:hover .opcion-estado-contenido {
            border-color: var(--verde);
            color: var(--verde);
        }

        .opcion-estado input:checked + .opcion-estado-contenido {
            border-color: var(--verde);
            background: var(--verde);
            color: white;
            box-shadow: 0 7px 18px rgba(36, 74, 53, .16);
        }

        .opcion-estado input:focus-visible + .opcion-estado-contenido {
            outline: 3px solid rgba(36, 74, 53, .18);
            outline-offset: 2px;
        }

        .form-select {
            min-height: 46px;
            border: 1px solid #dce1dc;
            background: white;
            font-size: 13px;
        }

        .btn-actualizar {
            min-height: 46px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            border: 0;
            border-radius: 5px;
            background: var(--verde);
            color: white;
            font-size: 12px;
            font-weight: 900;
        }

        .btn-actualizar:hover {
            background: var(--verde-oscuro);
            color: white;
        }

        .pedido-final {
            margin-top: 18px;
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

        @media (max-width: 991px) {
            .selector-cliente-card {
                grid-template-columns: 1fr;
            }

            .resumen-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .pedido-imagen {
                min-height: 290px;
                height: 290px;
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

            .pedido-contenido {
                padding: 21px;
            }

            .selector-cliente-form {
                flex-direction: column;
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

            .opciones-estado {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 420px) {
            .marca-texto {
                display: none;
            }

            .resumen-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .resumen-card {
                padding: 15px;
            }

            .menu-notificaciones-admin {
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
                                href="index.php"
                                class="dropdown-item active"
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
                            aria-label="Notificaciones administrativas"
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

            <h1>Pedidos de comida</h1>

            <p>
                Revisa los pedidos de los huéspedes, controla
                su preparación, la habitación relacionada y
                el estado del pago de cada consumo.
            </p>

        </div>
    </div>
</section>

<main class="contenido-pagina">
    <div class="container">

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "actualizado"
        ) { ?>

            <div class="mensaje mensaje-exito">
                <i class="bi bi-check-circle"></i>
                El estado del pedido fue actualizado correctamente.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "sin_cambios"
        ) { ?>

            <div class="mensaje mensaje-aviso">
                <i class="bi bi-info-circle"></i>
                El pedido ya tenía ese estado.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "pago_actualizado"
        ) { ?>

            <div class="mensaje mensaje-exito">
                <i class="bi bi-cash-coin"></i>
                El pedido fue marcado como pagado correctamente.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "pago_sin_cambios"
        ) { ?>

            <div class="mensaje mensaje-aviso">
                <i class="bi bi-info-circle"></i>
                El pedido ya estaba marcado como pagado.
            </div>

        <?php } ?>

        <?php if ($error !== "") { ?>

            <div class="mensaje mensaje-error">
                <i class="bi bi-exclamation-triangle"></i>
                <?php echo h($error); ?>
            </div>

        <?php } ?>

        <section class="selector-cliente-card">

            <div>
                <div class="pagina-etiqueta text-success">
                    PEDIDOS POR CLIENTE
                </div>

                <h2>Seleccionar huésped</h2>

                <p>
                    Los pedidos se muestran por cliente para evitar
                    mezclar consumos de diferentes huéspedes.
                </p>

                <?php if ($clienteSeleccionado) { ?>

                    <span class="cliente-actual">
                        <i class="bi bi-person-check"></i>
                        <?php
                        echo h(
                            $clienteSeleccionado["nombres"] .
                            " " .
                            $clienteSeleccionado["apellidos"]
                        );
                        ?>
                        · <?php echo h($clienteSeleccionado["cedula"]); ?>
                    </span>

                <?php } ?>

            </div>

            <?php if (!empty($clientesPedidos)) { ?>

                <form
                    method="GET"
                    action="index.php#pedidosRegistrados"
                    class="selector-cliente-form"
                >

                    <select
                        name="cliente"
                        class="form-select"
                        aria-label="Seleccionar cliente"
                        required
                    >

                        <?php foreach (
                            $clientesPedidos as $clientePedido
                        ) { ?>

                            <option
                                value="<?php echo (int) $clientePedido["id_cliente"]; ?>"
                                <?php
                                echo (int) $clientePedido["id_cliente"] ===
                                    $idClienteFiltro
                                        ? "selected"
                                        : "";
                                ?>
                            >
                                <?php
                                echo h(
                                    $clientePedido["nombres"] .
                                    " " .
                                    $clientePedido["apellidos"] .
                                    " - " .
                                    $clientePedido["cedula"]
                                );
                                ?>
                                (<?php echo (int) $clientePedido["total_pedidos"]; ?>)
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
                        class="btn-ver-cliente"
                    >
                        <i class="bi bi-search me-1"></i>
                        Ver pedidos
                    </button>

                </form>

            <?php } ?>

        </section>

        <?php if ($clienteSeleccionado) { ?>

            <section
                id="pedidosRegistrados"
                class="resumen-grid"
            >

                <div class="resumen-card">
                    <div class="resumen-titulo">Total</div>
                    <div class="resumen-numero">
                        <?php echo (int) $resumenPedidos["total_pedidos"]; ?>
                    </div>
                </div>

                <div class="resumen-card">
                    <div class="resumen-titulo">Pendientes</div>
                    <div class="resumen-numero">
                        <?php echo (int) $resumenPedidos["pendientes"]; ?>
                    </div>
                </div>

                <div class="resumen-card">
                    <div class="resumen-titulo">Preparando</div>
                    <div class="resumen-numero">
                        <?php echo (int) $resumenPedidos["preparando"]; ?>
                    </div>
                </div>

                <div class="resumen-card">
                    <div class="resumen-titulo">Entregados</div>
                    <div class="resumen-numero">
                        <?php echo (int) $resumenPedidos["entregados"]; ?>
                    </div>
                </div>

                <div class="resumen-card">
                    <div class="resumen-titulo">Cancelados</div>
                    <div class="resumen-numero">
                        <?php echo (int) $resumenPedidos["cancelados"]; ?>
                    </div>
                </div>

                <div class="resumen-card">
                    <div class="resumen-titulo">Pagos pendientes</div>
                    <div class="resumen-numero">
                        <?php echo (int) $resumenPedidos["pagos_pendientes"]; ?>
                    </div>
                </div>

                <div class="resumen-card">
                    <div class="resumen-titulo">Pagados</div>
                    <div class="resumen-numero">
                        <?php echo (int) $resumenPedidos["pagados"]; ?>
                    </div>
                </div>

                <div class="resumen-card">
                    <div class="resumen-titulo">Valor acumulado</div>
                    <div class="resumen-numero">
                        $<?php
                        echo number_format(
                            (float) $resumenPedidos["valor_total"],
                            2
                        );
                        ?>
                    </div>
                </div>

            </section>

            <?php
            $etiquetasEstado = [
                "Todos" => "Todos",
                "Pendiente" => "Pendientes",
                "Preparando" => "Preparando",
                "Entregado" => "Entregados",
                "Cancelado" => "Cancelados"
            ];
            ?>

            <div class="filtros-estado">

                <?php foreach (
                    $etiquetasEstado as $valorEstado => $textoEstado
                ) { ?>

                    <a
                        href="<?php
                        echo h(
                            urlPedidos(
                                $idClienteFiltro,
                                $valorEstado
                            )
                        );
                        ?>"
                        class="filtro-estado <?php echo $estadoFiltro === $valorEstado ? "activo" : ""; ?>"
                    >
                        <?php echo h($textoEstado); ?>

                        <span class="filtro-total">
                            <?php echo (int) $totalesEstado[$valorEstado]; ?>
                        </span>
                    </a>

                <?php } ?>

            </div>

            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

                <div>
                    <div class="pagina-etiqueta text-success">
                        REGISTROS
                    </div>

                    <h2 class="mt-2 mb-1">
                        Pedidos de
                        <?php
                        echo h(
                            $clienteSeleccionado["nombres"] .
                            " " .
                            $clienteSeleccionado["apellidos"]
                        );
                        ?>
                    </h2>

                    <p class="text-muted mb-0">
                        Solo se muestran los pedidos de este cliente.
                    </p>
                </div>

                <span class="contador">
                    <i class="bi bi-receipt"></i>
                    <?php echo $totalPedidos; ?>
                    pedidos
                </span>

            </div>

        <?php } ?>

        <?php if (
            $pedidos &&
            mysqli_num_rows($pedidos) > 0
        ) { ?>

            <?php while (
                $pedido = mysqli_fetch_assoc($pedidos)
            ) { ?>

                <?php
                $rutaImagen =
                    imagenSegura($pedido["imagen"]);

                $fechaPedido =
                    fechaPedidoFormateada(
                        $pedido["fecha_pedido"]
                    );

                $estadoActual =
                    trim((string) $pedido["estado"]);

                $siguientesEstados =
                    $transicionesPermitidas[$estadoActual] ?? [];

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

                $puedeMarcarPagado =
                    $estadoPago === "Pendiente" &&
                    $estadoActual !== "Cancelado";
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

                                        <p class="text-muted mb-0">
                                            Pedido
                                            <strong>
                                                #<?php
                                                echo (int) $pedido["id_pedido"];
                                                ?>
                                            </strong>
                                        </p>

                                    </div>

                                    <span
                                        class="estado-badge <?php echo h(claseEstado($estadoActual)); ?>"
                                    >
                                        <?php if ($estadoActual === "Pendiente") { ?>
                                            <i class="bi bi-clock"></i>
                                        <?php } elseif ($estadoActual === "Preparando") { ?>
                                            <i class="bi bi-fire"></i>
                                        <?php } elseif ($estadoActual === "Entregado") { ?>
                                            <i class="bi bi-check-circle"></i>
                                        <?php } else { ?>
                                            <i class="bi bi-x-circle"></i>
                                        <?php } ?>

                                        <?php echo h($estadoActual); ?>
                                    </span>

                                </div>

                                <div class="row g-3">

                                    <div class="col-md-6">

                                        <div class="datos-box">

                                            <h3>
                                                Información del cliente
                                            </h3>

                                            <div class="row g-3 mt-1">

                                                <div class="col-sm-6">
                                                    <span class="dato-label">
                                                        Nombre
                                                    </span>

                                                    <span class="dato-valor">
                                                        <?php
                                                        echo h(
                                                            $pedido["nombres"] .
                                                            " " .
                                                            $pedido["apellidos"]
                                                        );
                                                        ?>
                                                    </span>
                                                </div>

                                                <div class="col-sm-6">
                                                    <span class="dato-label">
                                                        Cédula
                                                    </span>

                                                    <span class="dato-valor">
                                                        <?php
                                                        echo h($pedido["cedula"]);
                                                        ?>
                                                    </span>
                                                </div>

                                                <div class="col-sm-6">
                                                    <span class="dato-label">
                                                        Teléfono
                                                    </span>

                                                    <span class="dato-valor">
                                                        <?php
                                                        echo h($pedido["telefono"]);
                                                        ?>
                                                    </span>
                                                </div>

                                                <div class="col-sm-6">
                                                    <span class="dato-label">
                                                        Correo
                                                    </span>

                                                    <span class="dato-valor">
                                                        <?php
                                                        echo h($pedido["correo"]);
                                                        ?>
                                                    </span>
                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                    <div class="col-md-6">

                                        <div class="datos-box">

                                            <h3>
                                                Detalle del pedido
                                            </h3>

                                            <div class="row g-3 mt-1">

                                                <div class="col-4">
                                                    <span class="dato-label">
                                                        Cantidad
                                                    </span>

                                                    <span class="dato-valor">
                                                        <?php
                                                        echo (int) $pedido["cantidad"];
                                                        ?>
                                                    </span>
                                                </div>

                                                <div class="col-4">
                                                    <span class="dato-label">
                                                        Unitario
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

                                                <div class="col-4">
                                                    <span class="dato-label">
                                                        Total
                                                    </span>

                                                    <div class="pedido-total">
                                                        $<?php
                                                        echo number_format(
                                                            (float) $pedido["total"],
                                                            2
                                                        );
                                                        ?>
                                                    </div>
                                                </div>

                                                <div class="col-12">
                                                    <span class="dato-label">
                                                        Fecha
                                                    </span>

                                                    <span class="dato-valor">
                                                        <?php echo h($fechaPedido); ?>
                                                    </span>
                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                </div>

                                <?php if (
                                    trim((string) $pedido["observacion"]) !== ""
                                ) { ?>

                                    <div class="mensaje mensaje-aviso mt-3 mb-0">
                                        <i class="bi bi-chat-left-text"></i>

                                        <div>
                                            <strong>Observación:</strong>
                                            <?php
                                            echo h($pedido["observacion"]);
                                            ?>
                                        </div>
                                    </div>

                                <?php } ?>

                                <div class="pago-box">

                                    <div class="pago-encabezado">

                                        <div class="pago-titulo">
                                            <i class="bi bi-wallet2"></i>
                                            Control del pago
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

                                    <div class="pago-fila">
                                        <span>Forma de pago</span>

                                        <strong>
                                            <?php echo h($formaPago); ?>
                                        </strong>
                                    </div>

                                    <?php if (
                                        $formaPago ===
                                        "Cargar a la habitación"
                                    ) { ?>

                                        <div class="text-muted small mb-2">
                                            Este consumo está asociado a la reserva,
                                            pero se cobra por separado del alojamiento.
                                        </div>

                                        <div class="pago-fila">
                                            <span>Reserva</span>

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

                                        <div class="pago-fila">
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

                                        <?php if (
                                            trim(
                                                (string) (
                                                    $pedido[
                                                        "reserva_entrada"
                                                    ] ?? ""
                                                )
                                            ) !== "" &&
                                            trim(
                                                (string) (
                                                    $pedido[
                                                        "reserva_salida"
                                                    ] ?? ""
                                                )
                                            ) !== ""
                                        ) { ?>

                                            <div class="pago-fila">
                                                <span>Estadía</span>

                                                <strong>
                                                    <?php
                                                    echo h(
                                                        fechaReservaFormateada(
                                                            $pedido[
                                                                "reserva_entrada"
                                                            ]
                                                        )
                                                    );
                                                    ?>
                                                    al
                                                    <?php
                                                    echo h(
                                                        fechaReservaFormateada(
                                                            $pedido[
                                                                "reserva_salida"
                                                            ]
                                                        )
                                                    );
                                                    ?>
                                                </strong>
                                            </div>

                                        <?php } ?>

                                    <?php } else { ?>

                                        <div class="pago-fila">
                                            <span>Cobro</span>

                                            <strong>
                                                Al entregar el pedido
                                            </strong>
                                        </div>

                                    <?php } ?>

                                    <div class="pago-fila">
                                        <span>Valor</span>

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

                                        <div class="pago-fila">
                                            <span>Fecha del pago</span>

                                            <strong>
                                                <?php
                                                echo h(
                                                    fechaPedidoFormateada(
                                                        $fechaPago
                                                    )
                                                );
                                                ?>
                                            </strong>
                                        </div>

                                    <?php } ?>

                                    <?php if ($puedeMarcarPagado) { ?>

                                        <form method="POST">

                                            <input
                                                type="hidden"
                                                name="csrf"
                                                value="<?php
                                                echo h(
                                                    $_SESSION[
                                                        "csrf_pedidos"
                                                    ]
                                                );
                                                ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="accion"
                                                value="marcar_pagado"
                                            >

                                            <input
                                                type="hidden"
                                                name="id_pedido"
                                                value="<?php
                                                echo (int)
                                                    $pedido["id_pedido"];
                                                ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn-marcar-pagado w-100"
                                                onclick="return confirm('¿Confirmar que este pedido ya fue pagado?');"
                                            >
                                                <i class="bi bi-cash-coin"></i>
                                                Marcar como pagado
                                            </button>

                                        </form>

                                    <?php } elseif (
                                        $estadoActual === "Cancelado" &&
                                        $estadoPago !== "Pagado"
                                    ) { ?>

                                        <div class="text-muted small mt-3">
                                            Un pedido cancelado no puede
                                            registrarse como pagado.
                                        </div>

                                    <?php } ?>

                                </div>

                                <?php if (!empty($siguientesEstados)) { ?>

                                    <form
                                        method="POST"
                                        class="formulario-estado"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf"
                                            value="<?php echo h($_SESSION["csrf_pedidos"]); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="id_pedido"
                                            value="<?php echo (int) $pedido["id_pedido"]; ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="accion"
                                            value="actualizar_estado"
                                        >

                                        <div class="row g-3 align-items-end">

                                            <div class="col-md-8">

                                                <span class="form-label d-block">
                                                    Siguiente estado
                                                </span>

                                                <div class="opciones-estado">

                                                    <?php foreach (
                                                        $siguientesEstados as $indiceEstado => $estadoDisponible
                                                    ) { ?>

                                                        <label class="opcion-estado">

                                                            <input
                                                                type="radio"
                                                                name="estado"
                                                                value="<?php echo h($estadoDisponible); ?>"
                                                                <?php echo $indiceEstado === 0 ? "required" : ""; ?>
                                                            >

                                                            <span class="opcion-estado-contenido">
                                                                <i class="bi <?php
                                                                echo $estadoDisponible === "Cancelado"
                                                                    ? "bi-x-circle"
                                                                    : (
                                                                        $estadoDisponible === "Preparando"
                                                                            ? "bi-fire"
                                                                            : "bi-check-circle"
                                                                    );
                                                                ?>"></i>

                                                                <?php echo h($estadoDisponible); ?>
                                                            </span>

                                                        </label>

                                                    <?php } ?>

                                                </div>

                                            </div>

                                            <div class="col-md-4">

                                                <button
                                                    type="submit"
                                                    class="btn-actualizar w-100"
                                                >
                                                    <i class="bi bi-arrow-repeat"></i>
                                                    Actualizar estado
                                                </button>

                                            </div>

                                        </div>

                                    </form>

                                <?php } else { ?>

                                    <div class="pedido-final">

                                        <i class="bi bi-shield-check me-1"></i>

                                        Este pedido está
                                        <strong>
                                            <?php echo h(strtolower($estadoActual)); ?>
                                        </strong>
                                        y se conserva como historial.
                                        Su estado ya no puede cambiarse.

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
                    <i class="bi bi-receipt"></i>
                </div>

                <h2>
                    <?php if (!$clienteSeleccionado) { ?>
                        No existen pedidos registrados
                    <?php } else { ?>
                        No hay pedidos en esta categoría
                    <?php } ?>
                </h2>

                <p class="text-muted mb-0">
                    <?php if (!$clienteSeleccionado) { ?>
                        Los pedidos realizados por los clientes aparecerán aquí.
                    <?php } else { ?>
                        Selecciona otro estado para revisar el historial del cliente.
                    <?php } ?>
                </p>

            </div>

        <?php } ?>

        <?php if ($clienteSeleccionado && $totalPedidos > 0) { ?>

            <div class="paginacion-contenedor">

                <div class="paginacion-info">
                    Mostrando
                    <?php echo $primerRegistro; ?>
                    -
                    <?php echo $ultimoRegistro; ?>
                    de
                    <?php echo $totalPedidos; ?>
                    pedidos
                </div>

                <?php if ($totalPaginas > 1) { ?>

                    <nav
                        class="paginacion-hotel"
                        aria-label="Paginación de pedidos"
                    >

                        <?php if ($paginaActual > 1) { ?>

                            <a href="<?php echo h(urlPedidos($idClienteFiltro, $estadoFiltro, $paginaActual - 1)); ?>">
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

                            <a href="<?php echo h(urlPedidos($idClienteFiltro, $estadoFiltro, 1)); ?>">1</a>

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

                                <a href="<?php echo h(urlPedidos($idClienteFiltro, $estadoFiltro, $pagina)); ?>">
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

                            <a href="<?php echo h(urlPedidos($idClienteFiltro, $estadoFiltro, $totalPaginas)); ?>">
                                <?php echo $totalPaginas; ?>
                            </a>

                        <?php } ?>

                        <?php if ($paginaActual < $totalPaginas) { ?>

                            <a href="<?php echo h(urlPedidos($idClienteFiltro, $estadoFiltro, $paginaActual + 1)); ?>">
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
                Módulo de pedidos
            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

</body>

</html>