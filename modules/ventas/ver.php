<?php
if (session_status() === PHP_SESSION_NONE) session_start(); 
ob_start();
include '../../includes/header.php';

$database = Database::getInstance();
$db = $database->getConnection();

// Obtener venta
$id = $_GET['id'] ?? 0;
$query_venta = "SELECT v.*, c.nombre as cliente_nombre, c.numero_documento as cliente_documento, 
                c.ruc as cliente_ruc, c.direccion as cliente_direccion, u.nombre as usuario_nombre 
                FROM ventas v 
                LEFT JOIN clientes c ON v.cliente_id = c.id 
                LEFT JOIN usuarios u ON v.usuario_id = u.id 
                WHERE v.id = ?";
$stmt_venta = $db->prepare($query_venta);
$stmt_venta->execute([$id]);
$venta = $stmt_venta->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
    header('Location: index.php');
    exit;
}

// DEPURACIÓN: Mostrar información de la venta
error_log("Venta ID: " . $id . " - Factura: " . $venta['numero_factura']);

// Obtener detalles de la venta ORIGINAL
$query_detalles = "SELECT vd.*, p.nombre as producto_nombre, p.codigo as producto_codigo, p.codigo_barras
                   FROM venta_detalles vd 
                   JOIN productos p ON vd.producto_id = p.id 
                   WHERE vd.venta_id = ?";
$stmt_detalles = $db->prepare($query_detalles);
$stmt_detalles->execute([$id]);
$detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

// DEPURACIÓN: Mostrar detalles
error_log("Detalles encontrados: " . count($detalles));
foreach ($detalles as $detalle) {
    error_log("Producto ID: " . $detalle['producto_id'] . " - Nombre: " . $detalle['producto_nombre']);
}

// Obtener devoluciones de esta venta - CORREGIDO: usamos venta_id, no id
$query_devoluciones = "SELECT d.*, p.nombre as producto_nombre, p.codigo as producto_codigo,
                       u.nombre as usuario_nombre
                       FROM devoluciones d
                       JOIN productos p ON d.producto_id = p.id
                       LEFT JOIN usuarios u ON d.usuario_id = u.id
                       WHERE d.venta_id = ?
                       ORDER BY d.fecha DESC";
$stmt_devoluciones = $db->prepare($query_devoluciones);
$stmt_devoluciones->execute([$id]);
$devoluciones = $stmt_devoluciones->fetchAll(PDO::FETCH_ASSOC);

// DEPURACIÓN: Mostrar devoluciones
error_log("Devoluciones encontradas: " . count($devoluciones));
foreach ($devoluciones as $devolucion) {
    error_log("Devolución Producto ID: " . $devolucion['producto_id'] . 
              " - Cantidad: " . $devolucion['cantidad'] . 
              " - Monto: " . $devolucion['monto_devolucion']);
}

// Calcular total devuelto
$total_devoluciones = 0;
foreach ($devoluciones as $devolucion) {
    $total_devoluciones += $devolucion['monto_devolucion'];
}

// Calcular cantidades devueltas por producto - MEJORADO
$cantidades_devueltas = [];
$productos_devueltos_info = []; // Para almacenar info de productos devueltos
foreach ($devoluciones as $devolucion) {
    $producto_id = $devolucion['producto_id'];
    if (!isset($cantidades_devueltas[$producto_id])) {
        $cantidades_devueltas[$producto_id] = 0;
    }
    $cantidades_devueltas[$producto_id] += $devolucion['cantidad'];
    
    // Guardar info del producto devuelto
    $productos_devueltos_info[$producto_id] = [
        'nombre' => $devolucion['producto_nombre'],
        'codigo' => $devolucion['producto_codigo']
    ];
}

// DEPURACIÓN: Mostrar cantidades devueltas
error_log("Cantidades devueltas por producto: " . print_r($cantidades_devueltas, true));

// Verificar si hay productos en detalles que no están en devoluciones pero deberían mostrarse
// Esto es importante para productos devueltos completamente
$detalles_completos = $detalles;

// Si hay productos devueltos que no están en detalles, los agregamos para mostrar
foreach ($productos_devueltos_info as $producto_id => $info) {
    $encontrado = false;
    foreach ($detalles as $detalle) {
        if ($detalle['producto_id'] == $producto_id) {
            $encontrado = true;
            break;
        }
    }
    
    if (!$encontrado) {
        // Producto devuelto que no está en detalles (puede ser raro pero manejamos el caso)
        $detalles_completos[] = [
            'producto_id' => $producto_id,
            'producto_nombre' => $info['nombre'],
            'producto_codigo' => $info['codigo'],
            'codigo_barras' => '',
            'cantidad' => 0, // Se vendió 0 pero se devolvió
            'precio' => 0,
            'subtotal' => 0
        ];
    }
}

// Resto del código permanece igual...
// ============================================
// NUEVO: OBTENER PAGOS MIXTOS
// ============================================
$pagos_mixtos = [];
$total_pagos_mixtos = 0;
if ($venta['metodo_pago'] === 'mixto') {
    $query_check_table = "SHOW TABLES LIKE 'pagos_mixtos_detalles'";
    $stmt_check_table = $db->query($query_check_table);
    
    if ($stmt_check_table->fetch()) {
        $query_pagos_mixtos = "SELECT metodo, monto 
                               FROM pagos_mixtos_detalles 
                               WHERE venta_id = ? AND monto > 0 
                               ORDER BY CASE metodo 
                                        WHEN 'efectivo' THEN 1
                                        WHEN 'tarjeta' THEN 2
                                        WHEN 'transferencia' THEN 3
                                        WHEN 'otro' THEN 4
                                        ELSE 5 END";
        $stmt_pagos_mixtos = $db->prepare($query_pagos_mixtos);
        $stmt_pagos_mixtos->execute([$id]);
        $pagos_mixtos = $stmt_pagos_mixtos->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pagos_mixtos as $pago) {
            $total_pagos_mixtos += $pago['monto'];
        }
    }
}

// Resto del código...
// Determinar clase del estado
$estado_venta = $venta['estado'];
if ($venta['anulada']) {
    $estado_class = 'bg-red-100 text-red-800';
    $estado_text = 'Anulada';
    $estado_icon = 'fas fa-ban';
} elseif ($estado_venta == 'completada') {
    $estado_class = 'bg-green-100 text-green-800';
    $estado_text = 'Completada';
    $estado_icon = 'fas fa-check-circle';
} else {
    $estado_class = 'bg-gray-100 text-gray-800';
    $estado_text = $estado_venta ? ucfirst(str_replace('_', ' ', $estado_venta)) : 'Completada';
    $estado_icon = 'fas fa-question-circle';
}

// Determinar tipo de venta
$tipo_venta_valor = $venta['tipo_venta'] ?? 'contado';
if ($tipo_venta_valor == 'credito') {
    $tipo_class = 'bg-purple-100 text-purple-800';
    $tipo_text = 'Crédito';
    $tipo_icon = 'fas fa-hand-holding-usd';
} else {
    $tipo_class = 'bg-green-100 text-green-800';
    $tipo_text = 'Contado';
    $tipo_icon = 'fas fa-money-bill-wave';
}
?>

<div class="max-w-6xl mx-auto">
    <!-- DEPURACIÓN: Mostrar información de depuración en desarrollo -->
    <?php if (isset($_GET['debug'])): ?>
    <div class="bg-yellow-100 border border-yellow-400 rounded-lg p-4 mb-4">
        <h3 class="font-bold text-yellow-800">Información de Depuración:</h3>
        <p class="text-sm">Venta ID: <?php echo $id; ?></p>
        <p class="text-sm">Detalles encontrados: <?php echo count($detalles); ?></p>
        <p class="text-sm">Devoluciones encontradas: <?php echo count($devoluciones); ?></p>
        <p class="text-sm">Total devoluciones: $<?php echo number_format($total_devoluciones, 2); ?></p>
        <?php if (!empty($cantidades_devueltas)): ?>
        <p class="text-sm">Productos devueltos: <?php echo implode(', ', array_keys($cantidades_devueltas)); ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
            <div>
                <h2 class="text-xl font-semibold text-gray-800">Detalles de Venta</h2>
                <p class="text-sm text-gray-600">Información completa de la venta</p>
                <?php if (!empty($devoluciones)): ?>
                <p class="text-sm text-red-600 mt-1">
                    <i class="fas fa-exclamation-triangle"></i> Esta venta tiene <?php echo count($devoluciones); ?> devolución(es)
                </p>
                <?php endif; ?>
            </div>
            <div class="flex space-x-2">
                <a href="imprimir_ticket.php?id=<?php echo $venta['id']; ?>" target="_blank" 
                   class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-print mr-2"></i>Imprimir
                </a>
                <a href="index.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-arrow-left mr-2"></i>Volver
                </a>
                <a href="?id=<?php echo $id; ?>&debug=1" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg flex items-center">
                    <i class="fas fa-bug mr-2"></i>Debug
                </a>
            </div>
        </div>
        
        <div class="p-6">
            <!-- Información de la venta -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <div class="bg-gray-50 rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Información de la Venta</h3>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Número de Factura</dt>
                            <dd class="text-sm text-gray-900 font-semibold"><?php echo $venta['numero_factura']; ?></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Fecha y Hora</dt>
                            <dd class="text-sm text-gray-900"><?php echo date('d/m/Y H:i:s', strtotime($venta['fecha'])); ?></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Vendedor</dt>
                            <dd class="text-sm text-gray-900"><?php echo $venta['usuario_nombre']; ?></dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Tipo de Venta</dt>
                            <dd class="text-sm">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $tipo_class; ?>">
                                    <i class="<?php echo $tipo_icon; ?> mr-1"></i>
                                    <?php echo $tipo_text; ?>
                                </span>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Estado</dt>
                            <dd class="text-sm">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $estado_class; ?>">
                                    <i class="<?php echo $estado_icon; ?> mr-1"></i>
                                    <?php echo $estado_text; ?>
                                </span>
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Método de Pago</dt>
                            <dd class="text-sm text-gray-900 capitalize">
                                <?php echo $venta['metodo_pago']; ?>
                            </dd>
                        </div>
                        
                        <!-- Mostrar devoluciones si existen -->
                        <?php if (!empty($devoluciones)): ?>
                        <div class="pt-3 border-t border-gray-200">
                            <div class="flex justify-between">
                                <dt class="text-sm font-medium text-gray-500">Devoluciones</dt>
                                <dd class="text-sm">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                                        <i class="fas fa-undo mr-1"></i>
                                        <?php echo count($devoluciones); ?> devolución(es)
                                    </span>
                                </dd>
                            </div>
                            <div class="mt-1">
                                <span class="text-sm text-red-600 font-medium">
                                    Total devuelto: $<?php echo number_format($total_devoluciones, 2); ?>
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </dl>
                </div>
                
                <div class="bg-gray-50 rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Información del Cliente</h3>
                    <dl class="space-y-3">
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Cliente</dt>
                            <dd class="text-sm text-gray-900"><?php echo $venta['cliente_nombre'] ?? 'Cliente General'; ?></dd>
                        </div>
                        <?php if ($venta['cliente_documento']): ?>
                        <div class="flex justify-between">
                            <dt class="text-sm font-medium text-gray-500">Documento</dt>
                            <dd class="text-sm text-gray-900"><?php echo $venta['cliente_documento']; ?></dd>
                        </div>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- Historial de devoluciones -->
            <?php if (!empty($devoluciones)): ?>
            <div class="mb-8">
                <h3 class="text-lg font-medium text-gray-900 mb-4">
                    <i class="fas fa-undo text-orange-500 mr-2"></i>
                    Historial de Devoluciones
                </h3>
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 mb-6">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-orange-100">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-orange-800 uppercase">Fecha</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-orange-800 uppercase">Producto</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-orange-800 uppercase">Cantidad Devuelta</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-orange-800 uppercase">Monto Devolución</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-orange-800 uppercase">Motivo</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-orange-800 uppercase">Registrado por</th>
                                </tr>
                            </thead>
                            <tbody class="bg-orange-50 divide-y divide-orange-200">
                                <?php foreach ($devoluciones as $devolucion): ?>
                                <tr class="hover:bg-orange-100">
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo date('d/m/Y H:i', strtotime($devolucion['fecha'])); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($devolucion['producto_nombre']); ?>
                                        <div class="text-xs text-gray-500"><?php echo $devolucion['producto_codigo']; ?></div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <span class="font-medium text-red-600"><?php echo $devolucion['cantidad']; ?></span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-red-600">
                                        $<?php echo number_format($devolucion['monto_devolucion'], 2); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-700">
                                        <?php echo htmlspecialchars($devolucion['motivo']); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo $devolucion['usuario_nombre'] ?? 'Sistema'; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <!-- Total de devoluciones -->
                                <tr class="bg-orange-200 font-semibold">
                                    <td colspan="3" class="px-4 py-3 text-right text-sm text-orange-900">Total devoluciones:</td>
                                    <td class="px-4 py-3 text-sm font-bold text-red-700">
                                        $<?php echo number_format($total_devoluciones, 2); ?>
                                    </td>
                                    <td colspan="2"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Detalles de productos (ORIGINALES y mostrando devoluciones) -->
            <div class="mb-8">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Productos Vendidos</h3>
                <?php if (empty($detalles_completos)): ?>
                    <div class="text-center py-8 bg-gray-50 rounded-lg">
                        <i class="fas fa-box-open text-gray-300 text-4xl mb-3"></i>
                        <p class="text-gray-500">No hay productos en esta venta</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Producto</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Código</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cantidad Original</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Devuelto</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Neto Vendido</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Precio Unit.</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php 
                                $subtotal_original = 0;
                                $subtotal_neto = 0;
                                $productos_con_devolucion = 0;
                                ?>
                                <?php foreach ($detalles_completos as $detalle): 
                                    $cantidad_devuelta = $cantidades_devueltas[$detalle['producto_id']] ?? 0;
                                    $cantidad_neta = $detalle['cantidad'] - $cantidad_devuelta;
                                    $subtotal_original += $detalle['subtotal'];
                                    $subtotal_neto += ($cantidad_neta * $detalle['precio']);
                                    
                                    if ($cantidad_devuelta > 0) {
                                        $productos_con_devolucion++;
                                    }
                                ?>
                                <tr class="hover:bg-gray-50 <?php echo $cantidad_devuelta > 0 ? 'bg-red-50' : ''; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($detalle['producto_nombre']); ?>
                                        </div>
                                        <?php if ($cantidad_devuelta > 0): ?>
                                        <div class="text-xs text-red-600 mt-1">
                                            <i class="fas fa-undo mr-1"></i>Devuelto: <?php echo $cantidad_devuelta; ?> unidad(es)
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo $detalle['producto_codigo']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        <?php echo $detalle['cantidad']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                        <?php if ($cantidad_devuelta > 0): ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 text-red-800">
                                                <i class="fas fa-undo mr-1"></i><?php echo $cantidad_devuelta; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-gray-500">0</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-center font-medium">
                                        <?php echo $cantidad_neta; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                        $<?php echo number_format($detalle['precio'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 text-center">
                                        $<?php echo number_format($detalle['precio'] * $cantidad_neta, 2); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-gray-50">
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-right text-sm font-medium text-gray-900">Subtotal Original:</td>
                                    <td colspan="2" class="px-6 py-4 text-sm font-medium text-gray-900">
                                        $<?php echo number_format($subtotal_original, 2); ?>
                                    </td>
                                </tr>
                                <?php if ($total_devoluciones > 0): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-right text-sm font-medium text-red-600">Devoluciones:</td>
                                    <td colspan="2" class="px-6 py-4 text-sm font-medium text-red-600">
                                        -$<?php echo number_format($total_devoluciones, 2); ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-right text-sm font-bold text-gray-900">Subtotal Neto:</td>
                                    <td colspan="2" class="px-6 py-4 text-sm font-bold text-green-600">
                                        $<?php echo number_format($subtotal_neto, 2); ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Resumen de totales -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <?php if ($venta['observaciones']): ?>
                    <div class="mb-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Observaciones</h3>
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                            <p class="text-sm text-gray-700"><?php echo nl2br(htmlspecialchars($venta['observaciones'])); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="bg-green-50 rounded-lg p-6 border border-green-200">
                    <h3 class="text-lg font-medium text-green-900 mb-4">Resumen de Pagos</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Subtotal Original:</span>
                            <span class="font-medium">$<?php echo number_format($subtotal_original, 2); ?></span>
                        </div>
                        <?php if ($total_devoluciones > 0): ?>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Devoluciones:</span>
                            <span class="font-medium text-red-600">-$<?php echo number_format($total_devoluciones, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($venta['descuento'] > 0): ?>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Descuento:</span>
                            <span class="font-medium text-red-600">-$<?php echo number_format($venta['descuento'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php 
                        $subtotal_despues_devoluciones = $subtotal_original - $total_devoluciones;
                        $impuesto_calculado = $subtotal_despues_devoluciones * 0.18;
                        ?>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Impuesto (18%):</span>
                            <span class="font-medium">$<?php echo number_format($impuesto_calculado, 2); ?></span>
                        </div>
                        
                        <div class="flex justify-between border-t border-green-200 pt-3">
                            <span class="text-lg font-bold text-gray-900">Total Factura:</span>
                            <span class="text-lg font-bold text-green-600">$<?php echo number_format($venta['total'], 2); ?></span>
                        </div>
                        
                        <!-- Información para efectivo -->
                        <?php if ($venta['metodo_pago'] == 'efectivo'): ?>
                        <div class="flex justify-between border-t border-green-200 pt-3">
                            <span class="text-gray-600">Monto Recibido:</span>
                            <span class="font-medium">$<?php echo number_format($venta['monto_recibido'], 2); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Cambio:</span>
                            <span class="font-medium text-blue-600">$<?php echo number_format($venta['cambio'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Acciones adicionales -->
            <?php if (!$venta['anulada'] && $_SESSION['usuario_rol'] != 'cajero'): ?>
            <div class="mt-8 pt-6 border-t border-gray-200">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Acciones</h3>
                <div class="flex space-x-3 flex-wrap gap-2">
                    <?php if ($venta['estado'] == 'pendiente'): ?>
                    <a href="editar.php?id=<?php echo $venta['id']; ?>" 
                       class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-edit mr-2"></i>Editar Venta
                    </a>
                    <?php endif; ?>
                    
                    <!-- Mostrar botón de devolución solo si hay productos que aún no han sido completamente devueltos -->
                    <?php 
                    $productos_pendientes_devolucion = false;
                    foreach ($detalles_completos as $detalle) {
                        $cantidad_devuelta = $cantidades_devueltas[$detalle['producto_id']] ?? 0;
                        if ($cantidad_devuelta < $detalle['cantidad']) {
                            $productos_pendientes_devolucion = true;
                            break;
                        }
                    }
                    ?>
                    
                    <?php if ($productos_pendientes_devolucion): ?>
                    <a href="devolucion.php?id=<?php echo $venta['id']; ?>" 
                       class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-undo mr-2"></i>Procesar Devolución
                    </a>
                    <?php endif; ?>
                    
                    <button onclick="confirmarAnulacion(<?php echo $venta['id']; ?>, '<?php echo $venta['numero_factura']; ?>')" 
                            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-ban mr-2"></i>Anular Venta
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal de confirmación para anular -->
<div id="modalAnular" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-full max-w-md shadow-lg rounded-md bg-white">
        <div class="mt-3 text-center">
            <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900">Confirmar Anulación</h3>
            <div class="mt-2 px-7 py-3">
                <p class="text-sm text-gray-500">
                    ¿Estás seguro de que quieres anular la venta <span id="ventaNumero"></span>?
                </p>
                <p class="text-sm text-red-500 mt-2">Esta acción no se puede deshacer.</p>
                <div class="mt-4">
                    <label for="motivo_anulacion" class="block text-sm font-medium text-gray-700 text-left">Motivo:</label>
                    <textarea id="motivo_anulacion" name="motivo_anulacion" rows="3" 
                              class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                </div>
            </div>
            <div class="flex justify-center space-x-3 mt-4">
                <button onclick="cerrarModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded">
                    Cancelar
                </button>
                <button id="confirmarAnular" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">
                    Anular Venta
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let ventaIdAnular = null;

function confirmarAnulacion(id, numero) {
    ventaIdAnular = id;
    document.getElementById('ventaNumero').textContent = numero;
    document.getElementById('modalAnular').classList.remove('hidden');
}

function cerrarModal() {
    document.getElementById('modalAnular').classList.add('hidden');
    ventaIdAnular = null;
    document.getElementById('motivo_anulacion').value = '';
}

document.getElementById('confirmarAnular').addEventListener('click', function() {
    const motivo = document.getElementById('motivo_anulacion').value;
    if (!motivo.trim()) {
        alert('Por favor ingresa el motivo de la anulación.');
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'anular.php';
    
    const inputId = document.createElement('input');
    inputId.type = 'hidden';
    inputId.name = 'venta_id';
    inputId.value = ventaIdAnular;
    
    const inputMotivo = document.createElement('input');
    inputMotivo.type = 'hidden';
    inputMotivo.name = 'motivo_anulacion';
    inputMotivo.value = motivo;
    
    form.appendChild(inputId);
    form.appendChild(inputMotivo);
    document.body.appendChild(form);
    form.submit();
});
</script>

<?php include '../../includes/footer.php'; ?>