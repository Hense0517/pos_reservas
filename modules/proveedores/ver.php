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

// Obtener estadísticas del proveedor
$query_compras = "SELECT 
                    COUNT(*) as total_compras, 
                    SUM(total) as total_comprado,
                    MIN(fecha) as primera_compra,
                    MAX(fecha) as ultima_compra
                  FROM compras 
                  WHERE proveedor_id = ? AND estado != 'cancelada'";
$stmt_compras = $db->prepare($query_compras);
$stmt_compras->execute([$proveedor_id]);
$estadisticas = $stmt_compras->fetch(PDO::FETCH_ASSOC);

// Obtener productos comprados a este proveedor
$query_productos = "SELECT 
                    p.id,
                    p.nombre as producto_nombre,
                    p.codigo,
                    p.codigo_barras,
                    SUM(cd.cantidad) as total_cantidad,
                    AVG(cd.precio) as precio_promedio,
                    SUM(cd.subtotal) as total_comprado,
                    COUNT(DISTINCT c.id) as veces_comprado,
                    MAX(c.fecha) as ultima_compra_fecha,
                    (SELECT stock FROM productos WHERE id = p.id) as stock_actual
                  FROM compra_detalles cd
                  JOIN compras c ON cd.compra_id = c.id
                  JOIN productos p ON cd.producto_id = p.id
                  WHERE c.proveedor_id = ? 
                    AND c.estado != 'cancelada'
                  GROUP BY p.id, p.nombre, p.codigo
                  ORDER BY total_comprado DESC";
$stmt_productos = $db->prepare($query_productos);
$stmt_productos->execute([$proveedor_id]);
$productos = $stmt_productos->fetchAll(PDO::FETCH_ASSOC);

// Obtener compras recientes
$query_compras_recientes = "SELECT 
                            c.id,
                            c.numero_factura,
                            c.fecha,
                            c.total,
                            c.estado,
                            COUNT(cd.id) as total_productos,
                            SUM(cd.cantidad) as total_unidades
                          FROM compras c
                          LEFT JOIN compra_detalles cd ON c.id = cd.compra_id
                          WHERE c.proveedor_id = ?
                          GROUP BY c.id, c.numero_factura, c.fecha, c.total, c.estado
                          ORDER BY c.fecha DESC
                          LIMIT 5";
$stmt_compras_recientes = $db->prepare($query_compras_recientes);
$stmt_compras_recientes->execute([$proveedor_id]);
$compras_recientes = $stmt_compras_recientes->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <!-- Mensajes -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Detalle del Proveedor</h1>
            <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($proveedor['nombre']); ?></p>
        </div>
        <div class="flex flex-wrap gap-2 mt-4 sm:mt-0">
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver al Listado
            </a>
            <a href="editar.php?id=<?php echo $proveedor['id']; ?>" 
               class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-edit mr-2"></i>
                Editar
            </a>
            <a href="../compras/crear.php?proveedor_id=<?php echo $proveedor['id']; ?>" 
               class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-cart-plus mr-2"></i>
                Nueva Compra
            </a>
        </div>
    </div>

    <!-- Grid principal -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Columna izquierda: Información del proveedor -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Tarjeta de información del proveedor -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <!-- Encabezado con avatar -->
                <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-4">
                    <div class="flex items-center">
                        <div class="h-16 w-16 bg-white bg-opacity-20 rounded-lg flex items-center justify-center">
                            <span class="text-white font-bold text-xl">
                                <?php echo strtoupper(substr($proveedor['nombre'], 0, 2)); ?>
                            </span>
                        </div>
                        <div class="ml-4">
                            <h2 class="text-xl font-bold text-white"><?php echo htmlspecialchars($proveedor['nombre']); ?></h2>
                            <p class="text-blue-100">
                                <?php if (!empty($proveedor['ruc'])): ?>
                                    RUC: <?php echo htmlspecialchars($proveedor['ruc']); ?>
                                <?php else: ?>
                                    Sin RUC
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Información detallada -->
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Información de contacto -->
                        <div>
                            <h3 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">
                                <i class="fas fa-address-book mr-2"></i>
                                Información de Contacto
                            </h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="text-sm font-medium text-gray-500">Persona de Contacto</label>
                                    <p class="mt-1 text-gray-900">
                                        <?php echo !empty($proveedor['contacto']) ? htmlspecialchars($proveedor['contacto']) : 
                                            '<span class="text-gray-400">No especificado</span>'; ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500">Teléfono</label>
                                    <p class="mt-1 text-gray-900">
                                        <?php if (!empty($proveedor['telefono'])): ?>
                                            <a href="tel:<?php echo htmlspecialchars($proveedor['telefono']); ?>" 
                                               class="text-blue-600 hover:text-blue-800 hover:underline">
                                                <?php echo htmlspecialchars($proveedor['telefono']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-gray-400">No especificado</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500">Email</label>
                                    <p class="mt-1 text-gray-900">
                                        <?php if (!empty($proveedor['email'])): ?>
                                            <a href="mailto:<?php echo htmlspecialchars($proveedor['email']); ?>" 
                                               class="text-blue-600 hover:text-blue-800 hover:underline">
                                                <?php echo htmlspecialchars($proveedor['email']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-gray-400">No especificado</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Información adicional -->
                        <div>
                            <h3 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">
                                <i class="fas fa-info-circle mr-2"></i>
                                Información Adicional
                            </h3>
                            <div class="space-y-4">
                                <div>
                                    <label class="text-sm font-medium text-gray-500">Dirección</label>
                                    <p class="mt-1 text-gray-900">
                                        <?php echo !empty($proveedor['direccion']) ? nl2br(htmlspecialchars($proveedor['direccion'])) : 
                                            '<span class="text-gray-400">No especificada</span>'; ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500">Estado</label>
                                    <div class="mt-1">
                                        <?php if (($proveedor['estado'] ?? 'activo') === 'activo'): ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                                                <i class="fas fa-check-circle mr-1"></i>
                                                Activo
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-times-circle mr-1"></i>
                                                Inactivo
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500">Fechas</label>
                                    <div class="mt-1 space-y-1">
                                        <p class="text-sm text-gray-600">
                                            <i class="far fa-calendar-plus mr-1"></i>
                                            Creado: <?php echo date('d/m/Y H:i', strtotime($proveedor['created_at'])); ?>
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            <i class="far fa-calendar-check mr-1"></i>
                                            Actualizado: <?php echo date('d/m/Y H:i', strtotime($proveedor['updated_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Productos comprados a este proveedor -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-800">
                            <i class="fas fa-boxes mr-2"></i>
                            Productos Comprados
                        </h3>
                        <span class="text-sm text-gray-600">
                            <?php echo count($productos); ?> producto(s)
                        </span>
                    </div>
                </div>
                
                <?php if (empty($productos)): ?>
                    <div class="p-6 text-center">
                        <i class="fas fa-box-open text-gray-300 text-4xl mb-2"></i>
                        <p class="text-gray-500">No se han registrado compras de productos</p>
                        <p class="text-sm text-gray-400 mt-1">Realice una compra para ver los productos aquí</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Producto
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Código
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Compras
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Cantidad Total
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Precio Prom.
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Total Comprado
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Última Compra
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($productos as $producto): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($producto['producto_nombre']); ?>
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    ID: <?php echo $producto['id']; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo !empty($producto['codigo']) ? htmlspecialchars($producto['codigo']) : 
                                                (!empty($producto['codigo_barras']) ? htmlspecialchars($producto['codigo_barras']) : 'N/A'); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 text-center">
                                            <?php echo $producto['veces_comprado']; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 text-center">
                                            <?php echo number_format($producto['total_cantidad'], 0); ?>
                                            <?php if (isset($producto['stock_actual'])): ?>
                                            <div class="text-xs text-gray-500">
                                                Stock: <?php echo $producto['stock_actual']; ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            $<?php echo number_format($producto['precio_promedio'], 2); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-green-600">
                                            $<?php echo number_format($producto['total_comprado'], 2); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo $producto['ultima_compra_fecha'] ? date('d/m/Y', strtotime($producto['ultima_compra_fecha'])) : 'N/A'; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Totales de productos -->
                    <?php
                    $total_productos = count($productos);
                    $total_cantidad = array_sum(array_column($productos, 'total_cantidad'));
                    $total_comprado_productos = array_sum(array_column($productos, 'total_comprado'));
                    ?>
                    <div class="px-6 py-4 bg-gray-50 border-t">
                        <div class="grid grid-cols-3 gap-4 text-sm">
                            <div class="text-center">
                                <div class="text-gray-500">Productos Diferentes</div>
                                <div class="font-semibold text-gray-900"><?php echo $total_productos; ?></div>
                            </div>
                            <div class="text-center">
                                <div class="text-gray-500">Unidades Totales</div>
                                <div class="font-semibold text-gray-900"><?php echo number_format($total_cantidad, 0); ?></div>
                            </div>
                            <div class="text-center">
                                <div class="text-gray-500">Total Productos</div>
                                <div class="font-semibold text-green-600">$<?php echo number_format($total_comprado_productos, 2); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Compras recientes -->
            <?php if (!empty($compras_recientes)): ?>
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-800">
                            <i class="fas fa-history mr-2"></i>
                            Compras Recientes
                        </h3>
                        <a href="../compras/index.php?proveedor_id=<?php echo $proveedor_id; ?>" 
                           class="text-sm text-blue-600 hover:text-blue-800 hover:underline">
                            Ver todas
                        </a>
                    </div>
                </div>
                <div class="divide-y divide-gray-200">
                    <?php foreach ($compras_recientes as $compra): ?>
                    <div class="px-6 py-4 hover:bg-gray-50">
                        <div class="flex justify-between items-center">
                            <div>
                                <a href="../compras/ver.php?id=<?php echo $compra['id']; ?>" 
                                   class="text-sm font-medium text-blue-600 hover:text-blue-800 hover:underline">
                                    <?php echo htmlspecialchars($compra['numero_factura']); ?>
                                </a>
                                <p class="text-xs text-gray-500 mt-1">
                                    <?php echo date('d/m/Y H:i', strtotime($compra['fecha'])); ?>
                                    • <?php echo $compra['total_productos']; ?> producto(s)
                                    • <?php echo $compra['total_unidades']; ?> unidad(es)
                                </p>
                            </div>
                            <div class="text-right">
                                <div class="text-sm font-semibold text-gray-900">
                                    $<?php echo number_format($compra['total'], 2); ?>
                                </div>
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium 
                                    <?php echo $compra['estado'] === 'recibida' ? 'bg-green-100 text-green-800' : 
                                           ($compra['estado'] === 'pendiente' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800'); ?>">
                                    <?php echo ucfirst($compra['estado']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Columna derecha: Estadísticas y acciones -->
        <div class="space-y-6">
            <!-- Estadísticas -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">
                    <i class="fas fa-chart-bar mr-2"></i>
                    Estadísticas
                </h3>
                <div class="space-y-4">
                    <div class="text-center p-4 bg-blue-50 rounded-lg">
                        <div class="text-3xl font-bold text-blue-600"><?php echo $estadisticas['total_compras'] ?? 0; ?></div>
                        <div class="text-sm text-blue-500 mt-1">Compras Totales</div>
                    </div>
                    <div class="text-center p-4 bg-green-50 rounded-lg">
                        <div class="text-3xl font-bold text-green-600">
                            $<?php echo number_format($estadisticas['total_comprado'] ?? 0, 2); ?>
                        </div>
                        <div class="text-sm text-green-500 mt-1">Total Comprado</div>
                    </div>
                    <?php if ($estadisticas['primera_compra']): ?>
                    <div class="text-center p-4 bg-purple-50 rounded-lg">
                        <div class="text-sm font-medium text-purple-600">Primera Compra</div>
                        <div class="text-lg font-semibold text-purple-800 mt-1">
                            <?php echo date('d/m/Y', strtotime($estadisticas['primera_compra'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($estadisticas['ultima_compra']): ?>
                    <div class="text-center p-4 bg-yellow-50 rounded-lg">
                        <div class="text-sm font-medium text-yellow-600">Última Compra</div>
                        <div class="text-lg font-semibold text-yellow-800 mt-1">
                            <?php echo date('d/m/Y', strtotime($estadisticas['ultima_compra'])); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Acciones rápidas -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">
                    <i class="fas fa-bolt mr-2"></i>
                    Acciones Rápidas
                </h3>
                <div class="space-y-3">
                    <a href="../compras/crear.php?proveedor_id=<?php echo $proveedor['id']; ?>" 
                       class="w-full bg-green-600 hover:bg-green-700 text-white py-3 px-4 rounded-lg flex items-center justify-center transition duration-300">
                        <i class="fas fa-cart-plus mr-2"></i>
                        Nueva Compra
                    </a>
                    <a href="editar.php?id=<?php echo $proveedor['id']; ?>" 
                       class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3 px-4 rounded-lg flex items-center justify-center transition duration-300">
                        <i class="fas fa-edit mr-2"></i>
                        Editar Proveedor
                    </a>
                    <a href="../compras/index.php?proveedor_id=<?php echo $proveedor['id']; ?>" 
                       class="w-full bg-purple-600 hover:bg-purple-700 text-white py-3 px-4 rounded-lg flex items-center justify-center transition duration-300">
                        <i class="fas fa-list mr-2"></i>
                        Ver Todas las Compras
                    </a>
                </div>
            </div>

            <!-- Información adicional -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-lg font-semibold mb-4 text-gray-800 border-b pb-2">
                    <i class="fas fa-info-circle mr-2"></i>
                    Información del Proveedor
                </h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">ID:</span>
                        <span class="font-medium">#<?php echo $proveedor['id']; ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Estado:</span>
                        <span>
                            <?php if (($proveedor['estado'] ?? 'activo') === 'activo'): ?>
                                <span class="text-green-600 font-medium">Activo</span>
                            <?php else: ?>
                                <span class="text-red-600 font-medium">Inactivo</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Creado:</span>
                        <span><?php echo date('d/m/Y', strtotime($proveedor['created_at'])); ?></span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Actualizado:</span>
                        <span><?php echo date('d/m/Y', strtotime($proveedor['updated_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Funcionalidades JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Copiar información del proveedor
    const copyButtons = document.querySelectorAll('.copy-btn');
    copyButtons.forEach(button => {
        button.addEventListener('click', function() {
            const textToCopy = this.getAttribute('data-text');
            navigator.clipboard.writeText(textToCopy).then(() => {
                alert('Copiado al portapapeles: ' + textToCopy);
            });
        });
    });
    
    // Confirmar antes de desactivar proveedor
    const estadoSelect = document.querySelector('select[name="estado"]');
    if (estadoSelect) {
        estadoSelect.addEventListener('change', function() {
            if (this.value === 'inactivo') {
                if (!confirm('¿Está seguro de desactivar este proveedor?\n\nLos proveedores inactivos no aparecerán en las listas de selección.')) {
                    this.value = 'activo';
                }
            }
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>