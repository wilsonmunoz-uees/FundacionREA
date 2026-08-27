<?php
// consultas/consentimientos_vigentes.php - Consulta de consentimientos vigentes vs. revocados
// Los datos provienen de la API REST: /api/consultas/consentimientos-vigentes
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('consulta_vigentes');
$institucionId = institucionActual();

$finalidadFiltro = (int)($_GET['finalidad_id'] ?? 0);
$tipoDatoFiltro  = (int)($_GET['tipo_dato_id'] ?? 0);
$estadoFiltro    = $_GET['estado'] ?? 'ACTIVO';
$buscar          = trim($_GET['q'] ?? '');

$listado = apiGet('consultas/consentimientos-vigentes', [
    'estado'       => $estadoFiltro,
    'finalidad_id' => $finalidadFiltro ?: '',
    'tipo_dato_id' => $tipoDatoFiltro ?: '',
    'q'            => $buscar,
    'pagina'       => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

// Catálogos para los filtros (vienen en el bloque meta de la misma respuesta)
$finalidades = apiMeta($listado, 'finalidades', []);
$tiposDato   = apiMeta($listado, 'tipos_dato', []);

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Consentimientos Vigentes y Revocados';
$breadcrumb = [['label' => 'Consultas', 'url' => null], ['label' => 'Vigentes / Revocados', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>📋 Consentimientos Vigentes / Revocados</h1>
        <p>Consulte el estado de los consentimientos por finalidad y tipo de dato personal.</p>
    </div>
</div>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Persona</label>
            <input type="text" name="q" value="<?= e($buscar) ?>" placeholder="Nombre, apellido, identificación o email...">
        </div>
        <div class="form-group">
            <label>Finalidad</label>
            <select name="finalidad_id">
                <option value="">Todas</option>
                <?php foreach ($finalidades as $f): ?>
                    <option value="<?= e((string)$f['FinalidadId']) ?>" <?= $finalidadFiltro == $f['FinalidadId'] ? 'selected' : '' ?>><?= e($f['Nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Tipo de Dato</label>
            <select name="tipo_dato_id">
                <option value="">Todos</option>
                <?php foreach ($tiposDato as $td): ?>
                    <option value="<?= e((string)$td['TipoDatoId']) ?>" <?= $tipoDatoFiltro == $td['TipoDatoId'] ? 'selected' : '' ?>><?= e($td['Nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Estado</label>
            <select name="estado">
                <option value="ACTIVO" <?= $estadoFiltro === 'ACTIVO' ? 'selected' : '' ?>>Vigentes</option>
                <option value="INACTIVO" <?= $estadoFiltro === 'INACTIVO' ? 'selected' : '' ?>>Revocados</option>
                <option value="" <?= $estadoFiltro === '' ? 'selected' : '' ?>>Todos</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primario">Consultar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead><tr><th>Titular</th><th>Finalidad</th><th>Fecha Consentimiento</th><th>Fecha Revocación</th><th>Medio</th><th>Estado</th></tr></thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="6" class="tabla-vacia">No se encontraron consentimientos para los filtros seleccionados.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td><strong><?= e(nombreCompleto($r['Nombres'], $r['Apellidos'])) ?></strong></td>
                <td><?= e($r['FinalidadNombre'] ?: '—') ?></td>
                <td><?= f_fecha($r['FechaConsentimiento']) ?></td>
                <td><?= f_fecha($r['FechaRevocacion']) ?></td>
                <td><?= e($r['MedioConsentimiento'] ?: '—') ?></td>
                <td><?= badgeEstado($r['Estado']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php renderPaginacion($numPagina, $totalPaginas); ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
