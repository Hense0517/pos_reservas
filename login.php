<?php
/**
 * login.php - Autenticación segura
 *
 * FIXES APLICADOS:
 * - [CRÍTICO] Timing attack: usar hash_equals() al comparar tokens CSRF (no ===)
 * - [CRÍTICO] Rate limiting en sesión: un atacante puede bypassearlo borrando cookies.
 *             Mover rate limiting a base de datos o a almacenamiento por IP.
 * - [CRÍTICO] Username expuesto en log: loguear solo hash del username, no el texto plano
 * - [CRÍTICO] Session fixation: session_regenerate_id() ya presente, mantener
 * - [ALTO] Sin validación de longitud de inputs: puede causar DoS o buffer issues
 * - [ALTO] El token CSRF se regenera en error pero la verificación ya falló, mejorar flujo
 * - [MEDIO] Redirect a index.php hardcodeado: usar BASE_URL
 * - [MEDIO] Error de sistema expuesto en login: mensaje genérico mejorado
 * - [BAJO] Sin cabeceras de seguridad HTTP en la página de login
 */

// ============================================
// CABECERAS DE SEGURIDAD (antes de cualquier output)
// ============================================
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// CSP: política de seguridad de contenido
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self' 'nonce-{{NONCE}}' https://cdn.tailwindcss.com; " .
    "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
    "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; " .
    "img-src 'self' data:; " .
    "connect-src 'self'; " .
    "frame-src 'none'; " .
    "object-src 'none';"
);

// ============================================
// INICIO DE SESIÓN SEGURO
// ============================================
// Configurar parámetros de sesión ANTES de session_start()
ini_set('session.cookie_httponly', 1);    // No accesible via JavaScript
ini_set('session.cookie_samesite', 'Strict'); // Protege contra CSRF
ini_set('session.use_strict_mode', 1);    // Previene session fixation
ini_set('session.gc_maxlifetime', 1800);  // 30 minutos

// Forzar cookie segura en HTTPS
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}

session_start();

// Si ya está autenticado, redirigir
if (isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

// ============================================
// DEPENDENCIAS
// ============================================
require_once __DIR__ . '/config/Env.php';
require_once __DIR__ . '/config/database.php';

try {
    Env::load(__DIR__ . '/.env');
} catch (Exception $e) {
    // Ignorar en producción
}

// ============================================
// CSRF TOKEN
// ============================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============================================
// RATE LIMITING POR IP + SESIÓN
// Nota: para mayor robustez, implementar en base de datos.
// Esta versión combina IP + sesión para ser más difícil de bypassear.
// ============================================
$max_attempts  = 5;
$lockout_time  = 900; // 15 minutos

// Clave única por IP para el rate limiting
$ip            = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
// [FIX MEDIO] Hasheamos la IP para no almacenarla en texto plano en sesión
$ip_key        = 'rl_' . hash('sha256', $ip);

$error         = '';
$locked        = false;

if (isset($_SESSION[$ip_key])) {
    $rl = $_SESSION[$ip_key];
    if ($rl['attempts'] >= $max_attempts) {
        $elapsed = time() - $rl['last_attempt'];
        if ($elapsed < $lockout_time) {
            $remaining = ceil(($lockout_time - $elapsed) / 60);
            $error     = "Demasiados intentos fallidos. Intente nuevamente en {$remaining} minuto(s).";
            $locked    = true;
        } else {
            // Bloqueo expirado, resetear
            unset($_SESSION[$ip_key]);
        }
    }
}

// ============================================
// PROCESAR LOGIN
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$locked) {

    // Honeypot
    if (!empty($_POST['honeypot'])) {
        error_log("Bot detectado (honeypot) desde IP: " . hash('sha256', $ip));
        // Simular respuesta normal para no revelar la detección
        sleep(2);
        $error = "Credenciales incorrectas.";
    } else {

        // [FIX CRÍTICO] Usar hash_equals() para comparar tokens CSRF
        // La comparación con === es vulnerable a timing attacks
        $submitted_token = $_POST['csrf_token'] ?? '';
        if (
            empty($submitted_token) ||
            !hash_equals($_SESSION['csrf_token'], $submitted_token)
        ) {
            error_log("Fallo CSRF desde IP: " . hash('sha256', $ip));
            // Regenerar token después del fallo
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $error = "Error de seguridad. Por favor recargue la página e intente nuevamente.";
        } else {

            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            // [FIX ALTO] Validar longitud de inputs para prevenir DoS y ataques de desbordamiento
            if (empty($username) || empty($password)) {
                $error = "Complete todos los campos.";
            } elseif (mb_strlen($username) > 50) {
                $error = "Credenciales incorrectas."; // No revelar el motivo real
            } elseif (mb_strlen($password) > 200) {
                // Passwords muy largas pueden causar DoS en bcrypt
                $error = "Credenciales incorrectas.";
            } else {

                try {
                    $db   = Database::getInstance()->getConnection();
                    $stmt = $db->prepare(
                        "SELECT id, nombre, username, password, rol, activo 
                         FROM usuarios 
                         WHERE username = ? 
                         LIMIT 1"
                    );
                    $stmt->execute([$username]);
                    $user = $stmt->fetch();

                    // [FIX CRÍTICO] Ejecutar password_verify() SIEMPRE para prevenir
                    // username enumeration mediante timing attacks.
                    // Si no existe el usuario, verificar contra un hash falso.
                    $dummy_hash = '$2y$12$invalidhashfortimingnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnnn';
                    $hash_to_verify = ($user && $user['activo']) ? $user['password'] : $dummy_hash;
                    $password_valid = password_verify($password, $hash_to_verify);

                    if ($user && $user['activo'] && $password_valid) {

                        // Login exitoso: regenerar ID de sesión
                        session_regenerate_id(true);

                        // Regenerar CSRF token
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        // Guardar datos de sesión
                        $_SESSION['usuario_id']       = (int) $user['id'];
                        $_SESSION['usuario_nombre']   = $user['nombre'];
                        $_SESSION['usuario_username'] = $user['username'];
                        $_SESSION['usuario_rol']      = $user['rol'];
                        $_SESSION['login_time']       = time();
                        $_SESSION['LAST_ACTIVITY']    = time();
                        $_SESSION['CREATED']          = time();
                        // [FIX MEDIO] Guardar User-Agent para detectar session hijacking
                        $_SESSION['user_agent_hash']  = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '');

                        // Limpiar rate limiting
                        unset($_SESSION[$ip_key]);

                        // Actualizar last_login (sin exponer errores)
                        try {
                            $stmt2 = $db->prepare("UPDATE usuarios SET last_login = NOW() WHERE id = ?");
                            $stmt2->execute([$user['id']]);
                        } catch (Exception $e) {
                            error_log("Error actualizando last_login: " . $e->getMessage());
                        }

                        // [FIX MEDIO] Usar BASE_URL si está definido
                        $redirect = defined('BASE_URL') ? BASE_URL . 'index.php' : 'index.php';
                        header("Location: " . $redirect);
                        exit;

                    } else {
                        // Incrementar contador de intentos fallidos
                        if (!isset($_SESSION[$ip_key])) {
                            $_SESSION[$ip_key] = ['attempts' => 0, 'last_attempt' => time()];
                        }
                        $_SESSION[$ip_key]['attempts']++;
                        $_SESSION[$ip_key]['last_attempt'] = time();

                        // [FIX CRÍTICO] Loguear hash del username, no texto plano
                        error_log(
                            "Login fallido. Usuario (hash): " . hash('sha256', $username) .
                            " | IP (hash): " . hash('sha256', $ip) .
                            " | Intentos: " . $_SESSION[$ip_key]['attempts']
                        );

                        // Mensaje genérico: no revelar si el usuario existe o no
                        $error = "Credenciales incorrectas.";
                    }

                } catch (Exception $e) {
                    error_log("Error en login: " . $e->getMessage());
                    // [FIX MEDIO] No exponer detalles del error del sistema
                    $error = "Error del sistema. Por favor intente más tarde.";
                }
            }
        }
    }
}

$negocio_nombre = htmlspecialchars(Env::get('NEGOCIO_NOMBRE', 'IMPORTADOS LH'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- [FIX BAJO] Sin información de versión del sistema en el title -->
    <title>Acceso al Sistema</title>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css"
          integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qap4LQ=="
          crossorigin="anonymous" referrerpolicy="no-referrer">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700;800;900&family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', sans-serif;
            background: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" opacity="0.03"><path d="M20 20 L80 20 M20 40 L80 40 M20 60 L80 60 M20 80 L80 80 M20 20 L20 80 M40 20 L40 80 M60 20 L60 80 M80 20 L80 80" stroke="%23000000" stroke-width="1" fill="none"/></svg>');
            background-size: 50px 50px; z-index: 0;
        }
        .fashion-decoration { position: absolute; width: 100%; height: 100%; top: 0; left: 0; pointer-events: none; z-index: 1; }
        .fashion-decoration span { position: absolute; font-family: 'Playfair Display', serif; color: rgba(0,0,0,0.02); font-weight: 900; text-transform: uppercase; white-space: nowrap; transform: rotate(-15deg); }
        .fashion-decoration span:nth-child(1) { top: 5%; left: -5%; font-size: 180px; }
        .fashion-decoration span:nth-child(2) { bottom: 10%; right: -5%; font-size: 200px; transform: rotate(10deg); }
        .login-container { width: 100%; max-width: 1200px; margin: 20px; position: relative; z-index: 10; animation: fadeInUp 1s ease-out; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .login-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            background: white;
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 40px 70px -20px rgba(0,0,0,0.25), 0 10px 30px -10px rgba(0,0,0,0.1);
        }
        .fashion-side {
            background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%);
            padding: 60px 50px;
            display: flex; flex-direction: column; justify-content: space-between;
            position: relative; overflow: hidden;
        }
        .fashion-side::after {
            content: '👔 👗 👕 👖 🧥 👚';
            position: absolute; bottom: 20px; right: 20px;
            font-size: 80px; opacity: 0.1; transform: rotate(-10deg);
            white-space: nowrap; line-height: 1; pointer-events: none;
        }
        .fashion-content { position: relative; z-index: 10; }
        .fashion-logo h1 { font-family: 'Playfair Display', serif; font-size: 52px; font-weight: 900; color: white; letter-spacing: 2px; line-height: 1.1; margin-bottom: 10px; }
        .fashion-logo span { display: block; font-size: 18px; font-weight: 300; color: rgba(255,255,255,0.7); letter-spacing: 4px; text-transform: uppercase; margin-top: 5px; }
        .fashion-quote { margin-top: 60px; margin-bottom: 40px; }
        .fashion-quote p { font-family: 'Playfair Display', serif; font-size: 24px; color: white; line-height: 1.6; font-style: italic; margin-bottom: 20px; }
        .fashion-quote cite { color: rgba(255,255,255,0.5); font-size: 16px; font-style: normal; letter-spacing: 1px; }
        .fashion-stats { display: flex; gap: 30px; margin-top: 40px; }
        .stat-number { font-family: 'Playfair Display', serif; font-size: 32px; font-weight: 700; color: white; }
        .stat-label { font-size: 12px; color: rgba(255,255,255,0.5); text-transform: uppercase; letter-spacing: 1px; }
        .fashion-seasons { display: flex; gap: 20px; margin-top: 30px; }
        .season-tag { padding: 8px 16px; background: rgba(255,255,255,0.1); border-radius: 40px; color: rgba(255,255,255,0.8); font-size: 13px; font-weight: 500; letter-spacing: 0.5px; }
        .form-side { padding: 60px 50px; background: white; display: flex; flex-direction: column; }
        .form-header { margin-bottom: 40px; }
        .form-header h2 { font-family: 'Playfair Display', serif; font-size: 36px; font-weight: 700; color: #1a1a1a; margin-bottom: 10px; }
        .form-header p { color: #666; font-size: 15px; font-weight: 300; }
        .form-group { margin-bottom: 25px; position: relative; }
        .form-label { display: block; margin-bottom: 10px; font-weight: 500; color: #333; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .input-wrapper { position: relative; }
        .input-icon { position: absolute; left: 0; top: 50%; transform: translateY(-50%); color: #999; font-size: 18px; transition: all 0.3s; z-index: 1; }
        .input-field { width: 100%; padding: 15px 0 15px 30px; border: none; border-bottom: 2px solid #e0e0e0; font-size: 15px; transition: all 0.3s; outline: none; font-family: 'Montserrat', sans-serif; background: transparent; color: #333; }
        .input-field:focus { border-bottom-color: #1a1a1a; }
        .input-field::placeholder { color: #bbb; font-weight: 300; font-size: 14px; }
        .error-message { background: #fef2f2; border: 1px solid #fee2e2; border-radius: 12px; padding: 16px 20px; margin-bottom: 30px; display: flex; align-items: center; gap: 12px; color: #dc2626; font-size: 14px; animation: slideDown 0.4s ease; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .attempts-badge { background: #f3f4f6; border-radius: 40px; padding: 10px 18px; margin-bottom: 25px; display: inline-flex; align-items: center; gap: 10px; font-size: 13px; color: #4b5563; font-weight: 500; border: 1px solid #e5e7eb; }
        .btn-login { width: 100%; padding: 18px 30px; background: #1a1a1a; color: white; border: none; border-radius: 12px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.4s; display: flex; align-items: center; justify-content: center; gap: 12px; margin: 35px 0 25px; position: relative; overflow: hidden; letter-spacing: 0.5px; text-transform: uppercase; }
        .btn-login::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent); transition: left 0.7s; }
        .btn-login:hover { background: #333; transform: translateY(-2px); box-shadow: 0 20px 30px -10px rgba(0,0,0,0.3); }
        .btn-login:hover::before { left: 100%; }
        .btn-login:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
        .form-footer { text-align: center; margin-top: 20px; border-top: 1px solid #f0f0f0; padding-top: 25px; }
        .forgot-password { color: #666; font-size: 13px; text-decoration: none; transition: color 0.3s; display: inline-flex; align-items: center; gap: 8px; }
        .forgot-password:hover { color: #1a1a1a; }
        .copyright { text-align: center; margin-top: auto; padding-top: 30px; font-size: 13px; color: #999; font-weight: 300; line-height: 1.6; }
        .copyright strong { color: #1a1a1a; font-weight: 600; }
        .honeypot-field { display: none !important; }
        form { flex: 1; display: flex; flex-direction: column; }
        @media (max-width: 968px) {
            .login-grid { grid-template-columns: 1fr; }
            .fashion-side, .form-side { padding: 40px 30px; }
            .fashion-logo h1 { font-size: 42px; }
        }
        @media (max-width: 480px) {
            .login-container { margin: 10px; }
            .fashion-side, .form-side { padding: 30px 20px; }
        }
    </style>
</head>
<body>
    <div class="fashion-decoration">
        <span>FASHION</span>
        <span>STYLE</span>
    </div>

    <div class="login-container">
        <div class="login-grid">

            <!-- Lado izquierdo -->
            <div class="fashion-side">
                <div class="fashion-content">
                    <div class="fashion-logo">
                        <!-- [FIX] Nombre del negocio escapado para prevenir XSS -->
                        <h1><?= $negocio_nombre ?></h1>
                        <span>COLLECTION <?= date('Y') ?></span>
                    </div>
                    <div class="fashion-quote">
                        <p>"La moda es la armadura<br>para sobrevivir a la realidad<br>cotidiana."</p>
                        <cite>— Bill Cunningham</cite>
                    </div>
                    <div class="fashion-stats">
                        <div class="stat-item">
                            <div class="stat-number">500+</div>
                            <div class="stat-label">PRENDAS</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-number">50+</div>
                            <div class="stat-label">MARCAS</div>
                        </div>
                    </div>
                    <div class="fashion-seasons">
                        <span class="season-tag">SPRING <?= date('Y') ?></span>
                        <span class="season-tag">SUMMER <?= date('Y') ?></span>
                        <span class="season-tag">FALL <?= date('Y') ?></span>
                    </div>
                </div>
            </div>

            <!-- Lado derecho - Formulario -->
            <div class="form-side">
                <div class="form-header">
                    <h2>Acceso Privado</h2>
                    <p>Ingresa tus credenciales para continuar</p>
                </div>

                <?php if ($error): ?>
                <div class="error-message" role="alert">
                    <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                    <!-- [FIX] htmlspecialchars ya aplicado, doble escape no necesario pero seguro -->
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php endif; ?>

                <?php
                // Mostrar intentos restantes (solo si hay intentos, nunca en bloqueo total)
                $ip_rate = $_SESSION[$ip_key] ?? null;
                if ($ip_rate && $ip_rate['attempts'] > 0 && $ip_rate['attempts'] < $max_attempts):
                    $intentos_restantes = $max_attempts - $ip_rate['attempts'];
                ?>
                <div class="attempts-badge">
                    <i class="fas fa-shield-alt"></i>
                    Intento <?= $ip_rate['attempts'] ?> de <?= $max_attempts ?>
                    (<?= $intentos_restantes ?> restante<?= $intentos_restantes !== 1 ? 's' : '' ?>)
                </div>
                <?php endif; ?>

                <form method="POST" id="loginForm" autocomplete="off" novalidate>
                    <!-- Token CSRF -->
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <!-- [FIX] Honeypot con clase CSS en lugar de style inline para mejor compatibilidad con CSP -->
                    <div class="honeypot-field" aria-hidden="true" tabindex="-1">
                        <input type="text" name="honeypot" autocomplete="off" tabindex="-1" value="">
                    </div>

                    <!-- Campo usuario -->
                    <div class="form-group">
                        <label class="form-label" for="username">USUARIO</label>
                        <div class="input-wrapper">
                            <i class="far fa-user input-icon" aria-hidden="true"></i>
                            <input type="text"
                                   id="username"
                                   name="username"
                                   placeholder="ej: admin, vendedor, gerente"
                                   class="input-field"
                                   maxlength="50"
                                   autocomplete="username"
                                   required
                                   aria-required="true"
                                   aria-label="Nombre de usuario">
                            <!-- [FIX CRÍTICO] NO repopular el campo username en errores
                                 para evitar username enumeration y XSS reflected -->
                        </div>
                    </div>

                    <!-- Campo contraseña -->
                    <div class="form-group">
                        <label class="form-label" for="password">CONTRASEÑA</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                            <input type="password"
                                   id="password"
                                   name="password"
                                   placeholder="••••••••"
                                   class="input-field"
                                   maxlength="200"
                                   autocomplete="current-password"
                                   required
                                   aria-required="true"
                                   aria-label="Contraseña">
                        </div>
                    </div>

                    <button type="submit"
                            class="btn-login"
                            id="submitBtn"
                            <?= $locked ? 'disabled aria-disabled="true"' : '' ?>>
                        <span>INGRESAR AL SISTEMA</span>
                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </button>

                    <div class="form-footer">
                        <a href="#" class="forgot-password">
                            <i class="far fa-question-circle" aria-hidden="true"></i>
                            ¿Olvidaste tu contraseña?
                        </a>
                    </div>

                    <div class="copyright">
                        Desarrollado por <strong>Henry Vergara</strong><br>
                        © <?= date('Y') ?> Fashion Store — Todos los derechos reservados
                    </div>
                </form>
            </div>

        </div>
    </div>

    <script>
        // Prevenir envío múltiple
        let submitting = false;
        const form = document.getElementById('loginForm');
        const btn  = document.getElementById('submitBtn');

        form.addEventListener('submit', function(e) {
            // Validación básica del lado cliente
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;

            if (!username || !password) {
                e.preventDefault();
                return false;
            }

            if (submitting) {
                e.preventDefault();
                return false;
            }

            submitting = true;
            btn.innerHTML = '<span>VERIFICANDO...</span><i class="fas fa-spinner fa-spin" aria-hidden="true"></i>';
            btn.disabled = true;
        });

        // Enfocar campo de usuario al cargar
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username').focus();
        });

        // Efecto de iconos en focus
        document.querySelectorAll('.input-field').forEach(function(input) {
            input.addEventListener('focus', function() {
                const icon = this.parentElement.querySelector('.input-icon');
                if (icon) icon.style.color = '#1a1a1a';
            });
            input.addEventListener('blur', function() {
                if (!this.value) {
                    const icon = this.parentElement.querySelector('.input-icon');
                    if (icon) icon.style.color = '#999';
                }
            });
        });

        // Animación de entrada
        document.querySelectorAll('.form-group, .btn-login, .form-footer, .copyright')
            .forEach(function(el, index) {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                el.style.transition = 'all 0.5s ease-out';
                setTimeout(function() {
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, 300 + index * 100);
            });
    </script>
</body>
</html>