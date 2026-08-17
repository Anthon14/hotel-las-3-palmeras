<?php

session_start();

include("../config/conexion.php");

if (!isset($_SESSION["usuario"], $_SESSION["rol"])) {
    header("Location: ../login.php");
    exit();
}

$rolActual =
    strtolower(trim((string) $_SESSION["rol"]));

if ($rolActual !== "cliente") {
    header("Location: ../dashboard.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: mis_reservas.php");
    exit();
}

$csrfRecibido =
    (string) ($_POST["csrf"] ?? "");

$csrfSesion =
    (string) ($_SESSION["csrf_cancelar_reserva"] ?? "");

if (
    $csrfSesion === "" ||
    $csrfRecibido === "" ||
    !hash_equals($csrfSesion, $csrfRecibido)
) {
    header(
        "Location: mis_reservas.php?mensaje=cancelacion_no_permitida"
    );
    exit();
}

$idReserva =
    filter_input(
        INPUT_POST,
        "id_reserva",
        FILTER_VALIDATE_INT
    );

if (!$idReserva) {
    header(
        "Location: mis_reservas.php?mensaje=cancelacion_no_permitida"
    );
    exit();
}

$idUsuario =
    (int) ($_SESSION["id_usuario"] ?? 0);

if ($idUsuario <= 0) {
    $buscarUsuario = mysqli_prepare(
        $conn,
        "SELECT id_usuario
         FROM usuarios
         WHERE usuario = ?
           AND LOWER(rol) = 'cliente'
         LIMIT 1"
    );

    if (!$buscarUsuario) {
        header("Location: ../logout.php");
        exit();
    }

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

    if (!$filaUsuario) {
        header("Location: ../logout.php");
        exit();
    }

    $idUsuario =
        (int) $filaUsuario["id_usuario"];

    $_SESSION["id_usuario"] =
        $idUsuario;
}

$buscarCliente = mysqli_prepare(
    $conn,
    "SELECT id_cliente
     FROM clientes
     WHERE id_usuario = ?
     LIMIT 1"
);

if (!$buscarCliente) {
    header(
        "Location: mis_reservas.php?mensaje=cancelacion_no_permitida"
    );
    exit();
}

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

if (!$cliente) {
    header("Location: ../logout.php");
    exit();
}

$idCliente =
    (int) $cliente["id_cliente"];

$consultaReserva = mysqli_prepare(
    $conn,
    "SELECT
        r.id_reserva,
        r.estado,
        (
            SELECT p.estado_pago
            FROM pagos p
            WHERE p.id_reserva = r.id_reserva
            ORDER BY p.id_pago DESC
            LIMIT 1
        ) AS estado_pago
     FROM reservas r
     WHERE r.id_reserva = ?
       AND r.id_cliente = ?
     LIMIT 1"
);

if (!$consultaReserva) {
    header(
        "Location: mis_reservas.php?mensaje=cancelacion_no_permitida"
    );
    exit();
}

mysqli_stmt_bind_param(
    $consultaReserva,
    "ii",
    $idReserva,
    $idCliente
);

mysqli_stmt_execute($consultaReserva);

$resultadoReserva =
    mysqli_stmt_get_result($consultaReserva);

$reserva =
    mysqli_fetch_assoc($resultadoReserva);

mysqli_stmt_close($consultaReserva);

if (!$reserva) {
    header(
        "Location: mis_reservas.php?mensaje=cancelacion_no_permitida"
    );
    exit();
}

$estadoReserva =
    trim((string) $reserva["estado"]);

$estadoPago =
    trim((string) ($reserva["estado_pago"] ?? ""));

$puedeCancelar =
    $estadoReserva === "Pendiente" &&
    !in_array(
        $estadoPago,
        ["Pendiente", "Aceptado"],
        true
    );

if (!$puedeCancelar) {
    header(
        "Location: mis_reservas.php?mensaje=cancelacion_no_permitida"
    );
    exit();
}

$actualizarReserva = mysqli_prepare(
    $conn,
    "UPDATE reservas
     SET estado = 'Cancelada'
     WHERE id_reserva = ?
       AND id_cliente = ?
       AND estado = 'Pendiente'"
);

if (!$actualizarReserva) {
    header(
        "Location: mis_reservas.php?mensaje=cancelacion_no_permitida"
    );
    exit();
}

mysqli_stmt_bind_param(
    $actualizarReserva,
    "ii",
    $idReserva,
    $idCliente
);

mysqli_stmt_execute($actualizarReserva);

$filasActualizadas =
    mysqli_stmt_affected_rows($actualizarReserva);

mysqli_stmt_close($actualizarReserva);

if ($filasActualizadas !== 1) {
    header(
        "Location: mis_reservas.php?mensaje=cancelacion_no_permitida"
    );
    exit();
}

$_SESSION["csrf_cancelar_reserva"] =
    bin2hex(random_bytes(32));

header(
    "Location: mis_reservas.php?estado=Cancelada&mensaje=reserva_cancelada"
);
exit();