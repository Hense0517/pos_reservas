/* layout.js — comportamiento del header y del sidebar (un solo lugar, sin duplicados) */
(function () {
    'use strict';

    var DESKTOP_MIN = 1024;

    function $(selector, root) { return (root || document).querySelector(selector); }
    function $all(selector, root) { return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }

    function setCookie(name, value) {
        document.cookie = name + '=' + value + '; path=/; max-age=31536000; SameSite=Lax';
    }

    function init() {
        var sidebar = $('#sidebar');
        var overlay = $('#sidebarOverlay');
        var toggle = $('#menuToggle');
        var userButton = $('#userMenuButton');
        var userMenu = $('#userMenu');

        /* ---- Menú lateral (móvil / tablet) ---- */
        function setSidebar(open) {
            if (!sidebar) { return; }
            sidebar.classList.toggle('active', open);
            if (overlay) { overlay.classList.toggle('active', open); }
            document.body.classList.toggle('no-scroll', open && window.innerWidth < DESKTOP_MIN);
            if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        }

        if (toggle) {
            toggle.addEventListener('click', function (e) {
                e.preventDefault();
                setSidebar(!sidebar.classList.contains('active'));
            });
        }
        if (overlay) {
            overlay.addEventListener('click', function () { setSidebar(false); });
        }

        /* ---- Menú de usuario ---- */
        function setUserMenu(open) {
            if (!userMenu || !userButton) { return; }
            userMenu.classList.toggle('active', open);
            userButton.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        if (userButton && userMenu) {
            userButton.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                setUserMenu(!userMenu.classList.contains('active'));
            });
            document.addEventListener('click', function (e) {
                if (!userMenu.contains(e.target) && !userButton.contains(e.target)) { setUserMenu(false); }
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') { return; }
            setUserMenu(false);
            setSidebar(false);
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth >= DESKTOP_MIN) { setSidebar(false); }
            setUserMenu(false);
        });

        /* ---- Grupos desplegables del sidebar (recuerdan su estado en una cookie) ---- */
        $all('[data-nav-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var group = button.closest('.nav-group');
                if (!group) { return; }
                var open = !group.classList.contains('open');
                group.classList.toggle('open', open);
                button.setAttribute('aria-expanded', open ? 'true' : 'false');
                var cookie = button.getAttribute('data-nav-cookie');
                if (cookie) { setCookie(cookie, open ? 'expandido' : 'colapsado'); }
            });
        });

        /* ---- Reloj / fecha del header ---- */
        var clock = $('[data-reloj]');
        if (clock && window.Intl && Intl.DateTimeFormat) {
            var fmt = new Intl.DateTimeFormat('es-CO', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
            var paint = function () { clock.textContent = fmt.format(new Date()); };
            paint();
            window.setInterval(paint, 30000);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
