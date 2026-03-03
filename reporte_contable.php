<?php
// reporte_contable.php - VERSIÓN CORREGIDA
ob_start();
session_start();

// Incluir archivos necesarios
require_once 'config/database.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /pos/login.php');
    exit;
}

// Verificar permisos
if (!isset($_SESSION['usuario_rol']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor')) {
    header('Location: /pos/index.php');
    exit;
}

try {
    // Conexión a la base de datos
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("Error de conexión a la base de datos");
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Obtener parámetros de fechas
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$mostrar_detalles = isset($_GET['detalles']) ? true : false;

// Función para formatear moneda
function formato_moneda($valor) {
    if ($valor === null) return '$0.00';
    return '$' . number_format(floatval($valor), 2);
}

// Inicializar variables para evitar errores
$datos_ventas = [
    'total_ventas' => 0,
    'total_ventas_monto' => 0,
    'subtotal_ventas' => 0,
    'impuesto_ventas' => 0,
    'descuento_ventas' => 0,
    'promedio_venta' => 0
];

$datos_compras = [
    'total_compras' => 0,
    'total_compras_monto' => 0,
    'subtotal_compras' => 0,
    'impuesto_compras' => 0
];

$gastos_por_categoria = [];
$total_gastos = 0;
$ventas_diarias = [];
$top_productos = [];
$metodos_pago = [];

// Obtener datos de VENTAS - CONSULTA CORREGIDA
try {
    $query_ventas = "
        SELECT 
            COUNT(*) as total_ventas,
            COALESCE(SUM(total), 0) as total_ventas_monto,
            COALESCE(SUM(subtotal), 0) as subtotal_ventas,
            COALESCE(SUM(impuesto), 0) as impuesto_ventas,
            COALESCE(SUM(descuento), 0) as descuento_ventas,
            COALESCE(AVG(total), 0) as promedio_venta
        FROM ventas 
        WHERE fecha BETWEEN ? AND ? 
        AND estado = 'completada'
    ";
    $stmt_ventas = $db->prepare($query_ventas);
    $stmt_ventas->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
    $datos_ventas = $stmt_ventas->fetch(PDO::FETCH_ASSOC) ?: [];
    
} catch (Exception $e) {
    error_log("Error en consulta ventas: " . $e->getMessage());
}

// Obtener datos de COMPRAS - CONSULTA CORREGIDA
try {
    $query_compras = "
        SELECT 
            COUNT(*) as total_compras,
            COALESCE(SUM(total), 0) as total_compras_monto,
            COALESCE(SUM(subtotal), 0) as subtotal_compras,
            COALESCE(SUM(impuesto), 0) as impuesto_compras
        FROM compras 
        WHERE fecha BETWEEN ? AND ? 
        AND estado = 'completada'
    ";
    $stmt_compras = $db->prepare($query_compras);
    $stmt_compras->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
    $datos_compras = $stmt_compras->fetch(PDO::FETCH_ASSOC) ?: [];
    
} catch (Exception $e) {
    error_log("Error en consulta compras: " . $e->getMessage());
}

// Obtener datos de GASTOS - CONSULTA CORREGIDA
try {
    $query_gastos = "
        SELECT 
            COUNT(*) as total_gastos,
            COALESCE(SUM(monto), 0) as total_gastos_monto,
            COALESCE(categoria, 'General') as categoria
        FROM gastos 
        WHERE fecha BETWEEN ? AND ?
        GROUP BY categoria
    ";
    $stmt_gastos = $db->prepare($query_gastos);
    $stmt_gastos->execute([$fecha_inicio, $fecha_fin]);
    $gastos_por_categoria = $stmt_gastos->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($gastos_por_categoria as $gasto) {
        $total_gastos += floatval($gasto['total_gastos_monto']);
    }
    
} catch (Exception $e) {
    error_log("Error en consulta gastos: " . $e->getMessage());
}

// Calcular utilidades
$ingresos_netos = floatval($datos_ventas['total_ventas_monto'] ?? 0);
$costo_compras = floatval($datos_compras['total_compras_monto'] ?? 0);
$gastos_totales = floatval($total_gastos);

$utilidad_bruta = $ingresos_netos - $costo_compras;
$utilidad_neta = $utilidad_bruta - $gastos_totales;

// Obtener ventas por día - CONSULTA CORREGIDA
try {
    $query_ventas_diarias = "
        SELECT 
            DATE(fecha) as fecha,
            COUNT(*) as cantidad_ventas,
            COALESCE(SUM(total), 0) as total_dia
        FROM ventas 
        WHERE fecha BETWEEN ? AND ? 
        AND estado = 'completada'
        GROUP BY DATE(fecha)
        ORDER BY fecha
    ";
    $stmt_ventas_diarias = $db->prepare($query_ventas_diarias);
    $stmt_ventas_diarias->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
    $ventas_diarias = $stmt_ventas_diarias->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error en consulta ventas diarias: " . $e->getMessage());
}

// Obtener top productos vendidos - CONSULTA CORREGIDA
try {
    $query_top_productos = "
        SELECT 
            p.nombre,
            p.codigo,
            COALESCE(SUM(vd.cantidad), 0) as total_vendido,
            COALESCE(SUM(vd.subtotal), 0) as total_ingresos
        FROM productos p
        LEFT JOIN venta_detalles vd ON p.id = vd.producto_id
        LEFT JOIN ventas v ON vd.venta_id = v.id AND v.fecha BETWEEN ? AND ? AND v.estado = 'completada'
        GROUP BY p.id, p.nombre, p.codigo
        HAVING total_vendido > 0
        ORDER BY total_vendido DESC
        LIMIT 10
    ";
    $stmt_top_productos = $db->prepare($query_top_productos);
    $stmt_top_productos->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
    $top_productos = $stmt_top_productos->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error en consulta top productos: " . $e->getMessage());
}

// Métodos de pago - CONSULTA CORREGIDA
try {
    $query_metodos_pago = "
        SELECT 
            COALESCE(metodo_pago, 'efectivo') as metodo_pago,
            COUNT(*) as cantidad,
            COALESCE(SUM(total), 0) as total
        FROM ventas 
        WHERE fecha BETWEEN ? AND ? 
        AND estado = 'completada'
        GROUP BY metodo_pago
    ";
    $stmt_metodos_pago = $db->prepare($query_metodos_pago);
    $stmt_metodos_pago->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
    $metodos_pago = $stmt_metodos_pago->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error en consulta métodos pago: " . $e->getMessage());
}

// Incluir header después de todo el procesamiento
include 'includes/header.php';
?>

<div class="max-w-7xl mx-auto">
    <!-- Header -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200 bg-blue-600">
            <h2 class="text-xl font-semibold text-white">Reporte Contable General</h2>
            <p class="text-blue-100">Resumen financiero completo del negocio</p>
            <p class="text-blue-100 text-sm">Período: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></p>
        </div>
        
        <!-- Filtros -->
        <div class="p-6 border-b border-gray-200">
            <form method="GET" class="flex flex-col md:flex-row gap-4 items-end">
                <div class="flex-1 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="fecha_inicio" class="block text-sm font-medium text-gray-700">Fecha Inicio</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio" 
                               value="<?php echo $fecha_inicio; ?>" 
                               class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    <div>
                        <label for="fecha_fin" class="block text-sm font-medium text-gray-700">Fecha Fin</label>
                        <input type="date" id="fecha_fin" name="fecha_fin" 
                               value="<?php echo $fecha_fin; ?>" 
                               class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                <div class="flex space-x-2">
                    <div class="flex items-center">
                        <input type="checkbox" id="detalles" name="detalles" <?php echo $mostrar_detalles ? 'checked' : ''; ?> 
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <label for="detalles" class="ml-2 text-sm text-gray-700">Mostrar detalles</label>
                    </div>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm font-medium">
                        <i class="fas fa-filter mr-2"></i>Filtrar
                    </button>
                    <button type="button" onclick="imprimirReporte()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md text-sm font-medium">
                        <i class="fas fa-print mr-2"></i>Imprimir
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Resumen General -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <!-- Ventas -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-shopping-cart text-green-500 text-2xl"></i>
                </div>
                <div class="ml-4">
                    <h3 class="text-sm font-medium text-gray-500">Total Ventas</h3>
                    <p class="text-2xl font-bold text-gray-900"><?php echo formato_moneda($datos_ventas['total_ventas_monto']); ?></p>
                    <p class="text-sm text-gray-600"><?php echo $datos_ventas['total_ventas']; ?> transacciones</p>
                </div>
            </div>
        </div>

        <!-- Compras -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-truck-loading text-blue-500 text-2xl"></i>
                </div>
                <div class="ml-4">
                    <h3 class="text-sm font-medium text-gray-500">Total Compras</h3>
                    <p class="text-2xl font-bold text-gray-900"><?php echo formato_moneda($datos_compras['total_compras_monto']); ?></p>
                    <p class="text-sm text-gray-600"><?php echo $datos_compras['total_compras']; ?> compras</p>
                </div>
            </div>
        </div>

        <!-- Gastos -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-red-500">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-money-bill-wave text-red-500 text-2xl"></i>
                </div>
                <div class="ml-4">
                    <h3 class="text-sm font-medium text-gray-500">Total Gastos</h3>
                    <p class="text-2xl font-bold text-gray-900"><?php echo formato_moneda($total_gastos); ?></p>
                    <p class="text-sm text-gray-600">Operativos y administrativos</p>
                </div>
            </div>
        </div>

        <!-- Utilidad Neta -->
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-purple-500">
            <div class="flex items-center">
                <div class="flex-shrink-0">
                    <i class="fas fa-chart-line text-purple-500 text-2xl"></i>
                </div>
                <div class="ml-4">
                    <h3 class="text-sm font-medium text-gray-500">Utilidad Neta</h3>
                    <p class="text-2xl font-bold <?php echo $utilidad_neta >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                        <?php echo formato_moneda($utilidad_neta); ?>
                    </p>
                    <p class="text-sm text-gray-600">Después de gastos</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Estado de Resultados -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Estado de Resultados</h3>
        </div>
        <div class="p-6">
            <div class="space-y-4">
                <!-- Ingresos -->
                <div class="border-b border-gray-200 pb-4">
                    <h4 class="font-medium text-gray-900 mb-2">Ingresos</h4>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Ventas Totales:</span>
                            <span class="font-medium"><?php echo formato_moneda($datos_ventas['total_ventas_monto']); ?></span>
                        </div>
                        <div class="flex justify-between text-sm text-gray-500">
                            <span>Descuentos aplicados:</span>
                            <span>-<?php echo formato_moneda($datos_ventas['descuento_ventas']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Costos -->
                <div class="border-b border-gray-200 pb-4">
                    <h4 class="font-medium text-gray-900 mb-2">Costos</h4>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Compras de Inventario:</span>
                            <span class="font-medium"><?php echo formato_moneda($datos_compras['total_compras_monto']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Utilidad Bruta -->
                <div class="border-b border-gray-200 pb-4">
                    <div class="flex justify-between font-medium text-lg">
                        <span>Utilidad Bruta:</span>
                        <span class="<?php echo $utilidad_bruta >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo formato_moneda($utilidad_bruta); ?>
                        </span>
                    </div>
                </div>

                <!-- Gastos -->
                <div class="border-b border-gray-200 pb-4">
                    <h4 class="font-medium text-gray-900 mb-2">Gastos Operativos</h4>
                    <div class="space-y-2">
                        <?php if (!empty($gastos_por_categoria)): ?>
                            <?php foreach ($gastos_por_categoria as $gasto): ?>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-600"><?php echo $gasto['categoria'] ?: 'General'; ?>:</span>
                                <span><?php echo formato_moneda($gasto['total_gastos_monto']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-sm text-gray-500 text-center py-2">No hay gastos registrados en este período</div>
                        <?php endif; ?>
                        <div class="flex justify-between font-medium border-t border-gray-200 pt-2">
                            <span>Total Gastos:</span>
                            <span class="text-red-600">-<?php echo formato_moneda($total_gastos); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Utilidad Neta -->
                <div class="pt-4">
                    <div class="flex justify-between font-bold text-xl">
                        <span>UTILIDAD NETA:</span>
                        <span class="<?php echo $utilidad_neta >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo formato_moneda($utilidad_neta); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Métodos de Pago -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Métodos de Pago</h3>
        </div>
        <div class="p-6">
            <div class="space-y-4">
                <?php if (!empty($metodos_pago)): ?>
                    <?php foreach ($metodos_pago as $metodo): ?>
                    <div class="flex justify-between items-center p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center">
                            <i class="fas 
                                <?php 
                                switch($metodo['metodo_pago']) {
                                    case 'efectivo': echo 'fa-money-bill'; break;
                                    case 'tarjeta': echo 'fa-credit-card'; break;
                                    case 'transferencia': echo 'fa-exchange-alt'; break;
                                    case 'mixto': echo 'fa-random'; break;
                                    default: echo 'fa-money-bill';
                                }
                                ?> 
                                text-blue-500 mr-3">
                            </i>
                            <span class="font-medium text-gray-700 capitalize"><?php echo $metodo['metodo_pago']; ?></span>
                        </div>
                        <div class="text-right">
                            <div class="font-bold text-gray-900"><?php echo formato_moneda($metodo['total']); ?></div>
                            <div class="text-sm text-gray-500"><?php echo $metodo['cantidad']; ?> transacciones</div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-4 text-gray-500">
                        No hay transacciones en este período
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Top Productos -->
    <?php if (!empty($top_productos)): ?>
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Productos Más Vendidos</h3>
        </div>
        <div class="p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Producto</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Código</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cantidad Vendida</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Ingresos</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($top_productos as $producto): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $producto['nombre']; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $producto['codigo']; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $producto['total_vendido']; ?> unidades</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo formato_moneda($producto['total_ingresos']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
function imprimirReporte() {
    window.print();
}
</script>

<?php include 'includes/footer.php'; ?>