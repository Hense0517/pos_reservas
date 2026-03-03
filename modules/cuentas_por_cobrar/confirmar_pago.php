<?php
/**
 * ============================================
 * ARCHIVO: confirmar_pago.php
 * UBICACIÓN: /modules/cuentas_por_cobrar/confirmar_pago.php
 * FECHA CORRECCIÓN: 2026-02-17
 * 
 * PROPÓSITO:
 * Mostrar confirmación de pago registrado y abrir comprobante
 * 
 * PROBLEMA ORIGINAL:
 * 1. No cargaba los estilos de recursos.php
 * 2. Usaba Bootstrap inconsistente con el sistema
 * 3. Ruta de redirección incorrecta
 * 
 * SOLUCIÓN APLICADA:
 * - Usar header/footer del sistema con estilos unificados
 * - Corregir rutas con __DIR__
 * - Usar BASE_URL para redirecciones
 * ============================================
 */

session_start();

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Incluir configuración
require_once __DIR__ . '/../../includes/config.php';

// Incluir header del sistema (que ya carga recursos.php)
include __DIR__ . '/../../includes/header.php';

// Obtener IDs
$cuenta_id = isset($_GET['cuenta_id']) ? intval($_GET['cuenta_id']) : 0;
$pago_id = isset($_GET['pago_id']) ? intval($_GET['pago_id']) : 0;

// Conexión a base de datos
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}

// Obtener información básica de la cuenta y el pago
$cuenta = null;
$pago = null;

if ($cuenta_id > 0) {
    // Información de la cuenta
    $sql_cuenta = "SELECT cp.*, c.nombre as cliente_nombre, c.telefono, 
                          v.numero_factura, v.total as total_venta
                   FROM cuentas_por_cobrar cp
                   LEFT JOIN clientes c ON cp.cliente_id = c.id
                   LEFT JOIN ventas v ON cp.venta_id = v.id
                   WHERE cp.id = ?";
    
    $stmt_cuenta = $db->prepare($sql_cuenta);
    $stmt_cuenta->execute([$cuenta_id]);
    $cuenta = $stmt_cuenta->fetch(PDO::FETCH_ASSOC);
    
    // Información del pago específico
    if ($pago_id > 0) {
        $sql_pago = "SELECT * FROM pagos_cuentas_por_cobrar 
                     WHERE id = ? AND cuenta_id = ?";
        $stmt_pago = $db->prepare($sql_pago);
        $stmt_pago->execute([$pago_id, $cuenta_id]);
        $pago = $stmt_pago->fetch(PDO::FETCH_ASSOC);
    }
}

// Si no hay información de pago, intentar obtener de sesión
if (!$pago && isset($_SESSION['pago_registrado'])) {
    $pago = $_SESSION['pago_registrado'];
}

$page_title = "Pago Registrado - " . ($config['nombre_negocio'] ?? 'Sistema POS');
?>

<div class="max-w-4xl mx-auto p-6">
    <!-- Mensaje de éxito -->
    <div class="bg-green-50 border-l-4 border-green-500 p-6 rounded-lg shadow-md mb-6">
        <div class="flex items-center">
            <div class="flex-shrink-0">
                <i class="fas fa-check-circle text-green-500 text-4xl"></i>
            </div>
            <div class="ml-4">
                <h2 class="text-2xl font-bold text-green-800">¡Pago Registrado Exitosamente!</h2>
                <p class="text-green-700 mt-1">El pago ha sido procesado correctamente y se ha actualizado la cuenta.</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Información de la cuenta -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="bg-blue-600 px-4 py-3">
                <h3 class="text-lg font-semibold text-white">
                    <i class="fas fa-receipt mr-2"></i>
                    Información de la Cuenta
                </h3>
            </div>
            <div class="p-4">
                <?php if ($cuenta): ?>
                    <dl class="space-y-2">
                        <div class="flex justify-between border-b border-gray-100 pb-2">
                            <dt class="text-gray-600">Cliente:</dt>
                            <dd class="font-semibold text-gray-900"><?php echo htmlspecialchars($cuenta['cliente_nombre'] ?? 'N/A'); ?></dd>
                        </div>
                        <?php if (!empty($cuenta['telefono'])): ?>
                        <div class="flex justify-between border-b border-gray-100 pb-2">
                            <dt class="text-gray-600">Teléfono:</dt>
                            <dd class="font-semibold text-gray-900"><?php echo htmlspecialchars($cuenta['telefono']); ?></dd>
                        </div>
                        <?php endif; ?>
                        <div class="flex justify-between border-b border-gray-100 pb-2">
                            <dt class="text-gray-600">Factura:</dt>
                            <dd class="font-semibold text-blue-600"><?php echo htmlspecialchars($cuenta['numero_factura'] ?? 'N/A'); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-100 pb-2">
                            <dt class="text-gray-600">Total Deuda:</dt>
                            <dd class="font-semibold text-gray-900">$ <?php echo number_format($cuenta['total_deuda'] ?? 0, 0, ',', '.'); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-100 pb-2">
                            <dt class="text-gray-600">Saldo Anterior:</dt>
                            <dd class="font-semibold text-red-600">$ <?php echo number_format($cuenta['saldo_pendiente'] ?? 0, 0, ',', '.'); ?></dd>
                        </div>
                    </dl>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-4">No se encontró información de la cuenta</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Información del pago -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="bg-green-600 px-4 py-3">
                <h3 class="text-lg font-semibold text-white">
                    <i class="fas fa-credit-card mr-2"></i>
                    Detalles del Pago
                </h3>
            </div>
            <div class="p-4">
                <?php if ($pago): ?>
                    <dl class="space-y-2">
                        <div class="flex justify-between border-b border-gray-100 pb-2">
                            <dt class="text-gray-600">Monto Pagado:</dt>
                            <dd class="font-semibold text-green-600 text-xl">$ <?php echo number_format($pago['monto'] ?? 0, 0, ',', '.'); ?></dd>
                        </div>
                        <div class="flex justify-between border-b border-gray-100 pb-2">
                            <dt class="text-gray-600">Método de Pago:</dt>
                            <dd class="font-semibold text-gray-900 capitalize"><?php echo htmlspecialchars($pago['metodo_pago'] ?? 'N/A'); ?></dd>
                        </div>
                        <?php if (!empty($pago['referencia'])): ?>
                        <div class="flex justify-between border-b border-gray-100 pb-2">
                            <dt class="text-gray-600">Referencia:</dt>
                            <dd class="font-semibold text-gray-900"><?php echo htmlspecialchars($pago['referencia']); ?></dd>
                        </div>
                        <?php endif; ?>
                        <?php if (isset($pago['nuevo_saldo'])): ?>
                        <div class="flex justify-between border-b border-gray-100 pb-2">
                            <dt class="text-gray-600">Nuevo Saldo:</dt>
                            <dd class="font-semibold text-yellow-600">$ <?php echo number_format($pago['nuevo_saldo'], 0, ',', '.'); ?></dd>
                        </div>
                        <?php endif; ?>
                        <div class="flex justify-between border-b border-gray-100 pb-2">
                            <dt class="text-gray-600">Fecha:</dt>
                            <dd class="font-semibold text-gray-900"><?php echo date('d/m/Y H:i'); ?></dd>
                        </div>
                    </dl>
                <?php else: ?>
                    <p class="text-gray-500 text-center py-4">No se encontró información del pago</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Vista previa del comprobante -->
    <div class="mt-6 bg-gray-50 border-2 border-dashed border-gray-300 rounded-lg p-6">
        <div class="text-center mb-4">
            <i class="fas fa-receipt text-gray-400 text-5xl"></i>
            <h3 class="text-xl font-semibold text-gray-700 mt-2">Comprobante de Pago</h3>
            <p class="text-gray-500">Se abrirá una nueva ventana con el comprobante</p>
        </div>

        <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded">
            <div class="flex items-start">
                <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
                <div>
                    <p class="text-sm text-blue-700 font-medium">Instrucciones:</p>
                    <ul class="text-sm text-blue-600 mt-1 list-disc list-inside">
                        <li>El comprobante se abrirá automáticamente</li>
                        <li>Puede imprimirlo desde esa ventana</li>
                        <li>Cierre la ventana del comprobante cuando termine</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Botones de acción -->
    <div class="flex flex-wrap justify-center gap-4 mt-6">
        <button onclick="abrirTicket()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium flex items-center transition-colors">
            <i class="fas fa-receipt mr-2"></i>
            Ver Comprobante
        </button>
        
        <a href="ver.php?id=<?php echo $cuenta_id; ?>" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg font-medium flex items-center transition-colors">
            <i class="fas fa-eye mr-2"></i>
            Ver Detalles de la Cuenta
        </a>
        
        <a href="registrar_pago.php?cuenta_id=<?php echo $cuenta_id; ?>" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-medium flex items-center transition-colors">
            <i class="fas fa-plus-circle mr-2"></i>
            Registrar Otro Pago
        </a>
        
        <a href="index.php" class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg font-medium flex items-center transition-colors">
            <i class="fas fa-list-ul mr-2"></i>
            Volver a Cuentas
        </a>
    </div>
</div>

<script>
// Función para abrir el ticket en nueva ventana
function abrirTicket() {
    const url = 'ver_pago_ticket.php?cuenta_id=<?php echo $cuenta_id; ?>&pago_id=<?php echo $pago_id; ?>';
    const ventanaTicket = window.open(
        url,
        'TicketPago',
        'width=400,height=600,scrollbars=yes,resizable=yes,toolbar=no,location=no,directories=no,status=no,menubar=no'
    );
    
    if (ventanaTicket) {
        ventanaTicket.focus();
    } else {
        alert('Por favor, permita ventanas emergentes para ver el comprobante');
    }
}

// Abrir automáticamente el ticket al cargar la página
window.addEventListener('load', function() {
    setTimeout(abrirTicket, 800);
});
</script>

<?php 
// Limpiar sesión del pago registrado
unset($_SESSION['pago_registrado']);

// Incluir footer
include __DIR__ . '/../../includes/footer.php'; 
?>