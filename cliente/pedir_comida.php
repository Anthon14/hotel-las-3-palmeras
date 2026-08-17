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
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

function imagenSegura($imagen)
{
    $imagen = trim((string) $imagen);

    if (
        $imagen === "" ||
        !filter_var($imagen, FILTER_VALIDATE_URL)
    ) {
        return "../img/hotel.jpg";
    }

    $esquema = strtolower(
        (string) parse_url($imagen, PHP_URL_SCHEME)
    );

    return in_array($esquema, ["http", "https"], true)
        ? $imagen
        : "../img/hotel.jpg";
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
        $usuarioSesion = trim((string) $_SESSION["usuario"]);

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
            $idUsuario = (int) $filaUsuario["id_usuario"];
            $_SESSION["id_usuario"] = $idUsuario;
        }
    }
}

$cliente = null;

if ($idUsuario > 0) {
    $buscarCliente = mysqli_prepare(
        $conn,
        "SELECT id_cliente, nombres, apellidos
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

$idCliente = (int) $cliente["id_cliente"];

$nombreCliente = trim(
    (string) $cliente["nombres"] .
    " " .
    (string) $cliente["apellidos"]
);

if ($nombreCliente === "") {
    $nombreCliente = (string) $_SESSION["usuario"];
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

if (empty($_SESSION["csrf_pedido_comida"])) {
    $_SESSION["csrf_pedido_comida"] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_pedido_comida"];
$errores = [];
$mensaje = "";

if (
    isset($_GET["mensaje"]) &&
    $_GET["mensaje"] === "guardado"
) {
    $mensaje =
        "Tu pedido fue registrado correctamente y quedó Pendiente.";
}

$idComida = null;

if (isset($_GET["id"]) && $_GET["id"] !== "") {
    $idValidado = filter_var(
        $_GET["id"],
        FILTER_VALIDATE_INT
    );

    if (!$idValidado || (int) $idValidado <= 0) {
        header("Location: pedir_comida.php");
        exit();
    }

    $idComida = (int) $idValidado;
}

$comidas = [];

$consultaMenu = mysqli_prepare(
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
     WHERE estado = 'Disponible'
     ORDER BY
        CASE tipo
            WHEN 'Desayuno' THEN 1
            WHEN 'Almuerzo' THEN 2
            WHEN 'Cena' THEN 3
            WHEN 'Bebida' THEN 4
            ELSE 5
        END,
        nombre ASC"
);

if ($consultaMenu) {
    mysqli_stmt_execute($consultaMenu);

    $resultadoMenu =
        mysqli_stmt_get_result($consultaMenu);

    while (
        $filaComida =
            mysqli_fetch_assoc($resultadoMenu)
    ) {
        $comidas[] = $filaComida;
    }

    mysqli_stmt_close($consultaMenu);
} else {
    $errores[] =
        "No se pudo cargar el menú del hotel.";
}

$comida = null;

if ($idComida !== null) {
    $buscarComida = mysqli_prepare(
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
         WHERE id_comida = ?
           AND estado = 'Disponible'
         LIMIT 1"
    );

    if ($buscarComida) {
        mysqli_stmt_bind_param(
            $buscarComida,
            "i",
            $idComida
        );

        mysqli_stmt_execute($buscarComida);

        $resultadoComida =
            mysqli_stmt_get_result($buscarComida);

        $comida =
            mysqli_fetch_assoc($resultadoComida);

        mysqli_stmt_close($buscarComida);
    }

    if (!$comida) {
        header(
            "Location: pedir_comida.php?mensaje=no_disponible"
        );
        exit();
    }
}

if (
    isset($_GET["mensaje"]) &&
    $_GET["mensaje"] === "no_disponible"
) {
    $errores[] =
        "La comida seleccionada ya no está disponible.";
}

$reservasConfirmadas = [];

$consultaReservas = mysqli_prepare(
    $conn,
    "SELECT
        r.id_reserva,
        r.fecha_entrada,
        r.fecha_salida,
        h.numero,
        h.tipo
     FROM reservas r
     INNER JOIN habitaciones h
        ON h.id_habitacion = r.id_habitacion
     WHERE r.id_cliente = ?
       AND r.estado = 'Confirmada'
       AND r.fecha_salida >= CURDATE()
     ORDER BY
        CASE
            WHEN CURDATE()
                 BETWEEN r.fecha_entrada
                 AND r.fecha_salida
            THEN 0
            ELSE 1
        END,
        r.fecha_entrada ASC,
        r.id_reserva DESC"
);

if ($consultaReservas) {
    mysqli_stmt_bind_param(
        $consultaReservas,
        "i",
        $idCliente
    );

    mysqli_stmt_execute($consultaReservas);

    $resultadoReservas =
        mysqli_stmt_get_result($consultaReservas);

    while (
        $filaReserva =
            mysqli_fetch_assoc($resultadoReservas)
    ) {
        $reservasConfirmadas[] =
            $filaReserva;
    }

    mysqli_stmt_close($consultaReservas);
}

$cantidad = 1;
$observacion = "";
$formaPago = "Pagar al recibir";
$idReservaSeleccionada = 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if ($comida === null || $idComida === null) {
        header("Location: pedir_comida.php");
        exit();
    }

    $csrfRecibido = $_POST["csrf"] ?? "";

    $cantidadRecibida = filter_input(
        INPUT_POST,
        "cantidad",
        FILTER_VALIDATE_INT
    );

    $cantidad =
        $cantidadRecibida !== false &&
        $cantidadRecibida !== null
            ? (int) $cantidadRecibida
            : 0;

    $observacion =
        trim((string) ($_POST["observacion"] ?? ""));

    $formaPago =
        trim(
            (string) (
                $_POST["forma_pago"] ??
                "Pagar al recibir"
            )
        );

    $idReservaRecibida = filter_input(
        INPUT_POST,
        "id_reserva",
        FILTER_VALIDATE_INT
    );

    $idReservaSeleccionada =
        $idReservaRecibida !== false &&
        $idReservaRecibida !== null
            ? (int) $idReservaRecibida
            : 0;

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $errores[] =
            "La solicitud no es válida. Actualiza la página.";
    }

    if ($cantidad < 1 || $cantidad > 20) {
        $errores[] =
            "La cantidad debe estar entre 1 y 20.";
    }

    if (mb_strlen($observacion) > 255) {
        $errores[] =
            "La observación no puede superar los 255 caracteres.";
    }

    $formasPagoPermitidas = [
        "Pagar al recibir",
        "Cargar a la habitación"
    ];

    if (
        !in_array(
            $formaPago,
            $formasPagoPermitidas,
            true
        )
    ) {
        $errores[] =
            "Seleccione una forma de pago válida.";
    }

    if (
        $formaPago === "Cargar a la habitación" &&
        $idReservaSeleccionada <= 0
    ) {
        $errores[] =
            "Seleccione la reserva donde se cargará el pedido.";
    }

    if (empty($errores)) {
        mysqli_begin_transaction($conn);

        try {
            $idReservaPedido = null;

            if (
                $formaPago ===
                "Cargar a la habitación"
            ) {
                $bloquearReserva = mysqli_prepare(
                    $conn,
                    "SELECT
                        id_reserva,
                        estado,
                        fecha_salida
                     FROM reservas
                     WHERE id_reserva = ?
                       AND id_cliente = ?
                     LIMIT 1
                     FOR UPDATE"
                );

                if (!$bloquearReserva) {
                    throw new Exception(
                        "No se pudo validar la reserva seleccionada."
                    );
                }

                mysqli_stmt_bind_param(
                    $bloquearReserva,
                    "ii",
                    $idReservaSeleccionada,
                    $idCliente
                );

                mysqli_stmt_execute($bloquearReserva);

                $resultadoReserva =
                    mysqli_stmt_get_result($bloquearReserva);

                $reservaSeleccionada =
                    mysqli_fetch_assoc($resultadoReserva);

                mysqli_stmt_close($bloquearReserva);

                if (!$reservaSeleccionada) {
                    throw new Exception(
                        "La reserva seleccionada no pertenece al cliente."
                    );
                }

                if (
                    $reservaSeleccionada["estado"] !==
                    "Confirmada"
                ) {
                    throw new Exception(
                        "Solo puede cargar pedidos a una reserva confirmada."
                    );
                }

                $hoy =
                    (new DateTimeImmutable("today"))
                        ->format("Y-m-d");

                if (
                    (string) $reservaSeleccionada["fecha_salida"] <
                    $hoy
                ) {
                    throw new Exception(
                        "La reserva seleccionada ya finalizó."
                    );
                }

                $idReservaPedido =
                    (int) $reservaSeleccionada["id_reserva"];
            }

            $bloquearComida = mysqli_prepare(
                $conn,
                "SELECT precio, estado
                 FROM comidas
                 WHERE id_comida = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$bloquearComida) {
                throw new Exception(
                    "No se pudo validar la comida."
                );
            }

            mysqli_stmt_bind_param(
                $bloquearComida,
                "i",
                $idComida
            );

            mysqli_stmt_execute($bloquearComida);

            $resultadoBloqueo =
                mysqli_stmt_get_result($bloquearComida);

            $comidaBloqueada =
                mysqli_fetch_assoc($resultadoBloqueo);

            mysqli_stmt_close($bloquearComida);

            if (
                !$comidaBloqueada ||
                $comidaBloqueada["estado"] !== "Disponible"
            ) {
                throw new Exception(
                    "La comida ya no está disponible."
                );
            }

            $precioUnitario =
                round((float) $comidaBloqueada["precio"], 2);

            if ($precioUnitario <= 0) {
                throw new Exception(
                    "El precio de la comida no es válido."
                );
            }

            $total =
                round($precioUnitario * $cantidad, 2);

            $estadoPedido = "Pendiente";
            $estadoPago = "Pendiente";

            $guardarPedido = mysqli_prepare(
                $conn,
                "INSERT INTO pedidos_comida
                    (
                        id_cliente,
                        id_reserva,
                        id_comida,
                        cantidad,
                        precio_unitario,
                        total,
                        forma_pago,
                        estado_pago,
                        estado,
                        observacion
                    )
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );

            if (!$guardarPedido) {
                throw new Exception(
                    "No se pudo preparar el pedido."
                );
            }

            mysqli_stmt_bind_param(
                $guardarPedido,
                "iiiiddssss",
                $idCliente,
                $idReservaPedido,
                $idComida,
                $cantidad,
                $precioUnitario,
                $total,
                $formaPago,
                $estadoPago,
                $estadoPedido,
                $observacion
            );

            if (!mysqli_stmt_execute($guardarPedido)) {
                mysqli_stmt_close($guardarPedido);

                throw new Exception(
                    "No se pudo registrar el pedido."
                );
            }

            mysqli_stmt_close($guardarPedido);
            mysqli_commit($conn);

            $_SESSION["csrf_pedido_comida"] =
                bin2hex(random_bytes(32));

            header(
                "Location: pedir_comida.php?id=" .
                $idComida .
                "&mensaje=guardado"
            );
            exit();
        } catch (Throwable $excepcion) {
            mysqli_rollback($conn);

            $errores[] =
                $excepcion->getMessage() !== ""
                    ? $excepcion->getMessage()
                    : "No se pudo registrar el pedido.";
        }
    }
}

$rutaImagenSeleccionada =
    $comida !== null
        ? imagenSegura($comida["imagen"] ?? "")
        : "../img/hotel.jpg";
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
        <?php
        echo $comida !== null
            ? "Pedir comida"
            : "Menú de comidas";
        ?>
        - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=59"
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
            transition:
                background-color .2s ease,
                border-color .2s ease,
                transform .2s ease;
        }

        .btn-notificaciones-cliente:hover,
        .btn-notificaciones-cliente:focus {
            border-color: rgba(240, 217, 159, .75);
            background-color: rgba(255, 255, 255, .15);
            color: white;
            transform: translateY(-1px);
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
            min-height: 350px;
            display: flex;
            align-items: center;
            margin-top: 82px;
            color: white;
            background:
                linear-gradient(
                    90deg,
                    rgba(10, 29, 20, .92),
                    rgba(10, 29, 20, .57)
                ),
                url("../img/hotel.jpg") center/cover;
        }

        .pagina-hero-contenido {
            max-width: 760px;
            padding: 64px 0;
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
            background: #edf8f0;
            color: #24643a;
        }

        .mensaje-error {
            border: 1px solid #edc8c8;
            background: #fff1f1;
            color: #9b3131;
        }

        .comida-card {
            height: 100%;
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            box-shadow: var(--sombra);
        }

        .comida-imagen-menu {
            width: 100%;
            height: 235px;
            object-fit: cover;
        }

        .comida-cuerpo {
            padding: 23px;
        }

        .comida-tipo {
            color: #9b7739;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 1.7px;
        }

        .comida-titulo {
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
        }

        .comida-descripcion {
            min-height: 66px;
            color: var(--texto-suave);
            font-size: 13px;
            line-height: 1.6;
        }

        .comida-precio {
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 29px;
            font-weight: 700;
        }

        .btn-pedir {
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

        .btn-pedir:hover {
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

        .imagen-seleccionada {
            width: 100%;
            height: 100%;
            min-height: 660px;
            object-fit: cover;
        }

        .formulario-columna {
            padding: 34px;
        }

        .formulario-columna h2 {
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
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

        textarea.form-control {
            min-height: 105px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--verde);
            background: white;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, .10);
        }

        .cantidad-control {
            max-width: 185px;
        }

        .forma-pago-opciones {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .forma-pago-opcion {
            position: relative;
            margin: 0;
            cursor: pointer;
        }

        .forma-pago-opcion input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .forma-pago-contenido {
            min-height: 92px;
            height: 100%;
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 11px;
            padding: 14px;
            border: 1px solid #dce1dc;
            border-radius: 10px;
            background: #fbfcfa;
            transition:
                border-color .18s ease,
                background-color .18s ease,
                box-shadow .18s ease,
                transform .18s ease;
        }

        .forma-pago-opcion:hover .forma-pago-contenido {
            border-color: #b7c3ba;
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(35, 55, 42, .07);
        }

        .forma-pago-icono {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            display: grid;
            place-items: center;
            border-radius: 9px;
            background: var(--verde-claro);
            color: var(--verde);
            font-size: 17px;
        }

        .forma-pago-texto {
            min-width: 0;
            flex: 1;
            padding-right: 12px;
        }

        .forma-pago-texto strong {
            display: block;
            margin-bottom: 4px;
            color: var(--verde-oscuro);
            font-size: 13px;
        }

        .forma-pago-texto small {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            line-height: 1.45;
        }

        .forma-pago-check {
            width: 20px;
            height: 20px;
            position: absolute;
            top: 8px;
            right: 8px;
            display: none;
            place-items: center;
            border-radius: 50%;
            background: var(--verde);
            color: white;
            font-size: 9px;
        }

        .forma-pago-opcion input:checked + .forma-pago-contenido {
            border: 2px solid var(--verde);
            background: #f4faf6;
            box-shadow: 0 8px 20px rgba(36, 74, 53, .10);
        }

        .forma-pago-opcion input:checked +
        .forma-pago-contenido .forma-pago-icono {
            background: var(--verde);
            color: white;
        }

        .forma-pago-opcion input:checked +
        .forma-pago-contenido .forma-pago-check {
            display: grid;
        }

        .opcion-deshabilitada {
            cursor: not-allowed;
        }

        .opcion-deshabilitada .forma-pago-contenido {
            opacity: .55;
            background: #f3f4f2;
        }

        .opcion-deshabilitada:hover .forma-pago-contenido {
            border-color: #dce1dc;
            transform: none;
            box-shadow: none;
        }

        .forma-pago-aviso {
            display: flex;
            gap: 10px;
            margin-top: 11px;
            padding: 12px 14px;
            border: 1px solid #d8e2d9;
            border-radius: 8px;
            background: #f4f8f5;
            color: #4e6253;
            font-size: 11px;
            line-height: 1.55;
        }

        .resumen-pedido {
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

        .total-pedido {
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 30px;
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
            .imagen-seleccionada {
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
        }

        @media (max-width: 420px) {
            .marca-texto {
                display: none;
            }

            .dato-resumen {
                display: block;
            }

            .forma-pago-opciones {
                grid-template-columns: 1fr;
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

        <a href="index.php" class="navbar-brand marca-hotel p-0">

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
                    <a
                        href="pedir_comida.php"
                        class="nav-link active"
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
                SABORES DEL HOTEL
            </div>

            <h1>
                <?php
                echo $comida !== null
                    ? "Realiza tu pedido"
                    : "Menú de comidas";
                ?>
            </h1>

            <p>
                <?php if ($comida !== null) { ?>

                    Selecciona la cantidad, la forma de pago,
                    agrega una observación y confirma el pedido.

                <?php } else { ?>

                    Revisa los desayunos, almuerzos, cenas,
                    bebidas y extras disponibles.

                <?php } ?>
            </p>

        </div>
    </div>
</section>

<main class="contenido-pagina">
    <div class="container">

        <?php if ($mensaje !== "") { ?>

            <div class="mensaje mensaje-exito">

                <i class="bi bi-check-circle"></i>

                <div>
                    <strong>Pedido registrado.</strong>

                    <div>
                        <?php echo h($mensaje); ?>
                    </div>

                    <a
                        href="mis_pedidos.php"
                        class="fw-bold text-success"
                    >
                        Ver mis pedidos
                    </a>
                </div>

            </div>

        <?php } ?>

        <?php if (!empty($errores)) { ?>

            <div class="mensaje mensaje-error">

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

        <?php if ($comida === null) { ?>

            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

                <div>
                    <div class="pagina-etiqueta text-success">
                        MENÚ DISPONIBLE
                    </div>

                    <h2 class="mt-2 mb-1">
                        Escoge una comida
                    </h2>

                    <p class="text-muted mb-0">
                        Solo aparecen los productos disponibles.
                    </p>
                </div>

                <a
                    href="mis_pedidos.php"
                    class="btn-pedir"
                >
                    <i class="bi bi-receipt"></i>
                    Mis pedidos
                </a>

            </div>

            <?php if (!empty($comidas)) { ?>

                <div class="row g-4">

                    <?php foreach ($comidas as $item) { ?>

                        <?php
                        $rutaImagenItem =
                            imagenSegura($item["imagen"] ?? "");
                        ?>

                        <div class="col-md-6 col-xl-4">

                            <article class="comida-card">

                                <img
                                    src="<?php echo h($rutaImagenItem); ?>"
                                    alt="<?php echo h($item["nombre"]); ?>"
                                    class="comida-imagen-menu"
                                    loading="lazy"
                                    onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                                >

                                <div class="comida-cuerpo">

                                    <div class="comida-tipo">
                                        <?php
                                        echo h(
                                            strtoupper(
                                                (string) $item["tipo"]
                                            )
                                        );
                                        ?>
                                    </div>

                                    <h3 class="comida-titulo h4 mt-1">
                                        <?php echo h($item["nombre"]); ?>
                                    </h3>

                                    <p class="comida-descripcion">
                                        <?php
                                        echo trim(
                                            (string) $item["descripcion"]
                                        ) !== ""
                                            ? h($item["descripcion"])
                                            : "Sin descripción registrada.";
                                        ?>
                                    </p>

                                    <div class="d-flex justify-content-between align-items-center gap-3">

                                        <div class="comida-precio">
                                            $<?php
                                            echo number_format(
                                                (float) $item["precio"],
                                                2
                                            );
                                            ?>
                                        </div>

                                        <a
                                            href="pedir_comida.php?id=<?php echo (int) $item["id_comida"]; ?>"
                                            class="btn-pedir"
                                        >
                                            <i class="bi bi-cart-plus"></i>
                                            Pedir
                                        </a>

                                    </div>

                                </div>

                            </article>

                        </div>

                    <?php } ?>

                </div>

            <?php } else { ?>

                <div class="vacio">

                    <div class="display-4 mb-3">
                        <i class="bi bi-cup-hot"></i>
                    </div>

                    <h2>No hay comidas disponibles</h2>

                    <p class="text-muted mb-0">
                        El menú será actualizado por el personal del hotel.
                    </p>

                </div>

            <?php } ?>

        <?php } else { ?>

            <a href="pedir_comida.php" class="btn-volver">
                <i class="bi bi-arrow-left"></i>
                Volver al menú
            </a>

            <section class="pedido-card">

                <div class="row g-0">

                    <div class="col-lg-5">

                        <img
                            src="<?php echo h($rutaImagenSeleccionada); ?>"
                            alt="<?php echo h($comida["nombre"]); ?>"
                            class="imagen-seleccionada"
                            onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                        >

                    </div>

                    <div class="col-lg-7">

                        <div class="formulario-columna">

                            <div class="comida-tipo">
                                <?php
                                echo h(
                                    strtoupper(
                                        (string) $comida["tipo"]
                                    )
                                );
                                ?>
                            </div>

                            <h2 class="mt-2 mb-3">
                                <?php echo h($comida["nombre"]); ?>
                            </h2>

                            <p class="text-muted">
                                <?php
                                echo trim(
                                    (string) $comida["descripcion"]
                                ) !== ""
                                    ? h($comida["descripcion"])
                                    : "Esta comida no tiene una descripción registrada.";
                                ?>
                            </p>

                            <div class="comida-precio mb-4">
                                $<?php
                                echo number_format(
                                    (float) $comida["precio"],
                                    2
                                );
                                ?>
                            </div>

                            <form
                                method="POST"
                                id="formPedido"
                                autocomplete="off"
                            >

                                <input
                                    type="hidden"
                                    name="csrf"
                                    value="<?php echo h($csrf); ?>"
                                >

                                <div class="mb-4">

                                    <label
                                        for="cantidad"
                                        class="form-label"
                                    >
                                        Cantidad
                                    </label>

                                    <div class="input-group cantidad-control">

                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary"
                                            id="btnRestar"
                                        >
                                            −
                                        </button>

                                        <input
                                            type="number"
                                            name="cantidad"
                                            id="cantidad"
                                            class="form-control text-center"
                                            min="1"
                                            max="20"
                                            value="<?php echo (int) $cantidad; ?>"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary"
                                            id="btnSumar"
                                        >
                                            +
                                        </button>

                                    </div>

                                    <div class="text-muted small mt-2">
                                        Puedes solicitar entre 1 y 20 unidades.
                                    </div>

                                </div>

                                <div class="mb-4">

                                    <label class="form-label d-block mb-2">
                                        Forma de pago
                                    </label>

                                    <div class="forma-pago-opciones">

                                        <label class="forma-pago-opcion">

                                            <input
                                                type="radio"
                                                name="forma_pago"
                                                value="Pagar al recibir"
                                                <?php
                                                echo $formaPago ===
                                                    "Pagar al recibir"
                                                        ? "checked"
                                                        : "";
                                                ?>
                                                required
                                            >

                                            <span class="forma-pago-contenido">

                                                <span class="forma-pago-icono">
                                                    <i class="bi bi-cash-coin"></i>
                                                </span>

                                                <span class="forma-pago-texto">
                                                    <strong>
                                                        Pagar al recibir
                                                    </strong>

                                                    <small>
                                                        No pagas en esta pantalla.
                                                        El pedido queda pendiente y
                                                        se cobra cuando te lo entreguen.
                                                    </small>
                                                </span>

                                                <span class="forma-pago-check">
                                                    <i class="bi bi-check-lg"></i>
                                                </span>

                                            </span>

                                        </label>

                                        <label
                                            class="forma-pago-opcion <?php echo empty($reservasConfirmadas) ? "opcion-deshabilitada" : ""; ?>"
                                        >

                                            <input
                                                type="radio"
                                                name="forma_pago"
                                                value="Cargar a la habitación"
                                                <?php
                                                echo $formaPago ===
                                                    "Cargar a la habitación"
                                                        ? "checked"
                                                        : "";
                                                ?>
                                                <?php
                                                echo empty($reservasConfirmadas)
                                                    ? "disabled"
                                                    : "";
                                                ?>
                                                required
                                            >

                                            <span class="forma-pago-contenido">

                                                <span class="forma-pago-icono">
                                                    <i class="bi bi-door-open"></i>
                                                </span>

                                                <span class="forma-pago-texto">
                                                    <strong>
                                                        Cargar a la habitación
                                                    </strong>

                                                    <small>
                                                        El consumo queda asociado a tu
                                                        reserva y habitación para pagarlo
                                                        después en recepción. No se suma
                                                        al valor original del alojamiento.
                                                    </small>
                                                </span>

                                                <span class="forma-pago-check">
                                                    <i class="bi bi-check-lg"></i>
                                                </span>

                                            </span>

                                        </label>

                                    </div>

                                    <div
                                        id="avisoFormaPago"
                                        class="forma-pago-aviso"
                                    >
                                        <i class="bi bi-info-circle"></i>

                                        <div id="textoFormaPago">
                                            No se realiza ningún pago en esta pantalla.
                                            Si eliges pagar al recibir, pagas cuando te
                                            entreguen el pedido.
                                        </div>
                                    </div>

                                    <?php if (empty($reservasConfirmadas)) { ?>

                                        <div class="text-muted small mt-2">
                                            Para cargar un consumo a la habitación
                                            necesitas tener una reserva confirmada.
                                        </div>

                                    <?php } ?>

                                    <div class="text-muted small mt-2">
                                        Los pedidos de comida se cobran por separado
                                        del alojamiento, aunque estén asociados a una habitación.
                                    </div>

                                </div>

                                <div
                                    class="mb-4 d-none"
                                    id="contenedorReserva"
                                >

                                    <label
                                        for="id_reserva"
                                        class="form-label"
                                    >
                                        Reserva para cargar el consumo
                                    </label>

                                    <select
                                        name="id_reserva"
                                        id="id_reserva"
                                        class="form-select"
                                    >

                                        <option value="">
                                            Seleccione una reserva
                                        </option>

                                        <?php foreach (
                                            $reservasConfirmadas
                                            as $reservaConfirmada
                                        ) { ?>

                                            <option
                                                value="<?php
                                                echo (int)
                                                    $reservaConfirmada[
                                                        "id_reserva"
                                                    ];
                                                ?>"
                                                <?php
                                                echo $idReservaSeleccionada ===
                                                    (int) $reservaConfirmada[
                                                        "id_reserva"
                                                    ]
                                                        ? "selected"
                                                        : "";
                                                ?>
                                            >
                                                Habitación
                                                <?php
                                                echo h(
                                                    $reservaConfirmada[
                                                        "numero"
                                                    ]
                                                );
                                                ?>
                                                · Reserva #
                                                <?php
                                                echo (int)
                                                    $reservaConfirmada[
                                                        "id_reserva"
                                                    ];
                                                ?>
                                                ·
                                                <?php
                                                echo h(
                                                    formatearFecha(
                                                        $reservaConfirmada[
                                                            "fecha_entrada"
                                                        ]
                                                    )
                                                );
                                                ?>
                                                al
                                                <?php
                                                echo h(
                                                    formatearFecha(
                                                        $reservaConfirmada[
                                                            "fecha_salida"
                                                        ]
                                                    )
                                                );
                                                ?>
                                            </option>

                                        <?php } ?>

                                    </select>

                                    <?php if (
                                        empty($reservasConfirmadas)
                                    ) { ?>

                                        <div class="text-danger small mt-2">
                                            No tienes una reserva confirmada
                                            disponible para cargar consumos.
                                        </div>

                                    <?php } else { ?>

                                        <div class="text-muted small mt-2">
                                            El pedido quedará relacionado
                                            con la habitación seleccionada.
                                        </div>

                                    <?php } ?>

                                </div>

                                <div class="mb-4">

                                    <label
                                        for="observacion"
                                        class="form-label"
                                    >
                                        Observación
                                    </label>

                                    <textarea
                                        name="observacion"
                                        id="observacion"
                                        class="form-control"
                                        rows="3"
                                        maxlength="255"
                                        placeholder="Ejemplo: sin cebolla, poca sal o entregar a las 8:00"
                                    ><?php echo h($observacion); ?></textarea>

                                    <div class="text-muted small mt-2">
                                        Este campo es opcional.
                                    </div>

                                </div>

                                <div class="resumen-pedido mb-4">

                                    <div class="dato-resumen">

                                        <span>Precio unitario</span>

                                        <strong>
                                            $<?php
                                            echo number_format(
                                                (float) $comida["precio"],
                                                2
                                            );
                                            ?>
                                        </strong>

                                    </div>

                                    <div class="dato-resumen">

                                        <span>Cantidad</span>

                                        <strong id="cantidadResumen">
                                            <?php echo (int) $cantidad; ?>
                                        </strong>

                                    </div>

                                    <div class="dato-resumen">

                                        <span>Forma de pago</span>

                                        <strong id="formaPagoResumen">
                                            <?php echo h($formaPago); ?>
                                        </strong>

                                    </div>

                                    <div
                                        class="dato-resumen d-none"
                                        id="reservaResumenFila"
                                    >

                                        <span>Cargar a</span>

                                        <strong id="reservaResumen">
                                            Sin seleccionar
                                        </strong>

                                    </div>

                                    <div class="dato-resumen align-items-center">

                                        <span class="fw-bold">
                                            Total
                                        </span>

                                        <strong
                                            class="total-pedido"
                                            id="totalPedido"
                                        >
                                            $<?php
                                            echo number_format(
                                                (float) $comida["precio"] *
                                                (int) $cantidad,
                                                2
                                            );
                                            ?>
                                        </strong>

                                    </div>

                                </div>

                                <button
                                    type="submit"
                                    class="btn-pedir w-100"
                                >
                                    <i class="bi bi-check-circle"></i>
                                    Confirmar pedido
                                </button>

                            </form>

                        </div>

                    </div>

                </div>

            </section>

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
                href="mis_pedidos.php"
                class="btn btn-outline-light btn-sm"
            >
                Ver mis pedidos
            </a>

        </div>

    </div>

    <div class="footer-final">

        <div class="container d-flex justify-content-between flex-wrap gap-2">

            <span>
                Hotel Las 3 Palmeras © 2026
            </span>

            <span>
                Servicio de alimentación
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

<?php if ($comida !== null) { ?>

<script>
const precioUnitario =
    <?php echo json_encode((float) $comida["precio"]); ?>;

const cantidadInput =
    document.getElementById("cantidad");

const cantidadResumen =
    document.getElementById("cantidadResumen");

const totalPedido =
    document.getElementById("totalPedido");

const formasPago =
    document.querySelectorAll(
        'input[name="forma_pago"]'
    );

function obtenerFormaPago() {
    const seleccionada =
        document.querySelector(
            'input[name="forma_pago"]:checked'
        );

    return seleccionada
        ? seleccionada.value
        : "Pagar al recibir";
}

const contenedorReserva =
    document.getElementById("contenedorReserva");

const reservaSelect =
    document.getElementById("id_reserva");

const textoFormaPago =
    document.getElementById("textoFormaPago");

const formaPagoResumen =
    document.getElementById("formaPagoResumen");

const reservaResumenFila =
    document.getElementById("reservaResumenFila");

const reservaResumen =
    document.getElementById("reservaResumen");

const btnRestar =
    document.getElementById("btnRestar");

const btnSumar =
    document.getElementById("btnSumar");

function actualizarFormaPago() {
    const formaPagoActual =
        obtenerFormaPago();

    const cargarHabitacion =
        formaPagoActual ===
        "Cargar a la habitación";

    contenedorReserva.classList.toggle(
        "d-none",
        !cargarHabitacion
    );

    reservaSelect.required =
        cargarHabitacion;

    formaPagoResumen.textContent =
        formaPagoActual;

    reservaResumenFila.classList.toggle(
        "d-none",
        !cargarHabitacion
    );

    if (cargarHabitacion) {
        textoFormaPago.textContent =
            "No se paga en esta pantalla. El consumo queda " +
            "asociado a la reserva y habitación seleccionada, " +
            "pero no se suma al valor original del alojamiento. " +
            "Se paga por separado después en recepción y el " +
            "personal lo marca como Pagado.";

        const opcion =
            reservaSelect.options[
                reservaSelect.selectedIndex
            ];

        reservaResumen.textContent =
            reservaSelect.value !== ""
                ? opcion.textContent
                    .replace(/\s+/g, " ")
                    .trim()
                : "Sin seleccionar";
    } else {
        textoFormaPago.textContent =
            "No se paga en esta pantalla. El pedido queda con " +
            "pago Pendiente y se cobra cuando el personal " +
            "te entregue el pedido. Este cobro es independiente " +
            "del pago de la reserva.";

        reservaSelect.value = "";
        reservaResumen.textContent =
            "Sin seleccionar";
    }
}

function actualizarTotal() {
    let cantidad =
        parseInt(cantidadInput.value, 10);

    if (isNaN(cantidad) || cantidad < 1) {
        cantidad = 1;
    }

    if (cantidad > 20) {
        cantidad = 20;
    }

    cantidadInput.value = cantidad;
    cantidadResumen.textContent = cantidad;

    totalPedido.textContent =
        "$" + (precioUnitario * cantidad).toFixed(2);
}

btnRestar.addEventListener(
    "click",
    function () {
        let cantidad =
            parseInt(cantidadInput.value, 10) || 1;

        if (cantidad > 1) {
            cantidadInput.value = cantidad - 1;
            actualizarTotal();
        }
    }
);

btnSumar.addEventListener(
    "click",
    function () {
        let cantidad =
            parseInt(cantidadInput.value, 10) || 1;

        if (cantidad < 20) {
            cantidadInput.value = cantidad + 1;
            actualizarTotal();
        }
    }
);

cantidadInput.addEventListener(
    "input",
    actualizarTotal
);

formasPago.forEach(
    (opcion) => {
        opcion.addEventListener(
            "change",
            actualizarFormaPago
        );
    }
);

reservaSelect.addEventListener(
    "change",
    actualizarFormaPago
);

actualizarFormaPago();
actualizarTotal();
</script>

<?php } ?>

</body>
</html>