<?php
// modules/empleados.php - CRUD de Empleados (vinculados a Persona, por institución)
// Persistencia vía API REST: /api/empleados
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/campos_persona.php';

requireAcceso('empleados');
$institucionId = institucionActual();

$accion = $_GET['accion'] ?? 'listar';
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        // Los datos personales viajan junto con los del vínculo: la ficha de
        // la persona se crea o se reutiliza en la API (api/core/Padron.php).
        $datos = datosPersonaDelFormulario() + [
            'estado' => $_POST['estado'] ?? 'ACTIVO',
        ];

        if ($accion === 'crear') {
            $respuesta = apiPost('empleados', $datos);
            $mensajeOk = 'Empleado registrado correctamente.';
        } else {
            $id = (int)($_POST['empleado_id'] ?? 0);
            $respuesta = apiPut('empleados/' . $id, $datos);
            $mensajeOk = 'Empleado actualizado correctamente.';
        }

        if ($respuesta['ok']) {
            flashSet('exito', $mensajeOk);
            redirigir('empleados.php');
        }
        $errores = apiErrores($respuesta);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'cambiar_estado') {
    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiPatch('empleados/' . $id . '/estado');
        flashSet($respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok'] ? 'Estado actualizado.' : apiError($respuesta));
    }
    redirigir('empleados.php');
}

$registroEditar = null;
if ($accion === 'editar') {
    $respuesta = apiGet('empleados/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('empleados.php'); }
}

$buscar = trim($_GET['q'] ?? '');
$listado = apiGet('empleados', [
    'q'      => $buscar,
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Empleados';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Empleados', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>👥 Gestión de Empleados</h1>
        <p>Mantenimiento del personal vinculado a la institución.</p>
    </div>
    <div class="flex-gap">
        <a class="btn btn-primario" href="empleados.php?accion=crear">+ Nuevo Empleado</a>
    </div>
</div>

<?php if ($accion === 'crear' || $accion === 'editar'): ?>
    <div class="card">
        <h3><?= $accion === 'crear' ? 'Registrar Empleado' : 'Editar Empleado' ?></h3>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="empleados.php?accion=<?= e($accion) ?>">
            <?= csrfCampo() ?>
            <?php if ($accion === 'editar'): ?><input type="hidden" name="empleado_id" value="<?= e((string)$registroEditar['EmpleadoId']) ?>"><?php endif; ?>
            <?php
            camposPersona([
                'titulo'   => 'Datos del empleado',
                'ayuda'    => 'Si esta persona ya consta en la institución —por ejemplo, como '
                            . 'representante de un estudiante—, se reutiliza su ficha en lugar de duplicarla.',
                'registro' => $registroEditar,
                'correo'   => 'opcional',
            ]);
            ?>

            <div class="form-row">
                <div class="form-group" style="flex:0 1 200px;">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="ACTIVO" <?= (($registroEditar['Estado'] ?? 'ACTIVO') === 'ACTIVO') ? 'selected' : '' ?>>ACTIVO</option>
                        <option value="INACTIVO" <?= (($registroEditar['Estado'] ?? '') === 'INACTIVO') ? 'selected' : '' ?>>INACTIVO</option>
                    </select>
                </div>
            </div>
            <div class="flex-gap">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="empleados.php" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Buscar</label>
            <input type="text" name="q" placeholder="Nombres, apellidos o identificación..." value="<?= e($buscar) ?>">
        </div>
        <button type="submit" class="btn btn-secundario">Buscar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead>
        <tr><th>ID</th><th>Empleado</th><th>Identificación</th><th>Email</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr>
        </thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="6" class="tabla-vacia">No se encontraron empleados registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td>#<?= e((string)$r['EmpleadoId']) ?></td>
                <td><strong><?= e(nombreCompleto($r['Nombres'], $r['Apellidos'])) ?></strong></td>
                <td><?= e($r['Identificacion'] ?: '—') ?></td>
                <td><?= e($r['Email'] ?: '—') ?></td>
                <td><?= badgeEstado($r['Estado']) ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="empleados.php?accion=editar&id=<?= e((string)$r['EmpleadoId']) ?>">Editar</a>
                        <form method="POST" action="empleados.php?accion=cambiar_estado" onsubmit="return confirm('¿Confirma el cambio de estado?');" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id" value="<?= e((string)$r['EmpleadoId']) ?>">
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
