/**
 * js/documento.js
 * -----------------------------------------------------------------------------
 * Documento de identidad: adapta el campo al tipo elegido, mientras se escribe.
 *
 *   Cédula / RUC   solo dígitos
 *   Pasaporte      letras y dígitos
 *
 * El largo máximo lo fija la base de datos; llega ya calculado en el atributo
 * data-reglas del propio campo (ver api/core/Documento.php).
 *
 * Esto es una comodidad para quien captura, NO una medida de seguridad: el
 * servidor vuelve a comprobarlo todo en Documento::validar(). Si alguien
 * deshabilita el JavaScript, el formulario sigue siendo correcto, solo que el
 * aviso llega al guardar en vez de al escribir.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    /* ------------------------------------------------------------------ */
    /* Cédula y RUC: la misma comprobación que hace el servidor             */
    /* ------------------------------------------------------------------ */
    /* Ver api/core/Documento.php. Se repite aquí, y solo aquí, porque el
       aviso llega mucho antes: en cuanto se completa el número, no al
       guardar el formulario entero. */

    function provinciaValida(numero) {
        var p = parseInt(numero.substring(0, 2), 10);
        return (p >= 1 && p <= 24) || p === 30;
    }

    function modulo10(diez) {
        var suma = 0;
        for (var i = 0; i < 9; i++) {
            var v = parseInt(diez.charAt(i), 10) * (i % 2 === 0 ? 2 : 1);
            if (v > 9) { v -= 9; }
            suma += v;
        }
        return ((10 - suma % 10) % 10) === parseInt(diez.charAt(9), 10);
    }

    function modulo11(numero, coeficientes, posicion) {
        var suma = 0;
        for (var i = 0; i < coeficientes.length; i++) {
            suma += parseInt(numero.charAt(i), 10) * coeficientes[i];
        }
        var residuo = suma % 11;
        return (residuo === 0 ? 0 : 11 - residuo) === parseInt(numero.charAt(posicion), 10);
    }

    function problemaCedula(d) {
        if (!/^\d{10}$/.test(d))  { return 'La cédula tiene exactamente 10 dígitos.'; }
        if (!provinciaValida(d))  { return 'Los dos primeros dígitos son el código de provincia, del 01 al 24 (o 30).'; }
        if (parseInt(d.charAt(2), 10) > 5) {
            return 'El tercer dígito de una cédula de persona natural va de 0 a 5. Si es una empresa, elija RUC.';
        }
        if (!modulo10(d)) {
            return 'El dígito verificador no corresponde. Revise que no haya un número cambiado.';
        }
        return null;
    }

    function problemaRuc(d) {
        if (!/^\d{13}$/.test(d))  { return 'El RUC tiene exactamente 13 dígitos.'; }
        if (!provinciaValida(d))  { return 'Los dos primeros dígitos son el código de provincia, del 01 al 24 (o 30).'; }

        var tercero = parseInt(d.charAt(2), 10);

        if (tercero <= 5) {
            if (!modulo10(d.substring(0, 10))) {
                return 'El dígito verificador de la cédula que lleva dentro no corresponde.';
            }
            if (parseInt(d.substring(10, 13), 10) < 1) {
                return 'Los tres últimos dígitos son el establecimiento y empiezan en 001.';
            }
            return null;
        }
        if (tercero === 6) {
            if (!modulo11(d, [3, 2, 7, 6, 5, 4, 3, 2], 8)) {
                return 'El dígito verificador no corresponde a un RUC del sector público.';
            }
            if (parseInt(d.substring(9, 13), 10) < 1) {
                return 'Los cuatro últimos dígitos son el establecimiento y empiezan en 0001.';
            }
            return null;
        }
        if (tercero === 9) {
            if (!modulo11(d, [4, 3, 2, 7, 6, 5, 4, 3, 2], 9)) {
                return 'El dígito verificador no corresponde a un RUC de sociedad.';
            }
            if (parseInt(d.substring(10, 13), 10) < 1) {
                return 'Los tres últimos dígitos son el establecimiento y empiezan en 001.';
            }
            return null;
        }
        return 'El tercer dígito solo puede ser de 0 a 5 (persona natural), 6 (sector público) o 9 (sociedad).';
    }

    /** @return {string|null} motivo del rechazo, o null si el número es correcto */
    function problemaDocumento(tipo, valor) {
        if (valor === '') { return null; }        // vacío lo decide el «required»
        if (tipo === 'CEDULA') { return problemaCedula(valor); }
        if (tipo === 'RUC')    { return problemaRuc(valor); }
        return null;                              // el pasaporte no tiene forma que comprobar
    }

    function iniciar(campo) {
        if (campo.dataset.documentoListo === '1') { return; }
        campo.dataset.documentoListo = '1';

        var reglas;
        try {
            reglas = JSON.parse(campo.getAttribute('data-reglas') || '{}');
        } catch (e) {
            return;                       // sin reglas no se hace nada
        }

        var selector = document.getElementById(campo.getAttribute('data-tipo-campo'));
        var ayuda    = document.getElementById(campo.getAttribute('data-ayuda-campo'));

        var aviso = document.createElement('div');
        aviso.className = 'form-error-campo';
        aviso.hidden = true;
        if (campo.parentNode) {
            campo.parentNode.insertBefore(aviso, campo.nextSibling);
        }

        /* En los formularios internos el tipo lo elige un desplegable; en los
           enlaces públicos viene dado por el enlace, y llega en data-tipo-fijo. */
        function tipoActual() {
            if (selector) { return selector.value; }
            return campo.getAttribute('data-tipo-fijo') || 'CEDULA';
        }

        function reglaActual() {
            return reglas[tipoActual()] || reglas.CEDULA;
        }

        /**
         * Comprueba el número y enseña el motivo si no cuadra.
         *
         * @param mostrar false mientras se escribe: solo se levanta un aviso ya
         *                puesto, nunca se pone uno nuevo. Avisar en el cuarto
         *                dígito de una cédula a medio escribir es ruido.
         */
        function revisar(mostrar) {
            var motivo = problemaDocumento(tipoActual(), campo.value.trim());

            campo.setCustomValidity(motivo || '');
            campo.classList.toggle('campo-invalido', mostrar && motivo !== null);

            aviso.textContent = motivo || '';
            aviso.hidden = !(mostrar && motivo !== null);
        }

        /** Quita lo que el tipo no admite y recorta al largo de la columna. */
        function depurar(valor) {
            var regla = reglaActual();
            var limpio = String(valor).replace(new RegExp(regla.patron, 'g'), '');

            if (regla.patron.indexOf('A-Za-z') !== -1) {
                limpio = limpio.toUpperCase();
            }
            return limpio.slice(0, regla.maximo);
        }

        function aplicarTipo() {
            var regla = reglaActual();

            campo.setAttribute('maxlength', regla.maximo);
            campo.setAttribute('inputmode', regla.patron === '[^0-9]' ? 'numeric' : 'text');

            if (ayuda) { ayuda.textContent = regla.ayuda; }

            // Al cambiar de tipo se depura lo ya escrito: pasar de pasaporte a
            // cédula con letras dentro dejaría el campo en un estado imposible.
            var depurado = depurar(campo.value);
            if (depurado !== campo.value) { campo.value = depurado; }

            revisar(false);
        }

        campo.addEventListener('input', function () {
            var inicio    = campo.selectionStart;
            var largoPrev = campo.value.length;
            var depurado  = depurar(campo.value);

            if (depurado !== campo.value) {
                campo.value = depurado;
                // Se devuelve el cursor a donde estaba, descontando lo quitado
                var salto = largoPrev - depurado.length;
                try { campo.setSelectionRange(inicio - salto, inicio - salto); } catch (e) { /* campo sin selección */ }
            }

            /* En cuanto el número está completo se comprueba de verdad: es el
               momento en que el aviso vale para algo y no interrumpe. */
            var regla = reglaActual();
            revisar(regla.exacto > 0 && campo.value.length >= regla.exacto);
        });

        campo.addEventListener('blur', function () { revisar(true); });

        // Pegar: el navegador dispara 'input' después, así que basta con lo de
        // arriba; se deja este por los navegadores que no lo hacen.
        campo.addEventListener('paste', function () {
            window.setTimeout(function () { campo.value = depurar(campo.value); }, 0);
        });

        if (selector) {
            selector.addEventListener('change', aplicarTipo);
        }
        aplicarTipo();
    }

    function arrancar() {
        var campos = document.querySelectorAll('input[data-reglas]');
        Array.prototype.forEach.call(campos, iniciar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrancar);
    } else {
        arrancar();
    }
})();
