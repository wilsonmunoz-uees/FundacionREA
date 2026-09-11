<?php
// api/core/Controller.php
// Clase base de todos los controladores: conexión, contexto del usuario,
// guardas de acceso, paginación y utilidades de consulta.

abstract class Controller
{
    protected Request $peticion;
    protected PDO $db;
    /** Contexto del usuario autenticado (null si la ruta es pública). */
    protected ?array $usuario = null;

    public function __construct(Request $peticion)
    {
        $this->peticion = $peticion;
        $this->db       = Database::conexion();
        $this->usuario  = Auth::usuarioDe($peticion);
    }

    /* ------------------------------------------------------------------ */
    /* Guardas de acceso                                                   */
    /* ------------------------------------------------------------------ */

    protected function requiereAutenticacion(): array
    {
        if ($this->usuario === null) {
            Response::noAutenticado();
        }
        return $this->usuario;
    }

    /** Exige al menos uno de los roles indicados. */
    protected function requiereRol(array $roles): array
    {
        $usuario = $this->requiereAutenticacion();
        if (!Auth::tieneRol($usuario, $roles)) {
            Response::prohibido('No cuenta con permisos suficientes para acceder a este módulo.');
        }
        return $usuario;
    }

    /**
     * Exige alguno de los roles indicados O el permiso señalado.
     * Reproduce la regla usada en consentimientos y reportes.
     */
    protected function requiereRolOPermiso(array $roles, string $codigoPermiso): array
    {
        $usuario = $this->requiereAutenticacion();
        if (Auth::tieneRol($usuario, $roles) || Auth::tienePermiso($usuario, $codigoPermiso)) {
            return $usuario;
        }
        Response::prohibido('No cuenta con permisos suficientes para acceder a este módulo.');
    }

    /**
     * Exige acceso a una opción del sistema (por rol o por permiso), según el
     * mapa compartido con las páginas: includes/accesos.php.
     */
    protected function requiereAcceso(string $clave): array
    {
        $usuario = $this->requiereAutenticacion();
        if (Auth::puede($usuario, $clave)) {
            return $usuario;
        }
        Response::prohibido('No cuenta con permisos suficientes para acceder a este módulo.');
    }

    /** Institución educativa activa del usuario autenticado. */
    protected function institucion(): int
    {
        $usuario = $this->requiereAutenticacion();
        return (int)$usuario['institucion_id'];
    }

    /* ------------------------------------------------------------------ */
    /* Utilidades de consulta                                              */
    /* ------------------------------------------------------------------ */

    /** Devuelve [pagina, porPagina, offset] a partir de ?pagina= y ?por_pagina= */
    protected function paginacion(int $porPaginaPorDefecto = 12): array
    {
        $pagina     = max(1, $this->peticion->paramEntero('pagina', 1));
        $porPagina  = $this->peticion->paramEntero('por_pagina', $porPaginaPorDefecto);
        $porPagina  = max(1, min(500, $porPagina));
        return [$pagina, $porPagina, ($pagina - 1) * $porPagina];
    }

    /** Cuenta registros de una consulta ya construida. */
    protected function contar(string $sql, array $parametros = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($parametros);
        return (int)($stmt->fetch()['total'] ?? 0);
    }

    /** Ejecuta una consulta y devuelve todas las filas. */
    protected function consultar(string $sql, array $parametros = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($parametros);
        return $stmt->fetchAll();
    }

    /** Ejecuta una consulta y devuelve la primera fila (o null). */
    protected function consultarUna(string $sql, array $parametros = []): ?array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($parametros);
        $fila = $stmt->fetch();
        return $fila === false ? null : $fila;
    }

    /** Ejecuta una sentencia de escritura y devuelve el número de filas afectadas. */
    protected function ejecutar(string $sql, array $parametros = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($parametros);
        return $stmt->rowCount();
    }

    /** Ejecuta una consulta devolviendo una sola columna. */
    protected function columna(string $sql, array $parametros = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($parametros);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    protected function like(string $texto): string
    {
        return '%' . $texto . '%';
    }

    /* ------------------------------------------------------------------ */
    /* Auditoría (bitácora de la base de datos)                            */
    /* ------------------------------------------------------------------ */

    /**
     * Lee la fila completa de un registro para poder compararla antes y
     * después de un cambio. Devuelve null si el registro no existe.
     *
     * @param string   $tabla         Tabla afectada
     * @param string   $columnaId     Columna que identifica al registro
     * @param mixed    $id            Valor del identificador
     * @param int|null $institucionId Si se indica, restringe por institución
     */
    protected function filaAuditable(string $tabla, string $columnaId, $id, ?int $institucionId = null): ?array
    {
        $sql    = "SELECT * FROM `$tabla` WHERE `$columnaId` = ?";
        $params = [$id];

        if ($institucionId !== null) {
            $sql     .= ' AND InstitucionEducativaId = ?';
            $params[] = $institucionId;
        }

        return $this->consultarUna($sql, $params);
    }

    /** Registra un alta leyendo la fila recién insertada. */
    protected function auditarInsercion(string $tabla, string $columnaId, $id, ?int $institucionId = null): void
    {
        if ($this->usuario === null) {
            return;
        }
        $fila = $this->filaAuditable($tabla, $columnaId, $id, $institucionId);
        Auditoria::insercion($this->usuario, $tabla, $id, $fila ?? []);
    }

    /**
     * Registra un cambio comparando la fila anterior (leída con
     * filaAuditable() antes de escribir) con su estado actual.
     */
    protected function auditarActualizacion(string $tabla, string $columnaId, $id, ?array $antes, ?int $institucionId = null): void
    {
        if ($this->usuario === null || $antes === null) {
            return;
        }
        $despues = $this->filaAuditable($tabla, $columnaId, $id, $institucionId);
        Auditoria::actualizacion($this->usuario, $tabla, $id, $antes, $despues);
    }

    /** Registra una baja conservando los valores que tenía el registro. */
    protected function auditarEliminacion(string $tabla, $id, ?array $antes): void
    {
        if ($this->usuario === null) {
            return;
        }
        Auditoria::eliminacion($this->usuario, $tabla, $id, $antes);
    }

    /**
     * Registra el cambio de una lista asociada (roles de un usuario, permisos
     * de un rol, tipos de dato de un consentimiento). En lugar de una fila por
     * cada vínculo, deja una sola anotación legible con la lista completa
     * antes y después.
     *
     * @param array $antes   Valores anteriores (escalares)
     * @param array $despues Valores nuevos (escalares)
     */
    protected function auditarLista(string $tabla, $registroId, string $campo, array $antes, array $despues): void
    {
        if ($this->usuario === null) {
            return;
        }

        $normalizar = static function (array $valores): string {
            $valores = array_map('strval', $valores);
            sort($valores, SORT_NATURAL);
            return implode(', ', $valores);
        };

        Auditoria::cambioLista(
            $this->usuario,
            $tabla,
            $registroId,
            $campo,
            $normalizar($antes),
            $normalizar($despues)
        );
    }

    /** Traduce excepciones de MySQL a mensajes claros para el usuario final. */
    protected function errorBaseDatos(PDOException $ex, string $mensajeDuplicado): void
    {
        if ((string)$ex->getCode() === '23000') {
            error_log('[API] ' . $ex->getMessage());
            /* Un choque de unicidad casi siempre significa «ese dato ya está».
               Pero hay un caso en que no es culpa de quien captura, sino de la
               base: ver avisoIndiceHeredado(). */
            Response::error($this->avisoIndiceHeredado($ex) ?? $mensajeDuplicado, 409);
        }
        error_log('[API] ' . $ex->getMessage());
        Response::error(
            'No se pudo guardar. Informe al administrador del sistema para que revise el registro del servidor.',
            500
        );
    }

    /**
     * ¿El choque se debe a un único GLOBAL heredado del diseño de una sola
     * institución?
     *
     * La red tiene 21 instituciones y cada una lleva su propia ficha de la misma
     * persona: un padre puede ser representante en dos escuelas, y un empleado
     * puede ser proveedor en otra. El modelo ya lo contempla —los únicos son
     * (InstitucionEducativaId, Identificacion)—, pero una base que todavía
     * arrastre el único global sobre `Identificacion` rechaza el segundo
     * ingreso, y el mensaje que devuelve («Duplicate entry … for key
     * 'Identificacion'») no le dice nada a quien está frente a la pantalla.
     *
     * Aquí se comprueba si el índice culpable sigue existiendo y, de ser así, se
     * explica qué pasa y cuál es el remedio.
     *
     * @return string|null null si el choque es un duplicado corriente
     */
    protected function avisoIndiceHeredado(PDOException $ex): ?string
    {
        if (!str_contains($ex->getMessage(), 'Duplicate entry')) {
            return null;
        }
        if (preg_match("/for key '(?:.*\.)?(.*?)'/", $ex->getMessage(), $m) !== 1) {
            return null;
        }
        $clave = $m[1];      // MariaDB 10.6+ antepone el nombre de la tabla

        // Solo los nombres heredados; los únicos correctos llevan prefijo uk_
        if ($clave !== 'Identificacion' && $clave !== 'CodigoEstudiante') {
            return null;
        }

        $avisos = $this->avisosIndicesHeredados();
        return $avisos === [] ? null : implode(' ', $avisos);
    }

    /**
     * Únicos globales pendientes de convertir, ya redactados.
     *
     * @return string[] vacío si la base está al día
     */
    protected function avisosIndicesHeredados(): array
    {
        $explicacion = [
            'persona'    => 'una misma persona no puede constar en dos instituciones '
                          . '(el caso habitual del representante con hijos en dos escuelas, '
                          . 'o del empleado que también es proveedor de otra institución)',
            'estudiante' => 'dos instituciones no pueden usar el mismo código de estudiante, '
                          . 'aunque cada una lleve su propia numeración',
        ];

        $avisos = [];
        foreach (Padron::indicesGlobalesPendientes($this->db) as $indice => $tabla) {
            $avisos[] = 'La base de datos todavía tiene el índice antiguo «' . $indice . '» en la tabla «'
                . $tabla . '», heredado de cuando el sistema atendía a una sola institución. Mientras siga ahí, '
                . ($explicacion[$tabla] ?? 'el dato no puede repetirse entre instituciones')
                . '. Pida al administrador que ejecute el script 11_ALTER_unicos_por_institucion.sql.';
        }
        return $avisos;
    }

    /** Normaliza un valor de estado ACTIVO/INACTIVO. */
    protected function estado($valor, string $porDefecto = 'ACTIVO'): string
    {
        $valor = strtoupper(trim((string)$valor));
        return in_array($valor, ['ACTIVO', 'INACTIVO'], true) ? $valor : $porDefecto;
    }

    /** Invierte el estado actual (usado por los botones Activar/Inactivar). */
    protected function estadoInvertido($estadoActual): string
    {
        return strtoupper((string)$estadoActual) === 'ACTIVO' ? 'INACTIVO' : 'ACTIVO';
    }

    /** Devuelve el valor recibido o null si viene vacío. */
    protected function oNulo(string $valor): ?string
    {
        $valor = trim($valor);
        return $valor === '' ? null : $valor;
    }
}
