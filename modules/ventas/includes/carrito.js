// ============================================
// MÓDULO DE CARRITO DE COMPRAS
// ============================================

const CarritoModule = (function() {
    // Variables privadas
    let productos = [];
    let productoSeleccionadoIndex = null;

    // Inicializar módulo
    function init() {
        console.log('Módulo de carrito iniciado');
        actualizarVista();
    }

    // Agregar producto al carrito
    function agregarProducto(producto, cantidad) {
        if (!producto) return false;
        
        if (cantidad > producto.stock) {
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion(`Stock insuficiente. Disponible: ${producto.stock}`, 'error');
            }
            return false;
        }
        
        const index = productos.findIndex(p => p.id == producto.id);
        
        if (index > -1) {
            const nuevaCantidad = productos[index].cantidad + cantidad;
            if (nuevaCantidad > producto.stock) {
                if (typeof window.mostrarNotificacion === 'function') {
                    window.mostrarNotificacion(`Stock insuficiente. Máximo: ${producto.stock}`, 'error');
                }
                return false;
            }
            productos[index].cantidad = nuevaCantidad;
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion(`Cantidad actualizada: ${nuevaCantidad} unidades`, 'success');
            }
        } else {
            productos.push({
                id: producto.id,
                nombre: producto.nombre,
                precio: parseFloat(producto.precio_venta),
                cantidad: cantidad,
                stock: producto.stock,
                codigo: producto.codigo,
                codigo_barras: producto.codigo_barras,
                talla: producto.talla,
                color: producto.color,
                marca_nombre: producto.marca_nombre
            });
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion('Producto agregado al carrito', 'success');
            }
        }
        
        actualizarVista();
        if (typeof window.PausasModule !== 'undefined') {
            window.PausasModule.actualizarBotonPausa();
        }
        return true;
    }

    // Eliminar producto del carrito
    function eliminarProducto(index) {
        if (index >= 0 && index < productos.length) {
            const producto = productos[index];
            productos.splice(index, 1);
            if (productoSeleccionadoIndex === index) {
                productoSeleccionadoIndex = null;
            } else if (productoSeleccionadoIndex > index) {
                productoSeleccionadoIndex--;
            }
            actualizarVista();
            if (typeof window.PausasModule !== 'undefined') {
                window.PausasModule.actualizarBotonPausa();
            }
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion(`Producto "${producto.nombre}" eliminado`, 'info');
            }
        }
    }

    // Eliminar producto seleccionado
    function eliminarSeleccionado() {
        if (productoSeleccionadoIndex !== null) {
            eliminarProducto(productoSeleccionadoIndex);
        } else {
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion('Selecciona un producto del carrito primero', 'warning');
            }
        }
    }

    // Limpiar carrito
    function limpiar() {
        if (productos.length === 0) {
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion('El carrito ya está vacío', 'info');
            }
            return false;
        }
        
        Swal.fire({
            title: '¿Vaciar carrito?',
            text: 'Se eliminarán todos los productos del carrito',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#64748b',
            confirmButtonText: 'Sí, vaciar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                productos = [];
                productoSeleccionadoIndex = null;
                actualizarVista();
                if (typeof window.PausasModule !== 'undefined') {
                    window.PausasModule.actualizarBotonPausa();
                }
                if (typeof window.mostrarNotificacion === 'function') {
                    window.mostrarNotificacion('Carrito vaciado', 'success');
                }
            }
        });
    }

    // Seleccionar producto en el carrito
    function seleccionarProducto(index) {
        productoSeleccionadoIndex = index;
        actualizarVista();
    }

    // Actualizar vista del carrito
    function actualizarVista() {
        const lista = document.getElementById('listaProductos');
        const contador = document.getElementById('contadorProductos');
        
        if (!lista || !contador) return;
        
        if (productos.length === 0) {
            lista.innerHTML = `
                <div class="text-center py-8 fade-in">
                    <div class="text-gray-300 mb-2">
                        <i class="fas fa-shopping-cart text-2xl"></i>
                    </div>
                    <p class="text-gray-500 text-xs">Carrito vacío</p>
                    <p class="text-gray-400 text-xs mt-1">Agrega productos</p>
                </div>
            `;
            contador.textContent = '0';
            return;
        }
        
        contador.textContent = productos.length;
        
        let html = '<div class="space-y-2">';
        productos.forEach((producto, index) => {
            const subtotal = producto.precio * producto.cantidad;
            const esSeleccionado = productoSeleccionadoIndex === index;
            
            // Construir badges de atributos
            let atributosHTML = '';
            if (producto.talla) {
                atributosHTML += `<span class="inline-block bg-blue-100 text-blue-800 text-xs px-1 rounded mr-1">T:${producto.talla}</span>`;
            }
            if (producto.color) {
                atributosHTML += `<span class="inline-block bg-pink-100 text-pink-800 text-xs px-1 rounded">C:${producto.color}</span>`;
            }
            
            html += `
                <div class="product-item p-2 ${esSeleccionado ? 'bg-blue-50' : ''} cursor-pointer fade-in" 
                     onclick="CarritoModule.seleccionarProducto(${index})">
                    <div class="flex justify-between items-center">
                        <div class="flex-1 min-w-0">
                            <div class="font-medium text-gray-900 text-xs truncate">${producto.nombre}</div>
                            <div class="flex items-center mt-1">
                                ${atributosHTML}
                            </div>
                            <div class="text-gray-500 text-xs mt-1">
                                $${window.formatearDecimal ? window.formatearDecimal(producto.precio) : producto.precio} x ${producto.cantidad}
                            </div>
                        </div>
                        <div class="text-right ml-2">
                            <div class="font-bold text-green-600 text-xs">$${window.formatearDecimal ? window.formatearDecimal(subtotal) : subtotal}</div>
                            <button type="button" onclick="event.stopPropagation(); CarritoModule.eliminarProducto(${index})" 
                                    class="text-red-500 hover:text-red-700 text-xs mt-0.5">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
        html += '</div>';
        lista.innerHTML = html;
    }

    // Calcular totales
    function calcularTotales() {
        let subtotal = 0;
        productos.forEach(producto => {
            subtotal += producto.precio * producto.cantidad;
        });
        return subtotal;
    }

    // Obtener productos
    function getProductos() {
        return [...productos];
    }

    // Verificar si el carrito está vacío
    function estaVacio() {
        return productos.length === 0;
    }

    // API pública
    return {
        init,
        agregarProducto,
        eliminarProducto,
        eliminarSeleccionado,
        limpiar,
        seleccionarProducto,
        calcularTotales,
        getProductos,
        estaVacio
    };
})();

// Exportar al ámbito global
window.CarritoModule = CarritoModule;