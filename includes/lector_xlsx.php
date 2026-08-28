<?php
/**
 * includes/lector_xlsx.php
 * -----------------------------------------------------------------------------
 * Lector de archivos Excel (.xlsx) sin librerías externas.
 *
 * Un .xlsx es un archivo ZIP con documentos XML dentro. Esta clase lo abre con
 * ZipArchive (extensión estándar de PHP), resuelve el nombre real de cada hoja
 * y devuelve sus filas ya convertidas a texto, usando la primera fila como
 * encabezado.
 *
 * Uso:
 *
 *     require_once __DIR__ . '/lector_xlsx.php';
 *
 *     $libro = new LectorXlsx('/ruta/al/archivo.xlsx');
 *     $filas = $libro->hoja('Empleados');   // [['Identificación' => '09...', ...], ...]
 *
 * Qué resuelve:
 *   - Textos compartidos (sharedStrings.xml) y textos en línea.
 *   - Números y fechas seriales de Excel (se devuelven como AAAA-MM-DD).
 *   - Celdas vacías intercaladas: se respeta la letra de columna, de modo que
 *     los valores nunca se corren de columna.
 *   - Filas totalmente vacías: se omiten.
 * -----------------------------------------------------------------------------
 */

final class LectorXlsx
{
    /** Tope de filas por hoja, como red de seguridad ante archivos enormes. */
    public const MAX_FILAS = 5000;

    private ZipArchive $zip;
    /** @var string[] Textos compartidos del libro. */
    private array $textos = [];
    /** @var array<string,string> nombre de hoja => ruta del XML dentro del zip */
    private array $hojas = [];
    /** @var array<int,bool> índice de formato => ¿es fecha? */
    private array $formatosFecha = [];

    /** @throws RuntimeException si el archivo no se puede abrir o no es un .xlsx */
    public function __construct(string $rutaArchivo)
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(
                'El servidor no tiene habilitada la extensión ZIP de PHP, necesaria para leer archivos Excel.'
            );
        }

        $this->zip = new ZipArchive();
        if ($this->zip->open($rutaArchivo) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo. Verifique que sea un Excel válido (.xlsx).');
        }

        if ($this->zip->locateName('xl/workbook.xml') === false) {
            $this->zip->close();
            throw new RuntimeException(
                'El archivo no tiene el formato Excel esperado (.xlsx). '
                . 'Si lo guardó como .xls o .csv, vuelva a guardarlo como «Libro de Excel (*.xlsx)».'
            );
        }

        $this->cargarTextos();
        $this->cargarFormatosFecha();
        $this->cargarHojas();
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    /** Nombres de las hojas del libro, en el orden en que aparecen. */
    public function nombresDeHojas(): array
    {
        return array_keys($this->hojas);
    }

    public function tieneHoja(string $nombre): bool
    {
        return isset($this->hojas[$this->normalizar($nombre)]);
    }

    /**
     * Filas de una hoja como arreglos asociativos, usando la primera fila
     * como nombres de columna. Las filas vacías se descartan.
     *
     * @return array<int,array<string,string>>
     * @throws RuntimeException si la hoja no existe
     */
    public function hoja(string $nombre): array
    {
        $clave = $this->normalizar($nombre);
        if (!isset($this->hojas[$clave])) {
            throw new RuntimeException('El archivo no contiene la hoja «' . $nombre . '».');
        }

        $matriz = $this->filasCrudas($this->hojas[$clave]);
        if (!$matriz) {
            return [];
        }

        $encabezado = array_shift($matriz);
        $columnas   = [];
        foreach ($encabezado as $letra => $titulo) {
            $titulo = trim((string)$titulo);
            if ($titulo !== '') {
                $columnas[$letra] = $titulo;
            }
        }

        $filas = [];
        foreach ($matriz as $cruda) {
            $fila     = [];
            $conDatos = false;

            foreach ($columnas as $letra => $titulo) {
                $valor = trim((string)($cruda[$letra] ?? ''));
                $fila[$titulo] = $valor;
                if ($valor !== '') {
                    $conDatos = true;
                }
            }

            if ($conDatos) {
                $filas[] = $fila;
            }
        }

        return $filas;
    }

    /* ------------------------------------------------------------------ */
    /* Lectura interna                                                     */
    /* ------------------------------------------------------------------ */

    /** Nombre de hoja normalizado: sin acentos, sin espacios y en minúsculas. */
    private function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto), 'UTF-8');
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
        return preg_replace('/[^a-z0-9]/', '', $texto) ?? $texto;
    }

    private function xml(string $ruta): ?SimpleXMLElement
    {
        $contenido = $this->zip->getFromName($ruta);
        if ($contenido === false || $contenido === '') {
            return null;
        }
        $anterior = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contenido);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        return $xml === false ? null : $xml;
    }

    private function cargarTextos(): void
    {
        $xml = $this->xml('xl/sharedStrings.xml');
        if ($xml === null) {
            return;
        }

        foreach ($xml->si as $si) {
            // El texto puede venir entero (<t>) o partido en fragmentos (<r><t>)
            $texto = '';
            if (isset($si->t)) {
                $texto = (string)$si->t;
            }
            foreach ($si->r as $r) {
                $texto .= (string)$r->t;
            }
            $this->textos[] = $texto;
        }
    }

    /**
     * Marca qué formatos del libro corresponden a fechas, para poder convertir
     * los números seriales de Excel a una fecha legible.
     */
    private function cargarFormatosFecha(): void
    {
        // Formatos de fecha propios de Excel (numFmtId reservados)
        $integrados = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 30, 36, 45, 46, 47, 50, 57];

        $xml = $this->xml('xl/styles.xml');
        if ($xml === null) {
            return;
        }

        // Formatos personalizados que contienen componentes de fecha u hora
        $personalizados = [];
        if (isset($xml->numFmts)) {
            foreach ($xml->numFmts->numFmt as $formato) {
                $codigo = (string)$formato['formatCode'];
                $limpio = preg_replace('/\[[^\]]*\]|"[^"]*"/', '', $codigo);
                if (preg_match('/[dmyhs]/i', (string)$limpio)) {
                    $personalizados[(int)$formato['numFmtId']] = true;
                }
            }
        }

        if (!isset($xml->cellXfs)) {
            return;
        }

        $indice = 0;
        foreach ($xml->cellXfs->xf as $xf) {
            $id = (int)$xf['numFmtId'];
            $this->formatosFecha[$indice] = in_array($id, $integrados, true) || isset($personalizados[$id]);
            $indice++;
        }
    }

    /** Relaciona el nombre visible de cada hoja con su XML dentro del zip. */
    private function cargarHojas(): void
    {
        $libro = $this->xml('xl/workbook.xml');
        $rels  = $this->xml('xl/_rels/workbook.xml.rels');
        if ($libro === null) {
            return;
        }

        $destinos = [];
        if ($rels !== null) {
            foreach ($rels->Relationship as $rel) {
                $destino = (string)$rel['Target'];
                $destino = ltrim(str_replace('/xl/', '', $destino), '/');
                if (!str_starts_with($destino, 'xl/')) {
                    $destino = 'xl/' . $destino;
                }
                $destinos[(string)$rel['Id']] = $destino;
            }
        }

        $posicion = 0;
        foreach ($libro->sheets->sheet as $hoja) {
            $posicion++;
            $nombre = (string)$hoja['name'];

            // El atributo r:id vive en otro espacio de nombres
            $rid = '';
            foreach ($hoja->attributes('r', true) as $clave => $valor) {
                if ($clave === 'id') {
                    $rid = (string)$valor;
                }
            }

            $ruta = $destinos[$rid] ?? ('xl/worksheets/sheet' . $posicion . '.xml');
            if ($this->zip->locateName($ruta) === false) {
                $ruta = 'xl/worksheets/sheet' . $posicion . '.xml';
            }

            $this->hojas[$this->normalizar($nombre)] = $ruta;
        }
    }

    /**
     * Filas de una hoja indexadas por letra de columna.
     * @return array<int,array<string,string>>
     */
    private function filasCrudas(string $ruta): array
    {
        $xml = $this->xml($ruta);
        if ($xml === null || !isset($xml->sheetData)) {
            return [];
        }

        $filas = [];
        foreach ($xml->sheetData->row as $row) {
            if (count($filas) >= self::MAX_FILAS + 1) {
                break;   // +1: la primera fila es el encabezado
            }

            $celdas = [];
            foreach ($row->c as $c) {
                $referencia = (string)$c['r'];                       // Ej.: "B7"
                $letra      = preg_replace('/\d+/', '', $referencia) ?: '';
                $celdas[$letra] = $this->valorDeCelda($c);
            }
            $filas[] = $celdas;
        }

        return $filas;
    }

    /** Convierte una celda a texto según su tipo. */
    private function valorDeCelda(SimpleXMLElement $c): string
    {
        $tipo = (string)$c['t'];

        if ($tipo === 'inlineStr') {
            $texto = '';
            if (isset($c->is->t)) {
                $texto = (string)$c->is->t;
            }
            foreach ($c->is->r ?? [] as $r) {
                $texto .= (string)$r->t;
            }
            return $texto;
        }

        $crudo = isset($c->v) ? (string)$c->v : '';
        if ($crudo === '') {
            return '';
        }

        if ($tipo === 's') {                       // texto compartido
            return $this->textos[(int)$crudo] ?? '';
        }
        if ($tipo === 'b') {                       // booleano
            return $crudo === '1' ? 'SI' : 'NO';
        }
        if ($tipo === 'e') {                       // error de fórmula (#N/A, ...)
            return '';
        }
        if ($tipo === 'str') {                     // resultado de fórmula
            return $crudo;
        }

        // Numérico: puede ser una fecha según el formato aplicado
        $estilo = $c['s'] !== null ? (int)$c['s'] : -1;
        if (is_numeric($crudo) && ($this->formatosFecha[$estilo] ?? false)) {
            $fecha = $this->fechaDeSerial((float)$crudo);
            if ($fecha !== null) {
                return $fecha;
            }
        }

        return $crudo;
    }

    /** Número serial de Excel -> AAAA-MM-DD (base 1900, con el salto histórico). */
    private function fechaDeSerial(float $serial): ?string
    {
        if ($serial < 1 || $serial > 2958465) {   // fuera de 1900-01-01 .. 9999-12-31
            return null;
        }

        $dias = (int)floor($serial);
        // Excel considera 1900 bisiesto por compatibilidad: se corrige el desfase
        $dias -= ($dias > 59) ? 1 : 0;

        $marca = ($dias - 1) * 86400 + mktime(0, 0, 0, 1, 1, 1900);
        if ($marca === false) {
            return null;
        }

        return date('Y-m-d', $marca);
    }
}
