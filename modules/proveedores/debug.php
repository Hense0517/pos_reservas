<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../../includes/database.php';

echo "<pre>";
echo "POST data:\n";
print_r($_POST);
echo "\n\n";

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Conexión exitosa: " . ($db ? "SÍ" : "NO") . "\n";
    
    // Probar consulta simple
    $test = $db->query("SELECT 1");
    echo "Consulta de prueba: " . ($test ? "OK" : "FALLÓ") . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
echo "</pre>";
?>