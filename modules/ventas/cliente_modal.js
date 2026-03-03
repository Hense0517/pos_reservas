// cliente_modal.js
document.addEventListener('DOMContentLoaded', function() {
    // Botón para crear nuevo cliente
    document.getElementById('btnNuevoCliente').addEventListener('click', function() {
        mostrarModalNuevoCliente();
    });
});

// Función para mostrar el modal de nuevo cliente con SweetAlert
function mostrarModalNuevoCliente() {
    Swal.fire({
        title: 'Nuevo Cliente',
        html: `
            <div class="text-left space-y-3">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Tipo de Documento <span class="text-red-500">*</span>
                    </label>
                    <select id="swalTipoDocumento" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm">
                        <option value="">Seleccionar...</option>
                        <option value="CEDULA">Cédula</option>
                        <option value="DNI">DNI</option>
                        <option value="RUC">RUC</option>
                        <option value="PASAPORTE">Pasaporte</option>
                        <option value="TARJETA_IDENTIDAD">Tarjeta de Identidad</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Número de Documento <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="swalNumeroDocumento" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                           placeholder="Ej: 1234567890">
                    <div id="swalErrorDocumento" class="text-red-500 text-xs mt-1 hidden"></div>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                        Nombre Completo <span class="text-red-500">*</span>
                    </label>
                    <input type="text" id="swalNombre" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                           placeholder="Nombre y apellido del cliente">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                    <input type="text" id="swalTelefono" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                           placeholder="Ej: 0999999999">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" id="swalEmail" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                           placeholder="cliente@ejemplo.com">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Dirección</label>
                    <textarea id="swalDireccion" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                              placeholder="Dirección completa"></textarea>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notas adicionales</label>
                    <textarea id="swalNotas" rows="2"
                              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                              placeholder="Información adicional sobre el cliente"></textarea>
                </div>
            </div>
        `,
        width: '500px',
        padding: '20px',
        showCancelButton: true,
        confirmButtonText: 'Guardar Cliente',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#3b82f6',
        cancelButtonColor: '#64748b',
        focusConfirm: false,
        preConfirm: async () => {
            const tipoDocumento = document.getElementById('swalTipoDocumento').value;
            const numeroDocumento = document.getElementById('swalNumeroDocumento').value.trim();
            const nombre = document.getElementById('swalNombre').value.trim();
            const telefono = document.getElementById('swalTelefono').value.trim();
            const email = document.getElementById('swalEmail').value.trim();
            const direccion = document.getElementById('swalDireccion').value.trim();
            const notas = document.getElementById('swalNotas').value.trim();

            // Validaciones
            if (!tipoDocumento) {
                Swal.showValidationMessage('Seleccione el tipo de documento');
                return false;
            }

            if (!numeroDocumento) {
                Swal.showValidationMessage('El número de documento es obligatorio');
                return false;
            }

            if (!nombre) {
                Swal.showValidationMessage('El nombre es obligatorio');
                return false;
            }

            // Verificar si el documento ya existe
            try {
                const response = await fetch(`buscar_cliente.php?q=${encodeURIComponent(numeroDocumento)}`);
                const clientesExistentes = await response.json();
                
                if (clientesExistentes && clientesExistentes.length > 0) {
                    Swal.showValidationMessage('Este número de documento ya está registrado');
                    return false;
                }
            } catch (error) {
                console.error('Error verificando documento:', error);
                Swal.showValidationMessage('Error al verificar el documento');
                return false;
            }

            // Crear cliente
            try {
                const formData = new FormData();
                formData.append('tipo_documento', tipoDocumento);
                formData.append('numero_documento', numeroDocumento);
                formData.append('nombre', nombre);
                formData.append('telefono', telefono);
                formData.append('email', email);
                formData.append('direccion', direccion);
                formData.append('notas', notas);

                const response = await fetch('guardar_cliente.php', {
                    method: 'POST',
                    body: formData
                });

                const resultado = await response.json();

                if (resultado.success) {
                    // Retornar el cliente creado
                    return {
                        success: true,
                        cliente_id: resultado.cliente_id,
                        nombre: nombre,
                        tipo_documento: tipoDocumento,
                        numero_documento: numeroDocumento
                    };
                } else {
                    Swal.showValidationMessage(resultado.error || 'Error al guardar el cliente');
                    return false;
                }
            } catch (error) {
                console.error('Error:', error);
                Swal.showValidationMessage('Error de conexión');
                return false;
            }
        }
    }).then((result) => {
        if (result.isConfirmed && result.value && result.value.success) {
            const cliente = result.value;
            
            // Seleccionar el nuevo cliente automáticamente
            seleccionarCliente({
                id: cliente.cliente_id,
                nombre: cliente.nombre,
                tipo_documento: cliente.tipo_documento,
                numero_documento: cliente.numero_documento
            });
            
            // Mostrar notificación de éxito
            Swal.fire({
                title: '¡Cliente creado!',
                text: `Cliente ${cliente.nombre} creado exitosamente`,
                icon: 'success',
                confirmButtonColor: '#10b981',
                timer: 1500,
                showConfirmButton: false
            });
        }
    });
}

// Función para seleccionar cliente (debe estar disponible globalmente)
function seleccionarCliente(cliente) {
    document.getElementById('cliente_id').value = cliente.id;
    document.getElementById('cliente_id_form').value = cliente.id;
    document.getElementById('clienteNombre').textContent = cliente.nombre;
    document.getElementById('clienteDocumento').textContent = `${cliente.tipo_documento}: ${cliente.numero_documento}`;
    document.getElementById('infoCliente').classList.remove('hidden');
    
    // Si existe la función validarFormulario, llamarla
    if (typeof validarFormulario === 'function') {
        validarFormulario();
    }
}