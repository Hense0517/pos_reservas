<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/header.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$cliente_id = $_GET['id'];

// Obtener datos del cliente
$query = "SELECT * FROM clientes WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$cliente_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    $_SESSION['error'] = "Cliente no encontrado";
    header('Location: index.php');
    exit;
}
?>

<div class="max-w-4xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Editar Cliente</h1>
        <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-arrow-left mr-2"></i>
            Volver
        </a>
    </div>

    <form action="actualizar.php" method="POST" class="bg-white rounded-lg shadow p-6">
        <input type="hidden" name="id" value="<?php echo $cliente['id']; ?>">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Información básica -->
            <div class="md:col-span-2">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Información Personal</h2>
            </div>
            
            <div class="md:col-span-2">
                <label for="nombre" class="block text-sm font-medium text-gray-700">Nombre Completo *</label>
                <input type="text" id="nombre" name="nombre" required
                       value="<?php echo htmlspecialchars($cliente['nombre']); ?>"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label for="tipo_documento" class="block text-sm font-medium text-gray-700">Tipo de Documento *</label>
                <select id="tipo_documento" name="tipo_documento" required
                        class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <option value="CEDULA" <?php echo $cliente['tipo_documento'] === 'CEDULA' ? 'selected' : ''; ?>>Cédula</option>
                    <option value="DNI" <?php echo $cliente['tipo_documento'] === 'DNI' ? 'selected' : ''; ?>>DNI</option>
                    <option value="RUC" <?php echo $cliente['tipo_documento'] === 'RUC' ? 'selected' : ''; ?>>RUC</option>
                    <option value="PASAPORTE" <?php echo $cliente['tipo_documento'] === 'PASAPORTE' ? 'selected' : ''; ?>>Pasaporte</option>
                    <option value="TARJETA_IDENTIDAD" <?php echo $cliente['tipo_documento'] === 'TARJETA_IDENTIDAD' ? 'selected' : ''; ?>>Tarjeta de Identidad</option>
                    <option value="CEDULA_EXTRANJERIA" <?php echo $cliente['tipo_documento'] === 'CEDULA_EXTRANJERIA' ? 'selected' : ''; ?>>Cédula de Extranjería</option>
                </select>
            </div>

            <div>
                <label for="numero_documento" class="block text-sm font-medium text-gray-700">Número de Documento *</label>
                <input type="text" id="numero_documento" name="numero_documento" required
                       value="<?php echo htmlspecialchars($cliente['numero_documento']); ?>"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <!-- Información de contacto -->
            <div class="md:col-span-2">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Información de Contacto</h2>
            </div>

            <div>
                <label for="telefono" class="block text-sm font-medium text-gray-700">Teléfono</label>
                <input type="text" id="telefono" name="telefono"
                       value="<?php echo htmlspecialchars($cliente['telefono'] ?? ''); ?>"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input type="email" id="email" name="email"
                       value="<?php echo htmlspecialchars($cliente['email'] ?? ''); ?>"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
            </div>

            <div class="md:col-span-2">
                <label for="direccion" class="block text-sm font-medium text-gray-700">Dirección</label>
                <textarea id="direccion" name="direccion" rows="3"
                          class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($cliente['direccion'] ?? ''); ?></textarea>
            </div>
        </div>

        <div class="flex justify-end space-x-3 mt-8 pt-6 border-t">
            <a href="index.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Cancelar
            </a>
            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <i class="fas fa-save mr-2"></i>
                Actualizar Cliente
            </button>
        </div>
    </form>
</div>

<script>
// Validación de formato según tipo de documento
document.getElementById('tipo_documento').addEventListener('change', function() {
    const tipoDocumento = this.value;
    const inputDocumento = document.getElementById('numero_documento');
    
    switch(tipoDocumento) {
        case 'CEDULA':
            inputDocumento.placeholder = 'Ej: 12345678';
            break;
        case 'DNI':
            inputDocumento.placeholder = 'Ej: 87654321';
            break;
        case 'RUC':
            inputDocumento.placeholder = 'Ej: 12345678901';
            break;
        case 'PASAPORTE':
            inputDocumento.placeholder = 'Ej: AB123456';
            break;
        case 'TARJETA_IDENTIDAD':
            inputDocumento.placeholder = 'Ej: TI12345678';
            break;
        case 'CEDULA_EXTRANJERIA':
            inputDocumento.placeholder = 'Ej: CE12345678';
            break;
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>