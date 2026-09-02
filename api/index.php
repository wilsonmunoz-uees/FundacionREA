<?php
/**
 * api/index.php
 * -----------------------------------------------------------------------------
 * Punto único de entrada de la API REST del Sistema de Gestión de Protección de
 * Datos - Red Educativa Arquidiocesana (REA).
 *
 * Toda la conectividad con MySQL vive detrás de estos endpoints: las páginas del
 * sitio ya no abren conexiones PDO, sino que consumen esta API por HTTP.
 *
 * Autenticación: token Bearer firmado (ver core/Auth.php).
 *   Authorization: Bearer <token>
 *
 * Formato de respuesta:
 *   { "ok": true,  "datos": ..., "meta": { ... } }
 *   { "ok": false, "error": "mensaje", "errores": [ ... ] }
 * -----------------------------------------------------------------------------
 */

declare(strict_types=1);

date_default_timezone_set('America/Guayaquil');

// La API nunca debe imprimir avisos HTML: rompería el JSON.
ini_set('display_errors', '0');
error_reporting(E_ALL);

/* --------------------------------------------------------------------------
   Carga de clases
   -------------------------------------------------------------------------- */
spl_autoload_register(static function (string $clase): void {
    foreach ([__DIR__ . '/core/', __DIR__ . '/controllers/'] as $carpeta) {
        $ruta = $carpeta . $clase . '.php';
        if (is_file($ruta)) {
            require_once $ruta;
            return;
        }
    }
});

/* --------------------------------------------------------------------------
   Cabeceras y manejo global de errores
   -------------------------------------------------------------------------- */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Token, X-HTTP-Method-Override');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Credentials: true');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(static function (Throwable $e): void {
    error_log('[API] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Response::error('Error interno del servidor: ' . $e->getMessage(), 500);
});

set_error_handler(static function (int $severidad, string $mensaje, string $archivo = '', int $linea = 0): bool {
    if (!(error_reporting() & $severidad)) {
        return false;
    }
    throw new ErrorException($mensaje, 0, $severidad, $archivo, $linea);
});

/* --------------------------------------------------------------------------
   Rutas
   -------------------------------------------------------------------------- */
$peticion = new Request();
$router   = new Router();

// --- Documentación (Swagger / OpenAPI) ---------------------------------------
// /api/openapi.json  -> especificación OpenAPI 3.0
// /api/docs          -> Swagger UI (interfaz para explorar y probar la API)
$router->get('openapi.json', static function (): void {
    $spec = require __DIR__ . '/openapi.php';
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
});

$router->get('docs', static function (): void {
    header_remove('Content-Type');
    header('Content-Type: text/html; charset=utf-8');
    require __DIR__ . '/docs/index.php';
    exit;
});

// --- Estado del servicio -----------------------------------------------------
$router->get('', static function (): void {
    Response::exito([
        'servicio'      => 'API REST - Protección de Datos REA',
        'version'       => '1.0',
        'documentacion' => 'docs',
        'especificacion'=> 'openapi.json',
        'hora'          => date('c'),
    ]);
});
$router->get('estado', static function (): void {
    Response::exito(['estado' => 'operativo', 'hora' => date('c')]);
});

// --- Autenticación -----------------------------------------------------------
$router->post('auth/login',  [AuthController::class, 'login']);
$router->post('auth/logout', [AuthController::class, 'logout']);
// La fija su propio dueño: exige la contraseña vigente, y es el único camino
// para quitarse la clave temporal que envió el sistema.
$router->post('auth/cambiar-clave', [AuthController::class, 'cambiarClave']);
$router->get('auth/me',      [AuthController::class, 'me']);
$router->get('auth/permiso', [AuthController::class, 'permiso']);

// --- Instituciones educativas -------------------------------------------------
$router->get('instituciones/activas',     [InstitucionesController::class, 'activas']);   // pública (combo del login)
$router->get('instituciones',             [InstitucionesController::class, 'index']);
$router->post('instituciones',            [InstitucionesController::class, 'store']);
$router->get('instituciones/{id}',        [InstitucionesController::class, 'show']);
$router->put('instituciones/{id}',        [InstitucionesController::class, 'update']);
$router->patch('instituciones/{id}/estado', [InstitucionesController::class, 'estadoCambiar']);

// --- Reglas del documento de identidad ----------------------------------------
// Qué caracteres admite cada tipo y hasta dónde llega la columna de la base.
// Las pantallas lo consultan para adaptar el campo mientras se escribe.
$router->get('documento/reglas',       [DocumentoController::class, 'reglas']);

// --- Personas -----------------------------------------------------------------
// `persona` es la entidad padre de empleados, estudiantes, representantes y
// proveedores: NO tiene mantenimiento propio. Solo se lee. Sus fichas se crean
// desde esos módulos, desde los enlaces públicos o desde la PreCarga Inicial.
$router->get('personas/opciones',      [PersonasController::class, 'opciones']);
$router->get('personas',               [PersonasController::class, 'index']);
$router->get('personas/{id}/ficha',    [PersonasController::class, 'ficha']);
$router->get('personas/{id}',          [PersonasController::class, 'show']);

// --- Empleados ----------------------------------------------------------------
$router->get('empleados',               [EmpleadosController::class, 'index']);
$router->post('empleados',              [EmpleadosController::class, 'store']);
$router->get('empleados/{id}',          [EmpleadosController::class, 'show']);
$router->put('empleados/{id}',          [EmpleadosController::class, 'update']);
$router->patch('empleados/{id}/estado', [EmpleadosController::class, 'estadoCambiar']);

// --- Estudiantes --------------------------------------------------------------
$router->get('estudiantes',               [EstudiantesController::class, 'index']);
$router->post('estudiantes',              [EstudiantesController::class, 'store']);
$router->get('estudiantes/{id}',          [EstudiantesController::class, 'show']);
$router->put('estudiantes/{id}',          [EstudiantesController::class, 'update']);
$router->patch('estudiantes/{id}/estado', [EstudiantesController::class, 'estadoCambiar']);

// --- Proveedores --------------------------------------------------------------
$router->get('proveedores',               [ProveedoresController::class, 'index']);
$router->post('proveedores',              [ProveedoresController::class, 'store']);
$router->get('proveedores/{id}',          [ProveedoresController::class, 'show']);
$router->put('proveedores/{id}',          [ProveedoresController::class, 'update']);
$router->patch('proveedores/{id}/estado', [ProveedoresController::class, 'estadoCambiar']);

// --- Consentimientos (núcleo) --------------------------------------------------
$router->get('consentimientos/catalogos',      [ConsentimientosController::class, 'catalogos']);
$router->get('consentimientos',                [ConsentimientosController::class, 'index']);
$router->post('consentimientos',               [ConsentimientosController::class, 'store']);
$router->get('consentimientos/{id}',           [ConsentimientosController::class, 'show']);
$router->put('consentimientos/{id}',           [ConsentimientosController::class, 'update']);
$router->post('consentimientos/{id}/revocar',  [ConsentimientosController::class, 'revocar']);
$router->post('consentimientos/{id}/reactivar',[ConsentimientosController::class, 'reactivar']);

// --- Usuarios del sistema -------------------------------------------------------
// Antes de usuarios/{id}: si no, el enrutador tomaría «politica-clave» por un id.
$router->get('usuarios/politica-clave',       [UsuariosController::class, 'politicaClave']);
$router->get('usuarios/personas-disponibles', [UsuariosController::class, 'personasDisponibles']);
$router->get('usuarios/buscar',                [UsuariosController::class, 'buscar']);
$router->get('usuarios',                      [UsuariosController::class, 'index']);
$router->post('usuarios',                     [UsuariosController::class, 'store']);
$router->get('usuarios/{id}',                 [UsuariosController::class, 'show']);
$router->put('usuarios/{id}',                 [UsuariosController::class, 'update']);
$router->patch('usuarios/{id}/estado',        [UsuariosController::class, 'estadoCambiar']);

// --- Roles y permisos ------------------------------------------------------------
$router->get('roles',                     [RolesController::class, 'index']);
$router->post('roles',                    [RolesController::class, 'store']);
$router->get('roles/{id}',                [RolesController::class, 'show']);
$router->put('roles/{id}',                [RolesController::class, 'update']);
$router->patch('roles/{id}/estado',       [RolesController::class, 'estadoCambiar']);

$router->get('permisos',                  [PermisosController::class, 'index']);
$router->post('permisos',                 [PermisosController::class, 'store']);
$router->get('permisos/{id}',             [PermisosController::class, 'show']);
$router->put('permisos/{id}',             [PermisosController::class, 'update']);
$router->patch('permisos/{id}/estado',    [PermisosController::class, 'estadoCambiar']);

// --- Catálogos: finalidades y tipos de dato ---------------------------------------
$router->get('finalidades',               [FinalidadesController::class, 'index']);
$router->post('finalidades',              [FinalidadesController::class, 'store']);
$router->get('finalidades/{id}',          [FinalidadesController::class, 'show']);
$router->put('finalidades/{id}',          [FinalidadesController::class, 'update']);
$router->patch('finalidades/{id}/estado', [FinalidadesController::class, 'estadoCambiar']);

$router->get('tipos-dato',                [TiposDatoController::class, 'index']);
$router->post('tipos-dato',               [TiposDatoController::class, 'store']);
$router->get('tipos-dato/{id}',           [TiposDatoController::class, 'show']);
$router->put('tipos-dato/{id}',           [TiposDatoController::class, 'update']);
$router->delete('tipos-dato/{id}',        [TiposDatoController::class, 'destroy']);

// --- Consultas -------------------------------------------------------------------
$router->get('consultas/buscar-persona',           [ConsultasController::class, 'buscarPersona']);
$router->get('consultas/historial',                [ConsultasController::class, 'historial']);
$router->get('consultas/consentimientos-vigentes', [ConsultasController::class, 'consentimientosVigentes']);

// --- Reportes ---------------------------------------------------------------------
$router->get('reportes/dashboard',       [ReportesController::class, 'dashboard']);
$router->get('reportes/consentimientos', [ReportesController::class, 'consentimientos']);
$router->get('reportes/datos-sensibles', [ReportesController::class, 'datosSensibles']);
$router->get('reportes/titulares',       [ReportesController::class, 'titulares']);
$router->get('reportes/auditoria',       [ReportesController::class, 'auditoria']);
$router->get('reportes/cobertura-correo', [ReportesController::class, 'coberturaCorreo']);
$router->get('reportes/exportar',        [ReportesController::class, 'exportar']);

// --- Configuración del correo saliente ----------------------------------------------
$router->get('correo/configuracion',  [CorreoConfiguracionController::class, 'ver']);
$router->put('correo/configuracion',  [CorreoConfiguracionController::class, 'guardar']);
$router->post('correo/probar',        [CorreoConfiguracionController::class, 'probar']);

// --- Disclaimers de protección de datos ---------------------------------------------
$router->get('disclaimers',            [DisclaimersController::class, 'index']);
$router->post('disclaimers',           [DisclaimersController::class, 'store']);
$router->get('disclaimers/{id}',       [DisclaimersController::class, 'show']);
$router->put('disclaimers/{id}',       [DisclaimersController::class, 'update']);
$router->patch('disclaimers/{id}/activar', [DisclaimersController::class, 'activar']);
$router->delete('disclaimers/{id}',    [DisclaimersController::class, 'destroy']);

// --- Verificación pública por código (SIN token: enlaces con verificación) -----------
$router->post('verificacion-publica/consultar',      [VerificacionPublicaController::class, 'buscarRegistro']);
$router->post('verificacion-publica/enviar-codigo',  [VerificacionPublicaController::class, 'enviarCodigo']);
$router->post('verificacion-publica/validar-codigo', [VerificacionPublicaController::class, 'validarCodigo']);

// --- Envío masivo de invitaciones (rol Registro de Datos) ---------------------------
$router->get('envio-masivo/resumen',       [EnvioMasivoController::class, 'resumen']);
$router->get('envio-masivo/destinatarios', [EnvioMasivoController::class, 'destinatarios']);
$router->post('envio-masivo/enviar',       [EnvioMasivoController::class, 'enviar']);

// --- PreCarga inicial (solo SuperAdmin) ---------------------------------------------
$router->post('precarga/previsualizar', [PreCargaController::class, 'previsualizar']);
$router->post('precarga/procesar',      [PreCargaController::class, 'procesar']);

// --- Consentimiento público (SIN token: son los enlaces abiertos al titular) ---------
$router->get('consentimiento-publico/inicio',      [ConsentimientoPublicoController::class, 'inicio']);
$router->post('consentimiento-publico/identificar', [ConsentimientoPublicoController::class, 'identificar']);
$router->post('consentimiento-publico/registrar',   [ConsentimientoPublicoController::class, 'registrar']);

// --- Instalación inicial ------------------------------------------------------------
$router->post('setup/admin', [SetupController::class, 'crearAdmin']);

/* --------------------------------------------------------------------------
   Despacho
   -------------------------------------------------------------------------- */
if (!$router->despachar($peticion)) {
    Response::error("Ruta no encontrada: /{$peticion->ruta}", 404);
}
