/**
 * js/correo.js
 * -----------------------------------------------------------------------------
 * Dirección de correo: avisa de que está mal escrita en cuanto se sale del
 * campo, no al guardar.
 *
 * Aplica la MISMA regla que el servidor —ver api/core/CorreoElectronico.php—,
 * porque una comprobación del navegador más laxa que la del servidor no ayuda a
 * nadie: deja pasar y el error llega igual, solo que más tarde y sin decir qué.
 *
 * Esto es una comodidad para quien captura, NO una medida de seguridad. Si
 * alguien deshabilita el JavaScript, el formulario sigue siendo correcto: el
 * aviso llega al guardar en vez de al escribir.
 *
 * Se engancha solo a todo input[type=email] de la página. No hace falta marcar
 * nada en el HTML.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var LARGO_MAXIMO = 150;
    var LARGO_LOCAL  = 64;

    /** Motivo por el que la dirección no sirve, o null si sirve. */
    function problema(correo) {
        if (correo === '') { return null; }        // vacío lo decide el «required»

        if (correo.length > LARGO_MAXIMO) {
            return 'No puede superar los ' + LARGO_MAXIMO + ' caracteres.';
        }
        if (/\s/.test(correo)) {
            return 'No puede llevar espacios.';
        }

        var trozos = correo.split('@');
        if (trozos.length !== 2) {
            return 'Debe llevar una sola arroba, con la forma nombre@dominio.ext.';
        }

        var local   = trozos[0];
        var dominio = trozos[1];

        if (local === '') { return 'Falta el nombre antes de la arroba.'; }
        if (local.length > LARGO_LOCAL) {
            return 'La parte anterior a la arroba no puede superar los ' + LARGO_LOCAL + ' caracteres.';
        }
        if (local.charAt(0) === '.' || local.charAt(local.length - 1) === '.'
            || local.indexOf('..') !== -1) {
            return 'La parte anterior a la arroba no puede empezar ni terminar en punto, '
                 + 'ni llevar dos puntos seguidos.';
        }

        if (dominio === '') { return 'Falta el dominio después de la arroba.'; }
        if (dominio.indexOf('.') === -1) {
            return 'Al dominio «' + dominio + '» le falta la extensión: escriba, por ejemplo, '
                 + dominio + '.com.';
        }

        var etiquetas = dominio.split('.');
        for (var i = 0; i < etiquetas.length; i++) {
            if (etiquetas[i] === '') {
                return 'El dominio no puede llevar dos puntos seguidos ni terminar en punto.';
            }
            if (!/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i.test(etiquetas[i])) {
                return 'El dominio solo admite letras, números y guiones, y los guiones no pueden '
                     + 'ir al principio ni al final de cada parte.';
            }
        }

        var extension = etiquetas[etiquetas.length - 1];
        if (!/^[a-z]{2,24}$/i.test(extension)) {
            return 'La extensión «.' + extension + '» no es válida: son de 2 a 24 letras, '
                 + 'como .com, .ec o .edu.ec.';
        }

        return null;
    }

    function iniciar(campo) {
        if (campo.dataset.correoListo === '1') { return; }
        campo.dataset.correoListo = '1';

        var aviso = document.createElement('div');
        aviso.className = 'form-error-campo';
        aviso.hidden = true;
        if (campo.parentNode) {
            campo.parentNode.insertBefore(aviso, campo.nextSibling);
        }

        function revisar(mostrar) {
            /* El servidor guarda la dirección en minúsculas; el campo se deja
               como lo escribió la persona hasta que sale de él, para no cambiarle
               las letras bajo el cursor. */
            var valor = campo.value.trim();
            var motivo = problema(valor.toLowerCase());

            campo.setCustomValidity(motivo || '');
            campo.classList.toggle('campo-invalido', mostrar && motivo !== null);

            aviso.textContent = motivo || '';
            aviso.hidden = !(mostrar && motivo !== null);
        }

        campo.addEventListener('blur', function () {
            var limpio = campo.value.trim().toLowerCase();
            if (limpio !== campo.value) { campo.value = limpio; }
            revisar(true);
        });

        // Mientras escribe solo se levanta el aviso ya mostrado, no se pone uno
        // nuevo: avisar en la tercera letra de una dirección a medio escribir es
        // ruido, no ayuda.
        campo.addEventListener('input', function () { revisar(false); });

        revisar(false);
    }

    function arrancar() {
        var campos = document.querySelectorAll('input[type=email]');
        Array.prototype.forEach.call(campos, iniciar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrancar);
    } else {
        arrancar();
    }
})();
