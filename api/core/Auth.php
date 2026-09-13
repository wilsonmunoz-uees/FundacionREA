<?php
// api/core/Auth.php
// Autenticación de la API mediante token Bearer firmado (HMAC-SHA256).
//
// Formato del token:  base64url(cargaJSON) . "." . firmaHMAC
// La carga lleva usuario, institución, roles y expiración, de modo que la API
// no depende de $_SESSION y puede consumirse desde cualquier cliente.

final class Auth
{
    /** Vigencia del token en segundos (8 horas). */
    public const VIGENCIA = 28800;

    /** Rol con acceso total: entra en cualquier institución y abre todas las opciones. */
    public const ROL_SUPERADMIN = 'SuperAdmin';

    /**
     * Verificación de contraseña con password_verify().
     *
     * Está activa, que es como debe quedar. Nació en false porque los hashes
     * cargados en la base de pruebas no correspondían a las contraseñas, y con
     * ella apagada cualquier clave abría cualquier cuenta.
     *
     * No la vuelva a poner en false: es el riesgo RT-01 del registro, el de
     * mayor exposición de todo el análisis.
     */
    public const VALIDAR_PASSWORD = true;

    private static ?array $usuario = null;

    /* ------------------------------------------------------------------ */
    /* Emisión y verificación de tokens                                    */
    /* ------------------------------------------------------------------ */

    public static function emitirToken(array $usuario): array
    {
        $ahora = time();
        $carga = [
            'uid'    => (int)$usuario['usuario_id'],
            'usr'    => (string)$usuario['username'],
            'inst'   => (int)$usuario['institucion_id'],
            'roles'  => array_values($usuario['roles'] ?? []),
            'iat'    => $ahora,
            'exp'    => $ahora + self::VIGENCIA,
        ];

        $cargaCodificada = self::base64UrlCodificar(json_encode($carga, JSON_UNESCAPED_UNICODE));
        $firma           = self::firmar($cargaCodificada);

        return [
            'token'   => $cargaCodificada . '.' . $firma,
            'expira'  => $carga['exp'],
            'vigencia'=> self::VIGENCIA,
        ];
    }

    /** Valida firma y expiración. Devuelve la carga o null. */
    public static function verificarToken(string $token): ?array
    {
        $partes = explode('.', $token);
        if (count($partes) !== 2) {
            return null;
        }
        [$cargaCodificada, $firma] = $partes;

        if (!hash_equals(self::firmar($cargaCodificada), $firma)) {
            return null;
        }

        $carga = json_decode(self::base64UrlDecodificar($cargaCodificada) ?: '', true);
        if (!is_array($carga) || empty($carga['uid']) || empty($carga['exp'])) {
            return null;
        }
        if ((int)$carga['exp'] < time()) {
            return null;
        }

        return $carga;
    }

    private static function firmar(string $texto): string
    {
        return self::base64UrlCodificar(hash_hmac('sha256', $texto, Database::secreto(), true));
    }

    private static function base64UrlCodificar(string $datos): string
    {
        return rtrim(strtr(base64_encode($datos), '+/', '-_'), '=');
    }

    private static function base64UrlDecodificar(string $datos): string
    {
        return base64_decode(strtr($datos, '-_', '+/')) ?: '';
    }

    /* ------------------------------------------------------------------ */
    /* Contexto del usuario autenticado                                    */
    /* ------------------------------------------------------------------ */

    /** Extrae y valida el token de la petición. Devuelve null si no hay sesión válida. */
    public static function usuarioDe(Request $peticion): ?array
    {
        if (self::$usuario !== null) {
            return self::$usuario;
        }

        $cabecera = $peticion->cabecera('Authorization') ?? '';
        $token    = '';

        if (preg_match('/Bearer\s+(.+)$/i', $cabecera, $m)) {
            $token = trim($m[1]);
        } elseif ($peticion->cabecera('X-Api-Token')) {
            $token = trim((string)$peticion->cabecera('X-Api-Token'));
        } elseif (!empty($peticion->query['token'])) {
            $token = trim((string)$peticion->query['token']);
        }

        if ($token === '') {
            return null;
        }

        $carga = self::verificarToken($token);
        if ($carga === null) {
            return null;
        }

        self::$usuario = [
            'usuario_id'     => (int)$carga['uid'],
            'username'       => (string)($carga['usr'] ?? ''),
            'institucion_id' => (int)$carga['inst'],
            'roles'          => (array)($carga['roles'] ?? []),
            'expira'         => (int)$carga['exp'],
        ];

        return self::$usuario;
    }

    /* ------------------------------------------------------------------ */
    /* Login: valida credenciales y arma el contexto                       */
    /* ------------------------------------------------------------------ */

    /**
     * Valida las credenciales y arma el contexto de la sesión.
     *
     * Regla de institución:
     *   - Una cuenta con el rol SuperAdmin entra en CUALQUIER institución activa
     *     y lo hace con todos los permisos, porque el rol se propaga a la
     *     institución elegida. Los datos que verá son los de esa institución.
     *   - Las demás entran en la institución a la que pertenecen y en aquellas
     *     que se les hayan asignado (`usuario_institucion`). La coordinadora que
     *     atiende dos escuelas usa una sola cuenta y en cada una ve únicamente
     *     los datos de esa institución.
     *
     * Los roles NO viajan con la persona: se leen de la institución elegida, de
     * modo que se puede consultar en una y registrar en otra.
     *
     * `usuario.Username` tiene índice único global, así que la cuenta se
     * localiza con una sola consulta, sin ambigüedad entre instituciones.
     *
     * @param string|null $motivo Se rellena con 'credenciales' o 'institucion'
     *                            cuando el ingreso no procede, para que la
     *                            pantalla pueda decir qué pasó. Ojo: el motivo
     *                            'institucion' solo se devuelve DESPUÉS de dar
     *                            por buena la contraseña, de modo que no sirve
     *                            para averiguar qué cuentas existen.
     */
    public static function login(
        string $username,
        string $password,
        int $institucionId,
        ?string &$motivo = null
    ): ?array {
        $pdo    = Database::conexion();
        $motivo = 'credenciales';

        $stmt = $pdo->prepare(
            'SELECT UsuarioId, PersonaId, Username, PasswordHash, Email, Estado,
                    InstitucionEducativaId, DebeCambiarClave
               FROM usuario
              WHERE Username = ?'
        );
        $stmt->execute([$username]);
        $usuario = $stmt->fetch();

        if (!$usuario || $usuario['Estado'] !== 'ACTIVO') {
            return null;
        }

        $usuarioId         = (int)$usuario['UsuarioId'];
        $institucionPropia = (int)$usuario['InstitucionEducativaId'];
        $esSuperAdmin      = self::esSuperAdminGlobal($usuarioId);

        /* La contraseña se comprueba ANTES que la institución. Así, a quien no
           acierta la clave se le responde siempre lo mismo, y el aviso de «no
           tiene acceso a esa institución» solo lo ve quien ya demostró ser el
           dueño de la cuenta. */
        if (self::VALIDAR_PASSWORD && !self::passwordCorrecta($password, (string)$usuario['PasswordHash'])) {
            return null;
        }

        if (!self::puedeEntrarEn($usuarioId, $institucionPropia, $institucionId, $esSuperAdmin)) {
            $motivo = 'institucion';
            return null;
        }
        $motivo = null;

        // Roles asignados en la institución elegida (vacíos si es una visita)
        $roles = self::rolesDe($usuarioId, $institucionId);

        // El SuperAdmin conserva su rol en cualquier institución
        if ($esSuperAdmin && !in_array(self::ROL_SUPERADMIN, $roles, true)) {
            $roles[] = self::ROL_SUPERADMIN;
        }

        // Sella el último acceso (UsuarioId es único en todo el sistema)
        $stmt = $pdo->prepare('UPDATE usuario SET UltimoAcceso = NOW() WHERE UsuarioId = ?');
        $stmt->execute([$usuarioId]);

        return [
            'usuario_id'         => $usuarioId,
            'persona_id'         => (int)$usuario['PersonaId'],
            'username'           => (string)$usuario['Username'],
            'email'              => $usuario['Email'],
            /* Todavía tiene la clave temporal que le envió el sistema: hasta que
               fije la suya, la sesión no le sirve para nada más. */
            'debe_cambiar_clave' => ($usuario['DebeCambiarClave'] ?? 'NO') === 'SI',
            'institucion_id'     => $institucionId,
            'institucion_propia' => $institucionPropia,
            'visita'             => $institucionPropia !== $institucionId,
            'roles'              => array_values($roles),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* En qué instituciones puede entrar una cuenta                        */
    /* ------------------------------------------------------------------ */

    /**
     * ¿Puede esta cuenta iniciar sesión en esta institución?
     *
     * Tres caminos, en orden:
     *   1. el rol SuperAdmin, que abre toda la red;
     *   2. su propia institución, que nunca hay que conceder aparte;
     *   3. una asignación expresa en `usuario_institucion`.
     */
    public static function puedeEntrarEn(
        int $usuarioId,
        int $institucionPropia,
        int $institucionId,
        ?bool $esSuperAdmin = null
    ): bool {
        if ($esSuperAdmin ?? self::esSuperAdminGlobal($usuarioId)) {
            return true;
        }
        if ($institucionPropia === $institucionId) {
            return true;
        }

        return in_array($institucionId, self::institucionesAsignadas($usuarioId), true);
    }

    /**
     * Instituciones asignadas expresamente a una cuenta.
     *
     * Se consulta con tolerancia: una base a la que todavía no se le ha
     * ejecutado 12_ALTER_usuario_instituciones.sql no tiene la tabla, y en ese
     * caso lo correcto es comportarse como antes —cada cuenta en su propia
     * institución— y no dejar a nadie fuera por una actualización pendiente.
     *
     * @return int[]
     */
    public static function institucionesAsignadas(int $usuarioId): array
    {
        try {
            $stmt = Database::conexion()->prepare(
                'SELECT ui.InstitucionEducativaId
                   FROM usuario_institucion ui
             INNER JOIN institucion_educativa i ON i.id = ui.InstitucionEducativaId
                  WHERE ui.UsuarioId = ? AND i.estado = ?'
            );
            $stmt->execute([$usuarioId, 'ACTIVO']);

            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            error_log('[API] No se pudieron leer las instituciones asignadas: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Todas las instituciones en las que la cuenta puede entrar, la propia
     * incluida. Es lo que necesita la pantalla para pintar el formulario.
     *
     * @return int[]
     */
    public static function institucionesDe(int $usuarioId, int $institucionPropia): array
    {
        $todas = self::institucionesAsignadas($usuarioId);

        if (!in_array($institucionPropia, $todas, true)) {
            $todas[] = $institucionPropia;
        }
        sort($todas);

        return $todas;
    }

    /**
     * Roles asignados a un usuario dentro de una institución concreta.
     *
     * Solo cuentan los roles ACTIVOS, igual que ya ocurría con los permisos
     * (ver permisosDe y tienePermiso): desactivar un rol deja de otorgar sus
     * accesos, sin necesidad de quitar la asignación a cada usuario.
     */
    public static function rolesDe(int $usuarioId, int $institucionId): array
    {
        $stmt = Database::conexion()->prepare(
            "SELECT r.Nombre
               FROM rol r
         INNER JOIN usuariorol ur ON ur.RolId = r.RolId
                                 AND ur.UsuarioId = ?
                                 AND ur.InstitucionEducativaId = r.InstitucionEducativaId
              WHERE r.InstitucionEducativaId = ? AND r.Estado = 'ACTIVO'"
        );
        $stmt->execute([$usuarioId, $institucionId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * ¿La cuenta tiene el rol SuperAdmin en alguna institución?
     *
     * Se consulta sin filtrar por institución a propósito: `rol.Nombre` es
     * único en toda la base, de modo que el rol SuperAdmin existe una sola vez
     * y queda registrado bajo la institución donde se creó. Un SuperAdmin de
     * la institución 1 debe poder entrar igualmente en la 2.
     */
    public static function esSuperAdminGlobal(int $usuarioId): bool
    {
        $stmt = Database::conexion()->prepare(
            "SELECT COUNT(*) AS total
               FROM usuariorol ur
         INNER JOIN rol r ON r.RolId = ur.RolId
                         AND r.InstitucionEducativaId = ur.InstitucionEducativaId
              WHERE ur.UsuarioId = ? AND r.Nombre = ? AND r.Estado = 'ACTIVO'"
        );
        $stmt->execute([$usuarioId, self::ROL_SUPERADMIN]);

        return (int)($stmt->fetch()['total'] ?? 0) > 0;
    }

    private static function passwordCorrecta(string $password, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }
        if (password_verify($password, $hash)) {
            return true;
        }
        // Compatibilidad con registros antiguos guardados en texto plano
        return hash_equals($hash, $password);
    }

    /* ------------------------------------------------------------------ */
    /* Permisos                                                            */
    /* ------------------------------------------------------------------ */

    public static function esSuperAdmin(array $usuario): bool
    {
        return in_array(self::ROL_SUPERADMIN, $usuario['roles'] ?? [], true);
    }

    public static function tieneRol(array $usuario, array $rolesPermitidos): bool
    {
        return count(array_intersect($usuario['roles'] ?? [], $rolesPermitidos)) > 0;
    }

    /** Permiso concreto por Código: usuariorol -> rolpermiso -> permiso. */
    public static function tienePermiso(array $usuario, string $codigoPermiso): bool
    {
        if (self::esSuperAdmin($usuario)) {
            return true;
        }

        static $cache = [];
        $clave = $usuario['usuario_id'] . '|' . $usuario['institucion_id'] . '|' . $codigoPermiso;
        if (isset($cache[$clave])) {
            return $cache[$clave];
        }

        $stmt = Database::conexion()->prepare(
            "SELECT COUNT(*) AS total
               FROM usuariorol ur
         INNER JOIN rolpermiso rp ON rp.RolId = ur.RolId AND rp.InstitucionEducativaId = ur.InstitucionEducativaId
         INNER JOIN permiso p     ON p.PermisoId = rp.PermisoId AND p.InstitucionEducativaId = rp.InstitucionEducativaId
              WHERE ur.UsuarioId = ?
                AND ur.InstitucionEducativaId = ?
                AND p.Codigo = ?
                AND p.Estado = 'ACTIVO'"
        );
        $stmt->execute([$usuario['usuario_id'], $usuario['institucion_id'], $codigoPermiso]);

        return $cache[$clave] = ((int)($stmt->fetch()['total'] ?? 0) > 0);
    }

    /** ¿Tiene alguno de los permisos indicados? */
    public static function tieneAlgunPermiso(array $usuario, array $codigos): bool
    {
        foreach ($codigos as $codigo) {
            if (self::tienePermiso($usuario, $codigo)) {
                return true;
            }
        }
        return false;
    }

    /**
     * ¿Puede el usuario usar esta opción del sistema?
     * Usa el mismo mapa que las páginas: includes/accesos.php.
     */
    public static function puede(array $usuario, string $clave): bool
    {
        if (self::esSuperAdmin($usuario)) {
            return true;
        }

        require_once dirname(__DIR__, 2) . '/includes/accesos.php';
        $acceso = accesoDe($clave);

        if (!empty($acceso['autenticado'])) {
            return true;
        }
        if (!empty($acceso['roles']) && self::tieneRol($usuario, $acceso['roles'])) {
            return true;
        }
        return !empty($acceso['permisos']) && self::tieneAlgunPermiso($usuario, $acceso['permisos']);
    }

    /** Lista de códigos de permiso del usuario (se envía al cliente tras el login). */
    public static function permisosDe(int $usuarioId, int $institucionId): array
    {
        $stmt = Database::conexion()->prepare(
            "SELECT DISTINCT p.Codigo
               FROM usuariorol ur
         INNER JOIN rolpermiso rp ON rp.RolId = ur.RolId AND rp.InstitucionEducativaId = ur.InstitucionEducativaId
         INNER JOIN permiso p     ON p.PermisoId = rp.PermisoId AND p.InstitucionEducativaId = rp.InstitucionEducativaId
              WHERE ur.UsuarioId = ? AND ur.InstitucionEducativaId = ? AND p.Estado = 'ACTIVO'"
        );
        $stmt->execute([$usuarioId, $institucionId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
