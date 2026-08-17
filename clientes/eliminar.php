<?php
session_start();

include("../config/conexion.php");

if (!isset($_SESSION["usuario"], $_SESSION["rol"])) {
    header("Location: ../login.php");
    exit();
}

$rolActual = strtolower(trim((string) $_SESSION["rol"]));

if (!in_array($rolActual, ["administrador", "recepcionista"], true)) {
    header("Location: ../login.php");
    exit();
}

function h($texto)
{
    return htmlspecialchars((string) $texto, ENT_QUOTES, "UTF-8");
}

function obtenerClienteParaEliminar($conn, $idCliente)
{
    $consulta = mysqli_prepare(
        $conn,
        "SELECT
            c.id_cliente,
            c.id_usuario,
            c.nombres,
            c.apellidos,
            u.rol AS rol_usuario,
            (
                SELECT COUNT(*)
                FROM reservas r
                WHERE r.id_cliente = c.id_cliente
            ) AS total_reservas,
            (
                SELECT COUNT(*)
                FROM pedidos_comida p
                WHERE p.id_cliente = c.id_cliente
            ) AS total_pedidos
         FROM clientes c
         LEFT JOIN usuarios u
            ON u.id_usuario = c.id_usuario
         WHERE c.id_cliente = ?
         LIMIT 1"
    );

    if (!$consulta) {
        return null;
    }

    mysqli_stmt_bind_param($consulta, "i", $idCliente);
    mysqli_stmt_execute($consulta);

    $resultado = mysqli_stmt_get_result($consulta);
    $cliente = mysqli_fetch_assoc($resultado);

    mysqli_stmt_close($consulta);

    return $cliente ?: null;
}

if (empty($_SESSION["csrf_eliminar_cliente"])) {
    $_SESSION["csrf_eliminar_cliente"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_eliminar_cliente"];

$idCliente = 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $idCliente =
        filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT) ?: 0;
} else {
    $idCliente =
        filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT) ?: 0;
}

if ($idCliente <= 0) {
    header("Location: index.php");
    exit();
}

/* Eliminación protegida */
$error = "";
$motivos = [];
$cliente = obtenerClienteParaEliminar($conn, $idCliente);

if (!$cliente) {
    header("Location: index.php");
    exit();
}

if ((int) $cliente["total_reservas"] > 0) {
    $motivos[] =
        "Tiene reservas registradas y se debe conservar su historial.";
}

if ((int) $cliente["total_pedidos"] > 0) {
    $motivos[] =
        "Tiene pedidos de comida registrados y se debe conservar su historial.";
}

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    empty($motivos)
) {
    $csrfRecibido = $_POST["csrf"] ?? "";

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        header("Location: index.php?mensaje=error");
        exit();
    }

    mysqli_begin_transaction($conn);

    try {
        $cliente = obtenerClienteParaEliminar($conn, $idCliente);

        if (!$cliente) {
            throw new Exception(
                "El cliente seleccionado ya no existe."
            );
        }

        if ((int) $cliente["total_reservas"] > 0) {
            throw new Exception(
                "El cliente ahora tiene reservas registradas y no puede eliminarse."
            );
        }

        if ((int) $cliente["total_pedidos"] > 0) {
            throw new Exception(
                "El cliente ahora tiene pedidos registrados y no puede eliminarse."
            );
        }

        $idUsuario =
            !empty($cliente["id_usuario"])
                ? (int) $cliente["id_usuario"]
                : 0;

        $rolUsuario =
            trim((string) ($cliente["rol_usuario"] ?? ""));

        $eliminarCliente = mysqli_prepare(
            $conn,
            "DELETE FROM clientes
             WHERE id_cliente = ?"
        );

        if (!$eliminarCliente) {
            throw new Exception(
                "No se pudo preparar la eliminación del cliente."
            );
        }

        mysqli_stmt_bind_param(
            $eliminarCliente,
            "i",
            $idCliente
        );

        if (!mysqli_stmt_execute($eliminarCliente)) {
            mysqli_stmt_close($eliminarCliente);

            throw new Exception(
                "No se pudo eliminar el cliente."
            );
        }

        if (mysqli_stmt_affected_rows($eliminarCliente) !== 1) {
            mysqli_stmt_close($eliminarCliente);

            throw new Exception(
                "El cliente cambió mientras se procesaba la solicitud."
            );
        }

        mysqli_stmt_close($eliminarCliente);

        if (
            $idUsuario > 0 &&
            $rolUsuario === "Cliente"
        ) {
            $eliminarUsuario = mysqli_prepare(
                $conn,
                "DELETE FROM usuarios
                 WHERE id_usuario = ?
                   AND rol = 'Cliente'"
            );

            if (!$eliminarUsuario) {
                throw new Exception(
                    "No se pudo preparar la eliminación de la cuenta relacionada."
                );
            }

            mysqli_stmt_bind_param(
                $eliminarUsuario,
                "i",
                $idUsuario
            );

            if (!mysqli_stmt_execute($eliminarUsuario)) {
                mysqli_stmt_close($eliminarUsuario);

                throw new Exception(
                    "No se pudo eliminar la cuenta relacionada."
                );
            }

            mysqli_stmt_close($eliminarUsuario);
        }

        mysqli_commit($conn);

        $_SESSION["csrf_eliminar_cliente"] =
            bin2hex(random_bytes(32));

        header("Location: index.php?mensaje=eliminado");
        exit();
    } catch (Throwable $excepcion) {
        mysqli_rollback($conn);

        $error =
            trim((string) $excepcion->getMessage()) !== ""
                ? $excepcion->getMessage()
                : "No se pudo eliminar el cliente.";
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
        <?php echo empty($motivos) ? "Eliminar cliente" : "No se puede eliminar"; ?>
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

    <style>
        :root {
            --verde: #244a35;
            --verde-oscuro: #173325;
            --crema: #f7f3eb;
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
            padding: 25px 15px;
            background:
                linear-gradient(
                    rgba(14, 38, 25, .78),
                    rgba(14, 38, 25, .78)
                ),
                url("../img/hotel.jpg") center/cover;
            font-family: Arial, Helvetica, sans-serif;
        }

        .aviso-card {
            width: min(620px, 100%);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 14px;
            background: white;
            box-shadow: 0 25px 65px rgba(0, 0, 0, .28);
        }

        .aviso-cabecera {
            padding: 32px 34px 24px;
            border-bottom: 1px solid #e8ebe7;
            background: #fbfcfa;
        }

        .aviso-cuerpo {
            padding: 28px 34px 34px;
        }

        .icono {
            width: 64px;
            height: 64px;
            display: grid;
            place-items: center;
            margin-bottom: 20px;
            border-radius: 50%;
            background: #fff0d2;
            color: #8a6514;
            font-size: 28px;
        }

        .icono-eliminar {
            background: #fff0f0;
            color: #a43737;
        }

        h1 {
            margin: 0 0 10px;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 31px;
        }

        p,
        li {
            color: var(--texto-suave);
            font-size: 14px;
            line-height: 1.7;
        }

        .cliente-resumen {
            margin-bottom: 22px;
            padding: 16px 17px;
            border: 1px solid #e1e4df;
            border-radius: 8px;
            background: #f8faf8;
        }

        .cliente-resumen span {
            display: block;
            color: var(--texto-suave);
            font-size: 11px;
        }

        .cliente-resumen strong {
            display: block;
            margin-top: 3px;
            color: var(--verde-oscuro);
            font-size: 16px;
        }

        .motivos {
            margin: 0 0 20px;
            padding: 15px 17px 15px 36px;
            border: 1px solid #ead79f;
            border-radius: 8px;
            background: #fff8df;
        }

        .error {
            margin-bottom: 20px;
            padding: 14px 16px;
            border: 1px solid #edc8c8;
            border-radius: 8px;
            background: #fff1f1;
            color: #9b3131;
            font-size: 13px;
        }

        .acciones {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 11px;
            margin-top: 24px;
        }

        .btn-volver,
        .btn-eliminar {
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 900;
            text-decoration: none;
        }

        .btn-volver {
            border: 1px solid #d9ded9;
            background: white;
            color: var(--verde);
        }

        .btn-volver:hover {
            background: #f4f7f4;
            color: var(--verde-oscuro);
        }

        .btn-eliminar {
            border: 1px solid #a93333;
            background: #a93333;
            color: white;
        }

        .btn-eliminar:hover {
            background: #842929;
            color: white;
        }

        @media (max-width: 520px) {
            .aviso-cabecera,
            .aviso-cuerpo {
                padding-left: 22px;
                padding-right: 22px;
            }

            h1 {
                font-size: 27px;
            }

            .acciones {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<section class="aviso-card">

    <div class="aviso-cabecera">

        <div
            class="icono <?php echo empty($motivos) ? "icono-eliminar" : ""; ?>"
        >
            <i
                class="bi <?php
                echo empty($motivos)
                    ? "bi-person-x"
                    : "bi-shield-exclamation";
                ?>"
            ></i>
        </div>

        <h1>
            <?php
            echo empty($motivos)
                ? "Eliminar cliente"
                : "No se puede eliminar";
            ?>
        </h1>

        <p class="mb-0">
            <?php if (empty($motivos)) { ?>
                Confirma la eliminación antes de borrar
                definitivamente este registro.
            <?php } else { ?>
                El registro debe mantenerse para proteger
                el historial del hotel.
            <?php } ?>
        </p>

    </div>

    <div class="aviso-cuerpo">

        <?php if ($error !== "") { ?>

            <div class="error">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <?php echo h($error); ?>
            </div>

        <?php } ?>

        <div class="cliente-resumen">
            <span>Cliente seleccionado</span>

            <strong>
                <?php
                echo h(
                    $cliente["nombres"] .
                    " " .
                    $cliente["apellidos"]
                );
                ?>
            </strong>

            <?php if (!empty($cliente["id_usuario"])) { ?>
                <span class="mt-1">
                    Cuenta de acceso vinculada:
                    <?php echo h($cliente["rol_usuario"] ?? "Cliente"); ?>
                </span>
            <?php } else { ?>
                <span class="mt-1">
                    Huésped sin cuenta de acceso.
                </span>
            <?php } ?>
        </div>

        <?php if (!empty($motivos)) { ?>

            <p>
                Este cliente tiene información relacionada:
            </p>

            <ul class="motivos">
                <?php foreach ($motivos as $motivo) { ?>
                    <li><?php echo h($motivo); ?></li>
                <?php } ?>
            </ul>

            <p class="mb-0">
                No se eliminará ningún dato.
            </p>

            <div class="acciones">
                <a
                    href="index.php"
                    class="btn-volver"
                    style="grid-column: 1 / -1;"
                >
                    <i class="bi bi-arrow-left"></i>
                    Volver a clientes
                </a>
            </div>

        <?php } else { ?>

            <p class="mb-0">
                Si tiene una cuenta con rol Cliente, esa cuenta
                también se eliminará. Una cuenta administrativa
                relacionada se conservará.
            </p>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf"
                    value="<?php echo h($csrf); ?>"
                >

                <input
                    type="hidden"
                    name="id"
                    value="<?php echo (int) $idCliente; ?>"
                >

                <div class="acciones">

                    <a
                        href="index.php"
                        class="btn-volver"
                    >
                        <i class="bi bi-arrow-left"></i>
                        Cancelar
                    </a>

                    <button
                        type="submit"
                        class="btn-eliminar"
                    >
                        <i class="bi bi-trash"></i>
                        Sí, eliminar cliente
                    </button>

                </div>

            </form>

        <?php } ?>

    </div>

</section>

</body>
</html>