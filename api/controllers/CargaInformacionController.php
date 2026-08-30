<?php
/**
 * api/controllers/CargaInformacionController.php
 * -----------------------------------------------------------------------------
 * Carga de Información: incorporar al padrón de una institución lo que trae la
 * plantilla Excel, SIN borrar nada de lo que ya existe.
 *
 * Antes esta opción era una «PreCarga Inicial»: enceraba la institución y la
 * volvía a poblar. Solo servía para arrancar, y solo una vez. Ahora es una carga
 * DIFERENCIAL y puede repetirse tantas veces como haga falta —cada matrícula,
 * cada ingreso de personal—, porque compara contra lo que ya está:
 *
 *      · si la persona NO consta en la institución, se da de alta;
 *      · si YA consta, se actualiza con los datos que trae el archivo.
 *
 * Nada se elimina. Quien deje de aparecer en el archivo sigue donde estaba: dar
 * de baja es una decisión que se toma desde la pantalla del módulo, con nombre y
 * apellido, no como efecto colateral de una carga.
 *
 * Se ejecuta en dos tiempos:
 *
 *   1. POST api/carga-informacion/previsualizar
 *      Lee y valida el archivo SIN tocar la base. Devuelve cuántos registros
 *      trae cada hoja, los errores encontrados y —esto es lo que se mira— el
 *      desglose de cuántos serían altas y cuántos actualizaciones.
 *
 *   2. POST api/carga-informacion/procesar
 *      Repite la validación y, solo si el archivo está limpio y la pantalla
 *      envía la confirmación explícita, aplica los cambios. Todo dentro de una
 *      transacción: o entra completo, o no entra nada.
 *
 * QUÉ SE TOCA (siempre acotado a la institución del token):
 *      persona, empleado, estudiante, proveedor.
 *
 * QUÉ NO SE TOCA:
 *      consentimientos y su historial —siguen siendo válidos: la persona es la
 *      misma—; usuarios, roles, permisos y sus asignaciones; catálogos;
 *      disclaimers, configuración de correo e instituciones.
 *
 * El padrón es por institución, así que nada de esto alcanza a las demás
 * instituciones de la red: cada una tiene sus propias fichas.
 * -----------------------------------------------------------------------------
 */

final class CargaInformacionController extends Controller
{
    /** Clave en includes/accesos.php. Solo la abre el rol SuperAdmin. */
    private const MODULO = 'carga_informacion';

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

    /**
     * Todo lo que entra por la carga queda ACTIVO.
     *
     * La plantilla no pregunta el estado, y constar en el archivo es
     * precisamente la señal de que la persona está vigente: si alguien fue
     * inactivado y vuelve a venir en la carga, se reincorpora. Dar de baja
     * sigue siendo una decisión explícita desde la pantalla del módulo.
     */
    private const ESTADO_CARGA = 'ACTIVO';

    /* ================================================================== */
    /* Rutas                                                               */
    /* ================================================================== */

    /** POST api/carga-informacion/previsualizar */
    public function previsualizar(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        $lectura = $this->leerArchivo();
        $resumen = $this->analizar($institucionId, $lectura['datos']);

        Response::exito([
            'archivo'       => $lectura['nombre'],
            'resumen'       => $resumen['conteos'],
            'errores'       => $resumen['errores'],
            'advertencias'  => $resumen['advertencias'],
            'puede_procesar'=> $resumen['errores'] === [] && $resumen['conteos']['total'] > 0,
            'impacto'       => $resumen['impacto'],
            'inventario'    => $this->inventarioActual($institucionId),
        ]);
    }

    /** POST api/carga-informacion/procesar */
    public function procesar(array $ruta = []): void
    {
        $this->requiereAcceso(self::MODULO);
        $institucionId = $this->institucion();

        // La pantalla debe enviar la confirmación explícita del usuario.
        if ($this->peticion->texto('confirmacion') !== 'CARGAR INFORMACION') {
            Response::validacion(['Debe confirmar la operación antes de procesarla.']);
        }

        $lectura = $this->leerArchivo();
        $resumen = $this->analizar($institucionId, $lectura['datos']);

        if ($resumen['errores']) {
            Response::validacion($resumen['errores']);
        }
        if ($resumen['conteos']['total'] === 0) {
            Response::validacion(['El archivo no contiene ninguna fila para cargar.']);
        }

        $this->db->beginTransaction();
        try {
            $aplicado = $this->cargar($institucionId, $resumen['filas']);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            error_log('[API] CargaInformacion: ' . $e->getMessage());
            Response::error('La carga se canceló y no se modificó ningún dato: ' . $e->getMessage(), 500);
            return;
        }

        // La bitácora deja constancia de la operación completa, no fila a fila.
        $this->auditarCarga($institucionId, $aplicado);

        Response::exito([
            'mensaje'  => 'Carga de Información ejecutada correctamente.',
            'archivo'  => $lectura['nombre'],
            'aplicado' => $aplicado,
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

        $temporal = tempnam(sys_get_temp_dir(), 'carga_');
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
     * los errores, las advertencias y el desglose de altas y actualizaciones.
     *
     * @param array<string, array> $hojas
     * @return array{filas:array, conteos:array, errores:array, advertencias:array, impacto:array}
     */
    private function analizar(int $institucionId, array $hojas): array
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
                $codigos[$r['codigo']] = $r['identificacion'];
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
                $advertencias[] = $donde . 'no se indicó la relación del representante; se conservará la que ya conste.';
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
                        . 'Todo lo que se carga queda ACTIVO; cámbielo después desde su pantalla.';
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
                    . ' no está asignado a ningún estudiante del archivo; se carga solo como persona.';
            }
        }

        /* ---------------- Choques contra lo que ya está en la base --------------- */
        $impacto = $this->impacto($institucionId, $filas, $personas);

        /* El código de estudiante es único dentro de la institución. Antes esto
           no podía fallar —la carga enceraba primero—, pero ahora convive con lo
           que ya existe: si el código que trae el archivo ya lo tiene OTRO
           alumno, se avisa aquí en vez de dejar que la base rechace la operación
           entera con un mensaje que nadie puede interpretar. */
        foreach ($this->codigosOcupados($institucionId, array_keys($codigos)) as $codigo => $identificacionDueño) {
            $identificacionArchivo = $codigos[$codigo] ?? '';
            if ($identificacionArchivo !== '' && $identificacionArchivo !== $identificacionDueño) {
                $errores[] = 'Hoja Estudiantes: el código ' . $codigo . ' ya pertenece al estudiante '
                    . $identificacionDueño . ', y en el archivo se le asigna a ' . $identificacionArchivo . '.';
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
            'impacto'      => $impacto,
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

        /* El estado no se pide en la plantilla. Constar en el archivo es la
           señal de que la persona está vigente, así que todo lo que entra queda
           ACTIVO —también lo que estuviera inactivo y vuelve a venir—. Si el
           archivo trae una columna Estado, de una plantilla anterior, se ignora
           y se avisa una vez por hoja. */
        $estado = self::ESTADO_CARGA;

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
    /* Contraste con la base                                               */
    /* ================================================================== */

    /** Lo que la institución tiene hoy, para poder comparar contra el archivo. */
    private function inventarioActual(int $institucionId): array
    {
        $contar = fn(string $tabla): int => $this->contar(
            "SELECT COUNT(*) total FROM `$tabla` WHERE InstitucionEducativaId = ?",
            [$institucionId]
        );

        return [
            'personas'    => $contar('persona'),
            'empleados'   => $contar('empleado'),
            'estudiantes' => $contar('estudiante'),
            'proveedores' => $contar('proveedor'),
        ];
    }

    /**
     * Cuántas filas del archivo serían altas y cuántas actualizaciones.
     *
     * Es la información que reemplaza al viejo aviso de «esto se va a borrar»:
     * ahora nadie necesita saber qué se pierde, sino qué cambia.
     */
    private function impacto(int $institucionId, array $filas, array $personas): array
    {
        $desglose = static function (array $delArchivo, array $enLaBase): array {
            $altas = 0;
            $actualizaciones = 0;
            foreach (array_keys($delArchivo) as $identificacion) {
                if (isset($enLaBase[$identificacion])) {
                    $actualizaciones++;
                } else {
                    $altas++;
                }
            }
            return ['altas' => $altas, 'actualizaciones' => $actualizaciones];
        };

        return [
            'personas'    => $desglose($personas, $this->identificacionesDe($institucionId, 'persona')),
            'empleados'   => $desglose($filas['empleados'],   $this->identificacionesDe($institucionId, 'empleado')),
            'estudiantes' => $desglose($filas['estudiantes'], $this->identificacionesDe($institucionId, 'estudiante')),
            'proveedores' => $desglose($filas['proveedores'], $this->identificacionesDe($institucionId, 'proveedor')),
        ];
    }

    /**
     * Identificaciones que la institución ya tiene en una tabla del padrón.
     *
     * Se trae la lista entera de una vez en lugar de preguntar fila por fila:
     * un archivo de cinco mil proveedores serían cinco mil consultas.
     *
     * @return array<string, true> identificación => true, para buscar por clave
     */
    private function identificacionesDe(int $institucionId, string $tabla): array
    {
        static $cache = [];

        $clave = $tabla . '#' . $institucionId;
        if (isset($cache[$clave])) {
            return $cache[$clave];
        }

        $sql = $tabla === 'persona'
            ? 'SELECT p.Identificacion FROM persona p WHERE p.InstitucionEducativaId = ?'
            : "SELECT p.Identificacion
                 FROM `$tabla` v
           INNER JOIN persona p ON p.PersonaId = v.PersonaId
                WHERE v.InstitucionEducativaId = ?";

        $lista = $this->columna($sql, [$institucionId]);

        return $cache[$clave] = array_fill_keys(array_map('strval', $lista), true);
    }

    /**
     * De los códigos de estudiante que trae el archivo, cuáles ya están en uso
     * y por quién.
     *
     * @param string[] $codigos
     * @return array<string, string> código => identificación de quien lo tiene
     */
    private function codigosOcupados(int $institucionId, array $codigos): array
    {
        $codigos = array_values(array_filter($codigos, static fn($c): bool => (string)$c !== ''));
        if (!$codigos) {
            return [];
        }

        $ocupados = [];
        foreach (array_chunk($codigos, 400) as $lote) {
            $marcas = implode(',', array_fill(0, count($lote), '?'));
            $filas  = $this->consultar(
                "SELECT es.CodigoEstudiante, p.Identificacion
                   FROM estudiante es
             INNER JOIN persona p ON p.PersonaId = es.PersonaId
                  WHERE es.InstitucionEducativaId = ?
                    AND es.CodigoEstudiante IN ($marcas)",
                array_merge([$institucionId], $lote)
            );
            foreach ($filas as $fila) {
                $ocupados[(string)$fila['CodigoEstudiante']] = (string)$fila['Identificacion'];
            }
        }

        return $ocupados;
    }

    /* ================================================================== */
    /* Carga                                                               */
    /* ================================================================== */

    /**
     * Aplica el archivo sobre el padrón: da de alta lo que no está y actualiza
     * lo que sí. No borra nada.
     *
     * @return array Conteo de altas y actualizaciones por tabla
     */
    private function cargar(int $institucionId, array $filas): array
    {
        $aplicado = [
            'personas'    => ['altas' => 0, 'actualizaciones' => 0],
            'empleados'   => ['altas' => 0, 'actualizaciones' => 0],
            'estudiantes' => ['altas' => 0, 'actualizaciones' => 0],
            'proveedores' => ['altas' => 0, 'actualizaciones' => 0],
        ];

        /** @var array<string,int> identificación => PersonaId */
        $ids = [];

        foreach ($filas['personas'] as $identificacion => $persona) {
            $existente = Padron::porIdentificacion($this->db, $institucionId, $identificacion);
            $ids[$identificacion] = $this->personaId($institucionId, $persona);
            $aplicado['personas'][$existente === null ? 'altas' : 'actualizaciones']++;
        }

        /* ---------------- Empleados ------------------------------------------- */
        foreach ($filas['empleados'] as $identificacion => $fila) {
            $personaId = $ids[$identificacion];
            $vinculo   = $this->vinculoExistente('empleado', 'EmpleadoId', $institucionId, $personaId);

            if ($vinculo === null) {
                $this->ejecutar(
                    'INSERT INTO empleado (InstitucionEducativaId, PersonaId, Estado) VALUES (?,?,?)',
                    [$institucionId, $personaId, $fila['estado']]
                );
                $aplicado['empleados']['altas']++;
            } else {
                $this->ejecutar(
                    'UPDATE empleado SET Estado = ? WHERE EmpleadoId = ? AND InstitucionEducativaId = ?',
                    [$fila['estado'], $vinculo, $institucionId]
                );
                $aplicado['empleados']['actualizaciones']++;
            }
        }

        /* ---------------- Estudiantes ------------------------------------------ */
        foreach ($filas['estudiantes'] as $identificacion => $fila) {
            $personaId       = $ids[$identificacion];
            $representanteId = $ids[$fila['rep_id']] ?? null;
            $vinculo         = $this->vinculoExistente('estudiante', 'EstudianteId', $institucionId, $personaId);
            $codigo          = $fila['codigo'] !== '' ? $fila['codigo'] : null;

            if ($vinculo === null) {
                $this->ejecutar(
                    'INSERT INTO estudiante
                        (InstitucionEducativaId, PersonaId, CodigoEstudiante,
                         RepresentanteId, RepresentanteRelacion, Estado)
                     VALUES (?,?,?,?,?,?)',
                    [$institucionId, $personaId, $codigo, $representanteId, $fila['rep_relacion'], $fila['estado']]
                );
                $aplicado['estudiantes']['altas']++;
            } else {
                /* Lo que el archivo no trae no se borra: si la celda del código o
                   de la relación viene vacía, se conserva lo que ya constaba. El
                   representante sí es obligatorio en la plantilla, así que ese
                   siempre se asigna. */
                $this->ejecutar(
                    'UPDATE estudiante
                        SET CodigoEstudiante      = COALESCE(?, CodigoEstudiante),
                            RepresentanteId       = ?,
                            RepresentanteRelacion = COALESCE(?, RepresentanteRelacion),
                            Estado                = ?
                      WHERE EstudianteId = ? AND InstitucionEducativaId = ?',
                    [$codigo, $representanteId, $fila['rep_relacion'], $fila['estado'], $vinculo, $institucionId]
                );
                $aplicado['estudiantes']['actualizaciones']++;
            }
        }

        /* ---------------- Proveedores ------------------------------------------ */
        foreach ($filas['proveedores'] as $identificacion => $fila) {
            $personaId = $ids[$identificacion];
            $vinculo   = $this->vinculoExistente('proveedor', 'ProveedorId', $institucionId, $personaId);

            if ($vinculo === null) {
                $this->ejecutar(
                    'INSERT INTO proveedor (InstitucionEducativaId, PersonaId, Ruc, RazonSocial, Estado)
                     VALUES (?,?,?,?,?)',
                    [$institucionId, $personaId, $identificacion, $fila['razon_social'], $fila['estado']]
                );
                $aplicado['proveedores']['altas']++;
            } else {
                $this->ejecutar(
                    'UPDATE proveedor SET Ruc = ?, RazonSocial = ?, Estado = ?
                      WHERE ProveedorId = ? AND InstitucionEducativaId = ?',
                    [$identificacion, $fila['razon_social'], $fila['estado'], $vinculo, $institucionId]
                );
                $aplicado['proveedores']['actualizaciones']++;
            }
        }

        return $aplicado;
    }

    /**
     * Identificador del vínculo que ya relaciona a esta persona con esta
     * institución, o null si todavía no existe.
     */
    private function vinculoExistente(string $tabla, string $llave, int $institucionId, int $personaId): ?int
    {
        $fila = $this->consultarUna(
            "SELECT `$llave` AS id FROM `$tabla`
              WHERE InstitucionEducativaId = ? AND PersonaId = ?
              LIMIT 1",
            [$institucionId, $personaId]
        );

        return $fila ? (int)$fila['id'] : null;
    }

    /**
     * Crea la ficha de la persona o actualiza la que ya exista con ese
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
    private function auditarCarga(int $institucionId, array $aplicado): void
    {
        if ($this->usuario === null) {
            return;
        }

        $altas = 0;
        $cambios = 0;
        foreach ($aplicado as $tabla => $conteo) {
            if ($tabla === 'personas') {
                continue;   // las personas ya se cuentan dentro de cada vínculo
            }
            $altas   += (int)$conteo['altas'];
            $cambios += (int)$conteo['actualizaciones'];
        }

        Auditoria::cambioLista(
            $this->usuario,
            'carga_informacion',
            $institucionId,
            'Carga: ' . $altas . ' altas, ' . $cambios . ' actualizaciones',
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
     * adelante—, la carga acepta exactamente lo que se puede guardar, en vez
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
