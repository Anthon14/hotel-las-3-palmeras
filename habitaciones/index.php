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

function formatearFechaNotificacion($fecha)
{
    $fecha = trim((string) $fecha);

    if ($fecha === "") {
        return "-";
    }

    $tiempo = strtotime($fecha);

    if ($tiempo === false) {
        return $fecha;
    }

    return date("d/m/Y h:i A", $tiempo);
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

function urlHabitaciones(
    string $estado = "Todos",
    int $pagina = 1
): string {
    return "index.php?" . http_build_query([
        "estado" => $estado,
        "pagina" => max(1, $pagina)
    ]) . "#habitacionesRegistradas";
}

$tiposPermitidos = [
    "Individual" => 1,
    "Doble" => 2,
    "Matrimonial" => 2,
    "Triple" => 3,
    "Familiar" => 5,
    "Suite Junior" => 2,
    "Suite Ejecutiva" => 4,
    "Suite Presidencial" => 8
];

$preciosReferenciales = [
    "Individual" => 25,
    "Doble" => 35,
    "Matrimonial" => 38,
    "Triple" => 48,
    "Familiar" => 65,
    "Suite Junior" => 55,
    "Suite Ejecutiva" => 75,
    "Suite Presidencial" => 110
];

$estadosPermitidos = [
    "Disponible",
    "Ocupada",
    "Mantenimiento"
];

$errores = [];
$numero = "";
$tipo = "";
$precio = "";
$capacidad = "";
$estado = "Disponible";
$imagenUrl = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["guardar"])) {
    $numero = trim($_POST["numero"] ?? "");
    $tipo = trim($_POST["tipo"] ?? "");
    $precio = trim($_POST["precio"] ?? "");
    $estado = trim($_POST["estado"] ?? "");
    $imagenUrl = trim($_POST["imagen_url"] ?? "");

    $capacidad = $tiposPermitidos[$tipo] ?? "";

    if (
        $precio === "" &&
        isset($preciosReferenciales[$tipo])
    ) {
        $precio = (string) $preciosReferenciales[$tipo];
    }

    if ($numero === "") {
        $errores[] = "El número de habitación es obligatorio.";
    }

    if (!array_key_exists($tipo, $tiposPermitidos)) {
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

    if ($imagenUrl !== "") {
        if (!filter_var($imagenUrl, FILTER_VALIDATE_URL)) {
            $errores[] = "El enlace de la imagen no es válido.";
        } else {
            $hostImagen = parse_url($imagenUrl, PHP_URL_HOST);

            $hostsPermitidos = [
                "firebasestorage.googleapis.com",
                "storage.googleapis.com"
            ];

            if (!in_array($hostImagen, $hostsPermitidos, true)) {
                $errores[] = "La imagen debe estar almacenada en Firebase.";
            }
        }
    }

    if ($numero !== "") {
        $consultaNumero = mysqli_prepare(
            $conn,
            "SELECT id_habitacion
             FROM habitaciones
             WHERE numero = ?
             LIMIT 1"
        );

        if ($consultaNumero) {
            mysqli_stmt_bind_param($consultaNumero, "s", $numero);
            mysqli_stmt_execute($consultaNumero);

            $resultadoNumero = mysqli_stmt_get_result($consultaNumero);

            if (mysqli_num_rows($resultadoNumero) > 0) {
                $errores[] = "Ya existe una habitación con ese número.";
            }

            mysqli_stmt_close($consultaNumero);
        }
    }

    if (empty($errores)) {
        $precioDecimal = (float) $precio;
        $capacidadEntera = (int) $capacidad;
        $imagenParaGuardar = $imagenUrl !== "" ? $imagenUrl : null;

        $guardarHabitacion = mysqli_prepare(
            $conn,
            "INSERT INTO habitaciones
                (numero, tipo, precio, capacidad, estado, imagen)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        if ($guardarHabitacion) {
            mysqli_stmt_bind_param(
                $guardarHabitacion,
                "ssdiss",
                $numero,
                $tipo,
                $precioDecimal,
                $capacidadEntera,
                $estado,
                $imagenParaGuardar
            );

            if (mysqli_stmt_execute($guardarHabitacion)) {
                mysqli_stmt_close($guardarHabitacion);

                header("Location: index.php?mensaje=guardado");
                exit();
            }

            mysqli_stmt_close($guardarHabitacion);
        }

        $errores[] = "No se pudo registrar la habitación.";
    }
}

$estadosFiltroPermitidos = [
    "Todos",
    "Disponible",
    "Ocupada",
    "Mantenimiento"
];

$estadoFiltro =
    trim((string) ($_GET["estado"] ?? "Todos"));

if (
    !in_array(
        $estadoFiltro,
        $estadosFiltroPermitidos,
        true
    )
) {
    $estadoFiltro = "Todos";
}

$conteosEstado = [
    "Todos" => 0,
    "Disponible" => 0,
    "Ocupada" => 0,
    "Mantenimiento" => 0
];

$consultaConteos = mysqli_query(
    $conn,
    "SELECT estado, COUNT(*) AS total
     FROM habitaciones
     GROUP BY estado"
);

if ($consultaConteos) {
    while (
        $filaConteo =
            mysqli_fetch_assoc($consultaConteos)
    ) {
        $estadoConteo =
            (string) $filaConteo["estado"];

        $cantidadConteo =
            (int) $filaConteo["total"];

        if (
            array_key_exists(
                $estadoConteo,
                $conteosEstado
            )
        ) {
            $conteosEstado[$estadoConteo] =
                $cantidadConteo;
        }

        $conteosEstado["Todos"] +=
            $cantidadConteo;
    }
}

$totalHabitacionesGeneral =
    (int) $conteosEstado["Todos"];

$totalHabitaciones =
    (int) ($conteosEstado[$estadoFiltro] ?? 0);

$porPagina = 10;

$paginaActual = max(
    1,
    (int) ($_GET["pagina"] ?? 1)
);

$totalPaginas = max(
    1,
    (int) ceil(
        $totalHabitaciones / $porPagina
    )
);

if ($paginaActual > $totalPaginas) {
    $paginaActual = $totalPaginas;
}

$offset =
    ($paginaActual - 1) * $porPagina;

$habitaciones = false;

if ($estadoFiltro === "Todos") {
    $consultaHabitaciones = mysqli_prepare(
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
         ORDER BY id_habitacion DESC
         LIMIT ? OFFSET ?"
    );

    if ($consultaHabitaciones) {
        mysqli_stmt_bind_param(
            $consultaHabitaciones,
            "ii",
            $porPagina,
            $offset
        );

        if (mysqli_stmt_execute($consultaHabitaciones)) {
            $habitaciones =
                mysqli_stmt_get_result(
                    $consultaHabitaciones
                );
        }

        mysqli_stmt_close($consultaHabitaciones);
    }
} else {
    $consultaHabitaciones = mysqli_prepare(
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
         WHERE estado = ?
         ORDER BY id_habitacion DESC
         LIMIT ? OFFSET ?"
    );

    if ($consultaHabitaciones) {
        mysqli_stmt_bind_param(
            $consultaHabitaciones,
            "sii",
            $estadoFiltro,
            $porPagina,
            $offset
        );

        if (mysqli_stmt_execute($consultaHabitaciones)) {
            $habitaciones =
                mysqli_stmt_get_result(
                    $consultaHabitaciones
                );
        }

        mysqli_stmt_close($consultaHabitaciones);
    }
}

$primerRegistro =
    $totalHabitaciones > 0
        ? $offset + 1
        : 0;

$ultimoRegistro =
    min(
        $offset + $porPagina,
        $totalHabitaciones
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
    $consultaCantidadPagos = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM pagos
         WHERE estado_pago = 'Pendiente'"
    );

    if ($consultaCantidadPagos) {
        $filaCantidadPagos =
            mysqli_fetch_assoc(
                $consultaCantidadPagos
            );

        $pagosPendientes =
            (int) (
                $filaCantidadPagos["total"] ?? 0
            );
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
        Habitaciones - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=40"
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
            --blanco: #ffffff;
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

        .btn-salir {
            padding: 9px 15px;
            border-radius: 999px;
            font-weight: 700;
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
            border: 1px solid rgba(255, 255, 255, 0.28);
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.08);
            color: white;
            font-size: 17px;
            transition:
                background-color 0.2s ease,
                border-color 0.2s ease,
                transform 0.2s ease;
        }

        .btn-notificaciones-admin:hover,
        .btn-notificaciones-admin:focus {
            border-color: rgba(240, 217, 159, 0.75);
            background-color: rgba(255, 255, 255, 0.15);
            color: white;
            transform: translateY(-1px);
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
            background-color: #cf3f3f;
            color: white;
            font-size: 9px;
            font-weight: 900;
            line-height: 1;
        }

        .menu-notificaciones-admin {
            width: min(390px, calc(100vw - 30px));
            overflow: hidden;
            margin-top: 12px !important;
            padding: 0;
            border: 1px solid #dde2dd;
            border-radius: 12px;
            background-color: white;
            box-shadow:
                0 18px 46px
                rgba(14, 35, 23, 0.20);
        }

        .notificaciones-admin-cabecera {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 17px 18px;
            border-bottom: 1px solid #e8ebe7;
            background-color: #fbfcfa;
        }

        .notificaciones-admin-cabecera strong {
            display: block;
            color: var(--verde-oscuro);
            font-family: Georgia, "Times New Roman", serif;
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
            background-color: var(--verde-principal);
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
            color: var(--texto);
            transition: background-color 0.2s ease;
        }

        .notificacion-pago-admin:hover {
            background-color: #f4f8f5;
            color: var(--texto);
        }

        .notificacion-pago-admin:last-child {
            border-bottom: 0;
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
            background-color: #fff0c7;
            color: #81600d;
            font-size: 16px;
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
            color: var(--verde-principal) !important;
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
            color: var(--verde-principal);
            font-size: 26px;
        }

        .notificaciones-admin-pie {
            padding: 13px 18px;
            border-top: 1px solid #e8ebe7;
            background-color: #fbfcfa;
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

        .notificaciones-admin-pie a:hover {
            color: var(--verde-oscuro);
        }

        .pagina-hero {
            min-height: 410px;
            display: flex;
            align-items: center;
            margin-top: 82px;
            position: relative;
            color: white;
            background:
                linear-gradient(
                    90deg,
                    rgba(10, 29, 20, 0.91),
                    rgba(10, 29, 20, 0.63)
                ),
                url("../img/hotel.jpg");
            background-size: cover;
            background-position: center;
        }

        .pagina-hero-contenido {
            max-width: 750px;
            padding: 70px 0;
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
            font-size: clamp(2.8rem, 6vw, 5.3rem);
            font-weight: 700;
            line-height: 1;
        }

        .pagina-hero p {
            max-width: 650px;
            margin-bottom: 25px;
            color: rgba(255, 255, 255, 0.80);
            font-size: 16px;
            line-height: 1.7;
        }

        .hero-acciones {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .btn-hero {
            min-height: 47px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 18px;
            border: 1px solid var(--dorado);
            border-radius: 4px;
            background-color: var(--dorado);
            color: #203026;
            font-size: 12px;
            font-weight: 900;
        }

        .btn-hero:hover {
            background-color: #e7c882;
            color: #203026;
        }

        .btn-hero-secundario {
            border-color: rgba(255, 255, 255, 0.65);
            background-color: rgba(255, 255, 255, 0.08);
            color: white;
        }

        .btn-hero-secundario:hover {
            border-color: white;
            background-color: rgba(255, 255, 255, 0.17);
            color: white;
        }

        .contenido-pagina {
            padding: 80px 0;
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

        .contador {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 13px;
            border-radius: 999px;
            background-color: var(--verde-claro);
            color: var(--verde-principal);
            font-size: 12px;
            font-weight: 900;
        }

        .filtros-habitaciones {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 24px;
        }

        .filtro-habitacion {
            min-height: 74px;
            position: relative;
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 13px 15px;
            border: 1px solid #dfe4de;
            border-radius: 10px;
            background: white;
            color: #3f4942;
            transition: .2s ease;
        }

        .filtro-habitacion:hover {
            border-color: #bcc8be;
            color: #26352b;
            transform: translateY(-1px);
            box-shadow: 0 8px 22px rgba(28, 53, 37, .08);
        }

        .filtro-habitacion.activo {
            border: 2px solid var(--verde-principal);
            background: #f2f8f4;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, .08);
        }

        .filtro-habitacion-icono {
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: #eef2ee;
            color: var(--verde-principal);
            font-size: 16px;
        }

        .filtro-habitacion-texto {
            min-width: 0;
            flex: 1;
        }

        .filtro-habitacion-texto strong {
            display: block;
            color: var(--verde-oscuro);
            font-size: 12px;
        }

        .filtro-habitacion-texto small {
            display: block;
            margin-top: 2px;
            color: var(--texto-suave);
            font-size: 9px;
        }

        .filtro-habitacion-cantidad {
            min-width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 7px;
            border-radius: 999px;
            background: var(--verde-claro);
            color: var(--verde-principal);
            font-size: 11px;
            font-weight: 900;
        }

        .filtro-habitacion.disponible.activo {
            border-color: #2c8a57;
            background: #edf9f1;
        }

        .filtro-habitacion.ocupada.activo {
            border-color: #b74f4f;
            background: #fff2f2;
        }

        .filtro-habitacion.mantenimiento.activo {
            border-color: #b5811e;
            background: #fff8e8;
        }

        .mensaje {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 25px;
            padding: 14px 17px;
            border-radius: 6px;
            font-size: 13px;
        }

        .mensaje-exito {
            border: 1px solid #b8ddc2;
            background-color: #edf8f0;
            color: #24643a;
        }

        .mensaje-error {
            border: 1px solid #edc8c8;
            background-color: #fff1f1;
            color: #9b3131;
        }

        .mensaje ul {
            margin-bottom: 0;
        }

        .formulario-card {
            margin-bottom: 75px;
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
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 48px;
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
            padding: 28px;
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

        .vista-previa {
            width: 100%;
            max-width: 360px;
            height: 215px;
            object-fit: cover;
            border: 6px solid white;
            border-radius: 7px;
            box-shadow: 0 10px 26px rgba(28, 53, 37, 0.14);
        }

        .btn-guardar {
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 22px;
            border: none;
            border-radius: 4px;
            background-color: var(--verde-principal);
            color: white;
            font-size: 13px;
            font-weight: 900;
            transition: 0.2s ease;
        }

        .btn-guardar:hover {
            background-color: var(--verde-oscuro);
            color: white;
            transform: translateY(-2px);
        }

        .btn-guardar:disabled {
            opacity: 0.65;
            transform: none;
        }

        .tabla-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 8px;
            background-color: white;
            box-shadow: var(--sombra);
        }

        .tabla-card .table-responsive {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .tabla-card .tabla-hotel {
            min-width: 900px;
        }

        .tabla-hotel {
            margin: 0;
        }

        .tabla-hotel thead th {
            padding: 15px 16px;
            border: none;
            background-color: var(--verde-oscuro);
            color: white;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .tabla-hotel tbody td {
            padding: 14px 16px;
            border-color: #ecece6;
            color: #3c443e;
            font-size: 13px;
            vertical-align: middle;
        }

        .tabla-hotel tbody tr:hover {
            background-color: #f7faf7;
        }

        .imagen-tabla {
            width: 105px;
            height: 72px;
            object-fit: cover;
            border-radius: 5px;
        }

        .estado {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 900;
            white-space: nowrap;
        }

        .estado-disponible {
            background-color: #dff2e4;
            color: #21643b;
        }

        .estado-ocupada {
            background-color: #f7dede;
            color: #9d3030;
        }

        .estado-mantenimiento {
            background-color: #fff0c7;
            color: #81600d;
        }

        .acciones-tabla {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
        }

        .btn-editar,
        .btn-eliminar {
            min-height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 11px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 900;
        }

        .btn-editar {
            border: 1px solid #d4b25e;
            background-color: #fff5d9;
            color: #72550d;
        }

        .btn-editar:hover {
            background-color: #f8e6b2;
            color: #72550d;
        }

        .btn-eliminar {
            border: 1px solid #e0a9a9;
            background-color: #fff0f0;
            color: #9d3030;
        }

        .btn-eliminar:hover {
            background-color: #f8dcdc;
            color: #9d3030;
        }

        .tabla-vacia {
            padding: 35px !important;
            color: var(--texto-suave) !important;
            text-align: center;
        }

        .paginacion-contenedor {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-top: 24px;
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
            color: var(--verde-principal);
            font-size: 12px;
            font-weight: 800;
        }

        .paginacion-hotel a:hover {
            border-color: var(--verde-principal);
            background: var(--verde-claro);
            color: var(--verde-oscuro);
        }

        .paginacion-hotel .pagina-activa {
            border-color: var(--verde-principal);
            background: var(--verde-principal);
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
            .filtros-habitaciones {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

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

            .notificaciones-admin {
                margin-top: 10px;
            }

            .menu-notificaciones-admin {
                width:
                    min(
                        390px,
                        calc(100vw - 24px)
                    );
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
                min-height: 380px;
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

            .hero-acciones {
                justify-content: center;
            }

            .btn-hero,
            .btn-hero-secundario {
                width: 100%;
                max-width: 330px;
            }

            .contenido-pagina {
                padding: 60px 0;
            }

            .formulario-cuerpo {
                padding: 22px;
            }

            .formulario-cabecera {
                align-items: flex-start;
                padding: 20px;
            }

            .formulario-cabecera h3 {
                font-size: 19px;
            }

            .vista-previa {
                max-width: 100%;
                height: 200px;
            }

            .btn-guardar {
                width: 100%;
            }

            .acciones-tabla {
                min-width: 175px;
            }

            .tabla-card {
                border-radius: 7px;
            }

            .tabla-hotel thead th,
            .tabla-hotel tbody td {
                padding: 12px 13px;
            }

            .imagen-tabla {
                width: 90px;
                height: 64px;
            }

            .contador {
                margin-top: 4px;
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
            .filtros-habitaciones {
                grid-template-columns: 1fr;
            }

            .marca-texto {
                display: none;
            }

            .pagina-hero {
                min-height: 345px;
            }

            .pagina-hero-contenido {
                padding: 55px 0;
            }

            .pagina-hero h1 {
                font-size: 2.45rem;
            }

            .pagina-hero p {
                font-size: 14px;
            }

            .contenido-pagina {
                padding: 48px 0;
            }

            .formulario-card {
                margin-bottom: 55px;
            }

            .formulario-cabecera {
                gap: 11px;
                padding: 18px;
            }

            .formulario-icono {
                width: 42px;
                height: 42px;
                flex-basis: 42px;
                font-size: 18px;
            }

            .formulario-cuerpo {
                padding: 18px;
            }

            .vista-previa {
                height: 180px;
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

                        <div
                            class="dropdown-menu dropdown-menu-end menu-notificaciones-admin"
                        >

                            <div class="notificaciones-admin-cabecera">

                                <div>
                                    <strong>
                                        Pagos por revisar
                                    </strong>

                                    <small>
                                        Transferencias pendientes de aprobación
                                    </small>
                                </div>

                                <span class="notificaciones-admin-total">
                                    <?php echo $pagosPendientes; ?>
                                </span>

                            </div>

                            <div class="notificaciones-admin-lista">

                                <?php if (
                                    $notificacionesPagos &&
                                    mysqli_num_rows(
                                        $notificacionesPagos
                                    ) > 0
                                ) { ?>

                                    <?php while (
                                        $notificacionPago =
                                            mysqli_fetch_assoc(
                                                $notificacionesPagos
                                            )
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

                                                    <strong>
                                                        Nuevo pago pendiente
                                                    </strong>

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
                                                            $notificacionPago[
                                                                "numero_habitacion"
                                                            ]
                                                        );
                                                        ?>
                                                        ·
                                                        <?php
                                                        echo h(
                                                            $notificacionPago[
                                                                "metodo_pago"
                                                            ]
                                                        );
                                                        ?>
                                                    </span>

                                                    <span class="notificacion-pago-monto">
                                                        $<?php
                                                        echo number_format(
                                                            (float)
                                                            $notificacionPago["monto"],
                                                            2
                                                        );
                                                        ?>
                                                        ·
                                                        <?php
                                                        echo h(
                                                            formatearFechaNotificacion(
                                                                $notificacionPago[
                                                                    "fecha_pago"
                                                                ]
                                                            )
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

                                        No hay pagos pendientes
                                        por revisar.

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
                Gestión de habitaciones
            </h1>

            <p>
                Registra nuevas habitaciones, controla su disponibilidad,
                actualiza la información y administra las imágenes almacenadas
                en Firebase.
            </p>

            <div class="hero-acciones">

                <a
                    href="#registrarHabitacion"
                    class="btn-hero"
                >
                    <i class="bi bi-plus-circle"></i>
                    Registrar habitación
                </a>

                <a
                    href="../dashboard.php"
                    class="btn-hero btn-hero-secundario"
                >
                    <i class="bi bi-arrow-left"></i>
                    Volver al panel
                </a>

            </div>

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
                Habitación guardada correctamente.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "actualizado"
        ) { ?>

            <div class="mensaje mensaje-exito">
                <i class="bi bi-check-circle"></i>
                Habitación actualizada correctamente.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "eliminado"
        ) { ?>

            <div class="mensaje mensaje-exito">
                <i class="bi bi-check-circle"></i>
                Habitación eliminada correctamente.
            </div>

        <?php } ?>

        <?php if (!empty($errores)) { ?>

            <div class="mensaje mensaje-error">

                <i class="bi bi-exclamation-circle"></i>

                <div>
                    <strong>
                        No se pudo registrar la habitación:
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

        <section id="registrarHabitacion">

            <div class="mb-4">

                <p class="seccion-etiqueta">
                    NUEVO REGISTRO
                </p>

                <h2 class="seccion-titulo">
                    Registrar habitación
                </h2>

                <p class="seccion-texto">
                    Completa la información y selecciona una imagen para subirla a Firebase.
                </p>

            </div>

            <div class="formulario-card">

                <div class="formulario-cabecera">

                    <div class="formulario-icono">
                        <i class="bi bi-door-open"></i>
                    </div>

                    <div>
                        <h3>
                            Datos de la habitación
                        </h3>

                        <p>
                            La capacidad y el precio se asignarán automáticamente según el tipo seleccionado.
                        </p>
                    </div>

                </div>

                <div class="formulario-cuerpo">

                    <form
                        method="POST"
                        id="formHabitacion"
                    >

                        <input
                            type="hidden"
                            name="guardar"
                            value="1"
                        >

                        <input
                            type="hidden"
                            name="imagen_url"
                            id="imagen_url"
                            value="<?php echo h($imagenUrl); ?>"
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
                                    placeholder="Ej. 101"
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
                                    required
                                >

                                    <option value="">
                                        Seleccione
                                    </option>

                                    <?php foreach (
                                        $tiposPermitidos as
                                        $nombreTipo => $capacidadTipo
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
                                    min="0.01"
                                    step="0.01"
                                    value="<?php echo h($precio); ?>"
                                    placeholder="0.00"
                                    required
                                >

                                <div class="form-text">
                                    Se coloca automáticamente según el tipo, pero puedes modificarlo.
                                </div>

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

                            <div class="col-md-6 col-lg-3">

                                <label
                                    for="estado"
                                    class="form-label"
                                >
                                    Estado
                                </label>

                                <select
                                    id="estado"
                                    name="estado"
                                    class="form-select"
                                    required
                                >

                                    <?php foreach (
                                        $estadosPermitidos
                                        as $estadoPermitido
                                    ) { ?>

                                        <option
                                            value="<?php echo h($estadoPermitido); ?>"
                                            <?php
                                            echo $estado === $estadoPermitido
                                                ? "selected"
                                                : "";
                                            ?>
                                        >
                                            <?php echo h($estadoPermitido); ?>
                                        </option>

                                    <?php } ?>

                                </select>

                            </div>

                            <div class="col-lg-6">

                                <label
                                    for="imagen"
                                    class="form-label"
                                >
                                    Imagen de la habitación
                                </label>

                                <input
                                    type="file"
                                    id="imagen"
                                    class="form-control"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                >

                                <div class="form-text">
                                    Formatos permitidos: JPG, JPEG, PNG y WEBP.
                                    Tamaño máximo: 5 MB. La imagen se guardará en Firebase Storage.
                                </div>

                            </div>

                            <div class="col-lg-6">

                                <label class="form-label">
                                    Vista previa
                                </label>

                                <div>
                                    <img
                                        id="vistaPrevia"
                                        src="<?php
                                        echo $imagenUrl !== ""
                                            ? h($imagenUrl)
                                            : "../img/hotel.jpg";
                                        ?>"
                                        alt="Vista previa de la habitación"
                                        class="vista-previa"
                                        onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                                    >
                                </div>

                            </div>

                            <div class="col-12">

                                <div
                                    id="mensajeFirebase"
                                    class="alert d-none mb-0"
                                ></div>

                            </div>

                            <div class="col-12">

                                <button
                                    type="submit"
                                    id="btnGuardar"
                                    class="btn-guardar"
                                >
                                    <i class="bi bi-cloud-arrow-up"></i>
                                    Guardar habitación
                                </button>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

        </section>

        <section id="habitacionesRegistradas">

            <div class="row align-items-end mb-4 g-3">

                <div class="col-lg-8">

                    <p class="seccion-etiqueta">
                        REGISTROS
                    </p>

                    <h2 class="seccion-titulo">
                        Habitaciones registradas
                    </h2>

                    <p class="seccion-texto mb-0">
                        Filtra las habitaciones según su estado actual.
                    </p>

                </div>

                <div class="col-lg-4 text-lg-end">

                    <span class="contador">
                        <i class="bi bi-building"></i>

                        <?php echo $totalHabitacionesGeneral; ?>

                        habitaciones
                    </span>

                </div>

            </div>

            <?php
            $filtrosHabitaciones = [
                "Todos" => [
                    "texto" => "Todas",
                    "detalle" => "Todas las habitaciones",
                    "icono" => "bi-building",
                    "clase" => ""
                ],
                "Disponible" => [
                    "texto" => "Disponibles",
                    "detalle" => "Listas para reservar",
                    "icono" => "bi-check-circle",
                    "clase" => "disponible"
                ],
                "Ocupada" => [
                    "texto" => "Ocupadas",
                    "detalle" => "Actualmente ocupadas",
                    "icono" => "bi-person-fill",
                    "clase" => "ocupada"
                ],
                "Mantenimiento" => [
                    "texto" => "Mantenimiento",
                    "detalle" => "Fuera de servicio",
                    "icono" => "bi-tools",
                    "clase" => "mantenimiento"
                ]
            ];
            ?>

            <div class="filtros-habitaciones">

                <?php foreach (
                    $filtrosHabitaciones as
                    $valorFiltro => $informacionFiltro
                ) { ?>

                    <a
                        href="<?php echo h(urlHabitaciones($valorFiltro)); ?>"
                        class="filtro-habitacion <?php
                        echo h($informacionFiltro["clase"]);
                        echo $estadoFiltro === $valorFiltro
                            ? " activo"
                            : "";
                        ?>"
                    >
                        <span class="filtro-habitacion-icono">
                            <i class="bi <?php echo h($informacionFiltro["icono"]); ?>"></i>
                        </span>

                        <span class="filtro-habitacion-texto">
                            <strong>
                                <?php echo h($informacionFiltro["texto"]); ?>
                            </strong>

                            <small>
                                <?php echo h($informacionFiltro["detalle"]); ?>
                            </small>
                        </span>

                        <span class="filtro-habitacion-cantidad">
                            <?php echo (int) $conteosEstado[$valorFiltro]; ?>
                        </span>
                    </a>

                <?php } ?>

            </div>

            <div class="tabla-card">

                <div class="table-responsive">

                    <table class="table tabla-hotel align-middle">

                        <thead>

                            <tr>
                                <th>Imagen</th>
                                <th>Número</th>
                                <th>Tipo</th>
                                <th>Precio</th>
                                <th>Capacidad</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>

                        </thead>

                        <tbody>

                        <?php if (
                            $habitaciones &&
                            mysqli_num_rows($habitaciones) > 0
                        ) { ?>

                            <?php while (
                                $row = mysqli_fetch_assoc($habitaciones)
                            ) { ?>

                                <?php
                                $rutaImagen = resolverImagen(
                                    $row["imagen"] ?? ""
                                );
                                ?>

                                <tr>

                                    <td>
                                        <img
                                            src="<?php echo h($rutaImagen); ?>"
                                            alt="Habitación <?php echo h($row["numero"]); ?>"
                                            class="imagen-tabla"
                                            loading="lazy"
                                            onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                                        >
                                    </td>

                                    <td>
                                        <strong>
                                            <?php echo h($row["numero"]); ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?php echo h($row["tipo"]); ?>
                                    </td>

                                    <td>
                                        <strong>
                                            $<?php
                                            echo number_format(
                                                (float) $row["precio"],
                                                2
                                            );
                                            ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?php echo (int) $row["capacidad"]; ?>
                                        persona(s)
                                    </td>

                                    <td>

                                        <?php if (
                                            $row["estado"] === "Disponible"
                                        ) { ?>

                                            <span class="estado estado-disponible">
                                                Disponible
                                            </span>

                                        <?php } elseif (
                                            $row["estado"] === "Ocupada"
                                        ) { ?>

                                            <span class="estado estado-ocupada">
                                                Ocupada
                                            </span>

                                        <?php } else { ?>

                                            <span class="estado estado-mantenimiento">
                                                Mantenimiento
                                            </span>

                                        <?php } ?>

                                    </td>

                                    <td>

                                        <div class="acciones-tabla">

                                            <a
                                                href="editar.php?id=<?php
                                                echo urlencode(
                                                    $row["id_habitacion"]
                                                );
                                                ?>"
                                                class="btn-editar"
                                            >
                                                <i class="bi bi-pencil-square"></i>
                                                Editar
                                            </a>

                                            <a
                                                href="eliminar.php?id=<?php
                                                echo urlencode(
                                                    $row["id_habitacion"]
                                                );
                                                ?>"
                                                class="btn-eliminar"
                                                onclick="return confirm('¿Deseas eliminar esta habitación?');"
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
                                <td
                                    colspan="7"
                                    class="tabla-vacia"
                                >
                                    <?php if ($estadoFiltro === "Todos") { ?>
                                        No existen habitaciones registradas.
                                    <?php } else { ?>
                                        No hay habitaciones con estado
                                        <?php echo h(strtolower($estadoFiltro)); ?>.
                                    <?php } ?>
                                </td>
                            </tr>

                        <?php } ?>

                        </tbody>

                    </table>

                </div>

            </div>

            <?php if ($totalHabitaciones > 0) { ?>

                <div class="paginacion-contenedor">

                    <div class="paginacion-info">
                        Mostrando
                        <?php echo $primerRegistro; ?>
                        -
                        <?php echo $ultimoRegistro; ?>
                        de
                        <?php echo $totalHabitaciones; ?>
                        <?php
                        echo $estadoFiltro === "Todos"
                            ? "habitaciones"
                            : "habitaciones " .
                              h(strtolower($estadoFiltro));
                        ?>
                    </div>

                    <?php if ($totalPaginas > 1) { ?>

                        <nav
                            class="paginacion-hotel"
                            aria-label="Paginación de habitaciones"
                        >

                            <?php if ($paginaActual > 1) { ?>

                                <a href="<?php echo h(urlHabitaciones($estadoFiltro, $paginaActual - 1)); ?>">
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

                                <a href="<?php echo h(urlHabitaciones($estadoFiltro, 1)); ?>">1</a>

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

                                    <a href="<?php echo h(urlHabitaciones($estadoFiltro, $pagina)); ?>">
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

                                <a href="<?php echo h(urlHabitaciones($estadoFiltro, $totalPaginas)); ?>">
                                    <?php echo $totalPaginas; ?>
                                </a>

                            <?php } ?>

                            <?php if ($paginaActual < $totalPaginas) { ?>

                                <a href="<?php echo h(urlHabitaciones($estadoFiltro, $paginaActual + 1)); ?>">
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

        </section>

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
                    href="../dashboard.php"
                    class="btn btn-outline-light btn-sm"
                >
                    Volver al panel
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
                Módulo de habitaciones
            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>
    document.addEventListener("DOMContentLoaded", function () {
        const campoTipo = document.getElementById("tipo");
        const campoCapacidad = document.getElementById("capacidad");
        const campoPrecio = document.getElementById("precio");

        const capacidades = <?php
        echo json_encode(
            $tiposPermitidos,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
        ?>;

        const precios = <?php
        echo json_encode(
            $preciosReferenciales,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );
        ?>;

        function asignarDatosHabitacion(cambiarPrecio) {
            const tipoSeleccionado = campoTipo.value;

            campoCapacidad.value =
                capacidades[tipoSeleccionado] ?? "";

            if (cambiarPrecio || campoPrecio.value.trim() === "") {
                campoPrecio.value =
                    precios[tipoSeleccionado] ?? "";
            }
        }

        campoTipo.addEventListener("change", function () {
            asignarDatosHabitacion(true);
        });

        asignarDatosHabitacion(false);
    });
</script>

<script type="module">
    import {
        storage,
        ref,
        uploadBytes,
        getDownloadURL
    } from "../js/firebase-config.js";

    const formulario = document.getElementById("formHabitacion");
    const campoImagen = document.getElementById("imagen");
    const campoImagenUrl = document.getElementById("imagen_url");
    const vistaPrevia = document.getElementById("vistaPrevia");
    const btnGuardar = document.getElementById("btnGuardar");
    const mensajeFirebase = document.getElementById("mensajeFirebase");
    const campoNumero = document.getElementById("numero");

    let urlVistaPreviaTemporal = null;

    function mostrarMensaje(texto, tipo) {
        mensajeFirebase.textContent = texto;
        mensajeFirebase.className = "alert alert-" + tipo + " mb-0";
    }

    function ocultarMensaje() {
        mensajeFirebase.textContent = "";
        mensajeFirebase.className = "alert d-none mb-0";
    }

    campoImagen.addEventListener("change", function () {
        ocultarMensaje();

        const archivo = this.files[0];

        if (!archivo) {
            vistaPrevia.src =
                campoImagenUrl.value !== ""
                    ? campoImagenUrl.value
                    : "../img/hotel.jpg";

            return;
        }

        const tiposPermitidos = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];

        const tamanoMaximo = 5 * 1024 * 1024;

        if (!tiposPermitidos.includes(archivo.type)) {
            alert("Seleccione una imagen JPG, JPEG, PNG o WEBP.");

            campoImagen.value = "";
            vistaPrevia.src =
                campoImagenUrl.value !== ""
                    ? campoImagenUrl.value
                    : "../img/hotel.jpg";

            return;
        }

        if (archivo.size > tamanoMaximo) {
            alert("La imagen no puede superar los 5 MB.");

            campoImagen.value = "";
            vistaPrevia.src =
                campoImagenUrl.value !== ""
                    ? campoImagenUrl.value
                    : "../img/hotel.jpg";

            return;
        }

        if (urlVistaPreviaTemporal !== null) {
            URL.revokeObjectURL(urlVistaPreviaTemporal);
        }

        urlVistaPreviaTemporal = URL.createObjectURL(archivo);
        vistaPrevia.src = urlVistaPreviaTemporal;
    });

    formulario.addEventListener("submit", async function (evento) {
        evento.preventDefault();

        const archivo = campoImagen.files[0];

        if (!archivo) {
            formulario.submit();
            return;
        }

        const tiposPermitidos = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];

        const tamanoMaximo = 5 * 1024 * 1024;

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
            btnGuardar.disabled = true;
            btnGuardar.innerHTML =
                '<span class="spinner-border spinner-border-sm"></span> Subiendo imagen...';

            mostrarMensaje(
                "Subiendo la imagen a Firebase. Espere un momento...",
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
                "_" +
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

            campoImagenUrl.value = await getDownloadURL(
                resultado.ref
            );

            mostrarMensaje(
                "Imagen subida correctamente. Guardando habitación...",
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

            btnGuardar.disabled = false;
            btnGuardar.innerHTML =
                '<i class="bi bi-cloud-arrow-up"></i> Guardar habitación';
        }
    });
</script>

</body>
</html>