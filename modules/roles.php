<?php
// modules/roles.php - CRUD de Roles + asignación de Permisos
// Persistencia vía API REST: /api/roles
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('roles');
$institucionId = institucionActual();

$accion = $_GET['accion'] ?? 'listar';
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        $datos = [
            'nombre'      => trim($_POST['nombre'] ?? ''),
            'descripcion' => trim($_POST['descripcion'] ?? ''),
            'estado'      => $_POST['estado'] ?? 'ACTIVO',
            'permisos'    => array_map('intval', $_POST['permisos'] ?? []),
        ];

        if ($accion === 'crear') {
            $respuesta = apiPost('roles', $datos);
            $mensajeOk = 'Rol registrado correctamente.';
        } else {
            $id = (int)($_POST['rol_id'] ?? 0);
            $respuesta = apiPut('roles/' . $id, $datos);
            $mensajeOk = 'Rol actualizado correctamente.';
        }

        if ($respuesta['ok']) {
            flashSet('exito', $mensajeOk);
            redirigir('roles.php');
        }
        $errores = apiErrores($respuesta);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'cambiar_estado') {
    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiPatch('roles/' . $id . '/estado');
        flashSet($respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok'] ? 'Estado actualizado.' : apiError($respuesta));
    }
    redirigir('roles.php');
}

$registroEditar = null;
$permisosDelRol = [];
$todosLosPermisos = [];
if ($accion === 'editar') {
    $respuesta = apiGet('roles/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('roles.php'); }
    $permisosDelRol   = $registroEditar['permisos_asignados'] ?? [];
    $todosLosPermisos = $registroEditar['permisos_disponibles'] ?? [];
}

$buscar = trim($_GET['q'] ?? '');
$listado = apiGet('roles', [
    'q'      => $buscar,
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}
if (empty($todosLosPermisos)) {
    $todosLosPermisos = apiMeta($listado, 'permisos_disponibles', []);
}

// Conteo de permisos por rol para el listado (viene en cada fila)
$conteoPermisos = [];
foreach ($registros as $fila) {
    $conteoPermisos[$fila['RolId']] = (int)($fila['TotalPermisos'] ?? 0);
}

$pageTitle = 'Roles y Permisos';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Roles', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🛡️ Roles</h1>
        <p>Defina roles y asigne los permisos que controlan el acceso a cada módulo del sistema.</p>
    </div>
    <div class="flex-gap">
        <a class="btn btn-primario" href="roles.php?accion=crear">+ Nuevo Rol</a>
    </div>
</div>

<?php if ($accion === 'crear' || $accion === 'editar'): ?>
    <div class="card">
        <h3><?= $accion === 'crear' ? 'Registrar Rol' : 'Editar Rol' ?></h3>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="roles.php?accion=<?= e($accion) ?>">
            <?= csrfCampo() ?>
            <?php if ($accion === 'editar'): ?><input type="hidden" name="rol_id" value="<?= e((string)$registroEditar['RolId']) ?>"><?php endif; ?>
            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label class="campo-requerido">Nombre del Rol</label>
                    <input type="text" name="nombre" maxlength="50" required value="<?= e($registroEditar['Nombre'] ?? '') ?>">
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

            <fieldset>
                <legend>Permisos Asignados</legend>
                <?php if (empty($todosLosPermisos)): ?>
                    <p class="texto-mutado">No hay permisos registrados. <a href="permisos.php?accion=crear">Cree un permiso primero</a>.</p>
                <?php else: ?>
                    <div class="check-grid">
                        <?php foreach ($todosLosPermisos as $p): ?>
                            <label class="check-item">
                                <input type="checkbox" name="permisos[]" value="<?= e((string)$p['PermisoId']) ?>"
                                    <?= in_array($p['PermisoId'], $permisosDelRol) ? 'checked' : '' ?>>
                                <?= e($p['Nombre']) ?> <span class="texto-mutado">(<?= e($p['Modulo'] ?: $p['Codigo']) ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>

            <div class="flex-gap">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="roles.php" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Buscar</label>
            <input type="text" name="q" placeholder="Nombre del rol..." value="<?= e($buscar) ?>">
        </div>
        <button type="submit" class="btn btn-secundario">Buscar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead><tr><th>Nombre</th><th>Descripción</th><th>Permisos</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr></thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="5" class="tabla-vacia">No se encontraron roles registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td><strong><?= e($r['Nombre']) ?></strong></td>
                <td><?= truncar($r['Descripcion'], 70) ?></td>
                <td><span class="badge badge-info"><?= (int)($conteoPermisos[$r['RolId']] ?? 0) ?> permisos</span></td>
                <td><?= badgeEstado($r['Estado']) ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="roles.php?accion=editar&id=<?= e((string)$r['RolId']) ?>">Editar</a>
                        <form method="POST" action="roles.php?accion=cambiar_estado" onsubmit="return confirm('¿Confirma el cambio de estado?');" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id" value="<?= e((string)$r['RolId']) ?>">
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
