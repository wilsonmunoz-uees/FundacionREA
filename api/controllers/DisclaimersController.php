<?php
/**
 * api/controllers/DisclaimersController.php
 * -----------------------------------------------------------------------------
 * Parámetros: disclaimers de políticas de protección de datos.
 *
 * Cada disclaimer aplica a un tipo de persona (estudiante, empleado o
 * proveedor), lleva una versión y un texto enriquecido, y tiene un estado que
 * indica si es el vigente. Solo puede haber uno ACTIVO por tipo: al activar
 * uno, los demás de ese tipo pasan a INACTIVO.
 *
 * El texto se redacta en un editor visual y se muestra tal cual en la pantalla
 * pública, de modo que antes de guardarlo se depura con HtmlSeguro.
 * -----------------------------------------------------------------------------
 */

final class DisclaimersController extends Controller
{
    /** Clave en includes/accesos.php */
    private const MODULO = 'disclaimers';

    public const TIPOS = ['ESTUDIANTE', 'EMPLEADO', 'PROVEEDOR'];

    /** GET /api/disclaimers?tipo=&estado=&pagina= */
    public function index(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $where  = 'WHERE InstitucionEducativaId = ?';
        $params = [$institucionId];

        $tipo = strtoupper($this->peticion->paramTexto('tipo'));
        if (in_array($tipo, self::TIPOS, true)) {
            $where   .= ' AND TipoPersona = ?';
            $params[] = $tipo;
        }

        $estado = strtoupper($this->peticion->paramTexto('estado'));
        if (in_array($estado, ['ACTIVO', 'INACTIVO'], true)) {
            $where   .= ' AND Estado = ?';
            $params[] = $estado;
        }

        $total = $this->contar("SELECT COUNT(*) total FROM disclaimer $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(15);

        $datos = $this->consultar(
            "SELECT DisclaimerId, TipoPersona, Version, Titulo, Estado,
                    FechaCreacion, FechaVigencia, Username, CHAR_LENGTH(Texto) AS LargoTexto
               FROM disclaimer
             $where
           ORDER BY TipoPersona, Estado, DisclaimerId DESC
              LIMIT $offset, $porPagina",
            $params
        );

        // Cuál está vigente hoy para cada tipo: lo consulta la pantalla
        $vigentes = [];
        foreach (self::TIPOS as $t) {
            $fila = $this->vigenteDe($institucionId, $t);
            $vigentes[$t] = $fila ? [
                'DisclaimerId' => (int)$fila['DisclaimerId'],
                'Version'      => $fila['Version'],
                'FechaVigencia' => $fila['FechaVigencia'],
            ] : null;
        }

        Response::lista($datos, $total, $pagina, $porPagina, ['vigentes' => $vigentes]);
    }

    /** GET /api/disclaimers/{id} — incluye el texto completo. */
    public function show(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);

        $registro = $this->filaAuditable('disclaimer', 'DisclaimerId', (int)$ruta['id'], $this->institucion());
        if (!$registro) {
            Response::noEncontrado();
        }

        Response::exito($registro);
    }

    /** POST /api/disclaimers */
    public function store(array $ruta = []): void
    {
        $usuario       = $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $datos         = $this->validar($institucionId, null);

        try {
            $this->ejecutar(
                'INSERT INTO disclaimer
                    (InstitucionEducativaId, TipoPersona, Version, Titulo, Texto, Estado,
                     FechaCreacion, FechaVigencia, UsuarioId, Username)
                 VALUES (?,?,?,?,?,?,NOW(),?,?,?)',
                [
                    $institucionId, $datos['tipo'], $datos['version'], $datos['titulo'], $datos['texto'],
                    $datos['estado'], $datos['estado'] === 'ACTIVO' ? date('Y-m-d H:i:s') : null,
                    $usuario['usuario_id'], $usuario['username'],
                ]
            );
        } catch (PDOException $ex) {
            $this->errorBaseDatos($ex, 'Ya existe un disclaimer con esa versión para ese tipo de persona.');
        }

        $id = (int)$this->db->lastInsertId();

        if ($datos['estado'] === 'ACTIVO') {
            $this->desactivarOtros($institucionId, $datos['tipo'], $id);
        }

        $this->auditarInsercion('disclaimer', 'DisclaimerId', $id, $institucionId);

        Response::exito(['DisclaimerId' => $id, 'mensaje' => 'Disclaimer registrado correctamente.'], [], 201);
    }

    /** PUT /api/disclaimers/{id} */
    public function update(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id            = (int)$ruta['id'];

        $antes = $this->filaAuditable('disclaimer', 'DisclaimerId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }

        $datos = $this->validar($institucionId, $id);

        try {
            $this->ejecutar(
                'UPDATE disclaimer
                    SET TipoPersona = ?, Version = ?, Titulo = ?, Texto = ?, Estado = ?,
                        FechaVigencia = CASE WHEN ? = \'ACTIVO\' AND Estado <> \'ACTIVO\' THEN NOW() ELSE FechaVigencia END
                  WHERE DisclaimerId = ? AND InstitucionEducativaId = ?',
                [
                    $datos['tipo'], $datos['version'], $datos['titulo'], $datos['texto'], $datos['estado'],
                    $datos['estado'], $id, $institucionId,
                ]
            );
        } catch (PDOException $ex) {
            $this->errorBaseDatos($ex, 'Ya existe un disclaimer con esa versión para ese tipo de persona.');
        }

        if ($datos['estado'] === 'ACTIVO') {
            $this->desactivarOtros($institucionId, $datos['tipo'], $id);
        }

        $this->auditarActualizacion('disclaimer', 'DisclaimerId', $id, $antes, $institucionId);

        Response::exito(['DisclaimerId' => $id, 'mensaje' => 'Disclaimer actualizado correctamente.']);
    }

    /**
     * PATCH /api/disclaimers/{id}/activar
     * Deja este disclaimer como el vigente de su tipo y desactiva los demás.
     */
    public function activar(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id            = (int)$ruta['id'];

        $antes = $this->filaAuditable('disclaimer', 'DisclaimerId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }

        if (trim((string)$antes['Texto']) === '') {
            Response::validacion(['No se puede activar un disclaimer sin texto.']);
        }

        $this->ejecutar(
            "UPDATE disclaimer SET Estado = 'ACTIVO', FechaVigencia = NOW()
              WHERE DisclaimerId = ? AND InstitucionEducativaId = ?",
            [$id, $institucionId]
        );

        $this->desactivarOtros($institucionId, (string)$antes['TipoPersona'], $id);

        $this->auditarActualizacion('disclaimer', 'DisclaimerId', $id, $antes, $institucionId);

        Response::exito([
            'DisclaimerId' => $id,
            'mensaje'      => 'Este disclaimer quedó vigente para ' . mb_strtolower((string)$antes['TipoPersona']) . 's.',
        ]);
    }

    /** DELETE /api/disclaimers/{id} — no se permite borrar el vigente. */
    public function destroy(array $ruta): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $id            = (int)$ruta['id'];

        $antes = $this->filaAuditable('disclaimer', 'DisclaimerId', $id, $institucionId);
        if (!$antes) {
            Response::noEncontrado();
        }

        if ($antes['Estado'] === 'ACTIVO') {
            Response::error(
                'No se puede eliminar el disclaimer vigente. Active otro para ese tipo de persona y vuelva a intentarlo.',
                409
            );
        }

        $this->ejecutar(
            'DELETE FROM disclaimer WHERE DisclaimerId = ? AND InstitucionEducativaId = ?',
            [$id, $institucionId]
        );

        $this->auditarEliminacion('disclaimer', $id, $antes);

        Response::exito(['DisclaimerId' => $id, 'mensaje' => 'Disclaimer eliminado.']);
    }

    /* ================================================================== */
    /* Apoyo                                                               */
    /* ================================================================== */

    /**
     * Disclaimer vigente de un tipo de persona.
     * Lo usa también el flujo público, por eso es estático.
     */
    public static function vigente(PDO $db, int $institucionId, string $tipoPersona): ?array
    {
        $stmt = $db->prepare(
            "SELECT DisclaimerId, TipoPersona, Version, Titulo, Texto, FechaVigencia
               FROM disclaimer
              WHERE InstitucionEducativaId = ? AND TipoPersona = ? AND Estado = 'ACTIVO'
           ORDER BY FechaVigencia DESC, DisclaimerId DESC
              LIMIT 1"
        );
        $stmt->execute([$institucionId, $tipoPersona]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    private function vigenteDe(int $institucionId, string $tipoPersona): ?array
    {
        return self::vigente($this->db, $institucionId, $tipoPersona);
    }

    private function desactivarOtros(int $institucionId, string $tipoPersona, int $excepto): void
    {
        $this->ejecutar(
            "UPDATE disclaimer SET Estado = 'INACTIVO'
              WHERE InstitucionEducativaId = ? AND TipoPersona = ? AND DisclaimerId <> ?",
            [$institucionId, $tipoPersona, $excepto]
        );
    }

    /** @return array{tipo:string,version:string,titulo:?string,texto:string,estado:string} */
    private function validar(int $institucionId, ?int $id): array
    {
        $errores = [];

        $tipo    = strtoupper($this->peticion->texto('tipo_persona'));
        $version = trim($this->peticion->texto('version'));
        $titulo  = $this->oNulo($this->peticion->texto('titulo'));
        $texto   = HtmlSeguro::limpiar((string)$this->peticion->dato('texto', ''));
        $estado  = $this->estado($this->peticion->texto('estado', 'INACTIVO'), 'INACTIVO');

        if (!in_array($tipo, self::TIPOS, true)) {
            $errores[] = 'Indique el tipo de persona: estudiante, empleado o proveedor.';
        }
        if ($version === '') {
            $errores[] = 'La versión es obligatoria.';
        } elseif (mb_strlen($version) > 20) {
            $errores[] = 'La versión no puede superar los 20 caracteres.';
        }
        if (HtmlSeguro::aTexto($texto) === '') {
            $errores[] = 'El texto del disclaimer es obligatorio.';
        }

        if ($errores) {
            Response::validacion($errores);
        }

        return [
            'tipo'    => $tipo,
            'version' => $version,
            'titulo'  => $titulo !== null ? mb_substr($titulo, 0, 150) : null,
            'texto'   => $texto,
            'estado'  => $estado,
        ];
    }
}
