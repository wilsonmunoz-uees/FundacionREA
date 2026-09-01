<?php
// reportes/reporte_titulares.php
// Consulta de titulares que otorgaron o revocaron su consentimiento.
// Filtros: estado, tipo de persona y rango de fechas (los tres opcionales).
// Los datos vienen de la API REST (/api/reportes/titulares) y el PDF se arma
// aquí con includes/pdf_reporte.php, sin librerías externas.
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('reporte_titulares');
$institucionId = institucionActual();

/* ---------------------------------------------------------------------------
   Filtros
   --------------------------------------------------------------------------- */
$estadosDisponibles = [
    ''         => 'Todos',
    'ACTIVO'   => 'Consentimiento',
    'INACTIVO' => 'Revocado',
];
$tiposDisponibles = [
    'todos'       => 'Todos',
    'estudiantes' => 'Estudiantes',
    'empleados'   => 'Empleados',
    'proveedores' => 'Proveedores',
];

$filtroEstado = $_GET['estado'] ?? '';
if (!array_key_exists($filtroEstado, $estadosDisponibles)) {
    $filtroEstado = '';
}

$filtroTipo = $_GET['tipo'] ?? 'todos';
if (!array_key_exists($filtroTipo, $tiposDisponibles)) {
    $filtroTipo = 'todos';
}

$filtroDesde  = trim($_GET['desde'] ?? '');
$filtroHasta  = trim($_GET['hasta'] ?? '');
$filtroTexto  = trim($_GET['q'] ?? '');
$formato      = $_GET['formato'] ?? '';

// La consulta se ejecuta al pulsar Consultar (o al paginar / exportar)
$consultado = isset($_GET['consultar']) || $formato === 'pdf' || isset($_GET['pagina']);

$errorFechas = '';
if ($filtroDesde !== '' && $filtroHasta !== '' && $filtroDesde > $filtroHasta) {
    $errorFechas = 'La fecha inicial no puede ser posterior a la fecha final.';
    $consultado  = false;
}

$parametros = [
    'estado' => $filtroEstado,
    'tipo'   => $filtroTipo,
    'desde'  => $filtroDesde,
    'hasta'  => $filtroHasta,
    'q'      => $filtroTexto,
];

/* Resumen de filtros, tal como se muestra en pantalla y en el PDF */
$resumenFiltros = [
    'Estado'  => $estadosDisponibles[$filtroEstado],
    'Tipo'    => $tiposDisponibles[$filtroTipo],
    'Período' => ($filtroDesde !== '' || $filtroHasta !== '')
        ? (($filtroDesde !== '' ? f_fecha($filtroDesde, 'd/m/Y') : 'inicio') . ' a ' .
           ($filtroHasta !== '' ? f_fecha($filtroHasta, 'd/m/Y') : 'hoy'))
        : 'Todas las fechas',
];
if ($filtroTexto !== '') {
    $resumenFiltros['Búsqueda'] = $filtroTexto;
}

/* ---------------------------------------------------------------------------
   Exportación a PDF (usa los mismos filtros, sin paginar)
   --------------------------------------------------------------------------- */
if ($consultado && $formato === 'pdf') {
    require_once __DIR__ . '/../includes/pdf_reporte.php';

    $respuestaPdf = apiGet('reportes/titulares', $parametros + ['pagina' => 1, 'por_pagina' => 500]);

    if (!$respuestaPdf['ok']) {
        flashSet('error', 'No se pudo generar el PDF: ' . apiError($respuestaPdf));
        redirigir('reporte_titulares.php?' . http_build_query($parametros + ['consultar' => 1]));
    }

    $filasPdf   = apiDatos($respuestaPdf, []);
    $totalesPdf = apiMeta($respuestaPdf, 'totales', []);

    $pdf = new PdfReporte('H');

    $pdf->cabecera([
        'logo'        => __DIR__ . '/../assets/logo.png',
        'institucion' => $_SESSION['institucion_nombre'] ?? 'Red Educativa Arquidiocesana',
        'titulo'      => 'Reporte de Consentimientos por Titular',
        'subtitulo'   => 'Registro de consentimientos otorgados y revocados para el tratamiento de datos personales',
        'filtros'     => $resumenFiltros,
    ]);

    $pdf->pie([
        'usuario'    => ($_SESSION['username'] ?? 'sistema')
            . (!empty($_SESSION['roles']) ? ' (' . implode(', ', $_SESSION['roles']) . ')' : ''),
        'disclaimer' => 'Documento de uso interno y confidencial. Contiene datos personales protegidos por la Ley Orgánica de '
            . 'Protección de Datos Personales; su tratamiento se limita a la finalidad autorizada por el titular y su divulgación '
            . 'no autorizada está prohibida.',
    ]);

    $columnas = [
        ['clave' => 'FechaConsentimiento', 'titulo' => 'Fecha consentimiento', 'ancho' => 15, 'align' => 'L'],
        ['clave' => 'FechaRevocacion',     'titulo' => 'Fecha revocación',     'ancho' => 14, 'align' => 'L'],
        ['clave' => 'Titular',             'titulo' => 'Titular',              'ancho' => 26, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'Identificacion',      'titulo' => 'Identificación',       'ancho' => 12, 'align' => 'L'],
        ['clave' => 'TipoPersona',         'titulo' => 'Tipo',                 'ancho' => 12, 'align' => 'L'],
        ['clave' => 'EstadoTexto',         'titulo' => 'Estado',               'ancho' => 10, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'IpOrigen',            'titulo' => 'IP de origen',         'ancho' => 11, 'align' => 'L'],
    ];

    $filasFormateadas = [];
    foreach ($filasPdf as $fila) {
        $filasFormateadas[] = [
            'FechaConsentimiento' => f_fecha($fila['FechaConsentimiento'] ?? null),
            'FechaRevocacion'     => f_fecha($fila['FechaRevocacion'] ?? null),
            'Titular'             => $fila['Titular'] ?? '—',
            'Identificacion'      => $fila['Identificacion'] ?: '—',
            'TipoPersona'         => $fila['TipoPersona'] ?? '—',
            'EstadoTexto'         => $fila['EstadoTexto'] ?? '',
            'IpOrigen'            => $fila['IpOrigen'] ?: '—',
            // El estado se pinta en color según corresponda
            '_color_EstadoTexto'  => ($fila['Estado'] ?? '') === 'ACTIVO' ? [18, 115, 74] : [200, 16, 46],
        ];
    }

    $pdf->tabla($columnas, $filasFormateadas);
    $pdf->parrafo(sprintf(
        'Total de registros: %d   ·   Consentimientos vigentes: %d   ·   Consentimientos revocados: %d',
        (int)($totalesPdf['registros'] ?? 0),
        (int)($totalesPdf['consentidos'] ?? 0),
        (int)($totalesPdf['revocados'] ?? 0)
    ), 8.5, 'B', [40, 44, 52]);

    if (count($filasPdf) < (int)($totalesPdf['registros'] ?? 0)) {
        $pdf->parrafo('Nota: el reporte muestra los primeros ' . count($filasPdf)
            . ' registros. Ajuste los filtros para obtener un detalle más acotado.', 7.5);
    }

    $pdf->salida('rea_consentimientos_titulares_' . date('Ymd_His') . '.pdf');
    exit;
}

/* ---------------------------------------------------------------------------
   Consulta en pantalla
   --------------------------------------------------------------------------- */
$registros   = [];
$totales     = ['registros' => 0, 'consentidos' => 0, 'revocados' => 0];
$numPagina   = 1;
$totalPaginas = 1;

if ($consultado) {
    $listado = apiGet('reportes/titulares', $parametros + [
        'pagina'     => max(1, (int)($_GET['pagina'] ?? 1)),
        'por_pagina' => 15,
    ]);

    $registros = apiDatos($listado, []);
    $totales   = apiMeta($listado, 'totales', $totales);
    [$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

    if (!$listado['ok']) {
        flashSet('error', apiError($listado));
        $consultado = false;
    }
}

$hayResultados = $consultado && !empty($registros);
$urlPdf = 'reporte_titulares.php?' . http_build_query($parametros + ['consultar' => 1, 'formato' => 'pdf']);

$pageTitle = 'Consentimientos por Titular';
$breadcrumb = [['label' => 'Reportes', 'url' => null], ['label' => 'Consentimientos por Titular', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header no-imprimir">
    <div>
        <h1>🧾 Consentimientos por Titular</h1>
        <p>Detalle de las personas que otorgaron o revocaron su consentimiento para el tratamiento de datos.</p>
    </div>
    <div class="flex-gap">
        <?php if ($hayResultados): ?>
            <a href="<?= e($urlPdf) ?>" class="btn btn-primario" target="_blank" rel="noopener">📄 Exportar a PDF</a>
        <?php else: ?>
            <button type="button" class="btn btn-primario" disabled title="Ejecute una consulta para habilitar la exportación">
                📄 Exportar a PDF
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="card no-imprimir">
    <h3>Filtros de consulta</h3>
    <?php if ($errorFechas !== ''): ?>
        <div class="alerta alerta-error"><?= e($errorFechas) ?></div>
    <?php endif; ?>

    <form method="GET" action="reporte_titulares.php">
        <div class="form-row">
            <div class="form-group">
                <label for="estado">Estado del consentimiento</label>
                <select name="estado" id="estado">
                    <?php foreach ($estadosDisponibles as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $filtroEstado === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="tipo">Tipo de persona</label>
                <select name="tipo" id="tipo">
                    <?php foreach ($tiposDisponibles as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $filtroTipo === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="desde">Fecha inicial <span class="texto-mutado">(opcional)</span></label>
                <input type="date" name="desde" id="desde" value="<?= e($filtroDesde) ?>">
            </div>
            <div class="form-group">
                <label for="hasta">Fecha final <span class="texto-mutado">(opcional)</span></label>
                <input type="date" name="hasta" id="hasta" value="<?= e($filtroHasta) ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group" style="flex:2;">
                <label for="q">Titular <span class="texto-mutado">(opcional)</span></label>
                <input type="text" name="q" id="q" value="<?= e($filtroTexto) ?>" placeholder="Nombres, apellidos o identificación...">
            </div>
        </div>
        <div class="flex-gap">
            <button type="submit" name="consultar" value="1" class="btn btn-primario">🔍 Consultar</button>
            <a href="reporte_titulares.php" class="btn btn-secundario">Limpiar filtros</a>
        </div>
    </form>
</div>

<?php if (!$consultado): ?>
    <div class="alerta alerta-info">
        Seleccione los filtros que necesite y pulse <strong>Consultar</strong>. Todos los filtros son opcionales:
        sin cambiar nada obtendrá el detalle completo de la institución.
    </div>
<?php else: ?>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-valor"><?= (int)($totales['registros'] ?? 0) ?></div>
            <div class="kpi-label">Registros encontrados</div>
        </div>
        <div class="kpi-card kpi-alt-2">
            <div class="kpi-valor"><?= (int)($totales['consentidos'] ?? 0) ?></div>
            <div class="kpi-label">Consentimientos vigentes</div>
        </div>
        <div class="kpi-card kpi-alt-3">
            <div class="kpi-valor"><?= (int)($totales['revocados'] ?? 0) ?></div>
            <div class="kpi-label">Consentimientos revocados</div>
        </div>
    </div>

    <div class="card">
        <div class="flex-entre">
            <h3 class="mb-0">Detalle de titulares</h3>
            <span class="texto-mutado">
                <?= implode(' &middot; ', array_map(
                    fn($k, $v) => e($k) . ': <strong>' . e($v) . '</strong>',
                    array_keys($resumenFiltros),
                    $resumenFiltros
                )) ?>
            </span>
        </div>

        <div class="tabla-wrap" style="margin-top:12px;">
            <table class="tabla-datos">
                <thead>
                <tr>
                    <th>Fecha Consentimiento</th>
                    <th>Fecha Revocación</th>
                    <th>Titular</th>
                    <th>Identificación</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>IP de Origen</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($registros)): ?>
                    <tr><td colspan="7" class="tabla-vacia">No se encontraron registros con los filtros seleccionados.</td></tr>
                <?php endif; ?>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td><?= f_fecha($r['FechaConsentimiento']) ?></td>
                        <td><?= f_fecha($r['FechaRevocacion']) ?></td>
                        <td><strong><?= e($r['Titular']) ?></strong></td>
                        <td><?= e($r['Identificacion'] ?: '—') ?></td>
                        <td><span class="badge badge-neutro"><?= e($r['TipoPersona']) ?></span></td>
                        <td>
                            <span class="badge <?= $r['Estado'] === 'ACTIVO' ? 'badge-activo' : 'badge-inactivo' ?>">
                                <?= e($r['EstadoTexto']) ?>
                            </span>
                        </td>
                        <td><?= e($r['IpOrigen'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPaginacion($numPagina, $totalPaginas); ?>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>

<?php
// reportes/reporte_titulares.php
// Consulta de titulares que otorgaron o revocaron su consentimiento.
// Filtros: estado, tipo de persona y rango de fechas (los tres opcionales).
// Los datos vienen de la API REST (/api/reportes/titulares) y el PDF se arma
// aquí con includes/pdf_reporte.php, sin librerías externas.
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('reporte_titulares');
$institucionId = institucionActual();

/* ---------------------------------------------------------------------------
   Filtros
   --------------------------------------------------------------------------- */
$estadosDisponibles = [
    ''         => 'Todos',
    'ACTIVO'   => 'Consentimiento',
    'INACTIVO' => 'Revocado',
];
$tiposDisponibles = [
    'todos'       => 'Todos',
    'estudiantes' => 'Estudiantes',
    'empleados'   => 'Empleados',
    'proveedores' => 'Proveedores',
];

$filtroEstado = $_GET['estado'] ?? '';
if (!array_key_exists($filtroEstado, $estadosDisponibles)) {
    $filtroEstado = '';
}

$filtroTipo = $_GET['tipo'] ?? 'todos';
if (!array_key_exists($filtroTipo, $tiposDisponibles)) {
    $filtroTipo = 'todos';
}

$filtroDesde  = trim($_GET['desde'] ?? '');
$filtroHasta  = trim($_GET['hasta'] ?? '');
$filtroTexto  = trim($_GET['q'] ?? '');
$formato      = $_GET['formato'] ?? '';

// La consulta se ejecuta al pulsar Consultar (o al paginar / exportar)
$consultado = isset($_GET['consultar']) || $formato === 'pdf' || isset($_GET['pagina']);

$errorFechas = '';
if ($filtroDesde !== '' && $filtroHasta !== '' && $filtroDesde > $filtroHasta) {
    $errorFechas = 'La fecha inicial no puede ser posterior a la fecha final.';
    $consultado  = false;
}

$parametros = [
    'estado' => $filtroEstado,
    'tipo'   => $filtroTipo,
    'desde'  => $filtroDesde,
    'hasta'  => $filtroHasta,
    'q'      => $filtroTexto,
];

/* Resumen de filtros, tal como se muestra en pantalla y en el PDF */
$resumenFiltros = [
    'Estado'  => $estadosDisponibles[$filtroEstado],
    'Tipo'    => $tiposDisponibles[$filtroTipo],
    'Período' => ($filtroDesde !== '' || $filtroHasta !== '')
        ? (($filtroDesde !== '' ? f_fecha($filtroDesde, 'd/m/Y') : 'inicio') . ' a ' .
           ($filtroHasta !== '' ? f_fecha($filtroHasta, 'd/m/Y') : 'hoy'))
        : 'Todas las fechas',
];
if ($filtroTexto !== '') {
    $resumenFiltros['Búsqueda'] = $filtroTexto;
}

/* ---------------------------------------------------------------------------
   Exportación a PDF (usa los mismos filtros, sin paginar)
   --------------------------------------------------------------------------- */
if ($consultado && $formato === 'pdf') {
    require_once __DIR__ . '/../includes/pdf_reporte.php';

    $respuestaPdf = apiGet('reportes/titulares', $parametros + ['pagina' => 1, 'por_pagina' => 500]);

    if (!$respuestaPdf['ok']) {
        flashSet('error', 'No se pudo generar el PDF: ' . apiError($respuestaPdf));
        redirigir('reporte_titulares.php?' . http_build_query($parametros + ['consultar' => 1]));
    }

    $filasPdf   = apiDatos($respuestaPdf, []);
    $totalesPdf = apiMeta($respuestaPdf, 'totales', []);

    $pdf = new PdfReporte('H');

    $pdf->cabecera([
        'logo'        => __DIR__ . '/../assets/logo.png',
        'institucion' => $_SESSION['institucion_nombre'] ?? 'Red Educativa Arquidiocesana',
        'titulo'      => 'Reporte de Consentimientos por Titular',
        'subtitulo'   => 'Registro de consentimientos otorgados y revocados para el tratamiento de datos personales',
        'filtros'     => $resumenFiltros,
    ]);

    $pdf->pie([
        'usuario'    => ($_SESSION['username'] ?? 'sistema')
            . (!empty($_SESSION['roles']) ? ' (' . implode(', ', $_SESSION['roles']) . ')' : ''),
        'disclaimer' => 'Documento de uso interno y confidencial. Contiene datos personales protegidos por la Ley Orgánica de '
            . 'Protección de Datos Personales; su tratamiento se limita a la finalidad autorizada por el titular y su divulgación '
            . 'no autorizada está prohibida.',
    ]);

    $columnas = [
        ['clave' => 'FechaConsentimiento', 'titulo' => 'Fecha consentimiento', 'ancho' => 15, 'align' => 'L'],
        ['clave' => 'FechaRevocacion',     'titulo' => 'Fecha revocación',     'ancho' => 14, 'align' => 'L'],
        ['clave' => 'Titular',             'titulo' => 'Titular',              'ancho' => 26, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'Identificacion',      'titulo' => 'Identificación',       'ancho' => 12, 'align' => 'L'],
        ['clave' => 'TipoPersona',         'titulo' => 'Tipo',                 'ancho' => 12, 'align' => 'L'],
        ['clave' => 'EstadoTexto',         'titulo' => 'Estado',               'ancho' => 10, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'IpOrigen',            'titulo' => 'IP de origen',         'ancho' => 11, 'align' => 'L'],
    ];

    $filasFormateadas = [];
    foreach ($filasPdf as $fila) {
        $filasFormateadas[] = [
            'FechaConsentimiento' => f_fecha($fila['FechaConsentimiento'] ?? null),
            'FechaRevocacion'     => f_fecha($fila['FechaRevocacion'] ?? null),
            'Titular'             => $fila['Titular'] ?? '—',
            'Identificacion'      => $fila['Identificacion'] ?: '—',
            'TipoPersona'         => $fila['TipoPersona'] ?? '—',
            'EstadoTexto'         => $fila['EstadoTexto'] ?? '',
            'IpOrigen'            => $fila['IpOrigen'] ?: '—',
            // El estado se pinta en color según corresponda
            '_color_EstadoTexto'  => ($fila['Estado'] ?? '') === 'ACTIVO' ? [18, 115, 74] : [200, 16, 46],
        ];
    }

    $pdf->tabla($columnas, $filasFormateadas);
    $pdf->parrafo(sprintf(
        'Total de registros: %d   ·   Consentimientos vigentes: %d   ·   Consentimientos revocados: %d',
        (int)($totalesPdf['registros'] ?? 0),
        (int)($totalesPdf['consentidos'] ?? 0),
        (int)($totalesPdf['revocados'] ?? 0)
    ), 8.5, 'B', [40, 44, 52]);

    if (count($filasPdf) < (int)($totalesPdf['registros'] ?? 0)) {
        $pdf->parrafo('Nota: el reporte muestra los primeros ' . count($filasPdf)
            . ' registros. Ajuste los filtros para obtener un detalle más acotado.', 7.5);
    }

    $pdf->salida('rea_consentimientos_titulares_' . date('Ymd_His') . '.pdf');
    exit;
}

/* ---------------------------------------------------------------------------
   Consulta en pantalla
   --------------------------------------------------------------------------- */
$registros   = [];
$totales     = ['registros' => 0, 'consentidos' => 0, 'revocados' => 0];
$numPagina   = 1;
$totalPaginas = 1;

if ($consultado) {
    $listado = apiGet('reportes/titulares', $parametros + [
        'pagina'     => max(1, (int)($_GET['pagina'] ?? 1)),
        'por_pagina' => 15,
    ]);

    $registros = apiDatos($listado, []);
    $totales   = apiMeta($listado, 'totales', $totales);
    [$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

    if (!$listado['ok']) {
        flashSet('error', apiError($listado));
        $consultado = false;
    }
}

$hayResultados = $consultado && !empty($registros);
$urlPdf = 'reporte_titulares.php?' . http_build_query($parametros + ['consultar' => 1, 'formato' => 'pdf']);

$pageTitle = 'Consentimientos por Titular';
$breadcrumb = [['label' => 'Reportes', 'url' => null], ['label' => 'Consentimientos por Titular', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header no-imprimir">
    <div>
        <h1>🧾 Consentimientos por Titular</h1>
        <p>Detalle de las personas que otorgaron o revocaron su consentimiento para el tratamiento de datos.</p>
    </div>
    <div class="flex-gap">
        <?php if ($hayResultados): ?>
            <a href="<?= e($urlPdf) ?>" class="btn btn-primario" target="_blank" rel="noopener">📄 Exportar a PDF</a>
        <?php else: ?>
            <button type="button" class="btn btn-primario" disabled title="Ejecute una consulta para habilitar la exportación">
                📄 Exportar a PDF
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="card no-imprimir">
    <h3>Filtros de consulta</h3>
    <?php if ($errorFechas !== ''): ?>
        <div class="alerta alerta-error"><?= e($errorFechas) ?></div>
    <?php endif; ?>

    <form method="GET" action="reporte_titulares.php">
        <div class="form-row">
            <div class="form-group">
                <label for="estado">Estado del consentimiento</label>
                <select name="estado" id="estado">
                    <?php foreach ($estadosDisponibles as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $filtroEstado === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="tipo">Tipo de persona</label>
                <select name="tipo" id="tipo">
                    <?php foreach ($tiposDisponibles as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $filtroTipo === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="desde">Fecha inicial <span class="texto-mutado">(opcional)</span></label>
                <input type="date" name="desde" id="desde" value="<?= e($filtroDesde) ?>">
            </div>
            <div class="form-group">
                <label for="hasta">Fecha final <span class="texto-mutado">(opcional)</span></label>
                <input type="date" name="hasta" id="hasta" value="<?= e($filtroHasta) ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group" style="flex:2;">
                <label for="q">Titular <span class="texto-mutado">(opcional)</span></label>
                <input type="text" name="q" id="q" value="<?= e($filtroTexto) ?>" placeholder="Nombres, apellidos o identificación...">
            </div>
        </div>
        <div class="flex-gap">
            <button type="submit" name="consultar" value="1" class="btn btn-primario">🔍 Consultar</button>
            <a href="reporte_titulares.php" class="btn btn-secundario">Limpiar filtros</a>
        </div>
    </form>
</div>

<?php if (!$consultado): ?>
    <div class="alerta alerta-info">
        Seleccione los filtros que necesite y pulse <strong>Consultar</strong>. Todos los filtros son opcionales:
        sin cambiar nada obtendrá el detalle completo de la institución.
    </div>
<?php else: ?>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-valor"><?= (int)($totales['registros'] ?? 0) ?></div>
            <div class="kpi-label">Registros encontrados</div>
        </div>
        <div class="kpi-card kpi-alt-2">
            <div class="kpi-valor"><?= (int)($totales['consentidos'] ?? 0) ?></div>
            <div class="kpi-label">Consentimientos vigentes</div>
        </div>
        <div class="kpi-card kpi-alt-3">
            <div class="kpi-valor"><?= (int)($totales['revocados'] ?? 0) ?></div>
            <div class="kpi-label">Consentimientos revocados</div>
        </div>
    </div>

    <div class="card">
        <div class="flex-entre">
            <h3 class="mb-0">Detalle de titulares</h3>
            <span class="texto-mutado">
                <?= implode(' &middot; ', array_map(
                    fn($k, $v) => e($k) . ': <strong>' . e($v) . '</strong>',
                    array_keys($resumenFiltros),
                    $resumenFiltros
                )) ?>
            </span>
        </div>

        <div class="tabla-wrap" style="margin-top:12px;">
            <table class="tabla-datos">
                <thead>
                <tr>
                    <th>Fecha Consentimiento</th>
                    <th>Fecha Revocación</th>
                    <th>Titular</th>
                    <th>Identificación</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th>IP de Origen</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($registros)): ?>
                    <tr><td colspan="7" class="tabla-vacia">No se encontraron registros con los filtros seleccionados.</td></tr>
                <?php endif; ?>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td><?= f_fecha($r['FechaConsentimiento']) ?></td>
                        <td><?= f_fecha($r['FechaRevocacion']) ?></td>
                        <td><strong><?= e($r['Titular']) ?></strong></td>
                        <td><?= e($r['Identificacion'] ?: '—') ?></td>
                        <td><span class="badge badge-neutro"><?= e($r['TipoPersona']) ?></span></td>
                        <td>
                            <span class="badge <?= $r['Estado'] === 'ACTIVO' ? 'badge-activo' : 'badge-inactivo' ?>">
                                <?= e($r['EstadoTexto']) ?>
                            </span>
                        </td>
                        <td><?= e($r['IpOrigen'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPaginacion($numPagina, $totalPaginas); ?>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
