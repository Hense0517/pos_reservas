<?php
/**
 * config/Env.php - Gestor seguro de variables de entorno
 * 
 * FIXES APLICADOS:
 * - [CRÍTICO] Path traversal: validación estricta de la ruta del .env
 * - [CRÍTICO] Exposición de credenciales: eliminado define() global (constants son visibles via get_defined_constants())
 * - [MEDIO] Valores con comillas no eran parseados correctamente ("valor con espacios")
 * - [MEDIO] Sin validación de nombre de variable (posible inyección)
 * - [BAJO] Comentarios inline (#) en valores no eran ignorados correctamente
 */
class Env {
    private static $variables = [];
    private static $loaded = false;

    public static function load(string $path): void {
        // [FIX CRÍTICO] Normalizar y validar la ruta para prevenir Path Traversal
        $realPath = realpath($path);

        if ($realPath === false || !file_exists($realPath)) {
            throw new RuntimeException("Archivo .env no encontrado o ruta inválida.");
        }

        // [FIX CRÍTICO] Asegurarse de que el archivo .env está dentro del directorio raíz del proyecto
        // Nunca debe cargarse un .env desde fuera del proyecto
        $projectRoot = realpath(__DIR__ . '/../');
        if ($projectRoot === false || strpos($realPath, $projectRoot) !== 0) {
            throw new RuntimeException("Ruta del .env fuera del directorio del proyecto.");
        }

        // [FIX] Verificar permisos del archivo (no debe ser legible por todos)
        $perms = fileperms($realPath);
        // En Linux: si el archivo es world-readable (otros pueden leer), advertir
        if (PHP_OS_FAMILY !== 'Windows' && ($perms & 0x0004)) {
            error_log("ADVERTENCIA DE SEGURIDAD: El archivo .env tiene permisos demasiado permisivos. Ejecute: chmod 600 .env");
        }

        $lines = file($realPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            throw new RuntimeException("No se pudo leer el archivo .env.");
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Ignorar comentarios y líneas vacías
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Ignorar líneas sin '='
            if (strpos($line, '=') === false) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name  = trim($name);
            $value = trim($value);

            // [FIX MEDIO] Validar nombre de variable: solo letras mayúsculas, números y _
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
                error_log("Variable .env con nombre inválido ignorada: '$name'");
                continue;
            }

            // [FIX MEDIO] Parsear valores entre comillas dobles o simples
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            } else {
                // [FIX BAJO] Eliminar comentarios inline: APP_ENV=production # comentario
                if (($commentPos = strpos($value, ' #')) !== false) {
                    $value = trim(substr($value, 0, $commentPos));
                }
            }

            // [FIX CRÍTICO] NO usar define() — las constantes PHP son visibles globalmente
            // mediante get_defined_constants() y pueden filtrar credenciales.
            // Solo almacenar en el array privado estático.
            self::$variables[$name] = $value;
        }

        self::$loaded = true;
    }

    public static function get(string $key, $default = null): mixed {
        return self::$variables[$key] ?? $default;
    }

    /**
     * Obtener un valor requerido; lanza excepción si no existe.
     * Usar para credenciales críticas.
     */
    public static function required(string $key): string {
        if (!isset(self::$variables[$key]) || self::$variables[$key] === '') {
            throw new RuntimeException("Variable de entorno requerida no definida: '$key'");
        }
        return self::$variables[$key];
    }

    public static function set(string $key, string $value): void {
        // Validar nombre también en set()
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            throw new InvalidArgumentException("Nombre de variable inválido: '$key'");
        }
        self::$variables[$key] = $value;
    }

    public static function isLoaded(): bool {
        return self::$loaded;
    }

    /**
     * [SEGURIDAD] Nunca exponer todas las variables (podrían contener contraseñas).
     * Solo permitir acceso individual via get().
     */
    public static function all(): array {
        // Solo disponible en modo debug y nunca en producción
        if (self::get('APP_ENV') === 'production') {
            throw new RuntimeException("No se permite listar variables en producción.");
        }
        return self::$variables;
    }
}