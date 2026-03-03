<?php
// modules/reservas/ajax_busquedas_simple.php
require_once __DIR__ . '/../../includes/config.php';

// Desactivar errores que puedan generar HTML
ini_set('display_errors', 0);
error_reporting(0);

// Limpiar cualquier output buffer
while (ob_get_level()) ob_end_clean();

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$action = $_POST['action'] ?? '';

// Si es guardar cliente rápido
if ($action === 'guardar_cliente_rapido') {
    guardarClienteRapido();
    exit;
}

// Si es búsqueda de clientes
$termino = $_POST['termino'] ?? '';

if (strlen($termino) < 2) {
    echo json_encode([]);
    exit;
}

try {
    $termino_busqueda = "%$termino%";
    
    $query = "SELECT id, nombre, numero_documento, telefono, email 
              FROM clientes 
              WHERE nombre LIKE ? OR numero_documento LIKE ? OR telefono LIKE ?
              ORDER BY 
                 CASE 
                    WHEN numero_documento = ? THEN 1
                    WHEN nombre LIKE ? THEN 2
                    ELSE 3
                 END,
                 nombre 
              LIMIT 15";
    
    $stmt = $db->prepare($query);
    $termino_inicio = $termino . '%';
    $stmt->execute([$termino_busqueda, $termino_busqueda, $termino_busqueda, $termino, $termino_inicio]);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($clientes);
    
} catch (Exception $e) {
    echo json_encode([]);
}

function guardarClienteRapido() {
    global $db;
    
    $nombre = trim($_POST['nombre'] ?? '');
    $documento = trim($_POST['documento'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    if (empty($nombre)) {
        echo json_encode(['success' => false, 'message' => 'El nombre es requerido']);
        return;
    }
    
    try {
        // Verificar si ya existe por documento
        if (!empty($documento)) {
            $query = "SELECT id, nombre, numero_documento FROM clientes WHERE numero_documento = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$documento]);
            $existente = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existente) {
                echo json_encode([
                    'success' => true, 
                    'message' => 'Cliente ya existe',
                    'cliente' => [
                        'id' => $existente['id'],
                        'nombre' => $existente['nombre'],
                        'numero_documento' => $existente['numero_documento']
                    ]
                ]);
                return;
            }
        }
        
        // Insertar nuevo cliente
        $query = "INSERT INTO clientes (nombre, numero_documento, telefono, email, created_at, updated_at) 
                  VALUES (?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$nombre, $documento, $telefono, $email]);
        
        $id = $db->lastInsertId();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Cliente creado correctamente',
            'cliente' => [
                'id' => $id,
                'nombre' => $nombre,
                'numero_documento' => $documento,
                'telefono' => $telefono,
                'email' => $email
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error al guardar el cliente: ' . $e->getMessage()]);
    }
}
?>