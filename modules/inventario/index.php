<?php 
include '../../includes/header.php';

// Verificar permisos
if (!$auth->hasPermission('inventario', 'lectura')) {
    header("Location: ../../index.php");
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();
?>

<div class="max-w-7xl mx-auto">
    <!-- Header compacto -->
    <div class="mb-4">
        <div class="flex justify-between items-center">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Inventario</h1>
            </div>
        </div>
    </div>

    <!-- Barra superior de acciones rápidas CON COLORES -->
    <div class="mb-4">
        <div class="flex flex-wrap items-center gap-2">
            <a href="productos/" 
               class="inline-flex items-center px-3 py-1.5 bg-blue-100 text-blue-700 font-medium rounded-lg hover:bg-blue-200 transition-colors text-sm">
                <i class="fas fa-boxes mr-1.5"></i>
                Productos
            </a>
            
            <a href="categorias/" 
               class="inline-flex items-center px-3 py-1.5 bg-green-100 text-green-700 font-medium rounded-lg hover:bg-green-200 transition-colors text-sm">
                <i class="fas fa-tags mr-1.5"></i>
                Categorías
            </a>
            
            <!-- Botón para Marcas -->
            <a href="marcas/" 
               class="inline-flex items-center px-3 py-1.5 bg-cyan-100 text-cyan-700 font-medium rounded-lg hover:bg-cyan-200 transition-colors text-sm">
                <i class="fas fa-trademark mr-1.5"></i>
                Marcas
            </a>
            
            <a href="productos/crear.php" 
               class="inline-flex items-center px-3 py-1.5 bg-indigo-100 text-indigo-700 font-medium rounded-lg hover:bg-indigo-200 transition-colors text-sm">
                <i class="fas fa-plus mr-1.5"></i>
                Nuevo Producto
            </a>
            
            <a href="productos/escaner.php" 
               class="inline-flex items-center px-3 py-1.5 bg-amber-100 text-amber-700 font-medium rounded-lg hover:bg-amber-200 transition-colors text-sm">
                <i class="fas fa-search mr-1.5"></i>
                Buscar
            </a>
            
            <a href="etiquetas/" 
               class="inline-flex items-center px-3 py-1.5 bg-purple-600 text-white font-medium rounded-lg hover:bg-purple-700 transition-colors text-sm">
                <i class="fas fa-barcode mr-1.5"></i>
                Generar Etiquetas
            </a>
        </div>
    </div>

    <?php
    // Consultas para las métricas
    $query_total_productos = "SELECT COUNT(*) as total FROM productos WHERE activo = 1";
    $stmt = $db->prepare($query_total_productos);
    $stmt->execute();
    $total_productos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $query_total_categorias = "SELECT COUNT(*) as total FROM categorias WHERE activo = 1";
    $stmt = $db->prepare($query_total_categorias);
    $stmt->execute();
    $total_categorias = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Contar marcas activas (si existe la tabla)
    $total_marcas = 0;
    try {
        $query_total_marcas = "SELECT COUNT(*) as total FROM marcas WHERE activo = 1";
        $stmt = $db->prepare($query_total_marcas);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_marcas = $result['total'] ?? 0;
    } catch (Exception $e) {
        // La tabla marcas puede no existir aún
        $total_marcas = 0;
    }

    $query_stock_bajo = "SELECT COUNT(*) as total FROM productos WHERE stock <= stock_minimo AND stock > 0 AND activo = 1";
    $stmt = $db->prepare($query_stock_bajo);
    $stmt->execute();
    $stock_bajo = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $query_stock_agotado = "SELECT COUNT(*) as total FROM productos WHERE stock = 0 AND activo = 1";
    $stmt = $db->prepare($query_stock_agotado);
    $stmt->execute();
    $stock_agotado = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // VALOR DEL INVENTARIO AL COSTO
    $query_valor_inventario = "SELECT SUM(stock * precio_compra) as valor_total FROM productos WHERE activo = 1";
    $stmt = $db->prepare($query_valor_inventario);
    $stmt->execute();
    $valor_inventario = $stmt->fetch(PDO::FETCH_ASSOC)['valor_total'] ?? 0;

    // VALOR DEL INVENTARIO AL PRECIO DE VENTA
    $query_valor_venta = "SELECT SUM(stock * precio_venta) as valor_venta_total FROM productos WHERE activo = 1";
    $stmt = $db->prepare($query_valor_venta);
    $stmt->execute();
    $valor_venta = $stmt->fetch(PDO::FETCH_ASSOC)['valor_venta_total'] ?? 0;

    // UTILIDAD POTENCIAL
    $utilidad_potencial = $valor_venta - $valor_inventario;
    $porcentaje_utilidad = $valor_inventario > 0 ? (($utilidad_potencial / $valor_inventario) * 100) : 0;

    $query_productos_sin_categoria = "SELECT COUNT(*) as total FROM productos WHERE categoria_id IS NULL AND activo = 1";
    $stmt = $db->prepare($query_productos_sin_categoria);
    $stmt->execute();
    $sin_categoria = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    ?>

    <!-- Tarjetas de Métricas Compactas CON COLORES -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <!-- Total Productos -->
        <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-lg border border-blue-200 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-blue-600 font-semibold uppercase tracking-wide">PRODUCTOS</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $total_productos; ?></p>
                    <div class="flex items-center mt-2">
                        <span class="text-xs text-blue-500 font-medium"><?php echo $total_categorias; ?> categorías</span>
                    </div>
                </div>
                <div class="text-blue-500">
                    <i class="fas fa-boxes text-xl"></i>
                </div>
            </div>
        </div>

        <!-- Total Marcas -->
        <div class="bg-gradient-to-br from-cyan-50 to-cyan-100 rounded-lg border border-cyan-200 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-cyan-600 font-semibold uppercase tracking-wide">MARCAS</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1"><?php echo $total_marcas; ?></p>
                    <div class="flex items-center mt-2">
                        <a href="marcas/" class="text-xs text-cyan-600 hover:text-cyan-800 font-medium">
                            Gestionar marcas
                        </a>
                    </div>
                </div>
                <div class="text-cyan-500">
                    <i class="fas fa-trademark text-xl"></i>
                </div>
            </div>
        </div>

        <!-- Valor del Inventario AL PRECIO DE VENTA -->
        <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-lg border border-emerald-200 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-emerald-600 font-semibold uppercase tracking-wide">VENTA</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">
                        <?php echo $config['moneda_simbolo'] ?? '$'; ?><?php echo number_format($valor_venta, 0); ?>
                    </p>
                    <p class="text-xs text-emerald-500 font-medium mt-2">Valor de venta</p>
                </div>
                <div class="text-emerald-500">
                    <i class="fas fa-dollar-sign text-xl"></i>
                </div>
            </div>
        </div>

        <!-- Utilidad Potencial -->
        <div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-lg border border-purple-200 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-xs text-purple-600 font-semibold uppercase tracking-wide">UTILIDAD</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">
                        <?php echo $config['moneda_simbolo'] ?? '$'; ?><?php echo number_format($utilidad_potencial, 0); ?>
                    </p>
                    <div class="flex items-center mt-2">
                        <span class="text-xs <?php echo $porcentaje_utilidad >= 0 ? 'text-green-600' : 'text-red-600'; ?> font-medium">
                            <?php echo number_format($porcentaje_utilidad, 1); ?>%
                        </span>
                    </div>
                </div>
                <div class="text-purple-500">
                    <i class="fas fa-chart-line text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Acceso rápido a Marcas y Categorías -->
    <div class="mb-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Tarjeta de Acceso a Marcas -->
            <a href="marcas/" class="bg-gradient-to-br from-cyan-50 to-white rounded-lg border border-cyan-200 p-4 hover:border-cyan-300 hover:shadow-md transition-all duration-200">
                <div class="flex items-center mb-3">
                    <div class="bg-cyan-100 p-2 rounded-lg mr-3">
                        <i class="fas fa-trademark text-cyan-600 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900">Gestión de Marcas</h3>
                        <p class="text-xs text-gray-600 mt-0.5">Administra las marcas de tus productos</p>
                    </div>
                    <div class="ml-auto text-cyan-500">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                </div>
                
                <div class="mt-3 pt-3 border-t border-cyan-100">
                    <div class="flex justify-between items-center">
                        <div class="text-sm text-gray-700">
                            <span class="font-medium"><?php echo $total_marcas; ?></span> marcas activas
                        </div>
                        <span class="text-xs text-cyan-600 font-medium">Ver todas →</span>
                    </div>
                </div>
            </a>
            
            <!-- Tarjeta de Acceso a Categorías -->
            <a href="categorias/" class="bg-gradient-to-br from-green-50 to-white rounded-lg border border-green-200 p-4 hover:border-green-300 hover:shadow-md transition-all duration-200">
                <div class="flex items-center mb-3">
                    <div class="bg-green-100 p-2 rounded-lg mr-3">
                        <i class="fas fa-tags text-green-600 text-lg"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900">Gestión de Categorías</h3>
                        <p class="text-xs text-gray-600 mt-0.5">Organiza tus productos por categorías</p>
                    </div>
                    <div class="ml-auto text-green-500">
                        <i class="fas fa-arrow-right"></i>
                    </div>
                </div>
                
                <div class="mt-3 pt-3 border-t border-green-100">
                    <div class="flex justify-between items-center">
                        <div class="text-sm text-gray-700">
                            <span class="font-medium"><?php echo $total_categorias; ?></span> categorías activas
                        </div>
                        <span class="text-xs text-green-600 font-medium">Ver todas →</span>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Contenido Principal -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Productos por Categoría -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="px-4 py-3 border-b border-gray-200 bg-gradient-to-r from-blue-50 to-white">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-chart-pie mr-2 text-blue-500"></i>
                        Distribución por Categoría
                    </h2>
                    <a href="categorias/" class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                        Ver todas →
                    </a>
                </div>
            </div>
            <div class="p-4">
                <?php
                $query_categorias_dist = "SELECT c.nombre, COUNT(p.id) as total_productos
                                         FROM categorias c 
                                         LEFT JOIN productos p ON c.id = p.categoria_id AND p.activo = 1
                                         WHERE c.activo = 1
                                         GROUP BY c.id, c.nombre
                                         HAVING COUNT(p.id) > 0
                                         ORDER BY total_productos DESC 
                                         LIMIT 6";
                $stmt = $db->prepare($query_categorias_dist);
                $stmt->execute();
                $categorias_dist = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <?php if(count($categorias_dist) > 0): ?>
                    <div class="space-y-3">
                        <?php foreach ($categorias_dist as $categoria): 
                            $porcentaje = $total_productos > 0 ? ($categoria['total_productos'] / $total_productos) * 100 : 0;
                        ?>
                        <div class="flex items-center justify-between">
                            <div class="flex-1">
                                <div class="flex justify-between text-xs mb-1">
                                    <span class="font-medium text-gray-900"><?php echo $categoria['nombre']; ?></span>
                                    <span class="text-blue-600 font-semibold"><?php echo $categoria['total_productos']; ?></span>
                                </div>
                                <div class="w-full bg-blue-50 rounded-full h-2">
                                    <div class="bg-blue-500 h-2 rounded-full" 
                                         style="width: <?php echo $porcentaje; ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-6">
                        <i class="fas fa-chart-pie text-gray-300 text-xl mb-2"></i>
                        <p class="text-xs text-gray-600">No hay datos de categorías</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Alertas de Stock Crítico -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="px-4 py-3 border-b border-gray-200 bg-gradient-to-r from-rose-50 to-white">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                        <i class="fas fa-exclamation-triangle mr-2 text-rose-500"></i>
                        Stock Crítico
                    </h2>
                    <a href="productos/?filtro=stock_bajo" class="text-xs text-rose-600 hover:text-rose-800 font-medium">
                        Ver todos →
                    </a>
                </div>
            </div>
            <div class="p-4">
                <?php
                $query_stock_critico = "SELECT p.*, c.nombre as categoria_nombre 
                                      FROM productos p 
                                      LEFT JOIN categorias c ON p.categoria_id = c.id 
                                      WHERE (p.stock <= p.stock_minimo OR p.stock = 0) AND p.activo = 1 
                                      ORDER BY p.stock ASC 
                                      LIMIT 5";
                $stmt = $db->prepare($query_stock_critico);
    $stmt->execute();
                $stock_critico = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>

                <?php if (count($stock_critico) > 0): ?>
                    <div class="space-y-2">
                        <?php foreach ($stock_critico as $producto): 
                            $estado_color = $producto['stock'] == 0 ? 'text-rose-600' : 'text-amber-600';
                            $estado_bg = $producto['stock'] == 0 ? 'bg-rose-50' : 'bg-amber-50';
                        ?>
                        <div class="flex items-center justify-between p-2 <?php echo $estado_bg; ?> rounded-md hover:opacity-90 transition-opacity">
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-medium text-gray-900 truncate">
                                    <?php echo htmlspecialchars($producto['nombre']); ?>
                                </p>
                                <?php if($producto['categoria_nombre']): ?>
                                    <p class="text-xs text-gray-500 mt-0.5"><?php echo $producto['categoria_nombre']; ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center space-x-3 ml-3">
                                <span class="text-xs font-bold px-2 py-0.5 bg-white rounded <?php echo $estado_color; ?>">
                                    <?php echo $producto['stock']; ?>
                                </span>
                                <div class="flex items-center space-x-1">
                                    <a href="productos/editar.php?id=<?php echo $producto['id']; ?>" 
                                       class="text-blue-500 hover:text-blue-700" title="Reabastecer">
                                        <i class="fas fa-edit text-xs"></i>
                                    </a>
                                    <a href="etiquetas/?producto_id=<?php echo $producto['id']; ?>" 
                                       class="text-purple-500 hover:text-purple-700" title="Etiqueta">
                                        <i class="fas fa-tag text-xs"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center py-6">
                        <i class="fas fa-check-circle text-emerald-400 text-xl mb-2"></i>
                        <p class="text-xs text-gray-600">No hay productos con stock crítico</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Últimos productos agregados -->
    <div class="mt-4 bg-white rounded-lg shadow-sm border border-gray-200">
        <div class="px-4 py-3 border-b border-gray-200 bg-gradient-to-r from-emerald-50 to-white">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-gray-900 flex items-center">
                    <i class="fas fa-history mr-2 text-emerald-500"></i>
                    Últimos Productos Agregados
                </h2>
                <a href="productos/" class="text-xs text-emerald-600 hover:text-emerald-800 font-medium">
                    Ver todos →
                </a>
            </div>
        </div>
        <div class="p-4">
            <?php
            $query_ultimos = "SELECT p.*, c.nombre as categoria_nombre 
                            FROM productos p 
                            LEFT JOIN categorias c ON p.categoria_id = c.id 
                            WHERE p.activo = 1 
                            ORDER BY p.id DESC 
                            LIMIT 6";
            $stmt = $db->prepare($query_ultimos);
            $stmt->execute();
            $ultimos_productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php if (count($ultimos_productos) > 0): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                    <?php foreach ($ultimos_productos as $producto): ?>
                    <div class="bg-gradient-to-br from-gray-50 to-white border border-gray-200 rounded-lg p-3 hover:border-blue-300 transition-colors">
                        <div class="flex justify-between items-start mb-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">
                                    <?php echo htmlspecialchars($producto['nombre']); ?>
                                </p>
                                <?php if($producto['categoria_nombre']): ?>
                                    <p class="text-xs text-gray-500 mt-1"><?php echo $producto['categoria_nombre']; ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="ml-2 flex space-x-1">
                                <a href="etiquetas/?producto_id=<?php echo $producto['id']; ?>" 
                                   class="text-purple-500 hover:text-purple-700" title="Generar etiqueta">
                                    <i class="fas fa-tag text-xs"></i>
                                </a>
                                <a href="productos/editar.php?id=<?php echo $producto['id']; ?>" 
                                   class="text-blue-500 hover:text-blue-700" title="Editar">
                                    <i class="fas fa-edit text-xs"></i>
                                </a>
                            </div>
                        </div>
                        <div class="flex justify-between items-center">
                            <div>
                                <span class="text-xs font-medium text-blue-600">
                                    <?php echo $config['moneda_simbolo'] ?? '$'; ?><?php echo number_format($producto['precio_venta'], 2); ?>
                                </span>
                            </div>
                            <div>
                                <span class="text-xs font-medium px-2 py-0.5 <?php echo $producto['stock'] <= $producto['stock_minimo'] ? 'bg-rose-100 text-rose-700' : 'bg-emerald-100 text-emerald-700'; ?> rounded">
                                    Stock: <?php echo $producto['stock']; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-6">
                    <i class="fas fa-box-open text-gray-300 text-xl mb-2"></i>
                    <p class="text-xs text-gray-600">No hay productos registrados</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>