<?php
// modules/configuracion/guardar_configuracion.php

session_start();
require_once '../../config/database.php';

// Verificar si el usuario está autenticado
if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Configuración para subida de archivos
$upload_dir = '../../imagenes/logo/'; // Ruta relativa desde este archivo
$allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$max_size = 2 * 1024 * 1024; // 2MB

// Procesar el formulario cuando se envía
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Inicializar conexión a la base de datos
        $database = Database::getInstance();
        $conn = $database->getConnection();
        
        if (!$conn) {
            throw new Exception("No se pudo conectar a la base de datos");
        }

        // Recoger y sanitizar los datos del formulario
        $nombre_negocio = trim($_POST['nombre_negocio'] ?? '');
        $ruc = trim($_POST['ruc'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $moneda = trim($_POST['moneda'] ?? 'USD');
        $impuesto = floatval($_POST['impuesto'] ?? 0);

        // Validaciones básicas
        $errores = [];

        if (empty($nombre_negocio)) {
            $errores[] = "El nombre del negocio es obligatorio";
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = "El formato del email no es válido";
        }

        if ($impuesto < 0 || $impuesto > 100) {
            $errores[] = "El impuesto debe estar entre 0 y 100";
        }

        // Verificar y crear carpeta de logos si no existe
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0755, true)) {
                $errores[] = "No se pudo crear la carpeta de logos: " . $upload_dir;
            }
        }

        // Verificar permisos de escritura en la carpeta
        if (is_dir($upload_dir) && !is_writable($upload_dir)) {
            $errores[] = "La carpeta de logos no tiene permisos de escritura: " . $upload_dir;
        }

        // Procesar la subida del logo
        $ruta_logo = null;
        $logo_anterior = null;
        $nuevo_logo_subido = false;

        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['logo'];
            
            // Validar tipo de archivo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($mime_type, $allowed_types)) {
                $errores[] = "Solo se permiten archivos JPEG, PNG, GIF y WebP. Tipo detectado: " . $mime_type;
            }
            
            // Validar tamaño
            if ($file['size'] > $max_size) {
                $errores[] = "El archivo no debe superar los 2MB";
            }
            
            // Validar que sea una imagen real
            $image_info = getimagesize($file['tmp_name']);
            if (!$image_info) {
                $errores[] = "El archivo no es una imagen válida";
            }

            if (empty($errores)) {
                // OBTENER EL LOGO ANTERIOR ANTES DE ACTUALIZAR
                $stmt = $conn->query("SELECT logo FROM configuracion_negocio LIMIT 1");
                $logo_anterior = $stmt->fetchColumn();
                
                // DEFINIR NOMBRE FIJO PARA EL LOGO
                // Determinar extensión basada en el tipo MIME
                $extensiones = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp'
                ];
                
                $extension = $extensiones[$mime_type] ?? 'jpg';
                $nombre_archivo = 'logo.' . $extension;
                $upload_file = $upload_dir . $nombre_archivo;
                
                // RUTA RELATIVA QUE SE GUARDARÁ EN LA BD (sin el ../)
                $ruta_logo = 'imagenes/logo/' . $nombre_archivo;

                // Mover el archivo subido
                if (move_uploaded_file($file['tmp_name'], $upload_file)) {
                    $nuevo_logo_subido = true;
                    
                    // Cambiar permisos del archivo subido
                    chmod($upload_file, 0644);
                } else {
                    $errores[] = "Error al mover el archivo subido. Verifique los permisos de la carpeta.";
                }
            }
        } elseif ($_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            // Manejar otros errores de subida
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'El archivo excede el tamaño máximo permitido por el servidor',
                UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido por el formulario',
                UPLOAD_ERR_PARTIAL => 'El archivo fue solo parcialmente subido',
                UPLOAD_ERR_NO_TMP_DIR => 'No existe directorio temporal',
                UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir el archivo en el disco',
                UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida del archivo'
            ];
            
            $error_msg = $upload_errors[$_FILES['logo']['error']] ?? 'Error desconocido en la subida del archivo';
            $errores[] = $error_msg;
        }

        // Si hay errores, redirigir con mensajes
        if (!empty($errores)) {
            $_SESSION['error'] = implode('<br>', $errores);
            header('Location: index.php');
            exit;
        }

        // Iniciar transacción
        $conn->beginTransaction();

        try {
            // Verificar si ya existe configuración
            $stmt = $conn->query("SELECT COUNT(*) FROM configuracion_negocio");
            $existe_config = $stmt->fetchColumn();

            if ($existe_config > 0) {
                // Actualizar configuración existente
                if ($ruta_logo) {
                    $sql = "UPDATE configuracion_negocio SET 
                            nombre_negocio = ?, 
                            ruc = ?, 
                            direccion = ?, 
                            telefono = ?, 
                            email = ?, 
                            moneda = ?, 
                            impuesto = ?, 
                            logo = ?,
                            updated_at = CURRENT_TIMESTAMP";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$nombre_negocio, $ruc, $direccion, $telefono, $email, $moneda, $impuesto, $ruta_logo]);
                } else {
                    $sql = "UPDATE configuracion_negocio SET 
                            nombre_negocio = ?, 
                            ruc = ?, 
                            direccion = ?, 
                            telefono = ?, 
                            email = ?, 
                            moneda = ?, 
                            impuesto = ?,
                            updated_at = CURRENT_TIMESTAMP";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([$nombre_negocio, $ruc, $direccion, $telefono, $email, $moneda, $impuesto]);
                }
            } else {
                // Insertar nueva configuración
                $sql = "INSERT INTO configuracion_negocio 
                        (nombre_negocio, ruc, direccion, telefono, email, moneda, impuesto, logo) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$nombre_negocio, $ruc, $direccion, $telefono, $email, $moneda, $impuesto, $ruta_logo]);
            }

            // Si se subió un nuevo logo y existe un logo anterior, eliminarlo
            if ($nuevo_logo_subido && $logo_anterior && !empty($logo_anterior)) {
                // Extraer solo el nombre del archivo de la ruta relativa anterior
                $nombre_archivo_anterior = basename($logo_anterior);
                $logo_anterior_path = $upload_dir . $nombre_archivo_anterior;
                
                // Solo eliminar si el archivo existe y NO es el mismo que acabamos de subir
                if ($nombre_archivo_anterior !== $nombre_archivo && file_exists($logo_anterior_path) && is_file($logo_anterior_path)) {
                    unlink($logo_anterior_path);
                }
            }

            $conn->commit();

            $_SESSION['success'] = "Configuración guardada exitosamente";
            header('Location: index.php');
            exit;

        } catch (Exception $e) {
            $conn->rollBack();
            
            // Si se subió un archivo pero hubo error en la BD, eliminarlo
            if ($nuevo_logo_subido && isset($upload_file) && file_exists($upload_file)) {
                unlink($upload_file);
            }
            
            throw $e;
        }

    } catch (Exception $e) {
        $_SESSION['error'] = "Error al guardar la configuración: " . $e->getMessage();
        header('Location: index.php');
        exit;
    }
} else {
    // Si no es POST, redirigir al formulario
    header('Location: index.php');
    exit;
}