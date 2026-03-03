<?php
// modules/ventas/templates/ventas-template.php
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - Caja Rápida</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/ventas.css">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="h-full">
    <!-- Modal de ventas pausadas -->
    <div id="pausasModal" class="pausas-modal">
        <div class="pausas-content">
            <div class="pausas-header">
                <div class="flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-bold">Ventas Pausadas</h3>
                        <p class="text-sm opacity-90">Recupera o elimina ventas guardadas</p>
                    </div>
                    <button id="closePausasModal" class="text-white opacity-70 hover:opacity-100">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <div class="pausas-body">
                <div id="pausasList">
                    <div class="pausas-empty">
                        <i class="fas fa-hourglass-half text-4xl text-gray-300 mb-3"></i>
                        <p class="text-gray-500">No tienes ventas pausadas</p>
                        <p class="text-gray-400 text-sm mt-1">Las ventas que pausas aparecerán aquí</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Header minimalista -->
    <div class="bg-white border-b">
        <div class="max-w-7xl mx-auto px-3 py-2">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="icon-blue text-2xl">
                        <i class="fas fa-cash-register"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-gray-800 text-sm">Caja Rápida</div>
                        <div class="text-xs text-gray-500 flex items-center space-x-3">
                            <span>Factura: <span class="font-medium"><?php echo htmlspecialchars($nuevo_numero); ?></span></span>
                            <span><i class="far fa-clock text-gray-400"></i> <?php echo date('H:i'); ?></span>
                            <span><i class="fas fa-user text-gray-400"></i> <?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Usuario'); ?></span>
                            <?php if ($venta_pausada): ?>
                            <span class="badge-pausada"><i class="fas fa-pause mr-1"></i> Venta pausada</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2">
                    <!-- Botón de ventas pausadas -->
                    <div class="tooltip">
                        <button id="btnVerPausas" class="btn-pausas px-3 py-1 flex items-center relative">
                            <i class="fas fa-hourglass-half mr-1"></i>
                            <span>Pausadas</span>
                            <?php if ($total_pausadas > 0): ?>
                            <div class="pausas-count-badge" id="pausasCountHeader"><?php echo $total_pausadas; ?></div>
                            <?php endif; ?>
                        </button>
                        <span class="tooltiptext">Ver ventas pausadas</span>
                    </div>
                    
                    <!-- Botón de pausar venta -->
                    <div class="tooltip">
                        <button id="btnPausarVenta" class="btn-pausa px-4 py-2 flex items-center disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                            <i class="fas fa-pause mr-2"></i>
                            <span>Pausar</span>
                        </button>
                        <span class="tooltiptext">Pausar venta actual</span>
                    </div>
                    
                    <!-- Botón de restaurar venta pausada -->
                    <?php if ($venta_pausada): ?>
                    <div class="tooltip">
                        <button id="btnRestaurarVenta" class="btn-restaurar px-4 py-2 flex items-center">
                            <i class="fas fa-play mr-2"></i>
                            <span>Restaurar</span>
                        </button>
                        <span class="tooltiptext">Restaurar venta pausada</span>
                    </div>
                    <?php endif; ?>
                    
                    <a href="index.php" class="text-xs text-blue-600 hover:text-blue-800 flex items-center">
                        <i class="fas fa-arrow-left mr-1"></i>
                        <span>Ventas</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Contenido principal -->
    <div class="max-w-7xl mx-auto px-3 py-2 h-[calc(100vh-56px)]">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-3 h-full">
            
            <!-- Panel Izquierdo: Cliente y Productos -->
            <div class="lg:col-span-1 flex flex-col h-full">
                <div class="card flex-1 flex flex-col <?php echo $venta_pausada ? 'venta-pausada-activa' : ''; ?>">
                    <!-- Cliente -->
                    <div class="p-3 border-b">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-user-circle icon-blue"></i>
                                <span class="font-medium text-gray-700 text-sm">Cliente</span>
                            </div>
                            <button type="button" id="btnNuevoCliente" class="text-xs btn-primary px-2 py-1 flex items-center">
                                <i class="fas fa-plus mr-1"></i>
                                <span>Nuevo</span>
                            </button>
                        </div>
                        
                        <div class="relative mb-2">
                            <input type="text" id="buscarCliente" placeholder="Buscar cliente..." class="input-field w-full pl-8 text-sm">
                            <div class="absolute left-2 top-1/2 transform -translate-y-1/2 text-gray-400">
                                <i class="fas fa-search text-xs"></i>
                            </div>
                            <input type="hidden" id="cliente_id" value="">
                            <div id="resultadosCliente" class="absolute z-20 w-full mt-1 bg-white border rounded shadow-lg hidden max-h-40 overflow-y-auto text-xs"></div>
                        </div>
                        
                        <div id="infoCliente" class="p-2 bg-blue-50 border border-blue-200 rounded hidden">
                            <div class="flex justify-between items-center">
                                <div class="min-w-0">
                                    <div id="clienteNombre" class="font-medium text-blue-900 text-sm truncate"></div>
                                    <div id="clienteDocumento" class="text-xs text-blue-700 truncate"></div>
                                </div>
                                <button type="button" id="cambiarCliente" class="text-blue-600 hover:text-blue-800 text-xs ml-2 flex-shrink-0">
                                    <i class="fas fa-redo"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Productos -->
                    <div class="p-3 flex-1">
                        <div class="mb-3">
                            <div class="flex items-center space-x-2 mb-2">
                                <i class="fas fa-box icon-green"></i>
                                <span class="font-medium text-gray-700 text-sm">Productos</span>
                            </div>
                            
                            <div class="relative mb-2">
                                <input type="text" id="buscarProducto" placeholder="Buscar producto (código, nombre o referencia)..." class="input-field w-full pl-8 text-sm">
                                <div class="absolute left-2 top-1/2 transform -translate-y-1/2 text-gray-400">
                                    <i class="fas fa-search text-xs"></i>
                                </div>
                                <div id="resultadosBusqueda" class="absolute z-10 w-full mt-1 bg-white border rounded shadow-lg hidden max-h-96 overflow-y-auto text-xs"></div>
                            </div>

                            <div id="productoSeleccionadoInfo" class="p-2 bg-green-50 border border-green-200 rounded hidden mb-3">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-medium text-green-900">Producto seleccionado</span>
                                    <span class="badge">Listo</span>
                                </div>
                                <div class="grid grid-cols-3 gap-1 text-xs">
                                    <div class="text-green-700 truncate">Nombre:</div>
                                    <div id="productoNombre" class="font-medium text-green-900 col-span-2 truncate"></div>
                                    <div class="text-green-700">Precio:</div>
                                    <div id="productoPrecio" class="font-medium text-green-900"></div>
                                    <div class="text-green-700">Stock:</div>
                                    <div id="productoStock" class="font-medium text-green-900"></div>
                                </div>
                            </div>

                            <!-- Cantidad -->
                            <div class="p-2 bg-gray-50 rounded">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-medium text-gray-700">Cantidad</span>
                                    <div class="flex items-center space-x-2">
                                        <button type="button" id="btnDecrementar" class="w-6 h-6 bg-gray-200 rounded flex items-center justify-center hover:bg-gray-300">
                                            <i class="fas fa-minus text-xs"></i>
                                        </button>
                                        <input type="number" id="cantidadProducto" min="1" value="1" class="w-12 text-center input-field font-medium text-sm">
                                        <button type="button" id="btnIncrementar" class="w-6 h-6 bg-gray-200 rounded flex items-center justify-center hover:bg-gray-300">
                                            <i class="fas fa-plus text-xs"></i>
                                        </button>
                                    </div>
                                </div>
                                <button type="button" id="agregarProducto" class="w-full btn-primary py-2 text-xs flex items-center justify-center">
                                    <i class="fas fa-cart-plus mr-2"></i>
                                    <span>Agregar al carrito</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel Central: Carrito -->
            <div class="lg:col-span-1 flex flex-col h-full">
                <div class="card flex-1 flex flex-col <?php echo $venta_pausada ? 'venta-pausada-activa' : ''; ?>">
                    <div class="p-3 border-b bg-green-50">
                        <div class="flex justify-between items-center">
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-shopping-cart icon-green"></i>
                                <span class="font-medium text-gray-700 text-sm">Carrito</span>
                            </div>
                            <span id="contadorProductos" class="header-badge">0</span>
                        </div>
                    </div>

                    <div id="listaProductos" class="flex-1 p-3 overflow-y-auto scroll-thin">
                        <div class="text-center py-8">
                            <div class="text-gray-300 mb-2">
                                <i class="fas fa-shopping-cart text-2xl"></i>
                            </div>
                            <p class="text-gray-500 text-xs">Carrito vacío</p>
                            <p class="text-gray-400 text-xs mt-1">Agrega productos</p>
                        </div>
                    </div>

                    <div class="p-3 border-t">
                        <div class="flex space-x-2">
                            <button type="button" onclick="CarritoModule.limpiar()" class="flex-1 bg-red-50 text-red-600 hover:bg-red-100 py-1.5 px-2 rounded text-xs flex items-center justify-center">
                                <i class="fas fa-trash-alt mr-1"></i>
                                <span>Vaciar</span>
                            </button>
                            <button type="button" onclick="CarritoModule.eliminarSeleccionado()" class="flex-1 bg-yellow-50 text-yellow-600 hover:bg-yellow-100 py-1.5 px-2 rounded text-xs flex items-center justify-center">
                                <i class="fas fa-minus-circle mr-1"></i>
                                <span>Quitar</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel Derecho: Pago y Crédito -->
            <div class="lg:col-span-1 flex flex-col h-full">
                <div class="card flex-1 flex flex-col <?php echo $venta_pausada ? 'venta-pausada-activa' : ''; ?>">
                    <div class="p-3 border-b bg-blue-50">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-credit-card icon-blue"></i>
                                <span class="font-medium text-gray-700 text-sm">Pago</span>
                            </div>
                            <!-- Toggle Crédito/Contado -->
                            <div class="flex items-center space-x-2">
                                <span class="text-xs text-gray-600">Contado</span>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="toggleCredito">
                                    <span class="toggle-slider"></span>
                                </label>
                                <span class="text-xs text-gray-600">Crédito</span>
                            </div>
                        </div>
                        <input type="hidden" id="tipo_venta" name="tipo_venta" value="contado">
                    </div>

                    <div class="p-3 flex-1 overflow-y-auto scroll-thin space-y-3">
                        <!-- Panel de Crédito (oculto por defecto) -->
                        <div id="panelCredito" class="hidden space-y-2 fade-in">
                            <div class="credito-info">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="flex items-center space-x-1">
                                        <i class="fas fa-hand-holding-usd text-blue-500 text-xs"></i>
                                        <span class="text-xs font-medium text-blue-800">Venta a Crédito</span>
                                    </div>
                                    <button type="button" id="btnLimpiarCredito" class="text-xs text-blue-600 hover:text-blue-800">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                
                                <div class="grid grid-cols-1 gap-2">
                                    <div>
                                        <label class="label-compact">Abono Inicial</label>
                                        <input type="text" id="abono_inicial" value="0" class="input-field w-full text-sm moneda-input" placeholder="0">
                                        <div id="errorAbono" class="error-message hidden"></div>
                                    </div>
                                    <div class="checkbox-container">
                                        <input type="checkbox" id="chkFechaLimite">
                                        <label for="chkFechaLimite" class="text-xs text-gray-700">Establecer fecha límite</label>
                                    </div>
                                    <div id="fechaLimiteContainer" class="hidden">
                                        <label class="label-compact">Fecha Límite</label>
                                        <input type="date" id="fecha_limite" class="input-field w-full text-sm">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Información de deuda compacta -->
                            <div id="infoDeuda" class="deuda-info hidden">
                                <div class="grid grid-cols-3 gap-1 text-center">
                                    <div>
                                        <div class="label-compact">Total</div>
                                        <div id="totalVentaCredito" class="text-xs font-bold text-green-600">$0</div>
                                    </div>
                                    <div>
                                        <div class="label-compact">Abono</div>
                                        <div id="abonoCredito" class="text-xs font-bold text-blue-600">$0</div>
                                    </div>
                                    <div>
                                        <div class="label-compact">Saldo</div>
                                        <div id="saldoPendiente" class="text-xs font-bold text-red-600">$0</div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Métodos de pago -->
                        <div>
                            <label class="label-compact">Forma de pago *</label>
                            <div class="grid grid-cols-4 gap-1">
                                <button type="button" class="payment-card metodo-pago flex flex-col items-center justify-center" data-method="efectivo">
                                    <i class="fas fa-money-bill-wave text-green-500 mb-1 text-xs"></i>
                                    <span class="text-xs">Efectivo</span>
                                </button>
                                <button type="button" class="payment-card metodo-pago flex flex-col items-center justify-center" data-method="tarjeta">
                                    <i class="fas fa-credit-card text-blue-500 mb-1 text-xs"></i>
                                    <span class="text-xs">Tarjeta</span>
                                </button>
                                <button type="button" class="payment-card metodo-pago flex flex-col items-center justify-center" data-method="transferencia">
                                    <i class="fas fa-university text-purple-500 mb-1 text-xs"></i>
                                    <span class="text-xs">Transferencia</span>
                                </button>
                                <button type="button" class="payment-card metodo-pago flex flex-col items-center justify-center" data-method="mixto">
                                    <i class="fas fa-random text-orange-500 mb-1 text-xs"></i>
                                    <span class="text-xs">Mixto</span>
                                </button>
                            </div>
                            <input type="hidden" id="metodo_pago_form" value="">
                            <div id="errorMetodoPago" class="error-message hidden mt-1">Seleccione un método de pago</div>
                        </div>

                        <!-- Panel de Pago Mixto (Oculto por defecto) -->
                        <div id="panelPagoMixto" class="hidden space-y-2 mixto-info fade-in">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs font-medium text-yellow-800">Desglose de Pago Mixto *</span>
                                <button type="button" id="btnLimpiarMixto" class="text-xs text-yellow-600 hover:text-yellow-800">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                            
                            <div class="space-y-2">
                                <div>
                                    <label class="label-compact">Efectivo</label>
                                    <input type="text" id="monto_efectivo_mixto" value="0" class="input-field w-full text-sm moneda-input pago-mixto-input" placeholder="0">
                                    <div id="errorEfectivoMixto" class="error-message hidden"></div>
                                </div>
                                <div>
                                    <label class="label-compact">Tarjeta</label>
                                    <input type="text" id="monto_tarjeta_mixto" value="0" class="input-field w-full text-sm moneda-input pago-mixto-input" placeholder="0">
                                    <div id="errorTarjetaMixto" class="error-message hidden"></div>
                                </div>
                                <div>
                                    <label class="label-compact">Transferencia</label>
                                    <input type="text" id="monto_transferencia_mixto" value="0" class="input-field w-full text-sm moneda-input pago-mixto-input" placeholder="0">
                                    <div id="errorTransferenciaMixto" class="error-message hidden"></div>
                                </div>
                                <div>
                                    <label class="label-compact">Otro</label>
                                    <input type="text" id="monto_otro_mixto" value="0" class="input-field w-full text-sm moneda-input pago-mixto-input" placeholder="0">
                                    <div id="errorOtroMixto" class="error-message hidden"></div>
                                </div>
                                
                                <div class="pt-2 border-t">
                                    <div class="flex justify-between items-center text-xs">
                                        <span class="text-gray-600">Suma de pagos:</span>
                                        <span id="sumaPagosMixtos" class="font-bold">$0</span>
                                    </div>
                                    <div class="flex justify-between items-center text-xs mt-1">
                                        <span class="text-gray-600">Total de la venta:</span>
                                        <span id="totalCompararMixto" class="font-bold text-green-600">$0</span>
                                    </div>
                                    <div id="validacionMixto" class="mt-2 text-center">
                                        <span id="errorSumaPagos" class="error-message hidden">La suma no coincide con el total</span>
                                        <span id="successSumaPagos" class="success-message hidden">✓ Suma correcta</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Descuento -->
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="label-compact">Descuento</label>
                                <div class="flex space-x-2 text-xs">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="tipo_descuento" value="monto" checked class="h-3 w-3 text-blue-600">
                                        <span class="ml-1">Monto</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="tipo_descuento" value="porcentaje" class="h-3 w-3 text-blue-600">
                                        <span class="ml-1">%</span>
                                    </label>
                                </div>
                            </div>
                            <input type="text" id="descuento" value="0" class="input-field w-full text-sm moneda-input">
                            <div id="errorDescuento" class="error-message hidden"></div>
                        </div>

                        <!-- Resumen compacto -->
                        <div class="p-2 bg-gray-50 rounded">
                            <div class="space-y-1 text-xs">
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-600">Subtotal:</span>
                                    <span id="subtotal" class="font-medium">$0</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-600">Descuento:</span>
                                    <span id="descuentoTotal" class="font-medium text-red-600">$0</span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-600">Impuesto:</span>
                                    <span id="impuesto" class="font-medium">$0</span>
                                </div>
                                <div class="flex justify-between items-center pt-1 border-t">
                                    <span class="font-bold text-gray-800 text-xs">Total:</span>
                                    <span id="total" class="font-bold text-green-600 text-sm">$0</span>
                                </div>
                            </div>
                        </div>

                        <!-- Monto recibido y cambio (solo para contado NO mixto) -->
                        <div id="panelContado">
                            <label class="label-compact">Monto recibido *</label>
                            <input type="text" id="monto_recibido" class="input-field w-full text-sm moneda-input" placeholder="Ingrese monto recibido">
                            <div id="errorMontoRecibido" class="error-message hidden mt-1"></div>
                            
                            <div class="p-2 bg-green-50 rounded mt-2">
                                <div class="flex justify-between items-center">
                                    <span class="font-medium text-gray-700 text-xs">Cambio:</span>
                                    <span id="cambio" class="text-sm font-bold text-green-600">$0</span>
                                </div>
                            </div>
                        </div>

                        <!-- Observaciones -->
                        <div>
                            <label class="label-compact">Observaciones</label>
                            <textarea id="observaciones" rows="1" class="input-field w-full text-sm" placeholder="Opcional"></textarea>
                        </div>

                        <!-- Botón procesar -->
                        <div class="mt-2">
                            <button type="button" id="btnProcesarVenta" class="w-full btn-success py-2.5 text-sm font-medium flex items-center justify-center disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                                <i class="fas fa-check-circle mr-2"></i>
                                <span>Procesar venta</span>
                            </button>
                            <div id="errorGeneral" class="error-message text-center mt-2 hidden"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Formulario oculto para procesar la venta -->
    <form id="formVenta" action="procesar_venta.php" method="POST" class="hidden">
        <input type="hidden" name="numero_factura" value="<?php echo htmlspecialchars($nuevo_numero); ?>">
        <input type="hidden" id="impuesto_porcentaje" value="<?php echo htmlspecialchars($impuesto_porcentaje); ?>">
        <input type="hidden" id="impuesto_decimal" value="<?php echo htmlspecialchars($impuesto_decimal); ?>">
        <input type="hidden" id="cliente_id_form" name="cliente_id" value="">
        <input type="hidden" id="descuento_form" name="descuento" value="0">
        <input type="hidden" id="observaciones_form" name="observaciones" value="">
        <input type="hidden" id="monto_recibido_form" name="monto_recibido" value="">
        <input type="hidden" id="tipo_venta_form" name="tipo_venta" value="contado">
        <input type="hidden" id="abono_inicial_form" name="abono_inicial" value="0">
        <input type="hidden" id="fecha_limite_form" name="fecha_limite" value="">
        <input type="hidden" id="tipo_descuento_form" name="tipo_descuento" value="monto">
        <input type="hidden" id="metodo_pago_form_hidden" name="metodo_pago" value="">
        <input type="hidden" id="usar_fecha_limite" name="usar_fecha_limite" value="0">
        
        <!-- Campos para pago mixto -->
        <input type="hidden" id="monto_efectivo_mixto_form" name="monto_efectivo_mixto" value="0">
        <input type="hidden" id="monto_tarjeta_mixto_form" name="monto_tarjeta_mixto" value="0">
        <input type="hidden" id="monto_transferencia_mixto_form" name="monto_transferencia_mixto" value="0">
        <input type="hidden" id="monto_otro_mixto_form" name="monto_otro_mixto" value="0">
    </form>
</body>
</html>