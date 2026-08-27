<?php
// reportes/reporte_auditoria.php
// Bitácora de auditoría: todos los movimientos registrados en la base de datos
// para la institución educativa con la que se inició sesión.
// Filtros: rango de fechas, usuario (elegido en una subpantalla con pagineo),
// tabla, tipo de movimiento y texto libre. Los datos vienen de la API REST
// (/api/reportes/auditoria) y el PDF se arma con includes/pdf_reporte.php.
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/selector_persona.php';

requireAcceso('reporte_auditoria');
$institucionId = institucionActual();

/* ---------------------------------------------------------------------------
   Filtros
   --------------------------------------------------------------------------- */
$operacionesDisponibles = [
    ''       => 'Todos los movimientos',
    'INSERT' => 'Altas',
    'UPDATE' => 'Cambios',
    'DELETE' => 'Bajas',
];

$filtroDesde     = trim($_GET['desde'] ?? '');
$filtroHasta     = trim($_GET['hasta'] ?? '');
$filtroUsuario   = trim($_GET['username'] ?? '');
$filtroTabla     = trim($_GET['tabla'] ?? '');
$filtroOperacion = strtoupper(trim($_GET['operacion'] ?? ''));
$filtroTexto     = trim($_GET['q'] ?? '');
$formato         = $_GET['formato'] ?? '';

if (!array_key_exists($filtroOperacion, $operacionesDisponibles)) {
    $filtroOperacion = '';
}

// La consulta se ejecuta al pulsar Consultar (o al paginar / exportar)
$consultado = isset($_GET['consultar']) || $formato === 'pdf' || isset($_GET['pagina']);

$errorFechas = '';
if ($filtroDesde !== '' && $filtroHasta !== '' && $filtroDesde > $filtroHasta) {
    $errorFechas = 'La fecha inicial no puede ser posterior a la fecha final.';
    $consultado  = false;
}

$parametros = [
    'desde'     => $filtroDesde,
    'hasta'     => $filtroHasta,
    'username'  => $filtroUsuario,
    'tabla'     => $filtroTabla,
    'operacion' => $filtroOperacion,
    'q'         => $filtroTexto,
];

/* Resumen de filtros, tal como se muestra en pantalla y en el PDF */
$resumenFiltros = [
    'Institución' => $_SESSION['institucion_nombre'] ?? ('#' . $institucionId),
    'Período'     => ($filtroDesde !== '' || $filtroHasta !== '')
        ? (($filtroDesde !== '' ? f_fecha($filtroDesde, 'd/m/Y') : 'inicio') . ' a ' .
           ($filtroHasta !== '' ? f_fecha($filtroHasta, 'd/m/Y') : 'hoy'))
        : 'Todas las fechas',
    'Usuario'     => $filtroUsuario !== '' ? $filtroUsuario : 'Todos',
    'Movimiento'  => $operacionesDisponibles[$filtroOperacion],
];
if ($filtroTabla !== '') {
    $resumenFiltros['Tabla'] = $filtroTabla;
}
if ($filtroTexto !== '') {
    $resumenFiltros['Búsqueda'] = $filtroTexto;
}

/* ---------------------------------------------------------------------------
   Exportación a PDF (usa los mismos filtros, sin paginar)
   --------------------------------------------------------------------------- */
if ($consultado && $formato === 'pdf') {
    require_once __DIR__ . '/../includes/pdf_reporte.php';

    $respuestaPdf = apiGet('reportes/auditoria', $parametros + ['pagina' => 1, 'por_pagina' => 500]);

    if (!$respuestaPdf['ok']) {
        flashSet('error', 'No se pudo generar el PDF: ' . apiError($respuestaPdf));
        redirigir('reporte_auditoria.php?' . http_build_query($parametros + ['consultar' => 1]));
    }

    $filasPdf   = apiDatos($respuestaPdf, []);
    $totalesPdf = apiMeta($respuestaPdf, 'totales', []);

    if (empty($filasPdf)) {
        flashSet('advertencia', 'No se encontraron registros para exportar con los filtros seleccionados.');
        redirigir('reporte_auditoria.php?' . http_build_query($parametros + ['consultar' => 1]));
    }

    $pdf = new PdfReporte('H');

    $pdf->cabecera([
        'logo'        => __DIR__ . '/../assets/logo.png',
        'institucion' => $_SESSION['institucion_nombre'] ?? 'Red Educativa Arquidiocesana',
        'titulo'      => 'Bitácora de Auditoría del Sistema',
        'subtitulo'   => 'Registro de altas, cambios y bajas realizados sobre la base de datos',
        'filtros'     => $resumenFiltros,
    ]);

    $pdf->pie([
        'usuario'    => ($_SESSION['username'] ?? 'sistema')
            . (!empty($_SESSION['roles']) ? ' (' . implode(', ', $_SESSION['roles']) . ')' : ''),
        'disclaimer' => 'Documento de uso interno y confidencial. Contiene trazas de tratamiento de datos personales '
            . 'protegidos por la Ley Orgánica de Protección de Datos Personales; se emite con fines de control y '
            . 'auditoría, y su divulgación no autorizada está prohibida.',
    ]);

    $columnas = [
        ['clave' => 'FechaHora',      'titulo' => 'Fecha y hora',   'ancho' => 12, 'align' => 'L'],
        ['clave' => 'Username',       'titulo' => 'Usuario',        'ancho' => 10, 'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'IpOrigen',       'titulo' => 'IP origen',      'ancho' => 10, 'align' => 'L'],
        ['clave' => 'Tabla',          'titulo' => 'Tabla',          'ancho' => 12, 'align' => 'L'],
        ['clave' => 'RegistroId',     'titulo' => 'Registro',       'ancho' => 7,  'align' => 'L'],
        ['clave' => 'OperacionTexto', 'titulo' => 'Movimiento',     'ancho' => 9,  'align' => 'L', 'estilo' => 'B'],
        ['clave' => 'Campo',          'titulo' => 'Dato',           'ancho' => 12, 'align' => 'L'],
        ['clave' => 'ValorAnterior',  'titulo' => 'Valor original', 'ancho' => 14, 'align' => 'L'],
        ['clave' => 'ValorNuevo',     'titulo' => 'Valor nuevo',    'ancho' => 14, 'align' => 'L'],
    ];

    $coloresOperacion = [
        'INSERT' => [18, 115, 74],
        'UPDATE' => [180, 120, 10],
        'DELETE' => [200, 16, 46],
    ];

    $recortar = static fn($valor) => $valor === null || $valor === ''
        ? '—'
        : (mb_strlen((string)$valor) > 60 ? mb_substr((string)$valor, 0, 60) . '…' : (string)$valor);

    $filasFormateadas = [];
    foreach ($filasPdf as $fila) {
        $filasFormateadas[] = [
            'FechaHora'            => f_fecha($fila['FechaHora'] ?? null),
            'Username'             => $fila['Username'] ?: 'sistema',
            'IpOrigen'             => $fila['IpOrigen'] ?: '—',
            'Tabla'                => $fila['Tabla'] ?? '—',
            'RegistroId'           => $fila['RegistroId'] ?: '—',
            'OperacionTexto'       => $fila['OperacionTexto'] ?? '',
            'Campo'                => $recortar($fila['Campo'] ?? null),
            'ValorAnterior'        => $recortar($fila['ValorAnterior'] ?? null),
            'ValorNuevo'           => $recortar($fila['ValorNuevo'] ?? null),
            '_color_OperacionTexto' => $coloresOperacion[$fila['Operacion'] ?? ''] ?? [40, 44, 52],
        ];
    }

    $pdf->tabla($columnas, $filasFormateadas);
    $pdf->parrafo(sprintf(
        'Total de movimientos: %d   ·   Altas: %d   ·   Cambios: %d   ·   Bajas: %d   ·   Usuarios distintos: %d',
        (int)($totalesPdf['registros'] ?? 0),
        (int)($totalesPdf['altas'] ?? 0),
        (int)($totalesPdf['cambios'] ?? 0),
        (int)($totalesPdf['bajas'] ?? 0),
        (int)($totalesPdf['usuarios'] ?? 0)
    ), 8.5, 'B', [40, 44, 52]);

    $pdf->salida('rea_auditoria_' . date('Ymd_His') . '.pdf');
    exit;
}

/* ---------------------------------------------------------------------------
   Consulta en pantalla
   --------------------------------------------------------------------------- */
$registros    = [];
$totales      = ['registros' => 0, 'altas' => 0, 'cambios' => 0, 'bajas' => 0, 'usuarios' => 0];
$tablas       = [];
$numPagina    = 1;
$totalPaginas = 1;

if ($consultado) {
    $listado = apiGet('reportes/auditoria', $parametros + [
        'pagina'     => max(1, (int)($_GET['pagina'] ?? 1)),
        'por_pagina' => 15,
    ]);

    $registros = apiDatos($listado, []);
    $totales   = apiMeta($listado, 'totales', $totales);
    $tablas    = apiMeta($listado, 'tablas', []);
    [$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

    if (!$listado['ok']) {
        flashSet('error', apiError($listado));
        $consultado = false;
    }
}

// La tabla filtrada debe seguir apareciendo en el desplegable aunque la página
// actual no la incluya entre sus resultados.
if ($filtroTabla !== '' && !in_array($filtroTabla, $tablas, true)) {
    $tablas[] = $filtroTabla;
    sort($tablas);
}

$hayResultados = $consultado && !empty($registros);
$urlPdf = 'reporte_auditoria.php?' . http_build_query($parametros + ['consultar' => 1, 'formato' => 'pdf']);

$badgePorOperacion = [
    'INSERT' => 'badge-activo',
    'UPDATE' => 'badge-neutro',
    'DELETE' => 'badge-inactivo',
];

$pageTitle = 'Bitácora de Auditoría';
$breadcrumb = [['label' => 'Reportes', 'url' => null], ['label' => 'Bitácora de Auditoría', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header no-imprimir">
    <div>
        <h1>🗂️ Bitácora de Auditoría</h1>
        <p>Movimientos registrados en la base de datos de <strong><?= e($_SESSION['institucion_nombre'] ?? 'la institución') ?></strong>:
           quién, cuándo, desde qué IP y qué dato cambió.</p>
    </div>
    <div class="flex-gap">
        <?php if ($hayResultados): ?>
            <a href="<?= e($urlPdf) ?>" class="btn btn-primario" target="_blank" rel="noopener">Exportar a PDF</a>
        <?php else: ?>
            <button type="button" class="btn btn-primario" disabled title="Ejecute una consulta para habilitar la exportación">
                Exportar a PDF
            </button>
        <?php endif; ?>
    </div>
</div>

<div class="card no-imprimir">
    <h3>Filtros de consulta</h3>
    <?php if ($errorFechas !== ''): ?>
        <div class="alerta alerta-error"><?= e($errorFechas) ?></div>
    <?php endif; ?>

    <form method="GET" action="reporte_auditoria.php">
        <div class="form-row">
            <div class="form-group">
                <label for="desde">Fecha inicial <span class="texto-mutado">(opcional)</span></label>
                <input type="date" name="desde" id="desde" value="<?= e($filtroDesde) ?>">
            </div>
            <div class="form-group">
                <label for="hasta">Fecha final <span class="texto-mutado">(opcional)</span></label>
                <input type="date" name="hasta" id="hasta" value="<?= e($filtroHasta) ?>">
            </div>
            <?php
            // Usuario: etiqueta + botón que abre la subpantalla de búsqueda con pagineo
            selectorUsuario([
                'nombre'    => 'username',
                'id'        => 'username',
                'etiqueta'  => 'Usuario (opcional)',
                'valor'     => $filtroUsuario,
                'texto'     => $filtroUsuario,
                'vacio'     => 'Todos los usuarios',
                'ayuda'     => 'Deje el campo vacío para ver los movimientos de todos los usuarios.',
            ]);
            ?>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="operacion">Tipo de movimiento</label>
                <select name="operacion" id="operacion">
                    <?php foreach ($operacionesDisponibles as $valor => $etiqueta): ?>
                        <option value="<?= e($valor) ?>" <?= $filtroOperacion === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="tabla">Tabla <span class="texto-mutado">(opcional)</span></label>
                <select name="tabla" id="tabla">
                    <option value="">Todas</option>
                    <?php foreach ($tablas as $nombreTabla): ?>
                        <option value="<?= e($nombreTabla) ?>" <?= $filtroTabla === $nombreTabla ? 'selected' : '' ?>><?= e($nombreTabla) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:2;">
                <label for="q">Dato o valor <span class="texto-mutado">(opcional)</span></label>
                <input type="text" name="q" id="q" value="<?= e($filtroTexto) ?>" placeholder="Campo, valor original o valor nuevo...">
            </div>
        </div>

        <div class="flex-gap">
            <button type="submit" name="consultar" value="1" class="btn btn-primario">🔍 Consultar</button>
            <a href="reporte_auditoria.php" class="btn btn-secundario">Limpiar filtros</a>
        </div>
    </form>
</div>

<?php if (!$consultado): ?>
    <div class="alerta alerta-info">
        Seleccione el rango de fechas que necesite y pulse <strong>Consultar</strong>. Todos los filtros son
        opcionales: sin cambiar nada obtendrá la bitácora completa de la institución con la que inició sesión.
    </div>
<?php else: ?>

    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-valor"><?= (int)($totales['registros'] ?? 0) ?></div>
            <div class="kpi-label">Movimientos encontrados</div>
        </div>
        <div class="kpi-card kpi-alt-2">
            <div class="kpi-valor"><?= (int)($totales['altas'] ?? 0) ?></div>
            <div class="kpi-label">Altas</div>
        </div>
        <div class="kpi-card kpi-alt-1">
            <div class="kpi-valor"><?= (int)($totales['cambios'] ?? 0) ?></div>
            <div class="kpi-label">Cambios</div>
        </div>
        <div class="kpi-card kpi-alt-3">
            <div class="kpi-valor"><?= (int)($totales['bajas'] ?? 0) ?></div>
            <div class="kpi-label">Bajas</div>
        </div>
    </div>

    <div class="card">
        <div class="flex-entre">
            <h3 class="mb-0">Detalle de movimientos</h3>
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
                    <th>Fecha y Hora</th>
                    <th>Usuario</th>
                    <th>IP de Origen</th>
                    <th>Tabla</th>
                    <th>Registro</th>
                    <th>Movimiento</th>
                    <th>Dato</th>
                    <th>Valor Original</th>
                    <th>Valor Nuevo</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($registros)): ?>
                    <tr><td colspan="9" class="tabla-vacia">No se encontraron movimientos con los filtros seleccionados.</td></tr>
                <?php endif; ?>
                <?php foreach ($registros as $r): ?>
                    <tr>
                        <td><?= f_fecha($r['FechaHora']) ?></td>
                        <td><strong><?= e($r['Username'] ?: 'sistema') ?></strong></td>
                        <td><?= e($r['IpOrigen'] ?: '—') ?></td>
                        <td><?= e($r['Tabla']) ?></td>
                        <td><?= e($r['RegistroId'] ?: '—') ?></td>
                        <td>
                            <span class="badge <?= $badgePorOperacion[$r['Operacion']] ?? 'badge-neutro' ?>">
                                <?= e($r['OperacionTexto']) ?>
                            </span>
                        </td>
                        <td><?= e($r['Campo'] ?: '—') ?></td>
                        <td><?= $r['ValorAnterior'] === null || $r['ValorAnterior'] === '' ? '—' : truncar($r['ValorAnterior'], 60) ?></td>
                        <td><?= $r['ValorNuevo'] === null || $r['ValorNuevo'] === '' ? '—' : truncar($r['ValorNuevo'], 60) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php renderPaginacion($numPagina, $totalPaginas); ?>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
