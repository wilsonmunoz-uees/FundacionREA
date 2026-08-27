<?php
/**
 * includes/selector_entidad.php
 * -----------------------------------------------------------------------------
 * Campo de selección con subpantalla (modal) de búsqueda.
 *
 * Sustituye a un <select> con cientos de opciones: muestra una etiqueta con el
 * registro elegido y un botón que abre una ventana de búsqueda contra la API.
 * Al seleccionar, la ventana se cierra y devuelve el código y el nombre.
 *
 * El componente atiende dos orígenes de datos (ver selectorFuentes()):
 *
 *   personas -> directorio de personas   (ajax/buscar_personas.php)
 *   usuarios -> usuarios del sistema     (ajax/buscar_usuarios.php)
 *
 * Uso habitual — el atajo por entidad:
 *
 *     require_once __DIR__ . '/../includes/selector_persona.php';
 *     selectorPersona(['nombre' => 'persona_id', 'etiqueta' => 'Persona', ...]);
 *     selectorUsuario(['nombre' => 'username',   'etiqueta' => 'Usuario', ...]);
 *
 * Y una sola vez antes de cerrar la página (lo hace layout_bottom.php):
 *
 *     selectorEntidadModal();
 *
 * Nota: las clases CSS conservan el prefijo `selector-persona` / `sp-` porque
 * ya están definidas en css/style.css y son comunes a ambos orígenes.
 * -----------------------------------------------------------------------------
 */

/**
 * Orígenes de datos disponibles para la subpantalla de búsqueda.
 *
 * Cada origen declara:
 *   titulo   -> encabezado de la ventana
 *   url      -> endpoint AJAX que devuelve {ok, datos, meta}
 *   id       -> campo cuyo valor se guarda en el formulario
 *   nombre   -> campos que forman el texto principal de la etiqueta
 *   detalle  -> campos que forman el texto secundario
 *   vacio    -> mensaje cuando la búsqueda no arroja resultados
 *   columnas -> columnas de la tabla de resultados
 */
function selectorFuentes(): array
{
    $raiz = defined('APP_ROOT') ? APP_ROOT : '';

    return [
        'personas' => [
            'titulo'      => 'Buscar persona',
            'url'         => $raiz . 'ajax/buscar_personas.php',
            'id'          => 'PersonaId',
            'nombre'      => ['Apellidos', 'Nombres'],
            'detalle'     => ['TipoIdentificacion', 'Identificacion'],
            'vacio'       => 'No se encontraron personas con ese criterio.',
            'marcador'    => 'Nombres, apellidos, identificación o correo…',
            'columnas'    => [
                ['titulo' => 'Identificación',  'campos' => ['TipoIdentificacion', 'Identificacion']],
                ['titulo' => 'Nombre completo', 'campos' => ['Apellidos', 'Nombres'], 'fuerte' => true],
                ['titulo' => 'Correo',          'campos' => ['Email']],
            ],
        ],
        'usuarios' => [
            'titulo'      => 'Buscar usuario',
            'url'         => $raiz . 'ajax/buscar_usuarios.php',
            'id'          => 'Username',
            'nombre'      => ['Username'],
            'detalle'     => ['Apellidos', 'Nombres'],
            'vacio'       => 'No se encontraron usuarios con ese criterio.',
            'marcador'    => 'Nombre de usuario, nombres o apellidos…',
            'columnas'    => [
                ['titulo' => 'Usuario',         'campos' => ['Username'], 'fuerte' => true],
                ['titulo' => 'Nombre completo', 'campos' => ['Apellidos', 'Nombres']],
                ['titulo' => 'Estado',          'campos' => ['Estado']],
            ],
        ],
    ];
}

/**
 * Dibuja el campo (etiqueta + valor seleccionado + botones).
 *
 * Opciones admitidas:
 *   fuente      (string)  origen de datos: 'personas' (por defecto) o 'usuarios'.
 *   nombre      (string)  name del campo enviado en el POST. Obligatorio.
 *   id          (string)  id HTML; por defecto, el mismo que 'nombre'.
 *   etiqueta    (string)  texto de la etiqueta del campo.
 *   requerido   (bool)    marca el campo como obligatorio.
 *   valor       (int|string) valor ya seleccionado (modo edición).
 *   texto       (string)  texto a mostrar del valor seleccionado.
 *   detalle     (string)  dato secundario mostrado bajo el texto principal.
 *   vacio       (string)  texto cuando no hay selección.
 *   ayuda       (string)  nota bajo el campo.
 *   permite_limpiar (bool) muestra el botón de quitar selección. Por defecto true.
 *   filtros     (array)   filtros fijos para la búsqueda: ['sin_usuario' => 1].
 *   excluir_campo (string) id de otro selector cuyo valor debe excluirse de los
 *                          resultados (evita elegir al titular como su representante).
 */
function selectorEntidad(array $opciones): void
{
    $fuente    = $opciones['fuente'] ?? 'personas';
    $nombre    = $opciones['nombre'] ?? 'persona_id';
    $id        = $opciones['id'] ?? $nombre;
    $etiqueta  = $opciones['etiqueta'] ?? 'Persona';
    $requerido = !empty($opciones['requerido']);
    $valor     = trim((string)($opciones['valor'] ?? ''));
    $texto     = trim((string)($opciones['texto'] ?? ''));
    $detalle   = trim((string)($opciones['detalle'] ?? ''));
    $vacio     = $opciones['vacio'] ?? 'Sin selección';
    $ayuda     = $opciones['ayuda'] ?? '';
    $limpiar   = $opciones['permite_limpiar'] ?? true;
    $filtros   = $opciones['filtros'] ?? [];
    $excluir   = trim((string)($opciones['excluir_campo'] ?? ''));

    $hayValor = ($valor !== '' && $valor !== '0');
    if ($hayValor && $texto === '') {
        $texto = $valor;
    }
    ?>
    <div class="form-group">
        <label class="<?= $requerido ? 'campo-requerido' : '' ?>" for="<?= e($id) ?>_boton"><?= e($etiqueta) ?></label>

        <div class="selector-persona<?= $hayValor ? ' tiene-valor' : '' ?>" id="<?= e($id) ?>_caja">
            <input type="hidden" name="<?= e($nombre) ?>" id="<?= e($id) ?>" value="<?= e($valor) ?>"
                   data-vacio="<?= e($vacio) ?>"<?= $requerido ? ' data-requerido="1"' : '' ?>>

            <div class="selector-persona__valor" id="<?= e($id) ?>_texto" aria-live="polite">
                <span class="selector-persona__nombre"><?= $hayValor ? e($texto) : e($vacio) ?></span>
                <span class="selector-persona__detalle"><?= $hayValor && $detalle !== '' ? e($detalle) : '' ?></span>
            </div>

            <div class="selector-persona__acciones">
                <button type="button"
                        class="btn btn-sm btn-secundario selector-persona__buscar"
                        id="<?= e($id) ?>_boton"
                        data-selector-destino="<?= e($id) ?>"
                        data-selector-fuente="<?= e($fuente) ?>"
                        data-selector-titulo="<?= e($etiqueta) ?>"
                        <?= $filtros ? 'data-selector-filtros="' . e(json_encode($filtros)) . '"' : '' ?>
                        <?= $excluir !== '' ? 'data-selector-excluir="' . e($excluir) . '"' : '' ?>
                        title="Buscar">
                    🔍 Buscar
                </button>
                <?php if ($limpiar): ?>
                    <button type="button"
                            class="btn btn-sm btn-secundario selector-persona__limpiar"
                            data-selector-limpiar="<?= e($id) ?>"
                            title="Quitar la selección"
                            <?= $hayValor ? '' : 'hidden' ?>>✕</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($ayuda !== ''): ?>
            <div class="form-ayuda"><?= e($ayuda) ?></div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Dibuja la subpantalla de búsqueda y su JavaScript.
 * Se invoca una sola vez por página (layout_bottom.php ya lo hace).
 */
function selectorEntidadModal(): void
{
    static $yaImpreso = false;
    if ($yaImpreso) {
        return;
    }
    $yaImpreso = true;

    $fuentes = selectorFuentes();
    ?>
    <div class="sp-modal" id="spModal" hidden role="dialog" aria-modal="true" aria-labelledby="spModalTitulo">
        <div class="sp-modal__fondo" data-sp-cerrar></div>

        <div class="sp-modal__caja" role="document">
            <div class="sp-modal__cabecera">
                <h3 id="spModalTitulo">🔎 Buscar</h3>
                <button type="button" class="sp-modal__cerrar" data-sp-cerrar aria-label="Cerrar">✕</button>
            </div>

            <div class="sp-modal__buscador">
                <input type="search" id="spBusqueda" placeholder="Escriba su búsqueda…"
                       autocomplete="off" aria-label="Texto de búsqueda">
                <button type="button" class="btn btn-primario btn-sm" id="spBuscar">Buscar</button>
            </div>

            <div class="sp-modal__cuerpo">
                <table class="tabla-datos sp-tabla">
                    <thead id="spEncabezado"><tr></tr></thead>
                    <tbody id="spResultados">
                        <tr><td class="tabla-vacia">Escriba y pulse Buscar.</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="sp-modal__pie">
                <span class="sp-modal__conteo" id="spConteo"></span>
                <div class="flex-gap">
                    <button type="button" class="btn btn-sm btn-secundario" id="spAnterior" disabled>‹ Anterior</button>
                    <button type="button" class="btn btn-sm btn-secundario" id="spSiguiente" disabled>Siguiente ›</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        var FUENTES = <?= json_encode($fuentes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

        var modal = document.getElementById('spModal');
        if (!modal) { return; }

        var titulo     = document.getElementById('spModalTitulo');
        var entrada    = document.getElementById('spBusqueda');
        var btnBuscar  = document.getElementById('spBuscar');
        var encabezado = document.getElementById('spEncabezado');
        var cuerpo     = document.getElementById('spResultados');
        var conteo     = document.getElementById('spConteo');
        var btnPrev    = document.getElementById('spAnterior');
        var btnSig     = document.getElementById('spSiguiente');

        var fuente     = FUENTES.personas;  // configuración del origen en uso
        var destino    = null;   // id del input oculto que recibirá la selección
        var disparador = null;   // botón que abrió la ventana (para devolverle el foco)
        var pagina     = 1;
        var totalPag   = 1;
        var temporizador = null;
        var filtros    = {};     // filtros fijos declarados por el campo
        var idExcluido = '';     // registro que no debe aparecer (titular vs. representante)

        /* ---------------- Utilidades ---------------- */

        function escapar(texto) {
            return String(texto === null || texto === undefined ? '' : texto)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        /** Une los campos indicados de una fila, omitiendo los vacíos. */
        function unir(fila, campos) {
            var partes = (campos || []).map(function (campo) {
                var valor = fila[campo];
                return (valor === null || valor === undefined) ? '' : String(valor).trim();
            }).filter(function (parte) { return parte !== ''; });

            return partes.join(' ');
        }

        function columnas() { return fuente.columnas || []; }

        function vaciar(mensaje, clase) {
            cuerpo.innerHTML = '<tr><td colspan="' + (columnas().length + 1) + '" class="'
                + (clase || 'tabla-vacia') + '">' + escapar(mensaje) + '</td></tr>';
        }

        function pintarEncabezado() {
            var html = '';
            columnas().forEach(function (columna) {
                html += '<th>' + escapar(columna.titulo) + '</th>';
            });
            html += '<th class="sp-col-accion"></th>';
            encabezado.innerHTML = '<tr>' + html + '</tr>';
        }

        /* ---------------- Apertura y cierre ---------------- */

        function abrir(idDestino, textoTitulo, boton) {
            var clave = (boton && boton.getAttribute('data-selector-fuente')) || 'personas';
            fuente = FUENTES[clave] || FUENTES.personas;

            destino    = idDestino;
            disparador = boton || null;

            // Filtros fijos del campo (por ejemplo, personas sin cuenta de usuario)
            filtros = {};
            var declarados = boton && boton.getAttribute('data-selector-filtros');
            if (declarados) {
                try { filtros = JSON.parse(declarados) || {}; } catch (e) { filtros = {}; }
            }

            // Registro a excluir: el elegido en otro campo del mismo formulario
            idExcluido = '';
            var campoExcluir = boton && boton.getAttribute('data-selector-excluir');
            if (campoExcluir) {
                var otro = document.getElementById(campoExcluir);
                if (otro && otro.value) { idExcluido = otro.value; }
            }

            titulo.textContent   = '🔎 ' + (fuente.titulo || 'Buscar');
            entrada.placeholder  = fuente.marcador || 'Escriba su búsqueda…';
            modal.hidden = false;
            document.body.classList.add('sp-sin-scroll');
            entrada.value = '';
            pagina = 1;
            pintarEncabezado();
            vaciar('Escriba y pulse Buscar.');
            conteo.textContent = '';
            btnPrev.disabled = btnSig.disabled = true;
            setTimeout(function () { entrada.focus(); }, 40);
            buscar();  // primera página con el listado completo
        }

        function cerrar() {
            modal.hidden = true;
            document.body.classList.remove('sp-sin-scroll');
            if (disparador) { disparador.focus(); }
            destino = null;
            disparador = null;
        }

        /* ---------------- Búsqueda ---------------- */

        function buscar() {
            var termino = entrada.value.trim();
            vaciar('Buscando…');

            var url = fuente.url + '?q=' + encodeURIComponent(termino) + '&pagina=' + pagina;

            Object.keys(filtros).forEach(function (clave) {
                url += '&' + encodeURIComponent(clave) + '=' + encodeURIComponent(filtros[clave]);
            });
            if (idExcluido) {
                url += '&excluir=' + encodeURIComponent(idExcluido);
            }

            fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    if (r.status === 401) { throw new Error('Su sesión expiró. Vuelva a ingresar al sistema.'); }
                    return r.json().catch(function () { throw new Error('Respuesta inesperada del servidor.'); });
                })
                .then(function (datos) {
                    if (!datos.ok) { throw new Error(datos.error || 'No se pudo completar la búsqueda.'); }
                    pintar(datos.datos || [], datos.meta || {});
                })
                .catch(function (error) {
                    vaciar(error.message, 'tabla-vacia sp-error');
                    conteo.textContent = '';
                    btnPrev.disabled = btnSig.disabled = true;
                });
        }

        function pintar(filas, meta) {
            if (!filas.length) {
                vaciar(fuente.vacio || 'No se encontraron resultados.');
                conteo.textContent = '0 resultados';
                btnPrev.disabled = btnSig.disabled = true;
                return;
            }

            var html = '';
            filas.forEach(function (fila) {
                var nombre = unir(fila, fuente.nombre) || '(sin nombre)';

                html += '<tr class="sp-fila" tabindex="0"'
                     +  ' data-id="'      + escapar(fila[fuente.id]) + '"'
                     +  ' data-nombre="'  + escapar(nombre) + '"'
                     +  ' data-detalle="' + escapar(unir(fila, fuente.detalle)) + '">';

                columnas().forEach(function (columna) {
                    var valor = escapar(unir(fila, columna.campos) || '—');
                    html += '<td>' + (columna.fuerte ? '<strong>' + valor + '</strong>' : valor) + '</td>';
                });

                html += '<td class="sp-col-accion"><button type="button" class="btn btn-sm btn-primario">Seleccionar</button></td>'
                     +  '</tr>';
            });
            cuerpo.innerHTML = html;

            pagina   = meta.pagina || 1;
            totalPag = meta.total_paginas || 1;
            conteo.textContent = (meta.total || filas.length) + ' resultado(s) · página ' + pagina + ' de ' + totalPag;
            btnPrev.disabled = pagina <= 1;
            btnSig.disabled  = pagina >= totalPag;
        }

        /* ---------------- Selección ---------------- */

        function seleccionar(fila) {
            if (!destino) { return; }

            var campo = document.getElementById(destino);
            var caja  = document.getElementById(destino + '_caja');
            var texto = document.getElementById(destino + '_texto');
            if (!campo || !texto) { return; }

            campo.value = fila.getAttribute('data-id');
            texto.querySelector('.selector-persona__nombre').textContent  = fila.getAttribute('data-nombre');
            texto.querySelector('.selector-persona__detalle').textContent = fila.getAttribute('data-detalle');
            if (caja) { caja.classList.add('tiene-valor'); }

            var limpiar = document.querySelector('[data-selector-limpiar="' + destino + '"]');
            if (limpiar) { limpiar.hidden = false; }

            campo.dispatchEvent(new Event('change', { bubbles: true }));
            cerrar();
        }

        function limpiar(idDestino) {
            var campo = document.getElementById(idDestino);
            var caja  = document.getElementById(idDestino + '_caja');
            var texto = document.getElementById(idDestino + '_texto');
            if (!campo || !texto) { return; }

            campo.value = '';
            texto.querySelector('.selector-persona__nombre').textContent  = campo.getAttribute('data-vacio') || 'Sin selección';
            texto.querySelector('.selector-persona__detalle').textContent = '';
            if (caja) { caja.classList.remove('tiene-valor'); }

            var boton = document.querySelector('[data-selector-limpiar="' + idDestino + '"]');
            if (boton) { boton.hidden = true; }

            campo.dispatchEvent(new Event('change', { bubbles: true }));
        }

        /* ---------------- Eventos ---------------- */

        document.addEventListener('click', function (evento) {
            var abrirBtn = evento.target.closest('[data-selector-destino]');
            if (abrirBtn) {
                evento.preventDefault();
                abrir(abrirBtn.getAttribute('data-selector-destino'),
                      abrirBtn.getAttribute('data-selector-titulo'),
                      abrirBtn);
                return;
            }

            var limpiarBtn = evento.target.closest('[data-selector-limpiar]');
            if (limpiarBtn) {
                evento.preventDefault();
                limpiar(limpiarBtn.getAttribute('data-selector-limpiar'));
                return;
            }

            if (evento.target.closest('[data-sp-cerrar]')) {
                evento.preventDefault();
                cerrar();
                return;
            }

            var fila = evento.target.closest('.sp-fila');
            if (fila && modal.contains(fila)) {
                seleccionar(fila);
            }
        });

        cuerpo.addEventListener('keydown', function (evento) {
            if (evento.key === 'Enter' || evento.key === ' ') {
                var fila = evento.target.closest('.sp-fila');
                if (fila) { evento.preventDefault(); seleccionar(fila); }
            }
        });

        document.addEventListener('keydown', function (evento) {
            if (evento.key === 'Escape' && !modal.hidden) { cerrar(); }
        });

        btnBuscar.addEventListener('click', function () { pagina = 1; buscar(); });

        entrada.addEventListener('keydown', function (evento) {
            if (evento.key === 'Enter') { evento.preventDefault(); pagina = 1; buscar(); }
        });

        // Búsqueda automática mientras se escribe (con pausa para no saturar)
        entrada.addEventListener('input', function () {
            clearTimeout(temporizador);
            temporizador = setTimeout(function () { pagina = 1; buscar(); }, 400);
        });

        btnPrev.addEventListener('click', function () { if (pagina > 1) { pagina--; buscar(); } });
        btnSig.addEventListener('click', function () { if (pagina < totalPag) { pagina++; buscar(); } });

        /* Aviso al enviar un formulario con un selector obligatorio vacío */
        document.addEventListener('submit', function (evento) {
            var formulario = evento.target;
            if (!formulario || typeof formulario.querySelectorAll !== 'function') { return; }

            var pendientes = formulario.querySelectorAll('input[type="hidden"][data-requerido="1"]');
            for (var i = 0; i < pendientes.length; i++) {
                var campo = pendientes[i];
                if (!campo.value || campo.value === '0') {
                    evento.preventDefault();
                    var caja = document.getElementById(campo.id + '_caja');
                    if (caja) {
                        caja.classList.add('sp-invalido');
                        setTimeout(function () { caja.classList.remove('sp-invalido'); }, 2500);
                        caja.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    var boton = document.getElementById(campo.id + '_boton');
                    if (boton) { boton.focus(); }
                    return;
                }
            }
        });
    })();
    </script>
    <?php
}
