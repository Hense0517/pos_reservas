<?php
/**
 * ============================================
 * ARCHIVO: index.php
 * UBICACIÓN: /modules/compras/index.php
 * PROPÓSITO: Listado de compras con filtros y acciones
 * ============================================
 */

session_start();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/header.php';

// Verificar permisos
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Obtener filtros de URL
$estado = $_GET['estado'] ?? '';
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';

// Construir query con filtros
$sql = "SELECT c.*, p.nombre as proveedor_nombre, u.nombre as usuario_nombre 
        FROM compras c 
        LEFT JOIN proveedores p ON c.proveedor_id = p.id 
        LEFT JOIN usuarios u ON c.usuario_id = u.id 
        WHERE 1=1";

$params = [];

if (!empty($estado)) {
    $sql .= " AND c.estado = ?";
    $params[] = $estado;
}

if (!empty($desde)) {
    $sql .= " AND DATE(c.fecha) >= ?";
    $params[] = $desde;
}

if (!empty($hasta)) {
    $sql .= " AND DATE(c.fecha) <= ?";
    $params[] = $hasta;
}

$sql .= " ORDER BY c.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas
$stats = [
    'total' => count($compras),
    'pendientes' => 0,
    'recibidas' => 0,
    'canceladas' => 0
];

foreach ($compras as $c) {
    $stats[$c['estado'] . 's'] = ($stats[$c['estado'] . 's'] ?? 0) + 1;
}
?>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-truck text-blue-600 mr-2"></i>
                Gestión de Compras
            </h1>
            <p class="text-gray-600 mt-1">Administra tus órdenes de compra y proveedores</p>
        </div>
        <div class="flex space-x-3 mt-4 md:mt-0">
            <a href="crear.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-plus mr-2"></i>
                Nueva Compra
            </a>
        </div>
    </div>

    <!-- Estadísticas rápidas -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-blue-500">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-blue-100 text-blue-600 mr-3">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Total Compras</p>
                    <p class="text-2xl font-bold"><?php echo $stats['total']; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-yellow-500">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-yellow-100 text-yellow-600 mr-3">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Pendientes</p>
                    <p class="text-2xl font-bold"><?php echo $stats['pendientes']; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-green-500">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-green-100 text-green-600 mr-3">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Recibidas</p>
                    <p class="text-2xl font-bold"><?php echo $stats['recibidas']; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 border-l-4 border-red-500">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-red-100 text-red-600 mr-3">
                    <i class="fas fa-times-circle"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500">Canceladas</p>
                    <p class="text-2xl font-bold"><?php echo $stats['canceladas']; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="p-4 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-800">
                <i class="fas fa-filter mr-2"></i>
                Filtros de Búsqueda
            </h2>
        </div>
        <div class="p-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Estado</label>
                    <select id="filtroEstado" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <option value="">Todos los estados</option>
                        <option value="pendiente" <?php echo $estado == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                        <option value="recibida" <?php echo $estado == 'recibida' ? 'selected' : ''; ?>>Recibida</option>
                        <option value="cancelada" <?php echo $estado == 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Desde</label>
                    <input type="date" id="filtroDesde" value="<?php echo $desde; ?>" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fecha Hasta</label>
                    <input type="date" id="filtroHasta" value="<?php echo $hasta; ?>" 
                           class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="flex items-end space-x-2">
                    <button id="btnFiltrar" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex-1">
                        <i class="fas fa-search mr-2"></i>
                        Filtrar
                    </button>
                    <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de compras -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Factura</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Proveedor</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Usuario</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($compras)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <i class="fas fa-truck text-gray-300 text-5xl mb-4"></i>
                                <p class="text-gray-500 text-lg">No hay compras registradas</p>
                                <p class="text-gray-400 text-sm mt-1">Comienza creando una nueva compra</p>
                                <a href="crear.php" class="inline-block mt-4 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-plus mr-2"></i>
                                    Nueva Compra
                                </a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($compras as $compra): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($compra['numero_factura'] ?? 'N/A'); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($compra['proveedor_nombre'] ?? 'N/A'); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-600">
                                        <?php echo date('d/m/Y', strtotime($compra['fecha'])); ?>
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        <?php echo date('H:i', strtotime($compra['fecha'])); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-bold text-green-600">
                                        $<?php echo number_format($compra['total'], 0, ',', '.'); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $estado_colors = [
                                        'pendiente' => 'bg-yellow-100 text-yellow-800',
                                        'recibida' => 'bg-green-100 text-green-800',
                                        'cancelada' => 'bg-red-100 text-red-800'
                                    ];
                                    $color = $estado_colors[$compra['estado']] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-1 text-xs rounded-full <?php echo $color; ?>">
                                        <?php echo ucfirst($compra['estado']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-600">
                                        <?php echo htmlspecialchars($compra['usuario_nombre'] ?? 'N/A'); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex space-x-2">
                                        <!-- Ver - Siempre visible -->
                                        <a href="ver.php?id=<?php echo $compra['id']; ?>" 
                                           class="text-blue-600 hover:text-blue-900 p-2 hover:bg-blue-50 rounded-lg transition"
                                           title="Ver detalles">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <!-- Editar - Solo pendientes -->
                                        <?php if ($compra['estado'] == 'pendiente'): ?>
                                            <a href="editar.php?id=<?php echo $compra['id']; ?>" 
                                               class="text-green-600 hover:text-green-900 p-2 hover:bg-green-50 rounded-lg transition"
                                               title="Editar compra">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <!-- Cancelar - Solo pendientes -->
                                        <?php if ($compra['estado'] == 'pendiente'): ?>
                                            <button onclick="confirmarCancelacion(<?php echo $compra['id']; ?>, '<?php echo htmlspecialchars($compra['numero_factura']); ?>')" 
                                                    class="text-red-600 hover:text-red-900 p-2 hover:bg-red-50 rounded-lg transition"
                                                    title="Cancelar compra">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <!-- Imprimir - Siempre visible -->
                                        <a href="imprimir.php?id=<?php echo $compra['id']; ?>" 
                                           class="text-purple-600 hover:text-purple-900 p-2 hover:bg-purple-50 rounded-lg transition"
                                           title="Imprimir compra"
                                           target="_blank">
                                            <i class="fas fa-print"></i>
                                        </a>
                                        
                                        <!-- Eliminar - Solo admin -->
                                        <?php if ($_SESSION['usuario_rol'] == 'admin'): ?>
                                            <button onclick="confirmarEliminacion(<?php echo $compra['id']; ?>, '<?php echo htmlspecialchars($compra['numero_factura']); ?>')" 
                                                    class="text-red-600 hover:text-red-900 p-2 hover:bg-red-50 rounded-lg transition"
                                                    title="Eliminar permanentemente">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Resumen -->
        <?php if (!empty($compras)): ?>
        <div class="bg-gray-50 px-6 py-3 border-t border-gray-200">
            <div class="flex justify-between items-center">
                <p class="text-sm text-gray-600">
                    <i class="fas fa-list mr-1"></i>
                    Mostrando <?php echo count($compras); ?> compra(s)
                </p>
                <p class="text-sm font-semibold text-gray-900">
                    Total general: $<?php 
                    $total_general = array_sum(array_column($compras, 'total'));
                    echo number_format($total_general, 0, ',', '.');
                    ?>
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de confirmación para cancelar -->
<div id="modalCancelar" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg max-w-md w-full p-6">
        <div class="text-center mb-4">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900">Cancelar Compra</h3>
            <p class="text-gray-600 mt-2" id="mensajeCancelar"></p>
        </div>
        
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
            <p class="text-sm text-red-700">
                <i class="fas fa-info-circle mr-2"></i>
                Esta acción no se puede deshacer. La compra se marcará como cancelada.
            </p>
        </div>
        
        <div class="flex justify-end space-x-3">
            <button onclick="cerrarModal('modalCancelar')" 
                    class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                Cancelar
            </button>
            <a href="#" id="btnConfirmarCancelar" 
               class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg">
                Sí, Cancelar Compra
            </a>
        </div>
    </div>
</div>

<!-- Modal de confirmación para eliminar -->
<div id="modalEliminar" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg max-w-md w-full p-6">
        <div class="text-center mb-4">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-trash-alt text-red-600 text-2xl"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-900">Eliminar Compra</h3>
            <p class="text-gray-600 mt-2" id="mensajeEliminar"></p>
        </div>
        
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
            <p class="text-sm text-red-700">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                Esta acción eliminará PERMANENTEMENTE la compra y todos sus detalles.
            </p>
        </div>
        
        <div class="flex justify-end space-x-3">
            <button onclick="cerrarModal('modalEliminar')" 
                    class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                Cancelar
            </button>
            <a href="#" id="btnConfirmarEliminar" 
               class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg">
                Sí, Eliminar
            </a>
        </div>
    </div>
</div>

<script>
function confirmarCancelacion(id, factura) {
    const modal = document.getElementById('modalCancelar');
    const mensaje = document.getElementById('mensajeCancelar');
    const btnConfirmar = document.getElementById('btnConfirmarCancelar');
    
    mensaje.innerHTML = `¿Cancelar la compra <strong>${factura}</strong>?`;
    btnConfirmar.href = `cancelar.php?id=${id}`;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function confirmarEliminacion(id, factura) {
    const modal = document.getElementById('modalEliminar');
    const mensaje = document.getElementById('mensajeEliminar');
    const btnConfirmar = document.getElementById('btnConfirmarEliminar');
    
    mensaje.innerHTML = `¿Eliminar permanentemente la compra <strong>${factura}</strong>?`;
    btnConfirmar.href = `eliminar.php?id=${id}`;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function cerrarModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

// Filtros
document.getElementById('btnFiltrar').addEventListener('click', function() {
    const estado = document.getElementById('filtroEstado').value;
    const desde = document.getElementById('filtroDesde').value;
    const hasta = document.getElementById('filtroHasta').value;
    
    let url = 'index.php?';
    if (estado) url += 'estado=' + encodeURIComponent(estado) + '&';
    if (desde) url += 'desde=' + encodeURIComponent(desde) + '&';
    if (hasta) url += 'hasta=' + encodeURIComponent(hasta);
    
    window.location.href = url;
});

// Enter en campos de filtro
document.getElementById('filtroDesde').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') document.getElementById('btnFiltrar').click();
});
document.getElementById('filtroHasta').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') document.getElementById('btnFiltrar').click();
});
document.getElementById('filtroEstado').addEventListener('change', function() {
    document.getElementById('btnFiltrar').click();
});

// Cerrar modales con Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModal('modalCancelar');
        cerrarModal('modalEliminar');
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>