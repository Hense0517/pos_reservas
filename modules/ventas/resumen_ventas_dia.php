<?php
session_start();
require_once '../config/database.php';

// Verificar permisos
if (!isset($_SESSION['usuario_rol']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor' && $_SESSION['usuario_rol'] != 'cajero')) {
    die('No autorizado');
}

$database = Database::getInstance();
$db = $database->getConnection();

// Fecha actual
$fecha_actual = date('Y-m-d');

// 1. OBTENER VENTAS DEL DÍA
$query_ventas = "SELECT COUNT(*) as total_ventas,
                 SUM(CASE WHEN tipo_venta = 'credito' THEN 1 ELSE 0 END) as ventas_credito,
                 SUM(CASE WHEN tipo_venta = 'contado' OR tipo_venta IS NULL OR tipo_venta = '' THEN 1 ELSE 0 END) as ventas_contado,
                 SUM(total) as total_ventas_monto,
                 SUM(CASE WHEN tipo_venta = 'credito' THEN abono_inicial ELSE 0 END) as abonos_credito,
                 SUM(CASE WHEN tipo_venta = 'contado' OR tipo_venta IS NULL OR tipo_venta = '' THEN total ELSE 0 END) as total_contado,
                 SUM(descuento) as total_descuentos
                 FROM ventas 
                 WHERE DATE(fecha) = ? AND anulada = 0";
$stmt_ventas = $db->prepare($query_ventas);
$stmt_ventas->execute([$fecha_actual]);
$resumen_ventas = $stmt_ventas->fetch(PDO::FETCH_ASSOC);

// 2. OBTENER PRODUCTOS MÁS VENDIDOS DEL DÍA
$query_productos = "SELECT p.id, p.nombre, p.codigo, p.codigo_barras,
                   SUM(vd.cantidad) as total_vendido,
                   SUM(vd.cantidad * vd.precio) as total_ingresos,
                   COUNT(DISTINCT vd.venta_id) as veces_vendido
                   FROM venta_detalles vd
                   INNER JOIN productos p ON vd.producto_id = p.id
                   INNER JOIN ventas v ON vd.venta_id = v.id
                   WHERE DATE(v.fecha) = ? AND v.anulada = 0
                   GROUP BY p.id, p.nombre, p.codigo
                   ORDER BY total_vendido DESC
                   LIMIT 15";
$stmt_productos = $db->prepare($query_productos);
$stmt_productos->execute([$fecha_actual]);
$productos_vendidos = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);

// 3. OBTENER VENTAS POR HORA
$query_por_hora = "SELECT 
                   HOUR(fecha) as hora,
                   COUNT(*) as cantidad_ventas,
                   SUM(total) as total_ventas
                   FROM ventas
                   WHERE DATE(fecha) = ? AND anulada = 0
                   GROUP BY HOUR(fecha)
                   ORDER BY hora";
$stmt_por_hora = $db->prepare($query_por_hora);
$stmt_por_hora->execute([$fecha_actual]);
$ventas_por_hora = $stmt_por_hora->fetchAll(PDO::FETCH_ASSOC);

// 4. OBTENER VENTAS POR MÉTODO DE PAGO
$query_por_metodo = "SELECT 
                     metodo_pago,
                     COUNT(*) as cantidad_ventas,
                     SUM(total) as total_ventas,
                     SUM(CASE WHEN tipo_venta = 'credito' THEN abono_inicial ELSE 0 END) as total_abonos
                     FROM ventas
                     WHERE DATE(fecha) = ? AND anulada = 0
                     GROUP BY metodo_pago
                     ORDER BY total_ventas DESC";
$stmt_por_metodo = $db->prepare($query_por_metodo);
$stmt_por_metodo->execute([$fecha_actual]);
$ventas_por_metodo = $stmt_por_metodo->fetchAll(PDO::FETCH_ASSOC);

// 5. OBTENER VENTAS POR VENDEDOR
$query_por_vendedor = "SELECT 
                       u.nombre as vendedor,
                       COUNT(*) as cantidad_ventas,
                       SUM(v.total) as total_ventas,
                       SUM(CASE WHEN v.tipo_venta = 'credito' THEN v.abono_inicial ELSE 0 END) as total_abonos
                       FROM ventas v
                       INNER JOIN usuarios u ON v.usuario_id = u.id
                       WHERE DATE(v.fecha) = ? AND v.anulada = 0
                       GROUP BY u.nombre
                       ORDER BY total_ventas DESC";
$stmt_por_vendedor = $db->prepare($query_por_vendedor);
$stmt_por_vendedor->execute([$fecha_actual]);
$ventas_por_vendedor = $stmt_por_vendedor->fetchAll(PDO::FETCH_ASSOC);

// Calcular ingresos reales
$ingresos_reales = ($resumen_ventas['total_contado'] ?? 0) + ($resumen_ventas['abonos_credito'] ?? 0);
?>

<div class="space-y-6">
    <!-- Resumen general -->
    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 p-6 rounded-lg">
        <h4 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-chart-bar mr-2"></i>
            Resumen General del Día
        </h4>
        
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="flex items-center mb-2">
                    <div class="p-2 rounded-full bg-blue-100 text-blue-600 mr-3">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Total Ventas</div>
                        <div class="text-xl font-bold text-gray-900"><?php echo $resumen_ventas['total_ventas'] ?? 0; ?></div>
                    </div>
                </div>
                <div class="text-xs text-gray-500 mt-2">
                    <span class="text-green-600">Contado: <?php echo $resumen_ventas['ventas_contado'] ?? 0; ?></span>
                    <span class="ml-2 text-purple-600">Crédito: <?php echo $resumen_ventas['ventas_credito'] ?? 0; ?></span>
                </div>
            </div>
            
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="flex items-center mb-2">
                    <div class="p-2 rounded-full bg-green-100 text-green-600 mr-3">
                        <i class="fas fa-cash-register"></i>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Ingresos Reales</div>
                        <div class="text-xl font-bold text-green-600">$<?php echo number_format($ingresos_reales, 0, ',', '.'); ?></div>
                    </div>
                </div>
                <div class="text-xs text-gray-500 mt-2">
                    Contado: $<?php echo number_format($resumen_ventas['total_contado'] ?? 0, 0, ',', '.'); ?><br>
                    Abonos: $<?php echo number_format($resumen_ventas['abonos_credito'] ?? 0, 0, ',', '.'); ?>
                </div>
            </div>
            
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="flex items-center mb-2">
                    <div class="p-2 rounded-full bg-indigo-100 text-indigo-600 mr-3">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Total Facturado</div>
                        <div class="text-xl font-bold text-indigo-600">$<?php echo number_format($resumen_ventas['total_ventas_monto'] ?? 0, 0, ',', '.'); ?></div>
                    </div>
                </div>
                <div class="text-xs text-gray-500 mt-2">
                    <span class="text-green-600">Contado: $<?php echo number_format($resumen_ventas['total_contado'] ?? 0, 0, ',', '.'); ?></span><br>
                    <span class="text-purple-600">Crédito: $<?php echo number_format(($resumen_ventas['total_ventas_monto'] ?? 0) - ($resumen_ventas['total_contado'] ?? 0), 0, ',', '.'); ?></span>
                </div>
            </div>
            
            <div class="bg-white p-4 rounded-lg shadow">
                <div class="flex items-center mb-2">
                    <div class="p-2 rounded-full bg-yellow-100 text-yellow-600 mr-3">
                        <i class="fas fa-tag"></i>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500">Descuentos</div>
                        <div class="text-xl font-bold text-gray-900">$<?php echo number_format($resumen_ventas['total_descuentos'] ?? 0, 0, ',', '.'); ?></div>
                    </div>
                </div>
                <div class="text-xs text-gray-500 mt-2">
                    <?php if ($resumen_ventas['total_ventas_monto'] > 0): ?>
                    <?php echo number_format(($resumen_ventas['total_descuentos'] / $resumen_ventas['total_ventas_monto']) * 100, 1); ?>% del total
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Sección 1: Productos más vendidos -->
    <div class="bg-white rounded-lg shadow p-4">
        <h4 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-boxes mr-2"></i>
            Productos Más Vendidos Hoy
        </h4>
        
        <?php if (count($productos_vendidos) > 0): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Producto</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Cantidad</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Veces Vendido</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Total Vendido</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($productos_vendidos as $producto): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo $producto['nombre']; ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?php echo $producto['codigo'] ?? $producto['codigo_barras'] ?? 'N/A'; ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 font-bold">
                                <?php echo $producto['total_vendido']; ?> unidades
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                <?php echo $producto['veces_vendido']; ?> veces
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-green-600 font-bold">
                                $<?php echo number_format($producto['total_ingresos'], 2); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-box-open text-3xl mb-2"></i>
                <p>No hay productos vendidos hoy</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Sección 2: Ventas por método de pago y vendedor -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Método de pago -->
        <div class="bg-white rounded-lg shadow p-4">
            <h4 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-credit-card mr-2"></i>
                Ventas por Método de Pago
            </h4>
            
            <?php if (count($ventas_por_metodo) > 0): ?>
                <div class="space-y-3">
                    <?php foreach ($ventas_por_metodo as $metodo): 
                        $metodo_icon = '';
                        $metodo_color = '';
                        $metodo_nombre = ucfirst($metodo['metodo_pago']);
                        
                        switch(strtolower($metodo['metodo_pago'])) {
                            case 'efectivo':
                                $metodo_icon = 'money-bill-wave';
                                $metodo_color = 'text-green-600 bg-green-100';
                                break;
                            case 'tarjeta':
                                $metodo_icon = 'credit-card';
                                $metodo_color = 'text-blue-600 bg-blue-100';
                                break;
                            case 'transferencia':
                                $metodo_icon = 'university';
                                $metodo_color = 'text-purple-600 bg-purple-100';
                                break;
                            case 'mixto':
                                $metodo_icon = 'random';
                                $metodo_color = 'text-orange-600 bg-orange-100';
                                break;
                            default:
                                $metodo_icon = 'money-bill-wave';
                                $metodo_color = 'text-gray-600 bg-gray-100';
                        }
                    ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center">
                            <div class="p-2 rounded-full <?php echo $metodo_color; ?> mr-3">
                                <i class="fas fa-<?php echo $metodo_icon; ?>"></i>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900"><?php echo $metodo_nombre; ?></div>
                                <div class="text-sm text-gray-500">
                                    <?php echo $metodo['cantidad_ventas']; ?> ventas
                                    <?php if ($metodo['total_abonos'] > 0): ?>
                                        | Abonos: $<?php echo number_format($metodo['total_abonos'], 0); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="font-bold text-gray-900">$<?php echo number_format($metodo['total_ventas'], 0, ',', '.'); ?></div>
                            <div class="text-xs text-gray-500">
                                <?php if ($resumen_ventas['total_ventas_monto'] > 0): ?>
                                <?php echo number_format(($metodo['total_ventas'] / $resumen_ventas['total_ventas_monto']) * 100, 1); ?>%
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-credit-card text-3xl mb-2"></i>
                    <p>No hay ventas registradas</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Vendedores -->
        <div class="bg-white rounded-lg shadow p-4">
            <h4 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-users mr-2"></i>
                Desempeño por Vendedor
            </h4>
            
            <?php if (count($ventas_por_vendedor) > 0): ?>
                <div class="space-y-3">
                    <?php foreach ($ventas_por_vendedor as $vendedor): ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center">
                            <div class="p-2 rounded-full bg-indigo-100 text-indigo-600 mr-3">
                                <i class="fas fa-user"></i>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900"><?php echo $vendedor['vendedor']; ?></div>
                                <div class="text-sm text-gray-500">
                                    <?php echo $vendedor['cantidad_ventas']; ?> ventas
                                    <?php if ($vendedor['total_abonos'] > 0): ?>
                                        | Abonos: $<?php echo number_format($vendedor['total_abonos'], 0); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="font-bold text-gray-900">$<?php echo number_format($vendedor['total_ventas'], 0, ',', '.'); ?></div>
                            <div class="text-xs text-gray-500">
                                Promedio: $<?php echo $vendedor['cantidad_ventas'] > 0 ? number_format($vendedor['total_ventas'] / $vendedor['cantidad_ventas'], 0) : '0'; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-user text-3xl mb-2"></i>
                    <p>No hay ventas registradas</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sección 3: Ventas por hora -->
    <div class="bg-white rounded-lg shadow p-4">
        <h4 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-clock mr-2"></i>
            Ventas por Hora
        </h4>
        
        <?php if (count($ventas_por_hora) > 0): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Hora</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Cantidad de Ventas</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Total Ventas</th>
                            <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Promedio por Venta</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($ventas_por_hora as $hora): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo sprintf('%02d:00 - %02d:00', $hora['hora'], $hora['hora'] + 1); ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                <?php echo $hora['cantidad_ventas']; ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-green-600 font-bold">
                                $<?php echo number_format($hora['total_ventas'], 2); ?>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                $<?php echo $hora['cantidad_ventas'] > 0 ? number_format($hora['total_ventas'] / $hora['cantidad_ventas'], 2) : '0.00'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Gráfico simple de barras -->
            <div class="mt-6">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-700">Distribución por hora</span>
                </div>
                <div class="flex items-end h-32 space-x-1">
                    <?php 
                    // Encontrar el valor máximo para escalar
                    $max_ventas = max(array_column($ventas_por_hora, 'total_ventas'));
                    if ($max_ventas == 0) $max_ventas = 1;
                    ?>
                    <?php for ($i = 8; $i <= 20; $i++): 
                        $hora_data = array_filter($ventas_por_hora, function($item) use ($i) {
                            return $item['hora'] == $i;
                        });
                        $venta_hora = !empty($hora_data) ? reset($hora_data)['total_ventas'] : 0;
                        $altura = ($venta_hora / $max_ventas) * 100;
                    ?>
                    <div class="flex-1 flex flex-col items-center">
                        <div class="w-full bg-blue-200 rounded-t" style="height: <?php echo $altura; ?>%"></div>
                        <div class="text-xs text-gray-500 mt-1"><?php echo $i; ?>h</div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-clock text-3xl mb-2"></i>
                <p>No hay ventas registradas por hora</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Botones de acción -->
    <div class="flex justify-center space-x-3 pt-4">
        <button onclick="imprimirResumen()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-print mr-2"></i>
            Imprimir Resumen
        </button>
        <button onclick="exportarResumen()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-file-excel mr-2"></i>
            Exportar a Excel
        </button>
        <a href="../ventas/index.php?fecha_inicio=<?php echo $fecha_actual; ?>&fecha_fin=<?php echo $fecha_actual; ?>" 
           target="_blank" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-external-link-alt mr-2"></i>
            Ver Ventas del Día
        </a>
    </div>
</div>

<script>
function imprimirResumen() {
    window.print();
}

function exportarResumen() {
    // Simulación de exportación - en un sistema real, esto llamaría a un endpoint
    alert('Funcionalidad de exportación a Excel disponible próximamente');
}
</script>

<style>
@media print {
    .bg-gradient-to-r, .shadow, .rounded-lg, button {
        display: none !important;
    }
    
    body {
        font-size: 12px;
    }
}
</style>