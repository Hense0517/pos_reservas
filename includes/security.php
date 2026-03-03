<?php
// includes/security.php
if (!defined('SECURITY_LOADED')) {
    define('SECURITY_LOADED', true);

    /**
     * Genera un token CSRF único por sesión
     */
    function generarTokenCSRF() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verifica el token CSRF
     */
    function verificarTokenCSRF($token) {
        if (!isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
            error_log("Intento de CSRF detectado desde IP: " . $_SERVER['REMOTE_ADDR']);
            die("Error de validación de seguridad. Por favor recargue la página.");
        }
        return true;
    }

    /**
     * Encripta datos sensibles antes de enviarlos (para AJAX)
     */
    function encriptarDatos($data, $clave = null) {
        if ($clave === null) {
            $clave = $_SERVER['HTTP_USER_AGENT'] . session_id();
        }
        
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt(
            json_encode($data),
            'AES-256-CBC',
            hash('sha256', $clave, true),
            0,
            $iv
        );
        
        return base64_encode($iv . $encrypted);
    }

    /**
     * Desencripta datos recibidos
     */
    function desencriptarDatos($data, $clave = null) {
        if ($clave === null) {
            $clave = $_SERVER['HTTP_USER_AGENT'] . session_id();
        }
        
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        
        return json_decode(
            openssl_decrypt(
                $encrypted,
                'AES-256-CBC',
                hash('sha256', $clave, true),
                0,
                $iv
            ),
            true
        );
    }

    /**
     * Configura headers de seguridad - VERSIÓN CORREGIDA
     * Ahora incluye data: para fuentes y todos los orígenes necesarios
     */
    function configurarHeadersSeguridad() {
        header("X-Frame-Options: DENY");
        header("X-Content-Type-Options: nosniff");
        header("X-XSS-Protection: 1; mode=block");
        header("Referrer-Policy: strict-origin-when-cross-origin");
        
        // CSP CORREGIDA - Incluye data: para fuentes y más orígenes
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com; " .
               "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
               "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; " .
               "img-src 'self' data: https:; " .
               "connect-src 'self' https:; " .
               "frame-src 'none'; " .
               "object-src 'none';";
        
        header("Content-Security-Policy: " . $csp);
        
        // HSTS - Forzar HTTPS (solo si la conexión es HTTPS)
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
        }
    }

    /**
     * Valida que la petición sea POST y del mismo origen
     */
    function validarPeticion() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('HTTP/1.0 405 Method Not Allowed');
            die('Método no permitido');
        }
        
        $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
        $allowed = ['http://localhost', 'http://localhost/pos', 'https://' . $_SERVER['HTTP_HOST']];
        
        $valid = false;
        foreach ($allowed as $a) {
            if (strpos($origin, $a) === 0) {
                $valid = true;
                break;
            }
        }
        
        if (!$valid) {
            error_log("Intento de petición desde origen no permitido: $origin");
            die('Origen no permitido');
        }
    }

    /**
     * Honeypot - campo oculto que solo los bots llenan
     */
    function generarHoneypot() {
        return '<input type="text" name="honeypot" style="display:none" autocomplete="off">';
    }

    /**
     * Verifica honeypot
     */
    function verificarHoneypot() {
        if (!empty($_POST['honeypot'])) {
            error_log("Bot detectado - honeypot llenado desde IP: " . $_SERVER['REMOTE_ADDR']);
            die('Acceso denegado');
        }
    }
}

// EJECUTAR LA FUNCIÓN PARA APLICAR LOS HEADERS
// Esto es crucial - asegura que se apliquen en cada página
configurarHeadersSeguridad();
?>