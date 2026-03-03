<?php
/**
 * ============================================
 * ARCHIVO: procesar_completar.php
 * UBICACIÓN: /modules/reservas/procesar_completar.php
 * PROPÓSITO: Procesar el completado de reserva y facturación
 * ============================================
 */

session_start();

require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permiso
if (!$auth->hasPermission('reservas', 'completar')) {
    $_SESSION['error'] = "No tienes permisos para completar reservas";
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$database = Database::getInstance();
$db = $database->getConnection();

$reserva_id = intval($_POST['reserva_id'] ?? 0);
$metodo_pago = $_POST['metodo_pago'] ?? 'efectivo';
$monto_recibido = floatval($_POST['monto_recibido'] ?? 0);
$observaciones = trim($_POST['observaciones_venta'] ?? '');
$servicios_json = $_POST['servicios_json'] ?? '[]';
$productos_json = $_POST['productos_json'] ?? '[]';

if ($reserva_id <= 0) {
    $_SESSION['error'] = "ID de reserva no válido";
    header("Location: index.php");
    exit();
}

try {
    $db->beginTransaction();
    
    // Verificar que la reserva existe y está confirmada
    $query_check = "SELECT * FROM reservas WHERE id = ? AND estado = 'confirmada'";
    $stmt_check = $db->prepare($query_check);
    $stmt_check->execute([$reserva_id]);
    $reserva = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if (!$reserva) {
        throw new Exception("Reserva no encontrada o no está confirmada");
    }
    
    // Procesar servicios con precios actualizados
    $servicios = json_decode($servicios_json, true);
    $total_servicios = 0;
    
    if (!empty($servicios) && is_array($servicios)) {
        foreach ($servicios as $s) {
            $servicio_id = intval($s['id'] ?? 0);
            $precio_final = floatval($s['precio'] ?? 0);
            
            if ($servicio_id <= 0) continue;
            
            // Actualizar precio final en detalles
            $query_update = "UPDATE reserva_detalles_servicios 
                            SET precio_final = ?, subtotal = ?
                            WHERE reserva_id = ? AND servicio_id = ?";
            $stmt_update = $db->prepare($query_update);
            $stmt_update->execute([$precio_final, $precio_final, $reserva_id, $servicio_id]);
            
            $total_servicios += $precio_final;
            
            // Registrar ingreso por servicio en ingresos_reservas
            $query_ingreso = "INSERT INTO ingresos_reservas 
                             (reserva_id, fecha, concepto, tipo, monto, usuario_id) 
                             VALUES (?, NOW(), ?, 'servicio', ?, ?)";
            $stmt_ingreso = $db->prepare($query_ingreso);
            $concepto = "Servicio: " . ($s['nombre'] ?? 'Servicio #' . $servicio_id);
            $stmt_ingreso->execute([$reserva_id, $concepto, $precio_final, $_SESSION['usuario_id']]);
        }
    }
    
    // Procesar productos adicionales
    $productos = json_decode($productos_json, true);
    $total_productos = 0;
    
    if (!empty($productos) && is_array($productos)) {
        foreach ($productos as $p) {
            $producto_id = intval($p['id'] ?? 0);
            $cantidad = intval($p['cantidad'] ?? 1);
            $precio_unitario = floatval($p['precio'] ?? 0);
            $subtotal = floatval($p['subtotal'] ?? ($precio_unitario * $cantidad));
            
            if ($producto_id <= 0) continue;
            
            // Verificar stock
            $query_stock = "SELECT stock FROM productos WHERE id = ?";
            $stmt_stock = $db->prepare($query_stock);
            $stmt_stock->execute([$producto_id]);
            $producto = $stmt_stock->fetch(PDO::FETCH_ASSOC);
            
            if (!$producto) {
                throw new Exception("Producto no encontrado ID: " . $producto_id);
            }
            
            if ($producto['stock'] < $cantidad) {
                throw new Exception("Stock insuficiente para el producto " . ($p['nombre'] ?? ''));
            }
            
            // Insertar detalle de producto
            $query_detalle = "INSERT INTO reserva_detalles_productos 
                            (reserva_id, producto_id, nombre_producto, cantidad, precio_unitario, subtotal) 
                            VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_detalle = $db->prepare($query_detalle);
            $stmt_detalle->execute([
                $reserva_id,
                $producto_id,
                $p['nombre'] ?? 'Producto',
                $cantidad,
                $precio_unitario,
                $subtotal
            ]);
            
            // Actualizar stock
            $nuevo_stock = $producto['stock'] - $cantidad;
            $query_update_stock = "UPDATE productos SET stock = ? WHERE id = ?";
            $stmt_update_stock = $db->prepare($query_update_stock);
            $stmt_update_stock->execute([$nuevo_stock, $producto_id]);
            
            // Registrar auditoría de stock (si existe la tabla)
            try {
                $query_audit = "INSERT INTO auditoria_stock 
                              (producto_id, tipo_movimiento, cantidad, stock_anterior, stock_nuevo, usuario_id, referencia, motivo) 
                              VALUES (?, 'venta', ?, ?, ?, ?, ?, ?)";
                $stmt_audit = $db->prepare($query_audit);
                $referencia = "Reserva #" . $reserva_id;
                $motivo = "Venta en reserva";
                $stmt_audit->execute([
                    $producto_id,
                    $cantidad,
                    $producto['stock'],
                    $nuevo_stock,
                    $_SESSION['usuario_id'],
                    $referencia,
                    $motivo
                ]);
            } catch (Exception $e) {
                // Si la tabla no existe, continuamos
                error_log("Auditoría de stock no disponible: " . $e->getMessage());
            }
            
            // Registrar ingreso por producto en ingresos_reservas
            $query_ingreso = "INSERT INTO ingresos_reservas 
                             (reserva_id, fecha, concepto, tipo, monto, usuario_id) 
                             VALUES (?, NOW(), ?, 'producto', ?, ?)";
            $stmt_ingreso = $db->prepare($query_ingreso);
            $concepto = "Producto: " . ($p['nombre'] ?? 'Producto #' . $producto_id);
            $stmt_ingreso->execute([$reserva_id, $concepto, $subtotal, $_SESSION['usuario_id']]);
            
            $total_productos += $subtotal;
        }
    }
    
    // Calcular total general
    $total_general = $total_servicios + $total_productos;
    $cambio = max(0, $monto_recibido - $total_general);
    
    // Generar número de factura (puedes personalizar el formato)
    $numero_factura = 'FAC-' . date('Ymd') . '-' . str_pad($reserva_id, 5, '0', STR_PAD_LEFT);
    
    // Actualizar reserva
    $query_update = "UPDATE reservas SET 
                    estado = 'completada',
                    total_servicios = ?,
                    total_productos = ?,
                    total_general = ?,
                    updated_at = NOW()
                    WHERE id = ?";
    $stmt_update = $db->prepare($query_update);
    $stmt_update->execute([$total_servicios, $total_productos, $total_general, $reserva_id]);
    
    // Intentar insertar en tabla ventas si existe
    try {
        // Verificar si la tabla ventas existe
        $check_ventas = $db->query("SHOW TABLES LIKE 'ventas'");
        if ($check_ventas->rowCount() > 0) {
            $query_venta = "INSERT INTO ventas 
                           (numero_factura, cliente_nombre, cliente_telefono, usuario_id, fecha, subtotal, total, estado, tipo_venta, metodo_pago, monto_recibido, cambio, observaciones) 
                           VALUES (?, ?, ?, ?, NOW(), ?, ?, 'completada', 'contado', ?, ?, ?, ?)";
            $stmt_venta = $db->prepare($query_venta);
            
            $stmt_venta->execute([
                $numero_factura,
                $reserva['nombre_cliente'],
                $reserva['telefono_cliente'] ?? null,
                $_SESSION['usuario_id'],
                $total_general,
                $total_general,
                $metodo_pago,
                $monto_recibido,
                $cambio,
                $observaciones
            ]);
            
            $venta_id = $db->lastInsertId();
        }
    } catch (Exception $e) {
        // Si la tabla no existe, continuamos
        error_log("Tabla ventas no disponible: " . $e->getMessage());
    }
    
    $db->commit();
    
    $_SESSION['success'] = "Reserva completada y facturada correctamente. Factura #$numero_factura";
    header("Location: ver.php?id=" . $reserva_id);
    exit();
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Error al completar reserva: " . $e->getMessage());
    $_SESSION['error'] = "Error al completar la reserva: " . $e->getMessage();
    header("Location: completar.php?id=" . $reserva_id);
    exit();
}
?>