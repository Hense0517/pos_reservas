<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();

// Incluir config para tener acceso a $auth
$config_path = '../../../includes/config.php';
if (!file_exists($config_path)) {
    die("Error: No se encuentra config.php en $config_path");
}
include $config_path;

// Incluir header
$header_path = '../../../includes/header.php';
if (!file_exists($header_path)) {
    die("Error: No se encuentra header.php en $config_path");
}
include $header_path;

// Configurar zona horaria de Bogotá
date_default_timezone_set('America/Bogota');

// Verificar permisos usando la clase Auth
if (!$auth->hasPermission('productos', 'lectura')) {
    $_SESSION['error'] = "No tienes permisos para acceder a productos";
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Manejar eliminación
if (isset($_POST['eliminar_id'])) {
    // Verificar permisos para eliminar
    if (!$auth->hasPermission('productos', 'eliminar')) {
        $_SESSION['error'] = "No tienes permisos para eliminar productos.";
    } else {
        $id = $_POST['eliminar_id'];
        $query = "UPDATE productos SET activo = 0 WHERE id = ?";
        $stmt = $db->prepare($query);
        if ($stmt->execute([$id])) {
            $_SESSION['success'] = "Producto eliminado correctamente.";
        } else {
            $_SESSION['error'] = "Error al eliminar el producto.";
        }
    }
    header('Location: index.php');
    ob_end_flush();
    exit;
}

// Manejar actualización en línea via AJAX
if (isset($_POST['actualizar_campo'])) {
    if (!$auth->hasPermission('productos', 'editar')) {
        echo json_encode(['success' => false, 'message' => 'No tienes permisos para editar']);
        exit;
    }
    
    $id = intval($_POST['id']);
    $campo = $_POST['campo'];
    $valor = $_POST['valor'];
    
    // Validar campos permitidos
    $campos_permitidos = ['nombre', 'descripcion', 'precio_compra', 'precio_venta', 'stock', 'stock_minimo', 'talla', 'color', 'marca_id', 'categoria_id'];
    
    if (!in_array($campo, $campos_permitidos)) {
        echo json_encode(['success' => false, 'message' => 'Campo no permitido']);
        exit;
    }
    
    try {
        // Preparar la consulta
        $query = "UPDATE productos SET $campo = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $db->prepare($query);
        
        // Ejecutar con el valor apropiado
        if ($campo == 'precio_compra' || $campo == 'precio_venta') {
            $valor = floatval($valor);
        } elseif ($campo == 'stock' || $campo == 'stock_minimo' || $campo == 'marca_id' || $campo == 'categoria_id') {
            $valor = intval($valor);
        } else {
            $valor = trim($valor);
        }
        
        $stmt->execute([$valor, $id]);
        
        echo json_encode(['success' => true, 'message' => 'Actualizado correctamente']);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Parámetros de búsqueda y filtros
$busqueda = $_GET['busqueda'] ?? '';
$categoria_id = $_GET['categoria_id'] ?? '';
$marca_id = $_GET['marca_id'] ?? '';
$talla = $_GET['talla'] ?? '';
$color = $_GET['color'] ?? '';
$stock_bajo = $_GET['stock_bajo'] ?? '';
$mostrar_servicios = $_GET['mostrar_servicios'] ?? '1';
$orden = $_GET['orden'] ?? 'nombre';

// Obtener categorías para el filtro
$query_categorias = "SELECT * FROM categorias WHERE activo = 1 ORDER BY nombre";
$stmt_categorias = $db->prepare($query_categorias);
$stmt_categorias->execute();
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);

// Obtener marcas para el filtro
$query_marcas = "SELECT * FROM marcas WHERE activo = 1 ORDER BY nombre";
$stmt_marcas = $db->prepare($query_marcas);
$stmt_marcas->execute();
$marcas = $stmt_marcas->fetchAll(PDO::FETCH_ASSOC);

// Obtener tallas únicas para el filtro
$query_tallas = "SELECT DISTINCT talla FROM productos WHERE talla IS NOT NULL AND talla != '' AND activo = 1 ORDER BY talla";
$stmt_tallas = $db->prepare($query_tallas);
$stmt_tallas->execute();
$tallas = $stmt_tallas->fetchAll(PDO::FETCH_COLUMN);

// Obtener colores únicos para el filtro
$query_colores = "SELECT DISTINCT color FROM productos WHERE color IS NOT NULL AND color != '' AND activo = 1 ORDER BY color";
$stmt_colores = $db->prepare($query_colores);
$stmt_colores->execute();
$colores = $stmt_colores->fetchAll(PDO::FETCH_COLUMN);

// Construir consulta base con JOIN para marca
$query = "SELECT p.*, c.nombre as categoria_nombre, m.nombre as marca_nombre 
          FROM productos p 
          LEFT JOIN categorias c ON p.categoria_id = c.id 
          LEFT JOIN marcas m ON p.marca_id = m.id 
          WHERE p.activo = 1";

$params = [];
$conditions = [];

// Aplicar filtros
if (!empty($busqueda)) {
    $conditions[] = "(p.nombre LIKE ? OR p.codigo LIKE ? OR p.descripcion LIKE ? OR p.codigo_barras LIKE ?)";
    $searchTerm = "%$busqueda%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($categoria_id)) {
    $conditions[] = "p.categoria_id = ?";
    $params[] = $categoria_id;
}

if (!empty($marca_id)) {
    $conditions[] = "p.marca_id = ?";
    $params[] = $marca_id;
}

if (!empty($talla)) {
    $conditions[] = "p.talla = ?";
    $params[] = $talla;
}

if (!empty($color)) {
    $conditions[] = "p.color LIKE ?";
    $params[] = "%$color%";
}

if ($stock_bajo === '1') {
    $conditions[] = "p.stock <= p.stock_minimo AND p.es_servicio = 0";
}

if ($mostrar_servicios !== '1') {
    $conditions[] = "p.es_servicio = 0";
}

if (count($conditions) > 0) {
    $query .= " AND " . implode(" AND ", $conditions);
}

// Ordenamiento
$ordenes_validos = ['nombre', 'codigo', 'precio_venta', 'stock', 'created_at'];
$direccion = 'ASC';
if (strpos($orden, '-') === 0) {
    $orden = substr($orden, 1);
    $direccion = 'DESC';
}
if (!in_array($orden, $ordenes_validos)) {
    $orden = 'nombre';
}

// Ordenar por categoría primero y luego por el campo seleccionado
if ($orden == 'nombre') {
    $query .= " ORDER BY COALESCE(c.nombre, 'ZZZZ') ASC, p.nombre ASC";
} else {
    $query .= " ORDER BY COALESCE(c.nombre, 'ZZZZ') ASC, p.$orden $direccion";
}

// Obtener productos
$stmt = $db->prepare($query);
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener atributos dinámicos para todos los productos mostrados
$atributos_por_producto = [];

if (count($productos) > 0) {
    $productos_ids = array_column($productos, 'id');
    $placeholders = implode(',', array_fill(0, count($productos_ids), '?'));
    
    // Consulta para obtener todos los atributos de los productos mostrados
    $query_atributos = "SELECT pa.producto_id, pa.valor_texto, 
                               ta.nombre as tipo_nombre, ta.unidad, ta.icono,
                               va.valor as valor_predefinido
                        FROM producto_atributos pa
                        LEFT JOIN tipos_atributo ta ON pa.tipo_atributo_id = ta.id
                        LEFT JOIN valores_atributo va ON pa.valor_atributo_id = va.id
                        WHERE pa.producto_id IN ($placeholders)
                        ORDER BY ta.nombre";
    
    $stmt_atributos = $db->prepare($query_atributos);
    $stmt_atributos->execute($productos_ids);
    $atributos_db = $stmt_atributos->fetchAll(PDO::FETCH_ASSOC);
    
    // Organizar atributos por producto
    foreach ($atributos_db as $atributo) {
        $producto_id = $atributo['producto_id'];
        if (!isset($atributos_por_producto[$producto_id])) {
            $atributos_por_producto[$producto_id] = [];
        }
        
        // Determinar el valor a mostrar
        $valor_mostrar = $atributo['valor_predefinido'] ?? $atributo['valor_texto'] ?? '';
        $unidad = $atributo['unidad'] ? ' ' . $atributo['unidad'] : '';
        
        $atributos_por_producto[$producto_id][] = [
            'nombre' => $atributo['tipo_nombre'],
            'valor' => $valor_mostrar . ($unidad && !str_contains($valor_mostrar, $unidad) ? $unidad : ''), 
            'icono' => $atributo['icono'] ?? 'fas fa-tag'
        ];
    }
}

// Estadísticas
$query_stats = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN es_servicio = 0 AND stock <= stock_minimo THEN 1 ELSE 0 END) as stock_bajo,
                AVG(CASE WHEN es_servicio = 0 THEN precio_venta ELSE NULL END) as precio_promedio,
                SUM(CASE WHEN es_servicio = 1 THEN 1 ELSE 0 END) as total_servicios
                FROM productos WHERE activo = 1";
$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
?>

<style>
/* Estilos para hacer la tabla responsive y sin scroll horizontal */
.table-responsive {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

table {
    width: 100%;
    border-collapse: collapse;
    table-layout: auto;
    min-width: 1300px; /* Aumentado para dar espacio a más atributos */
}

/* Columnas con anchos específicos */
table th:nth-child(1) { width: 25%; } /* Producto */
table th:nth-child(2) { width: 10%; } /* Marca */
table th:nth-child(3) { width: 10%; } /* Categoría */
table th:nth-child(4) { width: 20%; } /* Atributos (aumentado) */
table th:nth-child(5) { width: 15%; } /* Precios */
table th:nth-child(6) { width: 10%; } /* Stock */
table th:nth-child(7) { width: 10%; } /* Acciones */

/* Estilos para campos editables */
.editable, .editable-marca, .editable-categoria {
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 4px;
    transition: background-color 0.2s;
    display: inline-block;
    width: 100%;
}

.editable:hover, .editable-marca:hover, .editable-categoria:hover {
    background-color: #f3f4f6;
}

.editing {
    background-color: #fef3c7 !important;
    padding: 8px !important;
}

.edit-input, .edit-select, .edit-textarea {
    width: 100%;
    padding: 6px 8px;
    border: 2px solid #3b82f6;
    border-radius: 4px;
    font-size: 0.875rem;
    background-color: white;
}

/* Badge para servicios */
.badge-servicio {
    background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

/* Estilos para la notificación */
#ajaxNotification {
    animation: slideIn 0.3s ease-out;
    z-index: 9999;
}

@keyframes slideIn {
    from { transform: translateX(100%); opacity: 0; }
    to { transform: translateX(0); opacity: 1; }
}

.fade-out {
    animation: fadeOut 0.3s ease-out forwards;
}

@keyframes fadeOut {
    from { opacity: 1; transform: translateX(0); }
    to { opacity: 0; transform: translateX(100%); }
}

/* Estilos para los encabezados de categoría */
.categoria-header {
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border-left: 4px solid #3b82f6;
}

/* Estilos para servicios */
.servicio-row {
    background-color: #f5f3ff;
}

.servicio-row td {
    opacity: 0.9;
}

/* Tooltip para información */
[data-tooltip] {
    position: relative;
    cursor: help;
}

[data-tooltip]:before {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    padding: 4px 8px;
    background: #1f2937;
    color: white;
    font-size: 12px;
    white-space: nowrap;
    border-radius: 4px;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    z-index: 1000;
}

[data-tooltip]:hover:before {
    opacity: 1;
    visibility: visible;
    bottom: 120%;
}

/* Estilos para atributos dinámicos */
.atributo-item {
    transition: all 0.2s ease;
}

.atributo-item:hover {
    background-color: #f9fafb;
    border-radius: 4px;
}
</style>

<div class="max-w-full px-4 py-6">
    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-6 gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">Productos</h1>
            <p class="text-gray-600">Gestiona el inventario de productos - <span class="text-blue-600 font-medium">Doble clic para editar</span></p>
            <div class="flex flex-wrap gap-2 mt-2">
                <span class="bg-blue-100 text-blue-800 text-xs px-3 py-1 rounded-full flex items-center">
                    <i class="fas fa-box mr-1"></i> <?php echo $stats['total'] - ($stats['total_servicios'] ?? 0); ?> productos
                </span>
                <span class="bg-purple-100 text-purple-800 text-xs px-3 py-1 rounded-full flex items-center">
                    <i class="fas fa-concierge-bell mr-1"></i> <?php echo $stats['total_servicios'] ?? 0; ?> servicios
                </span>
                <span class="bg-red-100 text-red-800 text-xs px-3 py-1 rounded-full flex items-center">
                    <i class="fas fa-exclamation-triangle mr-1"></i> <?php echo $stats['stock_bajo']; ?> stock bajo
                </span>
            </div>
        </div>
        <?php if ($auth->hasPermission('productos', 'crear')): ?>
        <div class="flex flex-wrap gap-2">
            <?php if ($auth->hasPermission('inventario', 'crear')): ?>
            <a href="../escaner.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-barcode mr-2"></i>
                Escanear
            </a>
            <?php endif; ?>
            
            <?php if ($auth->hasPermission('reportes', 'exportar')): ?>
            <a href="exportar_excel.php?<?php echo http_build_query($_GET); ?>" 
               class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-file-excel mr-2"></i>
                Exportar Excel
            </a>
            <?php endif; ?>
            
            <a href="crear.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-plus mr-2"></i>
                Nuevo Producto
            </a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Mostrar mensajes -->
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

    <!-- Notificación AJAX -->
    <div id="ajaxNotification" class="hidden fixed top-4 right-4 z-50 max-w-sm">
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded shadow-lg">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-2"></i>
                <span id="ajaxMessage"></span>
            </div>
        </div>
    </div>

    <!-- Estadísticas rápidas -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-blue-100 text-blue-600">
                    <i class="fas fa-box"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Total Productos</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo $stats['total']; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-purple-100 text-purple-600">
                    <i class="fas fa-concierge-bell"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Servicios</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo $stats['total_servicios'] ?? 0; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-red-100 text-red-600">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Stock Bajo</p>
                    <p class="text-lg font-bold text-gray-900"><?php echo $stats['stock_bajo']; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4">
            <div class="flex items-center">
                <div class="p-2 rounded-full bg-green-100 text-green-600">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium text-gray-500">Precio Promedio</p>
                    <p class="text-lg font-bold text-gray-900">$<?php echo number_format($stats['precio_promedio'] ?? 0, 2); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros y Búsqueda -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="p-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Filtros y Búsqueda</h3>
        </div>
        <div class="p-4">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-4">
                <div class="lg:col-span-2">
                    <label for="busqueda" class="block text-sm font-medium text-gray-700">Búsqueda</label>
                    <input type="text" id="busqueda" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>"
                           placeholder="Nombre, código, descripción..."
                           class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div>
                    <label for="categoria_id" class="block text-sm font-medium text-gray-700">Categoría</label>
                    <select id="categoria_id" name="categoria_id" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Todas</option>
                        <?php foreach ($categorias as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo $categoria_id == $cat['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['nombre']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="marca_id" class="block text-sm font-medium text-gray-700">Marca</label>
                    <select id="marca_id" name="marca_id" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Todas</option>
                        <?php foreach ($marcas as $marca): ?>
                        <option value="<?php echo $marca['id']; ?>" <?php echo $marca_id == $marca['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($marca['nombre']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="stock_bajo" class="block text-sm font-medium text-gray-700">Estado</label>
                    <select id="stock_bajo" name="stock_bajo" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Todos</option>
                        <option value="1" <?php echo $stock_bajo == '1' ? 'selected' : ''; ?>>Stock bajo</option>
                    </select>
                </div>
                
                <div class="flex items-end space-x-2 col-span-1 lg:col-span-5">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md flex items-center">
                        <i class="fas fa-filter mr-2"></i>Filtrar
                    </button>
                    <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-md flex items-center">
                        <i class="fas fa-times mr-2"></i>Limpiar
                    </a>
                    
                    <div class="flex items-center ml-auto">
                        <input type="checkbox" id="mostrar_servicios" name="mostrar_servicios" value="1" 
                               <?php echo $mostrar_servicios == '1' ? 'checked' : ''; ?>
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <label for="mostrar_servicios" class="ml-2 block text-sm text-gray-900">
                            Mostrar servicios
                        </label>
                    </div>
                </div>
            </form>

            <!-- Filtros adicionales desplegables -->
            <div class="mt-4 pt-4 border-t border-gray-200">
                <details class="group">
                    <summary class="flex items-center cursor-pointer text-sm font-medium text-gray-700 hover:text-blue-600">
                        <i class="fas fa-chevron-right mr-2 group-open:rotate-90 transition-transform"></i>
                        Filtros avanzados (Talla, Color)
                    </summary>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label for="talla" class="block text-sm font-medium text-gray-700">Talla</label>
                            <select id="talla" name="talla" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Todas</option>
                                <?php foreach ($tallas as $t): ?>
                                <option value="<?php echo $t; ?>" <?php echo $talla == $t ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="color" class="block text-sm font-medium text-gray-700">Color</label>
                            <select id="color" name="color" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Todos</option>
                                <?php foreach ($colores as $c): ?>
                                <option value="<?php echo $c; ?>" <?php echo $color == $c ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </details>
            </div>

            <!-- Filtros activos -->
            <?php if ($busqueda || $categoria_id || $marca_id || $talla || $color || $stock_bajo || $mostrar_servicios !== '1'): ?>
            <div class="mt-4 flex flex-wrap gap-2">
                <span class="text-sm text-gray-500 mr-2">Filtros activos:</span>
                
                <?php if ($busqueda): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                    <i class="fas fa-search mr-1"></i> <?php echo htmlspecialchars($busqueda); ?>
                    <a href="?<?php echo http_build_query(array_filter(['categoria_id' => $categoria_id, 'marca_id' => $marca_id, 'talla' => $talla, 'color' => $color, 'stock_bajo' => $stock_bajo, 'mostrar_servicios' => $mostrar_servicios])); ?>" class="ml-1 text-blue-600 hover:text-blue-800">
                        <i class="fas fa-times"></i>
                    </a>
                </span>
                <?php endif; ?>
                
                <?php if ($categoria_id): 
                    $cat_nombre = '';
                    foreach ($categorias as $cat) {
                        if ($cat['id'] == $categoria_id) {
                            $cat_nombre = $cat['nombre'];
                            break;
                        }
                    }
                ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    <i class="fas fa-tag mr-1"></i> <?php echo htmlspecialchars($cat_nombre); ?>
                    <a href="?<?php echo http_build_query(array_filter(['busqueda' => $busqueda, 'marca_id' => $marca_id, 'talla' => $talla, 'color' => $color, 'stock_bajo' => $stock_bajo, 'mostrar_servicios' => $mostrar_servicios])); ?>" class="ml-1 text-green-600 hover:text-green-800">
                        <i class="fas fa-times"></i>
                    </a>
                </span>
                <?php endif; ?>
                
                <?php if ($marca_id): 
                    $marca_nombre = '';
                    foreach ($marcas as $marca) {
                        if ($marca['id'] == $marca_id) {
                            $marca_nombre = $marca['nombre'];
                            break;
                        }
                    }
                ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                    <i class="fas fa-copyright mr-1"></i> <?php echo htmlspecialchars($marca_nombre); ?>
                    <a href="?<?php echo http_build_query(array_filter(['busqueda' => $busqueda, 'categoria_id' => $categoria_id, 'talla' => $talla, 'color' => $color, 'stock_bajo' => $stock_bajo, 'mostrar_servicios' => $mostrar_servicios])); ?>" class="ml-1 text-purple-600 hover:text-purple-800">
                        <i class="fas fa-times"></i>
                    </a>
                </span>
                <?php endif; ?>
                
                <?php if ($talla): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-100 text-indigo-800">
                    <i class="fas fa-ruler-vertical mr-1"></i> <?php echo htmlspecialchars($talla); ?>
                    <a href="?<?php echo http_build_query(array_filter(['busqueda' => $busqueda, 'categoria_id' => $categoria_id, 'marca_id' => $marca_id, 'color' => $color, 'stock_bajo' => $stock_bajo, 'mostrar_servicios' => $mostrar_servicios])); ?>" class="ml-1 text-indigo-600 hover:text-indigo-800">
                        <i class="fas fa-times"></i>
                    </a>
                </span>
                <?php endif; ?>
                
                <?php if ($color): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-pink-100 text-pink-800">
                    <i class="fas fa-palette mr-1"></i> <?php echo htmlspecialchars($color); ?>
                    <a href="?<?php echo http_build_query(array_filter(['busqueda' => $busqueda, 'categoria_id' => $categoria_id, 'marca_id' => $marca_id, 'talla' => $talla, 'stock_bajo' => $stock_bajo, 'mostrar_servicios' => $mostrar_servicios])); ?>" class="ml-1 text-pink-600 hover:text-pink-800">
                        <i class="fas fa-times"></i>
                    </a>
                </span>
                <?php endif; ?>
                
                <?php if ($stock_bajo): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800">
                    <i class="fas fa-exclamation-triangle mr-1"></i> Stock bajo
                    <a href="?<?php echo http_build_query(array_filter(['busqueda' => $busqueda, 'categoria_id' => $categoria_id, 'marca_id' => $marca_id, 'talla' => $talla, 'color' => $color, 'mostrar_servicios' => $mostrar_servicios])); ?>" class="ml-1 text-red-600 hover:text-red-800">
                        <i class="fas fa-times"></i>
                    </a>
                </span>
                <?php endif; ?>
                
                <?php if ($mostrar_servicios !== '1'): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                    <i class="fas fa-eye-slash mr-1"></i> Ocultando servicios
                    <a href="?<?php echo http_build_query(array_filter(['busqueda' => $busqueda, 'categoria_id' => $categoria_id, 'marca_id' => $marca_id, 'talla' => $talla, 'color' => $color, 'stock_bajo' => $stock_bajo])); ?>&mostrar_servicios=1" class="ml-1 text-gray-600 hover:text-gray-800">
                        <i class="fas fa-times"></i>
                    </a>
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Resultados -->
    <div class="bg-white shadow overflow-hidden sm:rounded-lg">
        <?php if (count($productos) > 0): ?>
            <div class="table-responsive">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Producto</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Marca</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Categoría</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Atributos</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Precios</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="tablaProductos">
                        <?php 
                        $categoria_actual = '';
                        $first_row = true;
                        
                        foreach ($productos as $producto): 
                            $categoria = $producto['categoria_nombre'] ?? 'Sin categoría';
                            
                            // Mostrar separador de categoría si cambia
                            if ($categoria !== $categoria_actual):
                                if (!$first_row): ?>
                                    <!-- Separador visual entre categorías -->
                                    <tr class="bg-gray-50">
                                        <td colspan="7" class="px-4 py-2">
                                            <div class="border-t-2 border-gray-200"></div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <!-- Encabezado de categoría -->
                                <tr class="categoria-header">
                                    <td colspan="7" class="px-4 py-3">
                                        <div class="flex items-center">
                                            <div class="bg-blue-500 text-white p-2 rounded-lg mr-3">
                                                <i class="fas fa-folder-open"></i>
                                            </div>
                                            <div>
                                                <h3 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($categoria); ?></h3>
                                                <?php
                                                // Contar productos en esta categoría
                                                $cat_count = 0;
                                                foreach ($productos as $p) {
                                                    if (($p['categoria_nombre'] ?? 'Sin categoría') == $categoria) {
                                                        $cat_count++;
                                                    }
                                                }
                                                ?>
                                                <p class="text-sm text-gray-600"><?php echo $cat_count; ?> productos</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php 
                            $categoria_actual = $categoria;
                            endif; 
                            
                            $first_row = false;
                            
                            $es_servicio = $producto['es_servicio'] == 1;
                            $row_class = $es_servicio ? 'servicio-row' : '';
                            
                            if (!$es_servicio) {
                                $stock_class = $producto['stock'] <= $producto['stock_minimo'] ? 
                                    'bg-red-100 text-red-800' : 'bg-green-100 text-green-800';
                                $stock_text = $producto['stock'] <= $producto['stock_minimo'] ? 'Bajo' : 'Normal';
                            }
                            
                            $margen = $producto['precio_venta'] - $producto['precio_compra'];
                            $porcentaje_margen = $producto['precio_compra'] > 0 ? 
                                ($margen / $producto['precio_compra']) * 100 : 0;
                        ?>
                        <tr class="hover:bg-gray-50 <?php echo $row_class; ?>" data-id="<?php echo $producto['id']; ?>">
                            <td class="px-4 py-4">
                                <div class="flex items-center">
                                    <?php if (!empty($producto['imagen'])): ?>
                                    <div class="flex-shrink-0 h-10 w-10">
                                        <img class="h-10 w-10 rounded-lg object-cover" src="../../../assets/images/productos/<?php echo $producto['imagen']; ?>" alt="<?php echo htmlspecialchars($producto['nombre']); ?>">
                                    </div>
                                    <?php else: ?>
                                    <div class="flex-shrink-0 h-10 w-10 bg-gray-200 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-<?php echo $es_servicio ? 'concierge-bell' : 'box'; ?> text-gray-400"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div class="ml-3">
                                        <div class="text-sm font-medium text-gray-900 editable" data-field="nombre" data-type="text">
                                            <?php echo htmlspecialchars($producto['nombre']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500 max-w-xs truncate editable" data-field="descripcion" data-type="textarea">
                                            <?php echo htmlspecialchars($producto['descripcion'] ?? ''); ?>
                                        </div>
                                        <div class="text-xs text-gray-400 mt-1 flex flex-wrap gap-1">
                                            <span class="font-medium">Código:</span> <?php echo htmlspecialchars($producto['codigo']); ?>
                                            <?php if (!empty($producto['codigo_barras'])): ?>
                                            <span class="ml-2">
                                                <span class="font-medium">Barras:</span> <?php echo htmlspecialchars($producto['codigo_barras']); ?>
                                            </span>
                                            <?php endif; ?>
                                            <?php if ($es_servicio): ?>
                                            <span class="badge-servicio ml-2">
                                                <i class="fas fa-concierge-bell mr-1"></i> Servicio
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            
                            <!-- Columna de Marca -->
                            <td class="px-4 py-4">
                                <div class="editable-marca" data-field="marca_id" data-type="select" 
                                     data-product-id="<?php echo $producto['id']; ?>"
                                     data-current-value="<?php echo $producto['marca_id'] ?? ''; ?>">
                                    <?php if (!empty($producto['marca_nombre'])): ?>
                                        <div class="flex items-center">
                                            <div class="p-2 rounded-full bg-purple-100 text-purple-600 mr-2">
                                                <i class="fas fa-copyright text-xs"></i>
                                            </div>
                                            <span class="text-sm font-medium text-gray-900 marca-nombre">
                                                <?php echo htmlspecialchars($producto['marca_nombre']); ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center">
                                            <div class="p-2 rounded-full bg-gray-100 text-gray-600 mr-2">
                                                <i class="fas fa-copyright text-xs"></i>
                                            </div>
                                            <span class="text-sm text-gray-400 italic marca-nombre">
                                                Sin marca
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <input type="hidden" class="marca-id" value="<?php echo $producto['marca_id'] ?? ''; ?>">
                                </div>
                            </td>
                            
                            <!-- Columna de Categoría -->
                            <td class="px-4 py-4">
                                <div class="editable-categoria" data-field="categoria_id" data-type="select" 
                                     data-product-id="<?php echo $producto['id']; ?>"
                                     data-current-value="<?php echo $producto['categoria_id'] ?? ''; ?>">
                                    <?php if (!empty($producto['categoria_nombre'])): ?>
                                        <div class="flex items-center">
                                            <div class="p-2 rounded-full bg-blue-100 text-blue-600 mr-2">
                                                <i class="fas fa-tag text-xs"></i>
                                            </div>
                                            <span class="text-sm font-medium text-gray-900 categoria-nombre">
                                                <?php echo htmlspecialchars($producto['categoria_nombre']); ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center">
                                            <div class="p-2 rounded-full bg-gray-100 text-gray-600 mr-2">
                                                <i class="fas fa-tag text-xs"></i>
                                            </div>
                                            <span class="text-sm text-gray-400 italic categoria-nombre">
                                                Sin categoría
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <input type="hidden" class="categoria-id" value="<?php echo $producto['categoria_id'] ?? ''; ?>">
                                </div>
                            </td>
                            
                            <!-- Columna de Atributos (con atributos dinámicos) -->
                            <td class="px-4 py-4">
                                <div class="space-y-2">
                                    <!-- Atributos básicos (talla y color) - SOLO si existen -->
                                    <?php if (!empty($producto['talla'])): ?>
                                    <div class="flex items-center">
                                        <div class="p-2 rounded-full bg-indigo-100 text-indigo-600 mr-2">
                                            <i class="fas fa-ruler-vertical text-xs"></i>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-500">Talla</div>
                                            <div class="text-sm font-medium text-gray-900 editable" data-field="talla" data-type="select" data-options='["","XS","S","M","L","XL","XXL","XXXL","XXXXL"]'>
                                                <?php echo htmlspecialchars($producto['talla']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($producto['color'])): ?>
                                    <div class="flex items-center">
                                        <div class="p-2 rounded-full bg-pink-100 text-pink-600 mr-2">
                                            <i class="fas fa-palette text-xs"></i>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-500">Color</div>
                                            <div class="text-sm font-medium text-gray-900 editable" data-field="color" data-type="text">
                                                <?php echo htmlspecialchars($producto['color']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <!-- Atributos dinámicos -->
                                    <?php 
                                    $atributos_producto = $atributos_por_producto[$producto['id']] ?? [];
                                    foreach ($atributos_producto as $atributo): 
                                    ?>
                                    <div class="flex items-center atributo-item">
                                        <div class="p-2 rounded-full bg-purple-100 text-purple-600 mr-2">
                                            <i class="<?php echo $atributo['icono']; ?> text-xs"></i>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($atributo['nombre']); ?></div>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($atributo['valor']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <!-- Mensaje cuando no hay ningún atributo -->
                                    <?php if (empty($producto['talla']) && empty($producto['color']) && empty($atributos_producto)): ?>
                                    <div class="flex items-center opacity-50">
                                        <div class="p-2 rounded-full bg-gray-100 text-gray-600 mr-2">
                                            <i class="fas fa-tag text-xs"></i>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-500">Atributos</div>
                                            <div class="text-sm font-medium text-gray-400 italic">
                                                <?php echo $es_servicio ? 'N/A' : 'Sin atributos'; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Columna de Precios -->
                            <td class="px-4 py-4">
                                <div class="space-y-2">
                                    <div>
                                        <div class="text-xs text-gray-500">Compra</div>
                                        <div class="text-sm font-medium text-gray-900 editable" data-field="precio_compra" data-type="number" data-step="0.01">
                                            $<?php echo number_format($producto['precio_compra'], 2); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500">Venta</div>
                                        <div class="text-sm font-medium text-gray-900 editable" data-field="precio_venta" data-type="number" data-step="0.01">
                                            $<?php echo number_format($producto['precio_venta'], 2); ?>
                                        </div>
                                    </div>
                                    <?php if (!$es_servicio): ?>
                                    <div>
                                        <div class="text-xs text-gray-500">Margen</div>
                                        <div class="text-sm font-medium <?php echo $porcentaje_margen >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                            <?php echo number_format($porcentaje_margen, 1); ?>%
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            
                            <!-- Columna de Stock -->
                            <td class="px-4 py-4">
                                <?php if (!$es_servicio): ?>
                                <div class="space-y-2">
                                    <div>
                                        <div class="text-xs text-gray-500">Actual</div>
                                        <div class="text-lg font-bold text-gray-900 editable" data-field="stock" data-type="number">
                                            <?php echo $producto['stock']; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500">Mínimo</div>
                                        <div class="text-sm font-medium text-gray-900 editable" data-field="stock_minimo" data-type="number">
                                            <?php echo $producto['stock_minimo']; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?php echo $stock_class; ?>">
                                            <?php echo $stock_text; ?>
                                        </span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-2">
                                    <span class="badge-servicio">
                                        <i class="fas fa-concierge-bell mr-1"></i> Servicio
                                    </span>
                                    <div class="text-xs text-gray-400 mt-1">No aplica stock</div>
                                </div>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Columna de Acciones -->
                            <td class="px-4 py-4 whitespace-nowrap text-sm font-medium">
                                <div class="flex flex-col items-start space-y-2">
                                    <div class="flex items-center space-x-2">
                                        <a href="ver.php?id=<?php echo $producto['id']; ?>" class="text-green-600 hover:text-green-900 p-2 rounded-full hover:bg-green-50" title="Ver detalles">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <?php if ($auth->hasPermission('productos', 'editar')): ?>
                                        <a href="editar.php?id=<?php echo $producto['id']; ?>" class="text-blue-600 hover:text-blue-900 p-2 rounded-full hover:bg-blue-50" title="Editar completo">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($auth->hasPermission('productos', 'eliminar')): ?>
                                        <button onclick="confirmarEliminacion(<?php echo $producto['id']; ?>, '<?php echo htmlspecialchars($producto['nombre']); ?>')" 
                                                class="text-red-600 hover:text-red-900 p-2 rounded-full hover:bg-red-50" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($producto['codigo_barras']) && !$es_servicio): ?>
                                    <button onclick="imprimirEtiqueta(<?php echo $producto['id']; ?>)" 
                                            class="text-purple-600 hover:text-purple-900 p-2 rounded-full hover:bg-purple-50 self-start" title="Imprimir etiqueta">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Información de resultados -->
            <div class="bg-gray-50 px-6 py-3 border-t border-gray-200">
                <div class="flex flex-col sm:flex-row justify-between items-center">
                    <p class="text-sm text-gray-700">
                        Mostrando <span class="font-medium"><?php echo count($productos); ?></span> productos
                        <?php if ($busqueda || $categoria_id || $marca_id || $talla || $color || $stock_bajo || $mostrar_servicios !== '1'): ?>
                            (filtrados)
                        <?php endif; ?>
                    </p>
                    <div class="flex items-center space-x-4 mt-2 sm:mt-0">
                        <div class="text-sm text-gray-500">
                            <?php if (count($productos) > 0): ?>
                                Última actualización: <?php echo date('d/m/Y H:i'); ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($auth->hasPermission('reportes', 'exportar')): ?>
                        <a href="exportar_excel.php?<?php echo http_build_query($_GET); ?>" 
                           class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm flex items-center">
                            <i class="fas fa-file-excel mr-1"></i> Exportar
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-boxes text-gray-400 text-5xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">
                    <?php echo ($busqueda || $categoria_id || $marca_id || $talla || $color || $stock_bajo || $mostrar_servicios !== '1') ? 'No se encontraron productos' : 'No hay productos registrados'; ?>
                </h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($busqueda || $categoria_id || $marca_id || $talla || $color || $stock_bajo || $mostrar_servicios !== '1'): ?>
                        Intenta ajustar los filtros de búsqueda.
                    <?php else: ?>
                        Comienza agregando tu primer producto al inventario.
                    <?php endif; ?>
                </p>
                <?php if ($auth->hasPermission('productos', 'crear')): ?>
                <a href="crear.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                    <i class="fas fa-plus mr-2"></i>
                    Agregar Producto
                </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de confirmación para eliminar -->
<div id="modalEliminar" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900">Confirmar Eliminación</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">
                    ¿Estás seguro de que quieres eliminar el producto "<span id="productoNombre"></span>"?
                </p>
                <p class="text-sm text-red-500 mt-2">El producto se marcará como inactivo.</p>
            </div>
            <div class="flex justify-center space-x-3 mt-4">
                <button onclick="cerrarModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded">
                    Cancelar
                </button>
                <form id="formEliminar" method="POST" class="inline">
                    <input type="hidden" name="eliminar_id" id="eliminarId">
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">
                        Eliminar
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Resto del JavaScript se mantiene igual -->
<script>
// ============================================
// VARIABLES GLOBALES Y FUNCIONES BÁSICAS
// ============================================

let currentEditElement = null;
let originalValue = '';
let marcasData = <?php echo json_encode($marcas); ?>;
let categoriasData = <?php echo json_encode($categorias); ?>;

// Confirmar eliminación
function confirmarEliminacion(id, nombre) {
    document.getElementById('productoNombre').textContent = nombre;
    document.getElementById('eliminarId').value = id;
    document.getElementById('modalEliminar').classList.remove('hidden');
}

// Cerrar modal
function cerrarModal() {
    document.getElementById('modalEliminar').classList.add('hidden');
}

// Imprimir etiqueta de código de barras
function imprimirEtiqueta(productoId) {
    const ventana = window.open(`imprimir_etiqueta.php?id=${productoId}`, '_blank', 'width=400,height=300');
}

// Búsqueda rápida con Enter
document.getElementById('busqueda')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        this.form.submit();
    }
});

// Auto-focus en búsqueda al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    const busquedaInput = document.getElementById('busqueda');
    if (busquedaInput && !busquedaInput.value) {
        busquedaInput.focus();
    }
    
    // Inicializar edición en línea
    initInlineEditing();
    initMarcaCategoriaEditing();
});

// ============================================
// FUNCIONES PARA EDICIÓN EN LÍNEA DE CAMPOS BÁSICOS
// ============================================

function initInlineEditing() {
    // Agregar eventos a elementos editables básicos
    document.querySelectorAll('.editable:not(.editable-marca):not(.editable-categoria)').forEach(element => {
        element.addEventListener('dblclick', function(e) {
            if (currentEditElement) return;
            startEditing(this);
        });
        
        // También permitir clic con Ctrl o Alt
        element.addEventListener('click', function(e) {
            if (e.ctrlKey || e.altKey) {
                e.preventDefault();
                if (currentEditElement) return;
                startEditing(this);
            }
        });
    });
}

function startEditing(element) {
    // Verificar permisos de edición
    <?php if (!$auth->hasPermission('productos', 'editar')): ?>
    showNotification('No tienes permisos para editar productos', 'error');
    return;
    <?php endif; ?>
    
    currentEditElement = element;
    originalValue = element.textContent.trim();
    
    // Extraer datos del elemento
    const field = element.getAttribute('data-field');
    const type = element.getAttribute('data-type');
    const options = element.getAttribute('data-options');
    const step = element.getAttribute('data-step') || '1';
    
    // Guardar clase original
    const originalClass = element.className;
    element.classList.add('editing');
    
    // Limpiar contenido
    element.innerHTML = '';
    
    // Crear campo de entrada según el tipo
    let input;
    
    switch(type) {
        case 'textarea':
            input = document.createElement('textarea');
            input.className = 'edit-textarea';
            input.value = originalValue.replace(/^\$/, '').replace(/,/g, ''); // Quitar formato
            input.rows = 3;
            break;
            
        case 'select':
            input = document.createElement('select');
            input.className = 'edit-select';
            
            try {
                const optionList = JSON.parse(options);
                optionList.forEach(optionValue => {
                    const option = document.createElement('option');
                    option.value = optionValue;
                    option.textContent = optionValue || '(Vacío)';
                    if (optionValue === originalValue) {
                        option.selected = true;
                    }
                    input.appendChild(option);
                });
            } catch (e) {
                console.error('Error parsing options:', e);
            }
            break;
            
        case 'number':
            input = document.createElement('input');
            input.type = 'number';
            input.className = 'edit-input';
            input.step = step;
            input.min = '0';
            let numericValue = originalValue.replace(/[^\d.-]/g, '');
            if (numericValue === '' || numericValue === 'Sin' || numericValue === 'N/A') numericValue = '0';
            input.value = parseFloat(numericValue);
            break;
            
        default: // text
            input = document.createElement('input');
            input.type = 'text';
            input.className = 'edit-input';
            let textValue = originalValue;
            if (textValue === 'Sin talla' || textValue === 'Sin color' || textValue === 'N/A') {
                textValue = '';
            }
            input.value = textValue;
    }
    
    // Agregar eventos al input
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            saveEdit();
        } else if (e.key === 'Escape') {
            cancelEdit();
        }
    });
    
    input.addEventListener('blur', function() {
        setTimeout(() => {
            if (currentEditElement === element) {
                saveEdit();
            }
        }, 100);
    });
    
    // Agregar botones de acción
    const container = document.createElement('div');
    container.className = 'flex space-x-2 mt-2';
    
    const saveBtn = document.createElement('button');
    saveBtn.type = 'button';
    saveBtn.className = 'px-2 py-1 bg-green-500 text-white text-xs rounded hover:bg-green-600';
    saveBtn.innerHTML = '<i class="fas fa-check mr-1"></i> Guardar';
    saveBtn.addEventListener('click', saveEdit);
    
    const cancelBtn = document.createElement('button');
    cancelBtn.type = 'button';
    cancelBtn.className = 'px-2 py-1 bg-gray-300 text-gray-700 text-xs rounded hover:bg-gray-400';
    cancelBtn.innerHTML = '<i class="fas fa-times mr-1"></i> Cancelar';
    cancelBtn.addEventListener('click', cancelEdit);
    
    container.appendChild(saveBtn);
    container.appendChild(cancelBtn);
    
    // Insertar elementos
    element.appendChild(input);
    element.appendChild(container);
    
    // Enfocar el input
    setTimeout(() => {
        input.focus();
        if (type === 'text' || type === 'number') {
            input.select();
        }
    }, 10);
}

function saveEdit() {
    if (!currentEditElement) return;
    
    const field = currentEditElement.getAttribute('data-field');
    const type = currentEditElement.getAttribute('data-type');
    const productId = currentEditElement.closest('tr').getAttribute('data-id');
    const input = currentEditElement.querySelector('input, textarea, select');
    
    if (!input) {
        cancelEdit();
        return;
    }
    
    let newValue = input.value.trim();
    
    // Validaciones
    if (type === 'number') {
        newValue = parseFloat(newValue);
        if (isNaN(newValue)) {
            showNotification('Valor numérico inválido', 'error');
            cancelEdit();
            return;
        }
        if (newValue < 0) {
            showNotification('El valor no puede ser negativo', 'error');
            cancelEdit();
            return;
        }
    }
    
    // Enviar al servidor
    const formData = new FormData();
    formData.append('actualizar_campo', '1');
    formData.append('id', productId);
    formData.append('campo', field);
    formData.append('valor', newValue);
    
    // Mostrar indicador de carga
    currentEditElement.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Guardando...';
    
    fetch('index.php', {
        method: 'POST',
        body: formData,
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Actualizar visualmente
            updateDisplayValue(field, newValue, type);
            showNotification(data.message || 'Actualizado correctamente', 'success');
            
            // Si es precio de compra o venta, recalcular margen
            if (field === 'precio_compra' || field === 'precio_venta') {
                updateMargin(productId);
            }
            
            // Si es stock, actualizar estado
            if (field === 'stock' || field === 'stock_minimo') {
                updateStockStatus(productId);
            }
        } else {
            showNotification(data.message || 'Error al guardar', 'error');
            cancelEdit();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error de conexión', 'error');
        cancelEdit();
    });
}

function cancelEdit() {
    if (!currentEditElement) return;
    
    const type = currentEditElement.getAttribute('data-type');
    const field = currentEditElement.getAttribute('data-field');
    
    // Restaurar valor original
    if (type === 'number' && field && field.includes('precio')) {
        currentEditElement.textContent = '$' + parseFloat(originalValue.replace(/[^\d.-]/g, '')).toFixed(2);
    } else {
        currentEditElement.textContent = originalValue;
    }
    
    currentEditElement.classList.remove('editing');
    currentEditElement = null;
    originalValue = '';
}

function updateDisplayValue(field, value, type) {
    if (!currentEditElement) return;
    
    let displayValue = value;
    
    if (type === 'number' && field && field.includes('precio')) {
        displayValue = '$' + parseFloat(value).toFixed(2);
    } else if (type === 'number') {
        displayValue = parseInt(value);
    } else if (type === 'select') {
        displayValue = value || (field === 'talla' ? 'Sin talla' : 'Sin color');
    } else if (type === 'text' && field === 'color' && !value) {
        displayValue = 'Sin color';
    } else if (type === 'text' && field === 'descripcion' && !value) {
        displayValue = '';
    }
    
    currentEditElement.textContent = displayValue;
    currentEditElement.classList.remove('editing');
    currentEditElement = null;
    originalValue = '';
}

// ============================================
// FUNCIONES ESPECÍFICAS PARA EDICIÓN DE MARCAS Y CATEGORÍAS
// ============================================

function initMarcaCategoriaEditing() {
    // Inicializar edición de marcas
    document.querySelectorAll('.editable-marca').forEach(element => {
        element.addEventListener('dblclick', function(e) {
            e.stopPropagation();
            startMarcaEditing(this);
        });
        
        element.addEventListener('click', function(e) {
            if (e.ctrlKey || e.altKey) {
                e.preventDefault();
                e.stopPropagation();
                startMarcaEditing(this);
            }
        });
    });
    
    // Inicializar edición de categorías
    document.querySelectorAll('.editable-categoria').forEach(element => {
        element.addEventListener('dblclick', function(e) {
            e.stopPropagation();
            startCategoriaEditing(this);
        });
        
        element.addEventListener('click', function(e) {
            if (e.ctrlKey || e.altKey) {
                e.preventDefault();
                e.stopPropagation();
                startCategoriaEditing(this);
            }
        });
    });
}

function startMarcaEditing(element) {
    <?php if (!$auth->hasPermission('productos', 'editar')): ?>
    showNotification('No tienes permisos para editar productos', 'error');
    return;
    <?php endif; ?>
    
    if (currentEditElement) return;
    
    currentEditElement = element;
    const productId = element.getAttribute('data-product-id');
    const currentValue = element.getAttribute('data-current-value') || '';
    const currentNombre = element.querySelector('.marca-nombre').textContent.trim();
    
    originalValue = currentValue;
    
    // Guardar contenido original
    const originalHTML = element.innerHTML;
    
    // Agregar clase de edición
    element.classList.add('editing');
    
    // Limpiar contenido
    element.innerHTML = '';
    
    // Crear contenedor para el selector
    const container = document.createElement('div');
    container.className = 'marca-edit-container';
    
    // Crear selector de marcas
    const select = document.createElement('select');
    select.className = 'marca-edit-select';
    
    // Agregar opción vacía
    const emptyOption = document.createElement('option');
    emptyOption.value = '';
    emptyOption.textContent = 'Sin marca';
    emptyOption.selected = currentValue === '';
    select.appendChild(emptyOption);
    
    // Agregar marcas desde los datos cargados
    if (marcasData && marcasData.length > 0) {
        marcasData.forEach(marca => {
            const option = document.createElement('option');
            option.value = marca.id;
            option.textContent = marca.nombre;
            if (currentValue == marca.id) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }
    
    // Crear botones de acción
    const buttonContainer = document.createElement('div');
    buttonContainer.className = 'marca-edit-buttons';
    
    const saveButton = document.createElement('button');
    saveButton.type = 'button';
    saveButton.className = 'px-3 py-1 bg-green-500 text-white text-sm rounded hover:bg-green-600 flex items-center';
    saveButton.innerHTML = '<i class="fas fa-check mr-1"></i> Guardar';
    saveButton.addEventListener('click', () => saveMarcaEdit(productId, select.value));
    
    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'px-3 py-1 bg-gray-300 text-gray-700 text-sm rounded hover:bg-gray-400 flex items-center';
    cancelButton.innerHTML = '<i class="fas fa-times mr-1"></i> Cancelar';
    cancelButton.addEventListener('click', () => {
        element.innerHTML = originalHTML;
        element.classList.remove('editing');
        currentEditElement = null;
    });
    
    buttonContainer.appendChild(saveButton);
    buttonContainer.appendChild(cancelButton);
    
    // Agregar elementos al contenedor
    container.appendChild(select);
    container.appendChild(buttonContainer);
    
    // Agregar contenedor al elemento
    element.appendChild(container);
    
    // Enfocar el selector
    setTimeout(() => {
        select.focus();
    }, 10);
}

function startCategoriaEditing(element) {
    <?php if (!$auth->hasPermission('productos', 'editar')): ?>
    showNotification('No tienes permisos para editar productos', 'error');
    return;
    <?php endif; ?>
    
    if (currentEditElement) return;
    
    currentEditElement = element;
    const productId = element.getAttribute('data-product-id');
    const currentValue = element.getAttribute('data-current-value') || '';
    const currentNombre = element.querySelector('.categoria-nombre').textContent.trim();
    
    originalValue = currentValue;
    
    // Guardar contenido original
    const originalHTML = element.innerHTML;
    
    // Agregar clase de edición
    element.classList.add('editing');
    
    // Limpiar contenido
    element.innerHTML = '';
    
    // Crear contenedor para el selector
    const container = document.createElement('div');
    container.className = 'marca-edit-container';
    
    // Crear selector de categorías
    const select = document.createElement('select');
    select.className = 'marca-edit-select';
    
    // Agregar opción vacía
    const emptyOption = document.createElement('option');
    emptyOption.value = '';
    emptyOption.textContent = 'Sin categoría';
    emptyOption.selected = currentValue === '';
    select.appendChild(emptyOption);
    
    // Agregar categorías desde los datos cargados
    if (categoriasData && categoriasData.length > 0) {
        categoriasData.forEach(categoria => {
            const option = document.createElement('option');
            option.value = categoria.id;
            option.textContent = categoria.nombre;
            if (currentValue == categoria.id) {
                option.selected = true;
            }
            select.appendChild(option);
        });
    }
    
    // Crear botones de acción
    const buttonContainer = document.createElement('div');
    buttonContainer.className = 'marca-edit-buttons';
    
    const saveButton = document.createElement('button');
    saveButton.type = 'button';
    saveButton.className = 'px-3 py-1 bg-green-500 text-white text-sm rounded hover:bg-green-600 flex items-center';
    saveButton.innerHTML = '<i class="fas fa-check mr-1"></i> Guardar';
    saveButton.addEventListener('click', () => saveCategoriaEdit(productId, select.value));
    
    const cancelButton = document.createElement('button');
    cancelButton.type = 'button';
    cancelButton.className = 'px-3 py-1 bg-gray-300 text-gray-700 text-sm rounded hover:bg-gray-400 flex items-center';
    cancelButton.innerHTML = '<i class="fas fa-times mr-1"></i> Cancelar';
    cancelButton.addEventListener('click', () => {
        element.innerHTML = originalHTML;
        element.classList.remove('editing');
        currentEditElement = null;
    });
    
    buttonContainer.appendChild(saveButton);
    buttonContainer.appendChild(cancelButton);
    
    // Agregar elementos al contenedor
    container.appendChild(select);
    container.appendChild(buttonContainer);
    
    // Agregar contenedor al elemento
    element.appendChild(container);
    
    // Enfocar el selector
    setTimeout(() => {
        select.focus();
    }, 10);
}

function saveMarcaEdit(productId, marcaId) {
    if (!currentEditElement) return;
    
    // Mostrar indicador de carga
    currentEditElement.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Guardando...';
    
    // Enviar al servidor
    const formData = new FormData();
    formData.append('actualizar_campo', '1');
    formData.append('id', productId);
    formData.append('campo', 'marca_id');
    formData.append('valor', marcaId);
    
    fetch('index.php', {
        method: 'POST',
        body: formData,
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Encontrar el nombre de la marca seleccionada
            let marcaNombre = 'Sin marca';
            let iconClass = 'bg-gray-100 text-gray-600';
            
            if (marcaId && marcasData) {
                const marca = marcasData.find(m => m.id == marcaId);
                if (marca) {
                    marcaNombre = marca.nombre;
                    iconClass = 'bg-purple-100 text-purple-600';
                }
            }
            
            // Actualizar visualmente
            currentEditElement.innerHTML = `
                <div class="flex items-center">
                    <div class="p-2 rounded-full ${iconClass} mr-2">
                        <i class="fas fa-copyright text-xs"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-900 marca-nombre">
                        ${marcaNombre}
                    </span>
                </div>
                <input type="hidden" class="marca-id" value="${marcaId}">
            `;
            
            // Actualizar atributos
            currentEditElement.setAttribute('data-current-value', marcaId);
            
            showNotification('Marca actualizada correctamente', 'success');
            currentEditElement.classList.remove('editing');
            currentEditElement = null;
            
        } else {
            showNotification(data.message || 'Error al actualizar la marca', 'error');
            // Restaurar contenido original
            cancelMarcaEdit();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error de conexión', 'error');
        cancelMarcaEdit();
    });
}

function saveCategoriaEdit(productId, categoriaId) {
    if (!currentEditElement) return;
    
    // Mostrar indicador de carga
    currentEditElement.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Guardando...';
    
    // Enviar al servidor
    const formData = new FormData();
    formData.append('actualizar_campo', '1');
    formData.append('id', productId);
    formData.append('campo', 'categoria_id');
    formData.append('valor', categoriaId);
    
    fetch('index.php', {
        method: 'POST',
        body: formData,
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Encontrar el nombre de la categoría seleccionada
            let categoriaNombre = 'Sin categoría';
            let iconClass = 'bg-gray-100 text-gray-600';
            
            if (categoriaId && categoriasData) {
                const categoria = categoriasData.find(c => c.id == categoriaId);
                if (categoria) {
                    categoriaNombre = categoria.nombre;
                    iconClass = 'bg-blue-100 text-blue-600';
                }
            }
            
            // Actualizar visualmente
            currentEditElement.innerHTML = `
                <div class="flex items-center">
                    <div class="p-2 rounded-full ${iconClass} mr-2">
                        <i class="fas fa-tag text-xs"></i>
                    </div>
                    <span class="text-sm font-medium text-gray-900 categoria-nombre">
                        ${categoriaNombre}
                    </span>
                </div>
                <input type="hidden" class="categoria-id" value="${categoriaId}">
            `;
            
            // Actualizar atributos
            currentEditElement.setAttribute('data-current-value', categoriaId);
            
            showNotification('Categoría actualizada correctamente', 'success');
            currentEditElement.classList.remove('editing');
            currentEditElement = null;
            
        } else {
            showNotification(data.message || 'Error al actualizar la categoría', 'error');
            // Restaurar contenido original
            cancelCategoriaEdit();
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Error de conexión', 'error');
        cancelCategoriaEdit();
    });
}

function cancelMarcaEdit() {
    if (!currentEditElement) return;
    
    const productId = currentEditElement.getAttribute('data-product-id');
    const currentValue = originalValue || '';
    
    // Encontrar el nombre de la marca original
    let marcaNombre = 'Sin marca';
    let iconClass = 'bg-gray-100 text-gray-600';
    
    if (currentValue && marcasData) {
        const marca = marcasData.find(m => m.id == currentValue);
        if (marca) {
            marcaNombre = marca.nombre;
            iconClass = 'bg-purple-100 text-purple-600';
        }
    }
    
    // Restaurar visualmente
    currentEditElement.innerHTML = `
        <div class="flex items-center">
            <div class="p-2 rounded-full ${iconClass} mr-2">
                <i class="fas fa-copyright text-xs"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 marca-nombre">
                ${marcaNombre}
            </span>
        </div>
        <input type="hidden" class="marca-id" value="${currentValue}">
    `;
    
    currentEditElement.classList.remove('editing');
    currentEditElement = null;
    originalValue = '';
}

function cancelCategoriaEdit() {
    if (!currentEditElement) return;
    
    const productId = currentEditElement.getAttribute('data-product-id');
    const currentValue = originalValue || '';
    
    // Encontrar el nombre de la categoría original
    let categoriaNombre = 'Sin categoría';
    let iconClass = 'bg-gray-100 text-gray-600';
    
    if (currentValue && categoriasData) {
        const categoria = categoriasData.find(c => c.id == currentValue);
        if (categoria) {
            categoriaNombre = categoria.nombre;
            iconClass = 'bg-blue-100 text-blue-600';
        }
    }
    
    // Restaurar visualmente
    currentEditElement.innerHTML = `
        <div class="flex items-center">
            <div class="p-2 rounded-full ${iconClass} mr-2">
                <i class="fas fa-tag text-xs"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 categoria-nombre">
                ${categoriaNombre}
            </span>
        </div>
        <input type="hidden" class="categoria-id" value="${currentValue}">
    `;
    
    currentEditElement.classList.remove('editing');
    currentEditElement = null;
    originalValue = '';
}

// ============================================
// FUNCIONES DE UTILIDAD
// ============================================

function updateMargin(productId) {
    const row = document.querySelector(`tr[data-id="${productId}"]`);
    if (!row) return;
    
    const precioCompraElem = row.querySelector('[data-field="precio_compra"]');
    const precioVentaElem = row.querySelector('[data-field="precio_venta"]');
    const margenElem = row.querySelector('.text-green-600, .text-red-600');
    
    if (!precioCompraElem || !precioVentaElem || !margenElem) return;
    
    const precioCompra = parseFloat(precioCompraElem.textContent.replace(/[^\d.-]/g, ''));
    const precioVenta = parseFloat(precioVentaElem.textContent.replace(/[^\d.-]/g, ''));
    
    if (precioCompra > 0) {
        const margen = precioVenta - precioCompra;
        const porcentaje = (margen / precioCompra) * 100;
        
        margenElem.textContent = porcentaje.toFixed(1) + '%';
        margenElem.className = porcentaje >= 0 ? 'text-sm font-medium text-green-600' : 'text-sm font-medium text-red-600';
    }
}

function updateStockStatus(productId) {
    const row = document.querySelector(`tr[data-id="${productId}"]`);
    if (!row) return;
    
    const stockElem = row.querySelector('[data-field="stock"]');
    const stockMinimoElem = row.querySelector('[data-field="stock_minimo"]');
    const statusBadge = row.querySelector('.inline-flex.items-center');
    
    if (!stockElem || !stockMinimoElem || !statusBadge) return;
    
    const stock = parseInt(stockElem.textContent);
    const stockMinimo = parseInt(stockMinimoElem.textContent);
    
    if (stock <= stockMinimo) {
        statusBadge.className = 'inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-red-100 text-red-800';
        statusBadge.textContent = 'Bajo';
    } else {
        statusBadge.className = 'inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800';
        statusBadge.textContent = 'Normal';
    }
}

function showNotification(message, type = 'success') {
    const notification = document.getElementById('ajaxNotification');
    const messageElem = document.getElementById('ajaxMessage');
    
    if (!notification || !messageElem) return;
    
    // Configurar estilo según tipo
    if (type === 'error') {
        notification.className = 'fixed top-4 right-4 z-50 max-w-sm bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded shadow-lg';
    } else {
        notification.className = 'fixed top-4 right-4 z-50 max-w-sm bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded shadow-lg';
    }
    
    messageElem.textContent = message;
    notification.classList.remove('hidden');
    
    // Ocultar después de 3 segundos
    setTimeout(() => {
        notification.classList.add('fade-out');
        setTimeout(() => {
            notification.classList.add('hidden');
            notification.classList.remove('fade-out');
        }, 300);
    }, 3000);
}

// Manejar clics fuera de los campos de edición
document.addEventListener('click', function(e) {
    if (currentEditElement && !currentEditElement.contains(e.target)) {
        // Determinar qué tipo de edición está en curso
        if (currentEditElement.classList.contains('editable-marca')) {
            saveMarcaEdit(
                currentEditElement.getAttribute('data-product-id'),
                currentEditElement.querySelector('select')?.value || ''
            );
        } else if (currentEditElement.classList.contains('editable-categoria')) {
            saveCategoriaEdit(
                currentEditElement.getAttribute('data-product-id'),
                currentEditElement.querySelector('select')?.value || ''
            );
        } else if (currentEditElement.classList.contains('editable')) {
            saveEdit();
        }
    }
});
</script>

<?php include '../../../includes/footer.php'; ?>