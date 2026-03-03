<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();

// RUTAS CORREGIDAS - con barra después de __DIR__
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/header.php';

// Verificar permisos usando la clase Auth
if (!$auth->hasPermission('productos', 'lectura')) {
    $_SESSION['error'] = "No tienes permisos para ver productos";
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Obtener ID del producto
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    $_SESSION['error'] = "ID de producto no válido";
    header('Location: index.php');
    exit;
}

// Obtener datos del producto
try {
    $query = "SELECT p.*, 
                     c.nombre as categoria_nombre,
                     m.nombre as marca_nombre
              FROM productos p
              LEFT JOIN categorias c ON p.categoria_id = c.id
              LEFT JOIN marcas m ON p.marca_id = m.id
              WHERE p.id = ?";
    $stmt = $db->prepare($query);
    $stmt->execute([$id]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$producto) {
        $_SESSION['error'] = "Producto no encontrado";
        header('Location: index.php');
        exit;
    }
} catch (Exception $e) {
    error_log("Error cargando producto: " . $e->getMessage());
    $_SESSION['error'] = "Error al cargar el producto";
    header('Location: index.php');
    exit;
}

// Obtener atributos dinámicos del producto
$atributos_producto = [];
try {
    $query_atributos = "SELECT pa.*, ta.nombre as tipo_nombre, ta.icono, ta.unidad,
                               va.valor as valor_predefinido
                        FROM producto_atributos pa
                        LEFT JOIN tipos_atributo ta ON pa.tipo_atributo_id = ta.id
                        LEFT JOIN valores_atributo va ON pa.valor_atributo_id = va.id
                        WHERE pa.producto_id = ?
                        ORDER BY ta.nombre";
    $stmt_atributos = $db->prepare($query_atributos);
    $stmt_atributos->execute([$id]);
    $atributos_producto = $stmt_atributos->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error cargando atributos del producto: " . $e->getMessage());
}

// Determinar el tipo de talla para mostrar correctamente (ACTUALIZADO con XXS y XXXXL)
$tipo_talla = 'alfabetica';
$talla_display = 'N/A';
if (!empty($producto['talla'])) {
    $talla_display = htmlspecialchars($producto['talla']);
    // Si es numérico (entre 1 y 50) y no es una letra
    if (is_numeric($producto['talla']) && $producto['talla'] >= 1 && $producto['talla'] <= 50) {
        $tipo_talla = 'numerica';
        $talla_display = 'Talla ' . htmlspecialchars($producto['talla']);
    } else {
        // Es alfabética, mostrar con su descripción (ACTUALIZADO con XXS y XXXXL)
        $descripciones_talla = [
            'XXS' => 'Extra Extra Small',
            'XS' => 'Extra Small',
            'S' => 'Small',
            'M' => 'Medium',
            'L' => 'Large',
            'XL' => 'Extra Large',
            'XXL' => '2X Large',
            'XXXL' => '3X Large',
            'XXXXL' => '4X Large'
        ];
        if (array_key_exists($producto['talla'], $descripciones_talla)) {
            $talla_display = $producto['talla'] . ' - ' . $descripciones_talla[$producto['talla']];
        }
    }
}

// Obtener historial de ventas del producto
$ventas = [];
try {
    $query_ventas = "SELECT 
                        v.id as venta_id,
                        v.numero_factura,
                        v.fecha as fecha_venta,
                        v.total as total_venta,
                        v.estado as estado_venta,
                        v.tipo_venta,
                        c.nombre as cliente_nombre,
                        vd.cantidad,
                        vd.precio_unitario,
                        vd.subtotal
                     FROM venta_detalles vd
                     INNER JOIN ventas v ON vd.venta_id = v.id
                     LEFT JOIN clientes c ON v.cliente_id = c.id
                     WHERE vd.producto_id = ? AND v.anulada = 0
                     ORDER BY v.fecha DESC
                     LIMIT 50";
    $stmt_ventas = $db->prepare($query_ventas);
    $stmt_ventas->execute([$id]);
    $ventas = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error cargando ventas: " . $e->getMessage());
}

// Obtener historial de compras del producto
$compras = [];
try {
    $query_compras = "SELECT 
                        comp.id as compra_id,
                        comp.numero_compra,
                        comp.fecha as fecha_compra,
                        comp.total as total_compra,
                        comp.estado as estado_compra,
                        pv.nombre as proveedor_nombre,
                        cd.cantidad,
                        cd.precio_unitario,
                        cd.subtotal
                     FROM compra_detalles cd
                     INNER JOIN compras comp ON cd.compra_id = comp.id
                     LEFT JOIN proveedores pv ON comp.proveedor_id = pv.id
                     WHERE cd.producto_id = ?
                     ORDER BY comp.fecha DESC
                     LIMIT 50";
    $stmt_compras = $db->prepare($query_compras);
    $stmt_compras->execute([$id]);
    $compras = $stmt_compras->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error cargando compras: " . $e->getMessage());
}

// Calcular estadísticas
$total_vendido = array_sum(array_column($ventas, 'cantidad'));
$total_ventas_valor = array_sum(array_column($ventas, 'subtotal'));
$total_comprado = array_sum(array_column($compras, 'cantidad'));
$total_compras_valor = array_sum(array_column($compras, 'subtotal'));

$es_servicio = ($producto['es_servicio'] ?? 0) == 1;
$page_title = "Detalle del Producto - " . htmlspecialchars($producto['nombre']);
?>

<style>
.tab-button {
    transition: all 0.2s ease;
    padding: 1rem 1.5rem;
    font-size: 0.875rem;
    font-weight: 500;
    border-bottom: 2px solid transparent;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.tab-button:hover {
    background-color: #f9fafb;
}

.tab-button.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
    font-weight: 600;
}

.tab-content {
    transition: opacity 0.2s ease;
}

.hidden {
    display: none;
}

table {
    border-collapse: separate;
    border-spacing: 0;
    width: 100%;
}

th {
    position: sticky;
    top: 0;
    background-color: #f9fafb;
    z-index: 10;
}

.atributo-item {
    background-color: #f9fafb;
    border-radius: 0.5rem;
    padding: 0.75rem;
    border-left: 3px solid #6366f1;
    transition: all 0.2s ease;
}

.atributo-item:hover {
    background-color: #f3f4f6;
    transform: translateX(5px);
}

.badge-servicio {
    background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
    color: white;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}
</style>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
        <div>
            <div class="flex items-center gap-3">
                <h1 class="text-3xl font-bold text-gray-900">
                    <i class="fas fa-box text-blue-600 mr-2"></i>
                    <?php echo htmlspecialchars($producto['nombre']); ?>
                </h1>
                <?php if ($es_servicio): ?>
                <span class="badge-servicio">
                    <i class="fas fa-concierge-bell"></i> Servicio
                </span>
                <?php endif; ?>
            </div>
            <p class="text-gray-600 mt-1">
                Código: <span class="font-mono bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($producto['codigo']); ?></span>
                <?php if (!empty($producto['codigo_barras'])): ?>
                    | Código Barras: <span class="font-mono bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($producto['codigo_barras']); ?></span>
                <?php endif; ?>
            </p>
        </div>
        <div class="flex space-x-3">
            <?php if ($auth->hasPermission('productos', 'editar')): ?>
            <a href="editar.php?id=<?php echo $id; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-edit mr-2"></i>
                Editar Producto
            </a>
            <?php endif; ?>
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver
            </a>
        </div>
    </div>

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

    <!-- Estadísticas rápidas -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-blue-100 text-blue-600">
                    <i class="fas fa-box"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Stock Actual</p>
                    <p class="text-2xl font-bold <?php echo !$es_servicio && $producto['stock'] <= $producto['stock_minimo'] ? 'text-red-600' : 'text-gray-900'; ?>">
                        <?php echo $es_servicio ? 'N/A' : $producto['stock']; ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-yellow-100 text-yellow-600">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Stock Mínimo</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $es_servicio ? 'N/A' : $producto['stock_minimo']; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-green-100 text-green-600">
                    <i class="fas fa-shopping-cart"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Total Vendido</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $total_vendido; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-purple-100 text-purple-600">
                    <i class="fas fa-truck"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Total Comprado</p>
                    <p class="text-2xl font-bold text-gray-900"><?php echo $total_comprado; ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full <?php echo ($producto['stock'] > 0) ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Estado</p>
                    <p class="text-xl font-bold <?php echo $producto['activo'] ? 'text-green-600' : 'text-red-600'; ?>">
                        <?php echo $producto['activo'] ? 'Activo' : 'Inactivo'; ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Pestañas (Tabs) -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <!-- Encabezado de pestañas -->
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px" aria-label="Tabs">
                <button onclick="showTab('info')" id="tab-info-btn" class="tab-button active">
                    <i class="fas fa-info-circle"></i>
                    Información del Producto
                </button>
                <button onclick="showTab('ventas')" id="tab-ventas-btn" class="tab-button">
                    <i class="fas fa-shopping-cart"></i>
                    Historial de Ventas 
                    <span class="ml-2 bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs"><?php echo count($ventas); ?></span>
                </button>
                <button onclick="showTab('compras')" id="tab-compras-btn" class="tab-button">
                    <i class="fas fa-truck"></i>
                    Historial de Compras 
                    <span class="ml-2 bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full text-xs"><?php echo count($compras); ?></span>
                </button>
            </nav>
        </div>

        <!-- Contenido de pestañas -->
        <div class="p-6">
            <!-- TAB 1: Información del Producto -->
            <div id="tab-info" class="tab-content">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Columna 1: Información básica -->
                    <div class="lg:col-span-1">
                        <div class="bg-gray-50 rounded-lg p-4 h-full">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                <i class="fas fa-tag text-blue-600 mr-2"></i>
                                Información Básica
                            </h3>
                            <dl class="space-y-3">
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Código Interno:</dt>
                                    <dd class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($producto['codigo']); ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Código Barras:</dt>
                                    <dd class="text-sm font-medium text-gray-900">
                                        <?php echo !empty($producto['codigo_barras']) ? htmlspecialchars($producto['codigo_barras']) : 'N/A'; ?>
                                    </dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Categoría:</dt>
                                    <dd class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($producto['categoria_nombre'] ?? 'Sin categoría'); ?>
                                    </dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Marca:</dt>
                                    <dd class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($producto['marca_nombre'] ?? 'Sin marca'); ?>
                                    </dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Tipo:</dt>
                                    <dd class="text-sm">
                                        <?php if ($es_servicio): ?>
                                            <span class="badge-servicio">
                                                <i class="fas fa-concierge-bell"></i> Servicio
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs">Producto</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Estado:</dt>
                                    <dd class="text-sm">
                                        <span class="px-2 py-1 rounded-full text-xs <?php echo $producto['activo'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo $producto['activo'] ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    </dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Creado:</dt>
                                    <dd class="text-sm text-gray-900"><?php echo date('d/m/Y H:i', strtotime($producto['created_at'] ?? 'now')); ?></dd>
                                </div>
                                <?php if (!empty($producto['updated_at']) && $producto['updated_at'] != $producto['created_at']): ?>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Actualizado:</dt>
                                    <dd class="text-sm text-gray-900"><?php echo date('d/m/Y H:i', strtotime($producto['updated_at'])); ?></dd>
                                </div>
                                <?php endif; ?>
                            </dl>
                        </div>
                    </div>

                    <!-- Columna 2: Precios -->
                    <div class="lg:col-span-1">
                        <div class="bg-gray-50 rounded-lg p-4 h-full">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                <i class="fas fa-dollar-sign text-green-600 mr-2"></i>
                                Precios y Márgenes
                            </h3>
                            <?php 
                            $margen = $producto['precio_venta'] - $producto['precio_compra'];
                            $porcentaje_margen = $producto['precio_compra'] > 0 ? ($margen / $producto['precio_compra']) * 100 : 0;
                            ?>
                            <dl class="space-y-3">
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Precio de Compra:</dt>
                                    <dd class="text-lg font-bold text-gray-900">$ <?php echo number_format($producto['precio_compra'], 0, ',', '.'); ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Precio de Venta:</dt>
                                    <dd class="text-lg font-bold text-green-600">$ <?php echo number_format($producto['precio_venta'], 0, ',', '.'); ?></dd>
                                </div>
                                <?php if (!$es_servicio): ?>
                                <div class="border-t border-gray-200 my-2"></div>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Margen Bruto:</dt>
                                    <dd class="text-sm font-medium <?php echo $margen >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                        $ <?php echo number_format($margen, 0, ',', '.'); ?>
                                    </dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-sm text-gray-500">Margen Porcentaje:</dt>
                                    <dd class="text-sm font-medium <?php echo $porcentaje_margen >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                        <?php echo number_format($porcentaje_margen, 1); ?>%
                                    </dd>
                                </div>
                                <?php endif; ?>
                            </dl>
                        </div>
                    </div>

                    <!-- Columna 3: Atributos Básicos (Talla y Color) -->
                    <div class="lg:col-span-1">
                        <div class="bg-gray-50 rounded-lg p-4 h-full">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">
                                <i class="fas fa-palette text-purple-600 mr-2"></i>
                                Atributos Básicos
                            </h3>
                            <dl class="space-y-3">
                                <div class="flex justify-between items-start">
                                    <dt class="text-sm text-gray-500">Talla:</dt>
                                    <dd class="text-sm font-medium text-gray-900 text-right">
                                        <?php if (!empty($producto['talla'])): ?>
                                            <?php if ($tipo_talla == 'numerica'): ?>
                                                <span class="inline-flex items-center bg-blue-100 text-blue-800 px-2 py-1 rounded">
                                                    <i class="fas fa-hashtag mr-1 text-xs"></i>
                                                    <?php echo $talla_display; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center bg-purple-100 text-purple-800 px-2 py-1 rounded">
                                                    <?php echo $talla_display; ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400"><?php echo $es_servicio ? 'N/A' : 'Sin talla'; ?></span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div class="flex justify-between items-start">
                                    <dt class="text-sm text-gray-500">Color:</dt>
                                    <dd class="text-sm font-medium text-gray-900 text-right">
                                        <?php if (!empty($producto['color'])): ?>
                                            <span class="inline-flex items-center">
                                                <span class="w-4 h-4 rounded-full mr-2 inline-block" style="background-color: <?php 
                                                    // Intentar convertir el nombre del color a un color de fondo
                                                    $colores_map = [
                                                        'rojo' => '#EF4444',
                                                        'azul' => '#3B82F6',
                                                        'verde' => '#10B981',
                                                        'amarillo' => '#F59E0B',
                                                        'negro' => '#1F2937',
                                                        'blanco' => '#F9FAFB',
                                                        'gris' => '#6B7280',
                                                        'morado' => '#8B5CF6',
                                                        'rosa' => '#EC4899',
                                                        'naranja' => '#F97316',
                                                        'marron' => '#92400E',
                                                        'beige' => '#F5F5DC',
                                                        'celeste' => '#7DD3FC',
                                                        'turquesa' => '#14B8A6',
                                                        'vino' => '#991B1B',
                                                        'dorado' => '#FBBF24',
                                                        'plateado' => '#E5E7EB',
                                                        'cafe' => '#92400E',
                                                        'crema' => '#FEF3C7',
                                                        'indigo' => '#6366F1'
                                                    ];
                                                    $color_lower = strtolower($producto['color']);
                                                    if (array_key_exists($color_lower, $colores_map)) {
                                                        echo $colores_map[$color_lower];
                                                    } else {
                                                        echo '#CCCCCC';
                                                    }
                                                ?>; border: 1px solid #E5E7EB;"></span>
                                                <?php echo htmlspecialchars($producto['color']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-gray-400"><?php echo $es_servicio ? 'N/A' : 'Sin color'; ?></span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>

                <!-- Atributos Dinámicos Adicionales -->
                <?php if (!empty($atributos_producto)): ?>
                <div class="mt-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">
                        <i class="fas fa-tags text-indigo-600 mr-2"></i>
                        Atributos Adicionales
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($atributos_producto as $atributo): 
                            // Determinar el valor a mostrar
                            $valor_mostrar = $atributo['valor_predefinido'] ?? $atributo['valor_texto'] ?? '';
                            $unidad = $atributo['unidad'] ? ' ' . $atributo['unidad'] : '';
                        ?>
                        <div class="atributo-item">
                            <div class="flex items-start">
                                <div class="flex-shrink-0 mr-3">
                                    <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center text-indigo-600">
                                        <i class="<?php echo $atributo['icono'] ?? 'fas fa-tag'; ?>"></i>
                                    </div>
                                </div>
                                <div class="flex-1">
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($atributo['tipo_nombre']); ?></p>
                                    <p class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($valor_mostrar . $unidad); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Descripción -->
                <?php if (!empty($producto['descripcion'])): ?>
                <div class="mt-6 bg-gray-50 rounded-lg p-4">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">
                        <i class="fas fa-align-left text-blue-600 mr-2"></i>
                        Descripción
                    </h3>
                    <p class="text-gray-700 whitespace-pre-line"><?php echo nl2br(htmlspecialchars($producto['descripcion'])); ?></p>
                </div>
                <?php endif; ?>

                <!-- Código de barras visual -->
                <?php if (!empty($producto['codigo_barras']) && strlen($producto['codigo_barras']) >= 12 && !$es_servicio): ?>
                <div class="mt-6 bg-gray-50 rounded-lg p-4">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">
                        <i class="fas fa-barcode text-blue-600 mr-2"></i>
                        Código de Barras
                    </h3>
                    <div class="flex justify-center p-4 bg-white rounded-lg border border-gray-200">
                        <svg id="barcode"></svg>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- TAB 2: Historial de Ventas -->
            <div id="tab-ventas" class="tab-content hidden">
                <?php if (count($ventas) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Factura</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cliente</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cantidad</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Precio Unit.</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php 
                                $total_cantidad_ventas = 0;
                                $total_subtotal_ventas = 0;
                                foreach ($ventas as $venta): 
                                    $total_cantidad_ventas += $venta['cantidad'];
                                    $total_subtotal_ventas += $venta['subtotal'];
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo date('d/m/Y H:i', strtotime($venta['fecha_venta'])); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <a href="../../ventas/ver.php?id=<?php echo $venta['venta_id']; ?>" class="text-blue-600 hover:text-blue-900 font-medium">
                                            <?php echo htmlspecialchars($venta['numero_factura']); ?>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-900">
                                        <?php echo htmlspecialchars($venta['cliente_nombre'] ?? 'Cliente General'); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo $venta['cantidad']; ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        $ <?php echo number_format($venta['precio_unitario'], 0, ',', '.'); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-green-600">
                                        $ <?php echo number_format($venta['subtotal'], 0, ',', '.'); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs rounded-full <?php echo $venta['estado_venta'] == 'completada' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                            <?php echo ucfirst($venta['estado_venta']); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <a href="../../ventas/ver.php?id=<?php echo $venta['venta_id']; ?>" class="text-blue-600 hover:text-blue-900">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <th colspan="3" class="px-4 py-3 text-right text-sm font-medium text-gray-900">Totales:</th>
                                    <th class="px-4 py-3 text-sm font-bold text-gray-900"><?php echo $total_cantidad_ventas; ?></th>
                                    <th></th>
                                    <th class="px-4 py-3 text-sm font-bold text-green-600">$ <?php echo number_format($total_subtotal_ventas, 0, ',', '.'); ?></th>
                                    <th colspan="2"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-shopping-cart text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No hay ventas registradas</h3>
                        <p class="text-gray-500">Este producto aún no ha sido vendido.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- TAB 3: Historial de Compras -->
            <div id="tab-compras" class="tab-content hidden">
                <?php if (count($compras) > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">N° Compra</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Proveedor</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cantidad</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Precio Unit.</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php 
                                $total_cantidad_compras = 0;
                                $total_subtotal_compras = 0;
                                foreach ($compras as $compra): 
                                    $total_cantidad_compras += $compra['cantidad'];
                                    $total_subtotal_compras += $compra['subtotal'];
                                ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo date('d/m/Y H:i', strtotime($compra['fecha_compra'])); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <a href="../../compras/ver.php?id=<?php echo $compra['compra_id']; ?>" class="text-blue-600 hover:text-blue-900 font-medium">
                                            <?php echo htmlspecialchars($compra['numero_compra'] ?? 'N/A'); ?>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-900">
                                        <?php echo htmlspecialchars($compra['proveedor_nombre'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo $compra['cantidad']; ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        $ <?php echo number_format($compra['precio_unitario'], 0, ',', '.'); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-purple-600">
                                        $ <?php echo number_format($compra['subtotal'], 0, ',', '.'); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="px-2 py-1 text-xs rounded-full bg-green-100 text-green-800">
                                            <?php echo ucfirst($compra['estado_compra'] ?? 'recibida'); ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <a href="../../compras/ver.php?id=<?php echo $compra['compra_id']; ?>" class="text-blue-600 hover:text-blue-900">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <th colspan="3" class="px-4 py-3 text-right text-sm font-medium text-gray-900">Totales:</th>
                                    <th class="px-4 py-3 text-sm font-bold text-gray-900"><?php echo $total_cantidad_compras; ?></th>
                                    <th></th>
                                    <th class="px-4 py-3 text-sm font-bold text-purple-600">$ <?php echo number_format($total_subtotal_compras, 0, ',', '.'); ?></th>
                                    <th colspan="2"></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-truck text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No hay compras registradas</h3>
                        <p class="text-gray-500">Este producto aún no ha sido comprado.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Incluir la librería JsBarcode para mostrar el código de barras -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<script>
// Función para cambiar entre pestañas
function showTab(tabName) {
    // Ocultar todos los contenidos
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.add('hidden');
    });
    
    // Desactivar todos los botones
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active', 'border-blue-500', 'text-blue-600');
        btn.classList.add('border-transparent', 'text-gray-500');
    });
    
    // Mostrar el tab seleccionado
    document.getElementById(`tab-${tabName}`).classList.remove('hidden');
    
    // Activar el botón seleccionado
    const activeBtn = document.getElementById(`tab-${tabName}-btn`);
    activeBtn.classList.add('active', 'border-blue-500', 'text-blue-600');
    activeBtn.classList.remove('border-transparent', 'text-gray-500');
}

// Generar código de barras si existe
document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($producto['codigo_barras']) && strlen($producto['codigo_barras']) >= 12): ?>
    JsBarcode("#barcode", "<?php echo $producto['codigo_barras']; ?>", {
        format: "EAN13",
        width: 2,
        height: 60,
        displayValue: true,
        fontSize: 16,
        margin: 10
    });
    <?php endif; ?>
    
    // Inicializar con la primera pestaña visible
    showTab('info');
});
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>