<?php
// modules/reportes/reportes_profesional.php
ob_start();
session_start();

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /pos/login.php');
    exit;
}

// Verificar permisos
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] != 'admin') {
    header('Location: /pos/index.php');
    exit;
}

// Incluir archivos necesarios
require_once '../../config/database.php';
require_once '../../includes/header.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    if (!$db) {
        throw new Exception("Error de conexión a la base de datos");
    }
    
} catch (Exception $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Obtener parámetros de fechas
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$tipo_reporte = $_GET['tipo_reporte'] ?? 'general';

// Función para formatear moneda
function formato_moneda($valor) {
    if ($valor === null || $valor === '') return '$0.00';
    return '$' . number_format(floatval($valor), 2);
}

// Función para formatear porcentaje
function formato_porcentaje($valor, $decimales = 1) {
    if ($valor === null || $valor === '') return '0.0%';
    return number_format(floatval($valor), $decimales) . '%';
}

// COLORES para gráficos (paleta profesional)
$colores = [
    'ventas' => ['fondo' => 'rgba(16, 185, 129, 0.1)', 'borde' => 'rgb(16, 185, 129)', 'texto' => '#10b981'],
    'compras' => ['fondo' => 'rgba(59, 130, 246, 0.1)', 'borde' => 'rgb(59, 130, 246)', 'texto' => '#3b82f6'],
    'gastos' => ['fondo' => 'rgba(239, 68, 68, 0.1)', 'borde' => 'rgb(239, 68, 68)', 'texto' => '#ef4444'],
    'utilidad' => ['fondo' => 'rgba(139, 92, 246, 0.1)', 'borde' => 'rgb(139, 92, 246)', 'texto' => '#8b5cf6'],
    'clientes' => ['fondo' => 'rgba(245, 158, 11, 0.1)', 'borde' => 'rgb(245, 158, 11)', 'texto' => '#f59e0b'],
    'productos' => ['fondo' => 'rgba(14, 165, 233, 0.1)', 'borde' => 'rgb(14, 165, 233)', 'texto' => '#0ea5e9'],
];

// ============================================================================
// CONSULTAS DE DATOS GENERALES
// ============================================================================

// 1. DATOS PRINCIPALES DEL PERÍODO
$datos_periodo = [
    'ventas' => ['total' => 0, 'cantidad' => 0, 'promedio' => 0],
    'compras' => ['total' => 0, 'cantidad' => 0],
    'gastos' => ['total' => 0],
    'utilidad' => ['bruta' => 0, 'neta' => 0],
];

try {
    // Ventas
    $query = "SELECT 
        COUNT(*) as cantidad,
        COALESCE(SUM(total), 0) as total,
        COALESCE(AVG(total), 0) as promedio,
        COALESCE(SUM(CASE WHEN metodo_pago = 'efectivo' THEN total ELSE 0 END), 0) as efectivo,
        COALESCE(SUM(CASE WHEN metodo_pago = 'tarjeta' THEN total ELSE 0 END), 0) as tarjeta,
        COALESCE(SUM(CASE WHEN metodo_pago = 'transferencia' THEN total ELSE 0 END), 0) as transferencia,
        COALESCE(SUM(CASE WHEN tipo_venta = 'credito' THEN total ELSE 0 END), 0) as credito
    FROM ventas 
    WHERE fecha BETWEEN ? AND ? 
    AND anulada = 0";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
    $datos_periodo['ventas'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: $datos_periodo['ventas'];
    
    // Compras
    $query = "SELECT 
        COUNT(*) as cantidad,
        COALESCE(SUM(total), 0) as total
    FROM compras 
    WHERE fecha BETWEEN ? AND ? 
    AND estado != 'cancelada'";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
    $datos_periodo['compras'] = $stmt->fetch(PDO::FETCH_ASSOC) ?: $datos_periodo['compras'];
    
    // Gastos
    $query = "SELECT 
        COALESCE(SUM(monto), 0) as total
    FROM gastos 
    WHERE fecha BETWEEN ? AND ?";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$fecha_inicio, $fecha_fin]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $datos_periodo['gastos']['total'] = $result['total'] ?? 0;
    
} catch (Exception $e) {
    // Manejo de errores silencioso
}

// Calcular utilidades
$datos_periodo['utilidad']['bruta'] = $datos_periodo['ventas']['total'] - $datos_periodo['compras']['total'];
$datos_periodo['utilidad']['neta'] = $datos_periodo['utilidad']['bruta'] - $datos_periodo['gastos']['total'];

// ============================================================================
// CONSULTAS ESPECÍFICAS SEGÚN TIPO DE REPORTE
// ============================================================================

$datos_especificos = [];

switch($tipo_reporte) {
    case 'ventas':
        // Datos detallados de ventas
        try {
            // Ventas por día
            $query = "SELECT 
                DATE(fecha) as dia,
                COUNT(*) as cantidad,
                COALESCE(SUM(total), 0) as total,
                COALESCE(AVG(total), 0) as promedio
            FROM ventas 
            WHERE fecha BETWEEN ? AND ? 
            AND anulada = 0
            GROUP BY DATE(fecha)
            ORDER BY dia";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
            $datos_especificos['ventas_diarias'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Ventas por método de pago
            $query = "SELECT 
                CASE 
                    WHEN metodo_pago = '' THEN 'efectivo'
                    ELSE metodo_pago 
                END as metodo,
                COUNT(*) as cantidad,
                COALESCE(SUM(total), 0) as total
            FROM ventas 
            WHERE fecha BETWEEN ? AND ? 
            AND anulada = 0
            GROUP BY metodo
            ORDER BY total DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
            $datos_especificos['metodos_pago'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Top 10 productos más vendidos
            $query = "SELECT 
                p.nombre,
                SUM(dv.cantidad) as cantidad_vendida,
                SUM(dv.subtotal) as total_vendido,
                COUNT(DISTINCT dv.venta_id) as veces_vendido
            FROM detalles_venta dv
            JOIN productos p ON dv.producto_id = p.id
            JOIN ventas v ON dv.venta_id = v.id
            WHERE v.fecha BETWEEN ? AND ? 
            AND v.anulada = 0
            GROUP BY p.id, p.nombre
            ORDER BY cantidad_vendida DESC
            LIMIT 10";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
            $datos_especificos['top_productos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            // Manejo de errores
        }
        break;
        
    case 'compras':
        // Datos detallados de compras
        try {
            // Compras por proveedor
            $query = "SELECT 
                pr.nombre as proveedor,
                COUNT(c.id) as cantidad,
                COALESCE(SUM(c.total), 0) as total
            FROM compras c
            LEFT JOIN proveedores pr ON c.proveedor_id = pr.id
            WHERE c.fecha BETWEEN ? AND ? 
            AND c.estado != 'cancelada'
            GROUP BY pr.id, pr.nombre
            ORDER BY total DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
            $datos_especificos['compras_proveedor'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Compras por día
            $query = "SELECT 
                DATE(fecha) as dia,
                COUNT(*) as cantidad,
                COALESCE(SUM(total), 0) as total
            FROM compras 
            WHERE fecha BETWEEN ? AND ? 
            AND estado != 'cancelada'
            GROUP BY DATE(fecha)
            ORDER BY dia";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
            $datos_especificos['compras_diarias'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            // Manejo de errores
        }
        break;
        
    case 'gastos':
        // Datos detallados de gastos
        try {
            // Gastos por categoría
            $query = "SELECT 
                categoria,
                COUNT(*) as cantidad,
                COALESCE(SUM(monto), 0) as total,
                COALESCE(AVG(monto), 0) as promedio
            FROM gastos 
            WHERE fecha BETWEEN ? AND ?
            GROUP BY categoria
            ORDER BY total DESC";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$fecha_inicio, $fecha_fin]);
            $datos_especificos['gastos_categoria'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Gastos por día
            $query = "SELECT 
                DATE(fecha) as dia,
                COUNT(*) as cantidad,
                COALESCE(SUM(monto), 0) as total
            FROM gastos 
            WHERE fecha BETWEEN ? AND ?
            GROUP BY DATE(fecha)
            ORDER BY dia";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$fecha_inicio, $fecha_fin]);
            $datos_especificos['gastos_diarios'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            // Manejo de errores
        }
        break;
        
    case 'inventario':
        // Datos de inventario
        try {
            // Productos con bajo stock
            $query = "SELECT 
                nombre,
                codigo,
                stock,
                stock_minimo,
                precio_venta,
                (stock * precio_venta) as valor_stock
            FROM productos 
            WHERE activo = 1
            AND stock <= stock_minimo
            ORDER BY (stock_minimo - stock) DESC
            LIMIT 15";
            
            $stmt = $db->prepare($query);
            $stmt->execute();
            $datos_especificos['bajo_stock'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Valor total del inventario
            $query = "SELECT 
                COUNT(*) as total_productos,
                COALESCE(SUM(stock), 0) as total_unidades,
                COALESCE(SUM(stock * precio_compra), 0) as valor_compra,
                COALESCE(SUM(stock * precio_venta), 0) as valor_venta
            FROM productos 
            WHERE activo = 1";
            
            $stmt = $db->prepare($query);
            $stmt->execute();
            $datos_especificos['inventario_total'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            // Manejo de errores
        }
        break;
        
    default: // Reporte general
        try {
            // Datos para gráficos generales
            $query = "SELECT 
                DATE(fecha) as dia,
                COALESCE(SUM(CASE WHEN tipo = 'venta' THEN monto ELSE 0 END), 0) as ventas,
                COALESCE(SUM(CASE WHEN tipo = 'compra' THEN monto ELSE 0 END), 0) as compras,
                COALESCE(SUM(CASE WHEN tipo = 'gasto' THEN monto ELSE 0 END), 0) as gastos
            FROM (
                SELECT fecha, total as monto, 'venta' as tipo FROM ventas WHERE anulada = 0
                UNION ALL
                SELECT fecha, total as monto, 'compra' as tipo FROM compras WHERE estado != 'cancelada'
                UNION ALL
                SELECT fecha, monto, 'gasto' as tipo FROM gastos
            ) as transacciones
            WHERE fecha BETWEEN ? AND ?
            GROUP BY DATE(fecha)
            ORDER BY dia";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$fecha_inicio . ' 00:00:00', $fecha_fin . ' 23:59:59']);
            $datos_especificos['tendencias'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            // Manejo de errores
        }
        break;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reportes Profesionales</title>
    <!-- Chart.js para gráficos -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Font Awesome para iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --color-ventas: #10b981;
            --color-compras: #3b82f6;
            --color-gastos: #ef4444;
            --color-utilidad: #8b5cf6;
            --color-clientes: #f59e0b;
            --color-productos: #0ea5e9;
        }
        
        .card-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .card-stats {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .card-stats:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .progress-bar {
            height: 8px;
            border-radius: 4px;
            background: linear-gradient(90deg, #f0f4ff 0%, #e0e7ff 100%);
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.6s ease;
        }
        
        .metric-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .trend-up {
            background-color: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .trend-down {
            background-color: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .trend-neutral {
            background-color: rgba(107, 114, 128, 0.1);
            color: #6b7280;
        }
        
        .nav-reportes {
            background: linear-gradient(to right, #f8fafc, #f1f5f9);
            border-radius: 12px;
            padding: 4px;
        }
        
        .nav-reportes .nav-link {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            color: #64748b;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .nav-reportes .nav-link.active {
            background: white;
            color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.1);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .data-table {
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .data-table th {
            background-color: #f8fafc;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .data-table tr:hover {
            background-color: #f1f5f9;
        }
        
        .kpi-card {
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        
        .kpi-header {
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        
        .kpi-body {
            padding: 20px;
        }
        
        .value-display {
            font-size: 2.5rem;
            font-weight: 800;
            line-height: 1;
            background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .info-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        
        .print-only {
            display: none;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            
            .print-only {
                display: block;
            }
            
            .card {
                break-inside: avoid;
                border: 1px solid #ddd !important;
                box-shadow: none !important;
            }
            
            .chart-container {
                height: 200px;
            }
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="max-w-7xl mx-auto px-4 py-6">
        <!-- Encabezado -->
        <div class="mb-8">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 mb-2">Reportes Contables</h1>
                    <p class="text-gray-600">Análisis financiero detallado del negocio</p>
                    <div class="flex items-center gap-2 mt-2">
                        <span class="info-badge bg-blue-100 text-blue-800">
                            <i class="fas fa-calendar-alt"></i>
                            <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>
                        </span>
                        <span class="info-badge bg-green-100 text-green-800">
                            <i class="fas fa-clock"></i>
                            Generado: <?php echo date('d/m/Y H:i'); ?>
                        </span>
                    </div>
                </div>
                
                <div class="flex gap-3">
                    <button onclick="imprimirReporte()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 flex items-center gap-2 no-print">
                        <i class="fas fa-print"></i>
                        <span>Imprimir</span>
                    </button>
                    <button onclick="exportarPDF()" class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700 flex items-center gap-2 no-print">
                        <i class="fas fa-file-pdf"></i>
                        <span>Exportar PDF</span>
                    </button>
                </div>
            </div>
            
            <!-- Navegación entre reportes -->
            <div class="nav-reportes mb-8 no-print">
                <div class="flex flex-wrap gap-2">
                    <a href="?tipo_reporte=general&fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" 
                       class="nav-link <?php echo $tipo_reporte == 'general' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-pie"></i>
                        General
                    </a>
                    <a href="?tipo_reporte=ventas&fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" 
                       class="nav-link <?php echo $tipo_reporte == 'ventas' ? 'active' : ''; ?>">
                        <i class="fas fa-shopping-cart"></i>
                        Ventas
                    </a>
                    <a href="?tipo_reporte=compras&fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" 
                       class="nav-link <?php echo $tipo_reporte == 'compras' ? 'active' : ''; ?>">
                        <i class="fas fa-truck-loading"></i>
                        Compras
                    </a>
                    <a href="?tipo_reporte=gastos&fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" 
                       class="nav-link <?php echo $tipo_reporte == 'gastos' ? 'active' : ''; ?>">
                        <i class="fas fa-money-bill-wave"></i>
                        Gastos
                    </a>
                    <a href="?tipo_reporte=inventario&fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" 
                       class="nav-link <?php echo $tipo_reporte == 'inventario' ? 'active' : ''; ?>">
                        <i class="fas fa-boxes"></i>
                        Inventario
                    </a>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8 no-print">
            <div class="flex flex-col lg:flex-row gap-6">
                <div class="flex-1">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
                        <i class="fas fa-filter text-blue-500"></i>
                        Filtros del Reporte
                    </h3>
                    
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <input type="hidden" name="tipo_reporte" value="<?php echo $tipo_reporte; ?>">
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-start mr-1"></i>
                                Fecha Inicio
                            </label>
                            <input type="date" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>" 
                                   class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-calendar-end mr-1"></i>
                                Fecha Fin
                            </label>
                            <input type="date" name="fecha_fin" value="<?php echo $fecha_fin; ?>" 
                                   class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                        </div>
                        
                        <div class="flex items-end">
                            <button type="submit" 
                                    class="w-full bg-gradient-to-r from-blue-600 to-blue-700 text-white px-6 py-3 rounded-lg hover:from-blue-700 hover:to-blue-800 transition-all flex items-center justify-center gap-2 font-medium">
                                <i class="fas fa-sync-alt"></i>
                                Actualizar Reporte
                            </button>
                        </div>
                    </form>
                    
                    <div class="mt-4 flex flex-wrap gap-2">
                        <button type="button" onclick="setFechas('hoy')" 
                                class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm">
                            Hoy
                        </button>
                        <button type="button" onclick="setFechas('semana')" 
                                class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm">
                            Esta Semana
                        </button>
                        <button type="button" onclick="setFechas('mes')" 
                                class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm">
                            Este Mes
                        </button>
                        <button type="button" onclick="setFechas('anio')" 
                                class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm">
                            Este Año
                        </button>
                    </div>
                </div>
                
                <div class="lg:w-64">
                    <div class="bg-gradient-to-br from-blue-50 to-blue-100 border border-blue-200 rounded-xl p-5">
                        <h4 class="font-semibold text-blue-900 mb-3 flex items-center gap-2">
                            <i class="fas fa-info-circle"></i>
                            Información del Período
                        </h4>
                        <div class="space-y-2">
                            <div class="flex justify-between">
                                <span class="text-sm text-blue-700">Días:</span>
                                <span class="text-sm font-semibold text-blue-900">
                                    <?php echo round((strtotime($fecha_fin) - strtotime($fecha_inicio)) / (60 * 60 * 24)) + 1; ?>
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-sm text-blue-700">Estado:</span>
                                <span class="text-sm font-semibold <?php echo $datos_periodo['utilidad']['neta'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $datos_periodo['utilidad']['neta'] >= 0 ? 'Positivo' : 'Negativo'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Ventas -->
            <div class="kpi-card card-stats">
                <div class="kpi-header">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold text-gray-900">Ventas</h3>
                        <span class="metric-badge trend-up">
                            <i class="fas fa-arrow-up"></i>
                            <?php echo $datos_periodo['ventas']['cantidad'] > 0 ? 'Activo' : 'Inactivo'; ?>
                        </span>
                    </div>
                    <div class="value-display mb-2">
                        <?php echo formato_moneda($datos_periodo['ventas']['total']); ?>
                    </div>
                    <div class="text-sm text-gray-600">
                        <?php echo $datos_periodo['ventas']['cantidad']; ?> transacciones
                    </div>
                </div>
                <div class="kpi-body">
                    <div class="space-y-3">
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">Promedio/venta</span>
                                <span class="font-medium"><?php echo formato_moneda($datos_periodo['ventas']['promedio']); ?></span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill bg-green-500" 
                                     style="width: <?php echo min(($datos_periodo['ventas']['promedio'] / 500) * 100, 100); ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Compras -->
            <div class="kpi-card card-stats">
                <div class="kpi-header">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold text-gray-900">Compras</h3>
                        <span class="metric-badge trend-neutral">
                            <i class="fas fa-sync-alt"></i>
                            <?php echo $datos_periodo['compras']['cantidad']; ?> órdenes
                        </span>
                    </div>
                    <div class="value-display mb-2" style="background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                        <?php echo formato_moneda($datos_periodo['compras']['total']); ?>
                    </div>
                    <div class="text-sm text-gray-600">
                        Inversión en inventario
                    </div>
                </div>
                <div class="kpi-body">
                    <div class="space-y-3">
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">Por transacción</span>
                                <span class="font-medium">
                                    <?php echo $datos_periodo['compras']['cantidad'] > 0 ? formato_moneda($datos_periodo['compras']['total'] / $datos_periodo['compras']['cantidad']) : '$0.00'; ?>
                                </span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill bg-blue-500" 
                                     style="width: <?php echo min(($datos_periodo['compras']['total'] / ($datos_periodo['ventas']['total'] ?: 1)) * 100, 100); ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gastos -->
            <div class="kpi-card card-stats">
                <div class="kpi-header">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold text-gray-900">Gastos</h3>
                        <span class="metric-badge trend-down">
                            <i class="fas fa-chart-line"></i>
                            Operativos
                        </span>
                    </div>
                    <div class="value-display mb-2" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                        <?php echo formato_moneda($datos_periodo['gastos']['total']); ?>
                    </div>
                    <div class="text-sm text-gray-600">
                        Costos operativos
                    </div>
                </div>
                <div class="kpi-body">
                    <div class="space-y-3">
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">% de ventas</span>
                                <span class="font-medium">
                                    <?php echo $datos_periodo['ventas']['total'] > 0 ? 
                                           number_format(($datos_periodo['gastos']['total'] / $datos_periodo['ventas']['total']) * 100, 1) . '%' : '0%'; ?>
                                </span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill bg-red-500" 
                                     style="width: <?php echo min(($datos_periodo['gastos']['total'] / ($datos_periodo['ventas']['total'] ?: 1)) * 100, 100); ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Utilidad Neta -->
            <div class="kpi-card card-stats">
                <div class="kpi-header">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="font-semibold text-gray-900">Utilidad Neta</h3>
                        <span class="metric-badge <?php echo $datos_periodo['utilidad']['neta'] >= 0 ? 'trend-up' : 'trend-down'; ?>">
                            <i class="fas <?php echo $datos_periodo['utilidad']['neta'] >= 0 ? 'fa-arrow-up' : 'fa-arrow-down'; ?>"></i>
                            <?php echo $datos_periodo['utilidad']['neta'] >= 0 ? 'Rentable' : 'Pérdida'; ?>
                        </span>
                    </div>
                    <div class="value-display mb-2" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">
                        <?php echo formato_moneda($datos_periodo['utilidad']['neta']); ?>
                    </div>
                    <div class="text-sm text-gray-600">
                        Resultado final
                    </div>
                </div>
                <div class="kpi-body">
                    <div class="space-y-3">
                        <div>
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600">Margen neto</span>
                                <span class="font-medium <?php echo $datos_periodo['utilidad']['neta'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    <?php echo $datos_periodo['ventas']['total'] > 0 ? 
                                           number_format(($datos_periodo['utilidad']['neta'] / $datos_periodo['ventas']['total']) * 100, 1) . '%' : '0%'; ?>
                                </span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill <?php echo $datos_periodo['utilidad']['neta'] >= 0 ? 'bg-purple-500' : 'bg-red-500'; ?>" 
                                     style="width: <?php echo min(abs($datos_periodo['utilidad']['neta'] / ($datos_periodo['ventas']['total'] ?: 1)) * 100, 100); ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contenido específico del reporte -->
        <?php switch($tipo_reporte): 
            case 'ventas': ?>
                <!-- Reporte de Ventas -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- Gráfico de ventas diarias -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <i class="fas fa-chart-line text-green-500"></i>
                            Ventas Diarias
                        </h3>
                        <div class="chart-container">
                            <canvas id="chartVentasDiarias"></canvas>
                        </div>
                    </div>

                    <!-- Métodos de pago -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <i class="fas fa-credit-card text-blue-500"></i>
                            Métodos de Pago
                        </h3>
                        <div class="chart-container">
                            <canvas id="chartMetodosPago"></canvas>
                        </div>
                        <div class="mt-6 space-y-3">
                            <?php if(isset($datos_especificos['metodos_pago'])): ?>
                                <?php foreach($datos_especificos['metodos_pago'] as $metodo): 
                                    $porcentaje = $datos_periodo['ventas']['total'] > 0 ? ($metodo['total'] / $datos_periodo['ventas']['total']) * 100 : 0;
                                ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                    <div class="flex items-center gap-3">
                                        <div class="w-3 h-3 rounded-full 
                                            <?php echo $metodo['metodo'] == 'efectivo' ? 'bg-green-500' : 
                                                  ($metodo['metodo'] == 'tarjeta' ? 'bg-blue-500' : 'bg-purple-500'); ?>">
                                        </div>
                                        <span class="font-medium text-gray-900"><?php echo ucfirst($metodo['metodo']); ?></span>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-gray-900"><?php echo formato_moneda($metodo['total']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo number_format($porcentaje, 1); ?>%</div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Top productos -->
                <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                        <i class="fas fa-crown text-yellow-500"></i>
                        Top 10 Productos Más Vendidos
                    </h3>
                    <div class="overflow-x-auto">
                        <table class="w-full data-table">
                            <thead>
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Producto</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cantidad</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Vendido</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Veces Vendido</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">% del Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php if(isset($datos_especificos['top_productos'])): ?>
                                    <?php foreach($datos_especificos['top_productos'] as $index => $producto): 
                                        $porcentaje = $datos_periodo['ventas']['total'] > 0 ? ($producto['total_vendido'] / $datos_periodo['ventas']['total']) * 100 : 0;
                                    ?>
                                    <tr>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <span class="w-8 h-8 flex items-center justify-center bg-blue-100 text-blue-600 rounded-lg mr-3 font-bold">
                                                    <?php echo $index + 1; ?>
                                                </span>
                                                <span class="font-medium text-gray-900"><?php echo htmlspecialchars($producto['nombre']); ?></span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-gray-900 font-semibold"><?php echo number_format($producto['cantidad_vendida']); ?></td>
                                        <td class="px-6 py-4 text-green-600 font-semibold"><?php echo formato_moneda($producto['total_vendido']); ?></td>
                                        <td class="px-6 py-4 text-gray-700"><?php echo $producto['veces_vendido']; ?> veces</td>
                                        <td class="px-6 py-4">
                                            <div class="flex items-center gap-2">
                                                <div class="flex-1 progress-bar">
                                                    <div class="progress-fill bg-green-500" style="width: <?php echo $porcentaje; ?>%"></div>
                                                </div>
                                                <span class="text-sm font-medium text-gray-700"><?php echo number_format($porcentaje, 1); ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                            <i class="fas fa-box-open text-4xl mb-3 opacity-50"></i>
                                            <p>No hay datos de productos vendidos en este período</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php break; ?>
                
            <?php case 'compras': ?>
                <!-- Reporte de Compras -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- Compras por proveedor -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <i class="fas fa-building text-blue-500"></i>
                            Compras por Proveedor
                        </h3>
                        <div class="chart-container">
                            <canvas id="chartComprasProveedor"></canvas>
                        </div>
                        <div class="mt-6 space-y-3">
                            <?php if(isset($datos_especificos['compras_proveedor'])): ?>
                                <?php foreach($datos_especificos['compras_proveedor'] as $proveedor): ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                                            <i class="fas fa-truck text-blue-600"></i>
                                        </div>
                                        <span class="font-medium text-gray-900"><?php echo htmlspecialchars($proveedor['proveedor'] ?: 'Sin proveedor'); ?></span>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-blue-600"><?php echo formato_moneda($proveedor['total']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo $proveedor['cantidad']; ?> compras</div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Compras diarias -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <i class="fas fa-calendar-alt text-green-500"></i>
                            Tendencia de Compras
                        </h3>
                        <div class="chart-container">
                            <canvas id="chartComprasDiarias"></canvas>
                        </div>
                    </div>
                </div>
                <?php break; ?>
                
            <?php case 'gastos': ?>
                <!-- Reporte de Gastos -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- Gastos por categoría -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <i class="fas fa-tags text-red-500"></i>
                            Distribución por Categoría
                        </h3>
                        <div class="chart-container">
                            <canvas id="chartGastosCategoria"></canvas>
                        </div>
                    </div>

                    <!-- Detalle de gastos -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <i class="fas fa-list-ol text-orange-500"></i>
                            Detalle de Gastos
                        </h3>
                        <div class="space-y-4">
                            <?php if(isset($datos_especificos['gastos_categoria'])): ?>
                                <?php foreach($datos_especificos['gastos_categoria'] as $categoria): 
                                    $porcentaje = $datos_periodo['gastos']['total'] > 0 ? ($categoria['total'] / $datos_periodo['gastos']['total']) * 100 : 0;
                                ?>
                                <div class="p-4 bg-gray-50 rounded-lg">
                                    <div class="flex items-center justify-between mb-2">
                                        <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($categoria['categoria']); ?></h4>
                                        <span class="font-semibold text-red-600"><?php echo formato_moneda($categoria['total']); ?></span>
                                    </div>
                                    <div class="flex items-center justify-between text-sm text-gray-600 mb-3">
                                        <span><?php echo $categoria['cantidad']; ?> registros</span>
                                        <span>Promedio: <?php echo formato_moneda($categoria['promedio']); ?></span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill bg-red-500" style="width: <?php echo $porcentaje; ?>%"></div>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1 text-right"><?php echo number_format($porcentaje, 1); ?>% del total</div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php break; ?>
                
            <?php case 'inventario': ?>
                <!-- Reporte de Inventario -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- Resumen de inventario -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <i class="fas fa-boxes text-blue-500"></i>
                            Resumen de Inventario
                        </h3>
                        <div class="space-y-6">
                            <?php if(isset($datos_especificos['inventario_total'])): ?>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-blue-50 p-4 rounded-lg">
                                    <div class="text-sm text-blue-700 mb-1">Valor al Costo</div>
                                    <div class="text-2xl font-bold text-blue-900"><?php echo formato_moneda($datos_especificos['inventario_total']['valor_compra']); ?></div>
                                </div>
                                <div class="bg-green-50 p-4 rounded-lg">
                                    <div class="text-sm text-green-700 mb-1">Valor al Vender</div>
                                    <div class="text-2xl font-bold text-green-900"><?php echo formato_moneda($datos_especificos['inventario_total']['valor_venta']); ?></div>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <div class="text-sm text-gray-700 mb-1">Productos Activos</div>
                                    <div class="text-2xl font-bold text-gray-900"><?php echo $datos_especificos['inventario_total']['total_productos']; ?></div>
                                </div>
                                <div class="bg-purple-50 p-4 rounded-lg">
                                    <div class="text-sm text-purple-700 mb-1">Unidades Totales</div>
                                    <div class="text-2xl font-bold text-purple-900"><?php echo number_format($datos_especificos['inventario_total']['total_unidades']); ?></div>
                                </div>
                            </div>
                            
                            <div class="bg-gradient-to-r from-blue-100 to-purple-100 p-4 rounded-lg">
                                <div class="text-sm text-blue-800 mb-1">Margen Potencial del Inventario</div>
                                <div class="text-2xl font-bold text-blue-900">
                                    <?php echo formato_moneda($datos_especificos['inventario_total']['valor_venta'] - $datos_especificos['inventario_total']['valor_compra']); ?>
                                </div>
                                <div class="text-sm text-blue-700 mt-1">
                                    <?php echo $datos_especificos['inventario_total']['valor_compra'] > 0 ? 
                                           number_format((($datos_especificos['inventario_total']['valor_venta'] - $datos_especificos['inventario_total']['valor_compra']) / $datos_especificos['inventario_total']['valor_compra']) * 100, 1) . '%' : '0%'; ?> de margen
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Productos con bajo stock -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <i class="fas fa-exclamation-triangle text-red-500"></i>
                            Productos con Bajo Stock
                        </h3>
                        <div class="space-y-3">
                            <?php if(isset($datos_especificos['bajo_stock']) && count($datos_especificos['bajo_stock']) > 0): ?>
                                <?php foreach($datos_especificos['bajo_stock'] as $producto): 
                                    $porcentaje_stock = $producto['stock_minimo'] > 0 ? ($producto['stock'] / $producto['stock_minimo']) * 100 : 0;
                                ?>
                                <div class="p-4 bg-red-50 border border-red-200 rounded-lg">
                                    <div class="flex items-center justify-between mb-2">
                                        <div>
                                            <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($producto['nombre']); ?></h4>
                                            <p class="text-sm text-gray-600"><?php echo $producto['codigo']; ?></p>
                                        </div>
                                        <span class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-sm font-medium">
                                            <?php echo $producto['stock']; ?> / <?php echo $producto['stock_minimo']; ?>
                                        </span>
                                    </div>
                                    <div class="flex items-center justify-between text-sm text-gray-600 mb-3">
                                        <span>Valor stock: <?php echo formato_moneda($producto['valor_stock']); ?></span>
                                        <span class="<?php echo $porcentaje_stock < 50 ? 'text-red-600' : 'text-yellow-600'; ?>">
                                            <?php echo number_format($porcentaje_stock, 1); ?>%
                                        </span>
                                    </div>
                                    <div class="progress-bar">
                                        <div class="progress-fill <?php echo $porcentaje_stock < 30 ? 'bg-red-500' : ($porcentaje_stock < 70 ? 'bg-yellow-500' : 'bg-green-500'); ?>" 
                                             style="width: <?php echo min($porcentaje_stock, 100); ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-8 text-gray-500">
                                    <i class="fas fa-check-circle text-4xl mb-3 text-green-500"></i>
                                    <p>¡Excelente! No hay productos con bajo stock</p>
                                    <p class="text-sm mt-1">Todos los productos tienen inventario suficiente</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php break; ?>
                
            <?php default: ?>
                <!-- Reporte General -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- Gráfico de tendencias -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <i class="fas fa-chart-area text-purple-500"></i>
                            Tendencias del Período
                        </h3>
                        <div class="chart-container">
                            <canvas id="chartTendencias"></canvas>
                        </div>
                    </div>

                    <!-- Estado de resultados -->
                    <div class="bg-white rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-6 flex items-center gap-2">
                            <i class="fas fa-file-invoice-dollar text-green-500"></i>
                            Estado de Resultados
                        </h3>
                        <div class="space-y-4">
                            <!-- Ingresos -->
                            <div class="p-4 bg-green-50 rounded-lg">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="font-medium text-green-900">Ingresos por Ventas</h4>
                                    <span class="text-2xl font-bold text-green-900"><?php echo formato_moneda($datos_periodo['ventas']['total']); ?></span>
                                </div>
                                <div class="text-sm text-green-700">
                                    <?php echo $datos_periodo['ventas']['cantidad']; ?> transacciones
                                </div>
                            </div>

                            <!-- Costos -->
                            <div class="p-4 bg-blue-50 rounded-lg">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="font-medium text-blue-900">Costos de Compras</h4>
                                    <span class="text-2xl font-bold text-blue-900"><?php echo formato_moneda($datos_periodo['compras']['total']); ?></span>
                                </div>
                                <div class="text-sm text-blue-700">
                                    <?php echo $datos_periodo['compras']['cantidad']; ?> órdenes
                                </div>
                            </div>

                            <!-- Utilidad Bruta -->
                            <div class="p-4 bg-purple-50 rounded-lg">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="font-medium text-purple-900">Utilidad Bruta</h4>
                                    <span class="text-2xl font-bold <?php echo $datos_periodo['utilidad']['bruta'] >= 0 ? 'text-purple-900' : 'text-red-900'; ?>">
                                        <?php echo formato_moneda($datos_periodo['utilidad']['bruta']); ?>
                                    </span>
                                </div>
                                <div class="text-sm text-purple-700">
                                    Margen bruto: <?php echo $datos_periodo['ventas']['total'] > 0 ? 
                                    number_format(($datos_periodo['utilidad']['bruta'] / $datos_periodo['ventas']['total']) * 100, 1) . '%' : '0%'; ?>
                                </div>
                            </div>

                            <!-- Gastos -->
                            <div class="p-4 bg-red-50 rounded-lg">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="font-medium text-red-900">Gastos Operativos</h4>
                                    <span class="text-2xl font-bold text-red-900"><?php echo formato_moneda($datos_periodo['gastos']['total']); ?></span>
                                </div>
                            </div>

                            <!-- Utilidad Neta -->
                            <div class="p-4 bg-gradient-to-r from-green-50 to-blue-50 border border-green-200 rounded-lg">
                                <div class="flex items-center justify-between mb-2">
                                    <h4 class="font-medium text-gray-900">UTILIDAD NETA</h4>
                                    <span class="text-3xl font-bold <?php echo $datos_periodo['utilidad']['neta'] >= 0 ? 'text-green-900' : 'text-red-900'; ?>">
                                        <?php echo formato_moneda($datos_periodo['utilidad']['neta']); ?>
                                    </span>
                                </div>
                                <div class="text-sm <?php echo $datos_periodo['utilidad']['neta'] >= 0 ? 'text-green-700' : 'text-red-700'; ?>">
                                    Margen neto: <?php echo $datos_periodo['ventas']['total'] > 0 ? 
                                    number_format(($datos_periodo['utilidad']['neta'] / $datos_periodo['ventas']['total']) * 100, 1) . '%' : '0%'; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Métricas adicionales -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-medium text-gray-900">Promedio por Venta</h4>
                            <i class="fas fa-coins text-yellow-500"></i>
                        </div>
                        <div class="text-2xl font-bold text-gray-900"><?php echo formato_moneda($datos_periodo['ventas']['promedio']); ?></div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-medium text-gray-900">Ventas al Contado</h4>
                            <i class="fas fa-money-bill-wave text-green-500"></i>
                        </div>
                        <div class="text-2xl font-bold text-gray-900"><?php echo formato_moneda($datos_periodo['ventas']['efectivo']); ?></div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-medium text-gray-900">Ventas a Crédito</h4>
                            <i class="fas fa-file-invoice-dollar text-purple-500"></i>
                        </div>
                        <div class="text-2xl font-bold text-gray-900"><?php echo formato_moneda($datos_periodo['ventas']['credito']); ?></div>
                    </div>
                    
                    <div class="bg-white rounded-xl shadow p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="font-medium text-gray-900">Eficiencia Comercial</h4>
                            <i class="fas fa-chart-bar text-blue-500"></i>
                        </div>
                        <div class="text-2xl font-bold <?php echo ($datos_periodo['ventas']['total'] / ($datos_periodo['compras']['total'] + $datos_periodo['gastos']['total'])) >= 1.3 ? 'text-green-600' : 'text-yellow-600'; ?>">
                            <?php echo ($datos_periodo['compras']['total'] + $datos_periodo['gastos']['total']) > 0 ? 
                                   number_format($datos_periodo['ventas']['total'] / ($datos_periodo['compras']['total'] + $datos_periodo['gastos']['total']), 2) : '0.00'; ?>
                        </div>
                    </div>
                </div>
                <?php break; ?>
        <?php endswitch; ?>

        <!-- Información para impresión -->
        <div class="print-only">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold">Reporte Contable</h1>
                <p class="text-gray-600">Período: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></p>
                <p class="text-gray-600">Generado: <?php echo date('d/m/Y H:i'); ?></p>
            </div>
        </div>
    </div>

    <script>
    // Configuración global de Chart.js
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#6b7280';
    
    // Variables para almacenar instancias de gráficos
    let charts = {};

    // Función para inicializar gráficos según el tipo de reporte
    function inicializarGraficos() {
        <?php switch($tipo_reporte): 
            case 'ventas': ?>
                // Gráfico de ventas diarias
                if (document.getElementById('chartVentasDiarias')) {
                    const ctx1 = document.getElementById('chartVentasDiarias').getContext('2d');
                    
                    <?php if(isset($datos_especificos['ventas_diarias'])): ?>
                    const labels1 = <?php echo json_encode(array_map(function($item) {
                        return date('d/m', strtotime($item['dia']));
                    }, $datos_especificos['ventas_diarias'])); ?>;
                    
                    const data1 = <?php echo json_encode(array_map(function($item) {
                        return floatval($item['total']);
                    }, $datos_especificos['ventas_diarias'])); ?>;
                    
                    charts.ventasDiarias = new Chart(ctx1, {
                        type: 'line',
                        data: {
                            labels: labels1,
                            datasets: [{
                                label: 'Ventas Diarias',
                                data: data1,
                                borderColor: '#10b981',
                                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointBackgroundColor: '#10b981',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointRadius: 5
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            return `Ventas: $${context.raw.toLocaleString('es-ES', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                                        }
                                    }
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '$' + value.toLocaleString('es-ES', {minimumFractionDigits: 0, maximumFractionDigits: 0});
                                        }
                                    }
                                }
                            }
                        }
                    });
                    <?php endif; ?>
                }

                // Gráfico de métodos de pago
                if (document.getElementById('chartMetodosPago')) {
                    const ctx2 = document.getElementById('chartMetodosPago').getContext('2d');
                    
                    <?php if(isset($datos_especificos['metodos_pago'])): ?>
                    const labels2 = <?php echo json_encode(array_map(function($item) {
                        return ucfirst($item['metodo']);
                    }, $datos_especificos['metodos_pago'])); ?>;
                    
                    const data2 = <?php echo json_encode(array_map(function($item) {
                        return floatval($item['total']);
                    }, $datos_especificos['metodos_pago'])); ?>;
                    
                    const backgroundColors = <?php echo json_encode(array_map(function($item) {
                        return $item['metodo'] == 'efectivo' ? 'rgba(16, 185, 129, 0.8)' : 
                               ($item['metodo'] == 'tarjeta' ? 'rgba(59, 130, 246, 0.8)' : 'rgba(139, 92, 246, 0.8)');
                    }, $datos_especificos['metodos_pago'])); ?>;
                    
                    charts.metodosPago = new Chart(ctx2, {
                        type: 'doughnut',
                        data: {
                            labels: labels2,
                            datasets: [{
                                data: data2,
                                backgroundColor: backgroundColors,
                                borderColor: '#ffffff',
                                borderWidth: 2,
                                hoverOffset: 15
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    position: 'right',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = Math.round((context.raw / total) * 100);
                                            return `${context.label}: $${context.raw.toLocaleString('es-ES', {minimumFractionDigits: 2})} (${percentage}%)`;
                                        }
                                    }
                                }
                            },
                            cutout: '60%'
                        }
                    });
                    <?php endif; ?>
                }
                <?php break; ?>
                
            <?php case 'compras': ?>
                // Gráfico de compras por proveedor
                if (document.getElementById('chartComprasProveedor')) {
                    const ctx = document.getElementById('chartComprasProveedor').getContext('2d');
                    
                    <?php if(isset($datos_especificos['compras_proveedor'])): ?>
                    const labels = <?php echo json_encode(array_map(function($item) {
                        return $item['proveedor'] ?: 'Sin proveedor';
                    }, $datos_especificos['compras_proveedor'])); ?>;
                    
                    const data = <?php echo json_encode(array_map(function($item) {
                        return floatval($item['total']);
                    }, $datos_especificos['compras_proveedor'])); ?>;
                    
                    charts.comprasProveedor = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Compras',
                                data: data,
                                backgroundColor: 'rgba(59, 130, 246, 0.8)',
                                borderColor: 'rgb(59, 130, 246)',
                                borderWidth: 1
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '$' + value.toLocaleString('es-ES', {minimumFractionDigits: 0});
                                        }
                                    }
                                }
                            }
                        }
                    });
                    <?php endif; ?>
                }
                <?php break; ?>
                
            <?php case 'gastos': ?>
                // Gráfico de gastos por categoría
                if (document.getElementById('chartGastosCategoria')) {
                    const ctx = document.getElementById('chartGastosCategoria').getContext('2d');
                    
                    <?php if(isset($datos_especificos['gastos_categoria'])): ?>
                    const labels = <?php echo json_encode(array_map(function($item) {
                        return $item['categoria'];
                    }, $datos_especificos['gastos_categoria'])); ?>;
                    
                    const data = <?php echo json_encode(array_map(function($item) {
                        return floatval($item['total']);
                    }, $datos_especificos['gastos_categoria'])); ?>;
                    
                    charts.gastosCategoria = new Chart(ctx, {
                        type: 'pie',
                        data: {
                            labels: labels,
                            datasets: [{
                                data: data,
                                backgroundColor: [
                                    'rgba(239, 68, 68, 0.8)',
                                    'rgba(245, 158, 11, 0.8)',
                                    'rgba(16, 185, 129, 0.8)',
                                    'rgba(59, 130, 246, 0.8)',
                                    'rgba(139, 92, 246, 0.8)',
                                    'rgba(14, 165, 233, 0.8)'
                                ]
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false
                        }
                    });
                    <?php endif; ?>
                }
                <?php break; ?>
                
            <?php default: ?>
                // Gráfico de tendencias (reporte general)
                if (document.getElementById('chartTendencias')) {
                    const ctx = document.getElementById('chartTendencias').getContext('2d');
                    
                    <?php if(isset($datos_especificos['tendencias'])): ?>
                    const labels = <?php echo json_encode(array_map(function($item) {
                        return date('d/m', strtotime($item['dia']));
                    }, $datos_especificos['tendencias'])); ?>;
                    
                    const ventasData = <?php echo json_encode(array_map(function($item) {
                        return floatval($item['ventas']);
                    }, $datos_especificos['tendencias'])); ?>;
                    
                    const comprasData = <?php echo json_encode(array_map(function($item) {
                        return floatval($item['compras']);
                    }, $datos_especificos['tendencias'])); ?>;
                    
                    const gastosData = <?php echo json_encode(array_map(function($item) {
                        return floatval($item['gastos']);
                    }, $datos_especificos['tendencias'])); ?>;
                    
                    charts.tendencias = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'Ventas',
                                    data: ventasData,
                                    borderColor: '#10b981',
                                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                                    borderWidth: 3,
                                    fill: true,
                                    tension: 0.4
                                },
                                {
                                    label: 'Compras',
                                    data: comprasData,
                                    borderColor: '#3b82f6',
                                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                    borderWidth: 3,
                                    fill: true,
                                    tension: 0.4
                                },
                                {
                                    label: 'Gastos',
                                    data: gastosData,
                                    borderColor: '#ef4444',
                                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                                    borderWidth: 3,
                                    fill: true,
                                    tension: 0.4
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                tooltip: {
                                    mode: 'index',
                                    intersect: false
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        callback: function(value) {
                                            return '$' + value.toLocaleString('es-ES', {minimumFractionDigits: 0});
                                        }
                                    }
                                }
                            }
                        }
                    });
                    <?php endif; ?>
                }
                <?php break; ?>
        <?php endswitch; ?>
    }

    // Funciones de utilidad
    function setFechas(rango) {
        const hoy = new Date();
        const fechaFin = document.querySelector('input[name="fecha_fin"]');
        const fechaInicio = document.querySelector('input[name="fecha_inicio"]');
        
        switch(rango) {
            case 'hoy':
                fechaInicio.value = fechaFin.value = hoy.toISOString().split('T')[0];
                break;
            case 'semana':
                const inicioSemana = new Date(hoy);
                inicioSemana.setDate(hoy.getDate() - hoy.getDay() + 1); // Lunes de esta semana
                fechaInicio.value = inicioSemana.toISOString().split('T')[0];
                fechaFin.value = hoy.toISOString().split('T')[0];
                break;
            case 'mes':
                const inicioMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
                fechaInicio.value = inicioMes.toISOString().split('T')[0];
                fechaFin.value = hoy.toISOString().split('T')[0];
                break;
            case 'anio':
                const inicioAnio = new Date(hoy.getFullYear(), 0, 1);
                fechaInicio.value = inicioAnio.toISOString().split('T')[0];
                fechaFin.value = hoy.toISOString().split('T')[0];
                break;
        }
        
        document.querySelector('form[method="GET"]').submit();
    }

    function imprimirReporte() {
        window.print();
    }

    function exportarPDF() {
        alert('Función de exportación a PDF. En una implementación real, aquí se generaría el PDF del reporte.');
        // Para implementar: usar una librería como jsPDF o enviar al servidor para generar PDF
    }

    // Inicializar cuando el DOM esté listo
    document.addEventListener('DOMContentLoaded', function() {
        inicializarGraficos();
        
        // Validar fechas en el formulario
        const form = document.querySelector('form[method="GET"]');
        form.addEventListener('submit', function(e) {
            const fechaInicio = document.querySelector('input[name="fecha_inicio"]').value;
            const fechaFin = document.querySelector('input[name="fecha_fin"]').value;
            
            if (fechaInicio && fechaFin && new Date(fechaInicio) > new Date(fechaFin)) {
                e.preventDefault();
                alert('La fecha de inicio no puede ser mayor que la fecha de fin');
                return false;
            }
        });
        
        // Auto-seleccionar el campo de fecha inicio
        const fechaInicioInput = document.querySelector('input[name="fecha_inicio"]');
        if (fechaInicioInput) {
            fechaInicioInput.focus();
        }
    });

    // Actualizar gráficos al cambiar tamaño de ventana
    window.addEventListener('resize', function() {
        Object.values(charts).forEach(chart => {
            if (chart) chart.resize();
        });
    });
    </script>
</body>
</html>