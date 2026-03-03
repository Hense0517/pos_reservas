<?php
class Encryption {
    private static $key;
    private static $method = 'AES-256-CBC';
    
    /**
     * Inicializa la clave de encriptación
     */
    private static function initKey() {
        if (!self::$key) {
            // Intentar leer la clave del archivo de configuración
            $keyFile = __DIR__ . '/../../config/encryption.key';
            
            if (file_exists($keyFile)) {
                self::$key = trim(file_get_contents($keyFile));
            } else {
                // Si no existe, crear una nueva clave
                self::$key = self::generateKey();
                self::saveKey(self::$key);
            }
        }
    }
    
    /**
     * Encripta un texto
     */
    public static function encrypt($data) {
        if ($data === null || $data === '') {
            return null;
        }
        
        self::initKey();
        
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::$method));
        $encrypted = openssl_encrypt($data, self::$method, self::$key, 0, $iv);
        
        // Combinar IV y datos encriptados y codificar en base64
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Desencripta un texto
     */
    public static function decrypt($data) {
        if ($data === null || $data === '') {
            return null;
        }
        
        self::initKey();
        
        $data = base64_decode($data);
        $ivLength = openssl_cipher_iv_length(self::$method);
        $iv = substr($data, 0, $ivLength);
        $encrypted = substr($data, $ivLength);
        
        return openssl_decrypt($encrypted, self::$method, self::$key, 0, $iv);
    }
    
    /**
     * Genera una nueva clave de encriptación
     */
    public static function generateKey() {
        return bin2hex(openssl_random_pseudo_bytes(32));
    }
    
    /**
     * Guarda la clave en el archivo de configuración
     */
    public static function saveKey($key) {
        $keyFile = __DIR__ . '/../../config/encryption.key';
        $dir = dirname($keyFile);
        
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        file_put_contents($keyFile, $key);
        chmod($keyFile, 0600);
        
        return true;
    }
}