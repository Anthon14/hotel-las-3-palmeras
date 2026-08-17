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

function urlImagenValida($url)
{
    $url = trim((string) $url);

    if ($url === "") {
        return "";
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return "";
    }

    $esquema = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    if (!in_array($esquema, ["http", "https"], true)) {
        return "";
    }

    return $url;
}

if (
    !isset($_GET["id"]) ||
    !filter_var($_GET["id"], FILTER_VALIDATE_INT)
) {
    header("Location: index.php");
    exit();
}

$idComida = (int) $_GET["id"];

if (empty($_SESSION["csrf_editar_comida"])) {
    $_SESSION["csrf_editar_comida"] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_editar_comida"];
$errores = [];

$tiposPermitidos = [
    "Desayuno",
    "Almuerzo",
    "Cena",
    "Bebida",
    "Extra"
];

$estadosPermitidos = [
    "Disponible",
    "No disponible"
];

$consulta = mysqli_prepare(
    $conn,
    "SELECT
        co.id_comida,
        co.nombre,
        co.tipo,
        co.descripcion,
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
    header("Location: index.php");
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

$nombre = (string) $comida["nombre"];
$tipo = (string) $comida["tipo"];
$descripcion = (string) $comida["descripcion"];
$precio = (string) $comida["precio"];
$estado = (string) $comida["estado"];
$imagenActual = trim((string) ($comida["imagen"] ?? ""));
$imagenNueva = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfRecibido = $_POST["csrf"] ?? "";

    $nombre = trim((string) ($_POST["nombre"] ?? ""));
    $tipo = trim((string) ($_POST["tipo"] ?? ""));
    $descripcion = trim((string) ($_POST["descripcion"] ?? ""));
    $precioTexto = trim((string) ($_POST["precio"] ?? ""));
    $estado = trim((string) ($_POST["estado"] ?? ""));
    $imagenNueva = trim((string) ($_POST["imagen_url"] ?? ""));

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $errores[] =
            "La solicitud no es válida. Actualiza la página.";
    }

    if ($nombre === "") {
        $errores[] =
            "Ingrese el nombre de la comida.";
    } elseif (mb_strlen($nombre) > 100) {
        $errores[] =
            "El nombre no puede superar los 100 caracteres.";
    }

    if (!in_array($tipo, $tiposPermitidos, true)) {
        $errores[] =
            "Seleccione un tipo de comida válido.";
    }

    if (mb_strlen($descripcion) > 255) {
        $errores[] =
            "La descripción no puede superar los 255 caracteres.";
    }

    if (
        $precioTexto === "" ||
        !is_numeric($precioTexto)
    ) {
        $errores[] =
            "Ingrese un precio válido.";
        $precio = "";
    } else {
        $precioNumero =
            round((float) $precioTexto, 2);

        $precio = number_format(
            $precioNumero,
            2,
            ".",
            ""
        );

        if ($precioNumero <= 0) {
            $errores[] =
                "El precio debe ser mayor que cero.";
        }

        if ($precioNumero > 99999.99) {
            $errores[] =
                "El precio ingresado es demasiado alto.";
        }
    }

    if (!in_array($estado, $estadosPermitidos, true)) {
        $errores[] =
            "Seleccione un estado válido.";
    }

    if (
        $imagenNueva !== "" &&
        urlImagenValida($imagenNueva) === ""
    ) {
        $errores[] =
            "La nueva imagen no tiene una dirección válida.";
    }

    if (empty($errores)) {
        $consultaDuplicada = mysqli_prepare(
            $conn,
            "SELECT id_comida
             FROM comidas
             WHERE LOWER(nombre) = LOWER(?)
               AND tipo = ?
               AND id_comida != ?
             LIMIT 1"
        );

        if (!$consultaDuplicada) {
            $errores[] =
                "No se pudo comprobar la información.";
        } else {
            mysqli_stmt_bind_param(
                $consultaDuplicada,
                "ssi",
                $nombre,
                $tipo,
                $idComida
            );

            mysqli_stmt_execute($consultaDuplicada);

            $resultadoDuplicada =
                mysqli_stmt_get_result($consultaDuplicada);

            if (mysqli_num_rows($resultadoDuplicada) > 0) {
                $errores[] =
                    "Ya existe otra comida con ese nombre y tipo.";
            }

            mysqli_stmt_close($consultaDuplicada);
        }
    }

    if (empty($errores)) {
        $imagenGuardar =
            $imagenNueva !== ""
                ? $imagenNueva
                : $imagenActual;

        $actualizar = mysqli_prepare(
            $conn,
            "UPDATE comidas
             SET
                nombre = ?,
                tipo = ?,
                descripcion = ?,
                precio = ?,
                estado = ?,
                imagen = ?
             WHERE id_comida = ?"
        );

        if (!$actualizar) {
            $errores[] =
                "No se pudo preparar la actualización.";
        } else {
            $precioNumero = (float) $precio;

            mysqli_stmt_bind_param(
                $actualizar,
                "sssdssi",
                $nombre,
                $tipo,
                $descripcion,
                $precioNumero,
                $estado,
                $imagenGuardar,
                $idComida
            );

            if (mysqli_stmt_execute($actualizar)) {
                mysqli_stmt_close($actualizar);

                $_SESSION["csrf_editar_comida"] =
                    bin2hex(random_bytes(32));

                header(
                    "Location: index.php?mensaje=actualizado"
                );
                exit();
            }

            mysqli_stmt_close($actualizar);

            $errores[] =
                "No se pudo actualizar la comida.";
        }
    }
}

$imagenVista =
    $imagenNueva !== ""
        ? urlImagenValida($imagenNueva)
        : urlImagenValida($imagenActual);

if ($imagenVista === "") {
    $imagenVista = "../img/hotel.jpg";
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

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Editar comida - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=54"
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
            min-height: 330px;
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
            padding: 62px 0;
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
            font-size: clamp(2.8rem, 6vw, 4.8rem);
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

        .mensaje-error {
            display: flex;
            gap: 10px;
            margin-bottom: 22px;
            padding: 14px 17px;
            border: 1px solid #edc8c8;
            border-radius: 6px;
            background: #fff1f1;
            color: #9b3131;
            font-size: 13px;
        }

        .editar-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            box-shadow: var(--sombra);
        }

        .imagen-columna {
            min-height: 100%;
            padding: 28px;
            background: #f4f6f2;
        }

        .imagen-actual {
            width: 100%;
            height: 390px;
            object-fit: cover;
            border: 1px solid #dfe3dd;
            border-radius: 9px;
            background: #ecefeb;
        }

        .imagen-info {
            margin-top: 13px;
            color: var(--texto-suave);
            font-size: 12px;
            line-height: 1.6;
        }

        .historial {
            margin-top: 16px;
            padding: 13px 15px;
            border: 1px solid #dedfd9;
            border-radius: 6px;
            background: white;
            color: #59615b;
            font-size: 12px;
        }

        .formulario-columna {
            padding: 30px;
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

        .estado-opciones {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .estado-opcion {
            position: relative;
            margin: 0;
        }

        .estado-opcion input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .estado-card {
            min-height: 74px;
            position: relative;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 12px 36px 12px 13px;
            border: 1px solid #dce1dc;
            border-radius: 10px;
            background: #f8faf8;
            cursor: pointer;
            transition: .2s ease;
        }

        .estado-card:hover {
            border-color: #bdc7bf;
            background: white;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(35, 55, 42, .08);
        }

        .estado-icono {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: #edf2ee;
            color: var(--verde);
            font-size: 16px;
        }

        .estado-texto strong {
            display: block;
            color: var(--verde-oscuro);
            font-size: 12px;
        }

        .estado-texto small {
            display: block;
            margin-top: 2px;
            color: var(--texto-suave);
            font-size: 9px;
            line-height: 1.4;
        }

        .estado-check {
            position: absolute;
            top: 9px;
            right: 10px;
            color: #c7cec9;
            font-size: 15px;
        }

        .estado-opcion input:checked + .estado-card {
            border: 2px solid var(--verde);
            background: #f3f9f5;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, .08);
        }

        .estado-opcion input:checked + .estado-card .estado-icono {
            background: var(--verde);
            color: white;
        }

        .estado-opcion input:checked + .estado-card .estado-check {
            color: var(--verde);
        }

        .estado-opcion.no-disponible input:checked + .estado-card {
            border-color: #b74f4f;
            background: #fff2f2;
            box-shadow: 0 0 0 4px rgba(183, 79, 79, .08);
        }

        .estado-opcion.no-disponible input:checked + .estado-card .estado-icono {
            background: #b74f4f;
            color: white;
        }

        .estado-opcion.no-disponible input:checked + .estado-card .estado-check {
            color: #b74f4f;
        }

        .btn-actualizar,
        .btn-cancelar {
            min-height: 47px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 20px;
            border-radius: 5px;
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

        @media (max-width: 991px) {
            .imagen-actual {
                height: 320px;
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

            .imagen-columna,
            .formulario-columna {
                padding: 22px;
            }
        }

        @media (max-width: 420px) {
            .marca-texto {
                display: none;
            }

            .estado-opciones {
                grid-template-columns: 1fr;
            }

            .btn-actualizar,
            .btn-cancelar {
                width: 100%;
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
                    <a href="index.php" class="nav-link active">
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
                            <a href="../clientes/index.php" class="dropdown-item">
                                <i class="bi bi-people me-2"></i>
                                Clientes
                            </a>
                        </li>

                        <li>
                            <a href="../pedidos/index.php" class="dropdown-item">
                                <i class="bi bi-receipt me-2"></i>
                                Pedidos
                            </a>
                        </li>

                        <?php if ($esAdministrador) { ?>

                            <li>
                                <a href="../pagos/index.php" class="dropdown-item">
                                    <i class="bi bi-credit-card me-2"></i>
                                    Pagos
                                </a>
                            </li>

                            <li>
                                <a href="../usuarios/index.php" class="dropdown-item">
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

            <h1>Editar comida</h1>

            <p>
                Modifica el nombre, tipo, precio, disponibilidad
                o imagen del producto seleccionado.
            </p>

        </div>
    </div>
</section>

<main class="contenido-pagina">
    <div class="container">

        <?php if (!empty($errores)) { ?>

            <div class="mensaje-error">

                <i class="bi bi-exclamation-triangle"></i>

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

        <section class="editar-card">

            <div class="row g-0">

                <div class="col-lg-5">

                    <div class="imagen-columna h-100">

                        <img
                            src="<?php echo h($imagenVista); ?>"
                            alt="<?php echo h($nombre); ?>"
                            id="vistaPrevia"
                            class="imagen-actual"
                            onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                        >

                        <div class="imagen-info">
                            La imagen actual se conserva cuando
                            no seleccionas un archivo nuevo.
                        </div>

                        <div class="historial">

                            <i class="bi bi-receipt me-1"></i>

                            Esta comida tiene
                            <strong>
                                <?php echo (int) $comida["total_pedidos"]; ?>
                            </strong>
                            pedidos relacionados.

                            Los pedidos anteriores conservan
                            el precio unitario que tenían al registrarse.

                        </div>

                    </div>

                </div>

                <div class="col-lg-7">

                    <div class="formulario-columna">

                        <div class="pagina-etiqueta text-success">
                            COMIDA #<?php echo $idComida; ?>
                        </div>

                        <h2 class="mt-2 mb-2">
                            Actualizar información
                        </h2>

                        <p class="text-muted small mb-4">
                            Completa los cambios y guarda la información.
                        </p>

                        <form
                            method="POST"
                            id="formEditarComida"
                            autocomplete="off"
                        >

                            <input
                                type="hidden"
                                name="csrf"
                                value="<?php echo h($csrf); ?>"
                            >

                            <div class="mb-3">

                                <label
                                    for="nombre"
                                    class="form-label"
                                >
                                    Nombre
                                </label>

                                <input
                                    type="text"
                                    id="nombre"
                                    name="nombre"
                                    class="form-control"
                                    maxlength="100"
                                    value="<?php echo h($nombre); ?>"
                                    required
                                >

                            </div>

                            <div class="row g-3">

                                <div class="col-md-6">

                                    <label
                                        for="tipo"
                                        class="form-label"
                                    >
                                        Tipo
                                    </label>

                                    <select
                                        id="tipo"
                                        name="tipo"
                                        class="form-select"
                                        required
                                    >

                                        <?php foreach ($tiposPermitidos as $tipoPermitido) { ?>

                                            <option
                                                value="<?php echo h($tipoPermitido); ?>"
                                                <?php
                                                echo $tipo === $tipoPermitido
                                                    ? "selected"
                                                    : "";
                                                ?>
                                            >
                                                <?php echo h($tipoPermitido); ?>
                                            </option>

                                        <?php } ?>

                                    </select>

                                </div>

                                <div class="col-md-6">

                                    <label
                                        for="precio"
                                        class="form-label"
                                    >
                                        Precio
                                    </label>

                                    <input
                                        type="number"
                                        id="precio"
                                        name="precio"
                                        class="form-control"
                                        min="0.01"
                                        max="99999.99"
                                        step="0.01"
                                        value="<?php echo h($precio); ?>"
                                        required
                                    >

                                </div>

                            </div>

                            <div class="mt-3">

                                <label
                                    for="descripcion"
                                    class="form-label"
                                >
                                    Descripción
                                </label>

                                <textarea
                                    id="descripcion"
                                    name="descripcion"
                                    class="form-control"
                                    rows="3"
                                    maxlength="255"
                                ><?php echo h($descripcion); ?></textarea>

                            </div>

                            <div class="mt-3">

                                <div class="form-label">
                                    Estado
                                </div>

                                <div class="estado-opciones">

                                    <label class="estado-opcion">

                                        <input
                                            type="radio"
                                            name="estado"
                                            value="Disponible"
                                            <?php echo $estado === "Disponible" ? "checked" : ""; ?>
                                            required
                                        >

                                        <span class="estado-card">

                                            <span class="estado-icono">
                                                <i class="bi bi-check-circle"></i>
                                            </span>

                                            <span class="estado-texto">
                                                <strong>Disponible</strong>
                                                <small>
                                                    Visible y disponible para pedidos.
                                                </small>
                                            </span>

                                            <span class="estado-check">
                                                <i class="bi bi-check-circle-fill"></i>
                                            </span>

                                        </span>

                                    </label>

                                    <label class="estado-opcion no-disponible">

                                        <input
                                            type="radio"
                                            name="estado"
                                            value="No disponible"
                                            <?php echo $estado === "No disponible" ? "checked" : ""; ?>
                                            required
                                        >

                                        <span class="estado-card">

                                            <span class="estado-icono">
                                                <i class="bi bi-slash-circle"></i>
                                            </span>

                                            <span class="estado-texto">
                                                <strong>No disponible</strong>
                                                <small>
                                                    Temporalmente fuera del menú.
                                                </small>
                                            </span>

                                            <span class="estado-check">
                                                <i class="bi bi-check-circle-fill"></i>
                                            </span>

                                        </span>

                                    </label>

                                </div>

                            </div>

                            <div class="mt-3">

                                <label
                                    for="archivo_imagen"
                                    class="form-label"
                                >
                                    Nueva imagen
                                </label>

                                <input
                                    type="file"
                                    id="archivo_imagen"
                                    class="form-control"
                                    accept=".jpg,.jpeg,.png,.webp"
                                >

                                <div class="text-muted small mt-2">
                                    Déjalo vacío para conservar la imagen actual.
                                    JPG, PNG o WEBP de máximo 5 MB.
                                </div>

                                <input
                                    type="hidden"
                                    name="imagen_url"
                                    id="imagen_url"
                                    value="<?php echo h($imagenNueva); ?>"
                                >

                                <div
                                    id="mensajeSubida"
                                    class="small mt-2"
                                ></div>

                            </div>

                            <div class="d-flex flex-wrap gap-3 mt-4">

                                <button
                                    type="submit"
                                    id="btnActualizar"
                                    class="btn-actualizar"
                                >
                                    <i class="bi bi-check-circle"></i>
                                    Guardar cambios
                                </button>

                                <a
                                    href="index.php"
                                    class="btn-cancelar"
                                >
                                    <i class="bi bi-arrow-left"></i>
                                    Volver sin guardar
                                </a>

                            </div>

                        </form>

                    </div>

                </div>

            </div>

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
                href="index.php"
                class="btn btn-outline-light btn-sm"
            >
                Volver a comidas
            </a>

        </div>

    </div>

    <div class="footer-final">

        <div class="container d-flex justify-content-between flex-wrap gap-2">

            <span>
                Hotel Las 3 Palmeras © 2026
            </span>

            <span>
                Edición de comidas
            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script type="module">

import {
    storage,
    ref,
    uploadBytes,
    getDownloadURL
} from "../js/firebase-config.js";

const formEditarComida =
    document.getElementById("formEditarComida");

const archivoImagen =
    document.getElementById("archivo_imagen");

const imagenUrl =
    document.getElementById("imagen_url");

const mensajeSubida =
    document.getElementById("mensajeSubida");

const btnActualizar =
    document.getElementById("btnActualizar");

const vistaPrevia =
    document.getElementById("vistaPrevia");

let urlTemporal = null;

archivoImagen.addEventListener(
    "change",
    function () {
        imagenUrl.value = "";
        mensajeSubida.textContent = "";

        const archivo =
            archivoImagen.files[0];

        if (urlTemporal) {
            URL.revokeObjectURL(urlTemporal);
            urlTemporal = null;
        }

        if (archivo) {
            urlTemporal =
                URL.createObjectURL(archivo);

            vistaPrevia.src = urlTemporal;
        }
    }
);

formEditarComida.addEventListener(
    "submit",
    async function (evento) {
        const archivo =
            archivoImagen.files[0];

        if (!archivo) {
            return;
        }

        if (imagenUrl.value !== "") {
            return;
        }

        evento.preventDefault();

        const tiposPermitidos = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];

        if (!tiposPermitidos.includes(archivo.type)) {
            mensajeSubida.className =
                "text-danger small mt-2";

            mensajeSubida.textContent =
                "La imagen seleccionada no es válida.";

            return;
        }

        const limite =
            5 * 1024 * 1024;

        if (archivo.size > limite) {
            mensajeSubida.className =
                "text-danger small mt-2";

            mensajeSubida.textContent =
                "La imagen supera los 5 MB.";

            return;
        }

        btnActualizar.disabled = true;

        btnActualizar.innerHTML =
            '<span class="spinner-border spinner-border-sm"></span> Subiendo imagen';

        mensajeSubida.className =
            "text-primary small mt-2";

        mensajeSubida.textContent =
            "Subiendo la nueva imagen a Firebase...";

        try {
            const nombreSeguro =
                archivo.name.replace(
                    /[^a-zA-Z0-9._-]/g,
                    "_"
                );

            const identificador =
                window.crypto?.randomUUID
                    ? window.crypto.randomUUID()
                    : Date.now().toString();

            const ruta =
                "comidas/" +
                identificador +
                "_" +
                nombreSeguro;

            const referencia =
                ref(storage, ruta);

            await uploadBytes(
                referencia,
                archivo,
                {
                    contentType: archivo.type
                }
            );

            const url =
                await getDownloadURL(referencia);

            imagenUrl.value = url;

            mensajeSubida.className =
                "text-success small mt-2";

            mensajeSubida.textContent =
                "Nueva imagen subida correctamente.";

            formEditarComida.submit();
        } catch (error) {
            console.error(error);

            mensajeSubida.className =
                "text-danger small mt-2";

            mensajeSubida.textContent =
                "No se pudo subir la nueva imagen.";

            btnActualizar.disabled = false;

            btnActualizar.innerHTML =
                '<i class="bi bi-check-circle"></i> Guardar cambios';
        }
    }
);

window.addEventListener(
    "beforeunload",
    function () {
        if (urlTemporal) {
            URL.revokeObjectURL(urlTemporal);
        }
    }
);

</script>

</body>

</html>