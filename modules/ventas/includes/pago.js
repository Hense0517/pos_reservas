// ============================================
// MÓDULO DE PAGOS Y TOTALES
// ============================================

const PagoModule = (function() {
    // Variables privadas
    let tipoVenta = 'contado';
    let metodoPagoSeleccionado = '';
    let impuesto = 0;
    let isProcessing = false;

    // Inicializar módulo
    function init(config = {}) {
        console.log('Módulo de pagos iniciado');
        
        if (config.impuesto) {
            impuesto = config.impuesto;
        }
        
        // Event listeners
        document.getElementById('toggleCredito')?.addEventListener('change', toggleCredito);
        document.getElementById('chkFechaLimite')?.addEventListener('change', toggleFechaLimite);
        document.getElementById('btnLimpiarCredito')?.addEventListener('click', limpiarCredito);
        document.getElementById('btnLimpiarMixto')?.addEventListener('click', limpiarPagoMixto);
        document.getElementById('btnProcesarVenta')?.addEventListener('click', procesarVenta);
        
        document.querySelectorAll('.metodo-pago').forEach(btn => {
            btn.addEventListener('click', seleccionarMetodoPago);
        });
        
        document.querySelectorAll('input[name="tipo_descuento"]').forEach(radio => {
            radio.addEventListener('change', calcularTotales);
        });
        
        // Inputs de moneda
        configurarInputsMoneda();
        
        // Validación periódica
        setInterval(validarFormulario, 500);
    }

    // Configurar inputs de moneda
    function configurarInputsMoneda() {
        const inputsMoneda = [
            'abono_inicial',
            'monto_recibido',
            'descuento',
            'monto_efectivo_mixto',
            'monto_tarjeta_mixto',
            'monto_transferencia_mixto',
            'monto_otro_mixto'
        ];
        
        inputsMoneda.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', manejarInputMoneda);
                input.addEventListener('blur', manejarBlurMoneda);
                
                // Configurar campos ocultos
                const formField = document.getElementById(`${id}_form`);
                if (formField && input.value) {
                    formField.value = desformatearNumero(input.value);
                }
            }
        });
    }

    // Manejar input de moneda
    function manejarInputMoneda(e) {
        const input = e.target;
        const valor = input.value.replace(/[^\d,]/g, '').replace(',', '.');
        input.value = valor;
        
        // Actualizar campo oculto
        const formField = document.getElementById(`${input.id}_form`);
        if (formField) {
            formField.value = desformatearNumero(valor);
        }
        
        calcularTotales();
    }

    function manejarBlurMoneda(e) {
        const input = e.target;
        if (input.value === '' || input.value === '0') {
            input.value = '0';
            const formField = document.getElementById(`${input.id}_form`);
            if (formField) {
                formField.value = '0';
            }
        }
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

    // Toggle crédito/contado
    function toggleCredito(e) {
        tipoVenta = e.target.checked ? 'credito' : 'contado';
        document.getElementById('tipo_venta').value = tipoVenta;
        document.getElementById('tipo_venta_form').value = tipoVenta;
        
        if (tipoVenta === 'credito') {
            const clienteId = document.getElementById('cliente_id_form').value;
            if (!clienteId) {
                if (typeof window.mostrarNotificacion === 'function') {
                    window.mostrarNotificacion('Debe seleccionar un cliente para venta a crédito', 'warning');
                }
                e.target.checked = false;
                tipoVenta = 'contado';
                document.getElementById('tipo_venta').value = 'contado';
                document.getElementById('tipo_venta_form').value = 'contado';
                return;
            }
            
            document.getElementById('panelCredito').classList.remove('hidden');
            document.getElementById('panelContado').classList.add('hidden');
            
            if (metodoPagoSeleccionado === 'mixto') {
                document.getElementById('panelPagoMixto').classList.add('hidden');
            }
        } else {
            document.getElementById('panelCredito').classList.add('hidden');
            document.getElementById('panelContado').classList.remove('hidden');
            
            document.getElementById('abono_inicial').value = '0';
            document.getElementById('infoDeuda').classList.add('hidden');
            
            if (metodoPagoSeleccionado === 'mixto') {
                document.getElementById('panelPagoMixto').classList.remove('hidden');
            }
        }
        
        calcularTotales();
        validarFormulario();
    }

    // Seleccionar método de pago
    function seleccionarMetodoPago(e) {
        document.querySelectorAll('.metodo-pago').forEach(b => b.classList.remove('selected'));
        e.target.classList.add('selected');
        
        metodoPagoSeleccionado = e.target.dataset.method;
        document.getElementById('metodo_pago_form').value = metodoPagoSeleccionado;
        document.getElementById('metodo_pago_form_hidden').value = metodoPagoSeleccionado;
        
        document.getElementById('errorMetodoPago').classList.add('hidden');
        
        if (metodoPagoSeleccionado === 'mixto') {
            document.getElementById('panelPagoMixto').classList.remove('hidden');
            document.getElementById('panelContado').classList.add('hidden');
        } else {
            document.getElementById('panelPagoMixto').classList.add('hidden');
            if (tipoVenta === 'contado') {
                document.getElementById('panelContado').classList.remove('hidden');
            }
        }
        
        calcularTotales();
        validarFormulario();
    }

    // Toggle fecha límite
    function toggleFechaLimite(e) {
        const container = document.getElementById('fechaLimiteContainer');
        const fechaInput = document.getElementById('fecha_limite');
        
        if (e.target.checked) {
            container.classList.remove('hidden');
            fechaInput.disabled = false;
            
            const hoy = new Date();
            const fechaLimite = new Date(hoy);
            fechaLimite.setDate(hoy.getDate() + 15);
            fechaInput.value = fechaLimite.toISOString().split('T')[0];
            document.getElementById('fecha_limite_form').value = fechaLimite.toISOString().split('T')[0];
            document.getElementById('usar_fecha_limite').value = '1';
        } else {
            container.classList.add('hidden');
            fechaInput.disabled = true;
            fechaInput.value = '';
            document.getElementById('fecha_limite_form').value = '';
            document.getElementById('usar_fecha_limite').value = '0';
        }
    }

    // Calcular totales
    function calcularTotales() {
        if (typeof window.CarritoModule === 'undefined') return;
        
        const subtotal = window.CarritoModule.calcularTotales();
        
        const tipoDescuento = document.querySelector('input[name="tipo_descuento"]:checked')?.value || 'monto';
        const valorDescuento = desformatearNumero(document.getElementById('descuento')?.value || '0');
        
        let descuentoTotal = 0;
        if (tipoDescuento === 'porcentaje') {
            descuentoTotal = subtotal * (valorDescuento / 100);
        } else {
            descuentoTotal = Math.min(valorDescuento, subtotal);
        }
        
        const subtotalConDescuento = subtotal - descuentoTotal;
        const impuestoTotal = subtotalConDescuento * impuesto;
        const total = subtotalConDescuento + impuestoTotal;
        
        // Actualizar UI
        document.getElementById('subtotal').textContent = `$${formatearNumero(subtotal)}`;
        document.getElementById('descuentoTotal').textContent = `$${formatearNumero(descuentoTotal)}`;
        document.getElementById('impuesto').textContent = `$${formatearNumero(impuestoTotal)}`;
        document.getElementById('total').textContent = `$${formatearNumero(total)}`;
        
        document.getElementById('descuento_form').value = valorDescuento;
        document.getElementById('tipo_descuento_form').value = tipoDescuento;
        
        // Calcular según tipo de venta
        if (tipoVenta === 'contado') {
            if (metodoPagoSeleccionado === 'mixto') {
                validarPagoMixto(total);
                document.getElementById('cambio').textContent = '$0';
                document.getElementById('monto_recibido_form').value = 0;
            } else {
                const montoRecibido = desformatearNumero(document.getElementById('monto_recibido')?.value || '0');
                const cambio = montoRecibido - total;
                document.getElementById('cambio').textContent = `$${formatearNumero(Math.max(0, cambio))}`;
                document.getElementById('monto_recibido_form').value = montoRecibido;
            }
            document.getElementById('abono_inicial_form').value = 0;
        } else {
            const abonoInicial = desformatearNumero(document.getElementById('abono_inicial')?.value || '0');
            document.getElementById('totalVentaCredito').textContent = `$${formatearNumero(total)}`;
            document.getElementById('abonoCredito').textContent = `$${formatearNumero(abonoInicial)}`;
            document.getElementById('saldoPendiente').textContent = `$${formatearNumero(total - abonoInicial)}`;
            
            document.getElementById('infoDeuda').classList.toggle('hidden', abonoInicial >= total);
            document.getElementById('monto_recibido_form').value = abonoInicial;
            document.getElementById('abono_inicial_form').value = abonoInicial;
            document.getElementById('cambio').textContent = '$0';
        }
        
        validarFormulario();
    }

    function formatearNumero(numero) {
        if (isNaN(numero) || numero === 0) return '0';
        return new Intl.NumberFormat('es-CO', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(numero);
    }

    // Validar pago mixto
    function validarPagoMixto(total) {
        const efectivo = desformatearNumero(document.getElementById('monto_efectivo_mixto')?.value || '0');
        const tarjeta = desformatearNumero(document.getElementById('monto_tarjeta_mixto')?.value || '0');
        const transferencia = desformatearNumero(document.getElementById('monto_transferencia_mixto')?.value || '0');
        const otro = desformatearNumero(document.getElementById('monto_otro_mixto')?.value || '0');
        
        const suma = efectivo + tarjeta + transferencia + otro;
        const diferencia = Math.abs(suma - total);
        const tolerancia = 0.01;
        
        // Actualizar campos ocultos
        document.getElementById('monto_efectivo_mixto_form').value = efectivo;
        document.getElementById('monto_tarjeta_mixto_form').value = tarjeta;
        document.getElementById('monto_transferencia_mixto_form').value = transferencia;
        document.getElementById('monto_otro_mixto_form').value = otro;
        
        const errorElement = document.getElementById('errorSumaPagos');
        const successElement = document.getElementById('successSumaPagos');
        
        document.getElementById('sumaPagosMixtos').textContent = `$${formatearNumero(suma)}`;
        document.getElementById('totalCompararMixto').textContent = `$${formatearNumero(total)}`;
        
        if (diferencia > tolerancia) {
            errorElement?.classList.remove('hidden');
            successElement?.classList.add('hidden');
            return false;
        } else {
            errorElement?.classList.add('hidden');
            successElement?.classList.remove('hidden');
            return true;
        }
    }

    // Limpiar crédito
    function limpiarCredito() {
        document.getElementById('abono_inicial').value = '0';
        document.getElementById('abono_inicial_form').value = '0';
        document.getElementById('chkFechaLimite').checked = false;
        document.getElementById('fechaLimiteContainer').classList.add('hidden');
        document.getElementById('fecha_limite').value = '';
        document.getElementById('fecha_limite_form').value = '';
        document.getElementById('usar_fecha_limite').value = '0';
        document.getElementById('infoDeuda').classList.add('hidden');
        calcularTotales();
    }

    // Limpiar pago mixto
    function limpiarPagoMixto() {
        ['monto_efectivo_mixto', 'monto_tarjeta_mixto', 'monto_transferencia_mixto', 'monto_otro_mixto'].forEach(id => {
            document.getElementById(id).value = '0';
            document.getElementById(`${id}_form`).value = '0';
        });
        calcularTotales();
    }

    // Validar formulario completo
    function validarFormulario() {
        if (isProcessing) return false;
        
        const btnProcesar = document.getElementById('btnProcesarVenta');
        const clienteValido = document.getElementById('cliente_id_form').value !== '';
        const productosValido = window.CarritoModule ? !window.CarritoModule.estaVacio() : false;
        const metodoPagoValido = document.getElementById('metodo_pago_form').value !== '';
        
        let errores = [];
        
        if (!clienteValido) errores.push('Seleccione un cliente');
        if (!productosValido) errores.push('Agregue al menos un producto');
        if (!metodoPagoValido) {
            errores.push('Seleccione un método de pago');
            document.getElementById('errorMetodoPago').classList.remove('hidden');
        } else {
            document.getElementById('errorMetodoPago').classList.add('hidden');
        }
        
        // Validaciones específicas
        if (tipoVenta === 'contado') {
            if (metodoPagoSeleccionado === 'mixto') {
                const total = desformatearNumero(document.getElementById('total').textContent);
                const sumaPagos = desformatearNumero(document.getElementById('monto_efectivo_mixto')?.value || '0') +
                                 desformatearNumero(document.getElementById('monto_tarjeta_mixto')?.value || '0') +
                                 desformatearNumero(document.getElementById('monto_transferencia_mixto')?.value || '0') +
                                 desformatearNumero(document.getElementById('monto_otro_mixto')?.value || '0');
                
                if (Math.abs(sumaPagos - total) > 0.01) {
                    errores.push('La suma de los pagos mixtos no coincide con el total');
                }
            } else {
                const total = desformatearNumero(document.getElementById('total').textContent);
                const montoRecibido = desformatearNumero(document.getElementById('monto_recibido').value) || 0;
                
                if (montoRecibido < total) {
                    errores.push('El monto recibido debe cubrir el total');
                }
            }
        } else {
            const total = desformatearNumero(document.getElementById('total').textContent);
            const abonoInicial = desformatearNumero(document.getElementById('abono_inicial').value) || 0;
            
            if (abonoInicial > total) {
                errores.push('El abono no puede ser mayor al total');
            }
            if (abonoInicial < 0) {
                errores.push('El abono no puede ser negativo');
            }
            if (!clienteValido) {
                errores.push('Se requiere cliente para venta a crédito');
            }
        }
        
        // Mostrar/ocultar errores
        const errorGeneral = document.getElementById('errorGeneral');
        if (errores.length > 0) {
            errorGeneral.textContent = errores.join('. ');
            errorGeneral.classList.remove('hidden');
            btnProcesar.disabled = true;
            return false;
        } else {
            errorGeneral.classList.add('hidden');
            btnProcesar.disabled = false;
            return true;
        }
    }

    // Procesar venta
    async function procesarVenta(e) {
        e.preventDefault();
        
        if (isProcessing) {
            mostrarNotificacion('Ya hay una venta en proceso', 'warning');
            return;
        }
        
        if (!validarFormulario()) {
            mostrarNotificacion('Complete todos los campos requeridos', 'error');
            return;
        }
        
        // Confirmar venta
        let mensajeConfirmacion = '';
        let tituloConfirmacion = '';
        
        const total = desformatearNumero(document.getElementById('total').textContent);
        
        if (tipoVenta === 'credito') {
            const abonoInicial = desformatearNumero(document.getElementById('abono_inicial').value) || 0;
            const saldoPendiente = total - abonoInicial;
            
            tituloConfirmacion = '¿Confirmar venta a CRÉDITO?';
            mensajeConfirmacion = `
                <div class="text-left">
                    <p><strong>Total:</strong> $${formatearNumero(total)}</p>
                    <p><strong>Abono inicial:</strong> $${formatearNumero(abonoInicial)}</p>
                    <p><strong>Saldo pendiente:</strong> $${formatearNumero(saldoPendiente)}</p>
                    <p class="mt-2 text-sm text-gray-600">¿Desea continuar?</p>
                </div>
            `;
        } else if (metodoPagoSeleccionado === 'mixto') {
            const efectivo = desformatearNumero(document.getElementById('monto_efectivo_mixto').value) || 0;
            const tarjeta = desformatearNumero(document.getElementById('monto_tarjeta_mixto').value) || 0;
            const transferencia = desformatearNumero(document.getElementById('monto_transferencia_mixto').value) || 0;
            const otro = desformatearNumero(document.getElementById('monto_otro_mixto').value) || 0;
            
            tituloConfirmacion = '¿Confirmar venta con PAGO MIXTO?';
            mensajeConfirmacion = `
                <div class="text-left space-y-1">
                    <p><strong>Total:</strong> $${formatearNumero(total)}</p>
                    <div class="ml-2 text-sm">
                        <p><strong>Efectivo:</strong> $${formatearNumero(efectivo)}</p>
                        <p><strong>Tarjeta:</strong> $${formatearNumero(tarjeta)}</p>
                        <p><strong>Transferencia:</strong> $${formatearNumero(transferencia)}</p>
                        <p><strong>Otro:</strong> $${formatearNumero(otro)}</p>
                    </div>
                    <p class="mt-2 text-sm text-gray-600">¿Desea continuar?</p>
                </div>
            `;
        } else {
            tituloConfirmacion = '¿Confirmar venta de CONTADO?';
            mensajeConfirmacion = '¿Desea procesar la venta?';
        }
        
        const result = await Swal.fire({
            title: tituloConfirmacion,
            html: mensajeConfirmacion,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, procesar',
            cancelButtonText: 'Cancelar',
            showLoaderOnConfirm: true,
            preConfirm: enviarVenta
        });
        
        if (result.isConfirmed && result.value) {
            Swal.fire({
                title: '¡Venta procesada!',
                text: 'La venta se ha procesado exitosamente',
                icon: 'success',
                confirmButtonColor: '#10b981',
                confirmButtonText: 'Ver detalle'
            }).then(() => {
                window.location.href = `ver.php?id=${result.value.venta_id}`;
            });
        }
    }

    async function enviarVenta() {
        try {
            isProcessing = true;
            
            const formData = new FormData(document.getElementById('formVenta'));
            
            // Agregar productos
            const productos = window.CarritoModule ? window.CarritoModule.getProductos() : [];
            productos.forEach((producto, index) => {
                formData.append(`productos[${index}][id]`, producto.id);
                formData.append(`productos[${index}][cantidad]`, producto.cantidad);
                formData.append(`productos[${index}][precio]`, producto.precio);
            });
            
            const response = await fetch('procesar_venta.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                throw new Error('Respuesta del servidor no es JSON');
            }
            
            const resultado = await response.json();
            
            if (resultado.success) {
                return resultado;
            } else {
                throw new Error(resultado.error || 'Error al procesar la venta');
            }
        } catch (error) {
            Swal.showValidationMessage(error.message);
            return false;
        } finally {
            isProcessing = false;
        }
    }

    function mostrarNotificacion(mensaje, tipo) {
        if (typeof window.mostrarNotificacion === 'function') {
            window.mostrarNotificacion(mensaje, tipo);
        }
    }

    // API pública
    return {
        init,
        calcularTotales,
        validarFormulario,
        getDatosPago: () => ({
            tipo_venta: tipoVenta,
            metodo_pago: metodoPagoSeleccionado
        }),
        getTipoVenta: () => tipoVenta,
        getMetodoPago: () => metodoPagoSeleccionado
    };
})();

// Exportar al ámbito global
window.PagoModule = PagoModule;