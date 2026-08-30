<?php
// reportes/exportar_csv.php - Exportación de datos a CSV para análisis externo
// Los datos provienen de la API REST: /api/reportes/exportar
// La página solo arma el archivo CSV con las filas que devuelve la API.
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('exportar_csv');
$institucionId = institucionActual();

$entidadesPermitidas = [
    'personas'          => 'Personas',
    'empleados'         => 'Empleados',
    'estudiantes'       => 'Estudiantes',
    'proveedores'       => 'Proveedores',
    'consentimientos'   => 'Consentimientos',
    'historial'         => 'Historial de Consentimientos',
];

$entidad = $_GET['entidad'] ?? '';

// ---------- Generación y descarga del CSV ----------
if ($entidad !== '' && isset($entidadesPermitidas[$entidad]) && isset($_GET['descargar'])) {
    $respuesta = apiGet('reportes/exportar', ['entidad' => $entidad]);

    if (!$respuesta['ok']) {
        flashSet('error', 'No se pudo generar la exportación: ' . apiError($respuesta));
        redirigir('exportar_csv.php');
    }

    $datos       = apiDatos($respuesta, []);
    $encabezados = $datos['encabezados'] ?? [];
    $filas       = $datos['filas'] ?? [];

    $nombreArchivo = 'rea_' . $entidad . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
    $salida = fopen('php://output', 'w');
    fwrite($salida, "\xEF\xBB\xBF"); // BOM para compatibilidad con Excel
    fputcsv($salida, $encabezados);
    foreach ($filas as $fila) {
        fputcsv($salida, (array)$fila);
    }
    fclose($salida);
    exit;
}

// ---------- Formulario de exportación ----------
$pageTitle = 'Exportar Datos (CSV)';
$breadcrumb = [['label' => 'Reportes', 'url' => null], ['label' => 'Exportar CSV', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>⬇️ Exportar Datos a CSV</h1>
        <p>Descargue la información de la institución activa en formato CSV para análisis externo o respaldo.</p>
    </div>
</div>

<div class="menu-grid">
    <?php foreach ($entidadesPermitidas as $clave => $etiqueta): ?>
        <div class="card-modulo">
            <div class="icono-modulo">📄</div>
            <h3><?= e($etiqueta) ?></h3>
            <p>Exportar el listado completo de <?= mb_strtolower(e($etiqueta)) ?> de la institución activa.</p>
            <a class="btn btn-primario btn-sm" href="exportar_csv.php?entidad=<?= e($clave) ?>&descargar=1">Descargar CSV</a>
        </div>
    <?php endforeach; ?>
</div>

<div class="alerta alerta-info">
    Por seguridad, la exportación de <strong>usuarios</strong> no incluye contraseñas ni datos de acceso; para gestión de cuentas utilice el módulo <a href="../modules/usuarios.php">Usuarios del Sistema</a>.
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
