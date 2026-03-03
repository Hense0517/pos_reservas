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

// Obtener ID de categoría
$categoria_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($categoria_id <= 0) {
    header('Location: index.php');
    exit;
}

// Obtener información de la categoría
$query = "SELECT * FROM categorias WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$categoria_id]);
$categoria = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$categoria) {
    header('Location: index.php');
    exit;
}

// Obtener atributos asignados y disponibles
$atributos_asignados = [];
$atributos_disponibles = [];

try {
    // Atributos ya asignados
    $query = "SELECT a.id, a.nombre, a.tipo, a.unidad_medida, ca.obligatorio 
              FROM categoria_atributos ca
              JOIN atributos a ON ca.atributo_id = a.id
              WHERE ca.categoria_id = ?
              ORDER BY a.nombre";
    $stmt = $db->prepare($query);
    $stmt->execute([$categoria_id]);
    $atributos_asignados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Atributos disponibles (no asignados)
    $query = "SELECT a.* 
              FROM atributos a
              WHERE a.activo = 1 
              AND a.id NOT IN (
                  SELECT atributo_id 
                  FROM categoria_atributos 
                  WHERE categoria_id = ?
              )
              ORDER BY a.nombre";
    $stmt = $db->prepare($query);
    $stmt->execute([$categoria_id]);
    $atributos_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION['error'] = "Error al cargar atributos: " . $e->getMessage();
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'asignar' && isset($_POST['atributo_id'])) {
            $atributo_id = intval($_POST['atributo_id']);
            $obligatorio = isset($_POST['obligatorio']) ? 1 : 0;
            
            $query = "INSERT INTO categoria_atributos (categoria_id, atributo_id, obligatorio) 
                      VALUES (?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->execute([$categoria_id, $atributo_id, $obligatorio]);
            
            $_SESSION['success'] = "Atributo asignado correctamente.";
            
        } elseif ($action === 'actualizar' && isset($_POST['atributo_id'])) {
            $atributo_id = intval($_POST['atributo_id']);
            $obligatorio = isset($_POST['obligatorio']) ? 1 : 0;
            
            $query = "UPDATE categoria_atributos 
                      SET obligatorio = ?
                      WHERE categoria_id = ? AND atributo_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$obligatorio, $categoria_id, $atributo_id]);
            
            $_SESSION['success'] = "Atributo actualizado correctamente.";
            
        } elseif ($action === 'remover' && isset($_POST['atributo_id'])) {
            $atributo_id = intval($_POST['atributo_id']);
            
            // Verificar si hay productos con este atributo antes de remover
            // (esto requiere lógica adicional basada en tu estructura)
            
            $query = "DELETE FROM categoria_atributos 
                      WHERE categoria_id = ? AND atributo_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$categoria_id, $atributo_id]);
            
            $_SESSION['success'] = "Atributo removido correctamente.";
        }
        
        // Recargar la página para ver cambios
        header("Location: atributos.php?id=" . $categoria_id);
        exit;
        
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error al procesar la acción: " . $e->getMessage();
    }
}

$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;

// Limpiar mensajes de sesión
unset($_SESSION['error']);
unset($_SESSION['success']);
?>

<div class="max-w-6xl mx-auto">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Atributos de Categoría</h1>
                <div class="flex items-center mt-2">
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 mr-2">
                        <i class="fas fa-arrow-left"></i> Volver a categorías
                    </a>
                    <span class="text-gray-400 mx-2">•</span>
                    <span class="text-gray-600"><?php echo htmlspecialchars($categoria['nombre']); ?></span>
                </div>
            </div>
            <a href="../atributos/crear.php?return_to=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>" 
               class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-md inline-flex items-center">
                <i class="fas fa-plus mr-2"></i>
                Nuevo Atributo
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if ($error): ?>
        <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            <?php echo $success; ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Columna 1: Atributos asignados -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800">
                    Atributos Asignados
                    <span class="bg-blue-100 text-blue-800 text-xs font-medium ml-2 px-2 py-1 rounded">
                        <?php echo count($atributos_asignados); ?>
                    </span>
                </h2>
            </div>
            
            <div class="p-6">
                <?php if (!empty($atributos_asignados)): ?>
                    <div class="space-y-4">
                        <?php foreach ($atributos_asignados as $atributo): ?>
                            <div class="border border-gray-200 rounded-md p-4 hover:bg-gray-50">
                                <form method="POST" class="flex items-center justify-between">
                                    <input type="hidden" name="action" value="actualizar">
                                    <input type="hidden" name="atributo_id" value="<?php echo $atributo['id']; ?>">
                                    
                                    <div class="flex items-start space-x-3 flex-1">
                                        <div>
                                            <h3 class="font-medium text-gray-900">
                                                <?php echo htmlspecialchars($atributo['nombre']); ?>
                                                <?php if ($atributo['unidad_medida']): ?>
                                                    <span class="text-sm text-gray-500">(<?php echo $atributo['unidad_medida']; ?>)</span>
                                                <?php endif; ?>
                                            </h3>
                                            <p class="text-xs text-gray-500 mt-1">
                                                Tipo: <?php echo htmlspecialchars($atributo['tipo']); ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center space-x-4">
                                        <div class="flex items-center">
                                            <input type="checkbox" 
                                                   id="obligatorio_<?php echo $atributo['id']; ?>" 
                                                   name="obligatorio"
                                                   class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded"
                                                   <?php echo $atributo['obligatorio'] ? 'checked' : ''; ?>
                                                   onchange="this.form.submit()">
                                            <label for="obligatorio_<?php echo $atributo['id']; ?>" 
                                                   class="ml-2 text-sm text-red-600 cursor-pointer">
                                                Obligatorio
                                            </label>
                                        </div>
                                        
                                        <button type="submit" name="action" value="remover" 
                                                class="text-red-600 hover:text-red-800 p-1"
                                                onclick="return confirm('¿Remover este atributo de la categoría?')">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-tag text-gray-300 text-4xl mb-3"></i>
                        <p class="text-gray-500">No hay atributos asignados</p>
                        <p class="text-sm text-gray-400 mt-1">Usa el formulario de la derecha para asignar atributos</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Columna 2: Asignar nuevos atributos -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800">Asignar Nuevos Atributos</h2>
            </div>
            
            <div class="p-6">
                <?php if (!empty($atributos_disponibles)): ?>
                    <div class="space-y-4">
                        <?php foreach ($atributos_disponibles as $atributo): ?>
                            <form method="POST" class="border border-gray-200 rounded-md p-4 hover:bg-gray-50">
                                <input type="hidden" name="action" value="asignar">
                                <input type="hidden" name="atributo_id" value="<?php echo $atributo['id']; ?>">
                                
                                <div class="flex items-start justify-between">
                                    <div class="flex items-start space-x-3">
                                        <div class="flex items-center h-5 mt-1">
                                            <input type="checkbox" 
                                                   id="seleccionar_<?php echo $atributo['id']; ?>"
                                                   name="seleccionar"
                                                   class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                                                   onchange="this.form.submit()">
                                        </div>
                                        <div>
                                            <label for="seleccionar_<?php echo $atributo['id']; ?>" 
                                                   class="font-medium text-gray-900 cursor-pointer">
                                                <?php echo htmlspecialchars($atributo['nombre']); ?>
                                                <?php if ($atributo['unidad_medida']): ?>
                                                    <span class="text-sm text-gray-500">(<?php echo $atributo['unidad_medida']; ?>)</span>
                                                <?php endif; ?>
                                            </label>
                                            <p class="text-xs text-gray-500 mt-1">
                                                Tipo: <?php echo htmlspecialchars($atributo['tipo']); ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-center">
                                        <input type="checkbox" 
                                               id="nuevo_obligatorio_<?php echo $atributo['id']; ?>" 
                                               name="obligatorio"
                                               class="h-4 w-4 text-red-600 focus:ring-red-500 border-gray-300 rounded">
                                        <label for="nuevo_obligatorio_<?php echo $atributo['id']; ?>" 
                                               class="ml-2 text-sm text-red-600 cursor-pointer">
                                            Obligatorio
                                        </label>
                                    </div>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-check-circle text-green-300 text-4xl mb-3"></i>
                        <p class="text-gray-500">Todos los atributos están asignados</p>
                        <a href="../atributos/crear.php" 
                           class="mt-3 inline-flex items-center text-blue-600 hover:text-blue-800">
                            <i class="fas fa-plus mr-1"></i>
                            Crear nuevo atributo
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../../../includes/footer.php'; ?>