<?php
// modules/tipos_dato.php - CRUD de Tipos de Dato Personal (catálogo global)
// Persistencia vía API REST: /api/tipos-dato
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('tipos_dato');

$accion = $_GET['accion'] ?? 'listar';
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        $datos = [
            'codigo'      => trim($_POST['codigo'] ?? ''),
            'nombre'      => trim($_POST['nombre'] ?? ''),
            'categoria'   => trim($_POST['categoria'] ?? ''),
            'es_sensible' => ($_POST['es_sensible'] ?? 'NO') === 'SI' ? 'SI' : 'NO',
        ];

        if ($accion === 'crear') {
            $respuesta = apiPost('tipos-dato', $datos);
            $mensajeOk = 'Tipo de dato registrado correctamente.';
        } else {
            $id = (int)($_POST['tipodato_id'] ?? 0);
            $respuesta = apiPut('tipos-dato/' . $id, $datos);
            $mensajeOk = 'Tipo de dato actualizado correctamente.';
        }

        if ($respuesta['ok']) {
            flashSet('exito', $mensajeOk);
            redirigir('tipos_dato.php');
        }
        $errores = apiErrores($respuesta);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'eliminar') {
    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiDelete('tipos-dato/' . $id);
        flashSet($respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok'] ? 'Tipo de dato eliminado.' : apiError($respuesta));
    }
    redirigir('tipos_dato.php');
}

$registroEditar = null;
if ($accion === 'editar') {
    $respuesta = apiGet('tipos-dato/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('tipos_dato.php'); }
}

$buscar = trim($_GET['q'] ?? '');
$listado = apiGet('tipos-dato', [
    'q'      => $buscar,
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Tipos de Dato Personal';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Tipos de Dato', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🗂️ Tipos de Dato Personal</h1>
        <p>Catálogo de categorías de datos (identificación, contacto, salud, biométricos, etc.) usados en los consentimientos.</p>
    </div>
    <div class="flex-gap">
        <a class="btn btn-primario" href="tipos_dato.php?accion=crear">+ Nuevo Tipo de Dato</a>
    </div>
</div>

<?php if ($accion === 'crear' || $accion === 'editar'): ?>
    <div class="card">
        <h3><?= $accion === 'crear' ? 'Registrar Tipo de Dato' : 'Editar Tipo de Dato' ?></h3>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="tipos_dato.php?accion=<?= e($accion) ?>">
            <?= csrfCampo() ?>
            <?php if ($accion === 'editar'): ?><input type="hidden" name="tipodato_id" value="<?= e((string)$registroEditar['TipoDatoId']) ?>"><?php endif; ?>
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
            <div class="form-row">
                <div class="form-group">
                    <label>Categoría</label>
                    <input type="text" name="categoria" maxlength="100" placeholder="Identificación, Contacto, Salud, Académico..." value="<?= e($registroEditar['Categoria'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>¿Dato Sensible?</label>
                    <select name="es_sensible">
                        <option value="NO" <?= (($registroEditar['EsSensible'] ?? 'NO') === 'NO') ? 'selected' : '' ?>>NO</option>
                        <option value="SI" <?= (($registroEditar['EsSensible'] ?? '') === 'SI') ? 'selected' : '' ?>>SÍ</option>
                    </select>
                    <div class="form-ayuda">Datos sensibles: salud, religión, biométricos, origen étnico, orientación, etc.</div>
                </div>
            </div>
            <div class="flex-gap">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="tipos_dato.php" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Buscar</label>
            <input type="text" name="q" placeholder="Código, nombre o categoría..." value="<?= e($buscar) ?>">
        </div>
        <button type="submit" class="btn btn-secundario">Buscar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead><tr><th>Código</th><th>Nombre</th><th>Categoría</th><th>Sensible</th><th class="no-imprimir">Acciones</th></tr></thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="5" class="tabla-vacia">No se encontraron tipos de dato registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td><span class="badge badge-neutro"><?= e($r['Codigo']) ?></span></td>
                <td><strong><?= e($r['Nombre']) ?></strong></td>
                <td><?= e($r['Categoria'] ?: '—') ?></td>
                <td><?= $r['EsSensible'] === 'SI' ? '<span class="badge badge-sensible">SENSIBLE</span>' : '<span class="badge badge-neutro">Normal</span>' ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="tipos_dato.php?accion=editar&id=<?= e((string)$r['TipoDatoId']) ?>">Editar</a>
                        <form method="POST" action="tipos_dato.php?accion=eliminar" onsubmit="return confirm('¿Eliminar este tipo de dato? Esta acción no se puede deshacer.');" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id" value="<?= e((string)$r['TipoDatoId']) ?>">
                            <button type="submit" class="btn btn-sm btn-peligro">Eliminar</button>
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
