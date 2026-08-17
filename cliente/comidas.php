<?php

session_start();

include("../config/conexion.php");

/** @var mysqli $conn */

/*
Comprobar que el usuario inició sesión.
*/
if (!isset($_SESSION['usuario'])) {
    header("Location: ../login.php");
    exit();
}

/*
Esta página es solamente para clientes.
*/
$rolActual = strtolower(
    trim($_SESSION['rol'] ?? "")
);

if ($rolActual !== "cliente") {
    header("Location: ../dashboard.php");
    exit();
}

/*
Mostrar textos de manera segura.
*/
function h($texto)
{
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}

$errorConsulta = "";

/*
Consultar solamente comidas disponibles.
*/
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
     ORDER BY
        FIELD(
            tipo,
            'Desayuno',
            'Almuerzo',
            'Cena',
            'Bebida',
            'Extra'
        ),
        nombre ASC"
);

if (!$consultaComidas) {
    $errorConsulta =
        "No se pudieron cargar las comidas disponibles.";
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
        Comidas disponibles - Hotel Las 3 Palmeras
    </title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../css/style.css?v=17"
    >

    <style>

        body {
            background-color: #f6f6f2;
        }

        .navbar-logo {
            width: 50px;
            height: 50px;
            object-fit: contain;
        }

        .contenido-comidas {
            padding-top: 110px;
            padding-bottom: 70px;
        }

        .encabezado-comidas {
            background:
                linear-gradient(
                    rgba(31, 72, 48, 0.88),
                    rgba(31, 72, 48, 0.88)
                ),
                url("../img/hotel.jpg");

            background-size: cover;
            background-position: center;
            border-radius: 22px;
            color: white;
            padding: 50px 35px;
            margin-bottom: 35px;
        }

        .filtro-btn {
            border-radius: 30px;
        }

        .comida-card {
            border: none;
            border-radius: 20px;
            overflow: hidden;
            transition:
                transform 0.2s ease,
                box-shadow 0.2s ease;
        }

        .comida-card:hover {
            transform: translateY(-5px);

            box-shadow:
                0 12px 30px
                rgba(0, 0, 0, 0.15);
        }

        .comida-imagen {
            width: 100%;
            height: 240px;
            object-fit: cover;
        }

        .comida-tipo {
            color: #2f5d42;
            font-size: 13px;
            font-weight: bold;
            letter-spacing: 1px;
        }

        .comida-precio {
            color: #2f5d42;
            font-size: 27px;
            font-weight: bold;
        }

        .estado-disponible {
            background-color: #dff5e6;
            color: #23683a;
            border-radius: 25px;
            padding: 6px 12px;
            font-size: 13px;
            font-weight: bold;
        }

        .btn-hotel {
            background-color: #2f5d42;
            color: white;
            border: none;
        }

        .btn-hotel:hover {
            background-color: #234832;
            color: white;
        }

        .sin-comidas {
            border: none;
            border-radius: 18px;
        }

    </style>

</head>

<body>

<nav
    class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top"
>

    <div class="container-fluid">

        <a
            href="index.php"
            class="navbar-brand p-0 me-3"
        >

            <img
                src="../img/logo.png"
                alt="Hotel Las 3 Palmeras"
                class="navbar-logo"
            >

        </a>

        <button
            class="navbar-toggler"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#menuCliente"
            aria-controls="menuCliente"
            aria-expanded="false"
            aria-label="Abrir menú"
        >

            <span class="navbar-toggler-icon"></span>

        </button>

        <div
            class="collapse navbar-collapse"
            id="menuCliente"
        >

            <ul
                class="navbar-nav me-auto mb-2 mb-lg-0"
            >

                <li class="nav-item">

                    <a
                        href="index.php"
                        class="nav-link"
                    >
                        Inicio
                    </a>

                </li>

                <li class="nav-item">

                    <a
                        href="comidas.php"
                        class="nav-link active"
                    >
                        Comidas
                    </a>

                </li>

                <li class="nav-item">

                    <a
                        href="mis_reservas.php"
                        class="nav-link"
                    >
                        Mis reservas
                    </a>

                </li>

                <li class="nav-item">

                    <a
                        href="perfil.php"
                        class="nav-link"
                    >
                        Mi perfil
                    </a>

                </li>

            </ul>

            <div
                class="d-flex flex-wrap align-items-center gap-3"
            >

                <span class="text-white">

                    Bienvenido:

                    <?php
                    echo h(
                        $_SESSION['usuario']
                    );
                    ?>

                </span>

                <a
                    href="../logout.php"
                    class="btn btn-danger btn-sm"
                >
                    Cerrar sesión
                </a>

            </div>

        </div>

    </div>

</nav>

<main class="container contenido-comidas">

    <section class="encabezado-comidas">

        <p class="fw-bold mb-2">
            HOTEL LAS 3 PALMERAS
        </p>

        <h1 class="display-6 fw-bold">
            Comidas disponibles
        </h1>

        <p class="mb-0">

            Conoce los desayunos, almuerzos,
            cenas, bebidas y extras disponibles
            durante tu estadía.

        </p>

    </section>

    <?php if (
        isset($_GET['mensaje']) &&
        $_GET['mensaje'] === "no_disponible"
    ) { ?>

        <div class="alert alert-warning">

            La comida seleccionada ya no se encuentra
            disponible.

        </div>

    <?php } ?>

    <?php if ($errorConsulta !== "") { ?>

        <div class="alert alert-danger">

            <?php echo h($errorConsulta); ?>

        </div>

    <?php } ?>

    <section class="mb-4">

        <div class="d-flex flex-wrap gap-2">

            <button
                type="button"
                class="btn btn-success filtro-btn"
                data-filtro="Todos"
            >
                Todos
            </button>

            <button
                type="button"
                class="btn btn-outline-success filtro-btn"
                data-filtro="Desayuno"
            >
                Desayunos
            </button>

            <button
                type="button"
                class="btn btn-outline-success filtro-btn"
                data-filtro="Almuerzo"
            >
                Almuerzos
            </button>

            <button
                type="button"
                class="btn btn-outline-success filtro-btn"
                data-filtro="Cena"
            >
                Cenas
            </button>

            <button
                type="button"
                class="btn btn-outline-success filtro-btn"
                data-filtro="Bebida"
            >
                Bebidas
            </button>

            <button
                type="button"
                class="btn btn-outline-success filtro-btn"
                data-filtro="Extra"
            >
                Extras
            </button>

        </div>

    </section>

    <?php if (
        $consultaComidas &&
        mysqli_num_rows($consultaComidas) > 0
    ) { ?>

        <section
            class="row g-4"
            id="contenedorComidas"
        >

            <?php while (
                $comida =
                    mysqli_fetch_assoc(
                        $consultaComidas
                    )
            ) { ?>

                <?php

                /*
                Preparar la imagen.
                */
                $imagenGuardada = trim(
                    (string) (
                        $comida['imagen'] ?? ""
                    )
                );

                $rutaImagen =
                    "../img/hotel.jpg";

                if (
                    $imagenGuardada !== "" &&
                    preg_match(
                        '/^https?:\/\//i',
                        $imagenGuardada
                    )
                ) {

                    $rutaImagen =
                        $imagenGuardada;
                }

                ?>

                <div
                    class="col-md-6 col-lg-4 comida-item"
                    data-tipo="<?php
                    echo h($comida['tipo']);
                    ?>"
                >

                    <article
                        class="card comida-card shadow-sm h-100"
                    >

                        <img
                            src="<?php
                            echo h($rutaImagen);
                            ?>"
                            alt="<?php
                            echo h($comida['nombre']);
                            ?>"
                            class="comida-imagen"
                            loading="lazy"
                            onerror="this.onerror=null; this.src='../img/hotel.jpg';"
                        >

                        <div class="card-body p-4">

                            <div
                                class="d-flex justify-content-between align-items-start gap-2 mb-2"
                            >

                                <div>

                                    <div class="comida-tipo">

                                        <?php
                                        echo h(
                                            strtoupper(
                                                $comida['tipo']
                                            )
                                        );
                                        ?>

                                    </div>

                                    <h2 class="h4 mt-1 mb-0">

                                        <?php
                                        echo h(
                                            $comida['nombre']
                                        );
                                        ?>

                                    </h2>

                                </div>

                                <span
                                    class="estado-disponible"
                                >
                                    Disponible
                                </span>

                            </div>

                            <p class="text-muted mt-3">

                                <?php

                                if (
                                    trim(
                                        (string)
                                        $comida['descripcion']
                                    ) !== ""
                                ) {

                                    echo h(
                                        $comida['descripcion']
                                    );

                                } else {

                                    echo
                                        "Sin descripción disponible.";
                                }

                                ?>

                            </p>

                        </div>

                        <div
                            class="card-footer bg-white border-0 px-4 pb-4"
                        >

                            <div
                                class="d-flex justify-content-between align-items-end gap-3"
                            >

                                <div>

                                    <small
                                        class="text-muted d-block"
                                    >
                                        Precio
                                    </small>

                                    <div class="comida-precio">

                                        $<?php
                                        echo number_format(
                                            (float)
                                            $comida['precio'],
                                            2
                                        );
                                        ?>

                                    </div>

                                </div>

                                <a
                                    href="pedir_comida.php?id=<?php
                                    echo (int)
                                        $comida['id_comida'];
                                    ?>"
                                    class="btn btn-hotel"
                                >
                                    Pedir comida
                                </a>

                            </div>

                        </div>

                    </article>

                </div>

            <?php } ?>

        </section>

        <div
            id="mensajeSinResultados"
            class="alert alert-info text-center mt-4 d-none"
        >

            No existen comidas disponibles
            en esta categoría.

        </div>

    <?php } else { ?>

        <div class="card sin-comidas shadow-sm">

            <div class="card-body text-center p-5">

                <h2 class="h4">
                    No existen comidas disponibles
                </h2>

                <p class="text-muted mb-0">

                    El hotel todavía no ha publicado
                    alimentos o bebidas disponibles.

                </p>

            </div>

        </div>

    <?php } ?>

</main>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>

const botonesFiltro =
    document.querySelectorAll(
        "[data-filtro]"
    );

const comidas =
    document.querySelectorAll(
        ".comida-item"
    );

const mensajeSinResultados =
    document.getElementById(
        "mensajeSinResultados"
    );

botonesFiltro.forEach(
    function (boton) {

        boton.addEventListener(
            "click",
            function () {

                const filtro =
                    boton.dataset.filtro;

                let cantidadVisible = 0;

                /*
                Cambiar el diseño del botón activo.
                */
                botonesFiltro.forEach(
                    function (otroBoton) {

                        otroBoton.classList.remove(
                            "btn-success"
                        );

                        otroBoton.classList.add(
                            "btn-outline-success"
                        );
                    }
                );

                boton.classList.remove(
                    "btn-outline-success"
                );

                boton.classList.add(
                    "btn-success"
                );

                /*
                Mostrar solamente la categoría elegida.
                */
                comidas.forEach(
                    function (comida) {

                        const tipo =
                            comida.dataset.tipo;

                        const mostrar =
                            filtro === "Todos" ||
                            tipo === filtro;

                        if (mostrar) {

                            comida.classList.remove(
                                "d-none"
                            );

                            cantidadVisible++;

                        } else {

                            comida.classList.add(
                                "d-none"
                            );
                        }
                    }
                );

                /*
                Mostrar mensaje cuando no existan
                resultados en una categoría.
                */
                if (mensajeSinResultados) {

                    if (cantidadVisible === 0) {

                        mensajeSinResultados
                            .classList
                            .remove("d-none");

                    } else {

                        mensajeSinResultados
                            .classList
                            .add("d-none");
                    }
                }
            }
        );
    }
);

</script>

</body>

</html>