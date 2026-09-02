<?php
// login.php
// El formulario ya no consulta MySQL: pide las instituciones a la API y
// delega la validación de credenciales en /api/auth/login.
//
// Recuerda el ÚLTIMO USUARIO y la ÚLTIMA INSTITUCIÓN en una cookie, para no
// volver a teclearlos en cada entrada. La CONTRASEÑA nunca se guarda, ni en la
// cookie ni en el almacén de claves del navegador: los campos van marcados con
// autocomplete="off" y el de contraseña se envía siempre vacío.
define('APP_ROOT', '');
require_once __DIR__ . '/auth.php';

// Si el usuario ya tiene sesión activa, redirigir al dashboard
if (isset($_SESSION['usuario_id']) && !empty($_SESSION['api_token'])) {
    header('Location: dashboard.php');
    exit;
}

// Generar token CSRF si no existe
csrfToken();

/* Cookie con el último ingreso. Solo usuario e institución: dos datos que la
   persona ya conoce de memoria y que no abren nada por sí solos. Dura 90 días,
   viaja únicamente por HTTPS cuando lo hay, y no es accesible desde JavaScript
   para que un script inyectado no pueda leerla. */
const COOKIE_ULTIMO_INGRESO = 'rea_ultimo_ingreso';
const COOKIE_DIAS           = 90;

/** Lo que se recordó del ingreso anterior. */
function ultimoIngreso(): array
{
    $crudo = $_COOKIE[COOKIE_ULTIMO_INGRESO] ?? '';

    if ($crudo === '') {
        return ['usuario' => '', 'institucion' => 0];
    }

    $datos = json_decode((string)$crudo, true);

    if (!is_array($datos)) {
        return ['usuario' => '', 'institucion' => 0];
    }

    return [
        'usuario'     => mb_substr(trim((string)($datos['u'] ?? '')), 0, 50),
        'institucion' => (int)($datos['i'] ?? 0),
    ];
}

/** Deja recordado el ingreso que acaba de funcionar. */
function recordarIngreso(string $usuario, int $institucionId): void
{
    $seguro = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    setcookie(COOKIE_ULTIMO_INGRESO, json_encode([
        'u' => mb_substr($usuario, 0, 50),
        'i' => $institucionId,
    ], JSON_UNESCAPED_UNICODE), [
        'expires'  => time() + COOKIE_DIAS * 86400,
        'path'     => '/',
        'secure'   => $seguro,
        'httponly' => true,      // fuera del alcance de JavaScript
        'samesite' => 'Lax',
    ]);
}

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
                // Solo se recuerda lo que funcionó, nunca un intento fallido
                recordarIngreso($username, (int)$institucion);
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

/* Lo que se muestra por defecto: lo escrito en este intento si lo hubo, y si no
   lo que quedó recordado del ingreso anterior. */
$recordado          = ultimoIngreso();
$usuarioPropuesto   = (string)($_POST['username'] ?? $recordado['usuario']);
$institucionElegida = (int)($_POST['institucion_id'] ?? $recordado['institucion']);

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
            <?php if ($recordado['usuario'] !== '' && ($_POST['username'] ?? '') === ''): ?>
                <p class="form-ayuda" style="text-align:center; margin-bottom:10px;">
                    Se recuerdan su usuario y su institución del ingreso anterior.
                    La contraseña nunca se guarda.
                </p>
            <?php endif; ?>
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="form-group">
                <label for="username" class="campo-requerido">Usuario</label>
                <input type="text" id="username" name="username" required
                       autocomplete="username" placeholder="Ej. jperez"
                       value="<?= e($usuarioPropuesto) ?>">
            </div>

            <div class="form-group">
                <label for="password" class="campo-requerido">Contraseña</label>
                <?php /* autocomplete="off" y sin valor: la contraseña no se recuerda
                         ni se ofrece al navegador para guardarla en su almacén. */ ?>
                <input type="password" id="password" name="password" required
                       autocomplete="off" placeholder="••••••••"
                       autocapitalize="off" autocorrect="off" spellcheck="false">
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
                        <option value="<?= e((string)$institucion['id']) ?>"
                            <?= (int)$institucion['id'] === $institucionElegida ? 'selected' : '' ?>>
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
