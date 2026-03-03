<?php
/**
 * ============================================
 * ARCHIVO: editar.php
 * UBICACIÓN: /modules/reservas/editar.php
 * PROPÓSITO: Editar una reserva existente
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
if (!$auth->hasPermission('reservas', 'editar')) {
    $_SESSION['error'] = "No tienes permisos para editar reservas";
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

// No permitir editar reservas completadas
if ($reserva['estado'] == 'completada') {
    $_SESSION['error'] = "No se puede editar una reserva completada";
    header("Location: ver.php?id=" . $id);
    exit();
}

// Obtener servicios de la reserva
$query_servicios = "SELECT * FROM reserva_detalles_servicios WHERE reserva_id = ?";
$stmt_servicios = $db->prepare($query_servicios);
$stmt_servicios->execute([$id]);
$servicios_reserva = $stmt_servicios->fetchAll(PDO::FETCH_ASSOC);

// Obtener todos los servicios disponibles
$query_servicios_disponibles = "SELECT id, nombre, precio, precio_variable FROM servicios WHERE activo = 1 ORDER BY nombre";
$stmt_servicios_disponibles = $db->prepare($query_servicios_disponibles);
$stmt_servicios_disponibles->execute();
$servicios_disponibles = $stmt_servicios_disponibles->fetchAll(PDO::FETCH_ASSOC);

// Obtener empleados que pueden atender servicios
$query_empleados = "SELECT DISTINCT u.id, u.nombre, u.username
                   FROM usuarios u
                   LEFT JOIN usuarios_servicios us ON u.id = us.usuario_id
                   WHERE u.activo = 1 
                     AND (u.rol = 'admin' OR us.usuario_id IS NOT NULL)
                   ORDER BY u.nombre";
$stmt_empleados = $db->prepare($query_empleados);
$stmt_empleados->execute();
$empleados = $stmt_empleados->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Editar Reserva - " . ($config['nombre_negocio'] ?? 'Sistema POS');
include __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-edit text-indigo-600 mr-2"></i>
                Editar Reserva
            </h1>
            <p class="text-gray-600 mt-1">
                Código: <span class="font-mono font-bold"><?php echo htmlspecialchars($reserva['codigo_reserva']); ?></span>
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

    <form id="formEditarReserva" method="POST" action="guardar_edicion.php" onsubmit="return validarFormulario()">
        <input type="hidden" name="reserva_id" value="<?php echo $id; ?>">
        <input type="hidden" id="servicios_json" name="servicios_json">

        <!-- Datos de la reserva -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-calendar-alt text-indigo-500 mr-2"></i>
                Datos de la Cita
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Fecha y Hora *</label>
                    <input type="datetime-local" id="fecha_hora" name="fecha_hora" required
                           value="<?php echo date('Y-m-d\TH:i', strtotime($reserva['fecha_hora_reserva'])); ?>"
                           class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Empleado</label>
                    <select id="usuario_id" name="usuario_id"
                            class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                        <option value="">Seleccionar empleado...</option>
                        <?php foreach ($empleados as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>" <?php echo $reserva['usuario_id'] == $emp['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($emp['nombre']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Servicios -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-cut text-indigo-500 mr-2"></i>
                Servicios
            </h2>
            
            <!-- Lista de servicios actuales -->
            <div id="servicios_seleccionados" class="space-y-2 mb-4">
                <?php foreach ($servicios_reserva as $s): ?>
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border servicio-item" data-id="<?php echo $s['servicio_id']; ?>">
                    <div class="flex-1">
                        <span class="font-medium"><?php echo htmlspecialchars($s['nombre_servicio']); ?></span>
                        <span class="text-indigo-600 font-semibold ml-3">$<?php echo number_format($s['precio_original'], 2); ?></span>
                    </div>
                    <button type="button" onclick="eliminarServicio(this, <?php echo $s['servicio_id']; ?>)" 
                            class="text-red-500 hover:text-red-700 p-2">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Buscador de servicios -->
            <div class="relative">
                <input type="text" id="buscar_servicio" 
                       placeholder="Buscar servicio para agregar..." 
                       class="w-full px-4 py-2 border rounded-lg pl-10 focus:ring-2 focus:ring-indigo-500">
                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
            </div>
            
            <!-- Resultados de búsqueda -->
            <div id="resultados_servicios" class="hidden mt-2 border rounded-lg max-h-60 overflow-y-auto bg-white shadow-lg"></div>
            
            <!-- Total -->
            <div class="mt-4 p-3 bg-indigo-50 rounded-lg text-right">
                <span class="text-sm text-gray-600">Total servicios:</span>
                <span class="text-xl font-bold text-indigo-600 ml-2" id="total_servicios">
                    $<?php 
                    $total = array_sum(array_column($servicios_reserva, 'precio_original'));
                    echo number_format($total, 2); 
                    ?>
                </span>
            </div>
        </div>

        <!-- Observaciones -->
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                <i class="fas fa-comment text-indigo-500 mr-2"></i>
                Observaciones
            </h2>
            
            <textarea name="observaciones" rows="3" 
                      class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500"
                      placeholder="Notas adicionales..."><?php echo htmlspecialchars($reserva['observaciones'] ?? ''); ?></textarea>
        </div>

        <!-- Botones -->
        <div class="flex justify-end space-x-3">
            <a href="ver.php?id=<?php echo $id; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg">
                Cancelar
            </a>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg">
                <i class="fas fa-save mr-2"></i>
                Guardar Cambios
            </button>
        </div>
    </form>
</div>

<script>
let serviciosSeleccionados = [];
let timeoutBusqueda;

// Inicializar servicios seleccionados
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.servicio-item').forEach(item => {
        const id = item.dataset.id;
        const nombre = item.querySelector('.font-medium').textContent;
        const precioText = item.querySelector('.text-indigo-600').textContent;
        const precio = parseFloat(precioText.replace('$', '').replace(',', ''));
        
        serviciosSeleccionados.push({
            id: parseInt(id),
            nombre: nombre,
            precio: precio
        });
    });
    actualizarServiciosJson();
});

// Búsqueda de servicios
document.getElementById('buscar_servicio')?.addEventListener('input', function(e) {
    clearTimeout(timeoutBusqueda);
    const termino = e.target.value.trim();
    
    if (termino.length < 2) {
        document.getElementById('resultados_servicios').classList.add('hidden');
        return;
    }
    
    timeoutBusqueda = setTimeout(() => {
        const idsExcluir = serviciosSeleccionados.map(s => s.id);
        
        fetch('ajax_busquedas.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 
                action: 'buscar_servicios', 
                termino: termino,
                excluir_ids: JSON.stringify(idsExcluir)
            })
        })
        .then(res => res.json())
        .then(data => {
            const resultados = document.getElementById('resultados_servicios');
            
            if (data.success && data.data && data.data.length > 0) {
                resultados.innerHTML = '';
                data.data.forEach(s => {
                    resultados.innerHTML += `
                        <div class="p-3 hover:bg-gray-100 cursor-pointer border-b" 
                             onclick="agregarServicio(${s.id}, '${s.nombre.replace(/'/g, "\\'")}', ${s.precio})">
                            <div class="font-medium">${s.nombre}</div>
                            <div class="text-sm text-gray-600">$${s.precio.toFixed(2)}</div>
                        </div>
                    `;
                });
                resultados.classList.remove('hidden');
            } else {
                resultados.innerHTML = '<div class="p-4 text-center text-gray-500">No hay servicios disponibles</div>';
                resultados.classList.remove('hidden');
            }
        });
    }, 300);
});

function agregarServicio(id, nombre, precio) {
    if (serviciosSeleccionados.some(s => s.id === id)) {
        alert('El servicio ya está agregado');
        return;
    }
    
    serviciosSeleccionados.push({ id, nombre, precio });
    
    const container = document.getElementById('servicios_seleccionados');
    container.innerHTML += `
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg border servicio-item" data-id="${id}">
            <div class="flex-1">
                <span class="font-medium">${nombre}</span>
                <span class="text-indigo-600 font-semibold ml-3">$${precio.toFixed(2)}</span>
            </div>
            <button type="button" onclick="eliminarServicio(this, ${id})" 
                    class="text-red-500 hover:text-red-700 p-2">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    document.getElementById('buscar_servicio').value = '';
    document.getElementById('resultados_servicios').classList.add('hidden');
    actualizarTotal();
    actualizarServiciosJson();
}

function eliminarServicio(btn, id) {
    btn.closest('.servicio-item').remove();
    serviciosSeleccionados = serviciosSeleccionados.filter(s => s.id !== id);
    actualizarTotal();
    actualizarServiciosJson();
}

function actualizarTotal() {
    const total = serviciosSeleccionados.reduce((sum, s) => sum + s.precio, 0);
    document.getElementById('total_servicios').textContent = '$' + total.toFixed(2);
}

function actualizarServiciosJson() {
    document.getElementById('servicios_json').value = JSON.stringify(serviciosSeleccionados);
}

function validarFormulario() {
    if (serviciosSeleccionados.length === 0) {
        alert('Debe agregar al menos un servicio');
        return false;
    }
    
    if (!document.getElementById('fecha_hora').value) {
        alert('Debe seleccionar fecha y hora');
        return false;
    }
    
    return confirm('¿Guardar los cambios?');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>