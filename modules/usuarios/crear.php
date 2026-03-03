<?php
/**
 * ============================================
 * ARCHIVO: crear.php
 * UBICACIÓN: /modules/usuarios/crear.php
 * PROPÓSITO: Crear un nuevo usuario en el sistema con múltiples roles
 * 
 * FUNCIONALIDADES:
 * - Formulario para crear nuevo usuario
 * - Selección múltiple de roles
 * - Validaciones básicas
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

// Verificar permisos (solo admin puede crear usuarios)
$es_admin = ($_SESSION['usuario_rol'] == 'admin');

if (!$es_admin) {
    $_SESSION['error'] = "No tienes permisos para crear usuarios";
    header("Location: index.php");
    exit();
}

// Obtener información del usuario actual
$user_id = $_SESSION['usuario_id'];
$database = Database::getInstance();
$db = $database->getConnection();

$user_query = "SELECT * FROM usuarios WHERE id = ?";
$user_stmt = $db->prepare($user_query);
$user_stmt->execute([$user_id]);
$current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Obtener todos los roles disponibles agrupados por categoría
$roles_query = "SELECT * FROM roles WHERE activo = 1 ORDER BY nivel DESC, nombre ASC";
$roles_stmt = $db->prepare($roles_query);
$roles_stmt->execute();
$roles = $roles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar roles por categoría
$roles_por_categoria = [];
foreach ($roles as $rol) {
    $categoria = $rol['categoria'] ?? 'general';
    if (!isset($roles_por_categoria[$categoria])) {
        $roles_por_categoria[$categoria] = [];
    }
    $roles_por_categoria[$categoria][] = $rol;
}

$page_title = "Nuevo Usuario - " . ($config['nombre_negocio'] ?? 'Sistema POS');
include __DIR__ . '/../../includes/header.php';
?>

<style>
.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.4);
}

.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

.btn-secondary {
    background: #6b7280;
    color: white;
    border: none;
    border-radius: 8px;
    padding: 10px 20px;
    font-weight: 500;
    transition: all 0.3s ease;
}

.btn-secondary:hover {
    background: #4b5563;
}

.section-title {
    color: #374151;
    font-weight: 600;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e5e7eb;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-weight: 500;
    color: #374151;
    margin-bottom: 8px;
    font-size: 14px;
}

.form-input {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    transition: border-color 0.3s ease;
}

.form-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.checkbox-label {
    display: flex;
    align-items: center;
    cursor: pointer;
}

.checkbox-input {
    width: 18px;
    height: 18px;
    margin-right: 10px;
    cursor: pointer;
}

.password-strength {
    height: 4px;
    background: #e5e7eb;
    border-radius: 2px;
    margin-top: 8px;
    overflow: hidden;
}

.password-strength-bar {
    height: 100%;
    width: 0;
    transition: width 0.3s ease;
}

.strength-weak {
    background: #ef4444;
    width: 33.33%;
}

.strength-medium {
    background: #f59e0b;
    width: 66.66%;
}

.strength-strong {
    background: #10b981;
    width: 100%;
}

/* Estilos para la selección de roles */
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

.rol-descripcion {
    font-size: 11px;
    color: #6b7280;
    margin-left: 26px;
    line-height: 1.2;
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
</style>

<div class="max-w-5xl mx-auto p-6">
    <!-- Cabecera -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
        <div>
            <h1 class="text-3xl font-bold text-gray-900">
                <i class="fas fa-user-plus text-blue-600 mr-2"></i>
                Nuevo Usuario
            </h1>
            <p class="text-gray-600 mt-1">Complete el formulario para crear un nuevo usuario con múltiples roles</p>
        </div>
        <div class="flex space-x-3 mt-4 md:mt-0">
            <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
                <i class="fas fa-arrow-left mr-2"></i>
                Volver al Listado
            </a>
        </div>
    </div>

    <!-- Mensajes -->
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

    <!-- Formulario -->
    <div class="card p-6">
        <form action="guardar_usuario_completo.php" method="POST" onsubmit="return validarFormulario()">
            <input type="hidden" name="usuario_id" value="">
            
            <!-- Información Básica -->
            <div class="mb-8">
                <h3 class="section-title">
                    <i class="fas fa-user-circle text-blue-500 mr-2"></i>
                    Información Básica
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="form-group">
                        <label class="form-label" for="username">
                            <i class="fas fa-user mr-1"></i> Usuario *
                        </label>
                        <input type="text" 
                               id="username"
                               name="username" 
                               required 
                               class="form-input"
                               placeholder="ej: juan.perez"
                               minlength="3"
                               maxlength="50"
                               autocomplete="off">
                        <p class="text-xs text-gray-500 mt-1">Mínimo 3 caracteres. Sin espacios.</p>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="password">
                            <i class="fas fa-lock mr-1"></i> Contraseña *
                        </label>
                        <input type="password" 
                               id="password"
                               name="password" 
                               required
                               class="form-input"
                               placeholder="••••••••"
                               minlength="6"
                               onkeyup="checkPasswordStrength(this.value)">
                        <div class="password-strength">
                            <div id="strengthBar" class="password-strength-bar"></div>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">Mínimo 6 caracteres. Usa mayúsculas, números y símbolos para mayor seguridad.</p>
                    </div>
                    
                    <div class="form-group md:col-span-2">
                        <label class="form-label" for="nombre">
                            <i class="fas fa-id-card mr-1"></i> Nombre Completo *
                        </label>
                        <input type="text" 
                               id="nombre"
                               name="nombre" 
                               required 
                               class="form-input"
                               placeholder="Ej: Juan Pérez García"
                               maxlength="100">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="email">
                            <i class="fas fa-envelope mr-1"></i> Email
                        </label>
                        <input type="email" 
                               id="email"
                               name="email" 
                               class="form-input"
                               placeholder="ej: juan@ejemplo.com"
                               maxlength="100">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="telefono">
                            <i class="fas fa-phone mr-1"></i> Teléfono
                        </label>
                        <input type="text" 
                               id="telefono"
                               name="telefono" 
                               class="form-input"
                               placeholder="Ej: 3001234567"
                               maxlength="20">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Estado</label>
                        <label class="checkbox-label">
                            <input type="checkbox" 
                                   name="activo" 
                                   value="1" 
                                   class="checkbox-input"
                                   checked>
                            <span class="text-gray-700">
                                <i class="fas fa-check-circle text-green-500 mr-1"></i>
                                Usuario Activo
                            </span>
                        </label>
                        <p class="text-xs text-gray-500 mt-1">Los usuarios inactivos no pueden iniciar sesión.</p>
                    </div>
                </div>
            </div>
            
            <!-- Selección de Roles Múltiples -->
            <div class="mb-8">
                <h3 class="section-title">
                    <i class="fas fa-user-tag text-blue-500 mr-2"></i>
                    Roles del Usuario <span class="text-sm font-normal text-gray-500">(puede seleccionar múltiples roles)</span>
                </h3>
                
                <!-- Roles seleccionados (vista previa) -->
                <div id="rolesSeleccionadosContainer" class="roles-seleccionados hidden">
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
                                       onchange="actualizarRolesSeleccionados()">
                                <span class="rol-badge" style="background-color: <?php echo $rol['color'] ?? '#6b7280'; ?>;"></span>
                                <span class="rol-nombre"><?php echo ucfirst($rol['nombre']); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <p class="text-xs text-gray-500 mt-3">
                    <i class="fas fa-info-circle mr-1"></i>
                    Los roles determinan los permisos y funcionalidades a las que tendrá acceso el usuario.
                </p>
            </div>
            
            <!-- Notas importantes -->
            <div class="mb-8 p-4 bg-blue-50 border border-blue-200 rounded-lg">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-blue-500 mt-1 mr-3 text-lg"></i>
                    <div>
                        <h4 class="font-medium text-blue-800 mb-2">Información importante:</h4>
                        <ul class="text-sm text-blue-700 space-y-1">
                            <li><i class="fas fa-check-circle mr-1"></i> Los campos marcados con * son obligatorios</li>
                            <li><i class="fas fa-key mr-1"></i> La contraseña debe tener al menos 6 caracteres</li>
                            <li><i class="fas fa-shield-alt mr-1"></i> Un usuario puede tener múltiples roles</li>
                            <li><i class="fas fa-envelope mr-1"></i> El email es opcional pero recomendado para recuperación de cuenta</li>
                            <li><i class="fas fa-users mr-1"></i> Los roles con mayor nivel tienen más privilegios</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Botones -->
            <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200">
                <a href="index.php" class="btn-secondary">
                    <i class="fas fa-times mr-2"></i>
                    Cancelar
                </a>
                <button type="submit" class="btn-primary" id="submitBtn">
                    <i class="fas fa-save mr-2"></i>
                    Crear Usuario
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

// Validación de fortaleza de contraseña
function checkPasswordStrength(password) {
    const strengthBar = document.getElementById('strengthBar');
    
    if (!password) {
        strengthBar.className = 'password-strength-bar';
        return;
    }
    
    let strength = 0;
    
    if (password.length >= 6) strength += 1;
    if (password.length >= 8) strength += 1;
    if (/[A-Z]/.test(password)) strength += 1;
    if (/[0-9]/.test(password)) strength += 1;
    if (/[^A-Za-z0-9]/.test(password)) strength += 1;
    
    if (strength <= 2) {
        strengthBar.className = 'password-strength-bar strength-weak';
    } else if (strength <= 4) {
        strengthBar.className = 'password-strength-bar strength-medium';
    } else {
        strengthBar.className = 'password-strength-bar strength-strong';
    }
}

// Validación del formulario
function validarFormulario() {
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const nombre = document.getElementById('nombre').value;
    const email = document.getElementById('email').value;
    const rolesSeleccionados = document.querySelectorAll('input[name="roles[]"]:checked').length;
    
    // Validar username
    if (username.length < 3) {
        alert('El nombre de usuario debe tener al menos 3 caracteres');
        document.getElementById('username').focus();
        return false;
    }
    
    if (username.includes(' ')) {
        alert('El nombre de usuario no puede contener espacios');
        document.getElementById('username').focus();
        return false;
    }
    
    // Validar contraseña
    if (password.length < 6) {
        alert('La contraseña debe tener al menos 6 caracteres');
        document.getElementById('password').focus();
        return false;
    }
    
    // Validar nombre
    if (nombre.trim().length === 0) {
        alert('El nombre completo es obligatorio');
        document.getElementById('nombre').focus();
        return false;
    }
    
    // Validar que tenga al menos un rol
    if (rolesSeleccionados === 0) {
        alert('Debe seleccionar al menos un rol para el usuario');
        return false;
    }
    
    // Validar email si se ingresó
    if (email && !validarEmail(email)) {
        alert('Por favor ingresa un email válido');
        document.getElementById('email').focus();
        return false;
    }
    
    // Confirmar
    if (!confirm('¿Estás seguro de crear este usuario?')) {
        return false;
    }
    
    // Deshabilitar botón
    document.getElementById('submitBtn').disabled = true;
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Procesando...';
    
    return true;
}

// Validar email
function validarEmail(email) {
    const re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
    return re.test(String(email).toLowerCase());
}

// Validar teléfono (opcional - solo números, +, - y espacios)
document.getElementById('telefono')?.addEventListener('input', function(e) {
    this.value = this.value.replace(/[^0-9+\-\s]/g, '');
});

// Auto-mayúscula para nombre
document.getElementById('nombre')?.addEventListener('blur', function(e) {
    this.value = this.value.replace(/\b\w/g, l => l.toUpperCase());
});

// Enfocar campo username al cargar
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('username').focus();
    
    // Inicializar roles seleccionados
    actualizarRolesSeleccionados();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>