<?php
/**
 * ============================================
 * ARCHIVO: ver.php
 * UBICACIÓN: /modules/cuentas_por_cobrar/ver.php
 * FECHA CORRECCIÓN: 2026-02-17
 * 
 * PROPÓSITO:
 * Mostrar detalles completos de una cuenta por cobrar
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
    $_SESSION['error'] = "No tienes permisos para acceder a esta sección";
    header("Location: " . BASE_URL . "index.php");
    exit();
}

$cuenta_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($cuenta_id === 0) {
    $_SESSION['error'] = "Cuenta no especificada";
    header("Location: index.php");
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// Obtener información detallada de la cuenta
try {
    $sql = "SELECT cp.*, 
                   c.nombre as cliente_nombre, 
                   c.telefono, 
                   c.email,
                   c.numero_documento,
                   c.direccion,
                   v.numero_factura, 
                   v.total as total_venta,
                   v.fecha as fecha_venta,
                   v.metodo_pago as metodo_pago_venta,
                   v.observaciones as observaciones_venta,
                   u.nombre as vendedor_nombre
            FROM cuentas_por_cobrar cp
            LEFT JOIN clientes c ON cp.cliente_id = c.id
            LEFT JOIN ventas v ON cp.venta_id = v.id
            LEFT JOIN usuarios u ON v.usuario_id = u.id
            WHERE cp.id = ?";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$cuenta_id]);
    $cuenta = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cuenta) {
        $_SESSION['error'] = "Cuenta no encontrada";
        header("Location: index.php");
        exit();
    }
    
    // Calcular días restantes/hábiles para vencimiento
    $dias_restantes = null;
    $estado_real = $cuenta['estado'];
    
    if ($cuenta['fecha_limite']) {
        try {
            $fecha_limite = new DateTime($cuenta['fecha_limite']);
            $hoy = new DateTime();
            $diferencia = $hoy->diff($fecha_limite);
            $dias_restantes = $diferencia->days;
            if ($diferencia->invert) {
                $dias_restantes = -$dias_restantes;
            }
            
            // Si está vencida y no pagada, actualizar estado
            if ($estado_real != 'pagada' && $dias_restantes < 0) {
                $estado_real = 'vencida';
            }
        } catch (Exception $e) {
            $dias_restantes = null;
        }
    }
    
    // Obtener historial de pagos si la tabla existe
    $historial_pagos = [];
    try {
        $check_table = $db->query("SHOW TABLES LIKE 'pagos_cuentas_por_cobrar'");
        if ($check_table->fetch()) {
            $sql_pagos = "SELECT p.*, u.nombre as usuario_nombre
                         FROM pagos_cuentas_por_cobrar p
                         LEFT JOIN usuarios u ON p.usuario_id = u.id
                         WHERE p.cuenta_id = ?
                         ORDER BY p.fecha_pago DESC";
            
            $stmt_pagos = $db->prepare($sql_pagos);
            $stmt_pagos->execute([$cuenta_id]);
            $historial_pagos = $stmt_pagos->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Tabla no existe, no es crítico
    }
    
    // Calcular total pagado
    $total_pagado = 0;
    foreach ($historial_pagos as $pago) {
        $total_pagado += floatval($pago['monto']);
    }
    
    $porcentaje_pagado = $cuenta['total_deuda'] > 0 ? ($total_pagado / $cuenta['total_deuda']) * 100 : 0;
    
} catch (Exception $e) {
    $_SESSION['error'] = "Error al cargar información: " . $e->getMessage();
    header("Location: index.php");
    exit();
}

// Procesar eliminación
if (isset($_POST['eliminar']) && $_SESSION['usuario_rol'] == 'admin') {
    try {
        $db->beginTransaction();
        
        // Verificar si hay pagos registrados
        if (count($historial_pagos) > 0) {
            throw new Exception("No se puede eliminar una cuenta con pagos registrados");
        }
        
        // Verificar que el saldo sea igual al total (no hay pagos)
        if ($cuenta['saldo_pendiente'] != $cuenta['total_deuda']) {
            throw new Exception("No se puede eliminar una cuenta con pagos aplicados");
        }
        
        // Eliminar cuenta
        $sql_delete = "DELETE FROM cuentas_por_cobrar WHERE id = ?";
        $stmt_delete = $db->prepare($sql_delete);
        $stmt_delete->execute([$cuenta_id]);
        
        if ($stmt_delete->rowCount() === 0) {
            throw new Exception("No se pudo eliminar la cuenta");
        }
        
        $db->commit();
        
        $_SESSION['success'] = "✅ Cuenta eliminada exitosamente";
        header("Location: index.php");
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "❌ Error al eliminar: " . $e->getMessage();
        header("Location: ver.php?id=" . $cuenta_id);
        exit();
    }
}

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
.info-box {
    background: #f8fafc;
    border-left: 4px solid #3b82f6;
    padding: 1rem;
    margin-bottom: 1rem;
    border-radius: 0 6px 6px 0;
}
.info-box.success {
    border-left-color: #10b981;
}
.info-box.warning {
    border-left-color: #f59e0b;
}
.info-box.danger {
    border-left-color: #ef4444;
}
.progress {
    height: 10px;
    background-color: #e2e8f0;
}
.progress-bar {
    background-color: #10b981;
}
.btn-action {
    min-width: 100px;
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
    margin-bottom: 1.5rem;
}
.timeline-item::before {
    content: '';
    position: absolute;
    left: -30px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #3b82f6;
    border: 2px solid white;
    box-shadow: 0 0 0 2px #3b82f6;
}
.timeline-item.pago::before {
    background: #10b981;
    box-shadow: 0 0 0 2px #10b981;
}
.timeline-item.creacion::before {
    background: #8b5cf6;
    box-shadow: 0 0 0 2px #8b5cf6;
}
</style>

<div class="max-w-7xl mx-auto p-6">
    <!-- Encabezado con acciones -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-receipt text-blue-600 mr-2"></i>
                Detalles de Cuenta
            </h1>
            <p class="text-gray-600 mt-1">ID: #<?php echo $cuenta['id']; ?> | Factura: <?php echo htmlspecialchars($cuenta['numero_factura']); ?></p>
        </div>
        <div class="flex space-x-3">
            <a href="registrar_pago.php?cuenta_id=<?php echo $cuenta['id']; ?>" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-cash-register mr-2"></i>
                Registrar Pago
            </a>
            <?php if ($_SESSION['usuario_rol'] == 'admin'): ?>
            <a href="editar.php?id=<?php echo $cuenta['id']; ?>" 
               class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-edit mr-2"></i>
                Editar
            </a>
            <?php endif; ?>
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver
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
    
    <!-- Resumen rápido -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-lg shadow p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-white text-sm opacity-90">Total Deuda</p>
                    <p class="text-white text-2xl font-bold"><?php echo formatMoney($cuenta['total_deuda']); ?></p>
                </div>
                <i class="fas fa-cash-stack text-white text-3xl opacity-50"></i>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-red-600 to-red-800 rounded-lg shadow p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-white text-sm opacity-90">Saldo Pendiente</p>
                    <p class="text-white text-2xl font-bold"><?php echo formatMoney($cuenta['saldo_pendiente']); ?></p>
                </div>
                <i class="fas fa-clock text-white text-3xl opacity-50"></i>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-green-600 to-green-800 rounded-lg shadow p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-white text-sm opacity-90">Pagado</p>
                    <p class="text-white text-2xl font-bold"><?php echo formatMoney($total_pagado); ?></p>
                    <p class="text-white text-xs"><?php echo number_format($porcentaje_pagado, 1); ?>%</p>
                </div>
                <i class="fas fa-check-circle text-white text-3xl opacity-50"></i>
            </div>
        </div>
        
        <div class="bg-gradient-to-r from-purple-600 to-purple-800 rounded-lg shadow p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-white text-sm opacity-90">Estado</p>
                    <?php
                    $badge_color = [
                        'pendiente' => 'bg-yellow-500',
                        'parcial' => 'bg-blue-500',
                        'pagada' => 'bg-green-500',
                        'vencida' => 'bg-red-500'
                    ][$estado_real] ?? 'bg-gray-500';
                    ?>
                    <span class="inline-block px-3 py-1 rounded-full text-white text-sm font-semibold <?php echo $badge_color; ?>">
                        <?php echo ucfirst($estado_real); ?>
                    </span>
                </div>
                <i class="fas fa-info-circle text-white text-3xl opacity-50"></i>
            </div>
        </div>
    </div>
    
    <!-- Barra de progreso -->
    <div class="bg-white rounded-lg shadow p-4 mb-6">
        <h3 class="text-lg font-semibold text-gray-800 mb-3">Progreso de Pago</h3>
        <div class="w-full bg-gray-200 rounded-full h-4">
            <div class="bg-green-600 h-4 rounded-full" style="width: <?php echo $porcentaje_pagado; ?>%"></div>
        </div>
        <div class="flex justify-between mt-2">
            <span class="text-sm text-gray-600">$0</span>
            <span class="text-sm text-gray-600"><?php echo formatMoney($cuenta['total_deuda']); ?></span>
        </div>
        <div class="text-center mt-2">
            <span class="font-semibold"><?php echo number_format($porcentaje_pagado, 1); ?>% completado</span>
        </div>
    </div>
    
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Columna izquierda: Información principal -->
        <div class="space-y-6">
            <!-- Información de la cuenta -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="bg-blue-600 px-6 py-4">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-info-circle mr-2"></i>
                        Información de la Cuenta
                    </h2>
                </div>
                <div class="p-6">
                    <dl class="space-y-3">
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">ID Cuenta:</dt>
                            <dd class="font-bold text-gray-900">#<?php echo $cuenta['id']; ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Factura:</dt>
                            <dd class="font-bold text-blue-600"><?php echo htmlspecialchars($cuenta['numero_factura']); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Fecha Venta:</dt>
                            <dd class="text-gray-900"><?php echo date('d/m/Y H:i', strtotime($cuenta['fecha_venta'])); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Fecha Creación:</dt>
                            <dd class="text-gray-900"><?php echo date('d/m/Y H:i', strtotime($cuenta['created_at'])); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Última Actualización:</dt>
                            <dd class="text-gray-900"><?php echo date('d/m/Y H:i', strtotime($cuenta['updated_at'])); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Fecha Límite:</dt>
                            <dd>
                                <?php if ($cuenta['fecha_limite']): ?>
                                    <span class="font-medium"><?php echo date('d/m/Y', strtotime($cuenta['fecha_limite'])); ?></span>
                                    <?php if ($dias_restantes !== null): ?>
                                        <br>
                                        <?php if ($dias_restantes < 0): ?>
                                            <span class="inline-block mt-1 px-2 py-1 text-xs rounded-full bg-red-100 text-red-800">
                                                Vencida hace <?php echo abs($dias_restantes); ?> días
                                            </span>
                                        <?php elseif ($dias_restantes == 0): ?>
                                            <span class="inline-block mt-1 px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800">
                                                Vence hoy
                                            </span>
                                        <?php elseif ($dias_restantes <= 3): ?>
                                            <span class="inline-block mt-1 px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800">
                                                Vence en <?php echo $dias_restantes; ?> día<?php echo $dias_restantes != 1 ? 's' : ''; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-block mt-1 px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                                                Vence en <?php echo $dias_restantes; ?> días
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-gray-400">Sin fecha límite</span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Estado:</dt>
                            <dd>
                                <span class="inline-block px-3 py-1 rounded-full text-white text-sm font-semibold <?php echo $badge_color; ?>">
                                    <?php echo ucfirst($estado_real); ?>
                                </span>
                            </dd>
                        </div>
                        <?php if ($cuenta['observaciones']): ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Observaciones:</dt>
                            <dd class="text-gray-900"><?php echo nl2br(htmlspecialchars($cuenta['observaciones'])); ?></dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
            
            <!-- Información del cliente -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="bg-purple-600 px-6 py-4">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-user-circle mr-2"></i>
                        Información del Cliente
                    </h2>
                </div>
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($cuenta['cliente_nombre']); ?></h3>
                        <?php if ($cuenta['cliente_id']): ?>
                        <a href="../clientes/ver.php?id=<?php echo $cuenta['cliente_id']; ?>" 
                           class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded-lg text-sm flex items-center">
                            <i class="fas fa-external-link-alt mr-1"></i>
                            Ver Cliente
                        </a>
                        <?php endif; ?>
                    </div>
                    
                    <dl class="space-y-2">
                        <?php if ($cuenta['numero_documento']): ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Documento:</dt>
                            <dd class="font-medium"><?php echo htmlspecialchars($cuenta['numero_documento']); ?></dd>
                        </div>
                        <?php endif; ?>
                        <?php if ($cuenta['telefono']): ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Teléfono:</dt>
                            <dd class="font-medium"><?php echo htmlspecialchars($cuenta['telefono']); ?></dd>
                        </div>
                        <?php endif; ?>
                        <?php if ($cuenta['email']): ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Email:</dt>
                            <dd class="font-medium"><?php echo htmlspecialchars($cuenta['email']); ?></dd>
                        </div>
                        <?php endif; ?>
                        <?php if ($cuenta['direccion']): ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Dirección:</dt>
                            <dd class="font-medium"><?php echo htmlspecialchars($cuenta['direccion']); ?></dd>
                        </div>
                        <?php endif; ?>
                        <?php if ($cuenta['vendedor_nombre']): ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Vendedor:</dt>
                            <dd class="font-medium"><?php echo htmlspecialchars($cuenta['vendedor_nombre']); ?></dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>
        
        <!-- Columna derecha: Historial y acciones -->
        <div class="space-y-6">
            <!-- Historial de pagos -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="bg-green-600 px-6 py-4 flex justify-between items-center">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-history mr-2"></i>
                        Historial de Pagos
                        <span class="ml-2 bg-white text-green-600 px-2 py-1 rounded-full text-sm">
                            <?php echo count($historial_pagos); ?>
                        </span>
                    </h2>
                    <?php if (count($historial_pagos) > 0): ?>
                    <a href="reporte_pagos.php?cuenta_id=<?php echo $cuenta['id']; ?>" 
                       target="_blank"
                       class="bg-white text-green-600 hover:bg-gray-100 px-3 py-1 rounded-lg text-sm flex items-center">
                        <i class="fas fa-print mr-1"></i>
                        Imprimir
                    </a>
                    <?php endif; ?>
                </div>
                <div class="p-6">
                    <?php if (count($historial_pagos) > 0): ?>
                        <div class="timeline">
                            <!-- Creación de la cuenta -->
                            <div class="timeline-item creacion mb-4">
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h4 class="font-bold text-gray-900">Creación de Cuenta</h4>
                                            <p class="text-sm text-gray-600"><?php echo date('d/m/Y H:i', strtotime($cuenta['created_at'])); ?></p>
                                        </div>
                                        <span class="bg-purple-100 text-purple-800 px-2 py-1 rounded-full text-xs">Inicio</span>
                                    </div>
                                    <p class="text-sm text-gray-700 mt-2">Deuda inicial: <?php echo formatMoney($cuenta['total_deuda']); ?></p>
                                </div>
                            </div>
                            
                            <!-- Pagos registrados -->
                            <?php foreach ($historial_pagos as $pago): ?>
                                <div class="timeline-item pago mb-4">
                                    <div class="bg-white border border-gray-200 rounded-lg p-4 shadow-sm">
                                        <div class="flex justify-between items-start">
                                            <div>
                                                <h4 class="font-bold text-gray-900">Pago Registrado</h4>
                                                <p class="text-sm text-gray-600"><?php echo date('d/m/Y H:i', strtotime($pago['fecha_pago'])); ?></p>
                                            </div>
                                            <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-bold">
                                                <?php echo formatMoney($pago['monto']); ?>
                                            </span>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2 mt-2">
                                            <div>
                                                <p class="text-xs text-gray-500">Registrado por</p>
                                                <p class="text-sm font-medium"><?php echo htmlspecialchars($pago['usuario_nombre'] ?? 'Sistema'); ?></p>
                                            </div>
                                            <div>
                                                <p class="text-xs text-gray-500">Método</p>
                                                <p class="text-sm font-medium capitalize"><?php echo $pago['metodo_pago']; ?></p>
                                            </div>
                                        </div>
                                        <?php if ($pago['referencia']): ?>
                                        <p class="text-xs text-gray-600 mt-2">
                                            <i class="fas fa-hashtag mr-1"></i> Ref: <?php echo htmlspecialchars($pago['referencia']); ?>
                                        </p>
                                        <?php endif; ?>
                                        <?php if ($pago['observaciones']): ?>
                                        <p class="text-xs text-gray-600 mt-1 italic">
                                            "<?php echo htmlspecialchars($pago['observaciones']); ?>"
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <!-- Estado actual -->
                            <div class="timeline-item mb-4">
                                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h4 class="font-bold text-gray-900">Estado Actual</h4>
                                            <p class="text-sm text-gray-600"><?php echo date('d/m/Y H:i'); ?></p>
                                        </div>
                                        <span class="inline-block px-3 py-1 rounded-full text-white text-sm font-semibold <?php echo $badge_color; ?>">
                                            <?php echo ucfirst($estado_real); ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-700 mt-2">
                                        Saldo pendiente: 
                                        <span class="font-bold <?php echo $cuenta['saldo_pendiente'] > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                            <?php echo formatMoney($cuenta['saldo_pendiente']); ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-cash-register text-gray-300 text-5xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">No hay pagos registrados</h3>
                            <p class="text-gray-500 mb-4">Esta cuenta aún no tiene pagos registrados.</p>
                            <a href="registrar_pago.php?cuenta_id=<?php echo $cuenta['id']; ?>" 
                               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                                <i class="fas fa-plus-circle mr-2"></i>
                                Registrar Primer Pago
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Información de la venta original -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="bg-indigo-600 px-6 py-4">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-shopping-cart mr-2"></i>
                        Información de la Venta
                    </h2>
                </div>
                <div class="p-6">
                    <dl class="space-y-3">
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Factura:</dt>
                            <dd>
                                <a href="../ventas/ver.php?id=<?php echo $cuenta['venta_id']; ?>" 
                                   class="text-blue-600 hover:text-blue-800 font-medium">
                                    <?php echo htmlspecialchars($cuenta['numero_factura']); ?>
                                    <i class="fas fa-external-link-alt ml-1 text-xs"></i>
                                </a>
                            </dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Fecha:</dt>
                            <dd class="text-gray-900"><?php echo date('d/m/Y H:i', strtotime($cuenta['fecha_venta'])); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Total Venta:</dt>
                            <dd class="font-bold text-gray-900"><?php echo formatMoney($cuenta['total_venta']); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Método Pago:</dt>
                            <dd class="capitalize"><?php echo ucfirst($cuenta['metodo_pago_venta'] ?? 'No especificado'); ?></dd>
                        </div>
                        <?php if ($cuenta['observaciones_venta']): ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Observaciones:</dt>
                            <dd class="text-gray-900 text-right"><?php echo nl2br(htmlspecialchars($cuenta['observaciones_venta'])); ?></dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Botones de acción -->
    <div class="mt-6 flex justify-center space-x-4">
        <a href="registrar_pago.php?cuenta_id=<?php echo $cuenta['id']; ?>" 
           class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-medium flex items-center">
            <i class="fas fa-cash-register mr-2"></i>
            Registrar Pago
        </a>
        <?php if ($_SESSION['usuario_rol'] == 'admin'): ?>
        <a href="editar.php?id=<?php echo $cuenta['id']; ?>" 
           class="bg-yellow-600 hover:bg-yellow-700 text-white px-6 py-3 rounded-lg font-medium flex items-center">
            <i class="fas fa-edit mr-2"></i>
            Editar Cuenta
        </a>
        <?php endif; ?>
        <?php if ($_SESSION['usuario_rol'] == 'admin' && $cuenta['saldo_pendiente'] == $cuenta['total_deuda'] && count($historial_pagos) == 0): ?>
        <button type="button" 
                onclick="confirmarEliminacion()"
                class="bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg font-medium flex items-center">
            <i class="fas fa-trash-alt mr-2"></i>
            Eliminar Cuenta
        </button>
        <?php endif; ?>
        <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg font-medium flex items-center">
            <i class="fas fa-arrow-left mr-2"></i>
            Volver
        </a>
    </div>
</div>

<!-- Modal de confirmación para eliminar -->
<?php if ($_SESSION['usuario_rol'] == 'admin' && $cuenta['saldo_pendiente'] == $cuenta['total_deuda'] && count($historial_pagos) == 0): ?>
<div id="modalEliminar" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100 mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-900 mb-2">Confirmar Eliminación</h3>
            <div class="mt-2 px-4 py-3 bg-red-50 rounded-lg text-left">
                <p class="text-sm text-red-800"><strong>Cliente:</strong> <?php echo htmlspecialchars($cuenta['cliente_nombre']); ?></p>
                <p class="text-sm text-red-800"><strong>Factura:</strong> <?php echo htmlspecialchars($cuenta['numero_factura']); ?></p>
                <p class="text-sm text-red-800"><strong>Monto:</strong> <?php echo formatMoney($cuenta['total_deuda']); ?></p>
                <p class="text-sm text-red-800"><strong>ID:</strong> #<?php echo $cuenta['id']; ?></p>
            </div>
            <p class="text-xs text-gray-500 mt-3">
                <i class="fas fa-info-circle mr-1"></i>
                Esta acción no se puede deshacer. Solo se pueden eliminar cuentas sin pagos registrados.
            </p>
            <div class="flex justify-center space-x-3 mt-4">
                <button onclick="cerrarModal()" 
                        class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg font-medium">
                    Cancelar
                </button>
                <form method="POST" class="inline">
                    <button type="submit" name="eliminar" 
                            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-medium">
                        <i class="fas fa-trash-alt mr-1"></i>
                        Sí, Eliminar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmarEliminacion() {
    document.getElementById('modalEliminar').classList.remove('hidden');
}

function cerrarModal() {
    document.getElementById('modalEliminar').classList.add('hidden');
}

window.onclick = function(event) {
    const modal = document.getElementById('modalEliminar');
    if (event.target === modal) {
        cerrarModal();
    }
}
</script>
<?php endif; ?>

<?php 
// ============================================
// INCLUIR FOOTER
// ============================================
include __DIR__ . '/../../includes/footer.php'; 
?>