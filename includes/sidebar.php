<?php
// includes/sidebar.php
// NO necesitas require_once aquí porque ya está incluido en header.php
// Solo verificar que hay sesión
if (!isset($_SESSION['usuario_id'])) return;

// Obtener roles del usuario actual (para permisos específicos)
$database = Database::getInstance();
$db = $database->getConnection();

$usuario_id = $_SESSION['usuario_id'];

// Obtener roles del usuario para verificar si puede ver ciertas opciones
$roles_query = "SELECT r.nombre FROM roles r
                INNER JOIN usuarios_roles ur ON r.id = ur.rol_id
                WHERE ur.usuario_id = ?";
$roles_stmt = $db->prepare($roles_query);
$roles_stmt->execute([$usuario_id]);
$usuario_roles = $roles_stmt->fetchAll(PDO::FETCH_COLUMN);

// Verificar si el usuario tiene permisos para reservas
$puede_ver_reservas = $auth->hasPermission('reservas', 'leer') || in_array('admin', $usuario_roles);
$puede_crear_reservas = $auth->hasPermission('reservas', 'crear') || in_array('admin', $usuario_roles);
$es_admin = ($_SESSION['usuario_rol'] == 'admin') || in_array('admin', $usuario_roles);

// Verificar si el menú de reservas debe estar expandido (por defecto colapsado)
$reservas_expandido = isset($_COOKIE['reservas_menu']) && $_COOKIE['reservas_menu'] === 'expandido';
?>
<aside class="sidebar bg-gray-300 shadow-lg">
    <!-- Navegación -->
    <nav class="h-full overflow-y-auto py-4">
        <div class="px-3 space-y-3"> <!-- Cambiado space-y-1 a space-y-3 para más separación -->
            <!-- Dashboard - Rosa con más separación -->
            <a href="<?php echo BASE_URL; ?>index.php" class="flex items-center px-3 py-2.5 text-gray-700 hover:text-pink-700 rounded-lg hover:bg-pink-50 transition-all group border border-transparent hover:border-pink-200">
                <div class="flex-shrink-0 w-8 h-8 bg-pink-100 rounded-lg flex items-center justify-center group-hover:bg-pink-500 transition-colors mr-3">
                    <i class="fas fa-home text-pink-600 group-hover:text-white text-sm"></i>
                </div>
                <span class="font-medium text-sm">Dashboard</span>
            </a>
            
            <!-- Ventas - Verde Menta -->
            <a href="<?php echo BASE_URL; ?>modules/ventas/" class="flex items-center px-3 py-2.5 text-gray-700 hover:text-emerald-700 rounded-lg hover:bg-emerald-50 transition-all group border border-transparent hover:border-emerald-200">
                <div class="flex-shrink-0 w-8 h-8 bg-emerald-100 rounded-lg flex items-center justify-center group-hover:bg-emerald-500 transition-colors mr-3">
                    <i class="fas fa-shopping-cart text-emerald-600 group-hover:text-white text-sm"></i>
                </div>
                <span class="font-medium text-sm">Ventas</span>
            </a>
            
            <!-- Ventas a Crédito - Azul Cielo -->
            <a href="<?php echo BASE_URL; ?>modules/cuentas_por_cobrar/" class="flex items-center px-3 py-2.5 text-gray-700 hover:text-sky-700 rounded-lg hover:bg-sky-50 transition-all group border border-transparent hover:border-sky-200">
                <div class="flex-shrink-0 w-8 h-8 bg-sky-100 rounded-lg flex items-center justify-center group-hover:bg-sky-500 transition-colors mr-3">
                    <i class="fas fa-hand-holding-usd text-sky-600 group-hover:text-white text-sm"></i>
                </div>
                <span class="font-medium text-sm">Ventas a Crédito</span>
            </a>
            
            <!-- RESERVAS - TEAL (PRINCIPAL) - AHORA COLAPSABLE -->
            <?php if ($puede_ver_reservas): ?>
            <div class="space-y-1">
                <!-- Botón principal de Reservas (colapsable) -->
                <button onclick="toggleReservasMenu()" class="w-full flex items-center px-3 py-2.5 text-gray-700 hover:text-teal-700 rounded-lg hover:bg-teal-50 transition-all group border border-transparent hover:border-teal-200">
                    <div class="flex-shrink-0 w-8 h-8 bg-teal-100 rounded-lg flex items-center justify-center group-hover:bg-teal-500 transition-colors mr-3">
                        <i class="fas fa-calendar-alt text-teal-600 group-hover:text-white text-sm"></i>
                    </div>
                    <span class="font-medium text-sm flex-1 text-left">Reservas</span>
                    <i class="fas fa-chevron-down text-gray-500 text-xs transition-transform duration-300 <?php echo $reservas_expandido ? 'rotate-180' : ''; ?>" id="reservas-chevron"></i>
                </button>
                
                <!-- Submenú de Reservas (colapsado por defecto) -->
                <div id="reservas-submenu" class="ml-11 space-y-1 overflow-hidden transition-all duration-300 <?php echo $reservas_expandido ? 'max-h-96 opacity-100 mt-1' : 'max-h-0 opacity-0'; ?>">
                    <!-- Calendario -->
                    <a href="<?php echo BASE_URL; ?>modules/reservas/calendario.php" class="flex items-center px-3 py-2 text-sm text-gray-600 hover:text-teal-600 hover:bg-teal-50 rounded-lg transition-all group border border-transparent hover:border-teal-200">
                        <i class="fas fa-calendar-week mr-3 text-xs w-4 text-gray-500 group-hover:text-teal-600"></i>
                        <span>Calendario</span>
                    </a>
                    
                    <!-- Listado de Reservas (Index) -->
                    <a href="<?php echo BASE_URL; ?>modules/reservas/" class="flex items-center px-3 py-2 text-sm text-gray-600 hover:text-teal-600 hover:bg-teal-50 rounded-lg transition-all group border border-transparent hover:border-teal-200">
                        <i class="fas fa-list mr-3 text-xs w-4 text-gray-500 group-hover:text-teal-600"></i>
                        <span>Listado de Reservas</span>
                    </a>
                    
                    <!-- Nueva Reserva (solo si tiene permiso) -->
                    <?php if ($puede_crear_reservas): ?>
                    <a href="<?php echo BASE_URL; ?>modules/reservas/crear.php" class="flex items-center px-3 py-2 text-sm text-gray-600 hover:text-teal-600 hover:bg-teal-50 rounded-lg transition-all group border border-transparent hover:border-teal-200">
                        <i class="fas fa-plus mr-3 text-xs w-4 text-gray-500 group-hover:text-teal-600"></i>
                        <span>Nueva Reserva</span>
                    </a>
                    <?php endif; ?>
                    
                    <!-- Servicios (solo admin) -->
                    <?php if ($es_admin): ?>
                    <a href="<?php echo BASE_URL; ?>modules/reservas/servicios/" class="flex items-center px-3 py-2 text-sm text-gray-600 hover:text-teal-600 hover:bg-teal-50 rounded-lg transition-all group border border-transparent hover:border-teal-200">
                        <i class="fas fa-cut mr-3 text-xs w-4 text-gray-500 group-hover:text-teal-600"></i>
                        <span>Servicios</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Compras - Púrpura -->
            <a href="<?php echo BASE_URL; ?>modules/compras/" class="flex items-center px-3 py-2.5 text-gray-700 hover:text-purple-700 rounded-lg hover:bg-purple-50 transition-all group border border-transparent hover:border-purple-200">
                <div class="flex-shrink-0 w-8 h-8 bg-purple-100 rounded-lg flex items-center justify-center group-hover:bg-purple-500 transition-colors mr-3">
                    <i class="fas fa-truck text-purple-600 group-hover:text-white text-sm"></i>
                </div>
                <span class="font-medium text-sm">Compras</span>
            </a>
            
            <!-- Inventario - Naranja -->
            <a href="<?php echo BASE_URL; ?>modules/inventario/" class="flex items-center px-3 py-2.5 text-gray-700 hover:text-orange-700 rounded-lg hover:bg-orange-50 transition-all group border border-transparent hover:border-orange-200">
                <div class="flex-shrink-0 w-8 h-8 bg-orange-100 rounded-lg flex items-center justify-center group-hover:bg-orange-500 transition-colors mr-3">
                    <i class="fas fa-boxes text-orange-600 group-hover:text-white text-sm"></i>
                </div>
                <span class="font-medium text-sm">Inventario</span>
            </a>
            
            <!-- Clientes - Amarillo -->
            <a href="<?php echo BASE_URL; ?>modules/clientes/" class="flex items-center px-3 py-2.5 text-gray-700 hover:text-yellow-700 rounded-lg hover:bg-yellow-50 transition-all group border border-transparent hover:border-yellow-200">
                <div class="flex-shrink-0 w-8 h-8 bg-yellow-100 rounded-lg flex items-center justify-center group-hover:bg-yellow-500 transition-colors mr-3">
                    <i class="fas fa-users text-yellow-600 group-hover:text-white text-sm"></i>
                </div>
                <span class="font-medium text-sm">Clientes</span>
            </a>
            
            <!-- Proveedores - Rojo -->
            <a href="<?php echo BASE_URL; ?>modules/proveedores/" class="flex items-center px-3 py-2.5 text-gray-700 hover:text-red-700 rounded-lg hover:bg-red-50 transition-all group border border-transparent hover:border-red-200">
                <div class="flex-shrink-0 w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center group-hover:bg-red-500 transition-colors mr-3">
                    <i class="fas fa-address-card text-red-600 group-hover:text-white text-sm"></i>
                </div>
                <span class="font-medium text-sm">Proveedores</span>
            </a>
            
            <!-- Gastos - Rosa Fuerte -->
            <a href="<?php echo BASE_URL; ?>modules/gastos/" class="flex items-center px-3 py-2.5 text-gray-700 hover:text-rose-700 rounded-lg hover:bg-rose-50 transition-all group border border-transparent hover:border-rose-200">
                <div class="flex-shrink-0 w-8 h-8 bg-rose-100 rounded-lg flex items-center justify-center group-hover:bg-rose-500 transition-colors mr-3">
                    <i class="fas fa-money-bill-wave text-rose-600 group-hover:text-white text-sm"></i>
                </div>
                <span class="font-medium text-sm">Gastos</span>
            </a>
            
            <!-- Reportes - Teal -->
            <a href="<?php echo BASE_URL; ?>modules/reportes/reporte_general.php" class="flex items-center px-3 py-2.5 text-gray-700 hover:text-teal-700 rounded-lg hover:bg-teal-50 transition-all group border border-transparent hover:border-teal-200">
                <div class="flex-shrink-0 w-8 h-8 bg-teal-100 rounded-lg flex items-center justify-center group-hover:bg-teal-500 transition-colors mr-3">
                    <i class="fas fa-chart-bar text-teal-600 group-hover:text-white text-sm"></i>
                </div>
                <span class="font-medium text-sm">Reportes</span>
            </a>
            
            <?php if ($es_admin): ?>
            <!-- Separador de Administración con más espacio -->
            <div class="pt-4 mt-4 border-t border-gray-200">
                <div class="px-3 mb-2">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Administración</p>
                </div>
            </div>
            
            <!-- Usuarios - Índigo -->
            <a href="<?php echo BASE_URL; ?>modules/usuarios/" class="flex items-center px-3 py-2.5 text-gray-700 hover:text-indigo-700 rounded-lg hover:bg-indigo-50 transition-all group border border-transparent hover:border-indigo-200">
                <div class="flex-shrink-0 w-8 h-8 bg-indigo-100 rounded-lg flex items-center justify-center group-hover:bg-indigo-500 transition-colors mr-3">
                    <i class="fas fa-user-cog text-indigo-600 group-hover:text-white text-sm"></i>
                </div>
                <span class="font-medium text-sm">Usuarios</span>
            </a>
            
            <!-- Configuración - Azul Oscuro -->
            <a href="<?php echo BASE_URL; ?>modules/configuracion/" class="flex items-center px-3 py-2.5 text-gray-700 hover:text-blue-700 rounded-lg hover:bg-blue-50 transition-all group border border-transparent hover:border-blue-200">
                <div class="flex-shrink-0 w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center group-hover:bg-blue-500 transition-colors mr-3">
                    <i class="fas fa-cog text-blue-600 group-hover:text-white text-sm"></i>
                </div>
                <span class="font-medium text-sm">Configuración</span>
            </a>
            <?php endif; ?>
        </div>
        
        <!-- Información de usuario en el sidebar (solo para móvil) -->
        <div class="lg:hidden mt-6 pt-4 border-t border-gray-200 px-3">
            <div class="flex items-center">
                <div class="flex-shrink-0 w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center">
                    <i class="fas fa-user text-white text-xs"></i>
                </div>
                <div class="ml-3 flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($_SESSION['usuario_nombre'] ?? 'Usuario'); ?></p>
                    <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($_SESSION['usuario_rol'] ?? ''); ?></p>
                </div>
            </div>
        </div>
    </nav>
</aside>

<style>
/* Pequeño ajuste para que los bordes se vean bien en hover */
.sidebar a {
    border-width: 1px;
    border-style: solid;
    border-color: transparent;
}
.sidebar a:hover {
    border-color: currentColor;
}

/* Transiciones suaves para el submenú */
#reservas-submenu {
    transition: max-height 0.3s ease-in-out, opacity 0.2s ease-in-out, margin 0.2s ease-in-out;
    overflow: hidden;
}

/* Rotación del chevron */
.rotate-180 {
    transform: rotate(180deg);
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            sidebar.classList.toggle('active');
            if (overlay) {
                overlay.classList.toggle('hidden');
            }
        });
    }
    
    if (overlay) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('active');
            overlay.classList.add('hidden');
        });
    }
    
    // Cerrar con Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar && sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
            if (overlay) {
                overlay.classList.add('hidden');
            }
        }
    });
    
    // Marcar el enlace activo en el sidebar
    const currentPath = window.location.pathname;
    document.querySelectorAll('.sidebar a').forEach(link => {
        const href = link.getAttribute('href');
        if (href && currentPath.includes(href) && href !== '#') {
            link.classList.add('bg-opacity-20', 'border-current');
            
            // Si es un enlace del submenú, expandir el menú de reservas
            if (href.includes('/reservas/')) {
                expandirReservas();
            }
        }
    });
});

// Función para toggle del menú de reservas
function toggleReservasMenu() {
    const submenu = document.getElementById('reservas-submenu');
    const chevron = document.getElementById('reservas-chevron');
    
    if (submenu.classList.contains('max-h-0')) {
        // Expandir
        submenu.classList.remove('max-h-0', 'opacity-0');
        submenu.classList.add('max-h-96', 'opacity-100', 'mt-1');
        chevron.classList.add('rotate-180');
        document.cookie = "reservas_menu=expandido; path=/; max-age=31536000"; // Guardar por 1 año
    } else {
        // Colapsar
        submenu.classList.remove('max-h-96', 'opacity-100', 'mt-1');
        submenu.classList.add('max-h-0', 'opacity-0');
        chevron.classList.remove('rotate-180');
        document.cookie = "reservas_menu=colapsado; path=/; max-age=31536000";
    }
}

// Función para expandir el menú (llamada cuando se navega a una página de reservas)
function expandirReservas() {
    const submenu = document.getElementById('reservas-submenu');
    const chevron = document.getElementById('reservas-chevron');
    
    if (submenu && submenu.classList.contains('max-h-0')) {
        submenu.classList.remove('max-h-0', 'opacity-0');
        submenu.classList.add('max-h-96', 'opacity-100', 'mt-1');
        chevron.classList.add('rotate-180');
        document.cookie = "reservas_menu=expandido; path=/; max-age=31536000";
    }
}
</script>