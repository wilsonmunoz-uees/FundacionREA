<?php
// modules/permisos.php - CRUD de Permisos del sistema (por institución)
// Persistencia vía API REST: /api/permisos
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('permisos');
$institucionId = institucionActual();
$modulos = ['ADMINISTRACION', 'REGISTRO_DATOS', 'CONSULTA_BUSQUEDAS', 'REPORTES_EXPORTACION'];

$accion = $_GET['accion'] ?? 'listar';
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        $datos = [
            'codigo'      => trim($_POST['codigo'] ?? ''),
            'nombre'      => trim($_POST['nombre'] ?? ''),
            'modulo'      => $_POST['modulo'] ?? '',
            'descripcion' => trim($_POST['descripcion'] ?? ''),
            'estado'      => $_POST['estado'] ?? 'ACTIVO',
        ];

        if ($accion === 'crear') {
            $respuesta = apiPost('permisos', $datos);
            $mensajeOk = 'Permiso registrado correctamente.';
        } else {
            $id = (int)($_POST['permiso_id'] ?? 0);
            $respuesta = apiPut('permisos/' . $id, $datos);
            $mensajeOk = 'Permiso actualizado correctamente.';
        }

        if ($respuesta['ok']) {
            flashSet('exito', $mensajeOk);
            redirigir('permisos.php');
        }
        $errores = apiErrores($respuesta);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'cambiar_estado') {
    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiPatch('permisos/' . $id . '/estado');
        flashSet($respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok'] ? 'Estado actualizado.' : apiError($respuesta));
    }
    redirigir('permisos.php');
}

$registroEditar = null;
if ($accion === 'editar') {
    $respuesta = apiGet('permisos/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('permisos.php'); }
}

$buscar = trim($_GET['q'] ?? '');
$listado = apiGet('permisos', [
    'q'      => $buscar,
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Permisos';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Permisos', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🔐 Permisos</h1>
        <p>Catálogo de permisos por módulo, asignables a roles en <a href="roles.php">Roles</a>.</p>
    </div>
    <div class="flex-gap">
        <a class="btn btn-primario" href="permisos.php?accion=crear">+ Nuevo Permiso</a>
    </div>
</div>

<?php if ($accion === 'crear' || $accion === 'editar'): ?>
    <div class="card">
        <h3><?= $accion === 'crear' ? 'Registrar Permiso' : 'Editar Permiso' ?></h3>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="permisos.php?accion=<?= e($accion) ?>">
            <?= csrfCampo() ?>
            <?php if ($accion === 'editar'): ?><input type="hidden" name="permiso_id" value="<?= e((string)$registroEditar['PermisoId']) ?>"><?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label class="campo-requerido">Código</label>
                    <input type="text" name="codigo" maxlength="50" required value="<?= e($registroEditar['Codigo'] ?? '') ?>" placeholder="Ej: REGISTRO_DATOS">
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="campo-requerido">Nombre</label>
                    <input type="text" name="nombre" maxlength="100" required value="<?= e($registroEditar['Nombre'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Módulo</label>
                    <select name="modulo">
                        <option value="">-- Sin módulo --</option>
                        <?php foreach ($modulos as $m): ?>
                            <option value="<?= $m ?>" <?= (($registroEditar['Modulo'] ?? '') === $m) ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="ACTIVO" <?= (($registroEditar['Estado'] ?? 'ACTIVO') === 'ACTIVO') ? 'selected' : '' ?>>ACTIVO</option>
                        <option value="INACTIVO" <?= (($registroEditar['Estado'] ?? '') === 'INACTIVO') ? 'selected' : '' ?>>INACTIVO</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Descripción</label>
                <textarea name="descripcion"><?= e($registroEditar['Descripcion'] ?? '') ?></textarea>
            </div>
            <div class="flex-gap">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="permisos.php" class="btn btn-secundario">Cancelar</a>
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
        <thead><tr><th>Código</th><th>Nombre</th><th>Módulo</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr></thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="5" class="tabla-vacia">No se encontraron permisos registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td><span class="badge badge-neutro"><?= e($r['Codigo']) ?></span></td>
                <td><strong><?= e($r['Nombre']) ?></strong></td>
                <td><?= $r['Modulo'] ? '<span class="badge badge-info">' . e($r['Modulo']) . '</span>' : '—' ?></td>
                <td><?= badgeEstado($r['Estado']) ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="permisos.php?accion=editar&id=<?= e((string)$r['PermisoId']) ?>">Editar</a>
                        <form method="POST" action="permisos.php?accion=cambiar_estado" onsubmit="return confirm('¿Confirma el cambio de estado?');" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id" value="<?= e((string)$r['PermisoId']) ?>">
                            <input type="hidden" name="estado_actual" value="<?= e($r['Estado']) ?>">
                            <button type="submit" class="btn btn-sm <?= $r['Estado'] === 'ACTIVO' ? 'btn-peligro' : 'btn-exito' ?>">
                                <?= $r['Estado'] === 'ACTIVO' ? 'Inactivar' : 'Activar' ?>
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
