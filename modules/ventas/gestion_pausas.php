<?php
// gestion_pausas.php
session_start();
require_once '../../config/database.php';

// Verificar que el usuario esté autenticado
if (!isset($_SESSION['usuario_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Verificar si la tabla ventas_pausadas existe, si no, crearla
function crearTablaVentasPausadas($db) {
    $query = "CREATE TABLE IF NOT EXISTS ventas_pausadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        datos_venta TEXT NOT NULL,
        fecha_pausa TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_usuario_id (usuario_id),
        INDEX idx_fecha_pausa (fecha_pausa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    try {
        $db->exec($query);
        return true;
    } catch (Exception $e) {
        error_log("Error al crear tabla ventas_pausadas: " . $e->getMessage());
        return false;
    }
}

// Crear tabla si no existe
crearTablaVentasPausadas($db);

// Obtener acción
$accion = $_GET['accion'] ?? $_POST['accion'] ?? '';

$usuario_id = $_SESSION['usuario_id'];

// Inicializar respuesta
header('Content-Type: application/json');

switch ($accion) {
    case 'contar_pausadas':
        try {
            $query = "SELECT COUNT(*) as count FROM ventas_pausadas WHERE usuario_id = :usuario_id";
            $stmt = $db->prepare($query);
            $stmt->execute([':usuario_id' => $usuario_id]);
            $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'count' => $resultado['count'] ?? 0]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error al contar pausas: ' . $e->getMessage()]);
        }
        break;
        
    case 'listar_pausadas':
        try {
            $query = "SELECT * FROM ventas_pausadas 
                     WHERE usuario_id = :usuario_id 
                     ORDER BY fecha_pausa DESC";
            $stmt = $db->prepare($query);
            $stmt->execute([':usuario_id' => $usuario_id]);
            $ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($ventas);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error al listar pausas: ' . $e->getMessage()]);
        }
        break;
        
    case 'pausar_venta':
        try {
            $datos_venta = $_POST['datos_venta'] ?? '';
            
            if (empty($datos_venta)) {
                throw new Exception('No se recibieron datos de venta');
            }
            
            // Validar que el JSON sea válido
            $datos_json = json_decode($datos_venta, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON inválido: ' . json_last_error_msg());
            }
            
            // Insertar venta pausada
            $query = "INSERT INTO ventas_pausadas (usuario_id, datos_venta) 
                     VALUES (:usuario_id, :datos_venta)";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':usuario_id' => $usuario_id,
                ':datos_venta' => $datos_venta
            ]);
            
            $id_pausa = $db->lastInsertId();
            
            echo json_encode([
                'success' => true,
                'pausa_id' => $id_pausa,
                'message' => 'Venta pausada correctamente'
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error al pausar venta: ' . $e->getMessage()]);
        }
        break;
        
    case 'recuperar_pausada':
        try {
            $pausa_id = $_POST['pausa_id'] ?? 0;
            
            if (empty($pausa_id)) {
                throw new Exception('ID de pausa no especificado');
            }
            
            // Obtener la venta pausada
            $query = "SELECT * FROM ventas_pausadas 
                     WHERE id = :id AND usuario_id = :usuario_id";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':id' => $pausa_id,
                ':usuario_id' => $usuario_id
            ]);
            
            $venta_pausada = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$venta_pausada) {
                throw new Exception('Venta pausada no encontrada');
            }
            
            // Decodificar los datos
            $datos_venta = json_decode($venta_pausada['datos_venta'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Error al decodificar datos de venta: ' . json_last_error_msg());
            }
            
            // Eliminar la venta pausada después de recuperarla
            $query_delete = "DELETE FROM ventas_pausadas WHERE id = :id AND usuario_id = :usuario_id";
            $stmt_delete = $db->prepare($query_delete);
            $stmt_delete->execute([
                ':id' => $pausa_id,
                ':usuario_id' => $usuario_id
            ]);
            
            echo json_encode([
                'success' => true,
                'datos' => $datos_venta,
                'message' => 'Datos de venta recuperados'
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error al recuperar venta: ' . $e->getMessage()]);
        }
        break;
        
    case 'eliminar_pausada':
        try {
            $pausa_id = $_POST['pausa_id'] ?? 0;
            
            if (empty($pausa_id)) {
                throw new Exception('ID de pausa no especificado');
            }
            
            // Verificar que la venta pertenezca al usuario
            $query_verificar = "SELECT id FROM ventas_pausadas 
                               WHERE id = :id AND usuario_id = :usuario_id";
            $stmt_verificar = $db->prepare($query_verificar);
            $stmt_verificar->execute([
                ':id' => $pausa_id,
                ':usuario_id' => $usuario_id
            ]);
            
            if (!$stmt_verificar->fetch()) {
                throw new Exception('Venta pausada no encontrada o no autorizada');
            }
            
            // Eliminar la venta pausada
            $query = "DELETE FROM ventas_pausadas 
                     WHERE id = :id AND usuario_id = :usuario_id";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':id' => $pausa_id,
                ':usuario_id' => $usuario_id
            ]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Venta pausada eliminada correctamente'
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Error al eliminar venta: ' . $e->getMessage()]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Acción no válida']);
        break;
}
?>