<?php
// modules/ventas/crear.php
session_start();

// Establecer zona horaria de Colombia
date_default_timezone_set('America/Bogota');

// RUTA CORREGIDA - 2 niveles hacia arriba
require_once __DIR__ . '/../../includes/config.php';

ob_start();

// Verificar autenticación
if (!isset($auth) || !$auth->getUserId()) {
    $_SESSION['error'] = "Debes iniciar sesión para acceder a esta función";
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Verificar permisos usando la clase Auth
if (!$auth->hasPermission('ventas', 'crear')) {
    $_SESSION['error'] = "No tienes permisos para crear ventas";
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

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

// Verificar si hay venta pausada para este usuario
$venta_pausada = null;
$venta_pausada_id = null;
$usuario_id = $_SESSION['usuario_id'] ?? 0;

// Buscar ventas pausadas para este usuario
try {
    $table_check = $db->query("SHOW TABLES LIKE 'ventas_pausadas'")->fetch();
    
    if ($table_check) {
        $query_pausada = "SELECT * FROM ventas_pausadas 
                         WHERE usuario_id = :usuario_id 
                         ORDER BY fecha_pausa DESC LIMIT 1";
        $stmt_pausada = $db->prepare($query_pausada);
        $stmt_pausada->execute([':usuario_id' => $usuario_id]);
        $venta_pausada = $stmt_pausada->fetch(PDO::FETCH_ASSOC);

        if ($venta_pausada) {
            $venta_pausada_id = $venta_pausada['id'];
        }
    }
} catch (Exception $e) {
    error_log("Error al buscar ventas pausadas: " . $e->getMessage());
}

// Obtener cantidad de ventas pausadas para el usuario actual
$total_pausadas = 0;
try {
    $table_check = $db->query("SHOW TABLES LIKE 'ventas_pausadas'")->fetch();
    if ($table_check) {
        $query_pausadas = "SELECT COUNT(*) as total FROM ventas_pausadas WHERE usuario_id = :usuario_id";
        $stmt_pausadas = $db->prepare($query_pausadas);
        $stmt_pausadas->execute([':usuario_id' => $usuario_id]);
        $ventas_pausadas = $stmt_pausadas->fetch(PDO::FETCH_ASSOC);
        $total_pausadas = $ventas_pausadas['total'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Error al contar pausas: " . $e->getMessage());
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
        
        .mixto-info {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 6px;
            padding: 8px;
        }
        
        .input-error {
            border-color: #ef4444;
            border-width: 2px;
        }
        
        .input-error:focus {
            border-color: #ef4444;
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.1);
        }
        
        .input-success {
            border-color: #10b981;
        }
        
        .input-success:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.1);
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
        
        .fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .label-compact {
            font-size: 11px;
            color: #64748b;
            margin-bottom: 3px;
            display: block;
        }
        
        .checkbox-container {
            display: flex;
            align-items: center;
            margin-top: 5px;
        }
        
        .checkbox-container input[type="checkbox"] {
            margin-right: 5px;
        }
        
        .input-disabled {
            background-color: #f3f4f6;
            cursor: not-allowed;
        }
        
        .btn-pausas {
            background: #8b5cf6;
            color: white;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
            padding: 4px 10px;
            font-size: 11px;
        }
        
        .btn-pausas:hover {
            background: #7c3aed;
        }
        
        .btn-pausas.with-badge {
            position: relative;
        }
        
        .pausas-count-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ef4444;
            color: white;
            font-size: 9px;
            font-weight: bold;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .pausas-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            display: none;
        }
        
        .pausas-content {
            background: white;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        
        .pausas-header {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
            padding: 15px 20px;
        }
        
        .pausas-body {
            padding: 20px;
            max-height: 50vh;
            overflow-y: auto;
        }
        
        .pausas-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 10px;
            transition: all 0.2s;
        }
        
        .pausas-item:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        
        .pausas-empty {
            text-align: center;
            padding: 40px 20px;
            color: #64748b;
        }
        
        .pausas-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .btn-resume {
            background: #10b981;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            flex: 1;
        }
        
        .btn-delete {
            background: #ef4444;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            flex: 1;
        }
        
        .badge-pausada {
            background: #fef3c7;
            color: #92400e;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
            margin-left: 5px;
        }
        
        .btn-pausa {
            background: #f59e0b;
            color: white;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
            padding: 4px 10px;
            font-size: 11px;
        }
        
        .btn-pausa:hover {
            background: #d97706;
        }
        
        .btn-restaurar {
            background: #8b5cf6;
            color: white;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
            padding: 4px 10px;
            font-size: 11px;
        }
        
        .btn-restaurar:hover {
            background: #7c3aed;
        }
        
        .venta-pausada-activa {
            border-left: 4px solid #f59e0b;
            background: #fffbeb;
        }
        
        .tooltip {
            position: relative;
            display: inline-block;
        }
        
        .tooltip .tooltiptext {
            visibility: hidden;
            width: 120px;
            background-color: #374151;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px 0;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -60px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 10px;
        }
        
        .tooltip .tooltiptext::after {
            content: "";
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #374151 transparent transparent transparent;
        }
        
        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }
        
        .moneda-input {
            text-align: right;
            font-family: 'Courier New', monospace;
            font-weight: 500;
        }
        
        .moneda-input::placeholder {
            color: #9ca3af;
            font-weight: normal;
        }
        
        .validating {
            border-color: #fbbf24;
            animation: pulse-border 1.5s infinite;
        }
        
        @keyframes pulse-border {
            0% { border-color: #fbbf24; }
            50% { border-color: #f59e0b; }
            100% { border-color: #fbbf24; }
        }

        .info-producto-seleccionado {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 4px 8px;
            align-items: center;
        }
        
        .info-producto-seleccionado .etiqueta {
            color: #047857;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .info-producto-seleccionado .valor {
            color: #064e3b;
            font-weight: 600;
        }

        .categoria-titulo {
            background: #8b5cf6;
            color: white;
            padding: 8px 12px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #7c3aed;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .categoria-titulo i {
            margin-right: 8px;
            font-size: 14px;
        }
        
        .categoria-titulo .contador {
            background: rgba(255,255,255,0.2);
            padding: 2px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: normal;
        }

        .marca-badge {
            background: #8b5cf6;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            display: inline-block;
        }
    </style>
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
                    <div class="tooltip">
                        <button id="btnVerPausas" 
                                class="btn-pausas px-3 py-1 flex items-center relative">
                            <i class="fas fa-hourglass-half mr-1"></i>
                            <span>Pausadas</span>
                            <?php if ($total_pausadas > 0): ?>
                            <div class="pausas-count-badge" id="pausasCountHeader"><?php echo $total_pausadas; ?></div>
                            <?php endif; ?>
                        </button>
                        <span class="tooltiptext">Ver ventas pausadas</span>
                    </div>
                    
                    <div class="tooltip">
                        <button id="btnPausarVenta" 
                                class="btn-pausa px-4 py-2 flex items-center disabled:opacity-50 disabled:cursor-not-allowed" 
                                disabled
                                title="Pausar venta actual">
                            <i class="fas fa-pause mr-2"></i>
                            <span>Pausar</span>
                        </button>
                        <span class="tooltiptext">Pausar venta actual</span>
                    </div>
                    
                    <?php if ($venta_pausada): ?>
                    <div class="tooltip">
                        <button id="btnRestaurarVenta" 
                                class="btn-restaurar px-4 py-2 flex items-center"
                                title="Restaurar venta pausada">
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
                            
                            <!-- BUSCADOR ÚNICO INTELIGENTE -->
                            <div class="relative mb-2">
                                <input type="text" id="buscadorInteligente" 
                                       placeholder="Buscar: nombre, código, código barras, categoría o marca..."
                                       class="input-field w-full pl-8 pr-8 text-sm"
                                       autocomplete="off">
                                <div class="absolute left-2 top-1/2 transform -translate-y-1/2 text-gray-400">
                                    <i class="fas fa-search text-xs"></i>
                                </div>
                                <div class="absolute right-2 top-1/2 transform -translate-y-1/2 text-gray-400">
                                    <i class="fas fa-lightbulb text-xs text-yellow-500" title="Busca productos o categorías"></i>
                                </div>
                                
                                <!-- Resultados de búsqueda -->
                                <div id="resultadosBusqueda" class="absolute z-50 w-full mt-1 bg-white border rounded shadow-lg max-h-96 overflow-y-auto text-xs"></div>
                            </div>

                            <!-- Producto seleccionado (se muestra cuando se elige un producto) -->
                            <div id="productoSeleccionadoInfo" class="p-2 bg-green-50 border border-green-200 rounded hidden mb-3">
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs font-medium text-green-900">Producto seleccionado</span>
                                    <span class="badge">Listo</span>
                                </div>
                                <div class="info-producto-seleccionado">
                                    <span class="etiqueta">Nombre:</span>
                                    <span id="productoNombre" class="valor truncate"></span>
                                    
                                    <span class="etiqueta">Precio:</span>
                                    <span id="productoPrecio" class="valor"></span>
                                    
                                    <span class="etiqueta">Stock:</span>
                                    <span id="productoStock" class="valor"></span>
                                    
                                    <span class="etiqueta">Categoría:</span>
                                    <span id="productoCategoria" class="valor truncate"></span>
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
                <div class="card flex-1 flex flex-col <?php echo $venta_pausada ? 'venta-pausada-activa' : ''; ?>">
                    <div class="p-3 border-b bg-blue-50">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <i class="fas fa-credit-card icon-blue"></i>
                                <span class="font-medium text-gray-700 text-sm">Pago</span>
                            </div>
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
                                               class="input-field w-full text-sm moneda-input" placeholder="0">
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
                                    <input type="text" id="monto_efectivo_mixto" value="0" 
                                           class="input-field w-full text-sm moneda-input pago-mixto-input" placeholder="0">
                                    <div id="errorEfectivoMixto" class="error-message hidden"></div>
                                </div>
                                
                                <div>
                                    <label class="label-compact">Tarjeta</label>
                                    <input type="text" id="monto_tarjeta_mixto" value="0" 
                                           class="input-field w-full text-sm moneda-input pago-mixto-input" placeholder="0">
                                    <div id="errorTarjetaMixto" class="error-message hidden"></div>
                                </div>
                                
                                <div>
                                    <label class="label-compact">Transferencia</label>
                                    <input type="text" id="monto_transferencia_mixto" value="0" 
                                           class="input-field w-full text-sm moneda-input pago-mixto-input" placeholder="0">
                                    <div id="errorTransferenciaMixto" class="error-message hidden"></div>
                                </div>
                                
                                <div>
                                    <label class="label-compact">Otro</label>
                                    <input type="text" id="monto_otro_mixto" value="0" 
                                           class="input-field w-full text-sm moneda-input pago-mixto-input" placeholder="0">
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
    const MAX_CLICKS = 2;
    let procesarTimeout = null;
    let metodoPagoSeleccionado = '';
    let timeoutBusqueda = null;

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

    // ==================== FUNCIONES DE PAUSA ====================
    
    document.getElementById('btnVerPausas').addEventListener('click', cargarVentasPausadas);
    document.getElementById('closePausasModal').addEventListener('click', () => {
        document.getElementById('pausasModal').style.display = 'none';
    });
    
    document.getElementById('pausasModal').addEventListener('click', (e) => {
        if (e.target === document.getElementById('pausasModal')) {
            document.getElementById('pausasModal').style.display = 'none';
        }
    });
    
    function actualizarBotonPausa() {
        const btnPausar = document.getElementById('btnPausarVenta');
        btnPausar.disabled = productosAgregados.length === 0;
    }
    
    document.getElementById('btnPausarVenta').addEventListener('click', async function() {
        if (productosAgregados.length === 0) {
            mostrarNotificacion('Agrega productos al carrito antes de pausar', 'warning');
            return;
        }
        
        Swal.fire({
            title: '¿Pausar venta?',
            text: 'La venta actual se guardará para continuar más tarde',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#8b5cf6',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, pausar',
            cancelButtonText: 'Cancelar',
            showLoaderOnConfirm: true,
            preConfirm: async () => {
                try {
                    const datosVenta = {
                        cliente: {
                            id: document.getElementById('cliente_id_form').value,
                            nombre: document.getElementById('clienteNombre').textContent,
                            documento: document.getElementById('clienteDocumento').textContent
                        },
                        productos: productosAgregados,
                        totales: {
                            subtotal: desformatearNumero(document.getElementById('subtotal').textContent),
                            descuento: desformatearNumero(document.getElementById('descuento_form').value),
                            tipo_descuento: document.querySelector('input[name="tipo_descuento"]:checked').value,
                            impuesto: desformatearNumero(document.getElementById('impuesto').textContent),
                            total: desformatearNumero(document.getElementById('total').textContent)
                        },
                        pago: {
                            tipo_venta: tipoVenta,
                            metodo_pago: metodoPagoSeleccionado,
                            monto_recibido: desformatearNumero(document.getElementById('monto_recibido_form').value),
                            abono_inicial: desformatearNumero(document.getElementById('abono_inicial_form').value)
                        },
                        fecha: new Date().toISOString(),
                        numero_factura: document.querySelector('input[name="numero_factura"]').value
                    };
                    
                    if (metodoPagoSeleccionado === 'mixto') {
                        datosVenta.pago.mixto = {
                            efectivo: desformatearNumero(document.getElementById('monto_efectivo_mixto').value) || 0,
                            tarjeta: desformatearNumero(document.getElementById('monto_tarjeta_mixto').value) || 0,
                            transferencia: desformatearNumero(document.getElementById('monto_transferencia_mixto').value) || 0,
                            otro: desformatearNumero(document.getElementById('monto_otro_mixto').value) || 0
                        };
                    }
                    
                    if (tipoVenta === 'credito') {
                        datosVenta.pago.fecha_limite = document.getElementById('fecha_limite_form').value;
                        datosVenta.pago.usar_fecha_limite = document.getElementById('usar_fecha_limite').value;
                    }
                    
                    const formData = new FormData();
                    formData.append('accion', 'pausar_venta');
                    formData.append('datos_venta', JSON.stringify(datosVenta));
                    
                    const response = await fetch('gestion_pausas.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const contentType = response.headers.get("content-type");
                    if (!contentType || !contentType.includes("application/json")) {
                        const text = await response.text();
                        console.error('Respuesta no es JSON:', text.substring(0, 200));
                        throw new Error('Error en el servidor. Respuesta no válida.');
                    }
                    
                    const resultado = await response.json();
                    
                    if (resultado.success) {
                        return resultado;
                    } else {
                        throw new Error(resultado.error || 'Error al pausar la venta');
                    }
                    
                } catch (error) {
                    throw error;
                }
            }
        }).then((result) => {
            if (result.isConfirmed && result.value) {
                productosAgregados = [];
                productoSeleccionado = null;
                productoSeleccionadoEnCarrito = null;
                document.getElementById('cliente_id_form').value = '';
                document.getElementById('cliente_id').value = '';
                document.getElementById('infoCliente').classList.add('hidden');
                document.getElementById('clienteNombre').textContent = '';
                document.getElementById('clienteDocumento').textContent = '';
                
                document.querySelectorAll('.metodo-pago').forEach(btn => {
                    btn.classList.remove('selected');
                });
                metodoPagoSeleccionado = '';
                document.getElementById('metodo_pago_form').value = '';
                document.getElementById('metodo_pago_form_hidden').value = '';
                
                if (tipoVenta === 'credito') {
                    document.getElementById('toggleCredito').checked = false;
                    document.getElementById('panelCredito').classList.add('hidden');
                    document.getElementById('panelContado').classList.remove('hidden');
                    document.getElementById('btnLimpiarCredito').click();
                }
                
                document.getElementById('panelPagoMixto').classList.add('hidden');
                document.getElementById('btnLimpiarMixto').click();
                
                document.getElementById('descuento').value = '0';
                document.getElementById('monto_recibido').value = '';
                document.getElementById('observaciones').value = '';
                
                actualizarCarrito();
                actualizarTotales();
                actualizarBotonPausa();
                
                Swal.fire({
                    title: '¡Venta pausada!',
                    text: 'La venta se ha guardado correctamente',
                    icon: 'success',
                    confirmButtonColor: '#10b981',
                    confirmButtonText: 'Aceptar'
                });
                
                actualizarContadorPausas();
                
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                mostrarNotificacion('Pausa cancelada', 'info');
            }
        }).catch((error) => {
            console.error('Error:', error);
            Swal.fire({
                title: 'Error',
                text: error.message || 'Error al pausar la venta',
                icon: 'error',
                confirmButtonColor: '#ef4444',
                confirmButtonText: 'Entendido'
            });
        });
    });
    
    async function cargarVentasPausadas() {
        try {
            const response = await fetch('gestion_pausas.php?accion=listar_pausadas');
            
            const contentType = response.headers.get("content-type");
            if (!contentType || !contentType.includes("application/json")) {
                const text = await response.text();
                console.error('Respuesta no es JSON:', text.substring(0, 200));
                throw new Error('Error en el formato de respuesta del servidor');
            }
            
            const ventas = await response.json();
            
            const pausasList = document.getElementById('pausasList');
            
            if (ventas && ventas.length > 0) {
                let html = '';
                
                ventas.forEach(venta => {
                    try {
                        const datos = JSON.parse(venta.datos_venta);
                        const fecha = new Date(venta.fecha_pausa);
                        const fechaFormateada = fecha.toLocaleDateString('es-ES', {
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
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
                                        <div class="font-bold text-green-600 text-sm">$${formatearDecimal(datos.totales.total)}</div>
                                        <div class="text-xs text-gray-500 mt-1">
                                            ${datos.productos.length} productos
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="text-xs text-gray-600 mb-3">
                                    <div class="flex items-center space-x-2">
                                        <span class="font-medium">Cliente:</span>
                                        <span>${datos.cliente.nombre || 'Sin cliente'}</span>
                                    </div>
                                    <div class="flex items-center space-x-2 mt-1">
                                        <span class="font-medium">Tipo:</span>
                                        <span class="${datos.pago.tipo_venta === 'credito' ? 'text-blue-600' : 'text-green-600'}">
                                            ${datos.pago.tipo_venta === 'credito' ? 'Crédito' : 'Contado'}
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="pausas-actions">
                                    <button type="button" class="btn-resume" onclick="recuperarVentaPausada(${venta.id})">
                                        <i class="fas fa-play mr-1"></i> Continuar
                                    </button>
                                    <button type="button" class="btn-delete" onclick="eliminarVentaPausada(${venta.id})">
                                        <i class="fas fa-trash-alt mr-1"></i> Eliminar
                                    </button>
                                </div>
                            </div>
                        `;
                    } catch (error) {
                        console.error('Error procesando venta pausada:', error);
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
            
            document.getElementById('pausasModal').style.display = 'flex';
            
        } catch (error) {
            console.error('Error al cargar ventas pausadas:', error);
            mostrarNotificacion('Error al cargar ventas pausadas: ' + error.message, 'error');
        }
    }
    
    async function recuperarVentaPausada(pausaId) {
        if (productosAgregados.length > 0) {
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
            
            if (!result.isConfirmed) {
                return;
            }
        }
        
        try {
            const formData = new FormData();
            formData.append('accion', 'recuperar_pausada');
            formData.append('pausa_id', pausaId);
            
            const response = await fetch('gestion_pausas.php', {
                method: 'POST',
                body: formData
            });
            
            const contentType = response.headers.get("content-type");
            if (!contentType || !contentType.includes("application/json")) {
                const text = await response.text();
                console.error('Respuesta no es JSON:', text.substring(0, 200));
                throw new Error('Error en el formato de respuesta del servidor');
            }
            
            const resultado = await response.json();
            
            if (resultado.success) {
                const datos = resultado.datos;
                
                if (datos.cliente && datos.cliente.id) {
                    seleccionarCliente({
                        id: datos.cliente.id,
                        nombre: datos.cliente.nombre,
                        tipo_documento: datos.cliente.documento ? datos.cliente.documento.split(': ')[0] : 'CEDULA',
                        numero_documento: datos.cliente.documento ? datos.cliente.documento.split(': ')[1] : ''
                    });
                }
                
                productosAgregados = datos.productos.map(producto => ({
                    id: producto.id,
                    nombre: producto.nombre,
                    precio: producto.precio,
                    cantidad: producto.cantidad,
                    stock: producto.stock || 999,
                    codigo: producto.codigo || '',
                    codigo_barras: producto.codigo_barras || ''
                }));
                
                if (datos.pago.tipo_venta === 'credito') {
                    document.getElementById('toggleCredito').checked = true;
                    tipoVenta = 'credito';
                    document.getElementById('tipo_venta').value = 'credito';
                    document.getElementById('tipo_venta_form').value = 'credito';
                    document.getElementById('panelCredito').classList.remove('hidden');
                    document.getElementById('panelContado').classList.add('hidden');
                    
                    if (datos.pago.abono_inicial) {
                        document.getElementById('abono_inicial').value = formatearDecimal(datos.pago.abono_inicial);
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
                    tipoVenta = 'contado';
                    document.getElementById('tipo_venta').value = 'contado';
                    document.getElementById('tipo_venta_form').value = 'contado';
                    document.getElementById('panelCredito').classList.add('hidden');
                    document.getElementById('panelContado').classList.remove('hidden');
                }
                
                if (datos.pago.metodo_pago) {
                    metodoPagoSeleccionado = datos.pago.metodo_pago;
                    document.getElementById('metodo_pago_form').value = datos.pago.metodo_pago;
                    document.getElementById('metodo_pago_form_hidden').value = datos.pago.metodo_pago;
                    
                    document.querySelectorAll('.metodo-pago').forEach(btn => {
                        btn.classList.remove('selected');
                        if (btn.dataset.method === datos.pago.metodo_pago) {
                            btn.classList.add('selected');
                        }
                    });
                    
                    if (datos.pago.metodo_pago === 'mixto') {
                        document.getElementById('panelPagoMixto').classList.remove('hidden');
                        document.getElementById('panelContado').classList.add('hidden');
                        
                        if (datos.pago.mixto) {
                            document.getElementById('monto_efectivo_mixto').value = formatearDecimal(datos.pago.mixto.efectivo || 0);
                            document.getElementById('monto_tarjeta_mixto').value = formatearDecimal(datos.pago.mixto.tarjeta || 0);
                            document.getElementById('monto_transferencia_mixto').value = formatearDecimal(datos.pago.mixto.transferencia || 0);
                            document.getElementById('monto_otro_mixto').value = formatearDecimal(datos.pago.mixto.otro || 0);
                            
                            document.getElementById('monto_efectivo_mixto_form').value = datos.pago.mixto.efectivo || 0;
                            document.getElementById('monto_tarjeta_mixto_form').value = datos.pago.mixto.tarjeta || 0;
                            document.getElementById('monto_transferencia_mixto_form').value = datos.pago.mixto.transferencia || 0;
                            document.getElementById('monto_otro_mixto_form').value = datos.pago.mixto.otro || 0;
                        }
                    } else if (tipoVenta === 'contado') {
                        document.getElementById('panelPagoMixto').classList.add('hidden');
                        document.getElementById('panelContado').classList.remove('hidden');
                    }
                }
                
                if (datos.totales.tipo_descuento) {
                    document.querySelector(`input[name="tipo_descuento"][value="${datos.totales.tipo_descuento}"]`).checked = true;
                }
                if (datos.totales.descuento) {
                    document.getElementById('descuento').value = formatearDecimal(datos.totales.descuento);
                    document.getElementById('descuento_form').value = datos.totales.descuento;
                }
                
                if (datos.pago.monto_recibido && tipoVenta === 'contado' && metodoPagoSeleccionado !== 'mixto') {
                    document.getElementById('monto_recibido').value = formatearDecimal(datos.pago.monto_recibido);
                    document.getElementById('monto_recibido_form').value = datos.pago.monto_recibido;
                }
                
                actualizarCarrito();
                actualizarTotales();
                actualizarBotonPausa();
                validarFormulario();
                
                document.getElementById('pausasModal').style.display = 'none';
                
                mostrarNotificacion('Venta recuperada exitosamente', 'success');
                
                actualizarContadorPausas();
                
            } else {
                throw new Error(resultado.error || 'Error al recuperar la venta');
            }
            
        } catch (error) {
            console.error('Error:', error);
            mostrarNotificacion(error.message || 'Error al recuperar la venta', 'error');
        }
    }
    
    async function eliminarVentaPausada(pausaId) {
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
        
        if (!result.isConfirmed) {
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('accion', 'eliminar_pausada');
            formData.append('pausa_id', pausaId);
            
            const response = await fetch('gestion_pausas.php', {
                method: 'POST',
                body: formData
            });
            
            const contentType = response.headers.get("content-type");
            if (!contentType || !contentType.includes("application/json")) {
                const text = await response.text();
                console.error('Respuesta no es JSON:', text.substring(0, 200));
                throw new Error('Error en el formato de respuesta del servidor');
            }
            
            const resultado = await response.json();
            
            if (resultado.success) {
                document.querySelector(`.pausas-item[data-id="${pausaId}"]`)?.remove();
                
                const pausasList = document.getElementById('pausasList');
                const items = pausasList.querySelectorAll('.pausas-item');
                if (items.length === 0) {
                    pausasList.innerHTML = `
                        <div class="pausas-empty">
                            <i class="fas fa-hourglass-half text-4xl text-gray-300 mb-3"></i>
                            <p class="text-gray-500">No tienes ventas pausadas</p>
                            <p class="text-gray-400 text-sm mt-1">Las ventas que pausas aparecerán aquí</p>
                    </div>
                    `;
                }
                
                actualizarContadorPausas();
                
                mostrarNotificacion('Venta pausada eliminada', 'success');
            } else {
                throw new Error(resultado.error || 'Error al eliminar la venta');
            }
            
        } catch (error) {
            console.error('Error:', error);
            mostrarNotificacion(error.message || 'Error al eliminar la venta', 'error');
        }
    }
    
    async function actualizarContadorPausas() {
        try {
            const response = await fetch('gestion_pausas.php?accion=contar_pausadas');
            
            const contentType = response.headers.get("content-type");
            if (!contentType || !contentType.includes("application/json")) {
                console.warn('Respuesta no es JSON');
                return;
            }
            
            const text = await response.text();
            let resultado;
            
            try {
                resultado = JSON.parse(text);
            } catch (e) {
                console.warn('Error parseando JSON del contador:', e);
                return;
            }
            
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
            } else {
                if (badge) {
                    badge.remove();
                    document.getElementById('btnVerPausas').classList.remove('with-badge');
                }
            }
        } catch (error) {
            console.warn('Error al actualizar contador:', error);
        }
    }
    
    <?php if ($venta_pausada): ?>
    document.getElementById('btnRestaurarVenta').addEventListener('click', function() {
        preguntarRestaurarVenta();
    });
    
    function preguntarRestaurarVenta() {
        try {
            const datosVenta = JSON.parse('<?php echo addslashes($venta_pausada["datos_venta"]); ?>');
            
            Swal.fire({
                title: '¿Restaurar venta pausada?',
                html: `<div class="text-left">
                        <p>Hay una venta en pausa guardada de una sesión anterior.</p>
                        <div class="mt-3 text-sm">
                            <p><strong>Cliente:</strong> ${datosVenta.cliente ? datosVenta.cliente.nombre : 'No especificado'}</p>
                            <p><strong>Productos:</strong> ${datosVenta.productos ? datosVenta.productos.length : 0} items</p>
                            <p><strong>Total:</strong> $${formatearDecimal(datosVenta.totales ? datosVenta.totales.total : 0)}</p>
                        </div>
                        <p class="text-sm text-gray-500 mt-3">¿Deseas restaurar esta venta?</p>
                       </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#8b5cf6',
                cancelButtonColor: '#64748b',
                confirmButtonText: 'Sí, restaurar',
                cancelButtonText: 'No, empezar nueva',
                showLoaderOnConfirm: false,
                allowOutsideClick: false,
                allowEscapeKey: false
            }).then((result) => {
                if (result.isConfirmed) {
                    restaurarVentaDesdePHP(datosVenta);
                } else {
                    eliminarVentaPausadaPHP();
                }
            });
            
        } catch (error) {
            console.error('Error al procesar venta pausada:', error);
            eliminarVentaPausadaPHP();
        }
    }
    
    function restaurarVentaDesdePHP(datosVenta) {
        if (datosVenta.cliente && datosVenta.cliente.id) {
            seleccionarCliente({
                id: datosVenta.cliente.id,
                nombre: datosVenta.cliente.nombre,
                tipo_documento: datosVenta.cliente.documento ? datosVenta.cliente.documento.split(': ')[0] : 'CEDULA',
                numero_documento: datosVenta.cliente.documento ? datosVenta.cliente.documento.split(': ')[1] : ''
            });
        }
        
        if (datosVenta.productos) {
            productosAgregados = datosVenta.productos.map(producto => ({
                id: producto.id,
                nombre: producto.nombre,
                precio: producto.precio,
                cantidad: producto.cantidad,
                stock: producto.stock || 999,
                codigo: producto.codigo || '',
                codigo_barras: producto.codigo_barras || ''
            }));
        }
        
        if (datosVenta.pago && datosVenta.pago.tipo_venta === 'credito') {
            document.getElementById('toggleCredito').checked = true;
            tipoVenta = 'credito';
            document.getElementById('tipo_venta').value = 'credito';
            document.getElementById('tipo_venta_form').value = 'credito';
            document.getElementById('panelCredito').classList.remove('hidden');
            document.getElementById('panelContado').classList.add('hidden');
            
            if (datosVenta.pago.abono_inicial) {
                document.getElementById('abono_inicial').value = formatearDecimal(datosVenta.pago.abono_inicial);
                document.getElementById('abono_inicial_form').value = datosVenta.pago.abono_inicial;
            }
            
            if (datosVenta.pago.fecha_limite) {
                document.getElementById('chkFechaLimite').checked = true;
                document.getElementById('fechaLimiteContainer').classList.remove('hidden');
                document.getElementById('fecha_limite').value = datosVenta.pago.fecha_limite;
                document.getElementById('fecha_limite_form').value = datosVenta.pago.fecha_limite;
                document.getElementById('usar_fecha_limite').value = datosVenta.pago.usar_fecha_limite || '0';
            }
        }
        
        if (datosVenta.pago && datosVenta.pago.metodo_pago) {
            metodoPagoSeleccionado = datosVenta.pago.metodo_pago;
            document.getElementById('metodo_pago_form').value = datosVenta.pago.metodo_pago;
            document.getElementById('metodo_pago_form_hidden').value = datosVenta.pago.metodo_pago;
            
            document.querySelectorAll('.metodo-pago').forEach(btn => {
                btn.classList.remove('selected');
                if (btn.dataset.method === datosVenta.pago.metodo_pago) {
                    btn.classList.add('selected');
                }
            });
            
            if (datosVenta.pago.metodo_pago === 'mixto') {
                document.getElementById('panelPagoMixto').classList.remove('hidden');
                document.getElementById('panelContado').classList.add('hidden');
                
                if (datosVenta.pago.mixto) {
                    document.getElementById('monto_efectivo_mixto').value = formatearDecimal(datosVenta.pago.mixto.efectivo || 0);
                    document.getElementById('monto_tarjeta_mixto').value = formatearDecimal(datosVenta.pago.mixto.tarjeta || 0);
                    document.getElementById('monto_transferencia_mixto').value = formatearDecimal(datosVenta.pago.mixto.transferencia || 0);
                    document.getElementById('monto_otro_mixto').value = formatearDecimal(datosVenta.pago.mixto.otro || 0);
                    
                    document.getElementById('monto_efectivo_mixto_form').value = datosVenta.pago.mixto.efectivo || 0;
                    document.getElementById('monto_tarjeta_mixto_form').value = datosVenta.pago.mixto.tarjeta || 0;
                    document.getElementById('monto_transferencia_mixto_form').value = datosVenta.pago.mixto.transferencia || 0;
                    document.getElementById('monto_otro_mixto_form').value = datosVenta.pago.mixto.otro || 0;
                }
            }
        }
        
        if (datosVenta.totales && datosVenta.totales.descuento) {
            document.getElementById('descuento').value = formatearDecimal(datosVenta.totales.descuento);
            document.getElementById('descuento_form').value = datosVenta.totales.descuento;
        }
        
        if (datosVenta.observaciones) {
            document.getElementById('observaciones').value = datosVenta.observaciones;
            document.getElementById('observaciones_form').value = datosVenta.observaciones;
        }
        
        if (datosVenta.pago && datosVenta.pago.monto_recibido && tipoVenta === 'contado' && metodoPagoSeleccionado !== 'mixto') {
            document.getElementById('monto_recibido').value = formatearDecimal(datosVenta.pago.monto_recibido);
            document.getElementById('monto_recibido_form').value = datosVenta.pago.monto_recibido;
        }
        
        actualizarCarrito();
        actualizarTotales();
        actualizarBotonPausa();
        validarFormulario();
        
        mostrarNotificacion('Venta pausada restaurada', 'success');
        
        eliminarVentaPausadaPHP();
    }
    
    function eliminarVentaPausadaPHP() {
        fetch('gestion_pausas.php', {
            method: 'POST',
            body: new URLSearchParams({
                accion: 'eliminar_pausada',
                pausa_id: '<?php echo $venta_pausada_id; ?>'
            })
        }).then(() => {
            actualizarContadorPausas();
            document.querySelector('.badge-pausada')?.remove();
            document.querySelectorAll('.venta-pausada-activa').forEach(el => {
                el.classList.remove('venta-pausada-activa');
            });
        }).catch(error => {
            console.error('Error al eliminar venta pausada:', error);
        });
    }
    <?php endif; ?>
    
    // ==================== BUSCADOR INTELIGENTE ====================

    // Buscador inteligente
    document.getElementById('buscadorInteligente').addEventListener('input', function() {
        const query = this.value.trim();
        
        if (timeoutBusqueda) {
            clearTimeout(timeoutBusqueda);
        }
        
        if (query.length < 2) {
            document.getElementById('resultadosBusqueda').classList.add('hidden');
            return;
        }
        
        timeoutBusqueda = setTimeout(() => {
            buscarInteligente(query);
        }, 300);
    });

    // Detectar Enter para búsqueda rápida
    document.getElementById('buscadorInteligente').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const query = this.value.trim();
            
            if (query.length > 0) {
                buscarInteligente(query, true);
            }
        }
    });

    // Función principal de búsqueda inteligente
    async function buscarInteligente(query, modoExacto = false) {
        try {
            const response = await fetch(`buscar_producto.php?q=${encodeURIComponent(query)}`);
            const contentType = response.headers.get("content-type");
            
            if (contentType && contentType.includes("application/json")) {
                const data = await response.json();
                
                const resultadosDiv = document.getElementById('resultadosBusqueda');
                resultadosDiv.innerHTML = '';
                
                let tieneResultados = false;
                
                // ===== SECCIÓN 1: Mostrar categorías encontradas =====
                if (data.categorias && data.categorias.length > 0) {
                    tieneResultados = true;
                    
                    const tituloCategorias = document.createElement('div');
                    tituloCategorias.className = 'p-2 bg-purple-50 border-b border-purple-200 font-medium text-purple-800 text-xs sticky top-0';
                    tituloCategorias.innerHTML = `
                        <div class="flex items-center">
                            <i class="fas fa-tags mr-1"></i> Categorías (${data.categorias.length})
                        </div>
                    `;
                    resultadosDiv.appendChild(tituloCategorias);
                    
                    data.categorias.forEach(categoria => {
                        const div = document.createElement('div');
                        div.className = 'p-2 hover:bg-purple-50 cursor-pointer border-b flex items-center justify-between';
                        div.innerHTML = `
                            <div class="flex items-center">
                                <i class="fas fa-folder text-purple-500 mr-2"></i>
                                <span class="font-medium">${categoria.nombre}</span>
                            </div>
                            <span class="text-purple-600 text-xs bg-purple-100 px-2 py-0.5 rounded">
                                <i class="fas fa-eye mr-1"></i>Ver productos
                            </span>
                        `;
                        
                        div.addEventListener('click', () => {
                            // Cargar productos de la categoría y mostrarlos
                            cargarProductosPorCategoria(categoria.id, categoria.nombre);
                        });
                        
                        resultadosDiv.appendChild(div);
                    });
                }
                
                // ===== SECCIÓN 2: Mostrar productos encontrados =====
                if (data.productos && data.productos.length > 0) {
                    tieneResultados = true;
                    
                    // Si es modo exacto y solo hay un producto, agregarlo automáticamente
                    if (modoExacto && data.productos.length === 1 && (!data.categorias || data.categorias.length === 0)) {
                        const producto = data.productos[0];
                        const cantidad = parseInt(document.getElementById('cantidadProducto').value) || 1;
                        
                        if (cantidad <= producto.stock) {
                            seleccionarProducto(producto);
                            agregarProductoAlCarrito(producto, cantidad);
                            document.getElementById('buscadorInteligente').value = '';
                            document.getElementById('resultadosBusqueda').classList.add('hidden');
                            mostrarNotificacion(`Producto agregado: ${producto.nombre}`, 'success');
                            return;
                        } else {
                            mostrarNotificacion(`Stock insuficiente. Disponible: ${producto.stock}`, 'error');
                        }
                    }
                    
                    const tituloProductos = document.createElement('div');
                    tituloProductos.className = 'p-2 bg-blue-50 border-b border-blue-200 font-medium text-blue-800 text-xs sticky top-0';
                    
                    // Ajustar posición sticky
                    let topOffset = 0;
                    if (data.categorias && data.categorias.length > 0) topOffset += 32;
                    tituloProductos.style.top = topOffset + 'px';
                    
                    tituloProductos.innerHTML = `
                        <div class="flex items-center">
                            <i class="fas fa-box mr-1"></i> Productos (${data.productos.length})
                        </div>
                    `;
                    resultadosDiv.appendChild(tituloProductos);
                    
                    data.productos.forEach(producto => {
                        const precio = formatearDecimal(parseFloat(producto.precio_venta));
                        const stockColor = producto.stock <= 5 ? 'text-red-600' : 'text-green-600';
                        const stockIcon = producto.stock <= 5 ? 'fa-exclamation-triangle' : 'fa-check-circle';
                        
                        let badges = '';
                        if (producto.marca_nombre) {
                            badges += `<span class="bg-purple-100 text-purple-800 px-1 py-0.5 rounded text-xs mr-1"><i class="fas fa-copyright mr-0.5"></i>${producto.marca_nombre}</span>`;
                        }
                        if (producto.talla) {
                            badges += `<span class="bg-blue-100 text-blue-800 px-1 py-0.5 rounded text-xs mr-1"><i class="fas fa-ruler mr-0.5"></i>T:${producto.talla}</span>`;
                        }
                        if (producto.color) {
                            badges += `<span class="bg-pink-100 text-pink-800 px-1 py-0.5 rounded text-xs mr-1"><i class="fas fa-palette mr-0.5"></i>${producto.color}</span>`;
                        }
                        
                        const div = document.createElement('div');
                        div.className = 'p-2 hover:bg-green-50 cursor-pointer border-b';
                        div.setAttribute('data-producto-id', producto.id);
                        div.innerHTML = `
                            <div class="flex items-start">
                                <div class="flex-1">
                                    <div class="font-bold text-gray-900 text-sm">${producto.nombre}</div>
                                    <div class="flex flex-wrap items-center gap-1 mt-1">
                                        ${badges}
                                        ${producto.categoria_nombre ? 
                                            `<span class="bg-green-100 text-green-800 px-1 py-0.5 rounded text-xs"><i class="fas fa-tag mr-0.5"></i>${producto.categoria_nombre}</span>` : ''}
                                    </div>
                                    <div class="flex justify-between items-center mt-2">
                                        <div class="flex items-center space-x-3">
                                            <span class="font-bold text-green-700">$${precio}</span>
                                            <span class="${stockColor} flex items-center text-xs">
                                                <i class="fas ${stockIcon} mr-1"></i>
                                                Stock: ${producto.stock}
                                            </span>
                                        </div>
                                        <span class="text-blue-600 text-xs hover:underline">Seleccionar →</span>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        div.addEventListener('click', () => {
                            seleccionarProducto(producto);
                            document.getElementById('buscadorInteligente').value = '';
                            document.getElementById('resultadosBusqueda').classList.add('hidden');
                            document.getElementById('cantidadProducto').focus();
                        });
                        
                        resultadosDiv.appendChild(div);
                    });
                }
                
                // ===== SECCIÓN 3: Sin resultados =====
                if (!tieneResultados) {
                    resultadosDiv.innerHTML = `
                        <div class="p-4 text-center text-gray-500">
                            <i class="fas fa-search text-2xl text-gray-300 mb-2"></i>
                            <p class="text-xs">No se encontraron productos ni categorías</p>
                            <p class="text-xs text-gray-400 mt-1">Prueba con otros términos</p>
                        </div>
                    `;
                }
                
                resultadosDiv.classList.remove('hidden');
            }
        } catch (error) {
            console.error('Error:', error);
            mostrarNotificacion('Error en la búsqueda', 'error');
        }
    }

    // Función para cargar productos por categoría (CORREGIDA)
    async function cargarProductosPorCategoria(categoriaId, categoriaNombre) {
        try {
            const resultadosDiv = document.getElementById('resultadosBusqueda');
            
            // Mostrar loading
            resultadosDiv.innerHTML = `
                <div class="p-4 text-center">
                    <div class="spinner mx-auto mb-2"></div>
                    <p class="text-xs text-gray-500">Cargando productos de ${categoriaNombre}...</p>
                </div>
            `;
            resultadosDiv.classList.remove('hidden');
            
            // Hacer la petición
            const response = await fetch(`buscar_producto.php?categoria_id=${categoriaId}`);
            const contentType = response.headers.get("content-type");
            
            if (contentType && contentType.includes("application/json")) {
                const data = await response.json();
                
                resultadosDiv.innerHTML = '';
                
                if (data.productos && data.productos.length > 0) {
                    // Título de la categoría con estilo destacado
                    const tituloCategoria = document.createElement('div');
                    tituloCategoria.className = 'categoria-titulo sticky top-0';
                    tituloCategoria.innerHTML = `
                        <div class="flex items-center">
                            <i class="fas fa-folder-open mr-2"></i>
                            <span>${categoriaNombre}</span>
                        </div>
                        <span class="contador">${data.productos.length} productos</span>
                    `;
                    resultadosDiv.appendChild(tituloCategoria);
                    
                    // Mostrar productos
                    data.productos.forEach(producto => {
                        const precio = formatearDecimal(parseFloat(producto.precio_venta));
                        const stockColor = producto.stock <= 5 ? 'text-red-600' : 'text-green-600';
                        const stockIcon = producto.stock <= 5 ? 'fa-exclamation-triangle' : 'fa-check-circle';
                        
                        let badges = '';
                        if (producto.marca_nombre) {
                            badges += `<span class="bg-purple-100 text-purple-800 px-1 py-0.5 rounded text-xs mr-1"><i class="fas fa-copyright mr-0.5"></i>${producto.marca_nombre}</span>`;
                        }
                        if (producto.talla) {
                            badges += `<span class="bg-blue-100 text-blue-800 px-1 py-0.5 rounded text-xs mr-1"><i class="fas fa-ruler mr-0.5"></i>T:${producto.talla}</span>`;
                        }
                        if (producto.color) {
                            badges += `<span class="bg-pink-100 text-pink-800 px-1 py-0.5 rounded text-xs mr-1"><i class="fas fa-palette mr-0.5"></i>${producto.color}</span>`;
                        }
                        
                        const div = document.createElement('div');
                        div.className = 'p-2 hover:bg-green-50 cursor-pointer border-b';
                        div.setAttribute('data-producto-id', producto.id);
                        div.innerHTML = `
                            <div class="flex items-start">
                                <div class="flex-1">
                                    <div class="font-bold text-gray-900 text-sm">${producto.nombre}</div>
                                    <div class="flex flex-wrap items-center gap-1 mt-1">
                                        ${badges}
                                    </div>
                                    <div class="flex justify-between items-center mt-2">
                                        <div class="flex items-center space-x-3">
                                            <span class="font-bold text-green-700">$${precio}</span>
                                            <span class="${stockColor} flex items-center text-xs">
                                                <i class="fas ${stockIcon} mr-1"></i>
                                                Stock: ${producto.stock}
                                            </span>
                                        </div>
                                        <span class="text-blue-600 text-xs hover:underline">Seleccionar →</span>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        div.addEventListener('click', () => {
                            seleccionarProducto(producto);
                            document.getElementById('buscadorInteligente').value = '';
                            document.getElementById('resultadosBusqueda').classList.add('hidden');
                            document.getElementById('cantidadProducto').focus();
                        });
                        
                        resultadosDiv.appendChild(div);
                    });
                } else {
                    resultadosDiv.innerHTML = `
                        <div class="p-4 text-center text-gray-500">
                            <i class="fas fa-folder-open text-2xl text-gray-300 mb-2"></i>
                            <p class="text-xs">No hay productos en la categoría ${categoriaNombre}</p>
                            <button onclick="document.getElementById('buscadorInteligente').focus()" class="mt-2 text-xs text-purple-600 hover:text-purple-800">
                                <i class="fas fa-search mr-1"></i>Buscar otros productos
                            </button>
                        </div>
                    `;
                }
            }
        } catch (error) {
            console.error('Error:', error);
            mostrarNotificacion('Error al cargar productos de la categoría', 'error');
        }
    }

    // Cerrar resultados al hacer clic fuera
    document.addEventListener('click', function(e) {
        const buscador = document.getElementById('buscadorInteligente');
        const resultados = document.getElementById('resultadosBusqueda');
        
        if (!buscador.contains(e.target) && !resultados.contains(e.target)) {
            resultados.classList.add('hidden');
        }
    });

    // ==================== FUNCIONES EXISTENTES ====================
    
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

    function formatearNumero(numero) {
        return new Intl.NumberFormat('es-CO', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0
        }).format(numero);
    }

    function formatearDecimal(numero) {
        if (isNaN(numero) || numero === 0) return '0';
        return new Intl.NumberFormat('es-CO', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(numero);
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

    function formatearNumeroMiles(numeroStr) {
        if (!numeroStr) return '';
        
        let limpio = numeroStr.replace(/\./g, '');
        
        if (limpio === '') return '';
        
        let partes = limpio.split(',');
        let parteEntera = partes[0];
        let parteDecimal = partes.length > 1 ? ',' + partes[1] : '';
        
        if (parteDecimal.length > 3) {
            parteDecimal = parteDecimal.substring(0, 3);
        }
        
        if (parteEntera.length > 3) {
            let formateado = '';
            let contador = 0;
            
            for (let i = parteEntera.length - 1; i >= 0; i--) {
                formateado = parteEntera[i] + formateado;
                contador++;
                if (contador === 3 && i > 0) {
                    formateado = '.' + formateado;
                    contador = 0;
                }
            }
            parteEntera = formateado;
        }
        
        return parteEntera + parteDecimal;
    }

    function manejarInputMoneda(input) {
        const valorOriginal = input.value;
        const cursorPos = input.selectionStart;
        
        let antesDelCursor = valorOriginal.substring(0, cursorPos);
        let digitosAntes = antesDelCursor.replace(/[^\d]/g, '').length;
        
        let nuevoValor = valorOriginal.replace(/[^\d,]/g, '');
        
        nuevoValor = nuevoValor.replace(/\./g, '');
        
        const partes = nuevoValor.split(',');
        if (partes.length > 2) {
            nuevoValor = partes[0] + ',' + partes.slice(1).join('');
        }
        
        if (nuevoValor.includes(',')) {
            const partesDecimal = nuevoValor.split(',');
            if (partesDecimal[1] && partesDecimal[1].length > 2) {
                nuevoValor = partesDecimal[0] + ',' + partesDecimal[1].substring(0, 2);
            }
        }
        
        const valorFormateado = formatearNumeroMiles(nuevoValor);
        
        input.value = valorFormateado;
        
        let nuevoCursorPos = 0;
        let digitosContados = 0;
        
        for (let i = 0; i < valorFormateado.length; i++) {
            if (/\d/.test(valorFormateado[i])) {
                digitosContados++;
            }
            if (digitosContados === digitosAntes) {
                nuevoCursorPos = i + 1;
                while (nuevoCursorPos < valorFormateado.length && valorFormateado[nuevoCursorPos] === '.') {
                    nuevoCursorPos++;
                }
                break;
            }
        }
        
        if (nuevoCursorPos === 0) {
            nuevoCursorPos = valorFormateado.length;
        }
        
        input.setSelectionRange(nuevoCursorPos, nuevoCursorPos);
        
        return desformatearNumero(valorFormateado);
    }

    function setupInputMonedaNuevoSistema(inputId) {
        const input = document.getElementById(inputId);
        const formField = document.getElementById(`${inputId}_form`);
        
        if (input.value && input.value !== '0') {
            const valor = desformatearNumero(input.value);
            input.value = formatearDecimal(valor);
            if (formField) formField.value = valor;
        }
        
        input.addEventListener('focus', function() {
            this.classList.remove('input-error', 'input-success');
            if (this.value === '0' || this.value === '') {
                this.value = '';
                if (formField) formField.value = 0;
            }
        });
        
        input.addEventListener('blur', function() {
            const valor = desformatearNumero(this.value);
            if (this.value.trim() === '' || valor === 0) {
                this.value = '0';
                if (formField) formField.value = 0;
            } else {
                this.value = formatearDecimal(valor);
                if (formField) formField.value = valor;
            }
            validarCampoMoneda(this, valor);
        });
        
        input.addEventListener('input', function(e) {
            const valor = manejarInputMoneda(this);
            
            if (formField) {
                formField.value = valor;
            }
            
            validarCampoMoneda(this, valor);
            
            actualizarTotales();
        });
    }

    function setupInputsPagoMixtoNuevo() {
        const inputsMixto = [
            'monto_efectivo_mixto',
            'monto_tarjeta_mixto',
            'monto_transferencia_mixto',
            'monto_otro_mixto'
        ];
        
        inputsMixto.forEach(inputId => {
            setupInputMonedaNuevoSistema(inputId);
        });
    }

    function validarCampoMoneda(inputElement, valor) {
        const inputId = inputElement.id;
        const errorElement = document.getElementById(`error${inputId.charAt(0).toUpperCase() + inputId.slice(1)}`);
        
        if (inputId === 'descuento') {
            const tipoDescuento = document.querySelector('input[name="tipo_descuento"]:checked').value;
            const subtotal = productosAgregados.reduce((sum, p) => sum + (p.precio * p.cantidad), 0);
            
            if (tipoDescuento === 'porcentaje' && (valor < 0 || valor > 100)) {
                inputElement.classList.add('input-error');
                inputElement.classList.remove('input-success');
                if (errorElement) {
                    errorElement.textContent = 'El descuento debe estar entre 0% y 100%';
                    errorElement.classList.remove('hidden');
                }
            } else if (tipoDescuento === 'monto' && valor > subtotal) {
                inputElement.classList.add('input-error');
                inputElement.classList.remove('input-success');
                if (errorElement) {
                    errorElement.textContent = 'El descuento no puede ser mayor al subtotal';
                    errorElement.classList.remove('hidden');
                }
            } else if (valor < 0) {
                inputElement.classList.add('input-error');
                inputElement.classList.remove('input-success');
                if (errorElement) {
                    errorElement.textContent = 'El valor no puede ser negativo';
                    errorElement.classList.remove('hidden');
                }
            } else {
                inputElement.classList.remove('input-error');
                inputElement.classList.add('input-success');
                if (errorElement) errorElement.classList.add('hidden');
            }
        } else if (inputId === 'monto_recibido') {
            const total = desformatearNumero(document.getElementById('total').textContent);
            
            if (valor < total) {
                inputElement.classList.add('input-error');
                inputElement.classList.remove('input-success');
                if (errorElement) {
                    errorElement.textContent = 'Monto insuficiente para cubrir el total';
                    errorElement.classList.remove('hidden');
                }
            } else if (valor < 0) {
                inputElement.classList.add('input-error');
                inputElement.classList.remove('input-success');
                if (errorElement) {
                    errorElement.textContent = 'El monto no puede ser negativo';
                    errorElement.classList.remove('hidden');
                }
            } else {
                inputElement.classList.remove('input-error');
                inputElement.classList.add('input-success');
                if (errorElement) errorElement.classList.add('hidden');
            }
        } else if (inputId.includes('mixto')) {
            if (valor < 0) {
                inputElement.classList.add('input-error');
                inputElement.classList.remove('input-success');
                const campo = inputId.split('_')[1];
                const errorId = `error${campo.charAt(0).toUpperCase() + campo.slice(1)}Mixto`;
                const errorEl = document.getElementById(errorId);
                if (errorEl) {
                    errorEl.textContent = 'El monto no puede ser negativo';
                    errorEl.classList.remove('hidden');
                }
            } else {
                inputElement.classList.remove('input-error');
                inputElement.classList.add('input-success');
                const campo = inputId.split('_')[1];
                const errorId = `error${campo.charAt(0).toUpperCase() + campo.slice(1)}Mixto`;
                const errorEl = document.getElementById(errorId);
                if (errorEl) errorEl.classList.add('hidden');
            }
        } else if (inputId === 'abono_inicial') {
            const total = desformatearNumero(document.getElementById('total').textContent);
            
            if (valor > total) {
                inputElement.classList.add('input-error');
                inputElement.classList.remove('input-success');
                if (errorElement) {
                    errorElement.textContent = 'El abono no puede ser mayor al total';
                    errorElement.classList.remove('hidden');
                }
            } else if (valor < 0) {
                inputElement.classList.add('input-error');
                inputElement.classList.remove('input-success');
                if (errorElement) {
                    errorElement.textContent = 'El abono no puede ser negativo';
                    errorElement.classList.remove('hidden');
                }
            } else {
                inputElement.classList.remove('input-error');
                inputElement.classList.add('input-success');
                if (errorElement) errorElement.classList.add('hidden');
            }
        }
    }

    document.getElementById('toggleCredito').addEventListener('change', function() {
        if (isProcessing) {
            this.checked = !this.checked;
            return;
        }
        
        tipoVenta = this.checked ? 'credito' : 'contado';
        document.getElementById('tipo_venta').value = tipoVenta;
        document.getElementById('tipo_venta_form').value = tipoVenta;
        
        if (tipoVenta === 'credito') {
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
            
            if (metodoPagoSeleccionado === 'mixto') {
                document.getElementById('panelPagoMixto').classList.add('hidden');
            }
            
            mostrarNotificacion('Modo crédito activado', 'info');
        } else {
            document.getElementById('panelCredito').classList.add('hidden');
            document.getElementById('panelContado').classList.remove('hidden');
            
            document.getElementById('abono_inicial').value = '0';
            document.getElementById('abono_inicial_form').value = '0';
            document.getElementById('infoDeuda').classList.add('hidden');
            
            if (metodoPagoSeleccionado === 'mixto') {
                document.getElementById('panelPagoMixto').classList.remove('hidden');
            }
            
            mostrarNotificacion('Modo contado activado', 'info');
        }
        
        validarFormulario();
        actualizarTotales();
    });

    document.getElementById('chkFechaLimite').addEventListener('change', function() {
        const fechaContainer = document.getElementById('fechaLimiteContainer');
        const fechaInput = document.getElementById('fecha_limite');
        const usarFechaLimiteInput = document.getElementById('usar_fecha_limite');
        
        if (this.checked) {
            fechaContainer.classList.remove('hidden');
            fechaInput.disabled = false;
            fechaInput.classList.remove('input-disabled');
            usarFechaLimiteInput.value = '1';
            
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

    document.getElementById('btnLimpiarMixto').addEventListener('click', function() {
        document.getElementById('monto_efectivo_mixto').value = '0';
        document.getElementById('monto_tarjeta_mixto').value = '0';
        document.getElementById('monto_transferencia_mixto').value = '0';
        document.getElementById('monto_otro_mixto').value = '0';
        
        document.getElementById('monto_efectivo_mixto_form').value = '0';
        document.getElementById('monto_tarjeta_mixto_form').value = '0';
        document.getElementById('monto_transferencia_mixto_form').value = '0';
        document.getElementById('monto_otro_mixto_form').value = '0';
        
        ['monto_efectivo_mixto', 'monto_tarjeta_mixto', 'monto_transferencia_mixto', 'monto_otro_mixto'].forEach(id => {
            const input = document.getElementById(id);
            input.classList.remove('input-error', 'input-success');
        });
        
        ['errorEfectivoMixto', 'errorTarjetaMixto', 'errorTransferenciaMixto', 'errorOtroMixto'].forEach(id => {
            const errorEl = document.getElementById(id);
            if (errorEl) errorEl.classList.add('hidden');
        });
        
        actualizarTotales();
    });

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
            
            if (metodo === 'mixto') {
                document.getElementById('panelPagoMixto').classList.remove('hidden');
                document.getElementById('panelContado').classList.add('hidden');
            } else {
                document.getElementById('panelPagoMixto').classList.add('hidden');
                if (tipoVenta === 'contado') {
                    document.getElementById('panelContado').classList.remove('hidden');
                }
            }
            
            validarFormulario();
        });
    });

    setupInputMonedaNuevoSistema('abono_inicial');
    setupInputMonedaNuevoSistema('monto_recibido');
    setupInputMonedaNuevoSistema('descuento');
    setupInputsPagoMixtoNuevo();

    document.getElementById('fecha_limite').addEventListener('change', function() {
        document.getElementById('fecha_limite_form').value = this.value;
    });

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

    document.getElementById('btnNuevoCliente').addEventListener('click', function() {
        mostrarModalNuevoCliente();
    });

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
                
                seleccionarCliente({
                    id: cliente.cliente_id,
                    nombre: cliente.nombre,
                    tipo_documento: cliente.tipo_documento,
                    numero_documento: cliente.numero_documento
                });
                
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

    function seleccionarProducto(producto) {
        productoSeleccionado = producto;
        
        document.getElementById('productoNombre').textContent = producto.nombre;
        document.getElementById('productoPrecio').textContent = `$${formatearDecimal(parseFloat(producto.precio_venta))}`;
        document.getElementById('productoStock').textContent = producto.stock;
        document.getElementById('productoCategoria').textContent = producto.categoria_nombre || 'Sin categoría';
        document.getElementById('productoSeleccionadoInfo').classList.remove('hidden');
        
        document.getElementById('cantidadProducto').max = producto.stock;
        document.getElementById('cantidadProducto').value = 1;
    }

    document.getElementById('agregarProducto').addEventListener('click', function() {
        if (!productoSeleccionado) {
            mostrarNotificacion('Primero busca y selecciona un producto', 'warning');
            document.getElementById('buscadorInteligente').focus();
            return;
        }
        
        const cantidad = parseInt(document.getElementById('cantidadProducto').value);
        agregarProductoAlCarrito(productoSeleccionado, cantidad);
    });

    function agregarProductoAlCarrito(producto, cantidad) {
        if (cantidad > producto.stock) {
            mostrarNotificacion(`Stock insuficiente. Disponible: ${producto.stock}`, 'error');
            return;
        }
        
        const index = productosAgregados.findIndex(p => p.id == producto.id);
        if (index > -1) {
            const nuevaCantidad = productosAgregados[index].cantidad + cantidad;
            if (nuevaCantidad > producto.stock) {
                mostrarNotificacion(`Stock insuficiente. Máximo: ${producto.stock}`, 'error');
                return;
            }
            productosAgregados[index].cantidad = nuevaCantidad;
            mostrarNotificacion(`Cantidad actualizada: ${nuevaCantidad} unidades`, 'success');
        } else {
            productosAgregados.push({
                id: producto.id,
                nombre: producto.nombre,
                precio: parseFloat(producto.precio_venta),
                cantidad: cantidad,
                stock: producto.stock,
                codigo: producto.codigo,
                codigo_barras: producto.codigo_barras,
                talla: producto.talla,
                color: producto.color,
                marca_nombre: producto.marca_nombre,
                categoria_nombre: producto.categoria_nombre
            });
            mostrarNotificacion('Producto agregado al carrito', 'success');
        }
        
        actualizarCarrito();
        actualizarTotales();
        actualizarBotonPausa();
        
        productoSeleccionado = null;
        document.getElementById('productoSeleccionadoInfo').classList.add('hidden');
        document.getElementById('cantidadProducto').value = 1;
        document.getElementById('buscadorInteligente').focus();
    }

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
            
            let atributosHTML = '';
            if (producto.talla) {
                atributosHTML += `<span class="inline-block bg-blue-100 text-blue-800 text-xs px-1 rounded mr-1">T:${producto.talla}</span>`;
            }
            if (producto.color) {
                atributosHTML += `<span class="inline-block bg-pink-100 text-pink-800 text-xs px-1 rounded">C:${producto.color}</span>`;
            }
            if (producto.categoria_nombre) {
                atributosHTML += `<span class="inline-block bg-green-100 text-green-800 text-xs px-1 rounded ml-1">${producto.categoria_nombre}</span>`;
            }
            
            html += `
                <div class="product-item p-2 ${esSeleccionado ? 'bg-blue-50' : ''} cursor-pointer fade-in" 
                     onclick="seleccionarProductoCarrito(${index})">
                    <div class="flex justify-between items-center">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 text-xs truncate">${producto.nombre}</div>
                            <div class="flex items-center mt-1">
                                ${atributosHTML}
                            </div>
                            <div class="text-gray-500 text-xs mt-1">
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
        actualizarBotonPausa();
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
                actualizarBotonPausa();
                mostrarNotificacion('Carrito vaciado', 'success');
            }
        });
    }

    function calcularSumaPagosMixtos() {
        const efectivo = desformatearNumero(document.getElementById('monto_efectivo_mixto').value) || 0;
        const tarjeta = desformatearNumero(document.getElementById('monto_tarjeta_mixto').value) || 0;
        const transferencia = desformatearNumero(document.getElementById('monto_transferencia_mixto').value) || 0;
        const otro = desformatearNumero(document.getElementById('monto_otro_mixto').value) || 0;
        
        return efectivo + tarjeta + transferencia + otro;
    }

    function validarSumaPagosMixtos(totalVenta) {
        const sumaPagos = calcularSumaPagosMixtos();
        const errorElement = document.getElementById('errorSumaPagos');
        const successElement = document.getElementById('successSumaPagos');
        const sumaElement = document.getElementById('sumaPagosMixtos');
        const totalElement = document.getElementById('totalCompararMixto');
        
        sumaElement.textContent = `$${formatearDecimal(sumaPagos)}`;
        totalElement.textContent = `$${formatearDecimal(totalVenta)}`;
        
        const diferencia = Math.abs(sumaPagos - totalVenta);
        const tolerancia = 0.01;
        
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
        
        document.getElementById('subtotal').textContent = `$${formatearDecimal(subtotal)}`;
        document.getElementById('descuentoTotal').textContent = `$${formatearDecimal(descuentoTotal)}`;
        document.getElementById('impuesto').textContent = `$${formatearDecimal(impuestoTotal)}`;
        document.getElementById('total').textContent = `$${formatearDecimal(total)}`;
        
        document.getElementById('descuento_form').value = valorDescuento;
        document.getElementById('tipo_descuento_form').value = tipoDescuento;
        
        if (tipoVenta === 'contado') {
            if (metodoPagoSeleccionado === 'mixto') {
                validarSumaPagosMixtos(total);
                
                document.getElementById('cambio').textContent = '$0';
                document.getElementById('monto_recibido_form').value = 0;
                document.getElementById('abono_inicial_form').value = 0;
                
            } else {
                const montoRecibido = desformatearNumero(document.getElementById('monto_recibido').value) || 0;
                const cambio = montoRecibido - total;
                
                document.getElementById('cambio').textContent = `$${formatearDecimal(Math.max(0, cambio))}`;
                document.getElementById('monto_recibido_form').value = montoRecibido;
                document.getElementById('abono_inicial_form').value = 0;
            }
            
        } else {
            const abonoInicial = desformatearNumero(document.getElementById('abono_inicial').value) || 0;
            const saldoPendiente = total - abonoInicial;
            
            document.getElementById('totalVentaCredito').textContent = `$${formatearDecimal(total)}`;
            document.getElementById('abonoCredito').textContent = `$${formatearDecimal(abonoInicial)}`;
            document.getElementById('saldoPendiente').textContent = `$${formatearDecimal(saldoPendiente)}`;
            
            if (total > 0 && abonoInicial < total) {
                document.getElementById('infoDeuda').classList.remove('hidden');
            } else {
                document.getElementById('infoDeuda').classList.add('hidden');
            }
            
            document.getElementById('monto_recibido_form').value = abonoInicial;
            document.getElementById('abono_inicial_form').value = abonoInicial;
            
            document.getElementById('cambio').textContent = '$0';
        }
        
        validarFormulario();
    }

    document.querySelectorAll('input[name="tipo_descuento"]').forEach(radio => {
        radio.addEventListener('change', actualizarTotales);
    });

    document.querySelectorAll('.pago-mixto-input').forEach(input => {
        input.addEventListener('input', actualizarTotales);
    });

    document.getElementById('observaciones').addEventListener('input', function() {
        document.getElementById('observaciones_form').value = this.value;
    });

    function validarFormulario() {
        if (isProcessing) return false;
        
        const btnProcesar = document.getElementById('btnProcesarVenta');
        const clienteValido = document.getElementById('cliente_id_form').value !== '';
        const productosValido = productosAgregados.length > 0;
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
        
        if (tipoVenta === 'contado') {
            if (metodoPagoSeleccionado === 'mixto') {
                const total = desformatearNumero(document.getElementById('total').textContent);
                const sumaPagos = calcularSumaPagosMixtos();
                const diferencia = Math.abs(sumaPagos - total);
                const tolerancia = 0.01;
                
                if (diferencia > tolerancia) {
                    errores.push('La suma de los pagos mixtos no coincide con el total');
                }
                
            } else {
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

    document.getElementById('btnProcesarVenta').addEventListener('click', async function(e) {
        e.preventDefault();
        
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
                    isProcessing = true;
                    
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
                    
                    productosAgregados.forEach((producto, index) => {
                        formData.append(`productos[${index}][id]`, producto.id);
                        formData.append(`productos[${index}][cantidad]`, producto.cantidad);
                        formData.append(`productos[${index}][precio]`, producto.precio);
                    });
                    
                    if (metodoPagoSeleccionado === 'mixto') {
                        prepararDatosPagoMixto(formData);
                    }
                    
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 15000);
                    
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
            isProcessing = false;
            procesarClickCount = 0;
            if (procesarTimeout) {
                clearTimeout(procesarTimeout);
                procesarTimeout = null;
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('buscadorInteligente').focus();
        
        actualizarContadorPausas();
        
        <?php if ($venta_pausada): ?>
        console.log('Hay una venta pausada disponible. Usa el botón "Restaurar" en el header para recuperarla.');
        <?php endif; ?>
        
        const camposMoneda = ['descuento', 'monto_recibido', 'abono_inicial', 
                             'monto_efectivo_mixto', 'monto_tarjeta_mixto', 
                             'monto_transferencia_mixto', 'monto_otro_mixto'];
        
        camposMoneda.forEach(campo => {
            const input = document.getElementById(campo);
            if (input && input.value !== '0') {
                const valor = desformatearNumero(input.value);
                input.value = formatearDecimal(valor);
            }
        });
    });

    setInterval(validarFormulario, 1000);
    
    setInterval(() => {
        if (procesarClickCount > 0 && !isProcessing) {
            procesarClickCount = 0;
        }
    }, 5000);
    </script>
</body>
</html>
<?php ob_end_flush(); ?>