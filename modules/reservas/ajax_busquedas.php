<?php
// modules/reservas/ajax_busquedas.php
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autenticado']);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'buscar_servicios':
        buscarServicios();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
}

function buscarServicios() {
    global $db;
    
    $termino = trim($_POST['termino'] ?? '');
    $excluir_ids = json_decode($_POST['excluir_ids'] ?? '[]', true);
    
    if (strlen($termino) < 2) {
        echo json_encode(['success' => false, 'message' => 'Escribe al menos 2 caracteres', 'data' => []]);
        return;
    }
    
    try {
        $query = "SELECT id, nombre, precio, precio_variable 
                  FROM servicios 
                  WHERE activo = 1 
                  AND nombre LIKE :termino";
        
        // Agregar exclusión de IDs si hay
        if (!empty($excluir_ids)) {
            $placeholders = implode(',', array_fill(0, count($excluir_ids), '?'));
            $query .= " AND id NOT IN ($placeholders)";
        }
        
        $query .= " ORDER BY nombre LIMIT 10";
        
        $termino_busqueda = "%$termino%";
        $stmt = $db->prepare($query);
        
        // Bind parameters
        $stmt->bindParam(':termino', $termino_busqueda);
        
        if (!empty($excluir_ids)) {
            foreach ($excluir_ids as $index => $id) {
                $stmt->bindValue($index + 2, $id, PDO::PARAM_INT);
            }
        }
        
        $stmt->execute();
        $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $servicios]);
        
    } catch (Exception $e) {
        error_log("Error en buscarServicios: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al buscar servicios', 'data' => []]);
    }
}
?>