<?php
require_once '../../includes/header.php';

// Obtener lista de clientes
$query = "SELECT * FROM clientes ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="max-w-7xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Gestión de Clientes</h1>
        <a href="crear.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-plus mr-2"></i>
            Nuevo Cliente
        </a>
    </div>

    <!-- Estadísticas -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 bg-blue-100 rounded-lg">
                    <i class="fas fa-users text-blue-600 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Total Clientes</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo count($clientes); ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 bg-green-100 rounded-lg">
                    <i class="fas fa-id-card text-green-600 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Con Cédula</p>
                    <p class="text-2xl font-bold text-gray-900">
                        <?php 
                        $conCedula = array_filter($clientes, function($c) { 
                            return $c['tipo_documento'] === 'CEDULA'; 
                        });
                        echo count($conCedula);
                        ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 bg-purple-100 rounded-lg">
                    <i class="fas fa-phone text-purple-600 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Con Teléfono</p>
                    <p class="text-2xl font-bold text-gray-900">
                        <?php 
                        $conTelefono = array_filter($clientes, function($c) { 
                            return !empty($c['telefono']); 
                        });
                        echo count($conTelefono);
                        ?>
                    </p>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-center">
                <div class="p-3 bg-yellow-100 rounded-lg">
                    <i class="fas fa-envelope text-yellow-600 text-xl"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm font-medium text-gray-600">Con Email</p>
                    <p class="text-2xl font-bold text-gray-900">
                        <?php 
                        $conEmail = array_filter($clientes, function($c) { 
                            return !empty($c['email']); 
                        });
                        echo count($conEmail);
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Búsqueda y Filtros -->
    <div class="bg-white rounded-lg shadow mb-6 p-4">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700">Buscar por nombre</label>
                <input type="text" id="buscarNombre" placeholder="Nombre del cliente..."
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Tipo de documento</label>
                <select id="filtroDocumento" class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
                    <option value="">Todos</option>
                    <option value="CEDULA">Cédula</option>
                    <option value="DNI">DNI</option>
                    <option value="RUC">RUC</option>
                    <option value="PASAPORTE">Pasaporte</option>
                    <option value="TARJETA_IDENTIDAD">Tarjeta de Identidad</option>
                    <option value="CEDULA_EXTRANJERIA">Cédula de Extranjería</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700">Buscar por documento</label>
                <input type="text" id="buscarDocumento" placeholder="Número de documento..."
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2">
            </div>
            <div class="flex items-end">
                <button id="btnBuscar" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md w-full">
                    <i class="fas fa-search mr-2"></i>
                    Buscar
                </button>
            </div>
        </div>
    </div>

    <!-- Tabla de clientes -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cliente</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Documento</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Teléfono</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Dirección</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Registro</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200" id="tablaClientes">
                    <?php if (empty($clientes)): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-4 text-center text-gray-500">
                                No hay clientes registrados
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clientes as $cliente): ?>
                            <tr class="cliente-fila">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10 bg-green-500 rounded-lg flex items-center justify-center">
                                            <span class="text-white font-bold text-sm">
                                                <?php echo strtoupper(substr($cliente['nombre'], 0, 2)); ?>
                                            </span>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900 nombre-cliente">
                                                <?php echo htmlspecialchars($cliente['nombre']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php 
                                                $tipoDoc = $cliente['tipo_documento'];
                                                $nombresDocumentos = [
                                                    'CEDULA' => 'Cédula',
                                                    'DNI' => 'DNI',
                                                    'RUC' => 'RUC',
                                                    'PASAPORTE' => 'Pasaporte',
                                                    'TARJETA_IDENTIDAD' => 'Tarjeta Identidad',
                                                    'CEDULA_EXTRANJERIA' => 'Cédula Extranjería'
                                                ];
                                                echo $nombresDocumentos[$tipoDoc] ?? $tipoDoc;
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 documento-cliente">
                                    <?php echo htmlspecialchars($cliente['numero_documento'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($cliente['telefono'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($cliente['email'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <?php 
                                    $direccion = $cliente['direccion'] ?? '';
                                    echo !empty($direccion) ? htmlspecialchars(substr($direccion, 0, 30) . (strlen($direccion) > 30 ? '...' : '')) : 'N/A'; 
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('d/m/Y', strtotime($cliente['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <a href="ver.php?id=<?php echo $cliente['id']; ?>" 
                                           class="text-blue-600 hover:text-blue-900" title="Ver">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="editar.php?id=<?php echo $cliente['id']; ?>" 
                                           class="text-green-600 hover:text-green-900" title="Editar">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="#" onclick="confirmarEliminacion(<?php echo $cliente['id']; ?>)" 
                                           class="text-red-600 hover:text-red-900" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function confirmarEliminacion(id) {
    if (confirm('¿Estás seguro de que deseas eliminar este cliente?')) {
        window.location.href = 'eliminar.php?id=' + id;
    }
}

// Búsqueda en tiempo real
document.addEventListener('DOMContentLoaded', function() {
    const buscarNombre = document.getElementById('buscarNombre');
    const buscarDocumento = document.getElementById('buscarDocumento');
    const filtroDocumento = document.getElementById('filtroDocumento');
    const btnBuscar = document.getElementById('btnBuscar');
    const filas = document.querySelectorAll('.cliente-fila');

    function filtrarClientes() {
        const textoNombre = buscarNombre.value.toLowerCase();
        const textoDocumento = buscarDocumento.value.toLowerCase();
        const tipoDocumento = filtroDocumento.value;

        filas.forEach(fila => {
            const nombre = fila.querySelector('.nombre-cliente').textContent.toLowerCase();
            const documento = fila.querySelector('.documento-cliente').textContent.toLowerCase();
            const tipoDocFila = fila.querySelector('.nombre-cliente + .text-gray-500').textContent.toLowerCase();

            const coincideNombre = nombre.includes(textoNombre);
            const coincideDocumento = documento.includes(textoDocumento);
            const coincideTipoDoc = !tipoDocumento || tipoDocFila.includes(tipoDocumento.toLowerCase());

            if (coincideNombre && coincideDocumento && coincideTipoDoc) {
                fila.style.display = '';
            } else {
                fila.style.display = 'none';
            }
        });
    }

    btnBuscar.addEventListener('click', filtrarClientes);
    buscarNombre.addEventListener('input', filtrarClientes);
    buscarDocumento.addEventListener('input', filtrarClientes);
    filtroDocumento.addEventListener('change', filtrarClientes);
});
</script>

<?php require_once '../../includes/footer.php'; ?>