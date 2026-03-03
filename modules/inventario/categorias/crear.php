<?php
if (session_status() === PHP_SESSION_NONE) session_start(); 
ob_start();
include '../../../includes/header.php';

// Verificar permisos
if ($_SESSION['usuario_rol'] != 'admin') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

$error = null;
$success = null;

// Procesar formulario
if ($_POST) {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $activo = isset($_POST['activo']) ? 1 : 0;

    // Validaciones
    if (empty($nombre)) {
        $error = "El nombre de la categoría es obligatorio.";
    } else {
        try {
            // Insertar la categoría
            $query = "INSERT INTO categorias (nombre, descripcion, activo) VALUES (?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->execute([$nombre, $descripcion, $activo]);
            
            $_SESSION['success'] = "Categoría creada correctamente";
            header('Location: index.php');
            ob_end_flush();
            exit;
            
        } catch (PDOException $e) {
            $error = "Error al crear la categoría: " . $e->getMessage();
        }
    }
}
?>

<div class="max-w-4xl mx-auto">
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Crear Nueva Categoría</h2>
            <p class="text-sm text-gray-600">Completa los datos para crear una nueva categoría</p>
        </div>
        
        <form method="POST" class="p-6 space-y-6">
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <!-- Información básica de la categoría -->
            <div class="bg-gray-50 p-4 rounded-md">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Información Básica</h3>
                
                <div class="grid grid-cols-1 gap-6">
                    <div>
                        <label for="nombre" class="block text-sm font-medium text-gray-700">
                            Nombre de la Categoría *
                        </label>
                        <input type="text" id="nombre" name="nombre" required
                               value="<?php echo isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : ''; ?>"
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label for="descripcion" class="block text-sm font-medium text-gray-700">
                            Descripción
                        </label>
                        <textarea id="descripcion" name="descripcion" rows="3"
                                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo isset($_POST['descripcion']) ? htmlspecialchars($_POST['descripcion']) : ''; ?></textarea>
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" id="activo" name="activo" 
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded" 
                               <?php echo !isset($_POST['activo']) || $_POST['activo'] ? 'checked' : ''; ?>>
                        <label for="activo" class="ml-2 block text-sm text-gray-900">
                            Categoría activa
                        </label>
                    </div>
                </div>
            </div>

            <!-- Botones de acción -->
            <div class="flex justify-end space-x-3 pt-6">
                <a href="index.php" 
                   class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-times mr-2"></i>
                    Cancelar
                </a>
                <button type="submit" 
                        class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-save mr-2"></i>
                    Guardar Categoría
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>