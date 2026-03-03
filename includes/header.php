<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) session_start();

// CORREGIDO: La ruta correcta es __DIR__ . '/config.php' (no 'includes/config.php')
require_once __DIR__ . '/config.php';

// Verificar si ya estamos en configuración para evitar doble inclusión
if (!defined('HEADER_LOADED')) {
    define('HEADER_LOADED', true);
    
    // Incluir recursos.php
    require_once __DIR__ . '/recursos.php';
    
    // Si $auth no está definido, recrearlo desde los datos de sesión
    if (!isset($auth) && isset($_SESSION['usuario_id'])) {
        // Crear un auth simple desde los datos de sesión
        class SessionAuth {
            private $user_id;
            private $user_rol;
            private $user_permisos;
            
            public function __construct() {
                $this->user_id = $_SESSION['usuario_id'] ?? null;
                $this->user_rol = $_SESSION['usuario_rol'] ?? null;
                $this->user_permisos = $_SESSION['user_permisos'] ?? [];
            }
            
            public function hasPermission($module, $action = 'leer') {
                if ($this->isAdmin()) return true;
                
                $action_map = [
                    'leer' => 'leer',
                    'crear' => 'crear',
                    'editar' => 'editar',
                    'eliminar' => 'eliminar',
                    'lectura' => 'leer'
                ];
                
                $accion = $action_map[$action] ?? 'leer';
                
                return isset($this->user_permisos[$module][$accion]) && 
                       $this->user_permisos[$module][$accion];
            }
            
            public function isAdmin() {
                return $this->user_rol === 'admin';
            }
            
            public function getUserId() {
                return $this->user_id;
            }
            
            public function getUserRol() {
                return $this->user_rol;
            }
            
            public function getUserName() {
                return $_SESSION['usuario_nombre'] ?? '';
            }
        }
        
        $auth = new SessionAuth();
    }
}

$page_title = isset($page_title) ? $page_title : ('Sistema POS - ' . htmlspecialchars($config['nombre_negocio'] ?? 'Mi Negocio'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <?php echo estilos_base(); ?>
</head>
<body class="bg-gray-100">
    <!-- Header fijo -->
    <header class="header bg-white shadow-sm border-b fixed top-0 left-0 right-0" style="z-index: 50; height: 80px;">
        <div class="h-full px-4 sm:px-6 lg:px-8 flex justify-between items-center">
            <div class="flex items-center">
                <button id="menuToggle" class="lg:hidden mr-4 text-gray-600 hover:text-gray-900">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <div class="flex items-center gap-4">
                    <?php if(!empty($config['logo'])): ?>
                        <!-- SOLO EL LOGO - sin placeholder -->
                        <img src="<?php echo BASE_URL; ?>/<?php echo $config['logo']; ?>" 
                             alt="Logo" 
                             class="h-16 w-auto object-contain">
                    <?php endif; ?>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900"><?php echo htmlspecialchars($config['nombre_negocio'] ?? 'Mi Negocio'); ?></h1>
                        <p class="text-sm text-gray-500 hidden sm:block">Sistema POS</p>
                    </div>
                </div>
            </div>
            
            <?php if (isset($_SESSION['usuario_id'])): ?>
            <div class="flex items-center space-x-4">
                <div class="text-right hidden sm:block">
                    <p class="text-base font-medium text-gray-900"><?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? ''); ?></p>
                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($_SESSION['usuario_rol'] ?? ''); ?></p>
                </div>
                <div class="relative">
                    <button id="userMenuButton" class="flex items-center focus:outline-none">
                        <div class="h-10 w-10 bg-gray-300 rounded-full flex items-center justify-center shadow-sm hover:bg-gray-400 transition-colors">
                            <i class="fas fa-user text-gray-600 text-lg"></i>
                        </div>
                    </button>
                    <div id="userMenu" class="dropdown-menu">
                        <a href="<?php echo BASE_URL; ?>modules/usuarios/perfil.php" class="dropdown-item">
                            <i class="fas fa-user-edit"></i> Mi Perfil
                        </a>
                        <a href="<?php echo BASE_URL; ?>logout.php" class="dropdown-item">
                            <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </header>

    <!-- Sidebar (ajustado para dejar espacio al header fijo) -->
    <aside id="sidebar" class="sidebar fixed top-[80px] left-0 w-64 h-[calc(100vh-80px)] bg-white border-r border-gray-200 overflow-y-auto">
        <?php include __DIR__ . '/sidebar.php'; ?>
    </aside>

    <!-- Overlay para móviles -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 hidden lg:hidden"></div>

    <!-- Contenido principal -->
    <main class="main-content ml-64 mt-[80px] p-6">