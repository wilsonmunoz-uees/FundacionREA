<?php
// includes/functions.php
// Funciones auxiliares compartidas por todos los módulos del sistema.
//
// Nota: las funciones que antes recibían un objeto PDO desaparecieron de aquí.
// El acceso a datos ahora ocurre en la API (carpeta /api) y las vistas solo
// formatean lo que la API devuelve.

/** Redirige y detiene la ejecución. */
function redirigir(string $url): void {
    header('Location: ' . $url);
    exit;
}

/** Devuelve un badge HTML según el valor de Estado (ACTIVO/INACTIVO u otros). */
function badgeEstado(?string $estado): string {
    $estado = strtoupper((string)$estado);
    if ($estado === 'ACTIVO' || $estado === 'SI') {
        return '<span class="badge badge-activo">' . e($estado) . '</span>';
    }
    if ($estado === 'INACTIVO' || $estado === 'NO') {
        return '<span class="badge badge-inactivo">' . e($estado) . '</span>';
    }
    return '<span class="badge badge-neutro">' . e($estado ?: '—') . '</span>';
}

/** Formatea una fecha/hora para mostrarla en pantalla. */
function f_fecha(?string $fecha, string $formato = 'd/m/Y H:i'): string {
    if (empty($fecha) || $fecha === '0000-00-00 00:00:00') {
        return '—';
    }
    try {
        $dt = new DateTime($fecha);
        return $dt->format($formato);
    } catch (Exception $ex) {
        return e($fecha);
    }
}

/**
 * Calcula parámetros de paginación a partir del total de registros.
 * Se conserva para las vistas que aún la usan; la API también devuelve esta
 * información en el bloque 'meta' de cada listado.
 */
function calcularPaginacion(int $totalRegistros, int $porPagina = 15): array {
    $paginaActual = max(1, (int)($_GET['pagina'] ?? 1));
    $totalPaginas = max(1, (int)ceil($totalRegistros / $porPagina));
    $paginaActual = min($paginaActual, $totalPaginas);
    $offset = ($paginaActual - 1) * $porPagina;
    return [$paginaActual, $totalPaginas, $offset];
}

/** Página actual y total de páginas a partir del 'meta' devuelto por la API. */
function paginacionDesdeMeta(array $meta): array {
    return [
        max(1, (int)($meta['pagina'] ?? 1)),
        max(1, (int)($meta['total_paginas'] ?? 1)),
    ];
}

/**
 * Encabezado de un formulario, con su título y el botón de volver al listado.
 *
 * El botón de abajo dice «Cancelar», que es lo correcto al pie de un formulario
 * pero obliga a recorrerlo entero para salir. En las pantallas largas —un
 * estudiante con su representante, un usuario con sus roles— eso significaba
 * bajar hasta el final solo para retroceder. Este va arriba y siempre se ve.
 */
function encabezadoFormulario(string $titulo, string $volverA, string $etiqueta = 'Volver al listado'): void {
    ?>
    <div class="form-encabezado">
        <h3><?= e($titulo) ?></h3>
        <a href="<?= e($volverA) ?>" class="btn btn-sm btn-secundario">&larr; <?= e($etiqueta) ?></a>
    </div>
    <?php
}

/**
 * Deja preparado el aviso de un guardado que además disparó el correo de
 * consentimiento.
 *
 * La API responde con `invitacion`, y ahí viene la frase ya redactada. Cuando el
 * correo no salió el aviso se marca como advertencia y no como éxito: los datos
 * sí se guardaron, pero el titular no se enteró, y eso hay que verlo.
 */
function flashGuardadoConInvitacion(string $mensajeBase, array $respuesta): void {
    $invitacion = apiDatos($respuesta, [])['invitacion'] ?? null;

    if (!is_array($invitacion) || ($invitacion['mensaje'] ?? '') === '') {
        flashSet('exito', $mensajeBase);
        return;
    }

    flashSet(
        !empty($invitacion['enviado']) ? 'exito' : 'advertencia',
        $mensajeBase . ' ' . $invitacion['mensaje']
    );
}

/**
 * Genera el bloque HTML de navegación de páginas conservando los filtros de la URL.
 *
 * Se dibuja SIEMPRE en una sola línea. Antes se imprimían todos los números, de
 * modo que una consulta de cuatro mil registros llenaba media pantalla de
 * botones; ahora se muestra una ventana alrededor de la página actual y, cuando
 * quedan páginas fuera de esa ventana, se indica con puntos suspensivos, un
 * acceso directo al extremo y el texto «Página X de Y».
 *
 * @param int $ventana Cuántos números como máximo se muestran a la vez.
 */
function renderPaginacion(int $paginaActual, int $totalPaginas, int $ventana = 7): void {
    if ($totalPaginas <= 1) return;

    $paginaActual = max(1, min($paginaActual, $totalPaginas));
    $params       = $_GET;

    /** Enlace a una página conservando los filtros que ya trae la URL. */
    $url = static function (int $pagina) use ($params): string {
        $params['pagina'] = $pagina;
        return '?' . http_build_query($params);
    };

    /* Ventana centrada en la página actual, corrida hacia adentro cuando se
       acerca a un extremo para que siempre se ofrezcan los mismos saltos. */
    $desde = max(1, $paginaActual - intdiv($ventana, 2));
    $hasta = min($totalPaginas, $desde + $ventana - 1);
    $desde = max(1, $hasta - $ventana + 1);

    echo '<nav class="paginacion" aria-label="Paginación">';

    // Anterior
    if ($paginaActual > 1) {
        echo '<a href="' . e($url($paginaActual - 1)) . '" rel="prev" title="Página anterior">‹</a>';
    } else {
        echo '<span class="pagina-inerte" aria-hidden="true">‹</span>';
    }

    // Primera página y corte, si la ventana no llega hasta el principio
    if ($desde > 1) {
        echo '<a href="' . e($url(1)) . '">1</a>';
        if ($desde > 2) {
            echo '<span class="pagina-corte" aria-hidden="true">…</span>';
        }
    }

    for ($i = $desde; $i <= $hasta; $i++) {
        if ($i === $paginaActual) {
            echo '<span class="pagina-actual" aria-current="page">' . $i . '</span>';
        } else {
            echo '<a href="' . e($url($i)) . '">' . $i . '</a>';
        }
    }

    /* Lo que pide la vista: al final, la señal de que la lista continúa. Los
       puntos suspensivos avisan de que hay más, y el número del final permite
       saltar directamente a la última página. */
    if ($hasta < $totalPaginas) {
        if ($hasta < $totalPaginas - 1) {
            echo '<span class="pagina-corte" aria-hidden="true">…</span>';
        }
        echo '<a href="' . e($url($totalPaginas)) . '" title="Última página">' . $totalPaginas . '</a>';
    }

    // Siguiente
    if ($paginaActual < $totalPaginas) {
        echo '<a href="' . e($url($paginaActual + 1)) . '" rel="next" title="Página siguiente">›</a>';
    } else {
        echo '<span class="pagina-inerte" aria-hidden="true">›</span>';
    }

    echo '<span class="pagina-resumen">Página ' . $paginaActual . ' de ' . $totalPaginas . '</span>';
    echo '</nav>';
}

/**
 * Devuelve opciones <option> a partir de un arreglo de filas entregado por la API.
 * Ejemplo: opcionesSelect($personas, 'PersonaId', 'etiqueta', $seleccionado)
 */
function opcionesSelect(array $filas, string $claveValor, string $claveTexto, $valorSeleccionado = ''): string {
    $html = '';
    foreach ($filas as $fila) {
        $valor = (string)($fila[$claveValor] ?? '');
        $texto = (string)($fila[$claveTexto] ?? '');
        $sel   = ((string)$valorSeleccionado === $valor) ? ' selected' : '';
        $html .= '<option value="' . e($valor) . '"' . $sel . '>' . e($texto) . '</option>';
    }
    return $html;
}

/** Nombre completo de una persona a partir de sus campos. */
function nombreCompleto(?string $nombres, ?string $apellidos): string {
    $texto = trim(($nombres ?? '') . ' ' . ($apellidos ?? ''));
    return $texto !== '' ? $texto : '—';
}

/** Trunca texto largo para vistas de tabla. */
function truncar(?string $texto, int $largo = 60): string {
    $texto = $texto ?? '';
    if (mb_strlen($texto) <= $largo) return e($texto);
    return e(mb_substr($texto, 0, $largo)) . '…';
}

/** Iniciales para el avatar del usuario en la barra superior. */
function iniciales(string $texto): string {
    $texto = trim($texto);
    if ($texto === '') return '?';
    return mb_strtoupper(mb_substr($texto, 0, 1));
}

/* ------------------------------------------------------------------------- */
/* Identidad visual de la institución                                         */
/* ------------------------------------------------------------------------- */

/**
 * Logotipo de una institución educativa.
 *
 * Los archivos viven en `assets/logos/` y se llaman con el código de la
 * institución en DOS dígitos: la institución 1 es `01.png`, la 13 es `13.png`.
 * El código es el mismo identificador que lleva la institución en la base.
 *
 * Si una institución todavía no tiene su logotipo cargado, se usa el de la Red
 * Educativa Arquidiocesana: un reporte sin ninguna marca se ve roto, y el de la
 * red siempre es cierto porque todas pertenecen a ella.
 *
 * Se resuelve aquí, en un solo sitio, porque lo necesitan tanto los reportes en
 * pantalla —que piden una URL— como los PDF —que piden una ruta de archivo—, y
 * si cada uno lo armara por su cuenta acabarían discrepando.
 *
 * @param int|null $institucionId null = la institución de la sesión
 * @return array{ruta:string, url:string, propio:bool}
 *         ruta   camino en disco, para incrustarlo en el PDF
 *         url    camino para un <img> de la pantalla, ya con APP_ROOT
 *         propio false cuando se cayó al logotipo de la red
 */
function logoInstitucion(?int $institucionId = null): array {
    $raiz    = dirname(__DIR__);
    $prefijo = defined('APP_ROOT') ? APP_ROOT : '';

    $institucionId = $institucionId ?? institucionActual();
    $respaldo = [
        'ruta'   => $raiz . '/assets/logo.png',
        'url'    => $prefijo . 'assets/logo.png',
        'propio' => false,
    ];

    if (!$institucionId || $institucionId <= 0) {
        return $respaldo;
    }

    // Dos dígitos: 1 -> «01». Por encima de 99 se usa el número tal cual, que
    // es lo que haría falta el día que la red pase de cien instituciones.
    $codigo = str_pad((string)(int)$institucionId, 2, '0', STR_PAD_LEFT);
    $ruta   = $raiz . '/assets/logos/' . $codigo . '.png';

    if (!is_file($ruta)) {
        return $respaldo;
    }

    return [
        'ruta'   => $ruta,
        'url'    => $prefijo . 'assets/logos/' . $codigo . '.png',
        'propio' => true,
    ];
}

/**
 * Nombre de la institución en la que se está trabajando, para las cabeceras.
 *
 * Sale de la sesión, que la guardó al ingresar. El respaldo nombra a la red
 * entera: es preferible a dejar la cabecera de un reporte en blanco.
 */
function nombreInstitucionActual(): string {
    $nombre = trim((string)($_SESSION['institucion_nombre'] ?? ''));

    return $nombre !== '' ? $nombre : 'Red Educativa Arquidiocesana';
}

/**
 * Cabecera de un reporte en pantalla: logotipo, institución y título.
 *
 * Es la misma que lleva el PDF, para que lo que se imprime desde el navegador
 * y lo que se descarga no sean dos documentos distintos.
 *
 * El escudo y el nombre de la institución se emiten SIEMPRE, pero la hoja de
 * estilos solo los dibuja al imprimir: en pantalla ya están en la barra
 * superior y verlos otra vez tres centímetros más abajo era leer dos veces lo
 * mismo. En papel esa barra no existe, así que ahí son lo único que dice de
 * quién es el documento. Por eso se marcan con clase y se ocultan por CSS en
 * vez de no imprimirlos: la misma cabecera sirve para los dos medios (ver
 * `.reporte-logo` y `.reporte-institucion` en css/style.css).
 *
 * @param bool $deLaRed true en los reportes que abarcan TODAS las instituciones:
 *                      ahí lo que corresponde es la marca de la Red, no la de
 *                      una escuela, que sería decir algo falso sobre el alcance
 *                      del documento.
 */
function cabeceraReporte(string $titulo, string $subtitulo = '', bool $deLaRed = false): void {
    $logo   = $deLaRed
        ? ['url' => (defined('APP_ROOT') ? APP_ROOT : '') . 'assets/logo.png']
        : logoInstitucion();
    $titular = $deLaRed ? 'Red Educativa Arquidiocesana' : nombreInstitucionActual();
    ?>
    <div class="reporte-cabecera">
        <?php /* Igual que en la barra: la medida va también en atributos, porque el
                 archivo mide 150x150 y sin hoja de estilos se dibujaría a tamaño
                 natural y desbarataría la cabecera. */ ?>
        <img src="<?= e($logo['url']) ?>" alt="" class="reporte-logo"
             width="58" height="58">
        <div class="reporte-identidad">
            <div class="reporte-institucion"><?= e($titular) ?></div>
            <h2 class="reporte-titulo"><?= e($titulo) ?></h2>
            <?php if ($subtitulo !== ''): ?>
                <p class="reporte-subtitulo"><?= e($subtitulo) ?></p>
            <?php endif; ?>
        </div>
        <div class="reporte-emision">
            Emitido el <?= e(date('d/m/Y H:i')) ?><br>
            por <?= e($_SESSION['username'] ?? 'sistema') ?>
        </div>
    </div>
    <?php
}
