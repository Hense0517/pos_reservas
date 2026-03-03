<?php
/**
 * ============================================
 * ARCHIVO: ver.php
 * UBICACIÓN: /modules/usuarios/ver.php
 * PROPÓSITO: Ver perfil de usuario con roles y servicios asignados
 * 
 * FUNCIONALIDADES:
 * - Ver información personal del usuario
 * - Ver roles múltiples del usuario
 * - Ver servicios que puede atender con nivel de experiencia
 * - Ver permisos asignados (para admin)
 * - Tabs de información, roles, servicios, permisos y seguridad
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

// Obtener conexión
$database = Database::getInstance();
$db = $database->getConnection();

// Verificar si es admin
$es_admin = ($_SESSION['usuario_rol'] == 'admin');

// Obtener ID del usuario a ver
$usuario_id = isset($_GET['id']) ? intval($_GET['id']) : $_SESSION['usuario_id'];

// Si es admin puede ver cualquier perfil, si no, solo el suyo
if (!$es_admin && $usuario_id != $_SESSION['usuario_id']) {
    $_SESSION['error'] = "No tienes permisos para ver este perfil";
    header("Location: index.php");
    exit();
}

// Obtener información del usuario
$query = "SELECT * FROM usuarios WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->execute([$usuario_id]);
$user_info = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user_info) {
    $_SESSION['error'] = "Usuario no encontrado";
    header("Location: index.php");
    exit();
}

// Variables de control
$is_own_profile = ($usuario_id == $_SESSION['usuario_id']);
$editable = ($is_own_profile || $es_admin);

// Obtener roles del usuario
$roles_query = "SELECT r.* FROM roles r
                INNER JOIN usuarios_roles ur ON r.id = ur.rol_id
                WHERE ur.usuario_id = ? AND r.activo = 1
                ORDER BY r.nivel DESC";
$roles_stmt = $db->prepare($roles_query);
$roles_stmt->execute([$usuario_id]);
$usuario_roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener servicios que puede atender el usuario
$servicios_query = "SELECT s.*, us.nivel_experiencia, us.fecha_asignacion,
                           u_asignador.nombre as asignado_por_nombre
                    FROM servicios s
                    INNER JOIN usuarios_servicios us ON s.id = us.servicio_id
                    LEFT JOIN usuarios u_asignador ON us.asignado_por = u_asignador.id
                    WHERE us.usuario_id = ? AND s.activo = 1
                    ORDER BY 
                        CASE us.nivel_experiencia
                            WHEN 'experto' THEN 1
                            WHEN 'avanzado' THEN 2
                            WHEN 'intermedio' THEN 3
                            WHEN 'principiante' THEN 4
                        END,
                        s.nombre";
$servicios_stmt = $db->prepare($servicios_query);
$servicios_stmt->execute([$usuario_id]);
$usuario_servicios = $servicios_stmt->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas de servicios
$total_servicios = count($usuario_servicios);
$servicios_por_nivel = [
    'experto' => 0,
    'avanzado' => 0,
    'intermedio' => 0,
    'principiante' => 0
];

foreach ($usuario_servicios as $servicio) {
    $nivel = $servicio['nivel_experiencia'];
    if (isset($servicios_por_nivel[$nivel])) {
        $servicios_por_nivel[$nivel]++;
    }
}

// Obtener permisos del usuario (si es admin o es su propio perfil)
$permisos_usuario = [];
if ($es_admin) {
    $query_permisos = "SELECT * FROM permisos WHERE usuario_id = ?";
    $stmt_permisos = $db->prepare($query_permisos);
    $stmt_permisos->execute([$usuario_id]);
    $permisos_actuales = $stmt_permisos->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($permisos_actuales as $permiso) {
        $permisos_usuario[$permiso['modulo']] = [
            'leer' => $permiso['leer'],
            'crear' => $permiso['crear'],
            'editar' => $permiso['editar'],
            'eliminar' => $permiso['eliminar']
        ];
    }
}

// Verificar si la tabla modulos_sistema existe
$modulos = [];
try {
    $check = $db->query("SHOW TABLES LIKE 'modulos_sistema'");
    if ($check->fetch()) {
        $query_modulos = "SELECT * FROM modulos_sistema WHERE activo = 1 ORDER BY grupo, orden, nombre";
        $stmt_modulos = $db->prepare($query_modulos);
        $stmt_modulos->execute();
        $modulos = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Crear tabla y módulos por defecto
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
            ['configuracion', 'Configuración', 'fa-cog', 'administracion', 11],
            ['reservas', 'Reservas', 'fa-calendar-alt', 'operaciones', 12]
        ];
        
        $insert = $db->prepare("INSERT INTO modulos_sistema (nombre, descripcion, icono, grupo, orden) VALUES (?, ?, ?, ?, ?)");
        foreach ($modulos_default as $mod) {
            $insert->execute($mod);
        }
        
        // Obtener módulos
        $stmt_modulos = $db->prepare($query_modulos);
        $stmt_modulos->execute();
        $modulos = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("Error cargando módulos: " . $e->getMessage());
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

$page_title = "Perfil de Usuario - " . ($config['nombre_negocio'] ?? 'Sistema POS');
include __DIR__ . '/../../includes/header.php';
?>

<style>
.tab-button {
    transition: all 0.2s ease;
    cursor: pointer;
    padding: 1rem 1.5rem;
    font-weight: 500;
    border-bottom: 2px solid transparent;
}
.tab-button:hover {
    background-color: #f9fafb;
}
.tab-button.active {
    border-bottom-color: #3b82f6;
    color: #3b82f6;
    background-color: #eff6ff;
}
.tab-content {
    animation: fadeIn 0.3s ease;
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.module-item {
    transition: all 0.2s ease;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    padding: 1rem;
}
.module-item:hover {
    border-color: #6366f1;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}
.badge-permiso {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 600;
}
.badge-permiso.leer { background-color: #dbeafe; color: #1e40af; }
.badge-permiso.crear { background-color: #d1fae5; color: #065f46; }
.badge-permiso.editar { background-color: #fef3c7; color: #92400e; }
.badge-permiso.eliminar { background-color: #fee2e2; color: #991b1b; }

.rol-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1rem;
    border-radius: 9999px;
    font-size: 0.85rem;
    font-weight: 500;
    margin: 0.25rem;
    border: 1px solid;
    transition: all 0.2s ease;
}
.rol-badge:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
}

.servicio-card {
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    padding: 1rem;
    transition: all 0.2s ease;
    background: white;
}
.servicio-card:hover {
    border-color: #3b82f6;
    box-shadow: 0 4px 12px -2px rgba(59, 130, 246, 0.2);
    transform: translateY(-2px);
}

.nivel-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
}
.nivel-experto { background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%); color: #78350f; }
.nivel-avanzado { background: linear-gradient(135deg, #34d399 0%, #10b981 100%); color: #064e3b; }
.nivel-intermedio { background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%); color: #1e3a8a; }
.nivel-principiante { background: linear-gradient(135deg, #c084fc 0%, #a855f7 100%); color: #581c87; }

.stats-card {
    background: white;
    border-radius: 0.75rem;
    padding: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #e5e7eb;
}
</style>

<div class="max-w-7xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-user-circle text-blue-600 mr-2"></i>
                <?php echo $is_own_profile ? 'Mi Perfil' : 'Perfil de Usuario'; ?>
            </h1>
            <p class="text-gray-600 mt-1">
                <?php echo $is_own_profile ? 'Información de tu cuenta' : 'Información del usuario ' . htmlspecialchars($user_info['nombre']); ?>
            </p>
        </div>
        <div class="flex space-x-3 mt-4 md:mt-0">
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center transition-all">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver
            </a>
            <?php if ($editable): ?>
            <a href="editar.php?id=<?php echo $usuario_id; ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center transition-all">
                <i class="fas fa-edit mr-2"></i>
                Editar Perfil
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded mb-4 flex justify-between items-center">
            <span><i class="fas fa-check-circle mr-2"></i><?php echo $_SESSION['success']; ?></span>
            <button onclick="this.parentElement.remove()" class="text-green-700 hover:text-green-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded mb-4 flex justify-between items-center">
            <span><i class="fas fa-exclamation-circle mr-2"></i><?php echo $_SESSION['error']; ?></span>
            <button onclick="this.parentElement.remove()" class="text-red-700 hover:text-red-900">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Tarjetas de resumen -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="stats-card">
            <p class="text-sm text-gray-500">Rol Principal</p>
            <p class="text-xl font-bold capitalize flex items-center mt-1">
                <i class="fas fa-user-tag mr-2 text-blue-500"></i>
                <?php echo $user_info['rol']; ?>
            </p>
        </div>
        <div class="stats-card">
            <p class="text-sm text-gray-500">Roles Totales</p>
            <p class="text-xl font-bold flex items-center mt-1">
                <i class="fas fa-users mr-2 text-purple-500"></i>
                <?php echo count($usuario_roles); ?>
            </p>
        </div>
        <div class="stats-card">
            <p class="text-sm text-gray-500">Servicios Asignados</p>
            <p class="text-xl font-bold flex items-center mt-1">
                <i class="fas fa-hand-holding-heart mr-2 text-green-500"></i>
                <?php echo $total_servicios; ?>
            </p>
        </div>
        <div class="stats-card">
            <p class="text-sm text-gray-500">Estado</p>
            <p class="text-xl font-bold flex items-center mt-1">
                <?php if ($user_info['activo']): ?>
                    <span class="text-green-600"><i class="fas fa-check-circle mr-2"></i>Activo</span>
                <?php else: ?>
                    <span class="text-red-600"><i class="fas fa-times-circle mr-2"></i>Inactivo</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-hidden">
        <!-- Tabs -->
        <div class="border-b border-gray-200 bg-gray-50">
            <nav class="flex flex-wrap">
                <button id="tab-info" 
                        onclick="mostrarTab('info')"
                        class="tab-button active">
                    <i class="fas fa-user mr-2"></i>
                    Información Personal
                </button>
                
                <button id="tab-roles" 
                        onclick="mostrarTab('roles')"
                        class="tab-button">
                    <i class="fas fa-user-tag mr-2"></i>
                    Roles (<?php echo count($usuario_roles); ?>)
                </button>
                
                <button id="tab-servicios" 
                        onclick="mostrarTab('servicios')"
                        class="tab-button">
                    <i class="fas fa-hand-holding-heart mr-2"></i>
                    Servicios (<?php echo $total_servicios; ?>)
                </button>
                
                <?php if ($es_admin): ?>
                <button id="tab-permisos" 
                        onclick="mostrarTab('permisos')"
                        class="tab-button">
                    <i class="fas fa-user-shield mr-2"></i>
                    Permisos
                </button>
                <?php endif; ?>
                
                <?php if ($is_own_profile || $es_admin): ?>
                <button id="tab-seguridad" 
                        onclick="mostrarTab('seguridad')"
                        class="tab-button">
                    <i class="fas fa-lock mr-2"></i>
                    Seguridad
                </button>
                <?php endif; ?>
            </nav>
        </div>
        
        <!-- Contenido de las tabs -->
        <div class="p-6">
            <!-- Tab Información Personal -->
            <div id="tab-content-info" class="tab-content">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="col-span-2 md:col-span-1">
                        <div class="bg-gray-50 p-5 rounded-lg">
                            <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-id-card text-blue-600 mr-2"></i>
                                Datos Personales
                            </h3>
                            <dl class="space-y-3">
                                <div class="flex justify-between border-b border-gray-200 pb-2">
                                    <dt class="text-gray-600">Nombre Completo:</dt>
                                    <dd class="font-medium"><?php echo htmlspecialchars($user_info['nombre']); ?></dd>
                                </div>
                                <div class="flex justify-between border-b border-gray-200 pb-2">
                                    <dt class="text-gray-600">Email:</dt>
                                    <dd class="font-medium"><?php echo htmlspecialchars($user_info['email'] ?? 'No especificado'); ?></dd>
                                </div>
                                <div class="flex justify-between border-b border-gray-200 pb-2">
                                    <dt class="text-gray-600">Teléfono:</dt>
                                    <dd class="font-medium"><?php echo htmlspecialchars($user_info['telefono'] ?? 'No especificado'); ?></dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                    
                    <div class="col-span-2 md:col-span-1">
                        <div class="bg-gray-50 p-5 rounded-lg">
                            <h3 class="text-lg font-medium text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-cog text-blue-600 mr-2"></i>
                                Datos de la Cuenta
                            </h3>
                            <dl class="space-y-3">
                                <div class="flex justify-between border-b border-gray-200 pb-2">
                                    <dt class="text-gray-600">Usuario:</dt>
                                    <dd class="font-medium"><?php echo htmlspecialchars($user_info['username']); ?></dd>
                                </div>
                                <div class="flex justify-between border-b border-gray-200 pb-2">
                                    <dt class="text-gray-600">Fecha Registro:</dt>
                                    <dd class="font-medium"><?php echo date('d/m/Y H:i', strtotime($user_info['created_at'])); ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">Último Login:</dt>
                                    <dd class="font-medium"><?php echo $user_info['last_login'] ? date('d/m/Y H:i', strtotime($user_info['last_login'])) : 'Nunca'; ?></dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tab Roles -->
            <div id="tab-content-roles" class="tab-content hidden">
                <?php if (empty($usuario_roles)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-user-tag text-gray-300 text-6xl mb-4"></i>
                        <p class="text-gray-500 text-lg">El usuario no tiene roles asignados</p>
                        <?php if ($es_admin): ?>
                        <a href="editar.php?id=<?php echo $usuario_id; ?>" class="inline-block mt-4 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Asignar Roles
                        </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($usuario_roles as $rol): ?>
                        <div class="border rounded-lg p-4 hover:shadow-lg transition-all" style="border-left: 4px solid <?php echo $rol['color']; ?>">
                            <div class="flex items-start gap-3">
                                <div class="p-3 rounded-full" style="background-color: <?php echo $rol['color']; ?>20;">
                                    <i class="<?php echo $rol['icono']; ?>" style="color: <?php echo $rol['color']; ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <h3 class="font-semibold text-gray-800 text-lg"><?php echo ucfirst($rol['nombre']); ?></h3>
                                    <p class="text-sm text-gray-600 mt-1"><?php echo $rol['descripcion'] ?: 'Sin descripción'; ?></p>
                                    <div class="mt-2 flex items-center gap-2">
                                        <span class="text-xs text-gray-500">
                                            <i class="fas fa-layer-group mr-1"></i>Nivel: <?php echo $rol['nivel']; ?>
                                        </span>
                                        <span class="text-xs text-gray-500">
                                            <i class="fas fa-folder mr-1"></i><?php echo ucfirst($rol['categoria'] ?? 'general'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Tab Servicios -->
            <div id="tab-content-servicios" class="tab-content hidden">
                <?php if (empty($usuario_servicios)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-hand-holding-heart text-gray-300 text-6xl mb-4"></i>
                        <p class="text-gray-500 text-lg">El usuario no tiene servicios asignados</p>
                        <?php if ($es_admin && $user_info['rol'] != 'admin'): ?>
                        <a href="asignar_servicios.php?id=<?php echo $usuario_id; ?>" class="inline-block mt-4 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Asignar Servicios
                        </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Estadísticas de servicios -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                        <div class="bg-gray-50 p-3 rounded-lg text-center">
                            <span class="text-sm text-gray-500">Total Servicios</span>
                            <p class="text-2xl font-bold"><?php echo $total_servicios; ?></p>
                        </div>
                        <div class="bg-purple-50 p-3 rounded-lg text-center">
                            <span class="text-sm text-purple-600">Experto</span>
                            <p class="text-2xl font-bold text-purple-700"><?php echo $servicios_por_nivel['experto']; ?></p>
                        </div>
                        <div class="bg-green-50 p-3 rounded-lg text-center">
                            <span class="text-sm text-green-600">Avanzado</span>
                            <p class="text-2xl font-bold text-green-700"><?php echo $servicios_por_nivel['avanzado']; ?></p>
                        </div>
                        <div class="bg-blue-50 p-3 rounded-lg text-center">
                            <span class="text-sm text-blue-600">Intermedio</span>
                            <p class="text-2xl font-bold text-blue-700"><?php echo $servicios_por_nivel['intermedio']; ?></p>
                        </div>
                    </div>
                    
                    <!-- Lista de servicios -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php foreach ($usuario_servicios as $servicio): ?>
                        <div class="servicio-card">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <h3 class="font-semibold text-gray-800 text-lg"><?php echo htmlspecialchars($servicio['nombre']); ?></h3>
                                        <span class="nivel-badge nivel-<?php echo $servicio['nivel_experiencia']; ?>">
                                            <i class="fas fa-star mr-1"></i>
                                            <?php echo ucfirst($servicio['nivel_experiencia']); ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($servicio['descripcion'] ?: 'Sin descripción'); ?></p>
                                    
                                    <div class="flex items-center gap-4 mt-3">
                                        <span class="text-sm font-semibold text-blue-600">
                                            $<?php echo number_format($servicio['precio'], 2); ?>
                                        </span>
                                        <?php if ($servicio['precio_variable']): ?>
                                            <span class="text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded-full">
                                                <i class="fas fa-random mr-1"></i>Variable
                                            </span>
                                        <?php endif; ?>
                                        <span class="text-xs text-gray-500">
                                            <i class="far fa-clock mr-1"></i><?php echo $servicio['duracion_minutos']; ?> min
                                        </span>
                                    </div>
                                    
                                    <?php if ($servicio['asignado_por_nombre']): ?>
                                    <p class="text-xs text-gray-400 mt-2">
                                        <i class="fas fa-user-check mr-1"></i>
                                        Asignado por: <?php echo htmlspecialchars($servicio['asignado_por_nombre']); ?>
                                        el <?php echo date('d/m/Y', strtotime($servicio['fecha_asignacion'])); ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Tab Permisos (solo para admin) -->
            <?php if ($es_admin): ?>
            <div id="tab-content-permisos" class="tab-content hidden">
                <?php if (empty($modulos)): ?>
                    <div class="text-center py-8">
                        <i class="fas fa-key text-gray-300 text-5xl mb-4"></i>
                        <p class="text-gray-500">No hay módulos configurados</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($modulos_agrupados as $grupo => $modulos_grupo): ?>
                    <div class="mb-8">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2 capitalize">
                            <i class="fas fa-folder-open text-indigo-500 mr-2"></i>
                            <?php echo $grupo; ?>
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($modulos_grupo as $modulo): 
                                $permiso = $permisos_usuario[$modulo['nombre']] ?? null;
                            ?>
                            <div class="module-item">
                                <div class="flex items-center mb-3">
                                    <div class="bg-indigo-100 p-2 rounded-lg mr-3">
                                        <i class="fas <?php echo $modulo['icono']; ?> text-indigo-600"></i>
                                    </div>
                                    <div>
                                        <h4 class="font-semibold text-gray-800"><?php echo $modulo['descripcion']; ?></h4>
                                        <p class="text-xs text-gray-500"><?php echo $modulo['nombre']; ?></p>
                                    </div>
                                </div>
                                
                                <div class="flex flex-wrap gap-1">
                                    <?php if ($permiso && $permiso['leer']): ?>
                                        <span class="badge-permiso leer"><i class="fas fa-eye mr-1"></i>Ver</span>
                                    <?php endif; ?>
                                    <?php if ($permiso && $permiso['crear']): ?>
                                        <span class="badge-permiso crear"><i class="fas fa-plus mr-1"></i>Crear</span>
                                    <?php endif; ?>
                                    <?php if ($permiso && $permiso['editar']): ?>
                                        <span class="badge-permiso editar"><i class="fas fa-edit mr-1"></i>Editar</span>
                                    <?php endif; ?>
                                    <?php if ($permiso && $permiso['eliminar']): ?>
                                        <span class="badge-permiso eliminar"><i class="fas fa-trash mr-1"></i>Eliminar</span>
                                    <?php endif; ?>
                                    <?php if (!$permiso): ?>
                                        <span class="text-xs text-gray-400 italic">Sin permisos</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- Tab Seguridad -->
            <div id="tab-content-seguridad" class="tab-content hidden">
                <div class="max-w-lg mx-auto">
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                        <div class="flex">
                            <i class="fas fa-exclamation-triangle text-yellow-500 mr-3 text-xl"></i>
                            <p class="text-sm text-yellow-700">
                                <?php if ($is_own_profile): ?>
                                    Cambia tu contraseña regularmente para mantener tu cuenta segura.
                                <?php else: ?>
                                    Estás cambiando la contraseña de otro usuario. El usuario será notificado del cambio.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    
                    <form action="cambiar_password.php" method="POST" class="space-y-4" onsubmit="return validarPassword()">
                        <input type="hidden" name="usuario_id" value="<?php echo $usuario_id; ?>">
                        
                        <?php if ($is_own_profile): ?>
                        <div>
                            <label for="password_actual" class="block text-sm font-medium text-gray-700 mb-1">
                                Contraseña Actual *
                            </label>
                            <input type="password" id="password_actual" name="password_actual" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <?php endif; ?>
                        
                        <div>
                            <label for="nueva_password" class="block text-sm font-medium text-gray-700 mb-1">
                                Nueva Contraseña *
                            </label>
                            <input type="password" id="nueva_password" name="nueva_password" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   minlength="6">
                            <p class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres</p>
                        </div>
                        
                        <div>
                            <label for="confirmar_password" class="block text-sm font-medium text-gray-700 mb-1">
                                Confirmar Contraseña *
                            </label>
                            <input type="password" id="confirmar_password" name="confirmar_password" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                   minlength="6">
                        </div>
                        
                        <div class="flex justify-end space-x-3 pt-4">
                            <button type="button" onclick="mostrarTab('info')" 
                                    class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                                Cancelar
                            </button>
                            <button type="submit" 
                                    class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
                                <i class="fas fa-key mr-2"></i>
                                Cambiar Contraseña
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function mostrarTab(tabName) {
    // Ocultar todos los contenidos
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.add('hidden');
    });
    
    // Mostrar el contenido seleccionado
    document.getElementById('tab-content-' + tabName).classList.remove('hidden');
    
    // Actualizar botones activos
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Activar el botón seleccionado
    document.getElementById('tab-' + tabName).classList.add('active');
}

function validarPassword() {
    const nueva = document.getElementById('nueva_password').value;
    const confirmar = document.getElementById('confirmar_password').value;
    
    if (nueva.length < 6) {
        alert('La nueva contraseña debe tener al menos 6 caracteres');
        return false;
    }
    
    if (nueva !== confirmar) {
        alert('Las contraseñas no coinciden');
        return false;
    }
    
    return confirm('¿Estás seguro de cambiar la contraseña?');
}

// Inicializar primera tab
document.addEventListener('DOMContentLoaded', function() {
    mostrarTab('info');
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>