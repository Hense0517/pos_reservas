<?php
// modules/reservas/servicios/editar.php
require_once __DIR__ . '/../../../includes/config.php';

if (!$auth->hasPermission('reservas', 'editar')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

$id = $_GET['id'] ?? 0;

// Obtener datos del servicio
$query = "SELECT * FROM servicios WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$servicio = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$servicio) {
    header('Location: index.php?error=Servicio no encontrado');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $precio = floatval($_POST['precio'] ?? 0);
    $precio_variable = isset($_POST['precio_variable']) ? 1 : 0;
    $duracion_minutos = intval($_POST['duracion_minutos'] ?? 30);
    $activo = isset($_POST['activo']) ? 1 : 0;
    
    if (empty($nombre)) {
        $error = "El nombre es requerido";
    } else {
        try {
            $query = "UPDATE servicios SET 
                      nombre = :nombre, 
                      descripcion = :descripcion, 
                      precio = :precio, 
                      precio_variable = :precio_variable, 
                      duracion_minutos = :duracion_minutos, 
                      activo = :activo,
                      updated_at = NOW()
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':descripcion', $descripcion);
            $stmt->bindParam(':precio', $precio);
            $stmt->bindParam(':precio_variable', $precio_variable);
            $stmt->bindParam(':duracion_minutos', $duracion_minutos);
            $stmt->bindParam(':activo', $activo);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            header('Location: index.php?mensaje=Servicio actualizado correctamente');
            exit;
        } catch (Exception $e) {
            $error = "Error al actualizar el servicio";
        }
    }
}

$page_title = 'Editar Servicio';
include __DIR__ . '/../../../includes/header.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">Editar Servicio</h1>
        
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Nombre *</label>
                <input type="text" name="nombre" required 
                       class="w-full px-3 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                       value="<?php echo htmlspecialchars($servicio['nombre']); ?>">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Descripción</label>
                <textarea name="descripcion" rows="3" 
                          class="w-full px-3 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500"><?php echo htmlspecialchars($servicio['descripcion'] ?? ''); ?></textarea>
            </div>
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Precio *</label>
                    <div class="relative">
                        <span class="absolute left-3 top-2 text-gray-500">$</span>
                        <input type="number" step="0.01" name="precio" required 
                               class="w-full pl-8 pr-3 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                               value="<?php echo $servicio['precio']; ?>">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Duración (minutos)</label>
                    <input type="number" name="duracion_minutos" 
                           class="w-full px-3 py-2 border rounded-lg focus:ring-indigo-500 focus:border-indigo-500"
                           value="<?php echo $servicio['duracion_minutos']; ?>">
                </div>
            </div>
            
            <div class="mb-4">
                <label class="flex items-center p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                    <input type="checkbox" name="precio_variable" class="mr-3" 
                           <?php echo $servicio['precio_variable'] ? 'checked' : ''; ?>>
                    <div>
                        <span class="text-sm font-medium text-gray-700">Precio variable</span>
                        <p class="text-xs text-gray-500">El precio se definirá al momento de completar la reserva</p>
                    </div>
                </label>
            </div>
            
            <div class="mb-6">
                <label class="flex items-center p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                    <input type="checkbox" name="activo" class="mr-3" 
                           <?php echo $servicio['activo'] ? 'checked' : ''; ?>>
                    <div>
                        <span class="text-sm font-medium text-gray-700">Activo</span>
                        <p class="text-xs text-gray-500">Los servicios inactivos no aparecerán en el selector de reservas</p>
                    </div>
                </label>
            </div>
            
            <div class="flex justify-end gap-2 border-t pt-4">
                <a href="index.php" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition-colors">
                    <i class="fas fa-times mr-2"></i>Cancelar
                </a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition-colors">
                    <i class="fas fa-save mr-2"></i>Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>