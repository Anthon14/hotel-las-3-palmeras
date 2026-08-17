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
    $fecha = trim((string) $fecha);

    if ($fecha === "") {
        return "-";
    }

    $tiempo = strtotime($fecha);

    if ($tiempo === false) {
        return $fecha;
    }

    return date("d/m/Y h:i A", $tiempo);
}

function urlReservasListado(
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

    return "index.php?" . http_build_query($parametros) . "#reservasRegistradas";
}

if (empty($_SESSION["csrf_reservas"])) {
    $_SESSION["csrf_reservas"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_reservas"];
$errores = [];

$idCliente = "";
$idHabitacion = "";
$fechaEntrada = "";
$fechaSalida = "";
$estado = "Pendiente";
$numeroPersonas = 1;
$planAlimentacion = "Solo alojamiento";

$precioDesayuno = 5.00;

$estadosPermitidos = [
    "Pendiente",
    "Confirmada"
];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar"])) {
    $csrfRecibido = $_POST["csrf"] ?? "";
    $idCliente = trim($_POST["id_cliente"] ?? "");
    $idHabitacion = trim($_POST["id_habitacion"] ?? "");
    $fechaEntrada = trim($_POST["fecha_entrada"] ?? "");
    $fechaSalida = trim($_POST["fecha_salida"] ?? "");
    $estado = trim($_POST["estado"] ?? "Pendiente");

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

    if (!filter_var($idCliente, FILTER_VALIDATE_INT)) {
        $errores[] = "Seleccione un cliente válido.";
    }

    if (!filter_var($idHabitacion, FILTER_VALIDATE_INT)) {
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
        $errores[] = "La fecha de salida debe ser mayor que la fecha de entrada.";
    } else {
        $hoy = new DateTimeImmutable("today");

        if ($entrada < $hoy) {
            $errores[] = "La fecha de entrada no puede ser anterior a hoy.";
        }
    }

    if (empty($errores)) {
        $idClienteEntero = (int) $idCliente;
        $idHabitacionEntero = (int) $idHabitacion;

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
            mysqli_stmt_bind_param(
                $consultaCliente,
                "i",
                $idClienteEntero
            );

            mysqli_stmt_execute($consultaCliente);

            $resultadoCliente =
                mysqli_stmt_get_result($consultaCliente);

            if (mysqli_num_rows($resultadoCliente) === 0) {
                $errores[] = "El cliente seleccionado no existe.";
            }

            mysqli_stmt_close($consultaCliente);
        }

        $precioHabitacion = 0.0;
        $estadoHabitacion = "";
        $capacidadHabitacion = 0;

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
                $idHabitacionEntero
            );

            mysqli_stmt_execute($consultaHabitacion);

            $resultadoHabitacion =
                mysqli_stmt_get_result($consultaHabitacion);

            $datosHabitacion =
                mysqli_fetch_assoc($resultadoHabitacion);

            mysqli_stmt_close($consultaHabitacion);

            if (!$datosHabitacion) {
                $errores[] = "La habitación seleccionada no existe.";
            } else {
                $precioHabitacion =
                    (float) $datosHabitacion["precio"];

                $estadoHabitacion =
                    trim((string) $datosHabitacion["estado"]);

                $capacidadHabitacion =
                    (int) $datosHabitacion["capacidad"];

                if ($estadoHabitacion === "Mantenimiento") {
                    $errores[] =
                        "La habitación está en mantenimiento y no puede reservarse.";
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

        if (empty($errores)) {
            
            $consultaCruce = mysqli_prepare(
                $conn,
                "SELECT id_reserva
                 FROM reservas
                 WHERE id_habitacion = ?
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
                    "iss",
                    $idHabitacionEntero,
                    $fechaSalida,
                    $fechaEntrada
                );

                mysqli_stmt_execute($consultaCruce);

                $resultadoCruce =
                    mysqli_stmt_get_result($consultaCruce);

                if (mysqli_num_rows($resultadoCruce) > 0) {
                    $errores[] =
                        "La habitación ya tiene una reserva pendiente o confirmada en esas fechas.";
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
                $errores[] =
                    "No se pudo preparar el registro de la reserva.";
            } else {
                mysqli_stmt_bind_param(
                    $guardarReserva,
                    "iissisddsd",
                    $idClienteEntero,
                    $idHabitacionEntero,
                    $fechaEntrada,
                    $fechaSalida,
                    $numeroPersonas,
                    $planAlimentacion,
                    $precioDesayuno,
                    $totalAlimentacion,
                    $estado,
                    $total
                );

                if (mysqli_stmt_execute($guardarReserva)) {
                    mysqli_stmt_close($guardarReserva);

                    header(
                        "Location: " .
                        urlReservasListado(
                            $idClienteEntero,
                            "Todos",
                            1,
                            "guardado"
                        )
                    );
                    exit();
                }

                mysqli_stmt_close($guardarReserva);

                $errores[] =
                    "No se pudo guardar la reserva.";
            }
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

$habitaciones = mysqli_query(
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
     ORDER BY numero"
);

$clientesReservas = [];

$consultaClientesReservas = mysqli_query(
    $conn,
    "SELECT
        c.id_cliente,
        c.nombres,
        c.apellidos,
        c.cedula,
        COUNT(r.id_reserva) AS total_reservas
     FROM clientes c
     INNER JOIN reservas r
        ON r.id_cliente = c.id_cliente
     GROUP BY
        c.id_cliente,
        c.nombres,
        c.apellidos,
        c.cedula
     ORDER BY
        c.nombres ASC,
        c.apellidos ASC"
);

if ($consultaClientesReservas) {
    while (
        $clienteReserva =
            mysqli_fetch_assoc($consultaClientesReservas)
    ) {
        $clientesReservas[] = $clienteReserva;
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

foreach ($clientesReservas as $clienteReserva) {
    if (
        (int) $clienteReserva["id_cliente"] ===
        $idClienteFiltro
    ) {
        $clienteSeleccionado = $clienteReserva;
        break;
    }
}

if (
    $clienteSeleccionado === null &&
    !empty($clientesReservas)
) {
    $clienteSeleccionado = $clientesReservas[0];

    $idClienteFiltro =
        (int) $clienteSeleccionado["id_cliente"];
}

$estadosFiltroPermitidos = [
    "Todos",
    "Pendiente",
    "Confirmada",
    "Finalizada",
    "Cancelada"
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

$resumenReservas = [
    "total" => 0,
    "pendientes" => 0,
    "confirmadas" => 0,
    "finalizadas" => 0,
    "canceladas" => 0
];

if ($idClienteFiltro > 0) {
    $consultaResumenReservas = mysqli_prepare(
        $conn,
        "SELECT
            COUNT(*) AS total,
            COALESCE(
                SUM(estado = 'Pendiente'),
                0
            ) AS pendientes,
            COALESCE(
                SUM(estado = 'Confirmada'),
                0
            ) AS confirmadas,
            COALESCE(
                SUM(estado = 'Finalizada'),
                0
            ) AS finalizadas,
            COALESCE(
                SUM(estado = 'Cancelada'),
                0
            ) AS canceladas
         FROM reservas
         WHERE id_cliente = ?"
    );

    if ($consultaResumenReservas) {
        mysqli_stmt_bind_param(
            $consultaResumenReservas,
            "i",
            $idClienteFiltro
        );

        if (mysqli_stmt_execute($consultaResumenReservas)) {
            $resultadoResumenReservas =
                mysqli_stmt_get_result($consultaResumenReservas);

            $datosResumenReservas =
                mysqli_fetch_assoc($resultadoResumenReservas);

            if ($datosResumenReservas) {
                $resumenReservas = $datosResumenReservas;
            }
        }

        mysqli_stmt_close($consultaResumenReservas);
    }
}

$totalesEstado = [
    "Todos" =>
        (int) $resumenReservas["total"],
    "Pendiente" =>
        (int) $resumenReservas["pendientes"],
    "Confirmada" =>
        (int) $resumenReservas["confirmadas"],
    "Finalizada" =>
        (int) $resumenReservas["finalizadas"],
    "Cancelada" =>
        (int) $resumenReservas["canceladas"]
];

$totalReservas =
    (int) ($totalesEstado[$estadoFiltro] ?? 0);

$porPagina = 10;

$paginaActual = max(
    1,
    (int) ($_GET["pagina"] ?? 1)
);

$totalPaginas = max(
    1,
    (int) ceil($totalReservas / $porPagina)
);

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset =
    ($paginaActual - 1) * $porPagina;

$reservas = false;

if ($idClienteFiltro > 0) {
    $sqlReservas =
        "SELECT
            r.id_reserva,
            r.id_cliente,
            c.nombres,
            c.apellidos,
            h.numero,
            h.tipo,
            r.fecha_entrada,
            r.fecha_salida,
            r.numero_personas,
            r.plan_alimentacion,
            r.precio_desayuno,
            r.total_alimentacion,
            r.estado,
            r.total
         FROM reservas r
         INNER JOIN clientes c
            ON r.id_cliente = c.id_cliente
         INNER JOIN habitaciones h
            ON r.id_habitacion = h.id_habitacion
         WHERE r.id_cliente = ?";

    if ($estadoFiltro !== "Todos") {
        $sqlReservas .=
            " AND r.estado = ?";
    }

    $sqlReservas .=
        " ORDER BY
            CASE
                WHEN r.estado = 'Pendiente' THEN 0
                WHEN r.estado = 'Confirmada' THEN 1
                WHEN r.estado = 'Finalizada' THEN 2
                WHEN r.estado = 'Cancelada' THEN 3
                ELSE 4
            END,
            r.fecha_entrada DESC,
            r.id_reserva DESC
          LIMIT ? OFFSET ?";

    $consultaReservas =
        mysqli_prepare($conn, $sqlReservas);

    if ($consultaReservas) {
        if ($estadoFiltro === "Todos") {
            mysqli_stmt_bind_param(
                $consultaReservas,
                "iii",
                $idClienteFiltro,
                $porPagina,
                $offset
            );
        } else {
            mysqli_stmt_bind_param(
                $consultaReservas,
                "isii",
                $idClienteFiltro,
                $estadoFiltro,
                $porPagina,
                $offset
            );
        }

        if (mysqli_stmt_execute($consultaReservas)) {
            $reservas =
                mysqli_stmt_get_result($consultaReservas);
        }

        mysqli_stmt_close($consultaReservas);
    }
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
            mysqli_fetch_assoc(
                $consultaCantidadPagos
            );

        $pagosPendientes =
            (int) (
                $filaCantidadPagos["total"] ?? 0
            );
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

$fechaMinima = date("Y-m-d");
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
        Reservas - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=62"
    >

    <style>
        :root {
            --verde: #244a35;
            --verde-oscuro: #173325;
            --verde-claro: #e9f0eb;
            --dorado: #d8b56d;
            --crema: #f7f3eb;
            --texto-suave: #687068;
            --sombra:
                0 18px 45px
                rgba(21, 45, 32, .12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background-color: var(--crema);
            color: #20231f;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
        }

        a {
            text-decoration: none;
        }

        .navbar-hotel {
            min-height: 82px;
            background-color:
                rgba(18, 39, 28, .98);
            border-bottom:
                1px solid
                rgba(255, 255, 255, .13);
            box-shadow:
                0 8px 24px
                rgba(0, 0, 0, .15);
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
            color:
                rgba(255, 255, 255, .83);
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
            color:
                rgba(255, 255, 255, .67);
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
            background-color: rgba(255, 255, 255, .08);
            color: white;
            font-size: 17px;
            transition:
                background-color .2s ease,
                border-color .2s ease,
                transform .2s ease;
        }

        .btn-notificaciones-admin:hover,
        .btn-notificaciones-admin:focus {
            border-color: rgba(240, 217, 159, .75);
            background-color: rgba(255, 255, 255, .15);
            color: white;
            transform: translateY(-1px);
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
            border: 1px solid #dde2dd;
            border-radius: 12px;
            background-color: white;
            box-shadow:
                0 18px 46px
                rgba(14, 35, 23, .20);
        }

        .notificaciones-admin-cabecera {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 17px 18px;
            border-bottom: 1px solid #e8ebe7;
            background-color: #fbfcfa;
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
            background-color: var(--verde);
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
            padding: 15px 18px;
            border-bottom: 1px solid #edf0ec;
            color: #20231f;
            transition: background-color .2s ease;
        }

        .notificacion-pago-admin:hover {
            background-color: #f4f8f5;
            color: #20231f;
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
            margin-top: 5px;
            color: var(--verde) !important;
            font-weight: 900;
        }

        .notificaciones-admin-vacio {
            padding: 28px 20px;
            color: var(--texto-suave);
            text-align: center;
            font-size: 12px;
        }

        .notificaciones-admin-vacio i {
            display: block;
            margin-bottom: 8px;
            color: var(--verde);
            font-size: 26px;
        }

        .notificaciones-admin-pie {
            padding: 13px 18px;
            border-top: 1px solid #e8ebe7;
            background-color: #fbfcfa;
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

        .notificaciones-admin-pie a:hover {
            color: var(--verde-oscuro);
        }

        .pagina-hero {
            min-height: 390px;
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
                url("../img/hotel.jpg")
                center/cover;
        }

        .pagina-hero-contenido {
            max-width: 760px;
            padding: 70px 0;
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
            font-size:
                clamp(2.8rem, 6vw, 5.2rem);
            font-weight: 700;
        }

        .pagina-hero p {
            max-width: 670px;
            color:
                rgba(255, 255, 255, .82);
            line-height: 1.7;
        }

        .contenido-pagina {
            padding: 75px 0;
        }

        .seccion-etiqueta {
            color: #9b7739;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 2px;
        }

        .seccion-titulo {
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-weight: 700;
        }

        .seccion-texto {
            color: var(--texto-suave);
            font-size: 14px;
            line-height: 1.7;
        }

        .mensaje {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
            padding: 14px 17px;
            border-radius: 6px;
            font-size: 13px;
        }

        .mensaje-exito {
            border: 1px solid #b8ddc2;
            background-color: #edf8f0;
            color: #24643a;
        }

        .mensaje-error {
            border: 1px solid #edc8c8;
            background-color: #fff1f1;
            color: #9b3131;
        }

        .formulario-card,
        .tabla-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 8px;
            background-color: white;
            box-shadow: var(--sombra);
        }

        .formulario-card {
            margin-bottom: 70px;
        }

        .formulario-cabecera {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 24px 27px;
            border-bottom:
                1px solid #e6e7e1;
            background-color: #fbfcfa;
        }

        .formulario-icono {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            flex: 0 0 48px;
            border-radius: 50%;
            background-color:
                var(--verde-claro);
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
            padding: 28px;
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
            box-shadow:
                0 0 0 4px
                rgba(36, 74, 53, .10);
        }

        .form-text {
            color: var(--texto-suave);
            font-size: 11px;
        }

        .estado-opciones {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            width: 100%;
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
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(35, 55, 42, .08);
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
            margin: 0 0 3px;
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
            box-shadow: 0 10px 25px rgba(36, 74, 53, .12);
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
            min-height: 105px;
            height: 100%;
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            border: 1px solid #dce1dc;
            border-radius: 11px;
            background-color: #fbfcfa;
            transition:
                transform .18s ease,
                border-color .18s ease,
                background-color .18s ease,
                box-shadow .18s ease;
        }

        .estado-opcion:hover .estado-opcion-contenido {
            border-color: #b9c4bb;
            background-color: white;
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
            background-color: #edf1ed;
            color: #566158;
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
            width: 23px;
            height: 23px;
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

        .estado-opcion input:checked +
        .estado-opcion-contenido .estado-opcion-check {
            display: grid;
        }

        .estado-pendiente input:checked + .estado-opcion-contenido {
            border-color: #c99a24;
            background-color: #fff9ea;
        }

        .estado-pendiente input:checked +
        .estado-opcion-contenido .estado-opcion-icono,
        .estado-pendiente input:checked +
        .estado-opcion-contenido .estado-opcion-check {
            background-color: #c99a24;
            color: white;
        }

        .estado-confirmada input:checked + .estado-opcion-contenido {
            border-color: #3b8156;
            background-color: #f1f9f3;
        }

        .estado-confirmada input:checked +
        .estado-opcion-contenido .estado-opcion-icono,
        .estado-confirmada input:checked +
        .estado-opcion-contenido .estado-opcion-check {
            background-color: #3b8156;
            color: white;
        }

        .estado-finalizada input:checked + .estado-opcion-contenido {
            border-color: #5477a8;
            background-color: #f2f6fc;
        }

        .estado-finalizada input:checked +
        .estado-opcion-contenido .estado-opcion-icono,
        .estado-finalizada input:checked +
        .estado-opcion-contenido .estado-opcion-check {
            background-color: #5477a8;
            color: white;
        }

        .estado-cancelada input:checked + .estado-opcion-contenido {
            border-color: #b65050;
            background-color: #fff4f4;
        }

        .estado-cancelada input:checked +
        .estado-opcion-contenido .estado-opcion-icono,
        .estado-cancelada input:checked +
        .estado-opcion-contenido .estado-opcion-check {
            background-color: #b65050;
            color: white;
        }

        .btn-guardar {
            min-height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            border: 1px solid var(--verde);
            border-radius: 10px;
            padding: 12px 21px;
            background-color: var(--verde);
            color: white;
            font-size: 13px;
            font-weight: 900;
            box-shadow: 0 8px 20px rgba(36, 74, 53, .18);
            transition:
                transform .18s ease,
                background-color .18s ease,
                box-shadow .18s ease;
        }

        .btn-guardar:hover {
            background-color: var(--verde-oscuro);
            color: white;
            transform: translateY(-1px);
            box-shadow: 0 11px 24px rgba(23, 51, 37, .24);
        }

        .contador {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 13px;
            border-radius: 999px;
            background-color:
                var(--verde-claro);
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
            background-color: white;
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
            background-color: var(--verde-claro);
            color: var(--verde);
            font-size: 10px;
            font-weight: 900;
        }

        .selector-huesped-form {
            display: flex;
            gap: 9px;
        }

        .selector-huesped-form .form-select {
            flex: 1;
        }

        .btn-ver-reservas {
            min-height: 49px;
            padding: 10px 17px;
            border: 1px solid var(--verde);
            border-radius: 7px;
            background-color: var(--verde);
            color: white;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .btn-ver-reservas:hover {
            background-color: var(--verde-oscuro);
            color: white;
        }

        .filtros-reservas {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 22px;
        }

        .filtro-reserva {
            min-height: 39px;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 12px;
            border: 1px solid #d9ded8;
            border-radius: 999px;
            background-color: white;
            color: #586159;
            font-size: 11px;
            font-weight: 900;
        }

        .filtro-reserva:hover {
            border-color: var(--verde);
            color: var(--verde);
        }

        .filtro-reserva.activo {
            border-color: var(--verde);
            background-color: var(--verde);
            color: white;
        }

        .filtro-reserva-total {
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

        .filtro-reserva.activo .filtro-reserva-total {
            background-color: rgba(255, 255, 255, .16);
            color: white;
        }

        .tabla-hotel {
            margin: 0;
        }

        .tabla-hotel thead th {
            padding: 15px 16px;
            border: 0;
            background-color:
                var(--verde-oscuro);
            color: white;
            font-size: 11px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .tabla-hotel tbody td {
            padding: 15px 16px;
            font-size: 13px;
            vertical-align: middle;
        }

        .estado-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
        }

        .estado-confirmada {
            background-color: #dff2e4;
            color: #21643b;
        }

        .estado-pendiente {
            background-color: #fff0c7;
            color: #81600d;
        }

        .estado-finalizada {
            background-color: #e2ecff;
            color: #285eab;
        }

        .estado-cancelada {
            background-color: #fff0f0;
            color: #9d3030;
        }

        .plan-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 9px;
            border-radius: 999px;
            background-color: var(--verde-claro);
            color: var(--verde);
            font-size: 10px;
            font-weight: 900;
            white-space: nowrap;
        }

        .plan-detalle {
            display: block;
            margin-top: 5px;
            color: var(--texto-suave);
            font-size: 10px;
            line-height: 1.45;
        }

        .calculo-reserva {
            height: 100%;
            padding: 14px;
            border: 1px solid #dfe4de;
            border-radius: 7px;
            background: #f4f7f4;
        }

        .calculo-reserva small {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .calculo-reserva strong {
            display: block;
            margin-top: 5px;
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 23px;
        }

        .acciones {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .paginacion-contenedor {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 15px;

            padding: 18px 20px;

            border-top:
                1px solid #e7e9e4;

            background-color: #fbfcfa;
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

            border:
                1px solid #dce1dc;

            border-radius: 5px;

            background-color: white;
            color: var(--verde);

            font-size: 12px;
            font-weight: 800;
        }

        .paginacion-hotel a:hover {
            border-color: var(--verde);
            background-color: var(--verde-claro);
            color: var(--verde-oscuro);
        }

        .paginacion-hotel .pagina-activa {
            border-color: var(--verde);
            background-color: var(--verde);
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
            background-color: transparent;
        }

        .btn-editar,
        .btn-eliminar {
            padding: 8px 11px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 900;
        }

        .btn-editar {
            border: 1px solid #d4b25e;
            background-color: #fff5d9;
            color: #72550d;
        }

        .btn-eliminar {
            border: 1px solid #e0a9a9;
            background-color: #fff0f0;
            color: #9d3030;
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
            border-top:
                1px solid
                rgba(255, 255, 255, .10);
            color:
                rgba(255, 255, 255, .52);
            font-size: 12px;
        }

        @media (max-width: 991px) {
            .plan-opciones {
                grid-template-columns: 1fr;
            }

            .selector-huesped {
                grid-template-columns: 1fr;
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
                min-height: 360px;
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

            .menu-notificaciones-admin {
                width:
                    min(
                        390px,
                        calc(100vw - 24px)
                    );
            }

            .formulario-cabecera {
                align-items: flex-start;
                padding: 20px;
            }

            .selector-huesped-form {
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
                min-height: 90px;
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
                font-size: 2.45rem;
            }

            .pagina-hero p {
                font-size: 14px;
            }

            .contenido-pagina {
                padding: 48px 0;
            }

            .formulario-cuerpo {
                padding: 18px;
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
            data-bs-target="#menuPrincipal"
            aria-controls="menuPrincipal"
            aria-expanded="false"
            aria-label="Abrir menú"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div
            class="collapse navbar-collapse"
            id="menuPrincipal"
        >
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">

                <li class="nav-item">
                    <a
                        href="../dashboard.php"
                        class="nav-link"
                    >
                        Inicio
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="../habitaciones/index.php"
                        class="nav-link"
                    >
                        Habitaciones
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="index.php"
                        class="nav-link active"
                    >
                        Reservas
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="../comidas/index.php"
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
                                        Pagos pendientes de aprobación
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
                                            href="../pagos/index.php?cliente=<?php echo (int) $notificacionPago["id_cliente"]; ?>&estado=Pendiente"
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
                                                        ·
                                                        <?php
                                                        echo h(
                                                            formatearFechaNotificacion(
                                                                $notificacionPago[
                                                                    "fecha_pago"
                                                                ]
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
                ADMINISTRACIÓN HOTELERA
            </div>

            <h1>
                Gestión de reservas
            </h1>

            <p>
                Registra alojamientos, número de huéspedes,
                desayuno, total de la reserva y evita cruces
                de habitaciones en las mismas fechas.
            </p>

        </div>

    </div>

</section>

<main class="contenido-pagina">

    <div class="container">

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "guardado"
        ) { ?>

            <div class="mensaje mensaje-exito">

                <i class="bi bi-check-circle"></i>

                Reserva guardada correctamente.

            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "actualizado"
        ) { ?>

            <div class="mensaje mensaje-exito">

                <i class="bi bi-check-circle"></i>

                Reserva actualizada correctamente.

            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "eliminado"
        ) { ?>

            <div class="mensaje mensaje-exito">

                <i class="bi bi-check-circle"></i>

                Reserva eliminada correctamente.

            </div>

        <?php } ?>

        <?php if (!empty($errores)) { ?>

            <div class="mensaje mensaje-error">

                <i class="bi bi-exclamation-circle"></i>

                <div>

                    <strong>
                        No se pudo registrar:
                    </strong>

                    <ul class="mt-2 mb-0">

                        <?php foreach ($errores as $error) { ?>

                            <li>
                                <?php echo h($error); ?>
                            </li>

                        <?php } ?>

                    </ul>

                </div>

            </div>

        <?php } ?>

        <section>

            <p class="seccion-etiqueta">
                NUEVA RESERVA
            </p>

            <h2 class="seccion-titulo">
                Registrar alojamiento
            </h2>

            <p class="seccion-texto mb-4">
                Las habitaciones en mantenimiento no aparecen.
                Las fechas se comprueban antes de guardar.
            </p>

            <div class="formulario-card">

                <div class="formulario-cabecera">

                    <div class="formulario-icono">
                        <i class="bi bi-calendar-plus"></i>
                    </div>

                    <div>

                        <h3>
                            Datos de la reserva
                        </h3>

                        <p>
                            Selecciona cliente, habitación, personas,
                            fechas y plan de alojamiento.
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

                                <label
                                    for="id_cliente"
                                    class="form-label"
                                >
                                    Cliente
                                </label>

                                <select
                                    id="id_cliente"
                                    name="id_cliente"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Seleccione un cliente
                                    </option>

                                    <?php if ($clientes) { ?>

                                        <?php while (
                                            $cliente =
                                                mysqli_fetch_assoc($clientes)
                                        ) { ?>

                                            <option
                                                value="<?php echo (int) $cliente["id_cliente"]; ?>"
                                                <?php
                                                echo (string) $idCliente ===
                                                    (string) $cliente["id_cliente"]
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

                                <label
                                    for="id_habitacion"
                                    class="form-label"
                                >
                                    Habitación
                                </label>

                                <select
                                    id="id_habitacion"
                                    name="id_habitacion"
                                    class="form-select"
                                    required
                                >

                                    <option
                                        value=""
                                        data-precio="0"
                                    >
                                        Seleccione una habitación
                                    </option>

                                    <?php if ($habitaciones) { ?>

                                        <?php while (
                                            $habitacion =
                                                mysqli_fetch_assoc($habitaciones)
                                        ) { ?>

                                            <option
                                                value="<?php echo (int) $habitacion["id_habitacion"]; ?>"
                                                data-precio="<?php echo h($habitacion["precio"]); ?>"
                                                data-capacidad="<?php echo (int) $habitacion["capacidad"]; ?>"
                                                <?php
                                                echo (string) $idHabitacion ===
                                                    (string) $habitacion["id_habitacion"]
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
                                            </option>

                                        <?php } ?>

                                    <?php } ?>

                                </select>

                                <div class="form-text">
                                    La disponibilidad se valida
                                    según las fechas elegidas.
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
                                    max="1"
                                    value="<?php echo (int) $numeroPersonas; ?>"
                                    required
                                >

                                <div
                                    class="form-text"
                                    id="texto_capacidad"
                                >
                                    Selecciona primero una habitación.
                                </div>

                            </div>

                            <div class="col-12">

                                <label class="form-label d-block mb-2">
                                    Estado inicial
                                </label>

                                <?php
                                $iconosEstado = [
                                    "Pendiente" => "bi-clock",
                                    "Confirmada" => "bi-check-circle"
                                ];
                                ?>

                                <div class="estado-opciones">

                                    <?php foreach (
                                        $estadosPermitidos as $estadoPermitido
                                    ) { ?>

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
                                                            Reserva registrada a la espera de confirmación.
                                                        <?php } else { ?>
                                                            Reserva aprobada para la estadía.
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
                                    Al registrar una reserva solo puede quedar
                                    Pendiente o Confirmada.
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
                                                    Incluye únicamente la habitación
                                                    durante las fechas seleccionadas.
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
                                                    Alojamiento con desayuno
                                                </strong>

                                                <small>
                                                    Agrega desayuno para cada huésped
                                                    durante cada noche de la reserva.
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
                                    El total se actualiza automáticamente
                                    según el plan seleccionado.
                                </div>

                            </div>

                            <div class="col-md-6 col-lg-3">

                                <label
                                    for="fecha_entrada"
                                    class="form-label"
                                >
                                    Fecha de entrada
                                </label>

                                <input
                                    type="date"
                                    id="fecha_entrada"
                                    name="fecha_entrada"
                                    class="form-control"
                                    min="<?php echo h($fechaMinima); ?>"
                                    value="<?php echo h($fechaEntrada); ?>"
                                    required
                                >

                            </div>

                            <div class="col-md-6 col-lg-3">

                                <label
                                    for="fecha_salida"
                                    class="form-label"
                                >
                                    Fecha de salida
                                </label>

                                <input
                                    type="date"
                                    id="fecha_salida"
                                    name="fecha_salida"
                                    class="form-control"
                                    min="<?php echo h($fechaMinima); ?>"
                                    value="<?php echo h($fechaSalida); ?>"
                                    required
                                >

                            </div>

                            <div class="col-md-6 col-lg-3">

                                <div class="calculo-reserva">
                                    <small>
                                        Habitación
                                    </small>

                                    <strong id="subtotal_habitacion">
                                        $0.00
                                    </strong>
                                </div>

                            </div>

                            <div class="col-md-6 col-lg-3">

                                <div class="calculo-reserva">
                                    <small>
                                        Desayuno
                                    </small>

                                    <strong id="total_desayuno">
                                        $0.00
                                    </strong>
                                </div>

                            </div>

                            <div class="col-md-6 col-lg-3">

                                <div class="calculo-reserva">
                                    <small>
                                        Total estimado
                                    </small>

                                    <strong id="total_estimado">
                                        $0.00
                                    </strong>
                                </div>

                            </div>

                            <div class="col-md-6 col-lg-3 d-flex align-items-stretch">

                                <button
                                    type="submit"
                                    name="guardar"
                                    class="btn-guardar w-100"
                                >
                                    <i class="bi bi-calendar-check"></i>
                                    Guardar reserva
                                </button>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

        </section>

        <section id="reservasRegistradas">

            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

                <div>
                    <p class="seccion-etiqueta">
                        REGISTROS
                    </p>

                    <h2 class="seccion-titulo">
                        Reservas por huésped
                    </h2>

                    <p class="seccion-texto mb-0">
                        Selecciona un huésped y luego filtra sus reservas por estado.
                    </p>
                </div>

                <?php if ($clienteSeleccionado) { ?>

                    <span class="contador">
                        <i class="bi bi-calendar3"></i>
                        <?php echo (int) $resumenReservas["total"]; ?>
                        reservas
                    </span>

                <?php } ?>

            </div>

            <div class="selector-huesped">

                <div>
                    <div class="seccion-etiqueta">
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
                            Solo se mostrarán las reservas pertenecientes
                            a este huésped.
                        </p>

                        <span class="huesped-seleccionado">
                            <i class="bi bi-person-check"></i>
                            Cédula:
                            <?php echo h($clienteSeleccionado["cedula"]); ?>
                        </span>

                    <?php } else { ?>

                        <h3>No hay huéspedes con reservas</h3>

                        <p>
                            Cuando se registre una reserva,
                            el huésped aparecerá en este listado.
                        </p>

                    <?php } ?>

                </div>

                <?php if (!empty($clientesReservas)) { ?>

                    <form
                        method="GET"
                        action="index.php#reservasRegistradas"
                        class="selector-huesped-form"
                    >

                        <select
                            name="cliente"
                            class="form-select"
                            required
                        >

                            <?php foreach (
                                $clientesReservas as $clienteReserva
                            ) { ?>

                                <option
                                    value="<?php echo (int) $clienteReserva["id_cliente"]; ?>"
                                    <?php
                                    echo (int) $clienteReserva["id_cliente"] ===
                                        $idClienteFiltro
                                            ? "selected"
                                            : "";
                                    ?>
                                >
                                    <?php
                                    echo h(
                                        $clienteReserva["nombres"] .
                                        " " .
                                        $clienteReserva["apellidos"] .
                                        " - " .
                                        $clienteReserva["cedula"]
                                    );
                                    ?>
                                    (<?php echo (int) $clienteReserva["total_reservas"]; ?>)
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
                            class="btn-ver-reservas"
                        >
                            <i class="bi bi-search me-1"></i>
                            Ver reservas
                        </button>

                    </form>

                <?php } ?>

            </div>

            <?php if ($clienteSeleccionado) { ?>

                <?php
                $etiquetasEstado = [
                    "Todos" => "Todas",
                    "Pendiente" => "Pendientes",
                    "Confirmada" => "Confirmadas",
                    "Finalizada" => "Finalizadas",
                    "Cancelada" => "Canceladas"
                ];
                ?>

                <div class="filtros-reservas">

                    <?php foreach (
                        $etiquetasEstado as $valorEstado => $textoEstado
                    ) { ?>

                        <a
                            href="<?php
                            echo h(
                                urlReservasListado(
                                    $idClienteFiltro,
                                    $valorEstado
                                )
                            );
                            ?>"
                            class="filtro-reserva <?php echo $estadoFiltro === $valorEstado ? "activo" : ""; ?>"
                        >
                            <?php echo h($textoEstado); ?>

                            <span class="filtro-reserva-total">
                                <?php echo (int) $totalesEstado[$valorEstado]; ?>
                            </span>
                        </a>

                    <?php } ?>

                </div>

                <div class="tabla-card">

                    <div class="table-responsive">

                        <table class="table tabla-hotel align-middle">

                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Habitación</th>
                                    <th>Entrada</th>
                                    <th>Salida</th>
                                    <th>Personas</th>
                                    <th>Plan</th>
                                    <th>Desayuno</th>
                                    <th>Estado</th>
                                    <th>Total</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if (
                                    $reservas &&
                                    mysqli_num_rows($reservas) > 0
                                ) { ?>

                                    <?php while (
                                        $row =
                                            mysqli_fetch_assoc($reservas)
                                    ) { ?>

                                        <tr>

                                            <td>
                                                <?php echo (int) $row["id_reserva"]; ?>
                                            </td>

                                            <td>
                                                Hab.
                                                <?php echo h($row["numero"]); ?>
                                                -
                                                <?php echo h($row["tipo"]); ?>
                                            </td>

                                            <td>
                                                <?php echo h($row["fecha_entrada"]); ?>
                                            </td>

                                            <td>
                                                <?php echo h($row["fecha_salida"]); ?>
                                            </td>

                                            <td>
                                                <?php
                                                echo max(
                                                    1,
                                                    (int) $row["numero_personas"]
                                                );
                                                ?>
                                            </td>

                                            <td>

                                                <span class="plan-badge">
                                                    <i class="bi bi-cup-hot"></i>

                                                    <?php
                                                    echo h(
                                                        $row["plan_alimentacion"]
                                                    );
                                                    ?>
                                                </span>

                                            </td>

                                            <td>
                                                $<?php
                                                echo number_format(
                                                    (float) $row["total_alimentacion"],
                                                    2
                                                );
                                                ?>
                                            </td>

                                            <td>

                                                <?php if (
                                                    $row["estado"] === "Confirmada"
                                                ) { ?>

                                                    <span class="estado-badge estado-confirmada">
                                                        <i class="bi bi-check-circle"></i>
                                                        Confirmada
                                                    </span>

                                                <?php } elseif (
                                                    $row["estado"] === "Pendiente"
                                                ) { ?>

                                                    <span class="estado-badge estado-pendiente">
                                                        <i class="bi bi-clock"></i>
                                                        Pendiente
                                                    </span>

                                                <?php } elseif (
                                                    $row["estado"] === "Finalizada"
                                                ) { ?>

                                                    <span class="estado-badge estado-finalizada">
                                                        <i class="bi bi-flag"></i>
                                                        Finalizada
                                                    </span>

                                                <?php } else { ?>

                                                    <span class="estado-badge estado-cancelada">
                                                        <i class="bi bi-x-circle"></i>
                                                        Cancelada
                                                    </span>

                                                <?php } ?>

                                            </td>

                                            <td>
                                                $<?php
                                                echo number_format(
                                                    (float) $row["total"],
                                                    2
                                                );
                                                ?>
                                            </td>

                                            <td>

                                                <div class="acciones">

                                                    <a
                                                        href="editar.php?id=<?php echo (int) $row["id_reserva"]; ?>"
                                                        class="btn-editar"
                                                    >
                                                        <i class="bi bi-pencil-square"></i>
                                                        Editar
                                                    </a>

                                                    <?php if ($row["estado"] === "Pendiente") { ?>

                                                        <a
                                                            href="eliminar.php?id=<?php echo (int) $row["id_reserva"]; ?>"
                                                            class="btn-eliminar"
                                                            onclick="return confirm('¿Deseas eliminar esta reserva?');"
                                                        >
                                                            <i class="bi bi-trash"></i>
                                                            Eliminar
                                                        </a>

                                                    <?php } ?>

                                                </div>

                                            </td>

                                        </tr>

                                    <?php } ?>

                                <?php } else { ?>

                                    <tr>
                                        <td
                                            colspan="10"
                                            class="text-center py-4"
                                        >
                                            No hay reservas
                                            <?php if ($estadoFiltro !== "Todos") { ?>
                                                con estado
                                                <?php echo h($estadoFiltro); ?>
                                            <?php } ?>
                                            para este huésped.
                                        </td>
                                    </tr>

                                <?php } ?>

                            </tbody>

                        </table>

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
                            </div>

                            <?php if ($totalPaginas > 1) { ?>

                                <nav
                                    class="paginacion-hotel"
                                    aria-label="Paginación de reservas"
                                >

                                    <?php if ($paginaActual > 1) { ?>

                                        <a
                                            href="<?php echo h(urlReservasListado($idClienteFiltro, $estadoFiltro, $paginaActual - 1)); ?>"
                                            aria-label="Página anterior"
                                        >
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

                                        <a
                                            href="<?php echo h(urlReservasListado($idClienteFiltro, $estadoFiltro, 1)); ?>"
                                        >
                                            1
                                        </a>

                                        <?php if ($paginaInicio > 2) { ?>
                                            <span class="pagina-puntos">
                                                ...
                                            </span>
                                        <?php } ?>

                                    <?php } ?>

                                    <?php for (
                                        $pagina = $paginaInicio;
                                        $pagina <= $paginaFin;
                                        $pagina++
                                    ) { ?>

                                        <?php if (
                                            $pagina === $paginaActual
                                        ) { ?>

                                            <span class="pagina-activa">
                                                <?php echo $pagina; ?>
                                            </span>

                                        <?php } else { ?>

                                            <a
                                                href="<?php echo h(urlReservasListado($idClienteFiltro, $estadoFiltro, $pagina)); ?>"
                                            >
                                                <?php echo $pagina; ?>
                                            </a>

                                        <?php } ?>

                                    <?php } ?>

                                    <?php if (
                                        $paginaFin < $totalPaginas
                                    ) { ?>

                                        <?php if (
                                            $paginaFin <
                                            $totalPaginas - 1
                                        ) { ?>
                                            <span class="pagina-puntos">
                                                ...
                                            </span>
                                        <?php } ?>

                                        <a
                                            href="<?php echo h(urlReservasListado($idClienteFiltro, $estadoFiltro, $totalPaginas)); ?>"
                                        >
                                            <?php echo $totalPaginas; ?>
                                        </a>

                                    <?php } ?>

                                    <?php if (
                                        $paginaActual < $totalPaginas
                                    ) { ?>

                                        <a
                                            href="<?php echo h(urlReservasListado($idClienteFiltro, $estadoFiltro, $paginaActual + 1)); ?>"
                                            aria-label="Página siguiente"
                                        >
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

            <?php } ?>

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
                Módulo de reservas
            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>
    const selectorHabitacion =
        document.getElementById(
            "id_habitacion"
        );

    const campoEntrada =
        document.getElementById(
            "fecha_entrada"
        );

    const campoSalida =
        document.getElementById(
            "fecha_salida"
        );

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
        } else {
            numeroPersonas.max = 1;

            textoCapacidad.textContent =
                "Selecciona primero una habitación.";
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

        const minimoSalida =
            anio + "-" + mes + "-" + dia;

        campoSalida.min = minimoSalida;

        if (
            campoSalida.value &&
            campoSalida.value <
                minimoSalida
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