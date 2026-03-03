<?php
// includes/recursos.php
// CORREGIDO: La ruta correcta es config.php (mismo directorio)
// ELIMINAR ESTA LÍNEA: require_once 'includes/config.php';

// recursos.php - Funciones para estilos y recursos comunes

function estilos_base() {
    return '
    <!-- Font Awesome para iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Tailwind CSS v4 - Play CDN (solo para desarrollo) -->
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    
    <!-- Estilos personalizados con Tailwind v4 -->
    <style type="text/tailwindcss">
        @theme {
            --color-primary: #2563eb;
            --color-secondary: #7c3aed;
            --color-success: #059669;
            --color-danger: #dc2626;
            --color-warning: #d97706;
            --color-info: #0891b2;
            
            --color-ventas: #f97316;
            --color-compras: #ec4899;
            --color-productos: #14b8a6;
            --color-clientes: #8b5cf6;
        }
        
        /* Clases personalizadas usando @layer */
        @layer components {
            .sidebar { 
                @apply fixed top-[73px] left-0 w-64 h-[calc(100vh-73px)] bg-white border-r border-gray-200 overflow-y-auto transition-all duration-300 z-40;
            }
            
            .main-content { 
                @apply ml-64 mt-[73px] p-6 transition-all duration-300 min-h-[calc(100vh-73px)];
            }
            
            .header { 
                @apply fixed top-0 left-0 right-0 h-[73px] z-50;
            }
            
            .dropdown-menu { 
                @apply hidden absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border border-gray-200 z-50;
            }
            
            .dropdown-menu.active { 
                @apply block;
            }
            
            .dropdown-item { 
                @apply flex items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 border-b border-gray-100 last:border-0;
            }
            
            .dropdown-item i { 
                @apply w-5 mr-3 text-gray-500;
            }
            
            .card-hover {
                @apply transition-all duration-300 hover:shadow-lg hover:-translate-y-1;
            }
        }
        
        /* Scrollbar personalizado */
        @layer utilities {
            ::-webkit-scrollbar {
                @apply w-2 h-2;
            }
            ::-webkit-scrollbar-track {
                @apply bg-gray-100;
            }
            ::-webkit-scrollbar-thumb {
                @apply bg-gray-400 rounded-full hover:bg-gray-500;
            }
        }
        
        /* Media queries con @custom-media */
        @custom-media --mobile (max-width: 768px);
        
        @media (--mobile) {
            .sidebar { 
                @apply -translate-x-full fixed;
            }
            .sidebar.active { 
                @apply translate-x-0;
            }
            .main-content { 
                @apply ml-0;
            }
        }
    </style>
    
    <!-- Estilos CSS de respaldo para navegadores que no soportan type="text/tailwindcss" -->
    <style>
        /* Fallback styles */
        .sidebar {
            position: fixed;
            top: 73px;
            left: 0;
            width: 16rem;
            height: calc(100vh - 73px);
            background: white;
            border-right: 1px solid #e5e7eb;
            overflow-y: auto;
            transition: all 0.3s ease;
            z-index: 40;
        }
        
        .main-content {
            margin-left: 16rem;
            margin-top: 73px;
            padding: 1.5rem;
            transition: all 0.3s ease;
            min-height: calc(100vh - 73px);
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
            }
            .sidebar.active {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
        }
        
        .dropdown-menu {
            display: none;
            position: absolute;
            right: 0;
            margin-top: 0.5rem;
            width: 12rem;
            background: white;
            border-radius: 0.375rem;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            border: 1px solid #e5e7eb;
            z-index: 50;
        }
        
        .dropdown-menu.active {
            display: block;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 1rem;
            color: #374151;
            font-size: 0.875rem;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .dropdown-item:hover {
            background-color: #f9fafb;
        }
        
        .dropdown-item i {
            width: 1rem;
            margin-right: 0.75rem;
            color: #6b7280;
        }
    </style>
    ';
}

function scripts_base() {
    return '
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // Toggle menú de usuario
        const userMenuButton = document.getElementById("userMenuButton");
        const userMenu = document.getElementById("userMenu");
        
        if (userMenuButton && userMenu) {
            userMenuButton.addEventListener("click", function(e) {
                e.preventDefault();
                e.stopPropagation();
                userMenu.classList.toggle("active");
            });
            
            document.addEventListener("click", function(e) {
                if (userMenu && userMenu.classList.contains("active")) {
                    if (!userMenu.contains(e.target) && e.target !== userMenuButton) {
                        userMenu.classList.remove("active");
                    }
                }
            });
        }
        
        // Toggle menú lateral en móviles
        const menuToggle = document.getElementById("menuToggle");
        const sidebar = document.getElementById("sidebar");
        
        if (menuToggle && sidebar) {
            menuToggle.addEventListener("click", function(e) {
                e.preventDefault();
                sidebar.classList.toggle("active");
                
                if (window.innerWidth <= 768) {
                    if (sidebar.classList.contains("active")) {
                        // Crear overlay
                        let overlay = document.getElementById("sidebarOverlay");
                        if (!overlay) {
                            overlay = document.createElement("div");
                            overlay.id = "sidebarOverlay";
                            overlay.className = "fixed inset-0 bg-black bg-opacity-50 z-30 lg:hidden";
                            document.body.appendChild(overlay);
                            
                            overlay.addEventListener("click", function() {
                                sidebar.classList.remove("active");
                                document.body.removeChild(overlay);
                            });
                        }
                    } else {
                        const overlay = document.getElementById("sidebarOverlay");
                        if (overlay) document.body.removeChild(overlay);
                    }
                }
            });
        }
        
        // Manejar resize de ventana
        window.addEventListener("resize", function() {
            // Cerrar menú de usuario si está abierto
            const userMenu = document.getElementById("userMenu");
            const userMenuButton = document.getElementById("userMenuButton");
            if (userMenu && userMenu.classList.contains("active")) {
                userMenu.classList.remove("active");
            }
            
            // En desktop, asegurar que el sidebar esté visible
            const sidebar = document.getElementById("sidebar");
            if (window.innerWidth > 768) {
                if (sidebar && sidebar.classList.contains("active")) {
                    sidebar.classList.remove("active");
                }
                const overlay = document.getElementById("sidebarOverlay");
                if (overlay) document.body.removeChild(overlay);
            }
        });
        
        console.log("Tailwind CSS v4 Play CDN cargado correctamente");
    });
    </script>
    ';
}

// Función para colores personalizados del negocio
function colores_negocio($color = 'primary') {
    $colores = [
        'primary' => 'blue-600',
        'secondary' => 'violet-600',
        'success' => 'emerald-600',
        'danger' => 'red-600',
        'warning' => 'amber-600',
        'info' => 'cyan-600',
        'ventas' => 'orange-600',
        'compras' => 'pink-600',
        'productos' => 'teal-600',
        'clientes' => 'violet-600',
    ];
    
    return $colores[$color] ?? 'blue-600';
}

// Función para gradientes predefinidos
function gradiente($tipo = 'primary') {
    $gradientes = [
        'primary' => 'from-blue-600 to-violet-600',
        'ventas' => 'from-orange-600 to-amber-600',
        'compras' => 'from-pink-600 to-rose-600',
        'productos' => 'from-teal-600 to-emerald-600',
        'clientes' => 'from-violet-600 to-purple-600',
        'exito' => 'from-emerald-600 to-teal-600',
        'peligro' => 'from-red-600 to-rose-600',
    ];
    
    return $gradientes[$tipo] ?? 'from-blue-600 to-violet-600';
}

// Función para animaciones CSS
function animacion($tipo = 'fade') {
    $animaciones = [
        'fade' => 'transition-opacity duration-300',
        'slide' => 'transition-transform duration-300',
        'scale' => 'transition-transform duration-300 hover:scale-105',
        'shadow' => 'transition-shadow duration-300 hover:shadow-lg',
        'all' => 'transition-all duration-300 hover:shadow-lg hover:-translate-y-1',
    ];
    
    return $animaciones[$tipo] ?? 'transition-all duration-300';
}
?>