<?php
/**
 * ============================================
 * ARCHIVO: eliminar.php
 * UBICACIÓN: /modules/reservas/eliminar.php
 * PROPÓSITO: Eliminar una reserva (cambiar estado a cancelada)
 * ============================================
 */

session_start();

require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permiso
if (!$auth->hasPermission('reservas', 'eliminar')) {
    $_SESSION['error'] = "No tienes permisos para eliminar reservas";
    header("Location: index.php");
    exit();
}

$database = Database::getInstance();
$db = $database->getConnection();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    $_SESSION['error'] = "ID de reserva no válido";
    header("Location: index.php");
    exit();
}

// Obtener datos de la reserva para mostrar
$query = "SELECT * FROM reservas WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$id]);
$reserva = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reserva) {
    $_SESSION['error'] = "Reserva no encontrada";
    header("Location: index.php");
    exit();
}

// No permitir eliminar reservas completadas
if ($reserva['estado'] == 'completada') {
    $_SESSION['error'] = "No se puede eliminar una reserva completada";
    header("Location: ver.php?id=" . $id);
    exit();
}

$page_title = "Eliminar Reserva - " . ($config['nombre_negocio'] ?? 'Sistema POS');
include __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-2xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow p-6">
        <div class="text-center mb-6">
            <div class="inline-block p-4 bg-red-100 rounded-full mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 text-4xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">¿Eliminar Reserva?</h1>
            <p class="text-gray-600">
                Estás a punto de eliminar la reserva <strong><?php echo htmlspecialchars($reserva['codigo_reserva']); ?></strong>
            </p>
        </div>

        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
            <p class="text-sm text-yellow-700">
                <i class="fas fa-info-circle mr-2"></i>
                Esta acción cambiará el estado de la reserva a "cancelada". No se eliminarán los registros permanentemente.
            </p>
        </div>

        <div class="bg-gray-50 rounded-lg p-4 mb-6">
            <h3 class="font-semibold text-gray-700 mb-3">Detalles de la reserva:</h3>
            <dl class="space-y-2">
                <div class="flex justify-between">
                    <dt class="text-gray-600">Cliente:</dt>
                    <dd class="font-medium"><?php echo htmlspecialchars($reserva['nombre_cliente']); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600">Fecha:</dt>
                    <dd class="font-medium"><?php echo date('d/m/Y', strtotime($reserva['fecha_reserva'])); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600">Hora:</dt>
                    <dd class="font-medium"><?php echo date('H:i', strtotime($reserva['hora_reserva'])); ?></dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-600">Estado actual:</dt>
                    <dd>
                        <span class="px-2 py-1 text-xs rounded-full <?php 
                            echo $reserva['estado'] == 'pendiente' ? 'bg-yellow-100 text-yellow-800' : 
                                ($reserva['estado'] == 'confirmada' ? 'bg-blue-100 text-blue-800' : 
                                'bg-gray-100 text-gray-800'); 
                        ?>">
                            <?php echo ucfirst($reserva['estado']); ?>
                        </span>
                    </dd>
                </div>
            </dl>
        </div>

        <form action="procesar_eliminar.php" method="POST" onsubmit="return confirmarEliminacion()">
            <input type="hidden" name="id" value="<?php echo $id; ?>">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Motivo de cancelación</label>
                <textarea name="motivo" rows="3" required
                          class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-red-500"
                          placeholder="Indique el motivo por el cual se cancela la reserva..."></textarea>
            </div>

            <div class="flex justify-end space-x-3">
                <a href="ver.php?id=<?php echo $id; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-times mr-2"></i>
                    No, volver
                </a>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-trash mr-2"></i>
                    Sí, cancelar reserva
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function confirmarEliminacion() {
    const motivo = document.querySelector('textarea[name="motivo"]').value.trim();
    
    if (!motivo) {
        alert('Debe ingresar un motivo de cancelación');
        return false;
    }
    
    return confirm('¿Está seguro de cancelar esta reserva? Esta acción no se puede deshacer.');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>