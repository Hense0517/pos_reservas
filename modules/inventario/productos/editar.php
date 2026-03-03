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
    die("Error: No se encuentra header.php en $header_path");
}
include $header_path;

// Verificar permisos usando la clase Auth
if (!$auth->hasPermission('productos', 'editar')) {
    $_SESSION['error'] = "No tienes permisos para editar productos";
    header('Location: ' . BASE_URL . 'modules/inventario/productos/index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Obtener el ID del producto a editar
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "ID de producto no válido";
    header('Location: index.php');
    exit;
}

$producto_id = intval($_GET['id']);

// Obtener categorías para el select
$query_categorias = "SELECT * FROM categorias WHERE activo = 1 ORDER BY nombre";
$stmt_categorias = $db->prepare($query_categorias);
$stmt_categorias->execute();
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);

// Obtener marcas para el select
$query_marcas = "SELECT * FROM marcas WHERE activo = 1 ORDER BY nombre";
$stmt_marcas = $db->prepare($query_marcas);
$stmt_marcas->execute();
$marcas = $stmt_marcas->fetchAll(PDO::FETCH_ASSOC);

// Obtener tipos de atributos activos
$tipos_atributo = [];
try {
    $query_tipos = "SELECT * FROM tipos_atributo WHERE activo = 1 ORDER BY 
                    CASE 
                        WHEN nombre = 'Talla' THEN 1
                        WHEN nombre = 'Color' THEN 2
                        ELSE 3
                    END, nombre";
    $stmt_tipos = $db->prepare($query_tipos);
    $stmt_tipos->execute();
    $tipos_atributo = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error cargando tipos de atributos: " . $e->getMessage());
}

// Obtener los datos actuales del producto
$query = "SELECT p.*, c.nombre as categoria_nombre, m.nombre as marca_nombre 
          FROM productos p 
          LEFT JOIN categorias c ON p.categoria_id = c.id 
          LEFT JOIN marcas m ON p.marca_id = m.id 
          WHERE p.id = ? AND p.activo = 1";
$stmt = $db->prepare($query);
$stmt->execute([$producto_id]);
$producto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$producto) {
    $_SESSION['error'] = "Producto no encontrado o ha sido eliminado";
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
                        WHERE pa.producto_id = ?";
    $stmt_atributos = $db->prepare($query_atributos);
    $stmt_atributos->execute([$producto_id]);
    $atributos_producto = $stmt_atributos->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error cargando atributos del producto: " . $e->getMessage());
}

// Organizar atributos por tipo para fácil acceso
$atributos_por_tipo = [];
foreach ($atributos_producto as $attr) {
    $atributos_por_tipo[$attr['tipo_atributo_id']] = $attr;
}

// Determinar el tipo de talla actual
$tipo_talla_actual = 'alfabetica'; // Por defecto
if (!empty($producto['talla'])) {
    // Si es numérico (entre 1 y 50) y no es una letra
    if (is_numeric($producto['talla']) && $producto['talla'] >= 1 && $producto['talla'] <= 50) {
        $tipo_talla_actual = 'numerica';
    }
}

$error = null;
$success = null;

// Procesar formulario de actualización
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $codigo = trim($_POST['codigo']);
    $codigo_barras = trim($_POST['codigo_barras']);
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $categoria_id = $_POST['categoria_id'] ?: null;
    $marca_id = $_POST['marca_id'] ?: null;
    
    // Limpiar el formato de moneda antes de guardar
    $precio_compra = floatval(str_replace('.', '', $_POST['precio_compra'] ?? 0));
    $precio_venta = floatval(str_replace('.', '', $_POST['precio_venta'] ?? 0));
    
    $stock = intval($_POST['stock']);
    $stock_minimo = intval($_POST['stock_minimo']);
    $es_servicio = isset($_POST['es_servicio']) ? 1 : 0;
    $tiene_atributos = isset($_POST['tiene_atributos']) ? 1 : 0;
    
    // Procesar talla según el tipo seleccionado
    $talla = null;
    if ($tiene_atributos) {
        $tipo_talla = $_POST['tipo_talla'] ?? 'alfabetica';
        if ($tipo_talla === 'alfabetica') {
            $talla = $_POST['talla_alfabetica'] ?: null;
        } else {
            $talla = $_POST['talla_numerica'] ?: null;
        }
    }
    
    $color = $tiene_atributos ? (trim($_POST['color']) ?: null) : null;

    // Validaciones básicas
    if (empty($codigo) || empty($nombre) || $precio_compra <= 0 || $precio_venta <= 0) {
        $error = "Todos los campos obligatorios deben ser completados correctamente.";
    } else if ($precio_venta < $precio_compra) {
        $error = "El precio de venta no puede ser menor al precio de compra.";
    } else if (!$es_servicio && ($stock < 0 || $stock_minimo < 0)) {
        $error = "Los valores de stock no pueden ser negativos.";
    } else {
        try {
            $db->beginTransaction();

            // Verificar si el código ya existe (excluyendo el producto actual)
            $query = "SELECT id FROM productos WHERE codigo = ? AND id != ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$codigo, $producto_id]);
            
            if ($stmt->fetch()) {
                $error = "El código del producto ya existe.";
            } else if (!empty($codigo_barras)) {
                // Verificar si el código de barras ya existe (excluyendo el producto actual)
                $query = "SELECT id FROM productos WHERE codigo_barras = ? AND id != ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$codigo_barras, $producto_id]);
                
                if ($stmt->fetch()) {
                    $error = "El código de barras ya existe.";
                }
            }
            
            // Validar que si tiene atributos activados, al menos un atributo debe estar completado
            if ($tiene_atributos && empty($talla) && empty($color)) {
                $error = "Si activas los atributos, debes completar al menos uno (talla o color).";
            }
            
            if (!$error) {
                // Si no hay código de barras, dejarlo como NULL
                $codigo_barras_valor = empty($codigo_barras) ? null : $codigo_barras;
                
                // Si es servicio, establecer stock en 0
                if ($es_servicio) {
                    $stock = 0;
                    $stock_minimo = 0;
                }
                
                $query = "UPDATE productos SET 
                          codigo = ?, 
                          codigo_barras = ?, 
                          nombre = ?, 
                          descripcion = ?, 
                          categoria_id = ?, 
                          marca_id = ?, 
                          precio_compra = ?, 
                          precio_venta = ?, 
                          stock = ?, 
                          stock_minimo = ?, 
                          talla = ?, 
                          color = ?, 
                          es_servicio = ?,
                          updated_at = NOW()
                          WHERE id = ?";
                
                $stmt = $db->prepare($query);
                
                if ($stmt->execute([$codigo, $codigo_barras_valor, $nombre, $descripcion, $categoria_id, $marca_id, 
                                    $precio_compra, $precio_venta, $stock, $stock_minimo, $talla, $color, $es_servicio, $producto_id])) {
                    
                    // Eliminar atributos dinámicos anteriores
                    $delete_atributos = "DELETE FROM producto_atributos WHERE producto_id = ?";
                    $delete_stmt = $db->prepare($delete_atributos);
                    $delete_stmt->execute([$producto_id]);
                    
                    // Guardar atributos dinámicos seleccionados (valores predefinidos)
                    if (isset($_POST['atributos']) && is_array($_POST['atributos'])) {
                        foreach ($_POST['atributos'] as $tipo_id => $valor_id) {
                            if (!empty($valor_id)) {
                                $query_atributo = "INSERT INTO producto_atributos (producto_id, tipo_atributo_id, valor_atributo_id) 
                                                  VALUES (?, ?, ?)";
                                $stmt_atributo = $db->prepare($query_atributo);
                                $stmt_atributo->execute([$producto_id, $tipo_id, $valor_id]);
                            }
                        }
                    }

                    // Guardar atributos de texto libre
                    if (isset($_POST['atributos_texto']) && is_array($_POST['atributos_texto'])) {
                        foreach ($_POST['atributos_texto'] as $tipo_id => $valor_texto) {
                            if (!empty($valor_texto)) {
                                $query_atributo = "INSERT INTO producto_atributos (producto_id, tipo_atributo_id, valor_texto) 
                                                  VALUES (?, ?, ?)";
                                $stmt_atributo = $db->prepare($query_atributo);
                                $stmt_atributo->execute([$producto_id, $tipo_id, $valor_texto]);
                            }
                        }
                    }
                    
                    // Registrar en auditoría si cambió el stock
                    if (!$es_servicio && $producto['stock'] != $stock) {
                        $diferencia = $stock - $producto['stock'];
                        $tipo_movimiento = $diferencia > 0 ? 'ajuste' : 'ajuste';
                        $query_audit = "INSERT INTO auditoria_stock 
                                       (producto_id, tipo_movimiento, cantidad, stock_anterior, stock_nuevo, usuario_id, referencia, motivo) 
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt_audit = $db->prepare($query_audit);
                        $referencia = "Ajuste manual";
                        $motivo = "Edición de producto";
                        $stmt_audit->execute([$producto_id, $tipo_movimiento, abs($diferencia), $producto['stock'], $stock, $_SESSION['usuario_id'], $referencia, $motivo]);
                    }
                    
                    $db->commit();
                    
                    $_SESSION['success'] = "Producto actualizado correctamente.";
                    header('Location: index.php');
                    ob_end_flush();
                    exit;
                } else {
                    $db->rollBack();
                    $error = "Error al actualizar el producto.";
                }
            } else {
                $db->rollBack();
            }
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error en editar producto: " . $e->getMessage());
            $error = "Error al procesar la solicitud: " . $e->getMessage();
        }
    }
    
    // Si hay error, actualizar los datos del producto con los valores del formulario
    $producto = array_merge($producto, $_POST);
}
?>

<style>
/* Estilos para el toggle switch */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.dot {
    transition: all 0.3s;
}

input:checked ~ .dot {
    transform: translateX(100%);
    background-color: #48bb78;
}

input:checked ~ .block {
    background-color: #48bb78;
}

/* Estilos para pestañas */
.tab-container {
    border-bottom: 2px solid #e5e7eb;
    margin-bottom: 1.5rem;
}

.tab-button {
    padding: 0.75rem 1.5rem;
    font-weight: 500;
    color: #6b7280;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s ease;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.tab-button:hover {
    color: #4f46e5;
}

.tab-button.active {
    color: #4f46e5;
    border-bottom-color: #4f46e5;
}

.tab-content {
    display: none;
    animation: fadeIn 0.3s ease;
}

.tab-content.active {
    display: block;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Estilo para campos de moneda */
.moneda-input {
    text-align: right;
}

/* Estilos para atributos */
.atributo-card {
    transition: all 0.2s ease;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    padding: 1rem;
    background: white;
}

.atributo-card:hover {
    border-color: #6366f1;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.atributo-titulo {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #f3f4f6;
}

.atributo-icono {
    width: 2rem;
    height: 2rem;
    background: #eef2ff;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4f46e5;
}

.hidden {
    display: none;
}

/* Estilos para el contenedor de talla */
.talla-container {
    background: #f9fafb;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1rem;
    border-left: 4px solid #6366f1;
}

.talla-opciones {
    display: flex;
    gap: 1rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.talla-opcion {
    flex: 1;
    min-width: 200px;
}

/* Estilos para código de barras */
.barcode-container {
    background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
    border: 2px dashed #d1d5db;
    border-radius: 1rem;
    padding: 2rem;
    text-align: center;
}

.barcode-preview {
    background: white;
    padding: 2rem;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    display: inline-block;
}

/* Estilo para el badge de servicio */
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
</style>

<div class="max-w-6xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-600 to-indigo-700">
            <div class="flex justify-between items-center">
                <div>
                    <h2 class="text-xl font-semibold text-white">Editar Producto</h2>
                    <p class="text-blue-100 text-sm mt-1">Actualiza los datos del producto #<?php echo $producto['id']; ?></p>
                </div>
                <div class="bg-white bg-opacity-20 px-3 py-1 rounded-full text-sm text-white">
                    ID: <?php echo $producto['id']; ?>
                </div>
            </div>
        </div>
        
        <form method="POST" class="p-6" id="productoForm">
            <?php if ($error): ?>
                <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>

            <!-- Pestañas -->
            <div class="tab-container">
                <button type="button" class="tab-button active" onclick="cambiarTab('basico', this)">
                    <i class="fas fa-info-circle"></i>
                    Información Básica
                </button>
                <button type="button" class="tab-button" onclick="cambiarTab('precios', this)">
                    <i class="fas fa-dollar-sign"></i>
                    Precios y Stock
                </button>
                <button type="button" class="tab-button" onclick="cambiarTab('atributos', this)">
                    <i class="fas fa-tags"></i>
                    Atributos
                </button>
                <button type="button" class="tab-button" onclick="cambiarTab('barcode', this)">
                    <i class="fas fa-barcode"></i>
                    Código de Barras
                </button>
            </div>

            <!-- Pestaña: Información Básica -->
            <div id="tab-basico" class="tab-content active">
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="codigo" class="block text-sm font-medium text-gray-700 mb-1">Código Interno *</label>
                            <input type="text" id="codigo" name="codigo" required
                                   value="<?php echo htmlspecialchars($producto['codigo']); ?>"
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="categoria_id" class="block text-sm font-medium text-gray-700 mb-1">Categoría</label>
                            <select id="categoria_id" name="categoria_id"
                                    class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Sin categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                <option value="<?php echo $categoria['id']; ?>" 
                                    <?php echo ($producto['categoria_id'] == $categoria['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($categoria['nombre']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="marca_id" class="block text-sm font-medium text-gray-700 mb-1">Marca</label>
                            <select id="marca_id" name="marca_id"
                                    class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Sin marca</option>
                                <?php foreach ($marcas as $marca): ?>
                                <option value="<?php echo $marca['id']; ?>" 
                                    <?php echo ($producto['marca_id'] == $marca['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($marca['nombre']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="nombre" class="block text-sm font-medium text-gray-700 mb-1">Nombre del Producto *</label>
                            <input type="text" id="nombre" name="nombre" required
                                   value="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                    
                    <div>
                        <label for="descripcion" class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                        <textarea id="descripcion" name="descripcion" rows="3"
                                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($producto['descripcion'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="flex items-center pt-2">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" id="es_servicio" name="es_servicio" value="1" class="sr-only" 
                                   <?php echo ($producto['es_servicio'] ?? 0) == 1 ? 'checked' : ''; ?>>
                            <div class="block bg-gray-600 w-14 h-8 rounded-full"></div>
                            <div class="dot absolute left-1 top-1 bg-white w-6 h-6 rounded-full transition"></div>
                            <span class="ml-3 text-sm text-gray-700">
                                <i class="fas fa-concierge-bell text-blue-600 mr-1"></i>
                                Es un servicio (no maneja inventario)
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Pestaña: Precios y Stock -->
            <div id="tab-precios" class="tab-content">
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="precio_compra" class="block text-sm font-medium text-gray-700 mb-1">Precio de Compra *</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <span class="text-gray-500">$</span>
                                </div>
                                <input type="text" id="precio_compra" name="precio_compra" required
                                       value="<?php echo isset($producto['precio_compra']) ? number_format($producto['precio_compra'], 0, '', '.') : ''; ?>"
                                       class="mt-1 block w-full pl-8 border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 moneda-input"
                                       placeholder="0">
                            </div>
                        </div>
                        
                        <div>
                            <label for="precio_venta" class="block text-sm font-medium text-gray-700 mb-1">Precio de Venta *</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                    <span class="text-gray-500">$</span>
                                </div>
                                <input type="text" id="precio_venta" name="precio_venta" required
                                       value="<?php echo isset($producto['precio_venta']) ? number_format($producto['precio_venta'], 0, '', '.') : ''; ?>"
                                       class="mt-1 block w-full pl-8 border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 moneda-input"
                                       placeholder="0">
                            </div>
                            
                            <?php if (!($producto['es_servicio'] ?? 0)): ?>
                            <div class="mt-2 text-sm">
                                <?php 
                                $margen = $producto['precio_venta'] - $producto['precio_compra'];
                                $porcentaje_margen = $producto['precio_compra'] > 0 ? ($margen / $producto['precio_compra']) * 100 : 0;
                                ?>
                                <span class="font-medium">Margen actual:</span> 
                                <span class="<?php echo $porcentaje_margen >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    $<?php echo number_format($margen, 0); ?> (<?php echo number_format($porcentaje_margen, 1); ?>%)
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Campos de stock (se ocultan si es servicio) -->
                    <div id="stockFields" class="grid grid-cols-1 md:grid-cols-2 gap-4 <?php echo ($producto['es_servicio'] ?? 0) == 1 ? 'hidden' : ''; ?>">
                        <div>
                            <label for="stock" class="block text-sm font-medium text-gray-700 mb-1">Stock Actual *</label>
                            <input type="number" id="stock" name="stock" min="0" required
                                   value="<?php echo $producto['stock']; ?>"
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            
                            <div class="mt-2 text-sm">
                                <?php 
                                $stock_class = $producto['stock'] <= $producto['stock_minimo'] ? 
                                    'text-red-600' : 'text-green-600';
                                $stock_text = $producto['stock'] <= $producto['stock_minimo'] ? 'Stock bajo' : 'Stock normal';
                                ?>
                                <span class="font-medium">Estado:</span> 
                                <span class="<?php echo $stock_class; ?>">
                                    <?php echo $stock_text; ?>
                                </span>
                            </div>
                        </div>
                        
                        <div>
                            <label for="stock_minimo" class="block text-sm font-medium text-gray-700 mb-1">Stock Mínimo *</label>
                            <input type="number" id="stock_minimo" name="stock_minimo" min="0" required 
                                   value="<?php echo $producto['stock_minimo']; ?>"
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>

                    <!-- Mensaje para servicios -->
                    <div id="servicioMessage" class="<?php echo ($producto['es_servicio'] ?? 0) == 1 ? '' : 'hidden'; ?> p-4 bg-purple-50 border border-purple-200 rounded-lg">
                        <div class="flex items-center">
                            <span class="badge-servicio mr-3">
                                <i class="fas fa-concierge-bell"></i> Servicio
                            </span>
                            <p class="text-sm text-purple-700">Este producto es un servicio, no requiere control de inventario.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pestaña: Atributos (TODOS juntos) -->
            <div id="tab-atributos" class="tab-content">
                <div class="space-y-4">
                    <!-- Toggle principal para activar/desactivar TODOS los atributos -->
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                        <div>
                            <h3 class="font-medium text-gray-800">Atributos del Producto</h3>
                            <p class="text-sm text-gray-600">Activa esta opción para agregar atributos al producto (todos son opcionales)</p>
                        </div>
                        <div>
                            <label for="tiene_atributos" class="flex items-center cursor-pointer">
                                <div class="relative">
                                    <?php 
                                    $tiene_atributos_checked = !empty($producto['talla']) || !empty($producto['color']) || !empty($atributos_producto);
                                    ?>
                                    <input type="checkbox" id="tiene_atributos" name="tiene_atributos" 
                                           class="sr-only" <?php echo $tiene_atributos_checked ? 'checked' : ''; ?>>
                                    <div class="block bg-gray-600 w-14 h-8 rounded-full"></div>
                                    <div class="dot absolute left-1 top-1 bg-white w-6 h-6 rounded-full transition"></div>
                                </div>
                                <div class="ml-3 text-gray-700 font-medium">
                                    <span id="atributosEstado"><?php echo $tiene_atributos_checked ? 'Con atributos' : 'Sin atributos'; ?></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Contenedor de TODOS los atributos (se muestra cuando está activado) -->
                    <div id="atributosContainer" class="<?php echo $tiene_atributos_checked ? '' : 'hidden'; ?> space-y-4">
                        
                        <!-- ATRIBUTO ESPECIAL: TALLA (con opciones numérica/alfabética) - OPCIONAL -->
                        <div class="talla-container">
                            <div class="flex items-center mb-3">
                                <div class="atributo-icono mr-2">
                                    <i class="fas fa-ruler"></i>
                                </div>
                                <h4 class="font-medium text-gray-800">Talla (opcional)</h4>
                            </div>
                            
                            <div class="talla-opciones">
                                <label class="flex items-center space-x-2 talla-opcion p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                                    <input type="radio" name="tipo_talla" value="alfabetica" 
                                           <?php echo ($tipo_talla_actual == 'alfabetica') ? 'checked' : ''; ?>
                                           onchange="cambiarTipoTalla()">
                                    <span class="text-sm font-medium">Talla Alfabética</span>
                                    <span class="text-xs text-gray-500">(XXS, XS, S, M, L, XL, XXL, XXXL, XXXXL)</span>
                                </label>
                                
                                <label class="flex items-center space-x-2 talla-opcion p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                                    <input type="radio" name="tipo_talla" value="numerica" 
                                           <?php echo ($tipo_talla_actual == 'numerica') ? 'checked' : ''; ?>
                                           onchange="cambiarTipoTalla()">
                                    <span class="text-sm font-medium">Talla Numérica</span>
                                    <span class="text-xs text-gray-500">(1 al 50)</span>
                                </label>
                            </div>

                            <!-- Selector de talla alfabética -->
                            <div id="tallaAlfabeticaContainer" class="mt-3 <?php echo ($tipo_talla_actual == 'alfabetica') ? '' : 'hidden'; ?>">
                                <select id="talla_alfabetica" name="talla_alfabetica" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">-- Seleccionar talla alfabética (opcional) --</option>
                                    <option value="XXS" <?php echo ($producto['talla'] == 'XXS') ? 'selected' : ''; ?>>XXS - Extra Extra Small</option>
                                    <option value="XS" <?php echo ($producto['talla'] == 'XS') ? 'selected' : ''; ?>>XS - Extra Small</option>
                                    <option value="S" <?php echo ($producto['talla'] == 'S') ? 'selected' : ''; ?>>S - Small</option>
                                    <option value="M" <?php echo ($producto['talla'] == 'M') ? 'selected' : ''; ?>>M - Medium</option>
                                    <option value="L" <?php echo ($producto['talla'] == 'L') ? 'selected' : ''; ?>>L - Large</option>
                                    <option value="XL" <?php echo ($producto['talla'] == 'XL') ? 'selected' : ''; ?>>XL - Extra Large</option>
                                    <option value="XXL" <?php echo ($producto['talla'] == 'XXL') ? 'selected' : ''; ?>>XXL - 2X Large</option>
                                    <option value="XXXL" <?php echo ($producto['talla'] == 'XXXL') ? 'selected' : ''; ?>>XXXL - 3X Large</option>
                                    <option value="XXXXL" <?php echo ($producto['talla'] == 'XXXXL') ? 'selected' : ''; ?>>XXXXL - 4X Large</option>
                                </select>
                            </div>

                            <!-- Selector de talla numérica -->
                            <div id="tallaNumericaContainer" class="mt-3 <?php echo ($tipo_talla_actual == 'numerica') ? '' : 'hidden'; ?>">
                                <select id="talla_numerica" name="talla_numerica" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">-- Seleccionar talla numérica (opcional) --</option>
                                    <?php for ($i = 1; $i <= 50; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($producto['talla'] == $i) ? 'selected' : ''; ?>>
                                        Talla <?php echo $i; ?>
                                    </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>

                        <!-- ATRIBUTO: Color (opcional) -->
                        <div class="atributo-card">
                            <div class="atributo-titulo">
                                <div class="atributo-icono">
                                    <i class="fas fa-palette"></i>
                                </div>
                                <h4 class="font-medium text-gray-800">Color (opcional)</h4>
                            </div>
                            <input type="text" id="color" name="color"
                                   value="<?php echo htmlspecialchars($producto['color'] ?? ''); ?>"
                                   placeholder="Ej: Rojo, Azul, Negro... (opcional)"
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                   list="coloresSugeridos">
                            <datalist id="coloresSugeridos">
                                <option value="Rojo">
                                <option value="Azul">
                                <option value="Verde">
                                <option value="Negro">
                                <option value="Blanco">
                                <option value="Gris">
                                <option value="Amarillo">
                                <option value="Naranja">
                                <option value="Rosa">
                                <option value="Morado">
                                <option value="Marrón">
                                <option value="Beige">
                            </datalist>
                        </div>

                        <!-- OTROS ATRIBUTOS DINÁMICOS (todos opcionales) -->
                        <?php foreach ($tipos_atributo as $tipo): ?>
                            <?php if ($tipo['nombre'] !== 'Talla' && $tipo['nombre'] !== 'Color'): ?>
                            <?php
                            // Obtener valores para este tipo
                            $query_valores = "SELECT * FROM valores_atributo WHERE tipo_atributo_id = ? AND activo = 1 ORDER BY orden, valor_numerico, valor";
                            $stmt_valores = $db->prepare($query_valores);
                            $stmt_valores->execute([$tipo['id']]);
                            $valores = $stmt_valores->fetchAll(PDO::FETCH_ASSOC);
                            
                            // Obtener valor actual si existe
                            $valor_actual = $atributos_por_tipo[$tipo['id']] ?? null;
                            $valor_seleccionado = $valor_actual['valor_atributo_id'] ?? '';
                            $valor_texto_actual = $valor_actual['valor_texto'] ?? '';
                            ?>
                            
                            <div class="atributo-card">
                                <div class="atributo-titulo">
                                    <div class="atributo-icono">
                                        <i class="<?php echo $tipo['icono']; ?>"></i>
                                    </div>
                                    <div>
                                        <h4 class="font-medium text-gray-800"><?php echo htmlspecialchars($tipo['nombre']); ?> (opcional)</h4>
                                        <?php if ($tipo['unidad']): ?>
                                            <span class="text-xs text-gray-500">Unidad: <?php echo $tipo['unidad']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if (!empty($valores)): ?>
                                    <?php if ($tipo['tipo_dato'] == 'select'): ?>
                                    <select name="atributos[<?php echo $tipo['id']; ?>]" class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
                                        <option value="">-- Seleccionar (opcional) --</option>
                                        <?php foreach ($valores as $valor): ?>
                                        <option value="<?php echo $valor['id']; ?>" <?php echo ($valor_seleccionado == $valor['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($valor['valor']); ?>
                                            <?php if ($tipo['unidad']): ?> <?php echo $tipo['unidad']; ?><?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php elseif ($tipo['tipo_dato'] == 'radio'): ?>
                                        <div class="space-y-1 max-h-40 overflow-y-auto p-2">
                                            <?php foreach ($valores as $valor): ?>
                                            <label class="flex items-center space-x-2 text-sm">
                                                <input type="radio" name="atributos[<?php echo $tipo['id']; ?>]" value="<?php echo $valor['id']; ?>" <?php echo ($valor_seleccionado == $valor['id']) ? 'checked' : ''; ?>>
                                                <span><?php echo htmlspecialchars($valor['valor']); ?></span>
                                                <?php if ($tipo['unidad']): ?><span class="text-xs text-gray-500"><?php echo $tipo['unidad']; ?></span><?php endif; ?>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($tipo['tipo_dato'] == 'texto'): ?>
                                    <input type="text" name="atributos_texto[<?php echo $tipo['id']; ?>]" 
                                           value="<?php echo htmlspecialchars($valor_texto_actual); ?>"
                                           class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
                                           placeholder="Ingrese <?php echo strtolower($tipo['nombre']); ?> (opcional)">
                                    <?php elseif ($tipo['tipo_dato'] == 'numero'): ?>
                                    <input type="number" name="atributos_texto[<?php echo $tipo['id']; ?>]" 
                                           value="<?php echo htmlspecialchars($valor_texto_actual); ?>"
                                           class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
                                           placeholder="Ingrese <?php echo strtolower($tipo['nombre']); ?> (opcional)">
                                    <?php elseif ($tipo['tipo_dato'] == 'decimal'): ?>
                                    <input type="number" step="0.01" name="atributos_texto[<?php echo $tipo['id']; ?>]" 
                                           value="<?php echo htmlspecialchars($valor_texto_actual); ?>"
                                           class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
                                           placeholder="Ingrese <?php echo strtolower($tipo['nombre']); ?> (opcional)">
                                    <?php else: ?>
                                    <p class="text-xs text-gray-400 text-center py-2">No hay valores predefinidos</p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php if ($tipo['descripcion']): ?>
                                <p class="text-xs text-gray-500 mt-1"><?php echo htmlspecialchars($tipo['descripcion']); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <div class="mt-4 p-3 bg-blue-50 rounded-lg text-sm text-blue-700">
                            <i class="fas fa-info-circle mr-2"></i>
                            Todos los atributos son opcionales. Puedes gestionar más tipos de atributos en <a href="../atributos/tipos.php" class="font-medium underline">Gestión de Atributos</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pestaña: Código de Barras -->
            <div id="tab-barcode" class="tab-content">
                <div class="space-y-4">
                    <div class="flex items-center space-x-2">
                        <div class="flex-1">
                            <label for="codigo_barras" class="block text-sm font-medium text-gray-700 mb-1">Código de Barras</label>
                            <input type="text" id="codigo_barras" name="codigo_barras"
                                   value="<?php echo htmlspecialchars($producto['codigo_barras'] ?? ''); ?>"
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <button type="button" id="generarCodigoBarras" class="mt-6 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">
                            <i class="fas fa-sync-alt mr-2"></i>
                            Generar Nuevo
                        </button>
                    </div>
                    
                    <p class="text-xs text-gray-500">Código EAN-13 de 13 dígitos (opcional)</p>

                    <div class="barcode-container mt-6">
                        <div class="barcode-preview">
                            <svg id="barcode" class="mx-auto"></svg>
                            <p id="barcodeText" class="text-sm font-mono text-gray-600 mt-2"><?php echo htmlspecialchars($producto['codigo_barras'] ?? ''); ?></p>
                        </div>
                        <p class="text-sm text-gray-500 mt-2">Vista previa del código de barras</p>
                    </div>
                </div>
            </div>
            
            <!-- Información adicional del producto -->
            <div class="mt-6 pt-4 border-t border-gray-200">
                <h3 class="text-lg font-medium text-gray-800 mb-3">Información del Producto</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-gray-600">
                    <div>
                        <p><span class="font-medium">Creado:</span> <?php echo date('d/m/Y H:i', strtotime($producto['created_at'])); ?></p>
                        <p><span class="font-medium">Última actualización:</span> 
                            <?php echo !empty($producto['updated_at']) ? date('d/m/Y H:i', strtotime($producto['updated_at'])) : 'Nunca'; ?>
                        </p>
                    </div>
                    <div>
                        <p><span class="font-medium">Marca actual:</span> <?php echo !empty($producto['marca_nombre']) ? htmlspecialchars($producto['marca_nombre']) : 'Sin marca'; ?></p>
                        <p><span class="font-medium">Categoría actual:</span> <?php echo !empty($producto['categoria_nombre']) ? htmlspecialchars($producto['categoria_nombre']) : 'Sin categoría'; ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Botones de acción -->
            <div class="flex justify-end space-x-3 pt-6 mt-4 border-t border-gray-200">
                <a href="index.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <i class="fas fa-arrow-left mr-2"></i> Volver
                </a>
                <a href="ver.php?id=<?php echo $producto_id; ?>" class="bg-green-600 hover:bg-green-700 text-white py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium">
                    <i class="fas fa-eye mr-2"></i> Ver Detalles
                </a>
                <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                    <i class="fas fa-save mr-2"></i>
                    Actualizar Producto
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Incluir la librería JsBarcode -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>

<script>
// Función para cambiar de pestaña
function cambiarTab(tabId, element) {
    // Ocultar todas las pestañas
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Mostrar la pestaña seleccionada
    document.getElementById('tab-' + tabId).classList.add('active');
    
    // Actualizar botones activos
    document.querySelectorAll('.tab-button').forEach(btn => {
        btn.classList.remove('active');
    });
    element.classList.add('active');
}

// Función para formatear números como moneda
function formatearMoneda(input) {
    let valor = input.value.replace(/\./g, '');
    valor = valor.replace(/\D/g, '');
    
    if (valor === '') {
        input.value = '';
        return;
    }
    
    const numero = parseInt(valor, 10);
    input.value = numero.toLocaleString('es-CO');
}

// Función para generar código de barras
function generarCodigoBarras() {
    return '20' + Math.floor(Math.random() * 10000000000).toString().padStart(10, '0');
}

// Función para actualizar la vista previa del código de barras
function actualizarVistaPrevia(codigo) {
    const barcodeElement = document.getElementById('barcode');
    const barcodeText = document.getElementById('barcodeText');
    
    if (codigo && codigo.length >= 12) {
        barcodeText.textContent = codigo;
        JsBarcode(barcodeElement, codigo, {
            format: "EAN13",
            width: 2,
            height: 60,
            displayValue: false
        });
    } else {
        barcodeElement.innerHTML = '';
        barcodeText.textContent = codigo || 'Ingrese un código válido';
    }
}

// Toggle para campos de stock según servicio
document.getElementById('es_servicio')?.addEventListener('change', function() {
    const stockFields = document.getElementById('stockFields');
    const servicioMessage = document.getElementById('servicioMessage');
    
    if (this.checked) {
        stockFields.classList.add('hidden');
        servicioMessage.classList.remove('hidden');
        document.getElementById('stock').value = 0;
        document.getElementById('stock_minimo').value = 0;
    } else {
        stockFields.classList.remove('hidden');
        servicioMessage.classList.add('hidden');
    }
});

// Toggle switch para atributos
document.getElementById('tiene_atributos')?.addEventListener('change', function() {
    const atributosContainer = document.getElementById('atributosContainer');
    const atributosEstado = document.getElementById('atributosEstado');
    
    if (this.checked) {
        atributosContainer.classList.remove('hidden');
        atributosEstado.textContent = 'Con atributos';
    } else {
        atributosContainer.classList.add('hidden');
        atributosEstado.textContent = 'Sin atributos';
    }
});

// Función para cambiar entre tipos de talla
function cambiarTipoTalla() {
    const tipoAlfabetico = document.querySelector('input[name="tipo_talla"][value="alfabetica"]').checked;
    const tallaAlfabeticaContainer = document.getElementById('tallaAlfabeticaContainer');
    const tallaNumericaContainer = document.getElementById('tallaNumericaContainer');
    
    if (tipoAlfabetico) {
        tallaAlfabeticaContainer.classList.remove('hidden');
        tallaNumericaContainer.classList.add('hidden');
        document.getElementById('talla_numerica').value = '';
    } else {
        tallaAlfabeticaContainer.classList.add('hidden');
        tallaNumericaContainer.classList.remove('hidden');
        document.getElementById('talla_alfabetica').value = '';
    }
}

// Event listeners para los radio buttons de tipo de talla
document.querySelectorAll('input[name="tipo_talla"]').forEach(radio => {
    radio.addEventListener('change', cambiarTipoTalla);
});

// Aplicar formateo a los campos de moneda
document.querySelectorAll('.moneda-input').forEach(input => {
    input.addEventListener('input', function() {
        formatearMoneda(this);
    });
    
    if (input.value) {
        formatearMoneda(input);
    }
});

// Generar nuevo código de barras
document.getElementById('generarCodigoBarras')?.addEventListener('click', function() {
    const nuevoCodigo = generarCodigoBarras();
    document.getElementById('codigo_barras').value = nuevoCodigo;
    actualizarVistaPrevia(nuevoCodigo);
});

// Actualizar vista previa cuando cambia el código de barras
document.getElementById('codigo_barras')?.addEventListener('input', function() {
    actualizarVistaPrevia(this.value);
});

// Calcular automáticamente el precio de venta con margen (opcional)
document.getElementById('precio_compra')?.addEventListener('blur', function() {
    const precioCompra = parseFloat(this.value.replace(/\./g, '')) || 0;
    const precioVentaInput = document.getElementById('precio_venta');
    const esServicio = document.getElementById('es_servicio')?.checked;
    
    if (!esServicio && (!precioVentaInput.value.replace(/\./g, '') || parseFloat(precioVentaInput.value.replace(/\./g, '')) === precioCompra)) {
        const margen = precioCompra * 0.3; // 30% de margen por defecto
        const precioVenta = precioCompra + margen;
        precioVentaInput.value = Math.round(precioVenta).toLocaleString('es-CO');
    }
});

// Inicializar vista previa al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    const codigoInicial = document.getElementById('codigo_barras')?.value;
    if (codigoInicial) actualizarVistaPrevia(codigoInicial);
    
    // Aplicar formateo a los campos de moneda existentes
    document.querySelectorAll('.moneda-input').forEach(input => {
        if (input.value) {
            formatearMoneda(input);
        }
    });
    
    // Inicializar el switch de atributos
    const tieneAtributosCheckbox = document.getElementById('tiene_atributos');
    const atributosContainer = document.getElementById('atributosContainer');
    const atributosEstado = document.getElementById('atributosEstado');
    
    if (tieneAtributosCheckbox && tieneAtributosCheckbox.checked) {
        atributosContainer?.classList.remove('hidden');
        if (atributosEstado) atributosEstado.textContent = 'Con atributos';
    } else if (tieneAtributosCheckbox) {
        atributosContainer?.classList.add('hidden');
        if (atributosEstado) atributosEstado.textContent = 'Sin atributos';
    }
});

// Validación para los atributos
document.getElementById('productoForm')?.addEventListener('submit', function(e) {
    const tieneAtributos = document.getElementById('tiene_atributos')?.checked;
    const esServicio = document.getElementById('es_servicio')?.checked;
    
    // Validar que precio de venta sea mayor o igual a precio de compra (solo para productos, no servicios)
    const precioCompra = parseFloat(document.getElementById('precio_compra').value.replace(/\./g, '')) || 0;
    const precioVenta = parseFloat(document.getElementById('precio_venta').value.replace(/\./g, '')) || 0;
    
    if (!esServicio && precioVenta < precioCompra) {
        e.preventDefault();
        alert('El precio de venta no puede ser menor al precio de compra.');
        return false;
    }
    
    return true;
});
</script>

<?php include '../../../includes/footer.php'; ?>