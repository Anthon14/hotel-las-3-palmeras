<?php

$host = "localhost";
$usuario = "USUARIO";
$password = "CONTRASENA";
$base_datos = "BASE_DE_DATOS";

$conexion = mysqli_connect(
    $host,
    $usuario,
    $password,
    $base_datos
);

if (!$conexion) {
    die("Error de conexión: " . mysqli_connect_error());
}

mysqli_set_charset($conexion, "utf8mb4");

date_default_timezone_set("America/Guayaquil");
mysqli_query($conexion, "SET time_zone = '-05:00'");
?>