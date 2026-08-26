<?php
/**
 * api/docs/index.php
 * -----------------------------------------------------------------------------
 * Swagger UI de la API REST del Sistema de Protección de Datos (REA).
 *
 * Se sirve en /api/docs (o /api/docs/index.php si el hosting no tiene
 * mod_rewrite). Los archivos de Swagger UI están alojados en ./assets, así que
 * la página funciona sin conexión a Internet y sin depender de ningún CDN.
 * -----------------------------------------------------------------------------
 */

/**
 * ¿Quién puede ver esta documentación?
 *   false -> pública (cómodo durante el desarrollo y para integradores externos)
 *   true  -> solo usuarios con sesión iniciada en el sistema
 * La API en sí siempre exige token: esto controla únicamente el acceso a la página.
 */
const DOCS_SOLO_AUTENTICADOS = false;

if (DOCS_SOLO_AUTENTICADOS) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['usuario_id'])) {
        header('Location: ../../login.php');
        exit;
    }
}

/* Ruta web de la carpeta /api, sirva quien sirva esta página. */
$script  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/api/index.php');
$baseApi = rtrim(dirname($script), '/');
if (str_ends_with($baseApi, '/docs')) {
    $baseApi = substr($baseApi, 0, -5);
}
if ($baseApi === '' || $baseApi === '.') {
    $baseApi = '/api';
}

/* Si estamos en modo sin mod_rewrite, la especificación se pide igual. */
$modoRespaldo = !empty($_GET['ruta']);
$urlSpec = $modoRespaldo
    ? $baseApi . '/index.php?ruta=openapi.json'
    : $baseApi . '/openapi.json';

$assets = $baseApi . '/docs/assets';
$h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API REST - Protección de Datos REA | Documentación</title>
    <link rel="icon" type="image/png" href="<?= $h($assets) ?>/favicon-32x32.png" sizes="32x32">
    <link rel="stylesheet" href="<?= $h($assets) ?>/swagger-ui.css">
    <style>
        :root { --rea-rojo: #c8102e; --rea-rojo-osc: #9c0c23; }
        body { margin: 0; background: #fafafa; }

        .rea-cabecera {
            background: var(--rea-rojo);
            color: #fff;
            padding: 18px 26px;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: center;
            justify-content: space-between;
        }
        .rea-cabecera h1 { margin: 0; font-size: 1.15rem; font-weight: 600; letter-spacing: .2px; }
        .rea-cabecera p  { margin: 4px 0 0; font-size: .82rem; opacity: .9; }
        .rea-cabecera a  {
            color: #fff;
            border: 1px solid rgba(255,255,255,.6);
            border-radius: 5px;
            padding: 7px 14px;
            text-decoration: none;
            font-size: .82rem;
        }
        .rea-cabecera a:hover { background: var(--rea-rojo-osc); }

        .rea-aviso {
            margin: 16px 26px 0;
            padding: 12px 16px;
            border-left: 4px solid var(--rea-rojo);
            background: #fff3f4;
            border-radius: 4px;
            font: 14px/1.55 -apple-system, "Segoe UI", Roboto, sans-serif;
            color: #3b3b3b;
        }
        .rea-aviso strong { color: var(--rea-rojo-osc); }
        .rea-aviso code {
            background: #fff;
            border: 1px solid #f0d3d7;
            border-radius: 3px;
            padding: 1px 5px;
            font-size: .9em;
        }

        .swagger-ui .topbar { display: none; }
        .swagger-ui .info { margin: 24px 0; }
        .swagger-ui .scheme-container { background: transparent; box-shadow: none; padding: 12px 0; }
    </style>
</head>
<body>

<header class="rea-cabecera">
    <div>
        <h1>API REST · Sistema de Protección de Datos</h1>
        <p>Red Educativa Arquidiocesana (REA)</p>
    </div>
    <a href="../../login.php">← Volver al sistema</a>
</header>

<div class="rea-aviso">
    <strong>Para probar los endpoints:</strong> ejecute <code>POST /auth/login</code>
    con su usuario, contraseña e institución. El token se guarda solo y las
    demás operaciones quedan autorizadas; también puede pegarlo a mano con el
    botón <strong>Authorize</strong>.
</div>

<div id="swagger-ui"></div>

<script src="<?= $h($assets) ?>/swagger-ui-bundle.js" charset="UTF-8"></script>
<script>
window.onload = function () {
    const ui = SwaggerUIBundle({
        url: <?= json_encode($urlSpec, JSON_UNESCAPED_SLASHES) ?>,
        dom_id: '#swagger-ui',
        deepLinking: true,
        docExpansion: 'none',
        defaultModelsExpandDepth: 0,
        filter: true,
        persistAuthorization: true,
        tryItOutEnabled: true,
        displayRequestDuration: true,
        supportedSubmitMethods: ['get', 'post', 'put', 'patch', 'delete'],
        presets: [SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout',

        // Al iniciar sesión, el token se toma de la respuesta y se aplica
        // automáticamente al botón Authorize.
        responseInterceptor: function (respuesta) {
            try {
                if (respuesta.url && respuesta.url.indexOf('/auth/login') !== -1 && respuesta.ok) {
                    const cuerpo = typeof respuesta.body === 'string'
                        ? JSON.parse(respuesta.body)
                        : respuesta.body;
                    const token = cuerpo && cuerpo.datos && cuerpo.datos.token;
                    if (token) {
                        ui.preauthorizeApiKey('bearerAuth', token);
                        mostrarAviso('Sesión iniciada: el token quedó aplicado en Authorize.');
                    }
                }
            } catch (e) {
                /* Si la respuesta no es la esperada, no se interrumpe la interfaz */
            }
            return respuesta;
        }
    });

    window.ui = ui;

    function mostrarAviso(texto) {
        let caja = document.getElementById('rea-token-aviso');
        if (!caja) {
            caja = document.createElement('div');
            caja.id = 'rea-token-aviso';
            caja.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:9999;background:#1f7a3f;'
                + 'color:#fff;padding:11px 16px;border-radius:6px;font:14px -apple-system,"Segoe UI",Roboto,sans-serif;'
                + 'box-shadow:0 4px 14px rgba(0,0,0,.22);';
            document.body.appendChild(caja);
        }
        caja.textContent = texto;
        clearTimeout(caja.temporizador);
        caja.temporizador = setTimeout(function () { caja.remove(); }, 5000);
    }
};
</script>

</body>
</html>
