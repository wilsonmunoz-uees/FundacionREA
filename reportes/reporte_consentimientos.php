<?php
// reportes/reporte_consentimientos.php - Reporte de consentimientos por finalidad y estado
// Los datos provienen de la API REST: /api/reportes/consentimientos
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('reporte_consentimientos');
$institucionId = institucionActual();

$respuesta = apiGet('reportes/consentimientos');
$reporte   = apiDatos($respuesta, []);

if (!$respuesta['ok']) {
    flashSet('error', apiError($respuesta));
}

$porFinalidad = $reporte['por_finalidad'] ?? [];
$porMedio     = $reporte['por_medio'] ?? [];
$porMes       = $reporte['por_mes'] ?? [];
$totales      = $reporte['totales'] ?? ['t' => 0, 'a' => 0, 'r' => 0];

$maxFinalidad = max(1, (int)($reporte['maximos']['finalidad'] ?? 1));
$maxMedio     = max(1, (int)($reporte['maximos']['medio'] ?? 1));
$maxMes       = max(1, (int)($reporte['maximos']['mes'] ?? 1));

$pageTitle = 'Reporte de Consentimientos';
$breadcrumb = [['label' => 'Reportes', 'url' => null], ['label' => 'Consentimientos por Finalidad', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header no-imprimir">
    <div>
        <h1>📈 Reporte de Consentimientos</h1>
        <p>Distribución de consentimientos por finalidad, medio y evolución mensual.</p>
    </div>
    <div class="flex-gap">
        <button onclick="window.print()" class="btn btn-secundario">🖨️ Imprimir</button>
        <a href="exportar_csv.php?entidad=consentimientos" class="btn btn-primario">⬇️ Exportar CSV</a>
    </div>
</div>

<div class="kpi-grid">
    <div class="kpi-card"><div class="kpi-valor"><?= (int)($totales['t'] ?? 0) ?></div><div class="kpi-label">Total Consentimientos</div></div>
    <div class="kpi-card kpi-alt-2"><div class="kpi-valor"><?= (int)($totales['a'] ?? 0) ?></div><div class="kpi-label">Vigentes</div></div>
    <div class="kpi-card kpi-alt-3"><div class="kpi-valor"><?= (int)($totales['r'] ?? 0) ?></div><div class="kpi-label">Revocados</div></div>
</div>

<div class="card">
    <h3>Consentimientos por Finalidad</h3>
    <?php if (empty(array_filter($porFinalidad, fn($r) => $r['total'] > 0))): ?>
        <p class="texto-mutado">Aún no hay datos suficientes para este reporte.</p>
    <?php else: ?>
        <?php foreach ($porFinalidad as $r): if ($r['total'] == 0) continue; ?>
            <div style="margin-bottom:10px;">
                <div class="flex-entre"><strong><?= e($r['Nombre']) ?></strong><span class="texto-mutado"><?= (int)$r['total'] ?> (✔ <?= (int)$r['activos'] ?> / ✖ <?= (int)$r['revocados'] ?>)</span></div>
                <div style="background:var(--rea-gris-100);border-radius:6px;overflow:hidden;height:12px;">
                    <div style="background:var(--rea-rojo);height:100%;width:<?= round($r['total']/$maxFinalidad*100) ?>%;"></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="form-row" style="align-items:stretch;">
    <div class="card" style="flex:1 1 380px;">
        <h3>Consentimientos por Medio</h3>
        <?php if (empty($porMedio)): ?>
            <p class="texto-mutado">Sin datos.</p>
        <?php else: ?>
            <?php foreach ($porMedio as $r): ?>
                <div style="margin-bottom:10px;">
                    <div class="flex-entre"><span><?= e($r['medio']) ?></span><span class="texto-mutado"><?= (int)$r['total'] ?></span></div>
                    <div style="background:var(--rea-gris-100);border-radius:6px;overflow:hidden;height:10px;">
                        <div style="background:var(--color-info);height:100%;width:<?= round($r['total']/$maxMedio*100) ?>%;"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="card" style="flex:1 1 380px;">
        <h3>Evolución Mensual (últimos 12 meses)</h3>
        <?php if (empty($porMes)): ?>
            <p class="texto-mutado">Sin datos suficientes.</p>
        <?php else: ?>
            <div class="flex-gap" style="align-items:flex-end;height:140px;">
                <?php foreach ($porMes as $r): ?>
                    <div style="text-align:center;flex:1;">
                        <div style="background:var(--rea-rojo);border-radius:4px 4px 0 0;width:100%;height:<?= max(6, round($r['total']/$maxMes*110)) ?>px;margin:0 auto;"></div>
                        <div class="texto-mutado" style="font-size:.68rem;margin-top:4px;"><?= e(substr($r['periodo'],5,2)) ?>/<?= e(substr($r['periodo'],2,2)) ?></div>
                        <div style="font-size:.72rem;font-weight:600;"><?= (int)$r['total'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
