<?php
/**
 * api/core/LectorXlsx.php
 * -----------------------------------------------------------------------------
 * Lector de archivos Excel (.xlsx) sin librerías externas.
 *
 * Un .xlsx es un archivo ZIP con documentos XML dentro. Aquí se abre con
 * ZipArchive (extensión estándar de PHP) y se recorre con SimpleXML, que
 * también viene de serie. No hace falta Composer ni PhpSpreadsheet.
 *
 * Lo que devuelve:
 *
 *     $libro = LectorXlsx::abrir('/ruta/archivo.xlsx');
 *     $libro->hojas();                 // ['Instrucciones', 'Empleados', ...]
 *     $libro->filas('Empleados');      // [['Identificación'=>'091...', ...], ...]
 *
 * Las filas se devuelven ya indexadas por el nombre del encabezado, de modo que
 * el orden de las columnas en la plantilla no importa: solo su nombre.
 *
 * Limitaciones asumidas a propósito, para mantenerlo simple y seguro:
 *   - Solo lectura.
 *   - Se ignoran fórmulas: se lee el último valor calculado que guardó Excel.
 *   - Se ignoran hojas ocultas y celdas fuera del rango de encabezados.
 * -----------------------------------------------------------------------------
 */

final class LectorXlsx
{
    /** Tope de filas leídas por hoja: evita que un archivo enorme agote memoria. */
    public const MAX_FILAS = 20000;

    private ZipArchive $zip;
    /** @var string[] Cadenas compartidas del libro (sharedStrings.xml). */
    private array $cadenas = [];
    /** @var array<string,string> nombre de hoja => ruta interna del XML */
    private array $hojas = [];
    /** @var int[] Índices de estilo que corresponden a un formato de fecha. */
    private array $estilosFecha = [];

    private function __construct()
    {
    }

    /* ------------------------------------------------------------------ */
    /* Apertura                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @throws RuntimeException si el archivo no existe, no es un .xlsx válido
     *                          o no trae ninguna hoja legible.
     */
    public static function abrir(string $ruta): self
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('El servidor no tiene habilitada la extensión ZipArchive, necesaria para leer archivos Excel.');
        }
        if (!is_file($ruta) || !is_readable($ruta)) {
            throw new RuntimeException('No se pudo abrir el archivo cargado.');
        }

        $libro = new self();
        $libro->zip = new ZipArchive();

        if ($libro->zip->open($ruta) !== true) {
            throw new RuntimeException('El archivo no es un Excel válido (.xlsx). Si lo guardó como .xls o .csv, vuelva a guardarlo como «Libro de Excel (*.xlsx)».');
        }

        $libro->cargarCadenas();
        $libro->cargarEstilos();
        $libro->cargarHojas();

        if (!$libro->hojas) {
            throw new RuntimeException('El archivo Excel no contiene hojas legibles.');
        }

        return $libro;
    }

    public function cerrar(): void
    {
        @$this->zip->close();
    }

    /** @return string[] Nombres de las hojas, en el orden del libro. */
    public function hojas(): array
    {
        return array_keys($this->hojas);
    }

    public function tieneHoja(string $nombre): bool
    {
        return $this->rutaDeHoja($nombre) !== null;
    }

    /* ------------------------------------------------------------------ */
    /* Lectura de una hoja                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Devuelve las filas de una hoja indexadas por el encabezado de cada
     * columna. La primera fila con contenido se toma como encabezado.
     *
     * Cada fila incluye además la clave especial '_fila' con el número de fila
     * real del Excel, para poder señalar los errores al usuario.
     *
     * @return array<int, array<string, string>>
     */
    public function filas(string $nombreHoja): array
    {
        $ruta = $this->rutaDeHoja($nombreHoja);
        if ($ruta === null) {
            return [];
        }

        $xml = $this->xml($ruta);
        if ($xml === null) {
            return [];
        }

        $encabezados = [];
        $filas       = [];
        $leidas      = 0;

        foreach ($xml->sheetData->row ?? [] as $fila) {
            if ($leidas >= self::MAX_FILAS) {
                break;
            }

            $numero = (int)($fila['r'] ?? 0);
            $celdas = [];

            foreach ($fila->c ?? [] as $celda) {
                $columna = $this->letraDeColumna((string)($celda['r'] ?? ''));
                if ($columna === '') {
                    continue;
                }
                $valor = $this->valorDeCelda($celda);
                if ($valor !== '') {
                    $celdas[$columna] = $valor;
                }
            }

            if (!$celdas) {
                continue;   // fila completamente vacía
            }

            // La primera fila con contenido define los encabezados
            if (!$encabezados) {
                foreach ($celdas as $columna => $texto) {
                    $encabezados[$columna] = self::normalizar($texto);
                }
                continue;
            }

            $registro = ['_fila' => (string)$numero];
            foreach ($encabezados as $columna => $clave) {
                $registro[$clave] = $celdas[$columna] ?? '';
            }

            $filas[] = $registro;
            $leidas++;
        }

        return $filas;
    }

    /**
     * Normaliza un encabezado para poder compararlo sin depender de tildes,
     * mayúsculas ni espacios de más:  «Tipo de Identificación» -> «tipo de identificacion»
     */
    public static function normalizar(string $texto): string
    {
        $texto = trim($texto);
        $texto = str_replace(
            ['á','é','í','ó','ú','Á','É','Í','Ó','Ú','ñ','Ñ','ü','Ü'],
            ['a','e','i','o','u','a','e','i','o','u','n','n','u','u'],
            $texto
        );
        $texto = mb_strtolower($texto, 'UTF-8');
        $texto = preg_replace('/\s+/u', ' ', $texto) ?? $texto;

        return trim($texto);
    }

    /* ------------------------------------------------------------------ */
    /* Interior del archivo                                                */
    /* ------------------------------------------------------------------ */

    private function cargarCadenas(): void
    {
        $xml = $this->xml('xl/sharedStrings.xml');
        if ($xml === null) {
            return;
        }

        foreach ($xml->si ?? [] as $si) {
            // Una cadena puede venir entera (<t>) o partida en tramos (<r><t>)
            $texto = '';
            foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $texto .= (string)$t;
            }
            $this->cadenas[] = $texto;
        }
    }

    /**
     * Marca qué estilos corresponden a fechas, para poder convertir el número
     * de serie de Excel (45000) a una fecha legible (2023-03-15).
     */
    private function cargarEstilos(): void
    {
        $xml = $this->xml('xl/styles.xml');
        if ($xml === null) {
            return;
        }

        // Formatos integrados de Excel que son fechas u horas
        $formatosFecha = [14,15,16,17,18,19,20,21,22,27,30,36,45,46,47,50,57];

        // Formatos personalizados: se detectan por su patrón (d/m/yyyy, etc.)
        $personalizados = [];
        foreach ($xml->numFmts->numFmt ?? [] as $fmt) {
            $codigo = (string)($fmt['formatCode'] ?? '');
            if (preg_match('/[dmyhs]/i', $codigo) && !preg_match('/^[#0.,%\s]*$/', $codigo)) {
                $personalizados[] = (int)$fmt['numFmtId'];
            }
        }

        $indice = 0;
        foreach ($xml->cellXfs->xf ?? [] as $xf) {
            $numFmtId = (int)($xf['numFmtId'] ?? 0);
            if (in_array($numFmtId, $formatosFecha, true) || in_array($numFmtId, $personalizados, true)) {
                $this->estilosFecha[] = $indice;
            }
            $indice++;
        }
    }

    private function cargarHojas(): void
    {
        $libro = $this->xml('xl/workbook.xml');
        if ($libro === null) {
            return;
        }

        // Relación Id -> archivo XML de cada hoja
        $destinos = [];
        $rels = $this->xml('xl/_rels/workbook.xml.rels');
        foreach ($rels->Relationship ?? [] as $rel) {
            $destinos[(string)$rel['Id']] = (string)$rel['Target'];
        }

        foreach ($libro->sheets->sheet ?? [] as $hoja) {
            $nombre = (string)$hoja['name'];

            // El atributo r:id vive en otro espacio de nombres
            $id = '';
            foreach ($hoja->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships') ?? [] as $clave => $valor) {
                if ($clave === 'id') {
                    $id = (string)$valor;
                }
            }

            $destino = $destinos[$id] ?? '';
            if ($destino === '') {
                continue;
            }

            $destino = ltrim($destino, '/');
            if (!str_starts_with($destino, 'xl/')) {
                $destino = 'xl/' . $destino;
            }

            $this->hojas[$nombre] = $destino;
        }
    }

    /** Busca la hoja por nombre, sin distinguir tildes ni mayúsculas. */
    private function rutaDeHoja(string $nombre): ?string
    {
        if (isset($this->hojas[$nombre])) {
            return $this->hojas[$nombre];
        }

        $buscado = self::normalizar($nombre);
        foreach ($this->hojas as $hoja => $ruta) {
            if (self::normalizar($hoja) === $buscado) {
                return $ruta;
            }
        }
        return null;
    }

    private function xml(string $ruta): ?SimpleXMLElement
    {
        $contenido = $this->zip->getFromName($ruta);
        if ($contenido === false || $contenido === '') {
            return null;
        }

        $anterior = libxml_use_internal_errors(true);
        // LIBXML_NONET evita cualquier acceso de red desde el XML del archivo.
        $xml = simplexml_load_string($contenido, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return $xml === false ? null : $xml;
    }

    /** Convierte el contenido de una celda a texto. */
    private function valorDeCelda(SimpleXMLElement $celda): string
    {
        $tipo = (string)($celda['t'] ?? '');

        // Texto en línea
        if ($tipo === 'inlineStr') {
            $texto = '';
            foreach ($celda->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $texto .= (string)$t;
            }
            return trim($texto);
        }

        if (!isset($celda->v)) {
            return '';
        }
        $bruto = (string)$celda->v;

        // Cadena compartida
        if ($tipo === 's') {
            return trim($this->cadenas[(int)$bruto] ?? '');
        }
        // Booleano
        if ($tipo === 'b') {
            return $bruto === '1' ? 'SI' : 'NO';
        }
        // Error de fórmula (#N/A, #VALOR!): se trata como vacío
        if ($tipo === 'e') {
            return '';
        }

        // Número: puede ser una fecha con formato
        $estilo = (int)($celda['s'] ?? 0);
        if (in_array($estilo, $this->estilosFecha, true) && is_numeric($bruto)) {
            $fecha = self::fechaDeSerie((float)$bruto);
            if ($fecha !== null) {
                return $fecha;
            }
        }

        return trim($bruto);
    }

    /**
     * Convierte el número de serie de Excel a fecha ISO.
     * Excel cuenta los días desde el 30/12/1899 (con su conocido error del
     * año bisiesto 1900, que ese punto de partida ya compensa).
     */
    public static function fechaDeSerie(float $serie): ?string
    {
        if ($serie < 1 || $serie > 2958465) {   // 2958465 = 31/12/9999
            return null;
        }

        $base = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));
        $dias = (int)floor($serie);

        return $base->modify('+' . $dias . ' days')->format('Y-m-d');
    }

    /** De la referencia de celda «BC12» devuelve «BC». */
    private function letraDeColumna(string $referencia): string
    {
        if (preg_match('/^([A-Z]+)/', strtoupper($referencia), $m)) {
            return $m[1];
        }
        return '';
    }
}
