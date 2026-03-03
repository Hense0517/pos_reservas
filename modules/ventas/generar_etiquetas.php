<?php
require_once '../../config/database.php';
session_start();

$productos_seleccionados = isset($_SESSION['productos_etiquetas']) ? $_SESSION['productos_etiquetas'] : [];

if (empty($productos_seleccionados)) {
    header('Location: index.php');
    exit();
}

// Si viene con parámetro de autoimpresión, agregamos JavaScript
$auto_print = isset($_GET['auto']) && $_GET['auto'] == '1';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Impresión de Etiquetas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        /* ESTILOS PARA PANTALLA - VISTA PREVIA */
        @media screen {
            body {
                font-family: Arial, sans-serif;
                margin: 20px;
                padding: 20px;
                background-color: #f5f5f5;
            }
            
            .header {
                text-align: center;
                background-color: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 20px;
            }
            
            .controls {
                text-align: center;
                margin: 20px 0;
            }
            
            .btn {
                padding: 12px 24px;
                margin: 0 10px;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-size: 16px;
                font-weight: bold;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: all 0.3s;
            }
            
            .btn-print {
                background-color: #28a745;
                color: white;
            }
            
            .btn-print:hover {
                background-color: #218838;
            }
            
            .btn-close {
                background-color: #dc3545;
                color: white;
            }
            
            .btn-close:hover {
                background-color: #c82333;
            }
            
            .btn-auto {
                background-color: #ffc107;
                color: #000;
            }
            
            .btn-auto:hover {
                background-color: #e0a800;
            }
            
            .preview-container {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                justify-content: center;
                padding: 20px;
                background-color: white;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            
            .etiqueta-preview {
                width: 155px; /* 32mm aproximado */
                height: 121px; /* 25mm aproximado */
                border: 1px solid #ccc;
                padding: 5px;
                text-align: center;
                font-size: 9px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
            }
            
            .nombre-preview {
                font-size: 10px;
                font-weight: bold;
                max-height: 20px;
                overflow: hidden;
            }
            
            .precio-preview {
                font-size: 11px;
                font-weight: bold;
                color: #28a745;
            }
            
            .barcode-preview {
                height: 25px;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            
            .barcode-preview svg {
                max-width: 100%;
                height: 25px !important;
            }
        }
        
        /* ESTILOS PARA IMPRESIÓN - CRÍTICOS */
        @media print {
            /* Reset total para impresión */
            * {
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                background: transparent !important;
            }
            
            body {
                width: 80mm !important;
                margin: 0 auto !important;
                padding: 0 !important;
                font-size: 8pt !important;
                background: white !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            
            /* Ocultar todo excepto las etiquetas */
            .no-print, .header, .controls, .preview-container {
                display: none !important;
                width: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
                visibility: hidden !important;
            }
            
            /* Contenedor de impresión */
            .print-area {
                display: block !important;
                width: 80mm !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* Configuración de página */
            @page {
                size: 80mm auto;
                margin: 0mm !important;
                padding: 0 !important;
            }
            
            /* Fila de etiquetas */
            .print-row {
                width: 80mm !important;
                height: 25mm !important;
                display: flex !important;
                margin: 0 !important;
                padding: 0 !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            
            /* Etiqueta individual - dimensiones exactas */
            .etiqueta-print {
                width: 32mm !important;
                height: 25mm !important;
                border: 0.2mm solid #000 !important;
                padding: 0.5mm !important;
                text-align: center !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: space-between !important;
                font-size: 7pt !important;
                margin: 0 !important;
                overflow: hidden !important;
                page-break-inside: avoid !important;
                break-inside: avoid !important;
                box-sizing: border-box !important;
            }
            
            .nombre-print {
                font-size: 7pt !important;
                font-weight: bold !important;
                max-height: 6mm !important;
                overflow: hidden !important;
            }
            
            .precio-print {
                font-size: 8pt !important;
                font-weight: bold !important;
            }
            
            .codigo-print {
                font-size: 6pt !important;
                font-family: monospace !important;
            }
            
            .barcode-print {
                height: 5mm !important;
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
            }
            
            .barcode-print svg {
                max-width: 90% !important;
                height: 5mm !important;
            }
        }
    </style>
    <script>
        // Función para imprimir de manera confiable
        function imprimirEtiquetas() {
            console.log("Iniciando impresión...");
            
            // Generar todos los códigos de barras primero
            generarCodigosBarras();
            
            // Pequeña pausa para asegurar que los códigos se generen
            setTimeout(function() {
                // Mostrar área de impresión
                document.querySelector('.print-area').style.display = 'block';
                document.querySelector('.preview-container').style.display = 'none';
                document.querySelector('.controls').style.display = 'none';
                document.querySelector('.header').style.display = 'none';
                
                // Forzar reflow
                document.body.offsetHeight;
                
                // Llamar a la impresión
                window.print();
                
                // Restaurar vista después de imprimir
                setTimeout(function() {
                    document.querySelector('.print-area').style.display = 'none';
                    document.querySelector('.preview-container').style.display = 'flex';
                    document.querySelector('.controls').style.display = 'block';
                    document.querySelector('.header').style.display = 'block';
                }, 100);
                
            }, 300);
        }
        
        // Función para auto-imprimir
        function autoImprimir() {
            generarCodigosBarras();
            setTimeout(function() {
                window.print();
                // Cerrar ventana después de imprimir (opcional)
                setTimeout(function() {
                    window.close();
                }, 1500);
            }, 1000);
        }
        
        // Función para generar códigos de barras
        function generarCodigosBarras() {
            <?php foreach ($productos_seleccionados as $producto): 
                $codigo_barras = !empty($producto['codigo_barras']) ? $producto['codigo_barras'] : ($producto['codigo'] ?? '');
                if ($codigo_barras):
            ?>
            try {
                // Para vista previa
                JsBarcode('#barcode-preview-<?php echo $producto['id']; ?>', '<?php echo addslashes($codigo_barras); ?>', {
                    format: "CODE128",
                    width: 1,
                    height: 25,
                    displayValue: false,
                    margin: 0
                });
                
                // Para impresión
                JsBarcode('#barcode-print-<?php echo $producto['id']; ?>', '<?php echo addslashes($codigo_barras); ?>', {
                    format: "CODE128",
                    width: 0.8,
                    height: 20,
                    displayValue: false,
                    margin: 0
                });
                
            } catch (e) {
                console.error("Error con código <?php echo $producto['id']; ?>:", e);
            }
            <?php 
                endif;
            endforeach; 
            ?>
        }
        
        // Inicializar al cargar
        document.addEventListener('DOMContentLoaded', function() {
            generarCodigosBarras();
            
            <?php if ($auto_print): ?>
            // Auto-imprimir si viene el parámetro
            autoImprimir();
            <?php endif; ?>
            
            // Evento para detectar si la impresión fue cancelada
            window.addEventListener('afterprint', function() {
                console.log("Diálogo de impresión cerrado");
            });
        });
    </script>
</head>
<body>
    <!-- ENCABEZADO (solo en pantalla) -->
    <div class="header no-print">
        <h2>🖨️ Impresión de Etiquetas 32x25mm</h2>
        <p>Total: <?php echo count($productos_seleccionados); ?> etiquetas | 
           Filas: <?php echo ceil(count($productos_seleccionados) / 2); ?></p>
    </div>
    
    <!-- CONTROLES (solo en pantalla) -->
    <div class="controls no-print">
        <button class="btn btn-print" onclick="imprimirEtiquetas()">
            <i class="fas fa-print"></i> IMPRIMIR AHORA
        </button>
        <button class="btn btn-auto" onclick="autoImprimir()">
            <i class="fas fa-bolt"></i> AUTO-IMPRIMIR
        </button>
        <button class="btn btn-close" onclick="window.close()">
            <i class="fas fa-times"></i> CERRAR
        </button>
    </div>
    
    <!-- VISTA PREVIA EN PANTALLA -->
    <div class="preview-container no-print">
        <?php foreach ($productos_seleccionados as $producto): 
            $codigo_barras = !empty($producto['codigo_barras']) ? $producto['codigo_barras'] : ($producto['codigo'] ?? '');
        ?>
        <div class="etiqueta-preview">
            <div class="nombre-preview"><?php echo htmlspecialchars($producto['nombre'] ?? ''); ?></div>
            <div class="precio-preview">$<?php echo number_format($producto['precio_venta'] ?? 0, 2); ?></div>
            <div class="codigo-preview"><?php echo htmlspecialchars($producto['codigo'] ?? ''); ?></div>
            <div class="barcode-preview">
                <svg id="barcode-preview-<?php echo $producto['id']; ?>"></svg>
            </div>
            <div class="codigo-preview"><?php echo htmlspecialchars($codigo_barras); ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- ÁREA DE IMPRESIÓN (oculta en pantalla, visible al imprimir) -->
    <div class="print-area" style="display: none;">
        <?php 
        // Organizar en filas de 2 etiquetas
        $total = count($productos_seleccionados);
        for ($i = 0; $i < $total; $i += 2):
        ?>
        <div class="print-row">
            <!-- Primera etiqueta de la fila -->
            <?php if (isset($productos_seleccionados[$i])): 
                $p1 = $productos_seleccionados[$i];
                $cb1 = !empty($p1['codigo_barras']) ? $p1['codigo_barras'] : ($p1['codigo'] ?? '');
            ?>
            <div class="etiqueta-print">
                <div class="nombre-print"><?php echo htmlspecialchars($p1['nombre'] ?? ''); ?></div>
                <div class="precio-print">$<?php echo number_format($p1['precio_venta'] ?? 0, 2); ?></div>
                <div class="codigo-print"><?php echo htmlspecialchars($p1['codigo'] ?? ''); ?></div>
                <div class="barcode-print">
                    <svg id="barcode-print-<?php echo $p1['id']; ?>"></svg>
                </div>
                <div class="codigo-print"><?php echo htmlspecialchars($cb1); ?></div>
            </div>
            <?php endif; ?>
            
            <!-- Segunda etiqueta de la fila -->
            <?php if (isset($productos_seleccionados[$i + 1])): 
                $p2 = $productos_seleccionados[$i + 1];
                $cb2 = !empty($p2['codigo_barras']) ? $p2['codigo_barras'] : ($p2['codigo'] ?? '');
            ?>
            <div class="etiqueta-print">
                <div class="nombre-print"><?php echo htmlspecialchars($p2['nombre'] ?? ''); ?></div>
                <div class="precio-print">$<?php echo number_format($p2['precio_venta'] ?? 0, 2); ?></div>
                <div class="codigo-print"><?php echo htmlspecialchars($p2['codigo'] ?? ''); ?></div>
                <div class="barcode-print">
                    <svg id="barcode-print-<?php echo $p2['id']; ?>"></svg>
                </div>
                <div class="codigo-print"><?php echo htmlspecialchars($cb2); ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php endfor; ?>
    </div>
    
    <!-- Iconos de FontAwesome -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/js/all.min.js"></script>
    
    <script>
        // Solución alternativa: Usar iframe para impresión
        function imprimirConIframe() {
            generarCodigosBarras();
            
            setTimeout(function() {
                // Crear un iframe temporal
                var iframe = document.createElement('iframe');
                iframe.style.position = 'absolute';
                iframe.style.width = '0';
                iframe.style.height = '0';
                iframe.style.border = 'none';
                document.body.appendChild(iframe);
                
                // Escribir contenido en el iframe
                var printContent = document.querySelector('.print-area').innerHTML;
                var iframeDoc = iframe.contentWindow || iframe.contentDocument;
                if (iframeDoc.document) iframeDoc = iframeDoc.document;
                
                iframeDoc.open();
                iframeDoc.write(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Imprimir Etiquetas</title>
                        <style>
                            @page { size: 80mm auto; margin: 0; }
                            body { width: 80mm; margin: 0 auto; font-size: 8pt; }
                            .print-row { width: 80mm; height: 25mm; display: flex; }
                            .etiqueta-print { 
                                width: 32mm; height: 25mm; border: 0.2mm solid #000; 
                                padding: 0.5mm; text-align: center; font-size: 7pt;
                                display: flex; flex-direction: column; justify-content: space-between;
                            }
                        </style>
                    </head>
                    <body>${printContent}</body>
                    </html>
                `);
                iframeDoc.close();
                
                // Imprimir el iframe
                setTimeout(function() {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                    
                    // Remover iframe después de imprimir
                    setTimeout(function() {
                        document.body.removeChild(iframe);
                    }, 1000);
                }, 500);
                
            }, 300);
        }
        
        // Agregar botón alternativo
        document.addEventListener('DOMContentLoaded', function() {
            var controls = document.querySelector('.controls');
            if (controls) {
                var altBtn = document.createElement('button');
                altBtn.className = 'btn btn-auto';
                altBtn.innerHTML = '<i class="fas fa-code"></i> IMPRIMIR (Método alternativo)';
                altBtn.onclick = imprimirConIframe;
                controls.appendChild(altBtn);
            }
        });
    </script>
</body>
</html>