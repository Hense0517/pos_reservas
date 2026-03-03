<?php
// Auto-fixed: 2026-02-17 01:57:21
require_once '../../../../includes/config.php';
// Archivo: exportar_excel.php
// Exporta el inventario de productos a HTML/Excel formateado

// Incluir config
$config_path = '../../../includes/config.php';
if (!file_exists($config_path)) {
    die("Error: No se encuentra config.php");
}
include $config_path;

// Configurar zona horaria
date_default_timezone_set('America/Bogota');

// Verificar permisos
session_start();
if (!$auth->hasPermission('reportes', 'exportar')) {
    $_SESSION['error'] = "No tienes permisos para exportar reportes";
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Parámetros de búsqueda
$busqueda = $_GET['busqueda'] ?? '';
$categoria_id = $_GET['categoria_id'] ?? '';
$marca_id = $_GET['marca_id'] ?? '';
$talla = $_GET['talla'] ?? '';
$color = $_GET['color'] ?? '';
$stock_bajo = $_GET['stock_bajo'] ?? '';

// Construir consulta
$query = "SELECT p.*, c.nombre as categoria_nombre, m.nombre as marca_nombre 
          FROM productos p 
          LEFT JOIN categorias c ON p.categoria_id = c.id 
          LEFT JOIN marcas m ON p.marca_id = m.id 
          WHERE p.activo = 1";

$params = [];
$conditions = [];

if (!empty($busqueda)) {
    $conditions[] = "(p.nombre LIKE ? OR p.codigo LIKE ? OR p.descripcion LIKE ? OR p.codigo_barras LIKE ?)";
    $searchTerm = "%$busqueda%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($categoria_id)) {
    $conditions[] = "p.categoria_id = ?";
    $params[] = $categoria_id;
}

if (!empty($marca_id)) {
    $conditions[] = "p.marca_id = ?";
    $params[] = $marca_id;
}

if (!empty($talla)) {
    $conditions[] = "p.talla = ?";
    $params[] = $talla;
}

if (!empty($color)) {
    $conditions[] = "p.color LIKE ?";
    $params[] = "%$color%";
}

if ($stock_bajo === '1') {
    $conditions[] = "p.stock <= p.stock_minimo";
}

if (count($conditions) > 0) {
    $query .= " AND " . implode(" AND ", $conditions);
}

$query .= " ORDER BY p.nombre ASC";

// Obtener productos
$stmt = $db->prepare($query);
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Nombre del archivo
$filename = 'inventario_' . date('Y-m-d_H-i') . '.xls';

// Configurar headers para descarga Excel
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte de Inventario</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 11px; }
        table { border-collapse: collapse; width: 100%; }
        th { background-color: #4F81BD; color: white; font-weight: bold; padding: 8px; text-align: center; border: 1px solid #ddd; }
        td { padding: 6px; border: 1px solid #ddd; }
        .titulo { font-size: 18px; font-weight: bold; text-align: center; color: #1F497D; margin: 20px 0; }
        .subtitulo { font-size: 12px; text-align: center; color: #666; margin: 10px 0; }
        .resumen { background-color: #E8F0FE; padding: 10px; margin: 15px 0; border: 1px solid #B8C6D9; }
        .total { background-color: #D9EAD3; font-weight: bold; }
        .stock-bajo { background-color: #FFCCCC; color: #990000; }
        .stock-agotado { background-color: #FF9999; color: #660000; font-weight: bold; }
        .margen-alto { color: #009900; }
        .margen-medio { color: #669900; }
        .margen-bajo { color: #999900; }
        .margen-negativo { color: #FF0000; }
        .notas { font-size: 10px; color: #666; margin-top: 20px; }
    </style>
</head>
<body>

<div class="titulo">REPORTE DE INVENTARIO - SISTEMA DE INVENTARIOS</div>
<div class="subtitulo">Generado el: <?php echo date('d/m/Y H:i:s'); ?></div>

<?php
// Información de filtros
$filtros = [];
if ($busqueda) $filtros[] = "Búsqueda: " . $busqueda;
if ($categoria_id) $filtros[] = "Categoría ID: " . $categoria_id;
if ($marca_id) $filtros[] = "Marca ID: " . $marca_id;
if ($talla) $filtros[] = "Talla: " . $talla;
if ($color) $filtros[] = "Color: " . $color;
if ($stock_bajo) $filtros[] = "Solo stock bajo";
?>

<div class="subtitulo">Filtros aplicados: <?php echo empty($filtros) ? 'Ninguno' : implode('; ', $filtros); ?></div>

<?php
// Obtener estadísticas para el resumen
$query_stats = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN stock <= stock_minimo THEN 1 ELSE 0 END) as stock_bajo,
                SUM(stock) as stock_total,
                SUM(precio_compra * stock) as valor_compra,
                SUM(precio_venta * stock) as valor_venta
                FROM productos WHERE activo = 1";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

$valor_compra = $stats['valor_compra'] ?? 0;
$valor_venta = $stats['valor_venta'] ?? 0;
$margen_total = $valor_venta - $valor_compra;
$porcentaje_margen = $valor_compra > 0 ? ($margen_total / $valor_compra) * 100 : 0;
?>

<div class="resumen">
    <strong>RESUMEN DEL INVENTARIO</strong><br>
    Total Productos: <?php echo $stats['total'] ?? 0; ?><br>
    Productos con Stock Bajo: <?php echo $stats['stock_bajo'] ?? 0; ?><br>
    Stock Total: <?php echo $stats['stock_total'] ?? 0; ?><br>
    Valor Total Compra: $<?php echo number_format($valor_compra, 2); ?><br>
    Valor Total Venta: $<?php echo number_format($valor_venta, 2); ?><br>
    Margen Total: $<?php echo number_format($margen_total, 2); ?><br>
    Porcentaje Margen: <?php echo number_format($porcentaje_margen, 2); ?>%
</div>

<table>
    <thead>
        <tr>
            <th>ID</th>
            <th>Código</th>
            <th>Nombre</th>
            <th>Descripción</th>
            <th>Marca</th>
            <th>Categoría</th>
            <th>Talla</th>
            <th>Color</th>
            <th>Stock</th>
            <th>Stock Mín</th>
            <th>Estado</th>
            <th>Precio Compra</th>
            <th>Precio Venta</th>
            <th>Margen %</th>
            <th>Valor Stock Compra</th>
            <th>Valor Stock Venta</th>
            <th>Código Barras</th>
            <th>Fecha Creación</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $total_stock = 0;
        $total_valor_compra = 0;
        $total_valor_venta = 0;
        
        foreach ($productos as $index => $producto):
            // Calcular valores
            $margen = $producto['precio_venta'] - $producto['precio_compra'];
            $porcentaje_margen = $producto['precio_compra'] > 0 ? ($margen / $producto['precio_compra']) * 100 : 0;
            $valor_stock_compra = $producto['precio_compra'] * $producto['stock'];
            $valor_stock_venta = $producto['precio_venta'] * $producto['stock'];
            
            // Determinar estado de stock y clase CSS
            $estado_stock = 'Normal';
            $estado_class = '';
            if ($producto['stock'] <= $producto['stock_minimo']) {
                $estado_stock = 'BAJO';
                $estado_class = 'stock-bajo';
            }
            if ($producto['stock'] == 0) {
                $estado_stock = 'AGOTADO';
                $estado_class = 'stock-agotado';
            }
            
            // Determinar clase para margen
            $margen_class = '';
            if ($porcentaje_margen >= 30) {
                $margen_class = 'margen-alto';
            } elseif ($porcentaje_margen >= 10) {
                $margen_class = 'margen-medio';
            } elseif ($porcentaje_margen >= 0) {
                $margen_class = 'margen-bajo';
            } else {
                $margen_class = 'margen-negativo';
            }
            
            // Acumular totales
            $total_stock += $producto['stock'];
            $total_valor_compra += $valor_stock_compra;
            $total_valor_venta += $valor_stock_venta;
        ?>
        <tr>
            <td><?php echo $producto['id']; ?></td>
            <td><?php echo htmlspecialchars($producto['codigo']); ?></td>
            <td><?php echo htmlspecialchars($producto['nombre']); ?></td>
            <td><?php echo htmlspecialchars($producto['descripcion'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($producto['marca_nombre'] ?? 'Sin marca'); ?></td>
            <td><?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría'); ?></td>
            <td><?php echo htmlspecialchars($producto['talla'] ?? ''); ?></td>
            <td><?php echo htmlspecialchars($producto['color'] ?? ''); ?></td>
            <td><?php echo $producto['stock']; ?></td>
            <td><?php echo $producto['stock_minimo']; ?></td>
            <td class="<?php echo $estado_class; ?>"><?php echo $estado_stock; ?></td>
            <td>$<?php echo number_format($producto['precio_compra'], 2); ?></td>
            <td>$<?php echo number_format($producto['precio_venta'], 2); ?></td>
            <td class="<?php echo $margen_class; ?>"><?php echo number_format($porcentaje_margen, 2); ?>%</td>
            <td>$<?php echo number_format($valor_stock_compra, 2); ?></td>
            <td>$<?php echo number_format($valor_stock_venta, 2); ?></td>
            <td><?php echo htmlspecialchars($producto['codigo_barras'] ?? ''); ?></td>
            <td><?php echo date('d/m/Y H:i', strtotime($producto['created_at'])); ?></td>
        </tr>
        <?php endforeach; ?>
        
        <!-- Fila de totales -->
        <tr class="total">
            <td colspan="8" style="text-align: right;">TOTALES:</td>
            <td><?php echo $total_stock; ?></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td>$<?php echo number_format($total_valor_compra, 2); ?></td>
            <td>$<?php echo number_format($total_valor_venta, 2); ?></td>
            <td colspan="3"></td>
        </tr>
    </tbody>
</table>

<div class="notas">
    <strong>NOTAS:</strong><br>
    1. Estado Stock: BAJO = Stock menor o igual al mínimo; AGOTADO = Stock igual a cero; Normal = Stock por encima del mínimo<br>
    2. Margen %: Calculado como ((Precio Venta - Precio Compra) / Precio Compra) * 100<br>
    3. Valores de Stock: Calculados como Precio × Stock actual<br>
    Reporte generado automáticamente por el Sistema de Inventarios
</div>

</body>
</html>