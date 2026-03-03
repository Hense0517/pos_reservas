<?php
// modules/reservas/ajax_get_empleados.php
require_once __DIR__ . '/../../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autenticado']);
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'get_empleados_por_servicio':
        getEmpleadosPorServicio();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
}

function getEmpleadosPorServicio() {
    global $db;
    
    $servicio_id = intval($_POST['servicio_id'] ?? 0);
    
    if ($servicio_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Servicio no válido']);
        return;
    }
    
    try {
        // Primero, verificar si hay usuarios con este servicio asignado
        $query_check = "SELECT COUNT(*) as total FROM usuarios_servicios WHERE servicio_id = ?";
        $stmt_check = $db->prepare($query_check);
        $stmt_check->execute([$servicio_id]);
        $total_asignados = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Construir la consulta según si hay asignados o no
        if ($total_asignados > 0) {
            // Hay usuarios asignados específicamente a este servicio
            $query = "SELECT DISTINCT u.id, u.nombre, u.username,
                             us.nivel_experiencia,
                             0 as es_admin
                      FROM usuarios u
                      INNER JOIN usuarios_servicios us ON u.id = us.usuario_id
                      WHERE u.activo = 1 
                        AND us.servicio_id = ?
                      ORDER BY 
                        CASE us.nivel_experiencia
                            WHEN 'experto' THEN 1
                            WHEN 'avanzado' THEN 2
                            WHEN 'intermedio' THEN 3
                            WHEN 'principiante' THEN 4
                            ELSE 5
                        END,
                        u.nombre";
            
            $stmt = $db->prepare($query);
            $stmt->execute([$servicio_id]);
            
        } else {
            // No hay usuarios asignados, devolver array vacío
            echo json_encode([
                'success' => true,
                'empleados' => [],
                'message' => 'No hay empleados disponibles para este servicio'
            ]);
            return;
        }
        
        $empleados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Capitalizar nivel de experiencia
        foreach ($empleados as &$emp) {
            $emp['nivel_experiencia'] = ucfirst($emp['nivel_experiencia']);
        }
        
        echo json_encode([
            'success' => true,
            'empleados' => $empleados,
            'total' => count($empleados)
        ]);
        
    } catch (Exception $e) {
        error_log("Error en getEmpleadosPorServicio: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al cargar empleados: ' . $e->getMessage()]);
    }
}
?>