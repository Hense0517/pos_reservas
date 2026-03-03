<?php 
// modules/configuracion/index.php

session_start();
require_once '../../config/database.php';

// Verificar si el usuario está autenticado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Obtener la configuración actual
$config = [];
try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    if ($conn) {
        $stmt = $conn->query("SELECT * FROM configuracion_negocio LIMIT 1");
        $config = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } else {
        $error = "Error de conexión a la base de datos";
    }
} catch (Exception $e) {
    $error = "Error al cargar la configuración: " . $e->getMessage();
}

include '../../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <!-- Mostrar mensajes de éxito/error -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Configuración del Negocio</h2>
            <p class="text-sm text-gray-600">Actualiza la información de tu negocio</p>
        </div>
        
        <form action="guardar_configuracion.php" method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="nombre_negocio" class="block text-sm font-medium text-gray-700">Nombre del Negocio *</label>
                    <input type="text" id="nombre_negocio" name="nombre_negocio" 
                           value="<?php echo htmlspecialchars($config['nombre_negocio'] ?? ''); ?>"
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                           required>
                </div>
                
                <div>
                    <label for="ruc" class="block text-sm font-medium text-gray-700">RUC</label>
                    <input type="text" id="ruc" name="ruc" 
                           value="<?php echo htmlspecialchars($config['ruc'] ?? ''); ?>"
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            
            <div>
                <label for="direccion" class="block text-sm font-medium text-gray-700">Dirección</label>
                <textarea id="direccion" name="direccion" rows="3"
                          class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($config['direccion'] ?? ''); ?></textarea>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="telefono" class="block text-sm font-medium text-gray-700">Teléfono</label>
                    <input type="text" id="telefono" name="telefono" 
                           value="<?php echo htmlspecialchars($config['telefono'] ?? ''); ?>"
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                    <input type="email" id="email" name="email" 
                           value="<?php echo htmlspecialchars($config['email'] ?? ''); ?>"
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="moneda" class="block text-sm font-medium text-gray-700">Moneda</label>
                    <select id="moneda" name="moneda" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="USD" <?php echo ($config['moneda'] ?? 'USD') == 'USD' ? 'selected' : ''; ?>>Dólares (USD)</option>
                        <option value="EUR" <?php echo ($config['moneda'] ?? 'USD') == 'EUR' ? 'selected' : ''; ?>>Euros (EUR)</option>
                        <option value="MXN" <?php echo ($config['moneda'] ?? 'USD') == 'MXN' ? 'selected' : ''; ?>>Pesos Mexicanos (MXN)</option>
                        <option value="COP" <?php echo ($config['moneda'] ?? 'USD') == 'COP' ? 'selected' : ''; ?>>Pesos Colombianos (COP)</option>
                    </select>
                </div>
                
                <div>
                    <label for="impuesto" class="block text-sm font-medium text-gray-700">Impuesto (%)</label>
                    <input type="number" id="impuesto" name="impuesto" step="0.01" min="0" max="100"
                           value="<?php echo htmlspecialchars($config['impuesto'] ?? 0); ?>"
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
            </div>
            
            <div>
                <label for="logo" class="block text-sm font-medium text-gray-700">Logo del Negocio</label>
                <input type="file" id="logo" name="logo" 
                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                       accept="image/jpeg,image/png,image/gif,image/webp">
                <p class="mt-1 text-sm text-gray-500">Formatos permitidos: JPEG, PNG, GIF, WebP. Tamaño máximo: 2MB</p>
                
                <?php 
                // MOSTRAR LOGO CON URL DIRECTA - Usando la URL absoluta corregida
                if(isset($config['logo']) && !empty($config['logo'])):
                    $logo_system_path = '../../' . $config['logo'];
                    
                    // URL absoluta corregida
                    $logo_display_url = 'https://www.valentinarojastienda.com.co/pos/' . $config['logo'];
                    
                    if(file_exists($logo_system_path)): 
                ?>
                    <div class="mt-2">
                        <p class="text-sm text-gray-500">Logo actual:</p>
                        <img src="<?php echo $logo_display_url; ?>" 
                             alt="Logo <?php echo htmlspecialchars($config['nombre_negocio'] ?? ''); ?>" 
                             class="h-20 w-20 mt-1 rounded border object-cover bg-gray-100"
                             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                        <div class="text-sm text-red-600 mt-1" style="display: none;">
                            ❌ Error al cargar la imagen. 
                            <br>URL: <?php echo $logo_display_url; ?>
                            <br>Verifica que el archivo .htaccess permita el acceso a imágenes.
                        </div>
                        <p class="text-xs text-gray-500 mt-1">
                            ✅ Archivo encontrado: <?php echo htmlspecialchars($config['logo']); ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="mt-2">
                        <p class="text-sm text-yellow-600">
                            ❌ El archivo del logo no se encuentra en el servidor.
                        </p>
                        <p class="text-xs text-gray-500">
                            Ruta en servidor: <?php echo htmlspecialchars($logo_system_path); ?>
                        </p>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <div class="flex justify-end space-x-3 pt-6">
                <button type="button" onclick="window.history.back()" 
                        class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Cancelar
                </button>
                <button type="submit" 
                        class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>