// ============================================
// MÓDULO DE VENTAS PAUSADAS - VERSIÓN CORREGIDA
// ============================================

const PausasModule = (function() {
    // Variables privadas
    let ventaPausadaId = null;

    // Inicializar módulo
    function init(config = {}) {
        console.log('Módulo de pausas iniciado');
        
        if (config.ventaPausadaId) {
            ventaPausadaId = config.ventaPausadaId;
        }
        
        document.getElementById('btnVerPausas')?.addEventListener('click', cargarVentasPausadas);
        document.getElementById('closePausasModal')?.addEventListener('click', cerrarModal);
        document.getElementById('pausasModal')?.addEventListener('click', (e) => {
            if (e.target === document.getElementById('pausasModal')) {
                cerrarModal();
            }
        });
        
        document.getElementById('btnPausarVenta')?.addEventListener('click', pausarVenta);
        
        if (document.getElementById('btnRestaurarVenta')) {
            document.getElementById('btnRestaurarVenta').addEventListener('click', preguntarRestaurarVenta);
        }
        
        actualizarContador();
    }

    // Habilitar/deshabilitar botón de pausar
    function actualizarBotonPausa() {
        const btnPausar = document.getElementById('btnPausarVenta');
        if (btnPausar) {
            const carritoVacio = window.CarritoModule ? window.CarritoModule.estaVacio() : true;
            btnPausar.disabled = carritoVacio;
        }
    }

    // Cargar ventas pausadas
    async function cargarVentasPausadas() {
        try {
            const response = await fetch('gestion_pausas.php?accion=listar_pausadas');
            const contentType = response.headers.get('content-type');
            
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Respuesta no válida');
            }
            
            const ventas = await response.json();
            mostrarListaPausadas(ventas);
            document.getElementById('pausasModal').style.display = 'flex';
            
        } catch (error) {
            console.error('Error:', error);
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion('Error al cargar ventas pausadas', 'error');
            }
        }
    }

    // Mostrar lista de ventas pausadas
    function mostrarListaPausadas(ventas) {
        const pausasList = document.getElementById('pausasList');
        
        if (!pausasList) return;
        
        if (ventas && ventas.length > 0) {
            let html = '';
            
            ventas.forEach(venta => {
                try {
                    const datos = JSON.parse(venta.datos_venta);
                    const fecha = new Date(venta.fecha_pausa);
                    const fechaFormateada = fecha.toLocaleDateString('es-ES', {
                        day: '2-digit', month: '2-digit', year: 'numeric',
                        hour: '2-digit', minute: '2-digit'
                    });
                    
                    html += `
                        <div class="pausas-item" data-id="${venta.id}">
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <div class="font-medium text-gray-900 text-sm">Venta Pausada</div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <i class="far fa-clock mr-1"></i>${fechaFormateada}
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-bold text-green-600 text-sm">$${formatearNumero(datos.totales?.total || 0)}</div>
                                    <div class="text-xs text-gray-500 mt-1">${datos.productos?.length || 0} productos</div>
                                </div>
                            </div>
                            <div class="text-xs text-gray-600 mb-3">
                                <div class="flex items-center space-x-2">
                                    <span class="font-medium">Cliente:</span>
                                    <span>${datos.cliente?.nombre || 'Sin cliente'}</span>
                                </div>
                                <div class="flex items-center space-x-2 mt-1">
                                    <span class="font-medium">Tipo:</span>
                                    <span class="${datos.pago?.tipo_venta === 'credito' ? 'text-blue-600' : 'text-green-600'}">
                                        ${datos.pago?.tipo_venta === 'credito' ? 'Crédito' : 'Contado'}
                                    </span>
                                </div>
                            </div>
                            <div class="pausas-actions">
                                <button type="button" class="btn-resume" onclick="PausasModule.recuperarVenta(${venta.id})">
                                    <i class="fas fa-play mr-1"></i> Continuar
                                </button>
                                <button type="button" class="btn-delete" onclick="PausasModule.eliminarVenta(${venta.id})">
                                    <i class="fas fa-trash-alt mr-1"></i> Eliminar
                                </button>
                            </div>
                        </div>
                    `;
                } catch (error) {
                    console.error('Error procesando venta:', error);
                }
            });
            
            pausasList.innerHTML = html;
        } else {
            pausasList.innerHTML = `
                <div class="pausas-empty">
                    <i class="fas fa-hourglass-half text-4xl text-gray-300 mb-3"></i>
                    <p class="text-gray-500">No tienes ventas pausadas</p>
                    <p class="text-gray-400 text-sm mt-1">Las ventas que pausas aparecerán aquí</p>
                </div>
            `;
        }
    }

    function formatearNumero(numero) {
        if (isNaN(numero) || numero === 0) return '0';
        return new Intl.NumberFormat('es-CO', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(numero);
    }

    // Pausar venta actual - VERSIÓN CORREGIDA
    async function pausarVenta() {
        if (typeof window.CarritoModule === 'undefined' || window.CarritoModule.estaVacio()) {
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion('Agrega productos al carrito antes de pausar', 'warning');
            }
            return;
        }
        
        const result = await Swal.fire({
            title: '¿Pausar venta?',
            text: 'La venta actual se guardará para continuar más tarde',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#8b5cf6',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, pausar',
            cancelButtonText: 'Cancelar',
            showLoaderOnConfirm: true,
            preConfirm: enviarPausa,
            allowOutsideClick: () => !Swal.isLoading()
        });
        
        console.log('Resultado de SweetAlert:', result);
        
        if (result.isConfirmed) {
            // Verificar si la respuesta tiene success = true (puede venir directamente o dentro de result.value)
            const respuesta = result.value;
            const exito = respuesta && (respuesta.success === true);
            
            if (exito) {
                limpiarTodo();
                await actualizarContador();
                
                Swal.fire({
                    title: '¡Venta pausada!',
                    text: 'La venta se ha guardado correctamente',
                    icon: 'success',
                    confirmButtonColor: '#10b981',
                    timer: 2000,
                    showConfirmButton: false
                });
            } else {
                Swal.fire({
                    title: 'Error',
                    text: respuesta?.error || 'Error al pausar la venta',
                    icon: 'error',
                    confirmButtonColor: '#ef4444'
                });
            }
        }
    }

    async function enviarPausa() {
        console.log('Iniciando enviarPausa...');
        
        try {
            const datosVenta = prepararDatosVenta();
            console.log('Datos de venta preparados:', datosVenta);
            
            // Validar que haya productos
            if (!datosVenta.productos || datosVenta.productos.length === 0) {
                Swal.showValidationMessage('No hay productos para pausar');
                return { success: false, error: 'No hay productos' };
            }
            
            const formData = new FormData();
            formData.append('accion', 'pausar_venta');
            formData.append('datos_venta', JSON.stringify(datosVenta));
            
            console.log('Enviando petición a gestion_pausas.php...');
            
            const response = await fetch('gestion_pausas.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            console.log('Respuesta recibida, status:', response.status);
            
            // Verificar si la respuesta es JSON
            const contentType = response.headers.get('content-type');
            console.log('Content-Type:', contentType);
            
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Respuesta no es JSON. Primeros 200 caracteres:', text.substring(0, 200));
                Swal.showValidationMessage('Error en el servidor. Respuesta no válida.');
                return { success: false, error: 'Error en el servidor. Respuesta no válida.' };
            }
            
            const resultado = await response.json();
            console.log('Resultado del servidor:', resultado);
            
            // El servidor devuelve { success: true, pausa_id, message } o { success: false, error }
            // Devolvemos el objeto completo para que la función preConfirm lo maneje
            return resultado;
            
        } catch (error) {
            console.error('Error en enviarPausa:', error);
            Swal.showValidationMessage(error.message);
            return { success: false, error: error.message };
        }
    }

    // Preparar datos de la venta para pausar
    function prepararDatosVenta() {
        const productos = window.CarritoModule ? window.CarritoModule.getProductos() : [];
        
        // Obtener valores de los campos
        const clienteId = document.getElementById('cliente_id_form')?.value || '';
        const clienteNombre = document.getElementById('clienteNombre')?.textContent || '';
        const clienteDocumento = document.getElementById('clienteDocumento')?.textContent || '';
        
        const subtotal = desformatearNumero(document.getElementById('subtotal')?.textContent || '0');
        const descuentoValor = parseFloat(document.getElementById('descuento_form')?.value || '0');
        const tipoDescuento = document.querySelector('input[name="tipo_descuento"]:checked')?.value || 'monto';
        const impuestoValor = desformatearNumero(document.getElementById('impuesto')?.textContent || '0');
        const totalValor = desformatearNumero(document.getElementById('total')?.textContent || '0');
        
        const tipoVenta = document.getElementById('tipo_venta_form')?.value || 'contado';
        const metodoPago = document.getElementById('metodo_pago_form_hidden')?.value || '';
        const montoRecibido = parseFloat(document.getElementById('monto_recibido_form')?.value || '0');
        const abonoInicial = parseFloat(document.getElementById('abono_inicial_form')?.value || '0');
        const fechaLimite = document.getElementById('fecha_limite_form')?.value || '';
        const usarFechaLimite = document.getElementById('usar_fecha_limite')?.value || '0';
        
        const observaciones = document.getElementById('observaciones_form')?.value || '';
        const numeroFactura = document.querySelector('input[name="numero_factura"]')?.value || '';
        
        // Preparar objeto de datos
        const datosVenta = {
            cliente: {
                id: clienteId,
                nombre: clienteNombre,
                documento: clienteDocumento
            },
            productos: productos,
            totales: {
                subtotal: subtotal,
                descuento: descuentoValor,
                tipo_descuento: tipoDescuento,
                impuesto: impuestoValor,
                total: totalValor
            },
            pago: {
                tipo_venta: tipoVenta,
                metodo_pago: metodoPago,
                monto_recibido: montoRecibido,
                abono_inicial: abonoInicial,
                fecha_limite: fechaLimite,
                usar_fecha_limite: usarFechaLimite
            },
            observaciones: observaciones,
            fecha: new Date().toISOString(),
            numero_factura: numeroFactura
        };
        
        // Si es pago mixto, agregar los montos
        if (metodoPago === 'mixto') {
            datosVenta.pago.mixto = {
                efectivo: parseFloat(document.getElementById('monto_efectivo_mixto_form')?.value || '0'),
                tarjeta: parseFloat(document.getElementById('monto_tarjeta_mixto_form')?.value || '0'),
                transferencia: parseFloat(document.getElementById('monto_transferencia_mixto_form')?.value || '0'),
                otro: parseFloat(document.getElementById('monto_otro_mixto_form')?.value || '0')
            };
        }
        
        return datosVenta;
    }

    function desformatearNumero(texto) {
        if (!texto || texto === '' || texto === '0') return 0;
        let limpio = texto.toString()
            .replace('$', '')
            .replace(/\./g, '')
            .replace(',', '.')
            .replace(/\s/g, '')
            .trim();
        if (limpio === '' || limpio === '-') return 0;
        const numero = parseFloat(limpio);
        return isNaN(numero) ? 0 : numero;
    }

    // Limpiar todo después de pausar
    function limpiarTodo() {
        if (window.CarritoModule) {
            // Vaciar carrito
            const productos = window.CarritoModule.getProductos();
            while (productos.length > 0) {
                window.CarritoModule.eliminarProducto(0);
            }
        }
        
        // Limpiar cliente
        document.getElementById('cliente_id_form').value = '';
        document.getElementById('cliente_id').value = '';
        document.getElementById('infoCliente')?.classList.add('hidden');
        document.getElementById('clienteNombre').textContent = '';
        document.getElementById('clienteDocumento').textContent = '';
        
        // Limpiar métodos de pago
        document.querySelectorAll('.metodo-pago').forEach(btn => btn.classList.remove('selected'));
        document.getElementById('metodo_pago_form').value = '';
        document.getElementById('metodo_pago_form_hidden').value = '';
        
        // Limpiar crédito
        if (document.getElementById('toggleCredito').checked) {
            document.getElementById('toggleCredito').checked = false;
            document.getElementById('panelCredito').classList.add('hidden');
            document.getElementById('panelContado').classList.remove('hidden');
        }
        
        // Limpiar pago mixto
        document.getElementById('panelPagoMixto').classList.add('hidden');
        limpiarPagoMixto();
        
        // Limpiar campos
        document.getElementById('descuento').value = '0';
        document.getElementById('descuento_form').value = '0';
        document.getElementById('monto_recibido').value = '';
        document.getElementById('monto_recibido_form').value = '0';
        document.getElementById('observaciones').value = '';
        document.getElementById('observaciones_form').value = '';
        
        // Recalcular totales
        if (window.PagoModule) {
            window.PagoModule.calcularTotales();
        }
        
        actualizarBotonPausa();
    }

    function limpiarPagoMixto() {
        ['monto_efectivo_mixto', 'monto_tarjeta_mixto', 'monto_transferencia_mixto', 'monto_otro_mixto'].forEach(id => {
            document.getElementById(id).value = '0';
            document.getElementById(`${id}_form`).value = '0';
        });
    }

    // Recuperar venta pausada
    async function recuperarVenta(pausaId) {
        if (!window.CarritoModule?.estaVacio()) {
            const result = await Swal.fire({
                title: '¿Continuar con esta venta?',
                text: 'La venta actual se perderá. ¿Deseas continuar?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Sí, continuar',
                cancelButtonText: 'Cancelar'
            });
            
            if (!result.isConfirmed) return;
        }
        
        try {
            const formData = new FormData();
            formData.append('accion', 'recuperar_pausada');
            formData.append('pausa_id', pausaId);
            
            const response = await fetch('gestion_pausas.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            // Verificar si la respuesta es JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Respuesta no es JSON:', text.substring(0, 200));
                throw new Error('Error en el formato de respuesta del servidor');
            }
            
            const resultado = await response.json();
            
            if (resultado.success) {
                cargarDatosVenta(resultado.datos);
                cerrarModal();
                await actualizarContador();
                
                if (typeof window.mostrarNotificacion === 'function') {
                    window.mostrarNotificacion('Venta recuperada exitosamente', 'success');
                }
            } else {
                throw new Error(resultado.error || 'Error al recuperar la venta');
            }
        } catch (error) {
            console.error('Error:', error);
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion(error.message || 'Error al recuperar la venta', 'error');
            }
        }
    }

    // Cargar datos de venta recuperada
    function cargarDatosVenta(datos) {
        // Limpiar carrito actual si existe
        if (window.CarritoModule) {
            const productos = window.CarritoModule.getProductos();
            while (productos.length > 0) {
                window.CarritoModule.eliminarProducto(0);
            }
        }
        
        // Cargar cliente
        if (datos.cliente && datos.cliente.id && window.ClienteModule) {
            window.ClienteModule.seleccionarCliente({
                id: datos.cliente.id,
                nombre: datos.cliente.nombre,
                tipo_documento: datos.cliente.documento?.split(': ')[0] || 'CEDULA',
                numero_documento: datos.cliente.documento?.split(': ')[1] || ''
            });
        }
        
        // Cargar productos
        if (datos.productos && window.CarritoModule) {
            datos.productos.forEach(p => {
                window.CarritoModule.agregarProducto(p, p.cantidad);
            });
        }
        
        // Cargar tipo de venta
        if (datos.pago && datos.pago.tipo_venta === 'credito') {
            document.getElementById('toggleCredito').checked = true;
            document.getElementById('panelCredito').classList.remove('hidden');
            document.getElementById('panelContado').classList.add('hidden');
            
            if (datos.pago.abono_inicial) {
                document.getElementById('abono_inicial').value = datos.pago.abono_inicial;
                document.getElementById('abono_inicial_form').value = datos.pago.abono_inicial;
            }
            
            if (datos.pago.fecha_limite) {
                document.getElementById('chkFechaLimite').checked = true;
                document.getElementById('fechaLimiteContainer').classList.remove('hidden');
                document.getElementById('fecha_limite').value = datos.pago.fecha_limite;
                document.getElementById('fecha_limite_form').value = datos.pago.fecha_limite;
                document.getElementById('usar_fecha_limite').value = datos.pago.usar_fecha_limite || '0';
            }
        } else {
            document.getElementById('toggleCredito').checked = false;
            document.getElementById('panelCredito').classList.add('hidden');
            document.getElementById('panelContado').classList.remove('hidden');
        }
        
        // Cargar método de pago
        if (datos.pago && datos.pago.metodo_pago) {
            const btn = document.querySelector(`.metodo-pago[data-method="${datos.pago.metodo_pago}"]`);
            if (btn) btn.click();
            
            if (datos.pago.metodo_pago === 'mixto' && datos.pago.mixto) {
                document.getElementById('monto_efectivo_mixto').value = datos.pago.mixto.efectivo || 0;
                document.getElementById('monto_tarjeta_mixto').value = datos.pago.mixto.tarjeta || 0;
                document.getElementById('monto_transferencia_mixto').value = datos.pago.mixto.transferencia || 0;
                document.getElementById('monto_otro_mixto').value = datos.pago.mixto.otro || 0;
                
                document.getElementById('monto_efectivo_mixto_form').value = datos.pago.mixto.efectivo || 0;
                document.getElementById('monto_tarjeta_mixto_form').value = datos.pago.mixto.tarjeta || 0;
                document.getElementById('monto_transferencia_mixto_form').value = datos.pago.mixto.transferencia || 0;
                document.getElementById('monto_otro_mixto_form').value = datos.pago.mixto.otro || 0;
            }
        }
        
        // Cargar descuento
        if (datos.totales) {
            if (datos.totales.tipo_descuento) {
                document.querySelector(`input[name="tipo_descuento"][value="${datos.totales.tipo_descuento}"]`).checked = true;
            }
            if (datos.totales.descuento) {
                document.getElementById('descuento').value = datos.totales.descuento;
                document.getElementById('descuento_form').value = datos.totales.descuento;
            }
        }
        
        // Cargar monto recibido
        if (datos.pago && datos.pago.monto_recibido && datos.pago.tipo_venta === 'contado' && datos.pago.metodo_pago !== 'mixto') {
            document.getElementById('monto_recibido').value = datos.pago.monto_recibido;
            document.getElementById('monto_recibido_form').value = datos.pago.monto_recibido;
        }
        
        // Cargar observaciones
        if (datos.observaciones) {
            document.getElementById('observaciones').value = datos.observaciones;
            document.getElementById('observaciones_form').value = datos.observaciones;
        }
        
        // Recalcular totales
        if (window.PagoModule) {
            window.PagoModule.calcularTotales();
        }
        
        actualizarBotonPausa();
    }

    // Eliminar venta pausada
    async function eliminarVenta(pausaId) {
        const result = await Swal.fire({
            title: '¿Eliminar venta pausada?',
            text: 'Esta acción no se puede deshacer',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        });
        
        if (!result.isConfirmed) return;
        
        try {
            const formData = new FormData();
            formData.append('accion', 'eliminar_pausada');
            formData.append('pausa_id', pausaId);
            
            const response = await fetch('gestion_pausas.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            // Verificar si la respuesta es JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Respuesta no es JSON:', text.substring(0, 200));
                throw new Error('Error en el formato de respuesta del servidor');
            }
            
            const resultado = await response.json();
            
            if (resultado.success) {
                document.querySelector(`.pausas-item[data-id="${pausaId}"]`)?.remove();
                await actualizarContador();
                
                if (typeof window.mostrarNotificacion === 'function') {
                    window.mostrarNotificacion('Venta pausada eliminada', 'success');
                }
            } else {
                throw new Error(resultado.error || 'Error al eliminar la venta');
            }
        } catch (error) {
            console.error('Error:', error);
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion(error.message || 'Error al eliminar la venta', 'error');
            }
        }
    }

    // Actualizar contador de pausas
    async function actualizarContador() {
        try {
            const response = await fetch('gestion_pausas.php?accion=contar_pausadas');
            
            // Verificar si la respuesta es JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                console.warn('Respuesta no es JSON');
                return;
            }
            
            const resultado = await response.json();
            
            const badge = document.getElementById('pausasCountHeader');
            const total = resultado.count || 0;
            
            if (total > 0) {
                if (badge) {
                    badge.textContent = total;
                } else {
                    const btn = document.getElementById('btnVerPausas');
                    btn.classList.add('with-badge');
                    const newBadge = document.createElement('div');
                    newBadge.className = 'pausas-count-badge';
                    newBadge.id = 'pausasCountHeader';
                    newBadge.textContent = total;
                    btn.appendChild(newBadge);
                }
            } else if (badge) {
                badge.remove();
                document.getElementById('btnVerPausas')?.classList.remove('with-badge');
            }
        } catch (error) {
            console.warn('Error actualizando contador:', error);
        }
    }

    // Cerrar modal
    function cerrarModal() {
        document.getElementById('pausasModal').style.display = 'none';
    }

    // Preguntar restaurar venta (para venta pausada en el header)
    function preguntarRestaurarVenta() {
        if (!ventaPausadaId) return;
        
        // Esta función se implementa en el archivo principal con PHP
        if (typeof window.preguntarRestaurarVentaPHP === 'function') {
            window.preguntarRestaurarVentaPHP();
        }
    }

    // API pública
    return {
        init,
        cargarVentasPausadas,
        recuperarVenta,
        eliminarVenta,
        actualizarContador,
        actualizarBotonPausa
    };
})();

// Exportar al ámbito global
window.PausasModule = PausasModule;