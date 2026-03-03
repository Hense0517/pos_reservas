<?php
if (session_status() === PHP_SESSION_NONE) session_start(); 
ob_start();
include '../../includes/header.php';

// Verificar permisos
if ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor') {
    header('Location: /sistema_pos/index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

$producto = null;
$codigo_barras = '';

// Buscar producto por código de barras
if (isset($_POST['codigo_barras']) && !empty($_POST['codigo_barras'])) {
    $codigo_barras = $_POST['codigo_barras'];
    $producto = $database->buscarProductoPorCodigoBarras($codigo_barras);
}
?>

<div class="max-w-4xl mx-auto">
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
            <h2 class="text-xl font-semibold text-gray-800">Escáner de Código de Barras</h2>
            <p class="text-sm text-gray-600">Busca productos escaneando su código de barras</p>
        </div>
        
        <div class="p-6">
            <form method="POST" class="mb-6">
                <div class="flex space-x-4">
                    <input type="text" id="codigo_barras" name="codigo_barras" 
                           value="<?php echo htmlspecialchars($codigo_barras); ?>"
                           placeholder="Ingresa o escanea el código de barras"
                           class="flex-1 border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                           autofocus>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-md">
                        <i class="fas fa-search mr-2"></i>Buscar
                    </button>
                </div>
            </form>

            <?php if ($producto): ?>
                <div class="bg-green-50 border border-green-200 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-green-800 mb-4">Producto Encontrado</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h4 class="font-medium text-gray-900">Información del Producto</h4>
                            <dl class="mt-2 space-y-2">
                                <div>
                                    <dt class="text-sm text-gray-500">Código:</dt>
                                    <dd class="text-sm font-medium"><?php echo htmlspecialchars($producto['codigo']); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500">Nombre:</dt>
                                    <dd class="text-sm font-medium"><?php echo htmlspecialchars($producto['nombre']); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500">Precio:</dt>
                                    <dd class="text-sm font-medium text-green-600">$<?php echo number_format($producto['precio_venta'], 2); ?></dd>
                                </div>
                                <div>
                                    <dt class="text-sm text-gray-500">Stock:</dt>
                                    <dd class="text-sm font-medium <?php echo $producto['stock'] <= $producto['stock_minimo'] ? 'text-red-600' : 'text-gray-900'; ?>">
                                        <?php echo $producto['stock']; ?> unidades
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <div class="text-center">
                            <div id="barcode" class="mb-2"></div>
                            <p class="text-xs text-gray-600"><?php echo $producto['codigo_barras']; ?></p>
                        </div>
                    </div>
                    <div class="mt-4 flex space-x-3">
                        <a href="../productos/ver.php?id=<?php echo $producto['id']; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-eye mr-2"></i>Ver Detalles
                        </a>
                        <a href="../productos/editar.php?id=<?php echo $producto['id']; ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-edit mr-2"></i>Editar
                        </a>
                    </div>
                </div>
            <?php elseif (!empty($codigo_barras)): ?>
                <div class="bg-red-50 border border-red-200 rounded-lg p-6 text-center">
                    <i class="fas fa-exclamation-triangle text-red-500 text-4xl mb-4"></i>
                    <h3 class="text-lg font-semibold text-red-800">Producto No Encontrado</h3>
                    <p class="text-red-600 mt-2">No se encontró ningún producto con el código de barras: <?php echo htmlspecialchars($codigo_barras); ?></p>
                    <a href="../productos/crear.php" class="inline-block mt-4 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Crear Nuevo Producto
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script>
<?php if ($producto && !empty($producto['codigo_barras'])): ?>
JsBarcode("#barcode", "<?php echo $producto['codigo_barras']; ?>", {
    format: "EAN13",
    width: 2,
    height: 60,
    displayValue: false
});
<?php endif; ?>

// Focus automático en el campo de código de barras
document.getElementById('codigo_barras').focus();
</script>

<?php include '../../includes/footer.php'; ?>