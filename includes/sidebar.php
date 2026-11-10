<?php
// includes/sidebar.php
// Menú lateral. Se incluye desde header.php (ya están cargados $auth, $config y BASE_URL).
// OJO: ocultar un enlace NO es control de acceso; cada módulo debe validar sus propios permisos.
if (!isset($_SESSION['usuario_id'])) return;

// Roles adicionales del usuario (para opciones específicas del menú)
$usuario_roles = [];
try {
    $roles_stmt = Database::getInstance()->getConnection()->prepare(
        "SELECT r.nombre FROM roles r
         INNER JOIN usuarios_roles ur ON r.id = ur.rol_id
         WHERE ur.usuario_id = ?"
    );
    $roles_stmt->execute([$_SESSION['usuario_id']]);
    $usuario_roles = $roles_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('sidebar: no se pudieron leer los roles: ' . $e->getMessage());
}

$es_admin = (($_SESSION['usuario_rol'] ?? '') === 'admin') || in_array('admin', $usuario_roles, true);
$puede_ver_reservas   = $es_admin || $auth->hasPermission('reservas', 'leer');
$puede_crear_reservas = $es_admin || $auth->hasPermission('reservas', 'crear');

// Ruta actual relativa a la base del sistema, p. ej. "modules/ventas/index.php"
$nav_base = (string) parse_url(BASE_URL, PHP_URL_PATH);
$nav_ruta = ltrim(substr($_SERVER['SCRIPT_NAME'] ?? '', strlen($nav_base)), '/');

$nav_activo = static function (string $prefijo) use ($nav_ruta): bool {
    return strpos($nav_ruta, $prefijo) === 0;
};

$en_reservas   = $nav_activo('modules/reservas/');
$reservas_open = $en_reservas || (($_COOKIE['reservas_menu'] ?? '') === 'expandido');

/** Imprime un enlace de primer nivel del menú. */
$nav_link = static function (string $ruta, string $icono, string $texto, bool $activo): void {
    echo '<a href="' . htmlspecialchars(BASE_URL . $ruta, ENT_QUOTES, 'UTF-8') . '" class="nav-item' . ($activo ? ' is-active' : '') . '"'
        . ($activo ? ' aria-current="page"' : '') . '>'
        . '<span class="nav-icon"><i class="fas ' . $icono . '" aria-hidden="true"></i></span>'
        . '<span class="nav-label">' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</span></a>';
};

/** Imprime un enlace del submenú. */
$nav_sub = static function (string $ruta, string $icono, string $texto, bool $activo): void {
    echo '<a href="' . htmlspecialchars(BASE_URL . $ruta, ENT_QUOTES, 'UTF-8') . '" class="nav-sub-item' . ($activo ? ' is-active' : '') . '"'
        . ($activo ? ' aria-current="page"' : '') . '>'
        . '<i class="fas ' . $icono . '" aria-hidden="true"></i><span>' . htmlspecialchars($texto, ENT_QUOTES, 'UTF-8') . '</span></a>';
};

$sub_calendario = $nav_ruta === 'modules/reservas/calendario.php';
$sub_crear      = $nav_ruta === 'modules/reservas/crear.php';
$sub_servicios  = $nav_activo('modules/reservas/servicios/');
$sub_listado    = $en_reservas && !$sub_calendario && !$sub_crear && !$sub_servicios;
?>
<nav class="sidebar-nav" aria-label="Navegación principal">

    <div class="nav-section">Mostrador</div>
    <?php
    $nav_link('index.php', 'fa-gauge-high', 'Panel principal', $nav_ruta === 'index.php' || $nav_ruta === '');
    $nav_link('modules/ventas/', 'fa-cash-register', 'Ventas', $nav_activo('modules/ventas/'));
    $nav_link('modules/cuentas_por_cobrar/', 'fa-hand-holding-dollar', 'Ventas a crédito', $nav_activo('modules/cuentas_por_cobrar/'));
    ?>

    <?php if ($puede_ver_reservas): ?>
    <div class="nav-section">Agenda</div>
    <div class="nav-group<?php echo $reservas_open ? ' open' : ''; ?>">
        <button type="button" class="nav-item<?php echo $en_reservas ? ' is-active' : ''; ?>"
                data-nav-toggle data-nav-cookie="reservas_menu"
                aria-expanded="<?php echo $reservas_open ? 'true' : 'false'; ?>" aria-controls="reservas-submenu">
            <span class="nav-icon"><i class="fas fa-calendar-check" aria-hidden="true"></i></span>
            <span class="nav-label">Reservas</span>
            <i class="fas fa-chevron-down nav-chevron" aria-hidden="true"></i>
        </button>
        <div class="nav-sub" id="reservas-submenu">
            <?php
            $nav_sub('modules/reservas/calendario.php', 'fa-calendar-days', 'Calendario', $sub_calendario);
            $nav_sub('modules/reservas/', 'fa-list-check', 'Listado de reservas', $sub_listado);
            if ($puede_crear_reservas) {
                $nav_sub('modules/reservas/crear.php', 'fa-calendar-plus', 'Nueva reserva', $sub_crear);
            }
            if ($es_admin) {
                $nav_sub('modules/reservas/servicios/', 'fa-scissors', 'Servicios', $sub_servicios);
            }
            ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="nav-section">Negocio</div>
    <?php
    $nav_link('modules/inventario/', 'fa-boxes-stacked', 'Inventario', $nav_activo('modules/inventario/'));
    $nav_link('modules/clientes/', 'fa-user-group', 'Clientes', $nav_activo('modules/clientes/'));
    $nav_link('modules/compras/', 'fa-truck-fast', 'Compras', $nav_activo('modules/compras/'));
    $nav_link('modules/proveedores/', 'fa-handshake', 'Proveedores', $nav_activo('modules/proveedores/'));
    $nav_link('modules/gastos/', 'fa-wallet', 'Gastos', $nav_activo('modules/gastos/'));
    $nav_link('modules/reportes/reporte_general.php', 'fa-chart-line', 'Reportes', $nav_activo('modules/reportes/'));
    ?>

    <?php if ($es_admin): ?>
    <div class="nav-section">Administración</div>
    <?php
    $nav_link('modules/usuarios/', 'fa-user-gear', 'Usuarios y permisos', $nav_activo('modules/usuarios/'));
    $nav_link('modules/configuracion/', 'fa-sliders', 'Configuración y colores', $nav_activo('modules/configuracion/'));
    ?>
    <?php endif; ?>
</nav>

<!-- Usuario (solo se ve en móvil, donde el header lo oculta) -->
<div class="sidebar-user">
    <span class="user-avatar" aria-hidden="true"><?php echo htmlspecialchars($hdr_iniciales ?? 'U', ENT_QUOTES, 'UTF-8'); ?></span>
    <div>
        <p><?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Usuario', ENT_QUOTES, 'UTF-8'); ?></p>
        <small><?php echo htmlspecialchars($hdr_rol ?? '', ENT_QUOTES, 'UTF-8'); ?></small>
    </div>
</div>

<div class="sidebar-card">
    <strong><i class="fas fa-spray-can-sparkles" aria-hidden="true"></i> Estilo en cada detalle</strong>
    Reserva, vende y cuida a tus clientes desde un solo lugar.
</div>
