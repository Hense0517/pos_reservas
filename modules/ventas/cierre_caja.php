<?php
// modules/ventas/cierre_caja.php
require_once '../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

// Verificar permisos
if (!$auth->hasPermission('ventas', 'leer')) {
    header('Location: ' . BASE_URL . 'index.php?error=permiso_denegado');
    exit;
}

// Fecha
$fecha = $_GET['fecha'] ?? date('Y-m-d');
$fecha_formateada = date('d/m/Y', strtotime($fecha));

// Conexión a BD
$database = Database::getInstance();
$db = $database->getConnection();

// PROCESAR BASE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_base'])) {
    $base_dia = floatval($_POST['base_dia']);
    
    $query_check = "SELECT id FROM base_caja_diaria WHERE fecha = ?";
    $stmt_check = $db->prepare($query_check);
    $stmt_check->execute([$fecha]);
    
    if ($stmt_check->fetch()) {
        $query = "UPDATE base_caja_diaria SET base_dia = ?, usuario_id = ?, actualizado_en = NOW() WHERE fecha = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$base_dia, $_SESSION['usuario_id'], $fecha]);
        $_SESSION['success'] = "Base actualizada correctamente";
    } else {
        $query = "INSERT INTO base_caja_diaria (fecha, base_dia, usuario_id, creado_en) VALUES (?, ?, ?, NOW())";
        $stmt = $db->prepare($query);
        $stmt->execute([$fecha, $base_dia, $_SESSION['usuario_id']]);
        $_SESSION['success'] = "Base registrada correctamente";
    }
    
    header("Location: cierre_caja.php?fecha=$fecha");
    exit;
}

// FUNCIÓN obtenerResumenCaja (se mantiene igual)
function obtenerResumenCaja($db, $fecha) {
    try {
        $query_base = "SELECT base_dia FROM base_caja_diaria WHERE fecha = ?";
        $stmt_base = $db->prepare($query_base);
        $stmt_base->execute([$fecha]);
        $base_data = $stmt_base->fetch();
        $base_dia = $base_data['base_dia'] ?? 0;
        
        $query_efectivo = "
            SELECT COALESCE(SUM(
                CASE 
                    WHEN (tipo_venta = 'contado' OR tipo_venta IS NULL) AND metodo_pago = 'efectivo' THEN total
                    WHEN tipo_venta = 'credito' AND metodo_pago = 'efectivo' THEN abono_inicial
                    ELSE 0 
                END
            ), 0) as efectivo_ventas
            FROM ventas 
            WHERE DATE(fecha) = ? AND anulada = 0
        ";
        $stmt_efectivo = $db->prepare($query_efectivo);
        $stmt_efectivo->execute([$fecha]);
        $efectivo_ventas = $stmt_efectivo->fetchColumn();
        
        $query_pagos_efectivo = "
            SELECT COALESCE(SUM(p.monto), 0) 
            FROM pagos_cuentas_por_cobrar p
            JOIN cuentas_por_cobrar cc ON p.cuenta_id = cc.id
            JOIN ventas v ON cc.venta_id = v.id
            WHERE DATE(p.fecha_pago) = ? 
            AND p.tipo_pago = 'pago_deuda'
            AND p.metodo_pago = 'efectivo'
            AND DATE(v.fecha) != DATE(p.fecha_pago)
        ";
        $stmt_pagos = $db->prepare($query_pagos_efectivo);
        $stmt_pagos->execute([$fecha]);
        $pagos_efectivo = $stmt_pagos->fetchColumn();
        
        $query_gastos = "SELECT COALESCE(SUM(monto), 0) FROM gastos WHERE DATE(fecha) = ?";
        $stmt_gastos = $db->prepare($query_gastos);
        $stmt_gastos->execute([$fecha]);
        $gastos = $stmt_gastos->fetchColumn();
        
        $query_mixtos_efectivo = "
            SELECT COALESCE(SUM(pmd.monto), 0)
            FROM pagos_mixtos_detalles pmd
            JOIN ventas v ON pmd.venta_id = v.id
            WHERE DATE(v.fecha) = ? 
            AND v.anulada = 0
            AND pmd.metodo = 'efectivo'
        ";
        $stmt_mixtos_efectivo = $db->prepare($query_mixtos_efectivo);
        $stmt_mixtos_efectivo->execute([$fecha]);
        $mixto_efectivo = $stmt_mixtos_efectivo->fetchColumn();
        
        $query_transferencias = "
            SELECT 
                COALESCE(SUM(CASE WHEN metodo_pago IN ('transferencia', 'consignacion') THEN total ELSE 0 END), 0) +
                COALESCE(SUM(CASE WHEN tipo_venta = 'credito' AND metodo_pago IN ('transferencia', 'consignacion') THEN abono_inicial ELSE 0 END), 0) +
                COALESCE((SELECT SUM(monto) FROM pagos_cuentas_por_cobrar WHERE DATE(fecha_pago) = ? AND metodo_pago IN ('transferencia', 'consignacion') AND tipo_pago = 'pago_deuda'), 0) +
                COALESCE((SELECT SUM(pmd.monto) FROM pagos_mixtos_detalles pmd JOIN ventas v ON pmd.venta_id = v.id WHERE DATE(v.fecha) = ? AND v.anulada = 0 AND pmd.metodo IN ('transferencia', 'consignacion')), 0)
            FROM ventas 
            WHERE DATE(fecha) = ? AND anulada = 0
        ";
        $stmt_trans = $db->prepare($query_transferencias);
        $stmt_trans->execute([$fecha, $fecha, $fecha]);
        $transferencias_total = $stmt_trans->fetchColumn();
        
        $query_tarjetas = "
            SELECT 
                COALESCE(SUM(CASE WHEN metodo_pago = 'tarjeta' THEN total ELSE 0 END), 0) +
                COALESCE(SUM(CASE WHEN tipo_venta = 'credito' AND metodo_pago = 'tarjeta' THEN abono_inicial ELSE 0 END), 0) +
                COALESCE((SELECT SUM(monto) FROM pagos_cuentas_por_cobrar WHERE DATE(fecha_pago) = ? AND metodo_pago = 'tarjeta' AND tipo_pago = 'pago_deuda'), 0) +
                COALESCE((SELECT SUM(pmd.monto) FROM pagos_mixtos_detalles pmd JOIN ventas v ON pmd.venta_id = v.id WHERE DATE(v.fecha) = ? AND v.anulada = 0 AND pmd.metodo = 'tarjeta'), 0)
            FROM ventas 
            WHERE DATE(fecha) = ? AND anulada = 0
        ";
        $stmt_tarj = $db->prepare($query_tarjetas);
        $stmt_tarj->execute([$fecha, $fecha, $fecha]);
        $tarjetas_total = $stmt_tarj->fetchColumn();
        
        $query_otros = "
            SELECT COALESCE(SUM(pmd.monto), 0)
            FROM pagos_mixtos_detalles pmd
            JOIN ventas v ON pmd.venta_id = v.id
            WHERE DATE(v.fecha) = ? 
            AND v.anulada = 0
            AND pmd.metodo = 'otro'
        ";
        $stmt_otros = $db->prepare($query_otros);
        $stmt_otros->execute([$fecha]);
        $otros_total = $stmt_otros->fetchColumn();
        
        $query_total_ventas = "
            SELECT COALESCE(SUM(total), 0) FROM ventas WHERE DATE(fecha) = ? AND anulada = 0
        ";
        $stmt_total = $db->prepare($query_total_ventas);
        $stmt_total->execute([$fecha]);
        $total_ventas = $stmt_total->fetchColumn();
        
        $query_contado = "
            SELECT COALESCE(SUM(total), 0) FROM ventas WHERE DATE(fecha) = ? AND anulada = 0 AND (tipo_venta = 'contado' OR tipo_venta IS NULL)
        ";
        $stmt_contado = $db->prepare($query_contado);
        $stmt_contado->execute([$fecha]);
        $ventas_contado = $stmt_contado->fetchColumn();
        
        $query_credito = "
            SELECT COALESCE(SUM(total), 0) FROM ventas WHERE DATE(fecha) = ? AND anulada = 0 AND tipo_venta = 'credito'
        ";
        $stmt_credito = $db->prepare($query_credito);
        $stmt_credito->execute([$fecha]);
        $ventas_credito = $stmt_credito->fetchColumn();
        
        $ingresos_efectivo_total = $efectivo_ventas + $pagos_efectivo + $mixto_efectivo;
        $total_efectivo_caja = $base_dia + $ingresos_efectivo_total - $gastos;
        $dinero_en_bancos = $transferencias_total + $tarjetas_total + $otros_total;
        
        return [
            'base_dia' => $base_dia,
            'efectivo_ventas' => $efectivo_ventas,
            'pagos_efectivo' => $pagos_efectivo,
            'gastos' => $gastos,
            'mixto_efectivo' => $mixto_efectivo,
            'transferencias_total' => $transferencias_total,
            'tarjetas_total' => $tarjetas_total,
            'otros_total' => $otros_total,
            'ingresos_efectivo_total' => $ingresos_efectivo_total,
            'total_efectivo_caja' => $total_efectivo_caja,
            'total_ventas' => $total_ventas,
            'ventas_contado' => $ventas_contado,
            'ventas_credito' => $ventas_credito,
            'dinero_en_bancos' => $dinero_en_bancos
        ];
        
    } catch (Exception $e) {
        error_log("Error en obtenerResumenCaja: " . $e->getMessage());
        return [
            'base_dia' => 0,
            'efectivo_ventas' => 0,
            'pagos_efectivo' => 0,
            'gastos' => 0,
            'mixto_efectivo' => 0,
            'transferencias_total' => 0,
            'tarjetas_total' => 0,
            'otros_total' => 0,
            'ingresos_efectivo_total' => 0,
            'total_efectivo_caja' => 0,
            'total_ventas' => 0,
            'ventas_contado' => 0,
            'ventas_credito' => 0,
            'dinero_en_bancos' => 0
        ];
    }
}

$resumen = obtenerResumenCaja($db, $fecha);

// Mensajes
if (isset($_SESSION['success'])) {
    $mensaje_exito = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $mensaje_error = $_SESSION['error'];
    unset($_SESSION['error']);
}

$page_title = "Cierre de Caja - " . $fecha_formateada;
include '../../includes/header.php';
?>

<!-- Estilos específicos para cierre de caja - MODERNO Y FRESCO -->
<style>
    body {
        background: linear-gradient(135deg, #f6f9fc 0%, #edf2f7 100%);
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    }
    
    .cierre-container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 20px;
    }
    
    /* Header moderno */
    .cierre-header {
        background: white;
        border-radius: 20px;
        padding: 25px 30px;
        margin-bottom: 30px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
        border: 1px solid rgba(255, 255, 255, 0.5);
        backdrop-filter: blur(10px);
    }
    
    .cierre-title {
        font-size: 28px;
        font-weight: 700;
        background: linear-gradient(135deg, #1e293b, #0f172a);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        letter-spacing: -0.5px;
    }
    
    .cierre-subtitle {
        color: #64748b;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 15px;
        margin-top: 5px;
    }
    
    .cierre-subtitle i {
        color: #3b82f6;
    }
    
    .badge-fecha {
        background: #eef2ff;
        color: #4f46e5;
        padding: 6px 12px;
        border-radius: 30px;
        font-size: 13px;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }
    
    /* Tarjetas principales */
    .cierre-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 25px;
        margin-bottom: 30px;
    }
    
    .cierre-card {
        background: white;
        border-radius: 24px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        transition: all 0.3s ease;
        border: 1px solid rgba(226, 232, 240, 0.6);
    }
    
    .cierre-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 25px 45px rgba(79, 70, 229, 0.1);
    }
    
    .cierre-card-header {
        padding: 18px 25px;
        font-weight: 600;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #edf2f7;
    }
    
    .cierre-card-header i {
        font-size: 20px;
    }
    
    /* Colores específicos por tipo de tarjeta */
    .card-efectivo .cierre-card-header {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
    }
    
    .card-bancos .cierre-card-header {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white;
    }
    
    .card-ventas .cierre-card-header {
        background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        color: white;
    }
    
    .cierre-card-body {
        padding: 25px;
    }
    
    /* Filas de información */
    .cierre-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 0;
        border-bottom: 1px dashed #e2e8f0;
    }
    
    .cierre-row:last-child {
        border-bottom: none;
    }
    
    .cierre-label {
        color: #475569;
        font-weight: 500;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .cierre-label i {
        width: 20px;
        color: #94a3b8;
        font-size: 14px;
    }
    
    .cierre-value {
        font-weight: 600;
        font-family: 'Inter', monospace;
        font-size: 16px;
        color: #1e293b;
    }
    
    /* Fila total */
    .cierre-total {
        background: #f8fafc;
        border-radius: 16px;
        padding: 18px 20px;
        margin-top: 15px;
        border: 2px solid #e2e8f0;
    }
    
    .cierre-total .cierre-label {
        color: #0f172a;
        font-weight: 700;
        font-size: 15px;
    }
    
    .cierre-total .cierre-value {
        font-size: 22px;
        font-weight: 700;
    }
    
    .cierre-total.card-efectivo .cierre-value {
        color: #059669;
    }
    
    .cierre-total.card-bancos .cierre-value {
        color: #2563eb;
    }
    
    .cierre-total.card-ventas .cierre-value {
        color: #7c3aed;
    }
    
    /* Botones modernos */
    .btn-cierre {
        padding: 14px 28px;
        border-radius: 50px;
        font-weight: 600;
        font-size: 14px;
        letter-spacing: 0.3px;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white;
    }
    
    .btn-primary:hover {
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(59, 130, 246, 0.4);
    }
    
    .btn-success {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
    }
    
    .btn-success:hover {
        background: linear-gradient(135deg, #059669, #047857);
        transform: translateY(-2px);
        box-shadow: 0 10px 25px rgba(16, 185, 129, 0.4);
    }
    
    .btn-secondary {
        background: white;
        color: #475569;
        border: 2px solid #e2e8f0;
    }
    
    .btn-secondary:hover {
        background: #f8fafc;
        border-color: #94a3b8;
        transform: translateY(-2px);
    }
    
    /* Mensajes de alerta */
    .alert {
        border-radius: 16px;
        padding: 16px 22px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-weight: 500;
        animation: slideIn 0.3s ease;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    
    .alert-error {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Modal moderno */
    .modal-moderno {
        background: white;
        border-radius: 30px;
        max-width: 450px;
        width: 90%;
        overflow: hidden;
        box-shadow: 0 50px 70px -20px rgba(0, 0, 0, 0.3);
        animation: modalIn 0.4s ease;
    }
    
    @keyframes modalIn {
        from {
            opacity: 0;
            transform: scale(0.9) translateY(20px);
        }
        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }
    
    .modal-header {
        background: linear-gradient(135deg, #10b981, #059669);
        color: white;
        padding: 25px 30px;
    }
    
    .modal-header h3 {
        font-size: 22px;
        font-weight: 700;
        margin-bottom: 5px;
    }
    
    .modal-body {
        padding: 30px;
    }
    
    .input-moderno {
        width: 100%;
        padding: 16px 20px;
        border: 2px solid #e2e8f0;
        border-radius: 16px;
        font-size: 18px;
        font-weight: 600;
        font-family: 'Inter', monospace;
        transition: all 0.3s;
        background: #f8fafc;
    }
    
    .input-moderno:focus {
        outline: none;
        border-color: #10b981;
        box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
        background: white;
    }
    
    .input-moderno::placeholder {
        color: #cbd5e1;
        font-weight: 400;
        font-size: 16px;
    }
    
    /* Botones de acción flotantes */
    .action-buttons {
        position: sticky;
        bottom: 30px;
        display: flex;
        justify-content: center;
        gap: 15px;
        margin-top: 40px;
        padding: 20px;
        background: rgba(255, 255, 255, 0.9);
        backdrop-filter: blur(10px);
        border-radius: 60px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.5);
        max-width: 500px;
        margin-left: auto;
        margin-right: auto;
    }
    
    /* Versión impresión */
    @media print {
        body { background: white; }
        .no-print { display: none !important; }
        .print-only { display: block !important; }
        .cierre-card { 
            break-inside: avoid;
            box-shadow: none;
            border: 2px solid #000;
        }
        .cierre-card-header { 
            background: white !important;
            color: black !important;
            border-bottom: 2px solid #000;
        }
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .cierre-header {
            padding: 20px;
        }
        
        .cierre-title {
            font-size: 24px;
        }
        
        .cierre-grid {
            grid-template-columns: 1fr;
        }
        
        .action-buttons {
            flex-direction: column;
            border-radius: 30px;
        }
    }
</style>

<div class="cierre-container no-print">
    <!-- Header moderno -->
    <div class="cierre-header">
        <div class="flex justify-between items-center flex-wrap gap-4">
            <div>
                <h1 class="cierre-title">
                    <i class="fas fa-cash-register mr-3 text-3xl" style="background: linear-gradient(135deg, #3b82f6, #8b5cf6); -webkit-background-clip: text; -webkit-text-fill-color: transparent;"></i>
                    CIERRE DE CAJA
                </h1>
                <div class="cierre-subtitle">
                    <span><i class="far fa-calendar-alt"></i> <?php echo $fecha_formateada; ?></span>
                    <span><i class="far fa-user"></i> <?php echo $_SESSION['usuario_nombre'] ?? 'Usuario'; ?></span>
                </div>
            </div>
            <div class="flex gap-3">
                <span class="badge-fecha">
                    <i class="far fa-clock"></i>
                    <?php echo date('H:i'); ?>
                </span>
                <button onclick="mostrarModal()" class="btn-cierre btn-success">
                    <i class="fas fa-money-bill-wave mr-2"></i>
                    <?php echo $resumen['base_dia'] > 0 ? 'ACTUALIZAR BASE' : 'REGISTRAR BASE'; ?>
                </button>
            </div>
        </div>
        
        <?php if ($resumen['base_dia'] > 0): ?>
        <div class="mt-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-2xl p-4 border border-blue-100">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                    <i class="fas fa-info-circle text-blue-600 text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Base registrada para hoy:</p>
                    <p class="text-2xl font-bold text-blue-600">$ <?php echo number_format($resumen['base_dia'], 0, ',', '.'); ?></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Mensajes -->
    <?php if (isset($mensaje_exito)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle text-xl"></i>
            <span><?php echo $mensaje_exito; ?></span>
        </div>
    <?php endif; ?>
    
    <?php if (isset($mensaje_error)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle text-xl"></i>
            <span><?php echo $mensaje_error; ?></span>
        </div>
    <?php endif; ?>

    <!-- Grid de tarjetas -->
    <div class="cierre-grid">
        <!-- EFECTIVO EN CAJA -->
        <div class="cierre-card card-efectivo">
            <div class="cierre-card-header">
                <i class="fas fa-money-bill-wave"></i>
                EFECTIVO EN CAJA
            </div>
            <div class="cierre-card-body">
                <div class="cierre-row">
                    <span class="cierre-label"><i class="fas fa-flag"></i> Base inicial:</span>
                    <span class="cierre-value">$ <?php echo number_format($resumen['base_dia'], 0, ',', '.'); ?></span>
                </div>
                <div class="cierre-row">
                    <span class="cierre-label"><i class="fas fa-shopping-cart"></i> Ventas efectivo:</span>
                    <span class="cierre-value">$ <?php echo number_format($resumen['efectivo_ventas'], 0, ',', '.'); ?></span>
                </div>
                <div class="cierre-row">
                    <span class="cierre-label"><i class="fas fa-hand-holding-usd"></i> Pagos deudas:</span>
                    <span class="cierre-value">$ <?php echo number_format($resumen['pagos_efectivo'], 0, ',', '.'); ?></span>
                </div>
                <div class="cierre-row">
                    <span class="cierre-label"><i class="fas fa-random"></i> Pagos mixtos:</span>
                    <span class="cierre-value">$ <?php echo number_format($resumen['mixto_efectivo'], 0, ',', '.'); ?></span>
                </div>
                <div class="cierre-row" style="border-bottom: 2px solid #10b981;">
                    <span class="cierre-label"><i class="fas fa-arrow-down"></i> Total ingresos:</span>
                    <span class="cierre-value font-bold text-emerald-600">$ <?php echo number_format($resumen['ingresos_efectivo_total'], 0, ',', '.'); ?></span>
                </div>
                <div class="cierre-row">
                    <span class="cierre-label"><i class="fas fa-minus-circle"></i> Gastos del día:</span>
                    <span class="cierre-value text-rose-600">$ <?php echo number_format($resumen['gastos'], 0, ',', '.'); ?></span>
                </div>
                
                <div class="cierre-total card-efectivo">
                    <div class="flex justify-between items-center">
                        <span class="cierre-label"><i class="fas fa-calculator"></i> TOTAL EFECTIVO:</span>
                        <span class="cierre-value">$ <?php echo number_format($resumen['total_efectivo_caja'], 0, ',', '.'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- DINERO EN BANCOS -->
        <div class="cierre-card card-bancos">
            <div class="cierre-card-header">
                <i class="fas fa-university"></i>
                DINERO EN BANCOS
            </div>
            <div class="cierre-card-body">
                <div class="cierre-row">
                    <span class="cierre-label"><i class="fas fa-exchange-alt"></i> Transferencias:</span>
                    <span class="cierre-value">$ <?php echo number_format($resumen['transferencias_total'], 0, ',', '.'); ?></span>
                </div>
                <div class="cierre-row">
                    <span class="cierre-label"><i class="fas fa-credit-card"></i> Tarjetas:</span>
                    <span class="cierre-value">$ <?php echo number_format($resumen['tarjetas_total'], 0, ',', '.'); ?></span>
                </div>
                <?php if ($resumen['otros_total'] > 0): ?>
                <div class="cierre-row">
                    <span class="cierre-label"><i class="fas fa-ellipsis-h"></i> Otros métodos:</span>
                    <span class="cierre-value">$ <?php echo number_format($resumen['otros_total'], 0, ',', '.'); ?></span>
                </div>
                <?php endif; ?>
                
                <div class="cierre-total card-bancos">
                    <div class="flex justify-between items-center">
                        <span class="cierre-label"><i class="fas fa-piggy-bank"></i> TOTAL BANCOS:</span>
                        <span class="cierre-value">$ <?php echo number_format($resumen['dinero_en_bancos'], 0, ',', '.'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- VENTAS DEL DÍA -->
        <div class="cierre-card card-ventas">
            <div class="cierre-card-header">
                <i class="fas fa-chart-line"></i>
                VENTAS DEL DÍA
            </div>
            <div class="cierre-card-body">
                <div class="cierre-row">
                    <span class="cierre-label"><i class="fas fa-hand-holding-usd"></i> Al contado:</span>
                    <span class="cierre-value">$ <?php echo number_format($resumen['ventas_contado'], 0, ',', '.'); ?></span>
                </div>
                <div class="cierre-row">
                    <span class="cierre-label"><i class="fas fa-credit-card"></i> A crédito:</span>
                    <span class="cierre-value">$ <?php echo number_format($resumen['ventas_credito'], 0, ',', '.'); ?></span>
                </div>
                
                <div class="cierre-total card-ventas">
                    <div class="flex justify-between items-center">
                        <span class="cierre-label"><i class="fas fa-chart-pie"></i> TOTAL VENTAS:</span>
                        <span class="cierre-value">$ <?php echo number_format($resumen['total_ventas'], 0, ',', '.'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Resumen rápido adicional -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <div class="bg-gradient-to-br from-emerald-50 to-emerald-100 rounded-2xl p-5 border border-emerald-200">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-emerald-500 rounded-full flex items-center justify-center text-white">
                    <i class="fas fa-cash-register text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-emerald-700 font-medium">Total en caja</p>
                    <p class="text-2xl font-bold text-emerald-800">$ <?php echo number_format($resumen['total_efectivo_caja'], 0, ',', '.'); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-2xl p-5 border border-blue-200">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-blue-500 rounded-full flex items-center justify-center text-white">
                    <i class="fas fa-university text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-blue-700 font-medium">En bancos</p>
                    <p class="text-2xl font-bold text-blue-800">$ <?php echo number_format($resumen['dinero_en_bancos'], 0, ',', '.'); ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-gradient-to-br from-violet-50 to-violet-100 rounded-2xl p-5 border border-violet-200">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 bg-violet-500 rounded-full flex items-center justify-center text-white">
                    <i class="fas fa-chart-line text-xl"></i>
                </div>
                <div>
                    <p class="text-sm text-violet-700 font-medium">Total ventas</p>
                    <p class="text-2xl font-bold text-violet-800">$ <?php echo number_format($resumen['total_ventas'], 0, ',', '.'); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- BOTONES DE ACCIÓN FLOTANTES -->
    <div class="action-buttons no-print">
        <button onclick="window.print()" class="btn-cierre btn-primary">
            <i class="fas fa-print"></i> IMPRIMIR
        </button>
        <a href="index.php" class="btn-cierre btn-secondary">
            <i class="fas fa-arrow-left"></i> VOLVER
        </a>
    </div>
</div>

<!-- MODAL MODERNO PARA BASE -->
<div id="modalBase" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" style="display: none; backdrop-filter: blur(5px);">
    <div class="modal-moderno">
        <div class="modal-header">
            <h3>
                <i class="fas fa-money-bill-wave mr-2"></i>
                <?php echo $resumen['base_dia'] > 0 ? 'ACTUALIZAR BASE' : 'REGISTRAR BASE'; ?>
            </h3>
            <p class="text-white text-opacity-90 text-sm">Fecha: <?php echo $fecha_formateada; ?></p>
        </div>
        
        <div class="modal-body">
            <form method="POST">
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-3">
                        <i class="fas fa-dollar-sign mr-2 text-emerald-500"></i>
                        Efectivo inicial en caja
                    </label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-500 font-bold">$</span>
                        <input type="number" 
                               step="0.01" 
                               name="base_dia" 
                               value="<?php echo $resumen['base_dia']; ?>"
                               required
                               class="input-moderno pl-10"
                               placeholder="0.00"
                               autofocus>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        Ingresa el dinero con el que iniciaste la caja hoy
                    </p>
                </div>
                
                <div class="flex gap-3">
                    <button type="button" 
                            onclick="cerrarModal()"
                            class="btn-cierre btn-secondary flex-1">
                        CANCELAR
                    </button>
                    <button type="submit" 
                            name="guardar_base"
                            class="btn-cierre btn-success flex-1">
                        <i class="fas fa-save mr-2"></i>
                        <?php echo $resumen['base_dia'] > 0 ? 'ACTUALIZAR' : 'GUARDAR'; ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- VERSIÓN IMPRESIÓN (mejorada) -->
<div class="print-only" style="display: none; padding: 30px; font-family: 'Inter', monospace; max-width: 800px; margin: 0 auto;">
    <div style="text-align: center; margin-bottom: 40px;">
        <h1 style="font-size: 24px; font-weight: 800; color: #000;">CIERRE DE CAJA</h1>
        <div style="border-top: 3px solid #000; width: 100px; margin: 15px auto;"></div>
        <p style="font-size: 14px; color: #333;">Fecha: <?php echo $fecha_formateada; ?> | Usuario: <?php echo $_SESSION['usuario_nombre'] ?? 'Usuario'; ?></p>
        <p style="font-size: 12px; color: #666;">Generado: <?php echo date('d/m/Y H:i:s'); ?></p>
    </div>
    
    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
        <tr style="background: #f3f4f6;">
            <th colspan="2" style="padding: 15px; text-align: left; font-size: 16px; border-bottom: 2px solid #000;">EFECTIVO EN CAJA</th>
        </tr>
        <tr><td style="padding: 8px 15px;">Base inicial:</td><td style="padding: 8px 15px; text-align: right;">$ <?php echo number_format($resumen['base_dia'], 0, ',', '.'); ?></td></tr>
        <tr><td style="padding: 8px 15px;">Ventas efectivo:</td><td style="padding: 8px 15px; text-align: right;">$ <?php echo number_format($resumen['efectivo_ventas'], 0, ',', '.'); ?></td></tr>
        <tr><td style="padding: 8px 15px;">Pagos deudas:</td><td style="padding: 8px 15px; text-align: right;">$ <?php echo number_format($resumen['pagos_efectivo'], 0, ',', '.'); ?></td></tr>
        <tr><td style="padding: 8px 15px;">Efectivo mixto:</td><td style="padding: 8px 15px; text-align: right;">$ <?php echo number_format($resumen['mixto_efectivo'], 0, ',', '.'); ?></td></tr>
        <tr><td style="padding: 8px 15px; border-bottom: 2px solid #000;">Total ingresos:</td><td style="padding: 8px 15px; text-align: right; border-bottom: 2px solid #000;">$ <?php echo number_format($resumen['ingresos_efectivo_total'], 0, ',', '.'); ?></td></tr>
        <tr><td style="padding: 8px 15px;">- Gastos:</td><td style="padding: 8px 15px; text-align: right;">$ <?php echo number_format($resumen['gastos'], 0, ',', '.'); ?></td></tr>
        <tr style="background: #f3f4f6; font-weight: bold;">
            <td style="padding: 15px;">TOTAL EFECTIVO:</td>
            <td style="padding: 15px; text-align: right;">$ <?php echo number_format($resumen['total_efectivo_caja'], 0, ',', '.'); ?></td>
        </tr>
        
        <tr><td colspan="2" style="height: 20px;"></td></tr>
        
        <tr style="background: #f3f4f6;">
            <th colspan="2" style="padding: 15px; text-align: left; font-size: 16px; border-bottom: 2px solid #000;">DINERO EN BANCOS</th>
        </tr>
        <tr><td style="padding: 8px 15px;">Transferencias:</td><td style="padding: 8px 15px; text-align: right;">$ <?php echo number_format($resumen['transferencias_total'], 0, ',', '.'); ?></td></tr>
        <tr><td style="padding: 8px 15px;">Tarjetas:</td><td style="padding: 8px 15px; text-align: right;">$ <?php echo number_format($resumen['tarjetas_total'], 0, ',', '.'); ?></td></tr>
        <?php if ($resumen['otros_total'] > 0): ?>
        <tr><td style="padding: 8px 15px;">Otros:</td><td style="padding: 8px 15px; text-align: right;">$ <?php echo number_format($resumen['otros_total'], 0, ',', '.'); ?></td></tr>
        <?php endif; ?>
        <tr style="background: #f3f4f6; font-weight: bold;">
            <td style="padding: 15px;">TOTAL BANCOS:</td>
            <td style="padding: 15px; text-align: right;">$ <?php echo number_format($resumen['dinero_en_bancos'], 0, ',', '.'); ?></td>
        </tr>
        
        <tr><td colspan="2" style="height: 20px;"></td></tr>
        
        <tr style="background: #f3f4f6;">
            <th colspan="2" style="padding: 15px; text-align: left; font-size: 16px; border-bottom: 2px solid #000;">VENTAS DEL DÍA</th>
        </tr>
        <tr><td style="padding: 8px 15px;">Ventas contado:</td><td style="padding: 8px 15px; text-align: right;">$ <?php echo number_format($resumen['ventas_contado'], 0, ',', '.'); ?></td></tr>
        <tr><td style="padding: 8px 15px;">Ventas crédito:</td><td style="padding: 8px 15px; text-align: right;">$ <?php echo number_format($resumen['ventas_credito'], 0, ',', '.'); ?></td></tr>
        <tr style="background: #f3f4f6; font-weight: bold;">
            <td style="padding: 15px;">TOTAL VENTAS:</td>
            <td style="padding: 15px; text-align: right;">$ <?php echo number_format($resumen['total_ventas'], 0, ',', '.'); ?></td>
        </tr>
    </table>
    
    <div style="margin-top: 50px; text-align: center;">
        <div style="border-top: 2px dashed #000; width: 200px; margin: 20px auto;"></div>
        <p style="font-size: 12px;">_________________________________</p>
        <p style="font-size: 12px; font-weight: bold;">Firma del responsable</p>
    </div>
</div>

<script>
function mostrarModal() {
    document.getElementById('modalBase').style.display = 'flex';
    // Enfocar el input automáticamente
    setTimeout(() => {
        const input = document.querySelector('.input-moderno');
        if (input) input.focus();
    }, 100);
}

function cerrarModal() {
    document.getElementById('modalBase').style.display = 'none';
}

window.onclick = function(event) {
    const modal = document.getElementById('modalBase');
    if (event.target === modal) {
        cerrarModal();
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        cerrarModal();
    }
});

// Formatear número mientras se escribe (opcional)
document.querySelector('.input-moderno')?.addEventListener('input', function(e) {
    // Solo para visualización, no afecta el valor real
    let value = e.target.value.replace(/\D/g, '');
    if (value) {
        value = parseInt(value).toLocaleString('es-CO');
        // No modificamos el valor real, solo mostramos formateado
    }
});
</script>

<?php include '../../includes/footer.php'; ?>