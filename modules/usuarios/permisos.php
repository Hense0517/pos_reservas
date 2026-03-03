<?php
/**
 * ============================================
 * ARCHIVO: permisos.php
 * UBICACIÓN: /modules/usuarios/permisos.php
 * PROPÓSITO: Gestionar permisos por usuario
 * 
 * FUNCIONALIDADES:
 * - Asignar permisos por módulo (leer, crear, editar, eliminar)
 * - Botones para seleccionar todos los permisos
 * - Agrupación por módulos
 * - Solo accesible para administradores
 * ============================================
 */

session_start();

// Incluir configuración principal
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permisos (solo admin puede gestionar permisos)
if ($_SESSION['usuario_rol'] != 'admin') {
    $_SESSION['error'] = "Solo administradores pueden gestionar permisos";
    header("Location: index.php");
    exit();
}

// Obtener conexión a base de datos
$database = Database::getInstance();
$db = $database->getConnection();

// Obtener ID del usuario
$usuario_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($usuario_id <= 0) {
    $_SESSION['error'] = "ID de usuario no válido";
    header("Location: index.php");
    exit();
}

// Obtener información del usuario
$query_usuario = "SELECT id, username, nombre, rol FROM usuarios WHERE id = ?";
$stmt_usuario = $db->prepare($query_usuario);
$stmt_usuario->execute([$usuario_id]);
$usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    $_SESSION['error'] = "Usuario no encontrado";
    header("Location: index.php");
    exit();
}

// Verificar si la tabla modulos_sistema existe
$tabla_modulos_existe = false;
try {
    $check = $db->query("SHOW TABLES LIKE 'modulos_sistema'");
    $tabla_modulos_existe = $check->fetch() ? true : false;
} catch (Exception $e) {
    $tabla_modulos_existe = false;
}

// Si no existe la tabla, usar módulos por defecto
if (!$tabla_modulos_existe) {
    // Crear tabla modulos_sistema si no existe
    $create_table = "CREATE TABLE IF NOT EXISTS modulos_sistema (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(50) NOT NULL,
        descripcion VARCHAR(100),
        icono VARCHAR(50) DEFAULT 'fa-cube',
        grupo VARCHAR(50) DEFAULT 'general',
        orden INT DEFAULT 0,
        activo TINYINT DEFAULT 1
    )";
    $db->exec($create_table);
    
    // Insertar módulos por defecto
    $modulos_default = [
        ['dashboard', 'Dashboard', 'fa-home', 'general', 1],
        ['ventas', 'Ventas', 'fa-shopping-cart', 'operaciones', 2],
        ['compras', 'Compras', 'fa-truck', 'operaciones', 3],
        ['inventario', 'Inventario', 'fa-boxes', 'operaciones', 4],
        ['clientes', 'Clientes', 'fa-users', 'operaciones', 5],
        ['proveedores', 'Proveedores', 'fa-truck-loading', 'operaciones', 6],
        ['gastos', 'Gastos', 'fa-money-bill-wave', 'financiero', 7],
        ['cuentas_por_cobrar', 'Cuentas por Cobrar', 'fa-hand-holding-usd', 'financiero', 8],
        ['reportes', 'Reportes', 'fa-chart-bar', 'reportes', 9],
        ['usuarios', 'Usuarios', 'fa-user-cog', 'administracion', 10],
        ['configuracion', 'Configuración', 'fa-cog', 'administracion', 11]
    ];
    
    $insert = $db->prepare("INSERT INTO modulos_sistema (nombre, descripcion, icono, grupo, orden) VALUES (?, ?, ?, ?, ?)");
    foreach ($modulos_default as $mod) {
        $insert->execute($mod);
    }
}

// Obtener módulos del sistema ordenados
$query_modulos = "SELECT * FROM modulos_sistema WHERE activo = 1 ORDER BY grupo, orden, nombre";
$stmt_modulos = $db->prepare($query_modulos);
$stmt_modulos->execute();
$modulos = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);

// Verificar si la tabla permisos existe
$tabla_permisos_existe = false;
try {
    $check = $db->query("SHOW TABLES LIKE 'permisos'");
    $tabla_permisos_existe = $check->fetch() ? true : false;
} catch (Exception $e) {
    $tabla_permisos_existe = false;
}

// Si no existe la tabla permisos, crearla
if (!$tabla_permisos_existe) {
    $create_table = "CREATE TABLE IF NOT EXISTS permisos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        modulo VARCHAR(50) NOT NULL,
        modulo_grupo VARCHAR(50),
        leer TINYINT DEFAULT 0,
        crear TINYINT DEFAULT 0,
        editar TINYINT DEFAULT 0,
        eliminar TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_usuario_modulo (usuario_id, modulo),
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
    )";
    $db->exec($create_table);
}

// Obtener permisos actuales del usuario
$query_permisos = "SELECT * FROM permisos WHERE usuario_id = ?";
$stmt_permisos = $db->prepare($query_permisos);
$stmt_permisos->execute([$usuario_id]);
$permisos_actuales = $stmt_permisos->fetchAll(PDO::FETCH_ASSOC);

// Crear array de permisos para fácil acceso
$permisos_usuario = [];
foreach ($permisos_actuales as $permiso) {
    $permisos_usuario[$permiso['modulo']] = [
        'leer' => $permiso['leer'],
        'crear' => $permiso['crear'],
        'editar' => $permiso['editar'],
        'eliminar' => $permiso['eliminar']
    ];
}

// Procesar formulario si se envió
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        // Eliminar permisos existentes del usuario
        $query_delete = "DELETE FROM permisos WHERE usuario_id = ?";
        $stmt_delete = $db->prepare($query_delete);
        $stmt_delete->execute([$usuario_id]);
        
        // Insertar nuevos permisos
        foreach ($modulos as $modulo) {
            $modulo_nombre = $modulo['nombre'];
            
            $leer = isset($_POST["permiso_{$modulo_nombre}_leer"]) ? 1 : 0;
            $crear = isset($_POST["permiso_{$modulo_nombre}_crear"]) ? 1 : 0;
            $editar = isset($_POST["permiso_{$modulo_nombre}_editar"]) ? 1 : 0;
            $eliminar = isset($_POST["permiso_{$modulo_nombre}_eliminar"]) ? 1 : 0;
            
            // Solo insertar si tiene al menos un permiso
            if ($leer || $crear || $editar || $eliminar) {
                // Verificar si la columna modulo_grupo existe
                $column_check = $db->query("SHOW COLUMNS FROM permisos LIKE 'modulo_grupo'");
                $has_grupo = $column_check->fetch() ? true : false;
                
                if ($has_grupo) {
                    $query_insert = "INSERT INTO permisos 
                                    (usuario_id, modulo, modulo_grupo, leer, crear, editar, eliminar) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?)";
                    
                    $stmt_insert = $db->prepare($query_insert);
                    $stmt_insert->execute([
                        $usuario_id,
                        $modulo_nombre,
                        $modulo['grupo'] ?? 'general',
                        $leer,
                        $crear,
                        $editar,
                        $eliminar
                    ]);
                } else {
                    $query_insert = "INSERT INTO permisos 
                                    (usuario_id, modulo, leer, crear, editar, eliminar) 
                                    VALUES (?, ?, ?, ?, ?, ?)";
                    
                    $stmt_insert = $db->prepare($query_insert);
                    $stmt_insert->execute([
                        $usuario_id,
                        $modulo_nombre,
                        $leer,
                        $crear,
                        $editar,
                        $eliminar
                    ]);
                }
            }
        }
        
        $db->commit();
        $_SESSION['success'] = "Permisos actualizados correctamente para " . $usuario['nombre'];
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("Error al actualizar permisos: " . $e->getMessage());
        $_SESSION['error'] = "Error al actualizar permisos: " . $e->getMessage();
    }
    
    header("Location: permisos.php?id=" . $usuario_id);
    exit;
}

// Agrupar módulos por grupo
$modulos_agrupados = [];
foreach ($modulos as $modulo) {
    $grupo = $modulo['grupo'] ?: 'general';
    if (!isset($modulos_agrupados[$grupo])) {
        $modulos_agrupados[$grupo] = [];
    }
    $modulos_agrupados[$grupo][] = $modulo;
}

$page_title = "Permisos de Usuario - " . ($config['nombre_negocio'] ?? 'Sistema POS');
include __DIR__ . '/../../includes/header.php';
?>

<style>
.grupo-modulos {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 16px;
}

.permiso-checkbox {
    width: 20px;
    height: 20px;
    cursor: pointer;
    margin-right: 8px;
}

.permiso-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    margin: 4px 0;
}

.all-permissions-btn {
    padding: 8px 16px;
    color: white;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    margin: 4px;
    transition: all 0.3s ease;
}

.all-permissions-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.modulo-card {
    transition: all 0.3s ease;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
    background-color: #f9fafb;
}

.modulo-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    border-color: #6366f1;
}

.modulo-header {
    display: flex;
    align-items: center;
    margin-bottom: 12px;
}

.modulo-icon {
    background-color: #e0e7ff;
    padding: 10px;
    border-radius: 8px;
    margin-right: 12px;
}

.permisos-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 8px;
}

@media (min-width: 768px) {
    .permisos-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

/* Estilos para checkboxes personalizados */
input[type="checkbox"]:checked {
    background-color: #4f46e5;
    border-color: #4f46e5;
}

.section-title {
    color: #374151;
    font-weight: 600;
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 2px solid #e5e7eb;
}

.card-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}
</style>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-user-shield text-purple-600 mr-2"></i>
                Gestión de Permisos
            </h1>
            <p class="text-gray-600 mt-1">
                Usuario: <span class="font-semibold text-indigo-600"><?php echo htmlspecialchars($usuario['nombre']); ?></span>
                (<?php echo htmlspecialchars($usuario['username']); ?>)
            </p>
        </div>
        <div class="flex space-x-3 mt-4 md:mt-0">
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver
            </a>
            <a href="editar.php?id=<?php echo $usuario_id; ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-edit mr-2"></i>
                Editar Usuario
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4 flex items-center justify-between fade-in">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 mr-3"></i>
                <span><?php echo $_SESSION['success']; ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-green-700 hover:text-green-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 flex items-center justify-between fade-in">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                <span><?php echo $_SESSION['error']; ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Contenido principal -->
    <div class="card-container p-6">
        <!-- Header del formulario -->
        <div class="mb-6 p-4 bg-gradient-to-r from-blue-50 to-indigo-50 rounded-lg">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">
                        <i class="fas fa-key mr-2"></i>Asignar Permisos
                    </h2>
                    <p class="text-sm text-gray-600">
                        Selecciona los permisos para <span class="font-semibold text-indigo-600"><?php echo htmlspecialchars($usuario['nombre']); ?></span>
                    </p>
                </div>
                <div class="mt-3 sm:mt-0 flex flex-wrap gap-2">
                    <button type="button" onclick="seleccionarTodo('leer')" 
                            class="all-permissions-btn bg-blue-600 hover:bg-blue-700">
                        <i class="fas fa-eye mr-1"></i> Todo Lectura
                    </button>
                    <button type="button" onclick="seleccionarTodo('crear')" 
                            class="all-permissions-btn bg-green-600 hover:bg-green-700">
                        <i class="fas fa-plus mr-1"></i> Todo Crear
                    </button>
                    <button type="button" onclick="seleccionarTodo('editar')" 
                            class="all-permissions-btn bg-yellow-600 hover:bg-yellow-700">
                        <i class="fas fa-edit mr-1"></i> Todo Editar
                    </button>
                    <button type="button" onclick="seleccionarTodo('eliminar')" 
                            class="all-permissions-btn bg-red-600 hover:bg-red-700">
                        <i class="fas fa-trash mr-1"></i> Todo Eliminar
                    </button>
                    <button type="button" onclick="deseleccionarTodo()" 
                            class="all-permissions-btn bg-gray-600 hover:bg-gray-700">
                        <i class="fas fa-times mr-1"></i> Limpiar Todo
                    </button>
                </div>
            </div>
        </div>

        <!-- Formulario de permisos -->
        <form method="POST" id="formPermisos">
            <input type="hidden" name="usuario_id" value="<?php echo $usuario_id; ?>">
            
            <?php foreach ($modulos_agrupados as $grupo => $modulos_grupo): 
                $grupo_nombre = ucfirst($grupo);
            ?>
            <div class="mb-8">
                <h3 class="section-title">
                    <i class="fas fa-folder-open mr-2 text-indigo-500"></i>
                    <?php echo $grupo_nombre; ?>
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($modulos_grupo as $modulo): 
                        $modulo_nombre = $modulo['nombre'];
                        $permiso_modulo = $permisos_usuario[$modulo_nombre] ?? ['leer' => 0, 'crear' => 0, 'editar' => 0, 'eliminar' => 0];
                    ?>
                    <div class="modulo-card">
                        <div class="modulo-header">
                            <div class="modulo-icon">
                                <i class="fas <?php echo htmlspecialchars($modulo['icono'] ?? 'fa-cube'); ?> text-indigo-600 text-lg"></i>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-semibold text-gray-800">
                                    <?php echo htmlspecialchars($modulo['descripcion'] ?? $modulo['nombre']); ?>
                                </h4>
                                <p class="text-xs text-gray-500">
                                    <i class="fas fa-hashtag mr-1"></i><?php echo htmlspecialchars($modulo_nombre); ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="permisos-grid">
                            <!-- Leer -->
                            <label class="permiso-label hover:bg-blue-50 p-2 rounded">
                                <input type="checkbox" 
                                       class="permiso-checkbox rounded text-blue-600 focus:ring-blue-500"
                                       name="permiso_<?php echo $modulo_nombre; ?>_leer"
                                       value="1"
                                       <?php echo $permiso_modulo['leer'] ? 'checked' : ''; ?>>
                                <span class="text-sm text-gray-700">
                                    <i class="fas fa-eye text-blue-500 mr-1"></i>Ver
                                </span>
                            </label>
                            
                            <!-- Crear -->
                            <label class="permiso-label hover:bg-green-50 p-2 rounded">
                                <input type="checkbox" 
                                       class="permiso-checkbox rounded text-green-600 focus:ring-green-500"
                                       name="permiso_<?php echo $modulo_nombre; ?>_crear"
                                       value="1"
                                       <?php echo $permiso_modulo['crear'] ? 'checked' : ''; ?>>
                                <span class="text-sm text-gray-700">
                                    <i class="fas fa-plus text-green-500 mr-1"></i>Crear
                                </span>
                            </label>
                            
                            <!-- Editar -->
                            <label class="permiso-label hover:bg-yellow-50 p-2 rounded">
                                <input type="checkbox" 
                                       class="permiso-checkbox rounded text-yellow-600 focus:ring-yellow-500"
                                       name="permiso_<?php echo $modulo_nombre; ?>_editar"
                                       value="1"
                                       <?php echo $permiso_modulo['editar'] ? 'checked' : ''; ?>>
                                <span class="text-sm text-gray-700">
                                    <i class="fas fa-edit text-yellow-500 mr-1"></i>Editar
                                </span>
                            </label>
                            
                            <!-- Eliminar -->
                            <label class="permiso-label hover:bg-red-50 p-2 rounded">
                                <input type="checkbox" 
                                       class="permiso-checkbox rounded text-red-600 focus:ring-red-500"
                                       name="permiso_<?php echo $modulo_nombre; ?>_eliminar"
                                       value="1"
                                       <?php echo $permiso_modulo['eliminar'] ? 'checked' : ''; ?>>
                                <span class="text-sm text-gray-700">
                                    <i class="fas fa-trash text-red-500 mr-1"></i>Eliminar
                                </span>
                            </label>
                        </div>
                        
                        <!-- Botón rápido para seleccionar todo en este módulo -->
                        <div class="mt-3 pt-3 border-t border-gray-200">
                            <button type="button" 
                                    onclick="toggleModulo('<?php echo $modulo_nombre; ?>')"
                                    class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                                <i class="fas fa-check-double mr-1"></i>
                                <span id="toggle-text-<?php echo $modulo_nombre; ?>">
                                    <?php echo ($permiso_modulo['leer'] && $permiso_modulo['crear'] && $permiso_modulo['editar'] && $permiso_modulo['eliminar']) ? 'Quitar todos' : 'Seleccionar todos'; ?>
                                </span>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Botones de acción -->
            <div class="flex justify-end space-x-3 pt-8 border-t border-gray-200">
                <a href="index.php" 
                   class="px-6 py-3 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">
                    <i class="fas fa-times mr-2"></i>Cancelar
                </a>
                <button type="submit" 
                        class="px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-lg text-sm font-medium hover:from-indigo-700 hover:to-purple-700 transition shadow-md hover:shadow-lg">
                    <i class="fas fa-save mr-2"></i>Guardar Permisos
                </button>
            </div>
        </form>
    </div>
    
    <!-- Información de ayuda -->
    <div class="mt-8 p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <div class="flex items-start">
            <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
            <div>
                <h4 class="font-medium text-blue-800 mb-1">¿Cómo funcionan los permisos?</h4>
                <ul class="text-sm text-blue-700 space-y-1">
                    <li><strong>Ver:</strong> Permite ver los registros del módulo</li>
                    <li><strong>Crear:</strong> Permite agregar nuevos registros</li>
                    <li><strong>Editar:</strong> Permite modificar registros existentes</li>
                    <li><strong>Eliminar:</strong> Permite eliminar registros (¡cuidado con este permiso!)</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
// Función para seleccionar todos los checkboxes de un tipo de permiso
function seleccionarTodo(tipo) {
    const checkboxes = document.querySelectorAll(`input[name*="_${tipo}"]`);
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    actualizarTextosToggle();
}

// Función para deseleccionar todos los checkboxes
function deseleccionarTodo() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    actualizarTextosToggle();
}

// Función para alternar todos los permisos de un módulo
function toggleModulo(moduloId) {
    const checkboxes = document.querySelectorAll(`input[name*="${moduloId}_"]`);
    const todosSeleccionados = verificarModuloCompleto(moduloId);
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = !todosSeleccionados;
    });
    
    // Actualizar el texto del botón
    const toggleText = document.getElementById(`toggle-text-${moduloId}`);
    if (toggleText) {
        toggleText.textContent = todosSeleccionados ? 'Seleccionar todos' : 'Quitar todos';
    }
}

// Función para verificar si todos los permisos de un módulo están seleccionados
function verificarModuloCompleto(moduloId) {
    const checkboxes = document.querySelectorAll(`input[name*="${moduloId}_"]`);
    let todosSeleccionados = true;
    checkboxes.forEach(checkbox => {
        if (!checkbox.checked) {
            todosSeleccionados = false;
        }
    });
    return todosSeleccionados;
}

// Función para actualizar todos los textos de los botones toggle
function actualizarTextosToggle() {
    const modulos = document.querySelectorAll('[id^="toggle-text-"]');
    modulos.forEach(element => {
        const moduloId = element.id.replace('toggle-text-', '');
        const todosSeleccionados = verificarModuloCompleto(moduloId);
        element.textContent = todosSeleccionados ? 'Quitar todos' : 'Seleccionar todos';
    });
}

// Agregar eventos a los checkboxes para actualizar textos
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('input[type="checkbox"]');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            // Obtener el nombre del módulo del checkbox
            const name = this.name;
            if (name) {
                const parts = name.split('_');
                if (parts.length >= 2) {
                    const moduloId = parts[1];
                    
                    // Actualizar el texto del toggle para este módulo
                    const toggleText = document.getElementById(`toggle-text-${moduloId}`);
                    if (toggleText) {
                        const todosSeleccionados = verificarModuloCompleto(moduloId);
                        toggleText.textContent = todosSeleccionados ? 'Quitar todos' : 'Seleccionar todos';
                    }
                }
            }
        });
    });
    
    // Inicializar textos de toggle
    actualizarTextosToggle();
});

// Confirmación antes de enviar
document.getElementById('formPermisos')?.addEventListener('submit', function(e) {
    if (!confirm('¿Guardar los permisos asignados?')) {
        e.preventDefault();
        return false;
    }
    return true;
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>