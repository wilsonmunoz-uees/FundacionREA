/**
 * js/menu_movil.js
 * ---------------------------------------------------------------------------
 * El menú lateral como cajón, en tableta y teléfono.
 *
 * Por debajo de 900 px la barra lateral se sale de la pantalla y solo vuelve
 * cuando se pulsa el botón ☰. Abrirla era fácil; cerrarla, no: tapaba la
 * pantalla entera y el único modo de salir era acertar con el botón que quedaba
 * detrás. Este archivo añade lo que falta para que el cajón se comporte como
 * espera cualquiera que use un teléfono:
 *
 *   · un fondo oscurecido que se toca para cerrar;
 *   · la tecla Esc;
 *   · se cierra solo al elegir una opción, que es lo que se venía a hacer;
 *   · el fondo no se desplaza mientras está abierto;
 *   · al volver a una pantalla ancha se recoge, para no dejarlo abierto sobre
 *     un escritorio donde ya no hace falta.
 *
 * El foco se lleva al cajón al abrirlo y vuelve al botón al cerrarlo, para que
 * quien navega con teclado o lector de pantalla no se quede detrás.
 * ---------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var ANCHO_CAJON = 900;          // el mismo corte que usa la hoja de estilos

    var barra  = document.getElementById('sidebarApp');
    var boton  = document.querySelector('.menu-toggle');

    if (!barra || !boton) { return; }

    /* El fondo se crea aquí y no en el HTML: sin JavaScript no habría nada que
       lo cerrara, y un velo que no se puede quitar es peor que ninguno. */
    var fondo = document.createElement('button');
    fondo.type = 'button';
    fondo.className = 'menu-fondo';
    fondo.hidden = true;
    fondo.setAttribute('aria-label', 'Cerrar el menú');
    document.body.appendChild(fondo);

    boton.setAttribute('aria-controls', 'sidebarApp');
    boton.setAttribute('aria-expanded', 'false');
    boton.setAttribute('aria-label', 'Abrir el menú');

    function enModoCajon() {
        return window.matchMedia('(max-width: ' + ANCHO_CAJON + 'px)').matches;
    }

    function abrir() {
        barra.classList.add('abierto');
        fondo.hidden = false;
        document.body.classList.add('menu-abierto');
        boton.setAttribute('aria-expanded', 'true');
        barra.setAttribute('tabindex', '-1');
        barra.focus({ preventScroll: true });
    }

    function cerrar(devolverFoco) {
        barra.classList.remove('abierto');
        fondo.hidden = true;
        document.body.classList.remove('menu-abierto');
        boton.setAttribute('aria-expanded', 'false');
        barra.removeAttribute('tabindex');
        if (devolverFoco) { boton.focus(); }
    }

    function alternar() {
        if (barra.classList.contains('abierto')) { cerrar(true); } else { abrir(); }
    }

    /* El botón traía el alternar en un atributo onclick del HTML. Se sustituye
       por este manejador, que además se ocupa del fondo y del foco. */
    boton.removeAttribute('onclick');
    boton.addEventListener('click', alternar);

    fondo.addEventListener('click', function () { cerrar(true); });

    document.addEventListener('keydown', function (ev) {
        if ((ev.key === 'Escape' || ev.key === 'Esc') && barra.classList.contains('abierto')) {
            cerrar(true);
        }
    });

    /* Elegir una opción es irse a otra pantalla: el cajón se recoge para que la
       transición no se vea con el menú encima. */
    barra.addEventListener('click', function (ev) {
        var enlace = ev.target.closest ? ev.target.closest('a[href]') : null;
        if (enlace && enModoCajon()) { cerrar(false); }
    });

    /* Al girar el teléfono o ensanchar la ventana, la barra vuelve a su sitio
       por CSS; hay que quitar el velo y devolver el desplazamiento. */
    window.addEventListener('resize', function () {
        if (!enModoCajon() && barra.classList.contains('abierto')) { cerrar(false); }
    });
})();
