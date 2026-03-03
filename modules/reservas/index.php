<?php
/**
 * ============================================
 * ARCHIVO: index.php
 * UBICACIÓN: /modules/reservas/index.php
 * PROPÓSITO: Listado de reservas con acciones completas
 * ============================================
 */

session_start();

require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permiso
if (!$auth->hasPermission('reservas', 'leer')) {
    $_SESSION['error'] = "No tienes permisos para ver reservas";
    header("Location: " . BASE_URL . "index.php");
    exit();
}

$database = Database::getInstance();
$db = $database->getConnection();

// Procesar cambios de estado via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['action'] === 'cambiar_estado') {
        $id = intval($_POST['id'] ?? 0);
        $nuevo_estado = $_POST['estado'] ?? '';
        $motivo = $_POST['motivo'] ?? '';
        
        if ($id <= 0 || !in_array($nuevo_estado, ['confirmada', 'completada', 'cancelada'])) {
            echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
            exit;
        }
        
        try {
            // Verificar permiso específico
            if ($nuevo_estado === 'completada' && !$auth->hasPermission('reservas', 'completar')) {
                echo json_encode(['success' => false, 'message' => 'No tienes permiso para completar reservas']);
                exit;
            }
            
            if ($nuevo_estado === 'cancelada' && !$auth->hasPermission('reservas', 'eliminar')) {
                echo json_encode(['success' => false, 'message' => 'No tienes permiso para cancelar reservas']);
                exit;
            }
            
            $db->beginTransaction();
            
            if ($nuevo_estado === 'completada') {
                $query = "UPDATE reservas SET estado = 'completada', updated_at = NOW() WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$id]);
                
            } elseif ($nuevo_estado === 'cancelada') {
                $query = "UPDATE reservas SET estado = 'cancelada', motivo_cancelacion = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$motivo, $id]);
                
            } else {
                $query = "UPDATE reservas SET estado = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $db->prepare($query);
                $stmt->execute([$nuevo_estado, $id]);
            }
            
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'Estado actualizado correctamente']);
            
        } catch (Exception $e) {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Filtros
$filtro_estado = $_GET['estado'] ?? '';
$filtro_fecha = $_GET['fecha'] ?? date('Y-m-d');
$busqueda = $_GET['busqueda'] ?? '';

// Construir consulta
$sql = "SELECT r.*, u.nombre as empleado_nombre 
        FROM reservas r
        LEFT JOIN usuarios u ON r.usuario_id = u.id
        WHERE 1=1";
$params = [];

if (!empty($filtro_estado)) {
    $sql .= " AND r.estado = :estado";
    $params[':estado'] = $filtro_estado;
}

if (!empty($filtro_fecha)) {
    $sql .= " AND DATE(r.fecha_reserva) = :fecha";
    $params[':fecha'] = $filtro_fecha;
}

if (!empty($busqueda)) {
    $sql .= " AND (r.nombre_cliente LIKE :busqueda OR r.codigo_reserva LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}

$sql .= " ORDER BY r.fecha_hora_reserva DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
                SUM(CASE WHEN estado = 'confirmada' THEN 1 ELSE 0 END) as confirmadas,
                SUM(CASE WHEN estado = 'completada' THEN 1 ELSE 0 END) as completadas,
                SUM(CASE WHEN estado = 'cancelada' THEN 1 ELSE 0 END) as canceladas
                FROM reservas
                WHERE DATE(fecha_reserva) = CURDATE()";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$page_title = "Reservas - " . ($config['nombre_negocio'] ?? 'Sistema POS');
include __DIR__ . '/../../includes/header.php';
?>

<style>
/* Estilos mejorados para la tabla */
.table-container {
    overflow-x: auto;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
}

.table-container table {
    min-width: 1200px; /* Ancho mínimo para evitar que se compriman las columnas */
    width: 100%;
}

/* Estilos para los badges de estado */
.estado-badge {
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    white-space: nowrap;
}

.estado-pendiente { background-color: #fef3c7; color: #92400e; }
.estado-confirmada { background-color: #dbeafe; color: #1e40af; }
.estado-completada { background-color: #d1fae5; color: #065f46; }
.estado-cancelada { background-color: #fee2e2; color: #991b1b; }

/* Estilos para los botones de acción */
.action-btn {
    padding: 0.5rem;
    border-radius: 0.375rem;
    transition: all 0.2s ease;
    background: white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border: none;
    cursor: pointer;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
}

.action-btn:focus {
    outline: none;
    ring: 2px solid #3b82f6;
}

/* Estilos del modal mejorados */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: rgba(0, 0, 0, 0.5);
    z-index: 9998;
}

.modal-container {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    pointer-events: none;
}

.modal-content {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
    max-width: 32rem;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
    pointer-events: auto;
    animation: modalFadeIn 0.3s ease;
}

@keyframes modalFadeIn {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}

/* Estilos para los filtros */
.filtros-container {
    background: white;
    border-radius: 0.5rem;
    padding: 1rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
}

/* Estadísticas responsive */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: white;
    border-radius: 0.5rem;
    padding: 1rem;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
    border-left: 4px solid;
}

.stat-card.total { border-left-color: #3b82f6; }
.stat-card.pendientes { border-left-color: #f59e0b; }
.stat-card.confirmadas { border-left-color: #3b82f6; }
.stat-card.completadas { border-left-color: #10b981; }
.stat-card.canceladas { border-left-color: #ef4444; }

/* Ajustes responsive */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .filtros-grid {
        grid-template-columns: 1fr !important;
    }
    
    .action-group {
        flex-wrap: wrap;
    }
}

/* Tooltips personalizados */
[data-tooltip] {
    position: relative;
    cursor: help;
}

[data-tooltip]:before {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    padding: 4px 8px;
    background: #1f2937;
    color: white;
    font-size: 12px;
    border-radius: 4px;
    white-space: nowrap;
    opacity: 0;
    visibility: hidden;
    transition: all 0.2s ease;
    z-index: 1000;
}

[data-tooltip]:hover:before {
    opacity: 1;
    visibility: visible;
    bottom: 120%;
}
</style>

<div class="max-w-7xl mx-auto p-4 sm:p-6">
    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">
                <i class="fas fa-calendar-alt text-blue-600 mr-2"></i>
                Gestión de Reservas
            </h1>
            <p class="text-gray-600 mt-1 text-sm sm:text-base">Administra las citas y reservas del sistema</p>
        </div>
        <div class="flex flex-wrap gap-2 mt-4 md:mt-0">
            <a href="calendario.php" class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 sm:px-4 sm:py-2 rounded-lg flex items-center text-sm sm:text-base">
                <i class="fas fa-calendar-week mr-2"></i>
                <span class="hidden sm:inline">Ver Calendario</span>
                <span class="sm:hidden">Calendario</span>
            </a>
            <?php if ($auth->hasPermission('reservas', 'crear')): ?>
            <a href="crear.php" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 sm:px-4 sm:py-2 rounded-lg flex items-center text-sm sm:text-base">
                <i class="fas fa-plus mr-2"></i>
                <span class="hidden sm:inline">Nueva Reserva</span>
                <span class="sm:hidden">Nueva</span>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 sm:p-4 rounded mb-4 flex justify-between items-center text-sm sm:text-base">
            <span><i class="fas fa-check-circle mr-2"></i><?php echo $_SESSION['success']; ?></span>
            <button onclick="this.parentElement.remove()" class="text-green-700 hover:text-green-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 sm:p-4 rounded mb-4 flex justify-between items-center text-sm sm:text-base">
            <span><i class="fas fa-exclamation-circle mr-2"></i><?php echo $_SESSION['error']; ?></span>
            <button onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Estadísticas del día -->
    <div class="stats-grid">
        <div class="stat-card total">
            <p class="text-xs sm:text-sm text-gray-500">Total Hoy</p>
            <p class="text-xl sm:text-2xl font-bold"><?php echo $stats['total'] ?? 0; ?></p>
        </div>
        <div class="stat-card pendientes">
            <p class="text-xs sm:text-sm text-gray-500">Pendientes</p>
            <p class="text-xl sm:text-2xl font-bold text-yellow-600"><?php echo $stats['pendientes'] ?? 0; ?></p>
        </div>
        <div class="stat-card confirmadas">
            <p class="text-xs sm:text-sm text-gray-500">Confirmadas</p>
            <p class="text-xl sm:text-2xl font-bold text-blue-600"><?php echo $stats['confirmadas'] ?? 0; ?></p>
        </div>
        <div class="stat-card completadas">
            <p class="text-xs sm:text-sm text-gray-500">Completadas</p>
            <p class="text-xl sm:text-2xl font-bold text-green-600"><?php echo $stats['completadas'] ?? 0; ?></p>
        </div>
        <div class="stat-card canceladas">
            <p class="text-xs sm:text-sm text-gray-500">Canceladas</p>
            <p class="text-xl sm:text-2xl font-bold text-red-600"><?php echo $stats['canceladas'] ?? 0; ?></p>
        </div>
    </div>

    <!-- Filtros -->
    <div class="filtros-container">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 filtros-grid">
            <div>
                <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Buscar</label>
                <input type="text" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" 
                       placeholder="Cliente o código"
                       class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Estado</label>
                <select name="estado" class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-blue-500">
                    <option value="">Todos</option>
                    <option value="pendiente" <?php echo $filtro_estado == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                    <option value="confirmada" <?php echo $filtro_estado == 'confirmada' ? 'selected' : ''; ?>>Confirmada</option>
                    <option value="completada" <?php echo $filtro_estado == 'completada' ? 'selected' : ''; ?>>Completada</option>
                    <option value="cancelada" <?php echo $filtro_estado == 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
                </select>
            </div>
            <div>
                <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-1">Fecha</label>
                <input type="date" name="fecha" value="<?php echo $filtro_fecha; ?>"
                       class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="flex items-end space-x-2">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 text-sm rounded-lg flex-1">
                    <i class="fas fa-search mr-2"></i>Filtrar
                </button>
                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 text-sm rounded-lg">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Tabla de reservas con scroll horizontal -->
    <div class="table-container bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Código</th>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente</th>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fecha/Hora</th>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Empleado</th>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estado</th>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                        <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (count($reservas) > 0): ?>
                        <?php foreach ($reservas as $r): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap font-mono text-xs sm:text-sm">
                                <?php echo htmlspecialchars($r['codigo_reserva']); ?>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($r['nombre_cliente']); ?></div>
                                <?php if (!empty($r['telefono_cliente'])): ?>
                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($r['telefono_cliente']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-gray-900"><?php echo date('d/m/Y', strtotime($r['fecha_reserva'])); ?></div>
                                <div class="text-xs text-gray-500"><?php echo date('H:i', strtotime($r['hora_reserva'])); ?></div>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($r['empleado_nombre'] ?? 'No asignado'); ?>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                <span class="estado-badge estado-<?php echo $r['estado']; ?>">
                                    <i class="fas fa-<?php 
                                        echo $r['estado'] == 'completada' ? 'check-circle' : 
                                            ($r['estado'] == 'cancelada' ? 'times-circle' : 
                                            ($r['estado'] == 'confirmada' ? 'check-circle' : 'clock')); 
                                    ?>"></i>
                                    <?php echo ucfirst($r['estado']); ?>
                                </span>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-sm font-medium">
                                $<?php echo number_format($r['total_general'], 2); ?>
                            </td>
                            <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                                <div class="flex space-x-1 action-group">
                                    <!-- Ver detalle -->
                                    <a href="ver.php?id=<?php echo $r['id']; ?>" 
                                       class="action-btn text-blue-600 hover:text-blue-800 hover:bg-blue-50" 
                                       data-tooltip="Ver detalle">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <!-- Editar (solo si no está completada o cancelada) -->
                                    <?php if ($auth->hasPermission('reservas', 'editar') && !in_array($r['estado'], ['completada', 'cancelada'])): ?>
                                    <a href="editar.php?id=<?php echo $r['id']; ?>" 
                                       class="action-btn text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50" 
                                       data-tooltip="Editar">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <!-- Confirmar (de pendiente a confirmada) -->
                                    <?php if ($auth->hasPermission('reservas', 'editar') && $r['estado'] == 'pendiente'): ?>
                                    <button onclick="cambiarEstado(<?php echo $r['id']; ?>, 'confirmada')" 
                                            class="action-btn text-blue-600 hover:text-blue-800 hover:bg-blue-50" 
                                            data-tooltip="Confirmar reserva">
                                        <i class="fas fa-check-circle"></i>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <!-- Completar y Facturar (de confirmada a completada) -->
                                    <?php if ($auth->hasPermission('reservas', 'completar') && $r['estado'] == 'confirmada'): ?>
                                    <a href="completar.php?id=<?php echo $r['id']; ?>" 
                                       class="action-btn text-green-600 hover:text-green-800 hover:bg-green-50" 
                                       data-tooltip="Completar y facturar">
                                        <i class="fas fa-cash-register"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <!-- Cancelar (solo si no está completada o cancelada) -->
                                    <?php if ($auth->hasPermission('reservas', 'eliminar') && !in_array($r['estado'], ['completada', 'cancelada'])): ?>
                                    <button onclick="abrirModalCancelar(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars($r['nombre_cliente']); ?>')" 
                                            class="action-btn text-red-600 hover:text-red-800 hover:bg-red-50" 
                                            data-tooltip="Cancelar reserva">
                                        <i class="fas fa-times-circle"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-4 sm:px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-calendar-times text-4xl mb-3 opacity-30"></i>
                                <p class="text-sm sm:text-base">No hay reservas para mostrar</p>
                                <?php if ($auth->hasPermission('reservas', 'crear')): ?>
                                <a href="crear.php" class="inline-block mt-4 text-blue-600 hover:text-blue-800 text-sm sm:text-base">
                                    <i class="fas fa-plus mr-1"></i>Crear primera reserva
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="bg-gray-50 px-4 sm:px-6 py-3 border-t">
            <p class="text-xs sm:text-sm text-gray-600">
                <i class="fas fa-list mr-1"></i>
                Mostrando <?php echo count($reservas); ?> reserva(s)
            </p>
        </div>
    </div>
</div>

<!-- Modal de cancelación - VERSIÓN CORREGIDA -->
<div id="modalCancelar" class="hidden">
    <!-- Overlay -->
    <div class="modal-overlay" onclick="cerrarModalCancelar()"></div>
    
    <!-- Contenido del modal -->
    <div class="modal-container">
        <div class="modal-content">
            <div class="bg-white rounded-lg">
                <div class="p-4 sm:p-6">
                    <div class="flex items-start">
                        <div class="flex-shrink-0 flex items-center justify-center h-10 w-10 sm:h-12 sm:w-12 rounded-full bg-red-100">
                            <i class="fas fa-exclamation-triangle text-red-600 text-lg sm:text-xl"></i>
                        </div>
                        <div class="ml-3 sm:ml-4 flex-1">
                            <h3 class="text-base sm:text-lg font-medium text-gray-900">
                                Cancelar Reserva
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">
                                ¿Estás seguro de cancelar la reserva de <span id="clienteCancelar" class="font-semibold"></span>?
                            </p>
                            
                            <div class="mt-4">
                                <label class="block text-xs sm:text-sm font-medium text-gray-700 mb-2">
                                    Motivo de cancelación <span class="text-red-500">*</span>
                                </label>
                                <textarea id="motivoCancelar" rows="3" 
                                          class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-red-500"
                                          placeholder="Indique el motivo de la cancelación..."></textarea>
                                <p id="errorMotivo" class="text-xs text-red-500 mt-1 hidden">El motivo es obligatorio</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse rounded-b-lg">
                    <button type="button" onclick="procesarCancelacion()" 
                            class="w-full sm:w-auto inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-sm font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:ml-3">
                        <i class="fas fa-times-circle mr-2"></i>
                        Cancelar Reserva
                    </button>
                    <button type="button" onclick="cerrarModalCancelar()" 
                            class="mt-3 sm:mt-0 w-full sm:w-auto inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let reservaIdCancelar = null;

function cambiarEstado(id, nuevoEstado) {
    let mensaje = '';
    
    if (nuevoEstado === 'confirmada') {
        mensaje = '¿Confirmar esta reserva?';
    } else if (nuevoEstado === 'completada') {
        mensaje = '¿Completar esta reserva? Se registrará el ingreso.';
    }
    
    if (!confirm(mensaje)) {
        return;
    }
    
    fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'cambiar_estado',
            id: id,
            estado: nuevoEstado
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al cambiar el estado');
    });
}

function abrirModalCancelar(id, cliente) {
    reservaIdCancelar = id;
    document.getElementById('clienteCancelar').textContent = cliente;
    document.getElementById('modalCancelar').classList.remove('hidden');
    document.getElementById('motivoCancelar').value = '';
    document.getElementById('errorMotivo').classList.add('hidden');
    
    // Prevenir scroll en el body
    document.body.style.overflow = 'hidden';
}

function cerrarModalCancelar() {
    document.getElementById('modalCancelar').classList.add('hidden');
    reservaIdCancelar = null;
    
    // Restaurar scroll
    document.body.style.overflow = '';
}

function procesarCancelacion() {
    const motivo = document.getElementById('motivoCancelar').value.trim();
    
    if (!motivo) {
        document.getElementById('errorMotivo').classList.remove('hidden');
        document.getElementById('motivoCancelar').focus();
        return;
    }
    
    if (!reservaIdCancelar) return;
    
    fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'cambiar_estado',
            id: reservaIdCancelar,
            estado: 'cancelada',
            motivo: motivo
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al cancelar la reserva');
    });
}

// Cerrar modal con ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModalCancelar();
    }
});

// Prevenir que el modal capture clicks internos y los propague al overlay
document.querySelector('.modal-content')?.addEventListener('click', function(e) {
    e.stopPropagation();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>