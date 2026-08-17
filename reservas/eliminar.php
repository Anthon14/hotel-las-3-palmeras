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

if (
    !isset($_GET["id"]) ||
    !filter_var($_GET["id"], FILTER_VALIDATE_INT)
) {
    header("Location: index.php");
    exit();
}

$idReserva = (int) $_GET["id"];

if (empty($_SESSION["csrf_eliminar_reserva"])) {
    $_SESSION["csrf_eliminar_reserva"] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_eliminar_reserva"];
$errores = [];
$motivos = [];

/* Protección del historial */
$consultaReserva = mysqli_prepare(
    $conn,
    "SELECT
        r.id_reserva,
        r.id_cliente,
        r.id_habitacion,
        r.fecha_entrada,
        r.fecha_salida,
        r.estado,
        r.total,
        c.nombres,
        c.apellidos,
        h.numero,
        h.tipo
     FROM reservas r
     INNER JOIN clientes c
        ON c.id_cliente = r.id_cliente
     INNER JOIN habitaciones h
        ON h.id_habitacion = r.id_habitacion
     WHERE r.id_reserva = ?
     LIMIT 1"
);

if (!$consultaReserva) {
    header("Location: index.php");
    exit();
}

mysqli_stmt_bind_param(
    $consultaReserva,
    "i",
    $idReserva
);

mysqli_stmt_execute($consultaReserva);

$resultadoReserva =
    mysqli_stmt_get_result($consultaReserva);

$reserva =
    mysqli_fetch_assoc($resultadoReserva);

mysqli_stmt_close($consultaReserva);

if (!$reserva) {
    header("Location: index.php");
    exit();
}

$totalPagos = 0;
$totalPedidos = 0;

$consultaPagos = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total
     FROM pagos
     WHERE id_reserva = ?"
);

if (!$consultaPagos) {
    header("Location: index.php");
    exit();
}

mysqli_stmt_bind_param(
    $consultaPagos,
    "i",
    $idReserva
);

mysqli_stmt_execute($consultaPagos);

$resultadoPagos =
    mysqli_stmt_get_result($consultaPagos);

$filaPagos =
    mysqli_fetch_assoc($resultadoPagos);

$totalPagos =
    (int) ($filaPagos["total"] ?? 0);

mysqli_stmt_close($consultaPagos);

$consultaPedidos = mysqli_prepare(
    $conn,
    "SELECT COUNT(*) AS total
     FROM pedidos_comida
     WHERE id_reserva = ?"
);

if (!$consultaPedidos) {
    header("Location: index.php");
    exit();
}

mysqli_stmt_bind_param(
    $consultaPedidos,
    "i",
    $idReserva
);

mysqli_stmt_execute($consultaPedidos);

$resultadoPedidos =
    mysqli_stmt_get_result($consultaPedidos);

$filaPedidos =
    mysqli_fetch_assoc($resultadoPedidos);

$totalPedidos =
    (int) ($filaPedidos["total"] ?? 0);

mysqli_stmt_close($consultaPedidos);

if ($totalPagos > 0) {
    $motivos[] =
        "La reserva tiene pagos registrados y debe conservarse como historial.";
}

if ($totalPedidos > 0) {
    $motivos[] =
        "La reserva tiene pedidos de comida relacionados y debe conservarse como historial.";
}

if ($reserva["estado"] === "Confirmada") {
    $motivos[] =
        "La reserva está confirmada. Si ya no continuará, cámbiala a Cancelada en lugar de eliminarla.";
}

if ($reserva["estado"] === "Finalizada") {
    $motivos[] =
        "La reserva está finalizada y forma parte del historial del hotel.";
}

if ($reserva["estado"] === "Cancelada") {
    $motivos[] =
        "La reserva está cancelada y se conserva como parte del historial del hotel.";
}

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["confirmar_eliminacion"])
) {
    $csrfRecibido = $_POST["csrf"] ?? "";
    $idRecibido = (int) ($_POST["id_reserva"] ?? 0);

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $errores[] =
            "La solicitud no es válida. Actualiza la página.";
    }

    if ($idRecibido !== $idReserva) {
        $errores[] =
            "La reserva seleccionada no es válida.";
    }

    if (!empty($motivos)) {
        $errores[] =
            "Esta reserva no puede eliminarse.";
    }

    if (empty($errores)) {
        mysqli_begin_transaction($conn);

        try {
            $revisarReserva = mysqli_prepare(
                $conn,
                "SELECT
                    r.estado,
                    (SELECT COUNT(*) FROM pagos p WHERE p.id_reserva = r.id_reserva) AS total_pagos,
                    (SELECT COUNT(*) FROM pedidos_comida pc WHERE pc.id_reserva = r.id_reserva) AS total_pedidos
                 FROM reservas r
                 WHERE r.id_reserva = ?
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$revisarReserva) {
                throw new Exception(
                    "No se pudo comprobar nuevamente la reserva."
                );
            }

            mysqli_stmt_bind_param(
                $revisarReserva,
                "i",
                $idReserva
            );

            mysqli_stmt_execute($revisarReserva);

            $resultadoRevision =
                mysqli_stmt_get_result($revisarReserva);

            $revision =
                mysqli_fetch_assoc($resultadoRevision);

            mysqli_stmt_close($revisarReserva);

            if (!$revision) {
                throw new Exception(
                    "La reserva seleccionada ya no existe."
                );
            }

            if ((int) ($revision["total_pagos"] ?? 0) > 0) {
                throw new Exception(
                    "La reserva ahora tiene pagos relacionados y no puede eliminarse."
                );
            }

            if ((int) ($revision["total_pedidos"] ?? 0) > 0) {
                throw new Exception(
                    "La reserva ahora tiene pedidos relacionados y no puede eliminarse."
                );
            }

            if (
                in_array(
                    (string) $revision["estado"],
                    ["Confirmada", "Finalizada", "Cancelada"],
                    true
                )
            ) {
                throw new Exception(
                    "El estado actual de la reserva requiere conservarla como historial."
                );
            }

            $eliminarReserva = mysqli_prepare(
                $conn,
                "DELETE FROM reservas
                 WHERE id_reserva = ?"
            );

            if (!$eliminarReserva) {
                throw new Exception(
                    "No se pudo preparar la eliminación."
                );
            }

            mysqli_stmt_bind_param(
                $eliminarReserva,
                "i",
                $idReserva
            );

            if (!mysqli_stmt_execute($eliminarReserva)) {
                mysqli_stmt_close($eliminarReserva);

                throw new Exception(
                    "No se pudo eliminar la reserva."
                );
            }

            if (mysqli_stmt_affected_rows($eliminarReserva) !== 1) {
                mysqli_stmt_close($eliminarReserva);

                throw new Exception(
                    "La reserva cambió mientras se procesaba la solicitud."
                );
            }

            mysqli_stmt_close($eliminarReserva);

            mysqli_commit($conn);

            $_SESSION["csrf_eliminar_reserva"] =
                bin2hex(random_bytes(32));

            header(
                "Location: index.php?mensaje=eliminado"
            );
            exit();
        } catch (Throwable $excepcion) {
            mysqli_rollback($conn);

            $errores[] =
                trim((string) $excepcion->getMessage()) !== ""
                    ? $excepcion->getMessage()
                    : "No se pudo eliminar la reserva.";
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
        Eliminar reserva - Hotel Las 3 Palmeras
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

    <style>
        :root {
            --verde: #244a35;
            --verde-oscuro: #173325;
            --crema: #f7f3eb;
            --rojo: #a33636;
            --texto-suave: #687068;
        }

        * {
            box-sizing: border-box;
        }

        body {
            min-height: 100vh;
            margin: 0;
            display: grid;
            place-items: center;
            padding: 28px 16px;
            background:
                linear-gradient(
                    rgba(13, 36, 24, .82),
                    rgba(13, 36, 24, .82)
                ),
                url("../img/hotel.jpg") center/cover;
            font-family: Arial, Helvetica, sans-serif;
        }

        .confirmacion-card {
            width: min(680px, 100%);
            overflow: hidden;
            border-radius: 14px;
            background: white;
            box-shadow:
                0 26px 70px
                rgba(0, 0, 0, .30);
        }

        .cabecera {
            padding: 30px 32px;
            background: #fbfcfa;
            border-bottom: 1px solid #e5e7e2;
        }

        .icono {
            width: 62px;
            height: 62px;
            display: grid;
            place-items: center;
            margin-bottom: 18px;
            border-radius: 50%;
            background: #fff0f0;
            color: var(--rojo);
            font-size: 27px;
        }

        h1 {
            margin-bottom: 9px;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 32px;
        }

        .cabecera p,
        .contenido p,
        .contenido li {
            color: var(--texto-suave);
            font-size: 13px;
            line-height: 1.7;
        }

        .contenido {
            padding: 30px 32px;
        }

        .resumen {
            margin-bottom: 22px;
            padding: 18px;
            border: 1px solid #e1e4de;
            border-radius: 8px;
            background: var(--crema);
        }

        .resumen strong {
            color: var(--verde-oscuro);
        }

        .dato {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 7px 0;
            border-bottom: 1px solid #e4e1da;
            color: #505951;
            font-size: 13px;
        }

        .dato:last-child {
            border-bottom: 0;
        }

        .mensaje {
            display: flex;
            gap: 9px;
            margin-bottom: 20px;
            padding: 13px 15px;
            border-radius: 6px;
            font-size: 12px;
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

        .botones {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn-eliminar,
        .btn-cancelar {
            min-height: 47px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 19px;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 900;
            text-decoration: none;
        }

        .btn-eliminar {
            border: 1px solid var(--rojo);
            background: var(--rojo);
            color: white;
        }

        .btn-eliminar:hover {
            background: #7f2828;
            color: white;
        }

        .btn-cancelar {
            border: 1px solid #bdc3bd;
            background: white;
            color: #555d57;
        }

        .btn-cancelar:hover {
            background: #f1f3f1;
            color: #3a413c;
        }

        @media (max-width: 575px) {
            .cabecera,
            .contenido {
                padding: 25px 21px;
            }

            .dato {
                display: block;
            }

            .btn-eliminar,
            .btn-cancelar {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<section class="confirmacion-card">

    <div class="cabecera">

        <div class="icono">
            <i class="bi bi-trash3"></i>
        </div>

        <h1>
            Eliminar reserva
        </h1>

        <p class="mb-0">
            Revisa la información antes de continuar.
        </p>

    </div>

    <div class="contenido">

        <?php if (!empty($errores)) { ?>

            <div class="mensaje mensaje-error">

                <i class="bi bi-exclamation-circle"></i>

                <div>

                    <?php foreach ($errores as $error) { ?>

                        <div>
                            <?php echo h($error); ?>
                        </div>

                    <?php } ?>

                </div>

            </div>

        <?php } ?>

        <?php if (!empty($motivos)) { ?>

            <div class="mensaje mensaje-aviso">

                <i class="bi bi-shield-exclamation"></i>

                <div>

                    <strong>
                        No se puede eliminar esta reserva:
                    </strong>

                    <ul class="mt-2 mb-0">

                        <?php foreach ($motivos as $motivo) { ?>

                            <li>
                                <?php echo h($motivo); ?>
                            </li>

                        <?php } ?>

                    </ul>

                </div>

            </div>

        <?php } ?>

        <div class="resumen">

            <div class="dato">
                <strong>Reserva</strong>
                <span>#<?php echo $idReserva; ?></span>
            </div>

            <div class="dato">
                <strong>Cliente</strong>

                <span>
                    <?php
                    echo h(
                        $reserva["nombres"] .
                        " " .
                        $reserva["apellidos"]
                    );
                    ?>
                </span>
            </div>

            <div class="dato">
                <strong>Habitación</strong>

                <span>
                    Hab.
                    <?php echo h($reserva["numero"]); ?>
                    -
                    <?php echo h($reserva["tipo"]); ?>
                </span>
            </div>

            <div class="dato">
                <strong>Fechas</strong>

                <span>
                    <?php echo h($reserva["fecha_entrada"]); ?>
                    a
                    <?php echo h($reserva["fecha_salida"]); ?>
                </span>
            </div>

            <div class="dato">
                <strong>Estado</strong>
                <span><?php echo h($reserva["estado"]); ?></span>
            </div>

            <div class="dato">
                <strong>Total</strong>

                <span>
                    $<?php
                    echo number_format(
                        (float) $reserva["total"],
                        2
                    );
                    ?>
                </span>
            </div>

            <div class="dato">
                <strong>Pagos relacionados</strong>
                <span><?php echo $totalPagos; ?></span>
            </div>

            <div class="dato">
                <strong>Pedidos relacionados</strong>
                <span><?php echo $totalPedidos; ?></span>
            </div>

        </div>

        <?php if (empty($motivos)) { ?>

            <p>
                Esta reserva está pendiente y no tiene pagos ni pedidos
                relacionados. Al eliminarla, esas fechas quedarán libres
                sin modificar manualmente el estado general de la habitación.
            </p>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?php echo h($csrf); ?>"
                >

                <input
                    type="hidden"
                    name="id_reserva"
                    value="<?php echo $idReserva; ?>"
                >

                <div class="botones">

                    <button
                        type="submit"
                        name="confirmar_eliminacion"
                        class="btn-eliminar"
                    >
                        <i class="bi bi-trash3"></i>
                        Sí, eliminar reserva
                    </button>

                    <a
                        href="index.php"
                        class="btn-cancelar"
                    >
                        <i class="bi bi-x-circle"></i>
                        Cancelar
                    </a>

                </div>

            </form>

        <?php } else { ?>

            <a
                href="index.php"
                class="btn-cancelar"
            >
                <i class="bi bi-arrow-left"></i>
                Volver a reservas
            </a>

        <?php } ?>

    </div>

</section>

</body>

</html>