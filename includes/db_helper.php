<?php
// includes/db_helper.php
// ELIMINAR ESTA LÍNEA: require_once 'includes/config.php';

/**
 * Obtiene la conexión a la base de datos usando el singleton
 * @return PDO|null
 */
function getDbConnection() {
    try {
        // Asegurar que la clase Database está cargada
        if (!class_exists('Database')) {
            require_once __DIR__ . '/../config/Database.php';
        }
        
        $database = Database::getInstance();
        return $database->getConnection();
    } catch (Exception $e) {
        error_log("Error en getDbConnection: " . $e->getMessage());
        return null;
    }
}

/**
 * Obtiene la instancia de Database
 * @return Database
 */
function getDatabase() {
    if (!class_exists('Database')) {
        require_once __DIR__ . '/../config/Database.php';
    }
    
    return Database::getInstance();
}

/**
 * Ejecuta una consulta preparada de forma segura
 * @param string $sql
 * @param array $params
 * @return PDOStatement|false
 */
function executeQuery($sql, $params = []) {
    $db = getDbConnection();
    if (!$db) return false;
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Error en executeQuery: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtiene un solo registro
 * @param string $sql
 * @param array $params
 * @return array|false
 */
function fetchOne($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
}

/**
 * Obtiene múltiples registros
 * @param string $sql
 * @param array $params
 * @return array
 */
function fetchAll($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}