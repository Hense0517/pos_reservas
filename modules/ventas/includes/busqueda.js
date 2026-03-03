// ============================================
// MÓDULO DE BÚSQUEDA DE PRODUCTOS
// ============================================

const BusquedaModule = (function() {
    // Variables privadas
    let ultimaBusqueda = '';
    let timeoutId = null;

    // Inicializar módulo
    function init() {
        console.log('Módulo de búsqueda iniciado');
        
        const inputBusqueda = document.getElementById('buscarProducto');
        if (inputBusqueda) {
            inputBusqueda.addEventListener('input', manejarInput);
            inputBusqueda.addEventListener('keypress', manejarEnter);
            inputBusqueda.focus();
        }
    }

    // Manejar input de búsqueda
    function manejarInput(e) {
        const query = e.target.value.trim();
        
        if (timeoutId) {
            clearTimeout(timeoutId);
        }
        
        if (query.length < 2) {
            ocultarResultados();
            return;
        }
        
        timeoutId = setTimeout(() => {
            buscarProductos(query);
        }, 300);
    }

    // Manejar tecla Enter
    function manejarEnter(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const query = e.target.value.trim();
            if (query.length > 0) {
                buscarProductoExacto(query);
            }
        }
    }

    // Buscar productos
    async function buscarProductos(query) {
        ultimaBusqueda = query;
        
        try {
            const response = await fetch(`buscar_producto.php?q=${encodeURIComponent(query)}`);
            const contentType = response.headers.get('content-type');
            
            if (contentType && contentType.includes('application/json')) {
                const resultados = await response.json();
                mostrarResultados(resultados);
            }
        } catch (error) {
            console.error('Error en búsqueda:', error);
            if (typeof window.mostrarNotificacion === 'function') {
                window.mostrarNotificacion('Error al buscar productos', 'error');
            }
        }
    }

    // Buscar producto exacto (para Enter)
    async function buscarProductoExacto(query) {
        try {
            const response = await fetch(`buscar_producto.php?q=${encodeURIComponent(query)}`);
            const contentType = response.headers.get('content-type');
            
            if (contentType && contentType.includes('application/json')) {
                const resultados = await response.json();
                
                if (resultados.length === 1) {
                    const producto = resultados[0];
                    const cantidad = parseInt(document.getElementById('cantidadProducto')?.value) || 1;
                    
                    if (typeof window.ProductoSeleccionadoModule !== 'undefined') {
                        window.ProductoSeleccionadoModule.seleccionarProducto(producto);
                        setTimeout(() => {
                            if (typeof window.CarritoModule !== 'undefined') {
                                window.CarritoModule.agregarProducto(producto, cantidad);
                                document.getElementById('buscarProducto').value = '';
                                ocultarResultados();
                            }
                        }, 100);
                    }
                } else if (resultados.length > 1) {
                    mostrarResultados(resultados);
                } else {
                    if (typeof window.mostrarNotificacion === 'function') {
                        window.mostrarNotificacion('Producto no encontrado', 'error');
                    }
                }
            }
        } catch (error) {
            console.error('Error:', error);
        }
    }

    // Mostrar resultados de búsqueda
    function mostrarResultados(resultados) {
        const resultadosDiv = document.getElementById('resultadosBusqueda');
        if (!resultadosDiv) return;

        resultadosDiv.innerHTML = '';
        
        if (resultados.length > 0) {
            resultados.forEach(producto => {
                const div = crearElementoResultado(producto);
                resultadosDiv.appendChild(div);
            });
            resultadosDiv.classList.remove('hidden');
        } else {
            resultadosDiv.innerHTML = '<div class="px-3 py-3 text-gray-500 text-xs text-center fade-in">No se encontraron productos</div>';
            resultadosDiv.classList.remove('hidden');
        }
    }

    // Crear elemento HTML para un resultado
    function crearElementoResultado(producto) {
        const div = document.createElement('div');
        div.className = 'p-3 hover:bg-green-50 cursor-pointer border-b transition-colors duration-150';
        
        const precio = typeof window.formatearDecimal === 'function' 
            ? window.formatearDecimal(parseFloat(producto.precio_venta)) 
            : producto.precio_venta;
        
        const stockColor = producto.stock <= 5 ? 'text-red-600' : 'text-green-600';
        const stockIcon = producto.stock <= 5 ? 'fa-exclamation-triangle' : 'fa-check-circle';
        
        const descripcion = producto.descripcion 
            ? `<div class="text-gray-600 text-xs mt-1">${producto.descripcion}</div>` 
            : '';
        
        const marca = producto.marca_nombre 
            ? `<span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded text-xs font-medium mr-2"><i class="fas fa-copyright mr-1"></i>${producto.marca_nombre}</span>` 
            : '';
        
        const talla = producto.talla 
            ? `<span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-xs mr-2"><i class="fas fa-ruler mr-1"></i>Talla: ${producto.talla}</span>` 
            : '';
        
        const color = producto.color 
            ? `<span class="bg-pink-100 text-pink-800 px-2 py-0.5 rounded text-xs"><i class="fas fa-palette mr-1"></i>${producto.color}</span>` 
            : '';
        
        div.innerHTML = `
            <div class="flex items-start">
                <div class="flex-1">
                    <div class="font-bold text-gray-900 text-sm">${producto.nombre}</div>
                    ${descripcion}
                    <div class="flex flex-wrap items-center gap-1 mt-2">
                        ${marca}
                        ${talla}
                        ${color}
                    </div>
                    <div class="flex justify-between items-center mt-2">
                        <div class="flex items-center space-x-3">
                            <span class="font-bold text-green-700">$${precio}</span>
                            <span class="${stockColor} flex items-center text-xs">
                                <i class="fas ${stockIcon} mr-1"></i>
                                Stock: ${producto.stock}
                            </span>
                        </div>
                        <span class="text-blue-600 text-xs hover:underline">Seleccionar →</span>
                    </div>
                </div>
            </div>
        `;
        
        div.addEventListener('click', () => {
            if (typeof window.ProductoSeleccionadoModule !== 'undefined') {
                window.ProductoSeleccionadoModule.seleccionarProducto(producto);
                document.getElementById('buscarProducto').value = '';
                ocultarResultados();
                document.getElementById('cantidadProducto')?.focus();
                if (typeof window.mostrarNotificacion === 'function') {
                    window.mostrarNotificacion(`Producto seleccionado: ${producto.nombre}`, 'success');
                }
            }
        });
        
        return div;
    }

    function ocultarResultados() {
        const resultadosDiv = document.getElementById('resultadosBusqueda');
        if (resultadosDiv) {
            resultadosDiv.classList.add('hidden');
        }
    }

    // API pública
    return {
        init,
        buscarProductos,
        ocultarResultados
    };
})();

// Exportar al ámbito global
window.BusquedaModule = BusquedaModule;