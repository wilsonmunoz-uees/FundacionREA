<?php
// reportes/reporte_cobertura.php
// Reporte de Cobertura y Brecha de Consentimientos (Firmados vs. Pendientes).
// Permite auditar el cumplimiento de la LOPDP en la institución: quiénes ya
// otorgaron su consentimiento y quiénes siguen pendientes en el padrón activo.
// Consume el endpoint /api/reportes/cobertura y genera PDF institucional.
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('reporte_cobertura');
$institucionId = institucionActual();

/* ---------------------------------------------------------------------------
   Filtros
   --------------------------------------------------------------------------- */
$tiposDisponibles = [
    'todos'       => 'Todos los tipos',
    'estudiantes' => 'Estudiantes',
    'empleados'   => 'Empleados / Docentes',
    'proveedores' => 'Proveedores',
];

$estadosDisponibles = [
    'TODOS'      => 'Todos los estados',
    'PENDIENTE'  => 'Pendientes (Sin consentimiento)',
    'CONSENTIDO' => 'Consentimiento vigente',
    'REVOCADO'   => 'Consentimiento revocado',
];

$filtroTipo = strtolower($_GET['tipo'] ?? 'todos');
if (!array_key_exists($filtroTipo, $tiposDisponibles)) {
    $filtroTipo = 'todos';
}

$filtroEstado = strtoupper($_GET['estado_cobertura'] ?? 'PENDIENTE');
if (!array_key_exists($filtroEstado, $estadosDisponibles)) {
    $filtroEstado = 'PENDIENTE';
}

$filtroVersion = trim($_GET['version'] ?? 'todas');
$filtroTexto   = trim($_GET['q'] ?? '');
$formato       = $_GET['formato'] ?? '';

$parametros = [
    'tipo'             => $filtroTipo,
    'estado_cobertura' => $filtroEstado,
    'version'          => $filtroVersion,
    'q'                => $filtroTexto,
];

/* Resumen de filtros para encabezado y PDF */
$resumenFiltros = [
    'Institución'     => $_SESSION['institucion_nombre'] ?? ('#' . $institucionId),
    'Tipo de titular' => $tiposDisponibles[$filtroTipo],
    'Estado'          => $estadosDisponibles[$filtroEstado],
];
if ($filtroVersion !== '' && $filtroVersion !== 'todas') {
    $resumenFiltros['Versión'] = 'v' . $filtroVersion;
}
if ($filtroTexto !== '') {
    $resumenFiltros['Búsqueda'] = $filtroTexto;
}

/* ---------------------------------------------------------------------------
   Exportación a PDF (usa los mismos filtros, hasta 500 filas)
   --------------------------------------------------------------------------- */
if ($formato === 'pdf') {
    require_once __DIR__ . '/../includes/pdf_reporte.php';

    $respuestaPdf = apiGet('reportes/cobertura', $parametros + ['pagina' => 1, 'por_pagina' => 500]);

    if (!$respuestaPdf['ok']) {
        flashSet('error', 'No se pudo generar el PDF: ' . apiError($respuestaPdf));
        redirigir('reporte_cobertura.php?' . http_build_query($parametros));
    }

    $filasPdf = apiDatos($respuestaPdf, []);
    $metaPdf  = apiMeta($respuestaPdf, 'kpis', []);

    if (empty($filasPdf)) {
        flashSet('advertencia', 'No se encontraron registros para exportar con los filtros seleccionados.');
        redirigir('reporte_cobertura.php?' . http_build_query($parametros));
    }

    $pdf = new PdfReporte('H');

    $pdf->cabecera([
        'logo'        => __DIR__ . '/../assets/logo.png',
        'institucion' => $_SESSION['institucion_nombre'] ?? 'Red Educativa Arquidiocesana',
        'titulo'      => 'Reporte de Cobertura y Pendientes de Consentimiento',
        'subtitulo'   => 'Monitoreo de cumplimiento LOPDP: titulares con consentimiento otorgado vs. pendientes de registro',
        'filtros'     => $resumenFiltros,
    ]);

    $pdf->pie([
        'usuario'    => ($_SESSION['username'] ?? 'sistema')
            . (!empty($_SESSION['roles']) ? ' (' . implode(', ', $_SESSION['roles']) . ')' : ''),
        'disclaimer' => 'Documento de auditoría y control de cumplimiento. Contiene datos personales protegidos por la Ley '
            . 'Orgánica de Protección de Datos Personales (LOPDP); su divulgación no autorizada está terminantemente prohibida.',
    ]);

    $columnas = [
        ['clave' => 'Titular',         'titulo' => 'Titular',                  'ancho' => 26, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'Documento',       'titulo' => 'Documento',                'ancho' => 11, 'align' => 'L'],
        ['clave' => 'TipoPersona',     'titulo' => 'Tipo',                     'ancho' => 11, 'align' => 'L'],
        ['clave' => 'Representante',   'titulo' => 'Representante / Contacto', 'ancho' => 22, 'align' => 'L'],
        ['clave' => 'Version',         'titulo' => 'Versión',                  'ancho' => 8,  'align' => 'C'],
        ['clave' => 'EstadoTexto',     'titulo' => 'Estado Cobertura',         'ancho' => 12, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'Fecha',           'titulo' => 'Última Fecha',             'ancho' => 10, 'align' => 'L'],
    ];

    $filasFormateadas = [];
    foreach ($filasPdf as $f) {
        $estado = $f['EstadoCobertura'] ?? 'PENDIENTE';
        $fecha  = !empty($f['UltimaFechaConsentimiento'])
            ? f_fecha($f['UltimaFechaConsentimiento'])
            : (!empty($f['UltimaFechaRevocacion']) ? f_fecha($f['UltimaFechaRevocacion']) : '—');

        $colorEstado = [200, 16, 46]; // Rojo
        if ($estado === 'CONSENTIDO') {
            $colorEstado = [18, 115, 74];  // Verde
        } elseif ($estado === 'PENDIENTE') {
            $colorEstado = [180, 120, 10]; // Naranja / Ámbar
        }

        $filasFormateadas[] = [
            'Titular'            => $f['Titular'] ?? '—',
            'Documento'          => $f['Documento'] ?: '—',
            'TipoPersona'        => $f['TipoPersona'] ?? '—',
            'Representante'      => $f['Representante'] ?: '—',
            'Version'            => !empty($f['VersionPolitica']) ? 'v' . $f['VersionPolitica'] : '—',
            'EstadoTexto'        => $estado === 'CONSENTIDO' ? 'CONSENTIDO' : ($estado === 'PENDIENTE' ? 'PENDIENTE' : 'REVOCADO'),
            'Fecha'              => $fecha,
            '_color_EstadoTexto' => $colorEstado,
        ];
    }

    $pdf->tabla($columnas, $filasFormateadas);

    $poblacionTot = (int)($metaPdf['total'] ?? 0);
    $consTot      = (int)($metaPdf['consentidos'] ?? 0);
    $pendTot      = (int)($metaPdf['pendientes'] ?? 0);
    $revTot       = (int)($metaPdf['revocados'] ?? 0);
    $pctCob       = $metaPdf['pct_cobertura'] ?? 0;
    $pctPen       = $metaPdf['pct_pendientes'] ?? 0;

    $pdf->parrafo(sprintf(
        'Población objetivo: %d personas   ·   Consentimientos vigentes: %d (%.1f%%)   ·   Pendientes: %d (%.1f%%)   ·   Revocados: %d',
        $poblacionTot, $consTot, (float)$pctCob, $pendTot, (float)$pctPen, $revTot
    ), 8.5, 'B', [40, 44, 52]);

    $pdf->salida('rea_cobertura_pendientes_' . date('Ymd_His') . '.pdf');
    exit;
}

/* ---------------------------------------------------------------------------
   Consulta en pantalla
   --------------------------------------------------------------------------- */
$listado = apiGet('reportes/cobertura', $parametros + [
    'pagina'     => max(1, (int)($_GET['pagina'] ?? 1)),
    'por_pagina' => 15,
]);

$registros = apiDatos($listado, []);
$kpis      = apiMeta($listado, 'kpis', [
    'total' => 0, 'consentidos' => 0, 'pendientes' => 0, 'revocados' => 0,
    'pct_cobertura' => 0, 'pct_pendientes' => 0, 'pct_revocados' => 0
]);
$versiones = apiMeta($listado, 'versiones', []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$urlPdf = 'reporte_cobertura.php?' . http_build_query($parametros + ['formato' => 'pdf']);

$totalPoblacion = (int)($kpis['total'] ?? 0);
$totalCons      = (int)($kpis['consentidos'] ?? 0);
$totalPend      = (int)($kpis['pendientes'] ?? 0);
$totalRev       = (int)($kpis['revocados'] ?? 0);
$pctCob         = (float)($kpis['pct_cobertura'] ?? 0);
$pctPend        = (float)($kpis['pct_pendientes'] ?? 0);
$pctRev         = (float)($kpis['pct_revocados'] ?? 0);

$pageTitle = 'Reporte de Cobertura y Pendientes';
$breadcrumb = [['label' => 'Reportes', 'url' => null], ['label' => 'Cobertura y Pendientes', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header no-imprimir">
    <div>
        <h1>🎯 Reporte de Cobertura y Pendientes</h1>
        <p>Monitoreo de cumplimiento de la LOPDP: identifique qué personas han otorgado su consentimiento y quiénes siguen pendientes.</p>
    </div>
    <div class="flex-gap">
        <a href="<?= e($urlPdf) ?>" class="btn btn-primario" target="_blank" rel="noopener">Exportar a PDF</a>
    </div>
</div>

<!-- ================= KPIs de Cobertura ================= -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-valor"><?= $totalPoblacion ?></div>
        <div class="kpi-label">Población Activa</div>
    </div>
    <div class="kpi-card kpi-alt-2">
        <div class="kpi-valor"><?= $totalCons ?> <span style="font-size:0.95rem;font-weight:500;">(<?= $pctCob ?>%)</span></div>
        <div class="kpi-label">Consentimientos Vigentes</div>
    </div>
    <div class="kpi-card kpi-alt-3" style="border-left:4px solid #c8102e;">
        <div class="kpi-valor"><?= $totalPend ?> <span style="font-size:0.95rem;font-weight:500;">(<?= $pctPend ?>%)</span></div>
        <div class="kpi-label">Pendientes por Firmar</div>
    </div>
    <div class="kpi-card kpi-alt-1">
        <div class="kpi-valor"><?= $totalRev ?> <span style="font-size:0.95rem;font-weight:500;">(<?= $pctRev ?>%)</span></div>
        <div class="kpi-label">Revocados</div>
    </div>
</div>

<!-- ================= Barra de Progreso Visual ================= -->
<div class="card no-imprimir" style="margin-bottom:1.25rem;">
    <div class="flex-entre" style="margin-bottom:8px;">
        <h3 class="mb-0" style="font-size:1.05rem;">Nivel de Cumplimiento Global (<?= $resumenFiltros['Tipo de titular'] ?>)</h3>
        <span class="texto-mutado" style="font-weight:600;">
            <span style="color:var(--color-exito);"><?= $pctCob ?>% Vigentes</span> &nbsp;·&nbsp;
            <span style="color:var(--rea-rojo);"><?= $pctPend ?>% Pendientes</span>
            <?php if ($pctRev > 0): ?> &nbsp;·&nbsp; <span style="color:var(--rea-gris-600);"><?= $pctRev ?>% Revocados</span><?php endif; ?>
        </span>
    </div>
    <div style="background:var(--rea-gris-200);border-radius:999px;overflow:hidden;height:16px;display:flex;">
        <div title="Vigentes: <?= $pctCob ?>%" style="background:var(--color-exito);width:<?= $pctCob ?>%;height:100%;transition:width .3s;"></div>
        <div title="Pendientes: <?= $pctPend ?>%" style="background:var(--rea-rojo);width:<?= $pctPend ?>%;height:100%;transition:width .3s;"></div>
        <div title="Revocados: <?= $pctRev ?>%" style="background:var(--rea-gris-600);width:<?= $pctRev ?>%;height:100%;transition:width .3s;"></div>
    </div>
</div>

<!-- ================= Filtros de Búsqueda ================= -->
<div class="card no-imprimir">
    <h3>Filtros de consulta</h3>
    <form method="GET" action="reporte_cobertura.php">
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
                <label for="estado_cobertura">Estado del consentimiento</label>
                <select name="estado_cobertura" id="estado_cobertura">
                    <?php foreach ($estadosDisponibles as $val => $etiq): ?>
                        <option value="<?= e($val) ?>" <?= $filtroEstado === $val ? 'selected' : '' ?>><?= e($etiq) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="version">Versión de Política</label>
                <select name="version" id="version">
                    <option value="todas" <?= $filtroVersion === 'todas' ? 'selected' : '' ?>>Todas las versiones</option>
                    <?php foreach ($versiones as $ver): ?>
                        <option value="<?= e($ver) ?>" <?= $filtroVersion === $ver ? 'selected' : '' ?>>v<?= e($ver) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group" style="flex:1;">
                <label for="q">Buscar titular o documento</label>
                <input type="text" name="q" id="q" value="<?= e($filtroTexto) ?>" placeholder="Nombres, apellidos, cédula, código estudiantil...">
            </div>
        </div>
        <div class="flex-gap">
            <button type="submit" class="btn btn-primario">🔍 Consultar</button>
            <a href="reporte_cobertura.php" class="btn btn-secundario">Limpiar filtros</a>
        </div>
    </form>
</div>

<!-- ================= Tabla de Resultados ================= -->
<div class="card">
    <div class="flex-entre">
        <h3 class="mb-0">Padrón de Titulares y Estado de Consentimiento</h3>
        <span class="texto-mutado">
            <?= implode(' &middot; ', array_map(
                fn($k, $v) => e($k) . ': <strong>' . e($v) . '</strong>',
                array_keys($resumenFiltros),
                $resumenFiltros
            )) ?>
        </span>
    </div>

    <div class="tabla-wrap" style="margin-top:14px;">
        <table class="tabla-datos">
            <thead>
                <tr>
                    <th>Titular</th>
                    <th>Documento</th>
                    <th>Tipo</th>
                    <th>Representante / Contacto</th>
                    <th style="text-align:center;">Versión</th>
                    <th>Estado Cobertura</th>
                    <th>Última Fecha</th>
                    <th class="no-imprimir" style="text-align:center;">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($registros)): ?>
                    <tr><td colspan="8" class="tabla-vacia">No se encontraron personas con los filtros seleccionados.</td></tr>
                <?php endif; ?>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td>
                            <a href="../consultas/buscar_persona.php?id=<?= e((string)$r['PersonaId']) ?>" style="font-weight:600;" title="Ver ficha 360°">
                                <?= e($r['Titular']) ?>
                            </a>
                            <?php if (!empty($r['CodigoEstudiante'])): ?>
                                <br><small class="texto-mutado">Cód: <?= e($r['CodigoEstudiante']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($r['Documento']) ?></td>
                        <td><span class="badge badge-neutro"><?= e($r['TipoPersona']) ?></span></td>
                        <td>
                            <?php if ($r['Representante'] !== '—'): ?>
                                <span title="Representante"><?= e($r['Representante']) ?></span>
                            <?php else: ?>
                                <span class="texto-mutado">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if (!empty($r['VersionPolitica'])): ?>
                                <span class="badge badge-neutro">v<?= e($r['VersionPolitica']) ?></span>
                            <?php else: ?>
                                <span class="texto-mutado">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['EstadoCobertura'] === 'CONSENTIDO'): ?>
                                <span class="badge badge-activo">CONSENTIDO</span>
                            <?php elseif ($r['EstadoCobertura'] === 'PENDIENTE'): ?>
                                <span class="badge" style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;">PENDIENTE</span>
                            <?php else: ?>
                                <span class="badge badge-inactivo">REVOCADO</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($r['UltimaFechaConsentimiento'])): ?>
                                <?= f_fecha($r['UltimaFechaConsentimiento']) ?>
                            <?php elseif (!empty($r['UltimaFechaRevocacion'])): ?>
                                <span class="texto-mutado"><?= f_fecha($r['UltimaFechaRevocacion']) ?> (rev.)</span>
                            <?php else: ?>
                                <span class="texto-mutado">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="no-imprimir" style="text-align:center;">
                            <a href="../consultas/buscar_persona.php?id=<?= e((string)$r['PersonaId']) ?>" class="btn btn-sm btn-secundario" title="Ver ficha 360°">
                                Ficha 360°
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php renderPaginacion($numPagina, $totalPaginas); ?>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
