<?php
// reportes/reporte_titulares.php
// Consulta de titulares que otorgaron o revocaron su consentimiento.
// Filtros: estado, tipo de persona, finalidad, medio y rango de fechas.
// Los datos vienen de la API REST (/api/reportes/titulares) y el PDF se genera con includes/pdf_reporte.php.
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('reporte_titulares');
$institucionId = institucionActual();

/* ---------------------------------------------------------------------------
   Filtros
   --------------------------------------------------------------------------- */
$estadosDisponibles = [
    ''         => 'Todos los estados',
    'ACTIVO'   => 'Consentimiento vigente',
    'INACTIVO' => 'Consentimiento revocado',
];
$tiposDisponibles = [
    'todos'       => 'Todos los tipos',
    'estudiantes' => 'Estudiantes',
    'empleados'   => 'Empleados / Docentes',
    'proveedores' => 'Proveedores',
];
$mediosDisponibles = [
    'TODOS'    => 'Todos los canales',
    'WEB'      => 'Web',
    'EMAIL'    => 'Email',
    'WHATSAPP' => 'WhatsApp',
    'APP'      => 'App / Presencial',
];

$filtroEstado    = $_GET['estado'] ?? '';
if (!array_key_exists($filtroEstado, $estadosDisponibles)) {
    $filtroEstado = '';
}

$filtroTipo = $_GET['tipo'] ?? 'todos';
if (!array_key_exists($filtroTipo, $tiposDisponibles)) {
    $filtroTipo = 'todos';
}

$filtroFinalidad = (int)($_GET['finalidad_id'] ?? 0);
$filtroMedio     = strtoupper($_GET['medio'] ?? 'TODOS');
if (!array_key_exists($filtroMedio, $mediosDisponibles)) {
    $filtroMedio = 'TODOS';
}

$filtroDesde  = trim($_GET['desde'] ?? '');
$filtroHasta  = trim($_GET['hasta'] ?? '');
$filtroTexto  = trim($_GET['q'] ?? '');
$formato      = $_GET['formato'] ?? '';

// La consulta se ejecuta por defecto o al filtrar/paginar/exportar
$consultado = true;

$errorFechas = '';
if ($filtroDesde !== '' && $filtroHasta !== '' && $filtroDesde > $filtroHasta) {
    $errorFechas = 'La fecha inicial no puede ser posterior a la fecha final.';
}

$parametros = [
    'estado'       => $filtroEstado,
    'tipo'         => $filtroTipo,
    'finalidad_id' => $filtroFinalidad ?: '',
    'medio'        => $filtroMedio,
    'desde'        => $filtroDesde,
    'hasta'        => $filtroHasta,
    'q'            => $filtroTexto,
];

/* ---------------------------------------------------------------------------
   Exportación a PDF (usa los mismos filtros, sin paginar)
   --------------------------------------------------------------------------- */
if ($formato === 'pdf') {
    require_once __DIR__ . '/../includes/pdf_reporte.php';

    $respuestaPdf = apiGet('reportes/titulares', $parametros + ['pagina' => 1, 'por_pagina' => 500]);

    if (!$respuestaPdf['ok']) {
        flashSet('error', 'No se pudo generar el PDF: ' . apiError($respuestaPdf));
        redirigir('reporte_titulares.php?' . http_build_query($parametros));
    }

    $filasPdf     = apiDatos($respuestaPdf, []);
    $totalesPdf   = apiMeta($respuestaPdf, 'totales', []);
    $finalidades  = apiMeta($respuestaPdf, 'finalidades', []);

    if (empty($filasPdf)) {
        flashSet('advertencia', 'No se encontraron registros para exportar con los filtros seleccionados.');
        redirigir('reporte_titulares.php?' . http_build_query($parametros));
    }

    $nombreFinalidad = 'Todas las finalidades';
    foreach ($finalidades as $fn) {
        if ((int)$fn['FinalidadId'] === $filtroFinalidad) {
            $nombreFinalidad = $fn['Nombre'];
            break;
        }
    }

    $resumenFiltros = [
        'Institución'     => $_SESSION['institucion_nombre'] ?? ('#' . $institucionId),
        'Estado'          => $estadosDisponibles[$filtroEstado],
        'Tipo de titular' => $tiposDisponibles[$filtroTipo],
        'Finalidad'       => $nombreFinalidad,
        'Canal'           => $mediosDisponibles[$filtroMedio],
        'Período'         => ($filtroDesde !== '' || $filtroHasta !== '')
            ? (($filtroDesde !== '' ? f_fecha($filtroDesde, 'd/m/Y') : 'inicio') . ' a ' .
               ($filtroHasta !== '' ? f_fecha($filtroHasta, 'd/m/Y') : 'hoy'))
            : 'Todas las fechas',
    ];
    if ($filtroTexto !== '') {
        $resumenFiltros['Búsqueda'] = $filtroTexto;
    }

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
            . 'Protección de Datos Personales (LOPDP); su tratamiento se limita a la finalidad autorizada por el titular.',
    ]);

    $columnas = [
        ['clave' => 'Titular',             'titulo' => 'Titular',              'ancho' => 24, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'Identificacion',      'titulo' => 'Documento',            'ancho' => 12, 'align' => 'L'],
        ['clave' => 'TipoPersona',         'titulo' => 'Tipo',                 'ancho' => 12, 'align' => 'L'],
        ['clave' => 'FinalidadNombre',     'titulo' => 'Finalidad',            'ancho' => 20, 'align' => 'L'],
        ['clave' => 'MedioConsentimiento', 'titulo' => 'Canal',                'ancho' => 10, 'align' => 'L'],
        ['clave' => 'EstadoTexto',         'titulo' => 'Estado',               'ancho' => 12, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'FechaConsentimiento', 'titulo' => 'Fecha Otorgamiento',   'ancho' => 10, 'align' => 'L'],
    ];

    $filasFormateadas = [];
    foreach ($filasPdf as $fila) {
        $filasFormateadas[] = [
            'Titular'             => $fila['Titular'] ?? '—',
            'Identificacion'      => $fila['Identificacion'] ?: '—',
            'TipoPersona'         => $fila['TipoPersona'] ?? '—',
            'FinalidadNombre'     => $fila['FinalidadNombre'] ?: '—',
            'MedioConsentimiento' => $fila['MedioConsentimiento'] ?: '—',
            'EstadoTexto'         => $fila['EstadoTexto'] ?? '',
            'FechaConsentimiento' => f_fecha($fila['FechaConsentimiento'] ?? null),
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

    $pdf->salida('rea_consentimientos_titulares_' . date('Ymd_His') . '.pdf');
    exit;
}

/* ---------------------------------------------------------------------------
   Consulta en pantalla
   --------------------------------------------------------------------------- */
$listado = apiGet('reportes/titulares', $parametros + [
    'pagina'     => max(1, (int)($_GET['pagina'] ?? 1)),
    'por_pagina' => 15,
]);

$registros   = apiDatos($listado, []);
$totales     = apiMeta($listado, 'totales', ['registros' => 0, 'consentidos' => 0, 'revocados' => 0]);
$finalidades = apiMeta($listado, 'finalidades', []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$nombreFinalidad = 'Todas las finalidades';
foreach ($finalidades as $fn) {
    if ((int)$fn['FinalidadId'] === $filtroFinalidad) {
        $nombreFinalidad = $fn['Nombre'];
        break;
    }
}

$resumenFiltros = [
    'Estado'          => $estadosDisponibles[$filtroEstado],
    'Tipo de titular' => $tiposDisponibles[$filtroTipo],
    'Finalidad'       => $nombreFinalidad,
    'Canal'           => $mediosDisponibles[$filtroMedio],
    'Período'         => ($filtroDesde !== '' || $filtroHasta !== '')
        ? (($filtroDesde !== '' ? f_fecha($filtroDesde, 'd/m/Y') : 'inicio') . ' a ' .
           ($filtroHasta !== '' ? f_fecha($filtroHasta, 'd/m/Y') : 'hoy'))
        : 'Todas las fechas',
];
if ($filtroTexto !== '') {
    $resumenFiltros['Búsqueda'] = $filtroTexto;
}

$urlPdf = 'reporte_titulares.php?' . http_build_query($parametros + ['formato' => 'pdf']);
$hayResultados = !empty($registros);

$pageTitle = 'Consentimientos por Titular';
$breadcrumb = [['label' => 'Reportes', 'url' => null], ['label' => 'Consentimientos por Titular', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header no-imprimir">
    <div>
        <h1>🧾 Consentimientos por Titular</h1>
        <p>Detalle de las personas que otorgaron o revocaron su consentimiento para el tratamiento de datos personales.</p>
    </div>
    <div class="flex-gap">
        <?php if ($hayResultados): ?>
            <a href="<?= e($urlPdf) ?>" class="btn btn-primario" target="_blank" rel="noopener">Exportar a PDF</a>
        <?php else: ?>
            <button type="button" class="btn btn-primario" disabled title="No hay datos disponibles para exportar">
                Exportar a PDF
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- ================= KPIs ================= -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-valor"><?= (int)($totales['registros'] ?? 0) ?></div>
        <div class="kpi-label">Registros Encontrados</div>
    </div>
    <div class="kpi-card kpi-alt-2">
        <div class="kpi-valor"><?= (int)($totales['consentidos'] ?? 0) ?></div>
        <div class="kpi-label">Consentimientos Vigentes</div>
    </div>
    <div class="kpi-card kpi-alt-3">
        <div class="kpi-valor"><?= (int)($totales['revocados'] ?? 0) ?></div>
        <div class="kpi-label">Consentimientos Revocados</div>
    </div>
</div>

<!-- ================= Filtros ================= -->
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
                <label for="tipo">Tipo de titular</label>
                <select name="tipo" id="tipo">
                    <?php foreach ($tiposDisponibles as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $filtroTipo === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="finalidad_id">Finalidad</label>
                <select name="finalidad_id" id="finalidad_id">
                    <option value="">Todas las finalidades</option>
                    <?php foreach ($finalidades as $fn): ?>
                        <option value="<?= e((string)$fn['FinalidadId']) ?>" <?= $filtroFinalidad == $fn['FinalidadId'] ? 'selected' : '' ?>><?= e($fn['Nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="medio">Canal / Medio</label>
                <select name="medio" id="medio">
                    <?php foreach ($mediosDisponibles as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $filtroMedio === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="desde">Fecha inicial <span class="texto-mutado">(opcional)</span></label>
                <input type="date" name="desde" id="desde" value="<?= e($filtroDesde) ?>">
            </div>
            <div class="form-group">
                <label for="hasta">Fecha final <span class="texto-mutado">(opcional)</span></label>
                <input type="date" name="hasta" id="hasta" value="<?= e($filtroHasta) ?>">
            </div>
            <div class="form-group" style="flex:2;">
                <label for="q">Titular o identificación</label>
                <input type="text" name="q" id="q" value="<?= e($filtroTexto) ?>" placeholder="Nombres, apellidos o cédula...">
            </div>
        </div>
        <div class="flex-gap">
            <button type="submit" class="btn btn-primario">🔍 Consultar</button>
            <a href="reporte_titulares.php" class="btn btn-secundario">Limpiar filtros</a>
        </div>
    </form>
</div>

<!-- ================= Tabla de Resultados ================= -->
<div class="card">
    <div class="flex-entre">
        <h3 class="mb-0">Detalle de Titulares y Consentimientos</h3>
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
                    <th>Finalidad</th>
                    <th>Canal</th>
                    <th>Estado</th>
                    <th>Fecha Otorgamiento</th>
                    <th class="no-imprimir" style="text-align:center;">Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($registros)): ?>
                    <tr><td colspan="8" class="tabla-vacia">No se encontraron registros con los filtros seleccionados.</td></tr>
                <?php endif; ?>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td>
                            <a href="../consultas/buscar_persona.php?id=<?= e((string)$r['PersonaId']) ?>" style="font-weight:600;" title="Ver ficha 360°">
                                <?= e($r['Titular']) ?>
                            </a>
                        </td>
                        <td><?= e($r['Identificacion'] ?: '—') ?></td>
                        <td><span class="badge badge-neutro"><?= e($r['TipoPersona']) ?></span></td>
                        <td><?= e($r['FinalidadNombre'] ?: '—') ?></td>
                        <td><span class="badge badge-info"><?= e($r['MedioConsentimiento'] ?: 'Web') ?></span></td>
                        <td>
                            <span class="badge <?= $r['Estado'] === 'ACTIVO' ? 'badge-activo' : 'badge-inactivo' ?>">
                                <?= e($r['EstadoTexto']) ?>
                            </span>
                        </td>
                        <td><?= f_fecha($r['FechaConsentimiento']) ?></td>
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
