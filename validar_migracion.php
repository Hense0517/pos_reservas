<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] != 'admin') {
    die("Acceso denegado.");
}

$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Verificar Resultados de Migración</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f2f2f2; }
        .success { color: green; }
        .warning { color: orange; }
        .danger { color: red; }
        .section { margin: 30px 0; padding: 20px; border: 1px solid #ccc; }
    </style>
</head>
<body>
    <h1>✅ Verificación de Migración</h1>
    
    <?php
    // 1. Resumen general
    echo "<div class='section'>";
    echo "<h2>📊 Resumen General</h2>";
    
    $stats = [];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM productos");
    $stats['total_productos'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM productos WHERE tiene_variaciones = 1");
    $stats['productos_con_variaciones'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM productos WHERE tiene_variaciones = 0");
    $stats['productos_simples'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM producto_variaciones");
    $stats['total_variaciones'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM marcas");
    $stats['total_marcas'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT marca_id) as total FROM productos WHERE marca_id IS NOT NULL");
    $stats['productos_con_marca'] = $stmt->fetchColumn();
    
    echo "<table>";
    echo "<tr><th>Métrica</th><th>Valor</th><th>Estado</th></tr>";
    
    foreach ($stats as $nombre => $valor) {
        $estado = "✅ OK";
        $clase = "success";
        
        if ($nombre == 'productos_con_variaciones' && $valor == 0) {
            $estado = "⚠️ Ningún producto con variaciones";
            $clase = "warning";
        }
        
        if ($nombre == 'total_variaciones' && $valor == 0) {
            $estado = "❌ No hay variaciones";
            $clase = "danger";
        }
        
        echo "<tr>";
        echo "<td>" . str_replace('_', ' ', ucfirst($nombre)) . "</td>";
        echo "<td><strong>" . $valor . "</strong></td>";
        echo "<td class='$clase'>" . $estado . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    echo "</div>";
    
    // 2. Productos con variaciones
    echo "<div class='section'>";
    echo "<h2>🔢 Productos con Variaciones (Top 10)</h2>";
    
    $stmt = $pdo->query("
        SELECT p.id, p.codigo, p.nombre, 
               COUNT(pv.id) as variaciones,
               GROUP_CONCAT(pv.atributo_valor ORDER BY pv.id SEPARATOR ', ') as valores
        FROM productos p
        LEFT JOIN producto_variaciones pv ON p.id = pv.producto_id
        WHERE p.tiene_variaciones = 1
        GROUP BY p.id
        ORDER BY variaciones DESC
        LIMIT 10
    ");
    
    echo "<table>";
    echo "<tr><th>ID</th><th>Código</th><th>Producto</th><th>Variaciones</th><th>Valores</th></tr>";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td>" . $row['codigo'] . "</td>";
        echo "<td>" . substr($row['nombre'], 0, 40) . "...</td>";
        echo "<td class='success'><strong>" . $row['variaciones'] . "</strong></td>";
        echo "<td>" . substr($row['valores'], 0, 50) . "...</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    echo "</div>";
    
    // 3. Ejemplos de variaciones
    echo "<div class='section'>";
    echo "<h2>📋 Ejemplos de Variaciones Creadas</h2>";
    
    $stmt = $pdo->query("
        SELECT pv.id, pv.sku, pv.atributo_nombre, pv.atributo_valor, 
               pv.precio_venta, p.nombre as producto
        FROM producto_variaciones pv
        JOIN productos p ON pv.producto_id = p.id
        ORDER BY pv.created_at DESC
        LIMIT 15
    ");
    
    echo "<table>";
    echo "<tr><th>ID</th><th>SKU</th><th>Producto</th><th>Atributo</th><th>Valor</th><th>Precio</th></tr>";
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . $row['id'] . "</td>";
        echo "<td><code>" . $row['sku'] . "</code></td>";
        echo "<td>" . substr($row['producto'], 0, 30) . "...</td>";
        echo "<td>" . $row['atributo_nombre'] . "</td>";
        echo "<td><strong>" . $row['atributo_valor'] . "</strong></td>";
        echo "<td>$" . number_format($row['precio_venta'], 2) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    echo "</div>";
    
    // 4. Problemas detectados
    echo "<div class='section'>";
    echo "<h2>⚠️ Posibles Problemas</h2>";
    
    // Productos sin marca
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM productos WHERE marca_id IS NULL");
    $sin_marca = $stmt->fetchColumn();
    
    // Variaciones sin precio
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM producto_variaciones WHERE precio_venta = 0");
    $sin_precio = $stmt->fetchColumn();
    
    // SKUs duplicados
    $stmt = $pdo->query("
        SELECT sku, COUNT(*) as duplicados 
        FROM producto_variaciones 
        GROUP BY sku 
        HAVING duplicados > 1
    ");
    $skus_duplicados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<ul>";
    
    if ($sin_marca > 0) {
        echo "<li class='warning'>📦 Productos sin marca asignada: <strong>" . $sin_marca . "</strong></li>";
    }
    
    if ($sin_precio > 0) {
        echo "<li class='warning'>💰 Variaciones sin precio: <strong>" . $sin_precio . "</strong></li>";
    }
    
    if (count($skus_duplicados) > 0) {
        echo "<li class='danger'>🚫 SKUs duplicados encontrados: <strong>" . count($skus_duplicados) . "</strong></li>";
        foreach ($skus_duplicados as $sku) {
            echo "&nbsp;&nbsp;&nbsp;&nbsp;<small>SKU: " . $sku['sku'] . " (x" . $sku['duplicados'] . ")</small><br>";
        }
    }
    
    if ($sin_marca == 0 && $sin_precio == 0 && count($skus_duplicados) == 0) {
        echo "<li class='success'>✅ No se detectaron problemas importantes</li>";
    }
    
    echo "</ul>";
    echo "</div>";
    ?>
    
    <div style="margin-top: 30px;">
        <a href="migracion_final.php" style="background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
            🔄 Ejecutar Migración Nuevamente
        </a>
        <a href="modules/inventario/productos/index.php" style="background: #2ecc71; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-left: 10px;">
            📦 Gestionar Productos
        </a>
    </div>
</body>
</html>