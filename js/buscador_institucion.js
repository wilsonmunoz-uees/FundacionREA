/**
 * js/buscador_institucion.js
 * ---------------------------------------------------------------------------
 * Filtro por texto para el desplegable de instituciones del ingreso.
 *
 * La red tiene 21 instituciones y varias comparten las primeras palabras
 * («Unidad Educativa …»), de modo que buscar a ojo en la lista desplegada es
 * incómodo. Este archivo añade un campo de búsqueda que deja en el desplegable
 * solo lo que coincide.
 *
 * Es una MEJORA PROGRESIVA: el formulario funciona sin JavaScript. El campo de
 * búsqueda viene oculto en el HTML y solo se muestra si este archivo se ejecuta;
 * el desplegable, que es quien envía el dato, no se toca.
 *
 * Se filtra reconstruyendo las opciones del <select>, no ocultándolas: un
 * <option> con display:none lo respetan unos navegadores y otros no.
 * ---------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var lista  = document.getElementById('institucion_id');
    var buscar = document.getElementById('buscar_institucion');
    var aviso  = document.getElementById('institucion_conteo');

    if (!lista || !buscar) {
        return;
    }

    /* Copia de todas las opciones reales, en su orden original. La primera del
       desplegable es el «-- Seleccione --», que se conserva siempre aparte. */
    var opciones = [];
    var vacia    = null;

    Array.prototype.forEach.call(lista.options, function (opcion) {
        if (opcion.value === '') {
            vacia = { texto: opcion.text, valor: '' };
            return;
        }
        opciones.push({
            texto: opcion.text.trim(),
            valor: opcion.value,
            clave: normalizar(opcion.text)
        });
    });

    if (opciones.length === 0) {
        return;
    }

    /* Institución que venía marcada al abrir la página —la del ingreso anterior,
       recordada en la cookie—. Se conserva al filtrar. */
    var elegidaInicial = lista.value;

    /** Minúsculas y sin tildes, para que «espiritu» encuentre «Espíritu». */
    function normalizar(texto) {
        return String(texto)
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/\s+/g, ' ')
            .trim();
    }

    /** ¿La opción contiene TODAS las palabras escritas? Así «uni san» acierta. */
    function coincide(opcion, palabras) {
        return palabras.every(function (palabra) {
            return opcion.clave.indexOf(palabra) !== -1;
        });
    }

    function pintar() {
        var texto    = normalizar(buscar.value);
        var palabras = texto === '' ? [] : texto.split(' ');
        var elegida  = lista.value || elegidaInicial;

        var visibles = palabras.length === 0
            ? opciones
            : opciones.filter(function (opcion) { return coincide(opcion, palabras); });

        lista.innerHTML = '';

        if (vacia) {
            lista.appendChild(new Option(
                visibles.length === 0 ? 'Ninguna institución coincide' : vacia.texto,
                ''
            ));
        }

        visibles.forEach(function (opcion) {
            lista.appendChild(new Option(opcion.texto, opcion.valor));
        });

        /* Se conserva lo que ya estaba elegido si sigue en la lista; si el filtro
           deja una sola, se elige sola: es el caso normal después de escribir. */
        if (elegida !== '' && visibles.some(function (o) { return o.valor === elegida; })) {
            lista.value = elegida;
        } else if (visibles.length === 1) {
            lista.value = visibles[0].valor;
        }

        if (aviso) {
            aviso.textContent = visibles.length === opciones.length
                ? opciones.length + ' instituciones disponibles'
                : visibles.length + ' de ' + opciones.length + ' coinciden';
        }
    }

    // Enter en el buscador no debe enviar el formulario a medio llenar
    buscar.addEventListener('keydown', function (evento) {
        if (evento.key === 'Enter') {
            evento.preventDefault();
            pintar();
            lista.focus();
        }
    });

    buscar.addEventListener('input', pintar);
    buscar.hidden = false;

    var envoltorio = buscar.closest('.form-group');
    if (envoltorio) {
        envoltorio.hidden = false;
    }

    pintar();
})();
