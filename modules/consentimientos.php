<?php
// modules/consentimientos.php - Consulta de Consentimientos (núcleo del sistema)
//
// Pantalla de SOLO LECTURA. El consentimiento lo otorga el titular desde su
// enlace público, con verificación de identidad y constancia de fecha, hora,
// dirección de origen y versión de la política aceptada; aquí se consulta lo que
// dijo, no se escribe por él.
//
// La única acción que queda es la REVOCACIÓN, reservada al SuperAdmin: deja sin
// efecto una autorización del titular y afecta a todo tratamiento que dependiera
// de ella. Queda registrada en el historial.
//
// Toda la persistencia ocurre en la API REST: /api/consentimientos
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('consentimientos');
$institucionId = institucionActual();

$accion = $_GET['accion'] ?? 'listar';
$errores = [];

/* Alta y edición retiradas: si alguien llega con ?accion=crear o ?accion=editar
   escrito a mano, se le lleva al listado con la explicación. */
if (in_array($accion, ['crear', 'editar'], true)) {
    flashSet('error', $accion === 'crear'
        ? 'El consentimiento lo otorga el titular desde su enlace; no se registra a mano.'
        : 'Un consentimiento otorgado no se modifica. Puede consultarlo o, si corresponde, revocarlo.');
    redirigir('consentimientos.php');
}

// ---------- Revocar (solo SuperAdmin) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'revocar') {
    requireAcceso('consentimientos_revocar');

    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiPost('consentimientos/' . $id . '/revocar', [
            'observacion' => trim($_POST['observacion'] ?? ''),
        ]);
        flashSet(
            $respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok'] ? 'Consentimiento revocado correctamente.' : apiError($respuesta)
        );
    }
    redirigir('consentimientos.php');
}

// ---------- Datos para edición ----------
$registroEditar = null;
$tiposAutorizadosActuales = [];
if ($accion === 'editar' || $accion === 'ver') {
    $respuesta = apiGet('consentimientos/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('consentimientos.php'); }
    $tiposAutorizadosActuales = $registroEditar['tipos_autorizados'] ?? [];
}

// ---------- Catálogos para los formularios (una sola llamada) ----------
// Las personas ya no se cargan todas: se eligen en la subpantalla de búsqueda.
$catalogos = apiDatos(apiGet('consentimientos/catalogos'), []);
$finalidadesDisponibles = $catalogos['finalidades'] ?? [];
$tiposDatoDisponibles   = $catalogos['tipos_dato'] ?? [];

// ---------- Listado con filtros ----------
$buscar = trim($_GET['q'] ?? '');
$filtroEstado = $_GET['estado'] ?? '';
$listado = apiGet('consentimientos', [
    'q'      => $buscar,
    'estado' => in_array($filtroEstado, ['ACTIVO', 'INACTIVO'], true) ? $filtroEstado : '',
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Consentimientos';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Consentimientos', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>✅ Consentimientos</h1>
        <p>Consulta del consentimiento otorgado por los titulares para el tratamiento de sus datos personales.</p>
    </div>
</div>

<div class="alerta alerta-info">
    Esta pantalla es de <strong>consulta</strong>. El consentimiento lo otorga el titular desde su
    enlace, que confirma su identidad y deja constancia de la fecha, la hora, la dirección de origen y
    la versión de la política aceptada. Para pedirlo, use
    <strong><a href="envio_masivo.php">Envío Masivo de Invitaciones</a></strong>.
    <?php if (puedeAcceder('consentimientos_revocar')): ?>
        La <strong>revocación</strong> está disponible para su rol y queda registrada en el historial.
    <?php endif; ?>
</div>

<?php if ($accion === 'ver'): ?>
    <?php
    $repNombre = trim((string)($registroEditar['RepNombres'] ?? '') . ' ' . (string)($registroEditar['RepApellidos'] ?? ''));
    /** Nombre de cada tipo de dato autorizado, para no mostrar solo los códigos. */
    $nombreTipo = [];
    foreach ($tiposDatoDisponibles as $td) {
        $nombreTipo[(int)$td['TipoDatoId']] = $td;
    }
    ?>
    <div class="card">
        <?php encabezadoFormulario('Consentimiento otorgado', 'consentimientos.php'); ?>

        <div class="tabla-wrap">
            <table class="tabla-datos">
                <tbody>
                <tr>
                    <th style="width:32%;">Titular</th>
                    <td><strong><?= e(nombreCompleto($registroEditar['Nombres'] ?? '', $registroEditar['Apellidos'] ?? '')) ?></strong></td>
                </tr>
                <tr>
                    <th>Finalidad del tratamiento</th>
                    <td><?= e($registroEditar['FinalidadNombre'] ?? '—') ?></td>
                </tr>
                <tr>
                    <th>Representante</th>
                    <td><?= $repNombre !== '' ? e($repNombre) : '—' ?></td>
                </tr>
                <tr>
                    <th>Fecha y hora</th>
                    <td><?= f_fecha($registroEditar['FechaConsentimiento'] ?? null) ?></td>
                </tr>
                <tr>
                    <th>Medio</th>
                    <td><?= e($registroEditar['MedioConsentimiento'] ?: '—') ?></td>
                </tr>
                <tr>
                    <th>Versión de la política aceptada</th>
                    <td><?= e($registroEditar['VersionPolitica'] ?: '—') ?></td>
                </tr>
                <tr>
                    <th>Dirección de origen</th>
                    <td><?= e($registroEditar['IpOrigen'] ?: '—') ?></td>
                </tr>
                <tr>
                    <th>Estado</th>
                    <td>
                        <?= badgeEstado($registroEditar['Estado'] ?? '') ?>
                        <?php if (!empty($registroEditar['FechaRevocacion'])): ?>
                            <span class="texto-mutado">
                                Revocado el <?= f_fecha($registroEditar['FechaRevocacion']) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Tipos de dato autorizados</th>
                    <td>
                        <?php if (empty($tiposAutorizadosActuales)): ?>
                            <span class="texto-mutado">Ninguno registrado</span>
                        <?php else: ?>
                            <ul class="lista-simple">
                                <?php foreach ($tiposAutorizadosActuales as $tid): ?>
                                    <?php $td = $nombreTipo[(int)$tid] ?? null; ?>
                                    <li>
                                        <?= e($td['Nombre'] ?? ('Tipo #' . (int)$tid)) ?>
                                        <?php if (($td['EsSensible'] ?? 'NO') === 'SI'): ?>
                                            <span class="badge badge-sensible">SENSIBLE</span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <div class="flex-gap" style="margin-top:14px;">
            <?php if (puedeAcceder('consulta_historial')): ?>
                <a class="btn btn-secundario"
                   href="../consultas/historial_consentimientos.php?consentimiento_id=<?= e((string)$registroEditar['ConsentimientoId']) ?>">
                    Ver historial
                </a>
            <?php endif; ?>
            <?php if (puedeAcceder('consentimientos_revocar') && ($registroEditar['Estado'] ?? '') === 'ACTIVO'): ?>
                <form method="POST" action="consentimientos.php?accion=revocar"
                      onsubmit="return confirm('¿Confirma la revocación de este consentimiento? Queda registrada en el historial.');"
                      style="display:inline;">
                    <?= csrfCampo() ?>
                    <input type="hidden" name="id" value="<?= e((string)$registroEditar['ConsentimientoId']) ?>">
                    <input type="hidden" name="observacion" value="Revocado desde la ficha del consentimiento.">
                    <button type="submit" class="btn btn-peligro">Revocar consentimiento</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Buscar</label>
            <input type="text" name="q" placeholder="Persona o finalidad..." value="<?= e($buscar) ?>">
        </div>
        <div class="form-group">
            <label>Estado</label>
            <select name="estado">
                <option value="">Todos</option>
                <option value="ACTIVO" <?= $filtroEstado === 'ACTIVO' ? 'selected' : '' ?>>Activo</option>
                <option value="INACTIVO" <?= $filtroEstado === 'INACTIVO' ? 'selected' : '' ?>>Revocado</option>
            </select>
        </div>
        <button type="submit" class="btn btn-secundario">Filtrar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead><tr><th>Titular</th><th>Finalidad</th><th>Fecha</th><th>Medio</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr></thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="6" class="tabla-vacia">No se encontraron consentimientos registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td><strong><?= e(nombreCompleto($r['Nombres'], $r['Apellidos'])) ?></strong></td>
                <td><?= e($r['FinalidadNombre'] ?: '—') ?></td>
                <td><?= f_fecha($r['FechaConsentimiento']) ?></td>
                <td><?= e($r['MedioConsentimiento'] ?: '—') ?></td>
                <td><?= badgeEstado($r['Estado']) ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="consentimientos.php?accion=ver&id=<?= e((string)$r['ConsentimientoId']) ?>">Ver</a>
                        <?php if (puedeAcceder('consulta_historial')): ?>
                            <a class="btn btn-sm btn-secundario" href="../consultas/historial_consentimientos.php?consentimiento_id=<?= e((string)$r['ConsentimientoId']) ?>">Historial</a>
                        <?php endif; ?>
                        <?php /* Revocar es la única acción que queda, y solo para el SuperAdmin.
                                 Reactivar se retiró: devolver la vigencia a un consentimiento
                                 revocado es una decisión del titular, y el camino es que vuelva
                                 a otorgarlo desde su enlace, con su verificación y constancia. */ ?>
                        <?php if (puedeAcceder('consentimientos_revocar') && $r['Estado'] === 'ACTIVO'): ?>
                            <form method="POST" action="consentimientos.php?accion=revocar" onsubmit="return confirm('¿Confirma la revocación de este consentimiento?');" style="display:inline;">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="id" value="<?= e((string)$r['ConsentimientoId']) ?>">
                                <button type="submit" class="btn btn-sm btn-peligro">Revocar</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php renderPaginacion($numPagina, $totalPaginas); ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
