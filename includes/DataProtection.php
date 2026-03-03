<?php
require_once __DIR__ . '/Encryption.php';

class DataProtection {
    
    /**
     * Campos que deben ser encriptados
     */
    private static $sensitiveFields = ['telefono', 'email', 'direccion'];
    
    /**
     * Encripta los datos sensibles de un array
     */
    public static function encryptData($data) {
        foreach (self::$sensitiveFields as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $data[$field] = Encryption::encrypt($data[$field]);
            }
        }
        return $data;
    }
    
    /**
     * Desencripta los datos sensibles de un array
     */
    public static function decryptData($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (in_array($key, self::$sensitiveFields) && !empty($value)) {
                    $data[$key] = Encryption::decrypt($value);
                }
            }
        }
        return $data;
    }
    
    /**
     * Desencripta un array de resultados
     */
    public static function decryptResults($results) {
        if (is_array($results)) {
            foreach ($results as &$row) {
                $row = self::decryptData($row);
            }
        }
        return $results;
    }
    
    /**
     * Enmascara datos sensibles para mostrar parcialmente
     */
    public static function maskData($value, $type = 'general') {
        if (empty($value)) return '';
        
        switch ($type) {
            case 'email':
                $parts = explode('@', $value);
                $name = substr($parts[0], 0, 2) . '***' . substr($parts[0], -1);
                return $name . '@' . $parts[1];
                
            case 'telefono':
                $clean = preg_replace('/[^0-9]/', '', $value);
                if (strlen($clean) >= 8) {
                    return substr($clean, 0, 3) . '***' . substr($clean, -3);
                }
                return '***' . substr($value, -3);
                
            case 'documento':
                $clean = preg_replace('/[^0-9]/', '', $value);
                if (strlen($clean) >= 8) {
                    return substr($clean, 0, 3) . '****' . substr($clean, -3);
                }
                return '***' . substr($value, -3);
                
            default:
                if (strlen($value) > 6) {
                    return substr($value, 0, 3) . '...' . substr($value, -3);
                }
                return '***';
        }
    }
    
    /**
     * Registra acceso a datos sensibles (auditoría)
     */
    public static function logAccess($userId, $clientId, $action) {
        $logFile = __DIR__ . '/../../logs/data_access.log';
        $dir = dirname($logFile);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $logEntry = date('Y-m-d H:i:s') . " | Usuario: {$userId} | Cliente: {$clientId} | Acción: {$action} | IP: {$_SERVER['REMOTE_ADDR']}\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }
}