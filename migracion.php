<?php
// migracion_final.php - Versión simplificada y corregida
session_start();

// Incluir configuración de base de datos
require_once 'includes/config.php';
// Verificar sesión
if (!isset($_SESSION['usuario_rol']) || $_SESSION['usuario_rol'] != 'admin') {
    header('Location: login.php');
    exit;
}

// Conectar a la base de datos
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Función para normalizar nombres de productos
function normalizarNombreProducto($nombre) {
    $nombre = mb_strtolower($nombre, 'UTF-8');
    
    // Lista de palabras a remover
    $remover = [
        // Tallas
        'talla', 'tamano', 'size', 'tam', 't',
        'xxs', 'xs', 's', 'm', 'l', 'xl', 'xxl', 'xxxl',
        's/m', 'm/l', 'l/xl', 's-m', 'm-l',
        '1t', '2t', '3t', '4t',
        
        // Colores
        'blanco', 'negro', 'rojo', 'azul', 'verde', 'amarillo', 'rosado', 'morado',
        'gris', 'marron', 'marrón', 'naranja', 'beige', 'celeste', 'turquesa', 'vino',
        'dorado', 'plateado', 'chocolate', 'marfil', 'crudo', 'mocca', 'arena',
        'degradé', 'nude', 'vinotinto', 'rosa', 'café', 'piel', 'avena', 'beis',
        'kaki', 'azul marino', 'azul cielo', 'coral', 'lila', 'mostaza', 'olivo',
        
        // Marcas/textos
        'unica', 'única', 'brashali', 'guess', 'ck', 'calvin klein', 'tommy',
        'victoria', 'victoria\'s secret', 'b&b', 'bath & body works', 'b´lus',
        'rayban', 'versace', 'kiko', 'cello', 'toomy', 'blus',
        
        // Otros
        'print', 'estampada', 'clásica', 'clasica', 'brillante', 'sisa', 'largo',
        'corto', 'larga', 'corta', 'ml'
    ];
    
    foreach ($remover as $palabra) {
        $nombre = preg_replace('/\s*' . preg_quote($palabra, '/') . '\s*/i', ' ', $nombre);
    }
    
    // Remover números que sean tallas (4-46)
    $nombre = preg_replace('/\s*\b([4-9]|[1-3][0-9]|4[0-6])\b\s*/', ' ', $nombre);
    
    // Limpiar y normalizar
    $nombre = trim($nombre);
    $nombre = preg_replace('/\s+/', ' ', $nombre);
    $nombre = preg_replace('/^\s+|\s+$/', '', $nombre);
    
    return ucwords($nombre);
}

// Función para extraer atributos
function extraerAtributos($nombre) {
    $atributos = ['talla' => null, 'color' => null];
    
    // Buscar talla
    if (preg_match('/talla\s+([^\s]+)/i', $nombre, $matches)) {
        $atributos['talla'] = strtoupper(trim($matches[1]));
    }
    
    // Buscar colores
    $colores = ['blanco', 'negro', 'rojo', 'azul', 'verde', 'amarillo', 'rosado', 'morado',
                'gris', 'marron', 'marrón', 'naranja', 'beige', 'celeste', 'turquesa', 'vino',
                'dorado', 'plateado', 'chocolate', 'marfil', 'crudo', 'mocca', 'arena',
                'degradé', 'nude', 'vinotinto', 'rosa', 'café', 'piel', 'avena'];
    
    $nombre_lower = mb_strtolower($nombre, 'UTF-8');
    foreach ($colores as $color) {
        if (strpos($nombre_lower, $color) !== false) {
            $atributos['color'] = ucfirst($color);
            break;
        }
    }
    
    return $atributos;
}

// Función para obtener marca
function obtenerMarcaId($pdo, $nombre_producto) {
    $nombre = mb_strtolower($nombre_producto, 'UTF-8');
    
    $marcas = [
        'GUESS' => ['guess'],
        'CALVIN KLEIN' => ['calvin klein', 'ck'],
        'TOMMY HILFIGER' => ['tommy'],
        'BRASHALI' => ['brashali'],
        'ÚNICA' => ['única', 'unica'],
        'VICTORIA\'S SECRET' => ['victoria', 'victoria\'s secret'],
        'BATH & BODY WORKS' => ['b&b', 'bath & body works'],
        'B´LUS' => ['b´lus', 'b&lus'],
        'RAYBAN' => ['rayban'],
        'VERSACE' => ['versace'],
        'KIKO MILANO' => ['kiko'],
        'CELLO' => ['cello'],
        'STANLEY' => ['stanley'],
        'SUPERSTAR' => ['superstar'],
        'TRULY' => ['truly'],
        'BEBE' => ['bebe'],
        'GENÉRICA' => []
    ];
    
    $marca_nombre = 'GENÉRICA';
    foreach ($marcas as $marca => $patrones) {
        foreach ($patrones as $patron) {
            if (strpos($nombre, $patron) !== false) {
                $marca_nombre = $marca;
                break 2;
            }
        }
    }
    
    // Buscar o crear marca
    $stmt = $pdo->prepare("SELECT id FROM marcas WHERE nombre = ?");
    $stmt->execute([$marca_nombre]);
    $marca_id = $stmt->fetchColumn();
    
    if (!$marca_id) {
        $stmt = $pdo->prepare("INSERT INTO marcas (nombre, activo, created_at) VALUES (?, 1, NOW())");
        $stmt->execute([$marca_nombre]);
        $marca_id = $pdo->lastInsertId();
    }
    
    return $marca_id;
}

// Procesar migración
$log = [];
$stats = [
    'productos_procesados' => 0,
    'variaciones_creadas' => 0,
    'productos_simples' => 0,
    'errores' => 0
];

try {
    $pdo->beginTransaction();
    
    $log[] = "=== MIGRACIÓN DE PRODUCTOS ===";
    $log[] = "Inicio: " . date('Y-m-d H:i:s');
    $log[] = "";
    
    // PASO 1: Verificar precios existentes en venta_detalles
    $log[] = "--- PASO 1: Buscando precios históricos ---";
    
    // Crear tabla temporal para precios
    $pdo->exec("CREATE TEMPORARY TABLE IF NOT EXISTS temp_precios_productos (
        producto_id INT PRIMARY KEY,
        precio_venta DECIMAL(10,2) DEFAULT 0,
        precio_compra DECIMAL(10,2) DEFAULT 0,
        stock INT DEFAULT 0
    )");
    
    // Obtener último precio de venta de venta_detalles
    $sql = "INSERT INTO temp_precios_productos (producto_id, precio_venta)
            SELECT producto_id, MAX(precio) as precio_venta
            FROM venta_detalles 
            WHERE producto_id IS NOT NULL AND precio > 0
            GROUP BY producto_id
            ON DUPLICATE KEY UPDATE precio_venta = VALUES(precio_venta)";
    $pdo->exec($sql);
    
    // Obtener último precio de compra de compra_detalles
    $sql = "INSERT INTO temp_precios_productos (producto_id, precio_compra)
            SELECT producto_id, MAX(precio) as precio_compra
            FROM compra_detalles 
            WHERE producto_id IS NOT NULL AND precio > 0
            GROUP BY producto_id
            ON DUPLICATE KEY UPDATE precio_compra = VALUES(precio_compra)";
    $pdo->exec($sql);
    
    $log[] = "✓ Precios históricos cargados en tabla temporal";
    
    // PASO 2: Obtener productos
    $log[] = "";
    $log[] = "--- PASO 2: Obteniendo productos ---";
    
    $sql = "SELECT id, codigo, nombre, categoria_id, activo, created_at 
            FROM productos 
            ORDER BY nombre";
    $stmt = $pdo->query($sql);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $log[] = "✓ Productos encontrados: " . count($productos);
    
    // PASO 3: Agrupar productos
    $log[] = "";
    $log[] = "--- PASO 3: Agrupando productos por nombre base ---";
    
    $grupos = [];
    foreach ($productos as $producto) {
        $nombre_base = normalizarNombreProducto($producto['nombre']);
        
        // Si el nombre base está vacío, usar el original
        if (empty($nombre_base) || strlen($nombre_base) < 3) {
            $nombre_base = $producto['nombre'];
        }
        
        $key = $nombre_base . '_' . $producto['categoria_id'];
        
        if (!isset($grupos[$key])) {
            $grupos[$key] = [
                'nombre_base' => $nombre_base,
                'categoria_id' => $producto['categoria_id'],
                'productos' => []
            ];
        }
        
        $grupos[$key]['productos'][] = $producto;
    }
    
    $log[] = "✓ Grupos creados: " . count($grupos);
    
    // Mostrar algunos grupos de ejemplo
    $ejemplos = array_slice($grupos, 0, 3);
    foreach ($ejemplos as $grupo) {
        if (count($grupo['productos']) > 1) {
            $log[] = "  Ejemplo grupo con variaciones: '" . $grupo['nombre_base'] . "' - " . 
                    count($grupo['productos']) . " productos";
        }
    }
    
    // PASO 4: Procesar migración
    $log[] = "";
    $log[] = "--- PASO 4: Procesando migración ---";
    
    foreach ($grupos as $key => $grupo) {
        $stats['productos_procesados']++;
        
        if (count($grupo['productos']) > 1) {
            // Grupo con variaciones
            $producto_principal = $grupo['productos'][0];
            
            // Asignar marca
            $marca_id = obtenerMarcaId($pdo, $producto_principal['nombre']);
            
            // Marcar producto como con variaciones
            $stmt = $pdo->prepare("UPDATE productos SET tiene_variaciones = 1, marca_id = ? WHERE id = ?");
            $stmt->execute([$marca_id, $producto_principal['id']]);
            
            $log[] = "→ Producto base: " . substr($producto_principal['nombre'], 0, 40) . "...";
            
            // Crear variaciones para cada producto en el grupo
            foreach ($grupo['productos'] as $producto_var) {
                // Extraer atributos
                $atributos = extraerAtributos($producto_var['nombre']);
                
                // Determinar atributo principal
                $atributo_nombre = 'Variante';
                $atributo_valor = 'Estándar';
                
                if (!empty($atributos['talla'])) {
                    $atributo_nombre = 'Talla';
                    $atributo_valor = $atributos['talla'];
                } elseif (!empty($atributos['color'])) {
                    $atributo_nombre = 'Color';
                    $atributo_valor = $atributos['color'];
                }
                
                // Obtener precios históricos
                $stmt = $pdo->prepare("SELECT precio_venta, precio_compra FROM temp_precios_productos WHERE producto_id = ?");
                $stmt->execute([$producto_var['id']]);
                $precios = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $precio_venta = $precios['precio_venta'] ?? 0;
                $precio_compra = $precios['precio_compra'] ?? 0;
                
                // Crear SKU
                $sku = $producto_var['codigo'] . '-' . substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($atributo_valor)), 0, 5);
                
                // Insertar variación
                $sql = "INSERT INTO producto_variaciones 
                       (producto_id, sku, atributo_nombre, atributo_valor, precio_venta, precio_compra, activo, created_at) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                
                try {
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $producto_principal['id'],
                        $sku,
                        $atributo_nombre,
                        $atributo_valor,
                        $precio_venta,
                        $precio_compra,
                        $producto_var['activo'],
                        $producto_var['created_at']
                    ]);
                    
                    $stats['variaciones_creadas']++;
                    $log[] = "  ✓ Variación: " . substr($producto_var['nombre'], 0, 40) . 
                            " [" . $atributo_nombre . ": " . $atributo_valor . "]";
                    
                } catch (PDOException $e) {
                    // Si hay error de SKU duplicado, usar uno alternativo
                    if ($e->getCode() == '23000') {
                        $sku_alt = $producto_var['codigo'] . '-' . substr(uniqid(), 0, 8);
                        $stmt = $pdo->prepare($sql);
                        $stmt->execute([
                            $producto_principal['id'],
                            $sku_alt,
                            $atributo_nombre,
                            $atributo_valor,
                            $precio_venta,
                            $precio_compra,
                            $producto_var['activo'],
                            $producto_var['created_at']
                        ]);
                        $stats['variaciones_creadas']++;
                        $log[] = "  ✓ Variación (SKU alt): " . substr($producto_var['nombre'], 0, 40);
                    } else {
                        $log[] = "  ✗ Error: " . $e->getMessage();
                        $stats['errores']++;
                    }
                }
            }
            
        } else {
            // Producto simple
            $producto = $grupo['productos'][0];
            $marca_id = obtenerMarcaId($pdo, $producto['nombre']);
            
            // Marcar como producto simple
            $stmt = $pdo->prepare("UPDATE productos SET tiene_variaciones = 0, marca_id = ? WHERE id = ?");
            $stmt->execute([$marca_id, $producto['id']]);
            
            $stats['productos_simples']++;
            
            // Crear una variación por defecto para producto simple
            $atributos = extraerAtributos($producto['nombre']);
            $atributo_nombre = 'Variante';
            $atributo_valor = 'Única';
            
            if (!empty($atributos['talla']) || !empty($atributos['color'])) {
                if (!empty($atributos['talla'])) {
                    $atributo_nombre = 'Talla';
                    $atributo_valor = $atributos['talla'];
                } else {
                    $atributo_nombre = 'Color';
                    $atributo_valor = $atributos['color'];
                }
            }
            
            // Obtener precios
            $stmt = $pdo->prepare("SELECT precio_venta, precio_compra FROM temp_precios_productos WHERE producto_id = ?");
            $stmt->execute([$producto['id']]);
            $precios = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $precio_venta = $precios['precio_venta'] ?? 0;
            $precio_compra = $precios['precio_compra'] ?? 0;
            
            // Crear variación única
            $sku = $producto['codigo'] . '-VAR';
            $sql = "INSERT INTO producto_variaciones 
                   (producto_id, sku, atributo_nombre, atributo_valor, precio_venta, precio_compra, activo, created_at) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $producto['id'],
                    $sku,
                    $atributo_nombre,
                    $atributo_valor,
                    $precio_venta,
                    $precio_compra,
                    $producto['activo'],
                    $producto['created_at']
                ]);
                
                $stats['variaciones_creadas']++;
                $log[] = "○ Producto simple con variación: " . substr($producto['nombre'], 0, 40);
                
            } catch (PDOException $e) {
                $log[] = "○ Producto simple: " . substr($producto['nombre'], 0, 40);
            }
        }
    }
    
    $pdo->commit();
    
    // Estadísticas finales
    $log[] = "";
    $log[] = "=== MIGRACIÓN COMPLETADA ===";
    $log[] = "Resumen:";
    $log[] = "  • Productos procesados: " . $stats['productos_procesados'];
    $log[] = "  • Variaciones creadas: " . $stats['variaciones_creadas'];
    $log[] = "  • Productos simples: " . $stats['productos_simples'];
    $log[] = "  • Errores: " . $stats['errores'];
    $log[] = "";
    $log[] = "Fin: " . date('Y-m-d H:i:s');
    
} catch (Exception $e) {
    $pdo->rollBack();
    $log[] = "";
    $log[] = "=== ERROR EN MIGRACIÓN ===";
    $log[] = "Error: " . $e->getMessage();
    $log[] = "Línea: " . $e->getLine();
    $log[] = "Archivo: " . $e->getFile();
    $stats['errores']++;
}

// Mostrar resultados
?>
<!DOCTYPE html>
<html>
<head>
    <title>Migración Final</title>
    <style>
        body { font-family: 'Courier New', monospace; background: #f0f0f0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; }
        .log-line { margin: 2px 0; padding: 2px 10px; }
        .success { color: #27ae60; }
        .error { color: #e74c3c; }
        .info { color: #3498db; }
        .warning { color: #f39c12; font-weight: bold; }
        .stats { background: #2c3e50; color: white; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .btn { display: inline-block; padding: 10px 20px; margin: 10px 5px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; }
        .btn:hover { background: #2980b9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏗️ Migración de Productos</h1>
        <h3>Sistema de Variaciones</h3>
        
        <div class="stats">
            <h3>📊 Estadísticas</h3>
            <p>✅ Productos procesados: <strong><?php echo $stats['productos_procesados']; ?></strong></p>
            <p>🔢 Variaciones creadas: <strong><?php echo $stats['variaciones_creadas']; ?></strong></p>
            <p>📦 Productos simples: <strong><?php echo $stats['productos_simples']; ?></strong></p>
            <p>❌ Errores: <strong><?php echo $stats['errores']; ?></strong></p>
        </div>
        
        <div style="border: 1px solid #ddd; padding: 10px; max-height: 400px; overflow-y: auto; background: #2c3e50; color: #ecf0f1;">
            <h4>📝 Log de Ejecución:</h4>
            <?php foreach ($log as $line): 
                $class = 'info';
                if (strpos($line, '✓') !== false) $class = 'success';
                if (strpos($line, '✗') !== false) $class = 'error';
                if (strpos($line, '→') !== false || strpos($line, '○') !== false) $class = 'info';
                if (strpos($line, '===') !== false || strpos($line, '---') !== false) $class = 'warning';
            ?>
                <div class="log-line <?php echo $class; ?>"><?php echo htmlspecialchars($line); ?></div>
            <?php endforeach; ?>
        </div>
        
        <div style="margin-top: 20px;">
            <a href="verificar_resultados.php" class="btn">🔍 Verificar Resultados</a>
            <a href="modules/inventario/productos/index.php" class="btn">📦 Ir a Productos</a>
            <a href="index.php" class="btn">🏠 Ir al Inicio</a>
        </div>
    </div>
    
    <script>
        // Auto-scroll al final del log
        window.scrollTo(0, document.body.scrollHeight);
    </script>
</body>
</html>