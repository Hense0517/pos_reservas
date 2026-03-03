<?php
// Auto-fixed: 2026-02-17 01:57:21
require_once '../../../../includes/config.php';
// generar_etiquetas.php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Inicializar arrays si no existen
if (!isset($_SESSION['productos_etiquetas'])) {
    $_SESSION['productos_etiquetas'] = [];
}
if (!isset($_SESSION['cantidades_productos'])) {
    $_SESSION['cantidades_productos'] = [];
}

// Obtener productos de la sesión
$productos_seleccionados = $_SESSION['productos_etiquetas'] ?? [];
$cantidades_productos = $_SESSION['cantidades_productos'] ?? [];

// Expandir productos según cantidades
$productos_expandidos = [];
foreach ($productos_seleccionados as $id => $producto) {
    $cantidad = isset($cantidades_productos[$id]) ? max(1, intval($cantidades_productos[$id])) : 1;
    for ($i = 0; $i < $cantidad; $i++) {
        $productos_expandidos[] = [
            'id' => $producto['id'] ?? 0,
            'nombre' => $producto['nombre'] ?? '',
            'codigo_barras' => $producto['codigo_barras'] ?? ($producto['codigo'] ?? ''),
            'unique_id' => ($producto['id'] ?? 0) . '_' . $i
        ];
    }
}

// Calcular totales
$total_etiquetas = count($productos_expandidos);
$filas_necesarias = ceil($total_etiquetas / 2);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impresión de Etiquetas 30x25mm</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        /* RESET Y CONFIGURACIÓN BASE */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* PARA PANTALLA - CONTROLES VISIBLES */
        @media screen {
            body {
                background-color: #f5f5f5;
                padding: 20px;
                font-family: Arial, sans-serif;
                max-width: 210mm;
                margin: 0 auto;
            }
            
            .print-controls {
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                margin-bottom: 20px;
                text-align: center;
            }
            
            .print-controls h1 {
                color: #333;
                margin-bottom: 15px;
                font-size: 24px;
            }
            
            .stats-container {
                display: flex;
                justify-content: center;
                gap: 40px;
                margin: 20px 0;
            }
            
            .stat-box {
                text-align: center;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 8px;
                min-width: 120px;
            }
            
            .stat-value {
                font-size: 32px;
                font-weight: bold;
                color: #2c5282;
                display: block;
            }
            
            .stat-label {
                font-size: 14px;
                color: #666;
                margin-top: 5px;
            }
            
            .paper-info {
                background: #e6f7ff;
                padding: 10px;
                border-radius: 5px;
                margin: 15px 0;
                font-size: 14px;
            }
            
            .button-group {
                display: flex;
                justify-content: center;
                gap: 10px;
                flex-wrap: wrap;
                margin-top: 20px;
            }
            
            .print-btn {
                padding: 12px 24px;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-size: 14px;
                font-weight: 600;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                transition: all 0.3s;
            }
            
            .btn-primary {
                background: #2c5282;
                color: white;
            }
            
            .btn-primary:hover {
                background: #1a365d;
            }
            
            .btn-success {
                background: #38a169;
                color: white;
            }
            
            .btn-success:hover {
                background: #276749;
            }
            
            .btn-secondary {
                background: #718096;
                color: white;
            }
            
            .btn-secondary:hover {
                background: #4a5568;
            }
            
            .btn-warning {
                background: #ed8936;
                color: white;
            }
            
            .btn-warning:hover {
                background: #c05621;
            }
            
            /* CONTENEDOR DE ETIQUETAS EN PANTALLA */
            .etiquetas-container-screen {
                background: white;
                padding: 20px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                display: flex;
                flex-wrap: wrap;
                gap: 2mm; /* 2mm entre etiquetas */
                justify-content: center;
                width: 100%;
                max-width: 210mm;
                margin: 0 auto;
            }
            
            /* ETIQUETA EN PANTALLA */
            .etiqueta-screen {
                width: 30mm; /* 30mm */
                height: 25mm; /* 25mm */
                border: 1px solid #ccc;
                border-radius: 2px;
                padding: 1mm;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                background: white;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .nombre-screen {
                font-size: 7px;
                font-weight: bold;
                text-align: center;
                max-height: 7mm;
                overflow: hidden;
                text-overflow: ellipsis;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                line-height: 1.1;
                margin-bottom: 0.5mm;
                width: 100%;
            }
            
            .barcode-container-screen {
                height: 9mm;
                width: 100%;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            
            .barcode-container-screen svg {
                height: 9mm !important;
                max-width: 95% !important;
            }
        }
        
        /* PARA IMPRESIÓN - MEDIDAS EXACTAS */
        @media print {
            /* OCULTAR CONTROLES */
            .print-controls {
                display: none !important;
            }
            
            /* CONFIGURACIÓN DE PÁGINA */
            @page {
                size: 80mm auto; /* Ancho fijo de 80mm, alto automático */
                margin: 0mm; /* Sin márgenes - lo controlamos nosotros */
                padding: 0mm;
            }
            
            body {
                width: 80mm !important; /* Ancho exacto del papel */
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                background: white !important;
                font-family: Arial, sans-serif !important;
                font-size: 6pt !important; /* Más pequeño para caber */
            }
            
            /* CONTENEDOR DE ETIQUETAS PARA IMPRESIÓN */
            .etiquetas-container-print {
                width: 80mm; /* Ancho exacto del papel */
                display: flex;
                flex-wrap: wrap;
                padding: 2mm; /* Margen exterior de 2mm */
                box-sizing: border-box;
                gap: 2mm 2mm; /* 2mm de espacio entre etiquetas (vertical y horizontal) */
            }
            
            /* ETIQUETA INDIVIDUAL PARA IMPRESIÓN - 30x25mm exactos */
            .etiqueta-print {
                width: 30mm !important; /* 30mm de ancho exacto */
                height: 25mm !important; /* 25mm de alto exacto */
                border: 0.2mm solid #000;
                page-break-inside: avoid;
                break-inside: avoid;
                box-sizing: border-box;
                padding: 0.5mm; /* Padding interno mínimo */
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                align-items: center;
                text-align: center;
                overflow: hidden;
                background: white;
            }
            
            /* CÁLCULO EXACTO: 
               Papel: 80mm de ancho
               2 etiquetas de 30mm = 60mm
               Separación entre ellas: 2mm
               Margen izquierdo: 2mm
               Margen derecho: 2mm
               Márgenes laterales: 2 + 2 = 4mm
               Total: 30 + 2 + 30 + 4 = 66mm (queda 14mm para los bordes)
               
               VERIFICACIÓN: 2mm + 30mm + 2mm + 30mm + 2mm = 66mm
               Los 14mm restantes son para el padding del contenedor (2mm cada lado)
            */
            
            .nombre-print {
                font-size: 5.5pt !important; /* Más pequeño para caber */
                font-weight: bold;
                max-height: 8mm;
                height: 8mm;
                overflow: hidden;
                text-overflow: ellipsis;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                line-height: 1 !important;
                margin: 0.3mm 0;
                width: 100%;
                padding: 0 0.5mm;
            }
            
            .barcode-container-print {
                height: 12mm !important; /* Altura para código de barras */
                width: 100%;
                display: flex;
                justify-content: center;
                align-items: center;
                margin: 0;
                padding: 0;
            }
            
            .barcode-container-print svg {
                height: 10mm !important;
                max-width: 95% !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* Asegurar que no haya márgenes adicionales */
            .etiqueta-print * {
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* Forzar que las etiquetas no se estiren */
            .etiqueta-print {
                flex-shrink: 0;
                flex-grow: 0;
            }
        }
        
        /* ESTILOS COMPARTIDOS */
        .hidden {
            display: none !important;
        }
        
        /* CLASE ESPECIAL PARA DEBUG */
        .debug-grid {
            position: relative;
        }
        
        .debug-grid::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                linear-gradient(to right, rgba(255,0,0,0.1) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255,0,0,0.1) 1px, transparent 1px);
            background-size: 5mm 5mm; /* Cuadrícula de 5mm */
            pointer-events: none;
        }
    </style>
</head>
<body>
    <!-- CONTROLES DE IMPRESIÓN (SOLO EN PANTALLA) -->
    <div class="print-controls">
        <h1>🖨️ Impresión de Etiquetas 30x25mm</h1>
        
        <div class="paper-info">
            <strong>Configuración EXACTA:</strong><br>
            • Papel: 80mm de ancho<br>
            • Etiquetas: 30×25mm<br>
            • 2 columnas por fila<br>
            • Bordes: 2mm en todos lados<br>
            • Separación entre etiquetas: 2mm (horizontal y vertical)
        </div>
        
        <div class="stats-container">
            <div class="stat-box">
                <span class="stat-value"><?php echo $total_etiquetas; ?></span>
                <span class="stat-label">Total etiquetas</span>
            </div>
            <div class="stat-box">
                <span class="stat-value"><?php echo $filas_necesarias; ?></span>
                <span class="stat-label">Filas necesarias</span>
            </div>
            <div class="stat-box">
                <span class="stat-value"><?php echo count($productos_seleccionados); ?></span>
                <span class="stat-label">Productos diferentes</span>
            </div>
        </div>
        
        <div class="button-group">
            <button class="print-btn btn-primary" onclick="imprimirAhora()">
                <i class="fas fa-print"></i> IMPRIMIR AHORA
            </button>
            <button class="print-btn btn-success" onclick="autoImprimir()">
                <i class="fas fa-bolt"></i> AUTO-IMPRIMIR
            </button>
            <button class="print-btn btn-secondary" onclick="cerrarVentana()">
                <i class="fas fa-times"></i> CERRAR
            </button>
            <button class="print-btn btn-warning" onclick="imprimirAlternativo()">
                <i class="fas fa-print"></i> IMPRIMIR (Alternativo)
            </button>
            <button class="print-btn btn-primary" onclick="verificarMedidas()" style="background: #9c27b0;">
                <i class="fas fa-ruler"></i> VERIFICAR MEDIDAS
            </button>
        </div>
    </div>
    
    <!-- VISTA PREVIA EN PANTALLA -->
    <div class="etiquetas-container-screen" id="vista-pantalla">
        <?php if (!empty($productos_expandidos)): ?>
            <?php foreach ($productos_expandidos as $item): ?>
                <div class="etiqueta-screen">
                    <div class="nombre-screen"><?php echo htmlspecialchars($item['nombre']); ?></div>
                    <div class="barcode-container-screen">
                        <svg class="barcode-screen" id="barcode-screen-<?php echo $item['unique_id']; ?>"></svg>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; width: 100%;">
                <h3>No hay etiquetas para mostrar</h3>
                <p>Selecciona productos desde la página principal.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- CONTENEDOR PARA IMPRESIÓN (OCULTO EN PANTALLA) -->
    <div class="etiquetas-container-print hidden" id="contenedor-impresion">
        <?php if (!empty($productos_expandidos)): ?>
            <?php foreach ($productos_expandidos as $item): ?>
                <div class="etiqueta-print">
                    <div class="nombre-print"><?php echo htmlspecialchars($item['nombre']); ?></div>
                    <div class="barcode-container-print">
                        <svg class="barcode-print" id="barcode-print-<?php echo $item['unique_id']; ?>"></svg>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Incluir Font Awesome para íconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <script>
        // Generar códigos de barras para ambas vistas
        document.addEventListener('DOMContentLoaded', function() {
            const productos = <?php echo json_encode($productos_expandidos); ?>;
            
            console.log('Generando códigos de barras para', productos.length, 'etiquetas');
            console.log('Configuración EXACTA:');
            console.log('- Papel: 80mm de ancho');
            console.log('- Etiquetas: 30×25mm');
            console.log('- Bordes: 2mm');
            console.log('- Separación: 2mm horizontal y vertical');
            
            // Generar para vista pantalla
            productos.forEach(function(producto) {
                const codigo = producto.codigo_barras;
                const barcodeIdScreen = 'barcode-screen-' + producto.unique_id;
                const barcodeIdPrint = 'barcode-print-' + producto.unique_id;
                
                if (codigo) {
                    try {
                        // Vista pantalla
                        JsBarcode(`#${barcodeIdScreen}`, codigo, {
                            format: "CODE128",
                            width: 1.5,
                            height: 25,
                            displayValue: false,
                            margin: 0,
                            background: "transparent"
                        });
                        
                        // Vista impresión (más delgado para caber mejor)
                        JsBarcode(`#${barcodeIdPrint}`, codigo, {
                            format: "CODE128",
                            width: 0.8, // Más delgado para 30mm
                            height: 20,
                            displayValue: false,
                            margin: 0,
                            background: "transparent"
                        });
                        
                        console.log(`✓ Generado: ${producto.nombre.substring(0, 20)}...`);
                    } catch (error) {
                        console.error('Error generando código de barras:', error);
                    }
                }
            });
            
            // Mostrar distribución
            mostrarDistribucion();
        });
        
        function mostrarDistribucion() {
            const total = <?php echo $total_etiquetas; ?>;
            const filas = <?php echo $filas_necesarias; ?>;
            console.log(`Distribución: ${total} etiquetas en ${filas} filas`);
            console.log('Cálculo exacto:');
            console.log('• 2 etiquetas por fila (30mm cada una)');
            console.log('• Separación horizontal: 2mm');
            console.log('• Márgenes laterales: 2mm + 2mm');
            console.log('• Total ancho: 2 + 30 + 2 + 30 + 2 = 66mm dentro de 80mm');
            console.log('• Altura por fila: 25mm + 2mm separación = 27mm');
        }
        
        // Función para verificar medidas
        function verificarMedidas() {
            const contenedor = document.getElementById('contenedor-impresion');
            const etiquetas = contenedor.querySelectorAll('.etiqueta-print');
            
            console.log('=== VERIFICACIÓN DE MEDIDAS ===');
            console.log(`Contenedor: ${contenedor.offsetWidth}px (debería ser ~80mm = ~302px aprox)`);
            console.log(`Número de etiquetas: ${etiquetas.length}`);
            
            if (etiquetas.length > 0) {
                const primera = etiquetas[0];
                console.log(`Ancho etiqueta: ${primera.offsetWidth}px (debería ser ~30mm = ~113px)`);
                console.log(`Alto etiqueta: ${primera.offsetHeight}px (debería ser ~25mm = ~94px)`);
                
                // Convertir px a mm (1mm ≈ 3.78px en pantalla, pero en impresión es exacto)
                const pxPerMm = 3.779527559; // 96 DPI / 25.4
                console.log(`Ancho en mm: ${primera.offsetWidth / pxPerMm}mm`);
                console.log(`Alto en mm: ${primera.offsetHeight / pxPerMm}mm`);
            }
            
            // Mostrar alerta con información
            alert(`VERIFICACIÓN DE MEDIDAS:\n\n` +
                  `• Papel: 80mm de ancho\n` +
                  `• Etiquetas: 30×25mm\n` +
                  `• Bordes: 2mm\n` +
                  `• Separación: 2mm\n` +
                  `• Total etiquetas: ${total_etiquetas}\n` +
                  `• Filas necesarias: ${filas_necesarias}\n\n` +
                  `Revisa la consola para más detalles (F12)`);
        }
        
        // Función para imprimir ahora
        function imprimirAhora() {
            console.log('Iniciando impresión con medidas exactas...');
            
            // Mostrar el contenedor de impresión y ocultar el de pantalla
            document.getElementById('contenedor-impresion').classList.remove('hidden');
            document.getElementById('vista-pantalla').classList.add('hidden');
            document.querySelector('.print-controls').classList.add('hidden');
            
            // Aplicar estilos de impresión al body
            document.body.style.width = '80mm';
            document.body.style.margin = '0';
            document.body.style.padding = '0';
            
            // Pequeña pausa para renderizar
            setTimeout(function() {
                window.print();
                
                // Restaurar después de imprimir
                setTimeout(function() {
                    document.getElementById('contenedor-impresion').classList.add('hidden');
                    document.getElementById('vista-pantalla').classList.remove('hidden');
                    document.querySelector('.print-controls').classList.remove('hidden');
                    document.body.style.width = '';
                    document.body.style.margin = '';
                    document.body.style.padding = '';
                }, 500);
            }, 100);
        }
        
        // Función auto-imprimir
        function autoImprimir() {
            const btn = event.target;
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> IMPRIMIENDO...';
            btn.disabled = true;
            
            setTimeout(function() {
                imprimirAhora();
                
                // Restaurar botón después de 2 segundos
                setTimeout(function() {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }, 2000);
            }, 1000);
        }
        
        // Función cerrar ventana
        function cerrarVentana() {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.close();
            }
        }
        
        // Función impresión alternativa
        function imprimirAlternativo() {
            console.log('Usando método de impresión alternativo con medidas exactas');
            
            const productos = <?php echo json_encode($productos_expandidos); ?>;
            
            // Crear una ventana nueva para imprimir
            const printWindow = window.open('', '_blank');
            
            let html = `<!DOCTYPE html>
                <html>
                <head>
                    <title>Impresión de Etiquetas - 80mm</title>
                    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"><\/script>
                    <style>
                        @page {
                            size: 80mm auto;
                            margin: 0;
                        }
                        body {
                            width: 80mm !important;
                            margin: 0 !important;
                            padding: 2mm !important;
                            font-family: Arial, sans-serif !important;
                            font-size: 5.5pt !important;
                        }
                        .contenedor-exacto {
                            width: 76mm !important; /* 80mm - 4mm de padding */
                            display: flex;
                            flex-wrap: wrap;
                            gap: 2mm !important; /* Separación de 2mm */
                            margin: 0 !important;
                            padding: 0 !important;
                        }
                        .etiqueta-exacta {
                            width: 30mm !important;
                            height: 25mm !important;
                            border: 0.2mm solid #000 !important;
                            padding: 0.5mm !important;
                            display: flex !important;
                            flex-direction: column !important;
                            justify-content: space-between !important;
                            align-items: center !important;
                            text-align: center !important;
                            overflow: hidden !important;
                            box-sizing: border-box !important;
                            page-break-inside: avoid !important;
                            break-inside: avoid !important;
                        }
                        .nombre-exacto {
                            font-size: 5.5pt !important;
                            font-weight: bold !important;
                            max-height: 8mm !important;
                            height: 8mm !important;
                            overflow: hidden !important;
                            line-height: 1 !important;
                            margin: 0.3mm 0 !important;
                            width: 100% !important;
                            padding: 0 0.5mm !important;
                        }
                        .codigo-barras-exacto {
                            height: 12mm !important;
                            width: 100% !important;
                            display: flex !important;
                            justify-content: center !important;
                            align-items: center !important;
                            margin: 0 !important;
                            padding: 0 !important;
                        }
                        .codigo-barras-exacto svg {
                            height: 10mm !important;
                            max-width: 95% !important;
                        }
                    </style>
                </head>
                <body>
                    <div class="contenedor-exacto">`;
            
            productos.forEach(function(producto, index) {
                html += `
                    <div class="etiqueta-exacta">
                        <div class="nombre-exacto">${producto.nombre.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>
                        <div class="codigo-barras-exacto">
                            <svg id="barcode-alt-${index}"></svg>
                        </div>
                    </div>`;
            });
            
            html += `
                    </div>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const productos = ${JSON.stringify(productos)};
                            
                            productos.forEach(function(producto, index) {
                                const codigo = producto.codigo_barras;
                                if (codigo) {
                                    try {
                                        JsBarcode('#barcode-alt-' + index, codigo, {
                                            format: "CODE128",
                                            width: 0.8,
                                            height: 20,
                                            displayValue: false,
                                            margin: 0
                                        });
                                    } catch (error) {
                                        console.error('Error:', error);
                                    }
                                }
                            });
                            
                            // Auto-imprimir después de 500ms
                            setTimeout(function() {
                                window.print();
                                setTimeout(function() {
                                    window.close();
                                }, 1000);
                            }, 500);
                        });
                    <\/script>
                </body>
                </html>`;
            
            printWindow.document.write(html);
            printWindow.document.close();
        }
        
        // Evento después de imprimir
        window.onafterprint = function() {
            console.log('Impresión completada - Restaurando vista...');
            // Restaurar vista
            document.getElementById('contenedor-impresion').classList.add('hidden');
            document.getElementById('vista-pantalla').classList.remove('hidden');
            document.querySelector('.print-controls').classList.remove('hidden');
        };
        
        // Variables globales para uso en funciones
        const total_etiquetas = <?php echo $total_etiquetas; ?>;
        const filas_necesarias = <?php echo $filas_necesarias; ?>;
    </script>
</body>
</html>