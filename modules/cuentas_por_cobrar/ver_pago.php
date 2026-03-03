<?php
/**
 * ============================================
 * ARCHIVO: ver_pago.php
 * UBICACIÓN: /modules/cuentas_por_cobrar/ver_pago.php
 * FECHA CORRECCIÓN: 2026-02-17
 * 
 * PROPÓSITO:
 * Mostrar detalles completos de un pago específico
 * 
 * CORRECCIONES APLICADAS:
 * 1. Rutas absolutas con __DIR__
 * 2. Usa header/footer del sistema (estilos de recursos.php)
 * 3. Forza zona horaria Colombia
 * 4. Usa BASE_URL para redirecciones
 * 5. Función para limpiar formato de moneda
 * ============================================
 */

// Forzar zona horaria Colombia
date_default_timezone_set('America/Bogota');

session_start();

// ============================================
// FUNCIONES AUXILIARES
// ============================================

function formatMoney($amount) {
    return '$' . number_format(floatval($amount), 0, ',', '.');
}

function limpiarMontoColombiano($monto_formateado) {
    if (empty($monto_formateado)) return 0;
    $limpio = str_replace('$', '', $monto_formateado);
    $limpio = trim($limpio);
    if (strpos($limpio, ',') !== false) {
        $limpio = str_replace('.', '', $limpio);
        $limpio = str_replace(',', '.', $limpio);
    } else {
        $limpio = str_replace('.', '', $limpio);
    }
    return floatval($limpio);
}

// ============================================
// CONFIGURACIÓN INICIAL
// ============================================
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permisos
$roles_permitidos = ['admin', 'cajero', 'vendedor'];
if (!isset($_SESSION['usuario_rol']) || !in_array($_SESSION['usuario_rol'], $roles_permitidos)) {
    $_SESSION['error'] = "No tienes permisos para realizar esta acción";
    header("Location: " . BASE_URL . "index.php");
    exit();
}

$pago_id = isset($_GET['pago_id']) ? intval($_GET['pago_id']) : 0;
$cuenta_id = isset($_GET['cuenta_id']) ? intval($_GET['cuenta_id']) : 0;

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// Verificar si la tabla de pagos existe
$table_exists = false;
try {
    $check_table = $db->query("SHOW TABLES LIKE 'pagos_cuentas_por_cobrar'");
    $table_exists = $check_table->fetch();
} catch (Exception $e) {
    $table_exists = false;
}

// Obtener información del pago
$pago = null;
$cuenta = null;
$pagos_historial = [];
$total_pagado = 0;

try {
    // Si tenemos un ID de pago específico
    if ($table_exists && $pago_id > 0) {
        $query_pago = "SELECT p.*, 
                              u.nombre as usuario_nombre,
                              u.username as usuario_username
                       FROM pagos_cuentas_por_cobrar p
                       LEFT JOIN usuarios u ON p.usuario_id = u.id
                       WHERE p.id = ?";
        
        $stmt_pago = $db->prepare($query_pago);
        $stmt_pago->execute([$pago_id]);
        $pago = $stmt_pago->fetch(PDO::FETCH_ASSOC);
        
        if ($pago) {
            $cuenta_id = $pago['cuenta_id'];
        }
    }
    
    // Si no tenemos pago específico pero tenemos cuenta_id
    if ($cuenta_id > 0) {
        // Obtener información de la cuenta
        $query_cuenta = "SELECT cp.*, 
                                c.nombre as cliente_nombre, 
                                c.telefono,
                                c.direccion as cliente_direccion,
                                c.numero_documento as cliente_documento,
                                c.email as cliente_email,
                                c.ruc as cliente_ruc,
                                v.numero_factura,
                                v.total as total_venta,
                                v.fecha as fecha_venta,
                                v.estado as estado_venta,
                                v.abono_inicial,
                                v.descuento,
                                v.subtotal,
                                v.metodo_pago as metodo_pago_venta,
                                v.tipo_venta,
                                v.cambio,
                                u.nombre as usuario_venta_nombre,
                                v.observaciones as observaciones_venta
                         FROM cuentas_por_cobrar cp
                         LEFT JOIN clientes c ON cp.cliente_id = c.id
                         LEFT JOIN ventas v ON cp.venta_id = v.id
                         LEFT JOIN usuarios u ON v.usuario_id = u.id
                         WHERE cp.id = ?";
        
        $stmt_cuenta = $db->prepare($query_cuenta);
        $stmt_cuenta->execute([$cuenta_id]);
        $cuenta = $stmt_cuenta->fetch(PDO::FETCH_ASSOC);
        
        if (!$cuenta) {
            throw new Exception("Cuenta no encontrada");
        }
        
        // Obtener todos los pagos de esta cuenta
        if ($table_exists) {
            $query_pagos = "SELECT p.*, u.nombre as usuario_nombre
                            FROM pagos_cuentas_por_cobrar p
                            LEFT JOIN usuarios u ON p.usuario_id = u.id
                            WHERE p.cuenta_id = ?
                            ORDER BY p.fecha_pago ASC";
            
            $stmt_pagos = $db->prepare($query_pagos);
            $stmt_pagos->execute([$cuenta_id]);
            $pagos_historial = $stmt_pagos->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($pagos_historial as $p) {
                $total_pagado += floatval($p['monto']);
            }
        }
        
        // Si no hay pago específico pero hay historial, usar el último pago
        if (!$pago && !empty($pagos_historial)) {
            $pago = end($pagos_historial);
        }
    } else {
        throw new Exception("No se especificó una cuenta o pago válido");
    }
    
} catch (Exception $e) {
    $_SESSION['error'] = "Error al obtener información: " . $e->getMessage();
    header("Location: historial_pagos.php");
    exit();
}

// Calcular saldos
$saldo_pendiente = floatval($cuenta['saldo_pendiente']);
$total_deuda = floatval($cuenta['total_deuda']);
$pagado = $total_pagado;
$porcentaje_pagado = ($total_deuda > 0) ? ($pagado / $total_deuda) * 100 : 0;

// Determinar si la cuenta está pagada
$esta_pagada = ($saldo_pendiente <= 0);

// ============================================
// INCLUIR HEADER DEL SISTEMA
// ============================================
include __DIR__ . '/../../includes/header.php';
?>

<style>
.badge-estado {
    font-size: 0.85em;
    padding: 0.35em 0.65em;
}
.progress {
    height: 10px;
}
.info-box {
    background: #f8fafc;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    border-left: 4px solid #3b82f6;
}
.info-box h6 {
    color: #4b5563;
    margin-bottom: 5px;
}
.timeline {
    position: relative;
    padding-left: 30px;
}
.timeline::before {
    content: '';
    position: absolute;
    left: 15px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e2e8f0;
}
.timeline-item {
    position: relative;
    margin-bottom: 20px;
}
.timeline-item::before {
    content: '';
    position: absolute;
    left: -25px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #3b82f6;
    border: 2px solid white;
    box-shadow: 0 0 0 2px #3b82f6;
}
.timeline-item.pagada::before {
    background: #10b981;
    box-shadow: 0 0 0 2px #10b981;
}
.btn-print {
    background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%);
    border: none;
    color: white;
}
.btn-print:hover {
    background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
    color: white;
}
.metodo-icon {
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    margin-right: 10px;
}
.metodo-efectivo {
    background: #dcfce7;
    color: #059669;
}
.metodo-tarjeta {
    background: #dbeafe;
    color: #3b82f6;
}
.metodo-transferencia {
    background: #e0e7ff;
    color: #6366f1;
}
.metodo-cheque {
    background: #fef3c7;
    color: #d97706;
}
.metodo-nequi, .metodo-daviplata {
    background: #dbeafe;
    color: #1d4ed8;
}
.metodo-otros {
    background: #e5e7eb;
    color: #4b5563;
}
</style>

<div class="max-w-7xl mx-auto p-6">
    <!-- Encabezado -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-money-check-alt text-green-600 mr-2"></i>
                Detalles del Pago
            </h1>
            <nav class="flex items-center space-x-2 text-sm text-gray-500 mt-2">
                <a href="<?php echo BASE_URL; ?>index.php" class="hover:text-blue-600">Inicio</a>
                <i class="fas fa-chevron-right text-xs"></i>
                <a href="historial_pagos.php" class="hover:text-blue-600">Historial de Pagos</a>
                <i class="fas fa-chevron-right text-xs"></i>
                <span class="text-gray-700">Detalles</span>
            </nav>
        </div>
        <div class="flex space-x-3">
            <a href="ver_pago_ticket.php?cuenta_id=<?php echo $cuenta_id; ?>&pago_id=<?php echo $pago_id; ?>" 
               target="_blank"
               class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-print mr-2"></i>
                Imprimir Comprobante
            </a>
            <a href="registrar_pago.php?cuenta_id=<?php echo $cuenta_id; ?>" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-plus-circle mr-2"></i>
                Nuevo Pago
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 flex items-center">
            <i class="fas fa-exclamation-triangle mr-3"></i>
            <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4 flex items-center">
            <i class="fas fa-check-circle mr-3"></i>
            <span><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Información del Cliente y Cuenta -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="bg-blue-600 px-6 py-4">
                <h2 class="text-xl font-bold text-white">
                    <i class="fas fa-user-circle mr-2"></i>
                    Información del Cliente
                </h2>
            </div>
            <div class="p-6">
                <div class="info-box">
                    <h6 class="text-sm text-gray-600 mb-2">Cliente</h6>
                    <h3 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($cuenta['cliente_nombre']); ?></h3>
                    <?php if ($cuenta['telefono']): ?>
                        <p class="mt-2 text-gray-600">
                            <i class="fas fa-phone mr-2"></i><?php echo htmlspecialchars($cuenta['telefono']); ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($cuenta['cliente_documento']): ?>
                        <p class="mt-1 text-gray-600">
                            <i class="fas fa-id-card mr-2"></i>Documento: <?php echo htmlspecialchars($cuenta['cliente_documento']); ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($cuenta['cliente_email']): ?>
                        <p class="mt-1 text-gray-600">
                            <i class="fas fa-envelope mr-2"></i><?php echo htmlspecialchars($cuenta['cliente_email']); ?>
                        </p>
                    <?php endif; ?>
                </div>
                
                <div class="info-box">
                    <h6 class="text-sm text-gray-600 mb-2">Información de la Venta</h6>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500">Factura</p>
                            <p class="font-bold text-blue-600"><?php echo htmlspecialchars($cuenta['numero_factura']); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Fecha Venta</p>
                            <p class="font-medium"><?php echo date('d/m/Y H:i', strtotime($cuenta['fecha_venta'])); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Vendedor</p>
                            <p class="font-medium"><?php echo htmlspecialchars($cuenta['usuario_venta_nombre'] ?? 'N/A'); ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Tipo Venta</p>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $cuenta['tipo_venta'] == 'credito' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'; ?>">
                                <?php echo $cuenta['tipo_venta'] == 'credito' ? 'Crédito' : 'Contado'; ?>
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="info-box">
                    <h6 class="text-sm text-gray-600 mb-2">Estado de la Cuenta</h6>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Total Deuda:</span>
                            <span class="font-bold text-gray-900">$<?php echo number_format($total_deuda, 0, ',', '.'); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Total Pagado:</span>
                            <span class="font-bold text-green-600">$<?php echo number_format($pagado, 0, ',', '.'); ?></span>
                        </div>
                        <div class="flex justify-between border-t border-gray-200 pt-2">
                            <span class="text-gray-600">Saldo Pendiente:</span>
                            <span class="font-bold text-red-600 text-xl">$<?php echo number_format($saldo_pendiente, 0, ',', '.'); ?></span>
                        </div>
                    </div>
                    
                    <div class="mt-4">
                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                            <span>Progreso de pago</span>
                            <span class="font-semibold"><?php echo number_format($porcentaje_pagado, 1); ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <div class="bg-green-600 h-2.5 rounded-full" style="width: <?php echo $porcentaje_pagado; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Detalles del Pago -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="bg-green-600 px-6 py-4">
                <h2 class="text-xl font-bold text-white">
                    <i class="fas fa-file-invoice-dollar mr-2"></i>
                    Detalles del Pago
                </h2>
            </div>
            <div class="p-6">
                <?php if ($pago): ?>
                    <div class="info-box">
                        <h6 class="text-sm text-gray-600 mb-3">Información del Pago</h6>
                        
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <p class="text-sm text-gray-500">Fecha Pago</p>
                                <p class="font-medium"><?php echo date('d/m/Y H:i:s', strtotime($pago['fecha_pago'])); ?></p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Monto</p>
                                <p class="text-2xl font-bold text-green-600">$<?php echo number_format($pago['monto'], 0, ',', '.'); ?></p>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <p class="text-sm text-gray-500">Método de Pago</p>
                                <div class="flex items-center mt-1">
                                    <?php 
                                    $metodo_class = 'otros';
                                    $metodo_icon = 'fa-wallet';
                                    switch(strtolower($pago['metodo_pago'])) {
                                        case 'efectivo':
                                            $metodo_class = 'efectivo';
                                            $metodo_icon = 'fa-money-bill-wave';
                                            break;
                                        case 'tarjeta':
                                            $metodo_class = 'tarjeta';
                                            $metodo_icon = 'fa-credit-card';
                                            break;
                                        case 'transferencia':
                                        case 'consignacion':
                                            $metodo_class = 'transferencia';
                                            $metodo_icon = 'fa-university';
                                            break;
                                        case 'cheque':
                                            $metodo_class = 'cheque';
                                            $metodo_icon = 'fa-file-invoice-dollar';
                                            break;
                                        case 'nequi':
                                        case 'daviplata':
                                            $metodo_class = 'nequi';
                                            $metodo_icon = 'fa-mobile-alt';
                                            break;
                                    }
                                    ?>
                                    <div class="metodo-icon metodo-<?php echo $metodo_class; ?>">
                                        <i class="fas <?php echo $metodo_icon; ?>"></i>
                                    </div>
                                    <span class="font-medium capitalize"><?php echo $pago['metodo_pago']; ?></span>
                                </div>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Tipo de Pago</p>
                                <?php 
                                $tipo_class = $pago['tipo_pago'] == 'abono_inicial' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800';
                                $tipo_text = $pago['tipo_pago'] == 'abono_inicial' ? 'Abono Inicial' : 'Pago a Deuda';
                                ?>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $tipo_class; ?> mt-1">
                                    <?php echo $tipo_text; ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if (!empty($pago['referencia'])): ?>
                        <div class="mb-4">
                            <p class="text-sm text-gray-500 mb-1">Referencia</p>
                            <div class="bg-gray-50 p-3 rounded-lg">
                                <?php echo htmlspecialchars($pago['referencia']); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-4">
                            <p class="text-sm text-gray-500 mb-1">Registrado por</p>
                            <p class="font-medium"><?php echo htmlspecialchars($pago['usuario_nombre'] ?? 'Sistema'); ?></p>
                        </div>
                        
                        <?php if (!empty($pago['observaciones'])): ?>
                        <div>
                            <p class="text-sm text-gray-500 mb-1">Observaciones</p>
                            <div class="bg-gray-50 p-3 rounded-lg">
                                <?php echo nl2br(htmlspecialchars($pago['observaciones'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Acciones -->
                    <div class="flex justify-end space-x-3 mt-6 pt-4 border-t border-gray-200">
                        <a href="ver_pago_ticket.php?cuenta_id=<?php echo $cuenta_id; ?>&pago_id=<?php echo $pago_id; ?>" 
                           target="_blank"
                           class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-print mr-2"></i>
                            Imprimir Comprobante
                        </a>
                        <?php if ($_SESSION['usuario_rol'] == 'admin'): ?>
                        <a href="editar_pago.php?id=<?php echo $pago_id; ?>" 
                           class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-edit mr-2"></i>
                            Editar Pago
                        </a>
                        <?php endif; ?>
                    </div>
                    
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-receipt text-gray-300 text-5xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No se encontró información del pago</h3>
                        <p class="text-gray-500">Mostrando información general de la cuenta</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Historial de Pagos -->
    <div class="mt-6 bg-white rounded-lg shadow overflow-hidden">
        <div class="bg-purple-600 px-6 py-4">
            <h2 class="text-xl font-bold text-white">
                <i class="fas fa-history mr-2"></i>
                Historial de Pagos
            </h2>
        </div>
        <div class="p-6">
            <?php if (!empty($pagos_historial)): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Monto</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Método</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tipo</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Referencia</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Usuario</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($pagos_historial as $index => $historial): ?>
                            <tr class="hover:bg-gray-50 <?php echo $historial['id'] == $pago_id ? 'bg-blue-50' : ''; ?>">
                                <td class="px-4 py-3 whitespace-nowrap"><?php echo $index + 1; ?></td>
                                <td class="px-4 py-3 whitespace-nowrap"><?php echo date('d/m/Y H:i', strtotime($historial['fecha_pago'])); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap font-bold text-green-600">$<?php echo number_format($historial['monto'], 0, ',', '.'); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800 capitalize">
                                        <?php echo $historial['metodo_pago']; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <?php 
                                    $tipo_badge = $historial['tipo_pago'] == 'abono_inicial' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800';
                                    $tipo_text = $historial['tipo_pago'] == 'abono_inicial' ? 'Abono Inicial' : 'Pago a Deuda';
                                    ?>
                                    <span class="px-2 py-1 text-xs rounded-full <?php echo $tipo_badge; ?>">
                                        <?php echo $tipo_text; ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap"><?php echo htmlspecialchars($historial['referencia'] ?? '-'); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap"><?php echo htmlspecialchars($historial['usuario_nombre'] ?? 'Sistema'); ?></td>
                                <td class="px-4 py-3 whitespace-nowrap space-x-2">
                                    <a href="ver_pago.php?cuenta_id=<?php echo $cuenta_id; ?>&pago_id=<?php echo $historial['id']; ?>" 
                                       class="text-blue-600 hover:text-blue-900 inline-flex items-center" title="Ver detalles">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="ver_pago_ticket.php?cuenta_id=<?php echo $cuenta_id; ?>&pago_id=<?php echo $historial['id']; ?>" 
                                       target="_blank"
                                       class="text-gray-600 hover:text-gray-900 inline-flex items-center" title="Imprimir comprobante">
                                        <i class="fas fa-print"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="2" class="px-4 py-3 text-right text-sm font-bold text-gray-900">Total Pagado:</td>
                                <td class="px-4 py-3 text-sm font-bold text-green-600">$<?php echo number_format($total_pagado, 0, ',', '.'); ?></td>
                                <td colspan="5"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-history text-gray-300 text-5xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No hay pagos registrados</h3>
                    <p class="text-gray-500 mb-4">Esta cuenta aún no tiene pagos registrados</p>
                    <a href="registrar_pago.php?cuenta_id=<?php echo $cuenta_id; ?>" 
                       class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                        <i class="fas fa-plus-circle mr-2"></i>
                        Registrar Primer Pago
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Resaltar el pago actual en la tabla
    const currentPagoRow = document.querySelector('tr.bg-blue-50');
    if (currentPagoRow) {
        currentPagoRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    // Confirmación para acciones importantes
    document.querySelectorAll('a[href*="eliminar"]').forEach(link => {
        link.addEventListener('click', function(e) {
            if (!confirm('¿Está seguro de eliminar este pago? Esta acción no se puede deshacer.')) {
                e.preventDefault();
            }
        });
    });
});
</script>

<?php 
// ============================================
// INCLUIR FOOTER
// ============================================
include __DIR__ . '/../../includes/footer.php'; 
?>