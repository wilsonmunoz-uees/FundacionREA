<?php
// modules/estudiantes.php - CRUD de Estudiantes (con representante)
// Persistencia vía API REST: /api/estudiantes
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/campos_persona.php';

requireAcceso('estudiantes');
$institucionId = institucionActual();
/* Las relaciones del representante las publica la API leyéndolas del propio
   enum de la base, de modo que el desplegable solo ofrezca lo que se puede
   guardar. Si la base todavía no tiene las relaciones nuevas, aquí saldrán las
   cuatro de siempre en lugar de las nueve. */
$relaciones = ['MADRE', 'PADRE', 'REPRESENTANTE LEGAL', 'OTRO'];

$accion = $_GET['accion'] ?? 'listar';
$errores = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        // Datos del estudiante y de su representante en la misma petición: la
        // API crea o reutiliza cada ficha (api/core/Padron.php).
        $datos = datosPersonaDelFormulario()
               + datosPersonaDelFormulario('rep_')
               + [
                   'codigo_estudiante'      => trim($_POST['codigo_estudiante'] ?? ''),
                   'representante_relacion' => $_POST['representante_relacion'] ?? '',
                   'estado'                 => $_POST['estado'] ?? 'ACTIVO',
               ];

        if ($accion === 'crear') {
            $respuesta = apiPost('estudiantes', $datos);
            $mensajeOk = 'Estudiante matriculado correctamente.';
        } else {
            $id = (int)($_POST['estudiante_id'] ?? 0);
            $respuesta = apiPut('estudiantes/' . $id, $datos);
            $mensajeOk = 'Estudiante actualizado correctamente.';
        }

        if ($respuesta['ok']) {
            flashSet('exito', $mensajeOk);
            redirigir('estudiantes.php');
        }
        $errores = apiErrores($respuesta);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'cambiar_estado') {
    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiPatch('estudiantes/' . $id . '/estado');
        flashSet($respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok'] ? 'Estado actualizado.' : apiError($respuesta));
    }
    redirigir('estudiantes.php');
}

$registroEditar = null;
if ($accion === 'editar') {
    $respuesta = apiGet('estudiantes/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('estudiantes.php'); }
}

$buscar = trim($_GET['q'] ?? '');
$listado = apiGet('estudiantes', [
    'q'      => $buscar,
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

/* La API devuelve en la metadata las relaciones que la base acepta hoy */
$relacionesApi = apiMeta($listado, 'relaciones', []);
if (is_array($relacionesApi) && $relacionesApi) {
    $relaciones = $relacionesApi;
}

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Estudiantes';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Estudiantes', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🎓 Gestión de Estudiantes</h1>
        <p>Administración de alumnos matriculados y sus representantes legales.</p>
    </div>
    <div class="flex-gap">
        <a class="btn btn-primario" href="estudiantes.php?accion=crear">+ Matricular Estudiante</a>
    </div>
</div>

<?php if ($accion === 'crear' || $accion === 'editar'): ?>
    <div class="card">
        <h3><?= $accion === 'crear' ? 'Matricular Estudiante' : 'Editar Estudiante' ?></h3>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="estudiantes.php?accion=<?= e($accion) ?>">
            <?= csrfCampo() ?>
            <?php if ($accion === 'editar'): ?><input type="hidden" name="estudiante_id" value="<?= e((string)$registroEditar['EstudianteId']) ?>"><?php endif; ?>

            <?php
            camposPersona([
                'titulo'   => 'Datos del estudiante',
                'registro' => $registroEditar,
                'correo'   => 'opcional',
            ]);
            ?>

            <div class="form-row">
                <div class="form-group">
                    <label class="campo-requerido">Código de Estudiante</label>
                    <input type="text" name="codigo_estudiante" maxlength="20" required
                           value="<?= e($_POST['codigo_estudiante'] ?? $registroEditar['CodigoEstudiante'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:0 1 200px;">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="ACTIVO" <?= (($registroEditar['Estado'] ?? 'ACTIVO') === 'ACTIVO') ? 'selected' : '' ?>>ACTIVO</option>
                        <option value="INACTIVO" <?= (($registroEditar['Estado'] ?? '') === 'INACTIVO') ? 'selected' : '' ?>>INACTIVO</option>
                    </select>
                </div>
            </div>

            <?php
            camposPersona([
                'titulo'   => 'Representante legal',
                'ayuda'    => 'Opcional, pero si lo registra su correo es obligatorio: es la dirección a la '
                            . 'que llegan los avisos de consentimiento del estudiante. Si el representante ya '
                            . 'consta en la institución —por representar a un hermano, o por ser empleado—, '
                            . 'se reutiliza su ficha.',
                'prefijo'  => 'rep_',
                'registro' => $registroEditar,
                'correo'   => 'obligatorio',
            ]);
            ?>

            <div class="form-row">
                <div class="form-group" style="flex:0 1 260px;">
                    <label>Relación con el estudiante</label>
                    <select name="representante_relacion">
                        <option value="">-- Seleccione --</option>
                        <?php
                        $relacionActual = $_POST['representante_relacion'] ?? $registroEditar['RepresentanteRelacion'] ?? '';
                        foreach ($relaciones as $rel): ?>
                            <option value="<?= e($rel) ?>" <?= $relacionActual === $rel ? 'selected' : '' ?>><?= e($rel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="flex-gap">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="estudiantes.php" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Buscar</label>
            <input type="text" name="q" placeholder="Nombre, código o identificación..." value="<?= e($buscar) ?>">
        </div>
        <button type="submit" class="btn btn-secundario">Buscar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead>
        <tr><th>Código</th><th>Estudiante</th><th>Identificación</th><th>Representante</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr>
        </thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="6" class="tabla-vacia">No se encontraron estudiantes registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td><?= e($r['CodigoEstudiante']) ?></td>
                <td><strong><?= e(nombreCompleto($r['Nombres'], $r['Apellidos'])) ?></strong></td>
                <td><?= e($r['Identificacion'] ?: '—') ?></td>
                <td><?= $r['RepresentanteId'] ? e(nombreCompleto($r['RepNombres'], $r['RepApellidos'])) . ' (' . e($r['RepresentanteRelacion'] ?? '') . ')' : '—' ?></td>
                <td><?= badgeEstado($r['Estado']) ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="estudiantes.php?accion=editar&id=<?= e((string)$r['EstudianteId']) ?>">Editar</a>
                        <form method="POST" action="estudiantes.php?accion=cambiar_estado" onsubmit="return confirm('¿Confirma el cambio de estado?');" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id" value="<?= e((string)$r['EstudianteId']) ?>">
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
