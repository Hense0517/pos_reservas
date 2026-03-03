<?php
// modules/inventario/categorias/editar.php
// Activar errores para depuración (QUITAR EN PRODUCCIÓN)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// RUTA CORREGIDA: 3 niveles hacia arriba
require_once __DIR__ . '/../../../includes/config.php';

// Verificar permisos
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] != 'admin') {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

$error = null;
$success = null;

// Obtener ID de la categoría
$categoria_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($categoria_id <= 0) {
    header('Location: index.php');
    exit;
}

try {
    // Obtener categoría existente
    $query = "SELECT * FROM categorias WHERE id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$categoria_id]);
    $categoria = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$categoria) {
        header('Location: index.php');
        exit;
    }
} catch (Exception $e) {
    die("Error al cargar categoría: " . $e->getMessage());
}

// Procesar formulario de edición
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? '');
    $descripcion = trim($_POST['descripcion'] ?? '');
    $activo = isset($_POST['activo']) ? 1 : 0;

    if (empty($nombre)) {
        $error = "El nombre de la categoría es obligatorio.";
    } else {
        try {
            $query = "UPDATE categorias SET nombre = ?, descripcion = ?, activo = ? WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$nombre, $descripcion, $activo, $categoria_id]);
            
            $_SESSION['success'] = "Categoría actualizada correctamente";
            header('Location: index.php');
            exit;
            
        } catch (Exception $e) {
            $error = "Error al actualizar: " . $e->getMessage();
        }
    }
}

// Incluir header
include __DIR__ . '/../../../includes/header.php';
?>

<div class="max-w-4xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Editar Categoría</h2>
            <p class="text-sm text-gray-600">Modifica los datos de la categoría</p>
        </div>
        
        <form method="POST" class="p-6 space-y-6">
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <div class="bg-gray-50 p-4 rounded-md">
                <div class="grid grid-cols-1 gap-6">
                    <div>
                        <label for="nombre" class="block text-sm font-medium text-gray-700">
                            Nombre de la Categoría *
                        </label>
                        <input type="text" id="nombre" name="nombre" required
                               value="<?php echo htmlspecialchars($categoria['nombre']); ?>"
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>
                    
                    <div>
                        <label for="descripcion" class="block text-sm font-medium text-gray-700">
                            Descripción
                        </label>
                        <textarea id="descripcion" name="descripcion" rows="3"
                                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($categoria['descripcion']); ?></textarea>
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" id="activo" name="activo" 
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded" 
                               <?php echo $categoria['activo'] ? 'checked' : ''; ?>>
                        <label for="activo" class="ml-2 block text-sm text-gray-900">
                            Categoría activa
                        </label>
                    </div>
                </div>
            </div>

            <div class="flex justify-end space-x-3 pt-6">
                <a href="index.php" 
                   class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancelar
                </a>
                <button type="submit" 
                        class="py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                    Actualizar Categoría
                </button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>