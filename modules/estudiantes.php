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

/* La matrícula se hace por Carga de Información: aquí solo se edita, y solo los
   datos de contacto del estudiante, los de su representante y el parentesco. */
if ($accion === 'crear') {
    flashSet('error', 'La matrícula de estudiantes se realiza desde la opción «Carga de Información».');
    redirigir('estudiantes.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'editar') {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        $id = (int)($_POST['estudiante_id'] ?? 0);
        $respuesta = apiPut('estudiantes/' . $id, [
            'email'                  => trim($_POST['email'] ?? ''),
            'telefono'               => trim($_POST['telefono'] ?? ''),
            'rep_email'              => trim($_POST['rep_email'] ?? ''),
            'rep_telefono'           => trim($_POST['rep_telefono'] ?? ''),
            'representante_relacion' => $_POST['representante_relacion'] ?? '',
            'estado'                 => $_POST['estado'] ?? 'ACTIVO',
        ]);

        if ($respuesta['ok']) {
            flashSet('exito', 'Estudiante actualizado correctamente.');
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

/* Parentescos que ya se agruparon pero que la base todavía acepta por separado.
   El desplegable se arma con lo que la base admite —ofrecer algo que no se puede
   guardar sería peor—, así que si aparecen ABUELO y ABUELA sueltos no es que el
   sistema esté sin actualizar: es que falta correr la migración. Se dice. */
$relacionesRetiradas = apiMeta($listado, 'relaciones_retiradas', []);
$faltaMigrar = is_array($relacionesRetiradas) && $relacionesRetiradas !== [];

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
</div>

<div class="alerta alerta-info">
    Las matrículas se realizan desde la opción
    <strong><a href="carga_informacion.php">Carga de Información</a></strong>.
    Aquí puede corregir el <strong>correo</strong> y el <strong>teléfono</strong> del estudiante
    y de su representante, el <strong>parentesco</strong> y el <strong>estado</strong>.
</div>

<?php if ($faltaMigrar): ?>
    <div class="alerta alerta-advertencia">
        <strong>La base de datos todavía tiene la lista anterior de parentescos.</strong>
        Por eso el desplegable sigue mostrando
        <?= e(implode(', ', array_keys($relacionesRetiradas))) ?> por separado: se ofrece
        únicamente lo que la base puede guardar hoy.
        <div class="form-ayuda" style="margin-top:6px;">
            Para agruparlos en <strong>ABUELO/A</strong> y <strong>TIO/A</strong> y habilitar
            <strong>HERMANO/A</strong>, ejecute el script
            <code>08_ALTER_relacion_representante.sql</code> sobre la base. Convierte los
            registros ya grabados; no hay que tocar ninguna pantalla.
        </div>
    </div>
<?php endif; ?>

<?php if ($accion === 'editar'): ?>
    <?php $tieneRepresentante = !empty($registroEditar['RepresentanteId']); ?>
    <div class="card">
        <h3>Editar Estudiante</h3>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="estudiantes.php?accion=editar">
            <?= csrfCampo() ?>
            <input type="hidden" name="estudiante_id" value="<?= e((string)$registroEditar['EstudianteId']) ?>">

            <?php
            camposPersona([
                'titulo'    => 'Datos del estudiante',
                'registro'  => $registroEditar,
                'correo'    => 'opcional',
                'bloqueado' => true,
            ]);
            ?>

            <div class="form-row">
                <div class="form-group">
                    <label>Código de Estudiante</label>
                    <input type="text" class="campo-bloqueado" readonly tabindex="-1"
                           value="<?= e($registroEditar['CodigoEstudiante'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:0 1 200px;">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="ACTIVO" <?= (($registroEditar['Estado'] ?? 'ACTIVO') === 'ACTIVO') ? 'selected' : '' ?>>ACTIVO</option>
                        <option value="INACTIVO" <?= (($registroEditar['Estado'] ?? '') === 'INACTIVO') ? 'selected' : '' ?>>INACTIVO</option>
                    </select>
                </div>
            </div>

            <?php if ($tieneRepresentante): ?>
                <?php
                camposPersona([
                    'titulo'    => 'Representante legal',
                    'ayuda'     => 'El correo es la dirección a la que llegan los avisos de consentimiento '
                                 . 'del estudiante: mantenerlo al día es lo que hace que el consentimiento '
                                 . 'se pueda recoger.',
                    'prefijo'   => 'rep_',
                    'registro'  => $registroEditar,
                    'correo'    => 'obligatorio',
                    'bloqueado' => true,
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
            <?php else: ?>
                <div class="alerta alerta-advertencia">
                    Este estudiante no tiene representante legal asignado. Asígnelo desde la opción
                    <strong><a href="carga_informacion.php">Carga de Información</a></strong>.
                </div>
            <?php endif; ?>

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
