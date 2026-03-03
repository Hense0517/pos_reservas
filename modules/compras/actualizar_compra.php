<?php
/**
 * ============================================
 * ARCHIVO: actualizar_compra.php
 * UBICACIÓN: /modules/compras/actualizar_compra.php
 * PROPÓSITO: Actualizar una compra existente
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
    $compra_id = intval($_POST['compra_id']);
    $proveedor_id = intval($_POST['proveedor_id']);
    $fecha = $_POST['fecha'] . ' ' . ($_POST['hora'] ?? date('H:i:s'));
    
    if ($compra_id <= 0 || $proveedor_id <= 0) {
        throw new Exception("Datos inválidos");
    }
    
    // Verificar que la compra existe y está pendiente
    $stmt = $db->prepare("SELECT estado FROM compras WHERE id = ?");
    $stmt->execute([$compra_id]);
    $compra = $stmt->fetch();
    
    if (!$compra) {
        throw new Exception("Compra no encontrada");
    }
    
    if ($compra['estado'] != 'pendiente') {
        throw new Exception("Solo se pueden editar compras pendientes");
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
    
    // Actualizar compra - SIN CAMPO notas
    $stmt = $db->prepare("UPDATE compras SET proveedor_id = ?, fecha = ?, subtotal = ?, impuesto = ?, total = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$proveedor_id, $fecha, $subtotal, $impuesto, $total, $compra_id]);
    
    // Eliminar detalles antiguos
    $stmt = $db->prepare("DELETE FROM compra_detalles WHERE compra_id = ?");
    $stmt->execute([$compra_id]);
    
    // Insertar nuevos detalles
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
    
    $db->commit();
    
    $_SESSION['success'] = "✅ Compra actualizada exitosamente";
    header("Location: ver.php?id=" . $compra_id);
    exit();
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Error en actualizar_compra: " . $e->getMessage());
    $_SESSION['error'] = "❌ Error: " . $e->getMessage();
    header("Location: editar.php?id=" . ($compra_id ?? 0));
    exit();
}
?>