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

function limpiarUsuario($texto)
{
    $texto = trim((string) $texto);

    if (function_exists("iconv")) {
        $convertido = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $texto);

        if ($convertido !== false) {
            $texto = $convertido;
        }
    }

    $texto = strtolower($texto);
    $texto = preg_replace("/[^a-z0-9._-]+/", ".", $texto);
    $texto = preg_replace("/[._-]{2,}/", ".", $texto);
    $texto = trim($texto, "._-");

    return substr($texto, 0, 30);
}

function usuarioExiste($conn, $usuario)
{
    $consulta = mysqli_prepare(
        $conn,
        "SELECT id_usuario FROM usuarios WHERE usuario = ? LIMIT 1"
    );

    if (!$consulta) {
        throw new Exception("No se pudo comprobar el nombre de usuario.");
    }

    mysqli_stmt_bind_param($consulta, "s", $usuario);
    mysqli_stmt_execute($consulta);

    $resultado = mysqli_stmt_get_result($consulta);
    $existe = mysqli_num_rows($resultado) > 0;

    mysqli_stmt_close($consulta);

    return $existe;
}

function generarUsuario($conn, $nombres, $apellidos)
{
    $primerNombre = preg_split("/\s+/", trim($nombres))[0] ?? "cliente";
    $primerApellido = preg_split("/\s+/", trim($apellidos))[0] ?? "";
    $base = limpiarUsuario($primerNombre . "." . $primerApellido);

    if ($base === "") {
        $base = "cliente";
    }

    $usuario = $base;
    $numero = 2;

    while (usuarioExiste($conn, $usuario)) {
        $sufijo = (string) $numero;
        $usuario = substr($base, 0, 30 - strlen($sufijo)) . $sufijo;
        $numero++;
    }

    return $usuario;
}

if (
    !isset($_GET["id"]) ||
    !filter_var($_GET["id"], FILTER_VALIDATE_INT)
) {
    header("Location: index.php");
    exit();
}

$idCliente = (int) $_GET["id"];

if (empty($_SESSION["csrf_editar_cliente"])) {
    $_SESSION["csrf_editar_cliente"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_editar_cliente"];
$errores = [];

$consultaCliente = mysqli_prepare(
    $conn,
    "SELECT
        c.id_cliente,
        c.id_usuario,
        c.nombres,
        c.apellidos,
        c.cedula,
        c.telefono,
        c.correo,
        u.usuario,
        u.rol
     FROM clientes c
     LEFT JOIN usuarios u ON u.id_usuario = c.id_usuario
     WHERE c.id_cliente = ?
     LIMIT 1"
);

if (!$consultaCliente) {
    die("No se pudo consultar el cliente.");
}

mysqli_stmt_bind_param($consultaCliente, "i", $idCliente);
mysqli_stmt_execute($consultaCliente);

$resultadoCliente = mysqli_stmt_get_result($consultaCliente);
$datos = mysqli_fetch_assoc($resultadoCliente);

mysqli_stmt_close($consultaCliente);

if (!$datos) {
    header("Location: index.php");
    exit();
}

$idUsuario = !empty($datos["id_usuario"]) ? (int) $datos["id_usuario"] : 0;
$tieneCuenta = $idUsuario > 0;

$nombres = $datos["nombres"];
$apellidos = $datos["apellidos"];
$cedula = $datos["cedula"];
$correo = $datos["correo"];
$telefono = $datos["telefono"];
$usuarioActual = $datos["usuario"] ?? "";
$rolCuenta = $datos["rol"] ?? "";
$crearCuenta = false;
$usuarioNuevo = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["actualizar"])) {
    $csrfRecibido = $_POST["csrf"] ?? "";
    $nombres = trim($_POST["nombres"] ?? "");
    $apellidos = trim($_POST["apellidos"] ?? "");
    $cedula = trim($_POST["cedula"] ?? "");
    $correo = trim($_POST["correo"] ?? "");
    $telefono = trim($_POST["telefono"] ?? "");

    $crearCuenta = !$tieneCuenta && isset($_POST["crear_cuenta"]);
    $usuarioNuevo = trim($_POST["usuario_acceso"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmarPassword = $_POST["confirmar_password"] ?? "";

    if (!is_string($csrfRecibido) || !hash_equals($csrf, $csrfRecibido)) {
        $errores[] = "La solicitud no es válida. Actualiza la página.";
    }

    if ($nombres === "") {
        $errores[] = "Los nombres son obligatorios.";
    }

    if ($apellidos === "") {
        $errores[] = "Los apellidos son obligatorios.";
    }

    if (!preg_match("/^[0-9]{10}$/", $cedula)) {
        $errores[] = "La cédula debe tener exactamente 10 números.";
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] = "Ingrese un correo electrónico válido.";
    }

    if (!preg_match("/^[0-9]{10}$/", $telefono)) {
        $errores[] = "El teléfono debe tener exactamente 10 números.";
    }

    try {
        $consultaDuplicados = mysqli_prepare(
            $conn,
            "SELECT cedula, correo
             FROM clientes
             WHERE (cedula = ? OR correo = ?)
               AND id_cliente != ?
             LIMIT 1"
        );

        if (!$consultaDuplicados) {
            throw new Exception("No se pudieron comprobar los datos del cliente.");
        }

        mysqli_stmt_bind_param(
            $consultaDuplicados,
            "ssi",
            $cedula,
            $correo,
            $idCliente
        );

        mysqli_stmt_execute($consultaDuplicados);

        $resultadoDuplicados = mysqli_stmt_get_result($consultaDuplicados);
        $duplicado = mysqli_fetch_assoc($resultadoDuplicados);

        mysqli_stmt_close($consultaDuplicados);

        if ($duplicado) {
            if ($duplicado["cedula"] === $cedula) {
                $errores[] = "La cédula pertenece a otro cliente.";
            }

            if (strtolower($duplicado["correo"]) === strtolower($correo)) {
                $errores[] = "El correo pertenece a otro cliente.";
            }
        }

        $usuarioFinal = "";

        if ($crearCuenta) {
            if ($usuarioNuevo !== "") {
                $usuarioFinal = limpiarUsuario($usuarioNuevo);

                if (strlen($usuarioFinal) < 4) {
                    $errores[] = "El nombre de usuario debe tener mínimo 4 caracteres.";
                } elseif (usuarioExiste($conn, $usuarioFinal)) {
                    $errores[] = "El nombre de usuario ya está ocupado.";
                }
            }

            if (strlen($password) < 8) {
                $errores[] = "La contraseña debe tener mínimo 8 caracteres.";
            }

            if ($password !== $confirmarPassword) {
                $errores[] = "Las contraseñas no coinciden.";
            }
        }

        if (empty($errores)) {
            mysqli_begin_transaction($conn);

            $actualizarCliente = mysqli_prepare(
                $conn,
                "UPDATE clientes
                 SET nombres = ?,
                     apellidos = ?,
                     cedula = ?,
                     telefono = ?,
                     correo = ?
                 WHERE id_cliente = ?"
            );

            if (!$actualizarCliente) {
                throw new Exception("No se pudo preparar la actualización.");
            }

            mysqli_stmt_bind_param(
                $actualizarCliente,
                "sssssi",
                $nombres,
                $apellidos,
                $cedula,
                $telefono,
                $correo,
                $idCliente
            );

            if (!mysqli_stmt_execute($actualizarCliente)) {
                mysqli_stmt_close($actualizarCliente);
                throw new Exception("No se pudo actualizar el cliente.");
            }

            mysqli_stmt_close($actualizarCliente);

            if ($tieneCuenta) {
                $nombreCompleto = trim($nombres . " " . $apellidos);

                $actualizarNombreUsuario = mysqli_prepare(
                    $conn,
                    "UPDATE usuarios
                     SET nombre = ?
                     WHERE id_usuario = ?"
                );

                if (!$actualizarNombreUsuario) {
                    throw new Exception("No se pudo sincronizar el nombre de la cuenta.");
                }

                mysqli_stmt_bind_param(
                    $actualizarNombreUsuario,
                    "si",
                    $nombreCompleto,
                    $idUsuario
                );

                if (!mysqli_stmt_execute($actualizarNombreUsuario)) {
                    mysqli_stmt_close($actualizarNombreUsuario);
                    throw new Exception("No se pudo sincronizar el nombre de la cuenta.");
                }

                mysqli_stmt_close($actualizarNombreUsuario);
            }

            if ($crearCuenta) {
                if ($usuarioFinal === "") {
                    $usuarioFinal = generarUsuario($conn, $nombres, $apellidos);
                }

                $nombreCompleto = trim($nombres . " " . $apellidos);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $rolNuevo = "Cliente";

                $crearUsuario = mysqli_prepare(
                    $conn,
                    "INSERT INTO usuarios (nombre, usuario, password, rol)
                     VALUES (?, ?, ?, ?)"
                );

                if (!$crearUsuario) {
                    throw new Exception("No se pudo preparar la cuenta.");
                }

                mysqli_stmt_bind_param(
                    $crearUsuario,
                    "ssss",
                    $nombreCompleto,
                    $usuarioFinal,
                    $passwordHash,
                    $rolNuevo
                );

                if (!mysqli_stmt_execute($crearUsuario)) {
                    mysqli_stmt_close($crearUsuario);
                    throw new Exception("No se pudo crear la cuenta.");
                }

                $idUsuarioNuevo = mysqli_insert_id($conn);
                mysqli_stmt_close($crearUsuario);

                $relacionarCuenta = mysqli_prepare(
                    $conn,
                    "UPDATE clientes
                     SET id_usuario = ?
                     WHERE id_cliente = ?"
                );

                if (!$relacionarCuenta) {
                    throw new Exception("No se pudo relacionar la cuenta.");
                }

                mysqli_stmt_bind_param(
                    $relacionarCuenta,
                    "ii",
                    $idUsuarioNuevo,
                    $idCliente
                );

                if (!mysqli_stmt_execute($relacionarCuenta)) {
                    mysqli_stmt_close($relacionarCuenta);
                    throw new Exception("No se pudo relacionar la cuenta.");
                }

                mysqli_stmt_close($relacionarCuenta);
            }

            mysqli_commit($conn);

            header("Location: index.php?mensaje=actualizado");
            exit();
        }
    } catch (Throwable $error) {
        @mysqli_rollback($conn);
        $errores[] = $error->getMessage();
    }
}

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Editar cliente - Hotel Las 3 Palmeras</title>

    <link
        rel="icon"
        type="image/png"
        href="../img/logocircular.png?v=3"
    >

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css?v=46">

    <style>
        :root {
            --verde: #244a35;
            --verde-oscuro: #173325;
            --verde-claro: #e9f0eb;
            --crema: #f7f3eb;
            --texto-suave: #687068;
            --sombra: 0 18px 45px rgba(21, 45, 32, .12);
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
            min-height: 350px;
            display: flex;
            align-items: center;
            margin-top: 82px;
            color: white;
            background:
                linear-gradient(90deg, rgba(10, 29, 20, .92), rgba(10, 29, 20, .61)),
                url("../img/hotel.jpg") center/cover;
        }

        .pagina-hero-contenido {
            max-width: 760px;
            padding: 65px 0;
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
            max-width: 650px;
            color: rgba(255, 255, 255, .82);
            line-height: 1.7;
        }

        .contenido-pagina {
            padding: 75px 0;
        }

        .mensaje-error {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
            padding: 14px 17px;
            border: 1px solid #edc8c8;
            border-radius: 6px;
            background: #fff1f1;
            color: #9b3131;
            font-size: 13px;
        }

        .formulario-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 8px;
            background: white;
            box-shadow: var(--sombra);
        }

        .formulario-cabecera {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 24px 27px;
            border-bottom: 1px solid #e6e7e1;
            background: #fbfcfa;
        }

        .formulario-icono {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: var(--verde-claro);
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
            padding: 29px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 900;
        }

        .form-control {
            min-height: 49px;
            border: 1px solid #dce1dc;
            background: #f7f9f7;
            font-size: 13px;
        }

        .form-control:focus {
            border-color: var(--verde);
            box-shadow: 0 0 0 4px rgba(36, 74, 53, .10);
        }

        .form-text {
            color: var(--texto-suave);
            font-size: 11px;
        }

        .cuenta-card {
            padding: 20px;
            border: 1px solid #dedfd9;
            border-radius: 8px;
            background: #fbfcfa;
        }

        .cuenta-card h4 {
            margin-bottom: 6px;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 20px;
        }

        .estado-cuenta {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 11px;
            border-radius: 999px;
            background: #dff2e4;
            color: #21643b;
            font-size: 11px;
            font-weight: 900;
        }

        .sin-cuenta {
            background: #fff0c7;
            color: #81600d;
        }

        .aviso {
            padding: 13px 15px;
            border-radius: 6px;
            background: #f5f1e6;
            color: #6e5a2c;
            font-size: 11px;
            line-height: 1.6;
        }

        .crear-cuenta-opcion {
            margin-top: 15px;
            position: relative;
        }

        .crear-cuenta-opcion input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .crear-cuenta-card {
            min-height: 76px;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 15px;
            border: 1px solid #dce1dc;
            border-radius: 10px;
            background: white;
            cursor: pointer;
            transition: .2s ease;
        }

        .crear-cuenta-card:hover {
            border-color: #bcc8be;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(35, 55, 42, .08);
        }

        .crear-cuenta-icono {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: var(--verde-claro);
            color: var(--verde);
            font-size: 18px;
        }

        .crear-cuenta-texto {
            min-width: 0;
            flex: 1;
        }

        .crear-cuenta-texto strong {
            display: block;
            color: var(--verde-oscuro);
            font-size: 12px;
        }

        .crear-cuenta-texto small {
            display: block;
            margin-top: 3px;
            color: var(--texto-suave);
            font-size: 10px;
            line-height: 1.4;
        }

        .crear-cuenta-check {
            color: #c5ccc7;
            font-size: 18px;
        }

        .crear-cuenta-opcion input:checked + .crear-cuenta-card {
            border: 2px solid var(--verde);
            background: #f3f9f5;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, .08);
        }

        .crear-cuenta-opcion input:checked + .crear-cuenta-card .crear-cuenta-icono {
            background: var(--verde);
            color: white;
        }

        .crear-cuenta-opcion input:checked + .crear-cuenta-card .crear-cuenta-check {
            color: var(--verde);
        }

        .btn-actualizar,
        .btn-cancelar {
            min-height: 47px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 20px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 900;
        }

        .btn-actualizar {
            border: 1px solid var(--verde);
            background: var(--verde);
            color: white;
        }

        .btn-actualizar:hover {
            background: var(--verde-oscuro);
            color: white;
        }

        .btn-cancelar {
            border: 1px solid #bdc3bd;
            background: white;
            color: #555d57;
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
                padding: 55px 0;
            }

            .formulario-cuerpo {
                padding: 22px;
            }

            .btn-actualizar,
            .btn-cancelar {
                width: 100%;
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
        }
    </style>
</head>

<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-hotel fixed-top">
    <div class="container">
        <a href="../dashboard.php" class="navbar-brand marca-hotel p-0">
            <img src="../img/logo.png" alt="Hotel Las 3 Palmeras" class="navbar-logo">

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
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="menuPrincipal">
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a href="../dashboard.php" class="nav-link">Inicio</a>
                </li>

                <li class="nav-item">
                    <a href="../habitaciones/index.php" class="nav-link">Habitaciones</a>
                </li>

                <li class="nav-item">
                    <a href="../reservas/index.php" class="nav-link">Reservas</a>
                </li>

                <li class="nav-item">
                    <a href="../comidas/index.php" class="nav-link">Comidas</a>
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
                            <a href="index.php" class="dropdown-item active">
                                <i class="bi bi-people me-2"></i>Clientes
                            </a>
                        </li>

                        <li>
                            <a href="../pedidos/index.php" class="dropdown-item">
                                <i class="bi bi-receipt me-2"></i>Pedidos
                            </a>
                        </li>

                        <?php if ($esAdministrador) { ?>
                            <li>
                                <a href="../pagos/index.php" class="dropdown-item">
                                    <i class="bi bi-credit-card me-2"></i>Pagos
                                </a>
                            </li>

                            <li>
                                <a href="../usuarios/index.php" class="dropdown-item">
                                    <i class="bi bi-person-gear me-2"></i>Usuarios
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

                                                    <strong>Nuevo pago pendiente</strong>

                                                    <span>
                                                        <?php
                                                        echo h(
                                                            $notificacionPago["nombres"] .
                                                            " " .
                                                            $notificacionPago["apellidos"]
                                                        );
                                                        ?>
                                                        · Reserva #
                                                        <?php echo (int) $notificacionPago["id_reserva"]; ?>
                                                    </span>

                                                    <span>
                                                        Habitación
                                                        <?php echo h($notificacionPago["numero_habitacion"]); ?>
                                                        ·
                                                        <?php echo h($notificacionPago["metodo_pago"]); ?>
                                                    </span>

                                                    <span class="notificacion-pago-monto">
                                                        $<?php
                                                        echo number_format(
                                                            (float) $notificacionPago["monto"],
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
                    <strong><?php echo h($_SESSION["usuario"]); ?></strong>

                    <span class="rol-navbar">
                        <i class="bi bi-shield-check me-1"></i>
                        <?php echo h($_SESSION["rol"]); ?>
                    </span>
                </div>

                <a href="../logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                    <i class="bi bi-box-arrow-right me-1"></i>Salir
                </a>
            </div>
        </div>
    </div>
</nav>

<section class="pagina-hero">
    <div class="container">
        <div class="pagina-hero-contenido">
            <div class="pagina-etiqueta">ADMINISTRACIÓN HOTELERA</div>

            <h1>Editar cliente</h1>

            <p>
                Modifica los datos del huésped y revisa su cuenta de acceso.
                Las cuentas existentes se administran desde el módulo Usuarios.
            </p>
        </div>
    </div>
</section>

<main class="contenido-pagina">
    <div class="container">

        <?php if (!empty($errores)) { ?>
            <div class="mensaje-error">
                <i class="bi bi-exclamation-circle"></i>

                <div>
                    <strong>No se pudo actualizar:</strong>

                    <ul class="mt-2 mb-0">
                        <?php foreach ($errores as $error) { ?>
                            <li><?php echo h($error); ?></li>
                        <?php } ?>
                    </ul>
                </div>
            </div>
        <?php } ?>

        <div class="formulario-card">
            <div class="formulario-cabecera">
                <div class="formulario-icono">
                    <i class="bi bi-pencil-square"></i>
                </div>

                <div>
                    <h3><?php echo h($nombres . " " . $apellidos); ?></h3>
                    <p>Cliente número <?php echo $idCliente; ?></p>
                </div>
            </div>

            <div class="formulario-cuerpo">
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">

                    <div class="row g-4">
                        <div class="col-md-6">
                            <label for="nombres" class="form-label">Nombres</label>

                            <input
                                type="text"
                                id="nombres"
                                name="nombres"
                                class="form-control"
                                value="<?php echo h($nombres); ?>"
                                maxlength="80"
                                required
                            >
                        </div>

                        <div class="col-md-6">
                            <label for="apellidos" class="form-label">Apellidos</label>

                            <input
                                type="text"
                                id="apellidos"
                                name="apellidos"
                                class="form-control"
                                value="<?php echo h($apellidos); ?>"
                                maxlength="80"
                                required
                            >
                        </div>

                        <div class="col-md-4">
                            <label for="cedula" class="form-label">Cédula</label>

                            <input
                                type="text"
                                id="cedula"
                                name="cedula"
                                class="form-control numerico"
                                value="<?php echo h($cedula); ?>"
                                maxlength="10"
                                inputmode="numeric"
                                required
                            >
                        </div>

                        <div class="col-md-4">
                            <label for="correo" class="form-label">Correo</label>

                            <input
                                type="email"
                                id="correo"
                                name="correo"
                                class="form-control"
                                value="<?php echo h($correo); ?>"
                                maxlength="120"
                                required
                            >
                        </div>

                        <div class="col-md-4">
                            <label for="telefono" class="form-label">Teléfono</label>

                            <input
                                type="text"
                                id="telefono"
                                name="telefono"
                                class="form-control numerico"
                                value="<?php echo h($telefono); ?>"
                                maxlength="10"
                                inputmode="numeric"
                                required
                            >
                        </div>

                        <div class="col-12">
                            <div class="cuenta-card">
                                <h4>Cuenta de acceso</h4>

                                <?php if ($tieneCuenta) { ?>
                                    <span class="estado-cuenta">
                                        <i class="bi bi-person-check"></i>
                                        Tiene cuenta
                                    </span>

                                    <div class="row g-3 mt-1">
                                        <div class="col-md-6">
                                            <label class="form-label">Usuario</label>

                                            <input
                                                type="text"
                                                class="form-control"
                                                value="<?php echo h($usuarioActual); ?>"
                                                readonly
                                            >
                                        </div>

                                        <div class="col-md-6">
                                            <label class="form-label">Rol actual</label>

                                            <input
                                                type="text"
                                                class="form-control"
                                                value="<?php echo h($rolCuenta); ?>"
                                                readonly
                                            >
                                        </div>
                                    </div>

                                    <div class="aviso mt-3">
                                        <i class="bi bi-info-circle me-1"></i>

                                        El usuario, contraseña y rol ya no se modifican aquí.

                                        <?php if ($esAdministrador) { ?>
                                            Adminístralos desde
                                            <a href="../usuarios/index.php"><strong>Usuarios</strong></a>.
                                        <?php } else { ?>
                                            Solamente un Administrador puede modificarlos.
                                        <?php } ?>
                                    </div>
                                <?php } else { ?>
                                    <span class="estado-cuenta sin-cuenta">
                                        <i class="bi bi-person-dash"></i>
                                        Solo huésped
                                    </span>

                                    <label class="crear-cuenta-opcion">

                                        <input
                                            type="checkbox"
                                            id="crear_cuenta"
                                            name="crear_cuenta"
                                            <?php echo $crearCuenta ? "checked" : ""; ?>
                                        >

                                        <span class="crear-cuenta-card">

                                            <span class="crear-cuenta-icono">
                                                <i class="bi bi-person-plus"></i>
                                            </span>

                                            <span class="crear-cuenta-texto">
                                                <strong>Crear cuenta de acceso</strong>
                                                <small>
                                                    Permite que este huésped ingrese al sistema como Cliente.
                                                </small>
                                            </span>

                                            <span class="crear-cuenta-check">
                                                <i class="bi bi-check-circle-fill"></i>
                                            </span>

                                        </span>

                                    </label>

                                    <div id="camposCuentaNueva" class="mt-3">
                                        <div class="row g-3">
                                            <div class="col-md-4">
                                                <label for="usuario_acceso" class="form-label">
                                                    Nombre de usuario
                                                </label>

                                                <input
                                                    type="text"
                                                    id="usuario_acceso"
                                                    name="usuario_acceso"
                                                    class="form-control"
                                                    maxlength="30"
                                                    value="<?php echo h($usuarioNuevo); ?>"
                                                    placeholder="Opcional"
                                                >

                                                <div class="form-text">
                                                    Vacío: se genera automáticamente.
                                                </div>
                                            </div>

                                            <div class="col-md-4">
                                                <label for="password" class="form-label">
                                                    Contraseña
                                                </label>

                                                <input
                                                    type="password"
                                                    id="password"
                                                    name="password"
                                                    class="form-control"
                                                    minlength="8"
                                                    autocomplete="new-password"
                                                >
                                            </div>

                                            <div class="col-md-4">
                                                <label for="confirmar_password" class="form-label">
                                                    Confirmar contraseña
                                                </label>

                                                <input
                                                    type="password"
                                                    id="confirmar_password"
                                                    name="confirmar_password"
                                                    class="form-control"
                                                    minlength="8"
                                                    autocomplete="new-password"
                                                >
                                            </div>
                                        </div>

                                        <div class="form-text mt-2">
                                            La cuenta se creará únicamente con rol Cliente.
                                            Cualquier cambio de rol se hará después en Usuarios.
                                        </div>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>

                        <div class="col-12 d-flex flex-wrap gap-2">
                            <button type="submit" name="actualizar" class="btn-actualizar">
                                <i class="bi bi-check-circle"></i>
                                Actualizar cliente
                            </button>

                            <a href="index.php" class="btn-cancelar">
                                <i class="bi bi-arrow-left"></i>
                                Volver sin guardar
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<footer class="footer-hotel mt-5">
    <div class="footer-principal">
        <div class="container d-flex flex-wrap justify-content-between align-items-center gap-4">
            <div class="d-flex align-items-center gap-3">
                <img src="../img/logo.png" alt="Hotel Las 3 Palmeras" class="footer-logo">

                <div>
                    <h4 class="mb-1">Hotel Las 3 Palmeras</h4>
                    <small class="text-white-50">Sistema administrativo hotelero.</small>
                </div>
            </div>

            <a href="index.php" class="btn btn-outline-light btn-sm">
                Volver a clientes
            </a>
        </div>
    </div>

    <div class="footer-final">
        <div class="container d-flex justify-content-between flex-wrap gap-2">
            <span>Hotel Las 3 Palmeras © 2026</span>
            <span>Edición de clientes</span>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

<script>
    document.querySelectorAll(".numerico").forEach((campo) => {
        campo.addEventListener("input", () => {
            campo.value = campo.value.replace(/\D/g, "");
        });
    });

    const crearCuenta = document.getElementById("crear_cuenta");
    const camposCuentaNueva = document.getElementById("camposCuentaNueva");
    const usuario = document.getElementById("usuario_acceso");
    const password = document.getElementById("password");
    const confirmar = document.getElementById("confirmar_password");

    function actualizarCuentaNueva() {
        if (!crearCuenta || !camposCuentaNueva) {
            return;
        }

        const activa = crearCuenta.checked;

        camposCuentaNueva.classList.toggle("d-none", !activa);
        password.required = activa;
        confirmar.required = activa;
    }

    if (crearCuenta) {
        crearCuenta.addEventListener("change", actualizarCuentaNueva);
        actualizarCuentaNueva();
    }

    if (usuario) {
        usuario.addEventListener("input", () => {
            usuario.value = usuario.value
                .toLowerCase()
                .normalize("NFD")
                .replace(/[\u0300-\u036f]/g, "")
                .replace(/[^a-z0-9._-]/g, "");
        });
    }
</script>

</body>
</html>