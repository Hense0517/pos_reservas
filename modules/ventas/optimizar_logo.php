<?php
// modules/ventas/optimizar_logo.php

require_once '../../config/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

// Obtener configuración
$query_config = "SELECT * FROM configuracion_negocio ORDER BY id DESC LIMIT 1";
$stmt_config = $db->prepare($query_config);
$stmt_config->execute();
$config = $stmt_config->fetch(PDO::FETCH_ASSOC);

if (!$config || empty($config['logo'])) {
    die('No hay logo configurado');
}

$logo_filename = $config['logo'];
$logo_path = '../../' . $logo_filename;

if (!file_exists($logo_path)) {
    die('El logo no existe en el servidor');
}

// Crear versión optimizada
$logo_info = pathinfo($logo_path);
$optimized_path = $logo_info['dirname'] . '/logo_print.' . $logo_info['extension'];

// Función de optimización
function optimizarImagenParaTermica($input_path, $output_path) {
    // Cargar imagen
    $extension = strtolower(pathinfo($input_path, PATHINFO_EXTENSION));
    
    switch($extension) {
        case 'jpg':
        case 'jpeg':
            $image = imagecreatefromjpeg($input_path);
            break;
        case 'png':
            $image = imagecreatefrompng($input_path);
            // Mantener transparencia
            imagealphablending($image, false);
            imagesavealpha($image, true);
            break;
        case 'gif':
            $image = imagecreatefromgif($input_path);
            break;
        default:
            return false;
    }
    
    if (!$image) return false;
    
    $width = imagesx($image);
    $height = imagesy($image);
    
    // Redimensionar a tamaño óptimo para térmica (200px máximo)
    $max_width = 200;
    if ($width > $max_width) {
        $ratio = $height / $width;
        $new_width = $max_width;
        $new_height = $max_width * $ratio;
        
        $resized = imagecreatetruecolor($new_width, $new_height);
        
        if($extension == 'png') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
            imagefilledrectangle($resized, 0, 0, $new_width, $new_height, $transparent);
        }
        
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        $image = $resized;
        $width = $new_width;
        $height = $new_height;
    }
    
    // Convertir a blanco y negro de alto contraste
    $contrast_image = imagecreatetruecolor($width, $height);
    
    if($extension == 'png') {
        imagealphablending($contrast_image, false);
        imagesavealpha($contrast_image, true);
        $transparent = imagecolorallocatealpha($contrast_image, 255, 255, 255, 127);
        imagefilledrectangle($contrast_image, 0, 0, $width, $height, $transparent);
    } else {
        $white = imagecolorallocate($contrast_image, 255, 255, 255);
        imagefilledrectangle($contrast_image, 0, 0, $width, $height, $white);
    }
    
    // Umbral para conversión (128 = medio)
    $threshold = 128;
    
    for($x = 0; $x < $width; $x++) {
        for($y = 0; $y < $height; $y++) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            
            // Convertir a escala de grises
            $gray = ($r + $g + $b) / 3;
            
            // Aplicar umbral para alto contraste
            $color = $gray > $threshold ? 255 : 0;
            
            $new_color = imagecolorallocate($contrast_image, $color, $color, $color);
            imagesetpixel($contrast_image, $x, $y, $new_color);
        }
    }
    
    // Guardar imagen optimizada
    switch($extension) {
        case 'jpg':
        case 'jpeg':
            imagejpeg($contrast_image, $output_path, 90);
            break;
        case 'png':
            imagepng($contrast_image, $output_path, 9);
            break;
        case 'gif':
            imagegif($contrast_image, $output_path);
            break;
    }
    
    imagedestroy($image);
    imagedestroy($contrast_image);
    
    return true;
}

// Optimizar imagen
if (optimizarImagenParaTermica($logo_path, $optimized_path)) {
    echo 'Logo optimizado correctamente para impresión térmica.';
    echo '<br>Ruta: ' . $optimized_path;
    echo '<br><img src="../../' . dirname($logo_filename) . '/logo_print.' . pathinfo($logo_filename, PATHINFO_EXTENSION) . '" style="max-width: 200px;">';
} else {
    echo 'Error al optimizar el logo.';
}
?>