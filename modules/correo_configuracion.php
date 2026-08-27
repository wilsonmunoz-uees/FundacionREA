<?php
/**
 * modules/correo_configuracion.php
 * -----------------------------------------------------------------------------
 * Servidor de correo saliente de la institución.
 *
 * Si no se configura nada, el sistema intenta enviar con la función mail() de
 * PHP. En hospedajes compartidos eso suele fallar o terminar en la carpeta de
 * correo no deseado, por lo que se recomienda configurar SMTP.
 * -----------------------------------------------------------------------------
 */
define('APP_ROOT', '../');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireAcceso('correo_configuracion');

$mensajePrueba = '';
$errores       = [];

/* --- Guardar --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    if (!csrfValido()) {
        $errores[] = 'La sesión expiró o el formulario no es válido. Vuelva a intentarlo.';
    } else {
        $datos = [
            'activo'           => !empty($_POST['activo']),
            'servidor'         => trim($_POST['servidor'] ?? ''),
            'puerto'           => (int)($_POST['puerto'] ?? 587),
            'seguridad'        => $_POST['seguridad'] ?? 'TLS',
            'usuario'          => trim($_POST['usuario'] ?? ''),
            'remitente_correo' => trim($_POST['remitente_correo'] ?? ''),
            'remitente_nombre' => trim($_POST['remitente_nombre'] ?? ''),
        ];
        // La clave solo viaja si se escribió una nueva
        if (trim($_POST['clave'] ?? '') !== '') {
            $datos['clave'] = $_POST['clave'];
        }

        $respuesta = apiPut('correo/configuracion', $datos);

        if ($respuesta['ok']) {
            flashSet('exito', 'Configuración de correo guardada correctamente.');
            redirigir('correo_configuracion.php');
        }
        $errores = apiErrores($respuesta) ?: [apiError($respuesta)];
    }
}

/* --- Prueba de envío --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'probar') {
    if (!csrfValido()) {
        $errores[] = 'La sesión expiró o el formulario no es válido. Vuelva a intentarlo.';
    } else {
        $respuesta = apiPost('correo/probar', ['correo' => trim($_POST['correo_prueba'] ?? '')]);

        if ($respuesta['ok']) {
            $datos = apiDatos($respuesta, []);
            $mensajePrueba = ($datos['mensaje'] ?? 'Mensaje enviado.')
                           . ' (vía ' . ($datos['via'] ?? '—') . ')';
        } else {
            $errores = apiErrores($respuesta) ?: [apiError($respuesta)];
        }
    }
}

$config = apiDatos(apiGet('correo/configuracion'), []);

$pageTitle  = 'Configuración de Correo';
$breadcrumb = [
    ['label' => 'Administración', 'url' => null],
    ['label' => 'Configuración de Correo', 'url' => null],
];
include __DIR__ . '/../includes/layout_top.php';
?>

<div class="page-header">
    <div>
        <h1>⚙️ Configuración de Correo</h1>
        <p>Servidor de correo saliente que usa el sistema para confirmar cada consentimiento registrado.</p>
    </div>
    <div class="flex-gap">
        <?php if (puedeAcceder('enlaces_verificados')): ?>
        <a href="enlaces_verificados.php" class="btn btn-secundario">🔗 Enlaces de consentimiento</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($errores): ?>
    <div class="alerta alerta-error">
        <?php foreach ($errores as $error): ?>
            <div><?= e($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($mensajePrueba !== ''): ?>
    <div class="alerta alerta-exito"><?= e($mensajePrueba) ?></div>
<?php endif; ?>

<div class="form-row" style="align-items:stretch;">

    <div class="card" style="flex:2 1 460px;">
        <h3>Servidor SMTP</h3>

        <form method="POST" action="correo_configuracion.php">
            <?= csrfCampo() ?>
            <input type="hidden" name="accion" value="guardar">

            <div class="form-group form-check">
                <label>
                    <input type="checkbox" name="activo" value="1" <?= !empty($config['activo']) ? 'checked' : '' ?>>
                    Enviar mediante SMTP (recomendado)
                </label>
                <div class="form-ayuda">
                    Sin marcar, el sistema usa la función <code>mail()</code> de PHP.
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="flex:2 1 240px;">
                    <label for="servidor">Servidor</label>
                    <input type="text" name="servidor" id="servidor" placeholder="smtp.gmail.com"
                           value="<?= e($config['servidor'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex:0 1 120px;">
                    <label for="puerto">Puerto</label>
                    <input type="number" name="puerto" id="puerto" min="1" max="65535"
                           value="<?= e($config['puerto'] ?? 587) ?>">
                </div>
                <div class="form-group" style="flex:0 1 150px;">
                    <label for="seguridad">Seguridad</label>
                    <select name="seguridad" id="seguridad">
                        <?php foreach (['TLS' => 'TLS (587)', 'SSL' => 'SSL (465)', 'NINGUNA' => 'Ninguna'] as $v => $t): ?>
                            <option value="<?= e($v) ?>" <?= ($config['seguridad'] ?? 'TLS') === $v ? 'selected' : '' ?>>
                                <?= e($t) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="usuario">Usuario</label>
                    <input type="text" name="usuario" id="usuario" autocomplete="off"
                           value="<?= e($config['usuario'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="clave">Contraseña</label>
                    <input type="password" name="clave" id="clave" autocomplete="new-password"
                           placeholder="<?= !empty($config['clave_definida']) ? '•••••••• (sin cambios)' : '' ?>">
                    <div class="form-ayuda">
                        <?= !empty($config['clave_definida'])
                            ? 'Ya hay una contraseña guardada. Déjelo vacío para conservarla.'
                            : 'En Gmail y Outlook use una «contraseña de aplicación», no la del correo.' ?>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="remitente_correo">Correo del remitente</label>
                    <input type="email" name="remitente_correo" id="remitente_correo"
                           placeholder="protecciondatos@institucion.edu.ec"
                           value="<?= e($config['remitente_correo'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="remitente_nombre">Nombre visible</label>
                    <input type="text" name="remitente_nombre" id="remitente_nombre"
                           placeholder="Red Educativa Arquidiocesana"
                           value="<?= e($config['remitente_nombre'] ?? '') ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primario">💾 Guardar configuración</button>
            <?php if (!empty($config['actualizado'])): ?>
                <span class="texto-mutado" style="margin-left:10px;">
                    Última actualización: <?= f_fecha($config['actualizado']) ?>
                </span>
            <?php endif; ?>
        </form>
    </div>

    <div class="card" style="flex:1 1 300px;">
        <h3>Probar el envío</h3>
        <p class="texto-mutado">
            Envía un mensaje de prueba con la configuración guardada. Guarde primero los cambios.
        </p>

        <form method="POST" action="correo_configuracion.php">
            <?= csrfCampo() ?>
            <input type="hidden" name="accion" value="probar">

            <div class="form-group">
                <label for="correo_prueba" class="campo-requerido">Enviar a</label>
                <input type="email" name="correo_prueba" id="correo_prueba" required
                       placeholder="su.correo@ejemplo.com">
            </div>

            <button type="submit" class="btn btn-secundario">📨 Enviar prueba</button>
        </form>

        <hr>

        <h3>Valores habituales</h3>
        <ul class="lista-simple">
            <li><strong>Gmail:</strong> smtp.gmail.com · 587 · TLS</li>
            <li><strong>Outlook / Microsoft 365:</strong> smtp.office365.com · 587 · TLS</li>
            <li><strong>Hospedaje propio:</strong> mail.sudominio.com · 465 · SSL</li>
        </ul>
        <p class="texto-mutado">
            Si el hospedaje bloquea las conexiones salientes a esos puertos, el envío fallará
            aunque los datos sean correctos; consúltelo con su proveedor.
        </p>
    </div>
</div>

<?php include __DIR__ . '/../includes/layout_bottom.php'; ?>
