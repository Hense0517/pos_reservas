<?php 
ob_start();

// Verificar permisos directamente sin header
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: /sistema_pos/index.php');
    exit;
}

if (!isset($_SESSION['usuario_rol']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor')) {
    header('Location: /sistema_pos/index.php');
    exit;
}

// Cargar configuración de base de datos
require_once '../../config/database.php';
$database = Database::getInstance();
$db = $database->getConnection();

// Obtener configuración del negocio
$query_config = "SELECT impuesto FROM configuracion_negocio LIMIT 1";
$stmt_config = $db->prepare($query_config);
$stmt_config->execute();
$configuracion = $stmt_config->fetch(PDO::FETCH_ASSOC);

$impuesto_porcentaje = $configuracion['impuesto'] ?? 19.00;
$impuesto_decimal = $impuesto_porcentaje / 100;

// Generar número de factura
$query_ultima_factura = "SELECT numero_factura FROM ventas ORDER BY id DESC LIMIT 1";
$stmt_ultima_factura = $db->prepare($query_ultima_factura);
$stmt_ultima_factura->execute();
$ultima_factura = $stmt_ultima_factura->fetch(PDO::FETCH_ASSOC);

if ($ultima_factura) {
    $ultimo_numero = intval(substr($ultima_factura['numero_factura'], 3));
    $nuevo_numero = 'FAC' . str_pad($ultimo_numero + 1, 6, '0', STR_PAD_LEFT);
} else {
    $nuevo_numero = 'FAC000001';
}
?>

<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS - Caja Rápida</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #f8fafc;
            font-size: 13px;
        }
        
        .card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-primary:hover {
            background: #2563eb;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .input-field {
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 13px;
        }
        
        .input-field:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }
        
        .badge {
            background: #f1f5f9;
            color: #475569;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .header-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }
        
        .payment-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 6px;
            transition: all 0.2s;
            font-size: 11px;
        }
        
        .payment-card:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        
        .payment-card.selected {
            background: #eff6ff;
            border-color: #3b82f6;
            border-width: 2px;
        }
        
        .product-item {
            border-left: 3px solid #10b981;
            background: white;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .product-item:hover {
            background: #f8fafc;
        }
        
        .scroll-thin::-webkit-scrollbar {
            width: 4px;
        }
        
        .scroll-thin::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        
        .scroll-thin::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 2px;
        }
        
        .icon-blue { color: #3b82f6; }
        .icon-green { color: #10b981; }
        .icon-purple { color: #8b5cf6; }
        .icon-orange { color: #f59e0b; }
        .icon-red { color: #ef4444; }
        .icon-gray { color: #64748b; }
        
        /* Toggle Switch */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 24px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: .4s;
            border-radius: 24px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: #3b82f6;
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }
        
        /* Estilos para crédito compactos */
        .credito-info {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 6px;
            padding: 8px;
        }
        
        .deuda-info {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 6px;
            font-size: 11px;
        }
        
        /* Estilos para pago mixto */
        .mixto-info {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 6px;
            padding: 8px;
        }
        
        /* Estilos mejorados para validación */
        .input-error {
            border-color: #ef4444;
        }
        
        .input-error:focus {
            border-color: #ef4444;
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.1);
        }
        
        .error-message {
            color: #ef4444;
            font-size: 10px;
            margin-top: 2px;
        }
        
        .success-message {
            color: #10b981;
            font-size: 10px;
            margin-top: 2px;
        }
        
        /* Loading spinner */
        .spinner {
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3b82f6;
            border-radius: 50%;
            width: 14px;
            height: 14px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 5px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Animaciones */
        .fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Estilos para labels compactos */
        .label-compact {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 3px;
            display: block;
        }
        
        /* Estilos para checkboxes */
        .checkbox-container {
            display: flex;
            align-items: center;
            margin-top: 5px;
        }
        
        .checkbox-container input[type="checkbox"] {
            margin-right: 5px;
        }
        
        /* Input de fecha deshabilitado */
        .input-disabled {
            background-color: #f3f4f6;
            cursor: not-allowed;
        }
        
        /* Monto parcial */
        .monto-parcial {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            padding: 4px 8px;
            font-size: 11px;
        }
    </style>
</head>
<body class="h-full">
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
                            <span>Factura: <span class="font-medium"><?php echo $nuevo_numero; ?></span></span>
                            <span><i class="far fa-clock text-gray-400"></i> <?php echo date('H:i'); ?></span>
                            <span><i class="fas fa-user text-gray-400"></i> <?php echo $_SESSION['usuario_nombre']; ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center space-x-2">
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
                <div class="card flex-1 flex flex-col">
                    <!-- Cliente -->
                    <div class="p-3 border-b">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-user-circle icon-blue"></i>
                                <span class="font-medium text-gray-700 text-sm">Cliente</span>
                            </div>
                            <button type="button" id="btnNuevoCliente" 
                                    class="text-xs btn-primary px-2 py-1 flex items-center">
                                <i class="fas fa-plus mr-1"></i>
                                <span>Nuevo</span>
                            </button>
                        </div>
                        
                        <div class="relative mb-2">
                            <input type="text" id="buscarCliente" 
                                   placeholder="Buscar cliente..."
                                   class="input-field w-full pl-8 text-sm">
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
                                <input type="text" id="buscarProducto" 
                                       placeholder="Buscar producto..."
                                       class="input-field w-full pl-8 text-sm">
                                <div class="absolute left-2 top-1/2 transform -translate-y-1/2 text-gray-400">
                                    <i class="fas fa-search text-xs"></i>
                                </div>
                                <div id="resultadosBusqueda" class="absolute z-10 w-full mt-1 bg-white border rounded shadow-lg hidden max-h-40 overflow-y-auto text-xs"></div>
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
                                        <button type="button" id="btnDecrementar" 
                                                class="w-6 h-6 bg-gray-200 rounded flex items-center justify-center hover:bg-gray-300">
                                            <i class="fas fa-minus text-xs"></i>
                                        </button>
                                        
                                        <input type="number" id="cantidadProducto" min="1" value="1" 
                                               class="w-12 text-center input-field font-medium text-sm">
                                        
                                        <button type="button" id="btnIncrementar" 
                                                class="w-6 h-6 bg-gray-200 rounded flex items-center justify-center hover:bg-gray-300">
                                            <i class="fas fa-plus text-xs"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <button type="button" id="agregarProducto" 
                                        class="w-full btn-primary py-2 text-xs flex items-center justify-center">
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
                <div class="card flex-1 flex flex-col">
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
                            <button type="button" onclick="limpiarCarrito()" 
                                    class="flex-1 bg-red-50 text-red-600 hover:bg-red-100 py-1.5 px-2 rounded text-xs flex items-center justify-center">
                                <i class="fas fa-trash-alt mr-1"></i>
                                <span>Vaciar</span>
                            </button>
                            <button type="button" onclick="eliminarProductoSeleccionado()" 
                                    class="flex-1 bg-yellow-50 text-yellow-600 hover:bg-yellow-100 py-1.5 px-2 rounded text-xs flex items-center justify-center">
                                <i class="fas fa-minus-circle mr-1"></i>
                                <span>Quitar</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel Derecho: Pago y Crédito -->
            <div class="lg:col-span-1 flex flex-col h-full">
                <div class="card flex-1 flex flex-col">
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
                                        <input type="text" id="abono_inicial" value="0" 
                                               class="input-field w-full text-sm" placeholder="0">
                                        <div id="errorAbono" class="error-message hidden"></div>
                                    </div>
                                    <div class="checkbox-container">
                                        <input type="checkbox" id="chkFechaLimite">
                                        <label for="chkFechaLimite" class="text-xs text-gray-700">Establecer fecha límite</label>
                                    </div>
                                    <div id="fechaLimiteContainer" class="hidden">
                                        <label class="label-compact">Fecha Límite</label>
                                        <input type="date" id="fecha_limite" 
                                               class="input-field w-full text-sm">
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
                                <!-- Efectivo -->
                                <div>
                                    <label class="label-compact">Efectivo</label>
                                    <input type="text" id="monto_efectivo_mixto" value="0" 
                                           class="input-field w-full text-sm pago-mixto-input" placeholder="0">
                                </div>
                                
                                <!-- Tarjeta -->
                                <div>
                                    <label class="label-compact">Tarjeta</label>
                                    <input type="text" id="monto_tarjeta_mixto" value="0" 
                                           class="input-field w-full text-sm pago-mixto-input" placeholder="0">
                                </div>
                                
                                <!-- Transferencia -->
                                <div>
                                    <label class="label-compact">Transferencia</label>
                                    <input type="text" id="monto_transferencia_mixto" value="0" 
                                           class="input-field w-full text-sm pago-mixto-input" placeholder="0">
                                </div>
                                
                                <!-- Otro método -->
                                <div>
                                    <label class="label-compact">Otro</label>
                                    <input type="text" id="monto_otro_mixto" value="0" 
                                           class="input-field w-full text-sm pago-mixto-input" placeholder="0">
                                </div>
                                
                                <!-- Suma y validación -->
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
                                        <input type="radio" name="tipo_descuento" value="monto" checked 
                                               class="h-3 w-3 text-blue-600">
                                        <span class="ml-1">Monto</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="tipo_descuento" value="porcentaje"
                                               class="h-3 w-3 text-blue-600">
                                        <span class="ml-1">%</span>
                                    </label>
                                </div>
                            </div>
                            <input type="text" id="descuento" value="0" class="input-field w-full text-sm">
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
                            <input type="text" id="monto_recibido" class="input-field w-full text-sm" placeholder="Ingrese monto recibido">
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
                            <button type="button" id="btnProcesarVenta" 
                                    class="w-full btn-success py-2.5 text-sm font-medium flex items-center justify-center disabled:opacity-50 disabled:cursor-not-allowed" disabled>
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
        <input type="hidden" name="numero_factura" value="<?php echo $nuevo_numero; ?>">
        <input type="hidden" id="impuesto_porcentaje" value="<?php echo $impuesto_porcentaje; ?>">
        <input type="hidden" id="impuesto_decimal" value="<?php echo $impuesto_decimal; ?>">
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

    <script>
    // Variables globales
    let productosAgregados = [];
    let productoSeleccionado = null;
    let productoSeleccionadoEnCarrito = null;
    const impuestoPorcentaje = parseFloat(document.getElementById('impuesto_porcentaje').value) || 0;
    const impuesto = parseFloat(document.getElementById('impuesto_decimal').value) || 0;
    let tipoVenta = 'contado';
    let isProcessing = false;
    let procesarClickCount = 0;
    const MAX_CLICKS = 2; // Máximo de clics permitidos
    let procesarTimeout = null;
    let metodoPagoSeleccionado = '';

    // Configuración de SweetAlert
    const Toast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 2000,
        timerProgressBar: true,
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });

    // Mostrar notificaciones con SweetAlert
    function mostrarNotificacion(mensaje, tipo = 'info') {
        const config = {
            text: mensaje,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2000,
            timerProgressBar: true
        };

        switch(tipo) {
            case 'success':
                Swal.fire({
                    ...config,
                    icon: 'success',
                    iconColor: '#10b981'
                });
                break;
            case 'error':
                Swal.fire({
                    ...config,
                    icon: 'error',
                    iconColor: '#ef4444'
                });
                break;
            case 'warning':
                Swal.fire({
                    ...config,
                    icon: 'warning',
                    iconColor: '#f59e0b'
                });
                break;
            case 'info':
            default:
                Swal.fire({
                    ...config,
                    icon: 'info',
                    iconColor: '#3b82f6'
                });
                break;
        }
    }

    // Formatear números
    function formatearNumero(numero) {
        return new Intl.NumberFormat('es-CO', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(numero);
    }

    function formatearDecimal(numero) {
        return new Intl.NumberFormat('es-CO', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(numero);
    }

    function desformatearNumero(texto) {
        if (!texto || texto === '') return 0;
        
        // Remover símbolo de moneda y espacios
        let limpio = texto.toString()
            .replace('$', '')
            .replace(/\./g, '')
            .replace(',', '.')
            .trim();
        
        if (limpio === '' || limpio === '-') return 0;
        
        const numero = parseFloat(limpio);
        return isNaN(numero) ? 0 : numero;
    }

    // Manejar input de montos con formato
    function setupInputMoneda(inputId) {
        const input = document.getElementById(inputId);
        
        input.addEventListener('focus', function() {
            let value = desformatearNumero(this.value);
            this.value = value === 0 ? '' : value.toString();
        });
        
        input.addEventListener('blur', function() {
            let value = desformatearNumero(this.value);
            if (this.value.trim() !== '') {
                this.value = formatearDecimal(value);
            }
        });
        
        input.addEventListener('input', function(e) {
            // Permitir números, punto y backspace
            let value = this.value;
            
            // Reemplazar comas por puntos
            value = value.replace(',', '.');
            
            // Permitir solo un punto decimal
            const parts = value.split('.');
            if (parts.length > 2) {
                value = parts[0] + '.' + parts.slice(1).join('');
            }
            
            // Permitir solo números y un punto decimal
            this.value = value.replace(/[^0-9.]/g, '');
            
            // Limitar decimales a 2
            if (this.value.includes('.')) {
                const decimalPart = this.value.split('.')[1];
                if (decimalPart && decimalPart.length > 2) {
                    this.value = this.value.substring(0, this.value.indexOf('.') + 3);
                }
            }
            
            // Actualizar el valor real en el campo oculto correspondiente
            const realValue = desformatearNumero(this.value);
            const formField = document.getElementById(`${inputId}_form`);
            if (formField) {
                formField.value = realValue;
            }
            
            // Actualizar totales
            actualizarTotales();
        });
    }

    // Setup inputs de pago mixto
    function setupInputsPagoMixto() {
        const inputsMixto = [
            'monto_efectivo_mixto',
            'monto_tarjeta_mixto',
            'monto_transferencia_mixto',
            'monto_otro_mixto'
        ];
        
        inputsMixto.forEach(inputId => {
            setupInputMoneda(inputId);
        });
    }

    // Toggle crédito/contado
    document.getElementById('toggleCredito').addEventListener('change', function() {
        if (isProcessing) {
            this.checked = !this.checked;
            return;
        }
        
        tipoVenta = this.checked ? 'credito' : 'contado';
        document.getElementById('tipo_venta').value = tipoVenta;
        document.getElementById('tipo_venta_form').value = tipoVenta;
        
        if (tipoVenta === 'credito') {
            // Validar que haya cliente seleccionado para crédito
            const clienteId = document.getElementById('cliente_id_form').value;
            if (!clienteId) {
                mostrarNotificacion('Debe seleccionar un cliente para venta a crédito', 'warning');
                document.getElementById('buscarCliente').focus();
                this.checked = false;
                tipoVenta = 'contado';
                document.getElementById('tipo_venta').value = 'contado';
                document.getElementById('tipo_venta_form').value = 'contado';
                return;
            }
            
            document.getElementById('panelCredito').classList.remove('hidden');
            document.getElementById('panelContado').classList.add('hidden');
            
            // Ocultar panel mixto si estaba visible
            if (metodoPagoSeleccionado === 'mixto') {
                document.getElementById('panelPagoMixto').classList.add('hidden');
            }
            
            mostrarNotificacion('Modo crédito activado', 'info');
        } else {
            document.getElementById('panelCredito').classList.add('hidden');
            document.getElementById('panelContado').classList.remove('hidden');
            
            // Limpiar campos de crédito
            document.getElementById('abono_inicial').value = '0';
            document.getElementById('abono_inicial_form').value = '0';
            document.getElementById('infoDeuda').classList.add('hidden');
            
            // Mostrar panel mixto si corresponde
            if (metodoPagoSeleccionado === 'mixto') {
                document.getElementById('panelPagoMixto').classList.remove('hidden');
            }
            
            mostrarNotificacion('Modo contado activado', 'info');
        }
        
        validarFormulario();
        actualizarTotales();
    });

    // Checkbox para fecha límite
    document.getElementById('chkFechaLimite').addEventListener('change', function() {
        const fechaContainer = document.getElementById('fechaLimiteContainer');
        const fechaInput = document.getElementById('fecha_limite');
        const usarFechaLimiteInput = document.getElementById('usar_fecha_limite');
        
        if (this.checked) {
            fechaContainer.classList.remove('hidden');
            fechaInput.disabled = false;
            fechaInput.classList.remove('input-disabled');
            usarFechaLimiteInput.value = '1';
            
            // Establecer fecha límite por defecto (15 días)
            const hoy = new Date();
            const fechaLimite = new Date(hoy);
            fechaLimite.setDate(hoy.getDate() + 15);
            fechaInput.value = fechaLimite.toISOString().split('T')[0];
            document.getElementById('fecha_limite_form').value = fechaLimite.toISOString().split('T')[0];
        } else {
            fechaContainer.classList.add('hidden');
            fechaInput.disabled = true;
            fechaInput.classList.add('input-disabled');
            fechaInput.value = '';
            usarFechaLimiteInput.value = '0';
            document.getElementById('fecha_limite_form').value = '';
        }
    });

    // Limpiar crédito
    document.getElementById('btnLimpiarCredito').addEventListener('click', function() {
        document.getElementById('abono_inicial').value = '0';
        document.getElementById('abono_inicial_form').value = '0';
        document.getElementById('chkFechaLimite').checked = false;
        document.getElementById('fechaLimiteContainer').classList.add('hidden');
        document.getElementById('fecha_limite').value = '';
        document.getElementById('fecha_limite_form').value = '';
        document.getElementById('usar_fecha_limite').value = '0';
        document.getElementById('infoDeuda').classList.add('hidden');
        actualizarTotales();
    });

    // Limpiar pago mixto
    document.getElementById('btnLimpiarMixto').addEventListener('click', function() {
        document.getElementById('monto_efectivo_mixto').value = '0';
        document.getElementById('monto_tarjeta_mixto').value = '0';
        document.getElementById('monto_transferencia_mixto').value = '0';
        document.getElementById('monto_otro_mixto').value = '0';
        
        // Actualizar campos ocultos
        document.getElementById('monto_efectivo_mixto_form').value = '0';
        document.getElementById('monto_tarjeta_mixto_form').value = '0';
        document.getElementById('monto_transferencia_mixto_form').value = '0';
        document.getElementById('monto_otro_mixto_form').value = '0';
        
        actualizarTotales();
    });

    // Controladores de cantidad
    document.getElementById('btnIncrementar').addEventListener('click', () => {
        const input = document.getElementById('cantidadProducto');
        input.value = parseInt(input.value) + 1;
    });

    document.getElementById('btnDecrementar').addEventListener('click', () => {
        const input = document.getElementById('cantidadProducto');
        if (parseInt(input.value) > 1) {
            input.value = parseInt(input.value) - 1;
        }
    });

    // Seleccionar método de pago
    document.querySelectorAll('.metodo-pago').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.metodo-pago').forEach(b => {
                b.classList.remove('selected');
            });
            this.classList.add('selected');
            
            const metodo = this.dataset.method;
            metodoPagoSeleccionado = metodo;
            document.getElementById('metodo_pago_form').value = metodo;
            document.getElementById('metodo_pago_form_hidden').value = metodo;
            
            document.getElementById('errorMetodoPago').classList.add('hidden');
            
            // Mostrar/ocultar paneles según método seleccionado
            if (metodo === 'mixto') {
                document.getElementById('panelPagoMixto').classList.remove('hidden');
                // En modo mixto, ocultar el monto recibido simple
                document.getElementById('panelContado').classList.add('hidden');
            } else {
                document.getElementById('panelPagoMixto').classList.add('hidden');
                // Si no es crédito, mostrar panel contado normal
                if (tipoVenta === 'contado') {
                    document.getElementById('panelContado').classList.remove('hidden');
                }
            }
            
            validarFormulario();
        });
    });

    // Setup inputs de moneda
    setupInputMoneda('abono_inicial');
    setupInputMoneda('monto_recibido');
    setupInputMoneda('descuento');
    setupInputsPagoMixto();

    // Event listener para fecha límite
    document.getElementById('fecha_limite').addEventListener('change', function() {
        document.getElementById('fecha_limite_form').value = this.value;
    });

    // Búsqueda de clientes
    document.getElementById('buscarCliente').addEventListener('input', function() {
        const query = this.value.trim();
        if (query.length < 2) {
            document.getElementById('resultadosCliente').classList.add('hidden');
            return;
        }
        buscarClientes(query);
    });

    async function buscarClientes(query) {
        try {
            const response = await fetch(`buscar_cliente.php?q=${encodeURIComponent(query)}`);
            const contentType = response.headers.get("content-type");
            
            // Verificar si la respuesta es JSON
            if (contentType && contentType.includes("application/json")) {
                const clientes = await response.json();
                const resultadosDiv = document.getElementById('resultadosCliente');
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
                        div.addEventListener('click', () => {
                            seleccionarCliente(cliente);
                            document.getElementById('resultadosCliente').classList.add('hidden');
                            document.getElementById('buscarCliente').value = '';
                            mostrarNotificacion(`Cliente ${cliente.nombre} seleccionado`, 'success');
                        });
                        resultadosDiv.appendChild(div);
                    });
                    resultadosDiv.classList.remove('hidden');
                } else {
                    resultadosDiv.innerHTML = `
                        <div class="px-3 py-3 text-gray-500 text-xs text-center fade-in">
                            <div class="mb-1">No se encontraron clientes</div>
                            <button type="button" onclick="mostrarModalNuevoCliente()" 
                                    class="text-blue-600 hover:text-blue-800 text-xs">
                                <i class="fas fa-plus mr-1"></i> Crear nuevo
                            </button>
                        </div>
                    `;
                    resultadosDiv.classList.remove('hidden');
                }
            } else {
                // Si no es JSON, mostrar error
                throw new Error('Respuesta del servidor no es JSON');
            }
        } catch (error) {
            console.error('Error:', error);
            mostrarNotificacion('Error en la búsqueda de clientes', 'error');
            document.getElementById('resultadosCliente').classList.add('hidden');
        }
    }

    function seleccionarCliente(cliente) {
        document.getElementById('cliente_id').value = cliente.id;
        document.getElementById('cliente_id_form').value = cliente.id;
        document.getElementById('clienteNombre').textContent = cliente.nombre;
        document.getElementById('clienteDocumento').textContent = `${cliente.tipo_documento}: ${cliente.numero_documento}`;
        document.getElementById('infoCliente').classList.remove('hidden');
        validarFormulario();
    }

    document.getElementById('cambiarCliente').addEventListener('click', function() {
        document.getElementById('cliente_id').value = '';
        document.getElementById('cliente_id_form').value = '';
        document.getElementById('infoCliente').classList.add('hidden');
        document.getElementById('buscarCliente').focus();
        validarFormulario();
    });

    // Crear nuevo cliente usando SweetAlert modal
    document.getElementById('btnNuevoCliente').addEventListener('click', function() {
        mostrarModalNuevoCliente();
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

    // Búsqueda de productos
    document.getElementById('buscarProducto').addEventListener('input', function() {
        const query = this.value.trim();
        if (query.length < 2) {
            document.getElementById('resultadosBusqueda').classList.add('hidden');
            return;
        }
        buscarProductos(query);
    });

    async function buscarProductos(query) {
        try {
            const response = await fetch(`buscar_producto.php?q=${encodeURIComponent(query)}`);
            const contentType = response.headers.get("content-type");
            
            if (contentType && contentType.includes("application/json")) {
                const resultados = await response.json();
                
                const resultadosDiv = document.getElementById('resultadosBusqueda');
                resultadosDiv.innerHTML = '';
                
                if (resultados.length > 0) {
                    resultados.forEach(producto => {
                        const div = document.createElement('div');
                        div.className = 'px-3 py-2 hover:bg-green-50 cursor-pointer border-b text-xs fade-in';
                        div.innerHTML = `
                            <div class="font-medium truncate">${producto.nombre}</div>
                            <div class="text-gray-600 mt-1">
                                $${formatearDecimal(parseFloat(producto.precio_venta))} | Stock: ${producto.stock}
                            </div>
                        `;
                        div.addEventListener('click', () => {
                            seleccionarProducto(producto);
                            document.getElementById('buscarProducto').value = '';
                            document.getElementById('resultadosBusqueda').classList.add('hidden');
                            document.getElementById('cantidadProducto').focus();
                            mostrarNotificacion(`Producto ${producto.nombre} seleccionado`, 'success');
                        });
                        resultadosDiv.appendChild(div);
                    });
                    resultadosDiv.classList.remove('hidden');
                } else {
                    resultadosDiv.innerHTML = '<div class="px-3 py-3 text-gray-500 text-xs text-center fade-in">No se encontraron productos</div>';
                    resultadosDiv.classList.remove('hidden');
                }
            } else {
                throw new Error('Respuesta del servidor no es JSON');
            }
        } catch (error) {
            console.error('Error:', error);
            mostrarNotificacion('Error al buscar productos', 'error');
            document.getElementById('resultadosBusqueda').classList.add('hidden');
        }
    }

    function seleccionarProducto(producto) {
        productoSeleccionado = producto;
        
        document.getElementById('productoNombre').textContent = producto.nombre;
        document.getElementById('productoPrecio').textContent = `$${formatearDecimal(parseFloat(producto.precio_venta))}`;
        document.getElementById('productoStock').textContent = producto.stock;
        document.getElementById('productoSeleccionadoInfo').classList.remove('hidden');
        
        document.getElementById('cantidadProducto').max = producto.stock;
        document.getElementById('cantidadProducto').value = 1;
    }

    // Agregar producto al carrito
    document.getElementById('agregarProducto').addEventListener('click', function() {
        if (!productoSeleccionado) {
            mostrarNotificacion('Primero busca y selecciona un producto', 'warning');
            document.getElementById('buscarProducto').focus();
            return;
        }
        
        const cantidad = parseInt(document.getElementById('cantidadProducto').value);
        
        if (cantidad < 1) {
            mostrarNotificacion('La cantidad debe ser mayor a 0', 'error');
            return;
        }
        
        if (cantidad > productoSeleccionado.stock) {
            mostrarNotificacion(`Stock insuficiente. Disponible: ${productoSeleccionado.stock}`, 'error');
            return;
        }
        
        const index = productosAgregados.findIndex(p => p.id == productoSeleccionado.id);
        if (index > -1) {
            const nuevaCantidad = productosAgregados[index].cantidad + cantidad;
            if (nuevaCantidad > productoSeleccionado.stock) {
                mostrarNotificacion(`Stock insuficiente. Máximo: ${productoSeleccionado.stock}`, 'error');
                return;
            }
            productosAgregados[index].cantidad = nuevaCantidad;
            mostrarNotificacion(`Cantidad actualizada: ${nuevaCantidad} unidades`, 'success');
        } else {
            productosAgregados.push({
                id: productoSeleccionado.id,
                nombre: productoSeleccionado.nombre,
                precio: parseFloat(productoSeleccionado.precio_venta),
                cantidad: cantidad,
                stock: productoSeleccionado.stock,
                codigo: productoSeleccionado.codigo,
                codigo_barras: productoSeleccionado.codigo_barras
            });
            mostrarNotificacion('Producto agregado al carrito', 'success');
        }
        
        actualizarCarrito();
        actualizarTotales();
        productoSeleccionado = null;
        document.getElementById('productoSeleccionadoInfo').classList.add('hidden');
        document.getElementById('cantidadProducto').value = 1;
        document.getElementById('buscarProducto').focus();
    });

    // Actualizar carrito
    function actualizarCarrito() {
        const lista = document.getElementById('listaProductos');
        const contador = document.getElementById('contadorProductos');
        
        if (productosAgregados.length === 0) {
            lista.innerHTML = `
                <div class="text-center py-8 fade-in">
                    <div class="text-gray-300 mb-2">
                        <i class="fas fa-shopping-cart text-2xl"></i>
                    </div>
                    <p class="text-gray-500 text-xs">Carrito vacío</p>
                    <p class="text-gray-400 text-xs mt-1">Agrega productos</p>
                </div>
            `;
            contador.textContent = '0';
            return;
        }
        
        contador.textContent = productosAgregados.length;
        
        let html = '<div class="space-y-2">';
        productosAgregados.forEach((producto, index) => {
            const subtotal = producto.precio * producto.cantidad;
            const esSeleccionado = productoSeleccionadoEnCarrito === index;
            html += `
                <div class="product-item p-2 ${esSeleccionado ? 'bg-blue-50' : ''} cursor-pointer fade-in" 
                     onclick="seleccionarProductoCarrito(${index})">
                    <div class="flex justify-between items-center">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 text-xs truncate">${producto.nombre}</div>
                            <div class="text-gray-500 text-xs mt-0.5">
                                $${formatearDecimal(producto.precio)} x ${producto.cantidad}
                            </div>
                        </div>
                        <div class="text-right ml-2">
                            <div class="font-bold text-green-600 text-xs">$${formatearDecimal(subtotal)}</div>
                            <button type="button" onclick="event.stopPropagation(); eliminarProducto(${index})" 
                                    class="text-red-500 hover:text-red-700 text-xs mt-0.5">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        lista.innerHTML = html;
    }

    function seleccionarProductoCarrito(index) {
        productoSeleccionadoEnCarrito = index;
        actualizarCarrito();
    }

    function eliminarProducto(index) {
        const producto = productosAgregados[index];
        productosAgregados.splice(index, 1);
        productoSeleccionadoEnCarrito = null;
        actualizarCarrito();
        actualizarTotales();
        mostrarNotificacion(`Producto "${producto.nombre}" eliminado`, 'info');
    }

    function eliminarProductoSeleccionado() {
        if (productoSeleccionadoEnCarrito !== null) {
            eliminarProducto(productoSeleccionadoEnCarrito);
        } else {
            mostrarNotificacion('Selecciona un producto del carrito primero', 'warning');
        }
    }

    function limpiarCarrito() {
        if (productosAgregados.length === 0) {
            mostrarNotificacion('El carrito ya está vacío', 'info');
            return;
        }
        
        Swal.fire({
            title: '¿Vaciar carrito?',
            text: 'Se eliminarán todos los productos del carrito',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, vaciar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                productosAgregados = [];
                productoSeleccionadoEnCarrito = null;
                actualizarCarrito();
                actualizarTotales();
                mostrarNotificacion('Carrito vaciado', 'success');
            }
        });
    }

    // Calcular suma de pagos mixtos
    function calcularSumaPagosMixtos() {
        const efectivo = desformatearNumero(document.getElementById('monto_efectivo_mixto').value) || 0;
        const tarjeta = desformatearNumero(document.getElementById('monto_tarjeta_mixto').value) || 0;
        const transferencia = desformatearNumero(document.getElementById('monto_transferencia_mixto').value) || 0;
        const otro = desformatearNumero(document.getElementById('monto_otro_mixto').value) || 0;
        
        return efectivo + tarjeta + transferencia + otro;
    }

    // Validar suma de pagos mixtos
    function validarSumaPagosMixtos(totalVenta) {
        const sumaPagos = calcularSumaPagosMixtos();
        const errorElement = document.getElementById('errorSumaPagos');
        const successElement = document.getElementById('successSumaPagos');
        const sumaElement = document.getElementById('sumaPagosMixtos');
        const totalElement = document.getElementById('totalCompararMixto');
        
        // Actualizar displays
        sumaElement.textContent = `$${formatearDecimal(sumaPagos)}`;
        totalElement.textContent = `$${formatearDecimal(totalVenta)}`;
        
        // Validar diferencia
        const diferencia = Math.abs(sumaPagos - totalVenta);
        const tolerancia = 0.01; // 1 centavo de tolerancia
        
        if (diferencia > tolerancia) {
            errorElement.classList.remove('hidden');
            successElement.classList.add('hidden');
            errorElement.textContent = `La suma ($${formatearDecimal(sumaPagos)}) no coincide con el total ($${formatearDecimal(totalVenta)})`;
            return false;
        } else {
            errorElement.classList.add('hidden');
            successElement.classList.remove('hidden');
            return true;
        }
    }

    // Actualizar totales
    function actualizarTotales() {
        let subtotal = 0;
        productosAgregados.forEach(producto => {
            subtotal += producto.precio * producto.cantidad;
        });
        
        const tipoDescuento = document.querySelector('input[name="tipo_descuento"]:checked').value;
        const valorDescuento = desformatearNumero(document.getElementById('descuento').value) || 0;
        
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
        document.getElementById('subtotal').textContent = `$${formatearDecimal(subtotal)}`;
        document.getElementById('descuentoTotal').textContent = `$${formatearDecimal(descuentoTotal)}`;
        document.getElementById('impuesto').textContent = `$${formatearDecimal(impuestoTotal)}`;
        document.getElementById('total').textContent = `$${formatearDecimal(total)}`;
        
        // Actualizar campos ocultos
        document.getElementById('descuento_form').value = valorDescuento;
        document.getElementById('tipo_descuento_form').value = tipoDescuento;
        
        // Manejo por tipo de venta
        if (tipoVenta === 'contado') {
            if (metodoPagoSeleccionado === 'mixto') {
                // Para pago mixto, validar suma
                const sumaValida = validarSumaPagosMixtos(total);
                
                // Ocultar cambio para pago mixto
                document.getElementById('cambio').textContent = '$0';
                document.getElementById('monto_recibido_form').value = 0;
                document.getElementById('abono_inicial_form').value = 0;
                
            } else {
                // Contado normal: calcular cambio
                const montoRecibido = desformatearNumero(document.getElementById('monto_recibido').value) || 0;
                const cambio = montoRecibido - total;
                
                document.getElementById('cambio').textContent = `$${formatearDecimal(Math.max(0, cambio))}`;
                document.getElementById('monto_recibido_form').value = montoRecibido;
                document.getElementById('abono_inicial_form').value = 0;
            }
            
        } else {
            // Crédito: calcular abono y saldo pendiente
            const abonoInicial = desformatearNumero(document.getElementById('abono_inicial').value) || 0;
            const saldoPendiente = total - abonoInicial;
            
            // Actualizar UI compacta
            document.getElementById('totalVentaCredito').textContent = `$${formatearDecimal(total)}`;
            document.getElementById('abonoCredito').textContent = `$${formatearDecimal(abonoInicial)}`;
            document.getElementById('saldoPendiente').textContent = `$${formatearDecimal(saldoPendiente)}`;
            
            // Mostrar/ocultar info de deuda
            if (total > 0 && abonoInicial < total) {
                document.getElementById('infoDeuda').classList.remove('hidden');
            } else {
                document.getElementById('infoDeuda').classList.add('hidden');
            }
            
            document.getElementById('monto_recibido_form').value = abonoInicial;
            document.getElementById('abono_inicial_form').value = abonoInicial;
            
            // Para crédito, el cambio siempre es 0
            document.getElementById('cambio').textContent = '$0';
        }
        
        validarFormulario();
    }

    // Event listeners para actualizar totales
    document.querySelectorAll('input[name="tipo_descuento"]').forEach(radio => {
        radio.addEventListener('change', actualizarTotales);
    });

    // Event listeners para inputs de pago mixto
    document.querySelectorAll('.pago-mixto-input').forEach(input => {
        input.addEventListener('input', actualizarTotales);
    });

    document.getElementById('observaciones').addEventListener('input', function() {
        document.getElementById('observaciones_form').value = this.value;
    });

    // Validar formulario completo
    function validarFormulario() {
        if (isProcessing) return false;
        
        const btnProcesar = document.getElementById('btnProcesarVenta');
        const clienteValido = document.getElementById('cliente_id_form').value !== '';
        const productosValido = productosAgregados.length > 0;
        const metodoPagoValido = document.getElementById('metodo_pago_form').value !== '';
        
        let errores = [];
        
        // Validaciones generales
        if (!clienteValido) errores.push('Seleccione un cliente');
        if (!productosValido) errores.push('Agregue al menos un producto');
        if (!metodoPagoValido) {
            errores.push('Seleccione un método de pago');
            document.getElementById('errorMetodoPago').classList.remove('hidden');
        } else {
            document.getElementById('errorMetodoPago').classList.add('hidden');
        }
        
        // Validaciones específicas por tipo de venta y método de pago
        if (tipoVenta === 'contado') {
            if (metodoPagoSeleccionado === 'mixto') {
                // Validar pago mixto
                const total = desformatearNumero(document.getElementById('total').textContent);
                const sumaPagos = calcularSumaPagosMixtos();
                const diferencia = Math.abs(sumaPagos - total);
                const tolerancia = 0.01;
                
                if (diferencia > tolerancia) {
                    errores.push('La suma de los pagos mixtos no coincide con el total');
                }
                
            } else {
                // Validar contado normal
                const total = desformatearNumero(document.getElementById('total').textContent);
                const montoRecibido = desformatearNumero(document.getElementById('monto_recibido').value) || 0;
                
                if (montoRecibido < total) {
                    errores.push('El monto recibido debe cubrir el total');
                    document.getElementById('errorMontoRecibido').textContent = 'Monto insuficiente';
                    document.getElementById('errorMontoRecibido').classList.remove('hidden');
                    document.getElementById('monto_recibido').classList.add('input-error');
                } else {
                    document.getElementById('errorMontoRecibido').classList.add('hidden');
                    document.getElementById('monto_recibido').classList.remove('input-error');
                }
            }
            
        } else {
            // Validar crédito
            const total = desformatearNumero(document.getElementById('total').textContent);
            const abonoInicial = desformatearNumero(document.getElementById('abono_inicial').value) || 0;
            
            if (abonoInicial > total) {
                errores.push('El abono no puede ser mayor al total');
                document.getElementById('errorAbono').textContent = 'Abono excede el total';
                document.getElementById('errorAbono').classList.remove('hidden');
                document.getElementById('abono_inicial').classList.add('input-error');
            } else {
                document.getElementById('errorAbono').classList.add('hidden');
                document.getElementById('abono_inicial').classList.remove('input-error');
            }
            
            // Validar que el abono no sea negativo
            if (abonoInicial < 0) {
                errores.push('El abono no puede ser negativo');
                document.getElementById('errorAbono').textContent = 'Abono no puede ser negativo';
                document.getElementById('errorAbono').classList.remove('hidden');
                document.getElementById('abono_inicial').classList.add('input-error');
            }
            
            if (!clienteValido) {
                errores.push('Se requiere cliente para venta a crédito');
            }
        }
        
        // Mostrar/ocultar errores generales
        if (errores.length > 0) {
            document.getElementById('errorGeneral').textContent = errores.join('. ');
            document.getElementById('errorGeneral').classList.remove('hidden');
            btnProcesar.disabled = true;
            return false;
        } else {
            document.getElementById('errorGeneral').classList.add('hidden');
            btnProcesar.disabled = false;
            return true;
        }
    }

    // Preparar datos de pago mixto para enviar
    function prepararDatosPagoMixto(formData) {
        const efectivo = desformatearNumero(document.getElementById('monto_efectivo_mixto').value) || 0;
        const tarjeta = desformatearNumero(document.getElementById('monto_tarjeta_mixto').value) || 0;
        const transferencia = desformatearNumero(document.getElementById('monto_transferencia_mixto').value) || 0;
        const otro = desformatearNumero(document.getElementById('monto_otro_mixto').value) || 0;
        
        formData.append('pago_mixto', '1');
        formData.append('monto_efectivo_mixto', efectivo);
        formData.append('monto_tarjeta_mixto', tarjeta);
        formData.append('monto_transferencia_mixto', transferencia);
        formData.append('monto_otro_mixto', otro);
        
        return formData;
    }

    // Procesar venta con SweetAlert y control de múltiples clics
    document.getElementById('btnProcesarVenta').addEventListener('click', async function(e) {
        e.preventDefault();
        
        // Control de múltiples clics
        procesarClickCount++;
        if (procesarClickCount > MAX_CLICKS) {
            mostrarNotificacion('Por favor espere, la venta se está procesando...', 'warning');
            return;
        }
        
        if (!validarFormulario() || isProcessing) {
            mostrarNotificacion('Complete todos los campos requeridos', 'error');
            procesarClickCount = 0;
            return;
        }
        
        // Confirmar antes de procesar con SweetAlert
        let mensajeConfirmacion = '';
        let tituloConfirmacion = '';
        
        if (tipoVenta === 'credito') {
            const total = desformatearNumero(document.getElementById('total').textContent);
            const abonoInicial = desformatearNumero(document.getElementById('abono_inicial').value) || 0;
            const saldoPendiente = total - abonoInicial;
            
            tituloConfirmacion = '¿Confirmar venta a CRÉDITO?';
            mensajeConfirmacion = `
                <div class="text-left">
                    <p><strong>Total:</strong> $${formatearDecimal(total)}</p>
                    <p><strong>Abono inicial:</strong> $${formatearDecimal(abonoInicial)}</p>
                    <p><strong>Saldo pendiente:</strong> $${formatearDecimal(saldoPendiente)}</p>
                    <p class="mt-2 text-sm text-gray-600">¿Desea continuar?</p>
                </div>
            `;
        } else if (metodoPagoSeleccionado === 'mixto') {
            const total = desformatearNumero(document.getElementById('total').textContent);
            const efectivo = desformatearNumero(document.getElementById('monto_efectivo_mixto').value) || 0;
            const tarjeta = desformatearNumero(document.getElementById('monto_tarjeta_mixto').value) || 0;
            const transferencia = desformatearNumero(document.getElementById('monto_transferencia_mixto').value) || 0;
            const otro = desformatearNumero(document.getElementById('monto_otro_mixto').value) || 0;
            
            tituloConfirmacion = '¿Confirmar venta con PAGO MIXTO?';
            mensajeConfirmacion = `
                <div class="text-left space-y-1">
                    <p><strong>Total:</strong> $${formatearDecimal(total)}</p>
                    <div class="ml-2 text-sm">
                        <p><strong>Efectivo:</strong> $${formatearDecimal(efectivo)}</p>
                        <p><strong>Tarjeta:</strong> $${formatearDecimal(tarjeta)}</p>
                        <p><strong>Transferencia:</strong> $${formatearDecimal(transferencia)}</p>
                        <p><strong>Otro:</strong> $${formatearDecimal(otro)}</p>
                    </div>
                    <p class="mt-2 text-sm text-gray-600">¿Desea continuar?</p>
                </div>
            `;
        } else {
            tituloConfirmacion = '¿Confirmar venta de CONTADO?';
            mensajeConfirmacion = '¿Desea procesar la venta?';
        }
        
        Swal.fire({
            title: tituloConfirmacion,
            html: mensajeConfirmacion,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#10b981',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, procesar',
            cancelButtonText: 'Cancelar',
            showLoaderOnConfirm: true,
            preConfirm: async () => {
                try {
                    // Bloquear procesamiento
                    isProcessing = true;
                    
                    // Crear formData
                    const formData = new FormData();
                    formData.append('numero_factura', document.querySelector('input[name="numero_factura"]').value);
                    formData.append('cliente_id', document.getElementById('cliente_id_form').value);
                    formData.append('tipo_venta', document.getElementById('tipo_venta_form').value);
                    formData.append('metodo_pago', document.getElementById('metodo_pago_form_hidden').value);
                    formData.append('descuento', document.getElementById('descuento_form').value);
                    formData.append('tipo_descuento', document.getElementById('tipo_descuento_form').value);
                    formData.append('observaciones', document.getElementById('observaciones_form').value);
                    formData.append('monto_recibido', document.getElementById('monto_recibido_form').value);
                    formData.append('abono_inicial', document.getElementById('abono_inicial_form').value);
                    formData.append('fecha_limite', document.getElementById('fecha_limite_form').value);
                    formData.append('usar_fecha_limite', document.getElementById('usar_fecha_limite').value);
                    
                    // Agregar productos
                    productosAgregados.forEach((producto, index) => {
                        formData.append(`productos[${index}][id]`, producto.id);
                        formData.append(`productos[${index}][cantidad]`, producto.cantidad);
                        formData.append(`productos[${index}][precio]`, producto.precio);
                    });
                    
                    // Si es pago mixto, agregar los montos específicos
                    if (metodoPagoSeleccionado === 'mixto') {
                        prepararDatosPagoMixto(formData);
                    }
                    
                    // Enviar venta con timeout
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 15000); // 15 segundos timeout
                    
                    const response = await fetch('procesar_venta.php', {
                        method: 'POST',
                        body: formData,
                        signal: controller.signal,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    
                    clearTimeout(timeoutId);
                    
                    const contentType = response.headers.get("content-type");
                    if (!contentType || !contentType.includes("application/json")) {
                        throw new Error('Respuesta del servidor no es JSON');
                    }
                    
                    const resultado = await response.json();
                    
                    if (resultado.success) {
                        return resultado;
                    } else {
                        throw new Error(resultado.error || 'Error al procesar la venta');
                    }
                    
                } catch (error) {
                    if (error.name === 'AbortError') {
                        throw new Error('El servidor está tardando demasiado. Por favor intente nuevamente.');
                    }
                    throw error;
                }
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                const resultado = result.value;
                
                Swal.fire({
                    title: '¡Venta procesada!',
                    text: 'La venta se ha procesado exitosamente',
                    icon: 'success',
                    confirmButtonColor: '#10b981',
                    confirmButtonText: 'Ver detalle'
                }).then(() => {
                    // Redirigir a resumen de venta
                    window.location.href = `ver.php?id=${resultado.venta_id}`;
                });
                
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                mostrarNotificacion('Venta cancelada', 'info');
            }
        }).catch((error) => {
            console.error('Error:', error);
            
            Swal.fire({
                title: 'Error',
                text: error.message || 'Error al procesar la venta',
                icon: 'error',
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Entendido'
            });
        }).finally(() => {
            // Resetear contador y estado
            isProcessing = false;
            procesarClickCount = 0;
            if (procesarTimeout) {
                clearTimeout(procesarTimeout);
                procesarTimeout = null;
            }
        });
    });

    // Focus inicial en búsqueda de producto
    document.getElementById('buscarProducto').focus();

    // Inicializar validación periódica
    setInterval(validarFormulario, 1000);
    
    // Limpiar contador de clics después de 5 segundos
    setInterval(() => {
        if (procesarClickCount > 0 && !isProcessing) {
            procesarClickCount = 0;
        }
    }, 5000);
    </script>
</body>
</html>