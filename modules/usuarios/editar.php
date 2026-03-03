<?php
/**
 * ============================================
 * ARCHIVO: editar.php
 * UBICACIÓN: /modules/usuarios/editar.php
 * PROPÓSITO: Editar usuario con múltiples roles
 * ============================================
 */

session_start();
require_once __DIR__ . '/../../includes/config.php';

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    header("Location: " . BASE_URL . "login.php");
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    $_SESSION['error'] = "ID de usuario no válido";
    header("Location: index.php");
    exit();
}

// Obtener datos del usuario
$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
$stmt->execute([$id]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {
    $_SESSION['error'] = "Usuario no encontrado";
    header("Location: index.php");
    exit();
}

// Verificar permisos
$es_admin = ($_SESSION['usuario_rol'] == 'admin');
$es_mismo_usuario = ($_SESSION['usuario_id'] == $id);

if (!$es_admin && !$es_mismo_usuario) {
    $_SESSION['error'] = "No tienes permisos para editar este usuario";
    header("Location: index.php");
    exit();
}

// Obtener roles del usuario
$roles_query = "SELECT rol_id FROM usuarios_roles WHERE usuario_id = ?";
$roles_stmt = $db->prepare($roles_query);
$roles_stmt->execute([$id]);
$usuario_roles = $roles_stmt->fetchAll(PDO::FETCH_COLUMN);

// Obtener todos los roles disponibles agrupados por categoría
$roles_disponibles_query = "SELECT * FROM roles WHERE activo = 1 ORDER BY nivel DESC, nombre ASC";
$roles_disponibles_stmt = $db->prepare($roles_disponibles_query);
$roles_disponibles_stmt->execute();
$roles_disponibles = $roles_disponibles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar roles por categoría
$roles_por_categoria = [];
foreach ($roles_disponibles as $rol) {
    $categoria = $rol['categoria'] ?? 'general';
    if (!isset($roles_por_categoria[$categoria])) {
        $roles_por_categoria[$categoria] = [];
    }
    $roles_por_categoria[$categoria][] = $rol;
}

// Obtener permisos del usuario
$permisos_query = "SELECT modulo, leer, crear, editar, eliminar FROM permisos WHERE usuario_id = ?";
$permisos_stmt = $db->prepare($permisos_query);
$permisos_stmt->execute([$id]);
$permisos_usuario = [];
while ($permiso = $permisos_stmt->fetch(PDO::FETCH_ASSOC)) {
    $permisos_usuario[$permiso['modulo']] = [
        'leer' => $permiso['leer'],
        'crear' => $permiso['crear'],
        'editar' => $permiso['editar'],
        'eliminar' => $permiso['eliminar']
    ];
}

$page_title = "Editar Usuario - " . ($config['nombre_negocio'] ?? 'Sistema POS');
include __DIR__ . '/../../includes/header.php';
?>

<style>
.categoria-rol {
    margin-bottom: 20px;
    padding: 15px;
    background: #f9fafb;
    border-radius: 8px;
    border-left: 4px solid #667eea;
}

.categoria-titulo {
    font-weight: 600;
    color: #374151;
    margin-bottom: 12px;
    text-transform: capitalize;
}

.roles-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
}

.rol-item {
    display: flex;
    align-items: center;
    padding: 8px 12px;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    transition: all 0.2s ease;
    cursor: pointer;
}

.rol-item:hover {
    background: #f3f4f6;
    border-color: #667eea;
    transform: translateY(-1px);
}

.rol-item input[type="checkbox"] {
    margin-right: 10px;
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.rol-nombre {
    font-size: 14px;
    font-weight: 500;
    color: #374151;
}

.rol-badge {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    margin-right: 8px;
}

.roles-seleccionados {
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
    margin-bottom: 20px;
}

.roles-seleccionados-titulo {
    font-weight: 500;
    color: #166534;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.roles-seleccionados-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.rol-tag {
    background: white;
    border: 1px solid #86efac;
    color: #166534;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 13px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.rol-tag i {
    font-size: 12px;
}

.rol-tag button {
    background: none;
    border: none;
    color: #991b1b;
    cursor: pointer;
    padding: 0 4px;
    font-size: 14px;
}

.rol-tag button:hover {
    color: #dc2626;
}

.modulo-permiso {
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 10px;
}

.modulo-titulo {
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
    text-transform: capitalize;
}

.permisos-grid {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

.permiso-item {
    display: flex;
    align-items: center;
    gap: 5px;
}

.permiso-item input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.permiso-label {
    font-size: 13px;
    color: #4b5563;
}
</style>

<div class="max-w-5xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-user-edit text-green-600 mr-2"></i>
                Editar Usuario
            </h1>
            <p class="text-gray-600 mt-1">Modificando: <strong><?php echo htmlspecialchars($usuario['username']); ?></strong> (<?php echo htmlspecialchars($usuario['nombre']); ?>)</p>
        </div>
        <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-arrow-left mr-2"></i>
            Volver
        </a>
    </div>

    <!-- Mensajes -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <!-- Formulario -->
    <div class="bg-white rounded-lg shadow p-6">
        <form action="guardar_usuario_completo.php" method="POST" onsubmit="return validarFormulario()">
            <input type="hidden" name="usuario_id" value="<?php echo $usuario['id']; ?>">
            
            <!-- Información Básica -->
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 pb-2 border-b">
                    <i class="fas fa-user-circle text-blue-500 mr-2"></i>
                    Información Básica
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Usuario *</label>
                        <input type="text" name="username" value="<?php echo htmlspecialchars($usuario['username']); ?>" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nueva Contraseña <span class="text-xs text-gray-500">(opcional)</span></label>
                        <input type="password" name="password" id="password"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="Dejar vacío para no cambiar"
                               onkeyup="checkPasswordStrength(this.value)">
                        <div id="passwordStrength" class="h-1 mt-1 rounded transition-all" style="width:0%"></div>
                        <p class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres si se cambia</p>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Nombre Completo *</label>
                        <input type="text" name="nombre" value="<?php echo htmlspecialchars($usuario['nombre']); ?>" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($usuario['email'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Teléfono</label>
                        <input type="text" name="telefono" value="<?php echo htmlspecialchars($usuario['telefono'] ?? ''); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    
                    <div class="flex items-center">
                        <input type="checkbox" name="activo" value="1" id="activo" <?php echo $usuario['activo'] ? 'checked' : ''; ?>
                               class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <label for="activo" class="ml-2 text-sm text-gray-700">
                            <i class="fas fa-<?php echo $usuario['activo'] ? 'check-circle text-green-500' : 'times-circle text-red-500'; ?> mr-1"></i>
                            Usuario activo
                        </label>
                    </div>
                </div>
            </div>
            
            <?php if ($es_admin): ?>
            <!-- Selección de Roles Múltiples (solo visible para admin) -->
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 pb-2 border-b">
                    <i class="fas fa-user-tag text-purple-500 mr-2"></i>
                    Roles del Usuario <span class="text-sm font-normal text-gray-500">(puede seleccionar múltiples)</span>
                </h3>
                
                <!-- Roles seleccionados (vista previa) -->
                <div id="rolesSeleccionadosContainer" class="roles-seleccionados <?php echo empty($usuario_roles) ? 'hidden' : ''; ?>">
                    <div class="roles-seleccionados-titulo">
                        <i class="fas fa-check-circle text-green-600"></i>
                        Roles seleccionados:
                    </div>
                    <div id="rolesSeleccionadosTags" class="roles-seleccionados-tags">
                        <!-- Se llenará dinámicamente con JS -->
                    </div>
                </div>
                
                <!-- Lista de roles por categoría -->
                <div class="space-y-4 mt-4">
                    <?php foreach ($roles_por_categoria as $categoria => $roles_cat): ?>
                    <div class="categoria-rol">
                        <h4 class="categoria-titulo">
                            <i class="fas fa-folder-open mr-2 text-indigo-500"></i>
                            <?php echo ucfirst($categoria); ?>
                        </h4>
                        <div class="roles-grid">
                            <?php foreach ($roles_cat as $rol): ?>
                            <label class="rol-item" title="<?php echo htmlspecialchars($rol['descripcion'] ?? ''); ?>">
                                <input type="checkbox" 
                                       name="roles[]" 
                                       value="<?php echo $rol['id']; ?>"
                                       data-nombre="<?php echo htmlspecialchars($rol['nombre']); ?>"
                                       data-color="<?php echo $rol['color'] ?? '#6b7280'; ?>"
                                       data-icono="<?php echo $rol['icono'] ?? 'fas fa-user-tag'; ?>"
                                       onchange="actualizarRolesSeleccionados()"
                                       <?php echo in_array($rol['id'], $usuario_roles) ? 'checked' : ''; ?>>
                                <span class="rol-badge" style="background-color: <?php echo $rol['color'] ?? '#6b7280'; ?>;"></span>
                                <span class="rol-nombre"><?php echo ucfirst($rol['nombre']); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Permisos específicos -->
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4 pb-2 border-b">
                    <i class="fas fa-key text-amber-500 mr-2"></i>
                    Permisos Específicos
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php
                    $modulos = ['ventas', 'compras', 'inventario', 'clientes', 'proveedores', 'usuarios', 'reportes', 'configuracion', 'reservas'];
                    foreach ($modulos as $modulo):
                        $permiso = $permisos_usuario[$modulo] ?? ['leer' => 0, 'crear' => 0, 'editar' => 0, 'eliminar' => 0];
                    ?>
                    <div class="modulo-permiso">
                        <h4 class="modulo-titulo"><?php echo ucfirst($modulo); ?></h4>
                        <div class="permisos-grid">
                            <label class="permiso-item">
                                <input type="checkbox" name="permisos[<?php echo $modulo; ?>][leer]" value="1" <?php echo $permiso['leer'] ? 'checked' : ''; ?>>
                                <span class="permiso-label">Leer</span>
                            </label>
                            <label class="permiso-item">
                                <input type="checkbox" name="permisos[<?php echo $modulo; ?>][crear]" value="1" <?php echo $permiso['crear'] ? 'checked' : ''; ?>>
                                <span class="permiso-label">Crear</span>
                            </label>
                            <label class="permiso-item">
                                <input type="checkbox" name="permisos[<?php echo $modulo; ?>][editar]" value="1" <?php echo $permiso['editar'] ? 'checked' : ''; ?>>
                                <span class="permiso-label">Editar</span>
                            </label>
                            <label class="permiso-item">
                                <input type="checkbox" name="permisos[<?php echo $modulo; ?>][eliminar]" value="1" <?php echo $permiso['eliminar'] ? 'checked' : ''; ?>>
                                <span class="permiso-label">Eliminar</span>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Botones -->
            <div class="flex justify-end space-x-3 mt-6 pt-6 border-t border-gray-200">
                <a href="index.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                    Cancelar
                </a>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors" id="submitBtn">
                    <i class="fas fa-save mr-2"></i>
                    Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Variables globales
let rolesSeleccionados = [];

// Función para actualizar la vista de roles seleccionados
function actualizarRolesSeleccionados() {
    const checkboxes = document.querySelectorAll('input[name="roles[]"]:checked');
    const container = document.getElementById('rolesSeleccionadosContainer');
    const tagsContainer = document.getElementById('rolesSeleccionadosTags');
    
    rolesSeleccionados = [];
    tagsContainer.innerHTML = '';
    
    checkboxes.forEach(cb => {
        const rolId = cb.value;
        const rolNombre = cb.dataset.nombre;
        const rolColor = cb.dataset.color;
        const rolIcono = cb.dataset.icono;
        
        rolesSeleccionados.push({
            id: rolId,
            nombre: rolNombre,
            color: rolColor,
            icono: rolIcono
        });
        
        const tag = document.createElement('span');
        tag.className = 'rol-tag';
        tag.innerHTML = `
            <i class="${rolIcono}" style="color: ${rolColor};"></i>
            ${ucfirst(rolNombre)}
            <button type="button" onclick="deseleccionarRol('${rolId}')" title="Quitar rol">
                <i class="fas fa-times"></i>
            </button>
        `;
        tagsContainer.appendChild(tag);
    });
    
    if (rolesSeleccionados.length > 0) {
        container.classList.remove('hidden');
    } else {
        container.classList.add('hidden');
    }
}

// Función para deseleccionar un rol específico
function deseleccionarRol(rolId) {
    const checkbox = document.querySelector(`input[name="roles[]"][value="${rolId}"]`);
    if (checkbox) {
        checkbox.checked = false;
        actualizarRolesSeleccionados();
    }
}

// Función para capitalizar primera letra
function ucfirst(string) {
    return string.charAt(0).toUpperCase() + string.slice(1);
}

// Validar fortaleza de contraseña
function checkPasswordStrength(password) {
    const strengthBar = document.getElementById('passwordStrength');
    
    if (!password) {
        strengthBar.style.width = '0%';
        strengthBar.className = 'h-1 mt-1 rounded';
        return;
    }
    
    let strength = 0;
    
    if (password.length >= 6) strength += 1;
    if (password.length >= 8) strength += 1;
    if (/[A-Z]/.test(password)) strength += 1;
    if (/[0-9]/.test(password)) strength += 1;
    if (/[^A-Za-z0-9]/.test(password)) strength += 1;
    
    let porcentaje = (strength / 5) * 100;
    strengthBar.style.width = porcentaje + '%';
    
    if (strength <= 2) {
        strengthBar.className = 'h-1 mt-1 rounded bg-red-500';
    } else if (strength <= 4) {
        strengthBar.className = 'h-1 mt-1 rounded bg-yellow-500';
    } else {
        strengthBar.className = 'h-1 mt-1 rounded bg-green-500';
    }
}

// Validación del formulario
function validarFormulario() {
    const username = document.querySelector('input[name="username"]').value;
    const nombre = document.querySelector('input[name="nombre"]').value;
    const password = document.querySelector('input[name="password"]').value;
    const rolesSeleccionados = document.querySelectorAll('input[name="roles[]"]:checked').length;
    const esAdmin = <?php echo $es_admin ? 'true' : 'false'; ?>;
    
    if (username.length < 3) {
        alert('El nombre de usuario debe tener al menos 3 caracteres');
        return false;
    }
    
    if (username.includes(' ')) {
        alert('El nombre de usuario no puede contener espacios');
        return false;
    }
    
    if (nombre.trim().length === 0) {
        alert('El nombre completo es obligatorio');
        return false;
    }
    
    if (password && password.length < 6) {
        alert('La contraseña debe tener al menos 6 caracteres');
        return false;
    }
    
    if (esAdmin && rolesSeleccionados === 0) {
        alert('Debe seleccionar al menos un rol para el usuario');
        return false;
    }
    
    // Deshabilitar botón
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Guardando...';
    
    return true;
}

// Inicializar roles seleccionados al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    actualizarRolesSeleccionados();
    
    // Validar teléfono (solo números)
    document.querySelector('input[name="telefono"]')?.addEventListener('input', function(e) {
        this.value = this.value.replace(/[^0-9+\-\s]/g, '');
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>