<?php
/**
 * cambiar_clave.php
 * -----------------------------------------------------------------------------
 * Cambio de la propia contraseña.
 *
 * Se llega aquí de dos maneras:
 *
 *   · Obligado, la primera vez. Las cuentas nuevas se crean con una contraseña
 *     temporal que el sistema genera y envía por correo a su titular. Mientras
 *     esa clave siga puesta, requireLogin() devuelve aquí desde cualquier
 *     pantalla: la sesión existe, pero no sirve para nada más.
 *   · Voluntariamente, desde el menú, cuando alguien quiere cambiar la suya.
 *
 * La pantalla no sabe ninguna contraseña: manda las tres a la API y es allí
 * donde se comprueba la actual y se aplica la política.
 * -----------------------------------------------------------------------------
 */
define('APP_ROOT', '');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireLogin();

$obligado = !empty($_SESSION['debe_cambiar_clave']);
$errores  = [];
$campos   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValido()) {
        $errores[] = 'La sesión expiró o el formulario no es válido. Vuelva a intentarlo.';
    } else {
        $respuesta = apiPost('auth/cambiar-clave', [
            'password_actual'  => (string)($_POST['password_actual'] ?? ''),
            'password'         => (string)($_POST['password'] ?? ''),
            'password_confirm' => (string)($_POST['password_confirm'] ?? ''),
        ]);

        if ($respuesta['ok']) {
            // Ya no hay clave temporal: la sesión recupera su alcance normal
            $_SESSION['debe_cambiar_clave'] = false;
            flashSet('exito', 'Su contraseña se cambió correctamente.');
            redirigir('dashboard.php');
        }

        $errores = apiErrores($respuesta);
        $campos  = array_flip((array)($respuesta['campos'] ?? []));
    }
}

/* Reglas que debe cumplir la contraseña, tal como las define el servidor. La
   pantalla solo las muestra y las va marcando; quien decide es la API. */
$reglasClave = apiDatos(apiGet('usuarios/politica-clave'), []);

$marcaError = static fn(string $campo): string => isset($campos[$campo]) ? ' campo-error' : '';

csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar contraseña - REA | Protección de Datos</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="login-body">

<div class="login-shell">
    <div class="login-container" style="max-width: 460px;">
        <div class="login-logo-wrap">
            <img src="assets/logo.png" alt="Red Educativa Arquidiocesana (REA)">
        </div>

        <?php if ($obligado): ?>
            <div class="alerta alerta-advertencia" style="text-align:left;">
                <strong>Debe cambiar su contraseña antes de continuar.</strong>
                La que recibió por correo es temporal y de un solo uso. Elija una suya:
                nadie más, tampoco quien administra el sistema, llegará a conocerla.
            </div>
        <?php endif; ?>

        <?php foreach ($errores as $error): ?>
            <div class="error-msg"><?= e($error) ?></div>
        <?php endforeach; ?>

        <form method="POST" action="cambiar_clave.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="form-group">
                <label for="password_actual" class="campo-requerido">
                    <?= $obligado ? 'Contraseña temporal recibida' : 'Contraseña actual' ?>
                </label>
                <input type="password" id="password_actual" name="password_actual" required
                       autocomplete="current-password"
                       class="<?= trim($marcaError('password_actual')) ?>">
            </div>

            <fieldset class="bloque-persona">
                <legend>Contraseña nueva</legend>

                <div class="form-group">
                    <label for="password" class="campo-requerido">Contraseña nueva</label>
                    <input type="password" id="password" name="password" required
                           autocomplete="new-password"
                           data-reglas="<?= e(json_encode($reglasClave, JSON_UNESCAPED_UNICODE)) ?>"
                           class="<?= trim($marcaError('password')) ?>">
                </div>

                <div class="form-group">
                    <label for="password_confirm" class="campo-requerido">Repita la contraseña</label>
                    <input type="password" id="password_confirm" name="password_confirm" required
                           autocomplete="new-password"
                           class="<?= trim($marcaError('password_confirm')) ?>">
                </div>
            </fieldset>

            <button type="submit" class="btn btn-submit">Guardar contraseña</button>
        </form>

        <?php if (!$obligado): ?>
            <p style="text-align:center; margin-top:14px;">
                <a href="dashboard.php">Volver al panel principal</a>
            </p>
        <?php else: ?>
            <p style="text-align:center; margin-top:14px;">
                <a href="logout.php">Salir sin cambiarla</a>
            </p>
        <?php endif; ?>
    </div>

    <div class="login-footer-nota">
        &copy; <?= date('Y') ?> Red Educativa Arquidiocesana &mdash; Uso interno confidencial
    </div>
</div>

<script src="js/password.js" defer></script>
</body>
</html>
