<?php
// check_permissions.php
session_start();
require_once '../../config/database.php';
require_once '../../config/auth.php';
require_once '../../config/permisos.php';

$database = Database::getInstance();
$auth = new Auth($database);

// Obtener el nombre del módulo actual
$current_page = basename($_SERVER['PHP_SELF']);
$module_name = getModuleName($current_page);

// Función para mapear páginas a módulos
function getModuleName($page) {
    $module_map = [
        'index.php' => 'dashboard',
        'productos.php' => 'productos',
        'ventas.php' => 'ventas',
        'clientes.php' => 'clientes',
        // Agrega más mapeos según tu estructura
    ];
    
    return $module_map[$page] ?? str_replace('.php', '', $page);
}

// Verificar si el usuario tiene permiso para ver esta página
if (!$auth->hasPermission($module_name, 'leer')) {
    $_SESSION['error'] = "No tienes permiso para acceder a esta sección";
    header("Location: ../../index.php");
    exit;
}
?>