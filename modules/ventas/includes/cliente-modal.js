// ============================================
// MÓDULO DE GESTIÓN DE CLIENTES
// ============================================

const ClienteModule = (function() {
    // Variables privadas
    let clienteSeleccionado = null;

    // Inicializar módulo
    function init() {
        console.log('Módulo de clientes iniciado');
        
        // Event listeners
        document.getElementById('buscarCliente')?.addEventListener('input', function(e) {
            const query = e.target.value.trim();
            if (query.length >= 2) {
                buscarClientes(query);
            } else {
                ocultarResultadosCliente();
            }
        });

        document.getElementById('btnNuevoCliente')?.addEventListener('click', mostrarModalNuevoCliente);
        document.getElementById('cambiarCliente')?.addEventListener('click', cambiarCliente);
    }

    // Buscar clientes
    async function buscarClientes(query) {
        try {
            const response = await fetch(`buscar_cliente.php?q=${encodeURIComponent(query)}`);
            const contentType = response.headers.get('content-type');
            
            if (contentType && contentType.includes('application/json')) {
                const clientes = await response.json();
                mostrarResultadosClientes(clientes);
            }
        } catch (error) {
            console.error('Error buscando clientes:', error);
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion('Error al buscar clientes', 'error');
            }
        }
    }

    // Mostrar resultados de clientes
    function mostrarResultadosClientes(clientes) {
        const resultadosDiv = document.getElementById('resultadosCliente');
        if (!resultadosDiv) return;

        resultadosDiv.innerHTML = '';
        
        if (clientes && clientes.length > 0) {
            clientes.forEach(cliente => {
                const div = document.createElement('div');
                div.className = 'px-3 py-2 hover:bg-blue-50 cursor-pointer border-b text-xs fade-in';
                div.innerHTML = `
                    <div class="font-medium truncate">${cliente.nombre}</div>
                    <div class="text-gray-600 mt-1 truncate">
                        ${cliente.tipo_documento}: ${cliente.numero_documento}
                    </div>
                `;
                div.addEventListener('click', () => seleccionarCliente(cliente));
                resultadosDiv.appendChild(div);
            });
            resultadosDiv.classList.remove('hidden');
        } else {
            resultadosDiv.innerHTML = `
                <div class="px-3 py-3 text-gray-500 text-xs text-center fade-in">
                    <div class="mb-1">No se encontraron clientes</div>
                    <button type="button" onclick="ClienteModule.mostrarModalNuevoCliente()" 
                            class="text-blue-600 hover:text-blue-800 text-xs">
                        <i class="fas fa-plus mr-1"></i> Crear nuevo
                    </button>
                </div>
            `;
            resultadosDiv.classList.remove('hidden');
        }
    }

    function ocultarResultadosCliente() {
        const resultadosDiv = document.getElementById('resultadosCliente');
        if (resultadosDiv) {
            resultadosDiv.classList.add('hidden');
        }
    }

    // Seleccionar cliente
    function seleccionarCliente(cliente) {
        clienteSeleccionado = cliente;
        
        document.getElementById('cliente_id').value = cliente.id;
        document.getElementById('cliente_id_form').value = cliente.id;
        document.getElementById('clienteNombre').textContent = cliente.nombre;
        document.getElementById('clienteDocumento').textContent = `${cliente.tipo_documento}: ${cliente.numero_documento}`;
        document.getElementById('infoCliente').classList.remove('hidden');
        
        ocultarResultadosCliente();
        document.getElementById('buscarCliente').value = '';
        
        if (typeof window.PagoModule !== 'undefined') {
            window.PagoModule.validarFormulario();
        }
        
        if (typeof window.mostrarNotificacion === 'function') {
            window.mostrarNotificacion(`Cliente ${cliente.nombre} seleccionado`, 'success');
        }
    }

    function cambiarCliente() {
        clienteSeleccionado = null;
        document.getElementById('cliente_id').value = '';
        document.getElementById('cliente_id_form').value = '';
        document.getElementById('infoCliente').classList.add('hidden');
        document.getElementById('buscarCliente').focus();
        
        if (typeof window.PagoModule !== 'undefined') {
            window.PagoModule.validarFormulario();
        }
    }

    // Modal de nuevo cliente
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
                </div>
            `,
            width: '500px',
            showCancelButton: true,
            confirmButtonText: 'Guardar Cliente',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#3b82f6',
            cancelButtonColor: '#64748b',
            preConfirm: guardarNuevoCliente
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                seleccionarCliente(result.value);
                Swal.fire({
                    title: '¡Cliente creado!',
                    text: `Cliente ${result.value.nombre} creado exitosamente`,
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
            }
        });
    }

    async function guardarNuevoCliente() {
        const tipoDocumento = document.getElementById('swalTipoDocumento').value;
        const numeroDocumento = document.getElementById('swalNumeroDocumento').value.trim();
        const nombre = document.getElementById('swalNombre').value.trim();

        if (!tipoDocumento || !numeroDocumento || !nombre) {
            Swal.showValidationMessage('Complete los campos obligatorios');
            return false;
        }

        try {
            const formData = new FormData();
            formData.append('tipo_documento', tipoDocumento);
            formData.append('numero_documento', numeroDocumento);
            formData.append('nombre', nombre);
            formData.append('telefono', document.getElementById('swalTelefono').value.trim());
            formData.append('email', document.getElementById('swalEmail').value.trim());
            formData.append('direccion', document.getElementById('swalDireccion').value.trim());

            const response = await fetch('guardar_cliente.php', {
                method: 'POST',
                body: formData
            });

            const resultado = await response.json();

            if (resultado.success) {
                return {
                    id: resultado.cliente_id,
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

    // API pública
    return {
        init,
        seleccionarCliente,
        mostrarModalNuevoCliente,
        getClienteSeleccionado: () => clienteSeleccionado
    };
})();

// Exportar al ámbito global
window.ClienteModule = ClienteModule;