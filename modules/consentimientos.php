<?php
// modules/consentimientos.php - CRUD de Consentimientos (núcleo del sistema)
// Gestiona el consentimiento otorgado por una persona para una finalidad determinada,
// el detalle de los tipos de dato autorizados y la bitácora de auditoría.
// Toda la persistencia ocurre en la API REST: /api/consentimientos
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/selector_persona.php';

requireAcceso('consentimientos');
$institucionId = institucionActual();
$medios = ['WEB', 'EMAIL', 'WHATSAPP', 'APP'];

$accion = $_GET['accion'] ?? 'listar';
$errores = [];

// ---------- Crear / Editar ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        $datos = [
            'persona_id'           => (int)($_POST['persona_id'] ?? 0),
            'finalidad_id'         => (int)($_POST['finalidad_id'] ?? 0),
            'representante_id'     => (int)($_POST['representante_id'] ?? 0),
            'medio'                => $_POST['medio'] ?? '',
            'version_politica'     => trim($_POST['version_politica'] ?? ''),
            'fecha_consentimiento' => str_replace('T', ' ', trim($_POST['fecha_consentimiento'] ?? '')),
            'ip_origen'            => trim($_POST['ip_origen'] ?? ''),
            'tipos_autorizados'    => array_map('intval', $_POST['tipos_autorizados'] ?? []),
        ];

        if ($accion === 'crear') {
            $respuesta = apiPost('consentimientos', $datos);
            $mensajeOk = 'Consentimiento registrado correctamente.';
        } else {
            $id = (int)($_POST['consentimiento_id'] ?? 0);
            $respuesta = apiPut('consentimientos/' . $id, $datos);
            $mensajeOk = 'Consentimiento actualizado correctamente.';
        }

        if ($respuesta['ok']) {
            flashSet('exito', $mensajeOk);
            redirigir('consentimientos.php');
        }
        $errores = apiErrores($respuesta);
    }
}

// ---------- Revocar / Reactivar ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['revocar', 'reactivar'], true)) {
    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiPost('consentimientos/' . $id . '/' . $accion, [
            'observacion' => trim($_POST['observacion'] ?? ''),
        ]);
        flashSet(
            $respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok']
                ? ($accion === 'revocar' ? 'Consentimiento revocado correctamente.' : 'Consentimiento reactivado correctamente.')
                : apiError($respuesta)
        );
    }
    redirigir('consentimientos.php');
}

// ---------- Datos para edición ----------
$registroEditar = null;
$tiposAutorizadosActuales = [];
if ($accion === 'editar' || $accion === 'ver') {
    $respuesta = apiGet('consentimientos/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('consentimientos.php'); }
    $tiposAutorizadosActuales = $registroEditar['tipos_autorizados'] ?? [];
}

// ---------- Catálogos para los formularios (una sola llamada) ----------
// Las personas ya no se cargan todas: se eligen en la subpantalla de búsqueda.
$catalogos = apiDatos(apiGet('consentimientos/catalogos'), []);
$finalidadesDisponibles = $catalogos['finalidades'] ?? [];
$tiposDatoDisponibles   = $catalogos['tipos_dato'] ?? [];

// ---------- Listado con filtros ----------
$buscar = trim($_GET['q'] ?? '');
$filtroEstado = $_GET['estado'] ?? '';
$listado = apiGet('consentimientos', [
    'q'      => $buscar,
    'estado' => in_array($filtroEstado, ['ACTIVO', 'INACTIVO'], true) ? $filtroEstado : '',
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}

$pageTitle = 'Consentimientos';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Consentimientos', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>✅ Consentimientos</h1>
        <p>Registro del consentimiento de titulares para el tratamiento de sus datos personales, según finalidad.</p>
    </div>
    <div class="flex-gap">
        <a class="btn btn-primario" href="consentimientos.php?accion=crear">+ Registrar Consentimiento</a>
    </div>
</div>

<?php if ($accion === 'crear' || $accion === 'editar'): ?>
    <div class="card">
        <h3><?= $accion === 'crear' ? 'Registrar Consentimiento' : 'Editar Consentimiento' ?></h3>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
        <form method="POST" action="consentimientos.php?accion=<?= e($accion) ?>">
            <?= csrfCampo() ?>
            <?php if ($accion === 'editar'): ?><input type="hidden" name="consentimiento_id" value="<?= e((string)$registroEditar['ConsentimientoId']) ?>"><?php endif; ?>

            <fieldset>
                <legend>Titular y Finalidad</legend>
                <div class="form-row">
                    <div style="flex:2;">
                        <?php
                        // Titular del dato: etiqueta + subpantalla de búsqueda
                        $personaId = (int)($registroEditar['PersonaId'] ?? ($_POST['persona_id'] ?? 0));
                        $persona   = personaResumen($personaId, $registroEditar);

                        selectorPersona([
                            'nombre'    => 'persona_id',
                            'etiqueta'  => 'Persona (Titular del Dato)',
                            'requerido' => true,
                            'valor'     => $personaId ?: '',
                            'texto'     => $persona['texto'],
                            'detalle'   => $persona['detalle'],
                            'vacio'     => 'Ningún titular seleccionado',
                            'ayuda'     => 'Pulse Buscar para elegir a la persona en el directorio.',
                        ]);
                        ?>
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label class="campo-requerido">Finalidad del Tratamiento</label>
                        <select name="finalidad_id" required>
                            <option value="">-- Seleccione --</option>
                            <?php foreach ($finalidadesDisponibles as $f): ?>
                                <option value="<?= e((string)$f['FinalidadId']) ?>" <?= (($registroEditar['FinalidadId'] ?? null) == $f['FinalidadId']) ? 'selected' : '' ?>><?= e($f['Nombre']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div style="flex:2;">
                        <?php
                        // Representante: se excluye de la búsqueda al propio titular
                        $repId = (int)($registroEditar['RepresentanteId'] ?? ($_POST['representante_id'] ?? 0));
                        $rep   = personaResumen($repId, [
                            'Nombres'        => $registroEditar['RepNombres'] ?? null,
                            'Apellidos'      => $registroEditar['RepApellidos'] ?? null,
                            'Identificacion' => $registroEditar['RepIdentificacion'] ?? null,
                        ]);

                        selectorPersona([
                            'nombre'        => 'representante_id',
                            'etiqueta'      => 'Representante (si el titular es menor de edad)',
                            'valor'         => $repId ?: '',
                            'texto'         => $rep['texto'],
                            'detalle'       => $rep['detalle'],
                            'vacio'         => 'Sin representante',
                            'excluir_campo' => 'persona_id',
                            'ayuda'         => 'Opcional. No aparece el titular ya seleccionado.',
                        ]);
                        ?>
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Detalle del Consentimiento</legend>
                <div class="form-row">
                    <div class="form-group">
                        <label>Fecha y Hora del Consentimiento</label>
                        <input type="datetime-local" name="fecha_consentimiento"
                               value="<?= e(!empty($registroEditar['FechaConsentimiento']) ? str_replace(' ', 'T', substr($registroEditar['FechaConsentimiento'], 0, 16)) : date('Y-m-d\TH:i')) ?>">
                    </div>
                    <div class="form-group">
                        <label>Medio</label>
                        <select name="medio">
                            <option value="">-- Seleccione --</option>
                            <?php foreach ($medios as $m): ?>
                                <option value="<?= $m ?>" <?= (($registroEditar['MedioConsentimiento'] ?? '') === $m) ? 'selected' : '' ?>><?= $m ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Versión de la Política de Privacidad</label>
                        <input type="text" name="version_politica" maxlength="50" value="<?= e($registroEditar['VersionPolitica'] ?? '') ?>" placeholder="Ej: v1.0">
                    </div>
                    <div class="form-group">
                        <label>IP de Origen</label>
                        <input type="text" name="ip_origen" maxlength="20" value="<?= e($registroEditar['IpOrigen'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')) ?>">
                    </div>
                </div>
            </fieldset>

            <fieldset>
                <legend>Tipos de Dato Personal Autorizados</legend>
                <?php if (empty($tiposDatoDisponibles)): ?>
                    <p class="texto-mutado">No hay tipos de dato registrados. <a href="tipos_dato.php?accion=crear">Cree un tipo de dato primero</a>.</p>
                <?php else: ?>
                    <div class="check-grid">
                        <?php foreach ($tiposDatoDisponibles as $td): ?>
                            <label class="check-item">
                                <input type="checkbox" name="tipos_autorizados[]" value="<?= e((string)$td['TipoDatoId']) ?>"
                                    <?= in_array($td['TipoDatoId'], $tiposAutorizadosActuales) ? 'checked' : '' ?>>
                                <?= e($td['Nombre']) ?>
                                <?php if ($td['EsSensible'] === 'SI'): ?><span class="badge badge-sensible">SENSIBLE</span><?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>

            <div class="flex-gap">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="consentimientos.php" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Buscar</label>
            <input type="text" name="q" placeholder="Persona o finalidad..." value="<?= e($buscar) ?>">
        </div>
        <div class="form-group">
            <label>Estado</label>
            <select name="estado">
                <option value="">Todos</option>
                <option value="ACTIVO" <?= $filtroEstado === 'ACTIVO' ? 'selected' : '' ?>>Activo</option>
                <option value="INACTIVO" <?= $filtroEstado === 'INACTIVO' ? 'selected' : '' ?>>Revocado</option>
            </select>
        </div>
        <button type="submit" class="btn btn-secundario">Filtrar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead><tr><th>Titular</th><th>Finalidad</th><th>Fecha</th><th>Medio</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr></thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="6" class="tabla-vacia">No se encontraron consentimientos registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td><strong><?= e(nombreCompleto($r['Nombres'], $r['Apellidos'])) ?></strong></td>
                <td><?= e($r['FinalidadNombre'] ?: '—') ?></td>
                <td><?= f_fecha($r['FechaConsentimiento']) ?></td>
                <td><?= e($r['MedioConsentimiento'] ?: '—') ?></td>
                <td><?= badgeEstado($r['Estado']) ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="consentimientos.php?accion=editar&id=<?= e((string)$r['ConsentimientoId']) ?>">Editar</a>
                        <?php if (puedeAcceder('consulta_historial')): ?>
                            <a class="btn btn-sm btn-secundario" href="../consultas/historial_consentimientos.php?consentimiento_id=<?= e((string)$r['ConsentimientoId']) ?>">Historial</a>
                        <?php endif; ?>
                        <?php if ($r['Estado'] === 'ACTIVO'): ?>
                            <form method="POST" action="consentimientos.php?accion=revocar" onsubmit="return confirm('¿Confirma la revocación de este consentimiento?');" style="display:inline;">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="id" value="<?= e((string)$r['ConsentimientoId']) ?>">
                                <button type="submit" class="btn btn-sm btn-peligro">Revocar</button>
                            </form>
                        <?php else: ?>
                            <form method="POST" action="consentimientos.php?accion=reactivar" onsubmit="return confirm('¿Reactivar este consentimiento?');" style="display:inline;">
                                <?= csrfCampo() ?>
                                <input type="hidden" name="id" value="<?= e((string)$r['ConsentimientoId']) ?>">
                                <button type="submit" class="btn btn-sm btn-exito">Reactivar</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php renderPaginacion($numPagina, $totalPaginas); ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
