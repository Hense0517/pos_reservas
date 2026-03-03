<?php
// Auto-fixed: 2026-02-17 01:57:21
require_once 'includes/config.php';
// CONFIGURA TUS DATOS DE CONEXIÓN
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "sistema_pos";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

echo "<h2>Estructura de la Base de Datos: $dbname</h2>";

$tables = $conn->query("SHOW TABLES");

while ($row = $tables->fetch_array()) {
    $tableName = $row[0];
    echo "<h3>Tabla: <strong>$tableName</strong></h3>";

    // Obtener estructura de columnas
    $columns = $conn->query("SHOW FULL COLUMNS FROM $tableName");

    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr>
            <th>Campo</th>
            <th>Tipo</th>
            <th>Nulo</th>
            <th>Clave</th>
            <th>Default</th>
            <th>Extra</th>
            <th>Comentario</th>
          </tr>";

    while ($col = $columns->fetch_assoc()) {
        echo "<tr>
                <td>{$col['Field']}</td>
                <td>{$col['Type']}</td>
                <td>{$col['Null']}</td>
                <td>{$col['Key']}</td>
                <td>{$col['Default']}</td>
                <td>{$col['Extra']}</td>
                <td>{$col['Comment']}</td>
              </tr>";
    }

    echo "</table><br>";
}

$conn->close();
?>
