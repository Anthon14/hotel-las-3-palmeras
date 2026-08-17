<?php
session_start();

include("../config/conexion.php");

if (!isset($_SESSION["usuario"], $_SESSION["rol"])) {
    header("Location: ../login.php");
    exit();
}

$rolActual = strtolower(trim((string) $_SESSION["rol"]));

if ($rolActual !== "cliente") {
    header("Location: ../dashboard.php");
    exit();
}

function h($texto)
{
    return htmlspecialchars(
        (string) $texto,
        ENT_QUOTES,
        "UTF-8"
    );
}

function claveActualValida($claveIngresada, $claveGuardada)
{
    $claveIngresada = (string) $claveIngresada;
    $claveGuardada = (string) $claveGuardada;

    if ($claveGuardada === "") {
        return false;
    }

    if (password_verify($claveIngresada, $claveGuardada)) {
        return true;
    }

    return hash_equals($claveGuardada, $claveIngresada);
}

$idUsuario = (int) ($_SESSION["id_usuario"] ?? 0);

if ($idUsuario <= 0) {
    $usuarioSesion =
        trim((string) $_SESSION["usuario"]);

    $buscarUsuario = mysqli_prepare(
        $conn,
        "SELECT id_usuario
         FROM usuarios
         WHERE usuario = ?
           AND LOWER(rol) = 'cliente'
         LIMIT 1"
    );

    if ($buscarUsuario) {
        mysqli_stmt_bind_param(
            $buscarUsuario,
            "s",
            $usuarioSesion
        );

        mysqli_stmt_execute($buscarUsuario);

        $resultadoUsuario =
            mysqli_stmt_get_result($buscarUsuario);

        $filaUsuario =
            mysqli_fetch_assoc($resultadoUsuario);

        mysqli_stmt_close($buscarUsuario);

        if ($filaUsuario) {
            $idUsuario =
                (int) $filaUsuario["id_usuario"];

            $_SESSION["id_usuario"] =
                $idUsuario;
        }
    }
}

if ($idUsuario <= 0) {
    session_unset();
    session_destroy();

    header(
        "Location: ../login.php?mensaje=sesion_invalida"
    );
    exit();
}

$consultaCuenta = mysqli_prepare(
    $conn,
    "SELECT
        usuario,
        password,
        rol
     FROM usuarios
     WHERE id_usuario = ?
       AND LOWER(rol) = 'cliente'
     LIMIT 1"
);

if (!$consultaCuenta) {
    header(
        "Location: index.php?mensaje=error_cuenta"
    );
    exit();
}

mysqli_stmt_bind_param(
    $consultaCuenta,
    "i",
    $idUsuario
);

mysqli_stmt_execute($consultaCuenta);

$resultadoCuenta =
    mysqli_stmt_get_result($consultaCuenta);

$cuenta =
    mysqli_fetch_assoc($resultadoCuenta);

mysqli_stmt_close($consultaCuenta);

if (!$cuenta) {
    session_unset();
    session_destroy();

    header(
        "Location: ../login.php?mensaje=sesion_invalida"
    );
    exit();
}

$usuarioActual =
    (string) $cuenta["usuario"];

$usuarioFormulario =
    $usuarioActual;

$consultaPerfil = mysqli_prepare(
    $conn,
    "SELECT
        id_cliente,
        nombres,
        apellidos,
        cedula,
        correo,
        telefono
     FROM clientes
     WHERE id_usuario = ?
     LIMIT 1"
);

if (!$consultaPerfil) {
    header(
        "Location: index.php?mensaje=error_perfil"
    );
    exit();
}

mysqli_stmt_bind_param(
    $consultaPerfil,
    "i",
    $idUsuario
);

mysqli_stmt_execute($consultaPerfil);

$resultadoPerfil =
    mysqli_stmt_get_result($consultaPerfil);

$perfil =
    mysqli_fetch_assoc($resultadoPerfil);

mysqli_stmt_close($consultaPerfil);

if (!$perfil) {
    session_unset();
    session_destroy();

    header(
        "Location: ../login.php?mensaje=cuenta_no_vinculada"
    );
    exit();
}

$idCliente =
    (int) $perfil["id_cliente"];

$nombres =
    (string) $perfil["nombres"];

$apellidos =
    (string) $perfil["apellidos"];

$cedula =
    (string) $perfil["cedula"];

$correo =
    (string) $perfil["correo"];

$telefono =
    (string) $perfil["telefono"];

/* Notificaciones */
$notificacionesPago = [];

$consultaNotificaciones = mysqli_prepare(
    $conn,
    "SELECT
        p.id_pago,
        p.id_reserva,
        p.estado_pago,
        p.observacion,
        p.monto,
        h.numero AS numero_habitacion
     FROM pagos p
     INNER JOIN reservas r
        ON r.id_reserva = p.id_reserva
     INNER JOIN habitaciones h
        ON h.id_habitacion = r.id_habitacion
     WHERE r.id_cliente = ?
       AND p.estado_pago IN ('Aceptado', 'Rechazado')
     ORDER BY p.id_pago DESC
     LIMIT 8"
);

if ($consultaNotificaciones) {
    mysqli_stmt_bind_param(
        $consultaNotificaciones,
        "i",
        $idCliente
    );

    if (mysqli_stmt_execute($consultaNotificaciones)) {
        $resultadoNotificaciones =
            mysqli_stmt_get_result($consultaNotificaciones);

        while (
            $notificacion =
                mysqli_fetch_assoc($resultadoNotificaciones)
        ) {
            $notificacionesPago[] = $notificacion;
        }
    }

    mysqli_stmt_close($consultaNotificaciones);
}

$errores = [];
$erroresAcceso = [];

if (empty($_SESSION["csrf_perfil"])) {
    $_SESSION["csrf_perfil"] =
        bin2hex(random_bytes(32));
}

if (empty($_SESSION["csrf_acceso"])) {
    $_SESSION["csrf_acceso"] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_perfil"];
$csrfAcceso = $_SESSION["csrf_acceso"];

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["guardar_perfil"])
) {
    $csrfRecibido =
        $_POST["csrf"] ?? "";

    $nombres =
        trim((string) ($_POST["nombres"] ?? ""));

    $apellidos =
        trim((string) ($_POST["apellidos"] ?? ""));

    $cedula =
        preg_replace(
            "/\D/",
            "",
            (string) ($_POST["cedula"] ?? "")
        );

    $correo =
        strtolower(
            trim((string) ($_POST["correo"] ?? ""))
        );

    $telefono =
        preg_replace(
            "/\D/",
            "",
            (string) ($_POST["telefono"] ?? "")
        );

    if (
        !is_string($csrfRecibido) ||
        !hash_equals($csrf, $csrfRecibido)
    ) {
        $errores[] =
            "La solicitud no es válida. Actualiza la página.";
    }

    if ($nombres === "") {
        $errores[] =
            "Los nombres son obligatorios.";
    } elseif (mb_strlen($nombres) > 100) {
        $errores[] =
            "Los nombres no pueden superar los 100 caracteres.";
    }

    if ($apellidos === "") {
        $errores[] =
            "Los apellidos son obligatorios.";
    } elseif (mb_strlen($apellidos) > 100) {
        $errores[] =
            "Los apellidos no pueden superar los 100 caracteres.";
    }

    if (!preg_match("/^[0-9]{10}$/", $cedula)) {
        $errores[] =
            "La cédula debe contener exactamente 10 números.";
    }

    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $errores[] =
            "Ingrese un correo válido.";
    } elseif (mb_strlen($correo) > 120) {
        $errores[] =
            "El correo no puede superar los 120 caracteres.";
    }

    if (!preg_match("/^[0-9]{10}$/", $telefono)) {
        $errores[] =
            "El teléfono debe contener exactamente 10 números.";
    }

    if (empty($errores)) {
        $verificarCedula = mysqli_prepare(
            $conn,
            "SELECT id_cliente
             FROM clientes
             WHERE cedula = ?
               AND id_cliente != ?
             LIMIT 1"
        );

        if (!$verificarCedula) {
            $errores[] =
                "No se pudo comprobar la cédula.";
        } else {
            mysqli_stmt_bind_param(
                $verificarCedula,
                "si",
                $cedula,
                $idCliente
            );

            mysqli_stmt_execute($verificarCedula);

            $resultadoCedula =
                mysqli_stmt_get_result($verificarCedula);

            if (mysqli_num_rows($resultadoCedula) > 0) {
                $errores[] =
                    "La cédula ya pertenece a otro cliente.";
            }

            mysqli_stmt_close($verificarCedula);
        }
    }

    if (empty($errores)) {
        $verificarCorreo = mysqli_prepare(
            $conn,
            "SELECT id_cliente
             FROM clientes
             WHERE LOWER(correo) = LOWER(?)
               AND id_cliente != ?
             LIMIT 1"
        );

        if (!$verificarCorreo) {
            $errores[] =
                "No se pudo comprobar el correo.";
        } else {
            mysqli_stmt_bind_param(
                $verificarCorreo,
                "si",
                $correo,
                $idCliente
            );

            mysqli_stmt_execute($verificarCorreo);

            $resultadoCorreo =
                mysqli_stmt_get_result($verificarCorreo);

            if (mysqli_num_rows($resultadoCorreo) > 0) {
                $errores[] =
                    "El correo ya pertenece a otro cliente.";
            }

            mysqli_stmt_close($verificarCorreo);
        }
    }

    if (empty($errores)) {
        $actualizar = mysqli_prepare(
            $conn,
            "UPDATE clientes
             SET
                nombres = ?,
                apellidos = ?,
                cedula = ?,
                correo = ?,
                telefono = ?
             WHERE id_cliente = ?
               AND id_usuario = ?"
        );

        if (!$actualizar) {
            $errores[] =
                "No se pudo preparar la actualización.";
        } else {
            mysqli_stmt_bind_param(
                $actualizar,
                "sssssii",
                $nombres,
                $apellidos,
                $cedula,
                $correo,
                $telefono,
                $idCliente,
                $idUsuario
            );

            if (mysqli_stmt_execute($actualizar)) {
                mysqli_stmt_close($actualizar);

                $_SESSION["nombre"] =
                    trim($nombres . " " . $apellidos);

                $_SESSION["csrf_perfil"] =
                    bin2hex(random_bytes(32));

                header(
                    "Location: perfil.php?mensaje=actualizado"
                );
                exit();
            }

            mysqli_stmt_close($actualizar);

            $errores[] =
                "No se pudieron actualizar tus datos.";
        }
    }
}

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["guardar_usuario"])
) {
    $csrfAccesoRecibido =
        $_POST["csrf_acceso"] ?? "";

    $usuarioFormulario =
        trim((string) ($_POST["nuevo_usuario"] ?? ""));

    $claveActualUsuario =
        (string) ($_POST["clave_actual_usuario"] ?? "");

    if (
        !is_string($csrfAccesoRecibido) ||
        !hash_equals($csrfAcceso, $csrfAccesoRecibido)
    ) {
        $erroresAcceso[] =
            "La solicitud no es válida. Actualiza la página.";
    }

    if ($usuarioFormulario === "") {
        $erroresAcceso[] =
            "El nuevo nombre de usuario es obligatorio.";
    } elseif (
        mb_strlen($usuarioFormulario) < 4 ||
        mb_strlen($usuarioFormulario) > 50
    ) {
        $erroresAcceso[] =
            "El usuario debe tener entre 4 y 50 caracteres.";
    } elseif (
        !preg_match(
            "/^[A-Za-z0-9._-]+$/",
            $usuarioFormulario
        )
    ) {
        $erroresAcceso[] =
            "El usuario solo puede contener letras, números, punto, guion y guion bajo.";
    }

    if ($claveActualUsuario === "") {
        $erroresAcceso[] =
            "Escribe tu contraseña actual para confirmar el cambio de usuario.";
    }

    if (
        empty($erroresAcceso) &&
        strcasecmp($usuarioFormulario, $usuarioActual) === 0
    ) {
        $erroresAcceso[] =
            "Escribe un nombre de usuario diferente al actual.";
    }

    if (empty($erroresAcceso)) {
        mysqli_begin_transaction($conn);

        try {
            $bloquearCuenta = mysqli_prepare(
                $conn,
                "SELECT usuario, password
                 FROM usuarios
                 WHERE id_usuario = ?
                   AND LOWER(rol) = 'cliente'
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$bloquearCuenta) {
                throw new Exception(
                    "No se pudo validar la cuenta."
                );
            }

            mysqli_stmt_bind_param(
                $bloquearCuenta,
                "i",
                $idUsuario
            );

            mysqli_stmt_execute($bloquearCuenta);

            $resultadoCuentaBloqueada =
                mysqli_stmt_get_result($bloquearCuenta);

            $cuentaBloqueada =
                mysqli_fetch_assoc($resultadoCuentaBloqueada);

            mysqli_stmt_close($bloquearCuenta);

            if (!$cuentaBloqueada) {
                throw new Exception(
                    "La cuenta ya no está disponible."
                );
            }

            if (
                !claveActualValida(
                    $claveActualUsuario,
                    $cuentaBloqueada["password"]
                )
            ) {
                throw new Exception(
                    "La contraseña actual es incorrecta."
                );
            }

            $verificarUsuario = mysqli_prepare(
                $conn,
                "SELECT id_usuario
                 FROM usuarios
                 WHERE LOWER(usuario) = LOWER(?)
                   AND id_usuario != ?
                 LIMIT 1"
            );

            if (!$verificarUsuario) {
                throw new Exception(
                    "No se pudo comprobar el nombre de usuario."
                );
            }

            mysqli_stmt_bind_param(
                $verificarUsuario,
                "si",
                $usuarioFormulario,
                $idUsuario
            );

            mysqli_stmt_execute($verificarUsuario);

            $resultadoUsuarioOcupado =
                mysqli_stmt_get_result($verificarUsuario);

            $usuarioOcupado =
                mysqli_fetch_assoc($resultadoUsuarioOcupado);

            mysqli_stmt_close($verificarUsuario);

            if ($usuarioOcupado) {
                throw new Exception(
                    "Ese nombre de usuario ya existe. Prueba con otro diferente."
                );
            }

            $actualizarUsuario = mysqli_prepare(
                $conn,
                "UPDATE usuarios
                 SET usuario = ?
                 WHERE id_usuario = ?
                   AND LOWER(rol) = 'cliente'"
            );

            if (!$actualizarUsuario) {
                throw new Exception(
                    "No se pudo preparar el cambio de usuario."
                );
            }

            mysqli_stmt_bind_param(
                $actualizarUsuario,
                "si",
                $usuarioFormulario,
                $idUsuario
            );

            if (!mysqli_stmt_execute($actualizarUsuario)) {
                $codigoError =
                    mysqli_stmt_errno($actualizarUsuario);

                mysqli_stmt_close($actualizarUsuario);

                if ($codigoError === 1062) {
                    throw new Exception(
                        "Ese nombre de usuario ya existe. Prueba con otro diferente."
                    );
                }

                throw new Exception(
                    "No se pudo cambiar el nombre de usuario."
                );
            }

            $filasActualizadas =
                mysqli_stmt_affected_rows($actualizarUsuario);

            mysqli_stmt_close($actualizarUsuario);

            if ($filasActualizadas !== 1) {
                throw new Exception(
                    "No se realizó el cambio de usuario."
                );
            }

            mysqli_commit($conn);

            $_SESSION["usuario"] =
                $usuarioFormulario;

            $_SESSION["csrf_acceso"] =
                bin2hex(random_bytes(32));

            header(
                "Location: perfil.php?mensaje=usuario_actualizado"
            );
            exit();
        } catch (Throwable $excepcion) {
            mysqli_rollback($conn);

            $erroresAcceso[] =
                $excepcion->getMessage() !== ""
                    ? $excepcion->getMessage()
                    : "No se pudo cambiar el nombre de usuario.";
        }
    }
}

if (
    $_SERVER["REQUEST_METHOD"] === "POST" &&
    isset($_POST["guardar_clave"])
) {
    $csrfAccesoRecibido =
        $_POST["csrf_acceso"] ?? "";

    $claveActualPassword =
        (string) ($_POST["clave_actual_password"] ?? "");

    $claveNueva =
        (string) ($_POST["clave_nueva"] ?? "");

    $confirmarClave =
        (string) ($_POST["confirmar_clave"] ?? "");

    if (
        !is_string($csrfAccesoRecibido) ||
        !hash_equals($csrfAcceso, $csrfAccesoRecibido)
    ) {
        $erroresAcceso[] =
            "La solicitud no es válida. Actualiza la página.";
    }

    if ($claveActualPassword === "") {
        $erroresAcceso[] =
            "Escribe tu contraseña actual.";
    }

    if ($claveNueva === "") {
        $erroresAcceso[] =
            "Escribe la contraseña nueva.";
    } elseif (strlen($claveNueva) < 8) {
        $erroresAcceso[] =
            "La contraseña nueva debe tener al menos 8 caracteres.";
    }

    if ($confirmarClave === "") {
        $erroresAcceso[] =
            "Confirma la contraseña nueva.";
    } elseif ($claveNueva !== $confirmarClave) {
        $erroresAcceso[] =
            "La confirmación de la contraseña nueva no coincide.";
    }

    if (empty($erroresAcceso)) {
        mysqli_begin_transaction($conn);

        try {
            $bloquearCuenta = mysqli_prepare(
                $conn,
                "SELECT password
                 FROM usuarios
                 WHERE id_usuario = ?
                   AND LOWER(rol) = 'cliente'
                 LIMIT 1
                 FOR UPDATE"
            );

            if (!$bloquearCuenta) {
                throw new Exception(
                    "No se pudo validar la cuenta."
                );
            }

            mysqli_stmt_bind_param(
                $bloquearCuenta,
                "i",
                $idUsuario
            );

            mysqli_stmt_execute($bloquearCuenta);

            $resultadoCuentaBloqueada =
                mysqli_stmt_get_result($bloquearCuenta);

            $cuentaBloqueada =
                mysqli_fetch_assoc($resultadoCuentaBloqueada);

            mysqli_stmt_close($bloquearCuenta);

            if (!$cuentaBloqueada) {
                throw new Exception(
                    "La cuenta ya no está disponible."
                );
            }

            if (
                !claveActualValida(
                    $claveActualPassword,
                    $cuentaBloqueada["password"]
                )
            ) {
                throw new Exception(
                    "La contraseña actual es incorrecta."
                );
            }

            if (
                claveActualValida(
                    $claveNueva,
                    $cuentaBloqueada["password"]
                )
            ) {
                throw new Exception(
                    "La contraseña nueva debe ser diferente de la actual."
                );
            }

            $claveHash =
                password_hash(
                    $claveNueva,
                    PASSWORD_DEFAULT
                );

            if ($claveHash === false) {
                throw new Exception(
                    "No se pudo proteger la contraseña nueva."
                );
            }

            $actualizarClave = mysqli_prepare(
                $conn,
                "UPDATE usuarios
                 SET password = ?
                 WHERE id_usuario = ?
                   AND LOWER(rol) = 'cliente'"
            );

            if (!$actualizarClave) {
                throw new Exception(
                    "No se pudo preparar el cambio de contraseña."
                );
            }

            mysqli_stmt_bind_param(
                $actualizarClave,
                "si",
                $claveHash,
                $idUsuario
            );

            if (!mysqli_stmt_execute($actualizarClave)) {
                mysqli_stmt_close($actualizarClave);

                throw new Exception(
                    "No se pudo cambiar la contraseña."
                );
            }

            mysqli_stmt_close($actualizarClave);

            mysqli_commit($conn);

            $_SESSION["csrf_acceso"] =
                bin2hex(random_bytes(32));

            header(
                "Location: perfil.php?mensaje=clave_actualizada"
            );
            exit();
        } catch (Throwable $excepcion) {
            mysqli_rollback($conn);

            $erroresAcceso[] =
                $excepcion->getMessage() !== ""
                    ? $excepcion->getMessage()
                    : "No se pudo cambiar la contraseña.";
        }
    }
}

$nombreCompleto =
    trim($nombres . " " . $apellidos);

if ($nombreCompleto === "") {
    $nombreCompleto =
        (string) $_SESSION["usuario"];
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
        Mi perfil - Hotel Las 3 Palmeras
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
        href="../css/style.css?v=59"
    >

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

        .notificaciones-cliente {
            position: relative;
        }

        .btn-notificaciones-cliente {
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

        .btn-notificaciones-cliente:hover,
        .btn-notificaciones-cliente:focus {
            border-color: rgba(240, 217, 159, .75);
            background: rgba(255, 255, 255, .15);
            color: white;
        }

        .contador-notificaciones-cliente {
            min-width: 19px;
            height: 19px;
            position: absolute;
            top: -5px;
            right: -5px;
            display: none;
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

        .menu-notificaciones-cliente {
            width: min(390px, calc(100vw - 30px));
            overflow: hidden;
            margin-top: 12px !important;
            padding: 0;
            border: 1px solid #dde2dd;
            border-radius: 12px;
            background: white;
            box-shadow: 0 18px 46px rgba(14, 35, 23, .20);
        }

        .notificaciones-cliente-cabecera {
            padding: 16px 18px;
            border-bottom: 1px solid #e8ebe7;
            background: #fbfcfa;
        }

        .notificaciones-cliente-cabecera strong {
            display: block;
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
            font-size: 17px;
        }

        .notificaciones-cliente-cabecera small {
            display: block;
            margin-top: 2px;
            color: var(--texto-suave);
            font-size: 10px;
        }

        .notificaciones-cliente-lista {
            max-height: 350px;
            overflow-y: auto;
        }

        .notificacion-cliente-item {
            display: block;
            padding: 14px 18px;
            border-bottom: 1px solid #edf0ec;
            color: #20231f;
        }

        .notificacion-cliente-item:hover {
            background: #f4f8f5;
            color: #20231f;
        }

        .notificacion-cliente-fila {
            display: flex;
            gap: 11px;
        }

        .notificacion-cliente-icono {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            font-size: 16px;
        }

        .notificacion-cliente-icono.aceptado {
            background: #dff2e4;
            color: #21643b;
        }

        .notificacion-cliente-icono.rechazado {
            background: #fff0f0;
            color: #9d3030;
        }

        .notificacion-cliente-contenido {
            min-width: 0;
            flex: 1;
        }

        .notificacion-cliente-contenido strong {
            display: block;
            margin-bottom: 3px;
            color: var(--verde-oscuro);
            font-size: 12px;
        }

        .notificacion-cliente-contenido span {
            display: block;
            color: var(--texto-suave);
            font-size: 10px;
            line-height: 1.5;
        }

        .notificacion-cliente-monto {
            margin-top: 4px;
            color: var(--verde) !important;
            font-weight: 900;
        }

        .notificaciones-cliente-vacio {
            padding: 28px 20px;
            color: var(--texto-suave);
            text-align: center;
            font-size: 12px;
        }

        .notificaciones-cliente-pie {
            padding: 12px 18px;
            border-top: 1px solid #e8ebe7;
            background: #fbfcfa;
        }

        .notificaciones-cliente-pie a {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            color: var(--verde);
            font-size: 11px;
            font-weight: 900;
        }

        .pagina-hero {
            min-height: 335px;
            display: flex;
            align-items: center;
            margin-top: 82px;
            color: white;
            background:
                linear-gradient(
                    90deg,
                    rgba(10, 29, 20, .92),
                    rgba(10, 29, 20, .58)
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
            font-size: clamp(2.8rem, 6vw, 5rem);
            font-weight: 700;
        }

        .pagina-hero p {
            max-width: 680px;
            color: rgba(255, 255, 255, .82);
            line-height: 1.7;
        }

        .contenido-pagina {
            padding: 70px 0;
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

        .perfil-card {
            overflow: hidden;
            border: 1px solid #e2e4de;
            border-radius: 10px;
            background: white;
            box-shadow: var(--sombra);
        }

        .perfil-lateral {
            height: 100%;
            padding: 34px;
            background:
                linear-gradient(
                    rgba(20, 54, 36, .91),
                    rgba(20, 54, 36, .91)
                ),
                url("../img/hotel.jpg") center/cover;
            color: white;
        }

        .perfil-icono {
            width: 72px;
            height: 72px;
            display: grid;
            place-items: center;
            margin-bottom: 22px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .13);
            color: #f0d99f;
            font-size: 31px;
        }

        .perfil-lateral h2 {
            font-family: Georgia, serif;
        }

        .perfil-lateral p {
            color: rgba(255, 255, 255, .72);
            font-size: 13px;
            line-height: 1.7;
        }

        .dato-cuenta {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, .16);
            font-size: 12px;
        }

        .dato-cuenta span {
            display: block;
            color: rgba(255, 255, 255, .58);
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .formulario-perfil {
            padding: 34px;
        }

        .formulario-perfil h2 {
            color: var(--verde-oscuro);
            font-family: Georgia, serif;
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
            background: white;
            box-shadow: 0 0 0 4px rgba(36, 74, 53, .10);
        }

        .btn-guardar {
            min-height: 48px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 11px 20px;
            border: 1px solid var(--verde);
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

        .nota-seguridad {
            display: flex;
            gap: 9px;
            margin-top: 22px;
            padding: 13px 15px;
            border: 1px solid #dedfd9;
            border-radius: 6px;
            background: #f7f8f5;
            color: var(--texto-suave);
            font-size: 12px;
            line-height: 1.6;
        }

        .acceso-seccion {
            margin-top: 30px;
        }

        .ayuda-usuario {
            margin-top: 8px;
            color: var(--texto-suave);
            font-size: 11px;
            line-height: 1.5;
        }

        .campo-clave {
            position: relative;
        }

        .btn-ver-clave {
            position: absolute;
            top: 35px;
            right: 10px;
            border: 0;
            background: transparent;
            color: var(--texto-suave);
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

            .perfil-lateral,
            .formulario-perfil {
                padding: 24px;
            }
        }

        @media (max-width: 420px) {
            .marca-texto {
                display: none;
            }

            .btn-guardar {
                width: 100%;
            }

            .menu-notificaciones-cliente {
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
            href="index.php"
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
            data-bs-target="#menuCliente"
            aria-controls="menuCliente"
            aria-expanded="false"
            aria-label="Abrir menú"
        >
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="menuCliente">

            <ul class="navbar-nav mx-auto mb-2 mb-lg-0">

                <li class="nav-item">
                    <a href="index.php" class="nav-link">
                        Inicio
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="index.php#habitaciones"
                        class="nav-link"
                    >
                        Habitaciones
                    </a>
                </li>

                <li class="nav-item">
                    <a
                        href="pedir_comida.php"
                        class="nav-link"
                    >
                        Comidas
                    </a>
                </li>

                <li class="nav-item dropdown">

                    <a
                        href="#"
                        class="nav-link dropdown-toggle active"
                        role="button"
                        data-bs-toggle="dropdown"
                    >
                        Mi cuenta
                    </a>

                    <ul class="dropdown-menu dropdown-menu-end">

                        <li>
                            <a
                                href="mis_reservas.php"
                                class="dropdown-item"
                            >
                                <i class="bi bi-calendar-check me-2"></i>
                                Mis reservas
                            </a>
                        </li>

                        <li>
                            <a
                                href="mis_pedidos.php"
                                class="dropdown-item"
                            >
                                <i class="bi bi-receipt me-2"></i>
                                Mis pedidos
                            </a>
                        </li>

                        <li>
                            <a
                                href="perfil.php"
                                class="dropdown-item active"
                            >
                                <i class="bi bi-person me-2"></i>
                                Mi perfil
                            </a>
                        </li>

                    </ul>

                </li>

            </ul>

            <div class="d-flex flex-wrap align-items-center gap-3">

                <div class="dropdown notificaciones-cliente">

                    <button
                        type="button"
                        id="btnNotificacionesCliente"
                        class="btn-notificaciones-cliente"
                        data-bs-toggle="dropdown"
                        data-bs-auto-close="outside"
                        aria-expanded="false"
                        aria-label="Notificaciones de pagos"
                        title="Notificaciones"
                    >
                        <i class="bi bi-bell"></i>

                        <span
                            id="contadorNotificacionesCliente"
                            class="contador-notificaciones-cliente"
                        ></span>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end menu-notificaciones-cliente">

                        <div class="notificaciones-cliente-cabecera">
                            <strong>Notificaciones</strong>
                            <small>Avisos importantes de tus pagos</small>
                        </div>

                        <div class="notificaciones-cliente-lista">

                            <?php if (!empty($notificacionesPago)) { ?>

                                <?php foreach (
                                    $notificacionesPago as $notificacion
                                ) { ?>

                                    <?php
                                    $notificacionAceptada =
                                        $notificacion["estado_pago"] === "Aceptado";

                                    $motivoRechazo =
                                        trim(
                                            (string) (
                                                $notificacion["observacion"] ?? ""
                                            )
                                        );
                                    ?>

                                    <a
                                        href="mis_reservas.php"
                                        class="notificacion-cliente-item"
                                        data-notificacion-pago="pago-<?php echo (int) $notificacion["id_pago"]; ?>"
                                    >
                                        <div class="notificacion-cliente-fila">

                                            <span
                                                class="notificacion-cliente-icono <?php echo $notificacionAceptada ? "aceptado" : "rechazado"; ?>"
                                            >
                                                <i
                                                    class="bi <?php echo $notificacionAceptada ? "bi-check-circle" : "bi-x-circle"; ?>"
                                                ></i>
                                            </span>

                                            <span class="notificacion-cliente-contenido">

                                                <strong>
                                                    <?php echo $notificacionAceptada ? "Pago aceptado" : "Pago rechazado"; ?>
                                                </strong>

                                                <span>
                                                    Reserva #
                                                    <?php echo (int) $notificacion["id_reserva"]; ?>
                                                    · Habitación
                                                    <?php echo h($notificacion["numero_habitacion"]); ?>
                                                </span>

                                                <span>
                                                    <?php if ($notificacionAceptada) { ?>
                                                        Tu pago fue aceptado y la reserva quedó confirmada.
                                                    <?php } else { ?>
                                                        Tu pago fue rechazado.
                                                        <?php if ($motivoRechazo !== "") { ?>
                                                            Motivo:
                                                            <?php echo h($motivoRechazo); ?>
                                                        <?php } ?>
                                                    <?php } ?>
                                                </span>

                                                <span class="notificacion-cliente-monto">
                                                    $<?php echo number_format((float) $notificacion["monto"], 2); ?>
                                                </span>

                                            </span>

                                        </div>
                                    </a>

                                <?php } ?>

                            <?php } else { ?>

                                <div class="notificaciones-cliente-vacio">
                                    <i class="bi bi-bell-slash d-block fs-4 mb-2"></i>
                                    Aún no tienes avisos de pagos.
                                </div>

                            <?php } ?>

                        </div>

                        <div class="notificaciones-cliente-pie">
                            <a href="mis_reservas.php">
                                <i class="bi bi-calendar-check"></i>
                                Ver mis reservas
                            </a>
                        </div>

                    </div>

                </div>

                <div class="usuario-navbar">
                    Bienvenido

                    <strong>
                        <?php echo h($nombreCompleto); ?>
                    </strong>
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
                INFORMACIÓN PERSONAL
            </div>

            <h1>Mi perfil</h1>

            <p>
                Consulta y actualiza tus datos personales,
                nombre de usuario y contraseña. El rol Cliente
                no puede modificarse desde esta página.
            </p>

        </div>
    </div>
</section>

<main class="contenido-pagina">
    <div class="container">

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "actualizado"
        ) { ?>

            <div class="mensaje mensaje-exito">
                <i class="bi bi-check-circle"></i>
                Tus datos fueron actualizados correctamente.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "usuario_actualizado"
        ) { ?>

            <div class="mensaje mensaje-exito">
                <i class="bi bi-person-check"></i>
                Tu nombre de usuario fue cambiado correctamente.
            </div>

        <?php } ?>

        <?php if (
            isset($_GET["mensaje"]) &&
            $_GET["mensaje"] === "clave_actualizada"
        ) { ?>

            <div class="mensaje mensaje-exito">
                <i class="bi bi-shield-check"></i>
                Tu contraseña fue cambiada correctamente.
            </div>

        <?php } ?>

        <?php if (!empty($errores)) { ?>

            <div class="mensaje mensaje-error">

                <i class="bi bi-exclamation-triangle"></i>

                <div>
                    <strong>Revisa la información:</strong>

                    <ul class="mt-2 mb-0">

                        <?php foreach ($errores as $error) { ?>

                            <li><?php echo h($error); ?></li>

                        <?php } ?>

                    </ul>
                </div>

            </div>

        <?php } ?>

        <section class="perfil-card">

            <div class="row g-0">

                <div class="col-lg-4">

                    <div class="perfil-lateral">

                        <div class="perfil-icono">
                            <i class="bi bi-person"></i>
                        </div>

                        <h2>
                            <?php echo h($nombreCompleto); ?>
                        </h2>

                        <p>
                            Mantén actualizados tus datos para que
                            el hotel pueda identificar correctamente
                            tus reservas, pagos y pedidos.
                        </p>

                        <div class="dato-cuenta">

                            <span>Nombre de usuario</span>

                            <strong>
                                <?php echo h($_SESSION["usuario"]); ?>
                            </strong>

                        </div>

                        <div class="dato-cuenta">

                            <span>Rol de la cuenta</span>

                            <strong>Cliente</strong>

                        </div>

                        <div class="dato-cuenta">

                            <span>Código de huésped</span>

                            <strong>
                                #<?php echo $idCliente; ?>
                            </strong>

                        </div>

                    </div>

                </div>

                <div class="col-lg-8">

                    <div class="formulario-perfil">

                        <div class="pagina-etiqueta text-success">
                            DATOS DEL HUÉSPED
                        </div>

                        <h2 class="mt-2 mb-2">
                            Actualizar información
                        </h2>

                        <p class="text-muted small mb-4">
                            Los cambios solamente se aplican
                            a tu propio perfil.
                        </p>

                        <form
                            method="POST"
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
                                        for="nombres"
                                        class="form-label"
                                    >
                                        Nombres
                                    </label>

                                    <input
                                        type="text"
                                        id="nombres"
                                        name="nombres"
                                        class="form-control"
                                        maxlength="100"
                                        value="<?php echo h($nombres); ?>"
                                        required
                                    >

                                </div>

                                <div class="col-md-6">

                                    <label
                                        for="apellidos"
                                        class="form-label"
                                    >
                                        Apellidos
                                    </label>

                                    <input
                                        type="text"
                                        id="apellidos"
                                        name="apellidos"
                                        class="form-control"
                                        maxlength="100"
                                        value="<?php echo h($apellidos); ?>"
                                        required
                                    >

                                </div>

                                <div class="col-md-4">

                                    <label
                                        for="cedula"
                                        class="form-label"
                                    >
                                        Cédula
                                    </label>

                                    <input
                                        type="text"
                                        id="cedula"
                                        name="cedula"
                                        class="form-control"
                                        maxlength="10"
                                        inputmode="numeric"
                                        pattern="[0-9]{10}"
                                        value="<?php echo h($cedula); ?>"
                                        required
                                    >

                                </div>

                                <div class="col-md-4">

                                    <label
                                        for="correo"
                                        class="form-label"
                                    >
                                        Correo
                                    </label>

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

                                    <label
                                        for="telefono"
                                        class="form-label"
                                    >
                                        Teléfono
                                    </label>

                                    <input
                                        type="text"
                                        id="telefono"
                                        name="telefono"
                                        class="form-control"
                                        maxlength="10"
                                        inputmode="numeric"
                                        pattern="[0-9]{10}"
                                        value="<?php echo h($telefono); ?>"
                                        required
                                    >

                                </div>

                            </div>

                            <div class="nota-seguridad">

                                <i class="bi bi-shield-lock"></i>

                                <div>
                                    Este formulario cambia únicamente tus
                                    datos personales. El usuario y la contraseña
                                    se actualizan en la sección que aparece más abajo.
                                </div>

                            </div>

                            <button
                                type="submit"
                                name="guardar_perfil"
                                class="btn-guardar mt-4"
                            >
                                <i class="bi bi-check-circle"></i>
                                Actualizar mis datos
                            </button>

                        </form>

                    </div>

                </div>

            </div>

        </section>

        <section class="perfil-card acceso-seccion">

            <div class="row g-0">

                <div class="col-lg-4">

                    <div class="perfil-lateral">

                        <div class="perfil-icono">
                            <i class="bi bi-key"></i>
                        </div>

                        <h2>Datos de acceso</h2>

                        <p>
                            El cambio de usuario y el cambio de contraseña
                            están separados. Puedes modificar solamente
                            el nombre de usuario sin crear una contraseña nueva.
                        </p>

                        <div class="dato-cuenta">

                            <span>Usuario actual</span>

                            <strong>
                                <?php echo h($usuarioActual); ?>
                            </strong>

                        </div>

                        <div class="dato-cuenta">

                            <span>Rol protegido</span>

                            <strong>Cliente</strong>

                        </div>

                    </div>

                </div>

                <div class="col-lg-8">

                    <div class="formulario-perfil">

                        <div class="pagina-etiqueta text-success">
                            NOMBRE DE USUARIO
                        </div>

                        <h2 class="mt-2 mb-2">
                            Cambiar el usuario
                        </h2>

                        <p class="text-muted small mb-4">
                            Escribe un usuario nuevo y tu contraseña actual.
                            No necesitas llenar ninguna contraseña nueva.
                        </p>

                        <?php if (!empty($erroresAcceso)) { ?>

                            <div class="mensaje mensaje-error">

                                <i class="bi bi-exclamation-triangle"></i>

                                <div>
                                    <strong>Revisa los datos de acceso:</strong>

                                    <ul class="mt-2 mb-0">

                                        <?php foreach ($erroresAcceso as $errorAcceso) { ?>

                                            <li>
                                                <?php echo h($errorAcceso); ?>
                                            </li>

                                        <?php } ?>

                                    </ul>
                                </div>

                            </div>

                        <?php } ?>

                        <form
                            method="POST"
                            autocomplete="off"
                        >

                            <input
                                type="hidden"
                                name="csrf_acceso"
                                value="<?php echo h($csrfAcceso); ?>"
                            >

                            <div class="row g-4">

                                <div class="col-12">

                                    <label
                                        for="nuevo_usuario"
                                        class="form-label"
                                    >
                                        Nuevo nombre de usuario
                                    </label>

                                    <input
                                        type="text"
                                        id="nuevo_usuario"
                                        name="nuevo_usuario"
                                        class="form-control"
                                        minlength="4"
                                        maxlength="50"
                                        pattern="[A-Za-z0-9._-]+"
                                        value="<?php echo h($usuarioFormulario); ?>"
                                        autocomplete="off"
                                        required
                                    >

                                    <div class="ayuda-usuario">
                                        Debe ser un usuario que todavía no exista.
                                        Puedes usar letras, números, punto, guion
                                        y guion bajo.
                                    </div>

                                </div>

                                <div class="col-12 campo-clave">

                                    <label
                                        for="clave_actual_usuario"
                                        class="form-label"
                                    >
                                        Contraseña actual
                                    </label>

                                    <input
                                        type="password"
                                        id="clave_actual_usuario"
                                        name="clave_actual_usuario"
                                        class="form-control pe-5"
                                        autocomplete="current-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="btn-ver-clave"
                                        data-mostrar-clave="clave_actual_usuario"
                                        aria-label="Mostrar contraseña actual"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>

                                </div>

                            </div>

                            <button
                                type="submit"
                                name="guardar_usuario"
                                class="btn-guardar mt-4"
                            >
                                <i class="bi bi-person-check"></i>
                                Cambiar nombre de usuario
                            </button>

                        </form>

                        <hr class="my-5">

                        <div class="pagina-etiqueta text-success">
                            CONTRASEÑA
                        </div>

                        <h2 class="mt-2 mb-2">
                            Cambiar contraseña
                        </h2>

                        <p class="text-muted small mb-4">
                            Usa este segundo formulario únicamente cuando
                            quieras crear una contraseña nueva.
                        </p>

                        <form
                            method="POST"
                            autocomplete="off"
                        >

                            <input
                                type="hidden"
                                name="csrf_acceso"
                                value="<?php echo h($csrfAcceso); ?>"
                            >

                            <div class="row g-4">

                                <div class="col-12 campo-clave">

                                    <label
                                        for="clave_actual_password"
                                        class="form-label"
                                    >
                                        Contraseña actual
                                    </label>

                                    <input
                                        type="password"
                                        id="clave_actual_password"
                                        name="clave_actual_password"
                                        class="form-control pe-5"
                                        autocomplete="current-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="btn-ver-clave"
                                        data-mostrar-clave="clave_actual_password"
                                        aria-label="Mostrar contraseña actual"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>

                                </div>

                                <div class="col-md-6 campo-clave">

                                    <label
                                        for="clave_nueva"
                                        class="form-label"
                                    >
                                        Contraseña nueva
                                    </label>

                                    <input
                                        type="password"
                                        id="clave_nueva"
                                        name="clave_nueva"
                                        class="form-control pe-5"
                                        minlength="8"
                                        autocomplete="new-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="btn-ver-clave"
                                        data-mostrar-clave="clave_nueva"
                                        aria-label="Mostrar contraseña nueva"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>

                                </div>

                                <div class="col-md-6 campo-clave">

                                    <label
                                        for="confirmar_clave"
                                        class="form-label"
                                    >
                                        Confirmar contraseña nueva
                                    </label>

                                    <input
                                        type="password"
                                        id="confirmar_clave"
                                        name="confirmar_clave"
                                        class="form-control pe-5"
                                        minlength="8"
                                        autocomplete="new-password"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="btn-ver-clave"
                                        data-mostrar-clave="confirmar_clave"
                                        aria-label="Mostrar confirmación"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>

                                </div>

                            </div>

                            <div class="nota-seguridad">

                                <i class="bi bi-shield-check"></i>

                                <div>
                                    El rol de la cuenta seguirá siendo Cliente.
                                    Ninguno de estos formularios permite cambiar
                                    a Administrador o Recepcionista.
                                </div>

                            </div>

                            <button
                                type="submit"
                                name="guardar_clave"
                                class="btn-guardar mt-4"
                            >
                                <i class="bi bi-shield-lock"></i>
                                Cambiar contraseña
                            </button>

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
                        Comodidad y tranquilidad para nuestros huéspedes.
                    </small>
                </div>

            </div>

            <a
                href="index.php"
                class="btn btn-outline-light btn-sm"
            >
                Volver al inicio
            </a>

        </div>

    </div>

    <div class="footer-final">

        <div class="container d-flex justify-content-between flex-wrap gap-2">

            <span>
                Hotel Las 3 Palmeras © 2026
            </span>

            <span>
                Perfil de <?php echo h($nombreCompleto); ?>
            </span>

        </div>

    </div>

</footer>

<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"
></script>

<script>
const claveNotificacionesPago =
    "hotel_notificaciones_pago_cliente_<?php echo (int) $idCliente; ?>";

const btnNotificacionesCliente =
    document.getElementById("btnNotificacionesCliente");

const contadorNotificacionesCliente =
    document.getElementById("contadorNotificacionesCliente");

const elementosNotificacionPago =
    Array.from(
        document.querySelectorAll("[data-notificacion-pago]")
    );

function obtenerNotificacionesVistas() {
    try {
        const guardadas =
            JSON.parse(
                localStorage.getItem(
                    claveNotificacionesPago
                ) || "[]"
            );

        return Array.isArray(guardadas)
            ? guardadas
            : [];
    } catch (error) {
        return [];
    }
}

function actualizarContadorNotificaciones() {
    const vistas = obtenerNotificacionesVistas();

    const noVistas =
        elementosNotificacionPago.filter(
            function (elemento) {
                return !vistas.includes(
                    elemento.dataset.notificacionPago
                );
            }
        );

    if (!contadorNotificacionesCliente) {
        return;
    }

    if (noVistas.length === 0) {
        contadorNotificacionesCliente.style.display =
            "none";
        contadorNotificacionesCliente.textContent =
            "";
        return;
    }

    contadorNotificacionesCliente.style.display =
        "inline-flex";

    contadorNotificacionesCliente.textContent =
        noVistas.length > 99
            ? "99+"
            : String(noVistas.length);
}

function marcarNotificacionesComoVistas() {
    const vistas = obtenerNotificacionesVistas();

    const idsActuales =
        elementosNotificacionPago.map(
            function (elemento) {
                return elemento.dataset.notificacionPago;
            }
        );

    localStorage.setItem(
        claveNotificacionesPago,
        JSON.stringify(
            Array.from(
                new Set([...vistas, ...idsActuales])
            )
        )
    );

    actualizarContadorNotificaciones();
}

if (btnNotificacionesCliente) {
    btnNotificacionesCliente.addEventListener(
        "shown.bs.dropdown",
        marcarNotificacionesComoVistas
    );
}

actualizarContadorNotificaciones();
</script>

<script>
const cedula =
    document.getElementById("cedula");

const telefono =
    document.getElementById("telefono");

function solamenteNumeros(campo) {
    campo.value =
        campo.value
            .replace(/\D/g, "")
            .slice(0, 10);
}

cedula.addEventListener(
    "input",
    function () {
        solamenteNumeros(cedula);
    }
);

telefono.addEventListener(
    "input",
    function () {
        solamenteNumeros(telefono);
    }
);

document.querySelectorAll(
    "[data-mostrar-clave]"
).forEach(function (boton) {
    boton.addEventListener(
        "click",
        function () {
            const idCampo =
                boton.getAttribute(
                    "data-mostrar-clave"
                );

            const campo =
                document.getElementById(idCampo);

            const mostrar =
                campo.type === "password";

            campo.type =
                mostrar ? "text" : "password";

            boton.innerHTML =
                mostrar
                    ? '<i class="bi bi-eye-slash"></i>'
                    : '<i class="bi bi-eye"></i>';
        }
    );
});
</script>

</body>

</html>