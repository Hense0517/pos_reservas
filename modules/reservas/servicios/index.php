<?php
// modules/reservas/servicios/index.php
require_once __DIR__ . '/../../../includes/config.php';

if (!$auth->hasPermission('reservas', 'leer')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

$page_title = 'Gestión de Servicios';
include __DIR__ . '/../../../includes/header.php';
?>

<div class="max-w-7xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-gray-800">Servicios</h1>
        <?php if ($auth->hasPermission('reservas', 'crear')): ?>
        <a href="crear.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-plus mr-2"></i>Nuevo Servicio
        </a>
        <?php endif; ?>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_GET['mensaje'])): ?>
        <div class="mb-4 p-4 rounded-lg <?php echo ($_GET['tipo'] ?? 'success') == 'success' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700'; ?>">
            <i class="fas <?php echo ($_GET['tipo'] ?? 'success') == 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?> mr-2"></i>
            <?php echo htmlspecialchars($_GET['mensaje']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="mb-4 p-4 rounded-lg bg-red-100 text-red-700">
            <i class="fas fa-exclamation-circle mr-2"></i>
            <?php echo htmlspecialchars($_GET['error']); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nombre</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descripción</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Precio</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duración</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Variable</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php
                $query = "SELECT * FROM servicios ORDER BY activo DESC, nombre ASC";
                $stmt = $db->prepare($query);
                $stmt->execute();
                
                if ($stmt->rowCount() == 0):
                ?>
                <tr>
                    <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                        <i class="fas fa-cut text-4xl mb-3 opacity-50"></i>
                        <p>No hay servicios registrados</p>
                        <a href="crear.php" class="text-indigo-600 hover:text-indigo-900 mt-2 inline-block">
                            <i class="fas fa-plus mr-1"></i>Crear el primer servicio
                        </a>
                    </td>
                </tr>
                <?php else: ?>
                    <?php while ($servicio = $stmt->fetch(PDO::FETCH_ASSOC)): ?>
                    <tr class="<?php echo !$servicio['activo'] ? 'bg-gray-50 text-gray-500' : ''; ?>">
                        <td class="px-6 py-4 font-medium">
                            <?php echo htmlspecialchars($servicio['nombre']); ?>
                            <?php if (!$servicio['activo']): ?>
                                <span class="ml-2 px-2 py-1 text-xs bg-gray-200 rounded">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <?php echo htmlspecialchars(substr($servicio['descripcion'] ?? '', 0, 50)) . (strlen($servicio['descripcion'] ?? '') > 50 ? '...' : ''); ?>
                        </td>
                        <td class="px-6 py-4 font-semibold">
                            $<?php echo number_format($servicio['precio'], 2); ?>
                        </td>
                        <td class="px-6 py-4">
                            <?php echo $servicio['duracion_minutos']; ?> min
                        </td>
                        <td class="px-6 py-4">
                            <?php if ($servicio['precio_variable']): ?>
                                <span class="px-2 py-1 text-xs bg-purple-100 text-purple-800 rounded-full">
                                    <i class="fas fa-random mr-1"></i>Variable
                                </span>
                            <?php else: ?>
                                <span class="px-2 py-1 text-xs bg-gray-100 text-gray-800 rounded-full">Fijo</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-2 py-1 text-xs rounded-full <?php echo $servicio['activo'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo $servicio['activo'] ? 'Activo' : 'Inactivo'; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4">
                            <a href="editar.php?id=<?php echo $servicio['id']; ?>" 
                               class="text-indigo-600 hover:text-indigo-900 mr-3" 
                               title="Editar">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if ($auth->hasPermission('reservas', 'eliminar')): ?>
                                <?php if ($servicio['activo']): ?>
                                    <a href="eliminar.php?id=<?php echo $servicio['id']; ?>" 
                                       class="text-red-600 hover:text-red-900" 
                                       title="Eliminar"
                                       onclick="return confirm('¿Estás seguro de eliminar este servicio?\n\nNota: Si tiene reservas asociadas, solo se desactivará.');">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-400 cursor-not-allowed" title="Servicio inactivo">
                                        <i class="fas fa-trash"></i>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Información adicional -->
    <div class="mt-4 text-sm text-gray-500 flex items-center gap-4">
        <span><i class="fas fa-circle text-green-500 mr-1"></i> Activo</span>
        <span><i class="fas fa-circle text-red-500 mr-1"></i> Inactivo</span>
        <span><i class="fas fa-random text-purple-500 mr-1"></i> Precio variable</span>
    </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>