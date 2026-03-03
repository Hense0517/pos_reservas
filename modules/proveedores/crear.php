<?php
require_once '../../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold text-gray-900">Nuevo Proveedor</h1>
        <a href="index.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex items-center">
            <i class="fas fa-arrow-left mr-2"></i>
            Volver
        </a>
    </div>

    <form action="guardar.php" method="POST" class="bg-white rounded-lg shadow p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Información básica -->
            <div class="md:col-span-2">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Información Básica</h2>
            </div>
            
            <div class="md:col-span-2">
                <label for="nombre" class="block text-sm font-medium text-gray-700">Nombre del Proveedor *</label>
                <input type="text" id="nombre" name="nombre" required
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Ej: Distribuidora Comercial S.A.">
            </div>

            <div>
                <label for="ruc" class="block text-sm font-medium text-gray-700">RUC</label>
                <input type="text" id="ruc" name="ruc"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Ej: 12345678901">
            </div>

            <div>
                <label for="contacto" class="block text-sm font-medium text-gray-700">Persona de Contacto</label>
                <input type="text" id="contacto" name="contacto"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Ej: Juan Pérez">
            </div>

            <!-- Información de contacto -->
            <div class="md:col-span-2">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Información de Contacto</h2>
            </div>

            <div>
                <label for="telefono" class="block text-sm font-medium text-gray-700">Teléfono</label>
                <input type="text" id="telefono" name="telefono"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Ej: 555-1234">
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input type="email" id="email" name="email"
                       class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                       placeholder="Ej: contacto@proveedor.com">
            </div>

            <div class="md:col-span-2">
                <label for="direccion" class="block text-sm font-medium text-gray-700">Dirección</label>
                <textarea id="direccion" name="direccion" rows="3"
                          class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                          placeholder="Dirección completa del proveedor"></textarea>
            </div>

            <!-- Estado -->
            <div class="md:col-span-2">
                <h2 class="text-lg font-semibold mb-4 text-gray-800">Estado</h2>
            </div>

            <div class="md:col-span-2">
                <label for="estado" class="block text-sm font-medium text-gray-700">Estado del Proveedor</label>
                <select id="estado" name="estado"
                        class="mt-1 block w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <option value="activo" selected>Activo</option>
                    <option value="inactivo">Inactivo</option>
                </select>
                <p class="mt-1 text-sm text-gray-500">
                    Los proveedores inactivos no aparecerán en las listas de selección.
                </p>
            </div>
        </div>

        <div class="flex justify-end space-x-3 mt-8 pt-6 border-t">
            <a href="index.php" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                Cancelar
            </a>
            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                <i class="fas fa-save mr-2"></i>
                Guardar Proveedor
            </button>
        </div>
    </form>
</div>

<?php require_once '../../includes/footer.php'; ?>