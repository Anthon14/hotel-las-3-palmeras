<?php

session_start();

include("config/conexion.php");
/* Página pública */
function h($texto)
{
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}

function resolverImagen(
    $imagen,
    $imagenPredeterminada
) {
    $imagen = trim((string) $imagen);

    if (
        $imagen === "" ||
        !filter_var($imagen, FILTER_VALIDATE_URL)
    ) {
        return $imagenPredeterminada;
    }

    $esquema = strtolower(
        (string) parse_url(
            $imagen,
            PHP_URL_SCHEME
        )
    );

    if (!in_array($esquema, ["http", "https"], true)) {
        return $imagenPredeterminada;
    }

    return $imagen;
}

$sesionIniciada = isset(
    $_SESSION["usuario"],
    $_SESSION["rol"]
);

$rolActual = strtolower(
    trim((string) ($_SESSION["rol"] ?? ""))
);

$esCliente =
    $sesionIniciada &&
    $rolActual === "cliente";

$esPersonal =
    $sesionIniciada &&
    in_array(
        $rolActual,
        ["administrador", "recepcionista"],
        true
    );

if ($esCliente) {
    $enlaceAcceso = "cliente/index.php";
    $textoAcceso = "Mi cuenta";
} elseif ($esPersonal) {
    $enlaceAcceso = "dashboard.php";
    $textoAcceso = "Ir al panel";
} else {
    $enlaceAcceso = "login.php";
    $textoAcceso = "Iniciar sesión";
}

$habitacionesDisponibles = [];
$errorHabitaciones = "";

$consultaHabitaciones = mysqli_query(
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
     WHERE estado = 'Disponible'
     ORDER BY id_habitacion DESC"
);

if (!$consultaHabitaciones) {
    $errorHabitaciones =
        "No se pudieron cargar las habitaciones.";
} else {
    while (
        $habitacion = mysqli_fetch_assoc(
            $consultaHabitaciones
        )
    ) {
        $habitacionesDisponibles[] =
            $habitacion;
    }
}

$totalHabitacionesDisponibles =
    count($habitacionesDisponibles);

$imagenPresentacionPrincipal =
    "img/hotel.jpg";

$imagenPresentacionSecundaria =
    "img/hotel.jpg";

$imagenesPresentacion = [];

foreach (
    $habitacionesDisponibles as $habitacion
) {
    $imagen = resolverImagen(
        $habitacion["imagen"] ?? "",
        "img/hotel.jpg"
    );

    if (
        $imagen !== "img/hotel.jpg" &&
        !in_array(
            $imagen,
            $imagenesPresentacion,
            true
        )
    ) {
        $imagenesPresentacion[] = $imagen;
    }
}

if (isset($imagenesPresentacion[0])) {
    $imagenPresentacionPrincipal =
        $imagenesPresentacion[0];
}

if (isset($imagenesPresentacion[1])) {
    $imagenPresentacionSecundaria =
        $imagenesPresentacion[1];
} elseif (isset($imagenesPresentacion[0])) {
    $imagenPresentacionSecundaria =
        $imagenesPresentacion[0];
}

$comidasDisponibles = [];
$errorComidas = "";

$consultaComidas = mysqli_query(
    $conn,
    "SELECT
        id_comida,
        nombre,
        tipo,
        descripcion,
        precio,
        estado,
        imagen
     FROM comidas
     WHERE estado = 'Disponible'
     ORDER BY id_comida DESC"
);

if (!$consultaComidas) {
    $errorComidas =
        "No se pudieron cargar las comidas.";
} else {
    while (
        $comida = mysqli_fetch_assoc(
            $consultaComidas
        )
    ) {
        $comidasDisponibles[] = $comida;
    }
}

$totalComidasDisponibles =
    count($comidasDisponibles);

function enlaceReservaPublica(
    int $idHabitacion,
    bool $esCliente,
    bool $esPersonal
): string {
    if ($esCliente) {
        return
            "cliente/reservar.php?id=" .
            $idHabitacion;
    }

    if ($esPersonal) {
        return "dashboard.php";
    }

    return
        "login.php?continuar=reserva&id=" .
        $idHabitacion;
}

function enlaceComidasPublico(
    bool $esCliente,
    bool $esPersonal
): string {
    if ($esCliente) {
        return "cliente/pedir_comida.php";
    }

    if ($esPersonal) {
        return "dashboard.php";
    }

    return "login.php?continuar=comidas";
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
        Hotel Las 3 Palmeras
    </title>

    <link
    rel="icon"
    type="image/png"
    href="img/logocircular.png?v=3"
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
        href="css/style.css?v=30"
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

            --sombra:
                0 18px 45px
                rgba(21, 45, 32, 0.13);
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

        .titulo-elegante {
            font-family:
                Georgia,
                "Times New Roman",
                serif;
        }
        .navbar-hotel {
            min-height: 82px;

            background-color:
                rgba(18, 39, 28, 0.90);

            border-bottom:
                1px solid
                rgba(255, 255, 255, 0.13);

            backdrop-filter: blur(12px);

            transition:
                background-color 0.25s ease,
                box-shadow 0.25s ease;
        }

        .navbar-hotel.navbar-con-sombra {
            background-color:
                rgba(18, 39, 28, 0.98);

            box-shadow:
                0 8px 24px
                rgba(0, 0, 0, 0.18);
        }

        .marca-hotel {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .navbar-logo {
            width: 54px !important;
            height: 54px !important;
            max-width: 54px !important;
            max-height: 54px !important;
            object-fit: contain !important;
            border-radius: 0 !important;
        }

        .marca-texto {
            line-height: 1.05;
        }

        .marca-texto strong {
            display: block;
            color: white;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

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
            color: rgba(255, 255, 255, 0.83);

            font-size: 14px;
            font-weight: 700;

            margin: 0 4px;

            padding:
                10px 11px !important;
        }

        .navbar-hotel .nav-link:hover,
        .navbar-hotel .nav-link.active {
            color: white;
        }

        .navbar-hotel .nav-link::after {
            content: "";

            position: absolute;

            left: 12px;
            right: 12px;
            bottom: 3px;

            height: 2px;

            background-color: var(--dorado);

            transform: scaleX(0);

            transition:
                transform 0.2s ease;
        }

        .navbar-hotel .nav-link:hover::after,
        .navbar-hotel .nav-link.active::after {
            transform: scaleX(1);
        }

        .usuario-navbar {
            color: white;

            font-size: 13px;
            line-height: 1.15;
        }

        .usuario-navbar strong {
            display: block;
            color: #ead8aa;
            font-size: 14px;
        }

        .btn-salir {
            border-radius: 999px;

            padding:
                9px 15px;

            font-weight: 700;
        }
        .hero-cliente {
            min-height: 100vh;

            position: relative;

            display: flex;
            align-items: center;

            overflow: visible;

            color: white;

            background:
                linear-gradient(
                    90deg,
                    rgba(10, 29, 20, 0.88) 0%,
                    rgba(10, 29, 20, 0.69) 47%,
                    rgba(10, 29, 20, 0.28) 100%
                ),
                url("img/hotel.jpg");

            background-size: cover;
            background-position: center;
        }

        .hero-cliente::before {
            content: "";

            position: absolute;
            inset: 0;

            background:
                radial-gradient(
                    circle at 82% 27%,
                    rgba(216, 181, 109, 0.14),
                    transparent 30%
                );

            pointer-events: none;
        }

        .hero-contenido {
            position: relative;
            z-index: 2;

            max-width: 770px;

            padding-top: 145px;
            padding-bottom: 180px;
        }

        .hero-etiqueta {
            display: inline-flex;
            align-items: center;
            gap: 10px;

            margin-bottom: 20px;

            color: #f0d99f;

            font-size: 13px;
            font-weight: 800;

            letter-spacing: 3px;
        }

        .hero-etiqueta::before {
            content: "";

            width: 44px;
            height: 2px;

            background-color: var(--dorado);
        }

        .hero-titulo {
            max-width: 720px;

            margin-bottom: 24px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size:
                clamp(
                    3.2rem,
                    7.4vw,
                    6.6rem
                );

            font-weight: 700;
            line-height: 0.98;

            letter-spacing: -2px;

            text-shadow:
                0 8px 24px
                rgba(0, 0, 0, 0.27);
        }

        .hero-titulo span {
            display: block;
            color: #f0d99f;
        }

        .hero-texto {
            max-width: 650px;

            margin-bottom: 34px;

            color:
                rgba(255, 255, 255, 0.88);

            font-size:
                clamp(
                    1rem,
                    1.8vw,
                    1.2rem
                );

            line-height: 1.75;
        }

        .hero-botones {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
        }

        .btn-hotel-principal,
        .btn-hotel-claro,
        .btn-hotel-borde {
            min-height: 52px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            gap: 9px;

            border-radius: 4px;

            padding:
                13px 24px;

            font-size: 14px;
            font-weight: 800;

            letter-spacing: 0.4px;

            transition:
                transform 0.2s ease,
                background-color 0.2s ease,
                border-color 0.2s ease;
        }

        .btn-hotel-principal {
            border:
                1px solid
                var(--dorado);

            background-color:
                var(--dorado);

            color: #203026;
        }

        .btn-hotel-principal:hover {
            background-color: #e7c882;
            border-color: #e7c882;
            color: #203026;

            transform:
                translateY(-2px);
        }

        .btn-hotel-claro {
            border: 1px solid white;
            background-color: white;
            color: var(--verde-oscuro);
        }

        .btn-hotel-claro:hover {
            background-color: #f2f2f2;
            color: var(--verde-oscuro);

            transform:
                translateY(-2px);
        }

        .btn-hotel-borde {
            border:
                1px solid
                rgba(255, 255, 255, 0.68);

            background-color:
                rgba(255, 255, 255, 0.07);

            color: white;
        }

        .btn-hotel-borde:hover {
            border-color: white;

            background-color:
                rgba(255, 255, 255, 0.17);

            color: white;

            transform:
                translateY(-2px);
        }

        .hero-datos {
            display: flex;
            flex-wrap: wrap;

            gap: 28px;

            margin-top: 38px;
        }

        .hero-dato {
            display: flex;
            align-items: center;
            gap: 11px;

            color:
                rgba(255, 255, 255, 0.82);

            font-size: 13px;
        }

        .hero-dato i {
            color: #f0d99f;
            font-size: 19px;
        }
        .atajos-flotantes {
            position: absolute;
            z-index: 4;

            left: 50%;
            bottom: -63px;

            width:
                min(
                    1120px,
                    calc(100% - 40px)
                );

            transform:
                translateX(-50%);
        }

        .atajos-contenedor {
            display: grid;

            grid-template-columns:
                repeat(3, 1fr);

            background-color: white;

            border-radius: 7px;

            box-shadow: var(--sombra);

            overflow: hidden;
        }

        .atajo-card {
            min-height: 126px;

            display: flex;
            align-items: center;

            gap: 15px;

            padding:
                25px 23px;

            color: var(--texto);

            border-right:
                1px solid #e7e5df;

            transition:
                background-color 0.2s ease,
                color 0.2s ease;
        }

        .atajo-card:last-child {
            border-right: none;
        }

        .atajo-card:hover {
            background-color:
                var(--verde-principal);

            color: white;
        }

        .atajo-icono {
            flex: 0 0 48px;

            width: 48px;
            height: 48px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 50%;

            background-color:
                var(--verde-claro);

            color:
                var(--verde-principal);

            font-size: 21px;

            transition:
                background-color 0.2s ease,
                color 0.2s ease;
        }

        .atajo-card:hover .atajo-icono {
            background-color:
                rgba(255, 255, 255, 0.15);

            color: #f2d89b;
        }

        .atajo-card strong {
            display: block;

            margin-bottom: 5px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 18px;
        }

        .atajo-card small {
            color: var(--texto-suave);
            font-size: 12px;
        }

        .atajo-card:hover small {
            color:
                rgba(255, 255, 255, 0.75);
        }
        .seccion {
            padding:
                96px 0;
        }

        .seccion-primera {
            padding-top: 150px;
        }

        .seccion-blanca {
            background-color: white;
        }

        .seccion-verde-claro {
            background-color:
                var(--verde-claro);
        }

        .seccion-etiqueta {
            margin-bottom: 12px;

            color: #9b7739;

            font-size: 12px;
            font-weight: 900;

            letter-spacing: 2.4px;

            text-transform: uppercase;
        }

        .seccion-titulo {
            margin-bottom: 17px;

            color: var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size:
                clamp(
                    2.2rem,
                    4.5vw,
                    3.6rem
                );

            font-weight: 700;
            line-height: 1.1;
        }

        .seccion-texto {
            color: var(--texto-suave);

            font-size: 16px;
            line-height: 1.8;
        }
        .presentacion-imagen {
            position: relative;
            min-height: 525px;
        }

        .presentacion-imagen-principal {
            width: 86%;
            height: 455px;

            object-fit: cover;

            border-radius: 5px;

            box-shadow: var(--sombra);
        }

        .presentacion-imagen-secundaria {
            position: absolute;

            right: 0;
            bottom: 0;

            width: 47%;
            height: 230px;

            object-fit: cover;

            border:
                9px solid
                var(--crema);

            border-radius: 5px;

            box-shadow: var(--sombra);
        }

        .sello-hotel {
            position: absolute;

            right: 14%;
            top: 25px;

            width: 105px;
            height: 105px;

            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;

            border-radius: 50%;

            background-color:
                var(--dorado);

            color: #223127;

            text-align: center;

            box-shadow: var(--sombra);
        }

        .sello-hotel strong {
            font-size: 25px;
            line-height: 1;
        }

        .sello-hotel small {
            max-width: 70px;

            margin-top: 5px;

            font-size: 9px;
            font-weight: 800;
            line-height: 1.15;

            letter-spacing: 0.5px;

            text-transform: uppercase;
        }

        .lista-beneficios {
            display: grid;

            grid-template-columns:
                repeat(2, 1fr);

            gap:
                14px 20px;

            padding: 0;

            margin:
                28px 0 32px;

            list-style: none;
        }

        .lista-beneficios li {
            display: flex;
            align-items: center;

            gap: 10px;

            color: #3d473f;

            font-size: 14px;
            font-weight: 700;
        }

        .lista-beneficios i {
            color: #a88242;
            font-size: 18px;
        }
        .habitacion-card {
            height: 100%;

            overflow: hidden;

            border: none;
            border-radius: 6px;

            background-color: white;

            box-shadow:
                0 12px 32px
                rgba(29, 52, 38, 0.10);

            transition:
                transform 0.24s ease,
                box-shadow 0.24s ease;
        }

        .habitacion-card:hover {
            transform:
                translateY(-7px);

            box-shadow:
                0 21px 43px
                rgba(29, 52, 38, 0.17);
        }

        .habitacion-imagen-contenedor {
            position: relative;
            overflow: hidden;
        }

        .habitacion-imagen {
            width: 100%;
            height: 275px;

            object-fit: cover;

            transition:
                transform 0.45s ease;
        }

        .habitacion-card:hover
        .habitacion-imagen {
            transform:
                scale(1.045);
        }

        .estado-disponible {
            position: absolute;

            top: 17px;
            right: 17px;

            padding:
                7px 12px;

            border-radius: 3px;

            background-color:
                rgba(25, 77, 47, 0.93);

            color: white;

            font-size: 11px;
            font-weight: 900;

            letter-spacing: 0.7px;

            text-transform: uppercase;
        }

        .habitacion-card .card-body {
            padding: 25px;
        }

        .habitacion-numero {
            margin-bottom: 7px;

            color: #9b7739;

            font-size: 11px;
            font-weight: 900;

            letter-spacing: 1.8px;

            text-transform: uppercase;
        }

        .habitacion-tipo {
            color: var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 25px;
            font-weight: 700;
        }

        .habitacion-detalles {
            display: flex;
            align-items: center;

            gap: 18px;

            padding:
                16px 0;

            margin:
                16px 0;

            border-top:
                1px solid #ece9e1;

            border-bottom:
                1px solid #ece9e1;

            color: var(--texto-suave);

            font-size: 13px;
        }

        .habitacion-detalles span {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .habitacion-detalles i {
            color: #a88242;
        }

        .habitacion-precio small {
            display: block;

            color: var(--texto-suave);

            font-size: 11px;
            font-weight: 700;

            text-transform: uppercase;
        }

        .habitacion-precio strong {
            color: var(--verde-principal);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 27px;
        }

        .habitacion-acciones {
            display: flex;
            gap: 9px;

            margin-top: 19px;
        }

        .btn-tarjeta,
        .btn-tarjeta-secundario {
            flex: 1;

            min-height: 43px;

            display: inline-flex;
            align-items: center;
            justify-content: center;

            border-radius: 3px;

            padding:
                10px 13px;

            font-size: 12px;
            font-weight: 900;

            transition:
                0.2s ease;
        }

        .btn-tarjeta {
            background-color:
                var(--verde-principal);

            border:
                1px solid
                var(--verde-principal);

            color: white;
        }

        .btn-tarjeta:hover {
            background-color:
                var(--verde-oscuro);

            border-color:
                var(--verde-oscuro);

            color: white;
        }

        .btn-tarjeta-secundario {
            background-color: transparent;

            border:
                1px solid #b6bcb7;

            color:
                var(--verde-principal);
        }

        .btn-tarjeta-secundario:hover {
            background-color:
                var(--verde-claro);

            border-color:
                var(--verde-principal);

            color:
                var(--verde-principal);
        }
        .modal-habitacion .modal-dialog {
            max-width: 760px;
        }

        .modal-habitacion .modal-content {
            overflow: hidden;
            border: none;
            border-radius: 12px;
            background-color: white;
            box-shadow:
                0 28px 70px
                rgba(15, 37, 24, 0.28);
        }

        .modal-habitacion .modal-header {
            padding: 21px 24px;
            border-bottom: none;
            background:
                linear-gradient(
                    135deg,
                    var(--verde-oscuro),
                    var(--verde-principal)
                );
            color: white;
        }

        .modal-habitacion .modal-title {
            margin: 0;
            color: white;
            font-family:
                Georgia,
                "Times New Roman",
                serif;
            font-size: 24px;
            font-weight: 700;
        }

        .modal-habitacion .btn-close {
            filter:
                invert(1)
                grayscale(100%)
                brightness(200%);
            opacity: 0.9;
        }

        .modal-habitacion .modal-body {
            padding: 0;
        }

        .modal-habitacion-imagen {
            width: 100%;
            height: 330px;
            display: block;
            object-fit: cover;
            background-color: #eef1ed;
        }

        .modal-habitacion-contenido {
            padding: 26px;
        }

        .modal-habitacion-etiqueta {
            margin-bottom: 8px;
            color: #9b7739;
            font-size: 11px;
            font-weight: 900;
            letter-spacing: 1.7px;
            text-transform: uppercase;
        }

        .modal-habitacion-tipo {
            margin-bottom: 20px;
            color: var(--verde-oscuro);
            font-family:
                Georgia,
                "Times New Roman",
                serif;
            font-size: 30px;
            font-weight: 700;
        }

        .modal-habitacion-datos {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 13px;
        }

        .modal-habitacion-dato {
            padding: 15px 16px;
            border:
                1px solid #e2e5df;
            border-radius: 7px;
            background-color: #f7f9f7;
        }

        .modal-habitacion-dato small {
            display: block;
            margin-bottom: 5px;
            color: var(--texto-suave);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: 0.8px;
            text-transform: uppercase;
        }

        .modal-habitacion-dato strong {
            color: #303731;
            font-size: 15px;
        }

        .modal-habitacion-precio strong {
            color: var(--verde-principal);
            font-family:
                Georgia,
                "Times New Roman",
                serif;
            font-size: 23px;
        }

        .modal-habitacion .modal-footer {
            gap: 10px;
            padding: 18px 26px 24px;
            border-top: 1px solid #eceee9;
            background-color: #fbfcfa;
        }

        .modal-habitacion .modal-footer .btn {
            min-width: 130px;
            min-height: 44px;
            font-size: 13px;
            font-weight: 800;
        }

        .modal-habitacion .btn-reservar-modal {
            border-color: var(--verde-principal);
            background-color: var(--verde-principal);
            color: white;
        }

        .modal-habitacion .btn-reservar-modal:hover {
            border-color: var(--verde-oscuro);
            background-color: var(--verde-oscuro);
            color: white;
        }
        .servicio-card {
            height: 100%;

            padding:
                31px 25px;

            border:
                1px solid #dedfd9;

            border-radius: 5px;

            background-color: white;

            transition:
                transform 0.2s ease,
                border-color 0.2s ease,
                box-shadow 0.2s ease;
        }

        .servicio-card:hover {
            transform:
                translateY(-5px);

            border-color: #cbb174;

            box-shadow:
                0 16px 35px
                rgba(28, 53, 37, 0.10);
        }

        .servicio-icono {
            width: 55px;
            height: 55px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 20px;

            border-radius: 50%;

            background-color:
                var(--verde-claro);

            color:
                var(--verde-principal);

            font-size: 23px;
        }

        .servicio-card h3 {
            color: var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 22px;
            font-weight: 700;
        }

        .servicio-card p {
            margin: 0;

            color: var(--texto-suave);

            font-size: 14px;
            line-height: 1.7;
        }
        .comidas-promocion {
            position: relative;
            overflow: hidden;

            color: white;

            background:
                linear-gradient(
                    90deg,
                    rgba(18, 49, 33, 0.96),
                    rgba(18, 49, 33, 0.82)
                ),
                url("img/hotel.jpg");

            background-size: cover;
            background-position: center;
        }

        .comidas-promocion::after {
            content: "";

            position: absolute;

            width: 310px;
            height: 310px;

            right: -85px;
            top: -105px;

            border:
                1px solid
                rgba(216, 181, 109, 0.21);

            border-radius: 50%;
        }

        .comidas-promocion
        .seccion-etiqueta {
            color: #f0d99f;
        }

        .comidas-promocion
        .seccion-titulo {
            color: white;
        }

        .comidas-promocion
        .seccion-texto {
            color:
                rgba(255, 255, 255, 0.76);
        }

        .comida-mini-card {
            height: 100%;

            overflow: hidden;

            border-radius: 5px;

            background-color: white;

            color: var(--texto);

            box-shadow:
                0 14px 33px
                rgba(0, 0, 0, 0.18);
        }

        .comida-mini-card img {
            width: 100%;
            height: 170px;

            object-fit: cover;
        }

        .comida-mini-card-contenido {
            padding: 18px;
        }

        .comida-mini-tipo {
            color: #9b7739;

            font-size: 10px;
            font-weight: 900;

            letter-spacing: 1.5px;

            text-transform: uppercase;
        }

        .comida-mini-card h3 {
            margin:
                6px 0 9px;

            color:
                var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 19px;
            font-weight: 700;
        }

        .comida-mini-precio {
            color:
                var(--verde-principal);

            font-size: 18px;
            font-weight: 900;
        }

        .btn-pedir-profesional {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;

            padding: 7px 13px;

            border:
                1px solid
                var(--verde-principal);
            border-radius: 4px;

            background-color:
                var(--verde-principal);
            color: white;

            font-size: 12px;
            font-weight: 700;
            line-height: 1;

            text-decoration: none;

            transition:
                background-color 0.2s ease,
                border-color 0.2s ease,
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .btn-pedir-profesional:hover {
            background-color:
                var(--verde-oscuro);
            border-color:
                var(--verde-oscuro);
            color: white;

            transform:
                translateY(-1px);

            box-shadow:
                0 5px 12px
                rgba(23, 51, 37, 0.18);
        }

        .btn-pedir-profesional:focus {
            color: white;
        }

        .btn-pedir-profesional i {
            font-size: 12px;
        }
        .paso-card {
            position: relative;

            height: 100%;

            padding:
                30px 25px;

            border:
                1px solid
                rgba(36, 74, 53, 0.08);

            border-radius: 7px;

            background-color: white;

            box-shadow:
                0 20px 44px
                rgba(27, 50, 35, 0.16);

            transition:
                transform 0.22s ease,
                box-shadow 0.22s ease,
                border-color 0.22s ease;
        }

        .paso-card:hover {
            transform:
                translateY(-5px);

            border-color:
                rgba(168, 130, 66, 0.35);

            box-shadow:
                0 26px 54px
                rgba(27, 50, 35, 0.20);
        }

        .paso-numero {
            width: 48px;
            height: 48px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 18px;

            border-radius: 50%;

            background-color:
                var(--dorado);

            color: #243126;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 20px;
            font-weight: 700;
        }

        .paso-card h3 {
            color:
                var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 21px;
            font-weight: 700;
        }

        .paso-card p {
            margin: 0;

            color:
                var(--texto-suave);

            font-size: 14px;
            line-height: 1.7;
        }
        .cuenta-card {
            height: 100%;

            position: relative;

            overflow: hidden;

            padding:
                31px 27px;

            border-radius: 5px;

            background-color: white;

            box-shadow:
                0 13px 31px
                rgba(27, 50, 35, 0.09);
        }

        .cuenta-card::after {
            content: "";

            position: absolute;

            right: -28px;
            bottom: -35px;

            width: 110px;
            height: 110px;

            border-radius: 50%;

            background-color:
                rgba(36, 74, 53, 0.05);
        }

        .cuenta-icono {
            width: 52px;
            height: 52px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin-bottom: 18px;

            border-radius: 50%;

            background-color:
                var(--verde-principal);

            color: white;

            font-size: 21px;
        }

        .cuenta-card h3 {
            color:
                var(--verde-oscuro);

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 22px;
            font-weight: 700;
        }

        .cuenta-card p {
            min-height: 68px;

            color:
                var(--texto-suave);

            font-size: 14px;
            line-height: 1.7;
        }

        .enlace-cuenta {
            position: relative;
            z-index: 2;

            display: inline-flex;
            align-items: center;

            gap: 8px;

            color:
                var(--verde-principal);

            font-size: 13px;
            font-weight: 900;
        }

        .enlace-cuenta:hover {
            color: #966f2f;
        }
        .footer-hotel {
            background-color: #13271c;
            color: white;
        }

        .footer-principal {
            padding:
                65px 0 45px;
        }

        .footer-logo {
            width: 68px;
            height: 68px;
            object-fit: contain;
        }

        .footer-marca h2 {
            margin-bottom: 8px;

            font-family:
                Georgia,
                "Times New Roman",
                serif;

            font-size: 25px;
        }

        .footer-marca p,
        .footer-hotel li,
        .footer-hotel a {
            color:
                rgba(255, 255, 255, 0.66);

            font-size: 13px;
        }

        .footer-hotel a:hover {
            color: #f0d99f;
        }

        .footer-titulo {
            margin-bottom: 17px;

            color: white;

            font-size: 13px;
            font-weight: 900;

            letter-spacing: 1.2px;

            text-transform: uppercase;
        }

        .footer-lista {
            padding: 0;
            margin: 0;
            list-style: none;
        }

        .footer-lista li {
            margin-bottom: 10px;
        }

        .footer-final {
            padding:
                20px 0;

            border-top:
                1px solid
                rgba(255, 255, 255, 0.10);

            color:
                rgba(255, 255, 255, 0.52);

            font-size: 12px;
        }
        @media (max-width: 1199px) {

            .atajo-card {
                padding:
                    22px 17px;
            }

            .atajo-card strong {
                font-size: 16px;
            }

            .presentacion-imagen-principal {
                width: 90%;
            }
        }

        @media (max-width: 991px) {
            
            .hero-cliente {
             display: block;
             min-height: auto;
              overflow: visible;
            }

            .navbar-hotel {
                background-color:
                    rgba(18, 39, 28, 0.98);
            }

            .navbar-collapse {
                padding:
                    18px 0 14px;
            }

            .navbar-hotel .nav-link {
                padding:
                    11px 0 !important;
            }

            .navbar-hotel
            .nav-link::after {
                left: 0;
                right: auto;
                width: 42px;
            }

            .usuario-navbar {
                margin-top: 12px;
            }

            .hero-cliente {
                min-height: auto;
                background-position: 62% center;
            }

            .hero-contenido {
                padding-top: 145px;
                padding-bottom: 95px;
            }

            .atajos-flotantes {
                position: relative;

                left: auto;
                bottom: auto;

                width: 100%;

                transform: none;

                background-color: white;
            }

            .atajos-contenedor {
                grid-template-columns:
                    repeat(2, 1fr);

                border-radius: 0;

                box-shadow: none;
            }

            .atajo-card:nth-child(2) {
                border-right: none;
            }

            .atajo-card:nth-child(-n + 2) {
                border-bottom:
                    1px solid #e7e5df;
            }

            .seccion-primera {
                padding-top: 90px;
            }

            .presentacion-imagen {
                margin-bottom: 45px;
            }

            .presentacion-imagen-principal {
                width: 87%;
            }
        }
        @media (max-width: 767px) {

            html {
                scroll-padding-top: 78px;
            }

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

            .hero-cliente {
                background:
                    linear-gradient(
                        rgba(10, 29, 20, 0.80),
                        rgba(10, 29, 20, 0.75)
                    ),
                    url("img/hotel.jpg");

                background-size: cover;
                background-position: center;
            }

            .hero-contenido {
                padding-top: 125px;
                padding-bottom: 70px;

                text-align: center;
            }

            .hero-etiqueta {
                justify-content: center;

                font-size: 11px;
                letter-spacing: 2px;
            }

            .hero-etiqueta::before {
                width: 28px;
            }

            .hero-titulo {
                margin-left: auto;
                margin-right: auto;

                font-size:
                    clamp(
                        2.75rem,
                        14vw,
                        4.1rem
                    );

                letter-spacing: -1px;
            }

            .hero-texto {
                margin-left: auto;
                margin-right: auto;

                font-size: 15px;
                line-height: 1.65;
            }

            .hero-botones {
                justify-content: center;
            }

            .btn-hotel-principal,
            .btn-hotel-claro,
            .btn-hotel-borde {
                width: 100%;
                max-width: 330px;
            }

            .hero-datos {
                justify-content: center;
                gap: 17px;
            }

            .atajos-contenedor {
                grid-template-columns: 1fr;
            }

            .atajo-card,
            .atajo-card:nth-child(2) {
                min-height: 100px;

                border-right: none;

                border-bottom:
                    1px solid #e7e5df;
            }

            .atajo-card:last-child {
                border-bottom: none;
            }

            .seccion,
            .seccion-primera {
                padding:
                    70px 0;
            }

            .seccion-titulo {
                font-size:
                    clamp(
                        2rem,
                        10vw,
                        2.65rem
                    );
            }

            .presentacion-imagen {
                min-height: 420px;
            }

            .presentacion-imagen-principal {
                width: 92%;
                height: 350px;
            }

            .presentacion-imagen-secundaria {
                width: 54%;
                height: 170px;

                border-width: 6px;
            }

            .sello-hotel {
                right: 4%;

                width: 88px;
                height: 88px;
            }

            .sello-hotel strong {
                font-size: 21px;
            }

            .lista-beneficios {
                grid-template-columns: 1fr;
            }

            .habitacion-imagen {
                height: 235px;
            }

            .habitacion-acciones {
                flex-direction: column;
            }

            .habitacion-detalles {
                flex-wrap: wrap;
            }

            .cuenta-card p {
                min-height: auto;
            }

            .footer-principal {
                padding-top: 50px;
            }

            .modal-habitacion .modal-dialog {
                margin:
                    0.75rem;
            }

            .modal-habitacion-imagen {
                height: 240px;
            }

            .modal-habitacion-contenido {
                padding: 21px;
            }

            .modal-habitacion-datos {
                grid-template-columns: 1fr;
            }

            .modal-habitacion .modal-footer {
                display: grid;
                grid-template-columns: 1fr;
                padding: 16px 21px 21px;
            }

            .modal-habitacion .modal-footer .btn {
                width: 100%;
            }

            .paso-card {
                padding:
                    27px 22px;
            }

            .footer-marca {
                align-items: flex-start !important;
            }
        }

        @media (max-width: 420px) {

            .marca-texto {
                display: none;
            }

            .hero-titulo {
                font-size: 2.75rem;
            }

            .hero-datos {
                flex-direction: column;
                align-items: center;
            }

            .presentacion-imagen {
                min-height: 360px;
            }

            .presentacion-imagen-principal {
                width: 100%;
                height: 300px;
            }

            .presentacion-imagen-secundaria {
                width: 58%;
                height: 145px;
            }

            .sello-hotel {
                top: 16px;
                right: 2%;
            }

            .hero-contenido {
                padding-top: 112px;
                padding-bottom: 58px;
            }

            .hero-texto {
                font-size: 14px;
            }

            .seccion,
            .seccion-primera {
                padding:
                    58px 0;
            }

            .habitacion-card .card-body {
                padding: 21px;
            }

            .modal-habitacion-imagen {
                height: 205px;
            }

            .modal-habitacion-tipo {
                font-size: 25px;
            }

            .footer-principal {
                padding-bottom: 35px;
            }

            .footer-final .container {
                justify-content: center !important;
                text-align: center;
            }
        }
    </style>

</head>

<body>

<nav
    class="navbar navbar-expand-lg navbar-dark navbar-hotel fixed-top"
>

    <div class="container">

        <a
            href="index.php"
            class="navbar-brand marca-hotel p-0"
        >

            <img
                src="img/logo.png"
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
            data-bs-target="#menuPublico"
            aria-controls="menuPublico"
            aria-expanded="false"
            aria-label="Abrir menú"
        >

            <span class="navbar-toggler-icon"></span>

        </button>

        <div
            class="collapse navbar-collapse"
            id="menuPublico"
        >

            <ul
                class="navbar-nav mx-auto mb-2 mb-lg-0"
            >

                <li class="nav-item">

                    <a
                        href="#inicio"
                        class="nav-link active"
                    >
                        Inicio
                    </a>

                </li>

                <li class="nav-item">

                    <a
                        href="#habitaciones"
                        class="nav-link"
                    >
                        Habitaciones
                    </a>

                </li>

                <li class="nav-item">

                    <a
                        href="#servicios"
                        class="nav-link"
                    >
                        Servicios
                    </a>

                </li>

                <li class="nav-item">

                    <a
                        href="#comidas"
                        class="nav-link"
                    >
                        Comidas
                    </a>

                </li>

                <li class="nav-item">

                    <a
                        href="#como-reservar"
                        class="nav-link"
                    >
                        Cómo reservar
                    </a>

                </li>

            </ul>

            <div
                class="d-flex flex-wrap align-items-center gap-2"
            >

                <a
                    href="<?php echo h($enlaceAcceso); ?>"
                    class="btn btn-outline-light btn-sm btn-salir"
                >

                    <i
                        class="bi bi-person-circle me-1"
                    ></i>

                    <?php echo h($textoAcceso); ?>

                </a>

                <?php if (!$sesionIniciada) { ?>

                    <a
                        href="registro.php"
                        class="btn btn-warning btn-sm btn-salir"
                    >

                        <i
                            class="bi bi-person-plus me-1"
                        ></i>

                        Crear cuenta

                    </a>

                <?php } elseif ($esCliente) { ?>

                    <a
                        href="logout.php"
                        class="btn btn-outline-light btn-sm btn-salir"
                    >

                        <i
                            class="bi bi-box-arrow-right me-1"
                        ></i>

                        Salir

                    </a>

                <?php } ?>

            </div>

        </div>

    </div>

</nav>

<main>

    <section
        class="hero-cliente"
        id="inicio"
    >

        <div class="container">

            <div class="hero-contenido">

                <div class="hero-etiqueta">
                    BIENVENIDO AL HOTEL LAS 3 PALMERAS
                </div>

                <h1 class="hero-titulo">

                    Descansa, disfruta y

                    <span>
                        siéntete como en casa
                    </span>

                </h1>

                <p class="hero-texto">

                    Conoce nuestras habitaciones, consulta
                    los precios y revisa las comidas
                    disponibles antes de crear tu cuenta.

                </p>

                <div class="hero-botones">

                    <a
                        href="#habitaciones"
                        class="btn-hotel-principal"
                    >

                        <i class="bi bi-door-open"></i>

                        Ver habitaciones

                    </a>

                    <a
                        href="#comidas"
                        class="btn-hotel-claro"
                    >

                        <i class="bi bi-cup-hot"></i>

                        Ver comidas

                    </a>

                </div>

                <div class="hero-datos">

                    <div class="hero-dato">

                        <i
                            class="bi bi-check-circle"
                        ></i>

                        Consulta sin registrarte

                    </div>

                    <div class="hero-dato">

                        <i
                            class="bi bi-shield-check"
                        ></i>

                        Reserva desde tu cuenta

                    </div>

                    <div class="hero-dato">

                        <i class="bi bi-phone"></i>

                        Disponible en celular

                    </div>

                </div>

            </div>

        </div>

        <div class="atajos-flotantes">

            <div class="atajos-contenedor">

                <a
                    href="#habitaciones"
                    class="atajo-card"
                >

                    <span class="atajo-icono">

                        <i
                            class="bi bi-building-check"
                        ></i>

                    </span>

                    <span>

                        <strong>
                            Habitaciones
                        </strong>

                        <small>

                            <?php
                            echo $totalHabitacionesDisponibles;
                            ?>

                            disponibles

                        </small>

                    </span>

                </a>

                <a
                    href="#comidas"
                    class="atajo-card"
                >

                    <span class="atajo-icono">

                        <i
                            class="bi bi-egg-fried"
                        ></i>

                    </span>

                    <span>

                        <strong>
                            Comidas
                        </strong>

                        <small>

                            <?php
                            echo $totalComidasDisponibles;
                            ?>

                            opciones disponibles

                        </small>

                    </span>

                </a>

                <a
                    href="<?php echo h(
                        !$sesionIniciada
                            ? "registro.php"
                            : ($esCliente
                                ? "cliente/index.php"
                                : "dashboard.php")
                    ); ?>"
                    class="atajo-card"
                >

                    <span class="atajo-icono">

                        <i class="<?php
                        echo !$sesionIniciada
                            ? "bi bi-person-plus"
                            : ($esCliente
                                ? "bi bi-person-circle"
                                : "bi bi-speedometer2");
                        ?>"></i>

                    </span>

                    <span>

                        <strong>
                            <?php
                            echo !$sesionIniciada
                                ? "Crear cuenta"
                                : ($esCliente
                                    ? "Mi cuenta"
                                    : "Panel administrativo");
                            ?>
                        </strong>

                        <small>
                            <?php
                            echo !$sesionIniciada
                                ? "Regístrate para reservar"
                                : ($esCliente
                                    ? "Consulta tus reservas y servicios"
                                    : "Volver a la gestión del hotel");
                            ?>
                        </small>

                    </span>

                </a>

            </div>

        </div>

    </section>

    <section class="seccion seccion-primera">

        <div class="container">

            <div
                class="row align-items-center g-5"
            >

                <div class="col-lg-6">

                    <div class="presentacion-imagen">

                        <img
                            src="<?php echo h($imagenPresentacionPrincipal); ?>"
                            alt="Habitación destacada del Hotel Las 3 Palmeras"
                            class="presentacion-imagen-principal"
                            onerror="this.onerror=null; this.src='img/hotel.jpg';"
                        >

                        <img
                            src="<?php echo h($imagenPresentacionSecundaria); ?>"
                            alt="Otra habitación disponible del Hotel Las 3 Palmeras"
                            class="presentacion-imagen-secundaria"
                            onerror="this.onerror=null; this.src='img/hotel.jpg';"
                        >

                        <div class="sello-hotel">

                            <strong>
                                3
                            </strong>

                            <small>
                                Palmeras para tu descanso
                            </small>

                        </div>

                    </div>

                </div>

                <div class="col-lg-6">

                    <p class="seccion-etiqueta">
                        CONOCE NUESTRO HOTEL
                    </p>

                    <h2 class="seccion-titulo">

                        Todo lo que necesitas
                        para una estadía cómoda

                    </h2>

                    <p class="seccion-texto">

                        Revisa las habitaciones disponibles,
                        sus imágenes, capacidad y precio por
                        noche sin necesidad de iniciar sesión.

                    </p>

                    <p class="seccion-texto">

                        <?php if (!$sesionIniciada) { ?>
                            Cuando elijas una habitación podrás
                            crear tu cuenta para completar la
                            reserva y registrar el pago.
                        <?php } elseif ($esCliente) { ?>
                            Desde tu cuenta puedes completar
                            una reserva, registrar el pago y
                            consultar el estado de tus servicios.
                        <?php } else { ?>
                            Desde el panel administrativo puedes
                            gestionar habitaciones, reservas y
                            servicios del hotel.
                        <?php } ?>

                    </p>

                    <ul class="lista-beneficios">

                        <li>

                            <i
                                class="bi bi-check2-circle"
                            ></i>

                            Habitaciones disponibles

                        </li>

                        <li>

                            <i
                                class="bi bi-check2-circle"
                            ></i>

                            Precios por noche

                        </li>

                        <li>

                            <i
                                class="bi bi-check2-circle"
                            ></i>

                            Comidas del hotel

                        </li>

                        <li>

                            <i
                                class="bi bi-check2-circle"
                            ></i>

                            Reservas en línea

                        </li>

                    </ul>

                    <a
                        href="<?php echo h(
                            !$sesionIniciada
                                ? "registro.php"
                                : ($esCliente
                                    ? "cliente/index.php"
                                    : "dashboard.php")
                        ); ?>"
                        class="btn-hotel-principal"
                    >

                        <i class="<?php
                        echo !$sesionIniciada
                            ? "bi bi-person-plus"
                            : ($esCliente
                                ? "bi bi-person-circle"
                                : "bi bi-speedometer2");
                        ?>"></i>

                        <?php
                        echo !$sesionIniciada
                            ? "Crear una cuenta"
                            : ($esCliente
                                ? "Ir a mi cuenta"
                                : "Ir al panel");
                        ?>

                    </a>

                </div>

            </div>

        </div>

    </section>

    <section
        class="seccion seccion-blanca"
        id="habitaciones"
    >

        <div class="container">

            <div
                class="row align-items-end mb-5 g-3"
            >

                <div class="col-lg-8">

                    <p class="seccion-etiqueta">
                        ALOJAMIENTO
                    </p>

                    <h2
                        class="seccion-titulo mb-2"
                    >
                        Habitaciones disponibles
                    </h2>

                    <p
                        class="seccion-texto mb-0"
                    >

                        <?php if (!$sesionIniciada) { ?>
                            Revisa sus detalles y crea una
                            cuenta cuando decidas reservar.
                        <?php } elseif ($esCliente) { ?>
                            Revisa sus detalles y reserva
                            directamente desde tu cuenta.
                        <?php } else { ?>
                            Revisa las habitaciones disponibles
                            actualmente en el hotel.
                        <?php } ?>

                    </p>

                </div>

                <div
                    class="col-lg-4 text-lg-end"
                >

                    <span
                        class="badge rounded-pill text-bg-light px-3 py-2"
                    >

                        <i
                            class="bi bi-door-open me-1"
                        ></i>

                        <?php
                        echo $totalHabitacionesDisponibles;
                        ?>

                        disponibles

                    </span>

                </div>

            </div>

            <?php if (
                $errorHabitaciones !== ""
            ) { ?>

                <div class="alert alert-danger">

                    <?php
                    echo h($errorHabitaciones);
                    ?>

                </div>

            <?php } ?>

            <?php if (
                count($habitacionesDisponibles) > 0
            ) { ?>

                <div class="row g-4">

                    <?php foreach (
                        $habitacionesDisponibles as $habitacion
                    ) { ?>

                        <?php

                        $rutaImagenHabitacion =
                            resolverImagen(
                                $habitacion[
                                    "imagen"
                                ] ?? "",
                                "img/hotel.jpg"
                            );

                        $idHabitacion =
                            (int) $habitacion[
                                "id_habitacion"
                            ];

                        ?>

                        <div
                            class="col-md-6 col-xl-4"
                        >

                            <article
                                class="habitacion-card"
                            >

                                <div
                                    class="habitacion-imagen-contenedor"
                                >

                                    <img
                                        src="<?php
                                        echo h(
                                            $rutaImagenHabitacion
                                        );
                                        ?>"
                                        alt="Habitación <?php
                                        echo h(
                                            $habitacion[
                                                "numero"
                                            ]
                                        );
                                        ?>"
                                        class="habitacion-imagen"
                                        loading="lazy"
                                        onerror="this.onerror=null; this.src='img/hotel.jpg';"
                                    >

                                    <span
                                        class="estado-disponible"
                                    >
                                        Disponible
                                    </span>

                                </div>

                                <div class="card-body">

                                    <div
                                        class="habitacion-numero"
                                    >

                                        Habitación

                                        <?php
                                        echo h(
                                            $habitacion[
                                                "numero"
                                            ]
                                        );
                                        ?>

                                    </div>

                                    <h3
                                        class="habitacion-tipo"
                                    >

                                        <?php
                                        echo h(
                                            $habitacion[
                                                "tipo"
                                            ]
                                        );
                                        ?>

                                    </h3>

                                    <div
                                        class="habitacion-detalles"
                                    >

                                        <span>

                                            <i
                                                class="bi bi-people"
                                            ></i>

                                            <?php
                                            echo (int)
                                                $habitacion[
                                                    "capacidad"
                                                ];
                                            ?>

                                            persona(s)

                                        </span>

                                        <span>

                                            <i
                                                class="bi bi-wifi"
                                            ></i>

                                            Servicio del hotel

                                        </span>

                                    </div>

                                    <div
                                        class="d-flex justify-content-between align-items-end gap-3"
                                    >

                                        <div
                                            class="habitacion-precio"
                                        >

                                            <small>
                                                Precio por noche
                                            </small>

                                            <strong>

                                                $<?php
                                                echo number_format(
                                                    (float)
                                                    $habitacion[
                                                        "precio"
                                                    ],
                                                    2
                                                );
                                                ?>

                                            </strong>

                                        </div>

                                    </div>

                                    <div
                                        class="habitacion-acciones"
                                    >

                                        <button
                                            type="button"
                                            class="btn-tarjeta-secundario"
                                            data-bs-toggle="modal"
                                            data-bs-target="#habitacion<?php echo $idHabitacion; ?>"
                                        >
                                            Ver detalles
                                        </button>

                                        <a
                                            href="<?php
                                            echo h(
                                                enlaceReservaPublica(
                                                    $idHabitacion,
                                                    $esCliente,
                                                    $esPersonal
                                                )
                                            );
                                            ?>"
                                            class="btn-tarjeta"
                                        >
                                            Reservar
                                        </a>

                                    </div>

                                </div>

                            </article>

                        </div>

                        <div
                            class="modal fade modal-habitacion"
                            id="habitacion<?php echo $idHabitacion; ?>"
                            tabindex="-1"
                            aria-hidden="true"
                        >

                            <div
                                class="modal-dialog modal-dialog-centered"
                            >

                                <div class="modal-content">

                                    <div class="modal-header">

                                        <h2 class="modal-title">

                                            Habitación

                                            <?php
                                            echo h(
                                                $habitacion[
                                                    "numero"
                                                ]
                                            );
                                            ?>

                                        </h2>

                                        <button
                                            type="button"
                                            class="btn-close"
                                            data-bs-dismiss="modal"
                                            aria-label="Cerrar"
                                        ></button>

                                    </div>

                                    <div class="modal-body">

                                        <img
                                            src="<?php
                                            echo h(
                                                $rutaImagenHabitacion
                                            );
                                            ?>"
                                            alt="Habitación <?php echo h($habitacion["numero"]); ?>"
                                            class="modal-habitacion-imagen"
                                            onerror="this.onerror=null; this.src='img/hotel.jpg';"
                                        >

                                        <div class="modal-habitacion-contenido">

                                            <div class="modal-habitacion-etiqueta">
                                                DETALLES DE ALOJAMIENTO
                                            </div>

                                            <h3 class="modal-habitacion-tipo">

                                                <?php
                                                echo h(
                                                    $habitacion[
                                                        "tipo"
                                                    ]
                                                );
                                                ?>

                                            </h3>

                                            <div class="modal-habitacion-datos">

                                                <div class="modal-habitacion-dato">

                                                    <small>
                                                        Capacidad
                                                    </small>

                                                    <strong>
                                                        <i class="bi bi-people me-1"></i>

                                                        <?php
                                                        echo (int)
                                                            $habitacion[
                                                                "capacidad"
                                                            ];
                                                        ?>

                                                        persona(s)
                                                    </strong>

                                                </div>

                                                <div class="modal-habitacion-dato modal-habitacion-precio">

                                                    <small>
                                                        Precio por noche
                                                    </small>

                                                    <strong>
                                                        $<?php
                                                        echo number_format(
                                                            (float)
                                                            $habitacion[
                                                                "precio"
                                                            ],
                                                            2
                                                        );
                                                        ?>
                                                    </strong>

                                                </div>

                                            </div>

                                        </div>

                                    </div>

                                    <div class="modal-footer">

                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary"
                                            data-bs-dismiss="modal"
                                        >
                                            Cerrar
                                        </button>

                                        <a
                                            href="<?php
                                            echo h(
                                                enlaceReservaPublica(
                                                    $idHabitacion,
                                                    $esCliente,
                                                    $esPersonal
                                                )
                                            );
                                            ?>"
                                            class="btn btn-reservar-modal"
                                        >
                                            <i class="bi bi-calendar2-check me-1"></i>
                                            Reservar
                                        </a>

                                    </div>

                                </div>

                            </div>

                        </div>

                    <?php } ?>

                </div>

            <?php } else { ?>

                <div
                    class="alert alert-info text-center p-4"
                >

                    En este momento no existen
                    habitaciones disponibles.

                </div>

            <?php } ?>

        </div>

    </section>

    <section
        class="seccion seccion-verde-claro"
        id="servicios"
    >

        <div class="container">

            <div
                class="text-center mx-auto mb-5"
                style="max-width: 760px;"
            >

                <p class="seccion-etiqueta">
                    SERVICIOS PARA TU ESTADÍA
                </p>

                <h2 class="seccion-titulo">

                    Una experiencia cómoda
                    y organizada

                </h2>

                <p
                    class="seccion-texto mb-0"
                >

                    Conoce los principales servicios
                    disponibles para nuestros huéspedes.

                </p>

            </div>

            <div class="row g-4">

                <div
                    class="col-sm-6 col-lg-3"
                >

                    <div class="servicio-card">

                        <div
                            class="servicio-icono"
                        >

                            <i
                                class="bi bi-door-open"
                            ></i>

                        </div>

                        <h3>
                            Habitaciones
                        </h3>

                        <p>

                            Consulta tipos, precios,
                            capacidad e imágenes antes
                            de realizar una reserva.

                        </p>

                    </div>

                </div>

                <div
                    class="col-sm-6 col-lg-3"
                >

                    <div class="servicio-card">

                        <div
                            class="servicio-icono"
                        >

                            <i
                                class="bi bi-calendar2-check"
                            ></i>

                        </div>

                        <h3>
                            Reservas
                        </h3>

                        <p>

                            Crea una cuenta para elegir
                            fechas y registrar tu
                            reservación en línea.

                        </p>

                    </div>

                </div>

                <div
                    class="col-sm-6 col-lg-3"
                >

                    <div class="servicio-card">

                        <div
                            class="servicio-icono"
                        >

                            <i
                                class="bi bi-cup-hot"
                            ></i>

                        </div>

                        <h3>
                            Comidas
                        </h3>

                        <p>

                            Revisa desayunos, almuerzos,
                            cenas, bebidas y extras
                            disponibles.

                        </p>

                    </div>

                </div>

                <div
                    class="col-sm-6 col-lg-3"
                >

                    <div class="servicio-card">

                        <div
                            class="servicio-icono"
                        >

                            <i
                                class="bi bi-headset"
                            ></i>

                        </div>

                        <h3>
                            Atención
                        </h3>

                        <p>

                            Consulta el estado de tus
                            servicios después de iniciar
                            sesión.

                        </p>

                    </div>

                </div>

            </div>

        </div>

    </section>

    <section
        class="seccion comidas-promocion"
        id="comidas"
    >

        <div
            class="container position-relative"
            style="z-index: 2;"
        >

            <div class="mb-5">

                <p class="seccion-etiqueta">
                    SABORES DEL HOTEL
                </p>

                <h2 class="seccion-titulo">

                    Comidas preparadas para
                    acompañar tu estadía

                </h2>

                <p class="seccion-texto mb-0">

                    <?php if (!$sesionIniciada) { ?>
                        Revisa el menú disponible. Para
                        realizar un pedido debes iniciar
                        sesión o crear una cuenta.
                    <?php } elseif ($esCliente) { ?>
                        Revisa el menú disponible y realiza
                        tus pedidos directamente desde
                        tu cuenta.
                    <?php } else { ?>
                        Revisa las comidas disponibles
                        actualmente para los huéspedes.
                    <?php } ?>

                </p>

            </div>

            <?php if (
                $errorComidas !== ""
            ) { ?>

                <div class="alert alert-danger">

                    <?php
                    echo h($errorComidas);
                    ?>

                </div>

            <?php } ?>

            <?php if (
                count($comidasDisponibles) > 0
            ) { ?>

                <div class="row g-3">

                    <?php foreach (
                        $comidasDisponibles as $comida
                    ) { ?>

                        <?php

                        $rutaImagenComida =
                            resolverImagen(
                                $comida[
                                    "imagen"
                                ] ?? "",
                                "img/hotel.jpg"
                            );

                        ?>

                        <div
                            class="col-md-6 col-lg-4"
                        >

                            <article
                                class="comida-mini-card"
                            >

                                <img
                                    src="<?php
                                    echo h(
                                        $rutaImagenComida
                                    );
                                    ?>"
                                    alt="<?php
                                    echo h(
                                        $comida[
                                            "nombre"
                                        ]
                                    );
                                    ?>"
                                    loading="lazy"
                                    onerror="this.onerror=null; this.src='img/hotel.jpg';"
                                >

                                <div
                                    class="comida-mini-card-contenido"
                                >

                                    <div
                                        class="comida-mini-tipo"
                                    >

                                        <?php
                                        echo h(
                                            $comida[
                                                "tipo"
                                            ]
                                        );
                                        ?>

                                    </div>

                                    <h3>

                                        <?php
                                        echo h(
                                            $comida[
                                                "nombre"
                                            ]
                                        );
                                        ?>

                                    </h3>

                                    <?php if (
                                        trim(
                                            (string)
                                            $comida[
                                                "descripcion"
                                            ]
                                        ) !== ""
                                    ) { ?>

                                        <p
                                            class="small text-secondary"
                                        >

                                            <?php
                                            echo h(
                                                $comida[
                                                    "descripcion"
                                                ]
                                            );
                                            ?>

                                        </p>

                                    <?php } ?>

                                    <div
                                        class="d-flex justify-content-between align-items-center gap-2"
                                    >

                                        <div
                                            class="comida-mini-precio"
                                        >

                                            $<?php
                                            echo number_format(
                                                (float)
                                                $comida[
                                                    "precio"
                                                ],
                                                2
                                            );
                                            ?>

                                        </div>

                                        <a
                                            href="<?php
                                            echo h(
                                                enlaceComidasPublico(
                                                    $esCliente,
                                                    $esPersonal
                                                )
                                            );
                                            ?>"
                                            class="btn-pedir-profesional"
                                        >
                                            <i class="bi bi-bag-check"></i>
                                            Pedir
                                        </a>

                                    </div>

                                </div>

                            </article>

                        </div>

                    <?php } ?>

                </div>

            <?php } else { ?>

                <div
                    class="p-4 rounded bg-white text-dark"
                >

                    El menú estará disponible
                    próximamente.

                </div>

            <?php } ?>

        </div>

    </section>

    <section
        class="seccion seccion-blanca"
        id="como-reservar"
    >

        <div class="container">

            <div
                class="text-center mx-auto mb-5"
                style="max-width: 730px;"
            >

                <p class="seccion-etiqueta">
                    PROCESO SENCILLO
                </p>

                <h2 class="seccion-titulo">

                    Reserva tu habitación
                    en pocos pasos

                </h2>

                <p
                    class="seccion-texto mb-0"
                >

                    Puedes revisar las opciones sin
                    registrarte y crear tu cuenta
                    cuando estés listo para reservar.

                </p>

            </div>

            <div class="row g-4">

                <div
                    class="col-md-6 col-lg-3"
                >

                    <div class="paso-card">

                        <div class="paso-numero">
                            1
                        </div>

                        <h3>
                            Revisa
                        </h3>

                        <p>

                            Consulta habitaciones,
                            capacidad y precios.

                        </p>

                    </div>

                </div>

                <div
                    class="col-md-6 col-lg-3"
                >

                    <div class="paso-card">

                        <div class="paso-numero">
                            2
                        </div>

                        <h3>
                            Crea tu cuenta
                        </h3>

                        <p>

                            Registra tus datos para
                            acceder al sistema.

                        </p>

                    </div>

                </div>

                <div
                    class="col-md-6 col-lg-3"
                >

                    <div class="paso-card">

                        <div class="paso-numero">
                            3
                        </div>

                        <h3>
                            Reserva
                        </h3>

                        <p>

                            Selecciona la habitación y
                            las fechas de tu estadía.

                        </p>

                    </div>

                </div>

                <div
                    class="col-md-6 col-lg-3"
                >

                    <div class="paso-card">

                        <div class="paso-numero">
                            4
                        </div>

                        <h3>
                            Registra el pago
                        </h3>

                        <p>

                            Elige el método y consulta
                            posteriormente su estado.

                        </p>

                    </div>

                </div>

            </div>

            <?php if (!$sesionIniciada) { ?>

                <div class="text-center mt-5">

                    <a
                        href="registro.php"
                        class="btn-hotel-principal"
                    >

                        <i
                            class="bi bi-person-plus"
                        ></i>

                        Crear cuenta para reservar

                    </a>

                </div>

            <?php } ?>

        </div>

    </section>

</main>

<footer class="footer-hotel">

    <div class="footer-principal">

        <div class="container">

            <div class="row g-5">

                <div class="col-lg-6">

                    <div
                        class="d-flex align-items-center gap-3 footer-marca"
                    >

                        <img
                            src="img/logo.png"
                            alt="Hotel Las 3 Palmeras"
                            class="footer-logo"
                        >

                        <div>

                            <h2>
                                Hotel Las 3 Palmeras
                            </h2>

                            <p class="mb-0">

                                Comodidad y tranquilidad
                                para nuestros huéspedes.

                            </p>

                        </div>

                    </div>

                </div>

                <div class="col-sm-6 col-lg-3">

                    <h3 class="footer-titulo">
                        Servicios
                    </h3>

                    <ul class="footer-lista">

                        <li>

                            <a href="#habitaciones">
                                Habitaciones
                            </a>

                        </li>

                        <li>

                            <a href="#comidas">
                                Comidas
                            </a>

                        </li>

                        <li>

                            <a href="#como-reservar">
                                Reservas
                            </a>

                        </li>

                    </ul>

                </div>

                <div class="col-sm-6 col-lg-3">

                    <h3 class="footer-titulo">
                        Reservaciones
                    </h3>

                    <p class="mb-3">

                        <?php if (!$sesionIniciada) { ?>
                            Crea tu cuenta para reservar
                            una habitación y consultar
                            tus servicios.
                        <?php } elseif ($esCliente) { ?>
                            Consulta tus reservas, pagos
                            y servicios desde tu cuenta.
                        <?php } else { ?>
                            Continúa con la gestión del hotel
                            desde el panel administrativo.
                        <?php } ?>

                    </p>

                    <a
                        href="<?php echo h(
                            !$sesionIniciada
                                ? "registro.php"
                                : ($esCliente
                                    ? "cliente/index.php"
                                    : "dashboard.php")
                        ); ?>"
                        class="btn btn-outline-light btn-sm"
                    >
                        <?php
                        echo !$sesionIniciada
                            ? "Registrarme"
                            : ($esCliente
                                ? "Ir a mi cuenta"
                                : "Ir al panel");
                        ?>
                    </a>

                </div>

            </div>

        </div>

    </div>

    <div class="footer-final">

        <div
            class="container d-flex flex-wrap justify-content-between gap-2"
        >

            <span>
                Hotel Las 3 Palmeras © 2026
            </span>

            <span>
                Sistema de reservas y servicios hoteleros
            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>

    const barraNavegacion =
        document.querySelector(
            ".navbar-hotel"
        );

    function actualizarBarra() {

        if (window.scrollY > 30) {

            barraNavegacion.classList.add(
                "navbar-con-sombra"
            );

        } else {

            barraNavegacion.classList.remove(
                "navbar-con-sombra"
            );
        }
    }

    actualizarBarra();

    window.addEventListener(
        "scroll",
        actualizarBarra
    );

    document
        .querySelectorAll(
            "#menuPublico a[href^='#']"
        )
        .forEach((enlace) => {

            enlace.addEventListener(
                "click",
                () => {

                    const menu =
                        document.getElementById(
                            "menuPublico"
                        );

                    const instancia =
                        bootstrap.Collapse
                            .getInstance(menu);

                    if (instancia) {
                        instancia.hide();
                    }
                }
            );
        });

</script>




</body>

</html>