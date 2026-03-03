<?php
// modules/reservas/ajax.php
require_once __DIR__ . '/../../includes/config.php';

// Desactivar errores que puedan generar HTML
ini_set('display_errors', 0);
error_reporting(0);

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'message' => 'No autenticado']);
    exit;
}

$action = $_POST['action'] ?? '';

// Función para limpiar formato de fecha de FullCalendar
function limpiarFechaFullCalendar($fecha_hora) {
    if (empty($fecha_hora)) return '';
    
    // Eliminar la 'T' y la zona horaria
    $fecha_hora_limpia = preg_replace('/T/', ' ', $fecha_hora);
    $fecha_hora_limpia = preg_replace('/[+-]\d{2}:\d{2}$/', '', $fecha_hora_limpia);
    
    // Si aún tiene formato ISO, extraer solo fecha y hora
    if (strpos($fecha_hora_limpia, 'T') !== false) {
        $partes = explode('T', $fecha_hora_limpia);
        $fecha_hora_limpia = $partes[0] . ' ' . substr($partes[1], 0, 8);
    }
    
    // Validar que la fecha tenga el formato correcto
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fecha_hora_limpia)) {
        // Intentar con otro formato
        $timestamp = strtotime($fecha_hora);
        if ($timestamp) {
            $fecha_hora_limpia = date('Y-m-d H:i:s', $timestamp);
        }
    }
    
    return $fecha_hora_limpia;
}

switch ($action) {
    case 'obtener_eventos':
        obtenerEventos();
        break;
    case 'obtener_detalle':
        obtenerDetalleReserva();
        break;
    case 'obtener_servicios':
        obtenerServicios();
        break;
    case 'obtener_usuarios_servicio':
        obtenerUsuariosServicio();
        break;
    case 'guardar_reserva':
        guardarReserva();
        break;
    case 'mover_reserva':
        moverReserva();
        break;
    case 'cambiar_estado':
        cambiarEstado();
        break;
    case 'eliminar_reserva':
        eliminarReserva();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
}

function obtenerEventos() {
    global $db;
    
    $start = $_POST['start'] ?? date('Y-m-d');
    $end = $_POST['end'] ?? date('Y-m-d', strtotime('+1 month'));
    
    try {
        $query = "SELECT r.id, r.nombre_cliente, r.fecha_hora_reserva as start, 
                         r.estado, u.nombre as usuario_nombre,
                         CASE 
                            WHEN r.estado = 'completada' THEN CONCAT('✅ ', r.nombre_cliente)
                            WHEN r.estado = 'cancelada' THEN CONCAT('❌ ', r.nombre_cliente)
                            WHEN r.estado = 'confirmada' THEN CONCAT('✓ ', r.nombre_cliente)
                            ELSE CONCAT('⏳ ', r.nombre_cliente)
                         END as title
                  FROM reservas r
                  LEFT JOIN usuarios u ON r.usuario_id = u.id
                  WHERE DATE(r.fecha_hora_reserva) BETWEEN :start AND :end
                  ORDER BY r.fecha_hora_reserva ASC";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':start', $start);
        $stmt->bindParam(':end', $end);
        $stmt->execute();
        
        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formatear para FullCalendar
        foreach ($eventos as &$evento) {
            $evento['id'] = (string)$evento['id'];
            $evento['extendedProps'] = [
                'estado' => $evento['estado'],
                'usuario' => $evento['usuario_nombre'] ?? 'No asignado'
            ];
            
            // Asignar color según estado
            switch($evento['estado']) {
                case 'pendiente':
                    $evento['backgroundColor'] = '#fbbf24';
                    $evento['borderColor'] = '#d97706';
                    break;
                case 'confirmada':
                    $evento['backgroundColor'] = '#60a5fa';
                    $evento['borderColor'] = '#2563eb';
                    break;
                case 'completada':
                    $evento['backgroundColor'] = '#34d399';
                    $evento['borderColor'] = '#059669';
                    break;
                case 'cancelada':
                    $evento['backgroundColor'] = '#f87171';
                    $evento['borderColor'] = '#dc2626';
                    break;
            }
            
            unset($evento['estado']);
            unset($evento['usuario_nombre']);
        }
        
        echo json_encode($eventos);
        
    } catch (Exception $e) {
        error_log("Error en obtenerEventos: " . $e->getMessage());
        echo json_encode([]);
    }
}

function obtenerDetalleReserva() {
    global $db;
    
    $id = $_POST['id'] ?? 0;
    
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID no proporcionado']);
        return;
    }
    
    try {
        // Obtener datos de la reserva
        $query = "SELECT r.*, u.nombre as usuario_nombre 
                  FROM reservas r
                  LEFT JOIN usuarios u ON r.usuario_id = u.id
                  WHERE r.id = :id";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $reserva = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reserva) {
            echo json_encode(['success' => false, 'message' => 'Reserva no encontrada']);
            return;
        }
        
        // Obtener servicios de la reserva
        $queryServicios = "SELECT * FROM reserva_detalles_servicios WHERE reserva_id = :reserva_id";
        $stmtServicios = $db->prepare($queryServicios);
        $stmtServicios->bindParam(':reserva_id', $id);
        $stmtServicios->execute();
        $servicios = $stmtServicios->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener productos de la reserva
        $queryProductos = "SELECT * FROM reserva_detalles_productos WHERE reserva_id = :reserva_id";
        $stmtProductos = $db->prepare($queryProductos);
        $stmtProductos->bindParam(':reserva_id', $id);
        $stmtProductos->execute();
        $productos = $stmtProductos->fetchAll(PDO::FETCH_ASSOC);
        
        $reserva['servicios'] = $servicios;
        $reserva['productos'] = $productos;
        
        echo json_encode(['success' => true, 'data' => $reserva]);
        
    } catch (Exception $e) {
        error_log("Error en obtenerDetalleReserva: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al cargar detalles: ' . $e->getMessage()]);
    }
}

function obtenerServicios() {
    global $db;
    
    try {
        $query = "SELECT id, nombre, precio, precio_variable 
                  FROM servicios WHERE activo = 1 ORDER BY nombre";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $servicios]);
        
    } catch (Exception $e) {
        error_log("Error en obtenerServicios: " . $e->getMessage());
        echo json_encode(['success' => false, 'data' => []]);
    }
}

function obtenerUsuariosServicio() {
    global $db;
    
    try {
        // Obtener usuarios que pueden atender servicios
        $query = "SELECT DISTINCT u.id, u.nombre, u.rol 
                  FROM usuarios u
                  LEFT JOIN usuarios_servicios us ON u.id = us.usuario_id
                  WHERE u.activo = 1 
                    AND (u.rol = 'admin' OR u.rol = 'vendedor' OR us.usuario_id IS NOT NULL)
                  ORDER BY u.nombre";
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $usuarios]);
        
    } catch (Exception $e) {
        error_log("Error en obtenerUsuariosServicio: " . $e->getMessage());
        echo json_encode(['success' => false, 'data' => []]);
    }
}

function guardarReserva() {
    global $db;
    
    $id = $_POST['id'] ?? null;
    $nombre_cliente = trim($_POST['nombre_cliente'] ?? '');
    $telefono_cliente = trim($_POST['telefono_cliente'] ?? '');
    $email_cliente = trim($_POST['email_cliente'] ?? '');
    $fecha_hora_reserva = $_POST['fecha_hora_reserva'] ?? '';
    $usuario_id = !empty($_POST['usuario_id']) ? $_POST['usuario_id'] : null;
    $servicio_id = $_POST['servicio_id'] ?? null;
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    if (empty($nombre_cliente) || empty($fecha_hora_reserva) || empty($servicio_id)) {
        echo json_encode(['success' => false, 'message' => 'Campos requeridos incompletos']);
        return;
    }
    
    try {
        $db->beginTransaction();
        
        // Limpiar formato de fecha
        $fecha_hora_limpia = limpiarFechaFullCalendar($fecha_hora_reserva);
        
        $fecha_reserva = date('Y-m-d', strtotime($fecha_hora_limpia));
        $hora_reserva = date('H:i:s', strtotime($fecha_hora_limpia));
        
        if ($id) {
            // Actualizar reserva existente
            $query = "UPDATE reservas SET 
                      nombre_cliente = :nombre_cliente,
                      telefono_cliente = :telefono_cliente,
                      email_cliente = :email_cliente,
                      fecha_reserva = :fecha_reserva,
                      hora_reserva = :hora_reserva,
                      fecha_hora_reserva = :fecha_hora_reserva,
                      usuario_id = :usuario_id,
                      observaciones = :observaciones
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $id);
            
            // Eliminar detalles de servicios anteriores
            $deleteServicios = $db->prepare("DELETE FROM reserva_detalles_servicios WHERE reserva_id = ?");
            $deleteServicios->execute([$id]);
            
        } else {
            // Generar código de reserva único
            $codigo_reserva = 'RES-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            // Verificar que el código no exista
            $checkCodigo = true;
            $intentos = 0;
            while ($checkCodigo && $intentos < 10) {
                $queryCheck = "SELECT id FROM reservas WHERE codigo_reserva = ?";
                $stmtCheck = $db->prepare($queryCheck);
                $stmtCheck->execute([$codigo_reserva]);
                if ($stmtCheck->fetch()) {
                    $codigo_reserva = 'RES-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
                    $intentos++;
                } else {
                    $checkCodigo = false;
                }
            }
            
            // Nueva reserva
            $query = "INSERT INTO reservas (
                      codigo_reserva, nombre_cliente, telefono_cliente, email_cliente,
                      fecha_reserva, hora_reserva, fecha_hora_reserva, usuario_id,
                      observaciones, estado, created_by
                      ) VALUES (
                      :codigo_reserva, :nombre_cliente, :telefono_cliente, :email_cliente,
                      :fecha_reserva, :hora_reserva, :fecha_hora_reserva, :usuario_id,
                      :observaciones, 'pendiente', :created_by
                      )";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':codigo_reserva', $codigo_reserva);
            $stmt->bindParam(':created_by', $_SESSION['usuario_id']);
        }
        
        $stmt->bindParam(':nombre_cliente', $nombre_cliente);
        $stmt->bindParam(':telefono_cliente', $telefono_cliente);
        $stmt->bindParam(':email_cliente', $email_cliente);
        $stmt->bindParam(':fecha_reserva', $fecha_reserva);
        $stmt->bindParam(':hora_reserva', $hora_reserva);
        $stmt->bindParam(':fecha_hora_reserva', $fecha_hora_limpia);
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->bindParam(':observaciones', $observaciones);
        
        $stmt->execute();
        
        if (!$id) {
            $id = $db->lastInsertId();
        }
        
        // Obtener información del servicio
        $queryServicio = "SELECT nombre, precio FROM servicios WHERE id = ?";
        $stmtServicio = $db->prepare($queryServicio);
        $stmtServicio->execute([$servicio_id]);
        $servicio = $stmtServicio->fetch(PDO::FETCH_ASSOC);
        
        if ($servicio) {
            // Insertar detalle del servicio
            $insertDetalle = "INSERT INTO reserva_detalles_servicios 
                            (reserva_id, servicio_id, nombre_servicio, precio_original, subtotal)
                            VALUES (?, ?, ?, ?, ?)";
            $stmtDetalle = $db->prepare($insertDetalle);
            $stmtDetalle->execute([$id, $servicio_id, $servicio['nombre'], $servicio['precio'], $servicio['precio']]);
            
            // Actualizar total
            $updateTotal = "UPDATE reservas SET total_servicios = ?, total_general = ? WHERE id = ?";
            $stmtUpdate = $db->prepare($updateTotal);
            $stmtUpdate->execute([$servicio['precio'], $servicio['precio'], $id]);
        }
        
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Reserva guardada correctamente', 'id' => $id]);
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error en guardarReserva: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al guardar la reserva: ' . $e->getMessage()]);
    }
}

function moverReserva() {
    global $db;
    
    $id = $_POST['id'] ?? 0;
    $fecha_hora_inicio = $_POST['fecha_hora_inicio'] ?? '';
    
    if (!$id || !$fecha_hora_inicio) {
        echo json_encode(['success' => false, 'message' => 'Datos incompletos']);
        return;
    }
    
    try {
        // Verificar que la reserva no esté completada o cancelada
        $queryCheck = "SELECT estado FROM reservas WHERE id = ?";
        $stmtCheck = $db->prepare($queryCheck);
        $stmtCheck->execute([$id]);
        $reserva = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$reserva) {
            echo json_encode(['success' => false, 'message' => 'Reserva no encontrada']);
            return;
        }
        
        if (in_array($reserva['estado'], ['completada', 'cancelada'])) {
            echo json_encode(['success' => false, 'message' => 'No se puede mover una reserva ' . $reserva['estado']]);
            return;
        }
        
        // Limpiar formato de fecha
        $fecha_hora_limpia = limpiarFechaFullCalendar($fecha_hora_inicio);
        
        if (empty($fecha_hora_limpia)) {
            echo json_encode(['success' => false, 'message' => 'Formato de fecha inválido']);
            return;
        }
        
        $fecha_reserva = date('Y-m-d', strtotime($fecha_hora_limpia));
        $hora_reserva = date('H:i:s', strtotime($fecha_hora_limpia));
        
        $query = "UPDATE reservas SET 
                  fecha_reserva = ?, hora_reserva = ?, fecha_hora_reserva = ?
                  WHERE id = ?";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$fecha_reserva, $hora_reserva, $fecha_hora_limpia, $id]);
        
        echo json_encode(['success' => true, 'message' => 'Reserva movida correctamente']);
        
    } catch (Exception $e) {
        error_log("Error en moverReserva: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al mover la reserva: ' . $e->getMessage()]);
    }
}

function cambiarEstado() {
    global $db;
    
    $id = $_POST['id'] ?? 0;
    $estado = $_POST['estado'] ?? '';
    $motivo = $_POST['motivo'] ?? '';
    
    if (!$id || !in_array($estado, ['pendiente', 'confirmada', 'completada', 'cancelada'])) {
        echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
        return;
    }
    
    try {
        // Verificar que la reserva exista
        $queryCheck = "SELECT estado FROM reservas WHERE id = ?";
        $stmtCheck = $db->prepare($queryCheck);
        $stmtCheck->execute([$id]);
        $reserva = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$reserva) {
            echo json_encode(['success' => false, 'message' => 'Reserva no encontrada']);
            return;
        }
        
        // No permitir cambiar estado de completada o cancelada
        if (in_array($reserva['estado'], ['completada', 'cancelada']) && $estado !== $reserva['estado']) {
            echo json_encode(['success' => false, 'message' => 'No se puede cambiar el estado de una reserva ' . $reserva['estado']]);
            return;
        }
        
        if ($estado === 'cancelada') {
            $query = "UPDATE reservas SET estado = ?, motivo_cancelacion = ? WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$estado, $motivo, $id]);
        } else {
            $query = "UPDATE reservas SET estado = ? WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$estado, $id]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Estado actualizado correctamente']);
        
    } catch (Exception $e) {
        error_log("Error en cambiarEstado: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al cambiar estado: ' . $e->getMessage()]);
    }
}

function eliminarReserva() {
    global $db;
    
    $id = $_POST['id'] ?? 0;
    $motivo = $_POST['motivo'] ?? '';
    
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'ID no proporcionado']);
        return;
    }
    
    try {
        // Verificar que la reserva exista
        $queryCheck = "SELECT estado FROM reservas WHERE id = ?";
        $stmtCheck = $db->prepare($queryCheck);
        $stmtCheck->execute([$id]);
        $reserva = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        
        if (!$reserva) {
            echo json_encode(['success' => false, 'message' => 'Reserva no encontrada']);
            return;
        }
        
        if ($reserva['estado'] === 'completada') {
            echo json_encode(['success' => false, 'message' => 'No se puede cancelar una reserva completada']);
            return;
        }
        
        if ($reserva['estado'] === 'cancelada') {
            echo json_encode(['success' => false, 'message' => 'La reserva ya está cancelada']);
            return;
        }
        
        // Cambiar estado a cancelada con motivo
        $query = "UPDATE reservas SET estado = 'cancelada', motivo_cancelacion = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$motivo, $id]);
        
        echo json_encode(['success' => true, 'message' => 'Reserva cancelada correctamente']);
        
    } catch (Exception $e) {
        error_log("Error en eliminarReserva: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error al cancelar reserva: ' . $e->getMessage()]);
    }
}
?>