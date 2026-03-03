<?php
// Para debug - mostrar todos los errores
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once '../../../config/database.php';
session_start();

// Crear instancia de la base de datos
$database = Database::getInstance();
$pdo = $database->getConnection();

// Verificar conexión
if (!$pdo) {
    die("<div style='color:red; padding:20px; border:2px solid red;'>
        <h2>Error de Conexión a la Base de Datos</h2>
        <p>No se pudo conectar a la base de datos. Verifica la configuración.</p>
    </div>");
}

// Verificar si hay filtros aplicados
$categoria_id = isset($_GET['categoria_id']) ? intval($_GET['categoria_id']) : '';
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$solo_stock_bajo = isset($_GET['stock_bajo']) ? true : false;
$solo_con_stock = isset($_GET['con_stock']) ? true : false;

// Configuración de paginación
$productos_por_pagina = 15;
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$offset = ($pagina_actual - 1) * $productos_por_pagina;

// Construir consulta base para contar total
$query_count = "SELECT COUNT(*) as total 
                FROM productos p 
                LEFT JOIN categorias c ON p.categoria_id = c.id 
                WHERE p.activo = 1";
$params_count = [];
$types_count = [];

// Aplicar filtros para contar
if (!empty($categoria_id)) {
    $query_count .= " AND p.categoria_id = ?";
    $params_count[] = $categoria_id;
    $types_count[] = PDO::PARAM_INT;
}

if (!empty($busqueda)) {
    $query_count .= " AND (p.nombre LIKE ? OR p.codigo LIKE ? OR p.codigo_barras LIKE ?)";
    $search_term = "%$busqueda%";
    $params_count[] = $search_term;
    $params_count[] = $search_term;
    $params_count[] = $search_term;
    $types_count[] = PDO::PARAM_STR;
    $types_count[] = PDO::PARAM_STR;
    $types_count[] = PDO::PARAM_STR;
}

if ($solo_stock_bajo) {
    $query_count .= " AND p.stock <= p.stock_minimo AND p.stock_minimo > 0";
}

if ($solo_con_stock) {
    $query_count .= " AND p.stock > 0";
}

// Obtener total de productos
try {
    $stmt = $pdo->prepare($query_count);
    
    // Asignar parámetros con tipos
    foreach ($params_count as $key => $param) {
        $stmt->bindValue($key + 1, $param, $types_count[$key] ?? PDO::PARAM_STR);
    }
    
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_productos = $result['total'] ?? 0;
    $total_paginas = ceil($total_productos / $productos_por_pagina);
} catch (Exception $e) {
    $total_productos = 0;
    $total_paginas = 1;
    error_log("Error al contar productos: " . $e->getMessage());
}

// Construir consulta para obtener productos con paginación
$query = "SELECT p.*, c.nombre as categoria_nombre 
          FROM productos p 
          LEFT JOIN categorias c ON p.categoria_id = c.id 
          WHERE p.activo = 1";
$params = [];
$types = [];

// Aplicar filtros
if (!empty($categoria_id)) {
    $query .= " AND p.categoria_id = ?";
    $params[] = $categoria_id;
    $types[] = PDO::PARAM_INT;
}

if (!empty($busqueda)) {
    $query .= " AND (p.nombre LIKE ? OR p.codigo LIKE ? OR p.codigo_barras LIKE ?)";
    $search_term = "%$busqueda%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
    $types[] = PDO::PARAM_STR;
    $types[] = PDO::PARAM_STR;
    $types[] = PDO::PARAM_STR;
}

if ($solo_stock_bajo) {
    $query .= " AND p.stock <= p.stock_minimo AND p.stock_minimo > 0";
}

if ($solo_con_stock) {
    $query .= " AND p.stock > 0";
}

$query .= " ORDER BY p.nombre ASC LIMIT ? OFFSET ?";
$params[] = $productos_por_pagina;
$params[] = $offset;
$types[] = PDO::PARAM_INT;
$types[] = PDO::PARAM_INT;

// Ejecutar consulta con paginación
try {
    $stmt = $pdo->prepare($query);
    
    // Asignar parámetros con tipos
    foreach ($params as $key => $param) {
        $stmt->bindValue($key + 1, $param, $types[$key] ?? PDO::PARAM_STR);
    }
    
    $stmt->execute();
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $productos = [];
    error_log("Error al obtener productos: " . $e->getMessage());
}

// Obtener categorías para el filtro
try {
    $stmt = $pdo->query("SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre ASC");
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $categorias = [];
    error_log("Error al obtener categorías: " . $e->getMessage());
}

// Inicializar arrays de sesión si no existen
if (!isset($_SESSION['productos_etiquetas'])) {
    $_SESSION['productos_etiquetas'] = [];
}
if (!isset($_SESSION['cantidades_productos'])) {
    $_SESSION['cantidades_productos'] = [];
}

// Procesar selección de productos para etiquetas
if (isset($_POST['seleccionar_productos']) && !empty($_POST['productos'])) {
    $ids = array_map('intval', $_POST['productos']);
    $cantidades = $_POST['cantidad'] ?? [];
    
    if (!empty($ids)) {
        $placeholders = str_repeat('?,', count($ids) - 1) . '?';
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM productos WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $productos_seleccionados_temp = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Agregar productos a la sesión con sus cantidades
            foreach ($productos_seleccionados_temp as $producto) {
                $id = $producto['id'];
                $cantidad = isset($cantidades[$id]) ? max(1, intval($cantidades[$id])) : 1;
                
                // Si el producto ya existe, sumar la cantidad
                if (isset($_SESSION['cantidades_productos'][$id])) {
                    $_SESSION['cantidades_productos'][$id] += $cantidad;
                } else {
                    $_SESSION['productos_etiquetas'][$id] = $producto;
                    $_SESSION['cantidades_productos'][$id] = $cantidad;
                }
            }
        } catch (Exception $e) {
            error_log("Error al obtener productos seleccionados: " . $e->getMessage());
        }
    }
} 

// Procesar actualización de cantidades
elseif (isset($_POST['actualizar_cantidades'])) {
    foreach ($_POST['cantidad_edit'] as $id => $cantidad) {
        $id = intval($id);
        $cantidad = max(1, intval($cantidad));
        
        if (isset($_SESSION['cantidades_productos'][$id])) {
            $_SESSION['cantidades_productos'][$id] = $cantidad;
        }
    }
}

// Procesar eliminación de producto
if (isset($_GET['eliminar_producto'])) {
    $id_eliminar = intval($_GET['eliminar_producto']);
    
    if (isset($_SESSION['productos_etiquetas'][$id_eliminar])) {
        unset($_SESSION['productos_etiquetas'][$id_eliminar]);
        unset($_SESSION['cantidades_productos'][$id_eliminar]);
    }
}

// Obtener productos seleccionados de la sesión
$productos_seleccionados = $_SESSION['productos_etiquetas'] ?? [];
$cantidades_productos = $_SESSION['cantidades_productos'] ?? [];

// Calcular total de etiquetas
$total_etiquetas = 0;
foreach ($cantidades_productos as $cantidad) {
    $total_etiquetas += $cantidad;
}

// Preparar datos para JSON
$productos_expandidos_para_js = [];

foreach ($productos_seleccionados as $id => $producto) {
    $cantidad = $cantidades_productos[$id] ?? 1;
    for ($i = 0; $i < $cantidad; $i++) {
        $productos_expandidos_para_js[] = [
            'id' => $producto['id'] ?? 0,
            'nombre' => $producto['nombre'] ?? '',
            'codigo_barras' => $producto['codigo_barras'] ?? ($producto['codigo'] ?? ''),
            'unique_id' => $producto['id'] . '_' . $i
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generador de Etiquetas 32x25mm</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #f8fafc;
            padding: 20px;
            color: #333;
        }

        .container {
            display: grid;
            grid-template-columns: 300px 1fr 300px;
            gap: 20px;
            max-width: 1600px;
            margin: 0 auto;
            height: calc(100vh - 40px);
        }

        header {
            grid-column: 1 / -1;
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        h1 {
            color: #2c3e50;
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stats-header {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .stat-badge {
            background: #4a6fa5;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* PANEL DE FILTROS */
        .filters-panel {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .filter-group {
            margin-bottom: 15px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }

        .filter-group input[type="text"],
        .filter-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }

        .filter-options {
            background: #f8fafc;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 15px;
            border: 1px solid #e5e7eb;
        }

        .filter-option {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            padding: 8px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .filter-option:hover {
            background-color: #f0f0f0;
        }

        .filter-option.active {
            background-color: #e6f7ff;
            border-left: 3px solid #4a6fa5;
        }

        .filter-option input[type="checkbox"] {
            margin-right: 8px;
        }

        .filter-option i {
            width: 20px;
            text-align: center;
            color: #4a6fa5;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-primary {
            background-color: #4a6fa5;
            color: white;
        }

        .btn-primary:hover {
            background-color: #3a5a8c;
        }

        .btn-secondary {
            background-color: #f0f0f0;
            color: #333;
        }

        .btn-secondary:hover {
            background-color: #e0e0e0;
        }

        /* LISTA DE PRODUCTOS CON PAGINACIÓN */
        .products-list-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            padding: 20px;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .products-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }

        .products-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }

        .select-all {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 15px;
            padding: 10px;
            background: #f8fafc;
            border-radius: 6px;
        }

        .productos-lista {
            flex: 1;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        /* PAGINACIÓN */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 5px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .pagination-btn {
            padding: 5px 10px;
            border: 1px solid #ddd;
            background: white;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.8rem;
            min-width: 30px;
            text-align: center;
        }

        .pagination-btn:hover:not(.disabled) {
            background: #f0f0f0;
        }

        .pagination-btn.active {
            background: #4a6fa5;
            color: white;
            border-color: #4a6fa5;
        }

        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-info {
            font-size: 0.8rem;
            color: #666;
            margin: 0 10px;
        }

        /* ITEMS DE PRODUCTO */
        .producto-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
            transition: background-color 0.2s;
        }

        .producto-item:hover {
            background-color: #f8fafc;
        }

        .producto-item:last-child {
            border-bottom: none;
        }

        .producto-checkbox {
            margin-right: 10px;
            flex-shrink: 0;
        }

        .producto-cantidad {
            margin-right: 10px;
            flex-shrink: 0;
        }

        .cantidad-input {
            width: 50px;
            padding: 4px 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
            font-size: 0.9rem;
        }

        .producto-info {
            flex: 1;
            min-width: 0;
        }

        .producto-nombre {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 2px;
            color: #2c3e50;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .producto-detalles {
            font-size: 0.8rem;
            color: #666;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .stock-badge {
            font-size: 0.75rem;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 600;
        }

        .stock-normal {
            background-color: #d1fae5;
            color: #065f46;
        }

        .stock-bajo {
            background-color: #fef3c7;
            color: #92400e;
        }

        .stock-agotado {
            background-color: #fee2e2;
            color: #991b1b;
        }

        .add-products-btn {
            margin-top: 15px;
            width: 100%;
        }

        /* PANEL DE SELECCIONADOS Y VISTA PREVIA */
        .selected-preview-panel {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        /* SECCIÓN DE PRODUCTOS SELECCIONADOS */
        .selected-section {
            padding: 20px;
            border-bottom: 2px solid #f0f0f0;
            flex-shrink: 0;
        }

        .selected-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .selected-products-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 6px;
            margin-bottom: 15px;
        }

        .selected-product-item {
            display: flex;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
            background: #f8fafc;
        }

        .selected-product-item:last-child {
            border-bottom: none;
        }

        .selected-product-info {
            flex: 1;
            min-width: 0;
        }

        .selected-product-controls {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-shrink: 0;
        }

        .cantidad-edit-input {
            width: 50px;
            padding: 4px 6px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
        }

        .remove-product-btn {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .selected-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin-top: 15px;
        }

        .stat-box {
            background: #f0f7ff;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
        }

        .stat-value {
            font-size: 1.2rem;
            font-weight: bold;
            color: #4a6fa5;
            display: block;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #666;
        }

        .selected-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        /* SECCIÓN DE VISTA PREVIA */
        .preview-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .preview-header {
            padding: 15px 20px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .preview-controls {
            padding: 15px 20px;
            border-bottom: 2px solid #f0f0f0;
            display: flex;
            gap: 10px;
        }

        .preview-container {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            background: #f9f9f9;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            align-content: flex-start;
        }

        /* ETIQUETA */
        .etiqueta {
            width: 152px;
            height: 92px;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 3px;
            padding: 4px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            flex-shrink: 0;
        }

        .nombre {
            font-size: 9px;
            font-weight: 600;
            margin-bottom: 3px;
            word-break: break-word;
            line-height: 1.1;
            max-height: 18px;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
        }

        .barcode-container {
            height: 35px;
            width: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .barcode-container svg {
            max-width: 100%;
            height: 35px !important;
        }

        .empty-state {
            color: #999;
            font-style: italic;
            text-align: center;
            padding: 20px;
            width: 100%;
        }

        /* ESTADOS VACÍOS */
        .no-products, .no-selected {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
            color: #999;
            padding: 20px;
            text-align: center;
        }

        .no-products i, .no-selected i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.3;
        }

        /* RESPONSIVE */
        @media (max-width: 1200px) {
            .container {
                grid-template-columns: 1fr;
                height: auto;
            }
            
            .filters-panel,
            .products-list-container,
            .selected-preview-panel {
                height: auto;
                min-height: 400px;
            }
        }

        @media print {
            body * {
                visibility: hidden;
            }
            
            .selected-preview-panel,
            .selected-preview-panel * {
                visibility: visible;
            }
            
            .selected-preview-panel {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none;
                border: none;
            }
            
            .preview-container {
                background: white;
                padding: 0;
            }
            
            .etiqueta {
                width: 32mm !important;
                height: 25mm !important;
                border: 0.2mm solid #000 !important;
                box-shadow: none !important;
                padding: 1mm !important;
            }
            
            .nombre {
                font-size: 7pt !important;
                max-height: 7mm;
            }
            
            .barcode-container {
                height: 10mm !important;
            }
            
            .barcode-container svg {
                height: 10mm !important;
            }
            
            .selected-section,
            .preview-header,
            .preview-controls {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- HEADER -->
    <header>
        <h1><i class="fas fa-barcode"></i> Generador de Etiquetas 32x25mm</h1>
        <div class="stats-header">
            <div class="stat-badge">
                <i class="fas fa-tags"></i> <?php echo $total_etiquetas; ?> etiquetas
            </div>
        </div>
    </header>

    <!-- CONTENEDOR PRINCIPAL DE 3 COLUMNAS -->
    <div class="container">
        <!-- COLUMNA 1: FILTROS -->
        <div class="filters-panel">
            <h2 style="margin-bottom: 20px; color: #2c3e50; font-size: 1.1rem;">
                <i class="fas fa-filter"></i> Filtros de Búsqueda
            </h2>
            
            <form method="GET" action="">
                <input type="hidden" name="pagina" value="1">
                
                <div class="filter-group">
                    <label for="categoria_id">Categoría:</label>
                    <select name="categoria_id" id="categoria_id">
                        <option value="">Todas las categorías</option>
                        <?php foreach ($categorias as $categoria): ?>
                            <option value="<?php echo $categoria['id']; ?>" <?php echo ($categoria_id == $categoria['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($categoria['nombre']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="busqueda">Buscar:</label>
                    <input type="text" name="busqueda" id="busqueda" 
                           value="<?php echo htmlspecialchars($busqueda); ?>" 
                           placeholder="Nombre, código...">
                </div>
                
                <!-- OPCIONES DE FILTRO DE STOCK -->
                <div class="filter-options">
                    <h3 style="font-size: 0.9rem; color: #4a6fa5; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;">
                        <i class="fas fa-filter"></i> Filtrar por Stock
                    </h3>
                    
                    <div class="filter-option <?php echo $solo_con_stock ? 'active' : ''; ?>">
                        <input type="checkbox" name="con_stock" id="con_stock" <?php echo $solo_con_stock ? 'checked' : ''; ?>>
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <div style="font-weight: 600; font-size: 0.9rem;">Solo con stock</div>
                            <div style="font-size: 0.8rem; color: #666;">Productos con stock disponible</div>
                        </div>
                    </div>
                    
                    <div class="filter-option <?php echo $solo_stock_bajo ? 'active' : ''; ?>">
                        <input type="checkbox" name="stock_bajo" id="stock_bajo" <?php echo $solo_stock_bajo ? 'checked' : ''; ?>>
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <div style="font-weight: 600; font-size: 0.9rem;">Solo stock bajo</div>
                            <div style="font-size: 0.8rem; color: #666;">Stock igual o menor al mínimo</div>
                        </div>
                    </div>
                    
                    <div class="filter-option <?php echo (!$solo_con_stock && !$solo_stock_bajo) ? 'active' : ''; ?>">
                        <input type="radio" name="filtro_stock" id="todos" <?php echo (!$solo_con_stock && !$solo_stock_bajo) ? 'checked' : ''; ?> 
                               onclick="document.getElementById('con_stock').checked = false; document.getElementById('stock_bajo').checked = false;">
                        <i class="fas fa-list"></i>
                        <div>
                            <div style="font-weight: 600; font-size: 0.9rem;">Todos los productos</div>
                            <div style="font-size: 0.8rem; color: #666;">Mostrar todos sin filtro de stock</div>
                        </div>
                    </div>
                </div>
                
                <div class="filter-buttons">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Aplicar Filtros
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Limpiar
                    </a>
                </div>
            </form>
        </div>

        <!-- COLUMNA 2: LISTA DE PRODUCTOS CON PAGINACIÓN -->
        <div class="products-list-container">
            <div class="products-header">
                <h2 style="color: #2c3e50; font-size: 1.1rem;">
                    <i class="fas fa-boxes"></i> Productos Disponibles
                </h2>
                <span style="font-size: 0.9rem; color: #666;">
                    <?php echo $total_productos; ?> productos
                </span>
            </div>
            
            <div class="products-info">
                <span>Página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?></span>
                <span>
                    <?php if ($solo_con_stock): ?>
                        <i class="fas fa-check-circle" style="color: #28a745;"></i> Con stock
                    <?php elseif ($solo_stock_bajo): ?>
                        <i class="fas fa-exclamation-triangle" style="color: #ffc107;"></i> Stock bajo
                    <?php else: ?>
                        <i class="fas fa-list" style="color: #6c757d;"></i> Todos
                    <?php endif; ?>
                </span>
            </div>
            
            <?php if (!empty($productos)): ?>
                <form method="POST" action="" id="form-productos">
                    <div class="select-all">
                        <input type="checkbox" id="select-all">
                        <label for="select-all" style="font-weight: 600; color: #2c3e50;">
                            Seleccionar todos
                        </label>
                    </div>
                    
                    <div class="productos-lista">
                        <?php foreach ($productos as $producto): 
                            // Determinar clase de stock
                            $stock = $producto['stock'] ?? 0;
                            $stock_minimo = $producto['stock_minimo'] ?? 0;
                            
                            if ($stock == 0) {
                                $stock_class = 'stock-agotado';
                                $stock_text = 'Agotado';
                            } elseif ($stock <= $stock_minimo && $stock_minimo > 0) {
                                $stock_class = 'stock-bajo';
                                $stock_text = 'Bajo';
                            } else {
                                $stock_class = 'stock-normal';
                                $stock_text = 'Disponible';
                            }
                        ?>
                            <div class="producto-item">
                                <div class="producto-checkbox">
                                    <input type="checkbox" name="productos[]" value="<?php echo $producto['id']; ?>" 
                                           id="producto-<?php echo $producto['id']; ?>"
                                           class="producto-check">
                                </div>
                                <div class="producto-cantidad">
                                    <input type="number" name="cantidad[<?php echo $producto['id']; ?>]" 
                                           class="cantidad-input" min="1" max="100" value="1">
                                </div>
                                <div class="producto-info">
                                    <div class="producto-nombre"><?php echo htmlspecialchars($producto['nombre'] ?? ''); ?></div>
                                    <div class="producto-detalles">
                                        <span><strong>Código:</strong> <?php echo htmlspecialchars($producto['codigo'] ?? ''); ?></span>
                                        <span>
                                            <span class="stock-badge <?php echo $stock_class; ?>">
                                                <i class="fas fa-box"></i> Stock: <?php echo $stock; ?> (<?php echo $stock_text; ?>)
                                            </span>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- PAGINACIÓN -->
                    <?php if ($total_paginas > 1): ?>
                    <div class="pagination">
                        <!-- Botón primera página -->
                        <button class="pagination-btn <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>"
                                onclick="cambiarPagina(1)"
                                <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-double-left"></i>
                        </button>
                        
                        <!-- Botón página anterior -->
                        <button class="pagination-btn <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>"
                                onclick="cambiarPagina(<?php echo $pagina_actual - 1; ?>)"
                                <?php echo $pagina_actual == 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-left"></i>
                        </button>
                        
                        <!-- Números de página -->
                        <?php 
                        $inicio = max(1, $pagina_actual - 2);
                        $fin = min($total_paginas, $pagina_actual + 2);
                        
                        for ($i = $inicio; $i <= $fin; $i++): 
                        ?>
                            <button class="pagination-btn <?php echo $i == $pagina_actual ? 'active' : ''; ?>"
                                    onclick="cambiarPagina(<?php echo $i; ?>)">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <!-- Botón página siguiente -->
                        <button class="pagination-btn <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>"
                                onclick="cambiarPagina(<?php echo $pagina_actual + 1; ?>)"
                                <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-right"></i>
                        </button>
                        
                        <!-- Botón última página -->
                        <button class="pagination-btn <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>"
                                onclick="cambiarPagina(<?php echo $total_paginas; ?>)"
                                <?php echo $pagina_actual == $total_paginas ? 'disabled' : ''; ?>>
                            <i class="fas fa-angle-double-right"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                    
                    <button type="submit" name="seleccionar_productos" class="btn btn-primary add-products-btn">
                        <i class="fas fa-plus-circle"></i> Agregar seleccionados
                    </button>
                </form>
            <?php else: ?>
                <div class="no-products">
                    <i class="fas fa-search"></i>
                    <p>No se encontraron productos con los filtros aplicados.</p>
                    <?php if ($total_productos > 0): ?>
                        <p style="font-size: 0.9rem; margin-top: 10px;">
                            Hay <?php echo $total_productos; ?> productos en total, pero la consulta no pudo recuperarlos.
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- COLUMNA 3: PRODUCTOS SELECCIONADOS + VISTA PREVIA -->
        <div class="selected-preview-panel">
            <!-- SECCIÓN SUPERIOR: PRODUCTOS SELECCIONADOS -->
            <div class="selected-section">
                <div class="selected-header">
                    <h2 style="color: #2c3e50; font-size: 1.1rem;">
                        <i class="fas fa-list-check"></i> Productos Seleccionados
                    </h2>
                    <?php if (!empty($productos_seleccionados)): ?>
                        <a href="?limpiar_seleccion=1" class="btn btn-secondary" style="padding: 5px 10px; font-size: 0.8rem;">
                            <i class="fas fa-trash"></i> Limpiar
                        </a>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($productos_seleccionados)): ?>
                    <form method="POST" action="" id="form-cantidades">
                        <div class="selected-products-list">
                            <?php foreach ($productos_seleccionados as $id => $producto): 
                                $cantidad = $cantidades_productos[$id] ?? 1;
                            ?>
                                <div class="selected-product-item">
                                    <div class="selected-product-info">
                                        <div class="producto-nombre"><?php echo htmlspecialchars($producto['nombre'] ?? ''); ?></div>
                                        <div class="producto-detalles">
                                            <span><strong>Código:</strong> <?php echo htmlspecialchars($producto['codigo'] ?? ''); ?></span>
                                        </div>
                                    </div>
                                    <div class="selected-product-controls">
                                        <input type="number" name="cantidad_edit[<?php echo $id; ?>]" 
                                               class="cantidad-edit-input" min="1" max="100" 
                                               value="<?php echo $cantidad; ?>">
                                        <a href="?eliminar_producto=<?php echo $id; ?>&categoria_id=<?php echo $categoria_id; ?>&busqueda=<?php echo urlencode($busqueda); ?>&con_stock=<?php echo $solo_con_stock ? '1' : '0'; ?>&stock_bajo=<?php echo $solo_stock_bajo ? '1' : '0'; ?>&pagina=<?php echo $pagina_actual; ?>" class="remove-product-btn" title="Eliminar">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="selected-stats">
                            <div class="stat-box">
                                <span class="stat-value"><?php echo count($productos_seleccionados); ?></span>
                                <span class="stat-label">Productos</span>
                            </div>
                            <div class="stat-box">
                                <span class="stat-value"><?php echo $total_etiquetas; ?></span>
                                <span class="stat-label">Total etiquetas</span>
                            </div>
                            <div class="stat-box">
                                <span class="stat-value"><?php echo ceil($total_etiquetas / 2); ?></span>
                                <span class="stat-label">Filas</span>
                            </div>
                            <div class="stat-box">
                                <span class="stat-value"><?php echo number_format(($total_etiquetas * 25) / 1000, 1); ?>m</span>
                                <span class="stat-label">Papel (80mm)</span>
                            </div>
                        </div>
                        
                        <div class="selected-actions">
                            <button type="submit" name="actualizar_cantidades" class="btn btn-warning" style="flex: 1;">
                                <i class="fas fa-sync-alt"></i> Actualizar
                            </button>
                            <button type="button" onclick="abrirImpresion()" class="btn btn-primary" style="flex: 1;">
                                <i class="fas fa-print"></i> Imprimir
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="no-selected">
                        <i class="fas fa-tags"></i>
                        <p>No hay productos seleccionados</p>
                        <p style="font-size: 0.9rem; margin-top: 10px;">Selecciona productos de la lista</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- SECCIÓN INFERIOR: VISTA PREVIA -->
            <div class="preview-section">
                <div class="preview-header">
                    <h2 style="color: #2c3e50; font-size: 1.1rem;">
                        <i class="fas fa-eye"></i> Vista Previa
                    </h2>
                    <span style="font-size: 0.9rem; color: #666;">
                        Tamaño: 32x25mm
                    </span>
                </div>
                
                <?php if (!empty($productos_seleccionados)): ?>
                    <div class="preview-controls">
                        <button id="btn-imprimir" class="btn btn-primary" style="flex: 1;">
                            <i class="fas fa-print"></i> Imprimir Ahora
                        </button>
                        <button id="btn-generar-pdf" class="btn btn-secondary" disabled style="flex: 1;">
                            <i class="fas fa-file-pdf"></i> PDF (próximamente)
                        </button>
                    </div>
                    
                    <div class="preview-container" id="preview-container">
                        <?php if (!empty($productos_expandidos_para_js)): ?>
                            <?php foreach ($productos_expandidos_para_js as $item): ?>
                                <div class="etiqueta" id="etiqueta-<?php echo $item['unique_id']; ?>">
                                    <div class="nombre"><?php echo htmlspecialchars($item['nombre'] ?? ''); ?></div>
                                    <div class="barcode-container">
                                        <svg class="barcode" id="barcode-<?php echo $item['unique_id']; ?>"></svg>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <p>No hay etiquetas para mostrar</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="no-selected" style="flex: 1; justify-content: center;">
                        <i class="fas fa-barcode" style="font-size: 4rem;"></i>
                        <p style="margin-top: 15px;">La vista previa aparecerá aquí</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let productosSeleccionados = <?php 
            echo json_encode($productos_expandidos_para_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        ?>;

        // Función para cambiar de página
        function cambiarPagina(pagina) {
            const url = new URL(window.location.href);
            url.searchParams.set('pagina', pagina);
            window.location.href = url.toString();
        }

        // Función para abrir ventana de impresión
        function abrirImpresion() {
            window.open('generar_etiquetas.php', '_blank', 'width=400,height=600');
        }

        // Inicializar códigos de barras
        document.addEventListener('DOMContentLoaded', function() {
            // Generar códigos de barras
            productosSeleccionados.forEach(function(producto) {
                const codigo = producto.codigo_barras;
                const barcodeId = 'barcode-' + producto.unique_id;
                
                if (codigo) {
                    try {
                        JsBarcode(`#${barcodeId}`, codigo, {
                            format: "CODE128",
                            width: 1.5,
                            height: 35,
                            displayValue: false,
                            margin: 0,
                            background: "transparent"
                        });
                    } catch (error) {
                        console.error(`Error en ${barcodeId}:`, error);
                    }
                }
            });
            
            // Select all functionality
            const selectAllCheckbox = document.getElementById('select-all');
            const productCheckboxes = document.querySelectorAll('.producto-check');
            
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    productCheckboxes.forEach(checkbox => {
                        checkbox.checked = selectAllCheckbox.checked;
                    });
                });
                
                productCheckboxes.forEach(checkbox => {
                    checkbox.addEventListener('change', function() {
                        const allChecked = Array.from(productCheckboxes).every(cb => cb.checked);
                        selectAllCheckbox.checked = allChecked;
                    });
                });
            }
            
            // Botón imprimir
            const btnImprimir = document.getElementById('btn-imprimir');
            if (btnImprimir) {
                btnImprimir.addEventListener('click', function() {
                    abrirImpresion();
                });
            }
        });
    </script>
</body>
</html>