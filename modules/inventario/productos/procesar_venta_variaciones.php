<?php
session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

try {
    $db->beginTransaction();
    
    // Obtener datos de la venta
    $numero_factura = $_POST['numero_factura'];
    $cliente_id = $_POST['cliente_id'];
    $metodo_pago = $_POST['metodo_pago'];
    $descuento = floatval($_POST['descuento']);
    $tipo_descuento = $_POST['tipo_descuento'];
    $observaciones = $_POST['observaciones'] ?? '';
    $monto_recibido = floatval($_POST['monto_recibido']);
    $usuario_id = $_SESSION['usuario_id'];
    
    // Obtener productos
    $productos = [];
    foreach ($_POST['productos'] as $index => $productoData) {
        $productos[] = [
            'id' => intval($productoData['id']),
            'variacion_id' => isset($productoData['variacion_id']) ? intval($productoData['variacion_id']) : null,
            'cantidad' => intval($productoData['cantidad']),
            'precio' => floatval($productoData['precio']),
            'sku' => $productoData['sku'] ?? '',
            'atributo_valor' => $productoData['atributo_valor'] ?? ''
        ];
    }
    
    // Calcular totales
    $subtotal = 0;
    foreach ($productos as $producto) {
        $subtotal += $producto['precio'] * $producto['cantidad'];
    }
    
    // Aplicar descuento
    if ($tipo_descuento === 'porcentaje') {
        $descuento_monto = $subtotal * ($descuento / 100);
    } else {
        $descuento_monto = $descuento;
    }
    
    $subtotal_con_descuento = $subtotal - $descuento_monto;
    
    // Obtener impuesto
    $query_config = "SELECT impuesto FROM configuracion_negocio LIMIT 1";
    $stmt_config = $db->prepare($query_config);
    $stmt_config->execute();
    $configuracion = $stmt_config->fetch(PDO::FETCH_ASSOC);
    
    $impuesto_porcentaje = $configuracion['impuesto'] ?? 19.00;
    $impuesto_decimal = $impuesto_porcentaje / 100;
    $impuesto_monto = $subtotal_con_descuento * $impuesto_decimal;
    
    $total = $subtotal_con_descuento + $impuesto_monto;
    $cambio = $monto_recibido - $total;
    
    // Insertar venta
    $query_venta = "INSERT INTO ventas 
                    (numero_factura, cliente_id, usuario_id, subtotal, descuento, impuesto, 
                     total, monto_recibido, cambio, metodo_pago, observaciones, estado, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completada', NOW())";
    
    $stmt_venta = $db->prepare($query_venta);
    $stmt_venta->execute([
        $numero_factura,
        $cliente_id,
        $usuario_id,
        $subtotal,
        $descuento_monto,
        $impuesto_monto,
        $total,
        $monto_recibido,
        max(0, $cambio),
        $metodo_pago,
        $observaciones
    ]);
    
    $venta_id = $db->lastInsertId();
    
    // Insertar detalles de venta (con variaciones)
    foreach ($productos as $producto) {
        // Determinar si es variación o producto simple
        if ($producto['variacion_id']) {
            // Venta de variación
            $query_detalle = "INSERT INTO venta_detalles 
                              (venta_id, producto_id, variacion_id, cantidad, precio, created_at) 
                              VALUES (?, ?, ?, ?, ?, NOW())";
            
            $stmt_detalle = $db->prepare($query_detalle);
            $stmt_detalle->execute([
                $venta_id,
                $producto['id'],
                $producto['variacion_id'],
                $producto['cantidad'],
                $producto['precio']
            ]);
            
            // Actualizar stock de la variación
            $query_update_stock = "UPDATE producto_variaciones 
                                   SET stock = stock - ? 
                                   WHERE id = ? AND producto_id = ?";
            
            $stmt_update = $db->prepare($query_update_stock);
            $stmt_update->execute([
                $producto['cantidad'],
                $producto['variacion_id'],
                $producto['id']
            ]);
            
        } else {
            // Venta de producto simple
            $query_detalle = "INSERT INTO venta_detalles 
                              (venta_id, producto_id, cantidad, precio, created_at) 
                              VALUES (?, ?, ?, ?, NOW())";
            
            $stmt_detalle = $db->prepare($query_detalle);
            $stmt_detalle->execute([
                $venta_id,
                $producto['id'],
                $producto['cantidad'],
                $producto['precio']
            ]);
            
            // Actualizar stock del producto simple
            $query_update_stock = "UPDATE producto_variaciones 
                                   SET stock = stock - ? 
                                   WHERE producto_id = ? 
                                   AND atributo_nombre = 'Variante' 
                                   AND atributo_valor = 'Única'";
            
            $stmt_update = $db->prepare($query_update_stock);
            $stmt_update->execute([
                $producto['cantidad'],
                $producto['id']
            ]);
        }
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'venta_id' => $venta_id,
        'numero_factura' => $numero_factura,
        'total' => $total
    ]);
    
} catch (Exception $e) {
    $db->rollBack();
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}