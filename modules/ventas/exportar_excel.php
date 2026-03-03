<?php
// exportar_excel.php - VERSIÓN CORREGIDA DE CÁLCULO DE DEUDA

// Iniciar sesión
session_start();

// Verificar permisos básicos
if (!isset($_SESSION['usuario_id'])) {
    die('Acceso no autorizado');
}

// Configurar zona horaria
date_default_timezone_set('America/Bogota');

// Incluir la conexión a la base de datos
require_once '../../config/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die('Error de conexión a la base de datos: ' . $e->getMessage());
}

// Obtener parámetros de filtro con valores por defecto
$fecha_inicio = isset($_GET['fecha_inicio']) ? $_GET['fecha_inicio'] : date('Y-m-d');
$fecha_fin = isset($_GET['fecha_fin']) ? $_GET['fecha_fin'] : date('Y-m-d');
$estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$tipo_venta = isset($_GET['tipo_venta']) ? $_GET['tipo_venta'] : '';
$metodo_pago = isset($_GET['metodo_pago']) ? $_GET['metodo_pago'] : '';

// Validar fechas
if (!strtotime($fecha_inicio) || !strtotime($fecha_fin)) {
    $fecha_inicio = date('Y-m-d');
    $fecha_fin = date('Y-m-d');
}

// Consulta básica para ventas
$sql_ventas = "SELECT 
    v.id,
    v.numero_factura,
    v.fecha,
    v.subtotal,
    v.descuento,
    v.impuesto,
    v.total,
    v.tipo_venta,
    v.metodo_pago,
    v.estado,
    v.anulada,
    v.abono_inicial,
    v.monto_recibido,
    v.cambio,
    COALESCE(c.nombre, 'Cliente General') as cliente_nombre,
    COALESCE(c.numero_documento, '') as cliente_documento,
    COALESCE(c.telefono, '') as cliente_telefono,
    u.nombre as usuario_nombre
FROM ventas v 
LEFT JOIN clientes c ON v.cliente_id = c.id 
LEFT JOIN usuarios u ON v.usuario_id = u.id 
WHERE DATE(v.fecha) BETWEEN :fecha_inicio AND :fecha_fin";

// Aplicar filtros
$params = [
    ':fecha_inicio' => $fecha_inicio,
    ':fecha_fin' => $fecha_fin
];

if ($estado === 'anuladas') {
    $sql_ventas .= " AND v.anulada = 1";
} elseif ($estado === 'completadas') {
    $sql_ventas .= " AND v.anulada = 0 AND v.estado = 'completada'";
} elseif ($estado === 'pendientes') {
    $sql_ventas .= " AND v.anulada = 0 AND v.estado = 'pendiente'";
} elseif ($estado === 'pendiente_credito') {
    $sql_ventas .= " AND v.anulada = 0 AND v.estado = 'pendiente_credito'";
} elseif ($estado === 'pagada_credito') {
    $sql_ventas .= " AND v.anulada = 0 AND v.estado = 'pagada_credito'";
} else {
    $sql_ventas .= " AND v.anulada = 0";
}

if ($tipo_venta === 'contado') {
    $sql_ventas .= " AND (v.tipo_venta = 'contado' OR v.tipo_venta IS NULL OR v.tipo_venta = '')";
} elseif ($tipo_venta === 'credito') {
    $sql_ventas .= " AND v.tipo_venta = 'credito'";
}

if ($metodo_pago && $metodo_pago !== 'todos') {
    $sql_ventas .= " AND v.metodo_pago = :metodo_pago";
    $params[':metodo_pago'] = $metodo_pago;
}

$sql_ventas .= " ORDER BY v.fecha DESC";

try {
    $stmt = $db->prepare($sql_ventas);
    $stmt->execute($params);
    $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('Error al consultar ventas: ' . $e->getMessage());
}

// Consulta para estadísticas básicas - CORREGIDA
try {
    $sql_stats = "SELECT 
        COUNT(*) as total_ventas,
        SUM(CASE WHEN anulada = 0 THEN total ELSE 0 END) as total_facturado,
        SUM(CASE WHEN anulada = 0 THEN descuento ELSE 0 END) as total_descuentos,
        SUM(CASE WHEN tipo_venta = 'credito' AND anulada = 0 THEN abono_inicial ELSE 0 END) as total_abonos,
        SUM(CASE WHEN anulada = 0 THEN impuesto ELSE 0 END) as total_impuestos,
        SUM(CASE WHEN anulada = 0 THEN subtotal ELSE 0 END) as total_subtotal,
        -- NUEVO: Calcular solo ventas a crédito para deuda pendiente
        SUM(CASE WHEN tipo_venta = 'credito' AND anulada = 0 THEN total ELSE 0 END) as total_credito,
        SUM(CASE WHEN tipo_venta = 'credito' AND anulada = 0 THEN descuento ELSE 0 END) as descuento_credito
    FROM ventas 
    WHERE DATE(fecha) BETWEEN :fecha_inicio AND :fecha_fin";
    
    $stmt_stats = $db->prepare($sql_stats);
    $stmt_stats->execute([
        ':fecha_inicio' => $fecha_inicio,
        ':fecha_fin' => $fecha_fin
    ]);
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = [
        'total_ventas' => 0,
        'total_facturado' => 0,
        'total_descuentos' => 0,
        'total_abonos' => 0,
        'total_impuestos' => 0,
        'total_subtotal' => 0,
        'total_credito' => 0,
        'descuento_credito' => 0
    ];
}

// Calcular valores CORRECTAMENTE
$total_facturado = $stats['total_facturado'] ?? 0;
$total_descuentos = $stats['total_descuentos'] ?? 0;
$total_abonos = $stats['total_abonos'] ?? 0;
$total_impuestos = $stats['total_impuestos'] ?? 0;
$total_subtotal = $stats['total_subtotal'] ?? 0;
$total_credito = $stats['total_credito'] ?? 0;
$descuento_credito = $stats['descuento_credito'] ?? 0;

// CÁLCULO CORREGIDO: Deuda pendiente solo para créditos
$ingresos_reales = $total_facturado - $total_descuentos;
$deuda_pendiente = max(0, $total_credito - $descuento_credito - $total_abonos);

// Configurar headers para Excel
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="reporte_ventas_' . date('Y-m-d_H-i') . '.xls"');
header('Cache-Control: max-age=0');
header('Expires: 0');

// Iniciar salida HTML para Excel
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reporte de Ventas - Valentina Rojas</title>
    <style>
        /* ESTILOS PROFESIONALES AZULES */
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            font-size: 10px; 
            color: #333;
            background-color: #ffffff;
        }
        
        .title { 
            font-size: 18px; 
            font-weight: 600; 
            text-align: center; 
            color: #1a365d;
            padding: 10px 0;
        }
        
        .subtitle { 
            font-size: 12px; 
            text-align: center; 
            color: #4a5568;
            padding-bottom: 10px;
        }
        
        .header { 
            background-color: #2c5282; 
            color: white; 
            font-weight: 600;
            font-size: 11px;
        }
        
        .header-secondary { 
            background-color: #4299e1; 
            color: white; 
            font-weight: 500;
            font-size: 10px;
        }
        
        .kpi-title { 
            background-color: #ebf8ff; 
            font-weight: 600; 
            color: #2c5282;
            text-align: center;
            border: 1px solid #bee3f8;
        }
        
        .kpi-value { 
            background-color: #f7fafc; 
            font-weight: 500; 
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        
        .total-row { 
            background-color: #e6fffa; 
            font-weight: 600; 
            border-top: 2px solid #38b2ac;
        }
        
        .success { color: #38a169; }
        .warning { color: #d69e2e; }
        .danger { color: #e53e3e; }
        .info { color: #3182ce; }
        
        table { 
            border-collapse: collapse; 
            width: 100%; 
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
        }
        
        th, td { 
            border: 1px solid #e2e8f0; 
            padding: 6px; 
            text-align: left;
            vertical-align: middle;
        }
        
        th { 
            text-align: center;
            font-weight: 600;
        }
        
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .text-bold { font-weight: 600; }
        
        .moneda { 
            font-family: 'Courier New', monospace;
            font-weight: 500;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 9px;
            font-weight: 500;
            text-align: center;
        }
        
        .badge-completada { background-color: #c6f6d5; color: #22543d; }
        .badge-pendiente { background-color: #feebc8; color: #744210; }
        .badge-anulada { background-color: #fed7d7; color: #742a2a; }
        .badge-credito { background-color: #c3dafe; color: #2a4365; }
        
        .separator {
            border-top: 2px solid #2c5282;
            margin: 15px 0;
        }
        
        .footer {
            font-size: 9px;
            color: #718096;
            border-top: 1px solid #e2e8f0;
            padding-top: 10px;
            margin-top: 20px;
        }
        
        .numeric-cell {
            font-family: 'Courier New', monospace;
            font-size: 10px;
        }
        
        .highlight {
            background-color: #ebf8ff;
            border-left: 3px solid #4299e1;
        }
        
        .zero-value {
            color: #a0aec0;
            font-style: italic;
        }
    </style>
</head>
<body>

<!-- ENCABEZADO PRINCIPAL -->
<table style="border: none; margin-bottom: 5px;">
    <tr>
        <td style="border: none; text-align: center; padding: 5px 0;">
            <div style="font-size: 22px; font-weight: 700; color: #2c5282; letter-spacing: 0.5px;">
                VALENTINA ROJAS
            </div>
            <div style="font-size: 14px; color: #4a5568; margin-top: 2px;">
                Reporte de Ventas
            </div>
        </td>
    </tr>
</table>

<!-- INFORMACIÓN DEL REPORTE -->
<table style="border: none; background-color: #f7fafc; margin-bottom: 15px;">
    <tr>
        <td style="border: none; padding: 8px; width: 60%;">
            <div style="color: #4a5568; font-size: 11px;">
                <strong>Período:</strong> <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>
            </div>
            <div style="color: #718096; font-size: 10px; margin-top: 2px;">
                Generado: <?php echo date('d/m/Y H:i:s'); ?> | 
                Usuario: <?php echo $_SESSION['usuario_nombre'] ?? 'Sistema'; ?>
            </div>
        </td>
        <td style="border: none; padding: 8px; width: 40%; text-align: right;">
            <div style="color: #4a5568; font-size: 10px;">
                <strong>Filtros aplicados:</strong><br>
                <?php if($estado): ?><span class="badge badge-credito" style="margin-right: 5px;">Estado: <?php echo $estado; ?></span><?php endif; ?>
                <?php if($tipo_venta): ?><span class="badge badge-credito" style="margin-right: 5px;">Tipo: <?php echo $tipo_venta; ?></span><?php endif; ?>
                <?php if($metodo_pago && $metodo_pago !== 'todos'): ?><span class="badge badge-credito">Pago: <?php echo $metodo_pago; ?></span><?php endif; ?>
            </div>
        </td>
    </tr>
</table>

<!-- KPI PRINCIPALES -->
<table style="margin-bottom: 20px; border: 1px solid #cbd5e0;">
    <tr>
        <td colspan="4" class="header" style="font-size: 12px; padding: 10px;">
            INDICADORES CLAVE - RESUMEN DEL PERÍODO
        </td>
    </tr>
    <tr>
        <td class="kpi-title" style="width: 25%;">VENTAS TOTALES</td>
        <td class="kpi-title" style="width: 25%;">INGRESOS REALES</td>
        <td class="kpi-title" style="width: 25%;">DESCUENTOS APLICADOS</td>
        <td class="kpi-title" style="width: 25%;">VENTAS REGISTRADAS</td>
    </tr>
    <tr>
        <td class="kpi-value" style="font-size: 14px; color: #2c5282;">
            <span class="moneda">$<?php echo number_format($total_facturado, 0, ',', '.'); ?></span>
        </td>
        <td class="kpi-value" style="font-size: 14px; color: #38a169;">
            <span class="moneda">$<?php echo number_format($ingresos_reales, 0, ',', '.'); ?></span>
        </td>
        <td class="kpi-value" style="font-size: 14px; color: #d69e2e;">
            <span class="moneda">$<?php echo number_format($total_descuentos, 0, ',', '.'); ?></span>
        </td>
        <td class="kpi-value" style="font-size: 14px; color: #805ad5;">
            <?php echo $stats['total_ventas'] ?? 0; ?> transacciones
        </td>
    </tr>
</table>

<!-- KPI SECUNDARIOS -->
<table style="margin-bottom: 25px; border: 1px solid #cbd5e0;">
    <tr>
        <td class="kpi-title" style="width: 20%;">VENTAS CONTADO</td>
        <td class="kpi-title" style="width: 20%;">VENTAS CRÉDITO</td>
        <td class="kpi-title" style="width: 20%;">ABONOS RECIBIDOS</td>
        <td class="kpi-title" style="width: 20%;">DEUDA PENDIENTE</td>
        <td class="kpi-title" style="width: 20%;">TICKET PROMEDIO</td>
    </tr>
    <tr>
        <td class="kpi-value">
            <?php 
            $ventas_contado = $total_facturado - $total_credito;
            ?>
            <span class="moneda info">$<?php echo number_format($ventas_contado, 0, ',', '.'); ?></span>
        </td>
        <td class="kpi-value">
            <span class="moneda">$<?php echo number_format($total_credito, 0, ',', '.'); ?></span>
        </td>
        <td class="kpi-value">
            <span class="moneda success">$<?php echo number_format($total_abonos, 0, ',', '.'); ?></span>
        </td>
        <td class="kpi-value">
            <?php if ($deuda_pendiente > 0): ?>
                <span class="moneda danger">$<?php echo number_format($deuda_pendiente, 0, ',', '.'); ?></span>
            <?php else: ?>
                <span class="moneda success zero-value">$0</span>
            <?php endif; ?>
        </td>
        <td class="kpi-value">
            <span class="moneda">$<?php echo $stats['total_ventas'] > 0 ? number_format($total_facturado / $stats['total_ventas'], 0, ',', '.') : '0'; ?></span>
        </td>
    </tr>
    <tr>
        <td class="kpi-title" colspan="5" style="font-size: 9px; color: #718096; text-align: center;">
            <?php 
            $porcentaje_contado = $total_facturado > 0 ? ($ventas_contado / $total_facturado) * 100 : 0;
            $porcentaje_credito = $total_facturado > 0 ? ($total_credito / $total_facturado) * 100 : 0;
            ?>
            Distribución: Contado <?php echo number_format($porcentaje_contado, 1); ?>% | Crédito <?php echo number_format($porcentaje_credito, 1); ?>%
        </td>
    </tr>
</table>

<!-- DETALLE DE VENTAS -->
<table style="margin-bottom: 25px;">
    <tr>
        <td colspan="14" class="header" style="padding: 10px; font-size: 12px;">
            DETALLE DE TRANSACCIONES (<?php echo count($ventas); ?> registros)
        </td>
    </tr>
    <tr class="header-secondary">
        <th style="width: 8%;">Factura</th>
        <th style="width: 8%;">Fecha</th>
        <th style="width: 6%;">Hora</th>
        <th style="width: 15%;">Cliente</th>
        <th style="width: 8%;">Documento</th>
        <th style="width: 10%;">Vendedor</th>
        <th style="width: 6%;">Tipo</th>
        <th style="width: 8%;">Pago</th>
        <th style="width: 8%;" class="text-right">Subtotal</th>
        <th style="width: 6%;" class="text-right">Desc.</th>
        <th style="width: 6%;" class="text-right">Imp.</th>
        <th style="width: 8%;" class="text-right">Total</th>
        <th style="width: 6%;" class="text-right">Recibido</th>
        <th style="width: 7%;">Estado</th>
    </tr>
    
    <?php 
    $sum_subtotal = 0;
    $sum_descuento = 0;
    $sum_impuesto = 0;
    $sum_total = 0;
    $sum_recibido = 0;
    $sum_cambio = 0;
    $sum_abono = 0;
    $sum_credito = 0;
    
    if (count($ventas) > 0):
        foreach ($ventas as $venta): 
            if (!$venta['anulada']) {
                $sum_subtotal += $venta['subtotal'];
                $sum_descuento += $venta['descuento'];
                $sum_impuesto += $venta['impuesto'];
                $sum_total += $venta['total'];
                $sum_recibido += $venta['monto_recibido'];
                $sum_cambio += $venta['cambio'];
                $sum_abono += $venta['abono_inicial'];
                
                if ($venta['tipo_venta'] == 'credito') {
                    $sum_credito += $venta['total'];
                }
            }
            
            // Determinar clase del estado
            $badge_class = '';
            $estado_text = '';
            
            if ($venta['anulada']) {
                $badge_class = 'badge-anulada';
                $estado_text = 'ANULADA';
            } elseif ($venta['estado'] == 'completada') {
                $badge_class = 'badge-completada';
                $estado_text = 'COMPLETADA';
            } elseif ($venta['estado'] == 'pendiente') {
                $badge_class = 'badge-pendiente';
                $estado_text = 'PENDIENTE';
            } elseif ($venta['estado'] == 'cancelada') {
                $badge_class = 'badge-anulada';
                $estado_text = 'CANCELADA';
            } else {
                $badge_class = 'badge-pendiente';
                $estado_text = strtoupper($venta['estado']);
            }
            
            // Para créditos, mostrar información adicional
            if ($venta['tipo_venta'] == 'credito' && !$venta['anulada']) {
                $badge_class = 'badge-credito';
                $saldo_pendiente = $venta['total'] - $venta['abono_inicial'];
                if ($venta['abono_inicial'] > 0) {
                    $estado_text = 'CRÉDITO (ABONO)';
                } else {
                    $estado_text = 'CRÉDITO';
                }
                if ($saldo_pendiente > 0) {
                    $estado_text .= '<br><small>Saldo: $' . number_format($saldo_pendiente, 0, ',', '.') . '</small>';
                }
            }
    ?>
    <tr <?php echo $venta['anulada'] ? 'style="background-color: #fff5f5;"' : ''; ?>>
        <td style="font-weight: 500; color: #2d3748;"><?php echo $venta['numero_factura']; ?></td>
        <td><?php echo date('d/m/Y', strtotime($venta['fecha'])); ?></td>
        <td><?php echo date('H:i', strtotime($venta['fecha'])); ?></td>
        <td style="color: #4a5568;"><?php echo htmlspecialchars($venta['cliente_nombre']); ?></td>
        <td><?php echo $venta['cliente_documento']; ?></td>
        <td style="color: #718096;"><?php echo $venta['usuario_nombre']; ?></td>
        <td class="text-center">
            <?php if ($venta['tipo_venta'] == 'credito'): ?>
                <span style="color: #2b6cb0; font-weight: 500;">CRÉD</span>
            <?php else: ?>
                <span style="color: #38a169; font-weight: 500;">CONT</span>
            <?php endif; ?>
        </td>
        <td class="text-center">
            <?php 
            $metodo = strtoupper(substr($venta['metodo_pago'] ?? 'EFECTIVO', 0, 3));
            $metodo_color = '#718096';
            if (strpos(strtolower($venta['metodo_pago'] ?? ''), 'efectivo') !== false) $metodo_color = '#38a169';
            if (strpos(strtolower($venta['metodo_pago'] ?? ''), 'tarjeta') !== false) $metodo_color = '#d69e2e';
            if (strpos(strtolower($venta['metodo_pago'] ?? ''), 'transferencia') !== false) $metodo_color = '#3182ce';
            ?>
            <span style="color: <?php echo $metodo_color; ?>; font-weight: 500;"><?php echo $metodo; ?></span>
        </td>
        <td class="text-right numeric-cell">$<?php echo number_format($venta['subtotal'], 0, ',', '.'); ?></td>
        <td class="text-right numeric-cell" style="color: #d69e2e;">$<?php echo number_format($venta['descuento'], 0, ',', '.'); ?></td>
        <td class="text-right numeric-cell">$<?php echo number_format($venta['impuesto'], 0, ',', '.'); ?></td>
        <td class="text-right numeric-cell" style="font-weight: 600; color: #2c5282;">$<?php echo number_format($venta['total'], 0, ',', '.'); ?></td>
        <td class="text-right numeric-cell" style="color: #38a169;">$<?php echo number_format($venta['monto_recibido'], 0, ',', '.'); ?></td>
        <td class="text-center">
            <span class="badge <?php echo $badge_class; ?>"><?php echo $estado_text; ?></span>
        </td>
    </tr>
    <?php 
        endforeach;
    else: 
    ?>
    <tr>
        <td colspan="14" class="text-center" style="padding: 20px; color: #a0aec0;">
            No hay ventas registradas en el período seleccionado
        </td>
    </tr>
    <?php endif; ?>
    
    <!-- TOTALES -->
    <tr class="total-row">
        <td colspan="8" class="text-bold" style="text-align: right; padding-right: 15px;">
            TOTALES GENERALES
        </td>
        <td class="text-right numeric-cell" style="font-weight: 700;">$<?php echo number_format($sum_subtotal, 0, ',', '.'); ?></td>
        <td class="text-right numeric-cell" style="font-weight: 700; color: #d69e2e;">$<?php echo number_format($sum_descuento, 0, ',', '.'); ?></td>
        <td class="text-right numeric-cell" style="font-weight: 700;">$<?php echo number_format($sum_impuesto, 0, ',', '.'); ?></td>
        <td class="text-right numeric-cell" style="font-weight: 700; color: #2c5282;">$<?php echo number_format($sum_total, 0, ',', '.'); ?></td>
        <td class="text-right numeric-cell" style="font-weight: 700; color: #38a169;">$<?php echo number_format($sum_recibido, 0, ',', '.'); ?></td>
        <td></td>
    </tr>
</table>

<!-- ANÁLISIS POR TIPO Y MÉTODO -->
<table style="margin-bottom: 25px; width: 100%;">
    <tr>
        <td style="width: 50%; vertical-align: top; padding-right: 10px;">
            <!-- ANÁLISIS POR TIPO DE VENTA -->
            <?php
            try {
                $sql_tipos = "SELECT 
                    CASE 
                        WHEN tipo_venta = 'credito' THEN 'CRÉDITO'
                        ELSE 'CONTADO'
                    END as tipo,
                    COUNT(*) as cantidad,
                    SUM(total) as total,
                    SUM(abono_inicial) as abonos,
                    AVG(total) as promedio
                FROM ventas 
                WHERE DATE(fecha) BETWEEN :fecha_inicio AND :fecha_fin 
                AND anulada = 0
                GROUP BY CASE 
                    WHEN tipo_venta = 'credito' THEN 'CRÉDITO'
                    ELSE 'CONTADO'
                END";
                
                $stmt_tipos = $db->prepare($sql_tipos);
                $stmt_tipos->execute([':fecha_inicio' => $fecha_inicio, ':fecha_fin' => $fecha_fin]);
                $tipos = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($tipos) > 0):
            ?>
            <table style="width: 100%; margin-bottom: 0;">
                <tr>
                    <td colspan="5" class="header" style="padding: 8px; font-size: 11px;">
                        ANÁLISIS POR TIPO DE VENTA
                    </td>
                </tr>
                <tr class="header-secondary">
                    <th style="width: 25%;">Tipo</th>
                    <th style="width: 20%;" class="text-center">Cantidad</th>
                    <th style="width: 25%;" class="text-right">Total</th>
                    <th style="width: 15%;" class="text-center">Abonos</th>
                    <th style="width: 15%;" class="text-center">%</th>
                </tr>
                <?php foreach ($tipos as $tipo): 
                    $porcentaje = $sum_total > 0 ? ($tipo['total'] / $sum_total) * 100 : 0;
                    $deuda_tipo = $tipo['tipo'] == 'CRÉDITO' ? ($tipo['total'] - $tipo['abonos']) : 0;
                ?>
                <tr>
                    <td style="font-weight: 500; color: #4a5568;">
                        <?php echo $tipo['tipo']; ?>
                    </td>
                    <td class="text-center"><?php echo $tipo['cantidad']; ?></td>
                    <td class="text-right numeric-cell">
                        <span style="color: #2c5282;">$<?php echo number_format($tipo['total'], 0, ',', '.'); ?></span>
                    </td>
                    <td class="text-center numeric-cell">
                        <?php if ($tipo['tipo'] == 'CRÉDITO'): ?>
                            <span style="color: #38a169;">$<?php echo number_format($tipo['abonos'], 0, ',', '.'); ?></span>
                        <?php else: ?>
                            <span class="zero-value">N/A</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center" style="font-weight: 500; color: #3182ce;">
                        <?php echo number_format($porcentaje, 1); ?>%
                    </td>
                </tr>
                <?php if ($tipo['tipo'] == 'CRÉDITO' && $deuda_tipo > 0): ?>
                <tr style="background-color: #fff5f5;">
                    <td colspan="3" class="text-right" style="font-size: 9px; color: #e53e3e;">
                        <strong>Saldo pendiente de crédito:</strong>
                    </td>
                    <td colspan="2" class="text-center" style="font-size: 9px; color: #e53e3e; font-weight: 600;">
                        $<?php echo number_format($deuda_tipo, 0, ',', '.'); ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </table>
            <?php 
                endif;
            } catch (Exception $e) {
                // Silenciar error
            }
            ?>
        </td>
        
        <td style="width: 50%; vertical-align: top; padding-left: 10px;">
            <!-- ANÁLISIS POR MÉTODO DE PAGO -->
            <?php
            try {
                $sql_metodos = "SELECT 
                    COALESCE(metodo_pago, 'efectivo') as metodo,
                    COUNT(*) as cantidad,
                    SUM(total) as total
                FROM ventas 
                WHERE DATE(fecha) BETWEEN :fecha_inicio AND :fecha_fin 
                AND anulada = 0
                GROUP BY COALESCE(metodo_pago, 'efectivo')";
                
                $stmt_metodos = $db->prepare($sql_metodos);
                $stmt_metodos->execute([':fecha_inicio' => $fecha_inicio, ':fecha_fin' => $fecha_fin]);
                $metodos = $stmt_metodos->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($metodos) > 0):
            ?>
            <table style="width: 100%; margin-bottom: 0;">
                <tr>
                    <td colspan="4" class="header" style="padding: 8px; font-size: 11px;">
                        DISTRIBUCIÓN POR MÉTODO DE PAGO
                    </td>
                </tr>
                <tr class="header-secondary">
                    <th style="width: 40%;">Método</th>
                    <th style="width: 25%;" class="text-center">Cantidad</th>
                    <th style="width: 35%;" class="text-right">Total</th>
                </tr>
                <?php foreach ($metodos as $metodo): 
                    $porcentaje = $sum_total > 0 ? ($metodo['total'] / $sum_total) * 100 : 0;
                ?>
                <tr>
                    <td style="font-weight: 500; color: #4a5568;">
                        <?php 
                        $metodo_nombre = strtoupper($metodo['metodo']);
                        if ($metodo_nombre == 'EFECTIVO') echo '💰 EFECTIVO';
                        elseif ($metodo_nombre == 'TARJETA') echo '💳 TARJETA';
                        elseif ($metodo_nombre == 'TRANSFERENCIA') echo '🏦 TRANSFERENCIA';
                        elseif ($metodo_nombre == 'MIXTO') echo '🔄 MIXTO';
                        else echo $metodo_nombre;
                        ?>
                    </td>
                    <td class="text-center"><?php echo $metodo['cantidad']; ?></td>
                    <td class="text-right numeric-cell">
                        $<?php echo number_format($metodo['total'], 0, ',', '.'); ?>
                        <div style="font-size: 9px; color: #718096;"><?php echo number_format($porcentaje, 1); ?>%</div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php 
                endif;
            } catch (Exception $e) {
                // Silenciar error
            }
            ?>
        </td>
    </tr>
</table>

<!-- RESUMEN FINAL DE LIQUIDEZ -->
<table style="margin-bottom: 20px; border: 2px solid #2c5282;">
    <tr>
        <td colspan="4" class="header" style="padding: 12px; font-size: 12px;">
            RESUMEN FINAL DE LIQUIDEZ
        </td>
    </tr>
    <tr>
        <td style="width: 25%; text-align: center; padding: 15px 5px; background-color: #ebf8ff;">
            <div style="font-size: 11px; color: #4a5568; margin-bottom: 5px;">TOTAL FACTURADO</div>
            <div style="font-size: 16px; font-weight: 700; color: #2c5282;">
                $<?php echo number_format($sum_total, 0, ',', '.'); ?>
            </div>
        </td>
        <td style="width: 25%; text-align: center; padding: 15px 5px; background-color: #f0fff4;">
            <div style="font-size: 11px; color: #4a5568; margin-bottom: 5px;">INGRESOS REALES</div>
            <div style="font-size: 16px; font-weight: 700; color: #38a169;">
                $<?php echo number_format($ingresos_reales, 0, ',', '.'); ?>
            </div>
        </td>
        <td style="width: 25%; text-align: center; padding: 15px 5px; background-color: #fefcbf;">
            <div style="font-size: 11px; color: #4a5568; margin-bottom: 5px;">EFECTIVO EN CAJA</div>
            <div style="font-size: 16px; font-weight: 700; color: #d69e2e;">
                $<?php echo number_format($sum_recibido - $sum_cambio, 0, ',', '.'); ?>
            </div>
        </td>
        <td style="width: 25%; text-align: center; padding: 15px 5px; <?php echo $deuda_pendiente > 0 ? 'background-color: #fff5f5;' : 'background-color: #f0fff4;'; ?>">
            <div style="font-size: 11px; color: #4a5568; margin-bottom: 5px;">DEUDA POR COBRAR</div>
            <div style="font-size: 16px; font-weight: 700; <?php echo $deuda_pendiente > 0 ? 'color: #e53e3e;' : 'color: #38a169;'; ?>">
                <?php if ($deuda_pendiente > 0): ?>
                    $<?php echo number_format($deuda_pendiente, 0, ',', '.'); ?>
                <?php else: ?>
                    $0
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <tr>
        <td colspan="4" style="padding: 8px; background-color: #f7fafc; font-size: 9px; color: #718096; text-align: center;">
            <strong>Nota:</strong> Deuda por cobrar se refiere únicamente a saldos pendientes de ventas a crédito después de aplicar descuentos y abonos.
        </td>
    </tr>
</table>

<!-- PIE DE PÁGINA -->
<table style="border: none; margin-top: 25px;">
    <tr>
        <td style="border: none; padding: 15px; background-color: #f7fafc; border-radius: 4px;">
            <div class="footer">
                <div style="color: #2c5282; font-weight: 600; margin-bottom: 5px;">VALENTINA ROJAS • SISTEMA POS</div>
                <div style="color: #718096; font-size: 9px; line-height: 1.4;">
                    <strong>Reporte generado:</strong> <?php echo date('d/m/Y H:i:s'); ?> • 
                    <strong>Período analizado:</strong> <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?> • 
                    <strong>Ventas analizadas:</strong> <?php echo count($ventas); ?> transacciones<br>
                    <strong>Notas:</strong> Los valores están en pesos colombianos (COP) • Ventas anuladas no se incluyen en los totales • 
                    Ingresos reales = Total facturado - Descuentos aplicados • Deuda por cobrar = Total créditos - Abonos recibidos • 
                    Efectivo en caja = Monto recibido - Cambio entregado
                </div>
            </div>
        </td>
    </tr>
</table>

</body>
</html>