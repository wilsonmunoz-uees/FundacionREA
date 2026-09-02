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
                // Con la clave temporal todavía puesta, lo único que puede hacer
                // es cambiarla: la pantalla lo lleva allí y no lo suelta.
                'debe_cambiar_clave' => !empty($usuario['debe_cambiar_clave']),
            ],
        ]);
    }

    /**
     * POST /api/auth/cambiar-clave
     *
     * La fija el propio dueño de la cuenta, y solo él: se exige la contraseña
     * vigente además de la nueva. Es el único camino para quitarse de encima la
     * clave temporal que envió el sistema.
     */
    public function cambiarClave(array $ruta = []): void
    {
        $usuario = $this->requiereAutenticacion();

        $actual    = (string)$this->peticion->dato('password_actual', '');
        $nueva     = (string)$this->peticion->dato('password', '');
        $confirmar = (string)$this->peticion->dato('password_confirm', '');

        $errores = [];
        $campos  = [];
        $falla   = static function (string $campo, string $mensaje) use (&$errores, &$campos): void {
            $errores[]      = $mensaje;
            $campos[$campo] = true;
        };

        if ($actual === '') {
            $falla('password_actual', 'Escriba su contraseña actual.');
        }
        if ($nueva === '') {
            $falla('password', 'Escriba la contraseña nueva.');
        } else {
            foreach (Password::validar($nueva) as $mensaje) {
                $falla('password', $mensaje);
            }
        }
        if ($nueva !== $confirmar) {
            $falla('password_confirm', 'Las contraseñas no coinciden.');
        }
        if ($actual !== '' && $nueva !== '' && $actual === $nueva) {
            $falla('password', 'La contraseña nueva debe ser distinta de la actual.');
        }

        if ($errores) {
            Response::validacion($errores, $campos);
        }

        $fila = $this->consultarUna(
            'SELECT PasswordHash FROM usuario WHERE UsuarioId = ?',
            [(int)$usuario['usuario_id']]
        );
        if (!$fila) {
            Response::noEncontrado();
        }

        if (!password_verify($actual, (string)$fila['PasswordHash'])) {
            Response::validacion(['La contraseña actual no es correcta.'], ['password_actual' => true]);
        }

        $this->ejecutar(
            'UPDATE usuario SET PasswordHash = ?, DebeCambiarClave = \'NO\' WHERE UsuarioId = ?',
            [password_hash($nueva, PASSWORD_DEFAULT), (int)$usuario['usuario_id']]
        );

        /* La bitácora deja constancia del hecho, nunca del valor: registra que
           la contraseña se cambió, no cuál es. */
        Auditoria::cambioLista(
            $usuario, 'usuario', (int)$usuario['usuario_id'],
            'PasswordHash', 'anterior', 'nueva'
        );

        Response::exito(['mensaje' => 'Contraseña actualizada correctamente.']);
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
