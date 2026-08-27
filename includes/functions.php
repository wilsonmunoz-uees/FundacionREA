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

/** Genera el bloque HTML de navegación de páginas conservando los filtros de la URL. */
function renderPaginacion(int $paginaActual, int $totalPaginas): void {
    if ($totalPaginas <= 1) return;
    $params = $_GET;
    echo '<div class="paginacion">';
    for ($i = 1; $i <= $totalPaginas; $i++) {
        $params['pagina'] = $i;
        $url = '?' . http_build_query($params);
        if ($i === $paginaActual) {
            echo '<span class="pagina-actual">' . $i . '</span>';
        } else {
            echo '<a href="' . e($url) . '">' . $i . '</a>';
        }
    }
    echo '</div>';
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
