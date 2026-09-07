<?php
// consultas/buscar_persona.php - Búsqueda de persona con vista 360°
// Los datos provienen de la API REST: /api/consultas/buscar-persona
//
// El detalle NO se dibuja debajo del listado: se abre como una subpantalla
// (modal) sobre él, con un botón de cerrar que devuelve al listado. Todo el
// mecanismo son enlaces —el que abre lleva `id`, el que cierra lo quita—, de
// modo que la pantalla funciona igual sin JavaScript y el botón «atrás» del
// navegador hace lo que el usuario espera.
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('consulta_buscar_persona');
$institucionId = institucionActual();

$buscar   = trim($_GET['q'] ?? '');
$idPedido = (int)($_GET['id'] ?? 0);   // el que llega en la URL: es quien abre la ficha

$resultados = [];
$personaDetalle = null;
$empleadoInfo = $estudianteInfo = $proveedorInfo = $usuarioInfo = null;
$consentimientosPersona = [];

if ($buscar !== '' || $idPedido > 0) {
    $respuesta = apiGet('consultas/buscar-persona', ['q' => $buscar, 'id' => $idPedido]);
    $datos     = apiDatos($respuesta, []);

    $resultados = $datos['resultados'] ?? [];

    if (!empty($datos['ficha'])) {
        $ficha                  = $datos['ficha'];
        $personaDetalle         = $ficha['persona'] ?? null;
        $empleadoInfo           = $ficha['empleado'] ?? null;
        $estudianteInfo         = $ficha['estudiante'] ?? null;
        $proveedorInfo          = $ficha['proveedor'] ?? null;
        $usuarioInfo            = $ficha['usuario'] ?? null;
        $consentimientosPersona = $ficha['consentimientos'] ?? [];
    }

    if (!$respuesta['ok']) {
        flashSet('error', apiError($respuesta));
    }
}

/* La ficha se abre solo cuando el usuario pulsó «Ver detalle»; que la búsqueda
   devuelva una única coincidencia no basta para taparle el listado. */
$fichaAbierta = ($idPedido > 0 && $personaDetalle !== null);

/* Dirección del listado: es a donde vuelve el botón de cerrar. */
$urlListado = 'buscar_persona.php' . ($buscar !== '' ? '?q=' . urlencode($buscar) : '');

$pageTitle = 'Buscar Persona';
$breadcrumb = [['label' => 'Consultas', 'url' => null], ['label' => 'Buscar Persona', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🔎 Buscar Persona</h1>
        <p>Localice una persona y consulte su información relacionada (roles institucionales y consentimientos otorgados).</p>
    </div>
</div>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Nombre, apellido, identificación o email</label>
            <input type="text" name="q" value="<?= e($buscar) ?>" placeholder="Escriba para buscar..." autofocus>
        </div>
        <button type="submit" class="btn btn-primario">Buscar</button>
    </form>
</div>

<?php if (!empty($resultados)): ?>
    <div class="tabla-wrap">
        <table class="tabla-datos">
            <thead><tr><th>Identificación</th><th>Nombre</th><th>Email</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($resultados as $r): ?>
                <?php $idFila = (int)$r['PersonaId']; ?>
                <tr<?= $fichaAbierta && $idFila === $idPedido ? ' class="fila-activa"' : '' ?>>
                    <td><?= e($r['Identificacion'] ?: '—') ?></td>
                    <td><?= e(nombreCompleto($r['Nombres'], $r['Apellidos'])) ?></td>
                    <td><?= e($r['Email'] ?: '—') ?></td>
                    <td><?= badgeEstado($r['Estado']) ?></td>
                    <td class="sp-col-accion">
                        <a class="btn btn-sm btn-secundario"
                           href="buscar_persona.php?q=<?= urlencode($buscar) ?>&id=<?= $idFila ?>">Ver detalle</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($buscar !== '' && empty($resultados)): ?>
    <div class="alerta alerta-info">No se encontraron personas que coincidan con la búsqueda.</div>
<?php endif; ?>

<?php if ($idPedido > 0 && $personaDetalle === null): ?>
    <div class="alerta alerta-advertencia">
        La persona solicitada no consta en esta institución.
        <a href="<?= e($urlListado) ?>">Volver al listado</a>.
    </div>
<?php endif; ?>

<?php if ($fichaAbierta): ?>
<div class="sp-modal ficha-modal" id="fichaPersona" role="dialog" aria-modal="true"
     aria-labelledby="fichaPersonaTitulo">
    <?php /* El fondo también cierra: es un enlace, no hace falta JavaScript. */ ?>
    <a class="sp-modal__fondo" href="<?= e($urlListado) ?>" tabindex="-1" aria-hidden="true"></a>

    <div class="sp-modal__caja sp-modal__caja--ancha">
        <div class="sp-modal__cabecera">
            <h3 id="fichaPersonaTitulo">
                🧑 <?= e(nombreCompleto($personaDetalle['Nombres'], $personaDetalle['Apellidos'])) ?>
            </h3>
            <a class="sp-modal__cerrar" href="<?= e($urlListado) ?>" id="fichaPersonaCerrar"
               title="Cerrar y volver al listado" aria-label="Cerrar y volver al listado">✕</a>
        </div>

        <div class="sp-modal__cuerpo">
            <div class="ficha-identidad">
                <div class="form-row">
                    <div><span class="texto-mutado">Identificación:</span><br><strong><?= e($personaDetalle['TipoIdentificacion'] ?: '') ?> <?= e($personaDetalle['Identificacion'] ?: '—') ?></strong></div>
                    <div><span class="texto-mutado">Email:</span><br><strong><?= e($personaDetalle['Email'] ?: '—') ?></strong></div>
                    <div><span class="texto-mutado">Teléfono:</span><br><strong><?= e($personaDetalle['Telefono'] ?: '—') ?></strong></div>
                    <div><span class="texto-mutado">Estado:</span><br><?= badgeEstado($personaDetalle['Estado']) ?></div>
                </div>
                <p class="texto-mutado" style="margin:12px 0 0;">
                    Los datos personales se corrigen desde el módulo que corresponda —Empleados,
                    Estudiantes o Proveedores—: esta pantalla es solo de consulta.
                </p>
            </div>

            <div class="menu-grid">
                <div class="card">
                    <h3>👥 Rol de Empleado</h3>
                    <?php if ($empleadoInfo): ?>
                        <p><strong>Código de empleado:</strong> #<?= e((string)$empleadoInfo['EmpleadoId']) ?></p>
                        <?= badgeEstado($empleadoInfo['Estado']) ?>
                    <?php else: ?>
                        <p class="texto-mutado">Sin registro como empleado en esta institución.</p>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <h3>🎓 Rol de Estudiante</h3>
                    <?php if ($estudianteInfo): ?>
                        <p><strong>Código:</strong> <?= e($estudianteInfo['CodigoEstudiante']) ?></p>
                        <?= badgeEstado($estudianteInfo['Estado']) ?>
                    <?php else: ?>
                        <p class="texto-mutado">Sin registro como estudiante en esta institución.</p>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <h3>📦 Rol de Proveedor</h3>
                    <?php if ($proveedorInfo): ?>
                        <p><strong>Razón Social:</strong> <?= e($proveedorInfo['RazonSocial']) ?></p>
                        <p><strong>RUC:</strong> <?= e($proveedorInfo['Ruc'] ?: '—') ?></p>
                        <?= badgeEstado($proveedorInfo['Estado']) ?>
                    <?php else: ?>
                        <p class="texto-mutado">Sin registro como proveedor en esta institución.</p>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <h3>🔑 Cuenta de Usuario</h3>
                    <?php if ($usuarioInfo): ?>
                        <p><strong>Usuario:</strong> <?= e($usuarioInfo['Username']) ?></p>
                        <p><strong>Último acceso:</strong> <?= f_fecha($usuarioInfo['UltimoAcceso']) ?></p>
                        <?= badgeEstado($usuarioInfo['Estado']) ?>
                    <?php else: ?>
                        <p class="texto-mutado">Sin cuenta de usuario en esta institución.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <h3>✅ Consentimientos Otorgados (<?= count($consentimientosPersona) ?>)</h3>
                <?php if (empty($consentimientosPersona)): ?>
                    <p class="texto-mutado">Esta persona no tiene consentimientos registrados en la institución activa.</p>
                <?php else: ?>
                    <div class="tabla-wrap">
                        <table class="tabla-datos">
                            <thead><tr><th>Finalidad</th><th>Fecha</th><th>Medio</th><th>Revocación</th><th>Estado</th></tr></thead>
                            <tbody>
                            <?php foreach ($consentimientosPersona as $c): ?>
                                <tr>
                                    <td><?= e($c['FinalidadNombre'] ?: '—') ?></td>
                                    <td><?= f_fecha($c['FechaConsentimiento']) ?></td>
                                    <td><?= e($c['MedioConsentimiento'] ?: '—') ?></td>
                                    <td><?= f_fecha($c['FechaRevocacion']) ?></td>
                                    <td><?= badgeEstado($c['Estado']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="sp-modal__pie">
            <span class="sp-modal__conteo">
                Ficha de consulta &mdash; institución activa.
            </span>
            <a href="<?= e($urlListado) ?>" class="btn btn-primario">Cerrar</a>
        </div>
    </div>
</div>

<script>
/* Mejoras sobre lo que ya funciona sin JavaScript: la tecla Esc cierra, y el
   fondo de la página no se desplaza mientras la ficha está abierta. */
(function () {
    'use strict';
    var cerrar = document.getElementById('fichaPersonaCerrar');
    if (!cerrar) { return; }

    document.body.classList.add('sp-sin-scroll');
    cerrar.focus();

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' || ev.key === 'Esc') {
            window.location.href = cerrar.getAttribute('href');
        }
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
