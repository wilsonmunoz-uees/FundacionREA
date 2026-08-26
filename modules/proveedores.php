<?php
// modules/proveedores.php - CRUD de Proveedores (bienes, servicios e infraestructura)
// Persistencia vía API REST: /api/proveedores
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/campos_persona.php';

requireAcceso('proveedores');
$institucionId = institucionActual();

$accion = $_GET['accion'] ?? 'listar';
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        $datos = datosPersonaDelFormulario() + [
            'ruc'          => trim($_POST['ruc'] ?? ''),
            'razon_social' => trim($_POST['razon_social'] ?? ''),
            'estado'       => $_POST['estado'] ?? 'ACTIVO',
        ];

        if ($accion === 'crear') {
            $respuesta = apiPost('proveedores', $datos);
            $mensajeOk = 'Proveedor registrado correctamente.';
        } else {
            $id = (int)($_POST['proveedor_id'] ?? 0);
            $respuesta = apiPut('proveedores/' . $id, $datos);
            $mensajeOk = 'Proveedor actualizado correctamente.';
        }

        if ($respuesta['ok']) {
            flashSet('exito', $mensajeOk);
            redirigir('proveedores.php');
        }
        $errores = apiErrores($respuesta);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'cambiar_estado') {
    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiPatch('proveedores/' . $id . '/estado');
        flashSet($respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok'] ? 'Estado actualizado.' : apiError($respuesta));
    }
    redirigir('proveedores.php');
}

$registroEditar = null;
if ($accion === 'editar') {
    $respuesta = apiGet('proveedores/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('proveedores.php'); }
}

$buscar = trim($_GET['q'] ?? '');
$listado = apiGet('proveedores', [
    'q'      => $buscar,
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Proveedores';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Proveedores', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>📦 Gestión de Proveedores</h1>
        <p>Control de proveedores de bienes, servicios e infraestructura.</p>
    </div>
    <div class="flex-gap">
        <a class="btn btn-primario" href="proveedores.php?accion=crear">+ Alta de Proveedor</a>
    </div>
</div>

<?php if ($accion === 'crear' || $accion === 'editar'): ?>
    <div class="card">
        <h3><?= $accion === 'crear' ? 'Registrar Proveedor' : 'Editar Proveedor' ?></h3>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="proveedores.php?accion=<?= e($accion) ?>">
            <?= csrfCampo() ?>
            <?php if ($accion === 'editar'): ?><input type="hidden" name="proveedor_id" value="<?= e((string)$registroEditar['ProveedorId']) ?>"><?php endif; ?>
            <?php
            camposPersona([
                'titulo'   => 'Persona de contacto',
                'ayuda'    => 'Quien atiende la relación con la institución. Si ya consta registrada, '
                            . 'se reutiliza su ficha.',
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
            <div class="form-row">
                <div class="form-group">
                    <label class="campo-requerido">Razón Social</label>
                    <input type="text" name="razon_social" maxlength="150" required value="<?= e($registroEditar['RazonSocial'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>RUC</label>
                    <input type="text" name="ruc" maxlength="20" value="<?= e($registroEditar['Ruc'] ?? '') ?>">
                </div>
            </div>
            <div class="flex-gap">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="proveedores.php" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Buscar</label>
            <input type="text" name="q" placeholder="Razón social o RUC..." value="<?= e($buscar) ?>">
        </div>
        <button type="submit" class="btn btn-secundario">Buscar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead>
        <tr><th>ID</th><th>Razón Social</th><th>RUC</th><th>Contacto</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr>
        </thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="6" class="tabla-vacia">No se encontraron proveedores registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td>#<?= e((string)$r['ProveedorId']) ?></td>
                <td><strong><?= e($r['RazonSocial']) ?></strong></td>
                <td><?= e($r['Ruc'] ?: '—') ?></td>
                <td><?= e(nombreCompleto($r['Nombres'] ?? null, $r['Apellidos'] ?? null)) ?></td>
                <td><?= badgeEstado($r['Estado']) ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="proveedores.php?accion=editar&id=<?= e((string)$r['ProveedorId']) ?>">Editar</a>
                        <form method="POST" action="proveedores.php?accion=cambiar_estado" onsubmit="return confirm('¿Confirma el cambio de estado?');" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id" value="<?= e((string)$r['ProveedorId']) ?>">
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
