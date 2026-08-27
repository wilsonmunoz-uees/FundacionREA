<?php
/**
 * REA Protege - Tablero Comparativo de Red Educativa (Multi-Sede)
 *
 * Vista ejecutiva global para SuperAdmin con indicadores de cumplimiento LOPDP
 * comparativos de todas las sedes / colegios de la red educativa.
 * Sin filtros (vista general consolidada para comparativa directa).
 */

define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/api_client.php';

requireRol(['SuperAdmin']);

/* ---------------------------------------------------------------------------
   Exportación a PDF
   --------------------------------------------------------------------------- */
if (isset($_GET['formato']) && $_GET['formato'] === 'pdf') {
    require_once __DIR__ . '/../includes/pdf_reporte.php';

    $apiRes = apiGet('reportes/red-educativa');
    if (!$apiRes['ok']) {
        flashSet('error', 'No se pudo generar el PDF: ' . apiError($apiRes));
        redirigir('reporte_red_educativa.php');
    }

    $datos  = apiDatos($apiRes, []);
    $kpis   = $datos['kpis_globales'] ?? [
        'total_sedes' => 0, 'poblacion_total' => 0,
        'consentimientos_total' => 0, 'cumplimiento_promedio' => 0,
    ];
    $sedes  = $datos['sedes'] ?? [];

    if (empty($sedes)) {
        flashSet('advertencia', 'No se encontraron registros para exportar.');
        redirigir('reporte_red_educativa.php');
    }

    $pdf = new PdfReporte('H');

    $pdf->cabecera([
        'logo'        => __DIR__ . '/../assets/logo.png',
        'institucion' => 'Sede Central - Red Educativa Arquidiocesana',
        'titulo'      => 'Tablero Comparativo de Red Educativa',
        'subtitulo'   => 'Consolidado General de Cumplimiento LOPDP - Todas las Sedes de la Red',
    ]);

    $pdf->pie([
        'usuario'    => ($_SESSION['username'] ?? 'sistema')
            . (!empty($_SESSION['roles']) ? ' (' . implode(', ', $_SESSION['roles']) . ')' : ''),
        'disclaimer' => 'Documento de uso directivo y confidencial. Contiene métricas consolidadas de tratamiento de datos personales '
            . 'protegidos por la Ley Orgánica de Protección de Datos Personales (LOPDP); su divulgación no autorizada está prohibida.',
    ]);

    $columnas = [
        ['clave' => 'Rank',        'titulo' => 'Rank',               'ancho' => 8,  'align' => 'C', 'estilo' => 'B'],
        ['clave' => 'Nombre',      'titulo' => 'Institución / Sede', 'ancho' => 38, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'Estudiantes', 'titulo' => 'Estudiantes',        'ancho' => 12, 'align' => 'R'],
        ['clave' => 'Empleados',   'titulo' => 'Empleados',          'ancho' => 12, 'align' => 'R'],
        ['clave' => 'Proveedores', 'titulo' => 'Proveedores',        'ancho' => 12, 'align' => 'R'],
        ['clave' => 'Poblacion',   'titulo' => 'Población Total',    'ancho' => 14, 'align' => 'R', 'estilo' => 'B'],
        ['clave' => 'Consentidos', 'titulo' => 'Consentidos',        'ancho' => 14, 'align' => 'R'],
        ['clave' => 'Pendientes',  'titulo' => 'Pendientes',         'ancho' => 14, 'align' => 'R'],
        ['clave' => 'Cumplimiento','titulo' => '% Cumpl.',           'ancho' => 12, 'align' => 'R', 'estilo' => 'B'],
    ];

    $filasPdf = [];
    foreach ($sedes as $s) {
        $filasPdf[] = [
            'Rank'         => '#' . $s['ranking'],
            'Nombre'       => $s['nombre'],
            'Estudiantes'  => number_format($s['estudiantes'], 0, ',', '.'),
            'Empleados'    => number_format($s['empleados'], 0, ',', '.'),
            'Proveedores'  => number_format($s['proveedores'], 0, ',', '.'),
            'Poblacion'    => number_format($s['poblacion'], 0, ',', '.'),
            'Consentidos'  => number_format($s['consentidos'], 0, ',', '.'),
            'Pendientes'   => number_format($s['pendientes'], 0, ',', '.'),
            'Cumplimiento' => $s['pct_cumplimiento'] . '%',
        ];
    }

    $pdf->tabla($columnas, $filasPdf);

    $pdf->parrafo(sprintf(
        'Sedes activas: %d   ·   Población total de la red: %d personas   ·   Consentimientos firmados: %d   ·   Cumplimiento promedio: %.1f%%',
        (int)$kpis['total_sedes'], (int)$kpis['poblacion_total'], (int)$kpis['consentimientos_total'], (float)$kpis['cumplimiento_promedio']
    ), 8.5, 'B', [40, 44, 52]);

    $pdf->salida('rea_red_educativa_' . date('Ymd_His') . '.pdf');
    exit;
}

/* ---------------------------------------------------------------------------
   Consulta en pantalla
   --------------------------------------------------------------------------- */
$apiRes = apiGet('reportes/red-educativa');
$datos  = apiDatos($apiRes, []);
$kpis   = $datos['kpis_globales'] ?? [
    'total_sedes' => 0, 'poblacion_total' => 0,
    'consentimientos_total' => 0, 'pendientes_total' => 0,
    'revocados_total' => 0, 'cumplimiento_promedio' => 0,
];
$sedes  = $datos['sedes'] ?? [];

if (!$apiRes['ok']) {
    flashSet('error', apiError($apiRes));
}

$urlPdf = 'reporte_red_educativa.php?formato=pdf';

$totalSedes = (int)($kpis['total_sedes'] ?? 0);
$totalPob   = (int)($kpis['poblacion_total'] ?? 0);
$totalCons  = (int)($kpis['consentimientos_total'] ?? 0);
$pctProm    = (float)($kpis['cumplimiento_promedio'] ?? 0);

$pageTitle = 'Tablero Comparativo - Red Educativa';
$breadcrumb = [['label' => 'Reportes', 'url' => null], ['label' => 'Red Educativa Multi-Sede', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header no-imprimir">
    <div>
        <h1>🏫 Tablero Comparativo de Red Educativa (Multi-Sede)</h1>
        <p>Vista ejecutiva global de cumplimiento de la LOPDP para todas las instituciones educativas pertenecientes a la red.</p>
    </div>
    <div class="flex-gap">
        <a href="<?= e($urlPdf) ?>" class="btn btn-primario" target="_blank" rel="noopener">Exportar a PDF</a>
    </div>
</div>

<!-- ================= KPIs Globales ================= -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-valor"><?= number_format($totalSedes) ?></div>
        <div class="kpi-label">Sedes Activas en Red</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-valor"><?= number_format($totalPob) ?></div>
        <div class="kpi-label">Población Objetivo Total</div>
    </div>
    <div class="kpi-card kpi-alt-2">
        <div class="kpi-valor"><?= number_format($totalCons) ?></div>
        <div class="kpi-label">Consentimientos Firmados</div>
    </div>
    <div class="kpi-card kpi-alt-3" style="<?= $pctProm < 50 ? 'border-left:4px solid var(--rea-rojo);' : '' ?>">
        <div class="kpi-valor <?= $pctProm >= 80 ? 'texto-exito' : ($pctProm < 50 ? 'texto-peligro' : '') ?>">
            <?= $pctProm ?>%
        </div>
        <div class="kpi-label">Cumplimiento Promedio Global</div>
    </div>
</div>

<!-- ================= Barra de Progreso Global ================= -->
<div class="card no-imprimir" style="margin-bottom:1.25rem;">
    <div class="flex-entre" style="margin-bottom:8px;">
        <h3 class="mb-0" style="font-size:1.05rem;">Nivel de Cobertura General de la Red</h3>
        <span class="texto-mutado" style="font-weight:600;">
            <span style="color:var(--color-exito);"><?= $pctProm ?>% Firmados (<?= number_format($totalCons) ?> de <?= number_format($totalPob) ?>)</span>
        </span>
    </div>
    <div style="background:var(--rea-gris-200);border-radius:999px;overflow:hidden;height:16px;">
        <div style="background:<?= $pctProm >= 80 ? 'var(--color-exito)' : ($pctProm >= 50 ? '#f59e0b' : 'var(--rea-rojo)') ?>;width:<?= min(100, $pctProm) ?>%;height:100%;transition:width .3s;"></div>
    </div>
</div>

<!-- ================= Tabla Comparativa de Sedes ================= -->
<div class="card">
    <div class="flex-entre">
        <h3 class="mb-0">Ranking y Comparativa por Institución Educativa</h3>
        <span class="texto-mutado"><?= count($sedes) ?> sedes evaluadas</span>
    </div>

    <div class="tabla-wrap" style="margin-top:14px;">
        <table class="tabla-datos">
            <thead>
                <tr>
                    <th style="width: 60px; text-align: center;">Rank</th>
                    <th>Institución / Sede</th>
                    <th style="text-align: right;">Estudiantes</th>
                    <th style="text-align: right;">Empleados</th>
                    <th style="text-align: right;">Proveedores</th>
                    <th style="text-align: right;">Población</th>
                    <th style="text-align: right;">Consentidos</th>
                    <th style="text-align: right;">Pendientes</th>
                    <th style="text-align: center; width: 160px;">% Cumplimiento</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sedes)): ?>
                    <tr><td colspan="9" class="tabla-vacia">No hay instituciones registradas en el sistema.</td></tr>
                <?php endif; ?>
                <?php foreach ($sedes as $s): ?>
                    <tr>
                        <td style="text-align: center; font-weight: 700;">
                            #<?= $s['ranking'] ?>
                        </td>
                        <td>
                            <strong><?= e($s['nombre']) ?></strong>
                            <div class="texto-mutado" style="font-size:0.78rem;"><?= e($s['direccion']) ?></div>
                        </td>
                        <td style="text-align: right;"><?= number_format($s['estudiantes']) ?></td>
                        <td style="text-align: right;"><?= number_format($s['empleados']) ?></td>
                        <td style="text-align: right;"><?= number_format($s['proveedores']) ?></td>
                        <td style="text-align: right;"><strong><?= number_format($s['poblacion']) ?></strong></td>
                        <td style="text-align: right;"><strong style="color:var(--color-exito);"><?= number_format($s['consentidos']) ?></strong></td>
                        <td style="text-align: right;" class="<?= $s['pendientes'] > 0 ? 'texto-peligro' : 'texto-mutado' ?>">
                            <?= number_format($s['pendientes']) ?>
                        </td>
                        <td>
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="flex:1; background:var(--rea-gris-200); border-radius:999px; height:8px; overflow:hidden;">
                                    <div style="background:<?= $s['pct_cumplimiento'] >= 80 ? 'var(--color-exito)' : ($s['pct_cumplimiento'] >= 50 ? '#f59e0b' : 'var(--rea-rojo)') ?>; height:100%; width:<?= min(100, $s['pct_cumplimiento']) ?>%;"></div>
                                </div>
                                <span style="font-weight:700; font-size:0.84rem; min-width:44px; text-align:right;">
                                    <?= $s['pct_cumplimiento'] ?>%
                                </span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
include __DIR__ . '/../includes/layout_bottom.php';
