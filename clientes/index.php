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

if (empty($_SESSION["csrf_clientes"])) {
    $_SESSION["csrf_clientes"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_clientes"];
$errores = [];

$nombres = "";
$apellidos = "";
$cedula = "";
$correo = "";
$telefono = "";
$tipoRegistro = "huesped";
$usuarioEscrito = "";

$registroCreado = $_SESSION["registro_cliente_creado"] ?? null;
unset($_SESSION["registro_cliente_creado"]);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar"])) {
    $csrfRecibido = $_POST["csrf"] ?? "";
    $nombres = trim($_POST["nombres"] ?? "");
    $apellidos = trim($_POST["apellidos"] ?? "");
    $cedula = trim($_POST["cedula"] ?? "");
    $correo = trim($_POST["correo"] ?? "");
    $telefono = trim($_POST["telefono"] ?? "");
    $tipoRegistro = trim($_POST["tipo_registro"] ?? "huesped");
    $usuarioEscrito = trim($_POST["usuario_acceso"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmarPassword = $_POST["confirmar_password"] ?? "";

    if (!is_string($csrfRecibido) || !hash_equals($csrf, $csrfRecibido)) {
        $errores[] = "La solicitud no es válida. Actualiza la página.";
    }

    if (!in_array($tipoRegistro, ["huesped", "cuenta"], true)) {
        $errores[] = "Seleccione un tipo de registro válido.";
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

    $usuarioFinal = "";
    $usuarioAutomatico = false;

    try {
        $consultaDuplicados = mysqli_prepare(
            $conn,
            "SELECT cedula, correo
             FROM clientes
             WHERE cedula = ? OR correo = ?
             LIMIT 1"
        );

        if (!$consultaDuplicados) {
            throw new Exception("No se pudieron comprobar los datos del huésped.");
        }

        mysqli_stmt_bind_param($consultaDuplicados, "ss", $cedula, $correo);
        mysqli_stmt_execute($consultaDuplicados);

        $resultadoDuplicados = mysqli_stmt_get_result($consultaDuplicados);
        $duplicado = mysqli_fetch_assoc($resultadoDuplicados);

        mysqli_stmt_close($consultaDuplicados);

        if ($duplicado) {
            if ($duplicado["cedula"] === $cedula) {
                $errores[] = "La cédula ya está registrada.";
            }

            if (strtolower($duplicado["correo"]) === strtolower($correo)) {
                $errores[] = "El correo ya está registrado.";
            }
        }

        if ($tipoRegistro === "cuenta") {
            if ($usuarioEscrito !== "") {
                $usuarioFinal = limpiarUsuario($usuarioEscrito);

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

            $idUsuarioNuevo = null;

            if ($tipoRegistro === "cuenta") {
                if ($usuarioFinal === "") {
                    $usuarioFinal = generarUsuario($conn, $nombres, $apellidos);
                    $usuarioAutomatico = true;
                }

                $nombreCompleto = trim($nombres . " " . $apellidos);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $rolCuenta = "Cliente";

                $guardarUsuario = mysqli_prepare(
                    $conn,
                    "INSERT INTO usuarios (nombre, usuario, password, rol)
                     VALUES (?, ?, ?, ?)"
                );

                if (!$guardarUsuario) {
                    throw new Exception("No se pudo preparar la cuenta del cliente.");
                }

                mysqli_stmt_bind_param(
                    $guardarUsuario,
                    "ssss",
                    $nombreCompleto,
                    $usuarioFinal,
                    $passwordHash,
                    $rolCuenta
                );

                if (!mysqli_stmt_execute($guardarUsuario)) {
                    mysqli_stmt_close($guardarUsuario);
                    throw new Exception("No se pudo crear la cuenta del cliente.");
                }

                $idUsuarioNuevo = mysqli_insert_id($conn);
                mysqli_stmt_close($guardarUsuario);
            }

            if ($tipoRegistro === "cuenta") {
                $guardarCliente = mysqli_prepare(
                    $conn,
                    "INSERT INTO clientes
                        (id_usuario, nombres, apellidos, cedula, telefono, correo)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );

                if (!$guardarCliente) {
                    throw new Exception("No se pudo preparar el registro del cliente.");
                }

                mysqli_stmt_bind_param(
                    $guardarCliente,
                    "isssss",
                    $idUsuarioNuevo,
                    $nombres,
                    $apellidos,
                    $cedula,
                    $telefono,
                    $correo
                );
            } else {
                $guardarCliente = mysqli_prepare(
                    $conn,
                    "INSERT INTO clientes
                        (nombres, apellidos, cedula, telefono, correo)
                     VALUES (?, ?, ?, ?, ?)"
                );

                if (!$guardarCliente) {
                    throw new Exception("No se pudo preparar el registro del huésped.");
                }

                mysqli_stmt_bind_param(
                    $guardarCliente,
                    "sssss",
                    $nombres,
                    $apellidos,
                    $cedula,
                    $telefono,
                    $correo
                );
            }

            if (!mysqli_stmt_execute($guardarCliente)) {
                mysqli_stmt_close($guardarCliente);
                throw new Exception("No se pudo guardar el huésped.");
            }

            mysqli_stmt_close($guardarCliente);
            mysqli_commit($conn);

            $_SESSION["registro_cliente_creado"] = [
                "tipo" => $tipoRegistro,
                "usuario" => $usuarioFinal,
                "automatico" => $usuarioAutomatico
            ];

            header("Location: index.php?mensaje=guardado");
            exit();
        }
    } catch (Throwable $error) {
        @mysqli_rollback($conn);
        $errores[] = $error->getMessage();
    }
}

$porPagina = 10;

$paginaActual = max(
    1,
    (int) ($_GET["pagina"] ?? 1)
);

$totalClientes = 0;

$consultaTotalClientes = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total
     FROM clientes"
);

if ($consultaTotalClientes) {
    $filaTotalClientes =
        mysqli_fetch_assoc($consultaTotalClientes);

    $totalClientes =
        (int) ($filaTotalClientes["total"] ?? 0);
}

$totalPaginas = max(
    1,
    (int) ceil(
        $totalClientes / $porPagina
    )
);

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset =
    ($paginaActual - 1) * $porPagina;

$clientes = mysqli_query(
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
     LEFT JOIN usuarios u
        ON u.id_usuario = c.id_usuario
     ORDER BY c.id_cliente DESC
     LIMIT $porPagina
     OFFSET $offset"
);

$primerRegistro =
    $totalClientes > 0
        ? $offset + 1
        : 0;

$ultimoRegistro =
    min(
        $offset + $porPagina,
        $totalClientes
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
    $consultaPagosPendientes = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM pagos
         WHERE estado_pago = 'Pendiente'"
    );

    if ($consultaPagosPendientes) {
        $filaPagosPendientes =
            mysqli_fetch_assoc($consultaPagosPendientes);

        $pagosPendientes =
            (int) ($filaPagosPendientes["total"] ?? 0);
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
         ORDER BY p.fecha_pago DESC, p.id_pago DESC
         LIMIT 6"
    );
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Clientes - Hotel Las 3 Palmeras</title>

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
            --dorado: #d8b56d;
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
            padding: 17px 18px;
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
            max-height: 365px;
            overflow-y: auto;
        }

        .notificacion-pago-admin {
            display: block;
            padding: 15px 18px;
            border-bottom: 1px solid #edf0ec;
            color: #20231f;
        }

        .notificacion-pago-admin:hover {
            background: #f4f8f5;
            color: #20231f;
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
            min-height: 390px;
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
            font-size: clamp(2.8rem, 6vw, 5.2rem);
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

        .formulario-card,
        .tabla-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 8px;
            background: white;
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
            padding: 28px;
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
            font-size: 11px;
            color: var(--texto-suave);
        }

        .tipo-card {
            height: 100%;
            display: flex;
            gap: 12px;
            padding: 16px;
            border: 1px solid #dce1dc;
            border-radius: 8px;
            background: #f8faf8;
            cursor: pointer;
        }

        .tipo-card:has(input:checked) {
            border-color: var(--verde);
            background: var(--verde-claro);
            box-shadow: 0 0 0 3px rgba(36, 74, 53, .08);
        }

        .tipo-card input {
            margin-top: 4px;
        }

        .tipo-card strong {
            display: block;
            color: var(--verde-oscuro);
            font-size: 13px;
        }

        .tipo-card small {
            color: var(--texto-suave);
            font-size: 11px;
            line-height: 1.5;
        }

        .cuenta-campos {
            padding: 20px;
            border: 1px solid #dedfd9;
            border-radius: 8px;
            background: #fbfcfa;
        }

        .btn-guardar {
            min-height: 49px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 4px;
            padding: 11px 20px;
            background: var(--verde);
            color: white;
            font-size: 13px;
            font-weight: 900;
        }

        .btn-guardar:hover {
            background: var(--verde-oscuro);
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

        .tabla-hotel {
            margin: 0;
        }

        .tabla-hotel thead th {
            padding: 15px 16px;
            border: 0;
            background: var(--verde-oscuro);
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

        .cuenta {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #dff2e4;
            color: #21643b;
            font-size: 10px;
            font-weight: 900;
        }

        .sin-cuenta {
            background: #fff0c7;
            color: #81600d;
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

            background: #fbfcfa;
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

        .btn-editar,
        .btn-eliminar {
            padding: 8px 11px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 900;
        }

        .btn-editar {
            background: #fff5d9;
            color: #72550d;
            border: 1px solid #d4b25e;
        }

        .btn-eliminar {
            background: #fff0f0;
            color: #9d3030;
            border: 1px solid #e0a9a9;
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
            aria-controls="menuPrincipal"
            aria-expanded="false"
            aria-label="Abrir menú"
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

                                                <div class="notificacion-pago-icono">
                                                    <i class="bi bi-receipt"></i>
                                                </div>

                                                <div class="notificacion-pago-contenido">
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
                                                        <?php
                                                        echo (int)
                                                            $notificacionPago["id_reserva"];
                                                        ?>
                                                    </span>

                                                    <span>
                                                        Habitación
                                                        <?php
                                                        echo h(
                                                            $notificacionPago["numero_habitacion"]
                                                        );
                                                        ?>
                                                        ·
                                                        <?php
                                                        echo h(
                                                            $notificacionPago["metodo_pago"]
                                                        );
                                                        ?>
                                                    </span>

                                                    <span class="notificacion-pago-monto">
                                                        $<?php
                                                        echo number_format(
                                                            (float) $notificacionPago["monto"],
                                                            2
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

            <h1>Gestión de clientes</h1>

            <p>
                Registra a una persona solamente como huésped o crea también una
                cuenta de Cliente para que pueda iniciar sesión en el sistema.
            </p>
        </div>
    </div>
</section>

<main class="contenido-pagina">
    <div class="container">

        <?php if (isset($_GET["mensaje"]) && $_GET["mensaje"] === "guardado") { ?>
            <div class="mensaje mensaje-exito">
                <i class="bi bi-check-circle"></i>

                <div>
                    <?php if ($registroCreado && $registroCreado["tipo"] === "cuenta") { ?>
                        Huésped y cuenta de Cliente creados correctamente.

                        <div class="mt-1">
                            Usuario:
                            <strong><?php echo h($registroCreado["usuario"]); ?></strong>

                            <?php if ($registroCreado["automatico"]) { ?>
                                (generado automáticamente)
                            <?php } ?>
                        </div>
                    <?php } else { ?>
                        Huésped registrado correctamente sin cuenta de acceso.
                    <?php } ?>
                </div>
            </div>
        <?php } ?>

        <?php if (isset($_GET["mensaje"]) && $_GET["mensaje"] === "actualizado") { ?>
            <div class="mensaje mensaje-exito">
                <i class="bi bi-check-circle"></i>
                Cliente actualizado correctamente.
            </div>
        <?php } ?>

        <?php if (isset($_GET["mensaje"]) && $_GET["mensaje"] === "eliminado") { ?>
            <div class="mensaje mensaje-exito">
                <i class="bi bi-check-circle"></i>
                Cliente eliminado correctamente.
            </div>
        <?php } ?>

        <?php if (!empty($errores)) { ?>
            <div class="mensaje mensaje-error">
                <i class="bi bi-exclamation-circle"></i>

                <div>
                    <strong>No se pudo registrar:</strong>

                    <ul class="mt-2 mb-0">
                        <?php foreach ($errores as $error) { ?>
                            <li><?php echo h($error); ?></li>
                        <?php } ?>
                    </ul>
                </div>
            </div>
        <?php } ?>

        <section>
            <p class="seccion-etiqueta">NUEVO HUÉSPED</p>
            <h2 class="seccion-titulo">Registrar cliente</h2>

            <p class="seccion-texto mb-4">
                Por defecto se registra solo como huésped. La cuenta de acceso es opcional.
            </p>

            <div class="formulario-card">
                <div class="formulario-cabecera">
                    <div class="formulario-icono">
                        <i class="bi bi-person-plus"></i>
                    </div>

                    <div>
                        <h3>Información del huésped</h3>
                        <p>Selecciona si también necesita una cuenta para iniciar sesión.</p>
                    </div>
                </div>

                <div class="formulario-cuerpo">
                    <form method="POST" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">

                        <div class="row g-4">
                            <div class="col-12">
                                <label class="form-label">Tipo de registro</label>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="tipo-card">
                                            <input
                                                type="radio"
                                                name="tipo_registro"
                                                value="huesped"
                                                <?php echo $tipoRegistro === "huesped" ? "checked" : ""; ?>
                                            >

                                            <span>
                                                <strong>Solo huésped</strong>
                                                <small>
                                                    Guarda sus datos, reservas, pagos y pedidos.
                                                    No podrá iniciar sesión.
                                                </small>
                                            </span>
                                        </label>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="tipo-card">
                                            <input
                                                type="radio"
                                                name="tipo_registro"
                                                value="cuenta"
                                                <?php echo $tipoRegistro === "cuenta" ? "checked" : ""; ?>
                                            >

                                            <span>
                                                <strong>Huésped con cuenta</strong>
                                                <small>
                                                    También crea un usuario con rol Cliente
                                                    para que pueda iniciar sesión.
                                                </small>
                                            </span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="nombres" class="form-label">Nombres</label>

                                <input
                                    type="text"
                                    id="nombres"
                                    name="nombres"
                                    class="form-control"
                                    maxlength="80"
                                    value="<?php echo h($nombres); ?>"
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
                                    maxlength="80"
                                    value="<?php echo h($apellidos); ?>"
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
                                    maxlength="10"
                                    value="<?php echo h($cedula); ?>"
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
                                    maxlength="120"
                                    value="<?php echo h($correo); ?>"
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
                                    maxlength="10"
                                    value="<?php echo h($telefono); ?>"
                                    inputmode="numeric"
                                    required
                                >
                            </div>

                            <div class="col-12" id="camposCuenta">
                                <div class="cuenta-campos">
                                    <div class="row g-4">
                                        <div class="col-12">
                                            <strong>Cuenta de acceso como Cliente</strong>

                                            <div class="form-text">
                                                El rol no se cambia aquí. Los cambios de rol se administran
                                                desde el módulo Usuarios y solamente por un Administrador.
                                            </div>
                                        </div>

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
                                                value="<?php echo h($usuarioEscrito); ?>"
                                                placeholder="Opcional"
                                            >

                                            <div class="form-text">
                                                Vacío: se genera automáticamente.
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <label for="password" class="form-label">Contraseña</label>

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
                                </div>
                            </div>

                            <div class="col-12">
                                <button type="submit" name="guardar" class="btn-guardar">
                                    <i class="bi bi-person-check"></i>
                                    <span id="textoGuardar">Guardar huésped</span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <section id="clientesRegistrados">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
                <div>
                    <p class="seccion-etiqueta">REGISTROS</p>
                    <h2 class="seccion-titulo">Clientes registrados</h2>
                </div>

                <span class="contador">
                    <i class="bi bi-people"></i>
                    <?php echo $totalClientes; ?> clientes
                </span>
            </div>

            <div class="tabla-card">
                <div class="table-responsive">
                    <table class="table tabla-hotel align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Cliente</th>
                                <th>Cédula</th>
                                <th>Correo</th>
                                <th>Teléfono</th>
                                <th>Cuenta</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php if ($clientes && mysqli_num_rows($clientes) > 0) { ?>
                                <?php while ($row = mysqli_fetch_assoc($clientes)) { ?>
                                    <tr>
                                        <td><?php echo (int) $row["id_cliente"]; ?></td>

                                        <td>
                                            <strong>
                                                <?php echo h($row["nombres"] . " " . $row["apellidos"]); ?>
                                            </strong>
                                        </td>

                                        <td><?php echo h($row["cedula"]); ?></td>
                                        <td><?php echo h($row["correo"]); ?></td>
                                        <td><?php echo h($row["telefono"]); ?></td>

                                        <td>
                                            <?php if (!empty($row["id_usuario"])) { ?>
                                                <span class="cuenta">
                                                    <i class="bi bi-person-check"></i>
                                                    Con cuenta
                                                </span>

                                                <small class="d-block text-muted mt-1">
                                                    Usuario: <?php echo h($row["usuario"]); ?>
                                                </small>
                                            <?php } else { ?>
                                                <span class="cuenta sin-cuenta">
                                                    <i class="bi bi-person-dash"></i>
                                                    Solo huésped
                                                </span>
                                            <?php } ?>
                                        </td>

                                        <td>
                                            <div class="acciones">
                                                <a
                                                    href="editar.php?id=<?php echo urlencode($row["id_cliente"]); ?>"
                                                    class="btn-editar"
                                                >
                                                    <i class="bi bi-pencil-square"></i>
                                                    Editar
                                                </a>

                                                <a
                                                    href="eliminar.php?id=<?php echo urlencode($row["id_cliente"]); ?>"
                                                    class="btn-eliminar"
                                                    onclick="return confirm('¿Deseas eliminar este cliente?');"
                                                >
                                                    <i class="bi bi-trash"></i>
                                                    Eliminar
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
                            <?php } else { ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        No existen clientes registrados.
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalClientes > 0) { ?>

                    <div class="paginacion-contenedor">

                        <div class="paginacion-info">
                            Mostrando
                            <?php echo $primerRegistro; ?>
                            -
                            <?php echo $ultimoRegistro; ?>
                            de
                            <?php echo $totalClientes; ?>
                            clientes
                        </div>

                        <?php if ($totalPaginas > 1) { ?>

                            <nav
                                class="paginacion-hotel"
                                aria-label="Paginación de clientes"
                            >

                                <?php if ($paginaActual > 1) { ?>

                                    <a
                                        href="?pagina=<?php echo $paginaActual - 1; ?>#clientesRegistrados"
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

                                    <a href="?pagina=1#clientesRegistrados">
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
                                            href="?pagina=<?php echo $pagina; ?>#clientesRegistrados"
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
                                        href="?pagina=<?php echo $totalPaginas; ?>#clientesRegistrados"
                                    >
                                        <?php echo $totalPaginas; ?>
                                    </a>

                                <?php } ?>

                                <?php if (
                                    $paginaActual < $totalPaginas
                                ) { ?>

                                    <a
                                        href="?pagina=<?php echo $paginaActual + 1; ?>#clientesRegistrados"
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
        </section>
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

            <a href="../dashboard.php" class="btn btn-outline-light btn-sm">
                Volver al panel
            </a>
        </div>
    </div>

    <div class="footer-final">
        <div class="container d-flex justify-content-between flex-wrap gap-2">
            <span>Hotel Las 3 Palmeras © 2026</span>
            <span>Módulo de clientes</span>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const opcionesRegistro = document.querySelectorAll(
        'input[name="tipo_registro"]'
    );

    const camposCuenta = document.getElementById("camposCuenta");
    const usuario = document.getElementById("usuario_acceso");
    const password = document.getElementById("password");
    const confirmar = document.getElementById("confirmar_password");
    const textoGuardar = document.getElementById("textoGuardar");

    function actualizarTipoRegistro() {
        const seleccion = document.querySelector(
            'input[name="tipo_registro"]:checked'
        );

        const crearCuenta = seleccion && seleccion.value === "cuenta";

        camposCuenta.classList.toggle("d-none", !crearCuenta);
        password.required = crearCuenta;
        confirmar.required = crearCuenta;

        textoGuardar.textContent = crearCuenta
            ? "Guardar huésped y cuenta"
            : "Guardar solo huésped";
    }

    opcionesRegistro.forEach((opcion) => {
        opcion.addEventListener("change", actualizarTipoRegistro);
    });

    actualizarTipoRegistro();

    document.querySelectorAll(".numerico").forEach((campo) => {
        campo.addEventListener("input", () => {
            campo.value = campo.value.replace(/\D/g, "");
        });
    });

    usuario.addEventListener("input", () => {
        usuario.value = usuario.value
            .toLowerCase()
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "")
            .replace(/[^a-z0-9._-]/g, "");
    });
</script>

</body>
</html>