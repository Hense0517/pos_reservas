<?php
// modules/reservas/templates/modal_completar.php
?>
<!-- Modal para completar reserva -->
<div id="modalCompletar" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-lg bg-white">
        <div class="flex justify-between items-center border-b pb-3 mb-4">
            <h3 class="text-xl font-semibold text-gray-900">Completar Reserva</h3>
            <button onclick="cerrarModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="detallesCompletar" class="mb-4 max-h-96 overflow-y-auto">
            <!-- Se llena dinámicamente con JS -->
            <p class="text-center text-gray-500">Cargando detalles...</p>
        </div>
        
        <div class="flex justify-end gap-2 border-t pt-3">
            <button type="button" onclick="cerrarModal()" 
                    class="bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded transition-colors">
                Cancelar
            </button>
            <button type="button" onclick="guardarCompletarReserva()" 
                    class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded transition-colors">
                Completar y Registrar Ingreso
            </button>
        </div>
    </div>
</div>