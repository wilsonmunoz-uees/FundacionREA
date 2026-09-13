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

        /* Instituciones y sus roles. Van siempre que el formulario las haya
           mostrado; la API los ignora si quien edita no es SuperAdmin, de modo
           que no basta con quitarlas de la pantalla para colarlas. */
        if (isset($_POST['instituciones'])) {
            $datos['instituciones'] = array_map('intval', (array)$_POST['instituciones']);

            $porInstitucion = [];
            foreach ((array)($_POST['roles_inst'] ?? []) as $institucionId => $roles) {
                $porInstitucion[(int)$institucionId] = array_map('intval', (array)$roles);
            }
            $datos['roles_por_institucion'] = $porInstitucion;
        }

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

/* Mapa de instituciones de la red con sus roles. Solo llega si quien está en la
   pantalla es SuperAdmin: para los demás administradores la cuenta se sigue
   gestionando como siempre, dentro de su institución. */
$mapaInstituciones = [];
$puedeAsignarInstituciones = false;

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

    $puedeAsignarInstituciones = !empty($registroEditar['puede_asignar_instituciones']);
    $mapaInstituciones         = $registroEditar['instituciones'] ?? [];
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
    $disponibles   = apiGet('usuarios/personas-disponibles', ['por_pagina' => 1]);
    $todosLosRoles = apiMeta($disponibles, 'roles_disponibles', []);

    $puedeAsignarInstituciones = (bool)apiMeta($disponibles, 'puede_asignar_instituciones', false);
    $mapaInstituciones         = apiMeta($disponibles, 'instituciones', []);
}

/* Tras un intento fallido, las casillas se vuelven a pintar con lo que la
   persona acababa de marcar, no con lo que consta grabado: si no, corregir el
   nombre de usuario le borraría de la pantalla veinte instituciones. */
if ($reintento && $mapaInstituciones) {
    $marcadas   = array_map('intval', (array)($_POST['instituciones'] ?? []));
    $rolesPost  = (array)($_POST['roles_inst'] ?? []);

    foreach ($mapaInstituciones as &$inst) {
        $id = (int)$inst['id'];
        $inst['asignada']        = !empty($inst['propia']) || in_array($id, $marcadas, true);
        $inst['roles_asignados'] = array_map('intval', (array)($rolesPost[$id] ?? []));
    }
    unset($inst);
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

            <?php if ($puedeAsignarInstituciones && $mapaInstituciones): ?>
                <fieldset>
                    <legend>Instituciones y roles</legend>
                    <p class="form-ayuda">
                        Marque las instituciones en las que esta cuenta podrá <strong>iniciar sesión</strong>,
                        y dentro de cada una, qué roles tendrá allí. Los roles no viajan con la persona:
                        puede consultar en una escuela y registrar en otra. Su institución va siempre
                        marcada y no se puede quitar.
                    </p>

                    <?php /* El filtro viene oculto y lo muestra js/instituciones_usuario.js:
                             sin JavaScript la lista se recorre entera, que con veintiuna
                             instituciones es incómodo pero funciona. */ ?>
                    <input type="text" id="filtro_instituciones" class="login-buscador" hidden
                           autocomplete="off" aria-label="Filtrar la lista de instituciones"
                           placeholder="Escriba parte del nombre para filtrar…">

                    <div class="instituciones-lista">
                        <p class="texto-mutado" id="instituciones_sin_resultado" hidden>
                            Ninguna institución coincide con lo que escribió.
                        </p>
                        <?php foreach ($mapaInstituciones as $inst): ?>
                            <?php
                            $idInst   = (int)$inst['id'];
                            $esPropia = !empty($inst['propia']);
                            $marcada  = !empty($inst['asignada']);
                            $rolesInst = array_map('intval', $inst['roles_asignados'] ?? []);
                            ?>
                            <details class="institucion-bloque" <?= $marcada ? 'open' : '' ?>
                                     data-nombre="<?= e($inst['nombre']) ?>">
                                <summary>
                                    <?php /* La casilla va dentro del resumen para que se vea y se
                                             marque sin tener que desplegar nada. Con la institución
                                             propia se dibuja bloqueada y su valor viaja aparte, en un
                                             campo oculto: un checkbox deshabilitado no se envía. */ ?>
                                    <label class="check-item" onclick="event.stopPropagation();">
                                        <input type="checkbox" name="instituciones[]"
                                               value="<?= e((string)$idInst) ?>"
                                               <?= $marcada ? 'checked' : '' ?>
                                               <?= $esPropia ? 'disabled' : '' ?>>
                                        <strong><?= e($inst['nombre']) ?></strong>
                                    </label>
                                    <?php if ($esPropia): ?>
                                        <input type="hidden" name="instituciones[]" value="<?= e((string)$idInst) ?>">
                                        <span class="badge badge-info">su institución</span>
                                    <?php endif; ?>
                                    <span class="texto-mutado institucion-conteo" <?= $rolesInst ? '' : 'hidden' ?>>
                                        <?= count($rolesInst) ?> <?= count($rolesInst) === 1 ? 'rol' : 'roles' ?>
                                    </span>
                                </summary>

                                <?php if (empty($inst['roles_disponibles'])): ?>
                                    <p class="texto-mutado">
                                        Esta institución todavía no tiene roles registrados. La cuenta podrá
                                        entrar, pero no verá ninguna opción hasta que se le cree alguno.
                                    </p>
                                <?php else: ?>
                                    <div class="check-grid">
                                        <?php foreach ($inst['roles_disponibles'] as $rl): ?>
                                            <label class="check-item">
                                                <input type="checkbox"
                                                       name="roles_inst[<?= e((string)$idInst) ?>][]"
                                                       value="<?= e((string)$rl['RolId']) ?>"
                                                       <?= in_array((int)$rl['RolId'], $rolesInst, true) ? 'checked' : '' ?>>
                                                <?= e($rl['Nombre']) ?>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </details>
                        <?php endforeach; ?>
                    </div>
                    <script src="<?= e(APP_ROOT) ?>js/instituciones_usuario.js" defer></script>
                </fieldset>
            <?php else: ?>
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
                    <p class="form-ayuda">
                        Estos son los roles en <strong><?= e($_SESSION['institucion_nombre'] ?? 'esta institución') ?></strong>.
                        Dar acceso a otra institución de la red lo hace el SuperAdmin.
                    </p>
                </fieldset>
            <?php endif; ?>

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
        <thead><tr><th>Usuario</th><th>Persona</th><th>Roles</th><th>Entra en</th><th>Último Acceso</th><th>Estado</th><th class="no-imprimir">Acciones</th></tr></thead>
        <tbody>
        <?php if (empty($registros)): ?>
            <tr><td colspan="7" class="tabla-vacia">No se encontraron usuarios registrados.</td></tr>
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
                <td>
                    <?php
                    /* Una cuenta que alcanza tres escuelas no se parece en nada a
                       una que alcanza la suya, y conviene verlo sin entrar a
                       editarla. Con más de dos se dice el número y se dejan los
                       nombres en el título, que si no la fila se vuelve ilegible. */
                    $suyas = $r['Instituciones'] ?? [];
                    ?>
                    <?php if (count($suyas) > 2): ?>
                        <span class="badge badge-info" title="<?= e(implode(', ', $suyas)) ?>">
                            <?= count($suyas) ?> instituciones
                        </span>
                    <?php elseif ($suyas): ?>
                        <?php foreach ($suyas as $nombreInst): ?>
                            <span class="badge badge-info"><?= e($nombreInst) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="texto-mutado">Su institución</span>
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
