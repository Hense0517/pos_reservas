<?php
// Auto-fixed: 2026-02-17 01:57:19
require_once 'includes/config.php';
// Activar errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>Prueba de conexión a Base de Datos</h2>";

// Tus credenciales actuales
$host = "localhost";
$dbname = "dakotabo_pos";
$username = "dakotabo_admin";
$password = "Sarita.2310@123";

echo "<h3>Intentando conectar con:</h3>";
echo "Host: " . $host . "<br>";
echo "Base de datos: " . $dbname . "<br>";
echo "Usuario: " . $username . "<br>";
echo "Contraseña: " . str_repeat("*", strlen($password)) . "<br>";

// Método 1: Conexión MySQLi
echo "<h3>Prueba con MySQLi:</h3>";
$mysqli = new mysqli($host, $username, $password, $dbname);

if ($mysqli->connect_error) {
    echo "❌ Error MySQLi: " . $mysqli->connect_error . "<br>";
    echo "Número de error: " . $mysqli->connect_errno . "<br>";
} else {
    echo "✅ Conexión MySQLi exitosa!<br>";
    echo "Información del servidor: " . $mysqli->server_info . "<br>";
    $mysqli->close();
}

// Método 2: Conexión PDO
echo "<h3>Prueba con PDO:</h3>";
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conexión PDO exitosa!<br>";
    
    // Probar una consulta simple
    $result = $pdo->query("SELECT 1 as test");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "Prueba de consulta: " . $row['test'] . "<br>";
    
} catch(PDOException $e) {
    echo "❌ Error PDO: " . $e->getMessage() . "<br>";
    echo "Código de error: " . $e->getCode() . "<br>";
}

// Probar conexión sin seleccionar base de datos
echo "<h3>Prueba de conexión al servidor MySQL (sin BD):</h3>";
try {
    $pdo_server = new PDO("mysql:host=$host;charset=utf8", $username, $password);
    $pdo_server->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✅ Conexión al servidor MySQL exitosa!<br>";
    
    // Listar bases de datos
    $stmt = $pdo_server->query("SHOW DATABASES LIKE 'dakotabo_pos'");
    if ($stmt->rowCount() > 0) {
        echo "✅ La base de datos 'dakotabo_pos' existe<br>";
    } else {
        echo "❌ La base de datos 'dakotabo_pos' NO existe<br>";
        
        // Intentar crear la base de datos
        try {
            $pdo_server->exec("CREATE DATABASE dakotabo_pos");
            echo "✅ Base de datos creada exitosamente<br>";
        } catch (Exception $e) {
            echo "❌ No se pudo crear la BD: " . $e->getMessage() . "<br>";
        }
    }
    
} catch(PDOException $e) {
    echo "❌ Error conectando al servidor: " . $e->getMessage() . "<br>";
}
?>