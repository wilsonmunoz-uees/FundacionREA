<?php
// api/controllers/UsuariosController.php
// CRUD de usuarios del sistema y asignación de roles (usuariorol).
// Las contraseñas se cifran aquí con password_hash(): nunca viajan hacia el cliente.

final class UsuariosController extends Controller
{
    private const ROLES = ['SuperAdmin'];
    /** Clave en includes/accesos.php: define qué permisos abren este recurso. */
    private const MODULO = 'usuarios';

    /** GET /api/usuarios?q=&pagina= */
    public function index(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'WHERE u.InstitucionEducativaId = ? AND (u.Username LIKE ? OR p.Nombres LIKE ? OR p.Apellidos LIKE ?)';
        $params = [$institucionId, $buscar, $buscar, $buscar];

        $total = $this->contar(
            "SELECT COUNT(*) total FROM usuario u INNER JOIN persona p ON p.PersonaId = u.PersonaId $where",
            $params
        );
        [$pagina, $porPagina, $offset] = $this->paginacion(12);

        $datos = $this->consultar(
            "SELECT u.InstitucionEducativaId, u.PersonaId, u.UsuarioId, u.Username, u.Email,
                    u.UltimoAcceso, u.Estado, p.Nombres, p.Apellidos
               FROM usuario u
         INNER JOIN persona p ON p.PersonaId = u.PersonaId
             $where
           ORDER BY u.Username
              LIMIT $offset, $porPagina",
            $params
        );

        // Roles de cada usuario listado (evita N+1 consultas desde la vista)
        $rolesPorUsuario = [];
        if ($datos) {
            $ids  = array_column($datos, 'UsuarioId');
            $in   = implode(',', array_fill(0, count($ids), '?'));
            $filas = $this->consultar(
                "SELECT ur.UsuarioId, r.Nombre
                   FROM usuariorol ur
             INNER JOIN rol r ON r.RolId = ur.RolId AND r.InstitucionEducativaId = ur.InstitucionEducativaId
                  WHERE ur.InstitucionEducativaId = ? AND ur.UsuarioId IN ($in)",
                array_merge([$institucionId], $ids)
            );
            foreach ($filas as $fila) {
                $rolesPorUsuario[(int)$fila['UsuarioId']][] = $fila['Nombre'];
            }
        }

        foreach ($datos as &$fila) {
            $fila['Roles'] = $rolesPorUsuario[(int)$fila['UsuarioId']] ?? [];
        }
        unset($fila);

        Response::lista($datos, $total, $pagina, $porPagina, [
            'roles_disponibles' => $this->rolesActivos($institucionId),
        ]);
    }

    /**
     * GET /api/usuarios/buscar?q=&pagina=&por_pagina=
     * Listado reducido para la subpantalla de búsqueda de usuarios
     * (bitácora de auditoría). No expone contraseñas ni datos de contacto.
     */
    public function buscar(array $ruta = []): void
    {
        $this->requiereAcceso('usuarios_lectura');
        $institucionId = $this->institucion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'WHERE u.InstitucionEducativaId = ?
                     AND (u.Username LIKE ? OR p.Nombres LIKE ? OR p.Apellidos LIKE ? OR p.Identificacion LIKE ?)';
        $params = [$institucionId, $buscar, $buscar, $buscar, $buscar];

        $total = $this->contar(
            "SELECT COUNT(*) total FROM usuario u
        LEFT JOIN persona p ON p.PersonaId = u.PersonaId $where",
            $params
        );
        [$pagina, $porPagina, $offset] = $this->paginacion(10);

        $datos = $this->consultar(
            "SELECT u.UsuarioId, u.Username, u.Estado, u.UltimoAcceso,
                    p.Nombres, p.Apellidos, p.Identificacion
               FROM usuario u
          LEFT JOIN persona p ON p.PersonaId = u.PersonaId
             $where
           ORDER BY u.Username
              LIMIT $offset, $porPagina",
            $params
        );

        Response::lista($datos, $total, $pagina, $porPagina);
    }

    /** GET /api/usuarios/{id} — incluye los RolId asignados. */
    public function show(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna(
            'SELECT u.InstitucionEducativaId, u.PersonaId, u.UsuarioId, u.Username, u.Email,
                    u.UltimoAcceso, u.Estado, p.Nombres, p.Apellidos
               FROM usuario u
         INNER JOIN persona p ON p.PersonaId = u.PersonaId
              WHERE u.UsuarioId = ? AND u.InstitucionEducativaId = ?',
            [$id, $institucionId]
        );
        if (!$registro) {
            Response::noEncontrado();
        }

        $registro['roles_asignados'] = array_map('intval', $this->columna(
            'SELECT RolId FROM usuariorol WHERE UsuarioId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        ));
        $registro['roles_disponibles'] = $this->rolesActivos($institucionId);

        Response::exito($registro);
    }

    /**
     * GET /api/usuarios/politica-clave
     *
     * Las condiciones que debe cumplir una contraseña, para que la pantalla las
     * muestre y las vaya marcando mientras se escribe. Quien decide sigue siendo
     * el servidor, en `api/core/Password.php`, al guardar.
     */
    public function politicaClave(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);

        Response::exito(Password::reglas(), [
            'largo_minimo' => Password::LARGO_MINIMO,
            'especiales'   => Password::ESPECIALES,
        ]);
    }

    /**
     * GET /api/usuarios/personas-disponibles
     * Personas activas que todavía no tienen cuenta en esta institución.
     */
    public function personasDisponibles(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $datos = $this->consultar(
            "SELECT p.PersonaId,
                    CONCAT(p.Apellidos, ' ', p.Nombres, ' - ', COALESCE(p.Identificacion,'S/I')) AS etiqueta
               FROM persona p
              WHERE p.InstitucionEducativaId = ?
                AND p.Estado = 'ACTIVO'
                AND p.PersonaId NOT IN (SELECT PersonaId FROM usuario WHERE InstitucionEducativaId = ?)
           ORDER BY p.Apellidos, p.Nombres",
            [$institucionId, $institucionId]
        );

        Response::exito($datos, ['roles_disponibles' => $this->rolesActivos($institucionId)]);
    }

    /** POST /api/usuarios */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $datos = $this->validar(true);

        try {
            $this->db->beginTransaction();

            $this->ejecutar(
                'INSERT INTO usuario (InstitucionEducativaId, PersonaId, Username, PasswordHash, Email, Estado)
                 VALUES (?,?,?,?,?,?)',
                [
                    $institucionId, $datos['persona_id'], $datos['username'],
                    password_hash($datos['password'], PASSWORD_DEFAULT), $datos['email'], $datos['estado'],
                ]
            );
            $usuarioId = (int)$this->db->lastInsertId();

            $this->sincronizarRoles($institucionId, $usuarioId, $datos['roles']);

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos(
                $ex,
                'Ya existe un usuario con ese nombre de usuario o esa persona ya tiene una cuenta en esta institución.'
            );
        }

        $this->auditarInsercion('usuario', 'UsuarioId', $usuarioId, $institucionId);
        $this->auditarLista(
            'usuario', $usuarioId, 'Roles',
            [], $this->nombresRoles($institucionId, $datos['roles'])
        );

        Response::exito(['UsuarioId' => $usuarioId, 'mensaje' => 'Usuario creado correctamente.'], [], 201);
    }

    /** PUT /api/usuarios/{id} — la contraseña solo cambia si se envía. */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id    = (int)$ruta['id'];
        $datos = $this->validar(false);

        $antes = $this->filaAuditable('usuario', 'UsuarioId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }
        $rolesAntes = $this->nombresRoles($institucionId, array_map('intval', $this->columna(
            'SELECT RolId FROM usuariorol WHERE UsuarioId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        )));

        try {
            $this->db->beginTransaction();

            if ($datos['password'] !== '') {
                $this->ejecutar(
                    'UPDATE usuario SET Username = ?, PasswordHash = ?, Email = ?, Estado = ?
                      WHERE UsuarioId = ? AND InstitucionEducativaId = ?',
                    [
                        $datos['username'], password_hash($datos['password'], PASSWORD_DEFAULT),
                        $datos['email'], $datos['estado'], $id, $institucionId,
                    ]
                );
            } else {
                $this->ejecutar(
                    'UPDATE usuario SET Username = ?, Email = ?, Estado = ?
                      WHERE UsuarioId = ? AND InstitucionEducativaId = ?',
                    [$datos['username'], $datos['email'], $datos['estado'], $id, $institucionId]
                );
            }

            $this->sincronizarRoles($institucionId, $id, $datos['roles']);

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos(
                $ex,
                'Ya existe un usuario con ese nombre de usuario o esa persona ya tiene una cuenta en esta institución.'
            );
        }

        $this->auditarActualizacion('usuario', 'UsuarioId', $id, $antes, $institucionId);
        $this->auditarLista(
            'usuario', $id, 'Roles',
            $rolesAntes, $this->nombresRoles($institucionId, $datos['roles'])
        );

        Response::exito(['UsuarioId' => $id, 'mensaje' => 'Usuario actualizado correctamente.']);
    }

    /** PATCH /api/usuarios/{id}/estado */
    public function estadoCambiar(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id = (int)$ruta['id'];

        $registro = $this->consultarUna(
            'SELECT Estado FROM usuario WHERE UsuarioId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        );
        if (!$registro) {
            Response::noEncontrado();
        }

        $nuevo = $this->peticion->texto('estado') !== ''
            ? $this->estado($this->peticion->texto('estado'))
            : $this->estadoInvertido($registro['Estado']);

        $this->ejecutar(
            'UPDATE usuario SET Estado = ? WHERE UsuarioId = ? AND InstitucionEducativaId = ?',
            [$nuevo, $id, $institucionId]
        );
        $this->auditarActualizacion('usuario', 'UsuarioId', $id, $registro, $institucionId);

        Response::exito(['UsuarioId' => $id, 'estado' => $nuevo, 'mensaje' => 'Estado actualizado.']);
    }

    /* ------------------------------------------------------------------ */

    private function rolesActivos(int $institucionId): array
    {
        return $this->consultar(
            "SELECT RolId, Nombre FROM rol WHERE InstitucionEducativaId = ? AND Estado = 'ACTIVO' ORDER BY Nombre",
            [$institucionId]
        );
    }

    /** Traduce identificadores de rol a nombres, para que la auditoría se lea. */
    private function nombresRoles(int $institucionId, array $rolIds): array
    {
        $rolIds = array_values(array_filter(array_map('intval', $rolIds)));
        if (!$rolIds) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($rolIds), '?'));

        return $this->columna(
            "SELECT Nombre FROM rol
              WHERE InstitucionEducativaId = ? AND RolId IN ($marcas) ORDER BY Nombre",
            array_merge([$institucionId], $rolIds)
        );
    }

    private function sincronizarRoles(int $institucionId, int $usuarioId, array $roles): void
    {
        $this->ejecutar(
            'DELETE FROM usuariorol WHERE UsuarioId = ? AND InstitucionEducativaId = ?',
            [$usuarioId, $institucionId]
        );

        if (!$roles) {
            return;
        }

        $stmt = $this->db->prepare('INSERT INTO usuariorol (InstitucionEducativaId, UsuarioId, RolId) VALUES (?,?,?)');
        foreach ($roles as $rolId) {
            $stmt->execute([$institucionId, $usuarioId, $rolId]);
        }
    }

    private function validar(bool $esNuevo): array
    {
        $errores   = [];
        $personaId = $this->peticion->entero('persona_id');
        $username  = $this->peticion->texto('username');
        $email     = $this->oNulo($this->peticion->texto('email'));
        $password  = (string)$this->peticion->dato('password', '');
        $confirmar = (string)$this->peticion->dato('password_confirm', $password);

        /* Se anota qué campo falla, no solo el mensaje: así la pantalla puede
           marcarlo en rojo sin tener que adivinar leyendo el texto. */
        $campos = [];
        $falla  = static function (string $campo, string $mensaje) use (&$errores, &$campos): void {
            $errores[]      = $mensaje;
            $campos[$campo] = true;
        };

        if ($esNuevo && $personaId <= 0) {
            $falla('persona_id', 'Debe seleccionar una persona.');
        } elseif ($personaId > 0
                  && !Padron::perteneceA($this->db, $personaId, $this->institucion())) {
            $falla('persona_id', 'La persona seleccionada no pertenece a esta institución.');
        }

        if ($username === '') {
            $falla('username', 'El nombre de usuario es obligatorio.');
        }

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $falla('email', 'El correo electrónico no es válido.');
        }

        /* Contraseña. Al editar puede dejarse en blanco: significa «no la
           cambies». Si viene con algo, se le exige toda la política. */
        if ($esNuevo && $password === '') {
            $falla('password', 'La contraseña es obligatoria para un nuevo usuario.');
        } elseif ($password !== '') {
            foreach (Password::validar($password) as $mensaje) {
                $falla('password', $mensaje);
            }
        }

        if ($password !== $confirmar) {
            $falla('password_confirm', 'Las contraseñas no coinciden.');
        }

        if ($errores) {
            Response::validacion($errores, $campos);
        }

        return [
            'persona_id' => $personaId,
            'username'   => $username,
            'email'      => $email,
            'password'   => $password,
            'estado'     => $this->estado($this->peticion->texto('estado', 'ACTIVO')),
            'roles'      => $this->peticion->arregloEnteros('roles'),
        ];
    }
}
