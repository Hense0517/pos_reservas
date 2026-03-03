<?php
/**
 * ============================================
 * ARCHIVO: index.php
 * UBICACIÓN: /modules/usuarios/index.php
 * PROPÓSITO: Gestión de usuarios del sistema con roles múltiples y asignación de servicios
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

// Verificar permisos (solo admin puede gestionar usuarios)
$es_admin = ($_SESSION['usuario_rol'] == 'admin');

if (!$es_admin) {
    $_SESSION['error'] = "No tienes permisos para acceder a esta sección";
    header("Location: " . BASE_URL . "index.php");
    exit();
}

// Obtener conexión a base de datos
$database = Database::getInstance();
$db = $database->getConnection();

// Obtener filtros de búsqueda
$busqueda = $_GET['busqueda'] ?? '';
$filtro_rol = $_GET['rol'] ?? '';
$filtro_estado = $_GET['estado'] ?? '';

// Construir consulta con filtros
$sql = "SELECT * FROM usuarios WHERE 1=1";
$params = [];

if (!empty($busqueda)) {
    $sql .= " AND (username LIKE :busqueda OR nombre LIKE :busqueda OR email LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}

if (!empty($filtro_rol)) {
    $sql .= " AND id IN (SELECT usuario_id FROM usuarios_roles WHERE rol_id = :rol_id)";
    $params[':rol_id'] = $filtro_rol;
}

if ($filtro_estado !== '') {
    $activo = ($filtro_estado == 'activo') ? 1 : 0;
    $sql .= " AND activo = :activo";
    $params[':activo'] = $activo;
}

$sql .= " ORDER BY id DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener todos los roles para el filtro
$roles_query = "SELECT * FROM roles WHERE activo = 1 ORDER BY nombre";
$roles_stmt = $db->prepare($roles_query);
$roles_stmt->execute();
$todos_roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas
$total_usuarios = count($usuarios);
$activos = 0;
$inactivos = 0;
$total_servicios_asignados = 0;

foreach ($usuarios as $usuario) {
    if ($usuario['activo']) {
        $activos++;
    } else {
        $inactivos++;
    }
    
    // Contar servicios asignados para estadísticas
    $count_query = "SELECT COUNT(*) as total FROM usuarios_servicios WHERE usuario_id = ?";
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute([$usuario['id']]);
    $total_servicios_asignados += $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
}

// Obtener información del usuario actual
$user_id = $_SESSION['usuario_id'];
$user_query = "SELECT * FROM usuarios WHERE id = ?";
$user_stmt = $db->prepare($user_query);
$user_stmt->execute([$user_id]);
$user_info = $user_stmt->fetch(PDO::FETCH_ASSOC);

$page_title = "Gestión de Usuarios - " . ($config['nombre_negocio'] ?? 'Sistema POS');
include __DIR__ . '/../../includes/header.php';
?>

<style>
.stat-card {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    background: white;
    border-radius: 12px;
    padding: 20px;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
}

.user-row {
    transition: background-color 0.2s ease;
}

.user-row:hover {
    background-color: #f8fafc;
}

.badge {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.9rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.3px;
}

.rol-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 500;
    margin: 0.15rem;
    border: 1px solid;
    transition: all 0.2s ease;
}

.rol-badge:hover {
    transform: scale(1.05);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.badge-active {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    border: none;
}

.badge-inactive {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    border: none;
}

.action-btn {
    padding: 0.6rem;
    border-radius: 0.5rem;
    transition: all 0.2s ease;
    background: white;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 35px;
    height: 35px;
}

.action-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

.action-btn i {
    font-size: 1rem;
}

.filtros-container {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    margin-bottom: 25px;
}

.table-container {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.table-header {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border-bottom: 2px solid #e2e8f0;
}

.pagination-info {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 15px 20px;
    font-size: 0.9rem;
    color: #64748b;
}

.avatar-circle {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    font-size: 1.2rem;
    color: white;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    box-shadow: 0 4px 6px -1px rgba(102, 126, 234, 0.4);
}

.servicios-count {
    background: #e0f2fe;
    color: #0369a1;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: 4px;
}

.btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 500;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.3);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(37, 99, 235, 0.4);
}

.btn-secondary {
    background: #64748b;
    color: white;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 500;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-secondary:hover {
    background: #475569;
    transform: translateY(-2px);
}

.btn-purple {
    background: linear-gradient(135deg, #a855f7 0%, #9333ea 100%);
    color: white;
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 500;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    box-shadow: 0 4px 6px -1px rgba(147, 51, 234, 0.3);
}

.btn-purple:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 15px -3px rgba(147, 51, 234, 0.4);
}

.fade-in {
    animation: fadeIn 0.5s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera con título y botones de acción -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
        <div class="mb-4 md:mb-0">
            <h1 class="text-4xl font-bold text-gray-900 mb-2">
                <i class="fas fa-users-cog text-blue-600 mr-3"></i>
                Gestión de Usuarios
            </h1>
            <p class="text-gray-600 text-lg">Administra los usuarios, roles y servicios del sistema</p>
        </div>
        
        <div class="flex flex-wrap gap-3">
            <a href="roles.php" class="btn-purple">
                <i class="fas fa-user-tag"></i>
                Gestionar Roles
            </a>
            <a href="crear.php" class="btn-primary">
                <i class="fas fa-user-plus"></i>
                Nuevo Usuario
            </a>
            <a href="<?php echo BASE_URL; ?>index.php" class="btn-secondary">
                <i class="fas fa-home"></i>
                Inicio
            </a>
        </div>
    </div>

    <!-- Mensajes de sesión -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-r-lg shadow-md fade-in flex justify-between items-center">
            <div class="flex items-center">
                <i class="fas fa-check-circle text-green-500 text-xl mr-3"></i>
                <span><?php echo $_SESSION['success']; ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-green-700 hover:text-green-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-r-lg shadow-md fade-in flex justify-between items-center">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle text-red-500 text-xl mr-3"></i>
                <span><?php echo $_SESSION['error']; ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Tarjetas de estadísticas mejoradas -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-5 mb-8">
        <div class="stat-card border-l-4 border-blue-500">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-blue-100 text-blue-600 mr-4">
                    <i class="fas fa-users text-2xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500 font-medium">Total Usuarios</p>
                    <p class="text-3xl font-bold text-gray-800"><?php echo $total_usuarios; ?></p>
                </div>
            </div>
        </div>
        
        <div class="stat-card border-l-4 border-green-500">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-green-100 text-green-600 mr-4">
                    <i class="fas fa-user-check text-2xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500 font-medium">Activos</p>
                    <p class="text-3xl font-bold text-green-600"><?php echo $activos; ?></p>
                </div>
            </div>
        </div>
        
        <div class="stat-card border-l-4 border-red-500">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-red-100 text-red-600 mr-4">
                    <i class="fas fa-user-slash text-2xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500 font-medium">Inactivos</p>
                    <p class="text-3xl font-bold text-red-600"><?php echo $inactivos; ?></p>
                </div>
            </div>
        </div>
        
        <div class="stat-card border-l-4 border-purple-500">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-purple-100 text-purple-600 mr-4">
                    <i class="fas fa-user-tag text-2xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500 font-medium">Roles</p>
                    <p class="text-3xl font-bold text-purple-600"><?php echo count($todos_roles); ?></p>
                </div>
            </div>
        </div>
        
        <div class="stat-card border-l-4 border-amber-500">
            <div class="flex items-center">
                <div class="p-3 rounded-full bg-amber-100 text-amber-600 mr-4">
                    <i class="fas fa-hand-holding-heart text-2xl"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-500 font-medium">Servicios Asignados</p>
                    <p class="text-3xl font-bold text-amber-600"><?php echo $total_servicios_asignados; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Filtros de búsqueda mejorados -->
    <div class="filtros-container">
        <div class="flex items-center mb-4">
            <i class="fas fa-filter text-blue-600 mr-2"></i>
            <h2 class="text-lg font-semibold text-gray-800">Filtros de Búsqueda</h2>
        </div>
        
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-search mr-1"></i>Buscar
                </label>
                <input type="text" name="busqueda" value="<?php echo htmlspecialchars($busqueda); ?>" 
                       placeholder="Usuario, nombre o email..."
                       class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-user-tag mr-1"></i>Rol
                </label>
                <select name="rol" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">Todos los roles</option>
                    <?php foreach ($todos_roles as $rol): ?>
                        <option value="<?php echo $rol['id']; ?>" <?php echo $filtro_rol == $rol['id'] ? 'selected' : ''; ?>>
                            <?php echo ucfirst($rol['nombre']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    <i class="fas fa-circle mr-1"></i>Estado
                </label>
                <select name="estado" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">Todos</option>
                    <option value="activo" <?php echo $filtro_estado == 'activo' ? 'selected' : ''; ?>>Activos</option>
                    <option value="inactivo" <?php echo $filtro_estado == 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                </select>
            </div>
            
            <div class="flex items-end space-x-2">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg flex-1 transition-all duration-200 font-medium">
                    <i class="fas fa-search mr-2"></i>
                    Filtrar
                </button>
                <a href="index.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-2.5 rounded-lg transition-all duration-200 font-medium">
                    <i class="fas fa-times"></i>
                </a>
            </div>
        </form>
    </div>

    <!-- Tabla de usuarios -->
    <div class="table-container">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="table-header">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Usuario</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Información</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Roles</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Estado</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Último Login</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if ($total_usuarios > 0): ?>
                        <?php foreach ($usuarios as $index => $usuario): 
                            $es_usuario_actual = ($usuario['id'] == $user_id);
                            
                            // Obtener roles del usuario
                            $roles_query = "SELECT r.* FROM roles r
                                          INNER JOIN usuarios_roles ur ON r.id = ur.rol_id
                                          WHERE ur.usuario_id = ? AND r.activo = 1
                                          ORDER BY r.nivel DESC";
                            $roles_stmt = $db->prepare($roles_query);
                            $roles_stmt->execute([$usuario['id']]);
                            $usuario_roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            // Contar servicios asignados
                            $servicios_count_query = "SELECT COUNT(*) as total FROM usuarios_servicios WHERE usuario_id = ?";
                            $servicios_count_stmt = $db->prepare($servicios_count_query);
                            $servicios_count_stmt->execute([$usuario['id']]);
                            $servicios_count = $servicios_count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
                            
                            $row_class = $index % 2 == 0 ? 'bg-white' : 'bg-gray-50';
                        ?>
                        <tr class="user-row <?php echo $row_class; ?>">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="avatar-circle mr-4">
                                        <?php echo strtoupper(substr($usuario['username'], 0, 2)); ?>
                                    </div>
                                    <div>
                                        <div class="text-sm font-semibold text-gray-900 flex items-center">
                                            <?php echo htmlspecialchars($usuario['username']); ?>
                                            <?php if ($es_usuario_actual): ?>
                                                <span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full font-medium">Tú</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            ID: <?php echo $usuario['id']; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($usuario['nombre']); ?></div>
                                <div class="text-xs text-gray-500 mt-1">
                                    <i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($usuario['email'] ?: 'Sin email'); ?>
                                </div>
                                <?php if ($usuario['telefono']): ?>
                                <div class="text-xs text-gray-500">
                                    <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($usuario['telefono']); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap gap-1 mb-1">
                                    <?php foreach ($usuario_roles as $rol): ?>
                                    <span class="rol-badge" style="background-color: <?php echo $rol['color']; ?>20; color: <?php echo $rol['color']; ?>; border-color: <?php echo $rol['color']; ?>40;">
                                        <i class="<?php echo $rol['icono']; ?> mr-1"></i>
                                        <?php echo ucfirst($rol['nombre']); ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                                
                                <!-- Contador de servicios asignados -->
                                <?php if ($servicios_count > 0): ?>
                                <div class="servicios-count">
                                    <i class="fas fa-hand-holding-heart"></i>
                                    <?php echo $servicios_count; ?> servicio(s) asignado(s)
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="badge <?php echo $usuario['activo'] ? 'badge-active' : 'badge-inactive'; ?>">
                                    <i class="fas fa-<?php echo $usuario['activo'] ? 'check-circle' : 'times-circle'; ?> mr-1"></i>
                                    <?php echo $usuario['activo'] ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                                <?php if ($usuario['last_login']): ?>
                                    <div class="font-medium"><?php echo date('d/m/Y', strtotime($usuario['last_login'])); ?></div>
                                    <div class="text-xs text-gray-400"><?php echo date('H:i', strtotime($usuario['last_login'])); ?></div>
                                <?php else: ?>
                                    <span class="text-gray-400 italic">Nunca</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex gap-2">
                                    <!-- Ver detalles -->
                                    <a href="ver.php?id=<?php echo $usuario['id']; ?>" 
                                       class="action-btn text-blue-600 hover:text-blue-800 hover:bg-blue-50"
                                       title="Ver detalles">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    
                                    <!-- Editar -->
                                    <a href="editar.php?id=<?php echo $usuario['id']; ?>" 
                                       class="action-btn text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50"
                                       title="Editar usuario">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    
                                    <!-- Gestionar permisos -->
                                    <a href="permisos.php?id=<?php echo $usuario['id']; ?>" 
                                       class="action-btn text-purple-600 hover:text-purple-800 hover:bg-purple-50"
                                       title="Gestionar permisos">
                                        <i class="fas fa-key"></i>
                                    </a>
                                    
                                    <!-- Asignar servicios (solo para no admins) -->
                                    <?php if ($usuario['rol'] != 'admin'): ?>
                                    <a href="asignar_servicios.php?id=<?php echo $usuario['id']; ?>" 
                                       class="action-btn text-green-600 hover:text-green-800 hover:bg-green-50"
                                       title="Asignar servicios que puede atender">
                                        <i class="fas fa-hand-holding-heart"></i>
                                    </a>
                                    <?php endif; ?>
                                    
                                    <!-- Activar/Desactivar (excepto usuario actual) -->
                                    <?php if (!$es_usuario_actual): ?>
                                        <?php if ($usuario['activo']): ?>
                                            <a href="toggle_usuario.php?id=<?php echo $usuario['id']; ?>&action=desactivar" 
                                               class="action-btn text-orange-600 hover:text-orange-800 hover:bg-orange-50"
                                               title="Desactivar usuario"
                                               onclick="return confirm('¿Estás seguro de desactivar a <?php echo addslashes($usuario['nombre']); ?>?\n\nEl usuario no podrá iniciar sesión.')">
                                                <i class="fas fa-user-slash"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="toggle_usuario.php?id=<?php echo $usuario['id']; ?>&action=activar" 
                                               class="action-btn text-green-600 hover:text-green-800 hover:bg-green-50"
                                               title="Activar usuario"
                                               onclick="return confirm('¿Activar a <?php echo addslashes($usuario['nombre']); ?>?')">
                                                <i class="fas fa-user-check"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <!-- Eliminar (solo admin) -->
                                        <a href="eliminar_usuario.php?id=<?php echo $usuario['id']; ?>" 
                                           class="action-btn text-red-600 hover:text-red-800 hover:bg-red-50"
                                           title="Eliminar usuario permanentemente"
                                           onclick="return confirm('⚠️ ¿ELIMINAR PERMANENTEMENTE a <?php echo addslashes($usuario['nombre']); ?>?\n\nEsta acción no se puede deshacer. Se eliminarán todos sus datos relacionados.')">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="px-6 py-16 text-center">
                                <div class="text-gray-500">
                                    <i class="fas fa-users-slash text-6xl mb-4 opacity-30"></i>
                                    <p class="text-xl font-medium text-gray-700">No hay usuarios registrados</p>
                                    <p class="text-gray-400 mt-2">Comienza creando el primer usuario del sistema</p>
                                    <a href="crear.php" class="inline-flex items-center mt-6 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition-all duration-200">
                                        <i class="fas fa-plus mr-2"></i>
                                        Crear Primer Usuario
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pie de tabla con información -->
        <?php if ($total_usuarios > 0): ?>
        <div class="pagination-info flex flex-col sm:flex-row justify-between items-center">
            <div class="flex items-center gap-4">
                <span>
                    <i class="fas fa-list mr-2 text-blue-500"></i>
                    Mostrando <strong><?php echo $total_usuarios; ?></strong> usuario(s)
                </span>
                <span class="hidden sm:inline text-gray-300">|</span>
                <span>
                    <i class="fas fa-user-check mr-2 text-green-500"></i>
                    <strong><?php echo $activos; ?></strong> activos
                </span>
                <span class="hidden sm:inline text-gray-300">|</span>
                <span>
                    <i class="fas fa-hand-holding-heart mr-2 text-amber-500"></i>
                    <strong><?php echo $total_servicios_asignados; ?></strong> servicios asignados
                </span>
            </div>
            <div class="mt-2 sm:mt-0 text-sm text-gray-500">
                <i class="fas fa-clock mr-1"></i>
                Última actualización: <?php echo date('d/m/Y H:i:s'); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Ayuda rápida -->
    <div class="mt-6 bg-blue-50 border border-blue-200 rounded-lg p-4">
        <div class="flex items-start gap-3">
            <i class="fas fa-lightbulb text-blue-600 text-xl mt-1"></i>
            <div>
                <h4 class="font-semibold text-blue-800 mb-2">Guía rápida de acciones:</h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm text-blue-700">
                    <div class="flex items-center gap-2">
                        <span class="bg-blue-600 text-white w-5 h-5 rounded-full flex items-center justify-center text-xs">1</span>
                        <span><strong>Roles:</strong> Define qué puede hacer el usuario en el sistema</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="bg-blue-600 text-white w-5 h-5 rounded-full flex items-center justify-center text-xs">2</span>
                        <span><strong>Permisos:</strong> Controla acceso a módulos específicos</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="bg-blue-600 text-white w-5 h-5 rounded-full flex items-center justify-center text-xs">3</span>
                        <span><strong>Servicios:</strong> Asigna qué servicios puede atender el usuario</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Filtros con tecla Enter
document.querySelector('input[name="busqueda"]')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        this.form.submit();
    }
});

// Auto-cerrar mensajes después de 5 segundos
setTimeout(function() {
    document.querySelectorAll('.bg-green-100, .bg-red-100').forEach(function(el) {
        if (el && el.parentNode) {
            el.style.transition = 'opacity 0.5s ease';
            el.style.opacity = '0';
            setTimeout(function() { 
                if (el && el.parentNode) el.remove(); 
            }, 500);
        }
    });
}, 5000);

// Tooltips personalizados (opcional)
document.querySelectorAll('[title]').forEach(element => {
    element.addEventListener('mouseenter', function(e) {
        // Puedes implementar tooltips personalizados aquí
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>