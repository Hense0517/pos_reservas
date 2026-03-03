<?php
/**
 * ============================================
 * ARCHIVO: ver_pago_ticket.php
 * UBICACIÓN: /modules/cuentas_por_cobrar/ver_pago_ticket.php
 * FECHA CORRECCIÓN: 2026-02-17
 * 
 * PROPÓSITO:
 * Mostrar comprobante de pago optimizado para impresión térmica 80mm
 * 
 * CORRECCIONES APLICADAS:
 * 1. Rutas absolutas con __DIR__
 * 2. Eliminado estilos_base() que causaba conflicto
 * 3. Estilos inline directamente en el archivo
 * 4. Forza zona horaria Colombia
 * 5. Mejorado el debugging
 * ============================================
 */

// Forzar zona horaria Colombia
date_default_timezone_set('America/Bogota');

// Activar reporte de errores temporalmente (solo para debug)
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// ============================================
// FUNCIONES AUXILIARES
// ============================================

function formatMoney($amount) {
    return '$' . number_format(floatval($amount), 0, ',', '.');
}

function truncateText($text, $length = 30) {
    if (strlen($text) > $length) {
        return substr($text, 0, $length - 3) . '...';
    }
    return $text;
}

function limpiarMontoColombiano($monto_formateado) {
    if (empty($monto_formateado)) return 0;
    $limpio = str_replace('$', '', $monto_formateado);
    $limpio = trim($limpio);
    if (strpos($limpio, ',') !== false) {
        $limpio = str_replace('.', '', $limpio);
        $limpio = str_replace(',', '.', $limpio);
    } else {
        $limpio = str_replace('.', '', $limpio);
    }
    return floatval($limpio);
}

// ============================================
// CONFIGURACIÓN INICIAL
// ============================================
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    die("Acceso denegado");
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Obtener IDs
$pago_id = isset($_GET['pago_id']) ? intval($_GET['pago_id']) : 0;
$cuenta_id = isset($_GET['cuenta_id']) ? intval($_GET['cuenta_id']) : 0;

// Verificar si la tabla de pagos existe
$table_exists = false;
try {
    $check_table = $db->query("SHOW TABLES LIKE 'pagos_cuentas_por_cobrar'");
    $table_exists = $check_table->fetch();
} catch (Exception $e) {
    $table_exists = false;
}

// Obtener información del pago
$pago = null;
if ($table_exists && $pago_id > 0) {
    try {
        $query_pago = "SELECT p.*, 
                              u.nombre as usuario_nombre,
                              u.username as usuario_username
                       FROM pagos_cuentas_por_cobrar p
                       LEFT JOIN usuarios u ON p.usuario_id = u.id
                       WHERE p.id = ?";
        $stmt_pago = $db->prepare($query_pago);
        $stmt_pago->execute([$pago_id]);
        $pago = $stmt_pago->fetch(PDO::FETCH_ASSOC);
        
        if ($pago) {
            $cuenta_id = $pago['cuenta_id'];
        }
    } catch (Exception $e) {
        // Continuar sin información específica del pago
    }
}

// Obtener información de la cuenta y cliente
$cuenta = null;
if ($cuenta_id > 0) {
    try {
        $query_cuenta = "SELECT cp.*, 
                                c.nombre as cliente_nombre, 
                                c.telefono,
                                c.direccion as cliente_direccion,
                                c.numero_documento as cliente_documento,
                                c.email as cliente_email,
                                c.ruc as cliente_ruc,
                                v.numero_factura,
                                v.total as total_venta,
                                v.fecha as fecha_venta,
                                v.estado as estado_venta,
                                v.abono_inicial,
                                v.descuento,
                                v.subtotal,
                                v.metodo_pago as metodo_pago_venta,
                                v.tipo_venta,
                                v.cambio,
                                u.nombre as usuario_venta_nombre,
                                v.observaciones as observaciones_venta
                         FROM cuentas_por_cobrar cp
                         LEFT JOIN clientes c ON cp.cliente_id = c.id
                         LEFT JOIN ventas v ON cp.venta_id = v.id
                         LEFT JOIN usuarios u ON v.usuario_id = u.id
                         WHERE cp.id = ?";
        
        $stmt_cuenta = $db->prepare($query_cuenta);
        $stmt_cuenta->execute([$cuenta_id]);
        $cuenta = $stmt_cuenta->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        die("Error al obtener información de la cuenta: " . $e->getMessage());
    }
}

// Si no hay cuenta, mostrar error
if (!$cuenta) {
    die("Cuenta no encontrada");
}

// Obtener todos los pagos de esta cuenta para estadísticas
$pagos_historial = [];
$total_pagado = 0;
if ($table_exists) {
    try {
        $query_pagos = "SELECT p.*, u.nombre as usuario_nombre
                        FROM pagos_cuentas_por_cobrar p
                        LEFT JOIN usuarios u ON p.usuario_id = u.id
                        WHERE p.cuenta_id = ?
                        ORDER BY p.fecha_pago ASC";
        
        $stmt_pagos = $db->prepare($query_pagos);
        $stmt_pagos->execute([$cuenta_id]);
        $pagos_historial = $stmt_pagos->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pagos_historial as $p) {
            $total_pagado += floatval($p['monto']);
        }
    } catch (Exception $e) {
        // Continuar sin historial
    }
}

// Calcular saldos
$saldo_pendiente = floatval($cuenta['saldo_pendiente']);
$total_deuda = floatval($cuenta['total_deuda']);
$pagado = $total_pagado;
$porcentaje_pagado = ($total_deuda > 0) ? ($pagado / $total_deuda) * 100 : 0;

// Si no hay pago específico pero hay historial, usar el último pago
if (!$pago && !empty($pagos_historial)) {
    $pago = end($pagos_historial);
}

// Obtener configuración del negocio
$config_negocio = [];
try {
    $query_config = "SELECT * FROM configuracion_negocio ORDER BY id DESC LIMIT 1";
    $stmt_config = $db->prepare($query_config);
    $stmt_config->execute();
    $config_negocio = $stmt_config->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Usar valores por defecto
    $config_negocio = [
        'nombre_negocio' => 'MI NEGOCIO',
        'direccion' => 'Dirección del negocio',
        'telefono' => '000-000-0000',
        'nit' => '000000000-0',
        'logo' => ''
    ];
}

// Determinar si la cuenta está pagada
$esta_pagada = ($saldo_pendiente <= 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobante de Pago - <?php echo htmlspecialchars($cuenta['numero_factura'] ?? 'VR'); ?></title>
    <style>
        /* Reset completo */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: #f0f0f0;
            font-family: 'Courier New', monospace;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        /* ================================================
           ESTILOS PARA VISTA PREVIA EN PANTALLA
           ================================================ */
        .screen-only {
            text-align: center;
            padding: 15px;
            background: #fff;
            margin-bottom: 20px;
            border-radius: 8px;
            width: 80mm;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .ticket-container {
            width: 80mm;
            max-width: 80mm;
            margin: 0 auto;
            padding: 3mm;
            background: white;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
            font-family: 'Courier New', monospace;
            font-size: 9pt;
            line-height: 1.2;
        }
        
        .btn-print {
            background: #4CAF50;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin: 5px;
            font-family: Arial, sans-serif;
        }
        
        .btn-close {
            background: #f44336;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin: 5px;
            font-family: Arial, sans-serif;
        }
        
        /* ===== ESTILOS PARA IMPRESIÓN ===== */
        @media print {
            @page {
                size: 80mm auto;
                margin: 0;
            }
            
            body {
                background: white;
                padding: 0;
                margin: 0;
                width: 80mm;
            }
            
            .screen-only {
                display: none !important;
            }
            
            .ticket-container {
                width: 80mm;
                max-width: 80mm;
                margin: 0;
                padding: 2mm;
                box-shadow: none;
                background: white;
            }
        }
        
        /* ===== ESTILOS DEL TICKET ===== */
        .text-center {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-left {
            text-align: left;
        }
        
        .bold {
            font-weight: bold;
        }
        
        .uppercase {
            text-transform: uppercase;
        }
        
        .x-small {
            font-size: 8pt;
        }
        
        .xx-small {
            font-size: 7pt;
        }
        
        .separator {
            border-bottom: 1px dashed #000;
            margin: 2mm 0;
        }
        
        .double-separator {
            border-bottom: 2px solid #000;
            margin: 2mm 0;
        }
        
        .table-row {
            display: flex;
            justify-content: space-between;
            width: 100%;
            margin-bottom: 0.5mm;
            padding: 0.2mm 0;
        }
        
        .estado-pagado {
            background: #000;
            color: white;
            padding: 1mm;
            text-align: center;
            margin: 1mm 0;
            font-weight: bold;
        }
        
        .estado-pendiente {
            background: #f0f0f0;
            color: #000;
            padding: 1mm;
            text-align: center;
            margin: 1mm 0;
            border: 1px solid #000;
            font-weight: bold;
        }
        
        .pago-detail {
            background: #f8f8f8;
            border: 1px solid #000;
            padding: 1mm;
            margin: 1mm 0;
        }
        
        .progress-bar {
            height: 2mm;
            background: #e0e0e0;
            border-radius: 1mm;
            margin: 1mm 0;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: #000;
            border-radius: 1mm;
        }
        
        .important-message {
            background: #f0f0f0;
            border: 1px solid #000;
            padding: 1mm;
            margin: 1mm 0;
            text-align: center;
            font-size: 7pt;
        }
        
        .thank-you {
            background: #e8e8e8;
            border: 1px solid #000;
            padding: 1mm;
            margin: 1mm 0;
            text-align: center;
            font-weight: bold;
        }
        
        .footer {
            text-align: center;
            margin-top: 2mm;
            padding-top: 1mm;
            border-top: 1px solid #000;
            font-size: 6pt;
        }
        
        .wrap {
            word-wrap: break-word;
            max-width: 50%;
        }
        
        .nowrap {
            white-space: nowrap;
        }
    </style>
</head>
<body>
    <!-- BOTONES PARA PANTALLA -->
    <div class="screen-only">
        <h3 style="margin: 5px 0; font-size: 14pt;">Vista Previa - Comprobante de Pago</h3>
        <p style="font-size: 9pt; margin: 5px 0;">Optimizado para impresora térmica 80mm</p>
        <div>
            <button class="btn-print" onclick="window.print()">
                🖨️ IMPRIMIR
            </button>
            <button class="btn-close" onclick="window.close()">
                ❌ CERRAR
            </button>
        </div>
        <p style="margin-top: 10px; font-size: 8pt; color: #666;">
            Cuenta: #<?php echo $cuenta_id; ?> | 
            Pago: #<?php echo $pago_id; ?> | 
            Factura: <?php echo htmlspecialchars($cuenta['numero_factura'] ?? 'N/A'); ?>
        </p>
    </div>

    <!-- CONTENIDO DEL TICKET -->
    <div class="ticket-container">
        <!-- ENCABEZADO DEL NEGOCIO -->
        <div class="text-center bold uppercase">
            <div style="font-size: 10pt; margin-bottom: 0.5mm;">
                <?php echo htmlspecialchars($config_negocio['nombre_negocio'] ?? 'MI NEGOCIO'); ?>
            </div>
            <div class="x-small">
                <?php echo truncateText(htmlspecialchars($config_negocio['direccion'] ?? 'Dirección'), 35); ?>
            </div>
            <div class="xx-small">
                TEL: <?php echo htmlspecialchars($config_negocio['telefono'] ?? 'N/A'); ?> | 
                NIT: <?php echo htmlspecialchars($config_negocio['nit'] ?? 'N/A'); ?>
            </div>
        </div>
        
        <div class="double-separator"></div>
        
        <!-- TÍTULO -->
        <div class="text-center bold uppercase" style="margin-bottom: 1mm;">
            COMPROBANTE DE PAGO
        </div>
        
        <!-- INFORMACIÓN DEL PAGO -->
        <?php if ($pago): ?>
        <div class="pago-detail">
            <div class="table-row bold nowrap">
                <span>FECHA:</span>
                <span class="text-right"><?php echo date('d/m/Y H:i', strtotime($pago['fecha_pago'])); ?></span>
            </div>
            <div class="table-row bold">
                <span>MONTO:</span>
                <span class="text-right"><?php echo formatMoney($pago['monto']); ?></span>
            </div>
            <div class="table-row">
                <span>METODO:</span>
                <span class="text-right bold uppercase"><?php echo substr(strtoupper($pago['metodo_pago']), 0, 15); ?></span>
            </div>
            <?php if (!empty($pago['referencia'])): ?>
            <div class="table-row">
                <span>REF:</span>
                <span class="text-right wrap"><?php echo truncateText(htmlspecialchars($pago['referencia']), 20); ?></span>
            </div>
            <?php endif; ?>
            <div class="table-row">
                <span>REG POR:</span>
                <span class="text-right wrap"><?php echo truncateText(htmlspecialchars($pago['usuario_nombre'] ?? 'Sistema'), 20); ?></span>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="separator"></div>
        
        <!-- INFORMACIÓN DEL CLIENTE -->
        <div class="text-center bold" style="margin-bottom: 0.5mm;">CLIENTE</div>
        <div class="table-row bold wrap">
            <span>NOMBRE:</span>
            <span class="text-right"><?php echo truncateText(htmlspecialchars($cuenta['cliente_nombre']), 20); ?></span>
        </div>
        <?php if ($cuenta['telefono']): ?>
        <div class="table-row">
            <span>TEL:</span>
            <span class="text-right"><?php echo htmlspecialchars($cuenta['telefono']); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($cuenta['cliente_documento']): ?>
        <div class="table-row">
            <span>DOC:</span>
            <span class="text-right"><?php echo htmlspecialchars($cuenta['cliente_documento']); ?></span>
        </div>
        <?php endif; ?>
        
        <div class="separator"></div>
        
        <!-- INFORMACIÓN DE LA VENTA -->
        <div class="text-center bold" style="margin-bottom: 0.5mm;">VENTA</div>
        <div class="table-row">
            <span>FACTURA:</span>
            <span class="text-right bold"><?php echo htmlspecialchars($cuenta['numero_factura'] ?? 'N/A'); ?></span>
        </div>
        <div class="table-row">
            <span>FECHA:</span>
            <span class="text-right"><?php echo date('d/m/Y', strtotime($cuenta['fecha_venta'])); ?></span>
        </div>
        <div class="table-row">
            <span>TIPO:</span>
            <span class="text-right bold uppercase"><?php echo ($cuenta['tipo_venta'] == 'credito') ? 'CREDITO' : 'CONTADO'; ?></span>
        </div>
        <?php if ($cuenta['abono_inicial'] > 0): ?>
        <div class="table-row">
            <span>ABONO INIC:</span>
            <span class="text-right"><?php echo formatMoney($cuenta['abono_inicial']); ?></span>
        </div>
        <?php endif; ?>
        
        <div class="separator"></div>
        
        <!-- ESTADO DE LA DEUDA -->
        <div class="text-center bold" style="margin-bottom: 0.5mm;">ESTADO DEUDA</div>
        <div class="table-row">
            <span>TOTAL DEUDA:</span>
            <span class="text-right bold"><?php echo formatMoney($total_deuda); ?></span>
        </div>
        <div class="table-row">
            <span>TOTAL PAGADO:</span>
            <span class="text-right bold"><?php echo formatMoney($pagado); ?></span>
        </div>
        <div class="table-row">
            <span>SALDO PEND:</span>
            <span class="text-right bold"><?php echo formatMoney($saldo_pendiente); ?></span>
        </div>
        
        <!-- BARRA DE PROGRESO -->
        <div style="margin: 1mm 0;">
            <div class="progress-bar">
                <div class="progress-fill" style="width: <?php echo $porcentaje_pagado; ?>%"></div>
            </div>
            <div class="text-center xx-small">
                PAGADO: <?php echo number_format($porcentaje_pagado, 1); ?>%
            </div>
        </div>
        
        <!-- ESTADO ACTUAL -->
        <div style="margin: 1mm 0;">
            <?php if ($esta_pagada): ?>
                <div class="estado-pagado">
                    ✅ CUENTA PAGADA ✅
                </div>
            <?php else: ?>
                <div class="estado-pendiente">
                    ⚠️ PENDIENTE: <?php echo formatMoney($saldo_pendiente); ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- HISTORIAL DE PAGOS -->
        <?php if (count($pagos_historial) > 1 && count($pagos_historial) <= 3): ?>
        <div class="separator"></div>
        <div class="text-center bold" style="margin-bottom: 0.5mm;">HISTORIAL</div>
        <?php foreach ($pagos_historial as $historial): ?>
        <div class="table-row xx-small nowrap">
            <span>
                <?php echo date('d/m', strtotime($historial['fecha_pago'])); ?> 
                (<?php echo strtoupper(substr($historial['metodo_pago'], 0, 3)); ?>)
            </span>
            <span class="text-right"><?php echo formatMoney($historial['monto']); ?></span>
        </div>
        <?php endforeach; ?>
        <div class="table-row bold xx-small" style="border-top: 1px dashed #000; padding-top: 0.5mm;">
            <span>TOTAL:</span>
            <span class="text-right"><?php echo formatMoney($total_pagado); ?></span>
        </div>
        <?php endif; ?>
        
        <!-- FECHA LÍMITE -->
        <?php if (!empty($cuenta['fecha_limite']) && $cuenta['fecha_limite'] != '0000-00-00' && !$esta_pagada): 
            $fecha_limite = new DateTime($cuenta['fecha_limite']);
            $hoy = new DateTime();
            $diferencia = $hoy->diff($fecha_limite);
            $dias_restantes = $diferencia->days;
            if ($diferencia->invert) {
                $dias_restantes = -$dias_restantes;
            }
        ?>
        <div class="separator"></div>
        <div class="table-row">
            <span>FECHA LIMITE:</span>
            <span class="text-right bold"><?php echo date('d/m/Y', strtotime($cuenta['fecha_limite'])); ?></span>
        </div>
        <?php if ($dias_restantes < 0): ?>
        <div class="important-message">
            ⚠️ VENCIDA <?php echo abs($dias_restantes); ?> DIAS
        </div>
        <?php elseif ($dias_restantes <= 3): ?>
        <div class="important-message">
            ⏰ VENCE EN <?php echo $dias_restantes; ?> DIAS
        </div>
        <?php endif; ?>
        <?php endif; ?>
        
        <!-- MENSAJE DE AGRADECIMIENTO -->
        <div class="separator"></div>
        <div class="thank-you">
            <?php if ($esta_pagada): ?>
                <strong>GRACIAS POR PAGAR</strong>
                <div class="xx-small">
                    Cuenta saldada
                </div>
            <?php else: ?>
                <strong>GRACIAS POR SU PAGO</strong>
                <div class="xx-small">
                    Saldo: <?php echo formatMoney($saldo_pendiente); ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- OBSERVACIONES DEL PAGO -->
        <?php if ($pago && !empty($pago['observaciones']) && strlen($pago['observaciones']) < 50): ?>
        <div class="separator"></div>
        <div class="text-center bold" style="margin-bottom: 0.5mm;">OBSERVACIONES</div>
        <div class="xx-small wrap" style="text-align: left; padding: 0.3mm;">
            <?php echo truncateText(nl2br(htmlspecialchars($pago['observaciones'])), 60); ?>
        </div>
        <?php endif; ?>
        
        <!-- INSTRUCCIONES -->
        <div class="important-message">
            <strong>CONSERVE ESTE COMPROBANTE</strong>
            <div style="margin-top: 0.3mm; text-align: left;">
                • Presente para abonos<br>
                • Contacto: <?php echo htmlspecialchars($config_negocio['telefono'] ?? ''); ?><br>
                • Valide saldo en sistema
            </div>
        </div>
        
        <!-- PIE DE PÁGINA -->
        <div class="footer">
            <div>-------------------</div>
            <div class="bold">COMPROBANTE DE PAGO</div>
            <div>Cuenta: #<?php echo $cuenta_id; ?></div>
            <div>Fact: <?php echo htmlspecialchars($cuenta['numero_factura'] ?? 'N/A'); ?></div>
            <div>Emitido: <?php echo date('d/m/Y H:i'); ?></div>
            <div><?php echo htmlspecialchars($config_negocio['nombre_negocio'] ?? ''); ?></div>
            <div>Sistema POS</div>
        </div>
    </div>

    <script>
    // Imprimir automáticamente al cargar la página
    window.onload = function() {
        setTimeout(function() {
            if (!window.location.href.includes('print=no')) {
                window.print();
            }
        }, 500);
    };

    // Manejar impresión con teclado
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
        if (e.key === 'Escape') {
            window.close();
        }
    });
    </script>
</body>
</html>