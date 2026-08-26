<?php
// modules/instituciones.php - CRUD de Instituciones Educativas (solo SuperAdmin)
// Persistencia vía API REST: /api/instituciones
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('instituciones');

$accion = $_GET['accion'] ?? 'listar';
$errores = [];

// ---------- Procesar formulario (crear / editar) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        $id = trim($_POST['id'] ?? '');
        $datos = [
            'id'              => $id,
            'nombre'          => trim($_POST['nombre'] ?? ''),
            'direccion'       => trim($_POST['direccion'] ?? ''),
            'telefono'        => trim($_POST['telefono'] ?? ''),
            'nombre_logotipo' => trim($_POST['nombre_logotipo'] ?? ''),
            'estado'          => $_POST['estado'] ?? 'ACTIVO',
        ];

        if ($accion === 'crear') {
            $respuesta = apiPost('instituciones', $datos);
            $mensajeOk = 'Institución educativa registrada correctamente.';
        } else {
            $respuesta = apiPut('instituciones/' . (int)$id, $datos);
            $mensajeOk = 'Institución educativa actualizada correctamente.';
        }

        if ($respuesta['ok']) {
            flashSet('exito', $mensajeOk);
            redirigir('instituciones.php');
        }
        $errores = apiErrores($respuesta);
    }
}

// ---------- Cambiar estado (activar / inactivar) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'cambiar_estado') {
    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiPatch('instituciones/' . $id . '/estado');
        flashSet($respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok'] ? 'Estado actualizado.' : apiError($respuesta));
    }
    redirigir('instituciones.php');
}

// ---------- Datos para edición ----------
$registroEditar = null;
if ($accion === 'editar') {
    $respuesta = apiGet('instituciones/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('instituciones.php'); }
}

// ---------- Listado con búsqueda ----------
$buscar = trim($_GET['q'] ?? '');
$listado = apiGet('instituciones', [
    'q'      => $buscar,
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));
$siguienteId = (int)apiMeta($listado, 'siguiente_id', 1);

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Instituciones Educativas';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Instituciones Educativas', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🏫 Instituciones Educativas</h1>
        <p>Entidades tenant del sistema: cada institución gestiona sus propios datos de forma aislada.</p>
    </div>
    <div class="flex-gap">
        <a class="btn btn-primario" href="instituciones.php?accion=crear">+ Nueva Institución</a>
    </div>
</div>

<?php if ($accion === 'crear' || $accion === 'editar'): ?>
    <div class="card">
        <h3><?= $accion === 'crear' ? 'Registrar Institución Educativa' : 'Editar Institución Educativa' ?></h3>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="instituciones.php?accion=<?= e($accion) ?>">
            <?= csrfCampo() ?>
            <div class="form-row">
                <div class="form-group" style="max-width:160px;">
                    <label class="campo-requerido">ID</label>
                    <?php if ($accion === 'editar'): ?>
                        <input type="text" value="<?= e((string)$registroEditar['id']) ?>" disabled>
                        <input type="hidden" name="id" value="<?= e((string)$registroEditar['id']) ?>">
                    <?php else: ?>
                        <input type="number" name="id" required value="<?= e((string)$siguienteId) ?>">
                        <div class="form-ayuda">Identificador numérico único (sugerido automáticamente).</div>
                    <?php endif; ?>
                </div>
                <div class="form-group" style="flex:2;">
                    <label class="campo-requerido">Nombre</label>
                    <input type="text" name="nombre" maxlength="50" required value="<?= e($registroEditar['nombre'] ?? ($_POST['nombre'] ?? '')) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="campo-requerido">Dirección</label>
                    <input type="text" name="direccion" maxlength="100" required value="<?= e($registroEditar['direccion'] ?? ($_POST['direccion'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label class="campo-requerido">Teléfono</label>
                    <input type="text" name="telefono" maxlength="20" required value="<?= e($registroEditar['telefono'] ?? ($_POST['telefono'] ?? '')) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Nombre del Logotipo (archivo)</label>
                    <input type="text" name="nombre_logotipo" maxlength="100" value="<?= e($registroEditar['nombre_logotipo'] ?? ($_POST['nombre_logotipo'] ?? '')) ?>">
                </div>
                <div class="form-group">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="ACTIVO" <?= (($registroEditar['estado'] ?? 'ACTIVO') === 'ACTIVO') ? 'selected' : '' ?>>ACTIVO</option>
                        <option value="INACTIVO" <?= (($registroEditar['estado'] ?? '') === 'INACTIVO') ? 'selected' : '' ?>>INACTIVO</option>
                    </select>
                </div>
            </div>
            <div class="flex-gap">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="instituciones.php" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Buscar</label>
            <input type="text" name="q" placeholder="Nombre o dirección..." value="<?= e($buscar) ?>">
        </div>
        <button type="submit" class="btn btn-secundario">Buscar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead>
        <tr><th>ID</th><th>Nombre</th><th>Dirección</th><th>Teléfono</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr>
        </thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="6" class="tabla-vacia">No se encontraron instituciones registradas.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td>#<?= e((string)$r['id']) ?></td>
                <td><strong><?= e($r['nombre']) ?></strong></td>
                <td><?= e($r['direccion']) ?></td>
                <td><?= e($r['telefono']) ?></td>
                <td><?= badgeEstado($r['estado']) ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="instituciones.php?accion=editar&id=<?= e((string)$r['id']) ?>">Editar</a>
                        <form method="POST" action="instituciones.php?accion=cambiar_estado" onsubmit="return confirm('¿Confirma el cambio de estado?');" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id" value="<?= e((string)$r['id']) ?>">
                            <input type="hidden" name="estado_actual" value="<?= e($r['estado']) ?>">
                            <button type="submit" class="btn btn-sm <?= $r['estado'] === 'ACTIVO' ? 'btn-peligro' : 'btn-exito' ?>">
                                <?= $r['estado'] === 'ACTIVO' ? 'Inactivar' : 'Activar' ?>
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
