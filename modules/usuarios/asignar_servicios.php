<?php
/**
 * ============================================
 * ARCHIVO: asignar_servicios.php
 * UBICACIÓN: /modules/usuarios/asignar_servicios.php
 * PROPÓSITO: Asignar servicios a usuarios
 * ============================================
 */

session_start();
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación y permisos
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] != 'admin') {
    $_SESSION['error'] = "No tienes permisos para acceder";
    header("Location: index.php");
    exit();
}

$database = Database::getInstance();
$db = $database->getConnection();

$usuario_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($usuario_id <= 0) {
    $_SESSION['error'] = "ID de usuario no válido";
    header("Location: index.php");
    exit();
}

// Obtener datos del usuario
$user_query = "SELECT * FROM usuarios WHERE id = ?";
$user_stmt = $db->prepare($user_query);
$user_stmt->execute([$usuario_id]);
$usuario = $user_stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    $_SESSION['error'] = "Usuario no encontrado";
    header("Location: index.php");
    exit();
}

// Procesar el formulario de asignación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_asignacion'])) {
    $servicios_asignados = $_POST['servicios'] ?? [];
    
    try {
        $db->beginTransaction();
        
        // Eliminar asignaciones anteriores
        $delete = "DELETE FROM usuarios_servicios WHERE usuario_id = ?";
        $delete_stmt = $db->prepare($delete);
        $delete_stmt->execute([$usuario_id]);
        
        // Insertar nuevas asignaciones
        if (!empty($servicios_asignados)) {
            $insert = "INSERT INTO usuarios_servicios (usuario_id, servicio_id, nivel_experiencia, asignado_por) 
                      VALUES (?, ?, ?, ?)";
            $insert_stmt = $db->prepare($insert);
            $asignado_por = $_SESSION['usuario_id'];
            
            foreach ($servicios_asignados as $servicio_id => $data) {
                if (isset($data['activo']) && $data['activo'] == 1) {
                    $nivel = $data['nivel'] ?? 'intermedio';
                    $insert_stmt->execute([$usuario_id, $servicio_id, $nivel, $asignado_por]);
                }
            }
        }
        
        $db->commit();
        $_SESSION['success'] = "Servicios asignados correctamente";
        header("Location: asignar_servicios.php?id=" . $usuario_id);
        exit();
        
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Error al asignar servicios: " . $e->getMessage();
    }
}

// Obtener todos los servicios disponibles
$servicios_query = "SELECT * FROM servicios WHERE activo = 1 ORDER BY nombre";
$servicios_stmt = $db->prepare($servicios_query);
$servicios_stmt->execute();
$servicios = $servicios_stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener servicios ya asignados al usuario
$asignados_query = "SELECT servicio_id, nivel_experiencia FROM usuarios_servicios WHERE usuario_id = ?";
$asignados_stmt = $db->prepare($asignados_query);
$asignados_stmt->execute([$usuario_id]);
$asignados = [];
while ($row = $asignados_stmt->fetch(PDO::FETCH_ASSOC)) {
    $asignados[$row['servicio_id']] = $row['nivel_experiencia'];
}

// Obtener roles del usuario para mostrar información
$roles_query = "SELECT r.* FROM roles r
                INNER JOIN usuarios_roles ur ON r.id = ur.rol_id
                WHERE ur.usuario_id = ?";
$roles_stmt = $db->prepare($roles_query);
$roles_stmt->execute([$usuario_id]);
$usuario_roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Asignar Servicios a Usuario";
include __DIR__ . '/../../includes/header.php';
?>

<style>
.servicio-card {
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 10px;
    transition: all 0.3s ease;
    background: white;
}

.servicio-card:hover {
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    border-color: #3b82f6;
}

.servicio-card.asignado {
    background-color: #f0fdf4;
    border-color: #22c55e;
    border-left-width: 4px;
}

.nivel-select {
    width: 150px;
    padding: 5px 8px;
    border-radius: 4px;
    border: 1px solid #d1d5db;
    background: white;
    font-size: 13px;
}

.nivel-select:focus {
    outline: none;
    border-color: #3b82f6;
    ring: 2px solid #3b82f6;
}

.nivel-select:disabled {
    background: #f3f4f6;
    color: #9ca3af;
    cursor: not-allowed;
}

.info-usuario {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.rol-tag {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    margin-right: 5px;
    background: rgba(255,255,255,0.2);
    color: white;
}

.stats-card {
    background: white;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
</style>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-hand-holding-heart text-blue-600 mr-2"></i>
                Asignar Servicios a Usuario
            </h1>
        </div>
        <div class="flex gap-2">
            <a href="ver.php?id=<?php echo $usuario_id; ?>" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>Volver al Usuario
            </a>
            <a href="index.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-users mr-2"></i>Lista de Usuarios
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
            <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Información del Usuario -->
    <div class="info-usuario">
        <div class="flex items-center gap-4">
            <div class="bg-white bg-opacity-20 rounded-full p-4">
                <i class="fas fa-user-circle text-4xl"></i>
            </div>
            <div>
                <h2 class="text-2xl font-bold"><?php echo htmlspecialchars($usuario['nombre']); ?></h2>
                <p class="text-white text-opacity-90">@<?php echo htmlspecialchars($usuario['username']); ?></p>
                <div class="mt-2">
                    <?php foreach ($usuario_roles as $rol): ?>
                        <span class="rol-tag">
                            <i class="<?php echo $rol['icono'] ?? 'fas fa-user-tag'; ?> mr-1"></i>
                            <?php echo ucfirst($rol['nombre']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats rápidas -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="stats-card">
            <p class="text-sm text-gray-500">Total Servicios</p>
            <p class="text-2xl font-bold"><?php echo count($servicios); ?></p>
        </div>
        <div class="stats-card">
            <p class="text-sm text-gray-500">Servicios Asignados</p>
            <p class="text-2xl font-bold text-green-600"><?php echo count($asignados); ?></p>
        </div>
        <div class="stats-card">
            <p class="text-sm text-gray-500">Por Asignar</p>
            <p class="text-2xl font-bold text-orange-600"><?php echo count($servicios) - count($asignados); ?></p>
        </div>
    </div>

    <!-- Formulario de asignación -->
    <div class="bg-white rounded-lg shadow p-6">
        <form method="POST" id="formAsignacion">
            <div class="mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold">Servicios Disponibles</h2>
                    <div class="flex gap-2">
                        <button type="button" onclick="seleccionarTodos()" class="text-sm bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">
                            Seleccionar Todos
                        </button>
                        <button type="button" onclick="deseleccionarTodos()" class="text-sm bg-gray-200 hover:bg-gray-300 px-3 py-1 rounded">
                            Deseleccionar Todos
                        </button>
                    </div>
                </div>
                
                <p class="text-sm text-gray-600 mb-4">
                    <i class="fas fa-info-circle mr-1 text-blue-500"></i>
                    Selecciona los servicios que <strong><?php echo htmlspecialchars($usuario['nombre']); ?></strong> puede atender:
                </p>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($servicios as $servicio): 
                        $asignado = isset($asignados[$servicio['id']]);
                        $nivel = $asignados[$servicio['id']] ?? 'intermedio';
                    ?>
                    <div class="servicio-card <?php echo $asignado ? 'asignado' : ''; ?>" id="card_<?php echo $servicio['id']; ?>">
                        <div class="flex items-start gap-3">
                            <input type="checkbox" 
                                   name="servicios[<?php echo $servicio['id']; ?>][activo]" 
                                   value="1" 
                                   class="mt-1 servicio-checkbox"
                                   data-id="<?php echo $servicio['id']; ?>"
                                   onchange="toggleServicio(<?php echo $servicio['id']; ?>, this.checked)"
                                   <?php echo $asignado ? 'checked' : ''; ?>>
                            
                            <div class="flex-1">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($servicio['nombre']); ?></h3>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($servicio['descripcion'] ?: 'Sin descripción'); ?></p>
                                    </div>
                                    <span class="text-sm font-semibold text-blue-600 bg-blue-50 px-2 py-1 rounded">
                                        $<?php echo number_format($servicio['precio'], 2); ?>
                                    </span>
                                </div>
                                
                                <div class="mt-3 flex items-center gap-3">
                                    <label class="text-sm text-gray-600">Nivel de experiencia:</label>
                                    <select name="servicios[<?php echo $servicio['id']; ?>][nivel]" 
                                            class="nivel-select"
                                            id="nivel_<?php echo $servicio['id']; ?>"
                                            <?php echo !$asignado ? 'disabled' : ''; ?>>
                                        <option value="principiante" <?php echo $nivel == 'principiante' ? 'selected' : ''; ?>>🌱 Principiante</option>
                                        <option value="intermedio" <?php echo $nivel == 'intermedio' ? 'selected' : ''; ?>>📊 Intermedio</option>
                                        <option value="avanzado" <?php echo $nivel == 'avanzado' ? 'selected' : ''; ?>>⚡ Avanzado</option>
                                        <option value="experto" <?php echo $nivel == 'experto' ? 'selected' : ''; ?>>👑 Experto</option>
                                    </select>
                                </div>
                                
                                <?php if ($servicio['precio_variable']): ?>
                                <p class="text-xs text-purple-600 mt-2">
                                    <i class="fas fa-random mr-1"></i>Precio variable
                                </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Botones de acción -->
            <div class="flex justify-end gap-3 pt-4 border-t">
                <a href="ver.php?id=<?php echo $usuario_id; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg">
                    Cancelar
                </a>
                <button type="submit" name="guardar_asignacion" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg flex items-center">
                    <i class="fas fa-save mr-2"></i>
                    Guardar Asignaciones
                </button>
            </div>
        </form>
    </div>

    <!-- Resumen de asignaciones -->
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 class="font-semibold text-blue-800 mb-2 flex items-center">
            <i class="fas fa-info-circle mr-2"></i>
            Información importante
        </h3>
        <ul class="text-sm text-blue-700 space-y-1">
            <li>• <strong>Administradores</strong> pueden atender cualquier servicio sin necesidad de asignación.</li>
            <li>• Los usuarios <strong>solo podrán atender</strong> los servicios que tengan asignados.</li>
            <li>• El <strong>nivel de experiencia</strong> ayuda a filtrar según la complejidad del servicio.</li>
            <li>• Un usuario puede tener <strong>múltiples servicios</strong> asignados.</li>
            <li>• Al crear una reserva, solo se mostrarán los empleados que pueden atender el servicio seleccionado.</li>
        </ul>
    </div>
</div>

<script>
function toggleServicio(servicioId, checked) {
    const card = document.getElementById('card_' + servicioId);
    const nivelSelect = document.getElementById('nivel_' + servicioId);
    
    if (checked) {
        card.classList.add('asignado');
        nivelSelect.disabled = false;
    } else {
        card.classList.remove('asignado');
        nivelSelect.disabled = true;
        nivelSelect.value = 'intermedio';
    }
}

function seleccionarTodos() {
    document.querySelectorAll('.servicio-checkbox').forEach(checkbox => {
        if (!checkbox.checked) {
            checkbox.checked = true;
            toggleServicio(checkbox.dataset.id, true);
        }
    });
}

function deseleccionarTodos() {
    document.querySelectorAll('.servicio-checkbox').forEach(checkbox => {
        if (checkbox.checked) {
            checkbox.checked = false;
            toggleServicio(checkbox.dataset.id, false);
        }
    });
}

// Confirmar antes de guardar
document.getElementById('formAsignacion').addEventListener('submit', function(e) {
    const seleccionados = document.querySelectorAll('.servicio-checkbox:checked').length;
    
    if (seleccionados === 0) {
        if (!confirm('No has seleccionado ningún servicio. ¿Continuar de todas formas?')) {
            e.preventDefault();
            return;
        }
    } else {
        if (!confirm('¿Guardar ' + seleccionados + ' servicio(s) para este usuario?')) {
            e.preventDefault();
            return;
        }
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>