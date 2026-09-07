<?php
// modules/finalidades.php - CRUD de Finalidades del Tratamiento de Datos (catálogo global)
// Persistencia vía API REST: /api/finalidades
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('finalidades');

$accion = $_GET['accion'] ?? 'listar';
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        $datos = [
            'codigo'      => trim($_POST['codigo'] ?? ''),
            'nombre'      => trim($_POST['nombre'] ?? ''),
            'descripcion' => trim($_POST['descripcion'] ?? ''),
            'activo'      => $_POST['activo'] ?? 'ACTIVO',
        ];

        if ($accion === 'crear') {
            $respuesta = apiPost('finalidades', $datos);
            $mensajeOk = 'Finalidad registrada correctamente.';
        } else {
            $id = (int)($_POST['finalidad_id'] ?? 0);
            $respuesta = apiPut('finalidades/' . $id, $datos);
            $mensajeOk = 'Finalidad actualizada correctamente.';
        }

        if ($respuesta['ok']) {
            flashSet('exito', $mensajeOk);
            redirigir('finalidades.php');
        }
        $errores = apiErrores($respuesta);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'cambiar_estado') {
    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiPatch('finalidades/' . $id . '/estado');
        flashSet($respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok'] ? 'Estado actualizado.' : apiError($respuesta));
    }
    redirigir('finalidades.php');
}

$registroEditar = null;
if ($accion === 'editar') {
    $respuesta = apiGet('finalidades/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('finalidades.php'); }
}

$buscar = trim($_GET['q'] ?? '');
$listado = apiGet('finalidades', [
    'q'      => $buscar,
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Finalidades del Tratamiento';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Finalidades', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🎯 Finalidades del Tratamiento</h1>
        <p>Catálogo de propósitos para los cuales se solicita el consentimiento (marketing, matrícula, nómina, etc.).</p>
    </div>
    <div class="flex-gap">
        <a class="btn btn-primario" href="finalidades.php?accion=crear">+ Nueva Finalidad</a>
    </div>
</div>

<div class="alerta alerta-info">
    Este catálogo lo comparten <strong>las 21 instituciones de la red</strong>: lo que se cambie aquí
    lo verán todas, y los consentimientos ya otorgados apuntan a estos registros. Por eso solo lo
    mantiene el SuperAdmin.
</div>

<?php if ($accion === 'crear' || $accion === 'editar'): ?>
    <div class="card">
        <?php encabezadoFormulario($accion === 'crear' ? 'Registrar Finalidad' : 'Editar Finalidad', 'finalidades.php'); ?>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="finalidades.php?accion=<?= e($accion) ?>">
            <?= csrfCampo() ?>
            <?php if ($accion === 'editar'): ?><input type="hidden" name="finalidad_id" value="<?= e((string)$registroEditar['FinalidadId']) ?>"><?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label class="campo-requerido">Código</label>
                    <input type="text" name="codigo" maxlength="50" required value="<?= e($registroEditar['Codigo'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="campo-requerido">Nombre</label>
                    <input type="text" name="nombre" maxlength="150" required value="<?= e($registroEditar['Nombre'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Descripción</label>
                <textarea name="descripcion"><?= e($registroEditar['Descripcion'] ?? '') ?></textarea>
            </div>
            <div class="form-group" style="max-width:220px;">
                <label>Estado</label>
                <select name="activo">
                    <option value="ACTIVO" <?= (($registroEditar['Activo'] ?? 'ACTIVO') === 'ACTIVO') ? 'selected' : '' ?>>ACTIVO</option>
                    <option value="INACTIVO" <?= (($registroEditar['Activo'] ?? '') === 'INACTIVO') ? 'selected' : '' ?>>INACTIVO</option>
                </select>
            </div>
            <div class="flex-gap">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="finalidades.php" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Buscar</label>
            <input type="text" name="q" placeholder="Código o nombre..." value="<?= e($buscar) ?>">
        </div>
        <button type="submit" class="btn btn-secundario">Buscar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead><tr><th>Código</th><th>Nombre</th><th>Descripción</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr></thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="5" class="tabla-vacia">No se encontraron finalidades registradas.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td><span class="badge badge-neutro"><?= e($r['Codigo']) ?></span></td>
                <td><strong><?= e($r['Nombre']) ?></strong></td>
                <td><?= truncar($r['Descripcion'], 80) ?></td>
                <td><?= badgeEstado($r['Activo']) ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="finalidades.php?accion=editar&id=<?= e((string)$r['FinalidadId']) ?>">Editar</a>
                        <form method="POST" action="finalidades.php?accion=cambiar_estado" onsubmit="return confirm('¿Confirma el cambio de estado?');" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id" value="<?= e((string)$r['FinalidadId']) ?>">
                            <input type="hidden" name="estado_actual" value="<?= e($r['Activo']) ?>">
                            <button type="submit" class="btn btn-sm <?= $r['Activo'] === 'ACTIVO' ? 'btn-peligro' : 'btn-exito' ?>">
                                <?= $r['Activo'] === 'ACTIVO' ? 'Inactivar' : 'Activar' ?>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php renderPaginacion($numPagina, $totalPaginas); ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
