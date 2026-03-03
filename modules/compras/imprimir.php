<?php
/**
 * ============================================
 * ARCHIVO: imprimir.php
 * UBICACIÓN: /modules/compras/imprimir.php
 * PROPÓSITO: Vista de impresión de compra
 * ============================================
 */

session_start();
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    die("Acceso denegado");
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    die("ID de compra no válido");
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Obtener datos de la compra
    $stmt = $db->prepare("SELECT c.*, p.nombre as proveedor_nombre, u.nombre as usuario_nombre 
                          FROM compras c 
                          LEFT JOIN proveedores p ON c.proveedor_id = p.id 
                          LEFT JOIN usuarios u ON c.usuario_id = u.id 
                          WHERE c.id = ?");
    $stmt->execute([$id]);
    $compra = $stmt->fetch();
    
    if (!$compra) {
        die("Compra no encontrada");
    }
    
    // Obtener detalles de la compra
    $stmt = $db->prepare("SELECT cd.*, pr.nombre as producto_nombre, pr.codigo 
                          FROM compra_detalles cd 
                          LEFT JOIN productos pr ON cd.producto_id = pr.id 
                          WHERE cd.compra_id = ?");
    $stmt->execute([$id]);
    $detalles = $stmt->fetchAll();
    
    // Obtener configuración del negocio
    $stmt = $db->prepare("SELECT * FROM configuracion_negocio ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch();
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprobante de Compra</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            background: #f5f5f5;
            padding: 20px;
            display: flex;
            justify-content: center;
        }
        
        .ticket {
            width: 80mm;
            max-width: 80mm;
            background: white;
            padding: 5mm;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .header {
            text-align: center;
            margin-bottom: 5mm;
            border-bottom: 2px dashed #000;
            padding-bottom: 3mm;
        }
        
        .header h1 {
            font-size: 14pt;
            margin-bottom: 2mm;
        }
        
        .header p {
            font-size: 9pt;
            margin: 1mm 0;
        }
        
        .info {
            margin-bottom: 5mm;
            font-size: 9pt;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 1mm 0;
        }
        
        table {
            width: 100%;
            font-size: 9pt;
            border-collapse: collapse;
            margin: 3mm 0;
        }
        
        th {
            border-bottom: 1px solid #000;
            padding: 1mm;
            text-align: left;
        }
        
        td {
            padding: 1mm 0;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .totals {
            margin-top: 3mm;
            border-top: 1px dashed #000;
            padding-top: 2mm;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            font-size: 10pt;
            margin: 1mm 0;
        }
        
        .grand-total {
            font-weight: bold;
            font-size: 12pt;
            border-top: 1px solid #000;
            padding-top: 2mm;
            margin-top: 2mm;
        }
        
        .footer {
            margin-top: 5mm;
            text-align: center;
            font-size: 8pt;
            border-top: 1px dashed #000;
            padding-top: 3mm;
        }
        
        .estado {
            display: inline-block;
            padding: 1mm 3mm;
            border-radius: 3mm;
            font-weight: bold;
            font-size: 8pt;
        }
        
        .estado.pendiente {
            background: #fef3c7;
            color: #92400e;
        }
        
        .estado.recibida {
            background: #d1fae5;
            color: #065f46;
        }
        
        .estado.cancelada {
            background: #fee2e2;
            color: #991b1b;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            .ticket {
                box-shadow: none;
            }
            .no-print {
                display: none;
            }
        }
        
        .no-print {
            text-align: center;
            margin-top: 10px;
        }
        
        .no-print button {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            margin: 0 5px;
            font-size: 12px;
        }
        
        .no-print button:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <div class="ticket">
        <!-- Encabezado -->
        <div class="header">
            <h1><?php echo htmlspecialchars($config['nombre_negocio'] ?? 'MI NEGOCIO'); ?></h1>
            <p>NIT: <?php echo htmlspecialchars($config['nit'] ?? 'N/A'); ?></p>
            <p><?php echo htmlspecialchars($config['direccion'] ?? ''); ?></p>
            <p>Tel: <?php echo htmlspecialchars($config['telefono'] ?? ''); ?></p>
            <p>--------------------------------</p>
            <h2>COMPROBANTE DE COMPRA</h2>
        </div>
        
        <!-- Información de la compra -->
        <div class="info">
            <div class="info-row">
                <span>Factura:</span>
                <span><strong><?php echo htmlspecialchars($compra['numero_factura']); ?></strong></span>
            </div>
            <div class="info-row">
                <span>Fecha:</span>
                <span><?php echo date('d/m/Y H:i', strtotime($compra['fecha'])); ?></span>
            </div>
            <div class="info-row">
                <span>Proveedor:</span>
                <span><?php echo htmlspecialchars($compra['proveedor_nombre']); ?></span>
            </div>
            <div class="info-row">
                <span>Usuario:</span>
                <span><?php echo htmlspecialchars($compra['usuario_nombre']); ?></span>
            </div>
            <div class="info-row">
                <span>Estado:</span>
                <span class="estado <?php echo $compra['estado']; ?>">
                    <?php echo ucfirst($compra['estado']); ?>
                </span>
            </div>
        </div>
        
        <p>--------------------------------</p>
        
        <!-- Tabla de productos -->
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th class="text-right">Cant</th>
                    <th class="text-right">P.Unit</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detalles as $d): ?>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($d['producto_nombre']); ?>
                        <br><small><?php echo htmlspecialchars($d['codigo']); ?></small>
                    </td>
                    <td class="text-right"><?php echo $d['cantidad']; ?></td>
                    <td class="text-right">$<?php echo number_format($d['precio'], 0, ',', '.'); ?></td>
                    <td class="text-right">$<?php echo number_format($d['cantidad'] * $d['precio'], 0, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <p>--------------------------------</p>
        
        <!-- Totales -->
        <div class="totals">
            <div class="total-row">
                <span>Subtotal:</span>
                <span>$<?php echo number_format($compra['subtotal'], 0, ',', '.'); ?></span>
            </div>
            <div class="total-row">
                <span>Impuesto:</span>
                <span>$<?php echo number_format($compra['impuesto'], 0, ',', '.'); ?></span>
            </div>
            <div class="total-row grand-total">
                <span>TOTAL:</span>
                <span>$<?php echo number_format($compra['total'], 0, ',', '.'); ?></span>
            </div>
        </div>
        
        <!-- Pie de página -->
        <div class="footer">
            <p>¡Gracias por su compra!</p>
            <p>Generado: <?php echo date('d/m/Y H:i'); ?></p>
            <p>Sistema POS v2.0</p>
        </div>
    </div>
    
    <!-- Botones de acción -->
    <div class="no-print">
        <button onclick="window.print()">🖨️ Imprimir</button>
        <button onclick="window.close()">❌ Cerrar</button>
    </div>
    
    <script>
        // Auto-print al cargar
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>