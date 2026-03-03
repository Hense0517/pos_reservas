<?php
// fix_all_files.php - CORRECTOR AUTOMÁTICO
// Ejecuta esto UNA SOLA VEZ y corregirá todos los archivos

echo "<h1>🔧 Corrigiendo todos los archivos automáticamente...</h1>";

function fixFile($filepath) {
    $content = file_get_contents($filepath);
    $original = $content;
    $changed = false;
    
    // 1. Reemplazar 'new Database()' por 'Database::getInstance()'
    $patterns = [
        '/new\s+Database\s*\(\)/' => 'Database::getInstance()',
        '/new\s+Database\s*\([^)]*\)/' => 'Database::getInstance()',
        '/\$db\s*=\s*new\s+Database\s*\(\)/' => '$db = Database::getInstance()',
        '/\$database\s*=\s*new\s+Database\s*\(\)/' => '$database = Database::getInstance()',
    ];
    
    foreach ($patterns as $pattern => $replacement) {
        $new_content = preg_replace($pattern, $replacement, $content);
        if ($new_content !== $content) {
            $content = $new_content;
            $changed = true;
        }
    }
    
    // 2. Verificar que tenga el include correcto
    $relative_path = getRelativePath($filepath);
    
    if (strpos($content, "require_once '") === false && 
        strpos($content, "require_once \"") === false &&
        strpos($content, "include '") === false &&
        strpos($content, "include \"") === false) {
        
        // No tiene include, agregarlo después del primer <?php
        $include_line = "require_once '$relative_path';";
        $content = preg_replace(
            '/<\?php/',
            "<?php\n// Auto-fixed: " . date('Y-m-d H:i:s') . "\n" . $include_line,
            $content,
            1
        );
        $changed = true;
    }
    
    // 3. Corregir includes incorrectos
    $content = preg_replace(
        "/require_once\s+['\"]\.\.\/config\/config\.php['\"]/",
        "require_once '../../includes/config.php'",
        $content
    );
    
    $content = preg_replace(
        "/require_once\s+['\"]\.\.\/includes\/config\.php['\"]/",
        "require_once '../../includes/config.php'",
        $content
    );
    
    $content = preg_replace(
        "/require_once\s+['\"]config\.php['\"]/",
        "require_once 'includes/config.php'",
        $content
    );
    
    // 4. Agregar verificación de sesión si no existe
    if (strpos($content, 'session_start()') === false && 
        strpos($content, '$_SESSION') !== false) {
        $content = preg_replace(
            '/<\?php/',
            "<?php\nif (session_status() === PHP_SESSION_NONE) session_start();",
            $content,
            1
        );
        $changed = true;
    }
    
    // Guardar si hubo cambios
    if ($changed) {
        // Hacer backup primero
        $backup_dir = __DIR__ . '/backup_' . date('Y-m-d');
        if (!is_dir($backup_dir)) {
            mkdir($backup_dir, 0777, true);
        }
        
        $backup_file = $backup_dir . '/' . str_replace(['/', '\\'], '_', substr($filepath, strlen(__DIR__))) . '.bak';
        file_put_contents($backup_file, $original);
        
        file_put_contents($filepath, $content);
        return true;
    }
    
    return false;
}

function getRelativePath($filepath) {
    $depth = substr_count($filepath, DIRECTORY_SEPARATOR) - substr_count(__DIR__, DIRECTORY_SEPARATOR);
    
    if (strpos($filepath, 'modules') !== false) {
        // Está en modules, necesita subir más niveles
        return str_repeat('../', $depth) . 'includes/config.php';
    } else {
        // Está en raíz
        return 'includes/config.php';
    }
}

function scanAllFiles($dir) {
    $count = 0;
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $filename = $file->getPathname();
            
            // Excluir archivos que no deben modificarse
            $exclude = ['Database.php', 'Env.php', 'fix_', 'backup', 'vendor', 'node_modules'];
            $skip = false;
            foreach ($exclude as $e) {
                if (strpos($filename, $e) !== false) {
                    $skip = true;
                    break;
                }
            }
            
            if (!$skip) {
                if (fixFile($filename)) {
                    $count++;
                    echo "✅ Corregido: " . htmlspecialchars(basename($filename)) . "<br>";
                    flush();
                }
            }
        }
    }
    
    return $count;
}

// Ejecutar
echo "<pre>";
$start = microtime(true);
$total = scanAllFiles(__DIR__);
$time = round(microtime(true) - $start, 2);
echo "</pre>";

echo "<hr>";
echo "<h2>📊 Resumen:</h2>";
echo "<p>✅ Archivos corregidos: <strong>$total</strong></p>";
echo "<p>⏱️ Tiempo: <strong>{$time} segundos</strong></p>";
echo "<p>📁 Backup creado en: <strong>backup_" . date('Y-m-d') . "</strong></p>";
echo "<hr>";
echo "<p>🚀 Ahora tus archivos deberían funcionar correctamente.</p>";
echo "<p><a href='index.php'>👉 Ir al inicio</a></p>";