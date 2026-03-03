<?php
// Para debug - mostrar todos los errores
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once '../../config/database.php';
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

// Construir consulta base
$query = "SELECT p.*, c.nombre as categoria_nombre 
          FROM productos p 
          LEFT JOIN categorias c ON p.categoria_id = c.id 
          WHERE p.activo = 1";
$params = [];

// Aplicar filtros
if (!empty($categoria_id)) {
    $query .= " AND p.categoria_id = ?";
    $params[] = $categoria_id;
}

if (!empty($busqueda)) {
    $query .= " AND (p.nombre LIKE ? OR p.codigo LIKE ? OR p.codigo_barras LIKE ?)";
    $search_term = "%$busqueda%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($solo_stock_bajo) {
    $query .= " AND p.stock <= p.stock_minimo AND p.stock_minimo > 0";
}

$query .= " ORDER BY p.nombre ASC";

// Ejecutar consulta
try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
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
    <title>Sistema de Etiquetas 32x25mm</title>
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
            background-color: #f5f5f5;
            padding: 20px;
            color: #333;
            max-width: 1400px;
            margin: 0 auto;
        }

        .container {
            display: flex;
            flex-wrap: wrap;
            gap: 30px;
        }

        header {
            width: 100%;
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #4a6fa5;
        }

        h1 {
            color: #2c3e50;
            margin-bottom: 10px;
        }

        .selection-panel {
            flex: 1;
            min-width: 350px;
            background-color: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            max-height: 90vh;
            overflow-y: auto;
        }

        .filtros {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .filter-group {
            margin-bottom: 15px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
        }

        .filter-group input[type="text"],
        .filter-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 15px;
        }

        .filter-group input[type="checkbox"] {
            margin-right: 8px;
        }

        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .button-group {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        button, .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            font-size: 15px;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
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

        .btn-success {
            background-color: #28a745;
            color: white;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .btn-warning {
            background-color: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background-color: #e0a800;
        }

        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .productos-lista {
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 5px;
        }

        .producto-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s;
        }

        .producto-item:hover {
            background-color: #f9f9f9;
        }

        .producto-item:last-child {
            border-bottom: none;
        }

        .producto-checkbox {
            margin-right: 15px;
            min-width: 40px;
        }

        .producto-cantidad {
            min-width: 80px;
            margin-right: 15px;
        }

        .cantidad-input {
            width: 60px;
            padding: 5px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
        }

        .producto-info {
            flex: 1;
        }

        .producto-nombre {
            font-weight: 600;
            margin-bottom: 3px;
        }

        .producto-detalles {
            font-size: 13px;
            color: #666;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .producto-stock {
            font-weight: 600;
        }

        .stock-bajo {
            color: #dc3545;
        }

        .stock-normal {
            color: #28a745;
        }

        .preview-panel {
            flex: 2;
            min-width: 500px;
            background-color: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            max-height: 90vh;
            overflow-y: auto;
        }

        .preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .preview-controls {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        /* CONTENEDOR DE ETIQUETAS - SIMPLIFICADO */
        .preview-container {
            background-color: #f9f9f9;
            padding: 20px;
            border-radius: 8px;
            min-height: 500px;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            justify-content: flex-start;
            align-content: flex-start;
        }

        /* ETIQUETA INDIVIDUAL - 32x25mm SIMPLIFICADA */
        .etiqueta {
            width: 155px;
            height: 95px;
            background-color: white;
            border: 1px solid #ddd;
            border-radius: 3px;
            padding: 5px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            page-break-inside: avoid;
            break-inside: avoid;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .nombre {
            font-size: 10px !important;
            font-weight: 600;
            margin-bottom: 2px;
            word-break: break-word;
            line-height: 1.1;
            max-height: 20px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* CÓDIGO DE BARRAS MÁS GRANDE */
        .barcode-container {
            margin: 5px 0;
            height: 40px; /* Aumentado para mejor lectura */
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .barcode-container svg {
            max-width: 100%;
            height: 40px !important; /* Más alto para mejor lectura */
        }

        .empty-state {
            color: #999;
            font-style: italic;
            text-align: center;
            padding: 40px 20px;
            width: 100%;
        }

        .counters {
            display: flex;
            gap: 20px;
            margin-top: 20px;
            padding: 15px;
            background-color: #f0f7ff;
            border-radius: 8px;
        }

        .counter-item {
            text-align: center;
        }

        .counter-value {
            font-size: 24px;
            font-weight: bold;
            color: #4a6fa5;
        }

        .counter-label {
            font-size: 13px;
            color: #666;
        }

        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .select-all {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 10px 15px;
            background-color: #f0f7ff;
            border-radius: 5px;
        }

        .select-all input {
            margin-right: 10px;
        }

        .select-all label {
            font-weight: 600;
            color: #2c3e50;
        }

        /* Lista de productos seleccionados */
        .selected-products-list {
            margin-top: 20px;
            border: 1px solid #eee;
            border-radius: 5px;
            overflow: hidden;
        }

        .selected-product-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            background-color: #f9f9f9;
        }

        .selected-product-item:last-child {
            border-bottom: none;
        }

        .selected-product-info {
            flex: 1;
        }

        .selected-product-controls {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .cantidad-edit-input {
            width: 60px;
            padding: 5px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-align: center;
        }

        .remove-product-btn {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 16px;
            padding: 5px;
        }

        .remove-product-btn:hover {
            color: #c82333;
        }

        /* Impresión - Optimizado para etiquetas 32x25mm SIMPLIFICADAS */
        @media print {
            body {
                background-color: white;
                padding: 2mm !important;
                margin: 0 !important;
                font-size: 8pt !important;
            }
            
            .selection-panel, .preview-controls, header, button, .btn, .counters, .alert, .filter-buttons, .selected-products-list, .selected-product-controls {
                display: none !important;
            }
            
            .preview-panel {
                box-shadow: none;
                padding: 0 !important;
                width: 100% !important;
                margin: 0 !important;
            }
            
            .preview-container {
                box-shadow: none;
                padding: 0 !important;
                background-color: white;
                gap: 0 !important;
                margin: 0 !important;
            }
            
            .etiqueta {
                width: 32mm !important;
                height: 25mm !important;
                border: 0.5px solid #000 !important;
                box-shadow: none !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                margin: 0 !important;
                padding: 1mm !important;
            }
            
            .nombre {
                font-size: 9pt !important;
                max-height: 8mm;
                margin-bottom: 1mm;
            }
            
            .barcode-container {
                height: 12mm !important; /* Más alto para impresión */
                margin: 1mm 0 !important;
            }
            
            .barcode-container svg {
                height: 12mm !important; /* Más alto para impresión */
                max-width: 95% !important;
            }
        }

        @media (max-width: 992px) {
            .container {
                flex-direction: column;
            }
            
            .selection-panel, .preview-panel {
                min-width: 100%;
            }
        }

        @media (max-width: 768px) {
            .preview-controls, .button-group, .filter-buttons, .selected-product-controls {
                flex-direction: column;
            }
            
            .producto-detalles {
                flex-direction: column;
                gap: 5px;
            }
            
            .selected-product-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .selected-product-controls {
                width: 100%;
                justify-content: space-between;
            }
        }
    </style>
</head>
<body>
    <header>
        <h1><i class="fas fa-tags"></i> Sistema de Etiquetas 32x25mm</h1>
        <p class="description">Selecciona productos y especifica cantidades para generar etiquetas</p>
    </header>
    
    <div class="container">
        <!-- Panel de selección de productos -->
        <div class="selection-panel">
            <h2><i class="fas fa-filter"></i> Filtros de Búsqueda</h2>
            
            <form method="GET" action="" class="filtros">
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
                    <label for="busqueda">Buscar (nombre, código):</label>
                    <input type="text" name="busqueda" id="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" placeholder="Nombre, código o código de barras...">
                </div>
                
                <div class="filter-group">
                    <label>
                        <input type="checkbox" name="stock_bajo" id="stock_bajo" <?php echo $solo_stock_bajo ? 'checked' : ''; ?>>
                        Mostrar solo productos con stock bajo
                    </label>
                </div>
                
                <div class="filter-buttons">
                    <button type="submit" class="btn-primary"><i class="fas fa-search"></i> Buscar</button>
                    <a href="?" class="btn-secondary"><i class="fas fa-redo"></i> Limpiar</a>
                </div>
            </form>
            
            <h2><i class="fas fa-boxes"></i> Productos Disponibles</h2>
            
            <?php if (!empty($productos)): ?>
                <form method="POST" action="" id="form-productos">
                    <div class="select-all">
                        <input type="checkbox" id="select-all">
                        <label for="select-all">Seleccionar todos los productos filtrados</label>
                    </div>
                    
                    <div class="productos-lista">
                        <?php foreach ($productos as $producto): 
                            $stock_class = (isset($producto['stock'], $producto['stock_minimo']) && 
                                           $producto['stock'] <= $producto['stock_minimo'] && 
                                           $producto['stock_minimo'] > 0) ? 'stock-bajo' : 'stock-normal';
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
                                        <span><strong>Precio:</strong> $<?php echo number_format($producto['precio_venta'] ?? 0, 2); ?></span>
                                        <span class="producto-stock <?php echo $stock_class; ?>">
                                            <strong>Stock:</strong> <?php echo $producto['stock'] ?? 0; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="button-group">
                        <button type="submit" name="seleccionar_productos" class="btn-success">
                            <i class="fas fa-plus-circle"></i> Agregar seleccionados
                        </button>
                        <button type="button" id="btn-deseleccionar" class="btn-secondary">
                            <i class="fas fa-times-circle"></i> Deseleccionar todos
                        </button>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No se encontraron productos con los filtros aplicados.
                </div>
            <?php endif; ?>
            
            <?php if (!empty($productos_seleccionados)): ?>
                <h2 style="margin-top: 30px;"><i class="fas fa-list-check"></i> Productos Seleccionados</h2>
                
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
                                    <div>
                                        <label>Cantidad:</label>
                                        <input type="number" name="cantidad_edit[<?php echo $id; ?>]" 
                                               class="cantidad-edit-input" min="1" max="100" 
                                               value="<?php echo $cantidad; ?>">
                                    </div>
                                    <a href="?eliminar_producto=<?php echo $id; ?>" class="remove-product-btn" title="Eliminar producto">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="button-group" style="margin-top: 15px;">
                        <button type="submit" name="actualizar_cantidades" class="btn-warning">
                            <i class="fas fa-sync-alt"></i> Actualizar Cantidades
                        </button>
                    </div>
                </form>
                
                <div class="counters">
                    <div class="counter-item">
                        <div class="counter-value"><?php echo count($productos_seleccionados); ?></div>
                        <div class="counter-label">Productos diferentes</div>
                    </div>
                    <div class="counter-item">
                        <div class="counter-value"><?php echo $total_etiquetas; ?></div>
                        <div class="counter-label">Total etiquetas</div>
                    </div>
                    <div class="counter-item">
                        <div class="counter-value"><?php echo ceil($total_etiquetas / 2); ?></div>
                        <div class="counter-label">Filas necesarias</div>
                    </div>
                </div>
                
                <div class="button-group" style="margin-top: 20px;">
                    <a href="generar_etiquetas_simple.php" target="_blank" class="btn-primary">
                        <i class="fas fa-print"></i> Vista de Impresión
                    </a>
                    <a href="limpiar_seleccion.php" class="btn-danger">
                        <i class="fas fa-trash"></i> Limpiar todas las selecciones
                    </a>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Panel de vista previa -->
        <div class="preview-panel">
            <div class="preview-header">
                <h2><i class="fas fa-eye"></i> Vista Previa de Etiquetas (32x25mm)</h2>
                <span id="contador"><?php echo $total_etiquetas; ?> etiquetas</span>
            </div>
            
            <?php if (!empty($productos_seleccionados)): ?>
                <div class="preview-controls">
                    <button id="btn-imprimir" class="btn-primary">
                        <i class="fas fa-print"></i> Imprimir Etiquetas
                    </button>
                    <div style="flex: 1;"></div>
                    <button id="btn-vista-impresion" class="btn-warning">
                        <i class="fas fa-search"></i> Abrir Vista de Impresión
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
                            <p>No hay etiquetas para mostrar. Ajusta las cantidades o selecciona productos.</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="preview-container">
                    <div class="empty-state">
                        <i class="fas fa-tags fa-3x" style="margin-bottom: 20px; color: #ddd;"></i>
                        <h3>No hay productos seleccionados</h3>
                        <p>Selecciona productos desde el panel izquierdo para generar etiquetas.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Variables globales
        let productosSeleccionados = <?php 
            echo json_encode($productos_expandidos_para_js, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        ?>;

        // Inicializar códigos de barras
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Generando códigos de barras para:', productosSeleccionados.length, 'etiquetas');
            
            // Generar códigos de barras para cada etiqueta
            productosSeleccionados.forEach(function(producto) {
                const codigo = producto.codigo_barras;
                const barcodeId = 'barcode-' + producto.unique_id;
                
                if (codigo) {
                    try {
                        JsBarcode(`#${barcodeId}`, codigo, {
                            format: "CODE128",
                            width: 2, // Un poco más ancho para mejor lectura
                            height: 40, // Más alto para mejor lectura
                            displayValue: false,
                            margin: 0,
                            background: "transparent"
                        });
                        console.log(`✓ Generado: ${barcodeId} - ${codigo}`);
                    } catch (error) {
                        console.error(`✗ Error en ${barcodeId}:`, error);
                        // Si falla, intentar con el código numérico
                        if (producto.id) {
                            try {
                                JsBarcode(`#${barcodeId}`, producto.id.toString(), {
                                    format: "CODE128",
                                    width: 2,
                                    height: 40,
                                    displayValue: false,
                                    margin: 0
                                });
                            } catch (error2) {
                                console.error(`✗ Error con ID:`, error2);
                            }
                        }
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
            
            // Botón deseleccionar todos
            const btnDeseleccionar = document.getElementById('btn-deseleccionar');
            if (btnDeseleccionar) {
                btnDeseleccionar.addEventListener('click', function() {
                    productCheckboxes.forEach(checkbox => {
                        checkbox.checked = false;
                    });
                    if (selectAllCheckbox) {
                        selectAllCheckbox.checked = false;
                    }
                });
            }
            
            // Botón imprimir
            const btnImprimir = document.getElementById('btn-imprimir');
            if (btnImprimir) {
                btnImprimir.addEventListener('click', function() {
                    window.print();
                });
            }
            
            // Botón vista impresión
            const btnVistaImpresion = document.getElementById('btn-vista-impresion');
            if (btnVistaImpresion) {
                btnVistaImpresion.addEventListener('click', function() {
                    window.open('generar_etiquetas_simple.php', '_blank');
                });
            }
        });
    </script>
</body>
</html>