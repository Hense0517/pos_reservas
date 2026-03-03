<?php
// modules/admin/modulos.php
session_start();
require_once '../../includes/config.php';

// Verificar permisos (solo admin)
if (!$auth || !$auth->isAdmin()) {
    $_SESSION['error'] = "No tienes permisos para esta sección";
    header("Location: ../../index.php");
    exit;
}

$permisosHandler = new Permisos($db);

// Acciones
$action = $_GET['action'] ?? 'list';

switch ($action) {
    case 'add':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nombre = trim($_POST['nombre']);
            $descripcion = trim($_POST['descripcion']);
            $icono = trim($_POST['icono']);
            $grupo = trim($_POST['grupo']);
            $ruta = trim($_POST['ruta']);
            $orden = intval($_POST['orden']);
            
            if ($permisosHandler->addModule($nombre, $descripcion, $icono, $grupo, $ruta, $orden)) {
                $_SESSION['success'] = "Módulo agregado correctamente";
                header("Location: modulos.php");
                exit;
            } else {
                $_SESSION['error'] = "Error al agregar el módulo";
            }
        }
        include 'modulo_form.php';
        break;
        
    case 'edit':
        $id = intval($_GET['id']);
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'nombre' => trim($_POST['nombre']),
                'descripcion' => trim($_POST['descripcion']),
                'icono' => trim($_POST['icono']),
                'grupo' => trim($_POST['grupo']),
                'ruta' => trim($_POST['ruta']),
                'orden' => intval($_POST['orden']),
                'activo' => isset($_POST['activo']) ? 1 : 0
            ];
            
            if ($permisosHandler->updateModule($id, $data)) {
                $_SESSION['success'] = "Módulo actualizado correctamente";
                header("Location: modulos.php");
                exit;
            } else {
                $_SESSION['error'] = "Error al actualizar el módulo";
            }
        }
        
        // Obtener módulo
        $query = "SELECT * FROM modulos_sistema WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $modulo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$modulo) {
            $_SESSION['error'] = "Módulo no encontrado";
            header("Location: modulos.php");
            exit;
        }
        
        include 'modulo_form.php';
        break;
        
    case 'delete':
        $id = intval($_GET['id']);
        
        // Verificar que no sea un módulo crítico
        $query = "SELECT nombre FROM modulos_sistema WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $modulo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $modulos_criticos = ['dashboard', 'usuarios'];
        
        if (in_array($modulo['nombre'], $modulos_criticos)) {
            $_SESSION['error'] = "No se puede eliminar este módulo crítico del sistema";
            header("Location: modulos.php");
            exit;
        }
        
        $query = "DELETE FROM modulos_sistema WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Módulo eliminado correctamente";
        } else {
            $_SESSION['error'] = "Error al eliminar el módulo";
        }
        
        header("Location: modulos.php");
        exit;
        
    default:
        // Listar módulos
        $query = "SELECT * FROM modulos_sistema ORDER BY grupo, orden";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $modulos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Agrupar por grupo
        $modulos_agrupados = [];
        foreach ($modulos as $modulo) {
            $grupo = $modulo['grupo'];
            if (!isset($modulos_agrupados[$grupo])) {
                $modulos_agrupados[$grupo] = [];
            }
            $modulos_agrupados[$grupo][] = $modulo;
        }
        
        include 'modulos_list.php';
        break;
}
?>