/**
 * js/instituciones_usuario.js
 * -----------------------------------------------------------------------------
 * Instituciones de una cuenta de usuario: comodidades para una lista larga.
 *
 * La red tiene 21 instituciones y cada una trae sus propios roles. Dibujarlas
 * todas abiertas convierte el formulario en un rollo de varias pantallas, y
 * dibujarlas todas cerradas obliga a desplegar a mano la que se acaba de marcar.
 * Este archivo resuelve las dos cosas:
 *
 *   · al marcar una institución, su bloque se abre solo, que es justo cuando
 *     hacen falta sus roles;
 *   · el contador de roles del encabezado se mantiene al día mientras se marca;
 *   · un campo de filtro deja llegar a una institución concreta sin recorrer la
 *     lista entera.
 *
 * Es una comodidad, NO una regla: sin JavaScript el formulario sigue completo
 * —los bloques se despliegan pulsando su título, como cualquier <details>— y
 * quien decide qué se guarda es la API, en UsuariosController.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    function bloques() {
        return Array.prototype.slice.call(document.querySelectorAll('.institucion-bloque'));
    }

    /** «3 roles» / «1 rol» / nada cuando no hay ninguno. */
    function actualizarConteo(bloque) {
        var marca = bloque.querySelector('.institucion-conteo');
        if (!marca) { return; }

        var cuantos = bloque.querySelectorAll('input[name^="roles_inst"]:checked').length;

        marca.textContent = cuantos === 0 ? '' : (cuantos + (cuantos === 1 ? ' rol' : ' roles'));
        marca.hidden = cuantos === 0;
    }

    function preparar(bloque) {
        var acceso = bloque.querySelector('input[name="instituciones[]"]:not([type=hidden])');

        if (acceso) {
            acceso.addEventListener('change', function () {
                // Marcarla es querer decidir sus roles: se abre sola.
                if (acceso.checked) { bloque.open = true; }
            });
        }

        Array.prototype.forEach.call(
            bloque.querySelectorAll('input[name^="roles_inst"]'),
            function (casilla) {
                casilla.addEventListener('change', function () {
                    actualizarConteo(bloque);

                    /* Dar un rol en una institución a la que la cuenta no entra
                       no serviría de nada, así que se marca el acceso solo. La
                       API lo daría por bueno igualmente, pero quien está en la
                       pantalla no tendría por qué adivinarlo. */
                    if (casilla.checked && acceso && !acceso.checked) {
                        acceso.checked = true;
                    }
                });
            }
        );

        actualizarConteo(bloque);
    }

    /** Campo para filtrar la lista por nombre. */
    function prepararFiltro(lista, todos) {
        var filtro = document.getElementById('filtro_instituciones');
        if (!filtro) { return; }

        filtro.hidden = false;

        filtro.addEventListener('input', function () {
            var buscado = filtro.value.trim().toLowerCase();

            todos.forEach(function (bloque) {
                var nombre = (bloque.getAttribute('data-nombre') || '').toLowerCase();
                bloque.hidden = buscado !== '' && nombre.indexOf(buscado) === -1;
            });

            /* Sin coincidencias se dice, en vez de dejar un hueco en blanco que
               parece un error de la pantalla. */
            var visibles = todos.filter(function (b) { return !b.hidden; }).length;
            var aviso    = document.getElementById('instituciones_sin_resultado');
            if (aviso) { aviso.hidden = visibles > 0; }
        });
    }

    function arrancar() {
        var lista = document.querySelector('.instituciones-lista');
        if (!lista) { return; }

        var todos = bloques();
        todos.forEach(preparar);
        prepararFiltro(lista, todos);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrancar);
    } else {
        arrancar();
    }
})();
