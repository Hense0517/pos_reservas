<?php
// modules/reservas/crear.php
require_once __DIR__ . '/../../includes/config.php';

if (!$auth->hasPermission('reservas', 'crear')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

$page_title = 'Nueva Reserva';
include __DIR__ . '/../../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="bg-white rounded-lg shadow-lg p-6">
        <!-- Encabezado -->
        <div class="flex justify-between items-center mb-6 pb-4 border-b">
            <div>
                <h1 class="text-2xl font-bold text-gray-800">Nueva Reserva</h1>
                <p class="text-gray-600 text-sm mt-1">Complete los datos para crear una nueva reserva</p>
            </div>
            <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>Volver
            </a>
        </div>

        <form id="formReserva" method="POST" action="guardar.php">
            <input type="hidden" id="cliente_id" name="cliente_id">
            <input type="hidden" id="servicios_json" name="servicios_json">

            <!-- SECCIÓN: Búsqueda de Cliente -->
            <div class="mb-8 bg-gray-50 p-4 rounded-lg">
                <h2 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                    <i class="fas fa-user mr-2 text-indigo-500"></i>
                    Datos del Cliente
                </h2>
                
                <!-- Buscador -->
                <div class="relative mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Buscar Cliente</label>
                    <div class="relative">
                        <input type="text" id="buscar_cliente" 
                               placeholder="Escriba nombre, documento o teléfono..." 
                               class="w-full px-4 py-3 border rounded-lg pl-12 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <i class="fas fa-search absolute left-4 top-4 text-gray-400 text-lg"></i>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Mínimo 2 caracteres para buscar</p>
                </div>

                <!-- Resultados de búsqueda -->
                <div id="resultados_clientes" class="hidden mt-2 border rounded-lg max-h-72 overflow-y-auto bg-white shadow-lg absolute z-20" style="width: calc(100% - 3rem);"></div>

                <!-- Cliente seleccionado -->
                <div id="cliente_seleccionado" class="hidden mt-3 p-4 bg-green-50 border border-green-200 rounded-lg relative">
                    <div class="flex justify-between items-start">
                        <div>
                            <p class="text-sm text-gray-600">Cliente seleccionado:</p>
                            <p class="font-semibold text-lg" id="cliente_nombre"></p>
                            <p class="text-sm text-gray-600" id="cliente_documento"></p>
                        </div>
                        <button type="button" onclick="limpiarCliente()" 
                                class="text-red-500 hover:text-red-700 bg-white rounded-full p-2 shadow-sm">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>

                <!-- Formulario rápido para nuevo cliente -->
                <div id="form_nuevo_cliente" class="hidden mt-4 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                    <h4 class="font-semibold mb-3 text-blue-800 flex items-center">
                        <i class="fas fa-plus-circle mr-2"></i>
                        Crear Nuevo Cliente
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Nombre *</label>
                            <input type="text" id="nuevo_cliente_nombre" 
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" 
                                   placeholder="Nombre completo">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Documento</label>
                            <input type="text" id="nuevo_cliente_documento" 
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" 
                                   placeholder="Número de documento">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Teléfono</label>
                            <input type="text" id="nuevo_cliente_telefono" 
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" 
                                   placeholder="Teléfono">
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" id="nuevo_cliente_email" 
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500" 
                                   placeholder="correo@ejemplo.com">
                        </div>
                    </div>
                    <div class="flex justify-end mt-4">
                        <button type="button" onclick="guardarNuevoCliente()" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors flex items-center">
                            <i class="fas fa-save mr-2"></i>
                            Guardar Cliente
                        </button>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN: Datos de la Reserva -->
            <div class="mb-8 bg-gray-50 p-4 rounded-lg">
                <h2 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                    <i class="fas fa-calendar-alt mr-2 text-indigo-500"></i>
                    Detalles de la Cita
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Fecha y Hora *</label>
                        <input type="datetime-local" id="fecha_hora" name="fecha_hora" required
                               class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                    </div>
                    
                    <!-- Selector de servicio principal -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Servicio Principal *</label>
                        <select id="servicio_principal" name="servicio_principal" required
                                class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500"
                                onchange="cargarEmpleadosPorServicio()">
                            <option value="">Seleccione un servicio...</option>
                            <?php
                            $servicios_query = "SELECT id, nombre, precio, precio_variable 
                                               FROM servicios WHERE activo = 1 ORDER BY nombre";
                            $servicios_stmt = $db->prepare($servicios_query);
                            $servicios_stmt->execute();
                            while ($servicio = $servicios_stmt->fetch(PDO::FETCH_ASSOC)):
                            ?>
                            <option value="<?php echo $servicio['id']; ?>" 
                                    data-precio="<?php echo $servicio['precio']; ?>"
                                    data-variable="<?php echo $servicio['precio_variable']; ?>">
                                <?php echo htmlspecialchars($servicio['nombre']); ?> - $<?php echo number_format($servicio['precio'], 2); ?>
                                <?php echo $servicio['precio_variable'] ? ' (Variable)' : ''; ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <!-- Empleados filtrados por servicio -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Empleado</label>
                        <select id="usuario_id" name="usuario_id"
                                class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500 bg-gray-100"
                                disabled>
                            <option value="">Primero seleccione un servicio...</option>
                        </select>
                        <p id="sin_empleados_msg" class="text-xs text-red-500 hidden mt-1">
                            <i class="fas fa-exclamation-circle mr-1"></i>
                            No hay empleados disponibles para este servicio
                        </p>
                        <p id="cargando_empleados" class="text-xs text-gray-500 hidden mt-1">
                            <i class="fas fa-spinner fa-spin mr-1"></i>
                            Cargando empleados...
                        </p>
                    </div>
                </div>
            </div>

            <!-- SECCIÓN: Servicios Adicionales -->
            <div class="mb-8 bg-gray-50 p-4 rounded-lg">
                <h2 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                    <i class="fas fa-cut mr-2 text-indigo-500"></i>
                    Servicios Adicionales <span class="text-sm font-normal text-gray-500 ml-2">(Opcional)</span>
                </h2>
                
                <!-- Buscador de servicios adicionales -->
                <div class="relative mb-4">
                    <input type="text" id="buscar_servicio_adicional" 
                           placeholder="Buscar servicio adicional por nombre..." 
                           class="w-full px-4 py-2 border rounded-lg pl-10 focus:ring-2 focus:ring-indigo-500"
                           disabled>
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    <p class="text-xs text-gray-500 mt-1">Primero seleccione el servicio principal</p>
                </div>

                <!-- Resultados de servicios adicionales -->
                <div id="resultados_servicios_adicionales" class="hidden mt-2 border rounded-lg max-h-60 overflow-y-auto bg-white shadow-lg mb-4"></div>
                
                <!-- Lista de servicios adicionales seleccionados -->
                <div id="servicios_adicionales_seleccionados" class="space-y-2 mt-3">
                    <!-- Se llenará dinámicamente -->
                </div>
                
                <!-- Total servicios -->
                <div id="total_servicios_container" class="mt-4 p-3 bg-indigo-50 rounded-lg text-right hidden">
                    <span class="text-sm text-gray-600">Total servicios:</span>
                    <span class="text-xl font-bold text-indigo-600 ml-2" id="total_servicios">$0.00</span>
                </div>
            </div>

            <!-- SECCIÓN: Observaciones -->
            <div class="mb-8">
                <label class="block text-sm font-medium text-gray-700 mb-2">Observaciones</label>
                <textarea name="observaciones" rows="3" 
                          class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500"
                          placeholder="Notas adicionales sobre la reserva..."></textarea>
            </div>

            <!-- Botones de acción -->
            <div class="flex justify-end gap-3 pt-4 border-t">
                <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg transition-colors">
                    Cancelar
                </a>
                <button type="submit" id="btnGuardar" 
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2 rounded-lg transition-colors flex items-center disabled:opacity-50 disabled:cursor-not-allowed"
                        disabled>
                    <i class="fas fa-save mr-2"></i>
                    Crear Reserva
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Variables globales
let serviciosSeleccionados = [];
let serviciosAdicionales = [];
let timeoutBusqueda;
let clienteSeleccionado = false;
let servicioPrincipalSeleccionado = null;

// ============================================
// FUNCIONES DE BÚSQUEDA DE CLIENTES
// ============================================
document.getElementById('buscar_cliente')?.addEventListener('input', function(e) {
    clearTimeout(timeoutBusqueda);
    const termino = e.target.value.trim();
    
    if (termino.length < 2) {
        document.getElementById('resultados_clientes').classList.add('hidden');
        return;
    }
    
    timeoutBusqueda = setTimeout(() => {
        fetch('ajax_busquedas_simple.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'termino=' + encodeURIComponent(termino)
        })
        .then(res => res.json())
        .then(clientes => {
            const resultados = document.getElementById('resultados_clientes');
            
            if (clientes && clientes.length > 0) {
                resultados.innerHTML = '';
                clientes.forEach(c => {
                    resultados.innerHTML += `
                        <div class="p-3 hover:bg-gray-100 cursor-pointer border-b transition-colors" 
                             onclick="seleccionarCliente(${c.id}, '${c.nombre.replace(/'/g, "\\'")}', '${c.numero_documento || ''}')">
                            <div class="font-medium">${c.nombre}</div>
                            <div class="text-sm text-gray-600">
                                📄 ${c.numero_documento || 'Sin documento'}
                            </div>
                        </div>
                    `;
                });
                resultados.classList.remove('hidden');
                document.getElementById('form_nuevo_cliente').classList.add('hidden');
            } else {
                resultados.innerHTML = `
                    <div class="p-4 text-center">
                        <p class="text-gray-600 mb-3">No se encontraron clientes con "<strong>${termino}</strong>"</p>
                        <button type="button" onclick="mostrarFormNuevoCliente('${termino.replace(/'/g, "\\'")}')" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm transition-colors">
                            <i class="fas fa-plus mr-2"></i>
                            Crear nuevo cliente
                        </button>
                    </div>
                `;
                resultados.classList.remove('hidden');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('resultados_clientes').innerHTML = `
                <div class="p-4 text-center text-red-600">
                    Error al buscar clientes
                </div>
            `;
            document.getElementById('resultados_clientes').classList.remove('hidden');
        });
    }, 300);
});

// Cerrar resultados al hacer clic fuera
document.addEventListener('click', function(e) {
    const resultados = document.getElementById('resultados_clientes');
    const buscador = document.getElementById('buscar_cliente');
    
    if (resultados && !resultados.contains(e.target) && e.target !== buscador) {
        resultados.classList.add('hidden');
    }
});

function seleccionarCliente(id, nombre, documento) {
    document.getElementById('cliente_id').value = id;
    document.getElementById('cliente_nombre').innerText = nombre;
    document.getElementById('cliente_documento').innerText = documento ? '📄 ' + documento : 'Sin documento';
    
    document.getElementById('cliente_seleccionado').classList.remove('hidden');
    document.getElementById('buscar_cliente').value = '';
    document.getElementById('resultados_clientes').classList.add('hidden');
    document.getElementById('form_nuevo_cliente').classList.add('hidden');
    
    clienteSeleccionado = true;
    verificarFormularioCompleto();
}

function limpiarCliente() {
    document.getElementById('cliente_id').value = '';
    document.getElementById('cliente_seleccionado').classList.add('hidden');
    document.getElementById('buscar_cliente').focus();
    clienteSeleccionado = false;
    verificarFormularioCompleto();
}

function mostrarFormNuevoCliente(termino) {
    document.getElementById('resultados_clientes').classList.add('hidden');
    document.getElementById('form_nuevo_cliente').classList.remove('hidden');
    document.getElementById('nuevo_cliente_nombre').value = termino;
    document.getElementById('nuevo_cliente_documento').focus();
}

function guardarNuevoCliente() {
    const nombre = document.getElementById('nuevo_cliente_nombre').value.trim();
    const documento = document.getElementById('nuevo_cliente_documento').value.trim();
    const telefono = document.getElementById('nuevo_cliente_telefono').value.trim();
    const email = document.getElementById('nuevo_cliente_email').value.trim();
    
    if (!nombre) {
        alert('El nombre es requerido');
        return;
    }
    
    const btn = event.target;
    const textoOriginal = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Guardando...';
    btn.disabled = true;
    
    fetch('ajax_busquedas_simple.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'guardar_cliente_rapido',
            nombre: nombre,
            documento: documento,
            telefono: telefono,
            email: email
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success && data.cliente) {
            seleccionarCliente(
                data.cliente.id, 
                data.cliente.nombre, 
                data.cliente.numero_documento || ''
            );
            document.getElementById('form_nuevo_cliente').classList.add('hidden');
        } else {
            alert('Error: ' + (data.message || 'No se pudo guardar el cliente'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al guardar el cliente');
    })
    .finally(() => {
        btn.innerHTML = textoOriginal;
        btn.disabled = false;
    });
}

// ============================================
// FUNCIÓN PARA CARGAR EMPLEADOS POR SERVICIO
// ============================================
function cargarEmpleadosPorServicio() {
    const servicioId = document.getElementById('servicio_principal').value;
    const empleadoSelect = document.getElementById('usuario_id');
    const sinEmpleadosMsg = document.getElementById('sin_empleados_msg');
    const cargandoMsg = document.getElementById('cargando_empleados');
    const buscarAdicional = document.getElementById('buscar_servicio_adicional');
    const totalContainer = document.getElementById('total_servicios_container');
    
    // Resetear servicios adicionales
    serviciosAdicionales = [];
    actualizarListaServiciosAdicionales();
    
    if (!servicioId) {
        empleadoSelect.innerHTML = '<option value="">Primero seleccione un servicio...</option>';
        empleadoSelect.disabled = true;
        buscarAdicional.disabled = true;
        buscarAdicional.placeholder = 'Primero seleccione el servicio principal';
        sinEmpleadosMsg.classList.add('hidden');
        cargandoMsg.classList.add('hidden');
        servicioPrincipalSeleccionado = null;
        serviciosSeleccionados = [];
        if (totalContainer) totalContainer.classList.add('hidden');
        verificarFormularioCompleto();
        return;
    }
    
    // Guardar el servicio principal seleccionado
    servicioPrincipalSeleccionado = servicioId;
    
    // Mostrar cargando
    empleadoSelect.innerHTML = '<option value="">Cargando empleados...</option>';
    empleadoSelect.disabled = true;
    buscarAdicional.disabled = true;
    buscarAdicional.placeholder = 'Cargando servicios adicionales...';
    cargandoMsg.classList.remove('hidden');
    sinEmpleadosMsg.classList.add('hidden');
    
    // Obtener precio del servicio principal
    const selectPrincipal = document.getElementById('servicio_principal');
    const selectedOption = selectPrincipal.options[selectPrincipal.selectedIndex];
    const precioPrincipal = parseFloat(selectedOption.dataset.precio || 0);
    const variablePrincipal = selectedOption.dataset.variable === '1';
    
    // Agregar servicio principal a la lista
    serviciosSeleccionados = [{
        id: parseInt(servicioId),
        nombre: selectedOption.text.split(' - ')[0],
        precio: precioPrincipal,
        precioVariable: variablePrincipal,
        esPrincipal: true
    }];
    
    // Actualizar total
    actualizarTotalServicios(serviciosSeleccionados);
    
    fetch('ajax_get_empleados.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'get_empleados_por_servicio',
            servicio_id: servicioId
        })
    })
    .then(res => res.json())
    .then(data => {
        cargandoMsg.classList.add('hidden');
        
        if (data.success && data.empleados && data.empleados.length > 0) {
            empleadoSelect.innerHTML = '<option value="">Seleccione un empleado...</option>';
            data.empleados.forEach(emp => {
                empleadoSelect.innerHTML += `
                    <option value="${emp.id}">
                        ${emp.nombre} (${emp.nivel_experiencia})
                    </option>
                `;
            });
            empleadoSelect.disabled = false;
            buscarAdicional.disabled = false;
            buscarAdicional.placeholder = 'Buscar servicio adicional...';
            sinEmpleadosMsg.classList.add('hidden');
        } else {
            empleadoSelect.innerHTML = '<option value="">No hay empleados disponibles</option>';
            empleadoSelect.disabled = true;
            buscarAdicional.disabled = true;
            buscarAdicional.placeholder = 'No hay empleados para este servicio';
            sinEmpleadosMsg.classList.remove('hidden');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        cargandoMsg.classList.add('hidden');
        empleadoSelect.innerHTML = '<option value="">Error al cargar empleados</option>';
        empleadoSelect.disabled = true;
        sinEmpleadosMsg.classList.remove('hidden');
    });
    
    verificarFormularioCompleto();
}

// ============================================
// FUNCIONES DE BÚSQUEDA DE SERVICIOS ADICIONALES
// ============================================
document.getElementById('buscar_servicio_adicional')?.addEventListener('input', function(e) {
    clearTimeout(timeoutBusqueda);
    const termino = e.target.value.trim();
    
    if (!servicioPrincipalSeleccionado) {
        alert('Primero debe seleccionar el servicio principal');
        this.value = '';
        return;
    }
    
    if (termino.length < 2) {
        document.getElementById('resultados_servicios_adicionales').classList.add('hidden');
        return;
    }
    
    const idsExcluir = [servicioPrincipalSeleccionado, ...serviciosAdicionales.map(s => s.id)];
    
    timeoutBusqueda = setTimeout(() => {
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
            const resultados = document.getElementById('resultados_servicios_adicionales');
            
            if (data.success && data.data && data.data.length > 0) {
                resultados.innerHTML = '';
                data.data.forEach(s => {
                    resultados.innerHTML += `
                        <div class="p-3 hover:bg-gray-100 cursor-pointer border-b transition-colors" 
                             onclick="agregarServicioAdicional(${s.id}, '${s.nombre.replace(/'/g, "\\'")}', ${s.precio}, ${s.precio_variable})">
                            <div class="font-medium">${s.nombre}</div>
                            <div class="text-sm text-gray-600 flex justify-between">
                                <span>$${parseFloat(s.precio).toFixed(2)}</span>
                                ${s.precio_variable ? '<span class="text-purple-600">(Precio variable)</span>' : ''}
                            </div>
                        </div>
                    `;
                });
                resultados.classList.remove('hidden');
            } else {
                resultados.innerHTML = '<div class="p-4 text-center text-gray-500">No se encontraron servicios adicionales</div>';
                resultados.classList.remove('hidden');
            }
        });
    }, 300);
});

function agregarServicioAdicional(id, nombre, precio, precioVariable) {
    if (serviciosAdicionales.some(s => s.id === id)) {
        alert('El servicio ya está agregado');
        return;
    }
    
    const servicio = { 
        id, 
        nombre, 
        precio: parseFloat(precio), 
        precioVariable: precioVariable == 1,
        esPrincipal: false
    };
    
    serviciosAdicionales.push(servicio);
    actualizarListaServiciosAdicionales();
    document.getElementById('buscar_servicio_adicional').value = '';
    document.getElementById('resultados_servicios_adicionales').classList.add('hidden');
}

function eliminarServicioAdicional(id) {
    serviciosAdicionales = serviciosAdicionales.filter(s => s.id !== id);
    actualizarListaServiciosAdicionales();
}

function actualizarListaServiciosAdicionales() {
    const container = document.getElementById('servicios_adicionales_seleccionados');
    
    if (serviciosAdicionales.length === 0) {
        container.innerHTML = '';
    } else {
        container.innerHTML = '<h3 class="text-sm font-medium text-gray-700 mb-2">Servicios adicionales:</h3>';
        serviciosAdicionales.forEach(s => {
            container.innerHTML += `
                <div class="flex items-center justify-between p-2 bg-white rounded-lg shadow-sm border mb-2">
                    <div class="flex-1">
                        <span class="font-medium">${s.nombre}</span>
                        <span class="text-indigo-600 font-semibold ml-3">$${s.precio.toFixed(2)}</span>
                        ${s.precioVariable ? '<span class="ml-2 text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded-full">Variable</span>' : ''}
                    </div>
                    <button type="button" onclick="eliminarServicioAdicional(${s.id})" 
                            class="text-red-500 hover:text-red-700 hover:bg-red-50 p-2 rounded-full transition-colors">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
        });
    }
    
    // Actualizar lista completa de servicios para enviar
    const todosServicios = [...serviciosSeleccionados, ...serviciosAdicionales];
    document.getElementById('servicios_json').value = JSON.stringify(todosServicios);
    actualizarTotalServicios(todosServicios);
    verificarFormularioCompleto();
}

function actualizarTotalServicios(servicios) {
    const totalElement = document.getElementById('total_servicios');
    const totalContainer = document.getElementById('total_servicios_container');
    
    if (!totalElement || !totalContainer) return;
    
    const total = servicios.reduce((sum, s) => sum + s.precio, 0);
    totalElement.textContent = '$' + total.toFixed(2);
    
    if (servicios.length > 0) {
        totalContainer.classList.remove('hidden');
    } else {
        totalContainer.classList.add('hidden');
    }
}

// ============================================
// VALIDACIÓN DEL FORMULARIO
// ============================================
function verificarFormularioCompleto() {
    const btnGuardar = document.getElementById('btnGuardar');
    const fechaHora = document.getElementById('fecha_hora').value;
    
    if (clienteSeleccionado && servicioPrincipalSeleccionado && fechaHora) {
        btnGuardar.disabled = false;
    } else {
        btnGuardar.disabled = true;
    }
}

document.getElementById('fecha_hora').addEventListener('change', verificarFormularioCompleto);

// ============================================
// VALIDACIÓN ANTES DE ENVIAR
// ============================================
document.getElementById('formReserva').addEventListener('submit', function(e) {
    e.preventDefault();
    
    if (!clienteSeleccionado) {
        alert('Debe seleccionar un cliente');
        return;
    }
    
    if (!servicioPrincipalSeleccionado) {
        alert('Debe seleccionar el servicio principal');
        return;
    }
    
    if (!document.getElementById('usuario_id').value) {
        alert('Debe seleccionar un empleado');
        return;
    }
    
    if (!document.getElementById('fecha_hora').value) {
        alert('Debe seleccionar fecha y hora');
        return;
    }
    
    this.submit();
});

// ============================================
// INICIALIZACIÓN
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Establecer fecha y hora por defecto (próxima hora)
    const ahora = new Date();
    ahora.setHours(ahora.getHours() + 1);
    ahora.setMinutes(0, 0, 0);
    
    const año = ahora.getFullYear();
    const mes = String(ahora.getMonth() + 1).padStart(2, '0');
    const dia = String(ahora.getDate()).padStart(2, '0');
    const horas = String(ahora.getHours()).padStart(2, '0');
    const minutos = '00';
    
    document.getElementById('fecha_hora').value = `${año}-${mes}-${dia}T${horas}:${minutos}`;
});
</script>

<style>
/* Estilos adicionales para mejorar la experiencia */
#resultados_clientes, #resultados_servicios_adicionales {
    position: absolute;
    z-index: 1000;
    background: white;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    max-height: 300px;
    overflow-y: auto;
    width: calc(100% - 2rem);
}

#resultados_clientes > div:hover, #resultados_servicios_adicionales > div:hover {
    background-color: #f3f4f6;
}

.transition-all {
    transition: all 0.3s ease;
}

input:required, select:required {
    border-left-width: 4px;
    border-left-color: #6366f1;
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>