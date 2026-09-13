<?php
// api/controllers/UsuariosController.php
// CRUD de usuarios del sistema, asignación de roles (usuariorol) y de las
// instituciones en las que la cuenta puede entrar (usuario_institucion).
// Las contraseñas se cifran aquí con password_hash(): nunca viajan hacia el cliente.
//
// QUIÉN PUEDE QUÉ
//
// Administrar cuentas —crearlas, renombrarlas, inactivarlas, darles roles— lo
// hace quien tenga abierta esta opción, y siempre DENTRO de su institución.
//
// Ampliar una cuenta a OTRA institución es distinto: es dar acceso a los datos
// personales de otra comunidad educativa, y quien manda en esa institución no
// participa en la decisión. Por eso queda reservado al SuperAdmin, que es quien
// responde por toda la red. Un administrador corriente que edite una cuenta con
// varias instituciones no las pierde: no las ve y no las toca.

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

        /* En cuántas instituciones entra cada cuenta. Se muestra en el listado
           porque una cuenta que alcanza tres escuelas no se parece en nada a una
           que alcanza la suya, y eso no debería haber que ir a buscarlo. */
        $institucionesPorUsuario = [];
        if ($datos) {
            $ids = array_column($datos, 'UsuarioId');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->consultar(
                "SELECT ui.UsuarioId, i.nombre
                   FROM usuario_institucion ui
             INNER JOIN institucion_educativa i ON i.id = ui.InstitucionEducativaId
                  WHERE ui.UsuarioId IN ($in) AND i.estado = 'ACTIVO'
               ORDER BY i.nombre",
                $ids
            ) as $fila) {
                $institucionesPorUsuario[(int)$fila['UsuarioId']][] = $fila['nombre'];
            }
        }

        foreach ($datos as &$fila) {
            $fila['Roles'] = $rolesPorUsuario[(int)$fila['UsuarioId']] ?? [];
            $fila['Instituciones'] = $institucionesPorUsuario[(int)$fila['UsuarioId']] ?? [];
        }
        unset($fila);

        Response::lista($datos, $total, $pagina, $porPagina, [
            'roles_disponibles' => $this->rolesActivos($institucionId),
            'puede_asignar_instituciones' => $this->puedeAsignarInstituciones(),
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

        /* Solo el SuperAdmin ve —y por tanto puede cambiar— el mapa de
           instituciones. Para los demás la cuenta se administra como siempre:
           su institución y sus roles. */
        $registro['puede_asignar_instituciones'] = $this->puedeAsignarInstituciones();

        if ($registro['puede_asignar_instituciones']) {
            $registro['instituciones'] = $this->mapaInstituciones(
                $id,
                (int)$registro['InstitucionEducativaId']
            );
        }

        Response::exito($registro);
    }

    /**
     * GET /api/usuarios/politica-clave
     *
     * Las condiciones que debe cumplir una contraseña, para que la pantalla las
     * muestre y las vaya marcando mientras se escribe. Quien decide sigue siendo
     * el servidor, en `api/core/Password.php`, al guardar.
     *
     * Basta con estar autenticado: la política no es un dato reservado, y la
     * necesita cualquiera que vaya a cambiar su propia contraseña, no solo quien
     * administra las cuentas.
     */
    public function politicaClave(array $ruta = []): void
    {
        $this->requiereAutenticacion();

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

        $meta = [
            'roles_disponibles'           => $this->rolesActivos($institucionId),
            'puede_asignar_instituciones' => $this->puedeAsignarInstituciones(),
        ];

        /* La cuenta todavía no existe, así que el mapa va en blanco salvo por su
           institución —la actual—, que queda marcada porque es donde nacerá. */
        if ($meta['puede_asignar_instituciones']) {
            $meta['instituciones'] = $this->mapaInstituciones(0, $institucionId);
        }

        Response::exito($datos, $meta);
    }

    /** POST /api/usuarios */
    public function store(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $datos = $this->validar(true);

        /* La contraseña la genera el sistema y no la ve nadie: ni quien crea la
           cuenta ni esta respuesta. Viaja por correo al titular, que está
           obligado a cambiarla en su primer ingreso. El correo tampoco se pide
           en el formulario: es el de la persona, que ya consta en el padrón. */
        $clave  = Password::generar();
        $correo = $this->correoDeLaPersona($institucionId, $datos['persona_id']);

        try {
            $this->db->beginTransaction();

            $this->ejecutar(
                'INSERT INTO usuario
                    (InstitucionEducativaId, PersonaId, Username, PasswordHash, DebeCambiarClave, Email, Estado)
                 VALUES (?,?,?,?,?,?,?)',
                [
                    $institucionId, $datos['persona_id'], $datos['username'],
                    password_hash($clave, PASSWORD_DEFAULT), 'SI', $correo, $datos['estado'],
                ]
            );
            $usuarioId = (int)$this->db->lastInsertId();

            $this->guardarAccesos($institucionId, $usuarioId, $institucionId, $datos);

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
        $this->auditarLista('usuario', $usuarioId, 'Roles', [], $this->nombresRoles(
            $institucionId,
            array_map('intval', $this->columna(
                'SELECT RolId FROM usuariorol WHERE UsuarioId = ? AND InstitucionEducativaId = ?',
                [$usuarioId, $institucionId]
            ))
        ));
        $this->auditarLista(
            'usuario', $usuarioId, 'Instituciones',
            [], $this->nombresInstituciones($usuarioId)
        );

        $aviso = ClaveTemporal::enviar($this->db, $institucionId, [
            'destino'  => $correo,
            'username' => $datos['username'],
            'clave'    => $clave,
        ]);

        Response::exito([
            'UsuarioId'  => $usuarioId,
            'mensaje'    => 'Usuario creado correctamente.',
            'credencial' => $aviso,
        ], [], 201);
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
        $institucionesAntes = $this->nombresInstituciones($id);

        /* El correo sigue siendo el de la persona: se refresca por si cambió en
           su ficha. Nadie lo escribe aquí. */
        $correo = $this->correoDeLaPersona($institucionId, (int)$antes['PersonaId']);

        /* Restablecer la clave no es escribir una: el sistema genera otra
           temporal, la envía y vuelve a exigir el cambio en el próximo ingreso.
           Así nadie —tampoco quien administra— llega a conocer la contraseña de
           otra persona. */
        $clave = $datos['restablecer_clave'] ? Password::generar() : '';

        try {
            $this->db->beginTransaction();

            if ($clave !== '') {
                $this->ejecutar(
                    'UPDATE usuario
                        SET Username = ?, PasswordHash = ?, DebeCambiarClave = \'SI\', Email = ?, Estado = ?
                      WHERE UsuarioId = ? AND InstitucionEducativaId = ?',
                    [
                        $datos['username'], password_hash($clave, PASSWORD_DEFAULT),
                        $correo, $datos['estado'], $id, $institucionId,
                    ]
                );
            } else {
                $this->ejecutar(
                    'UPDATE usuario SET Username = ?, Email = ?, Estado = ?
                      WHERE UsuarioId = ? AND InstitucionEducativaId = ?',
                    [$datos['username'], $correo, $datos['estado'], $id, $institucionId]
                );
            }

            $this->guardarAccesos($institucionId, $id, (int)$antes['InstitucionEducativaId'], $datos);

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

        /* Los roles se releen de la base en vez de darlos por lo enviado: con el
           mapa por institución, lo que llega en el cuerpo no es lo que queda
           grabado en ESTA institución, y la bitácora debe contar lo que pasó. */
        $this->auditarLista('usuario', $id, 'Roles', $rolesAntes, $this->nombresRoles(
            $institucionId,
            array_map('intval', $this->columna(
                'SELECT RolId FROM usuariorol WHERE UsuarioId = ? AND InstitucionEducativaId = ?',
                [$id, $institucionId]
            ))
        ));
        $this->auditarLista(
            'usuario', $id, 'Instituciones',
            $institucionesAntes, $this->nombresInstituciones($id)
        );

        $respuesta = ['UsuarioId' => $id, 'mensaje' => 'Usuario actualizado correctamente.'];

        if ($clave !== '') {
            $respuesta['credencial'] = ClaveTemporal::enviar($this->db, $institucionId, [
                'destino'  => $correo,
                'username' => $datos['username'],
                'clave'    => $clave,
            ]);
        }

        Response::exito($respuesta);
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

    /* ------------------------------------------------------------------ */
    /* Instituciones de la cuenta                                          */
    /* ------------------------------------------------------------------ */

    /** Ampliar una cuenta a otra institución solo lo hace el SuperAdmin. */
    private function puedeAsignarInstituciones(): bool
    {
        return Auth::esSuperAdmin($this->usuario ?? []);
    }

    /**
     * El cuadro completo para el formulario: cada institución activa de la red,
     * si la cuenta entra en ella, y qué roles de ESA institución tiene.
     *
     * Los roles son de cada institución, no de la persona: por eso cada entrada
     * trae los suyos. Alguien puede consultar en una escuela y registrar en otra.
     *
     * @param int $institucionPropia la de la cuenta; se marca y no se quita
     */
    private function mapaInstituciones(int $usuarioId, int $institucionPropia): array
    {
        $instituciones = $this->consultar(
            "SELECT id, nombre FROM institucion_educativa WHERE estado = 'ACTIVO' ORDER BY nombre"
        );

        $asignadas = $usuarioId > 0
            ? array_flip(Auth::institucionesAsignadas($usuarioId))
            : [];

        /* Roles de la cuenta en TODAS las instituciones, de una sola consulta:
           una por institución serían veintiuna para pintar un formulario. */
        $rolesPorInstitucion = [];
        if ($usuarioId > 0) {
            foreach ($this->consultar(
                'SELECT InstitucionEducativaId, RolId FROM usuariorol WHERE UsuarioId = ?',
                [$usuarioId]
            ) as $fila) {
                $rolesPorInstitucion[(int)$fila['InstitucionEducativaId']][] = (int)$fila['RolId'];
            }
        }

        $rolesDisponibles = [];
        foreach ($this->consultar(
            "SELECT InstitucionEducativaId, RolId, Nombre FROM rol
              WHERE Estado = 'ACTIVO' ORDER BY Nombre"
        ) as $fila) {
            $rolesDisponibles[(int)$fila['InstitucionEducativaId']][] = [
                'RolId'  => (int)$fila['RolId'],
                'Nombre' => $fila['Nombre'],
            ];
        }

        $mapa = [];
        foreach ($instituciones as $institucion) {
            $id = (int)$institucion['id'];

            $mapa[] = [
                'id'                => $id,
                'nombre'            => $institucion['nombre'],
                // La suya va marcada y no se puede desmarcar: es donde vive la
                // cuenta, y quitarla dejaría un usuario sin ninguna puerta.
                'propia'            => $id === $institucionPropia,
                'asignada'          => $id === $institucionPropia || isset($asignadas[$id]),
                'roles_disponibles' => $rolesDisponibles[$id] ?? [],
                'roles_asignados'   => $rolesPorInstitucion[$id] ?? [],
            ];
        }

        return $mapa;
    }

    /**
     * Deja `usuario_institucion` tal como pide el formulario.
     *
     * La institución propia entra siempre, la haya marcado o no quien edita:
     * es la única que la cuenta tiene garantizada y quitarla la dejaría sin
     * ninguna puerta por la que entrar.
     *
     * @param int[] $instituciones
     */
    private function sincronizarInstituciones(int $usuarioId, int $institucionPropia, array $instituciones): void
    {
        $instituciones[] = $institucionPropia;
        $instituciones   = array_values(array_unique(array_filter(array_map('intval', $instituciones))));

        $this->ejecutar('DELETE FROM usuario_institucion WHERE UsuarioId = ?', [$usuarioId]);

        $stmt = $this->db->prepare(
            'INSERT INTO usuario_institucion (InstitucionEducativaId, UsuarioId) VALUES (?,?)'
        );
        foreach ($instituciones as $institucionId) {
            $stmt->execute([$institucionId, $usuarioId]);
        }
    }

    /**
     * Roles a grabar en cada institución, ya depurados.
     *
     * Solo se conservan los roles que existen, están activos y pertenecen a la
     * institución donde se piden: así un formulario manipulado no puede colar
     * en una escuela el identificador de un rol de otra.
     *
     * @param array<int, int[]> $pedidos institucionId => RolId[]
     * @param int[]             $permitidas instituciones a las que entra la cuenta
     * @return array<int, int[]>
     */
    private function rolesPorInstitucionValidados(array $pedidos, array $permitidas): array
    {
        if (!$permitidas) {
            return [];
        }

        $marcas = implode(',', array_fill(0, count($permitidas), '?'));
        $validos = [];
        foreach ($this->consultar(
            "SELECT InstitucionEducativaId, RolId FROM rol
              WHERE Estado = 'ACTIVO' AND InstitucionEducativaId IN ($marcas)",
            array_map('intval', $permitidas)
        ) as $fila) {
            $validos[(int)$fila['InstitucionEducativaId']][(int)$fila['RolId']] = true;
        }

        $limpio = [];
        foreach ($permitidas as $institucionId) {
            $institucionId = (int)$institucionId;
            $roles = array_map('intval', (array)($pedidos[$institucionId] ?? []));

            $limpio[$institucionId] = array_values(array_filter(
                $roles,
                static fn(int $rolId): bool => isset($validos[$institucionId][$rolId])
            ));
        }

        return $limpio;
    }

    private function rolesActivos(int $institucionId): array
    {
        return $this->consultar(
            "SELECT RolId, Nombre FROM rol WHERE InstitucionEducativaId = ? AND Estado = 'ACTIVO' ORDER BY Nombre",
            [$institucionId]
        );
    }

    /**
     * Instituciones en las que entra la cuenta, por nombre.
     *
     * La bitácora guarda el QUÉ, nunca el dato, pero sí necesita que el campo se
     * lea: «Colegio San José» dice algo, «7» no.
     *
     * @return string[]
     */
    private function nombresInstituciones(int $usuarioId): array
    {
        return $this->columna(
            'SELECT i.nombre
               FROM usuario_institucion ui
         INNER JOIN institucion_educativa i ON i.id = ui.InstitucionEducativaId
              WHERE ui.UsuarioId = ?
           ORDER BY i.nombre',
            [$usuarioId]
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

        /* Ni el correo ni la contraseña se piden en el formulario:
           · el correo es el de la persona, que ya consta en el padrón, y
             pedirlo dos veces solo servía para que las dos copias se separaran;
           · la contraseña la genera el sistema y viaja al titular por correo,
             de modo que quien administra las cuentas no llega a conocerla.
           Si el alta se hace sobre una persona sin correo, no hay forma de
           entregarle su clave: se rechaza aquí y no después. */
        if ($personaId > 0 && $this->correoDeLaPersona($this->institucion(), $personaId) === null) {
            $falla('persona_id',
                'La persona seleccionada no tiene correo electrónico registrado, y es allí donde '
                . 'se le envía su contraseña. Regístreselo primero en su ficha.');
        }

        if ($errores) {
            Response::validacion($errores, $campos);
        }

        /* Las instituciones y sus roles solo llegan si quien edita es SuperAdmin.
           Si no lo es, se ignora lo que venga en el cuerpo: no basta con que la
           pantalla no muestre las casillas, porque la petición puede armarse a
           mano. Lo que se guarda entonces es lo de siempre: los roles de la
           institución en la que está trabajando. */
        $mandaInstituciones = $this->puedeAsignarInstituciones();
        $instituciones      = $mandaInstituciones
            ? $this->peticion->arregloEnteros('instituciones')
            : [];
        $rolesPorInstitucion = $mandaInstituciones
            ? (array)$this->peticion->dato('roles_por_institucion', [])
            : [];

        return [
            'persona_id'        => $personaId,
            'username'          => $username,
            'estado'            => $this->estado($this->peticion->texto('estado', 'ACTIVO')),
            'roles'             => $this->peticion->arregloEnteros('roles'),
            'manda_instituciones'   => $mandaInstituciones,
            'instituciones'         => $instituciones,
            'roles_por_institucion' => $rolesPorInstitucion,
            // Solo en la edición: genera otra clave temporal y la reenvía
            'restablecer_clave' => (bool)$this->peticion->dato('restablecer_clave', false),
        ];
    }

    /**
     * Guarda instituciones y roles según quién esté editando.
     *
     * SuperAdmin: manda el mapa completo —a qué instituciones entra la cuenta y
     * con qué roles en cada una—.
     *
     * Cualquier otro administrador: solo toca los roles de SU institución, y ni
     * siquiera ve las demás. Lo que la cuenta tenga en otras instituciones sigue
     * exactamente igual después de que él guarde.
     */
    private function guardarAccesos(int $institucionActual, int $usuarioId, int $institucionPropia, array $datos): void
    {
        if (!$datos['manda_instituciones']) {
            $this->sincronizarRoles($institucionActual, $usuarioId, $datos['roles']);
            return;
        }

        $instituciones = array_values(array_unique(array_merge(
            array_map('intval', $datos['instituciones']),
            [$institucionPropia]
        )));

        $this->sincronizarInstituciones($usuarioId, $institucionPropia, $instituciones);

        $porInstitucion = $this->rolesPorInstitucionValidados(
            $datos['roles_por_institucion'],
            $instituciones
        );

        /* Los roles se rehacen en TODAS las instituciones, no solo en las
           marcadas: si se le retira el acceso a una escuela, sus roles allí
           dejan de tener sentido y quedarían esperando a que alguien se los
           devolviera sin querer. */
        $this->ejecutar('DELETE FROM usuariorol WHERE UsuarioId = ?', [$usuarioId]);

        $stmt = $this->db->prepare(
            'INSERT INTO usuariorol (InstitucionEducativaId, UsuarioId, RolId) VALUES (?,?,?)'
        );
        foreach ($porInstitucion as $institucionId => $roles) {
            foreach ($roles as $rolId) {
                $stmt->execute([$institucionId, $usuarioId, $rolId]);
            }
        }
    }

    /**
     * Correo de la persona dueña de la cuenta.
     *
     * Es el único correo del sistema para esa persona: `usuario`.`Email` se
     * conserva como copia porque otras consultas la leen, pero quien manda es
     * la ficha del padrón.
     */
    private function correoDeLaPersona(int $institucionId, int $personaId): ?string
    {
        $ficha = Padron::porId($this->db, $institucionId, $personaId);
        $correo = trim((string)($ficha['Email'] ?? ''));

        return CorreoElectronico::esValido($correo) ? CorreoElectronico::normalizar($correo) : null;
    }
}
