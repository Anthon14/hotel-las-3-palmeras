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

$idComida = (int) $_GET["id"];

if (empty($_SESSION["csrf_eliminar_comida"])) {
    $_SESSION["csrf_eliminar_comida"] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_eliminar_comida"];
$error = "";
$eliminada = false;
$imagenEliminada = "";

/* Eliminación de comidas */
$consulta = mysqli_prepare(
    $conn,
    "SELECT
        co.id_comida,
        co.nombre,
        co.tipo,
        co.precio,
        co.estado,
        co.imagen,
        (
            SELECT COUNT(*)
            FROM pedidos_comida p
            WHERE p.id_comida = co.id_comida
        ) AS total_pedidos
     FROM comidas co
     WHERE co.id_comida = ?
     LIMIT 1"
);

if (!$consulta) {
    header("Location: index.php?mensaje=error_eliminar");
    exit();
}

mysqli_stmt_bind_param(
    $consulta,
    "i",
    $idComida
);

mysqli_stmt_execute($consulta);

$resultado =
    mysqli_stmt_get_result($consulta);

$comida =
    mysqli_fetch_assoc($resultado);

mysqli_stmt_close($consulta);

if (!$comida) {
    header("Location: index.php");
    exit();
}

$totalPedidos =
    (int) ($comida["total_pedidos"] ?? 0);

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["confirmar_eliminacion"])
) {
    $csrfRecibido = $_POST["csrf"] ?? "";
    $idRecibido = (int) ($_POST["id_comida"] ?? 0);

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $error =
            "La solicitud no es válida. Actualiza la página.";
    } elseif ($idRecibido !== $idComida) {
        $error =
            "La comida seleccionada no es válida.";
    } elseif ($totalPedidos > 0) {
        $error =
            "No se puede eliminar porque tiene pedidos relacionados.";
    }

    if ($error === "") {
        $revisarPedidos = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS total
             FROM pedidos_comida
             WHERE id_comida = ?"
        );

        if (!$revisarPedidos) {
            $error =
                "No se pudo comprobar nuevamente el historial de pedidos.";
        } else {
            mysqli_stmt_bind_param(
                $revisarPedidos,
                "i",
                $idComida
            );

            mysqli_stmt_execute($revisarPedidos);

            $resultadoPedidos =
                mysqli_stmt_get_result($revisarPedidos);

            $filaPedidos =
                mysqli_fetch_assoc($resultadoPedidos);

            mysqli_stmt_close($revisarPedidos);

            $totalPedidosActual =
                (int) ($filaPedidos["total"] ?? 0);

            if ($totalPedidosActual > 0) {
                $totalPedidos = $totalPedidosActual;

                $error =
                    "No se puede eliminar porque ahora tiene pedidos relacionados.";
            }
        }
    }

    if ($error === "") {
        $eliminar = mysqli_prepare(
            $conn,
            "DELETE FROM comidas
             WHERE id_comida = ?"
        );

        if (!$eliminar) {
            $error =
                "No se pudo preparar la eliminación.";
        } else {
            mysqli_stmt_bind_param(
                $eliminar,
                "i",
                $idComida
            );

            if (mysqli_stmt_execute($eliminar)) {
                $filasEliminadas =
                    mysqli_stmt_affected_rows($eliminar);

                mysqli_stmt_close($eliminar);

                if ($filasEliminadas === 1) {
                    $imagenEliminada =
                        trim((string) ($comida["imagen"] ?? ""));

                    $eliminada = true;

                    unset(
                        $_SESSION["csrf_eliminar_comida"]
                    );
                } else {
                    $error =
                        "La comida ya no existe.";
                }
            } else {
                mysqli_stmt_close($eliminar);

                $error =
                    "No se pudo eliminar la comida. Puede tener información relacionada.";
            }
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
        Eliminar comida - Hotel Las 3 Palmeras
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
            width: min(650px, 100%);
            overflow: hidden;
            border-radius: 14px;
            background: white;
            box-shadow: 0 26px 70px rgba(0, 0, 0, .30);
        }

        .cabecera {
            padding: 30px 32px;
            border-bottom: 1px solid #e5e7e2;
            background: #fbfcfa;
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

        .icono-exito {
            background: #e8f5eb;
            color: #28653d;
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

        .dato {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 8px 0;
            border-bottom: 1px solid #e4e1da;
            color: #505951;
            font-size: 13px;
        }

        .dato:last-child {
            border-bottom: 0;
        }

        .dato strong {
            color: var(--verde-oscuro);
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

        <div
            class="icono <?php echo $eliminada ? "icono-exito" : ""; ?>"
        >
            <i
                class="bi <?php echo $eliminada ? "bi-check-circle" : "bi-trash3"; ?>"
            ></i>
        </div>

        <h1>
            <?php
            echo $eliminada
                ? "Comida eliminada"
                : "Eliminar comida";
            ?>
        </h1>

        <p class="mb-0">
            <?php
            echo $eliminada
                ? "El registro fue eliminado correctamente."
                : "Revisa la información antes de continuar.";
            ?>
        </p>

    </div>

    <div class="contenido">

        <?php if ($eliminada) { ?>

            <div class="mensaje mensaje-aviso" id="mensajeFirebase">

                <i class="bi bi-cloud-arrow-up"></i>

                <div>
                    Limpiando la imagen almacenada en Firebase...
                </div>

            </div>

            <p>
                En unos segundos volverás al listado de comidas.
            </p>

            <a
                href="index.php?mensaje=eliminado"
                class="btn-cancelar"
            >
                <i class="bi bi-arrow-left"></i>
                Volver ahora
            </a>

        <?php } else { ?>

            <?php if ($error !== "") { ?>

                <div class="mensaje mensaje-error">

                    <i class="bi bi-exclamation-circle"></i>

                    <div>
                        <?php echo h($error); ?>
                    </div>

                </div>

            <?php } ?>

            <?php if ($totalPedidos > 0) { ?>

                <div class="mensaje mensaje-aviso">

                    <i class="bi bi-shield-exclamation"></i>

                    <div>

                        <strong>
                            No se puede eliminar esta comida.
                        </strong>

                        <div class="mt-1">
                            Tiene
                            <?php echo $totalPedidos; ?>
                            pedido(s) relacionado(s).
                            Cámbiala a “No disponible” para ocultarla
                            sin perder el historial.
                        </div>

                    </div>

                </div>

            <?php } ?>

            <div class="resumen">

                <div class="dato">
                    <strong>Comida</strong>
                    <span><?php echo h($comida["nombre"]); ?></span>
                </div>

                <div class="dato">
                    <strong>Tipo</strong>
                    <span><?php echo h($comida["tipo"]); ?></span>
                </div>

                <div class="dato">
                    <strong>Precio</strong>

                    <span>
                        $<?php
                        echo number_format(
                            (float) $comida["precio"],
                            2
                        );
                        ?>
                    </span>
                </div>

                <div class="dato">
                    <strong>Estado</strong>
                    <span><?php echo h($comida["estado"]); ?></span>
                </div>

                <div class="dato">
                    <strong>Pedidos relacionados</strong>
                    <span><?php echo $totalPedidos; ?></span>
                </div>

            </div>

            <?php if ($totalPedidos === 0) { ?>

                <p>
                    Esta acción eliminará definitivamente la comida.
                    Si la comida tiene una imagen guardada en Firebase,
                    también se intentará eliminar después de borrar el registro.
                </p>

                <form method="POST">

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?php echo h($csrf); ?>"
                    >

                    <input
                        type="hidden"
                        name="id_comida"
                        value="<?php echo $idComida; ?>"
                    >

                    <div class="botones">

                        <button
                            type="submit"
                            name="confirmar_eliminacion"
                            class="btn-eliminar"
                        >
                            <i class="bi bi-trash3"></i>
                            Confirmar eliminación
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
                    Volver a comidas
                </a>

            <?php } ?>

        <?php } ?>

    </div>

</section>

<?php if ($eliminada) { ?>

<script type="module">

import {
    storage,
    ref,
    deleteObject
} from "../js/firebase-config.js";

const imagen =
    <?php echo json_encode($imagenEliminada); ?>;

const mensaje =
    document.getElementById("mensajeFirebase");

function obtenerRutaFirebase(urlImagen) {
    if (!urlImagen) {
        return "";
    }

    try {
        const url = new URL(urlImagen);

        if (url.hostname === "firebasestorage.googleapis.com") {
            const coincidencia =
                url.pathname.match(/\/o\/(.+)$/);

            return coincidencia
                ? decodeURIComponent(coincidencia[1])
                : "";
        }

        if (url.hostname === "storage.googleapis.com") {
            const partes =
                url.pathname
                    .split("/")
                    .filter(Boolean);

            return partes.length > 1
                ? decodeURIComponent(partes.slice(1).join("/"))
                : "";
        }

        return "";
    } catch (error) {
        return "";
    }
}

async function limpiarImagen() {
    const ruta =
        obtenerRutaFirebase(imagen);

    if (ruta !== "") {
        try {
            const referencia =
                ref(storage, ruta);

            await deleteObject(referencia);
        } catch (error) {
            console.warn("No se pudo limpiar la imagen de Firebase.");
        }
    }

    mensaje.innerHTML =
        '<i class="bi bi-check-circle"></i>' +
        '<div>Eliminación completada. Redirigiendo...</div>';

    window.setTimeout(
        function () {
            window.location.replace(
                "index.php?mensaje=eliminado"
            );
        },
        900
    );
}

limpiarImagen();

</script>

<?php } ?>

</body>

</html>