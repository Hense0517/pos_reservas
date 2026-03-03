<?php
// modules/reservas/templates/modal_reserva.php
?>
<!-- Modal para crear/editar reserva -->
<div id="modalReserva" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50" aria-hidden="true">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-lg bg-white">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-900" id="modalTitle">Nueva Reserva</h3>
            <button onclick="cerrarModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="formReserva" onsubmit="guardarReserva(event)">
            <input type="hidden" id="reserva_id" name="id">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label for="nombre_cliente" class="block text-sm font-medium text-gray-700 mb-1">Nombre Cliente *</label>
                    <input type="text" id="nombre_cliente" name="nombre_cliente" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label for="telefono_cliente" class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                    <input type="tel" id="telefono_cliente" name="telefono_cliente"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label for="email_cliente" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" id="email_cliente" name="email_cliente"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label for="fecha_hora_reserva" class="block text-sm font-medium text-gray-700 mb-1">Fecha y Hora *</label>
                    <input type="datetime-local" id="fecha_hora_reserva" name="fecha_hora_reserva" required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label for="usuario_id" class="block text-sm font-medium text-gray-700 mb-1">Empleado (Opcional)</label>
                    <select id="usuario_id" name="usuario_id"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Seleccionar...</option>
                    </select>
                </div>
                <div>
                    <label for="servicio_id" class="block text-sm font-medium text-gray-700 mb-1">Servicio Principal *</label>
                    <select id="servicio_id" name="servicio_id" required onchange="actualizarPrecioServicio()">
                        <option value="">Seleccionar...</option>
                    </select>
                </div>
                <div>
                    <label for="precio_servicio" class="block text-sm font-medium text-gray-700 mb-1">Precio Servicio</label>
                    <input type="number" step="0.01" id="precio_servicio" name="precio_servicio"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500 bg-gray-100" readonly>
                    <p class="text-xs text-gray-500 mt-1">Se definirá al completar si es variable.</p>
                </div>
            </div>
            <div class="mb-4">
                <label for="observaciones" class="block text-sm font-medium text-gray-700 mb-1">Observaciones</label>
                <textarea id="observaciones" name="observaciones" rows="2"
                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"></textarea>
            </div>
            <div class="flex justify-end gap-2 border-t pt-3">
                <button type="button" onclick="cerrarModal()" class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded">
                    Cancelar
                </button>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded">
                    Guardar Reserva
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function actualizarPrecioServicio() {
        const select = document.getElementById('servicio_id');
        const precioInput = document.getElementById('precio_servicio');
        const selectedOption = select.options[select.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const precio = selectedOption.dataset.precio;
            const esVariable = selectedOption.dataset.variable === '1';
            precioInput.value = precio;
            precioInput.readOnly = !esVariable; // Si es variable, se puede editar al completar, no ahora.
        } else {
            precioInput.value = '';
        }
    }

    function guardarReserva(event) {
        event.preventDefault();
        const formData = new FormData(event.target);
        const data = Object.fromEntries(formData.entries());
        data.action = 'guardar_reserva'; // Añadir acción

        fetch('ajax.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(data)
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                alert('Reserva guardada con éxito.');
                cerrarModal();
                window.calendar.refetchEvents(); // Recargar eventos en el calendario
            } else {
                alert('Error al guardar: ' + result.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error de conexión al guardar.');
        });
    }
</script>