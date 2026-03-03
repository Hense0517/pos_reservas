<?php
if (session_status() === PHP_SESSION_NONE) session_start(); 
ob_start();

// RUTAS CORREGIDAS - USAR __DIR__
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/header.php';

// Verificar permisos usando la clase Auth
if (!$auth->hasPermission('productos', 'crear')) {
    $_SESSION['error'] = "No tienes permisos para crear productos";
    header('Location: ' . BASE_URL . 'modules/inventario/productos/index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Obtener categorías para el select
$categorias = [];
try {
    $query = "SELECT * FROM categorias WHERE activo = 1 ORDER BY nombre";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error cargando categorías: " . $e->getMessage());
}

// Obtener marcas para el select
$marcas = [];
try {
    $query = "SELECT * FROM marcas WHERE activo = 1 ORDER BY nombre";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $marcas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error cargando marcas: " . $e->getMessage());
}

// Obtener tipos de atributos activos (incluyendo Talla)
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

// Generar códigos automáticos
$next_code = 'PR0001';
$codigo_barras = '20' . rand(1000000000, 9999999999);

try {
    $query = "SELECT MAX(CAST(SUBSTRING(codigo, 3) AS UNSIGNED)) as max_code FROM productos WHERE codigo LIKE 'PR%'";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $next_code = 'PR' . str_pad(($result['max_code'] ?? 0) + 1, 4, '0', STR_PAD_LEFT);
} catch (Exception $e) {
    error_log("Error generando código: " . $e->getMessage());
}

$error = null;

// Procesar formulario
if ($_POST) {
    $codigo = trim($_POST['codigo']);
    $codigo_barras_input = trim($_POST['codigo_barras']);
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    $categoria_id = !empty($_POST['categoria_id']) ? $_POST['categoria_id'] : null;
    $marca_id = !empty($_POST['marca_id']) ? $_POST['marca_id'] : null;
    
    // Limpiar el formato de moneda antes de guardar (quitar puntos y convertir a número)
    $precio_compra = floatval(str_replace('.', '', $_POST['precio_compra'] ?? 0));
    $precio_venta = floatval(str_replace('.', '', $_POST['precio_venta'] ?? 0));
    
    $stock = intval($_POST['stock'] ?? 0);
    $stock_minimo = intval($_POST['stock_minimo'] ?? 5);
    $tiene_atributos = isset($_POST['tiene_atributos']) ? 1 : 0;
    $es_servicio = isset($_POST['es_servicio']) ? 1 : 0;

    // Procesar talla si está presente (es opcional)
    $talla = null;
    if (isset($_POST['talla_tipo']) && !empty($_POST['talla_tipo'])) {
        $tipo_talla = $_POST['talla_tipo'];
        if ($tipo_talla === 'alfabetica' && !empty($_POST['talla_alfabetica'])) {
            $talla = $_POST['talla_alfabetica'];
        } elseif ($tipo_talla === 'numerica' && !empty($_POST['talla_numerica'])) {
            $talla = $_POST['talla_numerica'];
        }
    }
    
    $color = isset($_POST['color']) ? trim($_POST['color']) : null;

    // Validaciones básicas
    if (empty($codigo) || empty($nombre) || $precio_compra <= 0 || $precio_venta <= 0) {
        $error = "Todos los campos obligatorios deben ser completados correctamente.";
    } else {
        try {
            $db->beginTransaction();

            // Verificar si el código ya existe
            $query = "SELECT id FROM productos WHERE codigo = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$codigo]);
            
            if ($stmt->fetch()) {
                $error = "El código del producto ya existe.";
            } else if (!empty($codigo_barras_input)) {
                // Verificar si el código de barras ya existe
                $query = "SELECT id FROM productos WHERE codigo_barras = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$codigo_barras_input]);
                
                if ($stmt->fetch()) {
                    $error = "El código de barras ya existe.";
                }
            }
            
            if (!$error) {
                $query = "INSERT INTO productos (codigo, codigo_barras, nombre, descripcion, categoria_id, marca_id, precio_compra, precio_venta, stock, stock_minimo, talla, color, es_servicio, activo, created_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())";
                $stmt = $db->prepare($query);
                
                if ($stmt->execute([$codigo, $codigo_barras_input, $nombre, $descripcion, $categoria_id, $marca_id, $precio_compra, $precio_venta, $stock, $stock_minimo, $talla, $color, $es_servicio])) {
                    $producto_id = $db->lastInsertId();
                    
                    // Guardar atributos dinámicos seleccionados (excluyendo talla que ya se guardó)
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

                    // Si tiene stock inicial y no es servicio, registrar en auditoría
                    if ($stock > 0 && !$es_servicio) {
                        $query_audit = "INSERT INTO auditoria_stock 
                                       (producto_id, tipo_movimiento, cantidad, stock_anterior, stock_nuevo, usuario_id, referencia, motivo) 
                                       VALUES (?, 'compra', ?, 0, ?, ?, 'Inventario inicial', 'Stock inicial al crear producto')";
                        $stmt_audit = $db->prepare($query_audit);
                        $stmt_audit->execute([$producto_id, $stock, $stock, $_SESSION['usuario_id']]);
                    }

                    $db->commit();
                    $_SESSION['success'] = "Producto creado correctamente.";
                    header('Location: index.php');
                    ob_end_flush();
                    exit;
                } else {
                    $db->rollBack();
                    $error = "Error al crear el producto.";
                }
            } else {
                $db->rollBack();
            }
        } catch (Exception $e) {
            $db->rollBack();
            error_log("Error en crear producto: " . $e->getMessage());
            $error = "Error al procesar la solicitud: " . $e->getMessage();
        }
    }
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
</style>

<div class="max-w-6xl mx-auto p-6">
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-blue-600 to-indigo-700">
            <h2 class="text-xl font-semibold text-white">Crear Nuevo Producto</h2>
            <p class="text-blue-100 text-sm mt-1">Complete los datos para agregar un nuevo producto al inventario</p>
        </div>
        
        <form method="POST" class="p-6" id="productoForm">
            <?php if ($error): ?>
                <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo htmlspecialchars($error); ?>
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
                                   value="<?php echo isset($_POST['codigo']) ? htmlspecialchars($_POST['codigo']) : $next_code; ?>"
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="categoria_id" class="block text-sm font-medium text-gray-700 mb-1">Categoría</label>
                            <select id="categoria_id" name="categoria_id"
                                    class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Seleccionar categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                <option value="<?php echo $categoria['id']; ?>" 
                                    <?php echo (isset($_POST['categoria_id']) && $_POST['categoria_id'] == $categoria['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($categoria['nombre']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="marca_id" class="block text-sm font-medium text-gray-700 mb-1">Marca</label>
                            <select id="marca_id" name="marca_id"
                                    class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Seleccionar marca</option>
                                <?php foreach ($marcas as $marca): ?>
                                <option value="<?php echo $marca['id']; ?>" 
                                    <?php echo (isset($_POST['marca_id']) && $_POST['marca_id'] == $marca['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($marca['nombre']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="nombre" class="block text-sm font-medium text-gray-700 mb-1">Nombre del Producto *</label>
                            <input type="text" id="nombre" name="nombre" required
                                   value="<?php echo isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : ''; ?>"
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                    
                    <div>
                        <label for="descripcion" class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                        <textarea id="descripcion" name="descripcion" rows="3"
                                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo isset($_POST['descripcion']) ? htmlspecialchars($_POST['descripcion']) : ''; ?></textarea>
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
                                       value="<?php echo isset($_POST['precio_compra']) ? htmlspecialchars($_POST['precio_compra']) : ''; ?>"
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
                                       value="<?php echo isset($_POST['precio_venta']) ? htmlspecialchars($_POST['precio_venta']) : ''; ?>"
                                       class="mt-1 block w-full pl-8 border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 moneda-input"
                                       placeholder="0">
                            </div>
                        </div>
                    </div>

                    <!-- Checkbox de servicio que oculta los campos de stock -->
                    <div class="flex items-center pt-2">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" id="es_servicio" name="es_servicio" value="1" class="sr-only" 
                                   <?php echo isset($_POST['es_servicio']) ? 'checked' : ''; ?>>
                            <div class="block bg-gray-600 w-14 h-8 rounded-full"></div>
                            <div class="dot absolute left-1 top-1 bg-white w-6 h-6 rounded-full transition"></div>
                            <span class="ml-3 text-sm text-gray-700">
                                <i class="fas fa-concierge-bell text-blue-600 mr-1"></i>
                                Es un servicio (no maneja inventario)
                            </span>
                        </label>
                    </div>

                    <!-- Campos de stock (se ocultan si es servicio) -->
                    <div id="stockFields" class="grid grid-cols-1 md:grid-cols-2 gap-4 <?php echo isset($_POST['es_servicio']) ? 'hidden' : ''; ?>">
                        <div>
                            <label for="stock" class="block text-sm font-medium text-gray-700 mb-1">Stock Inicial *</label>
                            <input type="number" id="stock" name="stock" min="0" required
                                   value="<?php echo isset($_POST['stock']) ? $_POST['stock'] : '0'; ?>"
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="stock_minimo" class="block text-sm font-medium text-gray-700 mb-1">Stock Mínimo *</label>
                            <input type="number" id="stock_minimo" name="stock_minimo" min="0" required 
                                   value="<?php echo isset($_POST['stock_minimo']) ? $_POST['stock_minimo'] : '5'; ?>"
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
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
                                    <input type="checkbox" id="tiene_atributos" name="tiene_atributos" 
                                           class="sr-only" <?php echo isset($_POST['tiene_atributos']) ? 'checked' : ''; ?>>
                                    <div class="block bg-gray-600 w-14 h-8 rounded-full"></div>
                                    <div class="dot absolute left-1 top-1 bg-white w-6 h-6 rounded-full transition"></div>
                                </div>
                                <div class="ml-3 text-gray-700 font-medium">
                                    <span id="atributosEstado"><?php echo isset($_POST['tiene_atributos']) ? 'Con atributos' : 'Sin atributos'; ?></span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Contenedor de TODOS los atributos (se muestra cuando está activado) -->
                    <div id="atributosContainer" class="<?php echo isset($_POST['tiene_atributos']) ? '' : 'hidden'; ?> space-y-4">
                        
                        <!-- ATRIBUTO ESPECIAL: TALLA (con opciones numérica/alfabética) - AHORA OPCIONAL -->
                        <div class="talla-container">
                            <div class="flex items-center mb-3">
                                <div class="atributo-icono mr-2">
                                    <i class="fas fa-ruler"></i>
                                </div>
                                <h4 class="font-medium text-gray-800">Talla (opcional)</h4>
                            </div>
                            
                            <div class="talla-opciones">
                                <label class="flex items-center space-x-2 talla-opcion p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                                    <input type="radio" name="talla_tipo" value="alfabetica" 
                                           <?php echo (!isset($_POST['talla_tipo']) || $_POST['talla_tipo'] == 'alfabetica') ? 'checked' : ''; ?>
                                           onchange="cambiarTipoTalla()">
                                    <span class="text-sm font-medium">Talla Alfabética</span>
                                    <span class="text-xs text-gray-500">(XXS, XS, S, M, L, XL, XXL, XXXL, XXXXL)</span>
                                </label>
                                
                                <label class="flex items-center space-x-2 talla-opcion p-3 border rounded-lg cursor-pointer hover:bg-gray-50">
                                    <input type="radio" name="talla_tipo" value="numerica" 
                                           <?php echo (isset($_POST['talla_tipo']) && $_POST['talla_tipo'] == 'numerica') ? 'checked' : ''; ?>
                                           onchange="cambiarTipoTalla()">
                                    <span class="text-sm font-medium">Talla Numérica</span>
                                    <span class="text-xs text-gray-500">(1 al 50)</span>
                                </label>
                            </div>

                            <!-- Selector de talla alfabética -->
                            <div id="tallaAlfabeticaContainer" class="mt-3 <?php echo (!isset($_POST['talla_tipo']) || $_POST['talla_tipo'] == 'alfabetica') ? '' : 'hidden'; ?>">
                                <select id="talla_alfabetica" name="talla_alfabetica" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">-- Seleccionar talla alfabética (opcional) --</option>
                                    <option value="XXS" <?php echo (isset($_POST['talla_alfabetica']) && $_POST['talla_alfabetica'] == 'XXS') ? 'selected' : ''; ?>>XXS - Extra Extra Small</option>
                                    <option value="XS" <?php echo (isset($_POST['talla_alfabetica']) && $_POST['talla_alfabetica'] == 'XS') ? 'selected' : ''; ?>>XS - Extra Small</option>
                                    <option value="S" <?php echo (isset($_POST['talla_alfabetica']) && $_POST['talla_alfabetica'] == 'S') ? 'selected' : ''; ?>>S - Small</option>
                                    <option value="M" <?php echo (isset($_POST['talla_alfabetica']) && $_POST['talla_alfabetica'] == 'M') ? 'selected' : ''; ?>>M - Medium</option>
                                    <option value="L" <?php echo (isset($_POST['talla_alfabetica']) && $_POST['talla_alfabetica'] == 'L') ? 'selected' : ''; ?>>L - Large</option>
                                    <option value="XL" <?php echo (isset($_POST['talla_alfabetica']) && $_POST['talla_alfabetica'] == 'XL') ? 'selected' : ''; ?>>XL - Extra Large</option>
                                    <option value="XXL" <?php echo (isset($_POST['talla_alfabetica']) && $_POST['talla_alfabetica'] == 'XXL') ? 'selected' : ''; ?>>XXL - 2X Large</option>
                                    <option value="XXXL" <?php echo (isset($_POST['talla_alfabetica']) && $_POST['talla_alfabetica'] == 'XXXL') ? 'selected' : ''; ?>>XXXL - 3X Large</option>
                                    <option value="XXXXL" <?php echo (isset($_POST['talla_alfabetica']) && $_POST['talla_alfabetica'] == 'XXXXL') ? 'selected' : ''; ?>>XXXXL - 4X Large</option>
                                </select>
                            </div>

                            <!-- Selector de talla numérica -->
                            <div id="tallaNumericaContainer" class="mt-3 <?php echo (isset($_POST['talla_tipo']) && $_POST['talla_tipo'] == 'numerica') ? '' : 'hidden'; ?>">
                                <select id="talla_numerica" name="talla_numerica" class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">-- Seleccionar talla numérica (opcional) --</option>
                                    <?php for ($i = 1; $i <= 50; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo (isset($_POST['talla_numerica']) && $_POST['talla_numerica'] == $i) ? 'selected' : ''; ?>>
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
                                   value="<?php echo isset($_POST['color']) ? htmlspecialchars($_POST['color']) : ''; ?>"
                                   placeholder="Ej: Rojo, Azul, Negro... (opcional)"
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
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
                                        <option value="<?php echo $valor['id']; ?>">
                                            <?php echo htmlspecialchars($valor['valor']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php elseif ($tipo['tipo_dato'] == 'radio'): ?>
                                        <div class="space-y-1 max-h-40 overflow-y-auto p-2">
                                            <?php foreach ($valores as $valor): ?>
                                            <label class="flex items-center space-x-2 text-sm">
                                                <input type="radio" name="atributos[<?php echo $tipo['id']; ?>]" value="<?php echo $valor['id']; ?>">
                                                <span><?php echo htmlspecialchars($valor['valor']); ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if ($tipo['tipo_dato'] == 'texto'): ?>
                                    <input type="text" name="atributos_texto[<?php echo $tipo['id']; ?>]" 
                                           class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
                                           placeholder="Ingrese <?php echo strtolower($tipo['nombre']); ?> (opcional)">
                                    <?php elseif ($tipo['tipo_dato'] == 'numero'): ?>
                                    <input type="number" name="atributos_texto[<?php echo $tipo['id']; ?>]" 
                                           class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-500"
                                           placeholder="Ingrese <?php echo strtolower($tipo['nombre']); ?> (opcional)">
                                    <?php elseif ($tipo['tipo_dato'] == 'decimal'): ?>
                                    <input type="number" step="0.01" name="atributos_texto[<?php echo $tipo['id']; ?>]" 
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
                                   value="<?php echo isset($_POST['codigo_barras']) ? htmlspecialchars($_POST['codigo_barras']) : $codigo_barras; ?>"
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <button type="button" id="generarCodigoBarras" class="mt-6 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md text-sm">
                            <i class="fas fa-sync-alt mr-2"></i>
                            Generar Nuevo
                        </button>
                    </div>
                    
                    <p class="text-xs text-gray-500">Código de 13 dígitos generado automáticamente</p>

                    <div class="barcode-container mt-6">
                        <div class="barcode-preview">
                            <svg id="barcode" class="mx-auto"></svg>
                            <p id="barcodeText" class="text-sm font-mono text-gray-600 mt-2"></p>
                        </div>
                        <p class="text-sm text-gray-500 mt-2">Vista previa del código de barras</p>
                    </div>
                </div>
            </div>

            <!-- Botones de acción -->
            <div class="flex justify-end space-x-3 pt-6 mt-6 border-t">
                <a href="index.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancelar
                </a>
                <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                    <i class="fas fa-save mr-2"></i>
                    Guardar Producto
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
    if (document.getElementById('barcode')) {
        if (codigo && codigo.length >= 12) {
            document.getElementById('barcodeText').textContent = codigo;
            JsBarcode("#barcode", codigo, {
                format: "EAN13",
                width: 2,
                height: 60,
                displayValue: false
            });
        } else {
            document.getElementById('barcode').innerHTML = '';
            document.getElementById('barcodeText').textContent = 'Ingrese un código válido';
        }
    }
}

// Toggle para campos de stock según servicio
document.getElementById('es_servicio')?.addEventListener('change', function() {
    const stockFields = document.getElementById('stockFields');
    if (this.checked) {
        stockFields.classList.add('hidden');
    } else {
        stockFields.classList.remove('hidden');
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
    const tipoAlfabetico = document.querySelector('input[name="talla_tipo"][value="alfabetica"]').checked;
    const tallaAlfabeticaContainer = document.getElementById('tallaAlfabeticaContainer');
    const tallaNumericaContainer = document.getElementById('tallaNumericaContainer');
    
    if (tipoAlfabetico) {
        tallaAlfabeticaContainer.classList.remove('hidden');
        tallaNumericaContainer.classList.add('hidden');
    } else {
        tallaAlfabeticaContainer.classList.add('hidden');
        tallaNumericaContainer.classList.remove('hidden');
    }
}

// Event listeners para los radio buttons de tipo de talla
document.querySelectorAll('input[name="talla_tipo"]').forEach(radio => {
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
    
    if (!precioVentaInput.value.replace(/\./g, '') || parseFloat(precioVentaInput.value.replace(/\./g, '')) === precioCompra) {
        const margen = precioCompra * 0.3;
        const precioVenta = precioCompra + margen;
        precioVentaInput.value = Math.round(precioVenta).toLocaleString('es-CO');
    }
});

// Inicializar
document.addEventListener('DOMContentLoaded', function() {
    const tieneAtributos = document.getElementById('tiene_atributos');
    const atributosContainer = document.getElementById('atributosContainer');
    const atributosEstado = document.getElementById('atributosEstado');
    
    if (tieneAtributos && tieneAtributos.checked) {
        atributosContainer.classList.remove('hidden');
        atributosEstado.textContent = 'Con atributos';
    } else if (tieneAtributos) {
        atributosContainer.classList.add('hidden');
        atributosEstado.textContent = 'Sin atributos';
    }
    
    cambiarTipoTalla();
    
    const codigoInicial = document.getElementById('codigo_barras')?.value;
    if (codigoInicial) actualizarVistaPrevia(codigoInicial);
});

// Validación del formulario (SIN validación de atributos obligatorios)
document.getElementById('productoForm')?.addEventListener('submit', function(e) {
    const codigo = document.getElementById('codigo').value.trim();
    const nombre = document.getElementById('nombre').value.trim();
    const precioCompra = document.getElementById('precio_compra').value.replace(/\./g, '');
    const precioVenta = document.getElementById('precio_venta').value.replace(/\./g, '');
    
    if (!codigo || !nombre || !precioCompra || !precioVenta) {
        e.preventDefault();
        alert('Por favor complete todos los campos obligatorios.');
        return false;
    }
    
    if (parseFloat(precioCompra) <= 0 || parseFloat(precioVenta) <= 0) {
        e.preventDefault();
        alert('Los precios deben ser mayores a cero.');
        return false;
    }
    
    return true;
});
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>