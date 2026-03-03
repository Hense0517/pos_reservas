<?php
/**
 * ============================================
 * ARCHIVO: completar.php
 * UBICACIÓN: /modules/reservas/completar.php
 * PROPÓSITO: Completar reserva, agregar productos/servicios y facturar
 * ============================================
 */

session_start();

require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permiso
if (!$auth->hasPermission('reservas', 'completar')) {
    $_SESSION['error'] = "No tienes permisos para completar reservas";
    header("Location: index.php");
    exit();
}

$database = Database::getInstance();
$db = $database->getConnection();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    $_SESSION['error'] = "ID de reserva no válido";
    header("Location: index.php");
    exit();
}

// Obtener datos de la reserva
$query = "SELECT r.*, u.nombre as empleado_nombre
          FROM reservas r
          LEFT JOIN usuarios u ON r.usuario_id = u.id
          WHERE r.id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$id]);
$reserva = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reserva) {
    $_SESSION['error'] = "Reserva no encontrada";
    header("Location: index.php");
    exit();
}

// Solo permitir completar reservas confirmadas
if ($reserva['estado'] != 'confirmada') {
    $_SESSION['error'] = "Solo se pueden completar reservas confirmadas";
    header("Location: ver.php?id=" . $id);
    exit();
}

// Obtener servicios de la reserva
$query_servicios = "SELECT * FROM reserva_detalles_servicios WHERE reserva_id = ?";
$stmt_servicios = $db->prepare($query_servicios);
$stmt_servicios->execute([$id]);
$servicios = $stmt_servicios->fetchAll(PDO::FETCH_ASSOC);

// Calcular total de servicios para JavaScript
$total_servicios = 0;
foreach ($servicios as $s) {
    $total_servicios += floatval($s['precio_original'] ?? 0);
}

// Obtener métodos de pago
$metodos_pago = [
    'efectivo' => 'Efectivo',
    'tarjeta' => 'Tarjeta de crédito/débito',
    'transferencia' => 'Transferencia bancaria',
    'nequi' => 'Nequi',
    'daviplata' => 'Daviplata',
    'mercado_pago' => 'Mercado Pago',
    'mixto' => 'Mixto (varios métodos)'
];

$page_title = "Completar Reserva - " . ($config['nombre_negocio'] ?? 'Sistema POS');
include __DIR__ . '/../../includes/header.php';
?>

<style>
.producto-item {
    transition: all 0.2s ease;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    padding: 0.75rem;
    cursor: pointer;
}

.producto-item:hover {
    border-color: #3b82f6;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.producto-item.seleccionado {
    border-color: #10b981;
    background-color: #f0fdf4;
}

.resumen-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.5rem;
    border-bottom: 1px dashed #e5e7eb;
}

.resumen-total {
    font-size: 1.25rem;
    font-weight: bold;
    color: #059669;
}

.metodo-pago-card {
    border: 2px solid #e5e7eb;
    border-radius: 0.5rem;
    padding: 1rem;
    cursor: pointer;
    transition: all 0.2s ease;
}

.metodo-pago-card:hover {
    border-color: #3b82f6;
    background-color: #eff6ff;
}

.metodo-pago-card.seleccionado {
    border-color: #10b981;
    background-color: #f0fdf4;
}

.cantidad-input {
    width: 80px;
    text-align: center;
    border: 1px solid #d1d5db;
    border-radius: 0.375rem;
    padding: 0.25rem;
}
</style>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-cash-register text-green-600 mr-2"></i>
                Completar Reserva
            </h1>
            <p class="text-gray-600 mt-1">
                Código: <span class="font-mono font-bold"><?php echo htmlspecialchars($reserva['codigo_reserva']); ?></span> - 
                Cliente: <span class="font-semibold"><?php echo htmlspecialchars($reserva['nombre_cliente']); ?></span>
            </p>
        </div>
        <div class="flex space-x-3">
            <a href="ver.php?id=<?php echo $id; ?>" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
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

    <form id="formCompletar" method="POST" action="procesar_completar.php" onsubmit="return validarFormulario()">
        <input type="hidden" name="reserva_id" value="<?php echo $id; ?>">
        <input type="hidden" name="productos_json" id="productos_json">
        <input type="hidden" name="servicios_json" id="servicios_json">

        <!-- Datos de la reserva -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <p class="text-sm text-gray-500">Cliente</p>
                <p class="font-semibold"><?php echo htmlspecialchars($reserva['nombre_cliente']); ?></p>
                <?php if (!empty($reserva['telefono_cliente'])): ?>
                <p class="text-sm text-gray-600">📞 <?php echo htmlspecialchars($reserva['telefono_cliente']); ?></p>
                <?php endif; ?>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <p class="text-sm text-gray-500">Fecha y Hora</p>
                <p class="font-semibold"><?php echo date('d/m/Y', strtotime($reserva['fecha_reserva'])); ?></p>
                <p class="text-sm text-gray-600">⏰ <?php echo date('H:i', strtotime($reserva['hora_reserva'])); ?></p>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <p class="text-sm text-gray-500">Empleado</p>
                <p class="font-semibold"><?php echo htmlspecialchars($reserva['empleado_nombre'] ?? 'No asignado'); ?></p>
            </div>
        </div>

        <!-- Servicios de la reserva (con posibilidad de ajustar precios) -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-cut text-blue-600 mr-2"></i>
                Servicios Realizados
            </h2>
            
            <div class="space-y-3">
                <?php foreach ($servicios as $index => $s): ?>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex-1">
                        <span class="font-medium"><?php echo htmlspecialchars($s['nombre_servicio']); ?></span>
                    </div>
                    <div class="flex items-center space-x-4">
                        <div>
                            <label class="text-sm text-gray-600 mr-2">Precio:</label>
                            <input type="number" 
                                   name="servicios[<?php echo $s['servicio_id']; ?>][precio]" 
                                   value="<?php echo floatval($s['precio_original']); ?>" 
                                   step="0.01" min="0"
                                   class="border rounded-lg px-3 py-1 w-32 text-right servicio-precio"
                                   data-original="<?php echo floatval($s['precio_original']); ?>"
                                   onchange="actualizarResumen()">
                        </div>
                        <input type="hidden" name="servicios[<?php echo $s['servicio_id']; ?>][id]" value="<?php echo $s['servicio_id']; ?>">
                        <input type="hidden" name="servicios[<?php echo $s['servicio_id']; ?>][nombre]" value="<?php echo htmlspecialchars($s['nombre_servicio']); ?>">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Búsqueda de productos adicionales -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-box text-green-600 mr-2"></i>
                Productos Adicionales
            </h2>
            
            <div class="mb-4">
                <div class="relative">
                    <input type="text" id="buscar_producto" 
                           placeholder="Buscar producto por nombre o código..." 
                           class="w-full px-4 py-3 border rounded-lg pl-12 focus:ring-2 focus:ring-green-500">
                    <i class="fas fa-search absolute left-4 top-4 text-gray-400"></i>
                </div>
                <p class="text-xs text-gray-500 mt-1">Mínimo 2 caracteres para buscar</p>
            </div>

            <!-- Resultados de búsqueda -->
            <div id="resultados_productos" class="hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3 mb-4 max-h-96 overflow-y-auto p-2"></div>

            <!-- Productos seleccionados -->
            <div id="productos_seleccionados" class="space-y-2 mt-4">
                <h3 class="font-medium text-gray-700">Productos agregados:</h3>
                <div id="lista_productos" class="space-y-2">
                    <p class="text-gray-500 text-center py-4">No hay productos agregados</p>
                </div>
            </div>
        </div>

        <!-- Métodos de pago -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-credit-card text-purple-600 mr-2"></i>
                Método de Pago
            </h2>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                <?php foreach ($metodos_pago as $valor => $etiqueta): ?>
                <div class="metodo-pago-card <?php echo $valor == 'efectivo' ? 'seleccionado' : ''; ?>" 
                     onclick="seleccionarMetodoPago('<?php echo $valor; ?>')"
                     data-metodo="<?php echo $valor; ?>">
                    <div class="text-center">
                        <i class="fas fa-<?php 
                            echo $valor == 'efectivo' ? 'money-bill-wave' : 
                                ($valor == 'tarjeta' ? 'credit-card' : 
                                ($valor == 'transferencia' ? 'exchange-alt' : 
                                ($valor == 'nequi' ? 'mobile-alt' : 
                                ($valor == 'daviplata' ? 'mobile' : 
                                ($valor == 'mercado_pago' ? 'hand-holding-usd' : 'money-bill'))))); 
                        ?> text-2xl mb-2"></i>
                        <p class="text-sm font-medium"><?php echo $etiqueta; ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <input type="hidden" name="metodo_pago" id="metodo_pago" value="efectivo">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Monto recibido</label>
                    <input type="number" name="monto_recibido" id="monto_recibido" step="0.01" min="0" value="0"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                           onchange="calcularCambio()">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Cambio</label>
                    <input type="text" id="cambio" readonly
                           class="w-full px-3 py-2 border rounded-lg bg-gray-100"
                           value="$0.00">
                </div>
            </div>
        </div>

        <!-- Resumen y total -->
        <div class="bg-indigo-50 rounded-lg shadow p-6 mb-6">
            <div class="flex justify-between items-center">
                <div>
                    <h3 class="text-lg font-semibold text-indigo-800">Resumen de la venta</h3>
                    <p class="text-sm text-indigo-600">Servicios + Productos</p>
                </div>
                <div class="text-right">
                    <p class="text-3xl font-bold text-indigo-800" id="total_general">
                        $<?php echo number_format($total_servicios, 2); ?>
                    </p>
                    <p class="text-sm text-indigo-600" id="detalle_total">
                        Servicios: $<?php echo number_format($total_servicios, 2); ?> | Productos: $0.00
                    </p>
                </div>
            </div>
        </div>

        <!-- Observaciones -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <label class="block text-sm font-medium text-gray-700 mb-2">Observaciones de la venta</label>
            <textarea name="observaciones_venta" rows="2" 
                      class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-green-500"
                      placeholder="Notas adicionales sobre la venta..."></textarea>
        </div>

        <!-- Botones de acción -->
        <div class="flex justify-end space-x-3">
            <a href="ver.php?id=<?php echo $id; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg">
                Cancelar
            </a>
            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg flex items-center">
                <i class="fas fa-check-circle mr-2"></i>
                Completar y Facturar
            </button>
        </div>
    </form>
</div>

<script>
// Variables globales
let productosSeleccionados = [];
let timeoutBusqueda;
let totalServicios = <?php echo floatval($total_servicios); ?>;

// Búsqueda de productos - CORREGIDO
document.getElementById('buscar_producto')?.addEventListener('input', function(e) {
    clearTimeout(timeoutBusqueda);
    const termino = e.target.value.trim();
    
    if (termino.length < 2) {
        document.getElementById('resultados_productos').classList.add('hidden');
        return;
    }
    
    timeoutBusqueda = setTimeout(() => {
        fetch('ajax_buscar_productos.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ termino: termino })
        })
        .then(res => res.json())
        .then(productos => {
            const resultados = document.getElementById('resultados_productos');
            
            if (productos && productos.length > 0) {
                resultados.innerHTML = '';
                productos.forEach(p => {
                    // Asegurar que precio_venta sea número
                    const precio = parseFloat(p.precio_venta) || 0;
                    const stock = parseInt(p.stock) || 0;
                    const id = parseInt(p.id) || 0;
                    
                    resultados.innerHTML += `
                        <div class="producto-item ${productosSeleccionados.some(sel => sel.id === id) ? 'seleccionado' : ''}" 
                             onclick="agregarProducto(${id}, '${p.nombre.replace(/'/g, "\\'")}', ${precio}, ${stock})">
                            <div class="flex items-center space-x-3">
                                <div class="flex-1">
                                    <p class="font-medium">${p.nombre}</p>
                                    <p class="text-sm text-gray-500">Código: ${p.codigo || 'N/A'}</p>
                                    <p class="text-sm text-gray-500">Stock: ${stock}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-green-600">$${precio.toFixed(2)}</p>
                                </div>
                            </div>
                        </div>
                    `;
                });
                resultados.classList.remove('hidden');
            } else {
                resultados.innerHTML = '<div class="col-span-3 text-center text-gray-500 py-4">No se encontraron productos</div>';
                resultados.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('resultados_productos').innerHTML = 
                '<div class="col-span-3 text-center text-red-500 py-4">Error al buscar productos</div>';
            document.getElementById('resultados_productos').classList.remove('hidden');
        });
    }, 300);
});

function agregarProducto(id, nombre, precio, stock) {
    // Asegurar que sean números
    id = parseInt(id) || 0;
    precio = parseFloat(precio) || 0;
    stock = parseInt(stock) || 0;
    
    if (id === 0 || precio === 0) {
        alert('Error: Datos de producto inválidos');
        return;
    }
    
    if (productosSeleccionados.some(p => p.id === id)) {
        // Si ya está seleccionado, aumentar cantidad
        const producto = productosSeleccionados.find(p => p.id === id);
        if (producto.cantidad < stock) {
            producto.cantidad++;
            producto.subtotal = producto.cantidad * producto.precio;
        } else {
            alert('Stock insuficiente. Stock disponible: ' + stock);
        }
    } else {
        // Nuevo producto
        productosSeleccionados.push({
            id: id,
            nombre: nombre,
            precio: precio,
            cantidad: 1,
            subtotal: precio,
            stock: stock
        });
    }
    
    actualizarListaProductos();
    document.getElementById('buscar_producto').value = '';
    document.getElementById('resultados_productos').classList.add('hidden');
}

function eliminarProducto(id) {
    productosSeleccionados = productosSeleccionados.filter(p => p.id !== id);
    actualizarListaProductos();
}

function actualizarCantidad(id, nuevaCantidad) {
    const producto = productosSeleccionados.find(p => p.id === id);
    if (producto) {
        if (nuevaCantidad <= 0) {
            eliminarProducto(id);
        } else if (nuevaCantidad <= producto.stock) {
            producto.cantidad = nuevaCantidad;
            producto.subtotal = nuevaCantidad * producto.precio;
            actualizarListaProductos();
        } else {
            alert('Stock insuficiente. Stock disponible: ' + producto.stock);
            // Restaurar valor anterior
            const input = document.querySelector(`input[data-producto-id="${id}"]`);
            if (input) input.value = producto.cantidad;
        }
    }
}

function actualizarListaProductos() {
    const container = document.getElementById('lista_productos');
    
    if (productosSeleccionados.length === 0) {
        container.innerHTML = '<p class="text-gray-500 text-center py-4">No hay productos agregados</p>';
    } else {
        container.innerHTML = '';
        productosSeleccionados.forEach(p => {
            container.innerHTML += `
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                    <div class="flex-1">
                        <p class="font-medium">${p.nombre}</p>
                        <p class="text-sm text-gray-500">$${p.precio.toFixed(2)} c/u</p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center space-x-2">
                            <button type="button" onclick="actualizarCantidad(${p.id}, ${p.cantidad - 1})" 
                                    class="w-8 h-8 bg-gray-200 rounded-full hover:bg-gray-300">-</button>
                            <input type="number" value="${p.cantidad}" min="1" max="${p.stock}"
                                   data-producto-id="${p.id}"
                                   class="cantidad-input"
                                   onchange="actualizarCantidad(${p.id}, parseInt(this.value))">
                            <button type="button" onclick="actualizarCantidad(${p.id}, ${p.cantidad + 1})" 
                                    class="w-8 h-8 bg-gray-200 rounded-full hover:bg-gray-300">+</button>
                        </div>
                        <p class="font-semibold w-24 text-right">$${p.subtotal.toFixed(2)}</p>
                        <button type="button" onclick="eliminarProducto(${p.id})" 
                                class="text-red-500 hover:text-red-700">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
        });
    }
    
    actualizarResumen();
}

function actualizarResumen() {
    // Calcular total productos
    const totalProductos = productosSeleccionados.reduce((sum, p) => sum + p.subtotal, 0);
    
    // Calcular total servicios (con precios ajustados)
    let totalServiciosActualizado = 0;
    document.querySelectorAll('.servicio-precio').forEach(input => {
        totalServiciosActualizado += parseFloat(input.value) || 0;
    });
    
    const totalGeneral = totalServiciosActualizado + totalProductos;
    
    // Actualizar displays
    document.getElementById('total_general').textContent = '$' + totalGeneral.toFixed(2);
    document.getElementById('detalle_total').textContent = 
        `Servicios: $${totalServiciosActualizado.toFixed(2)} | Productos: $${totalProductos.toFixed(2)}`;
    
    // Actualizar JSONs para enviar
    const serviciosData = [];
    const servicioInputs = document.querySelectorAll('input[name^="servicios"]');
    
    // Crear un objeto para agrupar por servicio_id
    const serviciosMap = new Map();
    
    servicioInputs.forEach(input => {
        const name = input.name;
        const match = name.match(/servicios\[(\d+)\]\[(\w+)\]/);
        if (match) {
            const servicioId = match[1];
            const campo = match[2];
            
            if (!serviciosMap.has(servicioId)) {
                serviciosMap.set(servicioId, { id: parseInt(servicioId) });
            }
            
            const servicio = serviciosMap.get(servicioId);
            if (campo === 'precio') {
                servicio.precio = parseFloat(input.value) || 0;
            } else if (campo === 'nombre') {
                servicio.nombre = input.value;
            }
        }
    });
    
    // Convertir Map a array
    const serviciosArray = Array.from(serviciosMap.values()).filter(s => s.nombre);
    
    document.getElementById('servicios_json').value = JSON.stringify(serviciosArray);
    document.getElementById('productos_json').value = JSON.stringify(productosSeleccionados);
    
    calcularCambio();
}

function seleccionarMetodoPago(metodo) {
    document.querySelectorAll('.metodo-pago-card').forEach(card => {
        card.classList.remove('seleccionado');
    });
    document.querySelector(`.metodo-pago-card[data-metodo="${metodo}"]`).classList.add('seleccionado');
    document.getElementById('metodo_pago').value = metodo;
}

function calcularCambio() {
    const totalText = document.getElementById('total_general').textContent;
    const total = parseFloat(totalText.replace('$', '')) || 0;
    const recibido = parseFloat(document.getElementById('monto_recibido').value) || 0;
    const cambio = recibido - total;
    
    document.getElementById('cambio').value = '$' + (cambio > 0 ? cambio.toFixed(2) : '0.00');
}

function validarFormulario() {
    const metodoPago = document.getElementById('metodo_pago').value;
    const totalText = document.getElementById('total_general').textContent;
    const total = parseFloat(totalText.replace('$', '')) || 0;
    const recibido = parseFloat(document.getElementById('monto_recibido').value) || 0;
    
    if (recibido < total) {
        alert('El monto recibido es menor al total de la venta');
        return false;
    }
    
    if (!metodoPago) {
        alert('Debe seleccionar un método de pago');
        return false;
    }
    
    return confirm('¿Completar la reserva y registrar la venta?');
}

// Inicializar
document.addEventListener('DOMContentLoaded', function() {
    actualizarResumen();
    
    // Inicializar método de pago
    seleccionarMetodoPago('efectivo');
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>