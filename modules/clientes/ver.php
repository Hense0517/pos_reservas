<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/header.php';

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$cliente_id = $_GET['id'];

// Obtener datos del cliente
$query = "SELECT * FROM clientes WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$cliente_id]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    $_SESSION['error'] = "Cliente no encontrado";
    header('Location: index.php');
    exit;
}

// Obtener estadísticas de ventas del cliente
$query_ventas = "SELECT 
                    COUNT(*) as total_ventas, 
                    SUM(CASE WHEN anulada = 0 THEN total ELSE 0 END) as total_comprado,
                    COUNT(CASE WHEN tipo_venta = 'credito' AND anulada = 0 THEN 1 END) as ventas_credito,
                    COUNT(CASE WHEN (tipo_venta = 'contado' OR tipo_venta IS NULL OR tipo_venta = '') AND anulada = 0 THEN 1 END) as ventas_contado
                 FROM ventas 
                 WHERE cliente_id = ? AND anulada = 0";
$stmt_ventas = $db->prepare($query_ventas);
$stmt_ventas->execute([$cliente_id]);
$estadisticas = $stmt_ventas->fetch(PDO::FETCH_ASSOC);

// Obtener información de cuentas por cobrar
$query_cuentas = "SELECT 
                    COUNT(*) as total_cuentas,
                    SUM(CASE WHEN estado IN ('pendiente', 'parcial') THEN saldo_pendiente ELSE 0 END) as total_deuda,
                    SUM(CASE WHEN estado = 'pagada' THEN total_deuda ELSE 0 END) as total_pagado,
                    SUM(CASE WHEN estado = 'vencida' THEN saldo_pendiente ELSE 0 END) as deuda_vencida
                 FROM cuentas_por_cobrar 
                 WHERE cliente_id = ?";
$stmt_cuentas = $db->prepare($query_cuentas);
$stmt_cuentas->execute([$cliente_id]);
$cuentas_info = $stmt_cuentas->fetch(PDO::FETCH_ASSOC);

// Obtener historial de ventas recientes
$query_historial = "SELECT v.*, u.nombre as vendedor_nombre
                    FROM ventas v
                    LEFT JOIN usuarios u ON v.usuario_id = u.id
                    WHERE v.cliente_id = ? AND v.anulada = 0
                    ORDER BY v.fecha DESC
                    LIMIT 10";
$stmt_historial = $db->prepare($query_historial);
$stmt_historial->execute([$cliente_id]);
$historial_ventas = $stmt_historial->fetchAll(PDO::FETCH_ASSOC);

// Obtener cuentas por cobrar activas
$query_cuentas_activas = "SELECT cp.*, v.numero_factura, v.fecha as fecha_venta
                         FROM cuentas_por_cobrar cp
                         JOIN ventas v ON cp.venta_id = v.id
                         WHERE cp.cliente_id = ? AND cp.estado IN ('pendiente', 'parcial', 'vencida')
                         ORDER BY cp.fecha_limite ASC";
$stmt_cuentas_activas = $db->prepare($query_cuentas_activas);
$stmt_cuentas_activas->execute([$cliente_id]);
$cuentas_activas = $stmt_cuentas_activas->fetchAll(PDO::FETCH_ASSOC);

// Mapeo de tipos de documento
$nombresDocumentos = [
    'CEDULA' => 'Cédula',
    'DNI' => 'DNI',
    'RUC' => 'RUC',
    'PASAPORTE' => 'Pasaporte',
    'TARJETA_IDENTIDAD' => 'Tarjeta de Identidad',
    'CEDULA_EXTRANJERIA' => 'Cédula de Extranjería'
];
?>

<div class="max-w-7xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Detalle del Cliente</h1>
            <p class="text-gray-600">Información completa del cliente y su historial</p>
        </div>
        <div class="flex space-x-2">
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver
            </a>
            <a href="editar.php?id=<?php echo $cliente['id']; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-edit mr-2"></i>
                Editar
            </a>
            <a href="../ventas/crear.php?cliente_id=<?php echo $cliente['id']; ?>" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-shopping-cart mr-2"></i>
                Nueva Venta
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Columna izquierda: Información del cliente -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="bg-gradient-to-r from-green-500 to-green-600 px-6 py-8 text-center">
                    <div class="h-20 w-20 bg-white bg-opacity-20 rounded-full flex items-center justify-center mx-auto">
                        <span class="text-white font-bold text-2xl">
                            <?php echo strtoupper(substr($cliente['nombre'], 0, 2)); ?>
                        </span>
                    </div>
                    <h2 class="text-xl font-bold text-white mt-4"><?php echo htmlspecialchars($cliente['nombre']); ?></h2>
                    <p class="text-green-100 text-sm mt-1">
                        <?php echo $nombresDocumentos[$cliente['tipo_documento']] . ': ' . htmlspecialchars($cliente['numero_documento']); ?>
                    </p>
                </div>

                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-4 text-gray-800">Información de Contacto</h3>
                    <div class="space-y-4">
                        <?php if (!empty($cliente['telefono'])): ?>
                        <div class="flex items-start">
                            <i class="fas fa-phone text-gray-400 w-5 mt-1"></i>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-500">Teléfono</p>
                                <p class="text-gray-900"><?php echo htmlspecialchars($cliente['telefono']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($cliente['email'])): ?>
                        <div class="flex items-start">
                            <i class="fas fa-envelope text-gray-400 w-5 mt-1"></i>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-500">Email</p>
                                <p class="text-gray-900"><?php echo htmlspecialchars($cliente['email']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($cliente['direccion'])): ?>
                        <div class="flex items-start">
                            <i class="fas fa-map-marker-alt text-gray-400 w-5 mt-1"></i>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-500">Dirección</p>
                                <p class="text-gray-900"><?php echo htmlspecialchars($cliente['direccion']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex items-start">
                            <i class="fas fa-calendar-alt text-gray-400 w-5 mt-1"></i>
                            <div class="ml-3">
                                <p class="text-sm font-medium text-gray-500">Registrado desde</p>
                                <p class="text-gray-900"><?php echo date('d/m/Y', strtotime($cliente['created_at'])); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estadísticas -->
            <div class="bg-white rounded-lg shadow p-6 mt-6">
                <h3 class="text-lg font-semibold mb-4 text-gray-800">Resumen de Compras</h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Total Ventas:</span>
                        <span class="font-bold text-gray-900"><?php echo $estadisticas['total_ventas'] ?? 0; ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Total Gastado:</span>
                        <span class="font-bold text-green-600">$<?php echo number_format($estadisticas['total_comprado'] ?? 0, 2); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Ventas al Contado:</span>
                        <span class="font-bold text-blue-600"><?php echo $estadisticas['ventas_contado'] ?? 0; ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Ventas a Crédito:</span>
                        <span class="font-bold text-purple-600"><?php echo $estadisticas['ventas_credito'] ?? 0; ?></span>
                    </div>
                </div>
            </div>

            <!-- Cuentas por Cobrar -->
            <div class="bg-white rounded-lg shadow p-6 mt-6">
                <h3 class="text-lg font-semibold mb-4 text-gray-800">Cuentas por Cobrar</h3>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Cuentas Activas:</span>
                        <span class="font-bold text-gray-900"><?php echo $cuentas_info['total_cuentas'] ?? 0; ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Deuda Total:</span>
                        <span class="font-bold text-red-600">$<?php echo number_format($cuentas_info['total_deuda'] ?? 0, 2); ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Total Pagado:</span>
                        <span class="font-bold text-green-600">$<?php echo number_format($cuentas_info['total_pagado'] ?? 0, 2); ?></span>
                    </div>
                    <?php if (($cuentas_info['deuda_vencida'] ?? 0) > 0): ?>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Deuda Vencida:</span>
                        <span class="font-bold text-red-600">$<?php echo number_format($cuentas_info['deuda_vencida'] ?? 0, 2); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($cuentas_info['total_cuentas'] > 0): ?>
                <a href="../cuentas_por_cobrar/index.php?cliente_id=<?php echo $cliente['id']; ?>" 
                   class="mt-4 w-full bg-purple-600 hover:bg-purple-700 text-white py-2 px-4 rounded-lg flex items-center justify-center">
                    <i class="fas fa-file-invoice-dollar mr-2"></i>
                    Ver Todas las Cuentas
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Columna derecha: Historial -->
        <div class="lg:col-span-3">
            <!-- Cuentas por cobrar activas -->
            <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h3 class="text-lg font-semibold text-gray-800">Cuentas por Cobrar Activas</h3>
                        <?php if (!empty($cuentas_activas)): ?>
                        <span class="text-sm text-red-600 font-medium">
                            Total: $<?php echo number_format($cuentas_info['total_deuda'] ?? 0, 2); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if (!empty($cuentas_activas)): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Factura</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha Venta</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha Límite</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Deuda</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Saldo Pendiente</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($cuentas_activas as $cuenta): 
                                // Determinar clase del estado
                                switch ($cuenta['estado']) {
                                    case 'vencida':
                                        $estado_class = 'bg-red-100 text-red-800';
                                        $estado_icon = 'fas fa-exclamation-triangle';
                                        break;
                                    case 'parcial':
                                        $estado_class = 'bg-yellow-100 text-yellow-800';
                                        $estado_icon = 'fas fa-hourglass-half';
                                        break;
                                    default:
                                        $estado_class = 'bg-orange-100 text-orange-800';
                                        $estado_icon = 'fas fa-clock';
                                }
                                
                                // Verificar si está vencida
                                $hoy = new DateTime();
                                $fecha_limite = new DateTime($cuenta['fecha_limite']);
                                $esta_vencida = ($hoy > $fecha_limite && $cuenta['estado'] != 'pagada');
                            ?>
                            <tr class="hover:bg-gray-50 <?php echo $esta_vencida ? 'bg-red-50' : ''; ?>">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo $cuenta['numero_factura']; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('d/m/Y', strtotime($cuenta['fecha_venta'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm <?php echo $esta_vencida ? 'text-red-600 font-bold' : 'text-gray-900'; ?>">
                                        <?php echo date('d/m/Y', strtotime($cuenta['fecha_limite'])); ?>
                                        <?php if ($esta_vencida): ?>
                                            <span class="text-xs ml-1">(Vencida)</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    $<?php echo number_format($cuenta['total_deuda'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-red-600">
                                    $<?php echo number_format($cuenta['saldo_pendiente'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $estado_class; ?>">
                                        <i class="<?php echo $estado_icon; ?> mr-1"></i>
                                        <?php echo ucfirst($cuenta['estado']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                    <a href="../ventas/ver.php?id=<?php echo $cuenta['venta_id']; ?>" 
                                       class="text-blue-600 hover:text-blue-900" title="Ver venta">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="../cuentas_por_cobrar/registrar_pago.php?cuenta_id=<?php echo $cuenta['id']; ?>" 
                                       class="text-green-600 hover:text-green-900" title="Registrar pago">
                                        <i class="fas fa-cash-register"></i>
                                    </a>
                                    <a href="../cuentas_por_cobrar/ver.php?id=<?php echo $cuenta['id']; ?>" 
                                       class="text-purple-600 hover:text-purple-900" title="Ver detalles de cuenta">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-file-invoice-dollar text-gray-400 text-5xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No hay cuentas activas</h3>
                    <p class="text-gray-500">Este cliente no tiene cuentas por cobrar pendientes.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Historial de ventas recientes -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800">Historial de Ventas Recientes</h3>
                </div>
                
                <?php if (!empty($historial_ventas)): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Factura</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fecha</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Tipo</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vendedor</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($historial_ventas as $venta): 
                                // Determinar tipo de venta
                                $tipo_venta = $venta['tipo_venta'] ?? 'contado';
                                if ($tipo_venta == 'credito') {
                                    $tipo_class = 'bg-purple-100 text-purple-800';
                                    $tipo_text = 'Crédito';
                                    $tipo_icon = 'fas fa-hand-holding-usd';
                                } else {
                                    $tipo_class = 'bg-green-100 text-green-800';
                                    $tipo_text = 'Contado';
                                    $tipo_icon = 'fas fa-money-bill-wave';
                                }
                                
                                // Determinar estado
                                $estado_class = 'bg-green-100 text-green-800';
                                $estado_text = 'Completada';
                                $estado_icon = 'fas fa-check-circle';
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo $venta['numero_factura']; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $tipo_class; ?>">
                                        <i class="<?php echo $tipo_icon; ?> mr-1"></i>
                                        <?php echo $tipo_text; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo $venta['vendedor_nombre']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">
                                        $<?php echo number_format($venta['total'], 2); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $estado_class; ?>">
                                        <i class="<?php echo $estado_icon; ?> mr-1"></i>
                                        <?php echo $estado_text; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                    <a href="../ventas/ver.php?id=<?php echo $venta['id']; ?>" 
                                       class="text-blue-600 hover:text-blue-900" title="Ver detalles">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="../ventas/imprimir_ticket.php?id=<?php echo $venta['id']; ?>" 
                                       target="_blank" class="text-gray-600 hover:text-gray-900" title="Imprimir">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <?php if ($tipo_venta == 'credito'): ?>
                                    <a href="../cuentas_por_cobrar/index.php?venta_id=<?php echo $venta['id']; ?>" 
                                       class="text-purple-600 hover:text-purple-900" title="Ver crédito">
                                        <i class="fas fa-file-invoice-dollar"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="px-6 py-4 border-t border-gray-200">
                    <div class="flex justify-between items-center">
                        <p class="text-sm text-gray-500">
                            Mostrando las últimas <?php echo count($historial_ventas); ?> ventas
                        </p>
                        <a href="../ventas/index.php?cliente_id=<?php echo $cliente['id']; ?>" 
                           class="text-blue-600 hover:text-blue-800 text-sm font-medium flex items-center">
                            Ver todas las ventas
                            <i class="fas fa-external-link-alt ml-1"></i>
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-shopping-cart text-gray-400 text-5xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No hay historial de ventas</h3>
                    <p class="text-gray-500 mb-4">Este cliente aún no ha realizado compras.</p>
                    <a href="../ventas/crear.php?cliente_id=<?php echo $cliente['id']; ?>" 
                       class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg inline-flex items-center">
                        <i class="fas fa-shopping-cart mr-2"></i>
                        Registrar Primera Venta
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>