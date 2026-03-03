<?php
// Auto-fixed: 2026-02-17 01:57:21
require_once '../../../includes/config.php';
// Activar TODOS los errores para debug
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Iniciar sesión PRIMERO
session_start();

// Verificar que la sesión está activa
if (!isset($_SESSION['usuario_rol'])) {
    die('ERROR: Sesión no iniciada o usuario no autenticado. <a href="/sistema_pos/login.php">Iniciar sesión</a>');
}

// Verificar permisos
$roles_permitidos = ['admin', 'vendedor', 'cajero'];
if (!in_array($_SESSION['usuario_rol'], $roles_permitidos)) {
    die('ERROR: No tienes permisos para acceder a esta página. Tu rol: ' . $_SESSION['usuario_rol']);
}

// Configurar zona horaria de Bogotá
date_default_timezone_set('America/Bogota');

// Intentar cargar la configuración de la base de datos
try {
    // Intentar diferentes rutas
    $database_paths = [
        __DIR__ . '/../config/database.php',
        __DIR__ . '/../../config/database.php',
        __DIR__ . '/../../../config/database.php',
        '/home/valentin/public_html/sistema_pos/config/database.php' // Ruta absoluta
    ];
    
    $database_loaded = false;
    foreach ($database_paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $database_loaded = true;
            break;
        }
    }
    
    if (!$database_loaded) {
        throw new Exception('No se pudo encontrar el archivo database.php');
    }
    
    // Crear instancia de Database
    $database = Database::getInstance();
    $db = $database->getConnection();
    
} catch (Exception $e) {
    die('ERROR DE CONEXIÓN A LA BASE DE DATOS: ' . $e->getMessage() . 
        '<br>Por favor, contacta al administrador del sistema.');
}

// Obtener fecha del filtro (por defecto hoy)
$fecha_seleccionada = $_GET['fecha'] ?? date('Y-m-d');
$fecha_actual = date('Y-m-d');

// Función para ejecutar consultas de forma segura
function ejecutarConsulta($db, $sql, $params = []) {
    try {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new Exception("Error preparando consulta: " . $db->errorInfo()[2]);
        }
        
        $resultado = $stmt->execute($params);
        if (!$resultado) {
            throw new Exception("Error ejecutando consulta: " . $stmt->errorInfo()[2]);
        }
        
        return $stmt;
    } catch (Exception $e) {
        // En producción, esto debería loguearse, no mostrarse
        error_log("Error en consulta SQL: " . $e->getMessage());
        return false;
    }
}

// 1. OBTENER VENTAS DEL DÍA SELECCIONADO (con manejo de errores)
try {
    $query_ventas = "SELECT COUNT(*) as total_ventas,
                     SUM(CASE WHEN tipo_venta = 'credito' THEN 1 ELSE 0 END) as ventas_credito,
                     SUM(CASE WHEN tipo_venta = 'contado' OR tipo_venta IS NULL OR tipo_venta = '' THEN 1 ELSE 0 END) as ventas_contado,
                     SUM(total) as total_ventas_monto,
                     SUM(CASE WHEN tipo_venta = 'credito' THEN abono_inicial ELSE 0 END) as abonos_credito,
                     SUM(CASE WHEN tipo_venta = 'contado' OR tipo_venta IS NULL OR tipo_venta = '' THEN total ELSE 0 END) as total_contado,
                     SUM(descuento) as total_descuentos
                     FROM ventas 
                     WHERE DATE(fecha) = ? AND anulada = 0";
    
    $stmt_ventas = ejecutarConsulta($db, $query_ventas, [$fecha_seleccionada]);
    
    if ($stmt_ventas) {
        $resumen_ventas = $stmt_ventas->fetch(PDO::FETCH_ASSOC);
        if (!$resumen_ventas) {
            $resumen_ventas = [
                'total_ventas' => 0,
                'ventas_credito' => 0,
                'ventas_contado' => 0,
                'total_ventas_monto' => 0,
                'abonos_credito' => 0,
                'total_contado' => 0,
                'total_descuentos' => 0
            ];
        }
    } else {
        $resumen_ventas = [
            'total_ventas' => 0,
            'ventas_credito' => 0,
            'ventas_contado' => 0,
            'total_ventas_monto' => 0,
            'abonos_credito' => 0,
            'total_contado' => 0,
            'total_descuentos' => 0
        ];
    }
} catch (Exception $e) {
    $resumen_ventas = [
        'total_ventas' => 0,
        'ventas_credito' => 0,
        'ventas_contado' => 0,
        'total_ventas_monto' => 0,
        'abonos_credito' => 0,
        'total_contado' => 0,
        'total_descuentos' => 0
    ];
    error_log("Error obteniendo ventas del día: " . $e->getMessage());
}

// Calcular valores
$total_ventas = $resumen_ventas['total_ventas'] ?? 0;
$ventas_credito = $resumen_ventas['ventas_credito'] ?? 0;
$ventas_contado = $resumen_ventas['ventas_contado'] ?? 0;
$total_ventas_monto = $resumen_ventas['total_ventas_monto'] ?? 0;
$abonos_credito = $resumen_ventas['abonos_credito'] ?? 0;
$total_contado = $resumen_ventas['total_contado'] ?? 0;
$total_descuentos = $resumen_ventas['total_descuentos'] ?? 0;

$ingresos_reales = $total_contado + $abonos_credito;
$total_credito = $total_ventas_monto - $total_contado;
if ($total_credito < 0) $total_credito = 0;
$deuda_pendiente = $total_credito - $abonos_credito;
if ($deuda_pendiente < 0) $deuda_pendiente = 0;

// Inicializar arrays vacíos para evitar errores
$productos_mas_vendidos = [];
$productos_todos_vendidos = [];
$ventas_por_hora = [];
$ventas_por_metodo = [];
$ventas_por_vendedor = [];

// 2. OBTENER PRODUCTOS MÁS VENDIDOS DEL DÍA (TOP 10)
try {
    $query_productos_mas_vendidos = "SELECT p.id, p.nombre, p.codigo, p.codigo_barras,
                       SUM(vd.cantidad) as total_vendido,
                       SUM(vd.cantidad * vd.precio) as total_ingresos,
                       COUNT(DISTINCT vd.venta_id) as veces_vendido
                       FROM venta_detalles vd
                       INNER JOIN productos p ON vd.producto_id = p.id
                       INNER JOIN ventas v ON vd.venta_id = v.id
                       WHERE DATE(v.fecha) = ? AND v.anulada = 0
                       GROUP BY p.id, p.nombre, p.codigo
                       ORDER BY total_vendido DESC
                       LIMIT 10";
    
    $stmt_productos_mas = ejecutarConsulta($db, $query_productos_mas_vendidos, [$fecha_seleccionada]);
    if ($stmt_productos_mas) {
        $productos_mas_vendidos = $stmt_productos_mas->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error obteniendo productos más vendidos: " . $e->getMessage());
}

// 3. OBTENER TODOS LOS ARTÍCULOS VENDIDOS (COMPLETO) - Agrupado por producto
try {
    $query_todos_productos = "SELECT p.id, p.nombre, p.codigo, p.codigo_barras,
                       SUM(vd.cantidad) as total_vendido,
                       SUM(vd.cantidad * vd.precio) as total_ingresos,
                       COUNT(DISTINCT vd.venta_id) as veces_vendido,
                       p.precio_venta as precio_unitario,
                       (SUM(vd.cantidad * vd.precio) / SUM(vd.cantidad)) as precio_promedio
                       FROM venta_detalles vd
                       INNER JOIN productos p ON vd.producto_id = p.id
                       INNER JOIN ventas v ON vd.venta_id = v.id
                       WHERE DATE(v.fecha) = ? AND v.anulada = 0
                       GROUP BY p.id, p.nombre, p.codigo, p.precio_venta
                       ORDER BY total_vendido DESC, p.nombre ASC";
    
    $stmt_todos_productos = ejecutarConsulta($db, $query_todos_productos, [$fecha_seleccionada]);
    if ($stmt_todos_productos) {
        $productos_todos_vendidos = $stmt_todos_productos->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error obteniendo todos los productos vendidos: " . $e->getMessage());
}

// 4. OBTENER VENTAS POR HORA
try {
    $query_por_hora = "SELECT 
                       HOUR(fecha) as hora,
                       COUNT(*) as cantidad_ventas,
                       SUM(total) as total_ventas
                       FROM ventas
                       WHERE DATE(fecha) = ? AND anulada = 0
                       GROUP BY HOUR(fecha)
                       ORDER BY hora";
    
    $stmt_por_hora = ejecutarConsulta($db, $query_por_hora, [$fecha_seleccionada]);
    if ($stmt_por_hora) {
        $ventas_por_hora = $stmt_por_hora->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error obteniendo ventas por hora: " . $e->getMessage());
}

// 5. OBTENER VENTAS POR MÉTODO DE PAGO
try {
    $query_por_metodo = "SELECT 
                         metodo_pago,
                         COUNT(*) as cantidad_ventas,
                         SUM(total) as total_ventas,
                         SUM(CASE WHEN tipo_venta = 'credito' THEN abono_inicial ELSE 0 END) as total_abonos
                         FROM ventas
                         WHERE DATE(fecha) = ? AND anulada = 0
                         GROUP BY metodo_pago
                         ORDER BY total_ventas DESC";
    
    $stmt_por_metodo = ejecutarConsulta($db, $query_por_metodo, [$fecha_seleccionada]);
    if ($stmt_por_metodo) {
        $ventas_por_metodo = $stmt_por_metodo->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error obteniendo ventas por método: " . $e->getMessage());
}

// 6. OBTENER VENTAS POR VENDEDOR
try {
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
    
    $stmt_por_vendedor = ejecutarConsulta($db, $query_por_vendedor, [$fecha_seleccionada]);
    if ($stmt_por_vendedor) {
        $ventas_por_vendedor = $stmt_por_vendedor->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error obteniendo ventas por vendedor: " . $e->getMessage());
}

// Ahora mostrar la página HTML
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resumen del Día - Sistema POS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
            .print-border { border: 1px solid #ddd !important; }
            .print-hidden { display: none !important; }
        }
        .card-hover:hover {
            transform: translateY(-2px);
            transition: transform 0.2s;
        }
        .badge-cantidad {
            background-color: #3b82f6;
            color: white;
            border-radius: 9999px;
            padding: 0.125rem 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .producto-item {
            transition: all 0.2s;
            border-left: 4px solid transparent;
        }
        .producto-item:hover {
            background-color: #f9fafb;
            border-left-color: #3b82f6;
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header simple -->
    <div class="bg-white shadow print-border">
        <div class="max-w-7xl mx-auto px-4 py-4">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">📊 Resumen Diario</h1>
                    <p class="text-gray-600 mt-1">
                        <i class="fas fa-calendar-day mr-1"></i>
                        <?php echo date('d/m/Y', strtotime($fecha_seleccionada)); ?> - 
                        <i class="fas fa-clock ml-2 mr-1"></i>
                        <?php echo date('H:i'); ?> (Bogotá)
                    </p>
                </div>
                <div class="no-print">
                    <a href="../ventas/index.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>Volver
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 py-6">
        <!-- FILTRO DE FECHA -->
        <div class="bg-white rounded-lg shadow mb-6 p-4 print-hidden">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h3 class="text-lg font-medium text-gray-900 flex items-center">
                        <i class="fas fa-filter mr-2 text-blue-600"></i>
                        Filtrar por Fecha
                    </h3>
                    <p class="text-sm text-gray-500">Selecciona una fecha para ver el resumen</p>
                </div>
                
                <form method="GET" class="flex flex-col sm:flex-row gap-3">
                    <div>
                        <input type="date" 
                               name="fecha" 
                               value="<?php echo $fecha_seleccionada; ?>"
                               class="border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-search mr-2"></i>
                            Buscar
                        </button>
                        <a href="?fecha=<?php echo $fecha_actual; ?>" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-calendar-day mr-2"></i>
                            Hoy
                        </a>
                        <button type="button" onclick="cambiarFecha(-1)" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-chevron-left mr-2"></i>
                            Ayer
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Indicadores de fecha -->
            <div class="mt-4 flex flex-wrap gap-3">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                    <i class="fas fa-calendar-check mr-1"></i>
                    Fecha seleccionada: <?php echo date('d/m/Y', strtotime($fecha_seleccionada)); ?>
                </span>
                
                <?php if ($fecha_seleccionada == $fecha_actual): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                    <i class="fas fa-check-circle mr-1"></i>
                    Mostrando fecha actual
                </span>
                <?php else: ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                    <i class="fas fa-history mr-1"></i>
                    Vista histórica
                </span>
                <a href="?fecha=<?php echo $fecha_actual; ?>" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800 hover:bg-blue-200">
                    <i class="fas fa-sync-alt mr-1"></i>
                    Volver a hoy
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Mensaje de advertencia si no hay datos -->
        <?php if ($total_ventas == 0): ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-yellow-700">
                        No hay ventas registradas para <?php echo date('d/m/Y', strtotime($fecha_seleccionada)); ?>.
                        Los datos se mostrarán cuando existan ventas para esta fecha.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tarjetas de resumen -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-6">
            <!-- Total Ventas -->
            <div class="bg-white rounded-lg shadow p-4 card-hover print-border">
                <div class="flex items-center mb-2">
                    <div class="p-2 rounded-full bg-blue-100 text-blue-600 mr-3">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Ventas Totales</p>
                        <p class="text-xl font-bold text-gray-900"><?php echo $total_ventas; ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Ingresos Reales -->
            <div class="bg-white rounded-lg shadow p-4 card-hover print-border">
                <div class="flex items-center mb-2">
                    <div class="p-2 rounded-full bg-green-100 text-green-600 mr-3">
                        <i class="fas fa-cash-register"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Ingresos Reales</p>
                        <p class="text-xl font-bold text-green-600">$<?php echo number_format($ingresos_reales, 0, ',', '.'); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Ventas Contado -->
            <div class="bg-white rounded-lg shadow p-4 card-hover print-border">
                <div class="flex items-center mb-2">
                    <div class="p-2 rounded-full bg-emerald-100 text-emerald-600 mr-3">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Contado</p>
                        <p class="text-xl font-bold text-emerald-600">$<?php echo number_format($total_contado, 0, ',', '.'); ?></p>
                        <p class="text-xs text-gray-500"><?php echo $ventas_contado; ?> ventas</p>
                    </div>
                </div>
            </div>
            
            <!-- Ventas Crédito -->
            <div class="bg-white rounded-lg shadow p-4 card-hover print-border">
                <div class="flex items-center mb-2">
                    <div class="p-2 rounded-full bg-purple-100 text-purple-600 mr-3">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Crédito</p>
                        <p class="text-xl font-bold text-purple-600">$<?php echo number_format($total_credito, 0, ',', '.'); ?></p>
                        <p class="text-xs text-gray-500"><?php echo $ventas_credito; ?> ventas</p>
                    </div>
                </div>
            </div>
            
            <!-- Deuda Pendiente -->
            <div class="bg-white rounded-lg shadow p-4 card-hover print-border">
                <div class="flex items-center mb-2">
                    <div class="p-2 rounded-full bg-red-100 text-red-600 mr-3">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Por Cobrar</p>
                        <p class="text-xl font-bold text-red-600">$<?php echo number_format($deuda_pendiente, 0, ',', '.'); ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Descuentos -->
            <div class="bg-white rounded-lg shadow p-4 card-hover print-border">
                <div class="flex items-center mb-2">
                    <div class="p-2 rounded-full bg-yellow-100 text-yellow-600 mr-3">
                        <i class="fas fa-tag"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Descuentos</p>
                        <p class="text-xl font-bold text-yellow-600">$<?php echo number_format($total_descuentos, 0, ',', '.'); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sección de TODOS los artículos vendidos (NUEVA SECCIÓN) -->
        <div class="bg-white rounded-lg shadow mb-6 print-border">
            <div class="p-4 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center">
                        <i class="fas fa-list mr-2 text-blue-600"></i>
                        Todos los Artículos Vendidos
                        <span class="ml-2 badge-cantidad"><?php echo count($productos_todos_vendidos); ?> productos</span>
                    </h3>
                    <div class="text-sm text-gray-500">
                        Total artículos: <?php 
                            $total_articulos = 0;
                            foreach ($productos_todos_vendidos as $producto) {
                                $total_articulos += $producto['total_vendido'];
                            }
                            echo $total_articulos;
                        ?>
                    </div>
                </div>
            </div>
            
            <div class="p-4">
                <?php if (count($productos_todos_vendidos) > 0): ?>
                    <!-- Contadores rápidos -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                        <div class="bg-blue-50 p-4 rounded-lg">
                            <div class="text-sm text-blue-600">Total Productos Diferentes</div>
                            <div class="text-2xl font-bold text-blue-700"><?php echo count($productos_todos_vendidos); ?></div>
                        </div>
                        <div class="bg-green-50 p-4 rounded-lg">
                            <div class="text-sm text-green-600">Total Unidades Vendidas</div>
                            <div class="text-2xl font-bold text-green-700"><?php echo $total_articulos; ?></div>
                        </div>
                        <div class="bg-purple-50 p-4 rounded-lg">
                            <div class="text-sm text-purple-600">Ingresos por Productos</div>
                            <div class="text-2xl font-bold text-purple-700">$<?php 
                                $total_ingresos_productos = 0;
                                foreach ($productos_todos_vendidos as $producto) {
                                    $total_ingresos_productos += $producto['total_ingresos'];
                                }
                                echo number_format($total_ingresos_productos, 0, ',', '.');
                            ?></div>
                        </div>
                    </div>
                    
                    <!-- Lista de productos -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Producto</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cantidad Vendida</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Veces Vendido</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Precio Promedio</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Ingresos</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($productos_todos_vendidos as $producto): 
                                    $precio_promedio = isset($producto['precio_promedio']) ? $producto['precio_promedio'] : 
                                                       ($producto['total_vendido'] > 0 ? $producto['total_ingresos'] / $producto['total_vendido'] : 0);
                                ?>
                                <tr class="producto-item">
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10 bg-blue-100 rounded-lg flex items-center justify-center">
                                                <i class="fas fa-box text-blue-600"></i>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($producto['nombre'] ?? 'Sin nombre'); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    <?php 
                                                    $codigo_display = $producto['codigo'] ?? $producto['codigo_barras'] ?? 'N/A';
                                                    if (strlen($codigo_display) > 20) {
                                                        echo substr($codigo_display, 0, 20) . '...';
                                                    } else {
                                                        echo $codigo_display;
                                                    }
                                                    ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($producto['codigo'] ?? $producto['codigo_barras'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-semibold bg-blue-100 text-blue-800">
                                                <i class="fas fa-layer-group mr-1"></i>
                                                <?php echo $producto['total_vendido']; ?> unidades
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        <span class="inline-flex items-center">
                                            <i class="fas fa-shopping-cart mr-1 text-gray-400"></i>
                                            <?php echo $producto['veces_vendido']; ?> veces
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700 font-medium">
                                        $<?php echo number_format($precio_promedio, 2); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-bold text-green-600">
                                        $<?php echo number_format($producto['total_ingresos'], 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Resumen por categorías (si quieres agregarlo después) -->
                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <div class="text-sm text-gray-600">
                            <i class="fas fa-info-circle mr-1 text-blue-500"></i>
                            Se muestran todos los productos vendidos el <?php echo date('d/m/Y', strtotime($fecha_seleccionada)); ?>.
                            Los productos están agrupados y se muestra la cantidad total vendida de cada uno.
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12 text-gray-500">
                        <i class="fas fa-boxes text-4xl mb-3 text-gray-300"></i>
                        <p class="text-lg">No hay artículos vendidos</p>
                        <p class="text-sm mt-1">No se registraron ventas de productos para esta fecha</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sección de productos más vendidos (TOP 10) -->
        <div class="bg-white rounded-lg shadow mb-6 print-border">
            <div class="p-4 border-b border-gray-200">
                <h3 class="text-lg font-bold text-gray-800 flex items-center">
                    <i class="fas fa-trophy mr-2 text-yellow-600"></i>
                    Productos Más Vendidos (Top 10)
                </h3>
            </div>
            
            <div class="p-4">
                <?php if (count($productos_mas_vendidos) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Producto</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Cantidad</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Veces Vendido</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productos_mas_vendidos as $index => $producto): ?>
                                <tr class="hover:bg-gray-50 <?php echo $index < 3 ? 'bg-yellow-50' : ''; ?>">
                                    <td class="px-4 py-3 whitespace-nowrap text-center">
                                        <?php if ($index == 0): ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-yellow-500 text-white text-xs font-bold">1</span>
                                        <?php elseif ($index == 1): ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-400 text-white text-xs font-bold">2</span>
                                        <?php elseif ($index == 2): ?>
                                        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-orange-600 text-white text-xs font-bold">3</span>
                                        <?php else: ?>
                                        <span class="text-sm text-gray-500"><?php echo $index + 1; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($producto['nombre'] ?? 'Sin nombre'); ?>
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
                        <p>No hay productos vendidos</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Métodos de pago y vendedores -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Métodos de pago -->
            <div class="bg-white rounded-lg shadow print-border">
                <div class="p-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center">
                        <i class="fas fa-credit-card mr-2 text-blue-600"></i>
                        Métodos de Pago
                    </h3>
                </div>
                
                <div class="p-4">
                    <?php if (count($ventas_por_metodo) > 0): ?>
                        <div class="space-y-3">
                            <?php foreach ($ventas_por_metodo as $metodo): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center">
                                    <?php
                                    $icono = '';
                                    $color = '';
                                    switch(strtolower($metodo['metodo_pago'])) {
                                        case 'efectivo': $icono = 'money-bill-wave'; $color = 'text-green-600'; break;
                                        case 'tarjeta': $icono = 'credit-card'; $color = 'text-blue-600'; break;
                                        case 'transferencia': $icono = 'university'; $color = 'text-purple-600'; break;
                                        case 'mixto': $icono = 'random'; $color = 'text-orange-600'; break;
                                        default: $icono = 'money-bill-wave'; $color = 'text-gray-600';
                                    }
                                    ?>
                                    <i class="fas fa-<?php echo $icono; ?> <?php echo $color; ?> text-lg mr-3"></i>
                                    <div>
                                        <div class="font-medium text-gray-900"><?php echo ucfirst($metodo['metodo_pago']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo $metodo['cantidad_ventas']; ?> ventas</div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-gray-900">$<?php echo number_format($metodo['total_ventas'], 0, ',', '.'); ?></div>
                                    <?php if ($metodo['total_abonos'] > 0): ?>
                                    <div class="text-xs text-green-600">Abonos: $<?php echo number_format($metodo['total_abonos'], 0); ?></div>
                                    <?php endif; ?>
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
            </div>

            <!-- Vendedores -->
            <div class="bg-white rounded-lg shadow print-border">
                <div class="p-4 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center">
                        <i class="fas fa-users mr-2 text-blue-600"></i>
                        Desempeño por Vendedor
                    </h3>
                </div>
                
                <div class="p-4">
                    <?php if (count($ventas_por_vendedor) > 0): ?>
                        <div class="space-y-3">
                            <?php foreach ($ventas_por_vendedor as $vendedor): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center">
                                    <div class="p-2 rounded-full bg-indigo-100 text-indigo-600 mr-3">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div>
                                        <div class="font-medium text-gray-900"><?php echo htmlspecialchars($vendedor['vendedor']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo $vendedor['cantidad_ventas']; ?> ventas</div>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-gray-900">$<?php echo number_format($vendedor['total_ventas'], 0, ',', '.'); ?></div>
                                    <?php if ($vendedor['total_abonos'] > 0): ?>
                                    <div class="text-xs text-green-600">Abonos: $<?php echo number_format($vendedor['total_abonos'], 0); ?></div>
                                    <?php endif; ?>
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
        </div>

        <!-- Botones de acción -->
        <div class="flex justify-center space-x-3 pt-4 no-print">
            <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-print mr-2"></i>
                Imprimir Resumen
            </button>
            <a href="../ventas/index.php?fecha_inicio=<?php echo $fecha_seleccionada; ?>&fecha_fin=<?php echo $fecha_seleccionada; ?>" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-list mr-2"></i>
                Ver Ventas del Día
            </a>
            <button onclick="exportarExcel()" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-file-excel mr-2"></i>
                Exportar Excel
            </button>
        </div>
    </div>

    <!-- Footer simple -->
    <div class="mt-8 pt-4 border-t border-gray-200 text-center text-gray-500 text-sm no-print">
        <p>Sistema POS - Resumen Diario - <?php echo date('d/m/Y H:i', strtotime($fecha_seleccionada)); ?> (Bogotá)</p>
    </div>

    <script>
    // Función para cambiar la fecha (ayer/hoy)
    function cambiarFecha(dias) {
        const fechaInput = document.querySelector('input[name="fecha"]');
        const fechaActual = new Date(fechaInput.value);
        fechaActual.setDate(fechaActual.getDate() + dias);
        
        // Formatear a YYYY-MM-DD
        const nuevaFecha = fechaActual.toISOString().split('T')[0];
        window.location.href = `?fecha=${nuevaFecha}`;
    }
    
    // Función para exportar a Excel
    function exportarExcel() {
        alert('Funcionalidad de exportación a Excel en desarrollo.\nPróximamente disponible.');
        // Aquí podrías implementar la exportación real
        // window.location.href = 'exportar_excel.php?fecha=' + '<?php echo $fecha_seleccionada; ?>';
    }
    
    // Script simple para impresión
    document.addEventListener('DOMContentLoaded', function() {
        // Asegurar que los números se formateen correctamente
        const formatNumber = (num) => {
            return new Intl.NumberFormat('es-CO').format(num);
        };
        
        // Resaltar productos con más de 10 unidades vendidas
        const productos = document.querySelectorAll('.producto-item');
        productos.forEach(producto => {
            const cantidadElement = producto.querySelector('.bg-blue-100');
            if (cantidadElement) {
                const texto = cantidadElement.textContent;
                const cantidad = parseInt(texto.match(/\d+/)[0]);
                if (cantidad >= 10) {
                    producto.classList.add('bg-green-50');
                    const badge = producto.querySelector('.bg-blue-100');
                    if (badge) {
                        badge.classList.remove('bg-blue-100', 'text-blue-800');
                        badge.classList.add('bg-green-100', 'text-green-800');
                    }
                }
            }
        });
    });
    </script>
</body>
</html>