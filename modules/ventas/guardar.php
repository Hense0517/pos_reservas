<?php
session_start();
require_once '../../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    // Recoger y validar datos
    $nombre = trim($_POST['nombre']);
    $tipo_documento = $_POST['tipo_documento'];
    $numero_documento = trim($_POST['numero_documento']);
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');

    // Validaciones
    $errores = [];

    if (empty($nombre)) {
        $errores[] = "El nombre del cliente es obligatorio";
    }

    if (empty($numero_documento)) {
        $errores[] = "El número de documento es obligatorio";
    }

    // Verificar si el número de documento ya existe
    $query_documento = "SELECT id FROM clientes WHERE numero_documento = ?";
    $stmt_documento = $db->prepare($query_documento);
    $stmt_documento->execute([$numero_documento]);
    if ($stmt_documento->fetch()) {
        $errores[] = "El número de documento ya está registrado";
    }

    // Verificar si el email ya existe
    if (!empty($email)) {
        $query_email = "SELECT id FROM clientes WHERE email = ?";
        $stmt_email = $db->prepare($query_email);
        $stmt_email->execute([$email]);
        if ($stmt_email->fetch()) {
            $errores[] = "El email ya está registrado";
        }
    }

    if (!empty($errores)) {
        echo json_encode(['success' => false, 'error' => implode(', ', $errores)]);
        exit;
    }

    // Insertar cliente
    $query = "INSERT INTO clientes (nombre, tipo_documento, numero_documento, telefono, email, direccion) 
              VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($query);
    $stmt->execute([$nombre, $tipo_documento, $numero_documento, $telefono, $email, $direccion]);

    $cliente_id = $db->lastInsertId();

    // Obtener datos del cliente creado
    $query_cliente = "SELECT * FROM clientes WHERE id = ?";
    $stmt_cliente = $db->prepare($query_cliente);
    $stmt_cliente->execute([$cliente_id]);
    $cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'cliente' => $cliente
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => "Error al crear el cliente: " . $e->getMessage()]);
}
exit;