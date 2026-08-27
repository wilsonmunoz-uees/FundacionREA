<?php
/**
 * modules/envio_masivo.php
 * -----------------------------------------------------------------------------
 * Envío Masivo de invitaciones al consentimiento. Opción del rol Registro de
 * Datos.
 *
 * Envía a estudiantes, empleados o proveedores el enlace de consentimiento CON
 * VERIFICACIÓN de su tipo, ya con su número de documento precargado. El correo
 * sale por el servidor configurado para la institución con la que se inició
 * sesión, y el enlace apunta a esa misma institución.
 *
 * Se puede enviar a todo un grupo o elegir persona por persona desde una
 * subventana de consulta, con búsqueda, paginación y contador de seleccionados.
 * -----------------------------------------------------------------------------
 */
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('envio_masivo');

$institucionId     = institucionActual();
$institucionNombre = $_SESSION['institucion_nombre'] ?? 'esta institución';

const ENVIO_TIPOS = [
    'ESTUDIANTE' => ['etiqueta' => 'Estudiantes', 'icono' => '🎓', 'documento' => 'Cédula'],
    'EMPLEADO'   => ['etiqueta' => 'Empleados',   'icono' => '👥', 'documento' => 'Cédula'],
    'PROVEEDOR'  => ['etiqueta' => 'Proveedores', 'icono' => '📦', 'documento' => 'RUC'],
];

$errores   = [];
$resultado = null;

/* ---------------------------------------------------------------------------
   Envío
   --------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'enviar') {
    if (!csrfValido()) {
        $errores[] = 'La sesión expiró o el formulario no es válido. Vuelva a intentarlo.';
    } else {
        $tipo    = strtoupper(trim($_POST['tipo'] ?? ''));
        $alcance = $_POST['alcance'] ?? 'todos';

        // Los seleccionados viajan en un campo oculto, separados por comas
        $personas = array_values(array_filter(array_map(
            'intval',
            explode(',', (string)($_POST['personas'] ?? ''))
        )));

        if (!isset(ENVIO_TIPOS[$tipo])) {
            $errores[] = 'Elija a quién va dirigido el envío.';
        } elseif ($alcance === 'seleccion' && !$personas) {
            $errores[] = 'No eligió ningún destinatario. Pulse «Elegir destinatarios» y marque al menos uno.';
        } elseif (empty($_POST['confirmado'])) {
            $errores[] = 'Confirme el envío antes de continuar.';
        } else {
            $respuesta = apiPost('envio-masivo/enviar', [
                'tipo'     => $tipo,
                'alcance'  => $alcance,
                'personas' => $personas,
            ]);

            if ($respuesta['ok']) {
                $resultado = apiDatos($respuesta, []);
            } else {
                $errores = apiErrores($respuesta);
            }
        }
    }
}

/* ---------------------------------------------------------------------------
   Datos de la pantalla
   --------------------------------------------------------------------------- */
$resumen = apiDatos(apiGet('envio-masivo/resumen'), []);
$conteos = $resumen['tipos'] ?? [];
$correo  = $resumen['correo'] ?? [];
$maximo  = (int)($resumen['maximo'] ?? 300);

$pageTitle  = 'Envío Masivo de Invitaciones';
$pageDesc   = 'Invita a registrar el consentimiento con el enlace verificado';
$breadcrumb = [
    ['label' => 'Registro de Datos', 'url' => null],
    ['label' => 'Envío Masivo de Invitaciones', 'url' => null],
];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>📨 Envío Masivo de Invitaciones</h1>
        <p>
            Envía a estudiantes, empleados o proveedores de <strong><?= e($institucionNombre) ?></strong>
            el enlace de consentimiento con verificación, con su documento ya precargado.
        </p>
    </div>
    <div class="flex-gap">
        <?php if (puedeAcceder('correo_configuracion')): ?>
            <a href="correo_configuracion.php" class="btn btn-secundario">⚙️ Configurar correo</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($errores): ?>
    <div class="alerta alerta-error">
        <?php foreach ($errores as $error): ?><div><?= e($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($correo['smtp_activo'])): ?>
    <div class="alerta alerta-advertencia">
        El envío por SMTP no está activo para esta institución: los correos saldrán con la función
        <code>mail()</code> de PHP, que en hospedaje compartido suele terminar en la carpeta de no
        deseados.
        <?php if (puedeAcceder('correo_configuracion')): ?>
            <a href="correo_configuracion.php">Configure el servidor de correo</a> antes de un envío grande.
        <?php else: ?>
            Pida a un administrador que configure el servidor de correo antes de un envío grande.
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($resultado !== null): ?>
    <!-- ==================== Resultado del envío ==================== -->
    <div class="alerta <?= (int)$resultado['enviados'] > 0 ? 'alerta-exito' : 'alerta-error' ?>">
        <strong><?= e($resultado['mensaje'] ?? '') ?></strong>
        &middot; <?= e(ENVIO_TIPOS[$resultado['tipo']]['etiqueta'] ?? '') ?>
        &middot; enviado vía <?= e($resultado['via'] ?? '') ?>
    </div>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-valor"><?= (int)($resultado['total'] ?? 0) ?></div>
            <div class="kpi-label">Destinatarios</div>
        </div>
        <div class="kpi-card kpi-alt-2">
            <div class="kpi-valor"><?= (int)($resultado['enviados'] ?? 0) ?></div>
            <div class="kpi-label">Correos enviados</div>
        </div>
        <div class="kpi-card kpi-alt-1">
            <div class="kpi-valor"><?= count($resultado['sin_correo'] ?? []) ?></div>
            <div class="kpi-label">Sin correo registrado</div>
        </div>
        <div class="kpi-card kpi-alt-3">
            <div class="kpi-valor"><?= count($resultado['fallidos'] ?? []) ?></div>
            <div class="kpi-label">No se pudieron enviar</div>
        </div>
    </div>

    <?php foreach ([
        ['clave' => 'sin_correo', 'titulo' => 'Sin correo registrado',
         'nota'  => 'No se les pudo escribir. Registre su correo desde el módulo que corresponda y vuelva a enviar.'],
        ['clave' => 'fallidos', 'titulo' => 'No se pudieron enviar',
         'nota'  => 'El servidor de correo rechazó estos mensajes.'],
    ] as $bloque): ?>
        <?php $lista = $resultado[$bloque['clave']] ?? []; ?>
        <?php if ($lista): ?>
            <div class="card">
                <h3><?= e($bloque['titulo']) ?> (<?= count($lista) ?>)</h3>
                <p class="texto-mutado"><?= e($bloque['nota']) ?></p>
                <div class="tabla-wrap">
                    <table class="tabla-datos">
                        <thead>
                        <tr><th>Identificación</th><th>Nombre</th><?php if ($bloque['clave'] === 'fallidos'): ?><th>Motivo</th><?php endif; ?></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($lista as $fila): ?>
                            <tr>
                                <td><?= e($fila['Identificacion'] ?? '') ?></td>
                                <td><?= e($fila['Nombre'] ?? '') ?></td>
                                <?php if ($bloque['clave'] === 'fallidos'): ?>
                                    <td class="texto-mutado"><?= e($fila['detalle'] ?? '—') ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <div class="flex-gap">
        <a href="envio_masivo.php" class="btn btn-primario">Realizar otro envío</a>
    </div>

<?php else: ?>

    <!-- ==================== Formulario de envío ==================== -->
    <form method="POST" action="envio_masivo.php" id="formEnvio">
        <?= csrfCampo() ?>
        <input type="hidden" name="accion" value="enviar">
        <input type="hidden" name="personas" id="personasSeleccionadas" value="">
        <input type="hidden" name="confirmado" id="confirmado" value="">

        <div class="card">
            <h3>Paso 1 · ¿A quién va dirigido?</h3>
            <div class="envio-tipos">
                <?php $primero = true; foreach (ENVIO_TIPOS as $clave => $tipo): ?>
                    <?php $c = $conteos[$clave] ?? ['total' => 0, 'con_correo' => 0, 'sin_correo' => 0]; ?>
                    <label class="envio-tipo">
                        <input type="radio" name="tipo" value="<?= e($clave) ?>"
                               data-etiqueta="<?= e($tipo['etiqueta']) ?>"
                               data-con-correo="<?= (int)$c['con_correo'] ?>"
                               <?= $primero ? 'checked' : '' ?>>
                        <span class="envio-tipo-cuerpo">
                            <span class="envio-tipo-icono"><?= $tipo['icono'] ?></span>
                            <span class="envio-tipo-nombre"><?= e($tipo['etiqueta']) ?></span>
                            <span class="envio-tipo-dato">
                                <strong><?= (int)$c['con_correo'] ?></strong> de <?= (int)$c['total'] ?> con correo
                            </span>
                            <?php if ((int)$c['sin_correo'] > 0): ?>
                                <span class="envio-tipo-aviso">
                                    <?= (int)$c['sin_correo'] ?> sin correo: no recibirán la invitación
                                </span>
                            <?php endif; ?>
                            <?php if (($c['escribe_a'] ?? '') === 'representante'): ?>
                                <span class="envio-tipo-nota">El correo va al representante</span>
                            <?php endif; ?>
                        </span>
                    </label>
                <?php $primero = false; endforeach; ?>
            </div>
        </div>

        <div class="card">
            <h3>Paso 2 · ¿A cuántos?</h3>

            <div class="form-group form-check">
                <label>
                    <input type="radio" name="alcance" value="todos" checked>
                    <strong>A todos</strong> los del grupo elegido que tengan correo registrado
                </label>
            </div>

            <div class="form-group form-check">
                <label>
                    <input type="radio" name="alcance" value="seleccion">
                    <strong>Solo a algunos</strong> elegidos uno por uno
                </label>
            </div>

            <div id="bloqueSeleccion" class="envio-seleccion" hidden>
                <button type="button" class="btn btn-secundario" id="btnElegir">🔍 Elegir destinatarios</button>
                <span class="envio-contador" id="contadorSeleccion">Ningún destinatario elegido</span>
            </div>

            <p class="texto-mutado" style="margin-bottom:0;">
                Máximo <?= $maximo ?> correos por envío. Si necesita más, divida el envío en tandas.
            </p>
        </div>

        <div class="card">
            <h3>Paso 3 · Enviar</h3>
            <p>
                Cada persona recibirá un correo con el enlace de consentimiento
                <strong>con verificación</strong> de su tipo, con su documento precargado. Al abrirlo se
                le enviará un código a ese mismo correo para confirmar su identidad.
            </p>
            <p class="texto-mutado">
                El envío no modifica ningún dato: solo invita. Puede repetirlo cuantas veces haga falta.
            </p>

            <button type="submit" class="btn btn-primario" id="btnEnviar">📨 Enviar invitaciones</button>
        </div>
    </form>

    <!-- ==================== Subventana de selección ==================== -->
    <div class="modal-fondo" id="modalDestinatarios" hidden>
        <div class="modal-caja" role="dialog" aria-modal="true" aria-labelledby="tituloModal">
            <div class="modal-cabecera">
                <h3 id="tituloModal">Elegir destinatarios</h3>
                <button type="button" class="modal-cerrar" id="btnCerrarModal" aria-label="Cerrar">&times;</button>
            </div>

            <div class="modal-filtros">
                <input type="search" id="buscarDestinatario" placeholder="Nombre, apellido o identificación...">
                <label class="modal-check">
                    <input type="checkbox" id="soloConCorreo" checked> Solo los que tienen correo
                </label>
            </div>

            <div class="modal-cuerpo">
                <div class="tabla-wrap">
                    <table class="tabla-datos">
                        <thead>
                        <tr>
                            <th style="width:36px;"><input type="checkbox" id="marcarPagina" title="Marcar los de esta página"></th>
                            <th>Identificación</th>
                            <th>Nombre</th>
                            <th>Correo al que se enviará</th>
                        </tr>
                        </thead>
                        <tbody id="cuerpoDestinatarios">
                            <tr><td colspan="4" class="tabla-vacia">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="modal-pie">
                <div class="modal-paginacion">
                    <button type="button" class="btn btn-sm btn-secundario" id="paginaAnterior">‹ Anterior</button>
                    <span id="infoPagina" class="texto-mutado">—</span>
                    <button type="button" class="btn btn-sm btn-secundario" id="paginaSiguiente">Siguiente ›</button>
                </div>
                <div class="modal-acciones">
                    <span class="envio-contador" id="contadorModal">0 elegidos</span>
                    <button type="button" class="btn btn-secundario" id="btnLimpiar">Quitar todos</button>
                    <button type="button" class="btn btn-primario" id="btnAceptar">Aceptar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        'use strict';

        var seleccion = {};          // PersonaId -> { nombre, identificacion }
        var pagina    = 1;
        var totalPag  = 1;

        var forma        = document.getElementById('formEnvio');
        var modal        = document.getElementById('modalDestinatarios');
        var cuerpo       = document.getElementById('cuerpoDestinatarios');
        var buscar       = document.getElementById('buscarDestinatario');
        var soloCorreo   = document.getElementById('soloConCorreo');
        var bloqueSel    = document.getElementById('bloqueSeleccion');
        var contador     = document.getElementById('contadorSeleccion');
        var contadorModal= document.getElementById('contadorModal');
        var infoPagina   = document.getElementById('infoPagina');
        var campoPersonas= document.getElementById('personasSeleccionadas');
        var confirmado   = document.getElementById('confirmado');
        var temporizador = null;

        function tipoElegido() {
            var r = forma.querySelector('input[name=tipo]:checked');
            return r ? r.value : '';
        }
        function etiquetaTipo() {
            var r = forma.querySelector('input[name=tipo]:checked');
            return r ? r.getAttribute('data-etiqueta') : '';
        }
        function conCorreoDelTipo() {
            var r = forma.querySelector('input[name=tipo]:checked');
            return r ? parseInt(r.getAttribute('data-con-correo'), 10) || 0 : 0;
        }
        function esSeleccion() {
            var r = forma.querySelector('input[name=alcance]:checked');
            return r && r.value === 'seleccion';
        }
        function cuantos() { return Object.keys(seleccion).length; }

        function pintarContadores() {
            var n = cuantos();
            contador.textContent = n === 0
                ? 'Ningún destinatario elegido'
                : n + ' destinatario(s) elegido(s)';
            contadorModal.textContent = n + ' elegidos';
            campoPersonas.value = Object.keys(seleccion).join(',');
        }

        /* --- Alcance: mostrar u ocultar el bloque de selección --- */
        forma.querySelectorAll('input[name=alcance]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                bloqueSel.hidden = !esSeleccion();
            });
        });

        /* --- Cambiar de tipo vacía la selección: son personas distintas --- */
        forma.querySelectorAll('input[name=tipo]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                seleccion = {};
                pintarContadores();
            });
        });

        /* --- Subventana --- */
        function abrirModal() {
            if (!tipoElegido()) { return; }
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
            buscar.value = '';
            pagina = 1;
            cargar();
            buscar.focus();
        }
        function cerrarModal() {
            modal.hidden = true;
            document.body.style.overflow = '';
        }

        document.getElementById('btnElegir').addEventListener('click', abrirModal);
        document.getElementById('btnCerrarModal').addEventListener('click', cerrarModal);
        document.getElementById('btnAceptar').addEventListener('click', cerrarModal);
        modal.addEventListener('click', function (evento) {
            if (evento.target === modal) { cerrarModal(); }
        });
        document.addEventListener('keydown', function (evento) {
            if (evento.key === 'Escape' && !modal.hidden) { cerrarModal(); }
        });

        document.getElementById('btnLimpiar').addEventListener('click', function () {
            seleccion = {};
            pintarContadores();
            cuerpo.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
        });

        /* --- Carga de la página de resultados --- */
        function cargar() {
            cuerpo.innerHTML = '<tr><td colspan="4" class="tabla-vacia">Cargando…</td></tr>';

            var url = '../ajax/buscar_destinatarios.php'
                    + '?tipo=' + encodeURIComponent(tipoElegido())
                    + '&q=' + encodeURIComponent(buscar.value.trim())
                    + '&solo_con_correo=' + (soloCorreo.checked ? 1 : 0)
                    + '&pagina=' + pagina;

            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    if (!r.ok) {
                        cuerpo.innerHTML = '<tr><td colspan="4" class="tabla-vacia">'
                            + textoPlano(r.error || 'No se pudo consultar.') + '</td></tr>';
                        return;
                    }
                    pintarFilas(r.datos || [], r.meta || {});
                })
                .catch(function () {
                    cuerpo.innerHTML = '<tr><td colspan="4" class="tabla-vacia">'
                        + 'No se pudo consultar. Revise su conexión.</td></tr>';
                });
        }

        function textoPlano(valor) {
            var d = document.createElement('div');
            d.textContent = valor;
            return d.innerHTML;
        }

        function pintarFilas(filas, meta) {
            totalPag = parseInt(meta.total_paginas, 10) || 1;
            infoPagina.textContent = 'Página ' + (parseInt(meta.pagina, 10) || 1)
                                   + ' de ' + totalPag
                                   + ' · ' + (parseInt(meta.total, 10) || 0) + ' encontrados';

            if (!filas.length) {
                cuerpo.innerHTML = '<tr><td colspan="4" class="tabla-vacia">Sin resultados.</td></tr>';
                document.getElementById('marcarPagina').checked = false;
                return;
            }

            var html = '';
            filas.forEach(function (f) {
                var nombre = ((f.Apellidos || '') + ' ' + (f.Nombres || '')).trim();
                var marcado = seleccion[f.PersonaId] ? ' checked' : '';
                var correo = f.TieneCorreo
                    ? textoPlano(f.Destinatario)
                    : '<span class="texto-mutado">sin correo registrado</span>';

                /* En estudiantes el correo no es del alumno sino de quien lo
                   representa: se dice de quién es, para que nadie dude. */
                if (f.TieneCorreo && (f.Representante || '').trim() !== '') {
                    correo += '<div class="texto-mutado" style="font-size:.82rem;">'
                            + 'Representante: ' + textoPlano(f.Representante) + '</div>';
                }

                html += '<tr class="' + (f.TieneCorreo ? '' : 'fila-sin-correo') + '">'
                     +   '<td><input type="checkbox" data-id="' + f.PersonaId + '"'
                     +       ' data-nombre="' + textoPlano(nombre) + '"'
                     +       (f.TieneCorreo ? '' : ' disabled title="Sin correo registrado"')
                     +       marcado + '></td>'
                     +   '<td>' + textoPlano(f.Identificacion || '') + '</td>'
                     +   '<td>' + textoPlano(nombre) + '</td>'
                     +   '<td>' + correo + '</td>'
                     + '</tr>';
            });
            cuerpo.innerHTML = html;

            cuerpo.querySelectorAll('input[type=checkbox]').forEach(function (c) {
                c.addEventListener('change', function () {
                    var id = c.getAttribute('data-id');
                    if (c.checked) {
                        seleccion[id] = c.getAttribute('data-nombre');
                    } else {
                        delete seleccion[id];
                    }
                    pintarContadores();
                });
            });

            var todos = Array.prototype.slice.call(cuerpo.querySelectorAll('input[type=checkbox]:not([disabled])'));
            document.getElementById('marcarPagina').checked =
                todos.length > 0 && todos.every(function (c) { return c.checked; });
        }

        /* --- Filtros y paginación --- */
        buscar.addEventListener('input', function () {
            window.clearTimeout(temporizador);
            temporizador = window.setTimeout(function () { pagina = 1; cargar(); }, 300);
        });
        soloCorreo.addEventListener('change', function () { pagina = 1; cargar(); });

        document.getElementById('paginaAnterior').addEventListener('click', function () {
            if (pagina > 1) { pagina--; cargar(); }
        });
        document.getElementById('paginaSiguiente').addEventListener('click', function () {
            if (pagina < totalPag) { pagina++; cargar(); }
        });

        document.getElementById('marcarPagina').addEventListener('change', function () {
            var marcar = this.checked;
            cuerpo.querySelectorAll('input[type=checkbox]:not([disabled])').forEach(function (c) {
                c.checked = marcar;
                var id = c.getAttribute('data-id');
                if (marcar) {
                    seleccion[id] = c.getAttribute('data-nombre');
                } else {
                    delete seleccion[id];
                }
            });
            pintarContadores();
        });

        /* --- Confirmación antes de enviar --- */
        forma.addEventListener('submit', function (evento) {
            if (confirmado.value === '1') { return; }

            var cuantosVan = esSeleccion() ? cuantos() : conCorreoDelTipo();

            if (esSeleccion() && cuantosVan === 0) {
                evento.preventDefault();
                window.alert('No eligió ningún destinatario. Pulse «Elegir destinatarios».');
                return;
            }
            if (cuantosVan === 0) {
                evento.preventDefault();
                window.alert('No hay nadie con correo registrado en ese grupo.');
                return;
            }

            var texto = 'Se enviarán ' + cuantosVan + ' invitación(es) a ' + etiquetaTipo() + '.\n\n'
                      + 'Cada persona recibirá el enlace de consentimiento con su documento precargado.'
                      + '\n\n¿Desea continuar?';

            if (!window.confirm(texto)) {
                evento.preventDefault();
                return;
            }

            confirmado.value = '1';
            var boton = document.getElementById('btnEnviar');
            boton.textContent = 'Enviando…';
            // El botón se deshabilita en el siguiente ciclo, para que su valor
            // viaje con el formulario.
            window.setTimeout(function () { boton.disabled = true; }, 0);
        });

        pintarContadores();
    })();
    </script>

<?php endif; ?>

<style>
/* --- Elección del grupo --- */
.envio-tipos { display: flex; flex-wrap: wrap; gap: 12px; }
.envio-tipo { flex: 1 1 220px; cursor: pointer; }
.envio-tipo input { position: absolute; opacity: 0; }
.envio-tipo-cuerpo {
    display: block; border: 2px solid #e6e9f0; border-radius: 10px;
    padding: 14px 16px; background: #fff; transition: border-color .15s, background .15s;
}
.envio-tipo input:checked + .envio-tipo-cuerpo { border-color: #1f4e79; background: #f0f5fb; }
.envio-tipo input:focus-visible + .envio-tipo-cuerpo { outline: 2px solid #1f4e79; outline-offset: 2px; }
.envio-tipo-icono { font-size: 22px; }
.envio-tipo-nombre { display: block; font-weight: 700; margin-top: 4px; }
.envio-tipo-dato { display: block; font-size: 13px; color: #5b6b7f; margin-top: 4px; }
.envio-tipo-aviso { display: block; font-size: 12px; color: #8a5a00; margin-top: 4px; }
.envio-tipo-nota { display: block; font-size: 12px; color: #5b7391; margin-top: 2px; font-style: italic; }

.envio-seleccion { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 10px 0 14px; }
.envio-contador { font-size: 13px; font-weight: 700; color: #1f4e79; }

/* --- Subventana de selección --- */
.modal-fondo {
    position: fixed; inset: 0; background: rgba(20, 22, 28, .55);
    display: flex; align-items: center; justify-content: center; padding: 16px; z-index: 1000;
}
.modal-fondo[hidden] { display: none; }
.modal-caja {
    background: #fff; border-radius: 12px; width: 100%; max-width: 780px;
    max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;
    box-shadow: 0 18px 50px rgba(0,0,0,.25);
}
.modal-cabecera {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid #e6e9f0;
}
.modal-cabecera h3 { margin: 0; }
.modal-cerrar { background: none; border: 0; font-size: 26px; line-height: 1; cursor: pointer; color: #667085; }
.modal-filtros { display: flex; gap: 12px; align-items: center; padding: 12px 20px; flex-wrap: wrap; }
.modal-filtros input[type=search] { flex: 1 1 260px; padding: 9px 12px; border: 1px solid #cfd8e3; border-radius: 8px; }
.modal-check { font-size: 13px; color: #5b6b7f; white-space: nowrap; }
.modal-cuerpo { overflow-y: auto; padding: 0 20px; flex: 1; }
.modal-pie {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 14px 20px; border-top: 1px solid #e6e9f0; flex-wrap: wrap;
}
.modal-paginacion, .modal-acciones { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.fila-sin-correo { opacity: .55; }
</style>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
