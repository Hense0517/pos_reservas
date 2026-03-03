<?php
// modules/cuentas_por_cobrar/index.php
session_start();

// CORREGIDO: Usar rutas absolutas con __DIR__
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/header.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "index.php");
    exit();
}

// Verificar permisos
$roles_permitidos = ['admin', 'cajero', 'vendedor'];
if (!isset($_SESSION['usuario_rol']) || !in_array($_SESSION['usuario_rol'], $roles_permitidos)) {
    $_SESSION['error'] = "No tienes permisos para acceder a esta sección";
    header("Location: " . BASE_URL . "index.php");
    exit();
}

// Obtener filtros
$estado = $_GET['estado'] ?? 'todos';
$busqueda = $_GET['busqueda'] ?? '';
$fecha_desde = $_GET['fecha_desde'] ?? '';
$fecha_hasta = $_GET['fecha_hasta'] ?? '';

// Conexión PDO - Database ya está incluida en config.php
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cuentas por Cobrar - Sistema POS</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                font-size: 12px;
            }
            table {
                width: 100%;
            }
        }
        .badge-pendiente {
            background-color: #fef3c7;
            color: #92400e;
        }
        .badge-parcial {
            background-color: #d1fae5;
            color: #065f46;
        }
        .badge-pagada {
            background-color: #dcfce7;
            color: #166534;
        }
        .badge-vencida {
            background-color: #fee2e2;
            color: #991b1b;
        }
        .search-highlight {
            background-color: #ffeb3b;
            font-weight: bold;
        }
        .progress-bar-small {
            height: 6px;
            background-color: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 2px;
        }
        .progress-fill {
            height: 100%;
            background-color: #3b82f6;
            border-radius: 3px;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Header ya incluido arriba -->
    
    <div class="max-w-7xl mx-auto px-4 py-6">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">
                    <i class="fas fa-receipt text-blue-600"></i> Cuentas por Cobrar
                </h1>
                <p class="text-gray-600">Gestión de créditos y saldos pendientes de clientes</p>
            </div>
            <div class="flex space-x-2 no-print">
                <a href="historial_pagos.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-clock-history mr-2"></i>
                    Historial de Pagos
                </a>
                <a href="registrar_pago.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-cash-register mr-2"></i>
                    Registrar Pago
                </a>
            </div>
        </div>

        <!-- Mostrar mensajes -->
        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <i class="fas fa-check-circle mr-2"></i>
                <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php
        try {
            // Verificar conexión a BD
            if (!$db) {
                throw new Exception("No hay conexión a la base de datos");
            }
            
            // Construir consulta SQL
            $where = "WHERE 1=1";
            $query_params = [];
            
            // Filtro de estado
            if ($estado == 'activas') {
                $where .= " AND (cp.estado = 'pendiente' OR cp.estado = 'parcial')";
            } elseif ($estado != 'todos' && !empty($estado)) {
                $where .= " AND cp.estado = ?";
                $query_params[] = $estado;
            }
            
            // Filtro de búsqueda
            if (!empty($busqueda)) {
                $where .= " AND (c.nombre LIKE ? OR c.numero_documento LIKE ? OR v.numero_factura LIKE ?)";
                $search_term = "%" . $busqueda . "%";
                $query_params[] = $search_term;
                $query_params[] = $search_term;
                $query_params[] = $search_term;
            }
            
            // Filtro de fechas
            if (!empty($fecha_desde)) {
                $where .= " AND DATE(cp.created_at) >= ?";
                $query_params[] = $fecha_desde;
            }
            
            if (!empty($fecha_hasta)) {
                $where .= " AND DATE(cp.created_at) <= ?";
                $query_params[] = $fecha_hasta;
            }
            
            // Consulta principal
            $sql = "SELECT cp.*, 
                           c.nombre as cliente_nombre, 
                           c.telefono, 
                           c.numero_documento,
                           v.numero_factura, 
                           v.total as total_venta, 
                           v.fecha as fecha_venta,
                           v.tipo_venta
                    FROM cuentas_por_cobrar cp
                    LEFT JOIN clientes c ON cp.cliente_id = c.id
                    LEFT JOIN ventas v ON cp.venta_id = v.id
                    $where
                    ORDER BY cp.created_at DESC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($query_params);
            $cuentas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calcular estadísticas TOTALES
            $stats_sql_total = "SELECT 
                COUNT(*) as total_cuentas,
                COALESCE(SUM(cp.total_deuda), 0) as total_deuda,
                COALESCE(SUM(cp.saldo_pendiente), 0) as total_pendiente,
                SUM(CASE WHEN cp.estado = 'vencida' THEN cp.saldo_pendiente ELSE 0 END) as total_vencido
                FROM cuentas_por_cobrar cp";
            
            $stats_stmt_total = $db->prepare($stats_sql_total);
            $stats_stmt_total->execute();
            $stats_total = $stats_stmt_total->fetch(PDO::FETCH_ASSOC);
            
            // Calcular estadísticas FILTRADAS
            if ($estado != 'todos' || !empty($busqueda) || !empty($fecha_desde) || !empty($fecha_hasta)) {
                $stats_sql_filtrado = "SELECT 
                    COUNT(*) as total_cuentas_filtrado,
                    COALESCE(SUM(cp.total_deuda), 0) as total_deuda_filtrado,
                    COALESCE(SUM(cp.saldo_pendiente), 0) as total_pendiente_filtrado,
                    SUM(CASE WHEN cp.estado = 'vencida' THEN cp.saldo_pendiente ELSE 0 END) as total_vencido_filtrado
                    FROM cuentas_por_cobrar cp
                    LEFT JOIN clientes c ON cp.cliente_id = c.id
                    LEFT JOIN ventas v ON cp.venta_id = v.id
                    $where";
                
                $stats_stmt_filtrado = $db->prepare($stats_sql_filtrado);
                $stats_stmt_filtrado->execute($query_params);
                $stats_filtrado = $stats_stmt_filtrado->fetch(PDO::FETCH_ASSOC);
            }
            
        } catch (Exception $e) {
            echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4'>Error al cargar datos: " . $e->getMessage() . "</div>";
            error_log("Error en cuentas_por_cobrar: " . $e->getMessage());
            $cuentas = [];
            $stats_total = ['total_cuentas' => 0, 'total_deuda' => 0, 'total_pendiente' => 0, 'total_vencido' => 0];
            $stats_filtrado = null;
        }
        ?>

        <!-- Estadísticas Mejoradas -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-blue-100 text-blue-600">
                        <i class="fas fa-money-check-alt"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-500">Total Deuda</p>
                        <p class="text-lg font-bold text-blue-600">
                            $<?php 
                            if (isset($stats_filtrado) && $stats_filtrado) {
                                echo number_format($stats_filtrado['total_deuda_filtrado'] ?? 0, 2);
                            } else {
                                echo number_format($stats_total['total_deuda'] ?? 0, 2);
                            }
                            ?>
                        </p>
                        <?php if (isset($stats_filtrado) && $stats_filtrado): ?>
                            <p class="text-xs text-gray-500">
                                Total general: $<?php echo number_format($stats_total['total_deuda'] ?? 0, 2); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-yellow-100 text-yellow-600">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-500">Saldo Pendiente</p>
                        <p class="text-lg font-bold text-yellow-600">
                            $<?php 
                            if (isset($stats_filtrado) && $stats_filtrado) {
                                echo number_format($stats_filtrado['total_pendiente_filtrado'] ?? 0, 2);
                            } else {
                                echo number_format($stats_total['total_pendiente'] ?? 0, 2);
                            }
                            ?>
                        </p>
                        <?php if (isset($stats_filtrado) && $stats_filtrado): ?>
                            <p class="text-xs text-gray-500">
                                Total general: $<?php echo number_format($stats_total['total_pendiente'] ?? 0, 2); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-purple-100 text-purple-600">
                        <i class="fas fa-receipt"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-500">Cuentas</p>
                        <p class="text-lg font-bold text-purple-600">
                            <?php 
                            if (isset($stats_filtrado) && $stats_filtrado) {
                                echo $stats_filtrado['total_cuentas_filtrado'] ?? 0;
                            } else {
                                echo $stats_total['total_cuentas'] ?? 0;
                            }
                            ?>
                        </p>
                        <?php if (isset($stats_filtrado) && $stats_filtrado): ?>
                            <p class="text-xs text-gray-500">
                                Total general: <?php echo $stats_total['total_cuentas'] ?? 0; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-red-100 text-red-600">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-500">Total Vencido</p>
                        <p class="text-lg font-bold text-red-600">
                            $<?php 
                            if (isset($stats_filtrado) && $stats_filtrado) {
                                echo number_format($stats_filtrado['total_vencido_filtrado'] ?? 0, 2);
                            } else {
                                echo number_format($stats_total['total_vencido'] ?? 0, 2);
                            }
                            ?>
                        </p>
                        <?php if (isset($stats_filtrado) && $stats_filtrado): ?>
                            <p class="text-xs text-gray-500">
                                Total general: $<?php echo number_format($stats_total['total_vencido'] ?? 0, 2); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="bg-white rounded-lg shadow mb-6 no-print">
            <div class="p-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">
                    <i class="fas fa-filter text-blue-600 mr-2"></i>Filtros de Búsqueda
                </h3>
            </div>
            <div class="p-4">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div class="md:col-span-2">
                        <label for="busqueda" class="block text-sm font-medium text-gray-700">
                            <i class="fas fa-search mr-1"></i>Buscar por:
                        </label>
                        <input type="text" id="busqueda" name="busqueda" 
                               value="<?php echo htmlspecialchars($busqueda); ?>"
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Nombre, cédula, factura...">
                        <p class="mt-1 text-xs text-gray-500">
                            Busca por: nombre del cliente, número de documento o número de factura
                        </p>
                    </div>
                    
                    <div>
                        <label for="estado" class="block text-sm font-medium text-gray-700">Estado</label>
                        <select id="estado" name="estado" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="todos" <?php echo $estado == 'todos' ? 'selected' : ''; ?>>Todos los estados</option>
                            <option value="pendiente" <?php echo $estado == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                            <option value="parcial" <?php echo $estado == 'parcial' ? 'selected' : ''; ?>>Parcial</option>
                            <option value="pagada" <?php echo $estado == 'pagada' ? 'selected' : ''; ?>>Pagada</option>
                            <option value="vencida" <?php echo $estado == 'vencida' ? 'selected' : ''; ?>>Vencida</option>
                            <option value="activas" <?php echo $estado == 'activas' ? 'selected' : ''; ?>>Activas (pendiente/parcial)</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="fecha_desde" class="block text-sm font-medium text-gray-700">Fecha Desde</label>
                        <input type="date" id="fecha_desde" name="fecha_desde" 
                               value="<?php echo $fecha_desde; ?>"
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label for="fecha_hasta" class="block text-sm font-medium text-gray-700">Fecha Hasta</label>
                        <input type="date" id="fecha_hasta" name="fecha_hasta" 
                               value="<?php echo $fecha_hasta; ?>"
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div class="col-span-1 md:col-span-5 flex justify-end space-x-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md flex items-center">
                            <i class="fas fa-search mr-2"></i>Buscar
                        </button>
                        <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-md flex items-center">
                            <i class="fas fa-times mr-2"></i>Limpiar
                        </a>
                        <?php if ($estado != 'todos' || !empty($busqueda) || !empty($fecha_desde) || !empty($fecha_hasta)): ?>
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                                <i class="fas fa-funnel mr-1"></i> Filtros activos
                            </span>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Información de resultados -->
        <?php if (!empty($busqueda) && count($cuentas) > 0): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
            <div class="flex items-center">
                <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                <div>
                    <p class="text-sm font-medium text-blue-800">
                        Mostrando <?php echo count($cuentas); ?> resultado(s) para: 
                        <span class="font-bold">"<?php echo htmlspecialchars($busqueda); ?>"</span>
                    </p>
                    <p class="text-xs text-blue-600 mt-1">
                        Búsqueda en: nombre del cliente, número de documento y número de factura
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Lista de Cuentas -->
        <div class="bg-white shadow overflow-hidden sm:rounded-lg">
            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex justify-between items-center no-print">
                <div>
                    <span class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-list-ul text-blue-600 mr-2"></i>Lista de Cuentas
                    </span>
                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                        <?php echo count($cuentas); ?> registros
                    </span>
                    <?php if (!empty($busqueda)): ?>
                    <span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                        <i class="fas fa-search mr-1"></i> Búsqueda activa
                    </span>
                    <?php endif; ?>
                </div>
                <div>
                    <span class="text-sm font-semibold text-gray-900">
                        Total filtrado: $<?php 
                        if (isset($stats_filtrado) && $stats_filtrado) {
                            echo number_format($stats_filtrado['total_deuda_filtrado'] ?? 0, 2);
                        } else {
                            echo number_format($stats_total['total_deuda'] ?? 0, 2);
                        }
                        ?>
                    </span>
                </div>
            </div>
            
            <?php if (count($cuentas) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Factura</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cliente</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Deuda</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Saldo Pendiente</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha Venta</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase no-print">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php 
                            // Función para resaltar coincidencias de búsqueda
                            function highlightSearch($text, $search) {
                                if (empty($search) || empty($text)) return htmlspecialchars($text);
                                $pattern = '/(' . preg_quote($search, '/') . ')/i';
                                return preg_replace($pattern, '<span class="search-highlight">$1</span>', htmlspecialchars($text));
                            }
                            
                            foreach ($cuentas as $row): 
                                // Determinar estado
                                $estado_real = $row['estado'];
                                
                                // Determinar clase del estado
                                switch($estado_real) {
                                    case 'pendiente':
                                        $estado_class = 'badge-pendiente';
                                        $estado_icon = 'fas fa-clock';
                                        break;
                                    case 'parcial':
                                        $estado_class = 'badge-parcial';
                                        $estado_icon = 'fas fa-money-bill-wave';
                                        break;
                                    case 'pagada':
                                        $estado_class = 'badge-pagada';
                                        $estado_icon = 'fas fa-check-circle';
                                        break;
                                    case 'vencida':
                                        $estado_class = 'badge-vencida';
                                        $estado_icon = 'fas fa-exclamation-triangle';
                                        break;
                                    default:
                                        $estado_class = 'bg-gray-100 text-gray-800';
                                        $estado_icon = 'fas fa-question-circle';
                                }
                                
                                $pagado = $row['total_deuda'] - $row['saldo_pendiente'];
                                $porcentaje = $row['total_deuda'] > 0 ? ($pagado / $row['total_deuda']) * 100 : 0;
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="text-sm font-bold text-gray-900">
                                        #<?php echo htmlspecialchars($row['id'] ?? 'N/A'); ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-blue-600">
                                        <?php 
                                        if (!empty($busqueda) && isset($row['numero_factura'])) {
                                            echo highlightSearch($row['numero_factura'], $busqueda);
                                        } else {
                                            echo htmlspecialchars($row['numero_factura'] ?? 'N/A');
                                        }
                                        ?>
                                    </div>
                                    <?php if (isset($row['venta_id']) && $row['venta_id']): ?>
                                        <div class="text-xs text-gray-500">
                                            Venta ID: <?php echo $row['venta_id']; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (isset($row['tipo_venta']) && $row['tipo_venta']): ?>
                                        <div class="text-xs <?php echo $row['tipo_venta'] == 'credito' ? 'text-orange-600' : 'text-green-600'; ?>">
                                            <?php echo $row['tipo_venta'] == 'credito' ? 'Crédito' : 'Contado'; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php 
                                        if (!empty($busqueda) && isset($row['cliente_nombre'])) {
                                            echo highlightSearch($row['cliente_nombre'], $busqueda);
                                        } else {
                                            echo htmlspecialchars($row['cliente_nombre'] ?? 'Cliente no encontrado');
                                        }
                                        ?>
                                    </div>
                                    <?php if (isset($row['numero_documento']) && $row['numero_documento']): ?>
                                        <div class="text-xs text-gray-500">
                                            Doc: 
                                            <?php 
                                            if (!empty($busqueda)) {
                                                echo highlightSearch($row['numero_documento'], $busqueda);
                                            } else {
                                                echo htmlspecialchars($row['numero_documento']);
                                            }
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (isset($row['telefono']) && $row['telefono']): ?>
                                        <div class="text-xs text-gray-500">
                                            Tel: <?php echo htmlspecialchars($row['telefono']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-bold text-gray-900">
                                        $<?php echo isset($row['total_deuda']) ? number_format($row['total_deuda'], 2) : '0.00'; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-bold <?php echo (isset($row['saldo_pendiente']) && $row['saldo_pendiente'] > 0) ? 'text-red-600' : 'text-green-600'; ?>">
                                        $<?php echo isset($row['saldo_pendiente']) ? number_format($row['saldo_pendiente'], 2) : '0.00'; ?>
                                    </div>
                                    <div class="text-xs <?php echo $porcentaje < 50 ? 'text-red-600' : ($porcentaje < 100 ? 'text-yellow-600' : 'text-green-600'); ?> mt-1">
                                        <?php echo number_format($porcentaje, 1); ?>% pagado
                                    </div>
                                    <div class="progress-bar-small">
                                        <div class="progress-fill" style="width: <?php echo min($porcentaje, 100); ?>%"></div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if (isset($row['fecha_venta']) && $row['fecha_venta']): ?>
                                        <div class="text-sm text-gray-900">
                                            <?php echo date('d/m/Y', strtotime($row['fecha_venta'])); ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-sm text-gray-400">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $estado_class; ?>">
                                        <i class="<?php echo $estado_icon; ?> mr-1"></i>
                                        <?php echo ucfirst($estado_real); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium space-x-2 no-print">
                                    <?php if (isset($row['id'])): ?>
                                    <a href="historial_pagos.php?cuenta_id=<?php echo $row['id']; ?>" 
                                       class="text-gray-600 hover:text-gray-900 inline-flex items-center" 
                                       title="Ver historial de pagos">
                                        <i class="fas fa-clock-history mr-1"></i>
                                    </a>
                                    <a href="ver.php?id=<?php echo $row['id']; ?>" 
                                       class="text-blue-600 hover:text-blue-900 inline-flex items-center" 
                                       title="Ver detalles">
                                        <i class="fas fa-eye mr-1"></i>
                                    </a>
                                    <a href="registrar_pago.php?cuenta_id=<?php echo $row['id']; ?>" 
                                       class="text-green-600 hover:text-green-900 inline-flex items-center" 
                                       title="Registrar pago">
                                        <i class="fas fa-cash-register mr-1"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <th colspan="3" class="px-4 py-3 text-right text-sm font-bold text-gray-900">Totales:</th>
                                <th class="px-4 py-3 text-sm font-bold text-blue-600">
                                    $<?php 
                                    $total_deuda = 0;
                                    foreach ($cuentas as $row) {
                                        $total_deuda += $row['total_deuda'] ?? 0;
                                    }
                                    echo number_format($total_deuda, 2); 
                                    ?>
                                </th>
                                <th class="px-4 py-3 text-sm font-bold <?php 
                                    $total_pendiente = 0;
                                    foreach ($cuentas as $row) {
                                        $total_pendiente += $row['saldo_pendiente'] ?? 0;
                                    }
                                    echo $total_pendiente > 0 ? 'text-red-600' : 'text-green-600'; 
                                    ?>">
                                    $<?php echo number_format($total_pendiente, 2); ?>
                                </th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-receipt text-gray-400 text-5xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">
                        <?php if (!empty($busqueda)): ?>
                            No se encontraron resultados para: "<?php echo htmlspecialchars($busqueda); ?>"
                        <?php else: ?>
                            No hay cuentas por cobrar
                        <?php endif; ?>
                    </h3>
                    <p class="text-gray-500 mb-4">
                        <?php if (!empty($busqueda)): ?>
                            No se encontraron cuentas que coincidan con la búsqueda.
                        <?php elseif ($estado != 'todos' || !empty($fecha_desde) || !empty($fecha_hasta)): ?>
                            No se encontraron cuentas con los filtros aplicados.
                        <?php else: ?>
                            No hay cuentas por cobrar registradas en el sistema.
                        <?php endif; ?>
                    </p>
                    <div class="space-x-2">
                        <?php if ($estado != 'todos' || !empty($busqueda) || !empty($fecha_desde) || !empty($fecha_hasta)): ?>
                            <a href="index.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md inline-flex items-center">
                                <i class="fas fa-times mr-2"></i>Mostrar todas las cuentas
                            </a>
                        <?php endif; ?>
                        <a href="../ventas/crear.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md inline-flex items-center">
                            <i class="fas fa-plus-circle mr-2"></i>Crear venta a crédito
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Información del reporte -->
        <div class="mt-4 text-sm text-gray-500 no-print">
            <p>Reporte generado el <?php echo date('d/m/Y H:i:s'); ?> por <?php echo $_SESSION['usuario_nombre'] ?? 'Usuario'; ?></p>
        </div>
    </div>

    <script>
    // Función para establecer fechas por defecto
    document.addEventListener('DOMContentLoaded', function() {
        // Establecer fecha fin como hoy por defecto
        const fechaHastaInput = document.getElementById('fecha_hasta');
        if (fechaHastaInput && !fechaHastaInput.value) {
            fechaHastaInput.value = '<?php echo date("Y-m-d"); ?>';
        }
        
        // Establecer fecha inicio como primer día del mes por defecto
        const fechaDesdeInput = document.getElementById('fecha_desde');
        if (fechaDesdeInput && !fechaDesdeInput.value) {
            fechaDesdeInput.value = '<?php echo date("Y-m-01"); ?>';
        }
        
        // Auto-focus en el campo de búsqueda
        const busquedaInput = document.getElementById('busqueda');
        if (busquedaInput && '<?php echo $busqueda; ?>' === '') {
            busquedaInput.focus();
        }
        
        // Buscar con Enter
        busquedaInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                document.querySelector('form').submit();
            }
        });
    });
    
    // Función para exportar a Excel
    function exportarExcel() {
        const table = document.querySelector('table');
        const html = table.outerHTML;
        const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'cuentas_por_cobrar_<?php echo date("Y-m-d"); ?>.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }
    </script>

    <?php include __DIR__ . '/../../includes/footer.php'; ?>
</body>
</html>