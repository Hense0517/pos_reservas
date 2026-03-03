<?php
/**
 * ============================================
 * ARCHIVO: roles.php
 * UBICACIÓN: /modules/usuarios/roles.php
 * PROPÓSITO: Gestión de roles del sistema
 * ============================================
 */

session_start();

require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

// Verificar permisos (solo admin puede gestionar roles)
$es_admin = ($_SESSION['usuario_rol'] == 'admin');

if (!$es_admin) {
    $_SESSION['error'] = "No tienes permisos para acceder a esta sección";
    header("Location: index.php");
    exit();
}

$database = Database::getInstance();
$db = $database->getConnection();

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'crear':
                $nombre = trim($_POST['nombre'] ?? '');
                $descripcion = trim($_POST['descripcion'] ?? '');
                $categoria = trim($_POST['categoria'] ?? 'general');
                $nivel = intval($_POST['nivel'] ?? 0);
                $color = trim($_POST['color'] ?? '#6b7280');
                $icono = trim($_POST['icono'] ?? 'fas fa-user-tag');
                
                if (!empty($nombre)) {
                    $query = "INSERT INTO roles (nombre, descripcion, categoria, nivel, color, icono, activo) 
                              VALUES (?, ?, ?, ?, ?, ?, 1)";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$nombre, $descripcion, $categoria, $nivel, $color, $icono]);
                    $_SESSION['success'] = "Rol '$nombre' creado correctamente";
                }
                break;
                
            case 'editar':
                $id = intval($_POST['id'] ?? 0);
                $nombre = trim($_POST['nombre'] ?? '');
                $descripcion = trim($_POST['descripcion'] ?? '');
                $categoria = trim($_POST['categoria'] ?? 'general');
                $nivel = intval($_POST['nivel'] ?? 0);
                $color = trim($_POST['color'] ?? '#6b7280');
                $icono = trim($_POST['icono'] ?? 'fas fa-user-tag');
                $activo = isset($_POST['activo']) ? 1 : 0;
                
                if ($id > 0 && !empty($nombre)) {
                    $query = "UPDATE roles SET 
                              nombre = ?, 
                              descripcion = ?, 
                              categoria = ?, 
                              nivel = ?, 
                              color = ?, 
                              icono = ?, 
                              activo = ?,
                              updated_at = NOW() 
                              WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$nombre, $descripcion, $categoria, $nivel, $color, $icono, $activo, $id]);
                    $_SESSION['success'] = "Rol actualizado correctamente";
                }
                break;
                
            case 'eliminar':
                $id = intval($_POST['id'] ?? 0);
                
                // Verificar si hay usuarios con este rol
                $check = "SELECT COUNT(*) as total FROM usuarios_roles WHERE rol_id = ?";
                $stmt = $db->prepare($check);
                $stmt->execute([$id]);
                $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
                
                if ($total > 0) {
                    $_SESSION['error'] = "No se puede eliminar el rol porque hay $total usuario(s) asignados";
                } else {
                    $query = "DELETE FROM roles WHERE id = ?";
                    $stmt = $db->prepare($query);
                    $stmt->execute([$id]);
                    $_SESSION['success'] = "Rol eliminado correctamente";
                }
                break;
        }
        header("Location: roles.php");
        exit();
    }
}

// Obtener todos los roles
$query = "SELECT * FROM roles ORDER BY nivel DESC, nombre ASC";
$stmt = $db->prepare($query);
$stmt->execute();
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar roles por categoría
$roles_por_categoria = [];
foreach ($roles as $rol) {
    $categoria = $rol['categoria'] ?? 'general';
    if (!isset($roles_por_categoria[$categoria])) {
        $roles_por_categoria[$categoria] = [];
    }
    $roles_por_categoria[$categoria][] = $rol;
}

// Categorías disponibles
$categorias = ['administracion', 'supervision', 'atencion', 'ventas', 'servicios', 'especializado', 'formacion', 'operativo', 'general'];

$page_title = "Gestión de Roles - " . ($config['nombre_negocio'] ?? 'Sistema POS');
include __DIR__ . '/../../includes/header.php';
?>

<style>
.role-card {
    transition: all 0.3s ease;
    border-left: 4px solid;
}

.role-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
}

.color-preview {
    width: 30px;
    height: 30px;
    border-radius: 50%;
    display: inline-block;
    border: 2px solid #fff;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.modal {
    transition: opacity 0.3s ease;
}

.badge-role {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    color: white;
}
</style>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-user-tag text-blue-600 mr-2"></i>
                Gestión de Roles
            </h1>
            <p class="text-gray-600 mt-1">Administra los roles y permisos del sistema</p>
        </div>
        <div class="flex space-x-3 mt-4 md:mt-0">
            <button onclick="abrirModalCrear()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-plus mr-2"></i>
                Nuevo Rol
            </button>
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver a Usuarios
            </a>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4 flex items-center justify-between">
            <span><?php echo $_SESSION['success']; ?></span>
            <button onclick="this.parentElement.remove()" class="text-green-700 hover:text-green-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 flex items-center justify-between">
            <span><?php echo $_SESSION['error']; ?></span>
            <button onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-500">Total Roles</p>
            <p class="text-2xl font-bold"><?php echo count($roles); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-500">Categorías</p>
            <p class="text-2xl font-bold"><?php echo count($roles_por_categoria); ?></p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-500">Nivel Promedio</p>
            <p class="text-2xl font-bold">
                <?php 
                $suma = array_sum(array_column($roles, 'nivel'));
                echo round($suma / max(1, count($roles)), 1);
                ?>
            </p>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <p class="text-sm text-gray-500">Roles Activos</p>
            <p class="text-2xl font-bold text-green-600">
                <?php echo count(array_filter($roles, fn($r) => $r['activo'])); ?>
            </p>
        </div>
    </div>

    <!-- Roles por categoría -->
    <?php foreach ($roles_por_categoria as $categoria => $roles_cat): ?>
    <div class="mb-8">
        <h2 class="text-xl font-semibold text-gray-800 mb-4 capitalize flex items-center">
            <i class="fas fa-folder-open text-indigo-500 mr-2"></i>
            <?php echo $categoria; ?>
            <span class="ml-2 text-sm bg-gray-200 text-gray-700 px-2 py-1 rounded-full">
                <?php echo count($roles_cat); ?> roles
            </span>
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($roles_cat as $rol): ?>
            <div class="role-card bg-white rounded-lg shadow p-4" style="border-left-color: <?php echo $rol['color']; ?>">
                <div class="flex justify-between items-start">
                    <div class="flex items-center gap-3">
                        <div class="color-preview" style="background-color: <?php echo $rol['color']; ?>"></div>
                        <div>
                            <h3 class="font-semibold text-gray-800 flex items-center gap-2">
                                <i class="<?php echo $rol['icono']; ?>" style="color: <?php echo $rol['color']; ?>"></i>
                                <?php echo ucfirst($rol['nombre']); ?>
                            </h3>
                            <p class="text-sm text-gray-500">Nivel: <?php echo $rol['nivel']; ?></p>
                        </div>
                    </div>
                    <span class="px-2 py-1 text-xs rounded-full <?php echo $rol['activo'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                        <?php echo $rol['activo'] ? 'Activo' : 'Inactivo'; ?>
                    </span>
                </div>
                
                <p class="text-sm text-gray-600 mt-2">
                    <?php echo $rol['descripcion'] ?: 'Sin descripción'; ?>
                </p>
                
                <div class="flex justify-end gap-2 mt-3 pt-2 border-t">
                    <button onclick="abrirModalEditar(<?php echo htmlspecialchars(json_encode($rol)); ?>)" 
                            class="text-indigo-600 hover:text-indigo-900 p-1" title="Editar">
                        <i class="fas fa-edit"></i>
                    </button>
                    <?php if ($rol['nombre'] !== 'admin'): ?>
                    <form method="POST" class="inline" onsubmit="return confirm('¿Eliminar este rol?')">
                        <input type="hidden" name="action" value="eliminar">
                        <input type="hidden" name="id" value="<?php echo $rol['id']; ?>">
                        <button type="submit" class="text-red-600 hover:text-red-900 p-1" title="Eliminar">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Modal Crear/Editar Rol -->
    <div id="rolModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-lg bg-white">
            <div class="flex justify-between items-center pb-3 mb-3 border-b">
                <h3 class="text-lg font-semibold text-gray-900" id="modalTitle">Nuevo Rol</h3>
                <button onclick="cerrarModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" id="rolForm">
                <input type="hidden" name="action" id="action" value="crear">
                <input type="hidden" name="id" id="rolId" value="">
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label>
                        <input type="text" name="nombre" id="rolNombre" required
                               class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500"
                               placeholder="ej: estilista_senior">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                        <textarea name="descripcion" id="rolDescripcion" rows="2"
                                  class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500"
                                  placeholder="Descripción del rol..."></textarea>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Categoría</label>
                            <select name="categoria" id="rolCategoria"
                                    class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                                <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo $cat; ?>"><?php echo ucfirst($cat); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nivel</label>
                            <input type="number" name="nivel" id="rolNivel" value="50" min="0" max="100"
                                   class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Color</label>
                            <input type="color" name="color" id="rolColor" value="#6b7280"
                                   class="w-full h-10 p-1 border rounded-lg">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Icono</label>
                            <select name="icono" id="rolIcono"
                                    class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-indigo-500">
                                <option value="fas fa-user-tag">Usuario</option>
                                <option value="fas fa-crown">Admin</option>
                                <option value="fas fa-chart-line">Gerente</option>
                                <option value="fas fa-cut">Barbero</option>
                                <option value="fas fa-spa">Spa</option>
                                <option value="fas fa-hand-sparkles">Manicure</option>
                                <option value="fas fa-user-tie">Estilista</option>
                                <option value="fas fa-phone-alt">Recepcionista</option>
                                <option value="fas fa-cash-register">Cajero</option>
                                <option value="fas fa-tag">Vendedor</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" name="activo" id="rolActivo" value="1" class="mr-2" checked>
                        <label for="rolActivo" class="text-sm text-gray-700">Rol activo</label>
                    </div>
                </div>
                
                <div class="flex justify-end gap-2 mt-6 pt-3 border-t">
                    <button type="button" onclick="cerrarModal()" 
                            class="bg-gray-500 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                        Cancelar
                    </button>
                    <button type="submit" 
                            class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function abrirModalCrear() {
    document.getElementById('modalTitle').innerText = 'Nuevo Rol';
    document.getElementById('action').value = 'crear';
    document.getElementById('rolId').value = '';
    document.getElementById('rolNombre').value = '';
    document.getElementById('rolDescripcion').value = '';
    document.getElementById('rolCategoria').value = 'general';
    document.getElementById('rolNivel').value = '50';
    document.getElementById('rolColor').value = '#6b7280';
    document.getElementById('rolIcono').value = 'fas fa-user-tag';
    document.getElementById('rolActivo').checked = true;
    
    document.getElementById('rolModal').classList.remove('hidden');
}

function abrirModalEditar(rol) {
    document.getElementById('modalTitle').innerText = 'Editar Rol';
    document.getElementById('action').value = 'editar';
    document.getElementById('rolId').value = rol.id;
    document.getElementById('rolNombre').value = rol.nombre;
    document.getElementById('rolDescripcion').value = rol.descripcion || '';
    document.getElementById('rolCategoria').value = rol.categoria || 'general';
    document.getElementById('rolNivel').value = rol.nivel || 0;
    document.getElementById('rolColor').value = rol.color || '#6b7280';
    document.getElementById('rolIcono').value = rol.icono || 'fas fa-user-tag';
    document.getElementById('rolActivo').checked = rol.activo == 1;
    
    document.getElementById('rolModal').classList.remove('hidden');
}

function cerrarModal() {
    document.getElementById('rolModal').classList.add('hidden');
}

// Cerrar modal con ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModal();
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>