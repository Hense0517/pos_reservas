<?php
/**
 * ============================================
 * ARCHIVO: editar_pago.php
 * UBICACIÓN: /modules/cuentas_por_cobrar/editar_pago.php
 * FECHA CORRECCIÓN: 2026-02-17
 * 
 * PROPÓSITO:
 * Editar un pago existente y recalcular saldos y estados
 * 
 * FUNCIONALIDAD:
 * - Editar monto, método de pago, referencia y observaciones
 * - Recalcular saldo pendiente de la cuenta
 * - Actualizar estado de la cuenta (pagada/parcial/pendiente)
 * - Actualizar estado de la venta asociada
 * ============================================
 */

// Forzar zona horaria Colombia
date_default_timezone_set('America/Bogota');

session_start();

// ============================================
// FUNCIÓN PARA LIMPIAR FORMATO DE MONEDA COLOMBIANA
// ============================================
function limpiarMontoColombiano($monto_formateado) {
    if (empty($monto_formateado)) return 0;
    
    // Remover el símbolo $ si existe
    $limpio = str_replace('$', '', $monto_formateado);
    
    // Remover espacios
    $limpio = trim($limpio);
    
    // Para moneda colombiana, los puntos son separadores de miles
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

// Verificar permisos (solo admin puede editar pagos)
if ($_SESSION['usuario_rol'] != 'admin') {
    $_SESSION['error'] = "No tienes permisos para editar pagos";
    header("Location: index.php");
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

// Obtener información del pago a editar
try {
    $sql_pago = "SELECT p.*, 
                        cp.venta_id, cp.cliente_id, cp.total_deuda, cp.saldo_pendiente as saldo_anterior_cuenta,
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
    
    // Obtener todos los pagos de esta cuenta para recalcular
    $sql_otros_pagos = "SELECT * FROM pagos_cuentas_por_cobrar 
                        WHERE cuenta_id = ? AND id != ?
                        ORDER BY fecha_pago ASC";
    $stmt_otros = $db->prepare($sql_otros_pagos);
    $stmt_otros->execute([$cuenta_id, $pago_id]);
    $otros_pagos = $stmt_otros->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $_SESSION['error'] = "Error al cargar información: " . $e->getMessage();
    header("Location: historial_pagos.php");
    exit();
}

// ============================================
// PROCESAR ACTUALIZACIÓN
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $nuevo_monto = limpiarMontoColombiano($_POST['monto']);
        $nuevo_metodo = $_POST['metodo_pago'];
        $nueva_referencia = trim($_POST['referencia'] ?? '');
        $nuevas_observaciones = trim($_POST['observaciones'] ?? '');
        
        // Validaciones
        if ($nuevo_monto <= 0) {
            throw new Exception("El monto debe ser mayor a cero");
        }
        
        if ($nuevo_monto > $pago['total_deuda']) {
            throw new Exception("El monto no puede exceder el total de la deuda");
        }
        
        $db->beginTransaction();
        
        // ============================================
        // PASO 1: ACTUALIZAR EL PAGO
        // ============================================
        $sql_update_pago = "UPDATE pagos_cuentas_por_cobrar 
                           SET monto = ?, metodo_pago = ?, referencia = ?, 
                               observaciones = ?, updated_at = NOW()
                           WHERE id = ?";
        $stmt_update = $db->prepare($sql_update_pago);
        $stmt_update->execute([
            $nuevo_monto,
            $nuevo_metodo,
            $nueva_referencia,
            $nuevas_observaciones,
            $pago_id
        ]);
        
        // ============================================
        // PASO 2: RECALCULAR SALDOS DE LA CUENTA
        // ============================================
        
        // Recalcular total pagado (incluyendo el nuevo monto)
        $total_pagado = $nuevo_monto;
        foreach ($otros_pagos as $otro) {
            $total_pagado += $otro['monto'];
        }
        
        $nuevo_saldo = $pago['total_deuda'] - $total_pagado;
        
        // Determinar nuevo estado de la cuenta
        if ($nuevo_saldo <= 0) {
            $nuevo_estado_cuenta = 'pagada';
            $nuevo_saldo = 0; // Asegurar que no sea negativo
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
                            SET estado = ?, updated_at = NOW()
                            WHERE id = ?";
        $stmt_update_venta = $db->prepare($sql_update_venta);
        $stmt_update_venta->execute([$nuevo_estado_venta, $venta_id]);
        
        $db->commit();
        
        $_SESSION['success'] = "✅ Pago actualizado correctamente. Nuevo estado de la cuenta: " . ucfirst($nuevo_estado_cuenta);
        header("Location: historial_pagos.php?cuenta_id=" . $cuenta_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "❌ Error al actualizar: " . $e->getMessage();
        header("Location: editar_pago.php?id=" . $pago_id);
        exit();
    }
}

// ============================================
// INCLUIR HEADER
// ============================================
include __DIR__ . '/../../includes/header.php';
?>

<style>
.metodo-pago-option {
    transition: all 0.3s ease;
    cursor: pointer;
    border-width: 2px;
}

.metodo-pago-option:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.metodo-pago-option.selected-efectivo {
    border-color: #10b981;
    background-color: #f0fdf4;
}

.metodo-pago-option.selected-tarjeta {
    border-color: #3b82f6;
    background-color: #eff6ff;
}

.metodo-pago-option.selected-transferencia {
    border-color: #8b5cf6;
    background-color: #f5f3ff;
}

.metodo-pago-option.selected-cheque {
    border-color: #f59e0b;
    background-color: #fffbeb;
}

.metodo-pago-option.selected-consignacion {
    border-color: #06b6d4;
    background-color: #cffafe;
}

.metodo-pago-option.selected-nequi {
    border-color: #ec4899;
    background-color: #fce7f3;
}

.metodo-pago-option.selected-daviplata {
    border-color: #f97316;
    background-color: #ffedd5;
}

.moneda-input {
    font-family: 'Courier New', monospace;
    font-weight: 600;
    text-align: right;
}

.card-resumen {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}
</style>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-edit text-blue-600 mr-2"></i>
                Editar Pago
            </h1>
            <p class="text-gray-600 mt-1">Modificar información del pago y recalcular saldos</p>
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

    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4 flex items-center">
            <i class="fas fa-check-circle mr-3 text-lg"></i>
            <span><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
        </div>
    <?php endif; ?>

    <!-- Alerta importante -->
    <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">
        <div class="flex items-start">
            <i class="fas fa-exclamation-triangle text-yellow-600 mt-1 mr-3"></i>
            <div>
                <p class="text-sm text-yellow-700 font-medium">
                    Al editar este pago, se recalculará automáticamente:
                </p>
                <ul class="text-sm text-yellow-600 list-disc list-inside mt-1">
                    <li>El saldo pendiente de la cuenta</li>
                    <li>El estado de la cuenta (pagada/parcial/pendiente)</li>
                    <li>El estado de la venta asociada</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Información del Pago Actual -->
        <div class="md:col-span-1">
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="bg-blue-600 px-4 py-3">
                    <h3 class="text-lg font-semibold text-white">
                        <i class="fas fa-info-circle mr-2"></i>
                        Información Actual
                    </h3>
                </div>
                <div class="p-4">
                    <dl class="space-y-3">
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Cliente:</dt>
                            <dd class="font-medium text-gray-900"><?php echo htmlspecialchars($pago['cliente_nombre']); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Factura:</dt>
                            <dd class="font-medium text-blue-600"><?php echo htmlspecialchars($pago['numero_factura']); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Fecha Pago:</dt>
                            <dd class="font-medium text-gray-900"><?php echo date('d/m/Y H:i', strtotime($pago['fecha_pago'])); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Monto Actual:</dt>
                            <dd class="font-bold text-green-600 text-xl">$ <?php echo number_format($pago['monto'], 0, ',', '.'); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Método Actual:</dt>
                            <dd class="font-medium text-gray-900 capitalize"><?php echo $pago['metodo_pago']; ?></dd>
                        </div>
                        <?php if ($pago['referencia']): ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Referencia:</dt>
                            <dd class="font-medium text-gray-900"><?php echo htmlspecialchars($pago['referencia']); ?></dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- Resumen de la Cuenta -->
            <div class="bg-white rounded-lg shadow overflow-hidden mt-6">
                <div class="bg-purple-600 px-4 py-3">
                    <h3 class="text-lg font-semibold text-white">
                        <i class="fas fa-chart-pie mr-2"></i>
                        Resumen de la Cuenta
                    </h3>
                </div>
                <div class="p-4">
                    <?php
                    $total_pagado_actual = $pago['monto'];
                    foreach ($otros_pagos as $otro) {
                        $total_pagado_actual += $otro['monto'];
                    }
                    $saldo_actual = $pago['total_deuda'] - $total_pagado_actual;
                    ?>
                    <dl class="space-y-3">
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Total Deuda:</dt>
                            <dd class="font-bold text-gray-900">$ <?php echo number_format($pago['total_deuda'], 0, ',', '.'); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Total Pagado:</dt>
                            <dd class="font-bold text-green-600">$ <?php echo number_format($total_pagado_actual, 0, ',', '.'); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600">Saldo Actual:</dt>
                            <dd class="font-bold <?php echo $saldo_actual > 0 ? 'text-red-600' : 'text-green-600'; ?>">
                                $ <?php echo number_format($saldo_actual, 0, ',', '.'); ?>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-gray-600">Estado Cuenta:</dt>
                            <dd>
                                <?php
                                $estado_actual = 'pendiente';
                                if ($saldo_actual <= 0) $estado_actual = 'pagada';
                                elseif ($saldo_actual < $pago['total_deuda']) $estado_actual = 'parcial';
                                
                                $badge_class = [
                                    'pendiente' => 'bg-yellow-100 text-yellow-800',
                                    'parcial' => 'bg-blue-100 text-blue-800',
                                    'pagada' => 'bg-green-100 text-green-800'
                                ][$estado_actual];
                                ?>
                                <span class="px-2 py-1 text-xs rounded-full <?php echo $badge_class; ?>">
                                    <?php echo ucfirst($estado_actual); ?>
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <!-- Barra de progreso -->
                    <?php 
                    $porcentaje_actual = $pago['total_deuda'] > 0 ? ($total_pagado_actual / $pago['total_deuda']) * 100 : 0;
                    ?>
                    <div class="mt-4">
                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                            <span>Progreso actual</span>
                            <span class="font-semibold"><?php echo number_format($porcentaje_actual, 1); ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo $porcentaje_actual; ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Formulario de Edición -->
        <div class="md:col-span-2">
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="bg-green-600 px-6 py-4">
                    <h2 class="text-xl font-bold text-white">
                        <i class="fas fa-credit-card mr-2"></i>
                        Editar Pago
                    </h2>
                </div>
                <div class="p-6">
                    <form method="POST" id="formEditarPago">
                        <input type="hidden" name="pago_id" value="<?php echo $pago_id; ?>">

                        <!-- Monto -->
                        <div class="mb-6">
                            <label for="monto" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-dollar-sign mr-1"></i>
                                Monto del Pago *
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <span class="text-gray-500">$</span>
                                </div>
                                <input type="text" 
                                       id="monto" 
                                       name="monto" 
                                       data-max="<?php echo $pago['total_deuda']; ?>"
                                       value="<?php echo number_format($pago['monto'], 0, ',', '.'); ?>"
                                       required
                                       class="pl-8 w-full p-3 border-2 border-gray-300 rounded-lg focus:border-green-500 focus:ring-2 focus:ring-green-200 outline-none transition-all text-lg moneda-input"
                                       placeholder="0">
                            </div>
                            <div id="errorMonto" class="error-message hidden mt-2 text-red-600 text-sm"></div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" onclick="setMonto(25)" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1 rounded text-sm">25% de la deuda</button>
                                <button type="button" onclick="setMonto(50)" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1 rounded text-sm">50% de la deuda</button>
                                <button type="button" onclick="setMonto(75)" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1 rounded text-sm">75% de la deuda</button>
                                <button type="button" onclick="setMonto(100)" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1 rounded text-sm">100% de la deuda</button>
                            </div>
                        </div>

                        <!-- Método de Pago -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-wallet mr-1"></i>
                                Método de Pago *
                            </label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <label class="metodo-pago-option flex flex-col items-center justify-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-green-500 transition-all <?php echo $pago['metodo_pago'] == 'efectivo' ? 'selected-efectivo' : ''; ?>">
                                    <input type="radio" name="metodo_pago" value="efectivo" class="hidden" <?php echo $pago['metodo_pago'] == 'efectivo' ? 'checked' : ''; ?>>
                                    <i class="fas fa-money-bill-wave text-green-500 text-2xl mb-2"></i>
                                    <span class="text-xs font-medium">Efectivo</span>
                                </label>

                                <label class="metodo-pago-option flex flex-col items-center justify-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-blue-500 transition-all <?php echo $pago['metodo_pago'] == 'tarjeta' ? 'selected-tarjeta' : ''; ?>">
                                    <input type="radio" name="metodo_pago" value="tarjeta" class="hidden" <?php echo $pago['metodo_pago'] == 'tarjeta' ? 'checked' : ''; ?>>
                                    <i class="fas fa-credit-card text-blue-500 text-2xl mb-2"></i>
                                    <span class="text-xs font-medium">Tarjeta</span>
                                </label>

                                <label class="metodo-pago-option flex flex-col items-center justify-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-purple-500 transition-all <?php echo $pago['metodo_pago'] == 'transferencia' ? 'selected-transferencia' : ''; ?>">
                                    <input type="radio" name="metodo_pago" value="transferencia" class="hidden" <?php echo $pago['metodo_pago'] == 'transferencia' ? 'checked' : ''; ?>>
                                    <i class="fas fa-university text-purple-500 text-2xl mb-2"></i>
                                    <span class="text-xs font-medium">Transferencia</span>
                                </label>

                                <label class="metodo-pago-option flex flex-col items-center justify-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-orange-500 transition-all <?php echo $pago['metodo_pago'] == 'cheque' ? 'selected-cheque' : ''; ?>">
                                    <input type="radio" name="metodo_pago" value="cheque" class="hidden" <?php echo $pago['metodo_pago'] == 'cheque' ? 'checked' : ''; ?>>
                                    <i class="fas fa-file-invoice text-orange-500 text-2xl mb-2"></i>
                                    <span class="text-xs font-medium">Cheque</span>
                                </label>

                                <label class="metodo-pago-option flex flex-col items-center justify-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-cyan-500 transition-all <?php echo $pago['metodo_pago'] == 'consignacion' ? 'selected-consignacion' : ''; ?>">
                                    <input type="radio" name="metodo_pago" value="consignacion" class="hidden" <?php echo $pago['metodo_pago'] == 'consignacion' ? 'checked' : ''; ?>>
                                    <i class="fas fa-landmark text-cyan-500 text-2xl mb-2"></i>
                                    <span class="text-xs font-medium">Consignación</span>
                                </label>

                                <label class="metodo-pago-option flex flex-col items-center justify-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-pink-500 transition-all <?php echo $pago['metodo_pago'] == 'nequi' ? 'selected-nequi' : ''; ?>">
                                    <input type="radio" name="metodo_pago" value="nequi" class="hidden" <?php echo $pago['metodo_pago'] == 'nequi' ? 'checked' : ''; ?>>
                                    <i class="fas fa-mobile-alt text-pink-500 text-2xl mb-2"></i>
                                    <span class="text-xs font-medium">Nequi</span>
                                </label>

                                <label class="metodo-pago-option flex flex-col items-center justify-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-orange-500 transition-all <?php echo $pago['metodo_pago'] == 'daviplata' ? 'selected-daviplata' : ''; ?>">
                                    <input type="radio" name="metodo_pago" value="daviplata" class="hidden" <?php echo $pago['metodo_pago'] == 'daviplata' ? 'checked' : ''; ?>>
                                    <i class="fas fa-wallet text-orange-500 text-2xl mb-2"></i>
                                    <span class="text-xs font-medium">Daviplata</span>
                                </label>
                            </div>
                            <div id="errorMetodoPago" class="error-message hidden mt-2 text-red-600 text-sm"></div>
                        </div>

                        <!-- Referencia -->
                        <div class="mb-6">
                            <label for="referencia" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-hashtag mr-1"></i>
                                Referencia (Opcional)
                            </label>
                            <input type="text" 
                                   id="referencia" 
                                   name="referencia" 
                                   value="<?php echo htmlspecialchars($pago['referencia']); ?>"
                                   class="w-full p-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all"
                                   placeholder="Número de transacción, cheque, etc.">
                        </div>

                        <!-- Observaciones -->
                        <div class="mb-6">
                            <label for="observaciones" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-comment mr-1"></i>
                                Observaciones
                            </label>
                            <textarea id="observaciones" 
                                      name="observaciones" 
                                      rows="3"
                                      class="w-full p-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all"
                                      placeholder="Detalles adicionales del pago..."><?php echo htmlspecialchars($pago['observaciones']); ?></textarea>
                        </div>

                        <!-- Botones -->
                        <div class="flex justify-end space-x-4">
                            <a href="historial_pagos.php?cuenta_id=<?php echo $cuenta_id; ?>" 
                               class="px-6 py-3 border-2 border-gray-300 rounded-lg text-gray-700 font-medium hover:bg-gray-50 transition-colors">
                                Cancelar
                            </a>
                            <button type="submit" 
                                    id="btnGuardar"
                                    class="px-6 py-3 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition-colors flex items-center">
                                <i class="fas fa-save mr-2"></i>
                                Guardar Cambios
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Función para formatear número a moneda colombiana
function formatearMonedaColombiana(numero) {
    return new Intl.NumberFormat('es-CO', {
        style: 'decimal',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
        useGrouping: true
    }).format(numero);
}

// Función para limpiar formato y obtener número
function limpiarFormatoMoneda(valor) {
    if (!valor || valor === '') return 0;
    let limpio = valor.toString().replace(/\./g, '');
    return parseInt(limpio, 10) || 0;
}

// Inicializar
document.addEventListener('DOMContentLoaded', function() {
    const montoInput = document.getElementById('monto');
    if (!montoInput) return;
    
    const maxMonto = parseFloat(montoInput.dataset.max) || 0;
    
    // Función para validar monto
    function validarMonto(valor) {
        const errorElement = document.getElementById('errorMonto');
        if (valor > maxMonto) {
            montoInput.classList.add('border-red-500');
            montoInput.classList.remove('border-green-500');
            if (errorElement) {
                errorElement.classList.remove('hidden');
                errorElement.textContent = 'El monto no puede exceder $' + formatearMonedaColombiana(maxMonto);
            }
            return false;
        } else if (valor <= 0) {
            montoInput.classList.add('border-red-500');
            montoInput.classList.remove('border-green-500');
            if (errorElement) {
                errorElement.classList.remove('hidden');
                errorElement.textContent = 'El monto debe ser mayor a cero';
            }
            return false;
        } else {
            montoInput.classList.remove('border-red-500');
            montoInput.classList.add('border-green-500');
            if (errorElement) {
                errorElement.classList.add('hidden');
            }
            return true;
        }
    }
    
    // Evento input
    montoInput.addEventListener('input', function(e) {
        let valor = this.value.replace(/[^\d]/g, '');
        if (valor === '') valor = '0';
        
        let numero = parseInt(valor, 10);
        
        if (numero > maxMonto) {
            numero = maxMonto;
        }
        
        this.value = formatearMonedaColombiana(numero);
        validarMonto(numero);
    });
    
    // Evento blur
    montoInput.addEventListener('blur', function() {
        let valor = limpiarFormatoMoneda(this.value);
        if (valor === 0) {
            this.value = '0';
        } else {
            this.value = formatearMonedaColombiana(valor);
        }
        validarMonto(valor);
    });
    
    // Botones de porcentaje
    window.setMonto = function(porcentaje) {
        if (!montoInput) return;
        const monto = Math.round((maxMonto * porcentaje) / 100);
        montoInput.value = formatearMonedaColombiana(monto);
        validarMonto(monto);
    };
    
    // Estilos para métodos de pago
    document.querySelectorAll('.metodo-pago-option').forEach(option => {
        option.addEventListener('click', function() {
            document.querySelectorAll('.metodo-pago-option').forEach(opt => {
                opt.classList.remove(
                    'selected-efectivo', 'selected-tarjeta', 'selected-transferencia', 
                    'selected-cheque', 'selected-consignacion', 'selected-nequi', 'selected-daviplata'
                );
            });
            
            const radio = this.querySelector('input[type="radio"]');
            const metodo = radio.value;
            
            if (metodo === 'efectivo') this.classList.add('selected-efectivo');
            else if (metodo === 'tarjeta') this.classList.add('selected-tarjeta');
            else if (metodo === 'transferencia') this.classList.add('selected-transferencia');
            else if (metodo === 'cheque') this.classList.add('selected-cheque');
            else if (metodo === 'consignacion') this.classList.add('selected-consignacion');
            else if (metodo === 'nequi') this.classList.add('selected-nequi');
            else if (metodo === 'daviplata') this.classList.add('selected-daviplata');
            
            radio.checked = true;
        });
    });
    
    // Validación del formulario
    const form = document.getElementById('formEditarPago');
    if (form) {
        form.addEventListener('submit', function(e) {
            const monto = limpiarFormatoMoneda(montoInput.value);
            
            if (!validarMonto(monto)) {
                e.preventDefault();
                montoInput.focus();
                montoInput.classList.add('error-shake');
                setTimeout(() => montoInput.classList.remove('error-shake'), 600);
                return false;
            }
            
            const metodoSeleccionado = document.querySelector('input[name="metodo_pago"]:checked');
            if (!metodoSeleccionado) {
                e.preventDefault();
                const errorElement = document.getElementById('errorMetodoPago');
                errorElement.classList.remove('hidden');
                errorElement.textContent = 'Debe seleccionar un método de pago';
                return false;
            }
            
            if (!confirm('¿Está seguro de actualizar este pago?\n\nEsto recalculará los saldos y estados automáticamente.')) {
                e.preventDefault();
                return false;
            }
            
            const btn = document.getElementById('btnGuardar');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Guardando...';
            
            return true;
        });
    }
});
</script>

<style>
.error-shake {
    animation: shake 0.6s ease-in-out;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
    20%, 40%, 60%, 80% { transform: translateX(5px); }
}
</style>

<?php 
// ============================================
// INCLUIR FOOTER
// ============================================
include __DIR__ . '/../../includes/footer.php'; 
?>