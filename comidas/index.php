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

function imagenSegura($imagen)
{
    $imagen = trim((string) $imagen);

    if ($imagen === "") {
        return "../img/hotel.jpg";
    }

    if (!filter_var($imagen, FILTER_VALIDATE_URL)) {
        return "../img/hotel.jpg";
    }

    $esquema = strtolower((string) parse_url($imagen, PHP_URL_SCHEME));

    if (!in_array($esquema, ["http", "https"], true)) {
        return "../img/hotel.jpg";
    }

    return $imagen;
}

if (empty($_SESSION["csrf_comidas"])) {
    $_SESSION["csrf_comidas"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_comidas"];
$errores = [];

$nombre = "";
$tipo = "";
$descripcion = "";
$precio = "";
$estado = "Disponible";
$imagen = "";

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

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfRecibido = $_POST["csrf"] ?? "";

    $nombre = trim((string) ($_POST["nombre"] ?? ""));
    $tipo = trim((string) ($_POST["tipo"] ?? ""));
    $descripcion = trim((string) ($_POST["descripcion"] ?? ""));
    $precioTexto = trim((string) ($_POST["precio"] ?? ""));
    $estado = trim((string) ($_POST["estado"] ?? ""));
    $imagen = trim((string) ($_POST["imagen_url"] ?? ""));

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
        $precio = round((float) $precioTexto, 2);

        if ($precio <= 0) {
            $errores[] =
                "El precio debe ser mayor que cero.";
        }

        if ($precio > 99999.99) {
            $errores[] =
                "El precio ingresado es demasiado alto.";
        }
    }

    if (!in_array($estado, $estadosPermitidos, true)) {
        $errores[] =
            "Seleccione un estado válido.";
    }

    if ($imagen !== "") {
        if (!filter_var($imagen, FILTER_VALIDATE_URL)) {
            $errores[] =
                "La dirección de la imagen no es válida.";
        } else {
            $esquemaImagen = strtolower(
                (string) parse_url($imagen, PHP_URL_SCHEME)
            );

            if (!in_array($esquemaImagen, ["http", "https"], true)) {
                $errores[] =
                    "La imagen debe usar una dirección segura.";
            }
        }
    }

    if (empty($errores)) {
        $consultaDuplicada = mysqli_prepare(
            $conn,
            "SELECT id_comida
             FROM comidas
             WHERE LOWER(nombre) = LOWER(?)
               AND tipo = ?
             LIMIT 1"
        );

        if (!$consultaDuplicada) {
            $errores[] =
                "No se pudo comprobar la información.";
        } else {
            mysqli_stmt_bind_param(
                $consultaDuplicada,
                "ss",
                $nombre,
                $tipo
            );

            mysqli_stmt_execute($consultaDuplicada);

            $resultadoDuplicada =
                mysqli_stmt_get_result($consultaDuplicada);

            if (mysqli_num_rows($resultadoDuplicada) > 0) {
                $errores[] =
                    "Ya existe una comida con ese nombre y tipo.";
            }

            mysqli_stmt_close($consultaDuplicada);
        }
    }

    if (empty($errores)) {
        $guardarComida = mysqli_prepare(
            $conn,
            "INSERT INTO comidas
                (
                    nombre,
                    tipo,
                    descripcion,
                    precio,
                    estado,
                    imagen
                )
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        if (!$guardarComida) {
            $errores[] =
                "No se pudo preparar el registro de la comida.";
        } else {
            mysqli_stmt_bind_param(
                $guardarComida,
                "sssdss",
                $nombre,
                $tipo,
                $descripcion,
                $precio,
                $estado,
                $imagen
            );

            if (mysqli_stmt_execute($guardarComida)) {
                mysqli_stmt_close($guardarComida);

                $_SESSION["csrf_comidas"] =
                    bin2hex(random_bytes(32));

                header(
                    "Location: index.php?mensaje=guardado"
                );
                exit();
            }

            mysqli_stmt_close($guardarComida);

            $errores[] =
                "No se pudo guardar la comida.";
        }
    }
}

$porPagina = 10;

$paginaActual = max(
    1,
    (int) ($_GET["pagina"] ?? 1)
);

$totalComidas = 0;

/* Listado de comidas */
$consultaTotalComidas = mysqli_query(
    $conn,
    "SELECT COUNT(*) AS total
     FROM comidas"
);

if ($consultaTotalComidas) {
    $filaTotalComidas =
        mysqli_fetch_assoc($consultaTotalComidas);

    $totalComidas =
        (int) ($filaTotalComidas["total"] ?? 0);
}

$totalPaginas = max(
    1,
    (int) ceil(
        $totalComidas / $porPagina
    )
);

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset =
    ($paginaActual - 1) * $porPagina;

$comidas = mysqli_query(
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
     ORDER BY
        CASE
            WHEN co.estado = 'Disponible' THEN 0
            ELSE 1
        END,
        co.id_comida DESC
     LIMIT $porPagina
     OFFSET $offset"
);

$primerRegistro =
    $totalComidas > 0
        ? $offset + 1
        : 0;

$ultimoRegistro =
    min(
        $offset + $porPagina,
        $totalComidas
    );

$paginaInicio = max(
    1,
    $paginaActual - 2
);

$paginaFin = min(
    $totalPaginas,
    $paginaActual + 2
);

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

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Gestión de comidas - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=53"
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
            min-height: 380px;
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
            padding: 68px 0;
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
            max-width: 670px;
            color: rgba(255, 255, 255, .82);
            line-height: 1.7;
        }

        .contenido-pagina {
            padding: 70px 0;
        }

        .mensaje {
            display: flex;
            gap: 10px;
            margin-bottom: 22px;
            padding: 14px 17px;
            border-radius: 6px;
            font-size: 13px;
        }

        .mensaje-exito {
            border: 1px solid #b8ddc2;
            background: #edf8f0;
            color: #24643a;
        }

        .mensaje-aviso {
            border: 1px solid #ead79f;
            background: #fff8df;
            color: #765a18;
        }

        .mensaje-error {
            border: 1px solid #edc8c8;
            background: #fff1f1;
            color: #9b3131;
        }

        .formulario-card,
        .comida-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 10px;
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
            flex: 0 0 48px;
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
            display: block;
            height: 100%;
            cursor: pointer;
        }

        .estado-opcion input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .estado-opcion-contenido {
            height: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 13px 14px;
            border: 1px solid #dce1dc;
            border-radius: 8px;
            background: #f7f9f7;
            transition: .2s ease;
        }

        .estado-opcion-contenido i {
            font-size: 18px;
        }

        .estado-opcion-contenido strong {
            display: block;
            color: var(--verde-oscuro);
            font-size: 12px;
        }

        .estado-opcion input:checked + .estado-opcion-contenido {
            border-color: var(--verde);
            background: var(--verde-claro);
            box-shadow: 0 0 0 3px rgba(36, 74, 53, .08);
        }

        .btn-guardar {
            min-height: 49px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 5px;
            background: var(--verde);
            color: white;
            font-size: 13px;
            font-weight: 900;
        }

        .btn-guardar:hover {
            background: var(--verde-oscuro);
            color: white;
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

        .comida-card {
            height: 100%;
        }

        .comida-imagen {
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
            min-height: 48px;
            color: var(--texto-suave);
            font-size: 13px;
            line-height: 1.6;
        }

        .comida-precio {
            color: var(--verde);
            font-family: Georgia, serif;
            font-size: 28px;
            font-weight: 700;
        }

        .estado-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
        }

        .estado-disponible {
            background: #dff2e4;
            color: #21643b;
        }

        .estado-no-disponible {
            background: #fff0f0;
            color: #9d3030;
        }

        .historial-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 10px;
            color: var(--texto-suave);
            font-size: 11px;
        }

        .acciones {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .btn-editar,
        .btn-eliminar {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 11px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 900;
        }

        .btn-editar {
            border: 1px solid #d4b25e;
            background: #fff5d9;
            color: #72550d;
        }

        .btn-eliminar {
            border: 1px solid #e0a9a9;
            background: #fff0f0;
            color: #9d3030;
        }

        .vacio {
            padding: 60px 25px;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            text-align: center;
            box-shadow: var(--sombra);
        }

        .paginacion-contenedor {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-top: 30px;
            padding: 18px 20px;
            border: 1px solid #e2e4de;
            border-radius: 8px;
            background: white;
            box-shadow: var(--sombra);
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
            border: 1px solid #dce1dc;
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
                padding: 52px 0;
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

            .estado-opciones {
                grid-template-columns: 1fr;
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

            <h1>Gestión de comidas</h1>

            <p>
                Registra alimentos y bebidas, administra su precio,
                disponibilidad e imagen para que los huéspedes puedan
                realizar pedidos desde su cuenta.
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
                La comida fue guardada correctamente.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "actualizado"
        ) { ?>

            <div class="mensaje mensaje-exito">
                <i class="bi bi-check-circle"></i>
                La comida fue actualizada correctamente.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "eliminado"
        ) { ?>

            <div class="mensaje mensaje-exito">
                <i class="bi bi-check-circle"></i>
                La comida fue eliminada correctamente.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "con_pedidos"
        ) { ?>

            <div class="mensaje mensaje-aviso">
                <i class="bi bi-shield-exclamation"></i>
                No se puede eliminar la comida porque tiene pedidos registrados.
                Cámbiala a “No disponible” para ocultarla sin perder el historial.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "error_eliminar"
        ) { ?>

            <div class="mensaje mensaje-error">
                <i class="bi bi-exclamation-triangle"></i>
                No se pudo eliminar la comida.
            </div>

        <?php } ?>

        <?php if (!empty($errores)) { ?>

            <div class="mensaje mensaje-error">

                <i class="bi bi-exclamation-triangle"></i>

                <div>
                    <strong>No se pudo guardar:</strong>

                    <ul class="mt-2 mb-0">

                        <?php foreach ($errores as $error) { ?>

                            <li><?php echo h($error); ?></li>

                        <?php } ?>

                    </ul>
                </div>

            </div>

        <?php } ?>

        <section class="formulario-card mb-5">

            <div class="formulario-cabecera">

                <div class="formulario-icono">
                    <i class="bi bi-cup-hot"></i>
                </div>

                <div>
                    <h3>Registrar nueva comida</h3>

                    <p>
                        Completa los datos y sube una imagen desde tu equipo.
                    </p>
                </div>

            </div>

            <div class="formulario-cuerpo">

                <form
                    method="POST"
                    id="formComida"
                    autocomplete="off"
                >

                    <input
                        type="hidden"
                        name="csrf"
                        value="<?php echo h($csrf); ?>"
                    >

                    <div class="row g-4">

                        <div class="col-md-6">

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
                                placeholder="Ejemplo: Desayuno continental"
                                required
                            >

                        </div>

                        <div class="col-md-3">

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

                                <option value="">
                                    Seleccione
                                </option>

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

                        <div class="col-md-3">

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
                                placeholder="0.00"
                                required
                            >

                        </div>

                        <div class="col-md-8">

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
                                placeholder="Ejemplo: café, pan, huevos y jugo"
                            ><?php echo h($descripcion); ?></textarea>

                        </div>

                        <div class="col-md-4">

                            <label
                                for="estado"
                                class="form-label"
                            >
                                Estado
                            </label>

                            <div class="estado-opciones">

                                <label class="estado-opcion">
                                    <input
                                        type="radio"
                                        name="estado"
                                        value="Disponible"
                                        <?php echo $estado === "Disponible" ? "checked" : ""; ?>
                                        required
                                    >
                                    <span class="estado-opcion-contenido">
                                        <i class="bi bi-check-circle"></i>
                                        <strong>Disponible</strong>
                                    </span>
                                </label>

                                <label class="estado-opcion">
                                    <input
                                        type="radio"
                                        name="estado"
                                        value="No disponible"
                                        <?php echo $estado === "No disponible" ? "checked" : ""; ?>
                                        required
                                    >
                                    <span class="estado-opcion-contenido">
                                        <i class="bi bi-x-circle"></i>
                                        <strong>No disponible</strong>
                                    </span>
                                </label>

                            </div>

                        </div>

                        <div class="col-md-8">

                            <label
                                for="archivo_imagen"
                                class="form-label"
                            >
                                Imagen
                            </label>

                            <input
                                type="file"
                                id="archivo_imagen"
                                class="form-control"
                                accept=".jpg,.jpeg,.png,.webp"
                            >

                            <div class="text-muted small mt-2">
                                JPG, PNG o WEBP de máximo 5 MB.
                                La imagen se guarda en Firebase.
                            </div>

                            <input
                                type="hidden"
                                name="imagen_url"
                                id="imagen_url"
                                value="<?php echo h($imagen); ?>"
                            >

                            <div
                                id="mensajeSubida"
                                class="small mt-2"
                            ></div>

                        </div>

                        <div class="col-md-4 d-flex align-items-end">

                            <button
                                type="submit"
                                id="btnGuardar"
                                class="btn-guardar w-100"
                            >
                                <i class="bi bi-plus-circle"></i>
                                Guardar comida
                            </button>

                        </div>

                    </div>

                </form>

            </div>

        </section>

        <div id="comidasRegistradas" class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

            <div>
                <div class="pagina-etiqueta text-success">
                    MENÚ DEL HOTEL
                </div>

                <h2 class="mt-2 mb-1">
                    Comidas registradas
                </h2>

                <p class="text-muted mb-0">
                    Las disponibles aparecen primero.
                </p>
            </div>

            <span class="contador">
                <i class="bi bi-cup-hot"></i>
                <?php echo $totalComidas; ?>
                comidas
            </span>

        </div>

        <?php if (
            $comidas &&
            mysqli_num_rows($comidas) > 0
        ) { ?>

            <div class="row g-4">

                <?php while (
                    $comida = mysqli_fetch_assoc($comidas)
                ) { ?>

                    <?php
                    $rutaImagen =
                        imagenSegura($comida["imagen"]);
                    ?>

                    <div class="col-md-6 col-xl-4">

                        <article class="comida-card">

                            <img
                                src="<?php echo h($rutaImagen); ?>"
                                alt="<?php echo h($comida["nombre"]); ?>"
                                class="comida-imagen"
                                loading="lazy"
                                onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                            >

                            <div class="comida-cuerpo">

                                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">

                                    <div>

                                        <div class="comida-tipo">
                                            <?php
                                            echo h(
                                                strtoupper(
                                                    (string) $comida["tipo"]
                                                )
                                            );
                                            ?>
                                        </div>

                                        <h3 class="comida-titulo h4 mt-1 mb-0">
                                            <?php echo h($comida["nombre"]); ?>
                                        </h3>

                                    </div>

                                    <?php if (
                                        $comida["estado"] === "Disponible"
                                    ) { ?>

                                        <span class="estado-badge estado-disponible">
                                            <i class="bi bi-check-circle"></i>
                                            Disponible
                                        </span>

                                    <?php } else { ?>

                                        <span class="estado-badge estado-no-disponible">
                                            <i class="bi bi-x-circle"></i>
                                            No disponible
                                        </span>

                                    <?php } ?>

                                </div>

                                <p class="comida-descripcion">
                                    <?php
                                    echo trim((string) $comida["descripcion"]) !== ""
                                        ? h($comida["descripcion"])
                                        : "Sin descripción.";
                                    ?>
                                </p>

                                <div class="comida-precio">
                                    $<?php
                                    echo number_format(
                                        (float) $comida["precio"],
                                        2
                                    );
                                    ?>
                                </div>

                                <div class="historial-badge">
                                    <i class="bi bi-receipt"></i>
                                    <?php echo (int) $comida["total_pedidos"]; ?>
                                    pedidos relacionados
                                </div>

                                <hr>

                                <div class="acciones">

                                    <a
                                        href="editar.php?id=<?php echo (int) $comida["id_comida"]; ?>"
                                        class="btn-editar"
                                    >
                                        <i class="bi bi-pencil-square"></i>
                                        Editar
                                    </a>

                                    <a
                                        href="eliminar.php?id=<?php echo (int) $comida["id_comida"]; ?>"
                                        class="btn-eliminar"
                                    >
                                        <i class="bi bi-trash"></i>
                                        Eliminar
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

                <h2>
                    No existen comidas registradas
                </h2>

                <p class="text-muted mb-0">
                    Registra el primer alimento o bebida
                    para mostrarlo a los huéspedes.
                </p>

            </div>

        <?php } ?>

        <?php if ($totalComidas > 0) { ?>

            <div class="paginacion-contenedor">

                <div class="paginacion-info">
                    Mostrando
                    <?php echo $primerRegistro; ?>
                    -
                    <?php echo $ultimoRegistro; ?>
                    de
                    <?php echo $totalComidas; ?>
                    comidas
                </div>

                <?php if ($totalPaginas > 1) { ?>

                    <nav
                        class="paginacion-hotel"
                        aria-label="Paginación de comidas"
                    >

                        <?php if ($paginaActual > 1) { ?>

                            <a href="?pagina=<?php echo $paginaActual - 1; ?>#comidasRegistradas">
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

                            <a href="?pagina=1#comidasRegistradas">1</a>

                            <?php if ($paginaInicio > 2) { ?>
                                <span class="pagina-puntos">...</span>
                            <?php } ?>

                        <?php } ?>

                        <?php for (
                            $pagina = $paginaInicio;
                            $pagina <= $paginaFin;
                            $pagina++
                        ) { ?>

                            <?php if ($pagina === $paginaActual) { ?>

                                <span class="pagina-activa">
                                    <?php echo $pagina; ?>
                                </span>

                            <?php } else { ?>

                                <a href="?pagina=<?php echo $pagina; ?>#comidasRegistradas">
                                    <?php echo $pagina; ?>
                                </a>

                            <?php } ?>

                        <?php } ?>

                        <?php if ($paginaFin < $totalPaginas) { ?>

                            <?php if (
                                $paginaFin <
                                $totalPaginas - 1
                            ) { ?>
                                <span class="pagina-puntos">...</span>
                            <?php } ?>

                            <a href="?pagina=<?php echo $totalPaginas; ?>#comidasRegistradas">
                                <?php echo $totalPaginas; ?>
                            </a>

                        <?php } ?>

                        <?php if ($paginaActual < $totalPaginas) { ?>

                            <a href="?pagina=<?php echo $paginaActual + 1; ?>#comidasRegistradas">
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
                Módulo de comidas
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

const formComida =
    document.getElementById("formComida");

const archivoImagen =
    document.getElementById("archivo_imagen");

const imagenUrl =
    document.getElementById("imagen_url");

const mensajeSubida =
    document.getElementById("mensajeSubida");

const btnGuardar =
    document.getElementById("btnGuardar");

archivoImagen.addEventListener(
    "change",
    function () {
        imagenUrl.value = "";
        mensajeSubida.textContent = "";
    }
);

formComida.addEventListener(
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

        btnGuardar.disabled = true;

        btnGuardar.innerHTML =
            '<span class="spinner-border spinner-border-sm"></span> Subiendo imagen';

        mensajeSubida.className =
            "text-primary small mt-2";

        mensajeSubida.textContent =
            "Subiendo imagen a Firebase...";

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
                "Imagen subida correctamente.";

            formComida.submit();
        } catch (error) {
            console.error(error);

            mensajeSubida.className =
                "text-danger small mt-2";

            mensajeSubida.textContent =
                "No se pudo subir la imagen.";

            btnGuardar.disabled = false;

            btnGuardar.innerHTML =
                '<i class="bi bi-plus-circle"></i> Guardar comida';
        }
    }
);

</script>

</body>

</html>