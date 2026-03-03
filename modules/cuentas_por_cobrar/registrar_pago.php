<?php
/**
 * ============================================
 * ARCHIVO: registrar_pago.php
 * UBICACIÓN: /modules/cuentas_por_cobrar/registrar_pago.php
 * FECHA CORRECCIÓN: 2026-02-17
 * 
 * PROPÓSITO:
 * Registrar pagos parciales o totales a cuentas por cobrar,
 * actualizar saldos pendientes y estados de ventas.
 * 
 * PROBLEMA CORREGIDO:
 * El formateo de moneda estaba malinterpretando 50.000 como 50
 * Solución: Función específica para limpiar formato colombiano
 * 
 * CAMBIOS REALIZADOS:
 * 1. Usa header/footer del sistema (recursos.php)
 * 2. Forza zona horaria Colombia (America/Bogota)
 * 3. Usa estilos consistentes con el sistema (Tailwind)
 * 4. Corrige rutas con __DIR__
 * 5. Usa BASE_URL para redirecciones
 * 6. Función limpiarMontoColombiano() para manejar formato de miles
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
    
    // IMPORTANTE: Para moneda colombiana, los puntos son separadores de miles
    // y la coma es el separador decimal (si existe)
    
    // Si tiene coma decimal, reemplazarla por punto para PHP
    if (strpos($limpio, ',') !== false) {
        $limpio = str_replace('.', '', $limpio); // Quitar puntos de miles
        $limpio = str_replace(',', '.', $limpio); // Reemplazar coma decimal por punto
    } else {
        // Si no tiene coma, solo quitar puntos de miles
        $limpio = str_replace('.', '', $limpio);
    }
    
    // Convertir a número
    $numero = floatval($limpio);
    
    return $numero;
}

// ============================================
// CONFIGURACIÓN INICIAL - RUTA CORREGIDA
// ============================================
require_once __DIR__ . '/../../includes/config.php';

// Verificar si el usuario está logueado
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permisos
$roles_permitidos = ['admin', 'cajero', 'vendedor'];
if (!isset($_SESSION['usuario_rol']) || !in_array($_SESSION['usuario_rol'], $roles_permitidos)) {
    $_SESSION['error'] = "No tienes permisos para realizar esta acción";
    header("Location: index.php");
    exit();
}

$cuenta_id = isset($_GET['cuenta_id']) ? intval($_GET['cuenta_id']) : 0;

// ============================================
// CONEXIÓN A BASE DE DATOS
// ============================================
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// Obtener información de la cuenta
if ($cuenta_id > 0) {
    try {
        $sql = "SELECT cp.*, c.nombre as cliente_nombre, c.telefono, 
                       v.numero_factura, v.total as total_venta,
                       v.id as venta_id, v.fecha as fecha_venta
                FROM cuentas_por_cobrar cp
                LEFT JOIN clientes c ON cp.cliente_id = c.id
                LEFT JOIN ventas v ON cp.venta_id = v.id
                WHERE cp.id = ? AND cp.saldo_pendiente > 0";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$cuenta_id]);
        $cuenta = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cuenta) {
            $_SESSION['error'] = "Cuenta no encontrada o ya pagada";
            header("Location: index.php");
            exit();
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Error al obtener información de la cuenta: " . $e->getMessage();
        header("Location: index.php");
        exit();
    }
}

// Variable para almacenar ID del pago registrado
$pago_registrado_id = null;

// ============================================
// PROCESAMIENTO DEL PAGO (POST)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cuenta_id = intval($_POST['cuenta_id']);
        
        // CORREGIDO: Usar la función limpiarMontoColombiano
        $monto = limpiarMontoColombiano($_POST['monto']);
        
        $metodo_pago = $_POST['metodo_pago'];
        $referencia = isset($_POST['referencia']) ? trim($_POST['referencia']) : '';
        $observaciones = isset($_POST['observaciones']) ? trim($_POST['observaciones']) : '';
        $usuario_id = $_SESSION['usuario_id'];
        $usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Usuario';
        
        // Fecha y hora actual de Colombia
        $fecha_hora_colombia = date('Y-m-d H:i:s');
        
        $db->beginTransaction();
        
        // Verificar que la cuenta exista y tenga saldo pendiente (con bloqueo)
        $sql = "SELECT cp.saldo_pendiente, cp.cliente_id, cp.venta_id, 
                       cp.total_deuda, v.id as venta_id, v.numero_factura
                FROM cuentas_por_cobrar cp
                INNER JOIN ventas v ON cp.venta_id = v.id
                WHERE cp.id = ? AND cp.saldo_pendiente > 0 
                FOR UPDATE";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$cuenta_id]);
        $cuenta_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$cuenta_info) {
            throw new Exception("Cuenta no encontrada o ya pagada");
        }
        
        $venta_id = $cuenta_info['venta_id'];
        $numero_factura = $cuenta_info['numero_factura'];
        
        // Validaciones
        if ($monto <= 0) {
            throw new Exception("El monto debe ser mayor a cero");
        }
        
        if ($monto > $cuenta_info['saldo_pendiente']) {
            throw new Exception("El monto excede el saldo pendiente ($" . number_format($cuenta_info['saldo_pendiente'], 0, ',', '.') . ")");
        }
        
        // Verificar si existe la tabla de pagos
        $table_exists = false;
        try {
            $check_table = "SHOW TABLES LIKE 'pagos_cuentas_por_cobrar'";
            $stmt_check = $db->query($check_table);
            $table_exists = $stmt_check->fetch();
        } catch (Exception $e) {
            $table_exists = false;
        }
        
        // Si no existe la tabla, intentar crearla
        if (!$table_exists) {
            try {
                $create_table = "CREATE TABLE IF NOT EXISTS pagos_cuentas_por_cobrar (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    cuenta_id INT NOT NULL,
                    monto DECIMAL(10,2) NOT NULL,
                    metodo_pago VARCHAR(50) NOT NULL,
                    referencia VARCHAR(100),
                    usuario_id INT NOT NULL,
                    observaciones TEXT,
                    fecha_pago DATETIME NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_cuenta (cuenta_id),
                    INDEX idx_fecha (fecha_pago),
                    FOREIGN KEY (cuenta_id) REFERENCES cuentas_por_cobrar(id) ON DELETE CASCADE
                )";
                $db->exec($create_table);
                $table_exists = true;
            } catch (Exception $e) {
                $table_exists = false;
            }
        }
        
        // Registrar pago en la tabla pagos_cuentas_por_cobrar
        if ($table_exists) {
            $sql_pago = "INSERT INTO pagos_cuentas_por_cobrar (
                cuenta_id, monto, metodo_pago, referencia,
                usuario_id, observaciones, fecha_pago, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $stmt_pago = $db->prepare($sql_pago);
            $stmt_pago->execute([
                $cuenta_id,
                $monto,
                $metodo_pago,
                $referencia,
                $usuario_id,
                $observaciones ?: "Pago registrado por: $usuario_nombre",
                $fecha_hora_colombia
            ]);
            
            // Obtener el ID del pago recién insertado
            $pago_registrado_id = $db->lastInsertId();
        }
        
        // Actualizar cuenta por cobrar
        $nuevo_saldo = $cuenta_info['saldo_pendiente'] - $monto;
        $nuevo_estado = ($nuevo_saldo == 0) ? 'pagada' : 'parcial';
        
        $sql_update_cuenta = "UPDATE cuentas_por_cobrar 
                             SET saldo_pendiente = ?, estado = ?, updated_at = NOW()
                             WHERE id = ?";
        
        $stmt_update_cuenta = $db->prepare($sql_update_cuenta);
        $stmt_update_cuenta->execute([$nuevo_saldo, $nuevo_estado, $cuenta_id]);
        
        // ACTUALIZAR ESTADO EN LA TABLA VENTAS
        if ($venta_id) {
            // Determinar el estado para la venta
            $estado_venta = 'completada'; // Por defecto
            
            if ($nuevo_estado == 'pagada') {
                $estado_venta = 'pagada_credito';
            } else if ($nuevo_estado == 'parcial') {
                $estado_venta = 'pendiente_credito';
            } else if ($nuevo_estado == 'pendiente') {
                $estado_venta = 'pendiente_credito';
            }
            
            // Actualizar estado en ventas
            $sql_update_venta = "UPDATE ventas 
                                SET estado = ?, abono_inicial = abono_inicial + ?
                                WHERE id = ?";
            
            $stmt_update_venta = $db->prepare($sql_update_venta);
            $stmt_update_venta->execute([$estado_venta, $monto, $venta_id]);
        }
        
        $db->commit();
        
        // Guardar información para mostrar el ticket
        $_SESSION['pago_registrado'] = [
            'pago_id' => $pago_registrado_id,
            'cuenta_id' => $cuenta_id,
            'monto' => $monto,
            'numero_factura' => $numero_factura,
            'nuevo_saldo' => $nuevo_saldo,
            'nuevo_estado' => $nuevo_estado,
            'metodo_pago' => $metodo_pago,
            'referencia' => $referencia,
            'fecha_pago' => $fecha_hora_colombia
        ];
        
        // Redirigir a una página de confirmación que mostrará el ticket
        header("Location: confirmar_pago.php?cuenta_id=" . $cuenta_id . "&pago_id=" . $pago_registrado_id);
        exit();
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = "❌ ERROR: " . $e->getMessage();
        header("Location: registrar_pago.php?cuenta_id=" . $cuenta_id);
        exit();
    }
}

// ============================================
// INCLUIR HEADER DEL SISTEMA
// ============================================
include __DIR__ . '/../../includes/header.php';
?>

<style>
/* Estilos adicionales para el formulario */
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

.moneda-input {
    font-family: 'Courier New', monospace;
    font-weight: 600;
    text-align: right;
}

/* Animación para mensajes de error */
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
    20%, 40%, 60%, 80% { transform: translateX(5px); }
}

.error-shake {
    animation: shake 0.6s ease-in-out;
}

/* Badges de estado */
.estado-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.estado-pendiente {
    background-color: #fef3c7;
    color: #92400e;
}

.estado-parcial {
    background-color: #dbeafe;
    color: #1e40af;
}

.estado-pagada {
    background-color: #d1fae5;
    color: #065f46;
}

.estado-vencida {
    background-color: #fee2e2;
    color: #991b1b;
}
</style>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-cash-register text-green-600 mr-2"></i>
                Registrar Pago
            </h1>
            <p class="text-gray-600">Registrar un pago para una cuenta por cobrar</p>
        </div>
        <div class="flex space-x-3">
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver a Cuentas
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

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Información de la Cuenta -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="bg-blue-600 px-6 py-4">
                <h2 class="text-xl font-bold text-white">
                    <i class="fas fa-receipt mr-2"></i>
                    Información de la Cuenta
                </h2>
            </div>
            <div class="p-6">
                <?php if (isset($cuenta)): ?>
                    <!-- Cliente info -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                        <div class="flex items-center">
                            <div class="bg-blue-100 rounded-full p-3 mr-4">
                                <i class="fas fa-user-circle text-blue-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($cuenta['cliente_nombre']); ?></h3>
                                <?php if ($cuenta['telefono']): ?>
                                    <p class="text-sm text-gray-600">
                                        <i class="fas fa-phone mr-1"></i> <?php echo htmlspecialchars($cuenta['telefono']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Detalles de la cuenta -->
                    <dl class="space-y-3">
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Factura:</dt>
                            <dd class="font-bold text-blue-600"><?php echo htmlspecialchars($cuenta['numero_factura'] ?? 'N/A'); ?></dd>
                        </div>
                        
                        <?php if ($cuenta['venta_id']): ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Venta ID:</dt>
                            <dd class="text-gray-900">#<?php echo $cuenta['venta_id']; ?></dd>
                        </div>
                        
                        <?php
                        // Obtener estado actual de la venta
                        $sql_estado_venta = "SELECT estado FROM ventas WHERE id = ?";
                        $stmt_estado = $db->prepare($sql_estado_venta);
                        $stmt_estado->execute([$cuenta['venta_id']]);
                        $estado_venta = $stmt_estado->fetch(PDO::FETCH_COLUMN);
                        
                        $badge_venta_class = [
                            'completada' => 'bg-green-100 text-green-800',
                            'pendiente_credito' => 'bg-yellow-100 text-yellow-800',
                            'pagada_credito' => 'bg-green-100 text-green-800',
                            'anulada' => 'bg-red-100 text-red-800'
                        ][$estado_venta] ?? 'bg-gray-100 text-gray-800';
                        ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Estado venta:</dt>
                            <dd>
                                <span class="px-2 py-1 text-xs rounded-full <?php echo $badge_venta_class; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $estado_venta)); ?>
                                </span>
                            </dd>
                        </div>
                        <?php endif; ?>

                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Fecha Venta:</dt>
                            <dd class="text-gray-900"><?php echo $cuenta['fecha_venta'] ? date('d/m/Y', strtotime($cuenta['fecha_venta'])) : 'N/A'; ?></dd>
                        </div>

                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Total Deuda:</dt>
                            <dd class="font-bold text-gray-900">$ <?php echo number_format($cuenta['total_deuda'], 0, ',', '.'); ?></dd>
                        </div>

                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Pagado:</dt>
                            <dd class="font-bold text-green-600">$ <?php echo number_format($cuenta['total_deuda'] - $cuenta['saldo_pendiente'], 0, ',', '.'); ?></dd>
                        </div>

                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Saldo Pendiente:</dt>
                            <dd class="font-bold text-red-600 text-xl">$ <?php echo number_format($cuenta['saldo_pendiente'], 0, ',', '.'); ?></dd>
                        </div>

                        <?php if ($cuenta['fecha_limite']): 
                            $fecha_limite = new DateTime($cuenta['fecha_limite']);
                            $hoy = new DateTime();
                            $diferencia = $hoy->diff($fecha_limite);
                            $dias_restantes = $diferencia->days;
                            if ($diferencia->invert) $dias_restantes = -$dias_restantes;
                        ?>
                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Fecha Límite:</dt>
                            <dd>
                                <span class="font-medium"><?php echo date('d/m/Y', strtotime($cuenta['fecha_limite'])); ?></span>
                                <?php if ($dias_restantes < 0): ?>
                                    <span class="ml-2 px-2 py-1 text-xs rounded-full bg-red-100 text-red-800">
                                        Vencida hace <?php echo abs($dias_restantes); ?> días
                                    </span>
                                <?php elseif ($dias_restantes <= 3): ?>
                                    <span class="ml-2 px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-800">
                                        Vence en <?php echo $dias_restantes; ?> días
                                    </span>
                                <?php endif; ?>
                            </dd>
                        </div>
                        <?php endif; ?>

                        <div class="flex justify-between border-b border-gray-200 pb-2">
                            <dt class="text-gray-600 font-medium">Estado:</dt>
                            <dd>
                                <?php
                                $badge_class = [
                                    'pendiente' => 'bg-yellow-100 text-yellow-800',
                                    'parcial' => 'bg-blue-100 text-blue-800',
                                    'pagada' => 'bg-green-100 text-green-800',
                                    'vencida' => 'bg-red-100 text-red-800'
                                ][$cuenta['estado']] ?? 'bg-gray-100 text-gray-800';
                                ?>
                                <span class="px-3 py-1 rounded-full text-sm font-medium <?php echo $badge_class; ?>">
                                    <?php echo ucfirst($cuenta['estado']); ?>
                                </span>
                            </dd>
                        </div>
                    </dl>

                    <!-- Barra de progreso -->
                    <?php 
                    $pagado = $cuenta['total_deuda'] - $cuenta['saldo_pendiente'];
                    $porcentaje = $cuenta['total_deuda'] > 0 ? ($pagado / $cuenta['total_deuda']) * 100 : 0;
                    ?>
                    <div class="mt-6">
                        <div class="flex justify-between text-sm text-gray-600 mb-1">
                            <span>Progreso de pago</span>
                            <span class="font-semibold"><?php echo number_format($porcentaje, 1); ?>%</span>
                        </div>
                        <div class="w-full bg-gray-200 rounded-full h-2.5">
                            <div class="bg-green-600 h-2.5 rounded-full" style="width: <?php echo $porcentaje; ?>%"></div>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-receipt text-gray-300 text-5xl mb-4"></i>
                        <p class="text-gray-500">No se encontró la cuenta especificada</p>
                        <a href="index.php" class="inline-block mt-4 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-list-ul mr-2"></i>
                            Ver Cuentas
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Formulario de Pago -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="bg-green-600 px-6 py-4">
                <h2 class="text-xl font-bold text-white">
                    <i class="fas fa-credit-card mr-2"></i>
                    Registrar Pago
                </h2>
            </div>
            <div class="p-6">
                <?php if (isset($cuenta)): ?>
                    <form method="POST" action="" id="formPago">
                        <input type="hidden" name="cuenta_id" value="<?php echo $cuenta['id']; ?>">

                        <!-- Monto -->
                        <div class="mb-6">
                            <label for="monto" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-dollar-sign mr-1"></i>
                                Monto a Pagar *
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <span class="text-gray-500">$</span>
                                </div>
                                <input type="text" 
                                       id="monto" 
                                       name="monto" 
                                       data-max="<?php echo $cuenta['saldo_pendiente']; ?>"
                                       value="<?php echo number_format($cuenta['saldo_pendiente'], 0, ',', '.'); ?>"
                                       required
                                       class="pl-8 w-full p-3 border-2 border-gray-300 rounded-lg focus:border-green-500 focus:ring-2 focus:ring-green-200 outline-none transition-all text-lg moneda-input"
                                       placeholder="0">
                            </div>
                            <p class="text-sm text-gray-500 mt-2">
                                Saldo disponible: <span class="font-bold text-red-600">$ <?php echo number_format($cuenta['saldo_pendiente'], 0, ',', '.'); ?></span>
                            </p>
                            <div class="flex flex-wrap gap-2 mt-3">
                                <button type="button" onclick="setMonto(25)" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1 rounded text-sm">25%</button>
                                <button type="button" onclick="setMonto(50)" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1 rounded text-sm">50%</button>
                                <button type="button" onclick="setMonto(75)" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1 rounded text-sm">75%</button>
                                <button type="button" onclick="setMonto(100)" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-1 rounded text-sm">100%</button>
                            </div>
                            <div id="errorMonto" class="error-message hidden mt-2 text-red-600 text-sm"></div>
                        </div>

                        <!-- Método de Pago -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-wallet mr-1"></i>
                                Método de Pago *
                            </label>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <label class="metodo-pago-option flex flex-col items-center justify-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-green-500 transition-all <?php echo (!isset($_POST['metodo_pago']) || $_POST['metodo_pago'] == 'efectivo') ? 'selected-efectivo' : ''; ?>">
                                    <input type="radio" name="metodo_pago" value="efectivo" class="hidden" <?php echo (!isset($_POST['metodo_pago']) || $_POST['metodo_pago'] == 'efectivo') ? 'checked' : ''; ?>>
                                    <i class="fas fa-money-bill-wave text-green-500 text-2xl mb-2"></i>
                                    <span class="text-xs font-medium">Efectivo</span>
                                </label>

                                <label class="metodo-pago-option flex flex-col items-center justify-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-blue-500 transition-all">
                                    <input type="radio" name="metodo_pago" value="tarjeta" class="hidden">
                                    <i class="fas fa-credit-card text-blue-500 text-2xl mb-2"></i>
                                    <span class="text-xs font-medium">Tarjeta</span>
                                </label>

                                <label class="metodo-pago-option flex flex-col items-center justify-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-purple-500 transition-all">
                                    <input type="radio" name="metodo_pago" value="transferencia" class="hidden">
                                    <i class="fas fa-university text-purple-500 text-2xl mb-2"></i>
                                    <span class="text-xs font-medium">Transferencia</span>
                                </label>

                                <label class="metodo-pago-option flex flex-col items-center justify-center p-4 border-2 border-gray-200 rounded-lg cursor-pointer hover:border-orange-500 transition-all">
                                    <input type="radio" name="metodo_pago" value="cheque" class="hidden">
                                    <i class="fas fa-file-invoice text-orange-500 text-2xl mb-2"></i>
                                    <span class="text-xs font-medium">Cheque</span>
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
                                   value="<?php echo isset($_POST['referencia']) ? htmlspecialchars($_POST['referencia']) : ''; ?>"
                                   class="w-full p-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all"
                                   placeholder="Número de transacción, cheque, etc.">
                        </div>

                        <!-- Observaciones -->
                        <div class="mb-6">
                            <label for="observaciones" class="block text-sm font-medium text-gray-700 mb-2">
                                <i class="fas fa-comment mr-1"></i>
                                Observaciones (Opcional)
                            </label>
                            <textarea id="observaciones" 
                                      name="observaciones" 
                                      rows="3"
                                      class="w-full p-3 border-2 border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all"
                                      placeholder="Detalles adicionales del pago..."><?php echo isset($_POST['observaciones']) ? htmlspecialchars($_POST['observaciones']) : ''; ?></textarea>
                        </div>

                        <!-- Alerta informativa -->
                        <div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-6">
                            <div class="flex items-start">
                                <i class="fas fa-exclamation-triangle text-yellow-600 mt-1 mr-3"></i>
                                <div>
                                    <p class="text-sm text-yellow-700 font-medium">Este pago actualizará:</p>
                                    <ul class="text-sm text-yellow-600 list-disc list-inside mt-1">
                                        <li>El saldo pendiente de la cuenta</li>
                                        <li>El estado de la cuenta (pendiente/parcial/pagada)</li>
                                        <li>El estado de la venta en el sistema</li>
                                        <li>Se generará un comprobante de pago</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Botón enviar -->
                        <button type="submit" 
                                id="btnRegistrarPago"
                                class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 px-6 rounded-lg transition-colors flex items-center justify-center text-lg">
                            <i class="fas fa-check-circle mr-3"></i>
                            REGISTRAR PAGO
                        </button>
                    </form>

                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-search text-gray-300 text-5xl mb-4"></i>
                        <p class="text-gray-500">Seleccione una cuenta válida para registrar un pago</p>
                        <a href="index.php" class="inline-block mt-4 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-list-ul mr-2"></i>
                            Ver Cuentas
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Fecha y hora del servidor (Colombia) -->
    <div class="mt-4 text-sm text-gray-500 text-center">
        <i class="far fa-clock mr-1"></i>
        Fecha y hora actual (Colombia): <?php echo date('d/m/Y H:i:s'); ?>
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
    // Remover puntos y convertir a número
    let limpio = valor.toString().replace(/\./g, '');
    return parseInt(limpio, 10) || 0;
}

// Inicializar cuando el DOM esté listo
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
    
    // Evento input: solo números y formateo en tiempo real
    montoInput.addEventListener('input', function(e) {
        let valor = this.value.replace(/[^\d]/g, '');
        if (valor === '') valor = '0';
        
        // Convertir a número
        let numero = parseInt(valor, 10);
        
        // Validar límite
        if (numero > maxMonto) {
            numero = maxMonto;
        }
        
        // Actualizar valor formateado
        this.value = formatearMonedaColombiana(numero);
        
        // Validar
        validarMonto(numero);
    });
    
    // Al perder foco, asegurar formato
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
            // Remover clases de selección de todos
            document.querySelectorAll('.metodo-pago-option').forEach(opt => {
                opt.classList.remove('selected-efectivo', 'selected-tarjeta', 'selected-transferencia', 'selected-cheque');
                opt.style.borderColor = '#e5e7eb';
            });
            
            // Agregar clase según el método seleccionado
            const radio = this.querySelector('input[type="radio"]');
            const metodo = radio.value;
            
            if (metodo === 'efectivo') {
                this.classList.add('selected-efectivo');
            } else if (metodo === 'tarjeta') {
                this.classList.add('selected-tarjeta');
            } else if (metodo === 'transferencia') {
                this.classList.add('selected-transferencia');
            } else if (metodo === 'cheque') {
                this.classList.add('selected-cheque');
            }
            
            // Marcar radio
            radio.checked = true;
            
            // Ocultar error de método de pago
            document.getElementById('errorMetodoPago')?.classList.add('hidden');
        });
    });
    
    // Validación del formulario
    const form = document.getElementById('formPago');
    if (form) {
        form.addEventListener('submit', function(e) {
            const monto = limpiarFormatoMoneda(montoInput.value);
            
            // Validar monto
            if (!validarMonto(monto)) {
                e.preventDefault();
                montoInput.focus();
                montoInput.classList.add('error-shake');
                setTimeout(() => montoInput.classList.remove('error-shake'), 600);
                return false;
            }
            
            // Validar método de pago
            const metodoSeleccionado = document.querySelector('input[name="metodo_pago"]:checked');
            if (!metodoSeleccionado) {
                e.preventDefault();
                const errorElement = document.getElementById('errorMetodoPago');
                errorElement.classList.remove('hidden');
                errorElement.textContent = 'Debe seleccionar un método de pago';
                document.querySelector('.metodo-pago-option').scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
            
            // Actualizar el valor del input con el número sin formato para enviar
            montoInput.value = monto;
            
            // Confirmación
            if (!confirm('¿Está seguro de registrar este pago?\n\nMonto: $' + formatearMonedaColombiana(monto) + '\n\nEste pago actualizará la cuenta y generará un comprobante.')) {
                e.preventDefault();
                return false;
            }
            
            // Deshabilitar botón
            const btn = document.getElementById('btnRegistrarPago');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-3"></i> Procesando...';
            
            return true;
        });
    }
});
</script>

<?php 
// ============================================
// INCLUIR FOOTER
// ============================================
include __DIR__ . '/../../includes/footer.php'; 
?>