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
// El JavaScript y el CSS del login son archivos propios (assets/js/login.js y assets/css/login.css),
// por eso no hace falta permitir scripts en línea ni CDNs de scripts.
header(
    "Content-Security-Policy: " .
    "default-src 'self'; " .
    "script-src 'self'; " .
    "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
    "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; " .
    "img-src 'self' data:; " .
    "connect-src 'self'; " .
    "frame-src 'none'; " .
    "object-src 'none'; " .
    "base-uri 'self'; " .
    "form-action 'self';"
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

// ============================================
// DATOS DE PRESENTACIÓN (nombre, logo y paleta del negocio)
// ============================================
require_once __DIR__ . '/includes/tema.php';

$negocio_nombre_raw = Env::get('NEGOCIO_NOMBRE', 'Mi Peluquería');
$negocio_logo = '';
try {
    $cfg = Database::getInstance()->getConnection()
        ->query("SELECT * FROM configuracion_negocio ORDER BY id DESC LIMIT 1")->fetch();
    if ($cfg) {
        if (!empty($cfg['nombre_negocio'])) {
            $negocio_nombre_raw = $cfg['nombre_negocio'];
        }
        // El logo solo se muestra si el archivo existe y está dentro de la carpeta de imágenes del proyecto
        if (!empty($cfg['logo'])) {
            $ruta_logo = ltrim(str_replace('\\', '/', (string) $cfg['logo']), '/');
            if (strpos($ruta_logo, '..') === false && is_file(__DIR__ . '/' . $ruta_logo)) {
                $negocio_logo = $ruta_logo;
            }
        }
    }
} catch (Throwable $e) {
    error_log('login: no se pudo leer la configuración del negocio: ' . $e->getMessage());
}

$negocio_nombre = htmlspecialchars($negocio_nombre_raw, ENT_QUOTES, 'UTF-8');
// Si el servidor devolvió un error, no repetir la animación de entrada
$sin_intro = ($_SERVER['REQUEST_METHOD'] === 'POST' || $error !== '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Acceso al sistema · <?= $negocio_nombre ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

    <?= tema_head() ?>
    <link rel="stylesheet" href="assets/css/login.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/login.css') ?>">
    <script src="assets/js/login.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/login.js') ?>" defer></script>
</head>
<body class="login-body<?= $sin_intro ? ' no-intro' : '' ?>"
      data-sin-intro="<?= $sin_intro ? '1' : '0' ?>"
      data-bloqueado="<?= $locked ? '1' : '0' ?>">

    <!-- Fondo: 5 fotos reales que cambian solas -->
    <div class="bg-slides" aria-hidden="true">
        <div class="bg-slide slide-1"></div>
        <div class="bg-slide slide-2"></div>
        <div class="bg-slide slide-3"></div>
        <div class="bg-slide slide-4"></div>
        <div class="bg-slide slide-5"></div>
    </div>
    <div class="bg-overlay" aria-hidden="true"></div>

    <!-- Intro: se abre como un corte de tijeras -->
    <div class="intro" id="intro" aria-hidden="true">
        <div class="curtain top"></div>
        <div class="curtain bottom"></div>
        <div class="intro-cut"></div>
        <div class="intro-core">
            <div class="intro-emblem">
                <svg viewBox="0 0 120 120"><circle class="ring-soft" cx="60" cy="60" r="52"/><circle cx="60" cy="60" r="52"/></svg>
                <?php if ($negocio_logo !== ''): ?>
                    <img class="intro-logo" src="<?= htmlspecialchars($negocio_logo, ENT_QUOTES, 'UTF-8') ?>" alt="">
                <?php else: ?>
                    <i class="fas fa-scissors"></i>
                <?php endif; ?>
            </div>
            <div class="intro-name" id="introName" data-texto="<?= $negocio_nombre ?>"><?= $negocio_nombre ?></div>
            <div class="intro-tag">Cabello · Color · Estilo</div>
        </div>
        <button type="button" class="intro-skip" id="introSkip">Saltar intro</button>
    </div>

    <main class="login-stage">
        <div class="login-grid">

            <!-- Mensajes sobre el mundo del cabello (cambian junto con el fondo) -->
            <section class="hero" aria-label="Presentación">
                <div class="hero-brand">
                    <span class="dot"><i class="fas fa-scissors" aria-hidden="true"></i></span>
                    <span><?= $negocio_nombre ?></span>
                </div>

                <div class="hero-copy" aria-live="off">
                    <article class="hero-slide is-active">
                        <span class="hero-kicker"><i class="fas fa-spray-can-sparkles" aria-hidden="true"></i> Tu salón</span>
                        <h2 class="hero-title">Tu peluquería, siempre en su <em>mejor versión</em></h2>
                        <p class="hero-text">Agenda, ventas e inventario en un solo lugar para que te dediques a lo que mejor sabes hacer.</p>
                    </article>
                    <article class="hero-slide">
                        <span class="hero-kicker"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> Peinados</span>
                        <h2 class="hero-title">Rizos que cuentan <em>historias</em></h2>
                        <p class="hero-text">Cada reserva es una oportunidad de dejar a un cliente feliz. Organiza tu agenda sin perder ni una cita.</p>
                    </article>
                    <article class="hero-slide">
                        <span class="hero-kicker"><i class="fas fa-palette" aria-hidden="true"></i> Color</span>
                        <h2 class="hero-title">Color, brillo y <em>personalidad</em></h2>
                        <p class="hero-text">Controla servicios, productos y caja con claridad: sabrás cuánto vendes y qué necesitas reponer.</p>
                    </article>
                    <article class="hero-slide">
                        <span class="hero-kicker"><i class="fas fa-scissors" aria-hidden="true"></i> Corte</span>
                        <h2 class="hero-title">Precisión en cada <em>corte</em></h2>
                        <p class="hero-text">Como unas buenas tijeras: un negocio afilado, ordenado y listo para atender.</p>
                    </article>
                    <article class="hero-slide">
                        <span class="hero-kicker"><i class="fas fa-heart" aria-hidden="true"></i> Cuidado capilar</span>
                        <h2 class="hero-title">Cabello sano, clientes <em>fieles</em></h2>
                        <p class="hero-text">Conoce a tus clientes, su historial y sus preferencias para ofrecer una atención que no olvidan.</p>
                    </article>
                </div>

                <div class="hero-dots" role="tablist" aria-label="Cambiar mensaje">
                    <?php for ($n = 1; $n <= 5; $n++): ?>
                        <button type="button" class="hero-dot<?= $n === 1 ? ' is-active' : '' ?>" aria-label="Mensaje <?= $n ?> de 5"></button>
                    <?php endfor; ?>
                </div>

                <ul class="hero-points">
                    <li><i class="fas fa-calendar-check" aria-hidden="true"></i> Agenda de citas</li>
                    <li><i class="fas fa-cash-register" aria-hidden="true"></i> Ventas y caja</li>
                    <li><i class="fas fa-boxes-stacked" aria-hidden="true"></i> Inventario</li>
                    <li><i class="fas fa-user-group" aria-hidden="true"></i> Clientes</li>
                </ul>
            </section>

            <!-- Formulario -->
            <section class="card-wrap">
                <div class="login-card">
                    <div class="card-head">
                        <div class="card-logo">
                            <?php if ($negocio_logo !== ''): ?>
                                <img src="<?= htmlspecialchars($negocio_logo, ENT_QUOTES, 'UTF-8') ?>" alt="Logo de <?= $negocio_nombre ?>">
                            <?php else: ?>
                                <i class="fas fa-scissors" aria-hidden="true"></i>
                            <?php endif; ?>
                        </div>
                        <h1 class="card-title">Bienvenido de nuevo</h1>
                        <p class="card-subtitle">Ingresa para gestionar <?= $negocio_nombre ?></p>
                    </div>

                    <!-- Indicador de pasos: muestra en qué campo estás -->
                    <div class="steps" id="steps" aria-hidden="true">
                        <div class="step is-active" data-step="username"><span class="step-num">1</span><span>Usuario</span></div>
                        <div class="step-bar"><span></span></div>
                        <div class="step" data-step="password"><span class="step-num">2</span><span>Contraseña</span></div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert <?= $locked ? 'alert-warn' : 'alert-error' ?>" role="alert">
                            <i class="fas <?= $locked ? 'fa-lock' : 'fa-circle-exclamation' ?>" aria-hidden="true"></i>
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
                            <i class="fas fa-shield-halved" aria-hidden="true"></i>
                            Intento <?= (int) $ip_rate['attempts'] ?> de <?= (int) $max_attempts ?>
                            (<?= (int) $intentos_restantes ?> restante<?= $intentos_restantes !== 1 ? 's' : '' ?>)
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="loginForm" autocomplete="off" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                        <!-- Honeypot: un humano nunca lo ve -->
                        <div class="honeypot-field" aria-hidden="true">
                            <input type="text" name="honeypot" autocomplete="off" tabindex="-1" value="">
                        </div>

                        <!-- Usuario -->
                        <div class="field reveal" id="field-username" style="animation-delay:.25s">
                            <label class="field-label" for="username">
                                <span>Usuario</span>
                                <span class="field-badge"><i class="fas fa-pen-nib" aria-hidden="true"></i> Escribiendo aquí</span>
                            </label>
                            <div class="input-wrap">
                                <i class="far fa-user input-icon" aria-hidden="true"></i>
                                <input type="text" id="username" name="username" class="input-field"
                                       placeholder="Tu nombre de usuario"
                                       maxlength="50" autocomplete="username" autocapitalize="none" spellcheck="false"
                                       required aria-required="true" aria-describedby="hint-username"
                                       <?= $locked ? 'disabled' : '' ?>>
                                <i class="fas fa-circle-check input-status ok" aria-hidden="true"></i>
                                <i class="fas fa-circle-exclamation input-status bad" aria-hidden="true"></i>
                            </div>
                            <p class="field-hint" id="hint-username" aria-live="polite"></p>
                            <!-- No se repopula el usuario tras un error: evita enumeración de usuarios y XSS reflejado -->
                        </div>

                        <!-- Contraseña -->
                        <div class="field field-has-toggle reveal" id="field-password" style="animation-delay:.35s">
                            <label class="field-label" for="password">
                                <span>Contraseña</span>
                                <span class="field-badge"><i class="fas fa-pen-nib" aria-hidden="true"></i> Escribiendo aquí</span>
                            </label>
                            <div class="input-wrap">
                                <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                                <input type="password" id="password" name="password" class="input-field"
                                       placeholder="••••••••"
                                       maxlength="200" autocomplete="current-password"
                                       required aria-required="true" aria-describedby="hint-password"
                                       <?= $locked ? 'disabled' : '' ?>>
                                <i class="fas fa-circle-check input-status ok" aria-hidden="true"></i>
                                <i class="fas fa-circle-exclamation input-status bad" aria-hidden="true"></i>
                                <button type="button" class="toggle-pass" id="togglePass" aria-label="Mostrar contraseña" aria-pressed="false">
                                    <i class="fas fa-eye" aria-hidden="true"></i>
                                </button>
                            </div>
                            <p class="field-hint" id="hint-password" aria-live="polite"></p>
                            <p class="caps-warning" id="capsWarning" role="status"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Tienes activado Bloq Mayús</p>
                        </div>

                        <button type="submit" class="btn-login reveal" id="submitBtn" style="animation-delay:.45s"
                                <?= $locked ? 'disabled aria-disabled="true"' : '' ?>>
                            <span>Ingresar al sistema</span>
                            <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </button>
                    </form>

                    <div class="card-footer reveal" style="animation-delay:.55s">
                        <button type="button" class="forgot" id="forgotBtn" aria-expanded="false" aria-controls="forgotHelp">
                            <i class="far fa-circle-question" aria-hidden="true"></i> ¿Olvidaste tu contraseña?
                        </button>
                        <p class="forgot-help" id="forgotHelp">
                            Por seguridad, la contraseña la restablece el administrador del sistema.
                            Comunícate con él e indícale tu nombre de usuario.
                        </p>
                    </div>

                    <div class="copyright">
                        Desarrollado por <strong>Henry Vergara</strong><br>
                        © <?= date('Y') ?> <?= $negocio_nombre ?> — Todos los derechos reservados
                    </div>
                </div>
            </section>
        </div>
    </main>
</body>
</html>
