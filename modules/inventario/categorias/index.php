<?php
if (session_status() === PHP_SESSION_NONE) session_start(); 
ob_start();
include '../../../includes/header.php';

// Verificar permisos
if ($_SESSION['usuario_rol'] != 'admin') {
    header('Location: /sistema_pos/index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Manejar eliminación
if (isset($_POST['eliminar_id'])) {
    $id = $_POST['eliminar_id'];
    
    // Verificar si la categoría tiene productos
    $query = "SELECT COUNT(*) as total FROM productos WHERE categoria_id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$id]);
    $total_productos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    if ($total_productos > 0) {
        $_SESSION['error'] = "No se puede eliminar la categoría porque tiene productos asociados.";
    } else {
        $query = "DELETE FROM categorias WHERE id = ?";
        $stmt = $db->prepare($query);
        if ($stmt->execute([$id])) {
            $_SESSION['success'] = "Categoría eliminada correctamente.";
        } else {
            $_SESSION['error'] = "Error al eliminar la categoría.";
        }
    }
    header('Location: index.php');
    ob_end_flush();
    exit;
}

// Obtener categorías
$query = "SELECT * FROM categorias ORDER BY nombre";
$stmt = $db->prepare($query);
$stmt->execute();
$categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="max-w-7xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Categorías</h1>
            <p class="text-gray-600">Gestiona las categorías de productos</p>
        </div>
        <a href="crear.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-plus mr-2"></i>
            Nueva Categoría
        </a>
    </div>

    <!-- Mostrar mensajes -->
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

    <div class="bg-white shadow overflow-hidden sm:rounded-lg">
        <?php if (count($categorias) > 0): ?>
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Nombre
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Descripción
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Estado
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Fecha Creación
                        </th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                            Acciones
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($categorias as $categoria): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($categoria['nombre']); ?></div>
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($categoria['descripcion'] ?? 'Sin descripción'); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $categoria['activo'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo $categoria['activo'] ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo date('d/m/Y', strtotime($categoria['created_at'])); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                            <a href="editar.php?id=<?php echo $categoria['id']; ?>" class="text-blue-600 hover:text-blue-900">
                                <i class="fas fa-edit"></i> Editar
                            </a>
                            <button onclick="confirmarEliminacion(<?php echo $categoria['id']; ?>, '<?php echo htmlspecialchars($categoria['nombre']); ?>')" class="text-red-600 hover:text-red-900">
                                <i class="fas fa-trash"></i> Eliminar
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-tags text-gray-400 text-5xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No hay categorías registradas</h3>
                <p class="text-gray-500 mb-4">Comienza creando tu primera categoría de productos.</p>
                <a href="crear.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                    <i class="fas fa-plus mr-2"></i>
                    Crear Categoría
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de confirmación para eliminar -->
<div id="modalEliminar" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900">Confirmar Eliminación</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">
                    ¿Estás seguro de que quieres eliminar la categoría "<span id="categoriaNombre"></span>"?
                </p>
                <p class="text-sm text-red-500 mt-2">Esta acción no se puede deshacer.</p>
            </div>
            <div class="flex justify-center space-x-3 mt-4">
                <button onclick="cerrarModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded">
                    Cancelar
                </button>
                <form id="formEliminar" method="POST" class="inline">
                    <input type="hidden" name="eliminar_id" id="eliminarId">
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">
                        Eliminar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmarEliminacion(id, nombre) {
    document.getElementById('categoriaNombre').textContent = nombre;
    document.getElementById('eliminarId').value = id;
    document.getElementById('modalEliminar').classList.remove('hidden');
}

function cerrarModal() {
    document.getElementById('modalEliminar').classList.add('hidden');
}
</script>

<?php include '../../../includes/footer.php'; ?>