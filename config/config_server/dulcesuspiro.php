<?php
// Auto-fixed: 2026-02-17 01:57:19
require_once 'includes/config.php';
class Database {
    private $host = "localhost";
    private $db_name = "tudulces_pos";
    private $username = "tudulces";
    private $password = "e8FZ3)hk3W5Hh(";
    public $conn;

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->exec("set names utf8");
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            error_log("Error de conexión: " . $exception->getMessage());
            return null;
        }
        return $this->conn;
    }

    public function getConfiguracionNegocio() {
        if (!$this->conn) return null;
        
        try {
            $query = "SELECT * FROM configuracion_negocio ORDER BY id DESC LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error obteniendo configuración: " . $e->getMessage());
            return null;
        }
    }

    // Función para generar código de barras único
    public function generarCodigoBarras() {
        do {
            // Generar código EAN-13 (13 dígitos)
            $codigo = '20' . str_pad(mt_rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
            
            // Verificar si ya existe
            $query = "SELECT id FROM productos WHERE codigo_barras = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$codigo]);
        } while ($stmt->fetch());
        
        return $codigo;
    }

    // Buscar producto por código de barras
    public function buscarProductoPorCodigoBarras($codigo_barras) {
        if (!$this->conn) return null;
        
        try {
            $query = "SELECT p.*, c.nombre as categoria_nombre 
                     FROM productos p 
                     LEFT JOIN categorias c ON p.categoria_id = c.id 
                     WHERE p.codigo_barras = ? AND p.activo = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$codigo_barras]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error buscando producto: " . $e->getMessage());
            return null;
        }
    }
}
?>