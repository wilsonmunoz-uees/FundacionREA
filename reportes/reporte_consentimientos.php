<?php
// reportes/reporte_consentimientos.php
// Tablero General de Métricas de Consentimiento y Cumplimiento LOPDP.
// Muestra el estado consolidado de la institución: cobertura, contactabilidad,
// finalidades, canales de recolección y evolución temporal.
// Consume el endpoint /api/reportes/consentimientos y genera PDF institucional.
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('reporte_consentimientos');
$institucionId     = institucionActual();
$institucionNombre = $_SESSION['institucion_nombre'] ?? 'esta institución';
$formato           = $_GET['formato'] ?? '';

/* ---------------------------------------------------------------------------
   Exportación a PDF
   --------------------------------------------------------------------------- */
if ($formato === 'pdf') {
    require_once __DIR__ . '/../includes/pdf_reporte.php';

    $respuestaPdf = apiGet('reportes/consentimientos');
    if (!$respuestaPdf['ok']) {
        flashSet('error', 'No se pudo generar el PDF: ' . apiError($respuestaPdf));
        redirigir('reporte_consentimientos.php');
    }

    $reportePdf   = apiDatos($respuestaPdf, []);
    $poblacionPdf = $reportePdf['poblacion'] ?? [];
    $porTipoPdf   = $reportePdf['por_tipo'] ?? [];
    $porFinPdf    = $reportePdf['por_finalidad'] ?? [];
    $porMedioPdf  = $reportePdf['por_medio'] ?? [];

    if (empty($porFinPdf) && empty($porTipoPdf) && empty($poblacionPdf['total'])) {
        flashSet('advertencia', 'No se encontraron registros para exportar en la institución activa.');
        redirigir('reporte_consentimientos.php');
    }

    $pdf = new PdfReporte('H');

    $pdf->cabecera([
        'logo'        => __DIR__ . '/../assets/logo.png',
        'institucion' => $institucionNombre,
        'titulo'      => 'Reporte de Consentimientos y Cumplimiento General',
        'subtitulo'   => 'Diagnóstico consolidado de cobertura, contactabilidad, finalidades y canales de recolección',
    ]);

    $pdf->pie([
        'usuario'    => ($_SESSION['username'] ?? 'sistema')
            . (!empty($_SESSION['roles']) ? ' (' . implode(', ', $_SESSION['roles']) . ')' : ''),
        'disclaimer' => 'Documento de auditoría y control de cumplimiento. Contiene métricas estadísticas de tratamiento de datos personales '
            . 'protegidos por la LOPDP; su divulgación no autorizada está prohibida.',
    ]);

    // 1. Tarjetas KPI Superiores
    $pdf->tarjetasKpi([
        [
            'valor' => number_format((int)$poblacionPdf['total']),
            'label' => 'Población Objetivo Activa',
            'color' => [28, 31, 39],
        ],
        [
            'valor'     => number_format((int)$poblacionPdf['consentidos']) . ' (' . $poblacionPdf['pct_cobertura'] . '%)',
            'label'     => 'Consentimientos Vigentes (Firmados)',
            'color'     => [18, 115, 74],
            'borde_izq' => [18, 115, 74],
        ],
        [
            'valor'     => number_format((int)$poblacionPdf['pendientes']) . ' (' . $poblacionPdf['pct_pendientes'] . '%)',
            'label'     => 'Pendientes por Firmar',
            'color'     => (int)$poblacionPdf['pendientes'] > 0 ? [200, 16, 46] : [100, 108, 122],
            'borde_izq' => (int)$poblacionPdf['pendientes'] > 0 ? [200, 16, 46] : null,
        ],
        [
            'valor'     => number_format((int)($poblacionPdf['revocados'] ?? 0)) . ' (' . ($poblacionPdf['pct_revocados'] ?? 0) . '%)',
            'label'     => 'Consentimientos Revocados',
            'color'     => [180, 120, 10],
            'borde_izq' => [180, 120, 10],
        ],
    ]);

    // 2. Barra de Cobertura Global (100% exacta: Firmados vs Pendientes)
    $pdf->barraProgreso('Nivel de Cobertura Institucional', [
        [
            'pct'   => (float)$poblacionPdf['pct_cobertura'],
            'label' => $poblacionPdf['pct_cobertura'] . '% Firmados (' . number_format((int)$poblacionPdf['consentidos']) . ')',
            'color' => [18, 115, 74],
        ],
        [
            'pct'   => (float)$poblacionPdf['pct_pendientes'],
            'label' => $poblacionPdf['pct_pendientes'] . '% Pendientes (' . number_format((int)$poblacionPdf['pendientes']) . ')',
            'color' => [200, 16, 46],
        ],
    ]);

    // 3. Sección 1: Desglose por Tipo de Titular
    $pdf->seccionTitulo('Cobertura y Contactabilidad por Tipo de Titular', 'Estado del consentimiento y disponibilidad de correos en Estudiantes, Empleados y Proveedores.');

    $columnasTipo = [
        ['clave' => 'Tipo',        'titulo' => 'Tipo de Titular',        'ancho' => 25, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'Poblacion',   'titulo' => 'Población',              'ancho' => 12, 'align' => 'R'],
        ['clave' => 'ConCorreo',   'titulo' => 'Con Correo (%)',         'ancho' => 16, 'align' => 'R'],
        ['clave' => 'Consentidos', 'titulo' => 'Firmados (Vigentes)',    'ancho' => 14, 'align' => 'R'],
        ['clave' => 'Pendientes',  'titulo' => 'Pendientes',             'ancho' => 12, 'align' => 'R'],
        ['clave' => 'Revocados',   'titulo' => 'Revocados',              'ancho' => 10, 'align' => 'R'],
        ['clave' => 'Cumplimiento','titulo' => '% Cobertura',            'ancho' => 11, 'align' => 'R', 'estilo' => 'B'],
    ];

    $filasTipo = [];
    foreach ($porTipoPdf as $t) {
        $filasTipo[] = [
            'Tipo'         => $t['etiqueta'] ?? '—',
            'Poblacion'    => number_format((int)$t['poblacion'], 0, ',', '.'),
            'ConCorreo'    => number_format((int)$t['con_correo'], 0, ',', '.') . ' (' . $t['pct_correo'] . '%)',
            'Consentidos'  => number_format((int)$t['consentidos'], 0, ',', '.'),
            'Pendientes'   => number_format((int)$t['pendientes'], 0, ',', '.'),
            'Revocados'    => number_format((int)($t['revocados'] ?? 0), 0, ',', '.'),
            'Cumplimiento' => $t['pct_cumplimiento'] . '%',
            '_color_Consentidos'  => [18, 115, 74],
            '_color_Pendientes'   => (int)$t['pendientes'] > 0 ? [200, 16, 46] : [40, 44, 52],
            '_color_Revocados'    => (int)($t['revocados'] ?? 0) > 0 ? [180, 120, 10] : [40, 44, 52],
            '_color_Cumplimiento' => [18, 115, 74],
        ];
    }

    // Fila total
    $filasTipo[] = [
        'Tipo'         => 'TOTAL INSTITUCIONAL',
        'Poblacion'    => number_format((int)$poblacionPdf['total'], 0, ',', '.'),
        'ConCorreo'    => number_format((int)$poblacionPdf['con_correo'], 0, ',', '.') . ' (' . $poblacionPdf['pct_con_correo'] . '%)',
        'Consentidos'  => number_format((int)$poblacionPdf['consentidos'], 0, ',', '.'),
        'Pendientes'   => number_format((int)$poblacionPdf['pendientes'], 0, ',', '.'),
        'Revocados'    => number_format((int)($poblacionPdf['revocados'] ?? 0), 0, ',', '.'),
        'Cumplimiento' => $poblacionPdf['pct_cobertura'] . '%',
        '_color_Tipo'         => [200, 16, 46],
        '_color_Consentidos'  => [18, 115, 74],
        '_color_Pendientes'   => [200, 16, 46],
        '_color_Revocados'    => [180, 120, 10],
        '_color_Cumplimiento' => [18, 115, 74],
    ];

    $pdf->tabla($columnasTipo, $filasTipo);

    // 4. Sección 2: Detalle por Finalidad
    $pdf->seccionTitulo('Distribución por Finalidad del Tratamiento', 'Nivel de aceptación por finalidad declarada.');

    $columnasFin = [
        ['clave' => 'Nombre',        'titulo' => 'Finalidad Declarada', 'ancho' => 45, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'Total',         'titulo' => 'Total Registros',     'ancho' => 15, 'align' => 'R'],
        ['clave' => 'Vigentes',      'titulo' => 'Vigentes (Activos)',  'ancho' => 15, 'align' => 'R'],
        ['clave' => 'Revocados',     'titulo' => 'Revocados',           'ancho' => 15, 'align' => 'R'],
        ['clave' => 'TasaAceptacion','titulo' => '% Aceptación',        'ancho' => 10, 'align' => 'R', 'estilo' => 'B'],
    ];

    $filasFin = [];
    foreach ($porFinPdf as $f) {
        $tot  = (int)($f['total'] ?? 0);
        $act  = (int)($f['activos'] ?? 0);
        $rev  = (int)($f['revocados'] ?? 0);
        $tasa = (float)($f['tasa_aceptacion'] ?? 0);

        $filasFin[] = [
            'Nombre'         => $f['Nombre'] ?? '—',
            'Total'          => number_format($tot, 0, ',', '.'),
            'Vigentes'       => number_format($act, 0, ',', '.'),
            'Revocados'      => number_format($rev, 0, ',', '.'),
            'TasaAceptacion' => $tasa . '%',
            '_color_Vigentes' => [18, 115, 74],
            '_color_Revocados'=> $rev > 0 ? [180, 120, 10] : [40, 44, 52],
        ];
    }
    $pdf->tabla($columnasFin, $filasFin);

    // 5. Sección 3: Canales de Recolección
    $pdf->seccionTitulo('Rendimiento y Calidad por Canal / Medio', 'Distribución de consentimientos y tasas de revocatoria según el medio de recolección.');

    $columnasMed = [
        ['clave' => 'Medio',          'titulo' => 'Canal / Medio',     'ancho' => 30, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'Total',          'titulo' => 'Consentimientos',   'ancho' => 18, 'align' => 'R'],
        ['clave' => 'Porcentaje',     'titulo' => '% del Total',       'ancho' => 16, 'align' => 'R'],
        ['clave' => 'Activos',        'titulo' => 'Vigentes',          'ancho' => 18, 'align' => 'R'],
        ['clave' => 'TasaRevocatoria','titulo' => 'Tasa Revocatoria',  'ancho' => 18, 'align' => 'R', 'estilo' => 'B'],
    ];

    $filasMed = [];
    foreach ($porMedioPdf as $m) {
        $totM = (int)($m['total'] ?? 0);
        $actM = (int)($m['activos'] ?? 0);
        $revM = (int)($m['revocados'] ?? 0);
        $pctM = (float)($m['pct_del_total'] ?? 0);
        $tasaR = (float)($m['tasa_revocatoria'] ?? 0);

        $filasMed[] = [
            'Medio'           => $m['medio'] ?? '—',
            'Total'           => number_format($totM, 0, ',', '.'),
            'Porcentaje'      => $pctM . '%',
            'Activos'         => number_format($actM, 0, ',', '.'),
            'TasaRevocatoria' => $tasaR . '% (' . $revM . ' rev.)',
            '_color_Activos'  => [18, 115, 74],
            '_color_TasaRevocatoria' => $tasaR > 0 ? [180, 120, 10] : [40, 44, 52],
        ];
    }
    $pdf->tabla($columnasMed, $filasMed);

    $pdf->salida('rea_reporte_consentimientos_' . date('Ymd_His') . '.pdf');
    exit;
}

/* ---------------------------------------------------------------------------
   Exportación a Excel (.xls)
   --------------------------------------------------------------------------- */
if ($formato === 'excel') {
    $respuestaXls = apiGet('reportes/consentimientos');
    if (!$respuestaXls['ok']) {
        flashSet('error', 'No se pudo generar el Excel: ' . apiError($respuestaXls));
        redirigir('reporte_consentimientos.php');
    }
    $reporteXls   = apiDatos($respuestaXls, []);
    $poblacionXls = $reporteXls['poblacion'] ?? [];
    $porTipoXls   = $reporteXls['por_tipo'] ?? [];
    $porFinXls    = $reporteXls['por_finalidad'] ?? [];
    $porMedioXls  = $reporteXls['por_medio'] ?? [];

    $nombreArchivo = 'rea_reporte_consentimientos_cumplimiento_' . date('Ymd_His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    ?>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <table border="0" style="font-family: Arial, sans-serif; border-collapse: collapse; width: 100%;">
        <!-- CABECERA INSTITUCIONAL -->
        <tr>
            <td colspan="7" style="background-color: #8B0000; color: #ffffff; font-size: 15px; font-weight: bold; padding: 8px; text-align: center; letter-spacing: 1px;">
                RED EDUCATIVA ARQUIDIOCESANA (REA)
            </td>
        </tr>
        <tr>
            <td colspan="7" style="background-color: #5c0000; color: #ffffff; font-size: 12px; font-weight: bold; padding: 4px; text-align: center;">
                <?= e($_SESSION['institucion_nombre'] ?? 'Escuela Don Bosco') ?> — Reporte de Consentimientos y Cumplimiento General
            </td>
        </tr>
        <tr>
            <td colspan="7" style="font-size: 9.5px; color: #666666; padding: 4px; text-align: center;">
                Diagnóstico consolidado de cobertura, contactabilidad, finalidades y canales de recolección
            </td>
        </tr>
        <tr><td colspan="7" style="height: 10px;"></td></tr>

        <!-- TARJETAS KPIS -->
        <tr style="background-color: #f8f9fa;">
            <td colspan="2" style="border: 1px solid #dcdcdc; padding: 8px; text-align: center;">
                <span style="font-size: 9px; color: #666; text-transform: uppercase;">Población Objetivo Activa</span><br>
                <strong style="font-size: 15px; color: #1c1f27;"><?= number_format((int)($poblacionXls['total'] ?? 0)) ?></strong>
            </td>
            <td colspan="2" style="border: 1px solid #dcdcdc; border-left: 3px solid #12734A; padding: 8px; text-align: center;">
                <span style="font-size: 9px; color: #666; text-transform: uppercase;">Consentimientos Vigentes (Firmados)</span><br>
                <strong style="font-size: 15px; color: #12734A;"><?= number_format((int)($poblacionXls['consentidos'] ?? 0)) ?> (<?= $poblacionXls['pct_cobertura'] ?? 0 ?>%)</strong>
            </td>
            <td colspan="2" style="border: 1px solid #dcdcdc; border-left: 3px solid #C8102E; padding: 8px; text-align: center;">
                <span style="font-size: 9px; color: #666; text-transform: uppercase;">Pendientes por Firmar</span><br>
                <strong style="font-size: 15px; color: #C8102E;"><?= number_format((int)($poblacionXls['pendientes'] ?? 0)) ?> (<?= $poblacionXls['pct_pendientes'] ?? 0 ?>%)</strong>
            </td>
            <td style="border: 1px solid #dcdcdc; border-left: 3px solid #B4780A; padding: 8px; text-align: center;">
                <span style="font-size: 9px; color: #666; text-transform: uppercase;">Consentimientos Revocados</span><br>
                <strong style="font-size: 15px; color: #B4780A;"><?= number_format((int)($poblacionXls['revocados'] ?? 0)) ?> (<?= $poblacionXls['pct_revocados'] ?? 0 ?>%)</strong>
            </td>
        </tr>
        <tr><td colspan="7" style="height: 14px;"></td></tr>

        <!-- SECCIÓN 1: COBERTURA Y CONTACTABILIDAD POR TIPO DE TITULAR -->
        <tr>
            <td colspan="7" style="font-size: 12px; font-weight: bold; color: #8B0000; border-bottom: 2px solid #8B0000; padding: 4px 0;">
                1. Cobertura y Contactabilidad por Tipo de Titular
            </td>
        </tr>
        <tr style="background-color: #f2f2f2;">
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: left;">TIPO DE TITULAR</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">POBLACIÓN</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">CON CORREO (%)</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">FIRMADOS (VIGENTES)</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">PENDIENTES</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">REVOCADOS</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">% COBERTURA</td>
        </tr>
        <?php foreach ((array)$porTipoXls as $t): ?>
            <tr style="background-color: #ffffff;">
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; font-weight: bold; padding: 5px;"><?= e($t['etiqueta'] ?? '—') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; padding: 5px; text-align: right;"><?= number_format((int)($t['poblacion'] ?? 0), 0, ',', '.') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; padding: 5px; text-align: right;"><?= number_format((int)($t['con_correo'] ?? 0), 0, ',', '.') ?> (<?= $t['pct_correo'] ?? 0 ?>%)</td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; font-weight: bold; padding: 5px; text-align: right; color: #12734A;"><?= number_format((int)($t['consentidos'] ?? 0), 0, ',', '.') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; padding: 5px; text-align: right; color: <?= (int)($t['pendientes'] ?? 0) > 0 ? '#C8102E' : '#333' ?>;"><?= number_format((int)($t['pendientes'] ?? 0), 0, ',', '.') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; padding: 5px; text-align: right; color: <?= (int)($t['revocados'] ?? 0) > 0 ? '#B4780A' : '#333' ?>;"><?= number_format((int)($t['revocados'] ?? 0), 0, ',', '.') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; font-weight: bold; padding: 5px; text-align: right; color: #12734A;"><?= $t['pct_cumplimiento'] ?? 0 ?>%</td>
            </tr>
        <?php endforeach; ?>
        <tr style="background-color: #fdf2f2; font-weight: bold;">
            <td style="border-top: 2px solid #8B0000; border-bottom: 1px solid #dcdcdc; font-size: 11px; padding: 6px; color: #8B0000;">TOTAL INSTITUCIONAL</td>
            <td style="border-top: 2px solid #8B0000; border-bottom: 1px solid #dcdcdc; font-size: 11px; padding: 6px; text-align: right;"><?= number_format((int)($poblacionXls['total'] ?? 0), 0, ',', '.') ?></td>
            <td style="border-top: 2px solid #8B0000; border-bottom: 1px solid #dcdcdc; font-size: 11px; padding: 6px; text-align: right;"><?= number_format((int)($poblacionXls['con_correo'] ?? 0), 0, ',', '.') ?> (<?= $poblacionXls['pct_con_correo'] ?? 0 ?>%)</td>
            <td style="border-top: 2px solid #8B0000; border-bottom: 1px solid #dcdcdc; font-size: 11px; padding: 6px; text-align: right; color: #12734A;"><?= number_format((int)($poblacionXls['consentidos'] ?? 0), 0, ',', '.') ?></td>
            <td style="border-top: 2px solid #8B0000; border-bottom: 1px solid #dcdcdc; font-size: 11px; padding: 6px; text-align: right; color: #C8102E;"><?= number_format((int)($poblacionXls['pendientes'] ?? 0), 0, ',', '.') ?></td>
            <td style="border-top: 2px solid #8B0000; border-bottom: 1px solid #dcdcdc; font-size: 11px; padding: 6px; text-align: right; color: #B4780A;"><?= number_format((int)($poblacionXls['revocados'] ?? 0), 0, ',', '.') ?></td>
            <td style="border-top: 2px solid #8B0000; border-bottom: 1px solid #dcdcdc; font-size: 11px; padding: 6px; text-align: right; color: #12734A;"><?= $poblacionXls['pct_cobertura'] ?? 0 ?>%</td>
        </tr>
        <tr><td colspan="7" style="height: 14px;"></td></tr>

        <!-- SECCIÓN 2: DISTRIBUCIÓN POR FINALIDAD DEL TRATAMIENTO -->
        <tr>
            <td colspan="7" style="font-size: 12px; font-weight: bold; color: #8B0000; border-bottom: 2px solid #8B0000; padding: 4px 0;">
                2. Distribución por Finalidad del Tratamiento
            </td>
        </tr>
        <tr style="background-color: #f2f2f2;">
            <td colspan="3" style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: left;">FINALIDAD DECLARADA</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">TOTAL REGISTROS</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">VIGENTES (ACTIVOS)</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">REVOCADOS</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">% ACEPTACIÓN</td>
        </tr>
        <?php foreach ((array)$porFinXls as $f): ?>
            <tr style="background-color: #ffffff;">
                <td colspan="3" style="border-bottom: 1px solid #e0e0e0; font-size: 11px; font-weight: bold; padding: 5px;"><?= e($f['Nombre'] ?? '—') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; padding: 5px; text-align: right;"><?= number_format((int)($f['total'] ?? 0), 0, ',', '.') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; font-weight: bold; padding: 5px; text-align: right; color: #12734A;"><?= number_format((int)($f['activos'] ?? 0), 0, ',', '.') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; padding: 5px; text-align: right; color: <?= (int)($f['revocados'] ?? 0) > 0 ? '#B4780A' : '#333' ?>;"><?= number_format((int)($f['revocados'] ?? 0), 0, ',', '.') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; font-weight: bold; padding: 5px; text-align: right;"><?= (float)($f['tasa_aceptacion'] ?? 0) ?>%</td>
            </tr>
        <?php endforeach; ?>
        <tr><td colspan="7" style="height: 14px;"></td></tr>

        <!-- SECCIÓN 3: RENDIMIENTO Y CALIDAD POR CANAL / MEDIO -->
        <tr>
            <td colspan="7" style="font-size: 12px; font-weight: bold; color: #8B0000; border-bottom: 2px solid #8B0000; padding: 4px 0;">
                3. Rendimiento y Calidad por Canal / Medio
            </td>
        </tr>
        <tr style="background-color: #f2f2f2;">
            <td colspan="3" style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: left;">CANAL / MEDIO</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">CONSENTIMIENTOS</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">% DEL TOTAL</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">VIGENTES</td>
            <td style="border-bottom: 1px solid #dcdcdc; font-size: 10px; font-weight: bold; padding: 6px; text-align: right;">TASA REVOCATORIA</td>
        </tr>
        <?php foreach ((array)$porMedioXls as $m): 
            $revM = (int)($m['revocados'] ?? 0);
            $tasaR = (float)($m['tasa_revocatoria'] ?? 0);
        ?>
            <tr style="background-color: #ffffff;">
                <td colspan="3" style="border-bottom: 1px solid #e0e0e0; font-size: 11px; font-weight: bold; padding: 5px;"><?= e($m['medio'] ?? '—') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; padding: 5px; text-align: right;"><?= number_format((int)($m['total'] ?? 0), 0, ',', '.') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; padding: 5px; text-align: right;"><?= (float)($m['pct_del_total'] ?? 0) ?>%</td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; font-weight: bold; padding: 5px; text-align: right; color: #12734A;"><?= number_format((int)($m['activos'] ?? 0), 0, ',', '.') ?></td>
                <td style="border-bottom: 1px solid #e0e0e0; font-size: 11px; font-weight: bold; padding: 5px; text-align: right; color: <?= $tasaR > 0 ? '#B4780A' : '#333' ?>;"><?= $tasaR ?>% (<?= $revM ?> rev.)</td>
            </tr>
        <?php endforeach; ?>

        <!-- PIE LEGAL -->
        <tr><td colspan="7" style="height: 15px;"></td></tr>
        <tr>
            <td colspan="7" style="font-size: 9px; color: #777777; border-top: 1px solid #ccc; padding-top: 4px;">
                Generado el <?= date('d/m/Y H:i:s') ?> &nbsp;·&nbsp; Emitido por: <?= e($_SESSION['username'] ?? 'admin') ?>
            </td>
        </tr>
        <tr>
            <td colspan="7" style="font-size: 8.5px; color: #999999;">
                Documento de auditoría y control de cumplimiento. Contiene métricas estadísticas de tratamiento de datos personales protegidos por la LOPDP; su divulgación no autorizada está prohibida.
            </td>
        </tr>
    </table>
    <?php
    exit;
}

/* ---------------------------------------------------------------------------
   Consulta en pantalla (estado general consolidado)
   --------------------------------------------------------------------------- */
$respuesta = apiGet('reportes/consentimientos');
$reporte   = apiDatos($respuesta, []);

if (!$respuesta['ok']) {
    flashSet('error', apiError($respuesta));
}

$poblacion    = $reporte['poblacion'] ?? ['total' => 0, 'con_correo' => 0, 'pct_con_correo' => 0, 'consentidos' => 0, 'pendientes' => 0, 'revocados' => 0, 'pct_cobertura' => 0, 'pct_pendientes' => 0, 'pct_revocados' => 0];
$porTipo      = $reporte['por_tipo'] ?? [];
$porFinalidad = $reporte['por_finalidad'] ?? [];
$porMedio     = $reporte['por_medio'] ?? [];
$porMes       = $reporte['por_mes'] ?? [];

$maxFinalidad = max(1, (int)($reporte['maximos']['finalidad'] ?? 1));
$maxMedio     = max(1, (int)($reporte['maximos']['medio'] ?? 1));
$maxMes       = max(1, (int)($reporte['maximos']['mes'] ?? 1));

$urlPdf = 'reporte_consentimientos.php?formato=pdf';

$hayResultados = ((int)($poblacion['total'] ?? 0) > 0) || !empty($porTipo);

$pageTitle = 'Reporte de Consentimientos';
$breadcrumb = [['label' => 'Reportes', 'url' => null], ['label' => 'Consentimientos y Cumplimiento General', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<!-- ================= Encabezado exclusivo de impresión ================= -->
<div class="solo-impresion" style="display:none;margin-bottom:14px;border-bottom:2px solid #c8102e;padding-bottom:10px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;">
        <div style="display:flex;align-items:center;gap:14px;">
            <img src="<?= e(APP_ROOT) ?>assets/logo.png" alt="REA" style="height:38px;width:auto;">
            <div>
                <div style="font-size:13pt;font-weight:700;color:#c8102e;"><?= e($institucionNombre) ?></div>
                <div style="font-size:10.5pt;font-weight:700;color:#0f172a;">Reporte de Consentimientos y Cumplimiento General</div>
                <div style="font-size:8pt;color:#64748b;">Diagnóstico consolidado de cobertura, contactabilidad, finalidades y canales de recolección</div>
            </div>
        </div>
        <div style="text-align:right;font-size:7.5pt;color:#64748b;">
            <div>Emisión: <?= date('d/m/Y H:i') ?></div>
            <div>Usuario: <?= e($_SESSION['username'] ?? 'sistema') ?></div>
        </div>
    </div>
</div>

<div class="page-header no-imprimir">
    <div>
        <h1>📈 Reporte de Consentimientos</h1>
        <p>Estado general y consolidado de cobertura, contactabilidad, finalidades y canales de recolección de <strong><?= e($institucionNombre) ?></strong>.</p>
    </div>
    <div class="flex-gap">
        <button type="button" onclick="window.print()" class="btn btn-secundario">Imprimir</button>
        <?php if ($hayResultados): ?>
            <a href="<?= e($urlPdf) ?>" class="btn btn-primario" target="_blank" rel="noopener">Exportar a PDF</a>
            <a href="reporte_consentimientos.php?formato=excel" class="btn btn-secundario" style="background:#1e7e34; color:#fff; border-color:#1c7430;">Exportar a Excel</a>
        <?php else: ?>
            <button type="button" class="btn btn-primario" disabled title="No hay datos disponibles para exportar">
                Exportar a PDF
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- ================= KPIs Principales ================= -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-valor"><?= number_format((int)$poblacion['total']) ?></div>
        <div class="kpi-label">Población Objetivo Activa</div>
    </div>
    <div class="kpi-card kpi-alt-2">
        <div class="kpi-valor"><?= number_format((int)$poblacion['consentidos']) ?> <span style="font-size:0.95rem;font-weight:500;">(<?= $poblacion['pct_cobertura'] ?>%)</span></div>
        <div class="kpi-label">Consentimientos Vigentes (Firmados)</div>
    </div>
    <div class="kpi-card kpi-alt-3" style="<?= (int)$poblacion['pendientes'] > 0 ? 'border-left:4px solid var(--rea-rojo);' : '' ?>">
        <div class="kpi-valor"><?= number_format((int)$poblacion['pendientes']) ?> <span style="font-size:0.95rem;font-weight:500;">(<?= $poblacion['pct_pendientes'] ?>%)</span></div>
        <div class="kpi-label">Pendientes por Firmar</div>
    </div>
    <div class="kpi-card kpi-alt-1">
        <div class="kpi-valor"><?= number_format((int)($poblacion['revocados'] ?? 0)) ?> <span style="font-size:0.95rem;font-weight:500;">(<?= $poblacion['pct_revocados'] ?? 0 ?>%)</span></div>
        <div class="kpi-label">Consentimientos Revocados</div>
    </div>
</div>

<!-- ================= Cobertura y Contactabilidad por Tipo de Titular ================= -->
<div class="card" style="margin-bottom:1.25rem;">
    <div class="flex-entre" style="margin-bottom:12px;">
        <div>
            <h3 class="mb-0">Cobertura y Contactabilidad por Tipo de Titular</h3>
            <p class="texto-mutado" style="margin:4px 0 0 0;font-size:0.9rem;">Estado del consentimiento y disponibilidad de correos en Estudiantes, Empleados y Proveedores.</p>
        </div>
        <div class="texto-mutado" style="font-weight:600;font-size:0.9rem;">
            <span style="color:var(--color-exito);"><?= $poblacion['pct_cobertura'] ?>% Firmados</span> &nbsp;·&nbsp;
            <span style="color:var(--rea-rojo);"><?= $poblacion['pct_pendientes'] ?>% Pendientes</span>
        </div>
    </div>

    <!-- Barra de Cobertura Global Integrada (100% exacta) -->
    <div style="background:var(--rea-gris-200);border-radius:999px;overflow:hidden;height:14px;display:flex;margin-bottom:16px;">
        <div title="Firmados: <?= $poblacion['pct_cobertura'] ?>%" style="background:var(--color-exito);width:<?= $poblacion['pct_cobertura'] ?>%;height:100%;transition:width .3s;"></div>
        <div title="Pendientes: <?= $poblacion['pct_pendientes'] ?>%" style="background:var(--rea-rojo);width:<?= $poblacion['pct_pendientes'] ?>%;height:100%;transition:width .3s;"></div>
    </div>

    <div class="tabla-wrap" style="overflow-x:hidden;">
        <table class="tabla-datos" style="table-layout:fixed; width:100%;">
            <thead>
                <tr>
                    <th style="width:26%;">Tipo de Titular</th>
                    <th style="text-align:right;width:11%;">Población</th>
                    <th style="text-align:right;width:18%;">Con Correo</th>
                    <th style="text-align:right;width:11%;">Vigentes</th>
                    <th style="text-align:right;width:11%;">Pendientes</th>
                    <th style="text-align:right;width:11%;">Revocados</th>
                    <th style="text-align:right;width:12%;">Cobertura</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($porTipo as $t): ?>
                    <tr>
                        <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><strong><?= e($t['etiqueta']) ?></strong></td>
                        <td style="text-align:right; font-weight:600;"><?= number_format((int)$t['poblacion']) ?></td>
                        <td style="text-align:right;">
                            <?= number_format((int)$t['con_correo']) ?>
                            <small class="texto-mutado">(<?= $t['pct_correo'] ?>%)</small>
                        </td>
                        <td style="text-align:right;"><strong style="color:var(--color-exito);"><?= number_format((int)$t['consentidos']) ?></strong></td>
                        <td style="text-align:right;" class="<?= (int)$t['pendientes'] > 0 ? 'texto-peligro' : 'texto-mutado' ?>">
                            <?= number_format((int)$t['pendientes']) ?>
                        </td>
                        <td style="text-align:right;" class="<?= (int)($t['revocados'] ?? 0) > 0 ? 'texto-alerta' : 'texto-mutado' ?>">
                            <?= number_format((int)($t['revocados'] ?? 0)) ?>
                        </td>
                        <td style="text-align:right;">
                            <span class="badge <?= $t['pct_cumplimiento'] >= 80 ? 'badge-activo' : ($t['pct_cumplimiento'] >= 50 ? 'badge-info' : 'badge-inactivo') ?>" style="padding:2px 7px;font-size:0.75rem;">
                                <?= $t['pct_cumplimiento'] ?>%
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700; background:var(--rea-gris-50);">
                    <td>TOTAL INSTITUCIONAL</td>
                    <td style="text-align:right;"><?= number_format((int)$poblacion['total']) ?></td>
                    <td style="text-align:right;">
                        <?= number_format((int)$poblacion['con_correo']) ?>
                        <small class="texto-mutado">(<?= $poblacion['pct_con_correo'] ?>%)</small>
                    </td>
                    <td style="text-align:right; color:var(--color-exito);"><?= number_format((int)$poblacion['consentidos']) ?></td>
                    <td style="text-align:right; color:var(--rea-rojo);"><?= number_format((int)$poblacion['pendientes']) ?></td>
                    <td style="text-align:right; color:#d97706;"><?= number_format((int)($poblacion['revocados'] ?? 0)) ?></td>
                    <td style="text-align:right;">
                        <span class="badge badge-activo" style="padding:2px 7px;font-size:0.78rem;">
                            <?= $poblacion['pct_cobertura'] ?>%
                        </span>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- ================= Detalle por Finalidad ================= -->
<div class="card" style="margin-bottom:1.25rem;">
    <div class="flex-entre">
        <h3 class="mb-0">Distribución por Finalidad del Tratamiento</h3>
        <span class="texto-mutado">Nivel de aceptación por finalidad declarada</span>
    </div>

    <?php if (empty($porFinalidad)): ?>
        <p class="texto-mutado" style="margin-top:12px;">Aún no hay finalidades registradas en el sistema.</p>
    <?php else: ?>
        <div class="tabla-wrap" style="overflow-x:hidden;margin-top:14px;">
            <table class="tabla-datos" style="table-layout:fixed; width:100%;">
                <thead>
                    <tr>
                        <th style="width:40%;">Finalidad Declarada</th>
                        <th style="text-align:right;width:12%;">Total</th>
                        <th style="text-align:right;width:12%;">Vigentes</th>
                        <th style="text-align:right;width:12%;">Revocados</th>
                        <th style="text-align:right;width:12%;">% Aceptación</th>
                        <th style="text-align:center;width:12%;">Distribución</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($porFinalidad as $r): 
                        $t = (int)($r['total'] ?? 0);
                        $a = (int)($r['activos'] ?? 0);
                        $rev = (int)($r['revocados'] ?? 0);
                        $tasa = (float)($r['tasa_aceptacion'] ?? 0);
                    ?>
                        <tr>
                            <td style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><strong><?= e($r['Nombre']) ?></strong></td>
                            <td style="text-align:right;"><?= number_format($t, 0, ',', '.') ?></td>
                            <td style="text-align:right;"><span style="color:var(--color-exito);font-weight:600;"><?= number_format($a, 0, ',', '.') ?></span></td>
                            <td style="text-align:right;"><span style="<?= $rev > 0 ? 'color:var(--rea-rojo);font-weight:600;' : 'color:var(--rea-gris-600);' ?>"><?= number_format($rev, 0, ',', '.') ?></span></td>
                            <td style="text-align:right;font-weight:700;"><?= $tasa ?>%</td>
                            <td style="text-align:center;">
                                <div style="background:var(--rea-gris-100);border-radius:6px;overflow:hidden;height:10px;display:flex;width:56px;margin:0 auto;">
                                    <div style="background:var(--color-exito);height:100%;width:<?= $tasa ?>%;" title="Vigentes: <?= $tasa ?>%"></div>
                                    <div style="background:var(--rea-rojo);height:100%;width:<?= 100 - $tasa ?>%;" title="Revocados: <?= round(100 - $tasa, 1) ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- ================= Fila Inferior: Canales y Evolución ================= -->
<div class="form-row" style="align-items:stretch;">
    <div class="card" style="flex:1 1 380px;">
        <h3>Rendimiento y Calidad por Canal / Medio</h3>
        <?php if (empty($porMedio)): ?>
            <p class="texto-mutado">Sin datos registrados.</p>
        <?php else: ?>
            <div class="tabla-wrap" style="margin-top:10px;">
                <table class="tabla-datos">
                    <thead>
                        <tr>
                            <th>Canal</th>
                            <th style="text-align:right;">Total</th>
                            <th style="text-align:right;">% Total</th>
                            <th style="text-align:right;">Revocatorias</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($porMedio as $m): ?>
                            <tr>
                                <td><strong><?= e($m['medio']) ?></strong></td>
                                <td style="text-align:right;"><?= number_format((int)$m['total']) ?></td>
                                <td style="text-align:right;"><?= $m['pct_del_total'] ?>%</td>
                                <td style="text-align:right;">
                                    <span class="<?= (float)$m['tasa_revocatoria'] > 0 ? 'texto-peligro' : 'texto-mutado' ?>">
                                        <?= $m['tasa_revocatoria'] ?>% (<?= (int)$m['revocados'] ?>)
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="card" style="flex:1 1 380px;">
        <h3>Evolución Mensual de Firmas (Últimos 12 meses)</h3>
        <?php if (empty($porMes)): ?>
            <p class="texto-mutado">Sin datos suficientes en los últimos 12 meses.</p>
        <?php else: ?>
            <div class="flex-gap" style="align-items:flex-end;height:140px;padding-top:10px;">
                <?php foreach ($porMes as $r): ?>
                    <div style="text-align:center;flex:1;">
                        <div style="background:var(--rea-rojo);border-radius:4px 4px 0 0;width:100%;height:<?= max(6, round((int)$r['total']/$maxMes*100)) ?>px;margin:0 auto;" title="<?= e($r['periodo']) ?>: <?= (int)$r['total'] ?>"></div>
                        <div class="texto-mutado" style="font-size:.68rem;margin-top:4px;"><?= e(substr($r['periodo'],5,2)) ?>/<?= e(substr($r['periodo'],2,2)) ?></div>
                        <div style="font-size:.72rem;font-weight:600;"><?= (int)$r['total'] ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
