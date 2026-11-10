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

// Datos para el header (solo presentación)
$hdr_nombre_negocio = $config['nombre_negocio'] ?? 'Mi Negocio';
$hdr_usuario = $_SESSION['usuario_nombre'] ?? '';
$hdr_rol_raw = $_SESSION['usuario_rol'] ?? '';
$hdr_roles = ['admin' => 'Administrador', 'vendedor' => 'Vendedor', 'cajero' => 'Cajero'];
$hdr_rol = $hdr_roles[$hdr_rol_raw] ?? ucfirst((string) $hdr_rol_raw);

$hdr_iniciales = '';
foreach (array_slice(preg_split('/\s+/', trim($hdr_usuario)) ?: [], 0, 2) as $parte) {
    $hdr_iniciales .= mb_strtoupper(mb_substr($parte, 0, 1));
}
if ($hdr_iniciales === '') {
    $hdr_iniciales = 'U';
}

$hdr_puede = static function (string $modulo, string $accion) use (&$auth): bool {
    return isset($auth) && $auth && method_exists($auth, 'hasPermission') && $auth->hasPermission($modulo, $accion);
};
$hdr_nueva_venta = $hdr_puede('ventas', 'crear');
$hdr_nueva_reserva = $hdr_puede('reservas', 'crear');
$hdr_logo = !empty($config['logo']) ? BASE_URL . ltrim($config['logo'], '/') : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <?php echo estilos_base(); ?>
</head>
<body class="app-body">
    <!-- Header fijo -->
    <header class="app-header">
        <div class="hdr-left">
            <button type="button" id="menuToggle" class="hdr-menu-btn" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false">
                <i class="fas fa-bars"></i>
            </button>
            <a class="brand" href="<?php echo BASE_URL; ?>index.php" aria-label="Ir al inicio">
                <?php if ($hdr_logo !== ''): ?>
                    <span class="brand-logo"><img src="<?php echo htmlspecialchars($hdr_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="Logo de <?php echo htmlspecialchars($hdr_nombre_negocio, ENT_QUOTES, 'UTF-8'); ?>"></span>
                <?php else: ?>
                    <span class="brand-logo is-icon"><i class="fas fa-scissors" aria-hidden="true"></i></span>
                <?php endif; ?>
                <span class="brand-text">
                    <span class="brand-name"><?php echo htmlspecialchars($hdr_nombre_negocio, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="brand-sub"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Peluquería &amp; Reservas</span>
                </span>
            </a>
        </div>

        <?php if (isset($_SESSION['usuario_id'])): ?>
        <div class="hdr-right">
            <span class="hdr-clock" title="Fecha y hora"><i class="far fa-clock" aria-hidden="true"></i> <span data-reloj></span></span>

            <?php if ($hdr_nueva_reserva): ?>
                <a class="hdr-pill" href="<?php echo BASE_URL; ?>modules/reservas/crear.php"><i class="fas fa-calendar-plus" aria-hidden="true"></i> Nueva reserva</a>
            <?php endif; ?>
            <?php if ($hdr_nueva_venta): ?>
                <a class="hdr-pill is-primary" href="<?php echo BASE_URL; ?>modules/ventas/crear.php"><i class="fas fa-cash-register" aria-hidden="true"></i> Nueva venta</a>
            <?php endif; ?>

            <div class="relative" style="position:relative">
                <button type="button" id="userMenuButton" class="user-chip" aria-haspopup="true" aria-expanded="false" aria-controls="userMenu">
                    <span class="user-chip-text">
                        <span class="user-chip-name" style="display:block"><?php echo htmlspecialchars($hdr_usuario, ENT_QUOTES, 'UTF-8'); ?></span>
                        <span class="user-chip-role"><?php echo htmlspecialchars($hdr_rol, ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <span class="user-avatar" aria-hidden="true"><?php echo htmlspecialchars($hdr_iniciales, ENT_QUOTES, 'UTF-8'); ?></span>
                </button>
                <div id="userMenu" class="dropdown-menu" role="menu">
                    <div class="dropdown-head">
                        <strong><?php echo htmlspecialchars($hdr_usuario, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span><?php echo htmlspecialchars($hdr_rol, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <a href="<?php echo BASE_URL; ?>modules/usuarios/perfil.php" class="dropdown-item" role="menuitem">
                        <i class="fas fa-user-pen" aria-hidden="true"></i> Mi perfil
                    </a>
                    <?php if ($hdr_rol_raw === 'admin'): ?>
                    <a href="<?php echo BASE_URL; ?>modules/configuracion/" class="dropdown-item" role="menuitem">
                        <i class="fas fa-palette" aria-hidden="true"></i> Colores y negocio
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo BASE_URL; ?>logout.php" class="dropdown-item is-danger" role="menuitem">
                        <i class="fas fa-right-from-bracket" aria-hidden="true"></i> Cerrar sesión
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </header>

    <!-- Menú lateral -->
    <aside id="sidebar" class="sidebar" aria-label="Menú principal">
        <?php include __DIR__ . '/sidebar.php'; ?>
    </aside>

    <!-- Fondo oscuro para el menú en móvil -->
    <div id="sidebarOverlay" class="sidebar-overlay"></div>

    <!-- Contenido principal -->
    <main class="main-content">
