/* login.js — intro, fondos rotativos, guía de campos y validación del formulario de acceso.
   Es solo experiencia de usuario: la validación real y la seguridad siguen en login.php (servidor). */
(function () {
    'use strict';

    var INTERVALO_MS = 6500;
    var reducido = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function $(selector, root) { return (root || document).querySelector(selector); }
    function $all(selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }

    /** Reemplaza el contenido de un botón por [texto opcional] + icono, sin usar innerHTML. */
    function pintarBoton(boton, texto, claseIcono) {
        while (boton.firstChild) { boton.removeChild(boton.firstChild); }
        if (texto) {
            var span = document.createElement('span');
            span.textContent = texto;
            boton.appendChild(span);
        }
        var icono = document.createElement('i');
        icono.className = 'fas ' + claseIcono;
        icono.setAttribute('aria-hidden', 'true');
        boton.appendChild(icono);
    }

    /* ---------------------------------------------------------------------
       1. Intro (se muestra una vez por sesión de navegador)
       --------------------------------------------------------------------- */
    function iniciarIntro() {
        var intro = $('#intro');
        if (!intro) { return; }

        var yaVista = false;
        try { yaVista = window.sessionStorage.getItem('introVista') === '1'; } catch (e) { /* sin storage */ }

        if (yaVista || reducido || document.body.getAttribute('data-sin-intro') === '1') {
            intro.classList.add('is-gone');
            return;
        }

        // Nombre del negocio letra por letra
        var nombre = $('#introName');
        if (nombre) {
            var texto = nombre.getAttribute('data-texto') || '';
            nombre.textContent = '';
            Array.prototype.forEach.call(texto, function (letra, i) {
                var span = document.createElement('span');
                span.textContent = letra === ' ' ? ' ' : letra;
                span.style.animationDelay = (0.85 + i * 0.045) + 's';
                nombre.appendChild(span);
            });
        }

        var cerrada = false;
        function cerrar() {
            if (cerrada) { return; }
            cerrada = true;
            intro.classList.add('is-open');
            try { window.sessionStorage.setItem('introVista', '1'); } catch (e) { /* sin storage */ }
            window.setTimeout(function () { intro.classList.add('is-gone'); }, 1100);
        }

        window.setTimeout(cerrar, 3050);
        var saltar = $('#introSkip');
        if (saltar) { saltar.addEventListener('click', cerrar); }
        intro.addEventListener('click', cerrar);
    }

    /* ---------------------------------------------------------------------
       2. Fondos y textos rotativos (5 fotos + 5 frases sobre cabello y peluquería)
       --------------------------------------------------------------------- */
    function iniciarSlides() {
        var fondos = $all('.bg-slide');
        var textos = $all('.hero-slide');
        var puntos = $all('.hero-dot');
        if (!fondos.length) { return; }

        var actual = 0;
        var timer = null;

        function mostrar(indice) {
            actual = (indice + fondos.length) % fondos.length;
            fondos.forEach(function (el, i) { el.classList.toggle('is-active', i === actual); });
            textos.forEach(function (el, i) { el.classList.toggle('is-active', i === actual); });
            puntos.forEach(function (el, i) {
                el.classList.toggle('is-active', i === actual);
                el.setAttribute('aria-current', i === actual ? 'true' : 'false');
            });
        }
        function programar() {
            window.clearInterval(timer);
            if (reducido) { return; }
            timer = window.setInterval(function () { mostrar(actual + 1); }, INTERVALO_MS);
        }

        puntos.forEach(function (punto, i) {
            punto.addEventListener('click', function () { mostrar(i); programar(); });
        });
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) { window.clearInterval(timer); } else { programar(); }
        });

        mostrar(0);
        programar();
    }

    /* ---------------------------------------------------------------------
       3. Formulario: indica en qué campo estás y valida en el cliente
       --------------------------------------------------------------------- */
    function iniciarFormulario() {
        var form = $('#loginForm');
        if (!form) { return; }

        var pasos = $('#steps');
        var btn = $('#submitBtn');
        var campos = {
            username: {
                field: $('#field-username'), input: $('#username'), hint: $('#hint-username'),
                ayuda: 'Escribe el usuario que te dio el administrador.',
                escribiendo: 'Estás escribiendo tu usuario…', max: 50
            },
            password: {
                field: $('#field-password'), input: $('#password'), hint: $('#hint-password'),
                ayuda: 'Ahora tu contraseña. Distingue mayúsculas y minúsculas.',
                escribiendo: 'Estás escribiendo tu contraseña…', max: 200
            }
        };

        function validar(nombre, mostrarError) {
            var c = campos[nombre];
            var valor = c.input.value;
            var mensaje = '';
            if (nombre === 'username') { valor = valor.trim(); }

            if (valor === '') {
                mensaje = nombre === 'username' ? 'Ingresa tu usuario.' : 'Ingresa tu contraseña.';
            } else if (valor.length > c.max) {
                mensaje = 'Máximo ' + c.max + ' caracteres.';
            }

            var ok = mensaje === '';
            c.field.classList.toggle('is-valid', ok && valor !== '');
            c.field.classList.toggle('is-invalid', !ok && mostrarError);
            c.input.setAttribute('aria-invalid', (!ok && mostrarError) ? 'true' : 'false');
            if (!ok && mostrarError) { c.hint.textContent = mensaje; }
            return ok;
        }

        function actualizarPasos(enfocado) {
            var okUser = campos.username.input.value.trim() !== '';
            var okPass = campos.password.input.value !== '';
            var pasoUser = $('[data-step="username"]');
            var pasoPass = $('[data-step="password"]');
            pasoUser.classList.toggle('is-active', enfocado === 'username' || (!enfocado && !okUser));
            pasoUser.classList.toggle('is-done', okUser && enfocado !== 'username');
            pasoPass.classList.toggle('is-active', enfocado === 'password' || (!enfocado && okUser && !okPass));
            pasoPass.classList.toggle('is-done', okPass && enfocado !== 'password');
            pasos.classList.toggle('is-half', okUser);
            pasos.classList.toggle('is-full', okUser && okPass);
        }

        Object.keys(campos).forEach(function (nombre) {
            var c = campos[nombre];
            c.input.addEventListener('focus', function () {
                c.field.classList.add('is-focus');
                // Si el campo tiene un error, se conserva el mensaje hasta que el usuario escriba
                if (!c.field.classList.contains('is-invalid')) {
                    c.hint.textContent = c.input.value ? c.escribiendo : c.ayuda;
                }
                actualizarPasos(nombre);
            });
            c.input.addEventListener('blur', function () {
                c.field.classList.remove('is-focus');
                var hayError = validar(nombre, c.input.value !== '' || c.touched) === false;
                if (!hayError) { c.hint.textContent = ''; }
                actualizarPasos(null);
            });
            c.input.addEventListener('input', function () {
                c.touched = true;
                c.hint.textContent = c.input.value ? c.escribiendo : c.ayuda;
                c.field.classList.remove('is-invalid');
                c.field.classList.toggle('is-valid', c.input.value.trim() !== '');
                actualizarPasos(nombre);
            });
        });

        // Mostrar / ocultar contraseña
        var toggle = $('#togglePass');
        if (toggle) {
            toggle.addEventListener('click', function () {
                var visible = campos.password.input.type === 'text';
                campos.password.input.type = visible ? 'password' : 'text';
                toggle.setAttribute('aria-pressed', visible ? 'false' : 'true');
                toggle.setAttribute('aria-label', visible ? 'Mostrar contraseña' : 'Ocultar contraseña');
                pintarBoton(toggle, '', visible ? 'fa-eye' : 'fa-eye-slash');
                campos.password.input.focus();
            });
        }

        // Aviso de Bloq Mayús
        var caps = $('#capsWarning');
        function revisarCaps(e) {
            if (!caps || !e.getModifierState) { return; }
            caps.classList.toggle('is-on', e.getModifierState('CapsLock'));
        }
        campos.password.input.addEventListener('keydown', revisarCaps);
        campos.password.input.addEventListener('keyup', revisarCaps);
        campos.password.input.addEventListener('blur', function () { if (caps) { caps.classList.remove('is-on'); } });

        // Ayuda "olvidé mi contraseña" (sin navegar a ninguna parte)
        var olvide = $('#forgotBtn');
        var ayuda = $('#forgotHelp');
        if (olvide && ayuda) {
            olvide.addEventListener('click', function () {
                var abierta = ayuda.classList.toggle('is-open');
                olvide.setAttribute('aria-expanded', abierta ? 'true' : 'false');
            });
        }

        // Envío: valida y evita doble envío
        var enviando = false;
        form.addEventListener('submit', function (e) {
            if (enviando) { e.preventDefault(); return; }
            campos.username.touched = true;
            campos.password.touched = true;
            var okUser = validar('username', true);
            var okPass = validar('password', true);
            if (!okUser || !okPass) {
                e.preventDefault();
                (okUser ? campos.password : campos.username).input.focus();
                return;
            }
            enviando = true;
            btn.disabled = true;
            pintarBoton(btn, 'Verificando…', 'fa-spinner fa-spin');
        });

        // Foco inicial: tras la intro (o enseguida si no hay intro)
        var bloqueado = document.body.getAttribute('data-bloqueado') === '1';
        var intro = $('#intro');
        var hayIntro = intro && !intro.classList.contains('is-gone') && getComputedStyle(intro).display !== 'none';
        window.setTimeout(function () {
            if (!bloqueado) { campos.username.input.focus(); }
        }, hayIntro ? 3300 : 250);

        actualizarPasos(null);
    }

    function init() {
        iniciarIntro();
        iniciarSlides();
        iniciarFormulario();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
