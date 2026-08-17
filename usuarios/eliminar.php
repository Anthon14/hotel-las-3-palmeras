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

if (empty($_SESSION["csrf_eliminar_usuario"])) {
    $_SESSION["csrf_eliminar_usuario"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_eliminar_usuario"];

$idUsuario = 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $idUsuario = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT) ?: 0;
} else {
    $idUsuario = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT) ?: 0;
}

if ($idUsuario <= 0) {
    header("Location: index.php");
    exit();
}

$idSesion = (int) ($_SESSION["id_usuario"] ?? 0);

function obtenerUsuario($conn, $idUsuario)
{
    $consulta = mysqli_prepare(
        $conn,
        "SELECT
            u.id_usuario,
            u.nombre,
            u.usuario,
            u.rol,
            c.id_cliente
         FROM usuarios u
         LEFT JOIN clientes c
            ON c.id_usuario = u.id_usuario
         WHERE u.id_usuario = ?
         LIMIT 1"
    );

    if (!$consulta) {
        return null;
    }

    mysqli_stmt_bind_param($consulta, "i", $idUsuario);
    mysqli_stmt_execute($consulta);

    $resultado = mysqli_stmt_get_result($consulta);
    $usuario = mysqli_fetch_assoc($resultado);

    mysqli_stmt_close($consulta);

    return $usuario ?: null;
}

/* Eliminación protegida */
$usuarioEliminar = obtenerUsuario($conn, $idUsuario);

if (!$usuarioEliminar) {
    header("Location: index.php");
    exit();
}

if ($idUsuario === $idSesion) {
    header("Location: index.php?mensaje=usuario_actual");
    exit();
}

if (!empty($usuarioEliminar["id_cliente"])) {
    header("Location: index.php?mensaje=usuario_vinculado");
    exit();
}

if ($usuarioEliminar["rol"] === "Administrador") {
    $consultaAdministradores = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total
         FROM usuarios
         WHERE rol = 'Administrador'
           AND id_usuario != ?"
    );

    if (!$consultaAdministradores) {
        header("Location: index.php?mensaje=error");
        exit();
    }

    mysqli_stmt_bind_param(
        $consultaAdministradores,
        "i",
        $idUsuario
    );

    mysqli_stmt_execute($consultaAdministradores);

    $resultadoAdministradores =
        mysqli_stmt_get_result($consultaAdministradores);

    $administradores =
        mysqli_fetch_assoc($resultadoAdministradores);

    mysqli_stmt_close($consultaAdministradores);

    if ((int) ($administradores["total"] ?? 0) === 0) {
        header("Location: index.php?mensaje=ultimo_admin");
        exit();
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfRecibido = $_POST["csrf"] ?? "";

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        header("Location: index.php?mensaje=error");
        exit();
    }

    $usuarioEliminar = obtenerUsuario($conn, $idUsuario);

    if (!$usuarioEliminar) {
        header("Location: index.php");
        exit();
    }

    if ($idUsuario === $idSesion) {
        header("Location: index.php?mensaje=usuario_actual");
        exit();
    }

    if (!empty($usuarioEliminar["id_cliente"])) {
        header("Location: index.php?mensaje=usuario_vinculado");
        exit();
    }

    if ($usuarioEliminar["rol"] === "Administrador") {
        $consultaAdministradores = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS total
             FROM usuarios
             WHERE rol = 'Administrador'
               AND id_usuario != ?"
        );

        if (!$consultaAdministradores) {
            header("Location: index.php?mensaje=error");
            exit();
        }

        mysqli_stmt_bind_param(
            $consultaAdministradores,
            "i",
            $idUsuario
        );

        mysqli_stmt_execute($consultaAdministradores);

        $resultadoAdministradores =
            mysqli_stmt_get_result($consultaAdministradores);

        $administradores =
            mysqli_fetch_assoc($resultadoAdministradores);

        mysqli_stmt_close($consultaAdministradores);

        if ((int) ($administradores["total"] ?? 0) === 0) {
            header("Location: index.php?mensaje=ultimo_admin");
            exit();
        }
    }

    $eliminarUsuario = mysqli_prepare(
        $conn,
        "DELETE FROM usuarios
         WHERE id_usuario = ?"
    );

    if (!$eliminarUsuario) {
        header("Location: index.php?mensaje=error");
        exit();
    }

    mysqli_stmt_bind_param(
        $eliminarUsuario,
        "i",
        $idUsuario
    );

    if (!mysqli_stmt_execute($eliminarUsuario)) {
        mysqli_stmt_close($eliminarUsuario);

        header("Location: index.php?mensaje=error");
        exit();
    }

    mysqli_stmt_close($eliminarUsuario);

    $_SESSION["csrf_eliminar_usuario"] =
        bin2hex(random_bytes(32));

    header("Location: index.php?mensaje=eliminado");
    exit();
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
        Eliminar usuario - Hotel Las 3 Palmeras
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
            padding: 28px 15px;
            background:
                linear-gradient(
                    rgba(12, 34, 22, .79),
                    rgba(12, 34, 22, .79)
                ),
                url("../img/hotel.jpg") center/cover;
            font-family: Arial, Helvetica, sans-serif;
        }

        .confirmacion-card {
            width: min(560px, 100%);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, .20);
            border-radius: 14px;
            background: white;
            box-shadow: 0 28px 70px rgba(0, 0, 0, .28);
        }

        .confirmacion-cabecera {
            padding: 30px 30px 24px;
            border-bottom: 1px solid #e8ebe7;
            background: #fbfcfa;
            text-align: center;
        }

        .icono-alerta {
            width: 62px;
            height: 62px;
            display: grid;
            place-items: center;
            margin: 0 auto 18px;
            border-radius: 50%;
            background: #fff0f0;
            color: #a43737;
            font-size: 26px;
        }

        .confirmacion-cabecera h1 {
            margin: 0 0 8px;
            color: var(--verde-oscuro);
            font-family: Georgia, "Times New Roman", serif;
            font-size: 29px;
            font-weight: 700;
        }

        .confirmacion-cabecera p {
            margin: 0;
            color: var(--texto-suave);
            font-size: 13px;
            line-height: 1.6;
        }

        .confirmacion-cuerpo {
            padding: 28px 30px 30px;
        }

        .usuario-resumen {
            margin-bottom: 24px;
            padding: 17px 18px;
            border: 1px solid #e1e4df;
            border-radius: 9px;
            background: #f8faf8;
        }

        .usuario-resumen span {
            display: block;
            color: var(--texto-suave);
            font-size: 11px;
        }

        .usuario-resumen strong {
            display: block;
            margin-top: 3px;
            color: var(--verde-oscuro);
            font-size: 16px;
        }

        .usuario-resumen .rol {
            margin-top: 7px;
            color: #765a18;
            font-size: 11px;
            font-weight: 800;
        }

        .aviso {
            margin-bottom: 24px;
            padding: 13px 14px;
            border: 1px solid #ead79f;
            border-radius: 7px;
            background: #fff8df;
            color: #765a18;
            font-size: 12px;
            line-height: 1.6;
        }

        .acciones {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 11px;
        }

        .btn-volver,
        .btn-eliminar {
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
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
            .confirmacion-cabecera,
            .confirmacion-cuerpo {
                padding-left: 22px;
                padding-right: 22px;
            }

            .acciones {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<section class="confirmacion-card">

    <div class="confirmacion-cabecera">

        <div class="icono-alerta">
            <i class="bi bi-person-x"></i>
        </div>

        <h1>Eliminar usuario</h1>

        <p>
            Confirma esta acción antes de eliminar
            definitivamente la cuenta del sistema.
        </p>

    </div>

    <div class="confirmacion-cuerpo">

        <div class="usuario-resumen">
            <span>Cuenta seleccionada</span>

            <strong>
                <?php echo h($usuarioEliminar["nombre"]); ?>
            </strong>

            <span>
                Usuario: <?php echo h($usuarioEliminar["usuario"]); ?>
            </span>

            <div class="rol">
                <?php echo h($usuarioEliminar["rol"]); ?>
            </div>
        </div>

        <div class="aviso">
            <i class="bi bi-exclamation-triangle me-1"></i>
            Esta acción elimina la cuenta de acceso y no se puede deshacer.
        </div>

        <form method="POST">

            <input
                type="hidden"
                name="csrf"
                value="<?php echo h($csrf); ?>"
            >

            <input
                type="hidden"
                name="id"
                value="<?php echo (int) $idUsuario; ?>"
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
                    Sí, eliminar usuario
                </button>

            </div>

        </form>

    </div>

</section>

</body>
</html>