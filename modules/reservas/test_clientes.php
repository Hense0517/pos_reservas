<?php
require_once __DIR__ . '/../../includes/config.php';

echo "<h2>Verificando tabla clientes</h2>";

// Verificar conexión
if ($db) {
    echo "<p style='color:green'>✓ Conexión a BD exitosa</p>";
} else {
    echo "<p style='color:red'>✗ Error de conexión</p>";
    exit;
}

// Contar clientes
$query = "SELECT COUNT(*) as total FROM clientes";
$stmt = $db->query($query);
$total = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<p>Total clientes en BD: <strong>" . $total['total'] . "</strong></p>";

// Mostrar primeros 5 clientes
$query = "SELECT id, nombre, numero_documento, telefono, email FROM clientes LIMIT 5";
$stmt = $db->query($query);
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Primeros 5 clientes:</h3>";
echo "<pre>";
print_r($clientes);
echo "</pre>";

// Probar búsqueda específica
$documento = '10781135';
$query = "SELECT * FROM clientes WHERE numero_documento = :doc";
$stmt = $db->prepare($query);
$stmt->bindParam(':doc', $documento);
$stmt->execute();
$resultado = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<h3>Buscando documento $documento:</h3>";
if ($resultado) {
    echo "<p style='color:green'>✓ Cliente encontrado:</p>";
    echo "<pre>";
    print_r($resultado);
    echo "</pre>";
} else {
    echo "<p style='color:red'>✗ Cliente NO encontrado</p>";
}

// Probar búsqueda por nombre
$nombre = 'HENRY';
$query = "SELECT * FROM clientes WHERE nombre LIKE :nombre";
$stmt = $db->prepare($query);
$nombre_busqueda = "%$nombre%";
$stmt->bindParam(':nombre', $nombre_busqueda);
$stmt->execute();
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Buscando nombre '$nombre':</h3>";
if (count($resultados) > 0) {
    echo "<p style='color:green'>✓ Encontrados " . count($resultados) . " clientes:</p>";
    echo "<pre>";
    print_r($resultados);
    echo "</pre>";
} else {
    echo "<p style='color:red'>✗ No se encontraron clientes</p>";
}