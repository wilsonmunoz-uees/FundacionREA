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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($accion, ['crear', 'editar'], true)) {
    if (!csrfValido()) {
        $errores[] = 'Token de seguridad inválido. Intente nuevamente.';
    } else {
        $datos = [
            'persona_id'       => (int)($_POST['persona_id'] ?? 0),
            'username'         => trim($_POST['username'] ?? ''),
            'email'            => trim($_POST['email'] ?? ''),
            'password'         => $_POST['password'] ?? '',
            'password_confirm' => $_POST['password_confirm'] ?? '',
            'estado'           => $_POST['estado'] ?? 'ACTIVO',
            'roles'            => array_map('intval', $_POST['roles'] ?? []),
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
            flashSet('exito', $mensajeOk);
            redirigir('usuarios.php');
        }
        $errores = apiErrores($respuesta);
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
    $respuesta = apiGet('usuarios/' . (int)($_GET['id'] ?? 0));
    $registroEditar = apiDatos($respuesta, null);
    if (!$registroEditar) { flashSet('error', 'Registro no encontrado.'); redirigir('usuarios.php'); }
    $rolesDelUsuario = $registroEditar['roles_asignados'] ?? [];
    $todosLosRoles   = $registroEditar['roles_disponibles'] ?? [];
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
        <h3><?= $accion === 'crear' ? 'Crear Usuario' : 'Editar Usuario' ?></h3>
        <?php foreach ($errores as $err): ?><div class="alerta alerta-error"><?= e($err) ?></div><?php endforeach; ?>
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
                    <label class="campo-requerido">Usuario</label>
                    <input type="text" name="username" maxlength="50" required value="<?= e($registroEditar['Username'] ?? '') ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <label>Correo Electrónico</label>
                    <input type="email" name="email" maxlength="150" value="<?= e($registroEditar['Email'] ?? '') ?>" autocomplete="off">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label<?= $accion === 'crear' ? ' class="campo-requerido"' : '' ?>>Contraseña<?= $accion === 'editar' ? ' (dejar en blanco para no cambiar)' : '' ?></label>
                    <input type="password" name="password" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label>Confirmar Contraseña</label>
                    <input type="password" name="password_confirm" autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label>Estado</label>
                    <select name="estado">
                        <option value="ACTIVO" <?= (($registroEditar['Estado'] ?? 'ACTIVO') === 'ACTIVO') ? 'selected' : '' ?>>ACTIVO</option>
                        <option value="INACTIVO" <?= (($registroEditar['Estado'] ?? '') === 'INACTIVO') ? 'selected' : '' ?>>INACTIVO</option>
                    </select>
                </div>
            </div>

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
