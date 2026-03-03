<?php
ob_start();
require_once '../../config/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

// Obtener venta
$id = $_GET['id'] ?? 0;
$query_venta = "SELECT v.*, 
                c.nombre as cliente_nombre, 
                c.numero_documento as cliente_documento, 
                c.ruc as cliente_ruc, 
                c.direccion as cliente_direccion, 
                u.nombre as usuario_nombre 
                FROM ventas v 
                LEFT JOIN clientes c ON v.cliente_id = c.id 
                LEFT JOIN usuarios u ON v.usuario_id = u.id 
                WHERE v.id = ?";
$stmt_venta = $db->prepare($query_venta);
$stmt_venta->execute([$id]);
$venta = $stmt_venta->fetch(PDO::FETCH_ASSOC);

if (!$venta) {
    die("Venta no encontrada");
}

// Obtener detalles
$query_detalles = "SELECT vd.*, p.nombre as producto_nombre, p.codigo as producto_codigo, p.codigo_barras
                   FROM venta_detalles vd 
                   JOIN productos p ON vd.producto_id = p.id 
                   WHERE vd.venta_id = ?";
$stmt_detalles = $db->prepare($query_detalles);
$stmt_detalles->execute([$id]);
$detalles = $stmt_detalles->fetchAll(PDO::FETCH_ASSOC);

// Obtener configuración del negocio
$query_config = "SELECT * FROM configuracion_negocio ORDER BY id DESC LIMIT 1";
$stmt_config = $db->prepare($query_config);
$stmt_config->execute();
$config = $stmt_config->fetch(PDO::FETCH_ASSOC);

// ================================================
// LOGO EN BASE64 PARA EVITAR PROBLEMAS DE CSP
// ================================================
$logo_base64 = '';
$logo_paths = [
    __DIR__ . '/../../imagenes/logo/logo.png',
    __DIR__ . '/../../imagenes/logo/logo.jpg',
    __DIR__ . '/../../assets/images/logo.png',
    __DIR__ . '/../../assets/images/logo_1763553782.jpg'
];

foreach ($logo_paths as $path) {
    if (file_exists($path)) {
        $image_data = file_get_contents($path);
        if ($image_data !== false) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $path);
            finfo_close($finfo);
            $logo_base64 = 'data:' . $mime_type . ';base64,' . base64_encode($image_data);
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket - <?php echo htmlspecialchars($venta['numero_factura']); ?></title>
    <style>
        /* ================================================
           ESTILOS OPTIMIZADOS PARA IMPRESORA TÉRMICA 80mm
           TEXTO NEGRO Y GRUESO PARA MÁXIMA LEGIBILIDAD
           ================================================ */
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', 'Courier', monospace;
            background: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 0;
        }
        
        .ticket {
            width: 72mm;
            max-width: 72mm;
            background: white;
            padding: 2mm 3mm;
            margin: 0 auto;
            font-size: 11pt;
            line-height: 1.3;
            font-weight: 700;
            color: #000;
        }
        
        .ticket * {
            font-weight: 700;
            color: #000;
        }
        
        h1, h2, h3, strong, .bold, .section-title, .grand-total, .thank-you {
            font-weight: 900 !important;
        }
        
        .header {
            text-align: center;
            margin-bottom: 2mm;
            border-bottom: 2px solid #000;
            padding-bottom: 1.5mm;
        }
        
        .header h1 {
            font-size: 16pt;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 1mm 0;
        }
        
        .header p {
            font-size: 10pt;
            font-weight: 700;
            margin: 1mm 0;
        }
        
        /* ===== LOGO - CORREGIDO: SIN FILTROS ===== */
        .logo-container {
            text-align: center;
            margin-bottom: 1.5mm;
        }
        
        .ticket-logo {
            max-width: 50mm;
            max-height: 15mm;
            object-fit: contain;
            /* IMPORTANTE: Sin filtros para que el logo se vea normal */
        }
        
        .logo-placeholder {
            width: 45mm;
            height: 15mm;
            background: #fff;
            border: 2px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 24pt;
            margin: 0 auto 2mm;
            color: #000;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            font-size: 10pt;
            margin: 1.5mm 0;
            border-bottom: 1px solid #000;
            padding-bottom: 1mm;
            font-weight: 700;
        }
        
        .info-row span:first-child {
            font-weight: 800;
        }
        
        .info-row span:last-child {
            text-align: right;
            max-width: 65%;
            font-weight: 700;
            word-break: break-word;
        }
        
        .info-row.no-border {
            border-bottom: none;
        }
        
        .section-title {
            font-weight: 900;
            text-align: center;
            padding: 1.5mm;
            margin: 2.5mm 0 1.5mm 0;
            font-size: 12pt;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            letter-spacing: 0.5px;
            background: #fff;
        }
        
        .productos-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10pt;
            margin: 2mm 0;
        }
        
        .productos-table th {
            border-bottom: 2px solid #000;
            padding: 1mm 0;
            text-align: left;
            font-weight: 900;
            font-size: 10pt;
        }
        
        .productos-table td {
            padding: 1mm 0;
            font-weight: 700;
            border-bottom: 1px dashed #000;
        }
        
        .productos-table tr:last-child td {
            border-bottom: none;
        }
        
        .text-right {
            text-align: right;
            padding-left: 2mm;
        }
        
        .producto-nombre {
            max-width: 35mm;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 700;
        }
        
        .totals {
            margin-top: 2mm;
            border-top: 2px solid #000;
            padding-top: 1.5mm;
        }
        
        .total-line {
            display: flex;
            justify-content: space-between;
            font-size: 11pt;
            margin: 1.5mm 0;
            font-weight: 800;
        }
        
        .grand-total {
            font-weight: 900;
            font-size: 14pt;
            border-top: 2px solid #000;
            padding-top: 1.5mm;
            margin-top: 1.5mm;
        }
        
        .credito-box {
            border: 2px solid #000;
            padding: 2mm;
            margin: 2.5mm 0;
        }
        
        .credito-title {
            font-weight: 900;
            text-align: center;
            padding: 1mm;
            margin-bottom: 1.5mm;
            font-size: 12pt;
            border-bottom: 1px solid #000;
        }
        
        .thank-you {
            text-align: center;
            font-weight: 900;
            padding: 2mm;
            margin: 2.5mm 0;
            font-size: 14pt;
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
        }
        
        .policy {
            text-align: center;
            font-size: 9pt;
            padding: 1.5mm;
            margin: 2mm 0;
            font-weight: 700;
            border: 1px solid #000;
        }
        
        .policy strong {
            font-weight: 900;
            font-size: 10pt;
        }
        
        .footer {
            text-align: center;
            margin-top: 2mm;
            padding-top: 1.5mm;
            border-top: 2px solid #000;
            font-size: 9pt;
            font-weight: 700;
        }
        
        .cut-line {
            text-align: center;
            font-size: 8pt;
            margin: 2mm 0 0;
            color: #000;
            font-weight: 700;
        }
        
        .cut-line span {
            letter-spacing: 2px;
        }
        
        .no-print {
            text-align: center;
            margin: 10px 0;
            padding: 5px;
        }
        
        .no-print button {
            background: #2c3e50;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            margin: 0 5px;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .no-print .btn-print {
            background: #27ae60;
        }
        
        .no-print .btn-close {
            background: #c0392b;
        }
        
        @media print {
            @page {
                size: 80mm auto;
                margin: 0;
            }
            
            body {
                padding: 0;
                margin: 0;
                background: white;
            }
            
            .no-print {
                display: none;
            }
            
            .ticket {
                width: 72mm;
                padding: 1mm 2mm;
                box-shadow: none;
                margin: 0 auto;
            }
            
            * {
                background: white !important;
                color: black !important;
                border-color: black !important;
                font-weight: 700 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                text-shadow: none !important;
                box-shadow: none !important;
            }
            
            .grand-total, 
            .section-title, 
            .thank-you, 
            h1, 
            .credito-title,
            .total-line:last-child,
            .productos-table th {
                font-weight: 900 !important;
            }
            
            .info-row,
            .productos-table td,
            .productos-table th,
            .section-title,
            .credito-box {
                border-color: #000 !important;
            }
            
            /* CORREGIDO: El logo se imprime sin filtros */
            .ticket-logo {
                filter: none !important;
                -webkit-filter: none !important;
            }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()" class="btn-print">🖨️ IMPRIMIR TICKET</button>
        <button onclick="window.close()" class="btn-close">❌ CERRAR</button>
        <p style="margin-top: 10px; color: #666; font-size: 12px;">Presione ESC para cerrar</p>
    </div>

    <div class="ticket">
        <div class="header">
            <?php if (!empty($logo_base64)): ?>
                <div class="logo-container">
                    <img src="<?php echo $logo_base64; ?>" class="ticket-logo" alt="Logo">
                </div>
            <?php else: ?>
                <div class="logo-placeholder">
                    <?php echo substr(htmlspecialchars($config['nombre_negocio'] ?? 'T'), 0, 2); ?>
                </div>
            <?php endif; ?>
            
            <h1><?php echo htmlspecialchars($config['nombre_negocio'] ?? 'MI TIENDA'); ?></h1>
            <p><?php echo htmlspecialchars($config['direccion'] ?? 'Dirección no registrada'); ?></p>
            <?php if (!empty($config['telefono'])): ?>
                <p>TEL: <?php echo htmlspecialchars($config['telefono']); ?></p>
            <?php endif; ?>
            <?php if (!empty($config['nit'])): ?>
                <p>NIT: <?php echo htmlspecialchars($config['nit']); ?></p>
            <?php endif; ?>
            <?php if (!empty($config['email'])): ?>
                <p><?php echo htmlspecialchars($config['email']); ?></p>
            <?php endif; ?>
        </div>

        <div class="info-row">
            <span>Factura:</span>
            <span><?php echo htmlspecialchars($venta['numero_factura']); ?></span>
        </div>
        
        <div class="info-row">
            <span>Fecha:</span>
            <span><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></span>
        </div>
        
        <div class="info-row">
            <span>Cliente:</span>
            <span><?php echo htmlspecialchars($venta['cliente_nombre'] ?? 'CONSUMIDOR FINAL'); ?></span>
        </div>
        
        <?php if (!empty($venta['cliente_documento'])): ?>
        <div class="info-row">
            <span>Documento:</span>
            <span><?php echo htmlspecialchars($venta['cliente_documento']); ?></span>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($venta['cliente_ruc'])): ?>
        <div class="info-row">
            <span>RUC:</span>
            <span><?php echo htmlspecialchars($venta['cliente_ruc']); ?></span>
        </div>
        <?php endif; ?>
        
        <div class="info-row">
            <span>Vendedor:</span>
            <span><?php echo htmlspecialchars($venta['usuario_nombre']); ?></span>
        </div>
        
        <div class="info-row no-border">
            <span>Método:</span>
            <span><?php echo strtoupper($venta['metodo_pago']); ?></span>
        </div>

        <div class="section-title">DETALLE DE PRODUCTOS</div>
        
        <table class="productos-table">
            <thead>
                <tr>
                    <th>DESCRIPCIÓN</th>
                    <th class="text-right">CANT</th>
                    <th class="text-right">PRECIO</th>
                    <th class="text-right">TOTAL</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detalles as $detalle): ?>
                <tr>
                    <td class="producto-nombre"><?php echo htmlspecialchars($detalle['producto_nombre']); ?></td>
                    <td class="text-right"><?php echo $detalle['cantidad']; ?></td>
                    <td class="text-right">$<?php echo number_format($detalle['precio'], 0, ',', '.'); ?></td>
                    <td class="text-right">$<?php echo number_format($detalle['subtotal'], 0, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals">
            <div class="total-line">
                <span>SUBTOTAL:</span>
                <span>$<?php echo number_format($venta['subtotal'], 0, ',', '.'); ?></span>
            </div>
            
            <?php if ($venta['descuento'] > 0): ?>
            <div class="total-line">
                <span>DESCUENTO:</span>
                <span>-$<?php echo number_format($venta['descuento'], 0, ',', '.'); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($venta['impuesto'] > 0): ?>
            <div class="total-line">
                <span>IMPUESTO:</span>
                <span>$<?php echo number_format($venta['impuesto'], 0, ',', '.'); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="total-line grand-total">
                <span>TOTAL A PAGAR:</span>
                <span>$<?php echo number_format($venta['total'], 0, ',', '.'); ?></span>
            </div>
        </div>

        <?php if (isset($venta['tipo_venta']) && $venta['tipo_venta'] == 'credito'): ?>
        <div class="credito-box">
            <div class="credito-title">VENTA A CRÉDITO</div>
            
            <div class="info-row no-border">
                <span>Total crédito:</span>
                <span>$<?php echo number_format($venta['total'], 0, ',', '.'); ?></span>
            </div>
            
            <?php if (!empty($venta['abono_inicial']) && $venta['abono_inicial'] > 0): ?>
            <div class="info-row no-border">
                <span>Abono inicial:</span>
                <span>$<?php echo number_format($venta['abono_inicial'], 0, ',', '.'); ?></span>
            </div>
            <?php endif; ?>
            
            <?php
            $saldo_pendiente = $venta['total'];
            if (!empty($venta['abono_inicial'])) {
                $saldo_pendiente -= $venta['abono_inicial'];
            }
            ?>
            <div class="info-row no-border" style="border-top: 1px solid #000; padding-top: 1mm; margin-top: 1mm;">
                <span><strong>SALDO PENDIENTE:</strong></span>
                <span><strong>$<?php echo number_format($saldo_pendiente, 0, ',', '.'); ?></strong></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($venta['cambio'] > 0): ?>
        <div class="info-row no-border" style="margin-top: 1mm;">
            <span>CAMBIO:</span>
            <span>$<?php echo number_format($venta['cambio'], 0, ',', '.'); ?></span>
        </div>
        <?php endif; ?>

        <?php if (!empty($venta['notas'])): ?>
        <div class="info-row" style="border-top: 1px dashed #000; margin-top: 2mm;">
            <span>Notas:</span>
            <span><?php echo htmlspecialchars($venta['notas']); ?></span>
        </div>
        <?php endif; ?>

        <div class="thank-you">
            ¡GRACIAS POR SU COMPRA!
        </div>
        
        <div class="policy">
            <strong>POLÍTICA DE DEVOLUCIONES</strong><br>
            Presentar este ticket dentro de 3 días hábiles
        </div>

        <div class="footer">
            <p>¡VUELVA PRONTO!</p>
            <p><?php echo date('d/m/Y H:i:s'); ?></p>
        </div>
        
        <div class="cut-line">
            <span>- - - - - - - - - - - - - - - - - - - -</span>
        </div>
    </div>

    <script>
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 800);
    };
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            window.close();
        }
    });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>