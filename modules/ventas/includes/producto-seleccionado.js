// ============================================
// MÓDULO DE PRODUCTO SELECCIONADO
// ============================================

const ProductoSeleccionadoModule = (function() {
    // Variables privadas
    let productoActual = null;

    // Inicializar módulo
    function init() {
        console.log('Módulo de producto seleccionado iniciado');
        
        document.getElementById('btnIncrementar')?.addEventListener('click', incrementarCantidad);
        document.getElementById('btnDecrementar')?.addEventListener('click', decrementarCantidad);
        document.getElementById('agregarProducto')?.addEventListener('click', agregarAlCarrito);
    }

    // Seleccionar producto
    function seleccionarProducto(producto) {
        productoActual = producto;
        
        document.getElementById('productoNombre').textContent = producto.nombre;
        document.getElementById('productoPrecio').textContent = `$${
            typeof window.formatearDecimal === 'function' 
                ? window.formatearDecimal(parseFloat(producto.precio_venta)) 
                : producto.precio_venta
        }`;
        document.getElementById('productoStock').textContent = producto.stock;
        document.getElementById('productoSeleccionadoInfo').classList.remove('hidden');
        
        const inputCantidad = document.getElementById('cantidadProducto');
        inputCantidad.max = producto.stock;
        inputCantidad.value = 1;
    }

    // Limpiar selección
    function limpiarSeleccion() {
        productoActual = null;
        document.getElementById('productoSeleccionadoInfo').classList.add('hidden');
        document.getElementById('cantidadProducto').value = 1;
    }

    // Incrementar cantidad
    function incrementarCantidad() {
        const input = document.getElementById('cantidadProducto');
        if (input) {
            input.value = parseInt(input.value) + 1;
        }
    }

    // Decrementar cantidad
    function decrementarCantidad() {
        const input = document.getElementById('cantidadProducto');
        if (input && parseInt(input.value) > 1) {
            input.value = parseInt(input.value) - 1;
        }
    }

    // Agregar al carrito
    function agregarAlCarrito() {
        if (!productoActual) {
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion('Primero busca y selecciona un producto', 'warning');
            }
            document.getElementById('buscarProducto')?.focus();
            return;
        }
        
        const cantidad = parseInt(document.getElementById('cantidadProducto').value);
        
        if (typeof window.CarritoModule !== 'undefined') {
            const agregado = window.CarritoModule.agregarProducto(productoActual, cantidad);
            if (agregado) {
                limpiarSeleccion();
            }
        }
    }

    // Obtener producto actual
    function getProductoActual() {
        return productoActual;
    }

    // API pública
    return {
        init,
        seleccionarProducto,
        limpiarSeleccion,
        getProductoActual,
        incrementarCantidad,
        decrementarCantidad,
        agregarAlCarrito
    };
})();

// Exportar al ámbito global
window.ProductoSeleccionadoModule = ProductoSeleccionadoModule;