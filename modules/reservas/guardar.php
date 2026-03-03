<?php
// modules/reservas/guardar.php
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación y permisos
if (!isset($_SESSION['usuario_id']) || !$auth->hasPermission('reservas', 'crear')) {
    $_SESSION['error'] = "No tienes permisos para crear reservas";
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: crear.php");
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Obtener datos del formulario
$cliente_id = intval($_POST['cliente_id'] ?? 0);
$usuario_id = !empty($_POST['usuario_id']) ? intval($_POST['usuario_id']) : null;
$fecha_hora = $_POST['fecha_hora'] ?? '';
$servicio_principal = intval($_POST['servicio_principal'] ?? 0);
$servicios_json = $_POST['servicios_json'] ?? '[]';
$observaciones = trim($_POST['observaciones'] ?? '');

// Validaciones básicas
$errors = [];

if ($cliente_id <= 0) {
    $errors[] = "Debe seleccionar un cliente";
}

if (empty($fecha_hora)) {
    $errors[] = "La fecha y hora son requeridas";
}

if ($servicio_principal <= 0) {
    $errors[] = "Debe seleccionar un servicio principal";
}

if (!empty($errors)) {
    $_SESSION['error'] = implode("<br>", $errors);
    header("Location: crear.php");
    exit;
}

// Obtener datos del cliente
$query_cliente = "SELECT nombre, telefono, email FROM clientes WHERE id = ?";
$stmt_cliente = $db->prepare($query_cliente);
$stmt_cliente->execute([$cliente_id]);
$cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    $_SESSION['error'] = "Cliente no encontrado";
    header("Location: crear.php");
    exit;
}

// Función para limpiar formato de fecha
function limpiarFecha($fecha_hora) {
    if (empty($fecha_hora)) return '';
    
    // Si viene con T (formato input datetime-local)
    $fecha_hora_limpia = str_replace('T', ' ', $fecha_hora);
    
    // Asegurar que tenga segundos
    if (strlen($fecha_hora_limpia) == 16) { // formato "YYYY-MM-DD HH:MM"
        $fecha_hora_limpia .= ':00';
    }
    
    return $fecha_hora_limpia;
}

// Decodificar servicios
$servicios = json_decode($servicios_json, true);
if (!is_array($servicios)) {
    $servicios = [];
}

// Asegurar que el servicio principal esté incluido
$servicio_principal_incluido = false;
foreach ($servicios as $s) {
    if (isset($s['id']) && $s['id'] == $servicio_principal) {
        $servicio_principal_incluido = true;
        break;
    }
}

if (!$servicio_principal_incluido) {
    // Obtener información del servicio principal
    $query_servicio = "SELECT id, nombre, precio, precio_variable FROM servicios WHERE id = ?";
    $stmt_servicio = $db->prepare($query_servicio);
    $stmt_servicio->execute([$servicio_principal]);
    $servicio_data = $stmt_servicio->fetch(PDO::FETCH_ASSOC);
    
    if ($servicio_data) {
        array_unshift($servicios, [
            'id' => $servicio_data['id'],
            'nombre' => $servicio_data['nombre'],
            'precio' => floatval($servicio_data['precio']),
            'precioVariable' => $servicio_data['precio_variable'] == 1,
            'esPrincipal' => true
        ]);
    } else {
        $_SESSION['error'] = "Servicio principal no encontrado";
        header("Location: crear.php");
        exit;
    }
}

try {
    $db->beginTransaction();
    
    // Limpiar formato de fecha
    $fecha_hora_limpia = limpiarFecha($fecha_hora);
    
    // Separar fecha y hora
    $fecha_reserva = date('Y-m-d', strtotime($fecha_hora_limpia));
    $hora_reserva = date('H:i:s', strtotime($fecha_hora_limpia));
    
    // Generar código de reserva único
    $codigo_reserva = 'RES-' . date('Ymd') . '-' . str_pad(random_int(1, 999), 3, '0', STR_PAD_LEFT);
    
    // Verificar que el código no exista
    $check_codigo = true;
    $intentos = 0;
    while ($check_codigo && $intentos < 10) {
        $query_check = "SELECT id FROM reservas WHERE codigo_reserva = ?";
        $stmt_check = $db->prepare($query_check);
        $stmt_check->execute([$codigo_reserva]);
        if ($stmt_check->fetch()) {
            $codigo_reserva = 'RES-' . date('Ymd') . '-' . str_pad(random_int(1, 999), 3, '0', STR_PAD_LEFT);
            $intentos++;
        } else {
            $check_codigo = false;
        }
    }
    
    // Calcular totales
    $total_servicios = 0;
    foreach ($servicios as $s) {
        $total_servicios += floatval($s['precio'] ?? 0);
    }
    
    // Insertar reserva
    $query = "INSERT INTO reservas (
                codigo_reserva, 
                nombre_cliente, 
                telefono_cliente, 
                email_cliente,
                fecha_reserva, 
                hora_reserva, 
                fecha_hora_reserva, 
                usuario_id, 
                observaciones, 
                estado, 
                total_servicios, 
                total_general,
                created_by,
                created_at
              ) VALUES (
                :codigo_reserva, 
                :nombre_cliente, 
                :telefono_cliente, 
                :email_cliente,
                :fecha_reserva, 
                :hora_reserva, 
                :fecha_hora_reserva, 
                :usuario_id, 
                :observaciones, 
                'pendiente', 
                :total_servicios, 
                :total_general,
                :created_by,
                NOW()
              )";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':codigo_reserva', $codigo_reserva);
    $stmt->bindParam(':nombre_cliente', $cliente['nombre']);
    $stmt->bindParam(':telefono_cliente', $cliente['telefono']);
    $stmt->bindParam(':email_cliente', $cliente['email']);
    $stmt->bindParam(':fecha_reserva', $fecha_reserva);
    $stmt->bindParam(':hora_reserva', $hora_reserva);
    $stmt->bindParam(':fecha_hora_reserva', $fecha_hora_limpia);
    $stmt->bindParam(':usuario_id', $usuario_id);
    $stmt->bindParam(':observaciones', $observaciones);
    $stmt->bindParam(':total_servicios', $total_servicios);
    $stmt->bindParam(':total_general', $total_servicios);
    $stmt->bindParam(':created_by', $_SESSION['usuario_id']);
    
    $stmt->execute();
    
    $reserva_id = $db->lastInsertId();
    
    // Insertar detalles de servicios
    $insert_detalle = "INSERT INTO reserva_detalles_servicios (
                        reserva_id, 
                        servicio_id, 
                        nombre_servicio, 
                        precio_original, 
                        precio_final, 
                        cantidad, 
                        subtotal
                      ) VALUES (
                        :reserva_id, 
                        :servicio_id, 
                        :nombre_servicio, 
                        :precio_original, 
                        :precio_final, 
                        1, 
                        :subtotal
                      )";
    
    $stmt_detalle = $db->prepare($insert_detalle);
    
    foreach ($servicios as $s) {
        $servicio_id = intval($s['id']);
        $nombre_servicio = $s['nombre'];
        $precio = floatval($s['precio'] ?? 0);
        
        $stmt_detalle->bindParam(':reserva_id', $reserva_id);
        $stmt_detalle->bindParam(':servicio_id', $servicio_id);
        $stmt_detalle->bindParam(':nombre_servicio', $nombre_servicio);
        $stmt_detalle->bindParam(':precio_original', $precio);
        $stmt_detalle->bindParam(':precio_final', $precio);
        $stmt_detalle->bindParam(':subtotal', $precio);
        $stmt_detalle->execute();
    }
    
    $db->commit();
    
    $_SESSION['success'] = "Reserva creada correctamente. Código: " . $codigo_reserva;
    header("Location: ver.php?id=" . $reserva_id);
    exit;
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("Error al guardar reserva: " . $e->getMessage());
    $_SESSION['error'] = "Error al crear la reserva: " . $e->getMessage();
    header("Location: crear.php");
    exit;
}
?>