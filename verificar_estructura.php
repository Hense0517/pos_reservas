<?php
// verificar_estructura.php - Verifica qué columnas tienes realmente
session_start();
require_once 'includes/config.php';

if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] != 'admin') {
    die("Acceso denegado.");
}

$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Verificar estructura de productos
echo "<h2>Estructura de la tabla 'productos'</h2>";
$stmt = $pdo->query("DESCRIBE productos");
$columnas = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Clave</th><th>Default</th><th>Extra</th></tr>";
foreach ($columnas as $columna) {
    echo "<tr>";
    echo "<td>" . $columna['Field'] . "</td>";
    echo "<td>" . $columna['Type'] . "</td>";
    echo "<td>" . $columna['Null'] . "</td>";
    echo "<td>" . $columna['Key'] . "</td>";
    echo "<td>" . $columna['Default'] . "</td>";
    echo "<td>" . $columna['Extra'] . "</td>";
    echo "</tr>";
}
echo "</table>";

// Verificar si existen las nuevas tablas
$tablas_nuevas = ['marcas', 'atributos', 'opciones_atributo', 'categoria_atributos', 'producto_variaciones', 'variacion_valores'];
echo "<h2>Tablas del sistema de variaciones</h2>";
echo "<ul>";
foreach ($tablas_nuevas as $tabla) {
    $stmt = $pdo->query("SHOW TABLES LIKE '$tabla'");
    $existe = $stmt->fetch() ? "✓ EXISTE" : "✗ NO EXISTE";
    echo "<li>$tabla: $existe</li>";
}
echo "</ul>";

// Verificar columnas agregadas a productos
echo "<h2>Columnas agregadas a 'productos'</h2>";
$columnas_requeridas = ['marca_id', 'tiene_variaciones'];
echo "<ul>";
foreach ($columnas_requeridas as $columna) {
    $stmt = $pdo->query("SHOW COLUMNS FROM productos LIKE '$columna'");
    $existe = $stmt->fetch() ? "✓ EXISTE" : "✗ NO EXISTE";
    echo "<li>$columna: $existe</li>";
}
echo "</ul>";

// Mostrar algunos productos de ejemplo
echo "<h2>Ejemplos de productos (primeros 10)</h2>";
$stmt = $pdo->query("SELECT id, codigo, nombre, categoria_id FROM productos LIMIT 10");
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Código</th><th>Nombre</th><th>Categoría</th><th>Nombre Normalizado</th></tr>";
foreach ($productos as $producto) {
    $nombre_normalizado = normalizarNombreProducto($producto['nombre']);
    echo "<tr>";
    echo "<td>" . $producto['id'] . "</td>";
    echo "<td>" . $producto['codigo'] . "</td>";
    echo "<td>" . $producto['nombre'] . "</td>";
    echo "<td>" . $producto['categoria_id'] . "</td>";
    echo "<td>" . $nombre_normalizado . "</td>";
    echo "</tr>";
}
echo "</table>";

function normalizarNombreProducto($nombre) {
    $nombre = mb_strtolower($nombre, 'UTF-8');
    $patrones = [
        '/\s+(talla|tamano|size|tam|t)\s*[\.\-\s]*([xsml0-9\/\-]+)/i',
        '/\s+(blanco|negro|rojo|azul|verde|amarillo|rosado|morado|gris|marron|naranja|beige|celeste|turquesa|vino|dorado|plateado|chocolate|marfil|crudo|mocca|arena|degradé|nude|vinotinto|rosa|café|piel|avena|beis|kaki|azul marino|azul cielo)/i',
    ];
    foreach ($patrones as $patron) {
        $nombre = preg_replace($patron, '', $nombre);
    }
    return ucwords(trim($nombre));
}
?>