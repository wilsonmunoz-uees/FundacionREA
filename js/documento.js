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
    /* La misma comprobación que hace el servidor                          */
    /* ------------------------------------------------------------------ */
    /* Ver api/core/Documento.php. Se comprueba LA FORMA —qué caracteres se
       admiten y qué largo debe tener—, no la validez del número: el dígito
       verificador se retiró a petición de la Fundación, porque el padrón trae
       documentos que no lo superan y que sí deben poder cargarse.

         Cédula y RUC   solo dígitos, largo exacto (10 y 13)
         Pasaporte      letras y dígitos, entre 6 y 12 caracteres

       Los largos no se escriben aquí: llegan en data-reglas, calculados por el
       servidor, de modo que este archivo no pueda quedarse desfasado.

       Se repite aquí, y solo aquí, porque el aviso llega mucho antes: en cuanto
       se completa el número, no al guardar el formulario entero. */

    /**
     * @param {{exacto:number, minimo:number, maximo:number, patron:string}} regla
     * @return {string|null} motivo del rechazo, o null si el número es correcto
     */
    function problemaDocumento(regla, valor) {
        if (valor === '') { return null; }        // vacío lo decide el «required»

        var letras = regla.patron.indexOf('A-Za-z') !== -1;

        if (!letras && /[^0-9]/.test(valor)) {
            return 'Solo se admiten dígitos numéricos.';
        }
        if (letras && /[^0-9A-Za-z]/.test(valor)) {
            return 'Solo se admiten letras y números.';
        }

        if (regla.exacto > 0) {
            if (valor.length !== regla.exacto) {
                return 'Debe tener exactamente ' + regla.exacto + ' dígitos.';
            }
            return null;
        }

        if (regla.maximo > 0 && valor.length > regla.maximo) {
            return 'No puede superar los ' + regla.maximo + ' caracteres.';
        }
        if (regla.minimo > 0 && valor.length < regla.minimo) {
            return 'Debe tener al menos ' + regla.minimo + ' caracteres.';
        }
        return null;
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
            var regla = reglas[tipoActual()] || reglas.CEDULA || {};

            /* Una instalación a medio actualizar puede devolver reglas sin los
               largos nuevos; con ceros, la comprobación simplemente no opina. */
            return {
                patron: regla.patron || '[^0-9]',
                maximo: regla.maximo || 50,
                minimo: regla.minimo || 0,
                exacto: regla.exacto || 0,
                ayuda:  regla.ayuda || ''
            };
        }

        /**
         * Comprueba el número y enseña el motivo si no cuadra.
         *
         * @param mostrar false mientras se escribe: solo se levanta un aviso ya
         *                puesto, nunca se pone uno nuevo. Avisar en el cuarto
         *                dígito de una cédula a medio escribir es ruido.
         */
        function revisar(mostrar) {
            var motivo = problemaDocumento(reglaActual(), campo.value.trim());

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

            /* El mínimo también cambia con el tipo: 10 en la cédula, 6 en el
               pasaporte. Si no se actualizara, al pasar de cédula a pasaporte el
               navegador seguiría exigiendo diez caracteres por su cuenta. */
            if (regla.minimo > 0) {
                campo.setAttribute('minlength', regla.minimo);
            } else {
                campo.removeAttribute('minlength');
            }

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

            /* En cuanto el número alcanza el largo que se le pide se comprueba
               de verdad: es el momento en que el aviso vale para algo y no
               interrumpe. Antes de eso, un pasaporte de tres letras todavía se
               está escribiendo. */
            var regla  = reglaActual();
            var umbral = regla.exacto > 0 ? regla.exacto : regla.minimo;
            revisar(umbral > 0 && campo.value.length >= umbral);
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
