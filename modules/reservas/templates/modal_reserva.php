<?php
// modules/reservas/templates/modal_reserva.php
?>
<!-- Modal para crear/editar reserva -->
<div id="modalReserva" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-lg bg-white">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-900" id="modalTitle">Nueva Reserva</h3>
            <button onclick="cerrarModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="formReserva" onsubmit="guardarReserva(event)">
            <input type="hidden" id="reserva_id" name="id">
            <input type="hidden" id="cliente_id" name="cliente_id">
            
            <!-- Búsqueda de cliente -->
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Buscar Cliente</label>
                <div class="relative">
                    <input type="text" id="buscar_cliente" placeholder="Escribe nombre o documento..." 
                           class="w-full px-3 py-2 border rounded-lg pl-10">
                    <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                </div>
                <div id="resultados_clientes" class="hidden mt-2 border rounded-lg max-h-60 overflow-y-auto bg-white absolute z-10 w-full shadow-lg"></div>
            </div>
            
            <!-- Datos del cliente seleccionado -->
            <div id="cliente_seleccionado" class="hidden bg-green-50 border border-green-200 rounded-lg p-3 mb-4">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="font-semibold" id="cliente_nombre"></p>
                        <p class="text-sm text-gray-600" id="cliente_documento"></p>
                    </div>
                    <button type="button" onclick="limpiarCliente()" class="text-red-500 hover:text-red-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            
            <!-- Formulario rápido para nuevo cliente -->
            <div id="form_nuevo_cliente" class="hidden bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
                <h4 class="font-semibold mb-2">Nuevo Cliente</h4>
                <div class="grid grid-cols-2 gap-2">
                    <input type="text" id="nuevo_cliente_nombre" placeholder="Nombre *" class="col-span-2 px-3 py-2 border rounded">
                    <input type="text" id="nuevo_cliente_documento" placeholder="Documento" class="px-3 py-2 border rounded">
                    <input type="text" id="nuevo_cliente_telefono" placeholder="Teléfono" class="px-3 py-2 border rounded">
                    <input type="email" id="nuevo_cliente_email" placeholder="Email" class="col-span-2 px-3 py-2 border rounded">
                </div>
                <button type="button" onclick="guardarNuevoCliente()" class="mt-2 bg-blue-600 text-white px-3 py-1 rounded text-sm">
                    Guardar Cliente
                </button>
            </div>
            
            <!-- Datos de la reserva -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="fecha_hora_reserva" class="block text-sm font-medium text-gray-700 mb-1">Fecha y Hora *</label>
                    <input type="datetime-local" id="fecha_hora_reserva" name="fecha_hora_reserva" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                
                <div>
                    <label for="usuario_id" class="block text-sm font-medium text-gray-700 mb-1">Empleado</label>
                    <select id="usuario_id" name="usuario_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">Seleccionar empleado...</option>
                    </select>
                </div>
                
                <div>
                    <label for="servicio_id" class="block text-sm font-medium text-gray-700 mb-1">Servicio *</label>
                    <select id="servicio_id" name="servicio_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">Seleccionar servicio...</option>
                    </select>
                </div>
            </div>
            
            <div class="mb-4">
                <label for="observaciones" class="block text-sm font-medium text-gray-700 mb-1">Observaciones</label>
                <textarea id="observaciones" name="observaciones" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea>
            </div>
            
            <div class="flex justify-end gap-2 border-t pt-3">
                <button type="button" onclick="cerrarModal()" 
                        class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                    Cancelar
                </button>
                <button type="submit" 
                        class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                    Guardar Reserva
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let timeoutBusqueda;

document.getElementById('buscar_cliente')?.addEventListener('input', function(e) {
    clearTimeout(timeoutBusqueda);
    const termino = e.target.value;
    
    if (termino.length < 2) {
        document.getElementById('resultados_clientes').classList.add('hidden');
        return;
    }
    
    timeoutBusqueda = setTimeout(() => {
        buscarClientes(termino);
    }, 300);
});

function buscarClientes(termino) {
    fetch('ajax_busquedas.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'buscar_clientes',
            termino: termino
        })
    })
    .then(res => res.json())
    .then(data => {
        const resultados = document.getElementById('resultados_clientes');
        
        if (data.success && data.data.length > 0) {
            resultados.innerHTML = '';
            data.data.forEach(c => {
                resultados.innerHTML += `
                    <div class="p-2 hover:bg-gray-100 cursor-pointer border-b" 
                         onclick="seleccionarCliente(${c.id}, '${c.nombre}', '${c.numero_documento || ''}')">
                        <div class="font-medium">${c.nombre}</div>
                        <div class="text-sm text-gray-600">${c.numero_documento || 'Sin documento'} | ${c.telefono || 'Sin teléfono'}</div>
                    </div>
                `;
            });
            resultados.classList.remove('hidden');
            document.getElementById('form_nuevo_cliente').classList.add('hidden');
        } else {
            resultados.innerHTML = `
                <div class="p-2 text-center">
                    <p class="text-gray-500 mb-2">No se encontraron clientes</p>
                    <button type="button" onclick="mostrarFormNuevoCliente('${termino}')" 
                            class="bg-blue-600 text-white px-3 py-1 rounded text-sm">
                        <i class="fas fa-plus mr-1"></i>Crear nuevo cliente
                    </button>
                </div>
            `;
            resultados.classList.remove('hidden');
        }
    });
}

function seleccionarCliente(id, nombre, documento) {
    document.getElementById('cliente_id').value = id;
    document.getElementById('cliente_nombre').innerText = nombre;
    document.getElementById('cliente_documento').innerText = documento || 'Sin documento';
    document.getElementById('cliente_seleccionado').classList.remove('hidden');
    document.getElementById('buscar_cliente').value = '';
    document.getElementById('resultados_clientes').classList.add('hidden');
    document.getElementById('form_nuevo_cliente').classList.add('hidden');
}

function limpiarCliente() {
    document.getElementById('cliente_id').value = '';
    document.getElementById('cliente_seleccionado').classList.add('hidden');
    document.getElementById('buscar_cliente').focus();
}

function mostrarFormNuevoCliente(termino) {
    document.getElementById('resultados_clientes').classList.add('hidden');
    document.getElementById('form_nuevo_cliente').classList.remove('hidden');
    document.getElementById('nuevo_cliente_nombre').value = termino;
}

function guardarNuevoCliente() {
    const nombre = document.getElementById('nuevo_cliente_nombre').value;
    const documento = document.getElementById('nuevo_cliente_documento').value;
    const telefono = document.getElementById('nuevo_cliente_telefono').value;
    const email = document.getElementById('nuevo_cliente_email').value;
    
    if (!nombre) {
        alert('El nombre es requerido');
        return;
    }
    
    fetch('ajax_busquedas.php', {
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
        if (data.success) {
            seleccionarCliente(data.cliente.id, data.cliente.nombre, data.cliente.documento);
            document.getElementById('form_nuevo_cliente').classList.add('hidden');
        } else {
            alert('Error: ' + data.message);
        }
    });
}
</script>