<?php
require_once __DIR__ . '/../../includes/config.php';

echo "<h2>Prueba de Conexión y Tablas</h2>";

// Verificar conexión
if ($db) {
    echo "<p style='color:green'>✅ Conexión a BD exitosa</p>";
} else {
    echo "<p style='color:red'>❌ Error de conexión</p>";
    exit;
}

// Verificar tabla reservas
try {
    $query = "SHOW TABLES LIKE 'reservas'";
    $stmt = $db->query($query);
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green'>✅ Tabla 'reservas' existe</p>";
    } else {
        echo "<p style='color:red'>❌ Tabla 'reservas' NO existe</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Verificar tabla reserva_detalles_servicios
try {
    $query = "SHOW TABLES LIKE 'reserva_detalles_servicios'";
    $stmt = $db->query($query);
    if ($stmt->rowCount() > 0) {
        echo "<p style='color:green'>✅ Tabla 'reserva_detalles_servicios' existe</p>";
    } else {
        echo "<p style='color:red'>❌ Tabla 'reserva_detalles_servicios' NO existe</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}

// Mostrar estructura de reservas
try {
    $query = "DESCRIBE reservas";
    $stmt = $db->query($query);
    echo "<h3>Estructura de tabla 'reservas':</h3>";
    echo "<pre>";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    echo "</pre>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>