<?php
// reportes/reporte_datos_sensibles.php - Reporte de tratamiento de datos sensibles
// Los datos provienen de la API REST: /api/reportes/datos-sensibles
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('reporte_datos_sensibles');
$institucionId = institucionActual();

$tipoDatoFiltro = (int)($_GET['tipo_dato_id'] ?? 0);

$respuesta = apiGet('reportes/datos-sensibles', ['tipo_dato_id' => $tipoDatoFiltro ?: '']);
$reporte   = apiDatos($respuesta, []);

if (!$respuesta['ok']) {
    flashSet('error', apiError($respuesta));
}

$resumenSensibles = $reporte['resumen'] ?? [];
$detalleSensibles = $reporte['detalle'] ?? [];
$tiposSensibles   = $reporte['tipos_sensibles'] ?? [];

$pageTitle = 'Reporte de Datos Sensibles';
$breadcrumb = [['label' => 'Reportes', 'url' => null], ['label' => 'Datos Sensibles', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header no-imprimir">
    <div>
        <h1>⚠️ Reporte de Datos Sensibles</h1>
        <p>Seguimiento del tratamiento de categorías especiales de datos personales (salud, biométricos, religión, etc.).</p>
    </div>
    <div class="flex-gap">
        <button onclick="window.print()" class="btn btn-secundario">🖨️ Imprimir</button>
    </div>
</div>

<?php if (empty($tiposSensibles)): ?>
    <div class="alerta alerta-info">No hay tipos de dato marcados como sensibles en el catálogo. Configúrelos en <a href="../modules/tipos_dato.php">Tipos de Dato Personal</a>.</div>
<?php else: ?>

<div class="card">
    <h3>Resumen por Tipo de Dato Sensible</h3>
    <div class="tabla-wrap">
        <table class="tabla-datos">
            <thead><tr><th>Tipo de Dato</th><th>Categoría</th><th>Autorizaciones Vigentes</th><th>Autorizaciones Históricas</th></tr></thead>
            <tbody>
            <?php foreach ($resumenSensibles as $r): ?>
                <tr>
                    <td><strong><?= e($r['Nombre']) ?></strong> <span class="badge badge-sensible">SENSIBLE</span></td>
                    <td><?= e($r['Categoria'] ?: '—') ?></td>
                    <td><span class="badge badge-info"><?= (int)$r['autorizados_vigentes'] ?></span></td>
                    <td><?= (int)$r['autorizados_total'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="flex-entre no-imprimir">
        <h3 class="mb-0">Detalle de Titulares con Autorización Vigente</h3>
        <form method="GET" class="flex-gap">
            <select name="tipo_dato_id" onchange="this.form.submit()">
                <option value="">Todos los tipos sensibles</option>
                <?php foreach ($tiposSensibles as $td): ?>
                    <option value="<?= e((string)$td['TipoDatoId']) ?>" <?= $tipoDatoFiltro == $td['TipoDatoId'] ? 'selected' : '' ?>><?= e($td['Nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div class="tabla-wrap" style="margin-top:12px;">
        <table class="tabla-datos">
            <thead><tr><th>Titular</th><th>Tipo de Dato</th><th>Finalidad</th><th>Fecha Consentimiento</th></tr></thead>
            <tbody>
            <?php if (empty($detalleSensibles)): ?>
                <tr><td colspan="4" class="tabla-vacia">No hay autorizaciones vigentes de datos sensibles.</td></tr>
            <?php endif; ?>
            <?php foreach ($detalleSensibles as $d): ?>
                <tr>
                    <td><?= e(nombreCompleto($d['Nombres'], $d['Apellidos'])) ?></td>
                    <td><span class="badge badge-sensible"><?= e($d['TipoDatoNombre']) ?></span></td>
                    <td><?= e($d['FinalidadNombre'] ?: '—') ?></td>
                    <td><?= f_fecha($d['FechaConsentimiento']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
