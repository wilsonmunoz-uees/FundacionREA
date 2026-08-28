<?php
/**
 * api/controllers/PreCargaController.php
 * -----------------------------------------------------------------------------
 * PreCarga Inicial: poblar de una sola vez el padrón de una institución a
 * partir de la plantilla Excel.
 *
 * Es una operación reservada al rol SuperAdmin (clave `precarga` en
 * includes/accesos.php) y se ejecuta en dos tiempos:
 *
 *   1. POST api/precarga/previsualizar
 *      Lee y valida el archivo SIN tocar la base. Devuelve cuántos registros
 *      trae cada hoja, los errores encontrados y el detalle de lo que se
 *      borraría si se confirma.
 *
 *   2. POST api/precarga/procesar
 *      Repite la validación y, solo si el archivo está limpio y la pantalla
 *      envía la confirmación explícita, ENCERA los datos de la institución
 *      activa y carga los del archivo. Todo dentro de una transacción: o entra
 *      completo, o no entra nada.
 *
 * QUÉ SE BORRA (siempre acotado a la institución del token):
 *      consentimientohistorial, consentimientodato, consentimiento,
 *      estudiante, empleado, proveedor
 *      y todas las personas del padrón de esa institución.
 *
 * QUÉ NO SE TOCA:
 *      usuarios, roles, permisos y sus asignaciones;
 *      catálogos de finalidades y tipos de dato;
 *      disclaimers, configuración de correo e instituciones;
 *      y las personas que tienen cuenta de usuario, porque su cuenta depende
 *      de ellas.
 *
 * El padrón es por institución, así que nada de esto alcanza a las demás
 * instituciones de la red: cada una tiene sus propias fichas.
 * -----------------------------------------------------------------------------
 */

final class PreCargaController extends Controller
{
    /** Clave en includes/accesos.php. Solo la abre el rol SuperAdmin. */
    private const MODULO = 'precarga';

    /** Tope del archivo cargado: 8 MB ya alcanzan para varios miles de filas. */
    private const MAX_BYTES = 8 * 1024 * 1024;

    /** Las filas de ejemplo de la plantilla empiezan así y se ignoran solas. */
    private const MARCA_EJEMPLO = '(EJEMPLO';

    /** Hojas esperadas y su papel dentro de la carga. */
    private const HOJAS = [
        'Empleados'      => 'empleado',
        'Estudiantes'    => 'estudiante',
        'Representantes' => 'representante',
        'Proveedores'    => 'proveedor',
    ];

    private const TIPOS_ID   = ['CEDULA', 'RUC', 'PASAPORTE'];

    /** Toda carga inicial entra activa: la plantilla ya no pregunta el estado. */
    private const ESTADO_INICIAL = 'ACTIVO';

    /* ================================================================== */
    /* Rutas                                                               */
    /* ================================================================== */

    /** POST api/precarga/previsualizar */
    public function previsualizar(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $lectura = $this->leerArchivo();
        $resumen = $this->analizar($lectura['datos']);

        Response::exito([
            'archivo'       => $lectura['nombre'],
            'resumen'       => $resumen['conteos'],
            'errores'       => $resumen['errores'],
            'advertencias'  => $resumen['advertencias'],
            'puede_procesar'=> $resumen['errores'] === [] && $resumen['conteos']['total'] > 0,
            'se_eliminara'  => $this->inventarioActual($institucionId),
        ]);
    }

    /** POST api/precarga/procesar */
    public function procesar(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        // La pantalla debe enviar la confirmación explícita del usuario.
        if ($this->peticion->texto('confirmacion') !== 'ENCERAR Y CARGAR') {
            Response::validacion(['Debe confirmar la operación antes de procesarla.']);
        }

        $lectura = $this->leerArchivo();
        $resumen = $this->analizar($lectura['datos']);

        if ($resumen['errores']) {
            Response::validacion($resumen['errores']);
        }
        if ($resumen['conteos']['total'] === 0) {
            Response::validacion(['El archivo no contiene ninguna fila para cargar.']);
        }

        $inventarioPrevio = $this->inventarioActual($institucionId);

        $this->db->beginTransaction();
        try {
            $borrado  = $this->encerar($institucionId);
            $cargado  = $this->cargar($institucionId, $resumen['filas']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('[API] PreCarga: ' . $e->getMessage());
            Response::error('La carga se canceló y no se modificó ningún dato: ' . $e->getMessage(), 500);
            return;
        }

        // La bitácora deja constancia de la operación completa, no fila a fila.
        $this->auditarPreCarga($institucionId, $lectura['nombre'], $inventarioPrevio, $borrado, $cargado);

        Response::exito([
            'mensaje'   => 'PreCarga ejecutada correctamente.',
            'archivo'   => $lectura['nombre'],
            'eliminado' => $borrado,
            'cargado'   => $cargado,
        ]);
    }

    /* ================================================================== */
    /* Lectura del archivo                                                 */
    /* ================================================================== */

    /**
     * El archivo llega dentro del JSON, codificado en base64: así viaja por el
     * mismo cliente HTTP que usa el resto del sitio, sin multipart.
     *
     * @return array{nombre:string, datos:array<string, array>}
     */
    private function leerArchivo(): array
    {
        $nombre = trim($this->peticion->texto('nombre_archivo')) ?: 'plantilla.xlsx';
        $base64 = $this->peticion->texto('archivo_base64');

        if ($base64 === '') {
            Response::validacion(['Debe seleccionar el archivo Excel de la plantilla.']);
        }

        // Algunos navegadores anteponen el encabezado data:...;base64,
        if (str_contains($base64, ',') && str_starts_with($base64, 'data:')) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        $binario = base64_decode($base64, true);
        if ($binario === false || $binario === '') {
            Response::validacion(['El archivo cargado llegó dañado. Vuelva a intentarlo.']);
        }
        if (strlen($binario) > self::MAX_BYTES) {
            Response::validacion(['El archivo supera el tamaño máximo permitido (8 MB).']);
        }
        // Todo .xlsx es un ZIP y empieza con la firma PK
        if (substr($binario, 0, 2) !== 'PK') {
            Response::validacion(['El archivo no es un Excel (.xlsx). Vuelva a guardarlo como «Libro de Excel (*.xlsx)».']);
        }

        $temporal = tempnam(sys_get_temp_dir(), 'precarga_');
        if ($temporal === false || file_put_contents($temporal, $binario) === false) {
            Response::error('No se pudo preparar el archivo para su lectura.', 500);
        }

        try {
            $libro  = LectorXlsx::abrir($temporal);
            $faltan = [];
            $datos  = [];

            foreach (array_keys(self::HOJAS) as $hoja) {
                if (!$libro->tieneHoja($hoja)) {
                    $faltan[] = $hoja;
                    continue;
                }
                $datos[$hoja] = $libro->filas($hoja);
            }
            $libro->cerrar();

            if ($faltan) {
                Response::validacion([
                    'El archivo no tiene las hojas esperadas. Faltan: ' . implode(', ', $faltan)
                    . '. Use la plantilla que descarga esta misma pantalla.',
                ]);
            }
        } catch (RuntimeException $e) {
            @unlink($temporal);
            Response::validacion([$e->getMessage()]);
            return ['nombre' => $nombre, 'datos' => []];   // inalcanzable
        }

        @unlink($temporal);

        return ['nombre' => $nombre, 'datos' => $datos];
    }

    /* ================================================================== */
    /* Validación                                                          */
    /* ================================================================== */

    /**
     * Revisa las cuatro hojas y devuelve las filas ya normalizadas junto con
     * los errores y advertencias encontrados.
     *
     * @param array<string, array> $hojas
     * @return array{filas:array, conteos:array, errores:array, advertencias:array}
     */
    private function analizar(array $hojas): array
    {
        $errores      = [];
        $advertencias = [];

        /** Quienes constan en dos hojas con nombre y razón social distintos. */
        $dobleRol = [];

        /** @var array<string, array> identificación => persona unificada */
        $personas = [];
        $filas = [
            'empleados'      => [],
            'estudiantes'    => [],
            'representantes' => [],
            'proveedores'    => [],
        ];

        /* ---------------- Representantes (primero: los usan los estudiantes) --- */
        foreach ($hojas['Representantes'] as $fila) {
            $r = $this->normalizarPersona($fila, 'Representantes', $errores, true);
            if ($r === null) {
                continue;
            }
            $this->acumularPersona($personas, $r, 'Representantes', $errores, $dobleRol);
            $filas['representantes'][$r['identificacion']] = $r;
        }

        /* ---------------- Empleados ------------------------------------------- */
        foreach ($hojas['Empleados'] as $fila) {
            $r = $this->normalizarPersona($fila, 'Empleados', $errores, true);
            if ($r === null) {
                continue;
            }
            if (isset($filas['empleados'][$r['identificacion']])) {
                $errores[] = $this->ubicacion('Empleados', $r['_fila'])
                    . 'la identificación ' . $r['identificacion'] . ' aparece dos veces en la hoja.';
                continue;
            }
            $this->acumularPersona($personas, $r, 'Empleados', $errores, $dobleRol);
            $filas['empleados'][$r['identificacion']] = $r;
        }

        /* ---------------- Estudiantes ------------------------------------------ */
        $codigos = [];
        foreach ($hojas['Estudiantes'] as $fila) {
            $r = $this->normalizarPersona($fila, 'Estudiantes', $errores, true);
            if ($r === null) {
                continue;
            }
            $donde = $this->ubicacion('Estudiantes', $r['_fila']);

            if (isset($filas['estudiantes'][$r['identificacion']])) {
                $errores[] = $donde . 'la identificación ' . $r['identificacion'] . ' aparece dos veces en la hoja.';
                continue;
            }

            $r['codigo']       = $this->campo($fila, 'codigo de estudiante', 20);
            $r['rep_id']       = $this->soloAlfanumerico($this->campo($fila, 'identificacion del representante', 50));
            $r['rep_relacion'] = mb_strtoupper($this->campo($fila, 'relacion del representante', 30));

            if ($r['codigo'] !== '') {
                if (isset($codigos[$r['codigo']])) {
                    $errores[] = $donde . 'el código de estudiante ' . $r['codigo'] . ' está repetido en el archivo.';
                    continue;
                }
                $codigos[$r['codigo']] = true;
            }

            if ($r['rep_id'] === '') {
                $errores[] = $donde . 'falta la identificación del representante.';
                continue;
            }
            if ($r['rep_id'] === $r['identificacion']) {
                $errores[] = $donde . 'el estudiante no puede ser su propio representante.';
                continue;
            }
            if (!isset($filas['representantes'][$r['rep_id']])) {
                $errores[] = $donde . 'el representante ' . $r['rep_id'] . ' no está en la hoja Representantes.';
                continue;
            }
            if ($r['rep_relacion'] !== '' && !in_array($r['rep_relacion'], $this->relaciones(), true)) {
                $errores[] = $donde . 'la relación «' . $r['rep_relacion'] . '» no es válida. Use: '
                    . implode(', ', $this->relaciones()) . '.';
                continue;
            }
            if ($r['rep_relacion'] === '') {
                $r['rep_relacion'] = null;
                $advertencias[] = $donde . 'no se indicó la relación del representante; quedará en blanco.';
            }

            $this->acumularPersona($personas, $r, 'Estudiantes', $errores, $dobleRol);
            $filas['estudiantes'][$r['identificacion']] = $r;
        }

        /* ---------------- Proveedores ------------------------------------------ */
        // La plantilla no pide nombres del proveedor: la persona de contacto se
        // deriva de la razón social, que sí es obligatoria.
        foreach ($hojas['Proveedores'] as $fila) {
            $r = $this->normalizarPersona($fila, 'Proveedores', $errores, false);
            if ($r === null) {
                continue;
            }
            $donde = $this->ubicacion('Proveedores', $r['_fila']);

            $r['razon_social'] = $this->campo($fila, 'razon social', 150);
            if ($r['razon_social'] === '') {
                $errores[] = $donde . 'falta la razón social.';
                continue;
            }
            if (isset($filas['proveedores'][$r['identificacion']])) {
                $errores[] = $donde . 'la identificación ' . $r['identificacion'] . ' aparece dos veces en la hoja.';
                continue;
            }

            // Nombres y apellidos de la persona: se toman de la razón social
            if ($r['nombres'] === '' && $r['apellidos'] === '') {
                $r['nombres']   = mb_substr($r['razon_social'], 0, 100);
                $r['apellidos'] = '';
            }

            $this->acumularPersona($personas, $r, 'Proveedores', $errores, $dobleRol);
            $filas['proveedores'][$r['identificacion']] = $r;
        }

        /* ---------------- Avisos de contexto ------------------------------------ */
        /* Empleados que además son proveedores bajo otra razón social. Es una
           situación normal y no impide cargar; se informa una sola vez, con un
           ejemplo, para que quede constancia de que se unificaron. */
        if ($dobleRol) {
            $ejemplo = reset($dobleRol);
            $advertencias[] = count($dobleRol) . ' persona(s) constan en dos hojas con nombre y razón '
                . 'social distintos; se cargan como una sola. Por ejemplo, ' . $ejemplo['identificacion']
                . ': «' . $ejemplo['persona'] . '» y «' . $ejemplo['razon'] . '».';
        }

        /* Plantilla anterior: traía una columna Estado que ya no se usa. Se avisa
           una sola vez por hoja para que nadie dé por hecho que se respetó. */
        foreach (array_keys(self::HOJAS) as $hoja) {
            foreach ($hojas[$hoja] as $fila) {
                $marcado = mb_strtoupper(trim((string)($fila['estado'] ?? '')));
                if ($marcado !== '' && $marcado !== 'ACTIVO') {
                    $advertencias[] = 'Hoja ' . $hoja . ': la columna «Estado» ya no se usa. '
                        . 'Todo lo que se carga entra como ACTIVO; cámbielo después desde su pantalla.';
                    break;
                }
            }
        }

        foreach ($filas['representantes'] as $identificacion => $rep) {
            $usado = false;
            foreach ($filas['estudiantes'] as $est) {
                if ($est['rep_id'] === $identificacion) {
                    $usado = true;
                    break;
                }
            }
            if (!$usado) {
                $advertencias[] = 'El representante ' . $identificacion
                    . ' no está asignado a ningún estudiante; se creará solo como persona.';
            }
        }

        $conteos = [
            'empleados'      => count($filas['empleados']),
            'estudiantes'    => count($filas['estudiantes']),
            'representantes' => count($filas['representantes']),
            'proveedores'    => count($filas['proveedores']),
            'personas'       => count($personas),
        ];
        $conteos['total'] = $conteos['empleados'] + $conteos['estudiantes']
                          + $conteos['representantes'] + $conteos['proveedores'];

        // La lista de errores se recorta para que la pantalla siga siendo legible.
        if (count($errores) > 100) {
            $sobrantes = count($errores) - 100;
            $errores   = array_slice($errores, 0, 100);
            $errores[] = '… y ' . $sobrantes . ' error(es) más. Corrija los anteriores y vuelva a validar.';
        }

        $filas['personas'] = $personas;

        return [
            'filas'        => $filas,
            'conteos'      => $conteos,
            'errores'      => $errores,
            'advertencias' => array_slice($advertencias, 0, 50),
        ];
    }

    /**
     * Valida y normaliza los campos comunes a las cuatro hojas.
     * Devuelve null si la fila es de ejemplo, está vacía o no es utilizable.
     */
    private function normalizarPersona(array $fila, string $hoja, array &$errores, bool $exigeNombre): ?array
    {
        $identificacion = $this->campo($fila, 'identificacion', 50);

        // Fila de ejemplo de la plantilla: se ignora en silencio
        if (str_starts_with(mb_strtoupper($identificacion), self::MARCA_EJEMPLO)) {
            return null;
        }

        $numeroFila = (int)($fila['_fila'] ?? 0);
        $donde      = $this->ubicacion($hoja, $numeroFila);

        $nombres   = $this->campo($fila, 'nombres', 100);
        $apellidos = $this->campo($fila, 'apellidos', 100);
        $email     = $this->campo($fila, 'email', 150);
        $telefono  = $this->campo($fila, 'telefono', 20);
        $razon     = $this->campo($fila, 'razon social', 150);

        // Fila completamente vacía: no es un error, simplemente no hay nada
        if ($identificacion === '' && $nombres === '' && $apellidos === ''
            && $email === '' && $telefono === '' && $razon === '') {
            return null;
        }

        $identificacion = $this->soloAlfanumerico($identificacion);
        if ($identificacion === '') {
            $errores[] = $donde . 'falta la identificación.';
            return null;
        }
        if (mb_strlen($identificacion) > 50) {
            $errores[] = $donde . 'la identificación es demasiado larga.';
            return null;
        }

        if ($exigeNombre && ($nombres === '' || $apellidos === '')) {
            $errores[] = $donde . 'faltan los nombres o los apellidos.';
            return null;
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = $donde . 'el correo «' . $email . '» no es válido.';
            return null;
        }

        $tipo = mb_strtoupper($this->campo($fila, 'tipo de identificacion', 20));
        if ($tipo === '') {
            // Se deduce por la longitud: 13 dígitos es RUC en Ecuador
            $tipo = (strlen($identificacion) === 13 && ctype_digit($identificacion)) ? 'RUC' : 'CEDULA';
        }
        if (!in_array($tipo, self::TIPOS_ID, true)) {
            $errores[] = $donde . 'el tipo de identificación «' . $tipo . '» no es válido. Use: '
                . implode(', ', self::TIPOS_ID) . '.';
            return null;
        }

        /* El estado no se pide en la plantilla: una carga inicial parte de cero y
           todo lo que entra queda ACTIVO. Si el archivo trae una columna Estado
           —de una plantilla anterior— sencillamente se ignora; darla de baja
           desde aquí obligaría a revisar cada fila para entender por qué una
           persona recién cargada no aparece en las pantallas. */
        $estado = self::ESTADO_INICIAL;

        return [
            '_fila'          => $numeroFila,
            '_hoja'          => $hoja,
            'identificacion' => $identificacion,
            'tipo'           => $tipo,
            'nombres'        => $nombres,
            'apellidos'      => $apellidos,
            'email'          => $email,
            'telefono'       => $telefono,
            'estado'         => $estado,
        ];
    }

    /**
     * Una misma persona puede estar en varias hojas (por ejemplo, empleado y
     * representante). Se unifica en un solo registro y se avisa si los datos
     * no coinciden entre hojas.
     *
     * @param array $dobleRol Se van anotando aquí quienes son empleado —o
     *                        representante, o estudiante— y además proveedor
     *                        bajo otro nombre. No es un error; ver abajo.
     */
    private function acumularPersona(
        array &$personas,
        array $fila,
        string $hoja,
        array &$errores,
        array &$dobleRol = []
    ): void {
        $clave = $fila['identificacion'];

        if (!isset($personas[$clave])) {
            $personas[$clave] = [
                'identificacion' => $clave,
                'tipo'           => $fila['tipo'],
                'nombres'        => $fila['nombres'],
                'apellidos'      => $fila['apellidos'],
                'email'          => $fila['email'],
                'telefono'       => $fila['telefono'],
                'estado'         => $fila['estado'],
                'hojas'          => [$hoja],
            ];
            return;
        }

        $previa = &$personas[$clave];
        $previa['hojas'][] = $hoja;

        // Se completan los huecos con lo que traiga la otra hoja
        foreach (['nombres', 'apellidos', 'email', 'telefono'] as $campo) {
            if ($previa[$campo] === '' && $fila[$campo] !== '') {
                $previa[$campo] = $fila[$campo];
            }
        }

        // Un nombre distinto para la misma cédula casi siempre es un error de captura
        $nombreA = trim($previa['nombres'] . ' ' . $previa['apellidos']);
        $nombreB = trim($fila['nombres'] . ' ' . $fila['apellidos']);

        if (self::mismoNombre($nombreA, $nombreB)) {
            return;
        }

        /* La hoja de Proveedores no tiene columnas de nombre: lo que se compara
           de ese lado es la RAZÓN SOCIAL, que a menudo no se parece en nada al
           nombre de la persona —un empleado que además presta servicios a través
           de su compañía—. Comparar un nombre contra una razón social no dice
           nada, así que aquí no hay error que reportar: la persona es una sola,
           su ficha conserva el nombre bien separado que trae su hoja propia, y
           la razón social se guarda donde corresponde, en proveedor.RazonSocial.

           Queda anotado para informarlo de una sola vez al final, no fila por
           fila: en un archivo grande son cientos y taparían lo que sí importa. */
        if ($hoja === 'Proveedores' || $previa['hojas'][0] === 'Proveedores') {
            $dobleRol[$clave] = [
                'identificacion' => $clave,
                'persona'        => $hoja === 'Proveedores' ? $nombreA : $nombreB,
                'razon'          => $hoja === 'Proveedores' ? $nombreB : $nombreA,
            ];
            return;
        }

        /* Entre hojas que sí traen nombres de persona —Empleados, Estudiantes,
           Representantes— dos nombres sin relación bajo la misma cédula siguen
           siendo un error: casi siempre es un dígito mal tecleado en el número. */
        $errores[] = $this->ubicacion($hoja, $fila['_fila'])
            . 'la identificación ' . $clave . ' ya aparece en '
            . $previa['hojas'][0] . ' con otro nombre: «' . $nombreA . '» frente a «'
            . $nombreB . '». Debe ser la misma persona.';
    }

    /**
     * ¿Los dos textos nombran a la misma persona?
     *
     * Se comparan las PALABRAS, no el orden en que están escritas. Media hoja de
     * cálculo del país escribe «apellidos nombres» y la otra media «nombres
     * apellidos», y en la hoja de Proveedores el nombre sale de la razón social,
     * que casi siempre viene con los apellidos primero:
     *
     *     Empleados   NELLY PATRICIA | BOURNE SOLIS
     *     Proveedores BOURNE SOLIS NELLY PATRICIA   (razón social)
     *
     * Es la misma señora, y exigir el mismo orden la rechazaba.
     *
     * También se acepta que un nombre sea más completo que el otro —«JUAN PEREZ»
     * frente a «JUAN PEREZ GOMEZ»—, que es lo que ocurre cuando en una hoja se
     * omitió el segundo apellido. Se exigen al menos dos palabras en común para
     * que un nombre de una sola palabra no coincida con cualquier cosa.
     *
     * Lo que sí se sigue rechazando es lo que de verdad importa: dos nombres sin
     * relación bajo la misma cédula, que casi siempre es un error de digitación
     * en el número.
     */
    private static function mismoNombre(string $a, string $b): bool
    {
        $palabras = static function (string $texto): array {
            $texto = LectorXlsx::normalizar($texto);
            if ($texto === '') {
                return [];
            }
            // Se descartan las partículas y las formas societarias: no distinguen
            // a nadie y aparecen en unas hojas sí y en otras no.
            $ruido = ['de', 'del', 'la', 'las', 'los', 'y', 'sa', 's.a', 's.a.',
                      'cia', 'ltda', 'cia.', 'ltda.', 'srl', 'ep', 'eirl'];

            $partes = array_filter(
                preg_split('/[\s,.]+/u', $texto) ?: [],
                static fn(string $p): bool => $p !== '' && !in_array($p, $ruido, true)
            );

            return array_values(array_unique($partes));
        };

        $unos = $palabras($a);
        $otros = $palabras($b);

        // Si de alguno de los dos lados no hay nombre, no hay nada que comparar
        if (!$unos || !$otros) {
            return true;
        }

        $comunes = array_intersect($unos, $otros);

        // El más corto debe estar contenido en el más largo
        $menor = min(count($unos), count($otros));

        return count($comunes) === $menor && $menor >= min(2, max(count($unos), count($otros)));
    }

    /* ================================================================== */
    /* Inventario, encerado y carga                                        */
    /* ================================================================== */

    /** Lo que hoy tiene la institución y que la carga eliminaría. */
    private function inventarioActual(int $institucionId): array
    {
        $contar = fn(string $tabla): int => $this->contar(
            "SELECT COUNT(*) total FROM `$tabla` WHERE InstitucionEducativaId = ?",
            [$institucionId]
        );

        return [
            'empleados'       => $contar('empleado'),
            'estudiantes'     => $contar('estudiante'),
            'proveedores'     => $contar('proveedor'),
            'consentimientos' => $contar('consentimiento'),
            'historial'       => $contar('consentimientohistorial'),
            // Personas del padrón que se borrarían con la carga
            'personas'        => $this->contar(
                'SELECT COUNT(*) total FROM persona p
                  WHERE ' . $this->condicionPersonaBorrable($institucionId),
                []
            ),
            // Personas protegidas: tienen cuenta de usuario y no se tocan
            'personas_con_usuario' => $this->contar(
                'SELECT COUNT(*) total
                   FROM persona p
                  WHERE p.InstitucionEducativaId = ?
                    AND EXISTS (SELECT 1 FROM usuario u WHERE u.PersonaId = p.PersonaId)',
                [$institucionId]
            ),
            // Personas que otra institución todavía usa: tampoco se tocan
            'personas_en_otra_institucion' => $this->contar(
                'SELECT COUNT(*) total
                   FROM persona p
                  WHERE p.InstitucionEducativaId = ?
                    AND (' . self::CONDICION_EN_OTRA_INSTITUCION . ')',
                [$institucionId]
            ),
        ];
    }

    /**
     * Condición SQL de «persona que puede borrarse».
     *
     * Se conservan dos clases de personas de esta institución:
     *
     *   1. Las que tienen CUENTA DE USUARIO: su cuenta depende de ellas y
     *      borrarlas dejaría a alguien sin acceso.
     *
     *   2. Las que TODAVÍA ESTÁN EN USO POR OTRA INSTITUCIÓN. En teoría no
     *      debería haberlas —desde que el padrón es por institución cada una
     *      tiene su propia ficha—, pero en una base que viene de la versión
     *      anterior sí las hay: cuando las personas eran globales, una misma
     *      ficha podía estar vinculada a varias instituciones, y el script que
     *      repartió el padrón (05) asignó cada ficha a una sola sin mover los
     *      vínculos de las demás.
     *
     *      Intentar borrarlas rompe la carga entera con un error de integridad
     *      —`proveedor` la referencia con RESTRICT—, y en las tablas que
     *      declaran SET NULL sería aún peor: el vínculo de la otra institución
     *      se quedaría sin persona, en silencio. Se dejan donde están.
     *
     * La institución se interpola porque ya viene tipada como int desde el
     * token; no hay entrada del usuario en esta cadena.
     */
    private function condicionPersonaBorrable(int $institucionId): string
    {
        $i = (int)$institucionId;

        return "
              p.InstitucionEducativaId = $i
          AND NOT EXISTS (SELECT 1 FROM usuario u WHERE u.PersonaId = p.PersonaId)
          AND NOT (" . self::CONDICION_EN_OTRA_INSTITUCION . ")";
    }

    /**
     * ¿Esta persona («p») sigue vinculada a una institución distinta de la suya?
     *
     * Se comprueban las cuatro tablas que apuntan a `persona`, incluidas las dos
     * columnas de representante.
     */
    private const CONDICION_EN_OTRA_INSTITUCION = "
        EXISTS (SELECT 1 FROM proveedor  x WHERE x.PersonaId       = p.PersonaId AND x.InstitucionEducativaId <> p.InstitucionEducativaId)
     OR EXISTS (SELECT 1 FROM empleado   x WHERE x.PersonaId       = p.PersonaId AND x.InstitucionEducativaId <> p.InstitucionEducativaId)
     OR EXISTS (SELECT 1 FROM estudiante x WHERE x.PersonaId       = p.PersonaId AND x.InstitucionEducativaId <> p.InstitucionEducativaId)
     OR EXISTS (SELECT 1 FROM estudiante x WHERE x.RepresentanteId = p.PersonaId AND x.InstitucionEducativaId <> p.InstitucionEducativaId)
     OR EXISTS (SELECT 1 FROM consentimiento x WHERE x.PersonaId   = p.PersonaId AND x.InstitucionEducativaId <> p.InstitucionEducativaId)";

    /**
     * Borra los datos de la institución. El orden respeta las claves foráneas:
     * primero el detalle, después la cabecera, después los vínculos y por
     * último las personas que quedaron sueltas.
     */
    private function encerar(int $institucionId): array
    {
        // El padrón entero de la institución, salvo quienes tienen cuenta de
        // usuario. Se calcula antes de borrar para poder informar cuántas son.
        $personasBorrables = $this->columna(
            'SELECT p.PersonaId FROM persona p WHERE ' . $this->condicionPersonaBorrable($institucionId)
        );

        $borrado = [];

        $borrado['historial'] = $this->ejecutar(
            'DELETE FROM consentimientohistorial WHERE InstitucionEducativaId = ?', [$institucionId]
        );
        $borrado['consentimiento_datos'] = $this->ejecutar(
            'DELETE FROM consentimientodato WHERE InstitucionEducativaId = ?', [$institucionId]
        );
        $borrado['consentimientos'] = $this->ejecutar(
            'DELETE FROM consentimiento WHERE InstitucionEducativaId = ?', [$institucionId]
        );
        $borrado['estudiantes'] = $this->ejecutar(
            'DELETE FROM estudiante WHERE InstitucionEducativaId = ?', [$institucionId]
        );
        $borrado['empleados'] = $this->ejecutar(
            'DELETE FROM empleado WHERE InstitucionEducativaId = ?', [$institucionId]
        );
        $borrado['proveedores'] = $this->ejecutar(
            'DELETE FROM proveedor WHERE InstitucionEducativaId = ?', [$institucionId]
        );

        $borrado['personas'] = 0;
        foreach (array_chunk($personasBorrables, 400) as $lote) {
            $marcas = implode(',', array_fill(0, count($lote), '?'));
            $borrado['personas'] += $this->ejecutar(
                "DELETE FROM persona
                  WHERE PersonaId IN ($marcas)
                    AND InstitucionEducativaId = ?
                    AND NOT EXISTS (SELECT 1 FROM usuario u WHERE u.PersonaId = persona.PersonaId)",
                array_merge(array_map('intval', $lote), [$institucionId])
            );
        }

        return $borrado;
    }

    /**
     * Inserta las personas y sus vínculos. Se ejecuta con el padrón ya encerado,
     * pero una persona puede seguir existiendo porque tiene cuenta de usuario:
     * en ese caso se reutiliza su ficha en lugar de duplicarla.
     */
    private function cargar(int $institucionId, array $filas): array
    {
        /** @var array<string,int> identificación => PersonaId */
        $ids = [];

        foreach ($filas['personas'] as $identificacion => $persona) {
            $ids[$identificacion] = $this->personaId($institucionId, $persona);
        }

        $cargado = ['personas' => count($ids), 'empleados' => 0, 'estudiantes' => 0, 'proveedores' => 0];

        foreach ($filas['empleados'] as $identificacion => $fila) {
            $this->ejecutar(
                'INSERT INTO empleado (InstitucionEducativaId, PersonaId, Estado) VALUES (?,?,?)',
                [$institucionId, $ids[$identificacion], $fila['estado']]
            );
            $cargado['empleados']++;
        }

        foreach ($filas['estudiantes'] as $identificacion => $fila) {
            $this->ejecutar(
                'INSERT INTO estudiante
                    (InstitucionEducativaId, PersonaId, CodigoEstudiante,
                     RepresentanteId, RepresentanteRelacion, Estado)
                 VALUES (?,?,?,?,?,?)',
                [
                    $institucionId,
                    $ids[$identificacion],
                    $fila['codigo'] !== '' ? $fila['codigo'] : null,
                    $ids[$fila['rep_id']] ?? null,
                    $fila['rep_relacion'],
                    $fila['estado'],
                ]
            );
            $cargado['estudiantes']++;
        }

        foreach ($filas['proveedores'] as $identificacion => $fila) {
            $this->ejecutar(
                'INSERT INTO proveedor (InstitucionEducativaId, PersonaId, Ruc, RazonSocial, Estado)
                 VALUES (?,?,?,?,?)',
                [$institucionId, $ids[$identificacion], $identificacion, $fila['razon_social'], $fila['estado']]
            );
            $cargado['proveedores']++;
        }

        return $cargado;
    }

    /**
     * Crea la ficha de la persona o reutiliza la que ya exista con ese
     * documento. La regla vive en api/core/Padron.php, el único lugar del
     * sistema donde se escribe en `persona`.
     */
    private function personaId(int $institucionId, array $persona): int
    {
        return Padron::crearOActualizar($this->db, $institucionId, [
            'identificacion' => $persona['identificacion'],
            'tipo'           => $persona['tipo'],
            'nombres'        => $persona['nombres'],
            'apellidos'      => $persona['apellidos'],
            'email'          => $persona['email'],
            'telefono'       => $persona['telefono'],
            'estado'         => $persona['estado'],
        ]);
    }

    /**
     * Una sola anotación en la bitácora con el balance de la operación.
     *
     * El balance son conteos, no datos de nadie, así que cabe en el propio
     * nombre del campo: la bitácora ya no guarda valores. Se recorta a lo que
     * admite la columna (64 caracteres).
     */
    private function auditarPreCarga(int $institucionId, string $archivo, array $inventario, array $borrado, array $cargado): void
    {
        if ($this->usuario === null) {
            return;
        }

        $total = static fn(array $datos): int => (int)array_sum(array_map('intval', $datos));

        Auditoria::cambioLista(
            $this->usuario,
            'precarga_inicial',
            $institucionId,
            'PreCarga: elimina ' . $total($borrado) . ', carga ' . $total($cargado),
            'PENDIENTE',
            'EJECUTADA'
        );
    }

    /* ================================================================== */
    /* Utilidades                                                          */
    /* ================================================================== */

    /**
     * Relaciones aceptadas para el representante.
     *
     * Se preguntan a la base, no a una lista escrita aquí: si el enum de
     * `estudiante`.`RepresentanteRelacion` está un paso atrás —o un paso
     * adelante—, la PreCarga acepta exactamente lo que se puede guardar, en vez
     * de dejar pasar un valor que MySQL truncaría después.
     */
    private function relaciones(): array
    {
        return EstudiantesController::relacionesDisponibles($this->db);
    }

    private function campo(array $fila, string $clave, int $largo): string
    {
        $valor = trim((string)($fila[$clave] ?? ''));
        return $valor === '' ? '' : mb_substr($valor, 0, $largo);
    }

    private function soloAlfanumerico(string $valor): string
    {
        return (string)preg_replace('/[^0-9A-Za-z]/', '', $valor);
    }

    private function ubicacion(string $hoja, int $fila): string
    {
        return 'Hoja ' . $hoja . ', fila ' . $fila . ': ';
    }
}
