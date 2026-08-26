<?php
/**
 * api/controllers/EnvioMasivoController.php
 * -----------------------------------------------------------------------------
 * Envío masivo de invitaciones al consentimiento.
 *
 * Envía a estudiantes, empleados o proveedores —a todos, o a los que se elijan
 * uno por uno— un correo con el enlace de consentimiento CON VERIFICACIÓN de su
 * tipo, ya con su número de documento precargado, de modo que quien lo abre solo
 * tiene que continuar.
 *
 * El correo sale por el servidor SMTP configurado para la institución con la que
 * se inició sesión (`correo_configuracion`), y el enlace apunta a esa misma
 * institución: cada una invita a su propia gente, con su propio remitente.
 *
 * Es una opción del rol **Registro de Datos** (clave `envio_masivo` en
 * includes/accesos.php).
 *
 * Rutas:
 *
 *   GET  api/envio-masivo/resumen        cuántos hay de cada tipo y a cuántos
 *                                        se les puede escribir
 *   GET  api/envio-masivo/destinatarios  listado paginado para la subventana de
 *                                        selección, con búsqueda
 *   POST api/envio-masivo/enviar         realiza el envío
 *
 * A quién se le escribe:
 *   · Estudiantes  → al correo del REPRESENTANTE, indicando de qué representado
 *                    se trata. Sin representante con correo, no se puede enviar.
 *   · Empleados    → a su propio correo.
 *   · Proveedores  → al correo del contacto registrado.
 * -----------------------------------------------------------------------------
 */

final class EnvioMasivoController extends Controller
{
    /** Clave en includes/accesos.php. */
    private const MODULO = 'envio_masivo';

    /** Tope de correos por petición: evita que el envío agote el tiempo del hosting. */
    private const MAX_POR_ENVIO = 300;

    /* ================================================================== */
    /* Consulta                                                            */
    /* ================================================================== */

    /** GET api/envio-masivo/resumen */
    public function resumen(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $resumen = [];
        foreach (ConsentimientoPublicoController::TIPOS as $tipo) {
            $resumen[$tipo] = $this->conteo($institucionId, $tipo);
        }

        $config = CorreoConfiguracionController::configuracionDe($this->db, $institucionId);

        Response::exito([
            'tipos'   => $resumen,
            'maximo'  => self::MAX_POR_ENVIO,
            'correo'  => [
                // Sin SMTP activo el sistema recurre a mail(), que en hospedaje
                // compartido suele terminar en la carpeta de no deseados.
                // Las claves son las columnas de `correo_configuracion`.
                'smtp_activo' => !empty($config)
                                 && ($config['Activo'] ?? 'NO') === 'SI'
                                 && trim((string)($config['Servidor'] ?? '')) !== '',
                'remitente'   => (string)($config['RemitenteCorreo'] ?? ''),
            ],
        ]);
    }

    /**
     * GET api/envio-masivo/destinatarios?tipo=&q=&solo_con_correo=&pagina=
     *
     * Alimenta la subventana de selección individual.
     */
    public function destinatarios(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $tipo = $this->tipo();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        [$sql, $where, $params] = $this->consultaDestinatarios($institucionId, $tipo);

        $where   .= ' AND (p.Nombres LIKE ? OR p.Apellidos LIKE ? OR p.Identificacion LIKE ?)';
        $params   = array_merge($params, [$buscar, $buscar, $buscar]);

        if ($this->peticion->paramEntero('solo_con_correo') === 1) {
            $where .= ' AND ' . $this->condicionCorreo($tipo);
        }

        $total = $this->contar("SELECT COUNT(*) total $sql $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(10);

        $datos = $this->consultar(
            "SELECT p.PersonaId, p.Identificacion, p.Nombres, p.Apellidos,
                    " . $this->columnaCorreo($tipo) . " AS Destinatario,
                    " . ($tipo === 'ESTUDIANTE'
                            ? "TRIM(CONCAT(COALESCE(r.Apellidos,''),' ',COALESCE(r.Nombres,''))) "
                            : "''") . " AS Representante
             $sql $where
             ORDER BY p.Apellidos, p.Nombres
             LIMIT $offset, $porPagina",
            $params
        );

        foreach ($datos as &$fila) {
            $fila['TieneCorreo'] = $this->correoValido((string)$fila['Destinatario']);
        }
        unset($fila);

        Response::lista($datos, $total, $pagina, $porPagina, [
            'tipo'      => $tipo,
            'documento' => ConsentimientoPublicoController::DOCUMENTO[$tipo],
        ]);
    }

    /* ================================================================== */
    /* Envío                                                               */
    /* ================================================================== */

    /**
     * POST api/envio-masivo/enviar
     * Cuerpo: { tipo, alcance: 'todos'|'seleccion', personas: [PersonaId, ...] }
     */
    public function enviar(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();
        $tipo = $this->tipo();

        $alcance = strtolower($this->peticion->texto('alcance', 'todos'));
        if (!in_array($alcance, ['todos', 'seleccion'], true)) {
            Response::validacion(['Indique si el envío es a todos o a una selección.']);
        }

        $seleccion = [];
        if ($alcance === 'seleccion') {
            foreach ((array)$this->peticion->dato('personas', []) as $valor) {
                $id = (int)$valor;
                if ($id > 0) {
                    $seleccion[$id] = $id;
                }
            }
            if (!$seleccion) {
                Response::validacion(['Seleccione al menos un destinatario.']);
            }
        }

        $institucion = $this->consultarUna(
            'SELECT id, nombre FROM institucion_educativa WHERE id = ?',
            [$institucionId]
        );
        $disclaimer = DisclaimersController::vigente($this->db, $institucionId, $tipo);

        $destinatarios = $this->cargarDestinatarios($institucionId, $tipo, $seleccion);

        if (!$destinatarios) {
            Response::validacion(['No hay destinatarios que coincidan con lo seleccionado.']);
        }
        if (count($destinatarios) > self::MAX_POR_ENVIO) {
            Response::validacion([
                'El envío supera el máximo de ' . self::MAX_POR_ENVIO . ' correos por vez. '
                . 'Divida la selección en tandas más pequeñas.',
            ]);
        }

        /* --- Envío ------------------------------------------------------
           Se abre una sola conexión SMTP para toda la tanda y se cierra al
           final: abrir una por correo sería mucho más lento y varios
           proveedores lo interpretan como abuso. */
        $correo = Correo::desdeConfiguracion(
            CorreoConfiguracionController::configuracionDe($this->db, $institucionId)
        );

        $enviados = 0;
        $fallidos = [];
        $sinCorreo = [];

        foreach ($destinatarios as $fila) {
            $destino = trim((string)$fila['Destinatario']);

            if (!$this->correoValido($destino)) {
                $sinCorreo[] = $this->resumenPersona($fila);
                continue;
            }

            $titular = trim((string)$fila['Apellidos'] . ' ' . (string)$fila['Nombres']);

            /* El correo del estudiante es el de su representante: el destinatario
               que se saluda en la cabecera es él, no el representado. */
            $nombreDestino = $tipo === 'ESTUDIANTE' && trim((string)($fila['Representante'] ?? '')) !== ''
                ? trim((string)$fila['Representante'])
                : $titular;

            $html = PlantillaCorreo::invitacionConsentimiento([
                'tipo'             => $tipo,
                'titular'          => $titular,
                'identificacion'   => (string)$fila['Identificacion'],
                'documento'        => ConsentimientoPublicoController::DOCUMENTO[$tipo],
                'es_representante' => $tipo === 'ESTUDIANTE',
                'representante'    => (string)($fila['Representante'] ?? ''),
                'institucion'      => (string)($institucion['nombre'] ?? ''),
                'enlace'           => $this->enlaceVerificado($tipo, $institucionId, (string)$fila['Identificacion']),
                'version'          => $disclaimer['Version'] ?? '',
                'fecha'            => date('d/m/Y'),
            ]);

            $ok = $correo->enviar(
                $destino,
                $nombreDestino,
                'Consentimiento para el tratamiento de sus datos personales',
                $html
            );

            if ($ok) {
                $enviados++;
            } else {
                $fallidos[] = $this->resumenPersona($fila) + ['detalle' => $correo->ultimoError()];
            }
        }

        $correo->cerrar();

        $this->auditarEnvio($institucionId, $tipo, $alcance, count($destinatarios), $enviados, $fallidos, $sinCorreo);

        Response::exito([
            'tipo'        => $tipo,
            'alcance'     => $alcance,
            'total'       => count($destinatarios),
            'enviados'    => $enviados,
            'sin_correo'  => $sinCorreo,
            'fallidos'    => $fallidos,
            'via'         => $correo->usaSmtp() ? 'SMTP' : 'mail() de PHP',
            'mensaje'     => $enviados > 0
                ? 'Se enviaron ' . $enviados . ' invitación(es).'
                : 'No se pudo enviar ninguna invitación.',
        ]);
    }

    /* ================================================================== */
    /* Consultas de apoyo                                                  */
    /* ================================================================== */

    /**
     * Base común de las consultas de destinatarios.
     *
     * @return array{0:string,1:string,2:array} [from+joins, where, parámetros]
     */
    private function consultaDestinatarios(int $institucionId, string $tipo): array
    {
        if ($tipo === 'ESTUDIANTE') {
            return [
                'FROM estudiante v
           INNER JOIN persona p ON p.PersonaId = v.PersonaId
            LEFT JOIN persona r ON r.PersonaId = v.RepresentanteId',
                "WHERE v.InstitucionEducativaId = ? AND v.Estado = 'ACTIVO'",
                [$institucionId],
            ];
        }

        $tabla = $tipo === 'EMPLEADO' ? 'empleado' : 'proveedor';

        return [
            "FROM `$tabla` v
        INNER JOIN persona p ON p.PersonaId = v.PersonaId",
            "WHERE v.InstitucionEducativaId = ? AND v.Estado = 'ACTIVO'",
            [$institucionId],
        ];
    }

    /** Columna de la que sale el correo al que se escribe, según el tipo. */
    private function columnaCorreo(string $tipo): string
    {
        return $tipo === 'ESTUDIANTE' ? 'r.Email' : 'p.Email';
    }

    /** Condición SQL de «tiene un correo al que escribirle». */
    private function condicionCorreo(string $tipo): string
    {
        $columna = $this->columnaCorreo($tipo);

        return "TRIM(COALESCE($columna, '')) <> '' AND $columna LIKE '%@%'";
    }

    /** Cuántos hay de este tipo y a cuántos se les puede escribir. */
    private function conteo(int $institucionId, string $tipo): array
    {
        [$sql, $where, $params] = $this->consultaDestinatarios($institucionId, $tipo);

        $total = $this->contar("SELECT COUNT(*) total $sql $where", $params);
        $con   = $this->contar(
            "SELECT COUNT(*) total $sql $where AND " . $this->condicionCorreo($tipo),
            $params
        );

        return [
            'total'      => $total,
            'con_correo' => $con,
            'sin_correo' => $total - $con,
            'escribe_a'  => $tipo === 'ESTUDIANTE' ? 'representante' : 'titular',
        ];
    }

    /**
     * Filas a las que se enviará. Con una selección concreta, se acota a ella;
     * en cualquier caso se limita a la institución y al tipo indicados, de modo
     * que un identificador ajeno no sirve de nada.
     *
     * @param array<int,int> $seleccion PersonaId elegidos; vacío = todos
     */
    private function cargarDestinatarios(int $institucionId, string $tipo, array $seleccion): array
    {
        [$sql, $where, $params] = $this->consultaDestinatarios($institucionId, $tipo);

        if ($seleccion) {
            $marcas  = implode(',', array_fill(0, count($seleccion), '?'));
            $where  .= " AND p.PersonaId IN ($marcas)";
            $params  = array_merge($params, array_values($seleccion));
        }

        return $this->consultar(
            "SELECT p.PersonaId, p.Identificacion, p.Nombres, p.Apellidos,
                    " . $this->columnaCorreo($tipo) . " AS Destinatario,
                    " . ($tipo === 'ESTUDIANTE'
                            ? "TRIM(CONCAT(COALESCE(r.Apellidos,''),' ',COALESCE(r.Nombres,''))) "
                            : "''") . " AS Representante
             $sql $where
             ORDER BY p.Apellidos, p.Nombres",
            $params
        );
    }

    /* ================================================================== */
    /* Utilidades                                                          */
    /* ================================================================== */

    /**
     * Enlace de consentimiento CON VERIFICACIÓN, con el documento precargado.
     *
     * La dirección pública se deduce de la propia petición, igual que hace la
     * pantalla de enlaces: así funciona en cualquier hospedaje sin configurar
     * nada aparte.
     */
    private function enlaceVerificado(string $tipo, int $institucionId, string $identificacion): string
    {
        $esHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        // /api/... -> raíz del sitio
        $script  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/api/index.php');
        $carpeta = rtrim(preg_replace('#/api(/.*)?$#', '', $script) ?? '', '/');

        return ($esHttps ? 'https' : 'http') . '://' . $host . $carpeta
            . '/consentimiento_verificado.php'
            . '?tipo=' . urlencode(mb_strtolower($tipo))
            . '&inst=' . $institucionId
            . '&doc='  . urlencode($identificacion);
    }

    private function correoValido(string $correo): bool
    {
        return $correo !== '' && filter_var($correo, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function resumenPersona(array $fila): array
    {
        return [
            'PersonaId'      => (int)$fila['PersonaId'],
            'Identificacion' => (string)$fila['Identificacion'],
            'Nombre'         => trim((string)$fila['Apellidos'] . ' ' . (string)$fila['Nombres']),
        ];
    }

    private function tipo(): string
    {
        $tipo = strtoupper(trim(
            $this->peticion->texto('tipo') ?: $this->peticion->paramTexto('tipo')
        ));

        if (!in_array($tipo, ConsentimientoPublicoController::TIPOS, true)) {
            Response::validacion([
                'Indique a quién va dirigido el envío: '
                . implode(', ', ConsentimientoPublicoController::TIPOS) . '.',
            ]);
        }

        return $tipo;
    }

    /**
     * Una anotación por envío en la bitácora, con el balance de la tanda.
     *
     * El balance son conteos, no direcciones ni nombres, así que cabe en el
     * propio nombre del campo: la bitácora ya no guarda valores.
     */
    private function auditarEnvio(
        int $institucionId,
        string $tipo,
        string $alcance,
        int $total,
        int $enviados,
        array $fallidos,
        array $sinCorreo
    ): void {
        if ($this->usuario === null) {
            return;
        }

        Auditoria::cambioLista(
            $this->usuario,
            'envio_masivo',
            $institucionId,
            'Invitaciones a ' . $tipo . ': ' . $enviados . ' de ' . $total,
            'PENDIENTE',
            'ENVIADO'
        );
    }
}
