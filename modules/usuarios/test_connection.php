<?php
// Auto-fixed: 2026-02-17 01:57:21
require_once '../../../includes/config.php';
// modules/usuarios/test_connection.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

echo "<h1>Prueba de Conexión</h1>";

$base_dir = dirname(__DIR__, 2);
echo "<p>Base dir: $base_dir</p>";

// Probar archivos
$files = [
    'config/database.php' => $base_dir . '/config/database.php',
    'config/auth.php' => $base_dir . '/config/auth.php',
    'config/permisos.php' => $base_dir . '/config/permisos.php'
];

foreach ($files as $name => $path) {
    echo "<p><strong>$name:</strong> ";
    if (file_exists($path)) {
        echo "EXISTE ($path)";
    } else {
        echo "NO EXISTE ($path)";
    }
    echo "</p>";
}

// Probar conexión
echo "<h2>Prueba de conexión a BD</h2>";
try {
    require_once $base_dir . '/config/database.php';
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    if ($db) {
        echo "<p style='color:green;'>✓ CONEXIÓN EXITOSA</p>";
        
        // Probar consulta
        $stmt = $db->query("SELECT COUNT(*) as total FROM usuarios");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "<p>Total usuarios en BD: " . $result['total'] . "</p>";
    } else {
        echo "<p style='color:red;'>✗ ERROR DE CONEXIÓN</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>✗ ERROR: " . $e->getMessage() . "</p>";
}
?>