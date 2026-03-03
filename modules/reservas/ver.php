<?php
/**
 * ============================================
 * ARCHIVO: ver.php
 * UBICACIÓN: /modules/reservas/ver.php
 * PROPÓSITO: Ver detalle de una reserva
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
if (!$auth->hasPermission('reservas', 'leer')) {
    $_SESSION['error'] = "No tienes permisos para ver reservas";
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

try {
    // Obtener datos de la reserva - CORREGIDO: sin JOIN con clientes
    $query = "SELECT r.*, 
                     u.nombre as empleado_nombre,
                     u.username as empleado_username
              FROM reservas r
              LEFT JOIN usuarios u ON r.usuario_id = u.id
              WHERE r.id = :id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reserva) {
        $_SESSION['error'] = "Reserva no encontrada";
        header("Location: index.php");
        exit();
    }

    // Obtener servicios de la reserva
    $query_servicios = "SELECT * FROM reserva_detalles_servicios WHERE reserva_id = :id";
    $stmt_servicios = $db->prepare($query_servicios);
    $stmt_servicios->bindParam(':id', $id);
    $stmt_servicios->execute();
    $servicios = $stmt_servicios->fetchAll(PDO::FETCH_ASSOC);

    // Obtener productos de la reserva (si los hay)
    $query_productos = "SELECT * FROM reserva_detalles_productos WHERE reserva_id = :id";
    $stmt_productos = $db->prepare($query_productos);
    $stmt_productos->bindParam(':id', $id);
    $stmt_productos->execute();
    $productos = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Error en ver.php: " . $e->getMessage());
    $_SESSION['error'] = "Error al cargar la reserva";
    header("Location: index.php");
    exit();
}

$page_title = "Detalle de Reserva - " . ($config['nombre_negocio'] ?? 'Sistema POS');
include __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-calendar-check text-blue-600 mr-2"></i>
                Detalle de Reserva
            </h1>
            <p class="text-gray-600 mt-1">
                Código: <span class="font-mono font-bold"><?php echo htmlspecialchars($reserva['codigo_reserva'] ?? 'N/A'); ?></span>
            </p>
        </div>
        <div class="flex space-x-3 mt-4 md:mt-0">
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver
            </a>
            <?php if ($auth->hasPermission('reservas', 'editar') && $reserva['estado'] != 'completada'): ?>
            <a href="editar.php?id=<?php echo $id; ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-edit mr-2"></i>
                Editar
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Tarjeta de estado -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="p-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div class="p-3 rounded-full <?php 
                        echo $reserva['estado'] == 'completada' ? 'bg-green-100' : 
                            ($reserva['estado'] == 'cancelada' ? 'bg-red-100' : 
                            ($reserva['estado'] == 'confirmada' ? 'bg-blue-100' : 'bg-yellow-100')); 
                    ?>">
                        <i class="fas <?php 
                            echo $reserva['estado'] == 'completada' ? 'fa-check-circle text-green-600' : 
                                ($reserva['estado'] == 'cancelada' ? 'fa-times-circle text-red-600' : 
                                ($reserva['estado'] == 'confirmada' ? 'fa-check-circle text-blue-600' : 'fa-clock text-yellow-600')); 
                        ?> text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Estado</p>
                        <p class="text-2xl font-bold capitalize <?php 
                            echo $reserva['estado'] == 'completada' ? 'text-green-600' : 
                                ($reserva['estado'] == 'cancelada' ? 'text-red-600' : 
                                ($reserva['estado'] == 'confirmada' ? 'text-blue-600' : 'text-yellow-600')); 
                        ?>">
                            <?php echo $reserva['estado']; ?>
                        </p>
                    </div>
                </div>
                
                <?php if ($reserva['estado'] == 'pendiente' && $auth->hasPermission('reservas', 'editar')): ?>
                <div class="flex space-x-2">
                    <button onclick="cambiarEstado('confirmada')" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-check mr-2"></i>Confirmar
                    </button>
                    <button onclick="cambiarEstado('cancelada')" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-times mr-2"></i>Cancelar
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Información del cliente y cita -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <!-- Datos del cliente -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-user text-blue-600 mr-2"></i>
                Datos del Cliente
            </h2>
            <dl class="space-y-3">
                <div class="flex justify-between border-b pb-2">
                    <dt class="text-gray-600">Nombre:</dt>
                    <dd class="font-medium"><?php echo htmlspecialchars($reserva['nombre_cliente']); ?></dd>
                </div>
                <?php if (!empty($reserva['telefono_cliente'])): ?>
                <div class="flex justify-between border-b pb-2">
                    <dt class="text-gray-600">Teléfono:</dt>
                    <dd class="font-medium"><?php echo htmlspecialchars($reserva['telefono_cliente']); ?></dd>
                </div>
                <?php endif; ?>
                <?php if (!empty($reserva['email_cliente'])): ?>
                <div class="flex justify-between border-b pb-2">
                    <dt class="text-gray-600">Email:</dt>
                    <dd class="font-medium"><?php echo htmlspecialchars($reserva['email_cliente']); ?></dd>
                </div>
                <?php endif; ?>
            </dl>
        </div>

        <!-- Datos de la cita -->
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-calendar-alt text-blue-600 mr-2"></i>
                Datos de la Cita
            </h2>
            <dl class="space-y-3">
                <div class="flex justify-between border-b pb-2">
                    <dt class="text-gray-600">Fecha:</dt>
                    <dd class="font-medium"><?php echo date('d/m/Y', strtotime($reserva['fecha_reserva'])); ?></dd>
                </div>
                <div class="flex justify-between border-b pb-2">
                    <dt class="text-gray-600">Hora:</dt>
                    <dd class="font-medium"><?php echo date('H:i', strtotime($reserva['hora_reserva'])); ?></dd>
                </div>
                <?php if (!empty($reserva['empleado_nombre'])): ?>
                <div class="flex justify-between border-b pb-2">
                    <dt class="text-gray-600">Empleado:</dt>
                    <dd class="font-medium"><?php echo htmlspecialchars($reserva['empleado_nombre']); ?></dd>
                </div>
                <?php endif; ?>
                <div class="flex justify-between">
                    <dt class="text-gray-600">Creada:</dt>
                    <dd class="font-medium"><?php echo date('d/m/Y H:i', strtotime($reserva['created_at'])); ?></dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Lista de servicios -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-cut text-blue-600 mr-2"></i>
                Servicios
            </h2>
            
            <?php if (empty($servicios)): ?>
                <p class="text-gray-500 text-center py-4">No hay servicios registrados</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Servicio</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Precio</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($servicios as $s): ?>
                            <tr>
                                <td class="px-4 py-3"><?php echo htmlspecialchars($s['nombre_servicio']); ?></td>
                                <td class="px-4 py-3 text-right font-medium">
                                    $<?php echo number_format($s['precio_final'] ?: $s['precio_original'], 2); ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td class="px-4 py-3 font-semibold">Total Servicios</td>
                                <td class="px-4 py-3 text-right font-bold text-indigo-600">
                                    $<?php echo number_format($reserva['total_servicios'], 2); ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Productos adicionales (si existen) -->
    <?php if (!empty($productos)): ?>
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-box text-blue-600 mr-2"></i>
                Productos Adicionales
            </h2>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Producto</th>
                            <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">Cantidad</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Precio Unit.</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($productos as $p): ?>
                        <tr>
                            <td class="px-4 py-3"><?php echo htmlspecialchars($p['nombre_producto']); ?></td>
                            <td class="px-4 py-3 text-center"><?php echo $p['cantidad']; ?></td>
                            <td class="px-4 py-3 text-right">$<?php echo number_format($p['precio_unitario'], 2); ?></td>
                            <td class="px-4 py-3 text-right font-medium">$<?php echo number_format($p['subtotal'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr>
                            <td colspan="3" class="px-4 py-3 font-semibold text-right">Total Productos:</td>
                            <td class="px-4 py-3 text-right font-bold text-indigo-600">
                                $<?php echo number_format($reserva['total_productos'], 2); ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Total general -->
    <div class="bg-indigo-50 rounded-lg shadow p-6 mb-6">
        <div class="flex justify-between items-center">
            <div>
                <p class="text-sm text-indigo-600">Total General</p>
                <p class="text-3xl font-bold text-indigo-800">$<?php echo number_format($reserva['total_general'], 2); ?></p>
            </div>
            <?php if (!empty($reserva['observaciones'])): ?>
            <div class="text-right">
                <p class="text-sm text-gray-600">Observaciones</p>
                <p class="text-sm text-gray-800 italic"><?php echo nl2br(htmlspecialchars($reserva['observaciones'])); ?></p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($reserva['motivo_cancelacion'])): ?>
    <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
        <p class="text-sm text-red-600">
            <i class="fas fa-exclamation-circle mr-2"></i>
            <strong>Motivo de cancelación:</strong> <?php echo nl2br(htmlspecialchars($reserva['motivo_cancelacion'])); ?>
        </p>
    </div>
    <?php endif; ?>
</div>

<script>
function cambiarEstado(nuevoEstado) {
    if (!confirm('¿Estás seguro de cambiar el estado de esta reserva a ' + nuevoEstado + '?')) {
        return;
    }
    
    const motivo = nuevoEstado === 'cancelada' ? prompt('Ingrese el motivo de cancelación:') : '';
    
    fetch('ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'cambiar_estado',
            id: <?php echo $id; ?>,
            estado: nuevoEstado,
            motivo: motivo || ''
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al cambiar el estado');
    });
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>