/**
 * js/telefono.js
 * ---------------------------------------------------------------------------
 * Limita los campos de teléfono a lo que el servidor acepta: dígitos, con un
 * «+» opcional al principio y en ningún otro sitio.
 *
 * Se aplica a todo campo marcado con data-telefono, de modo que cualquier
 * pantalla que capture un teléfono lo hereda sin repetir código.
 *
 * Es una comodidad para quien captura, no una defensa: quien decide es
 * api/core/Telefono.php al guardar. Aquí solo se evita el viaje al servidor
 * para descubrir que se coló una letra.
 * ---------------------------------------------------------------------------
 */
(function () {
    'use strict';

    /** Deja el valor en la forma que el servidor guardaría. */
    function limpiar(valor) {
        var internacional = valor.trim().charAt(0) === '+';
        var digitos = valor.replace(/[^0-9]/g, '');

        return (internacional ? '+' : '') + digitos;
    }

    function vigilar(campo) {
        if (campo.dataset.telefonoListo === '1') {
            return;
        }
        campo.dataset.telefonoListo = '1';

        campo.addEventListener('input', function () {
            var antes = campo.value;
            var punto = campo.selectionStart;
            var limpio = limpiar(antes);

            if (limpio === antes) {
                return;
            }

            campo.value = limpio;

            /* Se recoloca el cursor descontando lo que se quitó a su izquierda,
               para que escribir en medio del número no lo mande al final. */
            var quitados = antes.slice(0, punto).length
                         - limpiar(antes.slice(0, punto)).length;
            try {
                campo.setSelectionRange(punto - quitados, punto - quitados);
            } catch (e) {
                /* Algunos navegadores no permiten mover el cursor en type=tel;
                   no es grave, el valor ya quedó limpio. */
            }
        });

        // Al salir del campo se recorta al máximo que admite la columna
        campo.addEventListener('blur', function () {
            var maximo = parseInt(campo.getAttribute('maxlength'), 10) || 16;
            campo.value = limpiar(campo.value).slice(0, maximo);
        });
    }

    function iniciar() {
        var campos = document.querySelectorAll('[data-telefono]');
        Array.prototype.forEach.call(campos, vigilar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
