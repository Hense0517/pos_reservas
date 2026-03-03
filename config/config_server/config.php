<?php
// Auto-fixed: 2026-02-17 01:57:19
require_once 'includes/config.php';
// Configuración global del sistema - SIN ESPACIOS ANTES DE <?php
// NO HAY ESPACIOS NI LÍNEAS EN BLANCO ANTES DE ESTA LÍNEA

// Iniciar sesión PRIMERO, antes de cualquier salida
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Obtener el protocolo (http o https)
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$script_name = dirname($_SERVER['SCRIPT_NAME']);

// Definir constantes de rutas ABSOLUTAS
define('ROOT_PATH', dirname(dirname(__FILE__)));
define('BASE_URL', $protocol . '://' . $host . rtrim($script_name, '/'));
define('ASSETS_URL', BASE_URL . '/assets');
define('MODULES_URL', BASE_URL . '/modules');

// Incluir archivos de configuración
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/config/auth.php';

// Inicializar base de datos
$database = Database::getInstance();
$db = $database->getConnection();

// Inicializar autenticación
$auth = new Auth($database);

// Verificar autenticación en todas las páginas excepto login y logout
$current_page = basename($_SERVER['PHP_SELF']);
$excluded_pages = ['login.php', 'logout.php'];

if (!in_array($current_page, $excluded_pages)) {
    $auth->checkAuth();
}

// Obtener información del usuario si está logueado
$user_info = null;
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $user_info = $auth->getUserInfo();
}

// Obtener configuración del negocio
$config = [];
if (isset($db) && $db !== null) {
    try {
        $query = "SELECT * FROM configuracion_negocio ORDER BY id DESC LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $config = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error cargando configuración: " . $e->getMessage());
        // Si la tabla no existe, usar valores por defecto
        $config = [
            'nombre_negocio' => 'Mi Negocio',
            'logo' => '',
            'moneda' => 'COP'
        ];
    }
} else {
    $config = [
        'nombre_negocio' => 'Mi Negocio',
        'logo' => '',
        'moneda' => 'COP'
    ];
}

// Función auxiliar para verificar si archivo existe
function asset_exists($path) {
    return file_exists($_SERVER['DOCUMENT_ROOT'] . parse_url($path, PHP_URL_PATH));
}
?>
<!-- NO HAY ESPACIOS DESPUÉS DE CERRAR PHP -->