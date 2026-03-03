<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();
include '../../../includes/header.php';

// Verificar permisos
if ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor') {
    header('Location: /sistema_pos/index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Obtener ID del producto
$producto_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($producto_id <= 0) {
    header('Location: index.php');
    exit;
}

// Obtener información del producto
$query = "SELECT p.*, c.nombre as categoria_nombre, m.nombre as marca_nombre
          FROM productos p 
          LEFT JOIN categorias c ON p.categoria_id = c.id 
          LEFT JOIN marcas m ON p.marca_id = m.id
          WHERE p.id = ? AND p.activo = 1";
$stmt = $db->prepare($query);
$stmt->execute([$producto_id]);
$producto = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$producto) {
    $_SESSION['error'] = "Producto no encontrado.";
    header('Location: index.php');
    exit;
}

// Verificar que el producto tenga habilitadas las variaciones
if ($producto['tiene_variaciones'] == 0) {
    $_SESSION['error'] = "Este producto no tiene habilitadas las variaciones.";
    header('Location: editar.php?id=' . $producto_id);
    exit;
}

// Obtener atributos disponibles de la categoría
$query_atributos = "SELECT a.*, ca.obligatorio
                    FROM categoria_atributos ca
                    JOIN atributos a ON ca.atributo_id = a.id
                    WHERE ca.categoria_id = ? AND a.activo = 1
                    ORDER BY ca.orden";
$stmt_atributos = $db->prepare($query_atributos);
$stmt_atributos->execute([$producto['categoria_id']]);
$atributos = $stmt_atributos->fetchAll(PDO::FETCH_ASSOC);

// Obtener opciones para cada atributo
$opciones_atributos = [];
foreach ($atributos as $atributo) {
    $query_opciones = "SELECT * FROM opciones_atributo 
                       WHERE atributo_id = ? AND activo = 1 
                       ORDER BY orden";
    $stmt_opciones = $db->prepare($query_opciones);
    $stmt_opciones->execute([$atributo['id']]);
    $opciones_atributos[$atributo['id']] = $stmt_opciones->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener variaciones existentes
$query_variaciones = "SELECT * FROM producto_variaciones 
                      WHERE producto_id = ? AND activo = 1 
                      ORDER BY atributo_nombre, atributo_valor";
$stmt_variaciones = $db->prepare($query_variaciones);
$stmt_variaciones->execute([$producto_id]);
$variaciones = $stmt_variaciones->fetchAll(PDO::FETCH_ASSOC);

$error = null;
$success = null;

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'agregar_variacion') {
            // Agregar nueva variación
            $sku = trim($_POST['sku']);
            $atributo_nombre = trim($_POST['atributo_nombre']);
            $atributo_valor = trim($_POST['atributo_valor']);
            $precio_venta = floatval($_POST['precio_venta']);
            $precio_compra = floatval($_POST['precio_compra']);
            $stock = intval($_POST['stock']);
            
            // Validaciones
            if (empty($sku) || empty($atributo_nombre) || empty($atributo_valor)) {
                $error = "El SKU, nombre y valor del atributo son obligatorios.";
            } elseif ($precio_venta <= 0) {
                $error = "El precio de venta debe ser mayor a cero.";
            } else {
                // Verificar si el SKU ya existe
                $query_check = "SELECT id FROM producto_variaciones WHERE sku = ?";
                $stmt_check = $db->prepare($query_check);
                $stmt_check->execute([$sku]);
                
                if ($stmt_check->fetch()) {
                    $error = "El SKU ya existe. Use uno diferente.";
                } else {
                    $query = "INSERT INTO producto_variaciones 
                             (producto_id, sku, atributo_nombre, atributo_valor, 
                              precio_venta, precio_compra, stock, activo, created_at) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())";
                    
                    $stmt = $db->prepare($query);
                    if ($stmt->execute([$producto_id, $sku, $atributo_nombre, $atributo_valor, 
                                       $precio_venta, $precio_compra, $stock])) {
                        $success = "Variación agregada correctamente.";
                    } else {
                        $error = "Error al agregar la variación.";
                    }
                }
            }
            
        } elseif ($action === 'editar_variacion' && isset($_POST['variacion_id'])) {
            // Editar variación existente
            $variacion_id = intval($_POST['variacion_id']);
            $precio_venta = floatval($_POST['precio_venta']);
            $precio_compra = floatval($_POST['precio_compra']);
            $stock = intval($_POST['stock']);
            
            $query = "UPDATE producto_variaciones SET 
                     precio_venta = ?, 
                     precio_compra = ?, 
                     stock = ?, 
                     updated_at = NOW()
                     WHERE id = ? AND producto_id = ?";
            
            $stmt = $db->prepare($query);
            if ($stmt->execute([$precio_venta, $precio_compra, $stock, $variacion_id, $producto_id])) {
                $success = "Variación actualizada correctamente.";
            } else {
                $error = "Error al actualizar la variación.";
            }
            
        } elseif ($action === 'eliminar_variacion' && isset($_POST['variacion_id'])) {
            // Eliminar variación (marcar como inactiva)
            $variacion_id = intval($_POST['variacion_id']);
            
            $query = "UPDATE producto_variaciones SET activo = 0 WHERE id = ? AND producto_id = ?";
            $stmt = $db->prepare($query);
            if ($stmt->execute([$variacion_id, $producto_id])) {
                $success = "Variación eliminada correctamente.";
            } else {
                $error = "Error al eliminar la variación.";
            }
        }
        
        // Recargar la página para ver cambios
        header('Location: gestionar_variaciones.php?id=' . $producto_id);
        exit;
        
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}
?>

<div class="max-w-7xl mx-auto">
    <!-- Encabezado -->
    <div class="mb-6">
        <div class="flex justify-between items-start">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Gestionar Variaciones</h1>
                <div class="mt-2">
                    <a href="ver.php?id=<?php echo $producto_id; ?>" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-1"></i> Volver al producto
                    </a>
                    <span class="text-gray-400 mx-2">•</span>
                    <span class="text-gray-600"><?php echo htmlspecialchars($producto['nombre']); ?></span>
                </div>
            </div>
            <div class="flex space-x-2">
                <a href="editar.php?id=<?php echo $producto_id; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-edit mr-2"></i> Editar Producto
                </a>
                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                    <i class="fas fa-times mr-2"></i> Cerrar
                </a>
            </div>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Columna 1: Agregar nueva variación -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Agregar Nueva Variación</h3>
                </div>
                <div class="p-6">
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="agregar_variacion">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="sku" class="block text-sm font-medium text-gray-700">
                                    SKU *
                                </label>
                                <input type="text" id="sku" name="sku" required
                                       placeholder="Ej: <?php echo $producto['codigo']; ?>-S-BLANCO"
                                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <p class="text-xs text-gray-500 mt-1">Código único para esta variación</p>
                            </div>
                            
                            <div>
                                <label for="stock" class="block text-sm font-medium text-gray-700">
                                    Stock inicial
                                </label>
                                <input type="number" id="stock" name="stock" value="0" min="0"
                                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="atributo_nombre" class="block text-sm font-medium text-gray-700">
                                    Nombre del Atributo *
                                </label>
                                <select id="atributo_nombre" name="atributo_nombre" required
                                        class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Seleccionar atributo</option>
                                    <?php foreach ($atributos as $atributo): ?>
                                        <option value="<?php echo htmlspecialchars($atributo['nombre']); ?>">
                                            <?php echo htmlspecialchars($atributo['nombre']); ?>
                                            <?php if ($atributo['obligatorio']): ?> *<?php endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="Personalizado">Personalizado...</option>
                                </select>
                            </div>
                            
                            <div>
                                <label for="atributo_valor" class="block text-sm font-medium text-gray-700">
                                    Valor del Atributo *
                                </label>
                                <input type="text" id="atributo_valor" name="atributo_valor" required
                                       placeholder="Ej: S, Blanco, 250ml"
                                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <div id="opciones_container" class="mt-2 space-y-1 hidden">
                                    <?php foreach ($atributos as $atributo): 
                                        if (isset($opciones_atributos[$atributo['id']])):
                                    ?>
                                        <div id="opciones_<?php echo $atributo['id']; ?>" class="opciones-grupo hidden">
                                            <?php foreach ($opciones_atributos[$atributo['id']] as $opcion): ?>
                                                <button type="button" onclick="seleccionarOpcion('<?php echo htmlspecialchars($opcion['valor']); ?>')"
                                                        class="inline-block bg-gray-100 hover:bg-gray-200 text-gray-800 text-xs px-2 py-1 rounded mr-1 mb-1">
                                                    <?php echo htmlspecialchars($opcion['valor']); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; endforeach; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="precio_venta" class="block text-sm font-medium text-gray-700">
                                    Precio de Venta *
                                </label>
                                <input type="number" id="precio_venta" name="precio_venta" required min="0" step="0.01"
                                       placeholder="0.00"
                                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="precio_compra" class="block text-sm font-medium text-gray-700">
                                    Precio de Compra
                                </label>
                                <input type="number" id="precio_compra" name="precio_compra" min="0" step="0.01"
                                       placeholder="0.00"
                                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                        
                        <div class="pt-4 border-t border-gray-200">
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md">
                                <i class="fas fa-plus mr-2"></i> Agregar Variación
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Columna 2: Atributos disponibles -->
        <div>
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-medium text-gray-900">Atributos Disponibles</h3>
                </div>
                <div class="p-6">
                    <?php if (count($atributos) > 0): ?>
                        <div class="space-y-4">
                            <?php foreach ($atributos as $atributo): ?>
                                <div class="border border-gray-200 rounded-md p-3">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($atributo['nombre']); ?>
                                        </span>
                                        <?php if ($atributo['obligatorio']): ?>
                                            <span class="text-xs bg-red-100 text-red-800 px-2 py-1 rounded">Obligatorio</span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (isset($opciones_atributos[$atributo['id']]) && count($opciones_atributos[$atributo['id']]) > 0): ?>
                                        <div class="text-xs text-gray-600 mb-1">Opciones:</div>
                                        <div class="flex flex-wrap gap-1">
                                            <?php foreach ($opciones_atributos[$atributo['id']] as $opcion): ?>
                                                <span class="inline-block bg-gray-100 text-gray-800 text-xs px-2 py-1 rounded">
                                                    <?php echo htmlspecialchars($opcion['valor']); ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-xs text-gray-500">Sin opciones predefinidas</div>
                                    <?php endif; ?>
                                    
                                    <div class="mt-2 text-xs text-gray-500">
                                        Tipo: <?php echo htmlspecialchars($atributo['tipo']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-tag text-gray-300 text-3xl mb-2"></i>
                            <p class="text-gray-500">No hay atributos definidos</p>
                            <a href="../categorias/atributos.php?id=<?php echo $producto['categoria_id']; ?>" 
                               class="mt-2 inline-block text-sm text-blue-600 hover:text-blue-800">
                                <i class="fas fa-plus mr-1"></i> Agregar atributos
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de variaciones existentes -->
    <div class="bg-white rounded-lg shadow overflow-hidden mt-6">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <h3 class="text-lg font-medium text-gray-900">Variaciones Existentes</h3>
            <span class="text-sm text-gray-500">
                <?php echo count($variaciones); ?> variaciones
            </span>
        </div>
        
        <?php if (count($variaciones) > 0): ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                SKU
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Atributo
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Precios
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Stock
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Acciones
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($variaciones as $variacion): 
                            $stock_class = $variacion['stock'] <= 0 ? 'bg-red-100 text-red-800' : 
                                         ($variacion['stock'] <= 5 ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800');
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-mono text-gray-900"><?php echo htmlspecialchars($variacion['sku']); ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900">
                                    <div><strong><?php echo htmlspecialchars($variacion['atributo_nombre']); ?>:</strong></div>
                                    <div class="text-lg font-semibold"><?php echo htmlspecialchars($variacion['atributo_valor']); ?></div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <form method="POST" class="space-y-2">
                                    <input type="hidden" name="action" value="editar_variacion">
                                    <input type="hidden" name="variacion_id" value="<?php echo $variacion['id']; ?>">
                                    
                                    <div class="flex items-center space-x-2">
                                        <div class="text-sm">
                                            <label class="block text-xs text-gray-500">Venta</label>
                                            <input type="number" name="precio_venta" value="<?php echo $variacion['precio_venta']; ?>" 
                                                   step="0.01" min="0"
                                                   class="w-24 border border-gray-300 rounded px-2 py-1 text-sm">
                                        </div>
                                        <div class="text-sm">
                                            <label class="block text-xs text-gray-500">Compra</label>
                                            <input type="number" name="precio_compra" value="<?php echo $variacion['precio_compra']; ?>" 
                                                   step="0.01" min="0"
                                                   class="w-24 border border-gray-300 rounded px-2 py-1 text-sm">
                                        </div>
                                        <div class="text-sm">
                                            <label class="block text-xs text-gray-500">Stock</label>
                                            <input type="number" name="stock" value="<?php echo $variacion['stock']; ?>" 
                                                   min="0"
                                                   class="w-20 border border-gray-300 rounded px-2 py-1 text-sm">
                                        </div>
                                        <div class="pt-4">
                                            <button type="submit" class="text-green-600 hover:text-green-800" title="Guardar">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                                
                                <?php if ($variacion['precio_compra'] > 0): 
                                    $margen = $variacion['precio_venta'] - $variacion['precio_compra'];
                                    $porcentaje_margen = ($margen / $variacion['precio_compra']) * 100;
                                ?>
                                <div class="text-xs mt-1 <?php echo $porcentaje_margen >= 0 ? 'text-green-600' : 'text-red-600'; ?>">
                                    Margen: <?php echo number_format($porcentaje_margen, 1); ?>%
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-lg font-bold text-gray-900"><?php echo $variacion['stock']; ?></div>
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $stock_class; ?>">
                                    <?php echo $variacion['stock'] <= 0 ? 'Agotado' : ($variacion['stock'] <= 5 ? 'Bajo' : 'Disponible'); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar esta variación?')">
                                    <input type="hidden" name="action" value="eliminar_variacion">
                                    <input type="hidden" name="variacion_id" value="<?php echo $variacion['id']; ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900" title="Eliminar">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-layer-group text-gray-300 text-4xl mb-3"></i>
                <p class="text-gray-500">No hay variaciones creadas aún</p>
                <p class="text-sm text-gray-400 mt-1">Usa el formulario de arriba para agregar la primera variación</p>
            </div>
        <?php endif; ?>
        
        <!-- Resumen -->
        <?php if (count($variaciones) > 0): 
            // Calcular estadísticas
            $stock_total = array_sum(array_column($variaciones, 'stock'));
            $valor_inventario = 0;
            $valor_venta_total = 0;
            
            foreach ($variaciones as $variacion) {
                $valor_inventario += $variacion['stock'] * $variacion['precio_compra'];
                $valor_venta_total += $variacion['stock'] * $variacion['precio_venta'];
            }
        ?>
            <div class="bg-gray-50 px-6 py-4 border-t border-gray-200">
                <div class="flex justify-between items-center">
                    <div class="text-sm text-gray-700">
                        <strong>Resumen:</strong> 
                        <?php echo count($variaciones); ?> variaciones, 
                        <?php echo $stock_total; ?> unidades en stock
                    </div>
                    <div class="text-sm text-gray-700">
                        <strong>Valor inventario:</strong> $<?php echo number_format($valor_inventario, 2); ?>
                        <span class="text-gray-500 ml-2">
                            (Venta: $<?php echo number_format($valor_venta_total, 2); ?>)
                        </span>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Botones de acción -->
    <div class="mt-6 flex justify-between">
        <div>
            <a href="ver.php?id=<?php echo $producto_id; ?>" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg">
                <i class="fas fa-arrow-left mr-2"></i> Volver al producto
            </a>
        </div>
        <div class="flex space-x-2">
            <button onclick="imprimirListaVariaciones()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                <i class="fas fa-print mr-2"></i> Imprimir Lista
            </button>
            <a href="../etiquetas/generar_etiquetas.php?producto_id=<?php echo $producto_id; ?>" 
               target="_blank"
               class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                <i class="fas fa-tags mr-2"></i> Imprimir Etiquetas
            </a>
        </div>
    </div>
</div>

<script>
// Mostrar/ocultar opciones según atributo seleccionado
document.getElementById('atributo_nombre').addEventListener('change', function() {
    const opcionesContainer = document.getElementById('opciones_container');
    const valorInput = document.getElementById('atributo_valor');
    
    // Ocultar todas las opciones
    document.querySelectorAll('.opciones-grupo').forEach(el => el.classList.add('hidden'));
    opcionesContainer.classList.add('hidden');
    
    // Limpiar valor
    valorInput.value = '';
    
    // Si no es "Personalizado", mostrar opciones si existen
    if (this.value !== 'Personalizado' && this.value !== '') {
        // Buscar el atributo seleccionado
        const atributos = <?php echo json_encode($atributos); ?>;
        const atributoSeleccionado = atributos.find(a => a.nombre === this.value);
        
        if (atributoSeleccionado) {
            const grupoOpciones = document.getElementById('opciones_' + atributoSeleccionado.id);
            if (grupoOpciones) {
                grupoOpciones.classList.remove('hidden');
                opcionesContainer.classList.remove('hidden');
            }
        }
    }
});

// Seleccionar opción al hacer clic
function seleccionarOpcion(valor) {
    document.getElementById('atributo_valor').value = valor;
}

// Auto-generar SKU
document.getElementById('atributo_valor').addEventListener('input', function() {
    const skuInput = document.getElementById('sku');
    const codigoProducto = '<?php echo $producto['codigo']; ?>';
    const atributoValor = this.value.trim().toUpperCase().replace(/\s+/g, '-');
    
    if (atributoValor && !skuInput.value) {
        skuInput.value = codigoProducto + '-' + atributoValor.substring(0, 10);
    }
});

// Imprimir lista de variaciones
function imprimirListaVariaciones() {
    const contenido = document.querySelector('.bg-white.rounded-lg.shadow.overflow-hidden.mt-6');
    const ventana = window.open('', '_blank');
    ventana.document.write(`
        <html>
        <head>
            <title>Lista de Variaciones - <?php echo htmlspecialchars($producto['nombre']); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                h1 { color: #333; }
                .header { margin-bottom: 30px; }
                .footer { margin-top: 30px; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Lista de Variaciones</h1>
                <p><strong>Producto:</strong> <?php echo htmlspecialchars($producto['nombre']); ?></p>
                <p><strong>Código:</strong> <?php echo htmlspecialchars($producto['codigo']); ?></p>
                <p><strong>Fecha:</strong> ${new Date().toLocaleDateString()}</p>
            </div>
            ${contenido.outerHTML}
            <div class="footer">
                Impreso el ${new Date().toLocaleString()} | Sistema POS
            </div>
        </body>
        </html>
    `);
    ventana.document.close();
    ventana.print();
}
</script>

<?php include '../../../includes/footer.php'; ?>