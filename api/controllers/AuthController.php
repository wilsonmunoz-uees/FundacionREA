<?php
// api/controllers/AuthController.php
// Login, cierre de sesión y consulta del contexto autenticado.

final class AuthController extends Controller
{
    /**
     * POST /api/auth/login
     * Cuerpo: { "username": "...", "password": "...", "institucion_id": 1 }
     */
    public function login(array $ruta = []): void
    {
        $username      = $this->peticion->texto('username');
        $password      = (string)$this->peticion->dato('password', '');
        $institucionId = $this->peticion->entero('institucion_id');

        $errores = [];
        if ($username === '')   $errores[] = 'El usuario es obligatorio.';
        if ($password === '')   $errores[] = 'La contraseña es obligatoria.';
        if ($institucionId <= 0) $errores[] = 'Debe indicar la institución educativa.';

        if ($errores) {
            Response::validacion($errores);
        }

        $institucion = $this->consultarUna(
            'SELECT id, nombre, estado FROM institucion_educativa WHERE id = ?',
            [$institucionId]
        );
        if (!$institucion || $institucion['estado'] !== 'ACTIVO') {
            Response::error('La institución educativa seleccionada no está disponible.', 401);
        }

        $usuario = Auth::login($username, $password, $institucionId);
        if ($usuario === null) {
            Response::error('Credenciales incorrectas o cuenta inactiva.', 401);
        }

        $token = Auth::emitirToken($usuario);

        Response::exito([
            'token'    => $token['token'],
            'expira'   => $token['expira'],
            'vigencia' => $token['vigencia'],
            'usuario'  => [
                'usuario_id'         => $usuario['usuario_id'],
                'persona_id'         => $usuario['persona_id'],
                'username'           => $usuario['username'],
                'email'              => $usuario['email'],
                'institucion_id'     => $usuario['institucion_id'],
                'institucion_nombre' => $institucion['nombre'],
                // Institución a la que pertenece la cuenta. Difiere de la activa
                // cuando un SuperAdmin entra en otra institución.
                'institucion_propia' => $usuario['institucion_propia'],
                'visita'             => $usuario['visita'],
                'roles'              => $usuario['roles'],
                'permisos'           => Auth::permisosDe($usuario['usuario_id'], $usuario['institucion_id']),
            ],
        ]);
    }

    /**
     * POST /api/auth/logout
     * Los tokens son autocontenidos y expiran solos; el cliente simplemente
     * descarta el suyo. Se mantiene el endpoint por coherencia REST.
     */
    public function logout(array $ruta = []): void
    {
        Response::exito(['mensaje' => 'Sesión finalizada.']);
    }

    /** GET /api/auth/me — contexto del token vigente. */
    public function me(array $ruta = []): void
    {
        $usuario = $this->requiereAutenticacion();

        // La institución de la cuenta puede diferir de la activa: un SuperAdmin
        // entra en cualquier institución. Por eso se busca solo por UsuarioId
        // (índice único) y el nombre mostrado es el de la institución activa.
        $datos = $this->consultarUna(
            'SELECT u.UsuarioId, u.Username, u.Email, u.UltimoAcceso, u.Estado,
                    u.InstitucionEducativaId AS InstitucionPropiaId,
                    p.PersonaId, p.Nombres, p.Apellidos,
                    (SELECT i.nombre FROM institucion_educativa i WHERE i.id = ?) AS InstitucionNombre
               FROM usuario u
         INNER JOIN persona p ON p.PersonaId = u.PersonaId
              WHERE u.UsuarioId = ?',
            [$usuario['institucion_id'], $usuario['usuario_id']]
        );

        Response::exito([
            'usuario_id'     => $usuario['usuario_id'],
            'username'       => $usuario['username'],
            'institucion_id' => $usuario['institucion_id'],
            'roles'          => $usuario['roles'],
            'permisos'       => Auth::permisosDe($usuario['usuario_id'], $usuario['institucion_id']),
            'expira'         => $usuario['expira'],
            'detalle'        => $datos,
        ]);
    }

    /** GET /api/auth/permiso?codigo=REPORTES_EXPORTACION */
    public function permiso(array $ruta = []): void
    {
        $usuario = $this->requiereAutenticacion();
        $codigo  = $this->peticion->paramTexto('codigo');

        if ($codigo === '') {
            Response::validacion(['Debe indicar el código del permiso.']);
        }

        Response::exito([
            'codigo'  => $codigo,
            'tiene'   => Auth::tienePermiso($usuario, $codigo),
        ]);
    }
}
