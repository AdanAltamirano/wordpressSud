<?php
$conexion = mysqli_connect('localhost', 'wpuser', 'Adan', 'wordpress');

if (!$conexion) {
    die("Error de conexión: " . mysqli_connect_error());
}

echo "Conexión exitosa a MySQL!";
mysqli_close($conexion);
?>