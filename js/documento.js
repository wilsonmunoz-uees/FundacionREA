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

        function reglaActual() {
            var tipo = selector ? selector.value : 'CEDULA';
            return reglas[tipo] || reglas.CEDULA;
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
        });

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
        var campos = document.querySelectorAll('input[data-reglas][data-tipo-campo]');
        Array.prototype.forEach.call(campos, iniciar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrancar);
    } else {
        arrancar();
    }
})();
