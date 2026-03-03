<?php
// config/auth.php
// CORREGIDO: La ruta correcta es ../includes/config.php
// ELIMINAR ESTA LÍNEA: require_once 'includes/config.php';

class Auth {
    private $db_connection; // No guardamos el objeto PDO directamente
    private $user_id;
    private $user_rol;
    private $user_permisos; // Cache de permisos
    private static $db_instance = null; // Instancia estática para compartir conexión

    public function __construct($database) {
        // Guardamos el objeto Database, no el PDO directamente
        $this->db_connection = $database;
        $this->checkSession();
        $this->loadUserPermissions();
    }

    // Método para obtener la conexión PDO cuando sea necesario
    private function getDb() {
        if ($this->db_connection instanceof Database) {
            return $this->db_connection->getConnection();
        }
        return null;
    }

    private function checkSession() {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['usuario_id'])) {
            $this->user_id = $_SESSION['usuario_id'];
            $this->user_rol = $_SESSION['usuario_rol'] ?? null;
        }
    }

    private function loadUserPermissions() {
        if (!$this->user_id) {
            $this->user_permisos = [];
            return;
        }

        // Cachear permisos en sesión para mejor rendimiento
        $cache_key = 'user_permisos_' . $this->user_id;
        
        if (isset($_SESSION[$cache_key])) {
            $this->user_permisos = $_SESSION[$cache_key];
            return;
        }

        try {
            $db = $this->getDb();
            if (!$db) {
                $this->user_permisos = [];
                return;
            }

            // Obtener todos los permisos del usuario desde la tabla permisos
            $query = "SELECT modulo, leer, crear, editar, eliminar 
                     FROM permisos 
                     WHERE usuario_id = :usuario_id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':usuario_id', $this->user_id);
            $stmt->execute();
            
            $permisos = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $permisos[$row['modulo']] = [
                    'leer' => (bool)$row['leer'],
                    'crear' => (bool)$row['crear'],
                    'editar' => (bool)$row['editar'],
                    'eliminar' => (bool)$row['eliminar']
                ];
            }
            
            $this->user_permisos = $permisos;
            
            // Guardar en cache de sesión
            $_SESSION[$cache_key] = $permisos;
            
        } catch (Exception $e) {
            error_log("Error al cargar permisos: " . $e->getMessage());
            $this->user_permisos = [];
        }
    }

    public function checkAuth() {
        if (!isset($_SESSION['usuario_id'])) {
            $this->redirectToLogin();
        }
    }

    public function hasPermission($modulo, $nivel_requerido = 'leer') {
        // 1. Si no hay usuario, no tiene permisos
        if (!isset($this->user_id)) {
            return false;
        }

        // 2. Admin tiene TODOS los permisos SIEMPRE
        if ($this->user_rol === 'admin') {
            return true;
        }

        // 3. Convertir nivel requerido a formato de tabla
        $columna_permiso = $this->convertirNivelPermiso($nivel_requerido);
        
        // 4. Verificar en permisos cargados
        if (isset($this->user_permisos[$modulo])) {
            $permiso_modulo = $this->user_permisos[$modulo];
            
            // Verificar el nivel específico
            if ($columna_permiso === 'leer' && isset($permiso_modulo['leer']) && $permiso_modulo['leer']) {
                return true;
            }
            if ($columna_permiso === 'crear' && isset($permiso_modulo['crear']) && $permiso_modulo['crear']) {
                return true;
            }
            if ($columna_permiso === 'editar' && isset($permiso_modulo['editar']) && $permiso_modulo['editar']) {
                return true;
            }
            if ($columna_permiso === 'eliminar' && isset($permiso_modulo['eliminar']) && $permiso_modulo['eliminar']) {
                return true;
            }
        }

        // 5. Si no encuentra permiso, verificar en tabla (fallback)
        return $this->checkPermisoEnTabla($modulo, $columna_permiso);
    }

    private function convertirNivelPermiso($nivel_requerido) {
        switch ($nivel_requerido) {
            case 'lectura':
            case 'leer':
            case 'read':
                return 'leer';
                
            case 'crear':
            case 'create':
            case 'escritura':
                return 'crear';
                
            case 'editar':
            case 'edit':
            case 'actualizar':
                return 'editar';
                
            case 'eliminar':
            case 'delete':
            case 'borrar':
                return 'eliminar';
                
            default:
                return 'leer';
        }
    }

    private function checkPermisoEnTabla($modulo, $columna_permiso) {
        try {
            $db = $this->getDb();
            if (!$db) return false;

            $query = "SELECT $columna_permiso FROM permisos 
                     WHERE usuario_id = :usuario_id AND modulo = :modulo";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':usuario_id', $this->user_id);
            $stmt->bindParam(':modulo', $modulo);
            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $permiso = $stmt->fetch(PDO::FETCH_ASSOC);
                return (bool)$permiso[$columna_permiso];
            }

            return false;
            
        } catch (Exception $e) {
            error_log("Error en checkPermisoEnTabla: " . $e->getMessage());
            return false;
        }
    }

    // Método para limpiar cache de permisos
    public function clearPermissionCache() {
        $cache_key = 'user_permisos_' . $this->user_id;
        if (isset($_SESSION[$cache_key])) {
            unset($_SESSION[$cache_key]);
        }
        $this->loadUserPermissions(); // Recargar
    }

    // Método para forzar recarga de permisos
    public function reloadPermissions() {
        $cache_key = 'user_permisos_' . $this->user_id;
        if (isset($_SESSION[$cache_key])) {
            unset($_SESSION[$cache_key]);
        }
        $this->loadUserPermissions();
    }

    // Obtener todos los permisos del usuario
    public function getAllPermissions() {
        return $this->user_permisos;
    }

    // Verificar si el usuario tiene al menos permiso de lectura en un módulo
    public function canView($modulo) {
        return $this->hasPermission($modulo, 'leer');
    }

    // Verificar si el usuario puede crear en un módulo
    public function canCreate($modulo) {
        return $this->hasPermission($modulo, 'crear');
    }

    // Verificar si el usuario puede editar en un módulo
    public function canEdit($modulo) {
        return $this->hasPermission($modulo, 'editar');
    }

    // Verificar si el usuario puede eliminar en un módulo
    public function canDelete($modulo) {
        return $this->hasPermission($modulo, 'eliminar');
    }
    
    // Método para asignar permisos por defecto a un nuevo usuario
    public function asignarPermisosPorDefecto($usuario_id, $rol) {
        try {
            $db = $this->getDb();
            if (!$db) return false;

            // Definir permisos por defecto según rol
            $permisos_por_defecto = $this->getPermisosPorDefecto($rol);
            
            // Insertar permisos para cada módulo
            foreach ($permisos_por_defecto as $modulo => $permisos) {
                $query = "INSERT INTO permisos (usuario_id, modulo, leer, crear, editar, eliminar) 
                         VALUES (:usuario_id, :modulo, :leer, :crear, :editar, :eliminar)
                         ON DUPLICATE KEY UPDATE 
                         leer = VALUES(leer), 
                         crear = VALUES(crear), 
                         editar = VALUES(editar), 
                         eliminar = VALUES(eliminar)";
                
                $stmt = $db->prepare($query);
                $stmt->bindParam(':usuario_id', $usuario_id);
                $stmt->bindParam(':modulo', $modulo);
                $stmt->bindParam(':leer', $permisos['leer'], PDO::PARAM_INT);
                $stmt->bindParam(':crear', $permisos['crear'], PDO::PARAM_INT);
                $stmt->bindParam(':editar', $permisos['editar'], PDO::PARAM_INT);
                $stmt->bindParam(':eliminar', $permisos['eliminar'], PDO::PARAM_INT);
                $stmt->execute();
            }
            
            // Limpiar cache si es el usuario actual
            if ($usuario_id == $this->user_id) {
                $this->clearPermissionCache();
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error al asignar permisos por defecto: " . $e->getMessage());
            return false;
        }
    }
    
    private function getPermisosPorDefecto($rol) {
        // Solo definir permisos básicos por defecto
        // Los administradores pueden ajustarlos después
        if ($rol === 'admin') {
            return [
                'dashboard' => ['leer' => 1, 'crear' => 1, 'editar' => 1, 'eliminar' => 1]
            ];
        }
        
        // Para cajero/vendedor, solo dashboard por defecto
        if (in_array($rol, ['cajero', 'vendedor'])) {
            return [
                'dashboard' => ['leer' => 1, 'crear' => 0, 'editar' => 0, 'eliminar' => 0]
            ];
        }
        
        return [];
    }

    public function getUserInfo() {
        if (!isset($this->user_id)) {
            return null;
        }
        
        try {
            $db = $this->getDb();
            if (!$db) return null;

            $query = "SELECT id, username, nombre, email, rol, activo, last_login FROM usuarios WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $this->user_id);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error en getUserInfo: " . $e->getMessage());
            return null;
        }
    }

    public function logout() {
        session_destroy();
        $this->redirectToLogin();
    }

    public function redirectToLogin() {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'];
        $path = dirname($_SERVER['PHP_SELF']);
        $base_url = rtrim($protocol . $host . $path, '/') . '/';
        
        header("Location: " . $base_url . "login.php");
        exit;
    }

    public function isAdmin() {
        return $this->user_rol === 'admin';
    }
    
    public function isCajero() {
        return $this->user_rol === 'cajero';
    }
    
    public function isVendedor() {
        return $this->user_rol === 'vendedor';
    }
    
    public function getUserId() {
        return $this->user_id;
    }
    
    public function getUserRol() {
        return $this->user_rol;
    }
    
    public function getUserName() {
        $info = $this->getUserInfo();
        return $info ? $info['nombre'] : '';
    }
    
    public function updateLastLogin() {
        try {
            $db = $this->getDb();
            if (!$db) return false;

            $query = "UPDATE usuarios SET last_login = NOW() WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $this->user_id);
            $stmt->execute();
            return true;
        } catch (Exception $e) {
            error_log("Error al actualizar last_login: " . $e->getMessage());
            return false;
        }
    }

    // Método para serialización (evita guardar objetos PDO)
    public function __sleep() {
        return ['user_id', 'user_rol', 'user_permisos'];
    }

    // Método para deserialización
    public function __wakeup() {
        // La conexión se restablecerá cuando se necesite
        $this->db_connection = null;
    }
}
?>