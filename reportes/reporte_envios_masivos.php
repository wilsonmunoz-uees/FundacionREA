<?php
// reportes/reporte_envios_masivos.php
// Reporte de Efectividad de Campañas y Envíos Masivos de Invitaciones.
// Permite auditar el alcance, tasa de entrega, uso de enlaces verificados y
// porcentaje de conversión a consentimientos firmados en la institución.
// Consume el endpoint /api/reportes/envios-masivos y genera PDF institucional.
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('reporte_envios_masivos');
$institucionId = institucionActual();

/* ---------------------------------------------------------------------------
   Filtros
   --------------------------------------------------------------------------- */
$tiposDisponibles = [
    'todos'      => 'Todos los tipos',
    'estudiante' => 'Estudiantes',
    'empleado'   => 'Empleados / Docentes',
    'proveedor'  => 'Proveedores',
];

$estadosDisponibles = [
    'TODOS'     => 'Todos los estados',
    'USADO'     => 'Verificado / Usado',
    'PENDIENTE' => 'Pendiente activo',
    'ANULADO'   => 'Anulado',
];

$filtroTipo = strtolower($_GET['tipo'] ?? 'todos');
if (!array_key_exists($filtroTipo, $tiposDisponibles)) {
    $filtroTipo = 'todos';
}

$filtroEstado = strtoupper($_GET['estado'] ?? 'TODOS');
if (!array_key_exists($filtroEstado, $estadosDisponibles)) {
    $filtroEstado = 'TODOS';
}

$filtroDesde = trim($_GET['desde'] ?? '');
$filtroHasta = trim($_GET['hasta'] ?? '');
$filtroTexto = trim($_GET['q'] ?? '');
$formato     = $_GET['formato'] ?? '';

$errorFechas = '';
if ($filtroDesde !== '' && $filtroHasta !== '' && $filtroDesde > $filtroHasta) {
    $errorFechas = 'La fecha inicial no puede ser posterior a la fecha final.';
}

$parametros = [
    'tipo'   => $filtroTipo,
    'estado' => $filtroEstado,
    'desde'  => $filtroDesde,
    'hasta'  => $filtroHasta,
    'q'      => $filtroTexto,
];

/* Resumen de filtros para encabezado y PDF */
$resumenFiltros = [
    'Institución'     => $_SESSION['institucion_nombre'] ?? ('#' . $institucionId),
    'Tipo de titular' => $tiposDisponibles[$filtroTipo],
    'Estado'          => $estadosDisponibles[$filtroEstado],
    'Período'         => ($filtroDesde !== '' || $filtroHasta !== '')
        ? (($filtroDesde !== '' ? f_fecha($filtroDesde, 'd/m/Y') : 'inicio') . ' a ' .
           ($filtroHasta !== '' ? f_fecha($filtroHasta, 'd/m/Y') : 'hoy'))
        : 'Histórico completo',
];
if ($filtroTexto !== '') {
    $resumenFiltros['Búsqueda'] = $filtroTexto;
}

/* ---------------------------------------------------------------------------
   Exportación a PDF
   --------------------------------------------------------------------------- */
if ($formato === 'pdf') {
    require_once __DIR__ . '/../includes/pdf_reporte.php';

    $respuestaPdf = apiGet('reportes/envios-masivos', $parametros + ['pagina' => 1, 'por_pagina' => 500]);

    if (!$respuestaPdf['ok']) {
        flashSet('error', 'No se pudo generar el PDF: ' . apiError($respuestaPdf));
        redirigir('reporte_envios_masivos.php?' . http_build_query($parametros));
    }

    $filasPdf = apiDatos($respuestaPdf, []);
    $metaPdf  = apiMeta($respuestaPdf, 'kpis', []);

    if (empty($filasPdf)) {
        flashSet('advertencia', 'No se encontraron registros para exportar con los filtros seleccionados.');
        redirigir('reporte_envios_masivos.php?' . http_build_query($parametros));
    }

    $pdf = new PdfReporte('H');

    $pdf->cabecera([
        'logo'        => __DIR__ . '/../assets/logo.png',
        'institucion' => $_SESSION['institucion_nombre'] ?? 'Red Educativa Arquidiocesana',
        'titulo'      => 'Reporte de Efectividad de Envíos Masivos',
        'subtitulo'   => 'Auditoría de invitaciones enviadas, uso de enlaces verificados y conversión a consentimientos',
        'filtros'     => $resumenFiltros,
    ]);

    $pdf->pie([
        'usuario'    => ($_SESSION['username'] ?? 'sistema')
            . (!empty($_SESSION['roles']) ? ' (' . implode(', ', $_SESSION['roles']) . ')' : ''),
        'disclaimer' => 'Documento de auditoría y control de cumplimiento. Contiene métricas de trazabilidad de invitaciones al consentimiento '
            . 'protegidas por la LOPDP; su divulgación no autorizada está prohibida.',
    ]);

    $columnas = [
        ['clave' => 'Titular',        'titulo' => 'Titular / Representante', 'ancho' => 24, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'Documento',      'titulo' => 'Documento',              'ancho' => 11, 'align' => 'L'],
        ['clave' => 'Tipo',           'titulo' => 'Tipo',                   'ancho' => 11, 'align' => 'L'],
        ['clave' => 'Destinatario',   'titulo' => 'Correo de Destino',      'ancho' => 22, 'align' => 'L'],
        ['clave' => 'FechaEnvio',     'titulo' => 'Fecha Envío',            'ancho' => 11, 'align' => 'L'],
        ['clave' => 'EstadoEnlace',   'titulo' => 'Estado Enlace',          'ancho' => 11, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'FechaUso',       'titulo' => 'Fecha Uso',              'ancho' => 10, 'align' => 'L'],
    ];

    $filasFormateadas = [];
    foreach ($filasPdf as $f) {
        $estado = $f['EstadoCalculado'] ?? 'PENDIENTE';
        $colorEstado = [180, 120, 10]; // Ámbar pendiente
        if ($estado === 'USADO') {
            $colorEstado = [18, 115, 74];  // Verde
        } elseif ($estado === 'ANULADO') {
            $colorEstado = [200, 16, 46]; // Rojo
        }

        $filasFormateadas[] = [
            'Titular'             => $f['Titular'] ?? '—',
            'Documento'           => $f['Identificacion'] ?: '—',
            'Tipo'                => ucfirst(strtolower((string)$f['TipoPersona'])),
            'Destinatario'        => $f['Destinatario'] ?: '—',
            'FechaEnvio'          => f_fecha($f['FechaEmision'] ?? null),
            'EstadoEnlace'        => $estado === 'USADO' ? 'VERIFICADO' : ($estado === 'ANULADO' ? 'ANULADO' : 'PENDIENTE'),
            'FechaUso'            => !empty($f['FechaUso']) ? f_fecha($f['FechaUso']) : '—',
            '_color_EstadoEnlace' => $colorEstado,
        ];
    }

    $totInv  = (int)($metaPdf['total_invitaciones'] ?? 0);
    $totUsad = (int)($metaPdf['verificados_usados'] ?? 0);
    $totPend = (int)($metaPdf['pendientes_activos'] ?? 0);
    $totExp  = (int)($metaPdf['anulados'] ?? $metaPdf['expirados_anulados'] ?? 0);
    $pctConv = (float)($metaPdf['pct_conversion'] ?? 0);
    $pctPend = $totInv > 0 ? round(($totPend / $totInv) * 100, 1) : 0.0;
    $pctExp  = $totInv > 0 ? round(($totExp / $totInv) * 100, 1) : 0.0;

    // 1. Tarjetas KPI Superiores
    $pdf->tarjetasKpi([
        [
            'valor' => number_format($totInv),
            'label' => 'Invitaciones Emitidas',
            'color' => [28, 31, 39],
        ],
        [
            'valor'     => number_format($totUsad) . ' (' . $pctConv . '%)',
            'label'     => 'Enlaces Verificados (Firmados)',
            'color'     => [18, 115, 74],
            'borde_izq' => [18, 115, 74],
        ],
        [
            'valor'     => number_format($totPend) . ' (' . $pctPend . '%)',
            'label'     => 'Pendientes por Abrir',
            'color'     => $totPend > 0 ? [217, 119, 6] : [100, 108, 122],
            'borde_izq' => $totPend > 0 ? [217, 119, 6] : null,
        ],
        [
            'valor'     => number_format($totExp) . ' (' . $pctExp . '%)',
            'label'     => 'Anulados',
            'color'     => $totExp > 0 ? [200, 16, 46] : [100, 108, 122],
            'borde_izq' => $totExp > 0 ? [200, 16, 46] : null,
        ],
    ]);

    // 2. Barra de Conversión Global
    $pdf->barraProgreso('Tasa Global de Conversión de Invitaciones', [
        ['pct' => $pctConv, 'label' => $pctConv . '% Verificados (' . number_format($totUsad) . ')', 'color' => [18, 115, 74]],
        ['pct' => $pctPend, 'label' => $pctPend . '% Pendientes (' . number_format($totPend) . ')',  'color' => [217, 119, 6]],
        ['pct' => $pctExp,  'label' => $pctExp . '% Anulados (' . number_format($totExp) . ')',     'color' => [200, 16, 46]],
    ]);

    // 3. Sección Tabla de Trazabilidad
    $pdf->seccionTitulo('Trazabilidad de Invitaciones y Verificaciones', 'Detalle cronológico de enlaces generados y estado de autenticación.');
    $pdf->tabla($columnas, $filasFormateadas);

    $pdf->salida('rea_efectividad_envios_' . date('Ymd_His') . '.pdf');
    exit;
}

/* ---------------------------------------------------------------------------
   Consulta en pantalla
   --------------------------------------------------------------------------- */
$listado = apiGet('reportes/envios-masivos', $parametros + [
    'pagina'     => max(1, (int)($_GET['pagina'] ?? 1)),
    'por_pagina' => 15,
]);

$registros = apiDatos($listado, []);
$kpis      = apiMeta($listado, 'kpis', [
    'total_invitaciones' => 0, 'verificados_usados' => 0,
    'pendientes_activos' => 0, 'expirados_anulados' => 0,
    'pct_conversion'     => 0,
]);
$porTipo   = apiMeta($listado, 'por_tipo', []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$urlPdf = 'reporte_envios_masivos.php?' . http_build_query($parametros + ['formato' => 'pdf']);

$totalInvitaciones = (int)($kpis['total_invitaciones'] ?? 0);
$totalUsados       = (int)($kpis['verificados_usados'] ?? 0);
$totalPendientes   = (int)($kpis['pendientes_activos'] ?? 0);
$totalExpirados    = (int)($kpis['expirados_anulados'] ?? 0);
$pctConversion     = (float)($kpis['pct_conversion'] ?? 0);
$pctPendientes     = $totalInvitaciones > 0 ? round(($totalPendientes / $totalInvitaciones) * 100, 1) : 0;
$pctExpirados      = $totalInvitaciones > 0 ? round(($totalExpirados / $totalInvitaciones) * 100, 1) : 0;

$pageTitle = 'Reporte de Efectividad de Envíos Masivos';
$breadcrumb = [['label' => 'Reportes', 'url' => null], ['label' => 'Efectividad de Envíos Masivos', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<!-- ================= Encabezado exclusivo de impresión ================= -->
<div class="solo-impresion" style="display:none;margin-bottom:14px;border-bottom:2px solid #c8102e;padding-bottom:10px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:14px;">
        <div style="display:flex;align-items:center;gap:14px;">
            <img src="<?= e(APP_ROOT) ?>assets/logo.png" alt="REA" style="height:38px;width:auto;">
            <div>
                <div style="font-size:13pt;font-weight:700;color:#c8102e;"><?= e($_SESSION['institucion_nombre'] ?? 'Red Educativa Arquidiocesana') ?></div>
                <div style="font-size:10.5pt;font-weight:700;color:#0f172a;">Reporte de Efectividad de Envíos Masivos</div>
                <div style="font-size:8pt;color:#64748b;">Monitoreo de alcance y conversión de las campañas de invitación digital</div>
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
        <h1>📬 Reporte de Efectividad de Envíos Masivos</h1>
        <p>Monitoreo de alcance y conversión de las campañas de invitación digital: enlaces emitidos, verificados y pendientes.</p>
    </div>
    <div class="flex-gap">
        <a href="<?= e($urlPdf) ?>" class="btn btn-primario" target="_blank" rel="noopener">Exportar a PDF</a>
    </div>
</div>

<!-- ================= KPIs ================= -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-valor"><?= number_format($totalInvitaciones) ?></div>
        <div class="kpi-label">Invitaciones Emitidas</div>
    </div>
    <div class="kpi-card kpi-alt-2">
        <div class="kpi-valor"><?= number_format($totalUsados) ?> <span style="font-size:0.95rem;font-weight:500;">(<?= $pctConversion ?>%)</span></div>
        <div class="kpi-label">Enlaces Verificados (Firmados)</div>
    </div>
    <div class="kpi-card kpi-alt-3">
        <div class="kpi-valor"><?= number_format($totalPendientes) ?> <span style="font-size:0.95rem;font-weight:500;">(<?= $pctPendientes ?>%)</span></div>
        <div class="kpi-label">Pendientes por Abrir</div>
    </div>
    <div class="kpi-card kpi-alt-1">
        <div class="kpi-valor"><?= number_format($totalExpirados) ?> <span style="font-size:0.95rem;font-weight:500;">(<?= $pctExpirados ?>%)</span></div>
        <div class="kpi-label">Anulados</div>
    </div>
</div>

<!-- ================= Barra de Conversión ================= -->
<div class="card no-imprimir" style="margin-bottom:1.25rem;">
    <div class="flex-entre" style="margin-bottom:8px;">
        <h3 class="mb-0" style="font-size:1.05rem;">Tasa Global de Conversión (<?= $resumenFiltros['Tipo de titular'] ?>)</h3>
        <span class="texto-mutado" style="font-weight:600;">
            <span style="color:var(--color-exito);"><?= $pctConversion ?>% Verificados</span> &nbsp;·&nbsp;
            <span style="color:#d97706;"><?= $pctPendientes ?>% Pendientes</span>
            <?php if ($pctExpirados > 0): ?> &nbsp;·&nbsp; <span style="color:var(--rea-rojo);"><?= $pctExpirados ?>% Anulados</span><?php endif; ?>
        </span>
    </div>
    <div style="background:var(--rea-gris-200);border-radius:999px;overflow:hidden;height:16px;display:flex;">
        <div title="Verificados: <?= $pctConversion ?>%" style="background:var(--color-exito);width:<?= $pctConversion ?>%;height:100%;transition:width .3s;"></div>
        <div title="Pendientes: <?= $pctPendientes ?>%" style="background:#d97706;width:<?= $pctPendientes ?>%;height:100%;transition:width .3s;"></div>
        <div title="Anulados: <?= $pctExpirados ?>%" style="background:var(--rea-rojo);width:<?= $pctExpirados ?>%;height:100%;transition:width .3s;"></div>
    </div>
</div>

<!-- ================= Filtros ================= -->
<div class="card no-imprimir">
    <h3>Filtros de consulta</h3>
    <?php if ($errorFechas !== ''): ?>
        <div class="alerta alerta-error"><?= e($errorFechas) ?></div>
    <?php endif; ?>

    <form method="GET" action="reporte_envios_masivos.php">
        <div class="form-row">
            <div class="form-group">
                <label for="tipo">Tipo de titular</label>
                <select name="tipo" id="tipo">
                    <?php foreach ($tiposDisponibles as $val => $etiq): ?>
                        <option value="<?= e($val) ?>" <?= $filtroTipo === $val ? 'selected' : '' ?>><?= e($etiq) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="estado">Estado del enlace</label>
                <select name="estado" id="estado">
                    <?php foreach ($estadosDisponibles as $val => $etiq): ?>
                        <option value="<?= e($val) ?>" <?= $filtroEstado === $val ? 'selected' : '' ?>><?= e($etiq) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="desde">Desde</label>
                <input type="date" name="desde" id="desde" value="<?= e($filtroDesde) ?>">
            </div>
            <div class="form-group">
                <label for="hasta">Hasta</label>
                <input type="date" name="hasta" id="hasta" value="<?= e($filtroHasta) ?>">
            </div>
        </div>
        <div class="form-row" style="margin-top:14px; align-items:flex-end;">
            <div class="form-group" style="flex:1 1 340px; margin-bottom:0;">
                <label for="q">Buscar destinatario o documento</label>
                <input type="text" name="q" id="q" value="<?= e($filtroTexto) ?>" placeholder="Nombre, apellido, identificación o correo electrónico...">
            </div>
            <div class="form-group" style="flex:0 0 auto; margin-bottom:0; display:flex; gap:8px;">
                <button type="submit" class="btn btn-primario">🔍 Consultar</button>
                <a href="reporte_envios_masivos.php" class="btn btn-secundario">Limpiar</a>
            </div>
        </div>
    </form>
</div>

<!-- ================= Listado de Enlaces ================= -->
<div class="card">
    <div class="flex-entre">
        <h3 class="mb-0">Trazabilidad de Envíos e Invitaciones Digitales</h3>
        <span class="texto-mutado">Página <?= $numPagina ?> de <?= max(1, $totalPaginas) ?></span>
    </div>

    <div class="tabla-wrap" style="margin-top:14px;">
        <table class="tabla-datos">
            <thead>
                <tr>
                    <th>Titular / Representante</th>
                    <th>Documento</th>
                    <th>Tipo</th>
                    <th>Correo de Destino</th>
                    <th>Fecha Envío</th>
                    <th>Estado Enlace</th>
                    <th>Fecha Uso</th>
                    <th>Consentimiento</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($registros)): ?>
                    <tr>
                        <td colspan="8" class="tabla-vacia">No se encontraron invitaciones con los filtros seleccionados.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td>
                            <?php if (!empty($r['PersonaId'])): ?>
                                <a href="<?= e(APP_ROOT) ?>consultas/buscar_persona.php?q=<?= urlencode((string)$r['Identificacion']) ?>">
                                    <strong><?= e($r['Titular']) ?></strong>
                                </a>
                            <?php else: ?>
                                <strong><?= e($r['Titular']) ?></strong>
                            <?php endif; ?>
                            <?php if (!empty($r['Representante']) && $r['TipoPersona'] === 'ESTUDIANTE'): ?>
                                <br><small class="texto-mutado">Rep: <?= e($r['Representante']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($r['Identificacion'] ?: '—') ?></td>
                        <td><span class="badge badge-neutro"><?= ucfirst(strtolower((string)$r['TipoPersona'])) ?></span></td>
                        <td><?= e($r['Destinatario'] ?: '—') ?></td>
                        <td><?= f_fecha($r['FechaEmision']) ?></td>
                        <td>
                            <?php if ($r['EstadoCalculado'] === 'USADO'): ?>
                                <span class="badge badge-activo">VERIFICADO</span>
                            <?php elseif ($r['EstadoCalculado'] === 'PENDIENTE'): ?>
                                <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">PENDIENTE</span>
                            <?php elseif ($r['EstadoCalculado'] === 'ANULADO' || $r['EstadoCalculado'] === 'EXPIRADO'): ?>
                                <span class="badge badge-inactivo">ANULADO</span>
                            <?php else: ?>
                                <span class="badge badge-inactivo"><?= e($r['EstadoCalculado']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($r['FechaUso'])): ?>
                                <?= f_fecha($r['FechaUso']) ?>
                            <?php else: ?>
                                <span class="texto-mutado">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['ConsentimientoActivo'] === 'ACTIVO'): ?>
                                <span class="badge badge-activo">FIRMADO</span>
                            <?php else: ?>
                                <span class="badge badge-neutro">PENDIENTE</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php renderPaginacion($numPagina, $totalPaginas); ?>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
