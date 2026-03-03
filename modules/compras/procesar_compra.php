<?php
/**
 * ============================================
 * ARCHIVO: procesar_compra.php
 * UBICACIÓN: /modules/compras/procesar_compra.php
 * PROPÓSITO: Procesar el formulario de nueva compra y actualizar inventario automáticamente
 * ============================================
 */

session_start();
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    $db->beginTransaction();
    
    // Recibir datos del formulario
    $numero_factura = trim($_POST['numero_factura']);
    $proveedor_id = intval($_POST['proveedor_id']);
    $fecha = $_POST['fecha'] . ' ' . ($_POST['hora'] ?? date('H:i:s'));
    $usuario_id = $_SESSION['usuario_id'];
    
    // Validaciones básicas
    if (empty($numero_factura) || $proveedor_id <= 0) {
        throw new Exception("Complete todos los campos requeridos");
    }
    
    // Verificar que no exista el número de factura
    $stmt = $db->prepare("SELECT id FROM compras WHERE numero_factura = ?");
    $stmt->execute([$numero_factura]);
    if ($stmt->fetch()) {
        throw new Exception("El número de factura ya existe");
    }
    
    // Obtener productos del POST
    $productos_ids = $_POST['producto_id'] ?? [];
    $cantidades = $_POST['cantidad'] ?? [];
    $precios = $_POST['precio'] ?? [];
    
    if (empty($productos_ids)) {
        throw new Exception("Debe agregar al menos un producto");
    }
    
    // Calcular totales
    $subtotal = 0;
    $detalles = [];
    
    for ($i = 0; $i < count($productos_ids); $i++) {
        if (empty($productos_ids[$i])) continue;
        
        $cantidad = floatval($cantidades[$i]);
        $precio = floatval($precios[$i]);
        
        if ($cantidad <= 0 || $precio <= 0) {
            throw new Exception("Cantidad y precio deben ser mayores a cero");
        }
        
        $subtotal_producto = $cantidad * $precio;
        $subtotal += $subtotal_producto;
        
        $detalles[] = [
            'producto_id' => intval($productos_ids[$i]),
            'cantidad' => $cantidad,
            'precio' => $precio,
            'subtotal' => $subtotal_producto
        ];
    }
    
    // Calcular impuesto (configurable)
    $impuesto_porcentaje = 0; // Cambiar según configuración
    $impuesto = $subtotal * ($impuesto_porcentaje / 100);
    $total = $subtotal + $impuesto;
    
    // Insertar compra - CON estado 'recibida' para actualizar inventario automáticamente
    $stmt = $db->prepare("INSERT INTO compras (numero_factura, proveedor_id, fecha, subtotal, impuesto, total, usuario_id, estado, created_at) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'recibida', NOW())");
    
    $stmt->execute([
        $numero_factura,
        $proveedor_id,
        $fecha,
        $subtotal,
        $impuesto,
        $total,
        $usuario_id
    ]);
    
    $compra_id = $db->lastInsertId();
    
    // Insertar detalles
    $stmt_detalle = $db->prepare("INSERT INTO compra_detalles (compra_id, producto_id, cantidad, precio, subtotal) 
                                   VALUES (?, ?, ?, ?, ?)");
    
    foreach ($detalles as $d) {
        $stmt_detalle->execute([
            $compra_id,
            $d['producto_id'],
            $d['cantidad'],
            $d['precio'],
            $d['subtotal']
        ]);
    }
    
    // ACTUALIZAR INVENTARIO AUTOMÁTICAMENTE
    $update_producto = $db->prepare("UPDATE productos SET stock = stock + ?, updated_at = NOW() WHERE id = ?");
    
    foreach ($detalles as $d) {
        $result = $update_producto->execute([$d['cantidad'], $d['producto_id']]);
        
        if (!$result) {
            $errorInfo = $update_producto->errorInfo();
            throw new Exception("Error al actualizar stock del producto ID: " . $d['producto_id'] . " - " . $errorInfo[2]);
        }
        
        // Log para depuración
        error_log("Stock actualizado - Producto ID: " . $d['producto_id'] . " +" . $d['cantidad']);
    }
    
    $db->commit();
    
    $_SESSION['success'] = "✅ Compra registrada exitosamente. Inventario actualizado.";
    header("Location: ver.php?id=" . $compra_id);
    exit();
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error en procesar_compra: " . $e->getMessage());
    $_SESSION['error'] = "❌ Error: " . $e->getMessage();
    header("Location: crear.php");
    exit();
}
?>