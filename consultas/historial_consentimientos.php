<?php
// consultas/historial_consentimientos.php - Bitácora de auditoría de consentimientos
// Los datos provienen de la API REST: /api/consultas/historial
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('consulta_historial');
$institucionId = institucionActual();

$consentimientoIdFiltro = (int)($_GET['consentimiento_id'] ?? 0);
$fechaDesde = trim($_GET['desde'] ?? '');
$fechaHasta = trim($_GET['hasta'] ?? '');
$buscar     = trim($_GET['q'] ?? '');

$listado = apiGet('consultas/historial', [
    'consentimiento_id' => $consentimientoIdFiltro ?: '',
    'desde'             => $fechaDesde,
    'hasta'             => $fechaHasta,
    'q'                 => $buscar,
    'pagina'            => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Historial de Consentimientos';
$breadcrumb = [['label' => 'Consultas', 'url' => null], ['label' => 'Historial de Consentimientos', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🕒 Historial de Consentimientos</h1>
        <p>Bitácora de auditoría: creación, modificación, revocación y reactivación de consentimientos.</p>
    </div>
</div>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Persona o usuario</label>
            <input type="text" name="q" value="<?= e($buscar) ?>" placeholder="Buscar...">
        </div>
        <div class="form-group">
            <label>Desde</label>
            <input type="date" name="desde" value="<?= e($fechaDesde) ?>">
        </div>
        <div class="form-group">
            <label>Hasta</label>
            <input type="date" name="hasta" value="<?= e($fechaHasta) ?>">
        </div>
        <?php if ($consentimientoIdFiltro): ?>
            <input type="hidden" name="consentimiento_id" value="<?= e((string)$consentimientoIdFiltro) ?>">
        <?php endif; ?>
        <button type="submit" class="btn btn-primario">Filtrar</button>
        <a href="historial_consentimientos.php" class="btn btn-secundario">Limpiar</a>
    </form>
</div>

<div class="card">
    <?php if (empty($registros)): ?>
        <p class="texto-mutado">No se encontraron movimientos para los filtros seleccionados.</p>
    <?php else: ?>
        <ul class="timeline">
            <?php foreach ($registros as $h): ?>
                <li>
                    <div class="t-fecha"><?= f_fecha($h['FechaAccion']) ?> &middot; por <?= e($h['Username'] ?? 'sistema') ?> &middot; IP <?= e($h['IpOrigen'] ?: '—') ?></div>
                    <div class="t-titulo">
                        <?= e($h['Accion']) ?> &mdash; <?= e(nombreCompleto($h['Nombres'] ?? null, $h['Apellidos'] ?? null)) ?>
                        <?php if (!empty($h['FinalidadNombre'])): ?> <span class="texto-mutado">(<?= e($h['FinalidadNombre']) ?>)</span><?php endif; ?>
                    </div>
                    <div class="t-detalle">
                        Estado: <?= badgeEstado($h['EstadoAnterior']) ?> &rarr; <?= badgeEstado($h['EstadoNuevo']) ?>
                        <?php if (!empty($h['Observacion'])): ?><br><?= e($h['Observacion']) ?><?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php renderPaginacion($numPagina, $totalPaginas); ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
