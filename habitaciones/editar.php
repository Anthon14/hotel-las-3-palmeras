<?php
session_start();
include("../config/conexion.php");

if (!isset($_SESSION["usuario"], $_SESSION["rol"])) {
    header("Location: ../login.php");
    exit();
}

$rolActual = strtolower(trim($_SESSION["rol"]));

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

function resolverImagen($imagen)
{
    $imagen = trim((string) $imagen);

    if ($imagen === "") {
        return "../img/hotel.jpg";
    }

    if (filter_var($imagen, FILTER_VALIDATE_URL)) {
        return $imagen;
    }

    $rutas = [
        "../uploads/" . $imagen,
        "../uploads/habitaciones/" . $imagen
    ];

    foreach ($rutas as $ruta) {
        if (is_file(__DIR__ . "/" . $ruta)) {
            return $ruta;
        }
    }

    return "../img/hotel.jpg";
}

$tiposHabitacion = [
    "Individual" => [
        "capacidad" => 1,
        "precio" => 25
    ],
    "Doble" => [
        "capacidad" => 2,
        "precio" => 35
    ],
    "Matrimonial" => [
        "capacidad" => 2,
        "precio" => 38
    ],
    "Triple" => [
        "capacidad" => 3,
        "precio" => 48
    ],
    "Familiar" => [
        "capacidad" => 5,
        "precio" => 65
    ],
    "Suite Junior" => [
        "capacidad" => 2,
        "precio" => 55
    ],
    "Suite Ejecutiva" => [
        "capacidad" => 4,
        "precio" => 75
    ],
    "Suite Presidencial" => [
        "capacidad" => 8,
        "precio" => 110
    ]
];

$estadosPermitidos = [
    "Disponible",
    "Ocupada",
    "Mantenimiento"
];

$errores = [];

if (
    !isset($_GET["id"]) ||
    !filter_var($_GET["id"], FILTER_VALIDATE_INT)
) {
    header("Location: index.php");
    exit();
}

$id = (int) $_GET["id"];

$consulta = mysqli_prepare(
    $conn,
    "SELECT
        id_habitacion,
        numero,
        tipo,
        precio,
        capacidad,
        estado,
        imagen
     FROM habitaciones
     WHERE id_habitacion = ?
     LIMIT 1"
);

mysqli_stmt_bind_param($consulta, "i", $id);
mysqli_stmt_execute($consulta);

$resultado = mysqli_stmt_get_result($consulta);
$datos = mysqli_fetch_assoc($resultado);

mysqli_stmt_close($consulta);

if (!$datos) {
    header("Location: index.php");
    exit();
}

$numero = $datos["numero"];
$tipo = $datos["tipo"];
$precio = $datos["precio"];
$capacidad = $datos["capacidad"];
$estado = $datos["estado"];
$imagenActual = trim((string) ($datos["imagen"] ?? ""));
$imagenParaGuardar = $imagenActual;

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["actualizar"])
) {
    $numero = trim($_POST["numero"] ?? "");
    $tipo = trim($_POST["tipo"] ?? "");
    $precio = trim($_POST["precio"] ?? "");
    $estado = trim($_POST["estado"] ?? "");
    $imagenParaGuardar = trim($_POST["imagen_url"] ?? "");

    if ($imagenParaGuardar === "") {
        $imagenParaGuardar = $imagenActual;
    }

    $capacidad = $tiposHabitacion[$tipo]["capacidad"] ?? "";

    if ($numero === "") {
        $errores[] = "El número de habitación es obligatorio.";
    }

    if (!array_key_exists($tipo, $tiposHabitacion)) {
        $errores[] = "Seleccione un tipo de habitación válido.";
    }

    if (
        $precio === "" ||
        !is_numeric($precio) ||
        (float) $precio <= 0
    ) {
        $errores[] = "Ingrese un precio válido mayor que cero.";
    }

    if (!in_array($estado, $estadosPermitidos, true)) {
        $errores[] = "Seleccione un estado válido.";
    }

    if ($imagenParaGuardar !== $imagenActual) {
        if (!filter_var($imagenParaGuardar, FILTER_VALIDATE_URL)) {
            $errores[] = "El enlace de la nueva imagen no es válido.";
        } else {
            $hostImagen = parse_url(
                $imagenParaGuardar,
                PHP_URL_HOST
            );

            $hostsPermitidos = [
                "firebasestorage.googleapis.com",
                "storage.googleapis.com"
            ];

            if (!in_array($hostImagen, $hostsPermitidos, true)) {
                $errores[] = "La nueva imagen debe estar guardada en Firebase.";
            }
        }
    }

    if ($numero !== "") {
        $consultaNumero = mysqli_prepare(
            $conn,
            "SELECT id_habitacion
             FROM habitaciones
             WHERE numero = ?
             AND id_habitacion != ?
             LIMIT 1"
        );

        mysqli_stmt_bind_param(
            $consultaNumero,
            "si",
            $numero,
            $id
        );

        mysqli_stmt_execute($consultaNumero);

        $resultadoNumero = mysqli_stmt_get_result(
            $consultaNumero
        );

        if (mysqli_num_rows($resultadoNumero) > 0) {
            $errores[] = "Ya existe otra habitación con ese número.";
        }

        mysqli_stmt_close($consultaNumero);
    }

    if (empty($errores)) {
        $precioDecimal = (float) $precio;
        $capacidadEntera = (int) $capacidad;

        $actualizar = mysqli_prepare(
            $conn,
            "UPDATE habitaciones
             SET numero = ?,
                 tipo = ?,
                 precio = ?,
                 capacidad = ?,
                 estado = ?,
                 imagen = ?
             WHERE id_habitacion = ?"
        );

        mysqli_stmt_bind_param(
            $actualizar,
            "ssdissi",
            $numero,
            $tipo,
            $precioDecimal,
            $capacidadEntera,
            $estado,
            $imagenParaGuardar,
            $id
        );

        if (mysqli_stmt_execute($actualizar)) {
            mysqli_stmt_close($actualizar);

            header("Location: index.php?mensaje=actualizado");
            exit();
        }

        mysqli_stmt_close($actualizar);

        $errores[] = "No se pudo actualizar la habitación.";
    }
}

$rutaImagenActual = resolverImagen(
    $imagenParaGuardar !== ""
        ? $imagenParaGuardar
        : $imagenActual
);

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
        Editar habitación - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=41"
    >

    <style>
        :root {
            --verde-principal: #244a35;
            --verde-oscuro: #173325;
            --verde-claro: #e9f0eb;
            --dorado: #d8b56d;
            --crema: #f7f3eb;
            --texto: #20231f;
            --texto-suave: #687068;
            --sombra: 0 18px 45px rgba(21, 45, 32, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 88px;
        }

        body {
            margin: 0;
            overflow-x: hidden;
            background-color: var(--crema);
            color: var(--texto);
            font-family: Arial, Helvetica, sans-serif;
        }

        a {
            text-decoration: none;
        }

        .navbar-hotel {
            min-height: 82px;
            background-color: rgba(18, 39, 28, 0.98);
            border-bottom: 1px solid rgba(255, 255, 255, 0.13);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            backdrop-filter: blur(12px);
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
            font-family: Georgia, "Times New Roman", serif;
            font-size: 18px;
            letter-spacing: 0.3px;
        }

        .marca-texto small {
            color: #dbc58f;
            font-size: 11px;
            letter-spacing: 1.6px;
        }

        .navbar-hotel .nav-link {
            position: relative;
            margin: 0 3px;
            padding: 10px 9px !important;
            color: rgba(255, 255, 255, 0.83);
            font-size: 14px;
            font-weight: 700;
        }

        .navbar-hotel .nav-link:hover,
        .navbar-hotel .nav-link.active {
            color: white;
        }

        .navbar-hotel .nav-link::after {
            content: "";
            position: absolute;
            right: 10px;
            bottom: 3px;
            left: 10px;
            height: 2px;
            background-color: var(--dorado);
            transform: scaleX(0);
            transition: transform 0.2s ease;
        }

        .navbar-hotel .nav-link:hover::after,
        .navbar-hotel .nav-link.active::after {
            transform: scaleX(1);
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
            align-items: center;
            margin-top: 4px;
            color: rgba(255, 255, 255, 0.67);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.7px;
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
            background: var(--verde-principal);
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
            color: var(--verde-principal) !important;
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
            color: var(--verde-principal);
            font-size: 11px;
            font-weight: 900;
        }

        .btn-salir {
            padding: 9px 15px;
            border-radius: 999px;
            font-weight: 700;
        }

        .pagina-hero {
            min-height: 370px;
            display: flex;
            align-items: center;
            margin-top: 82px;
            color: white;
            background:
                linear-gradient(
                    90deg,
                    rgba(10, 29, 20, 0.92),
                    rgba(10, 29, 20, 0.60)
                ),
                url("../img/hotel.jpg");
            background-size: cover;
            background-position: center;
        }

        .pagina-hero-contenido {
            max-width: 750px;
            padding: 65px 0;
        }

        .pagina-etiqueta {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            color: #f0d99f;
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 2.5px;
        }

        .pagina-etiqueta::before {
            content: "";
            width: 40px;
            height: 2px;
            background-color: var(--dorado);
        }

        .pagina-hero h1 {
            margin-bottom: 17px;
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(2.8rem, 6vw, 5rem);
            font-weight: 700;
            line-height: 1;
        }

        .pagina-hero p {
            max-width: 650px;
            margin-bottom: 24px;
            color: rgba(255, 255, 255, 0.82);
            font-size: 16px;
            line-height: 1.7;
        }

        .btn-volver-hero {
            min-height: 46px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 18px;
            border: 1px solid rgba(255, 255, 255, 0.65);
            border-radius: 4px;
            background-color: rgba(255, 255, 255, 0.08);
            color: white;
            font-size: 12px;
            font-weight: 900;
        }

        .btn-volver-hero:hover {
            border-color: white;
            background-color: rgba(255, 255, 255, 0.17);
            color: white;
        }

        .contenido-pagina {
            padding: 75px 0;
        }

        .seccion-etiqueta {
            margin-bottom: 9px;
            color: #9b7739;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 2px;
        }

        .seccion-titulo {
            margin-bottom: 10px;
            color: var(--verde-oscuro);
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(2rem, 4vw, 3rem);
            font-weight: 700;
        }

        .seccion-texto {
            color: var(--texto-suave);
            font-size: 14px;
            line-height: 1.7;
        }

        .mensaje-error {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            margin-bottom: 25px;
            padding: 15px 17px;
            border: 1px solid #edc8c8;
            border-radius: 6px;
            background-color: #fff1f1;
            color: #9b3131;
            font-size: 13px;
        }

        .mensaje-error ul {
            margin-bottom: 0;
        }

        .formulario-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 8px;
            background-color: white;
            box-shadow: var(--sombra);
        }

        .formulario-cabecera {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 24px 27px;
            border-bottom: 1px solid #e6e7e1;
            background-color: #fbfcfa;
        }

        .formulario-icono {
            width: 49px;
            height: 49px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 49px;
            border-radius: 50%;
            background-color: var(--verde-claro);
            color: var(--verde-principal);
            font-size: 21px;
        }

        .formulario-cabecera h3 {
            margin: 0 0 4px;
            color: var(--verde-oscuro);
            font-family: Georgia, "Times New Roman", serif;
            font-size: 22px;
            font-weight: 700;
        }

        .formulario-cabecera p {
            margin: 0;
            color: var(--texto-suave);
            font-size: 12px;
        }

        .formulario-cuerpo {
            padding: 29px;
        }

        .form-label {
            margin-bottom: 7px;
            color: #3c463f;
            font-size: 12px;
            font-weight: 900;
        }

        .form-control,
        .form-select {
            min-height: 49px;
            border: 1px solid #dce1dc;
            border-radius: 6px;
            background-color: #f7f9f7;
            font-size: 13px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--verde-principal);
            background-color: white;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, 0.10);
        }

        .form-control[readonly] {
            background-color: #ecefea;
        }

        .form-text {
            color: var(--texto-suave);
            font-size: 11px;
            line-height: 1.6;
        }

        .imagen-actual {
            width: 100%;
            max-width: 430px;
            height: 275px;
            object-fit: cover;
            border: 6px solid white;
            border-radius: 7px;
            box-shadow: 0 11px 28px rgba(28, 53, 37, 0.15);
        }

        .precio-referencial {
            margin-top: 7px;
            color: var(--texto-suave);
            font-size: 11px;
        }

        .grupo-estado {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .opcion-estado {
            position: relative;
            margin: 0;
        }

        .opcion-estado input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .estado-boton {
            min-height: 84px;
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border: 1px solid #dce1dc;
            border-radius: 12px;
            background: #f8faf8;
            color: #445048;
            cursor: pointer;
            transition: .2s ease;
        }

        .estado-icono {
            width: 42px;
            height: 42px;
            flex: 0 0 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #edf2ee;
            color: #3f4b42;
            font-size: 17px;
        }

        .estado-texto {
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .estado-titulo {
            color: #243128;
            font-size: 13px;
            font-weight: 800;
            line-height: 1.2;
        }

        .estado-descripcion {
            color: var(--texto-suave);
            font-size: 11px;
            line-height: 1.35;
        }

        .estado-check {
            width: 22px;
            height: 22px;
            position: absolute;
            top: 10px;
            right: 10px;
            display: none;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: currentColor;
            color: white;
            font-size: 11px;
        }

        .estado-boton:hover {
            border-color: #c6d0c8;
            background: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(30, 53, 38, 0.08);
        }

        .opcion-estado input[type="radio"]:checked + .estado-boton {
            border-width: 2px;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, 0.08);
        }

        .opcion-estado input[type="radio"]:checked + .estado-boton .estado-check {
            display: inline-flex;
        }

        .opcion-estado.disponible input[type="radio"]:checked + .estado-boton {
            border-color: #2c8a57;
            background: #edf9f1;
            color: #1e6841;
        }

        .opcion-estado.disponible input[type="radio"]:checked + .estado-boton .estado-icono {
            background: #d9f0e1;
            color: #1e6841;
        }

        .opcion-estado.ocupada input[type="radio"]:checked + .estado-boton {
            border-color: #b5811e;
            background: #fff7e8;
            color: #8b6010;
        }

        .opcion-estado.ocupada input[type="radio"]:checked + .estado-boton .estado-icono {
            background: #f9e9c7;
            color: #8b6010;
        }

        .opcion-estado.mantenimiento input[type="radio"]:checked + .estado-boton {
            border-color: #b74f4f;
            background: #fff1f1;
            color: #9a3333;
        }

        .opcion-estado.mantenimiento input[type="radio"]:checked + .estado-boton .estado-icono {
            background: #f8dddd;
            color: #9a3333;
        }

        .botones-formulario {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 8px;
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
            border: 1px solid var(--verde-principal);
            background-color: var(--verde-principal);
            color: white;
        }

        .btn-actualizar:hover {
            border-color: var(--verde-oscuro);
            background-color: var(--verde-oscuro);
            color: white;
        }

        .btn-actualizar:disabled {
            opacity: 0.65;
        }

        .btn-cancelar {
            border: 1px solid #bdc3bd;
            background-color: white;
            color: #555d57;
        }

        .btn-cancelar:hover {
            border-color: #838c85;
            background-color: #f2f3f2;
            color: #363d38;
        }

        .footer-hotel {
            background-color: #13271c;
            color: white;
        }

        .footer-principal {
            padding: 42px 0;
        }

        .footer-logo {
            width: 62px;
            height: 62px;
            object-fit: contain;
        }

        .footer-marca h2 {
            margin-bottom: 6px;
            font-family: Georgia, "Times New Roman", serif;
            font-size: 22px;
        }

        .footer-marca p {
            margin: 0;
            color: rgba(255, 255, 255, 0.62);
            font-size: 12px;
        }

        .footer-final {
            padding: 18px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.10);
            color: rgba(255, 255, 255, 0.52);
            font-size: 12px;
        }

        @media (max-width: 1199px) {
            .navbar-hotel .nav-link {
                padding: 10px 6px !important;
                font-size: 13px;
            }
        }

        @media (max-width: 991px) {
            .navbar-collapse {
                padding: 18px 0 14px;
            }

            .navbar-hotel .nav-link {
                padding: 11px 0 !important;
            }

            .navbar-hotel .nav-link::after {
                right: auto;
                left: 0;
                width: 42px;
            }

            .usuario-navbar {
                margin-top: 12px;
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

            .marca-texto strong {
                font-size: 15px;
            }

            .marca-texto small {
                font-size: 9px;
            }

            .pagina-hero {
                min-height: 340px;
                margin-top: 74px;
                text-align: center;
            }

            .pagina-etiqueta {
                justify-content: center;
            }

            .pagina-hero p {
                margin-right: auto;
                margin-left: auto;
            }

            .contenido-pagina {
                padding: 58px 0;
            }

            .formulario-cuerpo {
                padding: 22px;
            }

            .imagen-actual {
                max-width: 100%;
                height: 235px;
            }

            .grupo-estado {
                grid-template-columns: 1fr;
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

            .pagina-hero h1 {
                font-size: 2.7rem;
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
                <strong>
                    Hotel Las 3 Palmeras
                </strong>

                <small>
                    COMODIDAD Y TRANQUILIDAD
                </small>
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

        <div
            class="collapse navbar-collapse"
            id="menuPrincipal"
        >

            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">

                <li class="nav-item">
                    <a
                        href="../dashboard.php"
                        class="nav-link"
                    >
                        Inicio
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="index.php"
                        class="nav-link active"
                    >
                        Habitaciones
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="../reservas/index.php"
                        class="nav-link"
                    >
                        Reservas
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="../comidas/index.php"
                        class="nav-link"
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
                        aria-expanded="false"
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
                    class="btn btn-outline-light btn-sm btn-salir"
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
                ADMINISTRACIÓN HOTELERA
            </div>

            <h1>
                Editar habitación
            </h1>

            <p>
                Actualiza la información de la habitación
                <?php echo h($numero); ?>, modifica su precio,
                estado o reemplaza la imagen almacenada en Firebase.
            </p>

            <a
                href="index.php"
                class="btn-volver-hero"
            >
                <i class="bi bi-arrow-left"></i>
                Volver a habitaciones
            </a>

        </div>

    </div>

</section>

<main class="contenido-pagina">

    <div class="container">

        <?php if (!empty($errores)) { ?>

            <div class="mensaje-error">

                <i class="bi bi-exclamation-circle"></i>

                <div>
                    <strong>
                        No se pudo actualizar la habitación:
                    </strong>

                    <ul class="mt-2">
                        <?php foreach ($errores as $error) { ?>
                            <li>
                                <?php echo h($error); ?>
                            </li>
                        <?php } ?>
                    </ul>
                </div>

            </div>

        <?php } ?>

        <div class="mb-4">

            <p class="seccion-etiqueta">
                HABITACIÓN <?php echo h($numero); ?>
            </p>

            <h2 class="seccion-titulo">
                Actualizar información
            </h2>

            <p class="seccion-texto">
                El precio actual se conservará. Al seleccionar otro tipo,
                se colocarán automáticamente el nuevo precio referencial y la capacidad.
            </p>

        </div>

        <div class="formulario-card">

            <div class="formulario-cabecera">

                <div class="formulario-icono">
                    <i class="bi bi-pencil-square"></i>
                </div>

                <div>
                    <h3>
                        Datos de la habitación
                    </h3>

                    <p>
                        Modifica solamente los campos que necesites actualizar.
                    </p>
                </div>

            </div>

            <div class="formulario-cuerpo">

                <form
                    method="POST"
                    id="formEditarHabitacion"
                >

                    <input
                        type="hidden"
                        name="actualizar"
                        value="1"
                    >

                    <input
                        type="hidden"
                        name="imagen_url"
                        id="imagen_url"
                        value="<?php echo h($imagenParaGuardar); ?>"
                    >

                    <div class="row g-4">

                        <div class="col-md-6 col-lg-2">

                            <label
                                for="numero"
                                class="form-label"
                            >
                                Número
                            </label>

                            <input
                                type="text"
                                id="numero"
                                name="numero"
                                class="form-control"
                                value="<?php echo h($numero); ?>"
                                maxlength="15"
                                required
                            >

                        </div>

                        <div class="col-md-6 col-lg-3">

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
                                onchange="actualizarDatosTipo(true)"
                                required
                            >

                                <?php foreach (
                                    $tiposHabitacion as
                                    $nombreTipo => $informacionTipo
                                ) { ?>

                                    <option
                                        value="<?php echo h($nombreTipo); ?>"
                                        <?php
                                        echo $tipo === $nombreTipo
                                            ? "selected"
                                            : "";
                                        ?>
                                    >
                                        <?php echo h($nombreTipo); ?>
                                    </option>

                                <?php } ?>

                            </select>

                        </div>

                        <div class="col-md-6 col-lg-2">

                            <label
                                for="precio"
                                class="form-label"
                            >
                                Precio por noche
                            </label>

                            <input
                                type="number"
                                id="precio"
                                name="precio"
                                class="form-control"
                                value="<?php echo h($precio); ?>"
                                step="0.01"
                                min="0.01"
                                required
                            >

                            <div
                                id="precioReferencial"
                                class="precio-referencial"
                            ></div>

                        </div>

                        <div class="col-md-6 col-lg-2">

                            <label
                                for="capacidad"
                                class="form-label"
                            >
                                Capacidad
                            </label>

                            <input
                                type="number"
                                id="capacidad"
                                name="capacidad"
                                class="form-control"
                                value="<?php echo h($capacidad); ?>"
                                readonly
                                required
                            >

                        </div>

                        <div class="col-12">

                            <label class="form-label d-block mb-3">
                                Estado
                            </label>

                            <div class="grupo-estado">

                                <label class="opcion-estado disponible">
                                    <input
                                        type="radio"
                                        name="estado"
                                        value="Disponible"
                                        <?php echo $estado === "Disponible" ? "checked" : ""; ?>
                                        required
                                    >

                                    <span class="estado-boton">
                                        <span class="estado-icono">
                                            <i class="bi bi-check-circle"></i>
                                        </span>
                                        <span class="estado-texto">
                                            <span class="estado-titulo">Disponible</span>
                                            <span class="estado-descripcion">Lista para nuevas reservas.</span>
                                        </span>
                                        <span class="estado-check">
                                            <i class="bi bi-check-lg"></i>
                                        </span>
                                    </span>
                                </label>

                                <label class="opcion-estado ocupada">
                                    <input
                                        type="radio"
                                        name="estado"
                                        value="Ocupada"
                                        <?php echo $estado === "Ocupada" ? "checked" : ""; ?>
                                        required
                                    >

                                    <span class="estado-boton">
                                        <span class="estado-icono">
                                            <i class="bi bi-person-fill"></i>
                                        </span>
                                        <span class="estado-texto">
                                            <span class="estado-titulo">Ocupada</span>
                                            <span class="estado-descripcion">Actualmente asignada a un huésped.</span>
                                        </span>
                                        <span class="estado-check">
                                            <i class="bi bi-check-lg"></i>
                                        </span>
                                    </span>
                                </label>

                                <label class="opcion-estado mantenimiento">
                                    <input
                                        type="radio"
                                        name="estado"
                                        value="Mantenimiento"
                                        <?php echo $estado === "Mantenimiento" ? "checked" : ""; ?>
                                        required
                                    >

                                    <span class="estado-boton">
                                        <span class="estado-icono">
                                            <i class="bi bi-tools"></i>
                                        </span>
                                        <span class="estado-texto">
                                            <span class="estado-titulo">Mantenimiento</span>
                                            <span class="estado-descripcion">No disponible mientras se revisa.</span>
                                        </span>
                                        <span class="estado-check">
                                            <i class="bi bi-check-lg"></i>
                                        </span>
                                    </span>
                                </label>

                            </div>

                        </div>

                        <div class="col-lg-6">

                            <label class="form-label">
                                Imagen actual
                            </label>

                            <div>
                                <img
                                    id="vistaPrevia"
                                    src="<?php echo h($rutaImagenActual); ?>"
                                    alt="Imagen de la habitación <?php echo h($numero); ?>"
                                    class="imagen-actual"
                                    onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                                >
                            </div>

                        </div>

                        <div class="col-lg-6">

                            <label
                                for="imagen"
                                class="form-label"
                            >
                                Reemplazar imagen
                            </label>

                            <input
                                type="file"
                                id="imagen"
                                class="form-control"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                            >

                            <div class="form-text">
                                Deja este campo vacío para conservar la imagen actual.
                                La nueva imagen se guardará en Firebase Storage.
                                Formatos permitidos: JPG, JPEG, PNG y WEBP.
                                Tamaño máximo: 5 MB.
                            </div>

                        </div>

                        <div class="col-12">

                            <div
                                id="mensajeFirebase"
                                class="alert d-none mb-0"
                            ></div>

                        </div>

                        <div class="col-12">

                            <div class="botones-formulario">

                                <button
                                    type="submit"
                                    id="btnActualizar"
                                    class="btn-actualizar"
                                >
                                    <i class="bi bi-check-circle"></i>
                                    Actualizar habitación
                                </button>

                                <a
                                    href="index.php"
                                    class="btn-cancelar"
                                >
                                    <i class="bi bi-arrow-left"></i>
                                    Volver sin guardar
                                </a>

                            </div>

                        </div>

                    </div>

                </form>

            </div>

        </div>

    </div>

</main>

<footer class="footer-hotel">

    <div class="footer-principal">

        <div class="container">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-4">

                <div class="d-flex align-items-center gap-3 footer-marca">

                    <img
                        src="../img/logo.png"
                        alt="Hotel Las 3 Palmeras"
                        class="footer-logo"
                    >

                    <div>
                        <h2>
                            Hotel Las 3 Palmeras
                        </h2>

                        <p>
                            Sistema administrativo hotelero.
                        </p>
                    </div>

                </div>

                <a
                    href="index.php"
                    class="btn btn-outline-light btn-sm"
                >
                    Volver a habitaciones
                </a>

            </div>

        </div>

    </div>

    <div class="footer-final">

        <div class="container d-flex flex-wrap justify-content-between gap-2">

            <span>
                Hotel Las 3 Palmeras © 2026
            </span>

            <span>
                Edición de habitaciones
            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>
    function actualizarDatosTipo(cambiarPrecio = false) {
        const tipo = document.getElementById("tipo").value;
        const capacidad = document.getElementById("capacidad");
        const precio = document.getElementById("precio");
        const precioReferencial = document.getElementById(
            "precioReferencial"
        );

        const habitaciones = {
            "Individual": {
                capacidad: 1,
                precio: 25
            },
            "Doble": {
                capacidad: 2,
                precio: 35
            },
            "Matrimonial": {
                capacidad: 2,
                precio: 38
            },
            "Triple": {
                capacidad: 3,
                precio: 48
            },
            "Familiar": {
                capacidad: 5,
                precio: 65
            },
            "Suite Junior": {
                capacidad: 2,
                precio: 55
            },
            "Suite Ejecutiva": {
                capacidad: 4,
                precio: 75
            },
            "Suite Presidencial": {
                capacidad: 8,
                precio: 110
            }
        };

        if (!habitaciones[tipo]) {
            capacidad.value = "";
            precioReferencial.textContent = "";
            return;
        }

        capacidad.value = habitaciones[tipo].capacidad;

        precioReferencial.textContent =
            "Precio referencial: $" +
            habitaciones[tipo].precio.toFixed(2);

        if (cambiarPrecio) {
            precio.value = habitaciones[tipo].precio.toFixed(2);
        }
    }

    actualizarDatosTipo(false);
</script>

<script type="module">
    import {
        storage,
        ref,
        uploadBytes,
        getDownloadURL
    } from "../js/firebase-config.js";

    const formulario = document.getElementById(
        "formEditarHabitacion"
    );

    const campoImagen = document.getElementById("imagen");
    const campoImagenUrl = document.getElementById("imagen_url");
    const vistaPrevia = document.getElementById("vistaPrevia");
    const btnActualizar = document.getElementById("btnActualizar");
    const mensajeFirebase = document.getElementById("mensajeFirebase");
    const campoNumero = document.getElementById("numero");

    const imagenOriginalMostrada = <?php
    echo json_encode(
        $rutaImagenActual,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );
    ?>;

    let urlVistaPreviaTemporal = null;

    function mostrarMensaje(texto, tipo) {
        mensajeFirebase.textContent = texto;
        mensajeFirebase.className =
            "alert alert-" + tipo + " mb-0";
    }

    function ocultarMensaje() {
        mensajeFirebase.textContent = "";
        mensajeFirebase.className =
            "alert d-none mb-0";
    }

    campoImagen.addEventListener("change", function () {
        ocultarMensaje();

        const archivo = this.files[0];

        if (!archivo) {
            vistaPrevia.src = imagenOriginalMostrada;
            return;
        }

        const tiposPermitidos = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];

        const tamanoMaximo = 5 * 1024 * 1024;

        if (!tiposPermitidos.includes(archivo.type)) {
            alert(
                "Seleccione una imagen JPG, JPEG, PNG o WEBP."
            );

            campoImagen.value = "";
            vistaPrevia.src = imagenOriginalMostrada;

            return;
        }

        if (archivo.size > tamanoMaximo) {
            alert(
                "La imagen no puede superar los 5 MB."
            );

            campoImagen.value = "";
            vistaPrevia.src = imagenOriginalMostrada;

            return;
        }

        if (urlVistaPreviaTemporal !== null) {
            URL.revokeObjectURL(
                urlVistaPreviaTemporal
            );
        }

        urlVistaPreviaTemporal =
            URL.createObjectURL(archivo);

        vistaPrevia.src =
            urlVistaPreviaTemporal;
    });

    formulario.addEventListener(
        "submit",
        async function (evento) {
            evento.preventDefault();

            const archivo =
                campoImagen.files[0];

            if (!archivo) {
                formulario.submit();
                return;
            }

            const tiposPermitidos = [
                "image/jpeg",
                "image/png",
                "image/webp"
            ];

            const tamanoMaximo =
                5 * 1024 * 1024;

            if (!tiposPermitidos.includes(archivo.type)) {
                mostrarMensaje(
                    "La imagen debe ser JPG, JPEG, PNG o WEBP.",
                    "danger"
                );

                return;
            }

            if (archivo.size > tamanoMaximo) {
                mostrarMensaje(
                    "La imagen no puede superar los 5 MB.",
                    "danger"
                );

                return;
            }

            try {
                btnActualizar.disabled = true;

                btnActualizar.innerHTML =
                    '<span class="spinner-border spinner-border-sm"></span> Subiendo imagen...';

                mostrarMensaje(
                    "Subiendo la nueva imagen a Firebase...",
                    "info"
                );

                const nombreSeguro = archivo.name
                    .normalize("NFD")
                    .replace(/[\u0300-\u036f]/g, "")
                    .replaceAll(" ", "_")
                    .replace(/[^a-zA-Z0-9._-]/g, "");

                const numeroHabitacion = campoNumero.value
                    .trim()
                    .replace(/[^a-zA-Z0-9_-]/g, "");

                const nombreFinal =
                    "habitacion_" +
                    numeroHabitacion +
                    "_editada_" +
                    Date.now() +
                    "_" +
                    nombreSeguro;

                const referenciaImagen = ref(
                    storage,
                    "habitaciones/" + nombreFinal
                );

                const resultado = await uploadBytes(
                    referenciaImagen,
                    archivo,
                    {
                        contentType: archivo.type
                    }
                );

                campoImagenUrl.value =
                    await getDownloadURL(
                        resultado.ref
                    );

                mostrarMensaje(
                    "Imagen subida correctamente. Actualizando habitación...",
                    "success"
                );

                formulario.submit();

            } catch (error) {
                console.error(error);

                mostrarMensaje(
                    "No se pudo subir la imagen a Firebase: " +
                    error.message,
                    "danger"
                );

                btnActualizar.disabled = false;

                btnActualizar.innerHTML =
                    '<i class="bi bi-check-circle"></i> Actualizar habitación';
            }
        }
    );
</script>

</body>
</html>