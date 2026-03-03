<?php
// modules/reservas/servicios/crear.php
require_once __DIR__ . '/../../../includes/config.php';

if (!$auth->hasPermission('reservas', 'crear')) {
    header('HTTP/1.0 403 Forbidden');
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
            $query = "INSERT INTO servicios (nombre, descripcion, precio, precio_variable, duracion_minutos, activo) 
                      VALUES (:nombre, :descripcion, :precio, :precio_variable, :duracion_minutos, :activo)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':descripcion', $descripcion);
            $stmt->bindParam(':precio', $precio);
            $stmt->bindParam(':precio_variable', $precio_variable);
            $stmt->bindParam(':duracion_minutos', $duracion_minutos);
            $stmt->bindParam(':activo', $activo);
            $stmt->execute();
            
            header('Location: index.php?mensaje=Servicio creado correctamente');
            exit;
        } catch (Exception $e) {
            $error = "Error al crear el servicio";
        }
    }
}

$page_title = 'Nuevo Servicio';
include __DIR__ . '/../../../includes/header.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">Nuevo Servicio</h1>
        
        <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Nombre *</label>
                <input type="text" name="nombre" required class="w-full px-3 py-2 border rounded-lg" value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>">
            </div>
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Descripción</label>
                <textarea name="descripcion" rows="3" class="w-full px-3 py-2 border rounded-lg"><?php echo htmlspecialchars($_POST['descripcion'] ?? ''); ?></textarea>
            </div>
            
            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Precio *</label>
                    <input type="number" step="0.01" name="precio" required class="w-full px-3 py-2 border rounded-lg" value="<?php echo $_POST['precio'] ?? 0; ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Duración (minutos)</label>
                    <input type="number" name="duracion_minutos" class="w-full px-3 py-2 border rounded-lg" value="<?php echo $_POST['duracion_minutos'] ?? 30; ?>">
                </div>
            </div>
            
            <div class="mb-4">
                <label class="flex items-center">
                    <input type="checkbox" name="precio_variable" class="mr-2" <?php echo isset($_POST['precio_variable']) ? 'checked' : ''; ?>>
                    <span class="text-sm text-gray-700">Precio variable (se define al completar la reserva)</span>
                </label>
            </div>
            
            <div class="mb-4">
                <label class="flex items-center">
                    <input type="checkbox" name="activo" class="mr-2" checked>
                    <span class="text-sm text-gray-700">Activo</span>
                </label>
            </div>
            
            <div class="flex justify-end gap-2">
                <a href="index.php" class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">Cancelar</a>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">Guardar</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>