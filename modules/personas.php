<?php
// modules/personas.php - CRUD de Personas (entidad base: empleados, estudiantes, representantes, proveedores, usuarios)
// Toda la persistencia se realiza contra la API REST: /api/personas
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('personas');

$accion = $_GET['accion'] ?? 'listar';
$errores = [];
$tiposId = ['CEDULA', 'RUC', 'PASAPORTE'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        $datos = [
            'tipo_identificacion' => $_POST['tipo_identificacion'] ?? '',
            'identificacion'      => trim($_POST['identificacion'] ?? ''),
            'nombres'             => trim($_POST['nombres'] ?? ''),
            'apellidos'           => trim($_POST['apellidos'] ?? ''),
            'email'               => trim($_POST['email'] ?? ''),
            'telefono'            => trim($_POST['telefono'] ?? ''),
            'estado'              => $_POST['estado'] ?? 'ACTIVO',
        ];

        if ($accion === 'crear') {
            $respuesta = apiPost('personas', $datos);
            $mensajeOk = 'Persona registrada correctamente.';
        } else {
            $id = (int)($_POST['persona_id'] ?? 0);
            $respuesta = apiPut('personas/' . $id, $datos);
            $mensajeOk = 'Persona actualizada correctamente.';
        }

        if ($respuesta['ok']) {
            flashSet('exito', $mensajeOk);
            redirigir('personas.php');
        }
        $errores = apiErrores($respuesta);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'cambiar_estado') {
    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiPatch('personas/' . $id . '/estado');
        flashSet($respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok'] ? 'Estado actualizado.' : apiError($respuesta));
    }
    redirigir('personas.php');
}

$registroEditar = null;
if ($accion === 'editar') {
    $respuesta = apiGet('personas/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('personas.php'); }
}

$buscar = trim($_GET['q'] ?? '');
$listado = apiGet('personas', [
    'q'      => $buscar,
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Personas';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Personas', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🧑 Personas</h1>
        <p>Entidad base: toda persona (empleado, estudiante, representante, proveedor o usuario) parte de aquí.</p>
    </div>
    <div class="flex-gap">
        <a class="btn btn-primario" href="personas.php?accion=crear">+ Nueva Persona</a>
    </div>
</div>

<?php if ($accion === 'crear' || $accion === 'editar'): ?>
    <div class="card">
        <h3><?= $accion === 'crear' ? 'Registrar Persona' : 'Editar Persona' ?></h3>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="personas.php?accion=<?= e($accion) ?>">
            <?= csrfCampo() ?>
            <?php if ($accion === 'editar'): ?><input type="hidden" name="persona_id" value="<?= e((string)$registroEditar['PersonaId']) ?>"><?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Tipo de Identificación</label>
                    <select name="tipo_identificacion">
                        <option value="">-- Seleccione --</option>
                        <?php foreach ($tiposId as $t): ?>
                            <option value="<?= $t ?>" <?= (($registroEditar['TipoIdentificacion'] ?? '') === $t) ? 'selected' : '' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Número de Identificación</label>
                    <input type="text" name="identificacion" maxlength="50" value="<?= e($registroEditar['Identificacion'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="campo-requerido">Nombres</label>
                    <input type="text" name="nombres" maxlength="100" required value="<?= e($registroEditar['Nombres'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="campo-requerido">Apellidos</label>
                    <input type="text" name="apellidos" maxlength="100" required value="<?= e($registroEditar['Apellidos'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Correo Electrónico</label>
                    <input type="email" name="email" maxlength="150" value="<?= e($registroEditar['Email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Teléfono</label>
                    <input type="text" name="telefono" maxlength="20" value="<?= e($registroEditar['Telefono'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="ACTIVO" <?= (($registroEditar['Estado'] ?? 'ACTIVO') === 'ACTIVO') ? 'selected' : '' ?>>ACTIVO</option>
                        <option value="INACTIVO" <?= (($registroEditar['Estado'] ?? '') === 'INACTIVO') ? 'selected' : '' ?>>INACTIVO</option>
                    </select>
                </div>
            </div>
            <div class="flex-gap">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="personas.php" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Buscar</label>
            <input type="text" name="q" placeholder="Nombres, apellidos, identificación o email..." value="<?= e($buscar) ?>">
        </div>
        <button type="submit" class="btn btn-secundario">Buscar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead>
        <tr><th>ID</th><th>Identificación</th><th>Nombre Completo</th><th>Email</th><th>Teléfono</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr>
        </thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="7" class="tabla-vacia">No se encontraron personas registradas.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td>#<?= e((string)$r['PersonaId']) ?></td>
                <td><?= e($r['TipoIdentificacion'] ?: '—') ?> <?= e($r['Identificacion'] ?: '') ?></td>
                <td><strong><?= e(nombreCompleto($r['Nombres'], $r['Apellidos'])) ?></strong></td>
                <td><?= e($r['Email'] ?: '—') ?></td>
                <td><?= e($r['Telefono'] ?: '—') ?></td>
                <td><?= badgeEstado($r['Estado']) ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="personas.php?accion=editar&id=<?= e((string)$r['PersonaId']) ?>">Editar</a>
                        <?php if (puedeAcceder('consulta_buscar_persona')): ?>
                            <a class="btn btn-sm btn-secundario" href="../consultas/buscar_persona.php?id=<?= e((string)$r['PersonaId']) ?>">Ver 360°</a>
                        <?php endif; ?>
                        <form method="POST" action="personas.php?accion=cambiar_estado" onsubmit="return confirm('¿Confirma el cambio de estado?');" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id" value="<?= e((string)$r['PersonaId']) ?>">
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
