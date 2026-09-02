<?php
// modules/usuarios.php - CRUD de Usuarios del Sistema + asignación de Roles
// Persistencia vía API REST: /api/usuarios (el cifrado de contraseñas ocurre en la API)
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/selector_persona.php';

requireAcceso('usuarios');
$institucionId = institucionActual();

$accion = $_GET['accion'] ?? 'listar';
$errores = [];

/* Campos que la API rechazó, para marcarlos en el formulario.
   Con el formulario recién abierto está vacío: no hay nada que señalar. */
$camposMal = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        /* Ni correo ni contraseña viajan desde aquí: el correo es el de la
           persona y la clave la genera y envía la API. Ver el aviso de la
           pantalla y api/core/ClaveTemporal.php. */
        $datos = [
            'persona_id'        => (int)($_POST['persona_id'] ?? 0),
            'username'          => trim($_POST['username'] ?? ''),
            'estado'            => $_POST['estado'] ?? 'ACTIVO',
            'roles'             => array_map('intval', $_POST['roles'] ?? []),
            'restablecer_clave' => !empty($_POST['restablecer_clave']),
        ];

        if ($accion === 'crear') {
            $respuesta = apiPost('usuarios', $datos);
            $mensajeOk = 'Usuario creado correctamente.';
        } else {
            $id = (int)($_POST['usuario_id'] ?? 0);
            $respuesta = apiPut('usuarios/' . $id, $datos);
            $mensajeOk = 'Usuario actualizado correctamente.';
        }

        if ($respuesta['ok']) {
            /* Si el alta —o el restablecimiento— disparó el correo con la clave,
               se dice cómo fue: es lo único que quien administra sabrá de ella. */
            $credencial = apiDatos($respuesta, [])['credencial'] ?? null;

            if (is_array($credencial) && ($credencial['mensaje'] ?? '') !== '') {
                flashSet(
                    !empty($credencial['enviado']) ? 'exito' : 'advertencia',
                    $mensajeOk . ' ' . $credencial['mensaje']
                );
            } else {
                flashSet('exito', $mensajeOk);
            }
            redirigir('usuarios.php');
        }

        /* Falló: NO se redirige ni se vuelve a leer de la base. El formulario se
           vuelve a pintar con lo que la persona acababa de escribir y se le marca
           exactamente qué campos corregir. */
        $errores   = apiErrores($respuesta);
        $camposMal = apiCampos($respuesta);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $accion === 'cambiar_estado') {
    if (csrfValido()) {
        $id = (int)($_POST['id'] ?? 0);
        $respuesta = apiPatch('usuarios/' . $id . '/estado');
        flashSet($respuesta['ok'] ? 'exito' : 'error',
            $respuesta['ok'] ? 'Estado actualizado.' : apiError($respuesta));
    }
    redirigir('usuarios.php');
}

$registroEditar = null;
$rolesDelUsuario = [];
$todosLosRoles = [];
if ($accion === 'editar') {
    /* Tras un intento fallido de guardar, el identificador ya no viene por la
       URL sino en el propio formulario: si solo se mirara $_GET, la pantalla
       diría «Registro no encontrado» y se perdería todo lo escrito. */
    $idEditar = (int)($_GET['id'] ?? $_POST['usuario_id'] ?? 0);

    $respuesta = apiGet('usuarios/' . $idEditar);
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('usuarios.php'); }
    $rolesDelUsuario = $registroEditar['roles_asignados'] ?? [];
    $todosLosRoles   = $registroEditar['roles_disponibles'] ?? [];
}

/* Lo que debe verse en cada campo: lo que la persona acaba de escribir si el
   guardado falló, y si no, lo que consta grabado. Sin esto, un error en un solo
   campo obligaba a teclear el formulario entero otra vez. */
$reintento = $_SERVER['REQUEST_METHOD'] === 'POST' && $errores !== [];

$valorCampo = static function (string $campo, string $columna) use ($reintento, $registroEditar) {
    if ($reintento && isset($_POST[$campo])) {
        return (string)$_POST[$campo];
    }
    return (string)($registroEditar[$columna] ?? '');
};

/** Clase CSS para marcar en rojo el campo que la API rechazó. */
$marcaError = static fn(string $campo): string => isset($camposMal[$campo]) ? ' campo-error' : '';

if ($reintento) {
    $rolesDelUsuario = array_map('intval', $_POST['roles'] ?? []);
}

// Roles disponibles para el formulario de creación.
// Las personas ya no se listan aquí: se buscan en la subpantalla, que solo
// muestra a quienes todavía no tienen cuenta en esta institución.
if ($accion === 'crear') {
    $todosLosRoles = apiMeta(apiGet('usuarios/personas-disponibles', ['por_pagina' => 1]), 'roles_disponibles', []);
}

$buscar = trim($_GET['q'] ?? '');
$listado = apiGet('usuarios', [
    'q'      => $buscar,
    'pagina' => max(1, (int)($_GET['pagina'] ?? 1)),
]);
$registros = apiDatos($listado, []);
[$numPagina, $totalPaginas] = paginacionDesdeMeta(apiMeta($listado));

if (!$listado['ok']) {
    flashSet('error', apiError($listado));
}
if (empty($todosLosRoles)) {
    $todosLosRoles = apiMeta($listado, 'roles_disponibles', []);
}

// Roles por usuario para el listado (la API los devuelve en cada fila)
$rolesPorUsuario = [];
foreach ($registros as $fila) {
    $rolesPorUsuario[$fila['UsuarioId']] = $fila['Roles'] ?? [];
}

$pageTitle = 'Usuarios del Sistema';
$breadcrumb = [['label' => 'Registro de Datos', 'url' => null], ['label' => 'Usuarios', 'url' => null]];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>🔑 Usuarios del Sistema</h1>
        <p>Cuentas de acceso al sistema y asignación de roles.</p>
    </div>
    <div class="flex-gap">
        <a class="btn btn-primario" href="usuarios.php?accion=crear">+ Nuevo Usuario</a>
    </div>
</div>

<?php if ($accion === 'crear' || $accion === 'editar'): ?>
    <div class="card">
        <?php encabezadoFormulario($accion === 'crear' ? 'Crear Usuario' : 'Editar Usuario', 'usuarios.php'); ?>
        <?php if ($errores): ?>
            <?php /* Un solo recuadro con la lista: cinco recuadros seguidos se
                      leen como cinco problemas distintos y ocupan la pantalla. */ ?>
            <div class="alerta alerta-error">
                <strong>Corrija lo siguiente y vuelva a guardar:</strong>
                <ul class="lista-errores">
                    <?php foreach ($errores as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form method="POST" action="usuarios.php?accion=<?= e($accion) ?>" autocomplete="off">
            <?= csrfCampo() ?>
            <?php if ($accion === 'editar'): ?>
                <input type="hidden" name="usuario_id" value="<?= e((string)$registroEditar['UsuarioId']) ?>">
                <div class="form-group">
                    <label>Persona</label>
                    <input type="text" disabled value="<?= e(nombreCompleto($registroEditar['Nombres'], $registroEditar['Apellidos'])) ?>">
                </div>
            <?php else: ?>
                <?php
                // Persona: etiqueta + subpantalla de búsqueda, filtrada a quienes
                // aún no tienen cuenta de usuario en esta institución.
                $personaId = (int)($_POST['persona_id'] ?? 0);
                $persona   = personaResumen($personaId);

                selectorPersona([
                    'nombre'    => 'persona_id',
                    'etiqueta'  => 'Persona',
                    'requerido' => true,
                    'valor'     => $personaId ?: '',
                    'texto'     => $persona['texto'],
                    'detalle'   => $persona['detalle'],
                    'vacio'     => 'Ninguna persona seleccionada',
                    'filtros'   => ['sin_usuario' => 1],
                    'ayuda'     => 'Solo se muestran personas activas que aún no tienen usuario en esta institución.',
                ]);
                ?>
            <?php endif; ?>

            <div class="form-row">
                <div class="form-group">
                    <label for="username" class="campo-requerido">Usuario</label>
                    <input type="text" name="username" id="username" maxlength="50" required
                           class="<?= trim($marcaError('username')) ?>"
                           value="<?= e($valorCampo('username', 'Username')) ?>" autocomplete="off">
                </div>
                <div class="form-group" style="flex:0 1 180px;">
                    <label for="estado">Estado</label>
                    <?php $estadoActual = $valorCampo('estado', 'Estado') ?: 'ACTIVO'; ?>
                    <select name="estado" id="estado">
                        <option value="ACTIVO"   <?= $estadoActual === 'ACTIVO'   ? 'selected' : '' ?>>ACTIVO</option>
                        <option value="INACTIVO" <?= $estadoActual === 'INACTIVO' ? 'selected' : '' ?>>INACTIVO</option>
                    </select>
                </div>
            </div>

            <fieldset class="bloque-clave">
                <legend>Contraseña</legend>

                <?php if ($accion === 'crear'): ?>
                    <p class="form-ayuda">
                        🔒 La contraseña <strong>la genera el sistema</strong> y se envía por correo a la
                        persona, a la dirección que consta en su ficha del padrón. Nadie más la conoce:
                        no aparece en esta pantalla ni queda registrada en ningún sitio legible.
                        Al ingresar por primera vez, el sistema le exigirá cambiarla.
                    </p>
                <?php else: ?>
                    <div class="form-group form-check">
                        <label>
                            <input type="checkbox" name="restablecer_clave" value="1">
                            Restablecer la contraseña y enviarle una nueva por correo
                        </label>
                        <div class="form-ayuda">
                            Úselo cuando la persona no pueda entrar. Se genera otra contraseña temporal,
                            se le envía y se le vuelve a exigir el cambio en su próximo ingreso. Ni usted
                            ni nadie llega a verla.
                        </div>
                    </div>
                <?php endif; ?>
            </fieldset>

            <fieldset>
                <legend>Roles Asignados</legend>
                <?php if (empty($todosLosRoles)): ?>
                    <p class="texto-mutado">No hay roles registrados. <a href="roles.php?accion=crear">Cree un rol primero</a>.</p>
                <?php else: ?>
                    <div class="check-grid">
                        <?php foreach ($todosLosRoles as $rl): ?>
                            <label class="check-item">
                                <input type="checkbox" name="roles[]" value="<?= e((string)$rl['RolId']) ?>"
                                    <?= in_array($rl['RolId'], $rolesDelUsuario) ? 'checked' : '' ?>>
                                <?= e($rl['Nombre']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </fieldset>

            <div class="flex-gap">
                <button type="submit" class="btn btn-primario">Guardar</button>
                <a href="usuarios.php" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="filtros-bar">
    <form method="GET" class="flex-gap w-100">
        <div class="form-group" style="flex:1;">
            <label>Buscar</label>
            <input type="text" name="q" placeholder="Usuario o nombre de persona..." value="<?= e($buscar) ?>">
        </div>
        <button type="submit" class="btn btn-secundario">Buscar</button>
    </form>
</div>

<div class="tabla-wrap">
    <table class="tabla-datos">
        <thead><tr><th>Usuario</th><th>Persona</th><th>Roles</th><th>Último Acceso</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr></thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="6" class="tabla-vacia">No se encontraron usuarios registrados.</td></tr>
        <?php endif; ?>
        <?php foreach ($registros as $r): ?>
            <tr>
                <td><strong><?= e($r['Username']) ?></strong></td>
                <td><?= e(nombreCompleto($r['Nombres'], $r['Apellidos'])) ?></td>
                <td>
                    <?php if (!empty($rolesPorUsuario[$r['UsuarioId']])): ?>
                        <?php foreach ($rolesPorUsuario[$r['UsuarioId']] as $nombreRol): ?>
                            <span class="badge badge-info"><?= e($nombreRol) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="texto-mutado">Sin roles</span>
                    <?php endif; ?>
                </td>
                <td><?= f_fecha($r['UltimoAcceso']) ?></td>
                <td><?= badgeEstado($r['Estado']) ?></td>
                <td class="no-imprimir">
                    <div class="tabla-acciones">
                        <a class="btn btn-sm btn-secundario" href="usuarios.php?accion=editar&id=<?= e((string)$r['UsuarioId']) ?>">Editar</a>
                        <form method="POST" action="usuarios.php?accion=cambiar_estado" onsubmit="return confirm('¿Confirma el cambio de estado?');" style="display:inline;">
                            <?= csrfCampo() ?>
                            <input type="hidden" name="id" value="<?= e((string)$r['UsuarioId']) ?>">
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
