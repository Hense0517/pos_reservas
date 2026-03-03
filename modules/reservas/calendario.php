<?php
// modules/reservas/index.php
require_once __DIR__ . '/../../includes/config.php';

if (!$auth->hasPermission('reservas', 'leer')) {
    header('HTTP/1.0 403 Forbidden');
    echo 'No tienes permiso para acceder a este módulo.';
    exit;
}

$page_title = 'Gestión de Reservas - Calendario';
include __DIR__ . '/../../includes/header.php';

// Detectar si es móvil (user agent simple)
$es_movil = preg_match('/(android|iphone|ipad|ipod|blackberry|windows phone|opera mini|iemobile)/i', $_SERVER['HTTP_USER_AGENT']);
?>

<link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />

<style>
    /* ============================================
       ESTILOS COMUNES PARA AMBAS VERSIONES
    ============================================ */
    .evento-pendiente { background-color: #fbbf24 !important; border-color: #d97706 !important; color: #000 !important; }
    .evento-confirmada { background-color: #60a5fa !important; border-color: #2563eb !important; color: #fff !important; }
    .evento-completada { background-color: #34d399 !important; border-color: #059669 !important; color: #fff !important; }
    .evento-cancelada { background-color: #f87171 !important; border-color: #dc2626 !important; color: #fff !important; text-decoration: line-through; }
    
    /* Modal styles */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: rgba(0, 0, 0, 0.5);
        backdrop-filter: blur(4px);
        z-index: 9998;
    }
    
    .modal-container {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        pointer-events: none;
    }
    
    .modal-content {
        background: white;
        border-radius: 1rem;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        max-width: 48rem;
        width: 90%;
        max-height: 90vh;
        overflow-y: auto;
        pointer-events: auto;
        animation: modalSlideIn 0.3s ease;
    }
    
    @keyframes modalSlideIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    /* Badges de estado */
    .estado-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.5rem 1rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        font-weight: 600;
        letter-spacing: 0.05em;
    }
    
    .estado-pendiente { background-color: #fef3c7; color: #92400e; border: 1px solid #fbbf24; }
    .estado-confirmada { background-color: #dbeafe; color: #1e40af; border: 1px solid #60a5fa; }
    .estado-completada { background-color: #d1fae5; color: #065f46; border: 1px solid #34d399; }
    .estado-cancelada { background-color: #fee2e2; color: #991b1b; border: 1px solid #f87171; }
    
    /* Tarjetas de información */
    .info-card {
        background: #f9fafb;
        border-radius: 0.75rem;
        padding: 1rem;
        border: 1px solid #e5e7eb;
        transition: all 0.2s ease;
    }
    
    .info-card:hover {
        border-color: #6366f1;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    
    .info-label {
        font-size: 0.75rem;
        font-weight: 500;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 0.25rem;
    }
    
    .info-value {
        font-size: 1rem;
        font-weight: 600;
        color: #1f2937;
    }
    
    /* Botones de acción */
    .action-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0.5rem 1rem;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 500;
        transition: all 0.2s ease;
        cursor: pointer;
        border: none;
        gap: 0.5rem;
    }
    
    .action-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    
    .btn-primary { background: #4f46e5; color: white; }
    .btn-primary:hover { background: #4338ca; }
    .btn-success { background: #10b981; color: white; }
    .btn-success:hover { background: #059669; }
    .btn-danger { background: #ef4444; color: white; }
    .btn-danger:hover { background: #dc2626; }
    .btn-warning { background: #f59e0b; color: white; }
    .btn-warning:hover { background: #d97706; }
    .btn-secondary { background: #6b7280; color: white; }
    .btn-secondary:hover { background: #4b5563; }
    
    /* ============================================
       VERSIÓN PC - ESTILOS ESPECÍFICOS
    ============================================ */
    .pc-version .fc-toolbar {
        padding: 1rem;
    }
    
    .pc-version .fc-toolbar-title {
        font-size: 1.5rem !important;
        font-weight: 600;
    }
    
    .pc-version .fc-button {
        padding: 0.5rem 1rem !important;
        font-size: 0.875rem !important;
        border-radius: 0.5rem !important;
    }
    
    /* ============================================
       VERSIÓN MÓVIL - ESTILOS COMO GOOGLE CALENDAR
    ============================================ */
    .mobile-version .fc-toolbar {
        flex-direction: column;
        gap: 0.5rem;
        padding: 0.5rem;
        background: white;
        border-bottom: 1px solid #e5e7eb;
        position: sticky;
        top: 0;
        z-index: 10;
    }
    
    .mobile-version .fc-toolbar-chunk {
        display: flex;
        justify-content: center;
        width: 100%;
    }
    
    .mobile-version .fc-toolbar-title {
        font-size: 1.25rem !important;
        font-weight: 600;
        color: #1f2937;
    }
    
    .mobile-version .fc-button {
        padding: 0.5rem 0.75rem !important;
        font-size: 0.75rem !important;
        border-radius: 9999px !important;
        background: #f3f4f6 !important;
        border: none !important;
        color: #4b5563 !important;
        box-shadow: none !important;
    }
    
    .mobile-version .fc-button-active {
        background: #4f46e5 !important;
        color: white !important;
    }
    
    .mobile-version .fc-button-group {
        border-radius: 9999px;
        background: #f3f4f6;
        padding: 0.25rem;
    }
    
    .mobile-version .fc-view-harness {
        background: #f9fafb;
    }
    
    .mobile-version .fc-daygrid-day {
        border: 1px solid #f3f4f6 !important;
    }
    
    .mobile-version .fc-daygrid-day-number {
        font-size: 0.875rem;
        padding: 0.5rem !important;
        color: #4b5563;
    }
    
    .mobile-version .fc-day-today {
        background: #eef2ff !important;
    }
    
    .mobile-version .fc-day-today .fc-daygrid-day-number {
        background: #4f46e5;
        color: white !important;
        border-radius: 9999px;
        width: 2rem;
        height: 2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0.25rem;
    }
    
    .mobile-version .fc-event {
        margin: 0 0.25rem 0.25rem 0.25rem !important;
        padding: 0.25rem 0.5rem !important;
        font-size: 0.7rem !important;
        border-radius: 0.375rem !important;
        border-left-width: 3px !important;
    }
    
    .mobile-version .fc-timegrid-slot {
        height: 3rem !important;
    }
    
    .mobile-version .fc-timegrid-slot-label {
        font-size: 0.7rem;
    }
    
    .mobile-version .fc-list-day-cushion {
        background: #f3f4f6 !important;
        padding: 0.75rem 1rem !important;
    }
    
    .mobile-version .fc-list-event {
        padding: 0.75rem 1rem !important;
    }
    
    .mobile-version .fc-list-event-title {
        font-size: 0.875rem;
        font-weight: 500;
    }
    
    .mobile-version .fc-list-event-time {
        font-size: 0.75rem;
        color: #6b7280;
    }
    
    /* Botón flotante para móvil (como Google Calendar) */
    .mobile-fab {
        position: fixed;
        bottom: 2rem;
        right: 1.5rem;
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: #4f46e5;
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3);
        border: none;
        cursor: pointer;
        z-index: 20;
        transition: all 0.2s ease;
    }
    
    .mobile-fab:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 15px rgba(79, 70, 229, 0.4);
    }
    
    .mobile-fab i {
        font-size: 1.5rem;
    }
    
    /* Barra inferior de navegación para móvil (como Google Calendar) */
    .mobile-bottom-nav {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: white;
        border-top: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-around;
        padding: 0.5rem;
        z-index: 15;
        backdrop-filter: blur(10px);
        background: rgba(255, 255, 255, 0.95);
    }
    
    .mobile-bottom-nav-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 0.25rem;
        padding: 0.5rem 1rem;
        border-radius: 9999px;
        color: #6b7280;
        font-size: 0.7rem;
        transition: all 0.2s ease;
        cursor: pointer;
        border: none;
        background: transparent;
    }
    
    .mobile-bottom-nav-item.active {
        color: #4f46e5;
        background: #eef2ff;
    }
    
    .mobile-bottom-nav-item i {
        font-size: 1.25rem;
    }
    
    /* Ajuste de padding para el contenido en móvil */
    .mobile-version .calendar-container {
        padding-bottom: 5rem;
    }
    
    /* Vista de hoy destacada en móvil */
    .mobile-today-bar {
        background: #eef2ff;
        padding: 0.75rem 1rem;
        border-radius: 0.5rem;
        margin: 0.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .mobile-today-bar .date {
        font-weight: 600;
        color: #4f46e5;
    }
    
    .mobile-today-bar .count {
        background: white;
        padding: 0.25rem 0.75rem;
        border-radius: 9999px;
        font-size: 0.75rem;
        color: #4f46e5;
        font-weight: 500;
    }
</style>

<div class="max-w-7xl mx-auto p-4 sm:p-6 <?php echo $es_movil ? 'mobile-version' : 'pc-version'; ?>">
    <?php if (!$es_movil): ?>
    <!-- VERSIÓN PC -->
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
        <div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-800">Calendario de Reservas</h1>
            <p class="text-gray-600 text-sm mt-1">Visualiza y gestiona todas tus reservas</p>
        </div>
        <?php if ($auth->hasPermission('reservas', 'crear')): ?>
        <button id="btnNuevaReserva" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg flex items-center text-sm sm:text-base shadow-md hover:shadow-lg transition-all">
            <i class="fas fa-plus mr-2"></i>Nueva Reserva
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Contenedor del calendario -->
    <div class="bg-white rounded-lg shadow p-4 calendar-container">
        <div id="calendar"></div>
    </div>

    <?php if ($es_movil): ?>
    <!-- VERSIÓN MÓVIL - Elementos estilo Google Calendar -->
    
    <!-- Barra de hoy (visible en vista mes) -->
    <div class="mobile-today-bar hidden" id="mobileTodayBar">
        <span class="date" id="todayDate"><?php echo date('d/m/Y'); ?></span>
        <span class="count" id="todayCount">0 reservas hoy</span>
    </div>
    
    <!-- Botón flotante para nueva reserva -->
    <?php if ($auth->hasPermission('reservas', 'crear')): ?>
    <button id="mobileFabButton" class="mobile-fab">
        <i class="fas fa-plus"></i>
    </button>
    <?php endif; ?>
    
    <!-- Barra de navegación inferior -->
    <div class="mobile-bottom-nav">
        <button class="mobile-bottom-nav-item active" onclick="cambiarVistaMovil('dayGridMonth')">
            <i class="fas fa-calendar-alt"></i>
            <span>Mes</span>
        </button>
        <button class="mobile-bottom-nav-item" onclick="cambiarVistaMovil('timeGridWeek')">
            <i class="fas fa-calendar-week"></i>
            <span>Semana</span>
        </button>
        <button class="mobile-bottom-nav-item" onclick="cambiarVistaMovil('timeGridDay')">
            <i class="fas fa-calendar-day"></i>
            <span>Día</span>
        </button>
        <button class="mobile-bottom-nav-item" onclick="cambiarVistaMovil('listWeek')">
            <i class="fas fa-list"></i>
            <span>Lista</span>
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Modales (igual para ambas versiones) -->
<!-- Modal para crear/editar reserva -->
<div id="modalReserva" class="hidden">
    <div class="modal-overlay" onclick="cerrarModal()"></div>
    <div class="modal-container">
        <div class="modal-content">
            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-6 py-4 rounded-t-lg">
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-3">
                        <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                            <i class="fas fa-calendar-plus text-white text-xl"></i>
                        </div>
                        <h3 class="text-white text-xl font-semibold" id="modalTitle">Nueva Reserva</h3>
                    </div>
                    <button onclick="cerrarModal()" class="text-white hover:text-gray-200 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            
            <div class="p-6">
                <form id="formReserva" onsubmit="guardarReserva(event)">
                    <input type="hidden" id="reserva_id" name="id">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre del Cliente *</label>
                            <input type="text" id="nombre_cliente" name="nombre_cliente" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                   placeholder="Nombre completo">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                            <input type="tel" id="telefono_cliente" name="telefono_cliente"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                   placeholder="Ej: 3001234567">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" id="email_cliente" name="email_cliente"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                   placeholder="cliente@email.com">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fecha y Hora *</label>
                            <input type="datetime-local" id="fecha_hora_reserva" name="fecha_hora_reserva" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Empleado</label>
                            <select id="usuario_id" name="usuario_id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="">Seleccionar empleado...</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Servicio *</label>
                            <select id="servicio_id" name="servicio_id" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="">Seleccionar servicio...</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Observaciones</label>
                        <textarea id="observaciones" name="observaciones" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                  placeholder="Notas adicionales sobre la reserva..."></textarea>
                    </div>
                    
                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" onclick="cerrarModal()" 
                                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                            Cancelar
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition-colors shadow-md hover:shadow-lg">
                            Guardar Reserva
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal para ver detalle de reserva -->
<div id="modalVerReserva" class="hidden">
    <div class="modal-overlay" onclick="cerrarModalVer()"></div>
    <div class="modal-container">
        <div class="modal-content max-w-2xl">
            <div class="bg-gradient-to-r from-indigo-600 to-purple-600 px-6 py-4 rounded-t-lg">
                <div class="flex justify-between items-center">
                    <div class="flex items-center space-x-3">
                        <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                            <i class="fas fa-calendar-check text-white text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-white text-xl font-semibold" id="modalTituloReserva">Detalle de Reserva</h3>
                            <p class="text-indigo-100 text-sm" id="modalCodigoReserva">Cargando...</p>
                        </div>
                    </div>
                    <button onclick="cerrarModalVer()" class="text-white hover:text-gray-200 transition-colors">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            
            <div class="p-6" id="detalleReservaContent">
                <div class="text-center py-8">
                    <i class="fas fa-spinner fa-spin text-indigo-600 text-3xl"></i>
                    <p class="text-gray-500 mt-2">Cargando información...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de confirmación para mover reserva -->
<div id="modalConfirmarMover" class="hidden">
    <div class="modal-overlay" onclick="cerrarModalMover()"></div>
    <div class="modal-container">
        <div class="modal-content max-w-md">
            <div class="bg-gradient-to-r from-yellow-500 to-yellow-600 px-6 py-4 rounded-t-lg">
                <div class="flex items-center space-x-3">
                    <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                        <i class="fas fa-question-circle text-white text-xl"></i>
                    </div>
                    <h3 class="text-white text-xl font-semibold">Confirmar movimiento</h3>
                </div>
            </div>
            <div class="p-6">
                <p class="text-gray-600 mb-4" id="mensajeMover">¿Estás seguro de mover esta reserva?</p>
                <div class="flex justify-end gap-2">
                    <button onclick="cerrarModalMover()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                        Cancelar
                    </button>
                    <button onclick="confirmarMoverReserva()" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition-colors shadow-md hover:shadow-lg">
                        Confirmar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de cancelación/eliminación -->
<div id="modalEliminar" class="hidden">
    <div class="modal-overlay" onclick="cerrarModalEliminar()"></div>
    <div class="modal-container">
        <div class="modal-content max-w-md">
            <div class="bg-gradient-to-r from-red-500 to-red-600 px-6 py-4 rounded-t-lg">
                <div class="flex items-center space-x-3">
                    <div class="bg-white bg-opacity-20 p-2 rounded-lg">
                        <i class="fas fa-exclamation-triangle text-white text-xl"></i>
                    </div>
                    <h3 class="text-white text-xl font-semibold">Cancelar Reserva</h3>
                </div>
            </div>
            <div class="p-6">
                <p class="text-gray-600 mb-4" id="mensajeEliminar">¿Estás seguro de cancelar esta reserva?</p>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Motivo de cancelación</label>
                    <textarea id="motivoEliminar" rows="3" 
                              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"
                              placeholder="Indique el motivo..."></textarea>
                </div>
                
                <div class="flex justify-end gap-2">
                    <button onclick="cerrarModalEliminar()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-colors">
                        Cancelar
                    </button>
                    <button onclick="confirmarEliminar()" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors shadow-md hover:shadow-lg">
                        Confirmar Cancelación
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
<script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/es.js'></script>

<script>
// Variables globales
let calendar;
let reservaIdMover = null;
let nuevaFechaMover = null;
let reservaIdEliminar = null;

// Detección de dispositivo
const esMovil = <?php echo $es_movil ? 'true' : 'false'; ?>;

// Permisos desde PHP
const puedeEditar = <?php echo $auth->hasPermission('reservas', 'editar') ? 'true' : 'false'; ?>;
const puedeEliminar = <?php echo $auth->hasPermission('reservas', 'eliminar') ? 'true' : 'false'; ?>;
const puedeCompletar = <?php echo $auth->hasPermission('reservas', 'completar') ? 'true' : 'false'; ?>;
const puedeCrear = <?php echo $auth->hasPermission('reservas', 'crear') ? 'true' : 'false'; ?>;

document.addEventListener('DOMContentLoaded', function() {
    var calendarEl = document.getElementById('calendar');
    
    // Configuración según dispositivo
    const configuracionCalendario = {
        locale: 'es',
        buttonText: {
            today: 'Hoy',
            month: 'Mes',
            week: 'Semana',
            day: 'Día',
            list: 'Lista'
        },
        events: function(fetchInfo, successCallback, failureCallback) {
            fetch('ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'obtener_eventos',
                    start: fetchInfo.startStr.split('T')[0],
                    end: fetchInfo.endStr.split('T')[0]
                })
            })
            .then(response => response.json())
            .then(data => {
                successCallback(data);
                if (esMovil) {
                    actualizarContadorHoy(data);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                failureCallback(error);
            });
        },
        eventDidMount: function(info) {
            info.el.setAttribute('title', `${info.event.title}\n${info.event.extendedProps.usuario || 'Sin empleado'}`);
        },
        dateClick: function(info) {
            if (puedeCrear) {
                abrirModalNueva(info.dateStr);
            }
        },
        eventClick: function(info) {
            verReserva(info.event.id);
        },
        editable: puedeEditar,
        eventDrop: function(info) {
            const estado = info.event.extendedProps.estado;
            if (estado === 'completada' || estado === 'cancelada') {
                alert('No se puede mover una reserva ' + estado);
                info.revert();
                return;
            }
            
            reservaIdMover = info.event.id;
            nuevaFechaMover = info.event.startStr;
            document.getElementById('mensajeMover').textContent = 
                `¿Mover reserva de ${new Date(info.oldEvent.startStr).toLocaleString('es-ES')} a ${new Date(info.event.startStr).toLocaleString('es-ES')}?`;
            document.getElementById('modalConfirmarMover').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            info.revert();
        }
    };
    
    // Configuración específica para móvil
    if (esMovil) {
        configuracionCalendario.initialView = 'dayGridMonth';
        configuracionCalendario.headerToolbar = false; // Ocultar toolbar PC
        configuracionCalendario.height = 'auto';
        configuracionCalendario.contentHeight = 'auto';
    } else {
        configuracionCalendario.initialView = window.innerWidth < 768 ? 'listWeek' : 'timeGridWeek';
        configuracionCalendario.headerToolbar = {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        };
    }
    
    calendar = new FullCalendar.Calendar(calendarEl, configuracionCalendario);
    calendar.render();

    // Event listeners según versión
    if (esMovil) {
        document.getElementById('mobileFabButton')?.addEventListener('click', function() {
            abrirModalNueva();
        });
        
        // Mostrar barra de hoy
        document.getElementById('mobileTodayBar').classList.remove('hidden');
    } else {
        document.getElementById('btnNuevaReserva')?.addEventListener('click', function() {
            abrirModalNueva();
        });
    }
    
    // Cargar servicios y usuarios
    cargarServicios();
    cargarUsuarios();
});

// Función para cambiar vista en móvil
function cambiarVistaMovil(vista) {
    if (calendar) {
        calendar.changeView(vista);
        
        // Actualizar botón activo
        document.querySelectorAll('.mobile-bottom-nav-item').forEach(btn => {
            btn.classList.remove('active');
        });
        event.target.closest('.mobile-bottom-nav-item').classList.add('active');
        
        // Mostrar/ocultar barra de hoy según vista
        const todayBar = document.getElementById('mobileTodayBar');
        if (vista === 'dayGridMonth') {
            todayBar.classList.remove('hidden');
        } else {
            todayBar.classList.add('hidden');
        }
    }
}

// Función para actualizar contador de hoy
function actualizarContadorHoy(eventos) {
    const hoy = new Date().toISOString().split('T')[0];
    const eventosHoy = eventos.filter(e => e.start.startsWith(hoy));
    document.getElementById('todayCount').textContent = `${eventosHoy.length} reservas hoy`;
}

// Resto de funciones (se mantienen igual)...
// ============================================
// FUNCIONES DEL MODAL DE RESERVA
// ============================================
function abrirModalNueva(fechaHoraInicio = null) {
    const modal = document.getElementById('modalReserva');
    const form = document.getElementById('formReserva');
    form.reset();
    document.getElementById('reserva_id').value = '';

    if (fechaHoraInicio) {
        let fechaHora = fechaHoraInicio;
        if (fechaHora.length === 10) {
            const ahora = new Date();
            fechaHora = fechaHora + 'T' + String(ahora.getHours()).padStart(2, '0') + ':00';
        }
        document.getElementById('fecha_hora_reserva').value = fechaHora.substring(0, 16);
    } else {
        const ahora = new Date();
        ahora.setHours(ahora.getHours() + 1);
        ahora.setMinutes(0, 0, 0);
        const año = ahora.getFullYear();
        const mes = String(ahora.getMonth() + 1).padStart(2, '0');
        const dia = String(ahora.getDate()).padStart(2, '0');
        const horas = String(ahora.getHours()).padStart(2, '0');
        document.getElementById('fecha_hora_reserva').value = `${año}-${mes}-${dia}T${horas}:00`;
    }

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function cerrarModal() {
    document.getElementById('modalReserva').classList.add('hidden');
    document.body.style.overflow = '';
}

function cargarServicios() {
    fetch('ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'obtener_servicios' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            let select = document.getElementById('servicio_id');
            select.innerHTML = '<option value="">Seleccionar servicio...</option>';
            data.data.forEach(s => {
                select.innerHTML += `<option value="${s.id}" data-precio="${s.precio}">${s.nombre} - $${parseFloat(s.precio).toFixed(2)}${s.precio_variable ? ' (Variable)' : ''}</option>`;
            });
        }
    })
    .catch(error => console.error('Error cargando servicios:', error));
}

function cargarUsuarios() {
    fetch('ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ action: 'obtener_usuarios_servicio' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            let select = document.getElementById('usuario_id');
            select.innerHTML = '<option value="">Seleccionar empleado...</option>';
            data.data.forEach(u => {
                select.innerHTML += `<option value="${u.id}">${u.nombre} (${u.rol})</option>`;
            });
        }
    })
    .catch(error => console.error('Error cargando usuarios:', error));
}

function guardarReserva(event) {
    event.preventDefault();
    
    const formData = new FormData(event.target);
    const data = new URLSearchParams();
    data.append('action', 'guardar_reserva');
    
    for (let [key, value] of formData.entries()) {
        data.append(key, value);
    }

    fetch('ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            mostrarNotificacion('✅ Reserva guardada con éxito', 'success');
            cerrarModal();
            calendar.refetchEvents();
        } else {
            mostrarNotificacion('❌ Error: ' + result.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarNotificacion('❌ Error al guardar la reserva', 'error');
    });
}

// ============================================
// FUNCIONES DEL MODAL DE DETALLE
// ============================================
function verReserva(id) {
    const modal = document.getElementById('modalVerReserva');
    const contentDiv = document.getElementById('detalleReservaContent');
    contentDiv.innerHTML = `
        <div class="text-center py-12">
            <i class="fas fa-spinner fa-spin text-indigo-600 text-4xl"></i>
            <p class="text-gray-500 mt-3">Cargando información de la reserva...</p>
        </div>
    `;
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';

    fetch('ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'obtener_detalle',
            id: id
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const r = data.data;
            
            document.getElementById('modalTituloReserva').textContent = `Reserva: ${r.nombre_cliente}`;
            document.getElementById('modalCodigoReserva').textContent = r.codigo_reserva || 'Sin código';
            
            let serviciosHtml = '';
            if (r.servicios && r.servicios.length > 0) {
                serviciosHtml = r.servicios.map(s => `
                    <div class="servicio-item flex justify-between items-center p-3 bg-gray-50 rounded-lg mb-2">
                        <span class="font-medium">${s.nombre_servicio}</span>
                        <span class="font-semibold text-indigo-600">$${parseFloat(s.precio_original).toFixed(2)}</span>
                    </div>
                `).join('');
            } else {
                serviciosHtml = '<p class="text-gray-500 text-center py-2">No hay servicios registrados</p>';
            }
            
            let productosHtml = '';
            if (r.productos && r.productos.length > 0) {
                productosHtml = `
                    <div class="mt-4">
                        <h4 class="text-sm font-medium text-gray-700 mb-2">Productos adicionales:</h4>
                        ${r.productos.map(p => `
                            <div class="flex justify-between items-center p-3 bg-green-50 rounded-lg mb-2">
                                <span>${p.nombre_producto} x${p.cantidad}</span>
                                <span class="font-semibold text-green-600">$${parseFloat(p.subtotal).toFixed(2)}</span>
                            </div>
                        `).join('')}
                    </div>
                `;
            }
            
            const estadoClass = {
                'pendiente': 'bg-yellow-100 text-yellow-800',
                'confirmada': 'bg-blue-100 text-blue-800',
                'completada': 'bg-green-100 text-green-800',
                'cancelada': 'bg-red-100 text-red-800'
            }[r.estado] || 'bg-gray-100 text-gray-800';

            contentDiv.innerHTML = `
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-2 text-sm">
                        <p class="text-gray-600 font-medium">Código:</p>
                        <p class="font-medium">${r.codigo_reserva || 'N/A'}</p>
                        
                        <p class="text-gray-600 font-medium">Cliente:</p>
                        <p class="font-medium">${r.nombre_cliente}</p>
                        
                        <p class="text-gray-600 font-medium">Teléfono:</p>
                        <p class="font-medium">${r.telefono_cliente || 'N/A'}</p>
                        
                        <p class="text-gray-600 font-medium">Email:</p>
                        <p class="font-medium">${r.email_cliente || 'N/A'}</p>
                        
                        <p class="text-gray-600 font-medium">Fecha:</p>
                        <p class="font-medium">${new Date(r.fecha_hora_reserva).toLocaleDateString('es-ES')}</p>
                        
                        <p class="text-gray-600 font-medium">Hora:</p>
                        <p class="font-medium">${new Date(r.fecha_hora_reserva).toLocaleTimeString('es-ES', {hour: '2-digit', minute:'2-digit'})}</p>
                        
                        <p class="text-gray-600 font-medium">Empleado:</p>
                        <p class="font-medium">${r.usuario_nombre || 'No asignado'}</p>
                        
                        <p class="text-gray-600 font-medium">Estado:</p>
                        <p><span class="px-2 py-1 rounded text-xs font-semibold capitalize ${estadoClass}">${r.estado}</span></p>
                    </div>
                    
                    ${r.observaciones ? `
                    <div class="border-t pt-2">
                        <p class="text-gray-600 font-medium">Observaciones:</p>
                        <p class="text-gray-700 bg-gray-50 p-2 rounded text-sm">${r.observaciones}</p>
                    </div>
                    ` : ''}
                    
                    <div class="border-t pt-2">
                        <p class="text-gray-600 font-medium mb-2">Servicios:</p>
                        ${serviciosHtml}
                    </div>
                    
                    ${productosHtml}
                    
                    <div class="border-t pt-2 flex justify-between items-center">
                        <p class="text-gray-600 font-medium">Total:</p>
                        <p class="text-xl font-bold text-indigo-600">$${parseFloat(r.total_general).toFixed(2)}</p>
                    </div>
                    
                    <div class="border-t pt-3 flex flex-wrap gap-2 justify-end">
                        <button onclick="cerrarModalVer()" class="action-button btn-secondary">Cerrar</button>
                        
                        ${puedeEditar && r.estado !== 'completada' && r.estado !== 'cancelada' ? `
                        <a href="editar.php?id=${r.id}" class="action-button btn-primary">Editar</a>
                        ` : ''}
                        
                        ${puedeCompletar && r.estado === 'confirmada' ? `
                        <a href="completar.php?id=${r.id}" class="action-button btn-success">Completar</a>
                        ` : ''}
                        
                        ${puedeEliminar && r.estado !== 'completada' && r.estado !== 'cancelada' ? `
                        <button onclick="mostrarModalEliminar(${r.id}, '${r.nombre_cliente.replace(/'/g, "\\'")}')" class="action-button btn-danger">Cancelar</button>
                        ` : ''}
                    </div>
                </div>
            `;
        } else {
            contentDiv.innerHTML = `<p class="text-center text-red-500">Error: ${data.message}</p>`;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        contentDiv.innerHTML = '<p class="text-center text-red-500">Error al cargar el detalle</p>';
    });
}

function cerrarModalVer() {
    document.getElementById('modalVerReserva').classList.add('hidden');
    document.body.style.overflow = '';
}

// ============================================
// FUNCIONES PARA MOVER RESERVA
// ============================================
function cerrarModalMover() {
    document.getElementById('modalConfirmarMover').classList.add('hidden');
    reservaIdMover = null;
    nuevaFechaMover = null;
    document.body.style.overflow = '';
}

function confirmarMoverReserva() {
    if (!reservaIdMover || !nuevaFechaMover) return;
    
    fetch('ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'mover_reserva',
            id: reservaIdMover,
            fecha_hora_inicio: nuevaFechaMover
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            mostrarNotificacion('✅ Reserva movida correctamente', 'success');
            calendar.refetchEvents();
        } else {
            mostrarNotificacion('❌ Error: ' + data.message, 'error');
            calendar.refetchEvents();
        }
        cerrarModalMover();
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarNotificacion('❌ Error al mover la reserva', 'error');
        calendar.refetchEvents();
        cerrarModalMover();
    });
}

// ============================================
// FUNCIONES PARA CANCELAR/ELIMINAR RESERVA
// ============================================
function mostrarModalEliminar(id, cliente) {
    cerrarModalVer();
    setTimeout(() => {
        reservaIdEliminar = id;
        document.getElementById('mensajeEliminar').textContent = `¿Cancelar reserva de ${cliente}?`;
        document.getElementById('modalEliminar').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }, 300);
}

function cerrarModalEliminar() {
    document.getElementById('modalEliminar').classList.add('hidden');
    document.getElementById('motivoEliminar').value = '';
    reservaIdEliminar = null;
    document.body.style.overflow = '';
}

function confirmarEliminar() {
    const motivo = document.getElementById('motivoEliminar').value.trim();
    
    if (!motivo) {
        mostrarNotificacion('Debe ingresar un motivo de cancelación', 'warning');
        return;
    }
    
    if (!reservaIdEliminar) return;
    
    fetch('ajax.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'eliminar_reserva',
            id: reservaIdEliminar,
            motivo: motivo
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            mostrarNotificacion('✅ Reserva cancelada correctamente', 'success');
            cerrarModalEliminar();
            calendar.refetchEvents();
        } else {
            mostrarNotificacion('❌ Error: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        mostrarNotificacion('❌ Error al cancelar la reserva', 'error');
    });
}

// ============================================
// FUNCIÓN DE NOTIFICACIÓN
// ============================================
function mostrarNotificacion(mensaje, tipo = 'info') {
    const notificacion = document.createElement('div');
    notificacion.className = `fixed top-4 right-4 px-4 py-3 rounded-lg shadow-lg z-50`;
    
    const colores = {
        success: 'bg-green-500',
        error: 'bg-red-500',
        warning: 'bg-yellow-500',
        info: 'bg-blue-500'
    };
    
    notificacion.classList.add(colores[tipo] || colores.info);
    notificacion.innerHTML = `
        <div class="flex items-center text-white">
            <i class="fas fa-${tipo === 'success' ? 'check-circle' : (tipo === 'error' ? 'exclamation-circle' : (tipo === 'warning' ? 'exclamation-triangle' : 'info-circle'))} mr-2"></i>
            <span>${mensaje}</span>
        </div>
    `;
    
    document.body.appendChild(notificacion);
    
    setTimeout(() => {
        notificacion.style.transition = 'opacity 0.3s ease';
        notificacion.style.opacity = '0';
        setTimeout(() => notificacion.remove(), 300);
    }, 3000);
}

// ============================================
// Cerrar modales con Escape
// ============================================
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        cerrarModal();
        cerrarModalVer();
        cerrarModalMover();
        cerrarModalEliminar();
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>