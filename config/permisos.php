<?php
// Auto-fixed: 2026-02-17 01:57:19
require_once 'includes/config.php';
// config/permisos.php

class Permisos {
    private $db;
    private $usuario_id;
    
    public function __construct($db, $usuario_id = null) {
        $this->db = $db;
        $this->usuario_id = $usuario_id;
    }
    
    /**
     * Verificar si un usuario tiene permiso para una acción específica
     */
    public function hasPermission($modulo, $accion = 'leer') {
        // Administradores tienen todos los permisos
        if ($this->isAdmin()) {
            return true;
        }
        
        // Si no hay usuario_id, denegar
        if (!$this->usuario_id) {
            return false;
        }
        
        try {
            $query = "SELECT $accion FROM permisos 
                     WHERE usuario_id = :usuario_id AND modulo = :modulo";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':usuario_id', $this->usuario_id);
            $stmt->bindParam(':modulo', $modulo);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return (bool)$result[$accion];
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error en hasPermission: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener todos los permisos de un usuario
     */
    public function getUserPermissions($usuario_id = null) {
        $id = $usuario_id ?: $this->usuario_id;
        
        if (!$id) {
            return [];
        }
        
        try {
            $query = "SELECT modulo, leer, crear, editar, eliminar 
                     FROM permisos 
                     WHERE usuario_id = :usuario_id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':usuario_id', $id);
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
            
            return $permisos;
        } catch (Exception $e) {
            error_log("Error en getUserPermissions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Obtener todos los módulos disponibles del sistema (desde base de datos)
     */
    public function getAllModules() {
        try {
            $query = "SELECT nombre, descripcion, icono, grupo, ruta, orden 
                     FROM modulos_sistema 
                     WHERE activo = 1 
                     ORDER BY grupo, orden ASC";
            
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            
            $modulos = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $grupo = $row['grupo'] ?: 'general';
                
                if (!isset($modulos[$grupo])) {
                    $modulos[$grupo] = [];
                }
                
                $modulos[$grupo][] = [
                    'nombre' => $row['nombre'],
                    'descripcion' => $row['descripcion'],
                    'icono' => $row['icono'],
                    'ruta' => $row['ruta'],
                    'orden' => $row['orden']
                ];
            }
            
            return $modulos;
        } catch (Exception $e) {
            error_log("Error en getAllModules: " . $e->getMessage());
            
            // Fallback: si hay error, retornar lista básica
            return $this->getDefaultModules();
        }
    }
    
    /**
     * Lista de módulos por defecto (usado como fallback)
     */
    private function getDefaultModules() {
        return [
            'principal' => [
                ['nombre' => 'dashboard', 'descripcion' => 'Panel Principal', 'icono' => 'fas fa-tachometer-alt']
            ],
            'ventas' => [
                ['nombre' => 'ventas', 'descripcion' => 'Punto de Venta', 'icono' => 'fas fa-shopping-cart'],
                ['nombre' => 'clientes', 'descripcion' => 'Gestión de Clientes', 'icono' => 'fas fa-users'],
                ['nombre' => 'devoluciones', 'descripcion' => 'Gestión de Devoluciones', 'icono' => 'fas fa-exchange-alt']
            ],
            'inventario' => [
                ['nombre' => 'productos', 'descripcion' => 'Gestión de Productos', 'icono' => 'fas fa-box'],
                ['nombre' => 'inventario', 'descripcion' => 'Control de Inventario', 'icono' => 'fas fa-warehouse'],
                ['nombre' => 'categorias', 'descripcion' => 'Categorías de Productos', 'icono' => 'fas fa-tags'],
                ['nombre' => 'marcas', 'descripcion' => 'Marcas de Productos', 'icono' => 'fas fa-trademark'],
                ['nombre' => 'atributos', 'descripcion' => 'Atributos de Productos', 'icono' => 'fas fa-list-alt']
            ],
            'compras' => [
                ['nombre' => 'compras', 'descripcion' => 'Gestión de Compras', 'icono' => 'fas fa-shopping-basket'],
                ['nombre' => 'proveedores', 'descripcion' => 'Gestión de Proveedores', 'icono' => 'fas fa-truck']
            ],
            'finanzas' => [
                ['nombre' => 'gastos', 'descripcion' => 'Registro de Gastos', 'icono' => 'fas fa-money-bill-wave'],
                ['nombre' => 'cuentas_cobrar', 'descripcion' => 'Cuentas por Cobrar', 'icono' => 'fas fa-hand-holding-usd']
            ],
            'reportes' => [
                ['nombre' => 'reportes', 'descripcion' => 'Reportes y Estadísticas', 'icono' => 'fas fa-chart-bar']
            ],
            'configuracion' => [
                ['nombre' => 'usuarios', 'descripcion' => 'Gestión de Usuarios', 'icono' => 'fas fa-user-cog'],
                ['nombre' => 'configuracion', 'descripcion' => 'Configuración del Sistema', 'icono' => 'fas fa-cog'],
                ['nombre' => 'auditoria', 'descripcion' => 'Auditoría del Sistema', 'icono' => 'fas fa-clipboard-check']
            ]
        ];
    }
    
    /**
     * Obtener lista plana de todos los nombres de módulos
     */
    public function getModuleNames() {
        try {
            $query = "SELECT nombre FROM modulos_sistema WHERE activo = 1 ORDER BY nombre";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            
            $nombres = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $nombres[] = $row['nombre'];
            }
            
            return $nombres;
        } catch (Exception $e) {
            error_log("Error en getModuleNames: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Verificar si usuario es administrador
     */
    public function isAdmin() {
        if (!$this->usuario_id) {
            return false;
        }
        
        try {
            $query = "SELECT rol FROM usuarios WHERE id = :usuario_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':usuario_id', $this->usuario_id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return $result['rol'] === 'admin';
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error en isAdmin: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Asignar permisos por defecto según rol
     */
    public function assignFromRole($usuario_id, $rol) {
        try {
            // Primero, eliminar permisos existentes
            $query_delete = "DELETE FROM permisos WHERE usuario_id = :usuario_id";
            $stmt_delete = $this->db->prepare($query_delete);
            $stmt_delete->bindParam(':usuario_id', $usuario_id);
            $stmt_delete->execute();
            
            // Obtener todos los módulos activos
            $modulos = $this->getModuleNames();
            
            // Definir permisos por rol
            $permisos_por_rol = $this->getDefaultPermissionsByRole($rol);
            
            // Insertar permisos
            foreach ($modulos as $modulo) {
                $permiso_data = $permisos_por_rol[$modulo] ?? ['leer' => 0, 'crear' => 0, 'editar' => 0, 'eliminar' => 0];
                
                $query_insert = "INSERT INTO permisos 
                                (usuario_id, modulo, leer, crear, editar, eliminar) 
                                VALUES 
                                (:usuario_id, :modulo, :leer, :crear, :editar, :eliminar)";
                
                $stmt_insert = $this->db->prepare($query_insert);
                $stmt_insert->bindParam(':usuario_id', $usuario_id);
                $stmt_insert->bindParam(':modulo', $modulo);
                $stmt_insert->bindParam(':leer', $permiso_data['leer'], PDO::PARAM_INT);
                $stmt_insert->bindParam(':crear', $permiso_data['crear'], PDO::PARAM_INT);
                $stmt_insert->bindParam(':editar', $permiso_data['editar'], PDO::PARAM_INT);
                $stmt_insert->bindParam(':eliminar', $permiso_data['eliminar'], PDO::PARAM_INT);
                $stmt_insert->execute();
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error en assignFromRole: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener permisos por defecto según rol
     */
    private function getDefaultPermissionsByRole($rol) {
        $todos_modulos = $this->getModuleNames();
        $permisos = [];
        
        foreach ($todos_modulos as $modulo) {
            switch ($rol) {
                case 'admin':
                    $permisos[$modulo] = ['leer' => 1, 'crear' => 1, 'editar' => 1, 'eliminar' => 1];
                    break;
                    
                case 'vendedor':
                    if (in_array($modulo, ['dashboard', 'ventas', 'clientes', 'productos', 'inventario', 'reportes', 'devoluciones'])) {
                        $permisos[$modulo] = $modulo === 'ventas' || $modulo === 'clientes' 
                            ? ['leer' => 1, 'crear' => 1, 'editar' => 1, 'eliminar' => 0]
                            : ['leer' => 1, 'crear' => 0, 'editar' => 0, 'eliminar' => 0];
                    }
                    break;
                    
                case 'cajero':
                    if (in_array($modulo, ['dashboard', 'ventas', 'clientes', 'productos', 'inventario'])) {
                        $permisos[$modulo] = $modulo === 'ventas' 
                            ? ['leer' => 1, 'crear' => 1, 'editar' => 0, 'eliminar' => 0]
                            : ['leer' => 1, 'crear' => 0, 'editar' => 0, 'eliminar' => 0];
                    }
                    break;
                    
                default:
                    $permisos[$modulo] = ['leer' => 0, 'crear' => 0, 'editar' => 0, 'eliminar' => 0];
            }
        }
        
        return $permisos;
    }
    
    /**
     * Obtener información básica del usuario
     */
    public function getUserInfo() {
        if (!$this->usuario_id) {
            return null;
        }
        
        try {
            $query = "SELECT id, username, nombre, email, rol, activo 
                     FROM usuarios WHERE id = :usuario_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':usuario_id', $this->usuario_id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            return null;
        } catch (Exception $e) {
            error_log("Error en getUserInfo: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Método simple para verificar rol (alternativa)
     */
    public function checkRole($rol_requerido) {
        if (!$this->usuario_id) {
            return false;
        }
        
        try {
            $query = "SELECT rol FROM usuarios WHERE id = :usuario_id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':usuario_id', $this->usuario_id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return $result['rol'] === $rol_requerido;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error en checkRole: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Agregar un nuevo módulo al sistema
     */
    public function addModule($nombre, $descripcion, $icono, $grupo, $ruta, $orden = 99) {
        try {
            $query = "INSERT INTO modulos_sistema 
                     (nombre, descripcion, icono, grupo, ruta, orden, activo, created_at) 
                     VALUES 
                     (:nombre, :descripcion, :icono, :grupo, :ruta, :orden, 1, NOW())";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':descripcion', $descripcion);
            $stmt->bindParam(':icono', $icono);
            $stmt->bindParam(':grupo', $grupo);
            $stmt->bindParam(':ruta', $ruta);
            $stmt->bindParam(':orden', $orden, PDO::PARAM_INT);
            
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Error en addModule: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Actualizar un módulo existente
     */
    public function updateModule($id, $data) {
        try {
            $campos = [];
            $valores = [];
            
            foreach ($data as $campo => $valor) {
                if (in_array($campo, ['nombre', 'descripcion', 'icono', 'grupo', 'ruta', 'orden', 'activo'])) {
                    $campos[] = "$campo = :$campo";
                    $valores[":$campo"] = $valor;
                }
            }
            
            if (empty($campos)) {
                return false;
            }
            
            $query = "UPDATE modulos_sistema SET " . implode(', ', $campos) . " WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            foreach ($valores as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            return $stmt->execute();
        } catch (Exception $e) {
            error_log("Error en updateModule: " . $e->getMessage());
            return false;
        }
    }
}
?>