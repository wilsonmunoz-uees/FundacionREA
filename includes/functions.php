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
