<?php
// modules/reportes/ventas_por_producto.php
session_start();

// Incluir configuraciones
require_once '../../includes/config.php';
require_once '../../config/database.php';

// Verificar permisos
if (!$auth->hasPermission('reportes', 'lectura')) {
    $_SESSION['error'] = "No tienes permisos para acceder a reportes";
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Configurar zona horaria
date_default_timezone_set('America/Bogota');

// Obtener parámetros
$producto_id = $_GET['producto_id'] ?? '';
$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-01');
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');
$fecha_inicio_sql = $fecha_inicio . ' 00:00:00';
$fecha_fin_sql = $fecha_fin . ' 23:59:59';

// ============================================================================
// 1. CONSULTA: PRODUCTOS Y SUS VENTAS (SIMPLIFICADA)
// ============================================================================
$productos_con_ventas = [];

try {
    $query = "SELECT 
        p.id,
        p.codigo,
        p.nombre,
        p.precio_venta,
        p.precio_compra,
        p.stock,
        c.nombre as categoria_nombre,
        m.nombre as marca_nombre,
        
        -- CONTAR CUÁNTAS VECES SE VENDIÓ ESTE PRODUCTO
        COUNT(DISTINCT vd.venta_id) as veces_vendido,
        
        -- SUMAR LA CANTIDAD TOTAL VENDIDA
        COALESCE(SUM(vd.cantidad), 0) as cantidad_vendida,
        
        -- SUMAR EL TOTAL VENDIDO
        COALESCE(SUM(vd.subtotal), 0) as total_vendido,
        
        -- CALCULAR UTILIDAD
        COALESCE(SUM(vd.subtotal - (vd.cantidad * p.precio_compra)), 0) as utilidad_total,
        
        -- OBTENER LA ÚLTIMA FECHA DE VENTA
        MAX(v.fecha) as ultima_venta_fecha
        
    FROM productos p
    LEFT JOIN venta_detalles vd ON p.id = vd.producto_id
    LEFT JOIN ventas v ON vd.venta_id = v.id
    LEFT JOIN categorias c ON p.categoria_id = c.id
    LEFT JOIN marcas m ON p.marca_id = m.id
    
    WHERE p.activo = 1
    AND v.fecha BETWEEN :fecha_inicio AND :fecha_fin
    AND v.anulada = 0
    AND v.estado = 'completada'
    
    GROUP BY p.id, p.codigo, p.nombre
    HAVING veces_vendido > 0
    ORDER BY veces_vendido DESC, total_vendido DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':fecha_inicio' => $fecha_inicio_sql,
        ':fecha_fin' => $fecha_fin_sql
    ]);
    
    $productos_con_ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = "Error en consulta: " . $e->getMessage();
}

// ============================================================================
// 2. DETALLES DE VENTAS DE UN PRODUCTO ESPECÍFICO
// ============================================================================
$detalles_ventas = [];
$info_producto = null;

if (!empty($producto_id) && is_numeric($producto_id)) {
    try {
        // Primero obtener información básica del producto
        $query_producto = "SELECT p.*, c.nombre as categoria_nombre, m.nombre as marca_nombre 
                          FROM productos p 
                          LEFT JOIN categorias c ON p.categoria_id = c.id 
                          LEFT JOIN marcas m ON p.marca_id = m.id 
                          WHERE p.id = ?";
        $stmt_producto = $db->prepare($query_producto);
        $stmt_producto->execute([$producto_id]);
        $info_producto = $stmt_producto->fetch(PDO::FETCH_ASSOC);
        
        // Luego obtener todas las ventas donde aparece este producto
        $query_ventas = "SELECT 
            v.id as venta_id,
            v.numero_factura,
            v.fecha,
            v.total as total_venta,
            v.estado,
            v.metodo_pago,
            v.anulada,
            
            cl.nombre as cliente_nombre,
            cl.numero_documento as cliente_documento,
            
            u.nombre as vendedor,
            
            vd.cantidad,
            vd.precio,
            vd.subtotal,
            
            -- Calcular utilidad de esta venta específica
            (vd.subtotal - (vd.cantidad * p.precio_compra)) as utilidad
            
        FROM venta_detalles vd
        INNER JOIN ventas v ON vd.venta_id = v.id
        INNER JOIN productos p ON vd.producto_id = p.id
        LEFT JOIN clientes cl ON v.cliente_id = cl.id
        LEFT JOIN usuarios u ON v.usuario_id = u.id
        
        WHERE vd.producto_id = ?
        AND v.fecha BETWEEN ? AND ?
        AND v.anulada = 0
        AND v.estado = 'completada'
        
        ORDER BY v.fecha DESC";
        
        $stmt_ventas = $db->prepare($query_ventas);
        $stmt_ventas->execute([
            $producto_id,
            $fecha_inicio_sql,
            $fecha_fin_sql
        ]);
        
        $detalles_ventas = $stmt_ventas->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $error_detalles = "Error obteniendo detalles: " . $e->getMessage();
    }
}

// ============================================================================
// 3. FUNCIONES DE AYUDA
// ============================================================================
function formato_moneda($valor) {
    return '$' . number_format(floatval($valor), 2, ',', '.');
}

function formato_fecha($fecha) {
    if (!$fecha) return 'Nunca';
    return date('d/m/Y H:i', strtotime($fecha));
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas por Producto</title>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
        
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header { 
            background: #2c3e50; 
            color: white; 
            padding: 20px; 
            border-radius: 10px 10px 0 0;
            margin-bottom: 20px;
        }
        
        .card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .filtros {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filtro-item label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }
        
        .filtro-item input, .filtro-item select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }
        
        .btn:hover { background: #2980b9; }
        
        .btn-success { background: #27ae60; }
        .btn-success:hover { background: #219653; }
        
        .btn-danger { background: #e74c3c; }
        .btn-danger:hover { background: #c0392b; }
        
        .btn-outline {
            background: transparent;
            border: 1px solid #3498db;
            color: #3498db;
        }
        
        .btn-outline:hover {
            background: #3498db;
            color: white;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th {
            background: #34495e;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: bold;
        }
        
        td {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        tr:hover { background: #f9f9f9; }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
        
        .resumen {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .resumen-item {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .resumen-item .valor {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .resumen-item .label {
            font-size: 14px;
            color: #666;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #777;
        }
        
        .no-data i {
            font-size: 50px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .acciones {
            display: flex;
            gap: 5px;
        }
        
        .btn-small {
            padding: 5px 10px;
            font-size: 12px;
        }
        
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- ENCABEZADO -->
        <div class="header">
            <h1>📦 Ventas por Producto</h1>
            <p>Consulta qué ventas están relacionadas a cada producto y cuántas veces fue vendido</p>
        </div>
        
        <!-- FILTROS -->
        <div class="card">
            <h3>Filtros de Búsqueda</h3>
            <form method="GET" class="filtros">
                <div class="filtro-item">
                    <label>Fecha Inicio:</label>
                    <input type="date" name="fecha_inicio" value="<?php echo $fecha_inicio; ?>">
                </div>
                
                <div class="filtro-item">
                    <label>Fecha Fin:</label>
                    <input type="date" name="fecha_fin" value="<?php echo $fecha_fin; ?>">
                </div>
                
                <div class="filtro-item">
                    <label>Producto específico (opcional):</label>
                    <select name="producto_id">
                        <option value="">Todos los productos</option>
                        <?php 
                        // Obtener lista de productos para el dropdown
                        try {
                            $query_prod = "SELECT id, codigo, nombre FROM productos WHERE activo = 1 ORDER BY nombre";
                            $stmt_prod = $db->prepare($query_prod);
                            $stmt_prod->execute();
                            $productos = $stmt_prod->fetchAll(PDO::FETCH_ASSOC);
                            
                            foreach ($productos as $prod) {
                                $selected = ($producto_id == $prod['id']) ? 'selected' : '';
                                echo "<option value='{$prod['id']}' {$selected}>{$prod['codigo']} - {$prod['nombre']}</option>";
                            }
                        } catch (Exception $e) {
                            echo "<option>Error cargando productos</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="filtro-item" style="grid-column: span 2;">
                    <button type="submit" class="btn">🔍 Buscar</button>
                    <a href="?" class="btn btn-outline">🔄 Limpiar</a>
                    <button onclick="window.print()" class="btn btn-success no-print">🖨️ Imprimir</button>
                </div>
            </form>
        </div>
        
        <!-- RESUMEN -->
        <?php if ($producto_id && $info_producto): ?>
        <div class="resumen">
            <div class="resumen-item">
                <div class="valor"><?php echo $info_producto['codigo']; ?></div>
                <div class="label">Código del Producto</div>
            </div>
            
            <div class="resumen-item">
                <div class="valor"><?php echo $info_producto['nombre']; ?></div>
                <div class="label">Nombre del Producto</div>
            </div>
            
            <div class="resumen-item">
                <div class="valor"><?php echo formato_moneda($info_producto['precio_venta']); ?></div>
                <div class="label">Precio de Venta</div>
            </div>
            
            <div class="resumen-item">
                <div class="valor"><?php echo count($detalles_ventas); ?></div>
                <div class="label">Ventas encontradas</div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- TABLA DE PRODUCTOS CON VENTAS -->
        <div class="card">
            <h3>
                <?php if ($producto_id && $info_producto): ?>
                    📋 Detalles de Ventas: <?php echo $info_producto['nombre']; ?>
                <?php else: ?>
                    📊 Productos Vendidos (Período: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> - <?php echo date('d/m/Y', strtotime($fecha_fin)); ?>)
                <?php endif; ?>
            </h3>
            
            <?php if (isset($error)): ?>
                <div style="background: #fee; color: #c00; padding: 10px; border-radius: 5px; margin: 10px 0;">
                    ❌ Error: <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($producto_id && $info_producto): ?>
                <!-- DETALLES DE VENTAS DE UN PRODUCTO ESPECÍFICO -->
                <?php if (count($detalles_ventas) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Factura</th>
                                <th>Cliente</th>
                                <th>Cantidad</th>
                                <th>Precio</th>
                                <th>Subtotal</th>
                                <th>Utilidad</th>
                                <th>Método Pago</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_cantidad = 0;
                            $total_subtotal = 0;
                            $total_utilidad = 0;
                            
                            foreach ($detalles_ventas as $venta): 
                                $total_cantidad += $venta['cantidad'];
                                $total_subtotal += $venta['subtotal'];
                                $total_utilidad += $venta['utilidad'];
                            ?>
                            <tr>
                                <td><?php echo formato_fecha($venta['fecha']); ?></td>
                                <td>
                                    <a href="../../modules/ventas/ver.php?id=<?php echo $venta['venta_id']; ?>" 
                                       target="_blank" 
                                       style="color: #3498db; text-decoration: none;">
                                        <?php echo $venta['numero_factura']; ?>
                                    </a>
                                </td>
                                <td><?php echo $venta['cliente_nombre'] ?: 'Cliente no registrado'; ?></td>
                                <td><?php echo $venta['cantidad']; ?></td>
                                <td><?php echo formato_moneda($venta['precio']); ?></td>
                                <td><?php echo formato_moneda($venta['subtotal']); ?></td>
                                <td style="color: <?php echo $venta['utilidad'] >= 0 ? 'green' : 'red'; ?>;">
                                    <?php echo formato_moneda($venta['utilidad']); ?>
                                </td>
                                <td>
                                    <span class="badge 
                                        <?php echo $venta['metodo_pago'] == 'efectivo' ? 'badge-success' : 
                                               ($venta['metodo_pago'] == 'tarjeta' ? 'badge-warning' : 'badge-info'); ?>">
                                        <?php echo ucfirst($venta['metodo_pago']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <!-- TOTALES -->
                            <tr style="background: #f8f9fa; font-weight: bold;">
                                <td colspan="3">TOTALES</td>
                                <td><?php echo $total_cantidad; ?></td>
                                <td>-</td>
                                <td><?php echo formato_moneda($total_subtotal); ?></td>
                                <td style="color: <?php echo $total_utilidad >= 0 ? 'green' : 'red'; ?>;">
                                    <?php echo formato_moneda($total_utilidad); ?>
                                </td>
                                <td>-</td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <div style="margin-top: 20px; padding: 10px; background: #e8f4f8; border-radius: 5px;">
                        <strong>📈 Resumen del Producto:</strong><br>
                        • Total vendido: <?php echo formato_moneda($total_subtotal); ?><br>
                        • Total unidades vendidas: <?php echo $total_cantidad; ?><br>
                        • Utilidad total: <?php echo formato_moneda($total_utilidad); ?><br>
                        • Número de ventas: <?php echo count($detalles_ventas); ?>
                    </div>
                    
                <?php else: ?>
                    <div class="no-data">
                        <div>📭</div>
                        <h3>No se encontraron ventas para este producto</h3>
                        <p>El producto no tiene ventas registradas en el período seleccionado.</p>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <!-- LISTA DE TODOS LOS PRODUCTOS CON SUS VENTAS -->
                <?php if (count($productos_con_ventas) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Producto</th>
                                <th>Categoría</th>
                                <th>Precio</th>
                                <th>Veces Vendido</th>
                                <th>Cantidad Vendida</th>
                                <th>Total Vendido</th>
                                <th>Utilidad</th>
                                <th class="no-print">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_general_ventas = 0;
                            $total_general_cantidad = 0;
                            $total_general_utilidad = 0;
                            
                            foreach ($productos_con_ventas as $index => $producto): 
                                $total_general_ventas += $producto['total_vendido'];
                                $total_general_cantidad += $producto['cantidad_vendida'];
                                $total_general_utilidad += $producto['utilidad_total'];
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <strong><?php echo $producto['nombre']; ?></strong><br>
                                    <small style="color: #666;"><?php echo $producto['codigo']; ?></small>
                                </td>
                                <td><?php echo $producto['categoria_nombre'] ?: 'Sin categoría'; ?></td>
                                <td><?php echo formato_moneda($producto['precio_venta']); ?></td>
                                <td>
                                    <span class="badge badge-info"><?php echo $producto['veces_vendido']; ?></span>
                                </td>
                                <td><?php echo $producto['cantidad_vendida']; ?></td>
                                <td style="font-weight: bold; color: #27ae60;">
                                    <?php echo formato_moneda($producto['total_vendido']); ?>
                                </td>
                                <td style="color: <?php echo $producto['utilidad_total'] >= 0 ? 'green' : 'red'; ?>;">
                                    <?php echo formato_moneda($producto['utilidad_total']); ?>
                                </td>
                                <td class="no-print acciones">
                                    <a href="?producto_id=<?php echo $producto['id']; ?>&fecha_inicio=<?php echo $fecha_inicio; ?>&fecha_fin=<?php echo $fecha_fin; ?>" 
                                       class="btn btn-small btn-outline">
                                        Ver Ventas
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <!-- TOTALES GENERALES -->
                            <tr style="background: #2c3e50; color: white; font-weight: bold;">
                                <td colspan="4">TOTALES GENERALES</td>
                                <td><?php echo count($productos_con_ventas); ?> productos</td>
                                <td><?php echo $total_general_cantidad; ?></td>
                                <td><?php echo formato_moneda($total_general_ventas); ?></td>
                                <td><?php echo formato_moneda($total_general_utilidad); ?></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                    
                <?php else: ?>
                    <div class="no-data">
                        <div>📭</div>
                        <h3>No se encontraron productos vendidos</h3>
                        <p>No hay ventas registradas en el período seleccionado.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- INFORMACIÓN DEL PERÍODO -->
        <div class="card" style="text-align: center; color: #666; font-size: 14px;">
            <p>Período consultado: <?php echo date('d/m/Y', strtotime($fecha_inicio)); ?> al <?php echo date('d/m/Y', strtotime($fecha_fin)); ?></p>
            <p>Reporte generado: <?php echo date('d/m/Y H:i:s'); ?></p>
        </div>
    </div>
    
    <script>
    // Función para establecer fechas rápidas
    function setFechaRapida(rango) {
        const hoy = new Date();
        const fechaInicio = document.querySelector('input[name="fecha_inicio"]');
        const fechaFin = document.querySelector('input[name="fecha_fin"]');
        
        switch(rango) {
            case 'hoy':
                const hoyStr = hoy.toISOString().split('T')[0];
                fechaInicio.value = fechaFin.value = hoyStr;
                break;
            case 'ayer':
                const ayer = new Date(hoy);
                ayer.setDate(hoy.getDate() - 1);
                const ayerStr = ayer.toISOString().split('T')[0];
                fechaInicio.value = fechaFin.value = ayerStr;
                break;
            case 'mes':
                const inicioMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
                fechaInicio.value = inicioMes.toISOString().split('T')[0];
                fechaFin.value = hoy.toISOString().split('T')[0];
                break;
        }
    }
    
    // Si hay un producto específico seleccionado, mostrar en consola
    <?php if ($producto_id && $info_producto): ?>
    console.log('Producto seleccionado:', {
        id: <?php echo $producto_id; ?>,
        nombre: '<?php echo $info_producto['nombre']; ?>',
        ventas_encontradas: <?php echo count($detalles_ventas); ?>
    });
    <?php endif; ?>
    </script>
</body>
</html>