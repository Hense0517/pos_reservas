<?php
/**
 * ============================================
 * ARCHIVO: eliminar_pago.php
 * UBICACIÓN: /modules/cuentas_por_cobrar/eliminar_pago.php
 * FECHA CORRECCIÓN: 2026-02-17
 * 
 * PROPÓSITO:
 * Eliminar un pago existente y recalcular saldos y estados de la cuenta
 * 
 * FUNCIONALIDAD:
 * - Eliminar lógicamente o físicamente el pago
 * - Recalcular saldo pendiente de la cuenta
 * - Actualizar estado de la cuenta (pagada/parcial/pendiente)
 * - Actualizar estado de la venta asociada
 * - Registrar la acción en logs
 * 
 * IMPORTANTE:
 * Cuando se elimina un pago, si la cuenta quedaba pagada,
 * debe volver a estado pendiente o parcial según corresponda.
 * ============================================
 */

// Forzar zona horaria Colombia
date_default_timezone_set('America/Bogota');

session_start();

// ============================================
// FUNCIÓN PARA REGISTRAR EN LOG
// ============================================
function registrarLog($db, $accion, $detalle, $usuario_id, $pago_id = null) {
    try {
        // Verificar si existe la tabla de logs
        $check_table = $db->query("SHOW TABLES LIKE 'logs_acciones'");
        if (!$check_table->fetch()) {
            // Crear tabla si no existe
            $sql_create = "CREATE TABLE IF NOT EXISTS logs_acciones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NOT NULL,
                accion VARCHAR(100) NOT NULL,
                detalle TEXT,
                pago_id INT,
                ip VARCHAR(45),
                user_agent TEXT,
                created_at DATETIME NOT NULL,
                INDEX idx_usuario (usuario_id),
                INDEX idx_pago (pago_id),
                INDEX idx_fecha (created_at)
            )";
            $db->exec($sql_create);
        }
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $sql_log = "INSERT INTO logs_acciones (usuario_id, accion, detalle, pago_id, ip, user_agent, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt_log = $db->prepare($sql_log);
        $stmt_log->execute([$usuario_id, $accion, $detalle, $pago_id, $ip, $user_agent]);
        
    } catch (Exception $e) {
        // Si falla el log, continuar con la operación
        error_log("Error al registrar log: " . $e->getMessage());
    }
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

// Verificar permisos (solo admin puede eliminar pagos)
if ($_SESSION['usuario_rol'] != 'admin') {
    $_SESSION['error'] = "No tienes permisos para eliminar pagos";
    header("Location: historial_pagos.php");
    exit();
}

$pago_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($pago_id <= 0) {
    $_SESSION['error'] = "ID de pago no válido";
    header("Location: historial_pagos.php");
    exit();
}

// ============================================
// CONEXIÓN A BASE DE DATOS
// ============================================
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// ============================================
// OBTENER INFORMACIÓN DEL PAGO A ELIMINAR
// ============================================
try {
    $sql_pago = "SELECT p.*, 
                        cp.venta_id, cp.cliente_id, cp.total_deuda, 
                        cp.saldo_pendiente as saldo_antes_pago,
                        v.numero_factura, v.tipo_venta, v.estado as estado_venta,
                        c.nombre as cliente_nombre
                 FROM pagos_cuentas_por_cobrar p
                 INNER JOIN cuentas_por_cobrar cp ON p.cuenta_id = cp.id
                 INNER JOIN ventas v ON cp.venta_id = v.id
                 INNER JOIN clientes c ON cp.cliente_id = c.id
                 WHERE p.id = ?";
    
    $stmt_pago = $db->prepare($sql_pago);
    $stmt_pago->execute([$pago_id]);
    $pago = $stmt_pago->fetch(PDO::FETCH_ASSOC);
    
    if (!$pago) {
        $_SESSION['error'] = "Pago no encontrado";
        header("Location: historial_pagos.php");
        exit();
    }
    
    $cuenta_id = $pago['cuenta_id'];
    $venta_id = $pago['venta_id'];
    $monto_pago = $pago['monto'];
    
    // Obtener otros pagos de la misma cuenta
    $sql_otros = "SELECT * FROM pagos_cuentas_por_cobrar 
                  WHERE cuenta_id = ? AND id != ?
                  ORDER BY fecha_pago ASC";
    $stmt_otros = $db->prepare($sql_otros);
    $stmt_otros->execute([$cuenta_id, $pago_id]);
    $otros_pagos = $stmt_otros->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $_SESSION['error'] = "Error al cargar información: " . $e->getMessage();
    header("Location: historial_pagos.php");
    exit();
}

// ============================================
// PROCESAR ELIMINACIÓN (via POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $confirmacion = $_POST['confirmacion'] ?? '';
        $motivo = trim($_POST['motivo'] ?? '');
        
        if ($confirmacion !== 'ELIMINAR') {
            throw new Exception("Confirmación incorrecta. Debe escribir 'ELIMINAR' para continuar.");
        }
        
        if (empty($motivo)) {
            throw new Exception("Debe especificar el motivo de la eliminación.");
        }
        
        $db->beginTransaction();
        
        // ============================================
        // PASO 1: ELIMINAR EL PAGO
        // ============================================
        
        // Opción 1: Eliminación física (completa)
        $sql_delete = "DELETE FROM pagos_cuentas_por_cobrar WHERE id = ?";
        $stmt_delete = $db->prepare($sql_delete);
        $stmt_delete->execute([$pago_id]);
        
        // Opción 2: Eliminación lógica (si prefieres mantener historial)
        // $sql_delete = "UPDATE pagos_cuentas_por_cobrar SET activo = 0, deleted_at = NOW(), deleted_by = ? WHERE id = ?";
        // $stmt_delete = $db->prepare($sql_delete);
        // $stmt_delete->execute([$_SESSION['usuario_id'], $pago_id]);
        
        // ============================================
        // PASO 2: RECALCULAR SALDOS DE LA CUENTA
        // ============================================
        
        // Calcular nuevo total pagado (sin el pago eliminado)
        $total_pagado = 0;
        foreach ($otros_pagos as $otro) {
            $total_pagado += $otro['monto'];
        }
        
        $nuevo_saldo = $pago['total_deuda'] - $total_pagado;
        
        // Determinar nuevo estado de la cuenta
        if ($nuevo_saldo <= 0) {
            $nuevo_estado_cuenta = 'pagada';
            $nuevo_saldo = 0;
        } elseif ($nuevo_saldo < $pago['total_deuda']) {
            $nuevo_estado_cuenta = 'parcial';
        } else {
            $nuevo_estado_cuenta = 'pendiente';
        }
        
        // Actualizar cuenta por cobrar
        $sql_update_cuenta = "UPDATE cuentas_por_cobrar 
                             SET saldo_pendiente = ?, estado = ?, updated_at = NOW()
                             WHERE id = ?";
        $stmt_update_cuenta = $db->prepare($sql_update_cuenta);
        $stmt_update_cuenta->execute([$nuevo_saldo, $nuevo_estado_cuenta, $cuenta_id]);
        
        // ============================================
        // PASO 3: ACTUALIZAR ESTADO DE LA VENTA
        // ============================================
        
        // Determinar nuevo estado de la venta según el saldo
        if ($nuevo_saldo <= 0) {
            $nuevo_estado_venta = 'pagada_credito';
        } elseif ($nuevo_saldo < $pago['total_deuda']) {
            $nuevo_estado_venta = 'pendiente_credito';
        } else {
            $nuevo_estado_venta = 'pendiente_credito';
        }
        
        $sql_update_venta = "UPDATE ventas 
                            SET estado = ?, abono_inicial = abono_inicial - ?,
                                updated_at = NOW()
                            WHERE id = ?";
        $stmt_update_venta = $db->prepare($sql_update_venta);
        $stmt_update_venta->execute([$nuevo_estado_venta, $monto_pago, $venta_id]);
        
        // ============================================
        // PASO 4: REGISTRAR EN LOG
        // ============================================
        
        $detalle_log = "Pago #{$pago_id} eliminado. " .
                       "Monto: $" . number_format($monto_pago, 0, ',', '.') . ", " .
                       "Factura: {$pago['numero_factura']}, " .
                       "Cliente: {$pago['cliente_nombre']}, " .
                       "Motivo: {$motivo}";
        
        registrarLog($db, 'ELIMINAR_PAGO', $detalle_log, $_SESSION['usuario_id'], $pago_id);
        
        $db->commit();
        
        $_SESSION['success'] = "✅ Pago eliminado correctamente. " .
                              "Nuevo estado de la cuenta: " . ucfirst($nuevo_estado_cuenta) . ". " .
                              "Nuevo saldo: $" . number_format($nuevo_saldo, 0, ',', '.');
        
        header("Location: historial_pagos.php?cuenta_id=" . $cuenta_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "❌ Error al eliminar: " . $e->getMessage();
        header("Location: eliminar_pago.php?id=" . $pago_id);
        exit();
    }
}

// ============================================
// INCLUIR HEADER
// ============================================
include __DIR__ . '/../../includes/header.php';
?>

<style>
.confirmacion-box {
    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
    border-left: 4px solid #dc2626;
}

.motivo-input:focus {
    border-color: #dc2626;
    box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
}

.btn-eliminar {
    background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
    transition: all 0.3s ease;
}

.btn-eliminar:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
}

.btn-eliminar:active {
    transform: translateY(0);
}

.pago-info {
    background-color: #f8fafc;
    border-left: 4px solid #3b82f6;
}

.alerta-impacto {
    background-color: #fff3cd;
    border-left: 4px solid #ffc107;
}
</style>

<div class="max-w-4xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-trash-alt text-red-600 mr-2"></i>
                Eliminar Pago
            </h1>
            <p class="text-gray-600 mt-1">Esta acción no se puede deshacer</p>
        </div>
        <div class="flex space-x-3">
            <a href="historial_pagos.php?cuenta_id=<?php echo $cuenta_id; ?>" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver al Historial
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 flex items-center">
            <i class="fas fa-exclamation-triangle mr-3 text-lg"></i>
            <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
        </div>
    <?php endif; ?>

    <!-- Alerta de peligro -->
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6">
        <div class="flex items-start">
            <i class="fas fa-exclamation-triangle text-red-600 mt-1 mr-3 text-xl"></i>
            <div>
                <h3 class="text-lg font-bold text-red-800">¡ATENCIÓN! Esta acción es irreversible</h3>
                <p class="text-red-700 mt-1">
                    Al eliminar este pago, se recalcularán automáticamente los saldos y estados de la cuenta.
                    Si la cuenta estaba pagada, volverá a estado pendiente o parcial según corresponda.
                </p>
            </div>
        </div>
    </div>

    <!-- Información del Pago a Eliminar -->
    <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
        <div class="bg-red-600 px-6 py-4">
            <h2 class="text-xl font-bold text-white">
                <i class="fas fa-info-circle mr-2"></i>
                Detalles del Pago a Eliminar
            </h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">Información del Pago</h3>
                    <dl class="space-y-2">
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">ID Pago:</dt>
                            <dd class="font-medium text-gray-900">#<?php echo $pago['id']; ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Monto:</dt>
                            <dd class="font-bold text-red-600 text-xl">$ <?php echo number_format($pago['monto'], 0, ',', '.'); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Método:</dt>
                            <dd class="font-medium text-gray-900 capitalize"><?php echo $pago['metodo_pago']; ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Fecha Pago:</dt>
                            <dd class="font-medium text-gray-900"><?php echo date('d/m/Y H:i', strtotime($pago['fecha_pago'])); ?></dd>
                        </div>
                        <?php if ($pago['referencia']): ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Referencia:</dt>
                            <dd class="font-medium text-gray-900"><?php echo htmlspecialchars($pago['referencia']); ?></dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                </div>
                
                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-3">Información del Cliente</h3>
                    <dl class="space-y-2">
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Cliente:</dt>
                            <dd class="font-medium text-gray-900"><?php echo htmlspecialchars($pago['cliente_nombre']); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Factura:</dt>
                            <dd class="font-medium text-blue-600"><?php echo htmlspecialchars($pago['numero_factura']); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Total Deuda:</dt>
                            <dd class="font-bold text-gray-900">$ <?php echo number_format($pago['total_deuda'], 0, ',', '.'); ?></dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <!-- Impacto de la Eliminación -->
    <div class="bg-yellow-50 rounded-lg p-6 mb-6 alerta-impacto">
        <h3 class="text-lg font-bold text-yellow-800 mb-4 flex items-center">
            <i class="fas fa-chart-line mr-2"></i>
            Impacto de la Eliminación
        </h3>
        
        <?php
        // Calcular cómo quedaría después de eliminar
        $total_pagado_actual = $pago['monto'];
        foreach ($otros_pagos as $otro) {
            $total_pagado_actual += $otro['monto'];
        }
        
        $total_pagado_despues = $total_pagado_actual - $pago['monto'];
        $saldo_despues = $pago['total_deuda'] - $total_pagado_despues;
        
        if ($saldo_despues <= 0) {
            $estado_despues = 'pagada';
            $color_estado = 'text-green-600';
            $badge_despues = 'bg-green-100 text-green-800';
        } elseif ($saldo_despues < $pago['total_deuda']) {
            $estado_despues = 'parcial';
            $color_estado = 'text-blue-600';
            $badge_despues = 'bg-blue-100 text-blue-800';
        } else {
            $estado_despues = 'pendiente';
            $color_estado = 'text-yellow-600';
            $badge_despues = 'bg-yellow-100 text-yellow-800';
        }
        ?>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white p-4 rounded-lg shadow">
                <p class="text-sm text-gray-600 mb-1">Situación Actual</p>
                <p class="text-2xl font-bold text-gray-900">$ <?php echo number_format($total_pagado_actual, 0, ',', '.'); ?></p>
                <p class="text-xs text-gray-500">Total pagado</p>
            </div>
            
            <div class="bg-white p-4 rounded-lg shadow flex items-center justify-center">
                <i class="fas fa-arrow-right text-3xl text-gray-400"></i>
                <i class="fas fa-trash-alt text-3xl text-red-500 mx-4"></i>
                <i class="fas fa-arrow-right text-3xl text-gray-400"></i>
            </div>
            
            <div class="bg-white p-4 rounded-lg shadow">
                <p class="text-sm text-gray-600 mb-1">Después de Eliminar</p>
                <p class="text-2xl font-bold <?php echo $color_estado; ?>">$ <?php echo number_format($total_pagado_despues, 0, ',', '.'); ?></p>
                <p class="text-xs text-gray-500">Nuevo total pagado</p>
            </div>
        </div>
        
        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white p-3 rounded-lg">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Saldo actual:</span>
                    <span class="font-bold <?php echo $pago['saldo_antes_pago'] > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                        $ <?php echo number_format($pago['saldo_antes_pago'], 0, ',', '.'); ?>
                    </span>
                </div>
                <div class="flex justify-between items-center mt-2">
                    <span class="text-gray-600">Saldo después:</span>
                    <span class="font-bold <?php echo $saldo_despues > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                        $ <?php echo number_format($saldo_despues, 0, ',', '.'); ?>
                    </span>
                </div>
            </div>
            
            <div class="bg-white p-3 rounded-lg">
                <div class="flex justify-between items-center">
                    <span class="text-gray-600">Estado actual:</span>
                    <?php
                    $estado_actual = 'pendiente';
                    if ($pago['saldo_antes_pago'] <= 0) $estado_actual = 'pagada';
                    elseif ($pago['saldo_antes_pago'] < $pago['total_deuda']) $estado_actual = 'parcial';
                    
                    $badge_actual = [
                        'pendiente' => 'bg-yellow-100 text-yellow-800',
                        'parcial' => 'bg-blue-100 text-blue-800',
                        'pagada' => 'bg-green-100 text-green-800'
                    ][$estado_actual];
                    ?>
                    <span class="px-2 py-1 text-xs rounded-full <?php echo $badge_actual; ?>">
                        <?php echo ucfirst($estado_actual); ?>
                    </span>
                </div>
                <div class="flex justify-between items-center mt-2">
                    <span class="text-gray-600">Estado después:</span>
                    <span class="px-2 py-1 text-xs rounded-full <?php echo $badge_despues; ?>">
                        <?php echo ucfirst($estado_despues); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulario de Confirmación -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="bg-gray-800 px-6 py-4">
            <h2 class="text-xl font-bold text-white">
                <i class="fas fa-shield-alt mr-2"></i>
                Confirmar Eliminación
            </h2>
        </div>
        <div class="p-6">
            <form method="POST" id="formEliminar" class="space-y-6">
                <!-- Motivo de eliminación -->
                <div>
                    <label for="motivo" class="block text-sm font-medium text-gray-700 mb-2">
                        <i class="fas fa-pen mr-1"></i>
                        Motivo de la eliminación *
                    </label>
                    <textarea id="motivo" 
                              name="motivo" 
                              rows="3"
                              required
                              class="motivo-input w-full p-3 border-2 border-gray-300 rounded-lg focus:border-red-500 focus:ring-2 focus:ring-red-200 outline-none transition-all"
                              placeholder="Explique por qué se elimina este pago (error, duplicado, etc.)"></textarea>
                </div>
                
                <!-- Confirmación escrita -->
                <div class="confirmacion-box p-4 rounded-lg">
                    <label for="confirmacion" class="block text-sm font-medium text-red-800 mb-2">
                        <i class="fas fa-exclamation-circle mr-1"></i>
                        Para confirmar, escriba <strong>"ELIMINAR"</strong> en mayúsculas:
                    </label>
                    <input type="text" 
                           id="confirmacion" 
                           name="confirmacion" 
                           required
                           class="w-full p-3 border-2 border-red-300 rounded-lg focus:border-red-500 focus:ring-2 focus:ring-red-200 outline-none transition-all uppercase"
                           placeholder="ELIMINAR"
                           pattern="ELIMINAR"
                           title="Debe escribir ELIMINAR en mayúsculas">
                </div>
                
                <!-- Checkbox de confirmación adicional -->
                <div class="flex items-start space-x-3">
                    <input type="checkbox" 
                           id="confirmar_impacto" 
                           required
                           class="mt-1 h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                    <label for="confirmar_impacto" class="text-sm text-gray-700">
                        <span class="font-bold">Confirmo que entiendo</span> que al eliminar este pago:
                        <ul class="list-disc list-inside mt-1 text-gray-600">
                            <li>Se recalculará el saldo de la cuenta</li>
                            <li>El estado de la cuenta puede cambiar (de pagada a parcial/pendiente)</li>
                            <li>El estado de la venta también se actualizará</li>
                            <li>Esta acción no se puede deshacer</li>
                        </ul>
                    </label>
                </div>
                
                <!-- Botones -->
                <div class="flex justify-end space-x-4 pt-4 border-t border-gray-200">
                    <a href="historial_pagos.php?cuenta_id=<?php echo $cuenta_id; ?>" 
                       class="px-6 py-3 border-2 border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50 transition-colors">
                        Cancelar
                    </a>
                    <button type="submit" 
                            id="btnEliminar"
                            class="btn-eliminar px-8 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition-all flex items-center">
                        <i class="fas fa-trash-alt mr-2"></i>
                        ELIMINAR PERMANENTEMENTE
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Nota de seguridad -->
    <div class="mt-4 text-sm text-gray-500 text-center">
        <i class="fas fa-shield-alt mr-1"></i>
        Esta acción será registrada en el log del sistema para auditoría.
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formEliminar');
    const btnEliminar = document.getElementById('btnEliminar');
    const confirmacionInput = document.getElementById('confirmacion');
    const motivoInput = document.getElementById('motivo');
    const checkboxImpacto = document.getElementById('confirmar_impacto');
    
    // Validar en tiempo real
    function validarFormulario() {
        const confirmacionValida = confirmacionInput.value === 'ELIMINAR';
        const motivoValido = motivoInput.value.trim().length >= 10;
        const checkboxValido = checkboxImpacto.checked;
        
        if (confirmacionValida) {
            confirmacionInput.classList.remove('border-red-300');
            confirmacionInput.classList.add('border-green-500', 'bg-green-50');
        } else {
            confirmacionInput.classList.remove('border-green-500', 'bg-green-50');
            confirmacionInput.classList.add('border-red-300');
        }
        
        return confirmacionValida && motivoValido && checkboxValido;
    }
    
    confirmacionInput.addEventListener('input', validarFormulario);
    motivoInput.addEventListener('input', validarFormulario);
    checkboxImpacto.addEventListener('change', validarFormulario);
    
    // Confirmación final con SweetAlert
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (!validarFormulario()) {
            alert('Por favor complete todos los campos correctamente');
            return;
        }
        
        // Usar SweetAlert si está disponible
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: '¿Eliminar pago?',
                html: `
                    <div class="text-left">
                        <p class="mb-2"><strong>Monto:</strong> $<?php echo number_format($pago['monto'], 0, ',', '.'); ?></p>
                        <p class="mb-2"><strong>Cliente:</strong> <?php echo addslashes($pago['cliente_nombre']); ?></p>
                        <p class="mb-2"><strong>Factura:</strong> <?php echo $pago['numero_factura']; ?></p>
                        <p class="text-red-600 font-bold mt-3">¡Esta acción no se puede deshacer!</p>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    btnEliminar.disabled = true;
                    btnEliminar.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Eliminando...';
                    form.submit();
                }
            });
        } else {
            if (confirm('¿Está SEGURO de eliminar este pago?\n\nMonto: $<?php echo number_format($pago['monto'], 0, ',', '.'); ?>\nCliente: <?php echo addslashes($pago['cliente_nombre']); ?>\n\n¡Esta acción no se puede deshacer!')) {
                btnEliminar.disabled = true;
                btnEliminar.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Eliminando...';
                form.submit();
            }
        }
    });
});
</script>

<?php 
// ============================================
// INCLUIR FOOTER
// ============================================
include __DIR__ . '/../../includes/footer.php'; 
?>