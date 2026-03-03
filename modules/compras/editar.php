<?php
/**
 * ============================================
 * ARCHIVO: editar.php
 * UBICACIÓN: /modules/compras/editar.php
 * PROPÓSITO: Editar compra existente
 * ============================================
 */

session_start();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/header.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar ID
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error'] = "ID de compra no válido";
    header("Location: index.php");
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Verificar que la compra existe y está pendiente
    $stmt = $db->prepare("SELECT * FROM compras WHERE id = ?");
    $stmt->execute([$id]);
    $compra = $stmt->fetch();
    
    if (!$compra) {
        $_SESSION['error'] = "Compra no encontrada";
        header("Location: index.php");
        exit();
    }
    
    if ($compra['estado'] != 'pendiente') {
        $_SESSION['error'] = "Solo se pueden editar compras pendientes";
        header("Location: index.php");
        exit();
    }
    
    // Obtener detalles de la compra
    $stmt = $db->prepare("SELECT cd.*, p.nombre as producto_nombre, p.codigo 
                          FROM compra_detalles cd 
                          LEFT JOIN productos p ON cd.producto_id = p.id 
                          WHERE cd.compra_id = ?");
    $stmt->execute([$id]);
    $detalles = $stmt->fetchAll();
    
    // Obtener proveedores activos
    $stmt = $db->prepare("SELECT id, nombre FROM proveedores WHERE estado = 'activo' ORDER BY nombre");
    $stmt->execute();
    $proveedores = $stmt->fetchAll();
    
    // Obtener productos activos
    $stmt = $db->prepare("SELECT id, nombre, codigo, precio_compra, stock FROM productos WHERE activo = 1 ORDER BY nombre");
    $stmt->execute();
    $productos = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    $_SESSION['error'] = "Error al cargar datos";
    header("Location: index.php");
    exit();
}

// Preparar productos para JS
$productos_js = array_map(function($p) {
    return [
        'id' => $p['id'],
        'nombre' => $p['nombre'],
        'codigo' => $p['codigo'],
        'precio' => floatval($p['precio_compra']),
        'stock' => intval($p['stock'])
    ];
}, $productos);
?>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-edit text-green-600 mr-2"></i>
                Editar Compra
            </h1>
            <p class="text-gray-600 mt-1">
                Modificando compra #<?php echo htmlspecialchars($compra['numero_factura']); ?>
            </p>
        </div>
        <div class="flex space-x-3 mt-4 md:mt-0">
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Formulario -->
    <form id="formCompra" action="actualizar_compra.php" method="POST" class="space-y-6">
        <input type="hidden" name="compra_id" value="<?php echo $id; ?>">
        
        <!-- Panel principal -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Información básica -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold mb-4 border-b pb-2">
                        <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                        Información de la Compra
                    </h2>
                    
                    <div class="space-y-4">
                        <!-- Factura (solo lectura) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Número de Factura
                            </label>
                            <div class="flex items-center bg-gray-100 border border-gray-300 rounded-lg px-3 py-2">
                                <i class="fas fa-receipt text-gray-400 mr-2"></i>
                                <span class="font-medium"><?php echo htmlspecialchars($compra['numero_factura']); ?></span>
                            </div>
                        </div>
                        
                        <!-- Proveedor -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Proveedor *
                            </label>
                            <select name="proveedor_id" required
                                    class="select2 w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Seleccionar proveedor</option>
                                <?php foreach ($proveedores as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" 
                                            <?php echo $p['id'] == $compra['proveedor_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['nombre']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Fecha y hora -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Fecha
                                </label>
                                <input type="date" name="fecha" 
                                       value="<?php echo date('Y-m-d', strtotime($compra['fecha'])); ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Hora
                                </label>
                                <input type="time" name="hora" 
                                       value="<?php echo date('H:i', strtotime($compra['fecha'])); ?>"
                                       class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Productos -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold">
                            <i class="fas fa-boxes text-green-600 mr-2"></i>
                            Productos
                        </h2>
                        <button type="button" onclick="agregarProducto()" 
                                class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded-lg text-sm flex items-center">
                            <i class="fas fa-plus mr-2"></i>
                            Agregar Producto
                        </button>
                    </div>
                    
                    <!-- Tabla de productos -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full">
                            <thead>
                                <tr class="bg-gray-50">
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Producto</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Cantidad</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Precio</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Subtotal</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Acción</th>
                                </tr>
                            </thead>
                            <tbody id="productos-container">
                                <?php foreach ($detalles as $detalle): ?>
                                <tr class="producto-row border-t">
                                    <td class="px-4 py-3">
                                        <select name="producto_id[]" required 
                                                class="select-producto w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <option value="">Seleccionar...</option>
                                            <?php foreach ($productos as $p): ?>
                                                <option value="<?php echo $p['id']; ?>" 
                                                        data-precio="<?php echo $p['precio_compra']; ?>"
                                                        <?php echo $p['id'] == $detalle['producto_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($p['nombre']); ?> 
                                                    (<?php echo $p['codigo']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="px-4 py-3">
                                        <input type="number" name="cantidad[]" min="1" 
                                               value="<?php echo $detalle['cantidad']; ?>" required
                                               onchange="actualizarSubtotal(this)"
                                               class="w-24 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center">
                                            <span class="mr-1">$</span>
                                            <input type="number" name="precio[]" step="0.01" min="0" 
                                                   value="<?php echo $detalle['precio']; ?>" required
                                                   onchange="actualizarSubtotal(this)"
                                                   class="w-28 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        </div>
                                    </td>
                                    <td class="px-4 py-3">
                                        <input type="text" name="subtotal[]" readonly
                                               value="$<?php echo number_format($detalle['cantidad'] * $detalle['precio'], 0, ',', '.'); ?>"
                                               class="w-28 bg-gray-100 border border-gray-300 rounded-lg px-3 py-2 text-sm font-semibold">
                                    </td>
                                    <td class="px-4 py-3">
                                        <button type="button" onclick="eliminarProducto(this)" 
                                                class="text-red-600 hover:text-red-900">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Panel de resumen y acción -->
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center">
                <div class="space-y-2">
                    <div class="flex items-center space-x-4">
                        <span class="text-gray-600">Subtotal:</span>
                        <span id="subtotal" class="text-xl font-semibold">
                            $<?php echo number_format($compra['subtotal'], 0, ',', '.'); ?>
                        </span>
                    </div>
                    <div class="flex items-center space-x-4">
                        <span class="text-gray-600">Total:</span>
                        <span id="total" class="text-2xl font-bold text-blue-600">
                            $<?php echo number_format($compra['total'], 0, ',', '.'); ?>
                        </span>
                    </div>
                </div>
                
                <div class="flex space-x-3 mt-4 md:mt-0">
                    <button type="button" onclick="if(confirm('¿Cancelar cambios?')) window.location='index.php'" 
                            class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button type="submit" 
                            class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg flex items-center">
                        <i class="fas fa-save mr-2"></i>
                        Guardar Cambios
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Template para nueva fila -->
<template id="producto-template">
    <tr class="producto-row border-t">
        <td class="px-4 py-3">
            <select name="producto_id[]" required 
                    class="select-producto w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="">Seleccionar...</option>
                <?php foreach ($productos as $p): ?>
                    <option value="<?php echo $p['id']; ?>" 
                            data-precio="<?php echo $p['precio_compra']; ?>">
                        <?php echo htmlspecialchars($p['nombre']); ?> 
                        (<?php echo $p['codigo']; ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="px-4 py-3">
            <input type="number" name="cantidad[]" min="1" value="1" required
                   onchange="actualizarSubtotal(this)"
                   class="w-24 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
        </td>
        <td class="px-4 py-3">
            <div class="flex items-center">
                <span class="mr-1">$</span>
                <input type="number" name="precio[]" step="0.01" min="0" required
                       onchange="actualizarSubtotal(this)"
                       class="w-28 border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
        </td>
        <td class="px-4 py-3">
            <input type="text" name="subtotal[]" readonly
                   class="w-28 bg-gray-100 border border-gray-300 rounded-lg px-3 py-2 text-sm font-semibold">
        </td>
        <td class="px-4 py-3">
            <button type="button" onclick="eliminarProducto(this)" 
                    class="text-red-600 hover:text-red-900">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    </tr>
</template>

<!-- Select2 CSS y JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
.select2-container--default .select2-selection--single {
    height: 42px;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 40px;
    padding-left: 12px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 40px;
}
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
let productosData = <?php echo json_encode($productos_js); ?>;

// Inicializar
document.addEventListener('DOMContentLoaded', function() {
    $('.select2').select2();
    $('.select-producto').each(function() {
        initSelectProducto(this);
    });
});

function initSelectProducto(select) {
    $(select).select2({
        placeholder: 'Buscar producto...',
        width: '100%'
    }).on('select2:select', function(e) {
        const data = e.params.data;
        const precio = $(this).find(':selected').data('precio');
        const row = $(this).closest('.producto-row');
        row.find('input[name="precio[]"]').val(precio);
        actualizarSubtotal(row.find('input[name="cantidad[]"]')[0]);
    });
}

function agregarProducto() {
    const template = document.getElementById('producto-template');
    const clone = template.content.cloneNode(true);
    const container = document.getElementById('productos-container');
    container.appendChild(clone);
    
    const newSelect = container.lastElementChild.querySelector('.select-producto');
    initSelectProducto(newSelect);
}

function eliminarProducto(btn) {
    const row = btn.closest('.producto-row');
    const rows = document.querySelectorAll('.producto-row');
    if (rows.length > 1) {
        $(row).remove();
        calcularTotales();
    } else {
        alert('Debe haber al menos un producto');
    }
}

function actualizarSubtotal(input) {
    const row = input.closest('.producto-row');
    const cantidad = parseFloat(row.querySelector('input[name="cantidad[]"]').value) || 0;
    const precio = parseFloat(row.querySelector('input[name="precio[]"]').value) || 0;
    const subtotal = cantidad * precio;
    
    row.querySelector('input[name="subtotal[]"]').value = '$' + subtotal.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    calcularTotales();
}

function calcularTotales() {
    let subtotal = 0;
    
    document.querySelectorAll('.producto-row').forEach(row => {
        const cantidad = parseFloat(row.querySelector('input[name="cantidad[]"]').value) || 0;
        const precio = parseFloat(row.querySelector('input[name="precio[]"]').value) || 0;
        subtotal += cantidad * precio;
    });
    
    const total = subtotal;
    
    document.getElementById('subtotal').textContent = '$' + subtotal.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    document.getElementById('total').textContent = '$' + total.toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

// Validar formulario
document.getElementById('formCompra').addEventListener('submit', function(e) {
    const proveedor = document.querySelector('select[name="proveedor_id"]').value;
    if (!proveedor) {
        e.preventDefault();
        alert('Seleccione un proveedor');
        return false;
    }
    
    let productosValidos = 0;
    document.querySelectorAll('.producto-row').forEach(row => {
        const select = row.querySelector('select[name="producto_id[]"]').value;
        const cantidad = parseFloat(row.querySelector('input[name="cantidad[]"]').value) || 0;
        const precio = parseFloat(row.querySelector('input[name="precio[]"]').value) || 0;
        
        if (select && cantidad > 0 && precio > 0) {
            productosValidos++;
        }
    });
    
    if (productosValidos === 0) {
        e.preventDefault();
        alert('Agregue al menos un producto válido');
        return false;
    }
    
    return confirm('¿Guardar los cambios?');
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>