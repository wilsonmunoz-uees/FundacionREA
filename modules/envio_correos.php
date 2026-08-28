<?php
/**
 * modules/envio_correos.php
 * -----------------------------------------------------------------------------
 * Envío masivo de invitaciones al consentimiento de datos.
 *
 * La pantalla presenta los tres grupos como un árbol —Estudiantes, Empleados y
 * Proveedores—, cada uno marcado y con «todos» seleccionado de entrada. Quien
 * necesite acotar puede pasar a «solo algunos» y elegir de un listado con
 * buscador y paginación; abajo de cada grupo queda el contador de seleccionados.
 *
 * El envío se hace por lotes contra la API, de modo que la pantalla muestre el
 * avance y el navegador no se quede esperando.
 * -----------------------------------------------------------------------------
 */
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('envio_correos');
$institucionId = institucionActual();

/* --- Datos para armar la pantalla --- */
$resumen = apiDatos(apiGet('correos/resumen'), []);

$finalidades = apiDatos(apiGet('finalidades', ['solo_activas' => 1, 'por_pagina' => 200]), []);

$config       = apiDatos(apiGet('correos/configuracion'), []);
$correoListo  = !empty($config['activo']) || true;   // mail() siempre está como respaldo
$usandoSmtp   = !empty($config['activo']);

$ultimosEnvios = apiDatos(apiGet('correos/envios', ['por_pagina' => 5]), []);

/* URL pública desde la que se arma el enlace del correo */
$esquema  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
$carpeta  = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$baseUrl  = $esquema . '://' . $host . $carpeta;

$grupos = [
    'ESTUDIANTE' => ['etiqueta' => 'Estudiantes', 'icono' => '🎓',
                     'nota' => 'El correo se envía al representante, indicando que se trata de su representado.'],
    'EMPLEADO'   => ['etiqueta' => 'Empleados',   'icono' => '👥', 'nota' => 'El correo se envía al propio colaborador.'],
    'PROVEEDOR'  => ['etiqueta' => 'Proveedores', 'icono' => '📦', 'nota' => 'El correo se envía al contacto registrado.'],
];

$pageTitle  = 'Envío de Correos';
$breadcrumb = [['label' => 'Administración', 'url' => null], ['label' => 'Envío de Correos', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header no-imprimir">
    <div>
        <h1>✉️ Envío Masivo de Correos</h1>
        <p>Invitación a otorgar o revocar el consentimiento para el tratamiento de datos personales.</p>
    </div>
    <div class="flex-gap">
        <a href="correo_configuracion.php" class="btn btn-secundario">⚙️ Configurar correo</a>
    </div>
</div>

<?php if (!$usandoSmtp): ?>
    <div class="alerta alerta-advertencia">
        <strong>El envío usará la función mail() del servidor.</strong>
        En hospedajes compartidos suele estar limitada y los mensajes pueden no salir o llegar a la
        bandeja de correo no deseado. <a href="correo_configuracion.php">Configure un servidor SMTP</a>
        para un envío confiable.
    </div>
<?php endif; ?>

<form method="POST" id="formEnvio" onsubmit="return false;">

    <!-- ------------------------------------------------------------------ -->
    <!-- Árbol de destinatarios                                              -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card">
        <div class="flex-entre">
            <h3 class="mb-0">1. ¿A quiénes desea escribir?</h3>
            <span class="texto-mutado" id="totalGeneral"></span>
        </div>

        <div class="arbol-destinatarios">
            <?php foreach ($grupos as $clave => $grupo): ?>
                <?php
                $datosGrupo = $resumen[$clave] ?? ['total' => 0, 'con_correo' => 0, 'sin_correo' => 0];
                $conCorreo  = (int)$datosGrupo['con_correo'];
                $sinCorreo  = (int)$datosGrupo['sin_correo'];
                ?>
                <div class="rama" data-tipo="<?= e($clave) ?>">

                    <label class="rama-cabecera">
                        <input type="checkbox" class="chk-grupo" data-tipo="<?= e($clave) ?>" checked>
                        <span class="rama-icono"><?= $grupo['icono'] ?></span>
                        <span class="rama-titulo">
                            <?= e($grupo['etiqueta']) ?>
                            <small><?= $conCorreo ?> con correo<?= $sinCorreo > 0 ? ' · ' . $sinCorreo . ' sin correo' : '' ?></small>
                        </span>
                    </label>

                    <div class="rama-cuerpo">
                        <label class="rama-opcion">
                            <input type="radio" name="modo_<?= e($clave) ?>" value="todos"
                                   class="rd-modo" data-tipo="<?= e($clave) ?>" checked>
                            Todos los <?= e(mb_strtolower($grupo['etiqueta'])) ?> (<?= $conCorreo ?>)
                        </label>

                        <label class="rama-opcion">
                            <input type="radio" name="modo_<?= e($clave) ?>" value="algunos"
                                   class="rd-modo" data-tipo="<?= e($clave) ?>">
                            Solo algunos…
                            <button type="button" class="btn btn-sm btn-secundario btn-elegir"
                                    data-tipo="<?= e($clave) ?>" hidden>🔍 Elegir</button>
                        </label>

                        <p class="rama-nota"><?= e($grupo['nota']) ?></p>

                        <div class="rama-contador" data-tipo="<?= e($clave) ?>">
                            Seleccionados: <strong data-contador="<?= e($clave) ?>"><?= $conCorreo ?></strong>
                            <span class="texto-mutado" data-detalle="<?= e($clave) ?>">(todos)</span>
                        </div>
                    </div>

                    <input type="hidden" name="ids_<?= e($clave) ?>" id="ids_<?= e($clave) ?>" value="">
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Contenido del mensaje                                               -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card">
        <h3>2. Contenido del mensaje</h3>

        <div class="form-row">
            <div class="form-group" style="flex:1 1 260px;">
                <label for="finalidad_id">Finalidad del tratamiento</label>
                <select name="finalidad_id" id="finalidad_id">
                    <option value="">— Sin finalidad específica —</option>
                    <?php foreach ($finalidades as $f): ?>
                        <option value="<?= e($f['FinalidadId']) ?>"><?= e($f['Nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-ayuda">Se muestra en el correo y queda registrada con el consentimiento.</div>
            </div>

            <div class="form-group" style="flex:2 1 340px;">
                <label for="asunto" class="campo-requerido">Asunto</label>
                <input type="text" name="asunto" id="asunto" maxlength="200"
                       value="Consentimiento para el tratamiento de sus datos personales">
            </div>
        </div>

        <div class="form-group">
            <label for="mensaje">Mensaje adicional <span class="texto-mutado">(opcional)</span></label>
            <textarea name="mensaje" id="mensaje" rows="3"
                      placeholder="Por ejemplo: el plazo para responder, o a quién dirigirse ante dudas."></textarea>
            <div class="form-ayuda">
                Se añade al texto base del correo, que ya incluye el enunciado y el enlace a la
                pantalla de consentimiento. El texto base se edita en
                <code>plantillas/correo_invitacion.php</code>.
            </div>
        </div>
    </div>

    <!-- ------------------------------------------------------------------ -->
    <!-- Envío                                                               -->
    <!-- ------------------------------------------------------------------ -->
    <div class="card">
        <h3>3. Enviar</h3>

        <p class="texto-mutado">
            Cada persona recibe un enlace propio y firmado. Quienes no tengan correo registrado se
            omiten y quedan anotados en el detalle del envío.
        </p>

        <div class="flex-gap">
            <button type="button" class="btn btn-primario" id="btnEnviar">✉️ Enviar invitaciones</button>
            <span class="texto-mutado" id="estadoEnvio"></span>
        </div>

        <div id="progreso" hidden>
            <div class="barra-progreso"><div class="barra-progreso__relleno" id="barraRelleno"></div></div>
            <div class="progreso-detalle" id="progresoDetalle"></div>
        </div>

        <div id="resultadoEnvio"></div>
    </div>
</form>

<?php if (!empty($ultimosEnvios)): ?>
<div class="card">
    <h3>Últimos envíos</h3>
    <div class="tabla-wrap">
        <table class="tabla-datos">
            <thead>
            <tr>
                <th>Fecha</th><th>Usuario</th><th>Finalidad</th><th>Asunto</th>
                <th>Estudiantes</th><th>Empleados</th><th>Proveedores</th><th>Enviados</th><th>Fallidos</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($ultimosEnvios as $envio): ?>
                <tr>
                    <td><?= f_fecha($envio['FechaEnvio']) ?></td>
                    <td><?= e($envio['Username'] ?: '—') ?></td>
                    <td><?= e($envio['FinalidadNombre'] ?: '—') ?></td>
                    <td><?= truncar($envio['Asunto'], 40) ?></td>
                    <td><?= (int)$envio['TotalEstudiantes'] ?></td>
                    <td><?= (int)$envio['TotalEmpleados'] ?></td>
                    <td><?= (int)$envio['TotalProveedores'] ?></td>
                    <td><span class="badge badge-activo"><?= (int)$envio['Enviados'] ?></span></td>
                    <td>
                        <?php if ((int)$envio['Fallidos'] > 0): ?>
                            <span class="badge badge-inactivo"><?= (int)$envio['Fallidos'] ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ---------------------------------------------------------------------- -->
<!-- Subpantalla de selección: buscador + paginación + casillas             -->
<!-- ---------------------------------------------------------------------- -->
<div class="sp-modal" id="modalDestinatarios" hidden role="dialog" aria-modal="true" aria-labelledby="mdTitulo">
    <div class="sp-modal__fondo" data-md-cerrar></div>
    <div class="sp-modal__caja" role="document">
        <div class="sp-modal__cabecera">
            <h3 id="mdTitulo">Elegir destinatarios</h3>
            <button type="button" class="sp-modal__cerrar" data-md-cerrar aria-label="Cerrar">✕</button>
        </div>

        <div class="sp-modal__buscador">
            <input type="search" id="mdBusqueda" placeholder="Nombres, apellidos, identificación o correo…" autocomplete="off">
            <button type="button" class="btn btn-primario btn-sm" id="mdBuscar">Buscar</button>
        </div>

        <div class="sp-modal__cuerpo">
            <table class="tabla-datos sp-tabla">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="mdTodosPagina" title="Marcar los de esta página"></th>
                        <th>Identificación</th><th>Nombre</th><th>Correo de destino</th>
                    </tr>
                </thead>
                <tbody id="mdResultados">
                    <tr><td colspan="4" class="tabla-vacia">Cargando…</td></tr>
                </tbody>
            </table>
        </div>

        <div class="sp-modal__pie">
            <span class="sp-modal__conteo" id="mdConteo"></span>
            <div class="flex-gap">
                <button type="button" class="btn btn-sm btn-secundario" id="mdAnterior" disabled>‹ Anterior</button>
                <button type="button" class="btn btn-sm btn-secundario" id="mdSiguiente" disabled>Siguiente ›</button>
                <button type="button" class="btn btn-sm btn-primario" id="mdListo">Listo</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var RAIZ      = <?= json_encode(APP_ROOT, JSON_UNESCAPED_SLASHES) ?>;
    var BASE_URL  = <?= json_encode($baseUrl, JSON_UNESCAPED_SLASHES) ?>;
    var TOTALES   = <?= json_encode($resumen, JSON_UNESCAPED_UNICODE) ?>;
    var TIPOS     = ['ESTUDIANTE', 'EMPLEADO', 'PROVEEDOR'];
    var ETIQUETAS = { ESTUDIANTE: 'estudiantes', EMPLEADO: 'empleados', PROVEEDOR: 'proveedores' };

    /* Estado de la pantalla: por tipo, si participa, si va «todos» y qué ids se eligieron */
    var estado = {};
    TIPOS.forEach(function (t) {
        estado[t] = { activo: true, todos: true, ids: [], nombres: {} };
    });

    function escapar(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function conCorreo(tipo) {
        return (TOTALES[tipo] && TOTALES[tipo].con_correo) || 0;
    }

    /* ------------------------- Contadores ------------------------- */

    function refrescarContadores() {
        var total = 0;

        TIPOS.forEach(function (tipo) {
            var e = estado[tipo];
            var n = !e.activo ? 0 : (e.todos ? conCorreo(tipo) : e.ids.length);
            total += n;

            var contador = document.querySelector('[data-contador="' + tipo + '"]');
            var detalle  = document.querySelector('[data-detalle="' + tipo + '"]');
            var caja     = document.querySelector('.rama-contador[data-tipo="' + tipo + '"]');

            if (contador) { contador.textContent = n; }
            if (detalle)  {
                detalle.textContent = !e.activo ? '(grupo excluido)'
                                    : (e.todos ? '(todos)' : '(selección manual)');
            }
            if (caja) { caja.classList.toggle('vacio', n === 0); }
        });

        var resumen = document.getElementById('totalGeneral');
        if (resumen) {
            resumen.textContent = total === 1
                ? '1 destinatario seleccionado'
                : total + ' destinatarios seleccionados';
        }

        document.getElementById('btnEnviar').disabled = (total === 0);
        return total;
    }

    /* ------------------------- Árbol ------------------------- */

    document.querySelectorAll('.chk-grupo').forEach(function (chk) {
        chk.addEventListener('change', function () {
            var tipo = chk.getAttribute('data-tipo');
            estado[tipo].activo = chk.checked;
            var rama = document.querySelector('.rama[data-tipo="' + tipo + '"]');
            if (rama) { rama.classList.toggle('apagada', !chk.checked); }
            refrescarContadores();
        });
    });

    document.querySelectorAll('.rd-modo').forEach(function (rd) {
        rd.addEventListener('change', function () {
            var tipo = rd.getAttribute('data-tipo');
            estado[tipo].todos = (rd.value === 'todos');

            var boton = document.querySelector('.btn-elegir[data-tipo="' + tipo + '"]');
            if (boton) { boton.hidden = estado[tipo].todos; }

            // Al pasar a «solo algunos» se abre la selección de una vez
            if (!estado[tipo].todos && estado[tipo].ids.length === 0) {
                abrirModal(tipo);
            }
            refrescarContadores();
        });
    });

    document.querySelectorAll('.btn-elegir').forEach(function (b) {
        b.addEventListener('click', function () { abrirModal(b.getAttribute('data-tipo')); });
    });

    /* ------------------------- Subpantalla ------------------------- */

    var modal   = document.getElementById('modalDestinatarios');
    var titulo  = document.getElementById('mdTitulo');
    var entrada = document.getElementById('mdBusqueda');
    var cuerpo  = document.getElementById('mdResultados');
    var conteo  = document.getElementById('mdConteo');
    var btnPrev = document.getElementById('mdAnterior');
    var btnSig  = document.getElementById('mdSiguiente');
    var chkPag  = document.getElementById('mdTodosPagina');

    var tipoActual = null, pagina = 1, totalPag = 1, temporizador = null;

    function abrirModal(tipo) {
        tipoActual = tipo;
        pagina = 1;
        titulo.textContent = 'Elegir ' + ETIQUETAS[tipo];
        entrada.value = '';
        modal.hidden = false;
        document.body.classList.add('sp-sin-scroll');
        setTimeout(function () { entrada.focus(); }, 40);
        cargar();
    }

    function cerrarModal() {
        modal.hidden = true;
        document.body.classList.remove('sp-sin-scroll');
        // Si no se eligió a nadie, el grupo vuelve a «todos» para no quedar en cero
        if (tipoActual && estado[tipoActual].ids.length === 0) {
            var rd = document.querySelector('.rd-modo[data-tipo="' + tipoActual + '"][value="todos"]');
            if (rd) { rd.checked = true; estado[tipoActual].todos = true; }
            var boton = document.querySelector('.btn-elegir[data-tipo="' + tipoActual + '"]');
            if (boton) { boton.hidden = true; }
        }
        tipoActual = null;
        refrescarContadores();
    }

    function cargar() {
        cuerpo.innerHTML = '<tr><td colspan="4" class="tabla-vacia">Buscando…</td></tr>';

        var url = RAIZ + 'ajax/buscar_destinatarios.php?tipo=' + encodeURIComponent(tipoActual)
                + '&q=' + encodeURIComponent(entrada.value.trim())
                + '&pagina=' + pagina;

        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (r.status === 401) { throw new Error('Su sesión expiró. Vuelva a ingresar al sistema.'); }
                return r.json();
            })
            .then(function (d) {
                if (!d.ok) { throw new Error(d.error || 'No se pudo cargar el listado.'); }
                pintar(d.datos || [], d.meta || {});
            })
            .catch(function (error) {
                cuerpo.innerHTML = '<tr><td colspan="4" class="tabla-vacia sp-error">'
                                 + escapar(error.message) + '</td></tr>';
            });
    }

    function pintar(filas, meta) {
        if (!filas.length) {
            cuerpo.innerHTML = '<tr><td colspan="4" class="tabla-vacia">No se encontraron registros.</td></tr>';
            conteo.textContent = '0 resultados';
            btnPrev.disabled = btnSig.disabled = true;
            return;
        }

        var elegidos = estado[tipoActual].ids;
        var html = '';

        filas.forEach(function (f) {
            var id       = String(f.PersonaId);
            var marcado  = elegidos.indexOf(id) !== -1 ? ' checked' : '';
            var sinCorreo = !f.Correo;
            var destino  = sinCorreo
                ? '<em class="texto-mutado">sin correo registrado</em>'
                : escapar(f.Correo) + (f.CorreoDe === 'REPRESENTANTE'
                    ? ' <span class="badge badge-neutro">representante</span>' : '');

            html += '<tr class="' + (sinCorreo ? 'fila-sin-correo' : '') + '">'
                 +  '<td class="col-check">'
                 +    '<input type="checkbox" class="md-chk" value="' + escapar(id) + '"'
                 +    ' data-nombre="' + escapar(f.Titular || '') + '"' + marcado
                 +    (sinCorreo ? ' disabled title="No tiene correo registrado"' : '') + '></td>'
                 +  '<td>' + escapar(f.Identificacion || '—') + '</td>'
                 +  '<td><strong>' + escapar(f.Titular || '') + '</strong>'
                 +    (f.Detalle ? '<br><small class="texto-mutado">' + escapar(f.Detalle) + '</small>' : '')
                 +    (f.Representante ? '<br><small class="texto-mutado">Rep.: ' + escapar(f.Representante) + '</small>' : '')
                 +  '</td>'
                 +  '<td>' + destino + '</td>'
                 +  '</tr>';
        });

        cuerpo.innerHTML = html;

        pagina   = meta.pagina || 1;
        totalPag = meta.total_paginas || 1;
        conteo.textContent = (meta.total || filas.length) + ' resultado(s) · página ' + pagina + ' de ' + totalPag
                           + ' · ' + elegidos.length + ' seleccionado(s)';
        btnPrev.disabled = pagina <= 1;
        btnSig.disabled  = pagina >= totalPag;
        chkPag.checked   = false;
    }

    /* Las casillas se recuerdan aunque se cambie de página o de búsqueda */
    cuerpo.addEventListener('change', function (evento) {
        var chk = evento.target;
        if (!chk.classList.contains('md-chk')) { return; }

        var lista  = estado[tipoActual].ids;
        var indice = lista.indexOf(chk.value);

        if (chk.checked && indice === -1) {
            lista.push(chk.value);
            estado[tipoActual].nombres[chk.value] = chk.getAttribute('data-nombre');
        } else if (!chk.checked && indice !== -1) {
            lista.splice(indice, 1);
        }

        conteo.textContent = conteo.textContent.replace(/\d+ seleccionado\(s\)/, lista.length + ' seleccionado(s)');
        refrescarContadores();
    });

    chkPag.addEventListener('change', function () {
        cuerpo.querySelectorAll('.md-chk:not(:disabled)').forEach(function (chk) {
            if (chk.checked !== chkPag.checked) {
                chk.checked = chkPag.checked;
                chk.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    });

    document.getElementById('mdBuscar').addEventListener('click', function () { pagina = 1; cargar(); });
    entrada.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); pagina = 1; cargar(); }
    });
    entrada.addEventListener('input', function () {
        clearTimeout(temporizador);
        temporizador = setTimeout(function () { pagina = 1; cargar(); }, 400);
    });
    btnPrev.addEventListener('click', function () { if (pagina > 1) { pagina--; cargar(); } });
    btnSig.addEventListener('click', function () { if (pagina < totalPag) { pagina++; cargar(); } });
    document.getElementById('mdListo').addEventListener('click', cerrarModal);

    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-md-cerrar]')) { cerrarModal(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) { cerrarModal(); }
    });

    /* ------------------------- Envío por lotes ------------------------- */

    var btnEnviar = document.getElementById('btnEnviar');
    var cajaEstado = document.getElementById('estadoEnvio');
    var progreso   = document.getElementById('progreso');
    var relleno    = document.getElementById('barraRelleno');
    var detalle    = document.getElementById('progresoDetalle');
    var resultado  = document.getElementById('resultadoEnvio');

    function seleccionActual() {
        var seleccion = {};
        TIPOS.forEach(function (t) {
            seleccion[t] = estado[t].activo
                ? { todos: estado[t].todos, ids: estado[t].todos ? [] : estado[t].ids.map(Number) }
                : { todos: false, ids: [] };
        });
        return seleccion;
    }

    function llamar(ruta, datos) {
        return fetch(RAIZ + 'ajax/enviar_correos.php?accion=' + ruta, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(datos)
        }).then(function (r) {
            return r.json().catch(function () { throw new Error('Respuesta inesperada del servidor.'); });
        }).then(function (d) {
            if (!d.ok) { throw new Error(d.error || 'No se pudo completar la operación.'); }
            return d.datos || {};
        });
    }

    btnEnviar.addEventListener('click', function () {
        var total = refrescarContadores();
        if (total === 0) { return; }

        if (!window.confirm('Se enviará el correo a ' + total + ' destinatario(s).\n\n¿Desea continuar?')) {
            return;
        }

        var comun = {
            base_url:     BASE_URL,
            finalidad_id: document.getElementById('finalidad_id').value,
            asunto:       document.getElementById('asunto').value,
            mensaje:      document.getElementById('mensaje').value
        };

        btnEnviar.disabled = true;
        resultado.innerHTML = '';
        progreso.hidden = false;
        relleno.style.width = '0%';
        cajaEstado.textContent = 'Preparando el envío…';
        detalle.textContent = '';

        llamar('preparar', Object.assign({ seleccion: seleccionActual() }, comun))
            .then(function (d) {
                var pendientes = d.destinatarios || [];
                var tamano     = d.tamano_lote || 25;
                var enviados = 0, fallidos = 0, fallos = [];

                function siguienteLote(indice) {
                    if (indice >= pendientes.length) {
                        return Promise.resolve();
                    }
                    var lote = pendientes.slice(indice, indice + tamano);

                    return llamar('enviar', Object.assign({
                        envio_id: d.envio_id,
                        destinatarios: lote
                    }, comun)).then(function (r) {
                        enviados += r.enviados || 0;
                        fallidos += r.fallidos || 0;

                        (r.resultados || []).forEach(function (x) {
                            if (x.estado !== 'ENVIADO') { fallos.push(x); }
                        });

                        var hechos = Math.min(indice + tamano, pendientes.length);
                        relleno.style.width = Math.round(hechos * 100 / pendientes.length) + '%';
                        cajaEstado.textContent = 'Enviando… ' + hechos + ' de ' + pendientes.length;
                        detalle.textContent = enviados + ' enviados · ' + fallidos + ' con problema'
                                            + (r.via ? ' · vía ' + r.via : '');

                        return siguienteLote(indice + tamano);
                    });
                }

                return siguienteLote(0).then(function () {
                    return { enviados: enviados, fallidos: fallidos, fallos: fallos, total: pendientes.length };
                });
            })
            .then(function (r) {
                cajaEstado.textContent = '';
                relleno.style.width = '100%';

                var html = '<div class="alerta ' + (r.fallidos > 0 ? 'alerta-advertencia' : 'alerta-exito') + '">'
                         + '<strong>Envío finalizado.</strong> '
                         + r.enviados + ' correo(s) enviado(s) de ' + r.total + '.'
                         + (r.fallidos > 0 ? ' ' + r.fallidos + ' no se pudieron enviar.' : '')
                         + '</div>';

                if (r.fallos.length) {
                    html += '<div class="tabla-wrap"><table class="tabla-datos"><thead><tr>'
                          + '<th>Destinatario</th><th>Correo</th><th>Situación</th></tr></thead><tbody>';
                    r.fallos.slice(0, 50).forEach(function (f) {
                        html += '<tr><td>' + escapar(f.titular) + '</td>'
                              + '<td>' + escapar(f.correo || '—') + '</td>'
                              + '<td>' + escapar(f.detalle || f.estado) + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                }

                resultado.innerHTML = html;
                btnEnviar.disabled = false;
            })
            .catch(function (error) {
                progreso.hidden = true;
                cajaEstado.textContent = '';
                resultado.innerHTML = '<div class="alerta alerta-error">' + escapar(error.message) + '</div>';
                btnEnviar.disabled = false;
            });
    });

    refrescarContadores();
})();
</script>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
