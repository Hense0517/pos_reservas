<?php
// Auto-fixed: 2026-02-17 01:57:21
require_once 'includes/config.php';
// includes/check_session.php
// Verificar sesión y permisos en cada página

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar que el usuario esté autenticado
if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: /pos/login.php');
    exit;
}

// Verificar timeout de sesión (60 minutos)
$timeout_minutes = 60;
if (isset($_SESSION['login_time'])) {
    $elapsed_time = time() - $_SESSION['login_time'];
    $timeout_seconds = $timeout_minutes * 60;
    
    if ($elapsed_time > $timeout_seconds) {
        // Cerrar sesión
        session_destroy();
        header('Location: /pos/login.php?timeout=1');
        exit;
    }
    
    // Renovar tiempo de sesión
    $_SESSION['login_time'] = time();
}

// Obtener información del usuario
$user_info = [
    'id' => $_SESSION['user_id'] ?? null,
    'username' => $_SESSION['username'] ?? '',
    'nombre' => $_SESSION['nombre'] ?? '',
    'email' => $_SESSION['email'] ?? '',
    'rol' => $_SESSION['rol'] ?? ''
];

// Incluir configuraciones
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';

$database = Database::getInstance();
$auth = new Auth($database);
?>