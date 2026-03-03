<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/header.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$proveedor_id = $_GET['id'];

// Obtener datos del proveedor
$query = "SELECT * FROM proveedores WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$proveedor_id]);
$proveedor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$proveedor) {
    $_SESSION['error'] = "Proveedor no encontrado";
    header('Location: index.php');
    exit;
}
?>

<div class="max-w-4xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Editar Proveedor</h1>
        <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-arrow-left mr-2"></i>
            Volver
        </a>
    </div>

    <form action="actualizar.php" method="POST" class="bg-white rounded-lg shadow p-6">
        <input type="hidden" name="id" value="<?php echo $proveedor['id']; ?>">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Información básica -->
            <div class="md:col-span-2">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Información Básica</h2>
            </div>
            
            <div class="md:col-span-2">
                <label for="nombre" class="block text-sm font-medium text-gray-700">Nombre del Proveedor *</label>
                <input type="text" id="nombre" name="nombre" required
                       value="<?php echo htmlspecialchars($proveedor['nombre']); ?>"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label for="ruc" class="block text-sm font-medium text-gray-700">RUC</label>
                <input type="text" id="ruc" name="ruc"
                       value="<?php echo htmlspecialchars($proveedor['ruc'] ?? ''); ?>"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label for="contacto" class="block text-sm font-medium text-gray-700">Persona de Contacto</label>
                <input type="text" id="contacto" name="contacto"
                       value="<?php echo htmlspecialchars($proveedor['contacto'] ?? ''); ?>"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Información de contacto -->
            <div class="md:col-span-2">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Información de Contacto</h2>
            </div>

            <div>
                <label for="telefono" class="block text-sm font-medium text-gray-700">Teléfono</label>
                <input type="text" id="telefono" name="telefono"
                       value="<?php echo htmlspecialchars($proveedor['telefono'] ?? ''); ?>"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input type="email" id="email" name="email"
                       value="<?php echo htmlspecialchars($proveedor['email'] ?? ''); ?>"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div class="md:col-span-2">
                <label for="direccion" class="block text-sm font-medium text-gray-700">Dirección</label>
                <textarea id="direccion" name="direccion" rows="3"
                          class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($proveedor['direccion'] ?? ''); ?></textarea>
            </div>

            <!-- Estado -->
            <div class="md:col-span-2">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Estado</h2>
            </div>

            <div class="md:col-span-2">
                <label for="estado" class="block text-sm font-medium text-gray-700">Estado del Proveedor</label>
                <select id="estado" name="estado"
                        class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <option value="activo" <?php echo ($proveedor['estado'] ?? 'activo') === 'activo' ? 'selected' : ''; ?>>Activo</option>
                    <option value="inactivo" <?php echo ($proveedor['estado'] ?? 'activo') === 'inactivo' ? 'selected' : ''; ?>>Inactivo</option>
                </select>
            </div>
        </div>

        <div class="flex justify-end space-x-3 mt-8 pt-6 border-t">
            <a href="index.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Cancelar
            </a>
            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <i class="fas fa-save mr-2"></i>
                Actualizar Proveedor
            </button>
        </div>
    </form>
</div>

<?php require_once '../../includes/footer.php'; ?>