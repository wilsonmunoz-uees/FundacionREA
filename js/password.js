/**
 * js/password.js
 * -----------------------------------------------------------------------------
 * Ayuda para el campo de contraseña del formulario de usuarios:
 *
 *   · lista de comprobación que se va marcando mientras se escribe
 *   · botón «Generar» que propone una que cumple todas las condiciones
 *   · botón para ver u ocultar lo escrito, y aviso si las dos no coinciden
 *
 * Las reglas llegan del servidor en data-reglas (api/core/Password.php), de modo
 * que si allí cambia la política, esto la sigue sin tocarse.
 *
 * Nada de esto decide: el servidor vuelve a validar la contraseña al guardarla.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var MAYUSCULAS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';   // sin I ni O
    var MINUSCULAS = 'abcdefghijkmnpqrstuvwxyz';   // sin l ni o
    var DIGITOS    = '23456789';                   // sin 0 ni 1
    var ESPECIALES = '*!-_';

    /** Entero aleatorio con la fuente criptográfica del navegador. */
    function alAzar(tope) {
        if (window.crypto && window.crypto.getRandomValues) {
            var buffer = new Uint32Array(1);
            var limite = Math.floor(4294967296 / tope) * tope;
            do { window.crypto.getRandomValues(buffer); } while (buffer[0] >= limite);
            return buffer[0] % tope;
        }
        return Math.floor(Math.random() * tope);
    }

    function unoDe(juego) {
        return juego.charAt(alAzar(juego.length));
    }

    function generar(largo) {
        var clave = [unoDe(MAYUSCULAS), unoDe(MINUSCULAS), unoDe(DIGITOS), unoDe(ESPECIALES)];
        var todos = MAYUSCULAS + MINUSCULAS + DIGITOS + ESPECIALES;

        while (clave.length < largo) { clave.push(unoDe(todos)); }

        for (var i = clave.length - 1; i > 0; i--) {
            var j = alAzar(i + 1);
            var t = clave[i]; clave[i] = clave[j]; clave[j] = t;
        }
        return clave.join('');
    }

    function iniciar(campo) {
        if (campo.dataset.passwordListo === '1') { return; }
        campo.dataset.passwordListo = '1';

        var reglas;
        try {
            reglas = JSON.parse(campo.getAttribute('data-reglas') || '[]');
        } catch (e) {
            return;
        }

        var confirmar = document.getElementById(campo.getAttribute('data-confirmar') || '');
        var lista     = document.getElementById(campo.getAttribute('data-lista') || '');
        var avisoConf = document.getElementById(campo.getAttribute('data-aviso-confirmar') || '');

        /* --- Lista de comprobación --- */
        var puntos = {};
        if (lista) {
            lista.innerHTML = '';
            reglas.forEach(function (regla) {
                var li = document.createElement('li');
                li.className = 'regla-clave';
                li.innerHTML = '<span class="regla-marca" aria-hidden="true">○</span> '
                             + '<span class="regla-texto"></span>';
                li.querySelector('.regla-texto').textContent = regla.texto;
                lista.appendChild(li);
                puntos[regla.clave] = { li: li, patron: new RegExp(regla.patron) };
            });
        }

        function revisar() {
            var valor = campo.value;

            reglas.forEach(function (regla) {
                var punto = puntos[regla.clave];
                if (!punto) { return; }

                // Con el campo vacío no se marca nada en rojo: todavía no falló
                var cumple = valor !== '' && punto.patron.test(valor);
                punto.li.className = 'regla-clave' + (valor === '' ? '' : (cumple ? ' cumple' : ' falta'));
                punto.li.querySelector('.regla-marca').textContent =
                    valor === '' ? '○' : (cumple ? '✓' : '✗');
            });

            if (avisoConf && confirmar) {
                var distintas = confirmar.value !== '' && confirmar.value !== campo.value;
                avisoConf.textContent = distintas ? 'Las dos contraseñas no coinciden.' : '';
                avisoConf.hidden = !distintas;

                /* Clase propia, distinta de la que pone el servidor: si se
                   reutilizara `campo-error`, al recargar el formulario con los
                   campos vacíos este aviso borraría la marca que vino de la
                   API y el usuario dejaría de ver qué corregir. */
                confirmar.classList.toggle('campo-error-vivo', distintas);
            }
        }

        campo.addEventListener('input', revisar);
        if (confirmar) { confirmar.addEventListener('input', revisar); }

        /* --- Ver u ocultar --- */
        // Se declara antes que el generador, que también cambia su etiqueta.
        var botonVer = document.getElementById(campo.getAttribute('data-ver') || '');
        if (botonVer) {
            botonVer.addEventListener('click', function () {
                var oculto = campo.type === 'password';
                campo.type = oculto ? 'text' : 'password';
                if (confirmar) { confirmar.type = campo.type; }
                botonVer.textContent = oculto ? 'Ocultar' : 'Ver';
            });
        }

        /* --- Botón generar --- */
        var botonGenerar = document.getElementById(campo.getAttribute('data-generar') || '');
        var salidaClave  = document.getElementById(campo.getAttribute('data-generada') || '');

        if (botonGenerar) {
            botonGenerar.addEventListener('click', function () {
                var clave = generar(12);

                campo.value = clave;
                if (confirmar) { confirmar.value = clave; }

                // Se muestra en claro: si nadie la ve, nadie puede anotarla
                campo.type = 'text';
                if (confirmar) { confirmar.type = 'text'; }
                if (botonVer) { botonVer.textContent = 'Ocultar'; }

                if (salidaClave) {
                    salidaClave.hidden = false;
                    salidaClave.querySelector('.clave-generada-valor').textContent = clave;
                }
                revisar();
                campo.focus();
            });
        }

        /* --- Copiar la generada --- */
        var botonCopiar = salidaClave ? salidaClave.querySelector('.btn-copiar-clave') : null;
        if (botonCopiar) {
            botonCopiar.addEventListener('click', function () {
                var texto = salidaClave.querySelector('.clave-generada-valor').textContent;
                var previo = botonCopiar.textContent;

                function avisar(ok) {
                    botonCopiar.textContent = ok ? '✓ Copiada' : 'No se pudo copiar';
                    window.setTimeout(function () { botonCopiar.textContent = previo; }, 1800);
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(texto).then(function () { avisar(true); },
                                                              function () { avisar(false); });
                } else {
                    avisar(false);
                }
            });
        }

        revisar();
    }

    function arrancar() {
        var campos = document.querySelectorAll('input[data-reglas][data-lista]');
        Array.prototype.forEach.call(campos, iniciar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrancar);
    } else {
        arrancar();
    }
})();
