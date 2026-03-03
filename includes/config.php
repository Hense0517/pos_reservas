<?php
// includes/config.php
// Evitar sesiones múltiples
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// NUEVO: CONTROL DE TIMEOUT DE SESIÓN
// ============================================
$timeout = 1800; // 30 minutos en segundos

// Verificar si la sesión ha expirado por inactividad
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > $timeout)) {
    // Última actividad fue hace más de 30 minutos
    session_unset();
    session_destroy();
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/pos2/') . 'login.php?error=timeout');
    exit();
}

// Actualizar timestamp de última actividad
$_SESSION['LAST_ACTIVITY'] = time();

// Regenerar ID de sesión periódicamente para prevenir session fixation
if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
} elseif (time() - $_SESSION['CREATED'] > 1800) {
    // Regenerar ID cada 30 minutos
    session_regenerate_id(true);
    $_SESSION['CREATED'] = time();
}

// ============================================
// NUEVO: FORZAR HTTPS EN PRODUCCIÓN
// ============================================
// Detectar si estamos en producción (desde .env)
$isProduction = false;
if (file_exists(__DIR__ . '/../.env')) {
    $envContent = file_get_contents(__DIR__ . '/../.env');
    if (preg_match('/APP_ENV=production/', $envContent)) {
        $isProduction = true;
    }
}

// Forzar HTTPS en producción
if ($isProduction && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on')) {
    header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    exit();
}

// ============================================
// FIN DE NUEVAS FUNCIONALIDADES
// ============================================

// Cargar variables de entorno
require_once __DIR__ . '/../config/Env.php';

try {
    Env::load(__DIR__ . '/../.env');
} catch (Exception $e) {
    // Si no hay .env, continuar con valores por defecto o mostrar error
    if (file_exists(__DIR__ . '/../.env.example')) {
        // En desarrollo mostrar error, en producción ignorar
        if (Env::get('APP_ENV') === 'development') {
            die("Error: Archivo .env no encontrado. Copie .env.example a .env y configure las credenciales.");
        }
    }
}

// VERIFICAR ANTES DE DEFINIR CONSTANTES
if (!defined('DB_HOST')) define('DB_HOST', Env::get('DB_HOST', 'localhost'));
if (!defined('DB_NAME')) define('DB_NAME', Env::get('DB_NAME', 'sistema_pos'));
if (!defined('DB_USER')) define('DB_USER', Env::get('DB_USER', 'root'));
if (!defined('DB_PASS')) define('DB_PASS', Env::get('DB_PASS', ''));
if (!defined('BASE_PATH')) define('BASE_PATH', dirname(__DIR__));

// Base URL dinámica (ajustada para HTTPS)
$protocol = $isProduction ? 'https://' : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://');
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . $host . '/pos/';
if (!defined('BASE_URL')) define('BASE_URL', $base_url);

// Lista de páginas que no requieren autenticación
$login_pages = ['login.php', 'logout.php', 'reset_password.php', 'reset_admin.php'];
$current_page = basename($_SERVER['PHP_SELF']);

// Inicializar variables globales
$config = [];
$user_info = [];
$db = null;
$auth = null;

// Verificar autenticación
if (!in_array($current_page, $login_pages)) {
    if (!isset($_SESSION['usuario_id'])) {
        header('Location: ' . BASE_URL . 'login.php');
        exit();
    }
}

// Conectar a la base de datos
try {
    // Incluir database.php
    require_once __DIR__ . '/../config/database.php';
    
    // Usar el método getInstance()
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    if ($db) {
        // Obtener configuración del negocio
        $config = $database->getConfiguracionNegocio() ?? [];
        
        // Si no hay datos, obtener directamente
        if (empty($config)) {
            $query = "SELECT * FROM configuracion_negocio ORDER BY id DESC LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $config = $stmt->fetch(PDO::FETCH_ASSOC) ?? [];
        }
        
        // Agregar valores por defecto si no existen
        if (!isset($config['moneda'])) {
            $config['moneda'] = 'USD';
        }
        if (!isset($config['meta_ventas_diaria'])) {
            $config['meta_ventas_diaria'] = 1000000;
        }
        if (!isset($config['nombre_negocio'])) {
            $config['nombre_negocio'] = 'Mi Negocio';
        }
        
        // Obtener información del usuario
        if (isset($_SESSION['usuario_id'])) {
            $query_user = "SELECT id, nombre, username, email, rol, activo FROM usuarios WHERE id = :id";
            $stmt_user = $db->prepare($query_user);
            $stmt_user->bindParam(':id', $_SESSION['usuario_id']);
            $stmt_user->execute();
            $user_info = $stmt_user->fetch(PDO::FETCH_ASSOC) ?? [];
            
            if (!$user_info || !$user_info['activo']) {
                session_destroy();
                header('Location: ' . BASE_URL . 'login.php?error=inactivo');
                exit();
            }
            
            $_SESSION['usuario_nombre'] = $user_info['nombre'];
            $_SESSION['usuario_rol'] = $user_info['rol'];
            
            // Incluir auth.php si existe
            $auth_file = __DIR__ . '/../config/auth.php';
            if (file_exists($auth_file)) {
                require_once $auth_file;
                $auth = new Auth($database);
                
                // Guardar SOLO datos escalares en sesión
                $_SESSION['user_info'] = $user_info;
                
                // Guardar permisos en sesión como array
                if (method_exists($auth, 'getAllPermissions')) {
                    $_SESSION['user_permisos'] = $auth->getAllPermissions();
                }
            } else {
                // Si no hay auth.php, crear un objeto simple
                if (!class_exists('SimpleAuth')) {
                    class SimpleAuth {
                        public function hasPermission($module, $action) {
                            return isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';
                        }
                        public function isAdmin() {
                            return isset($_SESSION['usuario_rol']) && $_SESSION['usuario_rol'] === 'admin';
                        }
                        public function getUserId() {
                            return $_SESSION['usuario_id'] ?? null;
                        }
                        public function getUserRol() {
                            return $_SESSION['usuario_rol'] ?? null;
                        }
                        public function getUserName() {
                            return $_SESSION['usuario_nombre'] ?? '';
                        }
                    }
                }
                $auth = new SimpleAuth();
            }
        }
    }
} catch (Exception $e) {
    error_log("Error en config.php: " . $e->getMessage());
    
    // Mostrar error solo si no es página de login
    if (!in_array($current_page, $login_pages)) {
        if (Env::get('APP_ENV') === 'development') {
            die("
            <!DOCTYPE html>
            <html>
            <head>
                <title>Error del Sistema</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 50px; text-align: center; }
                    .error { background: #ffebee; border: 1px solid #ffcdd2; padding: 20px; border-radius: 5px; max-width: 600px; margin: 0 auto; }
                    h1 { color: #d32f2f; }
                    code { background: #f5f5f5; padding: 2px 5px; border-radius: 3px; }
                </style>
            </head>
            <body>
                <div class='error'>
                    <h1>Error de Base de Datos</h1>
                    <p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
                    <p><strong>Base de datos:</strong> " . DB_NAME . "</p>
                    <p><strong>Usuario:</strong> " . DB_USER . "</p>
                    <p><a href='" . BASE_URL . "login.php'>Volver al Login</a></p>
                </div>
            </body>
            </html>");
        } else {
            die("
            <!DOCTYPE html>
            <html>
            <head>
                <title>Error del Sistema</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 50px; text-align: center; }
                    .error { background: #ffebee; border: 1px solid #ffcdd2; padding: 20px; border-radius: 5px; max-width: 600px; margin: 0 auto; }
                </style>
            </head>
            <body>
                <div class='error'>
                    <h1>Error del Sistema</h1>
                    <p>No se pudo conectar a la base de datos. Por favor contacte al administrador.</p>
                    <p><a href='" . BASE_URL . "login.php'>Volver al Login</a></p>
                </div>
            </body>
            </html>");
        }
    }
}

// Incluir helper de base de datos (después de todo lo demás)
require_once __DIR__ . '/db_helper.php';

// Exponer función global db()
if (!function_exists('db')) {
    function db() {
        return $GLOBALS['db'] ?? null;
    }
}
?>