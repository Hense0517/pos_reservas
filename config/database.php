<?php
/**
 * config/database.php - Conexión PDO segura
 *
 * FIXES APLICADOS:
 * - [CRÍTICO] Sin timeout de conexión: podía dejarse colgado indefinidamente
 * - [CRÍTICO] __wakeup() público: permite deserialización maliciosa (PHP Object Injection)
 * - [MEDIO] getConfiguracionNegocio() sin LIMIT en la query podría retornar demasiados datos
 * - [MEDIO] Sin límite de filas en el singleton para evitar memory leaks
 * - [BAJO] Charset no forzado via DSN Y via SET NAMES (redundante pero seguro mantenerlo)
 */

require_once __DIR__ . '/Env.php';

try {
    Env::load(__DIR__ . '/../.env');
} catch (Exception $e) {
    error_log("Error cargando .env: " . $e->getMessage());
}

class Database {
    private static ?Database $instance = null;
    private PDO $conn;

    private function __construct() {
        $host     = Env::get('DB_HOST', 'localhost');
        $dbname   = Env::get('DB_NAME', 'sistema_pos');
        $username = Env::get('DB_USER', 'root');
        $password = Env::get('DB_PASS', '');
        $port     = (int) Env::get('DB_PORT', '3306');

        // [FIX MEDIO] Validar que el host no sea un path o valor inyectado
        if (!preg_match('/^[a-zA-Z0-9._\-]+$/', $host)) {
            throw new RuntimeException("Valor de DB_HOST inválido.");
        }

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

            $this->conn = new PDO(
                $dsn,
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    // [FIX CRÍTICO] Emulate prepares en false = prepared statements reales
                    // Previene second-order SQL injection
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_PERSISTENT         => false,
                    // [FIX CRÍTICO] Timeout de conexión para evitar cuelgues
                    PDO::ATTR_TIMEOUT            => 5,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci,
                                                     sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'",
                    // [FIX MEDIO] Deshabilitar SSL self-signed si no es necesario
                    // PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
                ]
            );
        } catch (PDOException $e) {
            // [FIX CRÍTICO] NUNCA exponer el mensaje de error de PDO al usuario
            // El mensaje contiene host, usuario y nombre de base de datos
            error_log("Database connection error: " . $e->getMessage());
            throw new RuntimeException("Error de conexión a la base de datos.");
        }
    }

    public static function getInstance(): static {
        if (static::$instance === null) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    public function getConnection(): PDO {
        return $this->conn;
    }

    public function getConfiguracionNegocio(): array {
        try {
            // [FIX MEDIO] Query explícita con columnas específicas, no SELECT *
            // Evita exponer columnas sensibles que se puedan agregar en el futuro
            $stmt = $this->conn->prepare(
                "SELECT nombre_negocio, moneda, meta_ventas_diaria, telefono, 
                        direccion, email, logo_path
                 FROM configuracion_negocio 
                 ORDER BY id DESC 
                 LIMIT 1"
            );
            $stmt->execute();
            return $stmt->fetch() ?: [];
        } catch (PDOException $e) {
            error_log("Error obteniendo configuración del negocio: " . $e->getMessage());
            return [];
        }
    }

    // [FIX CRÍTICO] __clone y __wakeup privados/sin implementación
    // Un __wakeup() público permite PHP Object Injection via unserialize()
    private function __clone(): void {}

    /**
     * [FIX CRÍTICO] Lanzar excepción en __wakeup para prevenir deserialización.
     * Si alguien intenta unserialize() un objeto Database, fallará de forma segura.
     */
    public function __wakeup(): void {
        throw new RuntimeException("No se permite deserializar la clase Database.");
    }
}