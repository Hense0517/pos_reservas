<?php
if (session_status() === PHP_SESSION_NONE) session_start(); 
// Activar errores para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

ob_start();

// Incluir header PRIMERO - manejará la sesión y autenticación
include '../../includes/header.php';

// Incluir configuración de base de datos
require_once __DIR__ . '/../../config/database.php';

// FUNCIÓN SEGURA PARA FORMATEAR NÚMEROS (AGREGADA)
function formato_moneda($valor) {
    return number_format((float)($valor ?? 0), 2);
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// Parámetros de filtro
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$metodo_pago = $_GET['metodo_pago'] ?? '';
$cliente_id = $_GET['cliente_id'] ?? '';
$usuario_id = $_GET['usuario_id'] ?? '';
$venta_id = $_GET['venta_id'] ?? '';
$estado_pago = $_GET['estado_pago'] ?? '';
$tipo_pago = $_GET['tipo_pago'] ?? '';

// Obtener lista de clientes para el filtro
$clientes = [];
try {
    $clientes_sql = "SELECT id, nombre FROM clientes WHERE activo = 1 ORDER BY nombre";
    $clientes_stmt = $db->prepare($clientes_sql);
    $clientes_stmt->execute();
    $clientes = $clientes_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Silenciar error
}

// Obtener lista de usuarios para el filtro
$usuarios = [];
try {
    $usuarios_sql = "SELECT id, nombre FROM usuarios WHERE activo = 1 ORDER BY nombre";
    $usuarios_stmt = $db->prepare($usuarios_sql);
    $usuarios_stmt->execute();
    $usuarios = $usuarios_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Silenciar error
}

// Métodos de pago disponibles
$metodos_pago = ['efectivo', 'tarjeta', 'transferencia', 'cheque', 'nequi', 'daviplata', 'consignacion', 'otros'];

// Tipos de pago disponibles
$tipos_pago = [
    'todos' => 'Todos los tipos',
    'abono_inicial' => 'Abonos Iniciales',
    'pago_deuda' => 'Pagos a Deuda'
];

// Construir consulta para pagos - CORREGIDA PARA EVITAR ERROR "Cuenta no encontrada"
try {
    $query = "SELECT 
                p.*,
                c.nombre as cliente_nombre,
                c.telefono as cliente_telefono,
                cli.id as cliente_id,
                cli.nombre as cliente_nombre_completo,
                v.numero_factura,
                v.total as total_venta,
                v.tipo_venta,
                v.fecha as fecha_venta,
                u.nombre as usuario_nombre,
                IFNULL(cc.total_deuda, 0) as total_deuda,
                IFNULL(cc.saldo_pendiente, 0) as saldo_actual,
                IFNULL(cc.estado, 'no_registrada') as estado_cuenta
              FROM pagos_cuentas_por_cobrar p
              LEFT JOIN cuentas_por_cobrar cc ON p.cuenta_id = cc.id
              LEFT JOIN clientes cli ON cc.cliente_id = cli.id
              LEFT JOIN ventas v ON cc.venta_id = v.id
              LEFT JOIN usuarios u ON p.usuario_id = u.id
              LEFT JOIN clientes c ON cc.cliente_id = c.id
              WHERE DATE(p.fecha_pago) BETWEEN ? AND ?";
    
    $params = [$fecha_inicio, $fecha_fin];
    
    // Filtro por método de pago
    if (!empty($metodo_pago)) {
        $query .= " AND p.metodo_pago = ?";
        $params[] = $metodo_pago;
    }
    
    // Filtro por cliente
    if (!empty($cliente_id) && is_numeric($cliente_id)) {
        $query .= " AND cc.cliente_id = ?";
        $params[] = $cliente_id;
    }
    
    // Filtro por usuario que registró el pago
    if (!empty($usuario_id) && is_numeric($usuario_id)) {
        $query .= " AND p.usuario_id = ?";
        $params[] = $usuario_id;
    }
    
    // Filtro por venta
    if (!empty($venta_id) && is_numeric($venta_id)) {
        $query .= " AND cc.venta_id = ?";
        $params[] = $venta_id;
    }
    
    // Filtro por tipo de pago
    if (!empty($tipo_pago) && $tipo_pago !== 'todos') {
        if ($tipo_pago === 'abono_inicial') {
            $query .= " AND p.tipo_pago = 'abono_inicial'";
        } elseif ($tipo_pago === 'pago_deuda') {
            $query .= " AND p.tipo_pago = 'pago_deuda'";
        }
    }
    
    // Filtro por estado (basado en relación con cuenta)
    if (!empty($estado_pago)) {
        if ($estado_pago === 'completos') {
            $query .= " AND cc.estado = 'pagada'";
        } elseif ($estado_pago === 'parciales') {
            $query .= " AND cc.estado = 'parcial'";
        } elseif ($estado_pago === 'pendientes') {
            $query .= " AND cc.estado = 'pendiente' AND cc.saldo_pendiente > 0";
        } elseif ($estado_pago === 'no_registrada') {
            $query .= " AND cc.id IS NULL";
        }
    }
    
    $query .= " ORDER BY p.fecha_pago DESC, p.id DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Estadísticas de pagos - CORREGIDA PARA EVITAR ERRORES
    $stats_query = "SELECT 
                    COUNT(*) as total_pagos,
                    SUM(p.monto) as total_monto_pagado,
                    AVG(p.monto) as promedio_pago,
                    COUNT(DISTINCT p.usuario_id) as total_usuarios,
                    COUNT(DISTINCT cc.cliente_id) as total_clientes,
                    SUM(CASE WHEN p.tipo_pago = 'abono_inicial' THEN p.monto ELSE 0 END) as total_abonos_iniciales,
                    SUM(CASE WHEN p.tipo_pago = 'pago_deuda' THEN p.monto ELSE 0 END) as total_pagos_deuda,
                    COUNT(CASE WHEN p.tipo_pago = 'abono_inicial' THEN 1 END) as cantidad_abonos_iniciales,
                    COUNT(CASE WHEN p.tipo_pago = 'pago_deuda' THEN 1 END) as cantidad_pagos_deuda
                    FROM pagos_cuentas_por_cobrar p
                    LEFT JOIN cuentas_por_cobrar cc ON p.cuenta_id = cc.id
                    WHERE DATE(p.fecha_pago) BETWEEN ? AND ?";
    
    $stats_params = [$fecha_inicio, $fecha_fin];
    
    if (!empty($metodo_pago)) {
        $stats_query .= " AND p.metodo_pago = ?";
        $stats_params[] = $metodo_pago;
    }
    
    if (!empty($cliente_id) && is_numeric($cliente_id)) {
        $stats_query .= " AND cc.cliente_id = ?";
        $stats_params[] = $cliente_id;
    }
    
    if (!empty($tipo_pago) && $tipo_pago !== 'todos') {
        if ($tipo_pago === 'abono_inicial') {
            $stats_query .= " AND p.tipo_pago = 'abono_inicial'";
        } elseif ($tipo_pago === 'pago_deuda') {
            $stats_query .= " AND p.tipo_pago = 'pago_deuda'";
        }
    }
    
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->execute($stats_params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Si hay errores en las estadísticas, inicializar valores por defecto
    if (!$stats) {
        $stats = [
            'total_pagos' => 0,
            'total_monto_pagado' => 0,
            'promedio_pago' => 0,
            'total_usuarios' => 0,
            'total_clientes' => 0,
            'total_abonos_iniciales' => 0,
            'total_pagos_deuda' => 0,
            'cantidad_abonos_iniciales' => 0,
            'cantidad_pagos_deuda' => 0
        ];
    }
    
    // Asegurar que todos los valores sean numéricos (NO null)
    foreach ($stats as $key => $value) {
        $stats[$key] = (float)($value ?? 0);
    }
    
    // Calcular porcentajes con validación
    $stats['porcentaje_abonos_iniciales'] = $stats['total_monto_pagado'] > 0 ? 
        ($stats['total_abonos_iniciales'] / $stats['total_monto_pagado']) * 100 : 0;
    
    $stats['porcentaje_pagos_deuda'] = $stats['total_monto_pagado'] > 0 ? 
        ($stats['total_pagos_deuda'] / $stats['total_monto_pagado']) * 100 : 0;
    
} catch (Exception $e) {
    error_log("Error en consulta de pagos: " . $e->getMessage());
    $_SESSION['error'] = "Error al cargar el historial de pagos: " . $e->getMessage();
    $pagos = [];
    $stats = [
        'total_pagos' => 0,
        'total_monto_pagado' => 0,
        'promedio_pago' => 0,
        'total_usuarios' => 0,
        'total_clientes' => 0,
        'total_abonos_iniciales' => 0,
        'total_pagos_deuda' => 0,
        'cantidad_abonos_iniciales' => 0,
        'cantidad_pagos_deuda' => 0,
        'porcentaje_abonos_iniciales' => 0,
        'porcentaje_pagos_deuda' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial de Pagos - Sistema POS</title>
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
        .badge-abono-inicial {
            background-color: #fef3c7;
            color: #92400e;
        }
        .badge-pago-deuda {
            background-color: #d1fae5;
            color: #065f46;
        }
        .badge-no-registrada {
            background-color: #e5e7eb;
            color: #374151;
        }
        .btn-comprobante {
            transition: all 0.3s ease;
        }
        .btn-comprobante:hover {
            transform: scale(1.1);
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Header ya incluido arriba -->
    
    <div class="max-w-7xl mx-auto px-4 py-6">
        <div class="flex justify-between items-center mb-6">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Historial de Pagos</h1>
                <p class="text-gray-600">Registro de todos los pagos realizados en cuentas por cobrar</p>
            </div>
            <div class="flex space-x-2 no-print">
                <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Volver a Ventas
                </a>
                <button onclick="window.print()" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-file-pdf mr-2"></i>
                    Imprimir/PDF
                </button>
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

        <!-- Estadísticas Mejoradas - TODAS CON formato_moneda() -->
        <div class="grid grid-cols-1 md:grid-cols-7 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-blue-100 text-blue-600">
                        <i class="fas fa-money-check-alt"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-500">Total Pagos</p>
                        <p class="text-lg font-bold text-gray-900"><?php echo (int)$stats['total_pagos']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-green-100 text-green-600">
                        <i class="fas fa-dollar-sign"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-500">Monto Total</p>
                        <p class="text-lg font-bold text-gray-900">$<?php echo formato_moneda($stats['total_monto_pagado']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-yellow-100 text-yellow-600">
                        <i class="fas fa-hand-holding-usd"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-500">Abonos Iniciales</p>
                        <p class="text-lg font-bold text-yellow-600">$<?php echo formato_moneda($stats['total_abonos_iniciales']); ?></p>
                        <p class="text-xs text-gray-500"><?php echo (int)$stats['cantidad_abonos_iniciales']; ?> abonos</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-teal-100 text-teal-600">
                        <i class="fas fa-credit-card"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-500">Pagos a Deuda</p>
                        <p class="text-lg font-bold text-teal-600">$<?php echo formato_moneda($stats['total_pagos_deuda']); ?></p>
                        <p class="text-xs text-gray-500"><?php echo (int)$stats['cantidad_pagos_deuda']; ?> pagos</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-purple-100 text-purple-600">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-500">Promedio/Pago</p>
                        <p class="text-lg font-bold text-gray-900">$<?php echo formato_moneda($stats['promedio_pago']); ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-orange-100 text-orange-600">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-500">Clientes</p>
                        <p class="text-lg font-bold text-gray-900"><?php echo (int)$stats['total_clientes']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-lg shadow p-4">
                <div class="flex items-center">
                    <div class="p-2 rounded-full bg-red-100 text-red-600">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-gray-500">Usuarios</p>
                        <p class="text-lg font-bold text-gray-900"><?php echo (int)$stats['total_usuarios']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtros -->
        <div class="bg-white rounded-lg shadow mb-6 no-print">
            <div class="p-4 border-b border-gray-200">
                <h3 class="text-lg font-medium text-gray-900">Filtros de Búsqueda</h3>
            </div>
            <div class="p-4">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-8 gap-4">
                    <div>
                        <label for="fecha_inicio" class="block text-sm font-medium text-gray-700">Fecha Inicio</label>
                        <input type="date" id="fecha_inicio" name="fecha_inicio" 
                               value="<?php echo $fecha_inicio; ?>"
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label for="fecha_fin" class="block text-sm font-medium text-gray-700">Fecha Fin</label>
                        <input type="date" id="fecha_fin" name="fecha_fin" 
                               value="<?php echo $fecha_fin; ?>"
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label for="metodo_pago" class="block text-sm font-medium text-gray-700">Método Pago</label>
                        <select id="metodo_pago" name="metodo_pago" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Todos los métodos</option>
                            <?php foreach ($metodos_pago as $metodo): ?>
                                <option value="<?php echo $metodo; ?>" <?php echo $metodo_pago == $metodo ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($metodo); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="tipo_pago" class="block text-sm font-medium text-gray-700">Tipo de Pago</label>
                        <select id="tipo_pago" name="tipo_pago" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($tipos_pago as $key => $value): ?>
                                <option value="<?php echo $key; ?>" <?php echo $tipo_pago == $key ? 'selected' : ''; ?>>
                                    <?php echo $value; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="cliente_id" class="block text-sm font-medium text-gray-700">Cliente</label>
                        <select id="cliente_id" name="cliente_id" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Todos los clientes</option>
                            <?php foreach ($clientes as $cliente): ?>
                                <option value="<?php echo $cliente['id']; ?>" <?php echo $cliente_id == $cliente['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cliente['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="usuario_id" class="block text-sm font-medium text-gray-700">Usuario</label>
                        <select id="usuario_id" name="usuario_id" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Todos los usuarios</option>
                            <?php foreach ($usuarios as $usuario): ?>
                                <option value="<?php echo $usuario['id']; ?>" <?php echo $usuario_id == $usuario['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($usuario['nombre']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="estado_pago" class="block text-sm font-medium text-gray-700">Estado</label>
                        <select id="estado_pago" name="estado_pago" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Todos los estados</option>
                            <option value="completos" <?php echo $estado_pago == 'completos' ? 'selected' : ''; ?>>Completos</option>
                            <option value="parciales" <?php echo $estado_pago == 'parciales' ? 'selected' : ''; ?>>Parciales</option>
                            <option value="pendientes" <?php echo $estado_pago == 'pendientes' ? 'selected' : ''; ?>>Pendientes</option>
                            <option value="no_registrada" <?php echo $estado_pago == 'no_registrada' ? 'selected' : ''; ?>>Cuenta No Registrada</option>
                        </select>
                    </div>
                    
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md w-full">
                            <i class="fas fa-search mr-2"></i>Buscar
                        </button>
                        <a href="historial_pagos.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-md w-full text-center">
                            <i class="fas fa-times mr-2"></i>Limpiar
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de Pagos -->
        <div class="bg-white shadow overflow-hidden sm:rounded-lg">
            <div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex justify-between items-center no-print">
                <div>
                    <span class="text-sm text-gray-600">
                        Mostrando <?php echo count($pagos); ?> pagos
                        <?php if (!empty($fecha_inicio) || !empty($fecha_fin)): ?>
                            en el período <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div>
                    <span class="text-sm font-semibold text-gray-900">
                        Total filtrado: $<?php echo formato_moneda($stats['total_monto_pagado']); ?>
                    </span>
                </div>
            </div>
            
            <?php if (count($pagos) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha Pago</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cliente</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Factura</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tipo Pago</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Método</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Monto</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Usuario</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase no-print">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($pagos as $pago): 
                                // Determinar clase del estado
                                $estado_cuenta = $pago['estado_cuenta'] ?? 'no_registrada';
                                
                                switch($estado_cuenta) {
                                    case 'pagada':
                                        $estado_class = 'bg-green-100 text-green-800';
                                        $estado_text = 'Pagado';
                                        break;
                                    case 'parcial':
                                        $estado_class = 'bg-blue-100 text-blue-800';
                                        $estado_text = 'Parcial';
                                        break;
                                    case 'pendiente':
                                        $estado_class = 'bg-yellow-100 text-yellow-800';
                                        $estado_text = 'Pendiente';
                                        break;
                                    case 'no_registrada':
                                    default:
                                        $estado_class = 'bg-gray-100 text-gray-800';
                                        $estado_text = 'No Registrada';
                                        break;
                                }
                                
                                // Determinar icono del método de pago
                                switch(strtolower($pago['metodo_pago'])) {
                                    case 'efectivo':
                                        $metodo_icon = 'fas fa-money-bill-wave text-green-600';
                                        break;
                                    case 'tarjeta':
                                        $metodo_icon = 'fas fa-credit-card text-purple-600';
                                        break;
                                    case 'transferencia':
                                    case 'consignacion':
                                        $metodo_icon = 'fas fa-university text-blue-600';
                                        break;
                                    case 'cheque':
                                        $metodo_icon = 'fas fa-file-invoice-dollar text-yellow-600';
                                        break;
                                    default:
                                        $metodo_icon = 'fas fa-wallet text-gray-600';
                                }
                                
                                // Determinar tipo de pago
                                $tipo_pago_display = $pago['tipo_pago'] ?? 'pago_deuda';
                                if ($tipo_pago_display == 'abono_inicial') {
                                    $tipo_class = 'badge-abono-inicial';
                                    $tipo_text = 'Abono Inicial';
                                    $tipo_icon = 'fas fa-hand-holding-usd';
                                } else {
                                    $tipo_class = 'badge-pago-deuda';
                                    $tipo_text = 'Pago a Deuda';
                                    $tipo_icon = 'fas fa-credit-card';
                                }
                                
                                // Validar si existe cliente y factura
                                $cliente_nombre = $pago['cliente_nombre_completo'] ?? $pago['cliente_nombre'] ?? 'Cliente General';
                                $factura = $pago['numero_factura'] ?? 'N/A';
                                $cuenta_id = $pago['cuenta_id'] ?? 0;
                                $pago_id = $pago['id'] ?? 0;
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo date('d/m/Y', strtotime($pago['fecha_pago'])); ?>
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo date('H:i', strtotime($pago['fecha_pago'])); ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if ($cuenta_id > 0 && $pago['cliente_id']): ?>
                                        <div class="text-sm text-gray-900">
                                            <?php echo htmlspecialchars($cliente_nombre); ?>
                                        </div>
                                        <?php if (!empty($pago['cliente_telefono'])): ?>
                                            <div class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($pago['cliente_telefono']); ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="text-sm text-gray-900 italic">
                                            Información no disponible
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            Cuenta ID: <?php echo $cuenta_id; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <?php if ($factura != 'N/A'): ?>
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($factura); ?>
                                        </div>
                                        <?php if ($pago['tipo_venta']): ?>
                                            <div class="text-xs <?php echo $pago['tipo_venta'] == 'credito' ? 'text-orange-600' : 'text-purple-600'; ?>">
                                                <?php echo $pago['tipo_venta'] == 'credito' ? 'Crédito' : 'Contado'; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="text-sm text-gray-500 italic">
                                            Sin factura
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $tipo_class; ?>">
                                        <i class="<?php echo $tipo_icon; ?> mr-1"></i>
                                        <?php echo $tipo_text; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center">
                                        <i class="<?php echo $metodo_icon; ?> mr-2"></i>
                                        <span class="text-sm text-gray-900">
                                            <?php echo ucfirst($pago['metodo_pago']); ?>
                                        </span>
                                    </div>
                                    <?php if (!empty($pago['referencia'])): ?>
                                        <div class="text-xs text-gray-500 mt-1">
                                            Ref: <?php echo htmlspecialchars($pago['referencia']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm font-semibold text-gray-900">
                                        $<?php echo formato_moneda($pago['monto']); ?>
                                    </div>
                                    <?php if ($pago['total_deuda'] > 0): 
                                        $porcentaje_pagado = ($pago['monto'] / $pago['total_deuda']) * 100;
                                    ?>
                                        <div class="text-xs text-gray-500 mt-1">
                                            De $<?php echo formato_moneda($pago['total_deuda']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="text-sm text-gray-900">
                                        <?php echo htmlspecialchars($pago['usuario_nombre'] ?? 'N/A'); ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $estado_class; ?>">
                                        <?php echo $estado_text; ?>
                                    </span>
                                    <?php if ($pago['saldo_actual'] > 0): ?>
                                        <div class="text-xs text-red-600 mt-1">
                                            Saldo: $<?php echo formato_moneda($pago['saldo_actual']); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium space-x-2 no-print">
                                    <?php if ($cuenta_id > 0): ?>
                                        <a href="ver_pago.php?cuenta_id=<?php echo $cuenta_id; ?>&pago_id=<?php echo $pago_id; ?>" 
                                           class="text-blue-600 hover:text-blue-900 btn-comprobante" 
                                           title="Ver comprobante" target="_blank">
                                            <i class="fas fa-receipt"></i>
                                        </a>
                                        
                                        <a href="ver.php?id=<?php echo $cuenta_id; ?>" 
                                           class="text-green-600 hover:text-green-900 btn-comprobante" 
                                           title="Ver cuenta">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <a href="ver_pago_ticket.php?cuenta_id=<?php echo $cuenta_id; ?>&pago_id=<?php echo $pago_id; ?>" 
                                           class="text-purple-600 hover:text-purple-900 btn-comprobante" 
                                           title="Imprimir ticket" target="_blank">
                                            <i class="fas fa-print"></i>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-gray-400" title="Cuenta no disponible">
                                            <i class="fas fa-ban"></i>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($_SESSION['usuario_rol'] == 'admin'): ?>
                                    <a href="editar_pago.php?id=<?php echo $pago_id; ?>" 
                                       class="text-yellow-600 hover:text-yellow-900 btn-comprobante" 
                                       title="Editar pago">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    
                                    <a href="eliminar_pago.php?id=<?php echo $pago_id; ?>" 
                                       class="text-red-600 hover:text-red-900 btn-comprobante" 
                                       title="Eliminar pago" 
                                       onclick="return confirm('¿Está seguro de eliminar este pago?')">
                                        <i class="fas fa-trash-alt"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="5" class="px-4 py-3 text-right text-sm font-semibold text-gray-900">
                                    Total General:
                                </td>
                                <td class="px-4 py-3 text-sm font-bold text-gray-900">
                                    $<?php echo formato_moneda($stats['total_monto_pagado']); ?>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-money-check-alt text-gray-400 text-5xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No hay pagos registrados</h3>
                    <p class="text-gray-500 mb-4">No se encontraron pagos con los filtros aplicados.</p>
                    <p class="text-sm text-gray-400">Prueba con diferentes fechas o filtros.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Información del reporte -->
        <div class="mt-4 text-sm text-gray-500 no-print">
            <p>Reporte generado el <?php echo date('d/m/Y H:i:s'); ?> por <?php echo $_SESSION['usuario_nombre'] ?? 'Usuario'; ?></p>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Establecer fecha fin como hoy por defecto
        const fechaFinInput = document.getElementById('fecha_fin');
        if (fechaFinInput && !fechaFinInput.value) {
            fechaFinInput.value = '<?php echo date("Y-m-d"); ?>';
        }
        
        // Establecer fecha inicio como primer día del mes por defecto
        const fechaInicioInput = document.getElementById('fecha_inicio');
        if (fechaInicioInput && !fechaInicioInput.value) {
            fechaInicioInput.value = '<?php echo date("Y-m-01"); ?>';
        }
        
        // Alerta para pagos con cuenta no registrada
        const estadoSelect = document.getElementById('estado_pago');
        if (estadoSelect) {
            estadoSelect.addEventListener('change', function() {
                if (this.value === 'no_registrada') {
                    alert('Mostrando pagos donde la cuenta asociada no fue encontrada en el sistema.\nEsto puede deberse a:\n1. Eliminación de la cuenta\n2. Error en la vinculación\n3. Datos corruptos');
                }
            });
        }
        
        // Mejorar la experiencia de los botones
        document.querySelectorAll('.btn-comprobante').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (this.href.includes('eliminar_pago')) return;
                
                if (this.target === '_blank') {
                    e.preventDefault();
                    window.open(this.href, '_blank', 'width=800,height=600');
                }
            });
        });
        
        // Botón para exportar a Excel
        const exportBtn = document.createElement('button');
        exportBtn.className = 'bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center no-print ml-2';
        exportBtn.innerHTML = '<i class="fas fa-file-excel mr-2"></i>Exportar Excel';
        exportBtn.onclick = exportarExcel;
        
        // Agregar botón al header
        const headerButtons = document.querySelector('.flex.space-x-2.no-print');
        if (headerButtons) {
            headerButtons.appendChild(exportBtn);
        }
        
        // Función para exportar a Excel
        function exportarExcel() {
            const table = document.querySelector('table');
            let html = '<table>';
            html += table.innerHTML;
            html += '</table>';
            
            // Crear archivo Excel
            const blob = new Blob([`
                <html>
                <head>
                    <meta charset="UTF-8">
                    <style>
                        table { border-collapse: collapse; width: 100%; }
                        th, td { border: 1px solid #ddd; padding: 8px; }
                        th { background-color: #f2f2f2; }
                        tr:nth-child(even) { background-color: #f9f9f9; }
                    </style>
                </head>
                <body>
                    <h2>Historial de Pagos - <?php echo date('d/m/Y H:i:s'); ?></h2>
                    <p>Período: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></p>
                    <p>Total: $<?php echo formato_moneda($stats['total_monto_pagado']); ?></p>
                    ${html}
                </body>
                </html>
            `], { type: 'application/vnd.ms-excel' });
            
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'historial_pagos_<?php echo date("Y-m-d_His"); ?>.xls';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
    });
    </script>

    <?php include '../../includes/footer.php'; ?>
</body>
</html>
<?php ob_end_flush(); ?>