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

        /* Falló: NO se redirige ni se vuelve a leer de la base. El formulario se
           vuelve a pintar con lo que la persona acababa de escribir —salvo las
           contraseñas, que nunca se devuelven al navegador— y se le marca
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

/* Condiciones de la contraseña, tal como las define el servidor. Si la API no
   responde se usan las mismas por escrito: el formulario sigue siendo usable y
   quien valida de verdad es la API al guardar. */
$reglasClave = [];
if (in_array($accion, ['crear', 'editar'], true)) {
    $reglasClave = apiDatos(apiGet('usuarios/politica-clave'), []);
}
if (!$reglasClave) {
    $reglasClave = [
        ['clave' => 'largo',      'texto' => 'Al menos 8 caracteres',                  'patron' => '.{8,}'],
        ['clave' => 'mayuscula',  'texto' => 'Una letra mayúscula',                    'patron' => '[A-Z]'],
        ['clave' => 'minuscula',  'texto' => 'Una letra minúscula',                    'patron' => '[a-z]'],
        ['clave' => 'digito',     'texto' => 'Un número',                              'patron' => '[0-9]'],
        ['clave' => 'permitidos', 'texto' => 'Solo letras, números y *!-_ (opcionales)','patron' => '^[A-Za-z0-9*!\\-_]+$'],
    ];
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
                <?php if (isset($camposMal['password']) || isset($camposMal['password_confirm'])): ?>
                    <div class="form-ayuda" style="margin-top:6px;">
                        Por seguridad, la contraseña no se conserva: vuelva a escribirla.
                        El resto de los datos quedó como los dejó.
                    </div>
                <?php endif; ?>
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
                <div class="form-group">
                    <label for="email">Correo Electrónico</label>
                    <input type="email" name="email" id="email" maxlength="150"
                           class="<?= trim($marcaError('email')) ?>"
                           value="<?= e($valorCampo('email', 'Email')) ?>" autocomplete="off">
                </div>
            </div>

            <?php
            /* Las contraseñas NUNCA se reponen tras un error: no se devuelven al
               navegador ni siquiera lo que se acaba de escribir. Es el único par
               de campos que se vuelve a pedir, y se dice por qué. */
            $estadoActual = $valorCampo('estado', 'Estado') ?: 'ACTIVO';
            ?>
            <fieldset class="bloque-clave">
                <legend>Contraseña<?= $accion === 'editar' ? ' (opcional)' : '' ?></legend>

                <?php if ($accion === 'editar'): ?>
                    <p class="form-ayuda">Deje ambos campos en blanco para conservar la contraseña actual.</p>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password"<?= $accion === 'crear' ? ' class="campo-requerido"' : '' ?>>Contraseña</label>
                        <input type="password" name="password" id="password" autocomplete="new-password"
                               class="<?= trim($marcaError('password')) ?>"
                               data-reglas="<?= e(json_encode($reglasClave, JSON_UNESCAPED_UNICODE)) ?>"
                               data-lista="reglasClave"
                               data-confirmar="password_confirm"
                               data-aviso-confirmar="avisoConfirmar"
                               data-generar="btnGenerarClave"
                               data-generada="claveGenerada"
                               data-ver="btnVerClave">
                        <div class="flex-gap" style="margin-top:8px;">
                            <button type="button" class="btn btn-sm btn-secundario" id="btnGenerarClave">
                                🎲 Generar contraseña
                            </button>
                            <button type="button" class="btn btn-sm btn-secundario" id="btnVerClave">Ver</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Confirmar Contraseña</label>
                        <input type="password" name="password_confirm" id="password_confirm"
                               class="<?= trim($marcaError('password_confirm')) ?>"
                               autocomplete="new-password">
                        <div class="form-ayuda campo-aviso" id="avisoConfirmar" hidden></div>
                    </div>
                    <div class="form-group" style="flex:0 1 180px;">
                        <label for="estado">Estado</label>
                        <select name="estado" id="estado">
                            <option value="ACTIVO"   <?= $estadoActual === 'ACTIVO'   ? 'selected' : '' ?>>ACTIVO</option>
                            <option value="INACTIVO" <?= $estadoActual === 'INACTIVO' ? 'selected' : '' ?>>INACTIVO</option>
                        </select>
                    </div>
                </div>

                <div class="clave-generada" id="claveGenerada" hidden>
                    <span class="clave-generada-etiqueta">Contraseña generada:</span>
                    <code class="clave-generada-valor"></code>
                    <button type="button" class="btn btn-sm btn-secundario btn-copiar-clave">Copiar</button>
                    <div class="form-ayuda">
                        Anótela o cópiela ahora y entréguesela a su titular: una vez guardada, el sistema
                        no vuelve a mostrarla.
                    </div>
                </div>

                <p class="form-ayuda" style="margin-bottom:4px;">La contraseña debe cumplir:</p>
                <ul class="lista-reglas" id="reglasClave">
                    <?php foreach ($reglasClave as $regla): ?>
                        <li class="regla-clave"><span class="regla-marca">○</span> <?= e($regla['texto']) ?></li>
                    <?php endforeach; ?>
                </ul>
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

<?php if (in_array($accion, ['crear', 'editar'], true)): ?>
    <script src="<?= e(APP_ROOT) ?>js/password.js" defer></script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
