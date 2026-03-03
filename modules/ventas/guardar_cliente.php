<?php
// modules/ventas/guardar_cliente.php
session_start();

// Configurar header JSON primero
header('Content-Type: application/json');

// Ruta corregida - Usar __DIR__
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

// Verificar permisos (vendedores pueden crear clientes durante la venta)
if (!isset($auth) || (!$auth->hasPermission('clientes', 'crear') && $_SESSION['usuario_rol'] != 'vendedor' && $_SESSION['usuario_rol'] != 'admin')) {
    echo json_encode(['success' => false, 'error' => 'No tienes permisos para crear clientes']);
    exit;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    // Recibir datos del formulario
    $tipo_documento = $_POST['tipo_documento'] ?? 'CEDULA';
    $numero_documento = trim($_POST['numero_documento'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $ruc = '';

    // Validaciones básicas
    if (empty($nombre)) {
        echo json_encode(['success' => false, 'error' => 'El nombre es obligatorio']);
        exit;
    }
    
    if (empty($numero_documento)) {
        echo json_encode(['success' => false, 'error' => 'El número de documento es obligatorio']);
        exit;
    }

    // Verificar si el documento ya existe
    $sql_verificar = "SELECT id FROM clientes WHERE numero_documento = ?";
    $stmt_verificar = $db->prepare($sql_verificar);
    $stmt_verificar->execute([$numero_documento]);
    
    if ($stmt_verificar->fetch()) {
        echo json_encode(['success' => false, 'error' => 'El número de documento ya está registrado']);
        exit;
    }

    // Insertar nuevo cliente - SIN columna 'notas' (adaptado a tu estructura)
    $sql = "INSERT INTO clientes (tipo_documento, numero_documento, ruc, nombre, telefono, email, direccion, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$tipo_documento, $numero_documento, $ruc, $nombre, $telefono, $email, $direccion]);
    
    $cliente_id = $db->lastInsertId();

    echo json_encode([
        'success' => true,
        'cliente_id' => $cliente_id,
        'nombre' => $nombre,
        'tipo_documento' => $tipo_documento,
        'numero_documento' => $numero_documento,
        'mensaje' => 'Cliente creado exitosamente'
    ]);

} catch (PDOException $e) {
    error_log("Error PDO en guardar_cliente: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error en la base de datos: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("Error general en guardar_cliente: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Error al guardar el cliente: ' . $e->getMessage()
    ]);
}
?>