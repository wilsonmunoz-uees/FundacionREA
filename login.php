<?php
// login.php
// El formulario ya no consulta MySQL: pide las instituciones a la API y
// delega la validación de credenciales en /api/auth/login.
define('APP_ROOT', '');
require_once __DIR__ . '/auth.php';

// Si el usuario ya tiene sesión activa, redirigir al dashboard
if (isset($_SESSION['usuario_id']) && !empty($_SESSION['api_token'])) {
    header('Location: dashboard.php');
    exit;
}

// Generar token CSRF si no existe
csrfToken();

$error = '';

// Procesamiento del formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar token CSRF
    if (!csrfValido()) {
        $error = 'Error de validación de seguridad. Intente nuevamente.';
    } else {
        $username    = trim($_POST['username'] ?? '');
        $password    = $_POST['password'] ?? '';
        $institucion = $_POST['institucion_id'] ?? '';

        if (empty($username) || empty($password) || empty($institucion)) {
            $error = 'Por favor, ingrese usuario, contraseña e institución.';
        } else {
            // procesarLogin() llama a la API REST y guarda el token en la sesión
            $resultado = procesarLogin($username, $password, $institucion);

            if ($resultado === true) {
                header('Location: dashboard.php');
                exit;
            }
            $error = is_string($resultado) && $resultado !== ''
                ? $resultado
                : 'Credenciales incorrectas o cuenta inactiva.';
        }
    }
}

// Mensajes provenientes de otras páginas (por ejemplo, sesión expirada)
if ($error === '') {
    $flash = flashGet();
    if ($flash && ($flash['tipo'] ?? '') === 'error') {
        $error = $flash['mensaje'];
    }
}

// Instituciones activas para el combo (endpoint público de la API)
$respuestaInstituciones = apiGet('instituciones/activas');
$instituciones = apiDatos($respuestaInstituciones, []);

if (!$respuestaInstituciones['ok'] && $error === '') {
    $error = 'No se pudo contactar con el servicio de datos. ' . apiError($respuestaInstituciones);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - REA | Protección de Datos</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="login-body">

<div class="login-shell">

    <div class="login-container">
        <!-- El logotipo va dentro de la tarjeta clara: así se apoya sobre una
             superficie del mismo tono y no se recorta contra el fondo oscuro. -->
        <div class="login-logo-wrap">
            <img src="assets/logo.png" alt="Red Educativa Arquidiocesana (REA)">
        </div>

        <?php if ($error): ?>
            <div class="error-msg"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="form-group">
                <label for="username" class="campo-requerido">Usuario</label>
                <input type="text" id="username" name="username" required autocomplete="username" placeholder="Ej. jperez">
            </div>

            <div class="form-group">
                <label for="password" class="campo-requerido">Contraseña</label>
                <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="••••••••">
            </div>

            <!-- El buscador viene oculto y lo muestra js/buscador_institucion.js:
                 sin JavaScript el desplegable sigue funcionando por sí solo. -->
            <div class="form-group" hidden>
                <label for="buscar_institucion">Buscar institución</label>
                <input type="text" id="buscar_institucion" hidden autocomplete="off"
                       placeholder="Escriba parte del nombre…">
                <div class="form-ayuda" id="institucion_conteo"></div>
            </div>

            <div class="form-group">
                <label for="institucion_id" class="campo-requerido">Institución Educativa</label>
                <select name="institucion_id" id="institucion_id" required>
                    <option value="">-- Seleccione una institución --</option>
                    <?php foreach ($instituciones as $institucion): ?>
                        <option value="<?= e((string)$institucion['id']) ?>">
                            <?= e($institucion['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-submit">Ingresar</button>
        </form>

        <script src="js/buscador_institucion.js" defer></script>
    </div>

    <div class="login-footer-nota">
        &copy; <?= date('Y') ?> Red Educativa Arquidiocesana &mdash; Uso interno confidencial
    </div>
</div>

</body>
</html>
