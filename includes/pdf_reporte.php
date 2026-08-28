<?php
/**
 * includes/pdf_reporte.php
 * -----------------------------------------------------------------------------
 * Generador de reportes en PDF sin librerías externas.
 *
 * Escribe directamente un documento PDF 1.4 con las fuentes estándar
 * (Helvetica), de modo que funciona en cualquier hosting con PHP, sin Composer
 * ni extensiones adicionales. Cubre lo que necesitan los reportes del sistema:
 *
 *   · cabecera repetida en cada página, con logotipo e institución
 *   · tabla con encabezados, filas alternadas y salto de página automático
 *   · pie con fecha/hora, usuario, numeración y nota legal
 *
 * Uso:
 *   $pdf = new PdfReporte();
 *   $pdf->cabecera([...]); $pdf->pie([...]);
 *   $pdf->tabla($columnas, $filas);
 *   $pdf->salida('reporte.pdf');
 * -----------------------------------------------------------------------------
 */

final class PdfReporte
{
    /* Anchos de los glifos (unidades/1000) de Helvetica, indexados por byte CP1252 */
    private const ANCHOS_NORMAL = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 278, 278,
        355, 556, 556, 889, 667, 222, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556,
        556, 556, 556, 278, 278, 584, 584, 584, 556, 1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667,
        556, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556, 222,
        556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556, 556, 556, 333, 500, 278, 556,
        500, 722, 500, 500, 500, 334, 260, 334, 584, 0, 0, 0, 222, 556, 333, 1000, 556, 556, 333, 1000, 667, 333,
        1000, 0, 611, 0, 0, 222, 222, 333, 333, 350, 556, 1000, 333, 1000, 500, 333, 944, 0, 500, 667, 278, 333,
        556, 556, 556, 556, 0, 556, 333, 737, 370, 556, 584, 333, 737, 333, 400, 584, 333, 333, 333, 556, 537, 278,
        333, 333, 365, 556, 834, 834, 834, 611, 667, 667, 667, 667, 667, 667, 1000, 722, 667, 667, 667, 667, 278,
        278, 278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, 556, 556,
        556, 556, 556, 556, 889, 500, 556, 556, 556, 556, 278, 278, 278, 278, 556, 556, 556, 556, 556, 556, 556,
        584, 611, 556, 556, 556, 556, 500, 556, 500
    ];
    private const ANCHOS_NEGRITA = [
        0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 278, 333,
        474, 556, 556, 889, 722, 278, 333, 333, 389, 584, 278, 333, 278, 278, 556, 556, 556, 556, 556, 556, 556,
        556, 556, 556, 333, 333, 584, 584, 584, 611, 975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722,
        611, 833, 722, 778, 667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556, 278,
        556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611, 611, 611, 389, 556, 333, 611,
        556, 778, 556, 556, 500, 389, 280, 389, 584, 0, 0, 0, 278, 556, 500, 1000, 556, 556, 333, 1000, 667, 333,
        1000, 0, 611, 0, 0, 278, 278, 500, 500, 350, 556, 1000, 333, 1000, 556, 333, 944, 0, 500, 667, 278, 333,
        556, 556, 556, 556, 0, 556, 333, 737, 370, 556, 584, 333, 737, 333, 400, 584, 333, 333, 333, 611, 556, 278,
        333, 333, 365, 556, 834, 834, 834, 611, 722, 722, 722, 722, 722, 722, 1000, 722, 667, 667, 667, 667, 278,
        278, 278, 278, 722, 722, 778, 778, 778, 778, 778, 584, 778, 722, 722, 722, 722, 667, 667, 611, 556, 556,
        556, 556, 556, 556, 889, 556, 556, 556, 556, 556, 278, 278, 278, 278, 611, 611, 611, 611, 611, 611, 611,
        584, 611, 611, 611, 611, 611, 556, 611, 556
    ];

    /* Geometría de la página, en puntos (1 pt = 1/72 pulgada) */
    private float $ancho;
    private float $alto;
    private float $margen = 34.0;

    private float $y = 0.0;            // cursor vertical, medido desde arriba
    private array $paginas = [];       // contenido de cada página
    private string $flujo  = '';       // contenido de la página en curso
    private int $numPagina = 0;

    private array $cfgCabecera = [];
    private array $cfgPie = [];
    private ?array $logo = null;       // ['datos','ancho','alto']

    /** Columnas y filas de la tabla en curso (para repetir encabezados) */
    private array $columnas = [];

    private const ALIAS_TOTAL = '{{paginas}}';

    /** @param string $orientacion 'H' horizontal (por defecto) o 'V' vertical */
    public function __construct(string $orientacion = 'H')
    {
        // A4: 595 x 842 puntos
        if (strtoupper($orientacion) === 'V') {
            $this->ancho = 595.28;
            $this->alto  = 841.89;
        } else {
            $this->ancho = 841.89;
            $this->alto  = 595.28;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Configuración                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Cabecera del reporte.
     *   logo        ruta a una imagen JPEG o PNG (se convierte si hace falta)
     *   institucion nombre de la institución educativa
     *   titulo      título del reporte
     *   subtitulo   línea secundaria (opcional)
     *   filtros     ['Estado' => 'Vigentes', ...] resumen de la consulta
     */
    public function cabecera(array $configuracion): void
    {
        $this->cfgCabecera = $configuracion;
        if (!empty($configuracion['logo'])) {
            $this->logo = $this->prepararImagen($configuracion['logo']);
        }
    }

    /**
     * Pie del reporte.
     *   usuario     nombre del usuario que emite el reporte
     *   disclaimer  nota legal breve
     */
    public function pie(array $configuracion): void
    {
        $this->cfgPie = $configuracion;
    }

    /* ------------------------------------------------------------------ */
    /* Contenido                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Dibuja la tabla completa, paginando sola.
     *
     * @param array $columnas [['clave'=>'x','titulo'=>'X','ancho'=>90,'align'=>'L'], ...]
     *                        'ancho' es proporcional; se reparte el ancho útil.
     * @param array $filas    arreglos asociativos con las claves de las columnas
     */
    public function tabla(array $columnas, array $filas): void
    {
        $this->columnas = $this->calcularAnchos($columnas);

        if ($this->numPagina === 0) {
            $this->nuevaPagina();
        }

        $this->encabezadoTabla();

        $alturaFila = 16.0;
        $indice = 0;

        foreach ($filas as $fila) {
            if ($this->y + $alturaFila > $this->alto - $this->margen - 42) {
                $this->nuevaPagina();
                $this->encabezadoTabla();
            }

            // Fondo alterno
            if ($indice % 2 === 1) {
                $this->rectangulo($this->margen, $this->y, $this->anchoUtil(), $alturaFila, [246, 248, 251]);
            }

            $x = $this->margen;
            foreach ($this->columnas as $columna) {
                $valor = (string)($fila[$columna['clave']] ?? '');
                $this->celda(
                    $x + 5,
                    $this->y + 11,
                    $valor,
                    $columna['ancho'] - 10,
                    8.4,
                    $columna['estilo'] ?? '',
                    $columna['align'] ?? 'L',
                    $columna['color'] ?? [40, 44, 52],
                    $fila['_color_' . $columna['clave']] ?? null
                );
                $x += $columna['ancho'];
            }

            $this->linea($this->margen, $this->y + $alturaFila, $this->margen + $this->anchoUtil(), $this->y + $alturaFila, [230, 233, 240], 0.4);
            $this->y += $alturaFila;
            $indice++;
        }

        if (empty($filas)) {
            $this->celda($this->margen, $this->y + 14, 'No se encontraron registros con los filtros seleccionados.',
                $this->anchoUtil(), 9, '', 'C', [120, 128, 145]);
            $this->y += 22;
        }

        // Marco exterior de la tabla
        $this->linea($this->margen, $this->y, $this->margen + $this->anchoUtil(), $this->y, [200, 205, 215], 0.6);
    }

    /** Texto suelto bajo la tabla (por ejemplo, totales). */
    public function parrafo(string $texto, float $tamano = 8.5, string $estilo = '', array $color = [90, 96, 110]): void
    {
        if ($this->numPagina === 0) {
            $this->nuevaPagina();
        }
        $this->y += 14;
        $this->celda($this->margen, $this->y, $texto, $this->anchoUtil(), $tamano, $estilo, 'L', $color);
    }

    /** Verifica si hay espacio suficiente en la página actual; si no, salta a una nueva. */
    public function verificarEspacio(float $alto): void
    {
        if ($this->numPagina === 0) {
            $this->nuevaPagina();
            return;
        }
        if ($this->y + $alto > $this->alto - $this->margen - 30) {
            $this->nuevaPagina();
        }
    }

    /**
     * Título visual de sección estructurada con indicador corporativo lateral.
     */
    public function seccionTitulo(string $titulo, string $subtitulo = ''): void
    {
        $this->verificarEspacio(26 + ($subtitulo !== '' ? 12 : 0));
        $this->y += 10;
        // Indicador rojo a la izquierda
        $this->rectangulo($this->margen, $this->y + 2, 3.5, 12, [200, 16, 46]);
        $this->celda($this->margen + 8, $this->y + 11, $titulo, $this->anchoUtil() - 8, 10, 'B', 'L', [28, 31, 39]);
        $this->y += 15;

        if ($subtitulo !== '') {
            $this->celda($this->margen + 8, $this->y + 7, $subtitulo, $this->anchoUtil() - 8, 7.8, '', 'L', [100, 108, 122]);
            $this->y += 11;
        }
    }

    /**
     * Dibuja una fila de tarjetas de KPI ejecutivas con estilo idéntico al panel web.
     */
    public function tarjetasKpi(array $tarjetas): void
    {
        $cant = count($tarjetas);
        if ($cant === 0) {
            return;
        }

        $this->verificarEspacio(44);
        $this->y += 4;
        $gap = 8.0;
        $anchoTotal = $this->anchoUtil();
        $anchoTarjeta = ($anchoTotal - ($gap * ($cant - 1))) / $cant;
        $altoTarjeta = 36.0;

        $x = $this->margen;
        foreach ($tarjetas as $t) {
            $valor   = (string)($t['valor'] ?? '0');
            $label   = (string)($t['label'] ?? '');
            $colorV  = $t['color'] ?? [28, 31, 39];
            $colorBg = $t['bg'] ?? [248, 249, 251];
            $borde   = $t['borde'] ?? [225, 230, 240];

            // Fondo de la tarjeta
            $this->rectangulo($x, $this->y, $anchoTarjeta, $altoTarjeta, $colorBg);
            // Borde sutil
            $this->linea($x, $this->y, $x + $anchoTarjeta, $this->y, $borde, 0.5);
            $this->linea($x, $this->y + $altoTarjeta, $x + $anchoTarjeta, $this->y + $altoTarjeta, $borde, 0.5);
            $this->linea($x, $this->y, $x, $this->y + $altoTarjeta, $borde, 0.5);
            $this->linea($x + $anchoTarjeta, $this->y, $x + $anchoTarjeta, $this->y + $altoTarjeta, $borde, 0.5);

            // Borde indicador izquierdo si existe
            if (!empty($t['borde_izq'])) {
                $this->rectangulo($x, $this->y, 3.0, $altoTarjeta, $t['borde_izq']);
            }

            // Valor principal
            $this->celda($x + 8, $this->y + 17, $valor, $anchoTarjeta - 16, 12.5, 'B', 'L', $colorV);
            // Label descriptivo
            $this->celda($x + 8, $this->y + 29, $label, $anchoTarjeta - 16, 7.5, '', 'L', [102, 112, 133]);

            $x += $anchoTarjeta + $gap;
        }

        $this->y += $altoTarjeta + 10;
    }

    /**
     * Dibuja una barra de progreso visual (ej. Nivel de Cobertura Global).
     */
    public function barraProgreso(string $titulo, array $segmentos): void
    {
        $this->verificarEspacio(26);

        $etiquetas = [];
        foreach ($segmentos as $s) {
            if (!empty($s['label'])) {
                $etiquetas[] = $s['label'];
            }
        }
        if ($titulo !== '') {
            $this->celda($this->margen, $this->y + 7, $titulo, $this->anchoUtil() * 0.45, 8.5, 'B', 'L', [40, 44, 52]);
        }
        if (!empty($etiquetas)) {
            $this->celda($this->margen + $this->anchoUtil() * 0.45, $this->y + 7, implode('   ·   ', $etiquetas),
                $this->anchoUtil() * 0.55, 8, '', 'R', [76, 84, 98]);
        }

        $this->y += 10;
        $altoBarra = 6.0;
        $anchoTotal = $this->anchoUtil();

        // Fondo gris de la barra
        $this->rectangulo($this->margen, $this->y, $anchoTotal, $altoBarra, [230, 233, 240]);

        $x = $this->margen;
        $maxRight = $this->margen + $anchoTotal;
        foreach ($segmentos as $s) {
            $pct = max(0, min(100, (float)($s['pct'] ?? 0)));
            if ($pct > 0 && $x < $maxRight) {
                $anchoSeg = min(($pct / 100) * $anchoTotal, $maxRight - $x);
                $this->rectangulo($x, $this->y, $anchoSeg, $altoBarra, $s['color'] ?? [18, 115, 74]);
                $x += $anchoSeg;
            }
        }

        $this->y += $altoBarra + 10;
    }

    /* ------------------------------------------------------------------ */
    /* Páginas                                                             */
    /* ------------------------------------------------------------------ */

    private function nuevaPagina(): void
    {
        if ($this->numPagina > 0) {
            $this->dibujarPie();
            $this->paginas[] = $this->flujo;
        }

        $this->flujo = '';
        $this->numPagina++;
        $this->y = $this->margen;
        $this->dibujarCabecera();
    }

    private function dibujarCabecera(): void
    {
        $c = $this->cfgCabecera;
        $x = $this->margen;
        $alturaLogo = 0.0;

        if ($this->logo) {
            $alturaLogo = 34.0;
            $anchoLogo  = $alturaLogo * ($this->logo['ancho'] / max(1, $this->logo['alto']));
            $this->imagen($x, $this->y, $anchoLogo, $alturaLogo);
            $x += $anchoLogo + 14;
        }

        $institucion = (string)($c['institucion'] ?? '');
        $titulo      = (string)($c['titulo'] ?? 'Reporte');
        $subtitulo   = (string)($c['subtitulo'] ?? '');

        $this->celda($x, $this->y + 11, $institucion, $this->anchoUtil(), 12.5, 'B', 'L', [200, 16, 46]);
        $this->celda($x, $this->y + 24, $titulo, $this->anchoUtil(), 10.5, 'B', 'L', [28, 31, 39]);
        if ($subtitulo !== '') {
            $this->celda($x, $this->y + 35, $subtitulo, $this->anchoUtil(), 8, '', 'L', [102, 112, 133]);
        }

        $this->y += max($alturaLogo, 38) + 6;

        // Resumen de filtros aplicados
        if (!empty($c['filtros'])) {
            $partes = [];
            foreach ($c['filtros'] as $etiqueta => $valor) {
                $partes[] = $etiqueta . ': ' . $valor;
            }
            $this->celda($this->margen, $this->y + 9, implode('   ·   ', $partes),
                $this->anchoUtil(), 8, '', 'L', [76, 84, 98]);
            $this->y += 14;
        }

        $this->linea($this->margen, $this->y + 4, $this->margen + $this->anchoUtil(), $this->y + 4, [200, 16, 46], 1.1);
        $this->y += 12;
    }

    private function encabezadoTabla(): void
    {
        $alturaEncabezado = 18.0;
        $this->rectangulo($this->margen, $this->y, $this->anchoUtil(), $alturaEncabezado, [239, 241, 246]);

        $x = $this->margen;
        foreach ($this->columnas as $columna) {
            $this->celda($x + 5, $this->y + 12, mb_strtoupper($columna['titulo']), $columna['ancho'] - 10,
                7.8, 'B', $columna['align'] ?? 'L', [70, 78, 92]);
            $x += $columna['ancho'];
        }

        $this->linea($this->margen, $this->y + $alturaEncabezado, $this->margen + $this->anchoUtil(),
            $this->y + $alturaEncabezado, [200, 205, 215], 0.6);
        $this->y += $alturaEncabezado;
    }

    private function dibujarPie(): void
    {
        $base = $this->alto - $this->margen;

        $this->linea($this->margen, $base - 30, $this->margen + $this->anchoUtil(), $base - 30, [216, 220, 230], 0.6);

        $c = $this->cfgPie;
        $izquierda = 'Generado el ' . date('d/m/Y H:i:s')
            . '  ·  Emitido por: ' . (string)($c['usuario'] ?? '—');

        $this->celda($this->margen, $base - 20, $izquierda, $this->anchoUtil() - 90, 7.6, '', 'L', [76, 84, 98]);
        $this->celda($this->margen + $this->anchoUtil() - 90, $base - 20,
            'Página ' . $this->numPagina . ' de ' . self::ALIAS_TOTAL, 90, 7.6, '', 'R', [76, 84, 98]);

        if (!empty($c['disclaimer'])) {
            $this->celda($this->margen, $base - 8, (string)$c['disclaimer'], $this->anchoUtil(), 6.8, '', 'L', [130, 137, 150]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Primitivas de dibujo                                                */
    /* ------------------------------------------------------------------ */

    private function anchoUtil(): float
    {
        return $this->ancho - 2 * $this->margen;
    }

    /**
     * Escribe un texto recortado al ancho indicado.
     * $y se mide desde el borde superior de la página.
     */
    private function celda(float $x, float $y, string $texto, float $ancho, float $tamano,
                           string $estilo = '', string $align = 'L', array $color = [0, 0, 0],
                           ?array $colorFila = null): void
    {
        if ($colorFila !== null) {
            $color = $colorFila;
        }

        $texto = $this->aCp1252($texto);
        $texto = $this->recortar($texto, $ancho, $tamano, $estilo);

        $anchoTexto = $this->anchoTexto($texto, $tamano, $estilo);
        $posX = $x;
        if ($align === 'R') {
            $posX = $x + $ancho - $anchoTexto;
        } elseif ($align === 'C') {
            $posX = $x + ($ancho - $anchoTexto) / 2;
        }

        $fuente = ($estilo === 'B') ? '/F2' : '/F1';

        $this->flujo .= sprintf(
            "BT %s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET\n",
            $fuente, $tamano,
            $color[0] / 255, $color[1] / 255, $color[2] / 255,
            $posX, $this->alto - $y,
            $this->escapar($texto)
        );
    }

    private function rectangulo(float $x, float $y, float $ancho, float $alto, array $color): void
    {
        $this->flujo .= sprintf(
            "%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f\n",
            $color[0] / 255, $color[1] / 255, $color[2] / 255,
            $x, $this->alto - $y - $alto, $ancho, $alto
        );
    }

    private function linea(float $x1, float $y1, float $x2, float $y2, array $color, float $grosor): void
    {
        $this->flujo .= sprintf(
            "%.3F %.3F %.3F RG %.2F w %.2F %.2F m %.2F %.2F l S\n",
            $color[0] / 255, $color[1] / 255, $color[2] / 255, $grosor,
            $x1, $this->alto - $y1, $x2, $this->alto - $y2
        );
    }

    private function imagen(float $x, float $y, float $ancho, float $alto): void
    {
        $this->flujo .= sprintf(
            "q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n",
            $ancho, $alto, $x, $this->alto - $y - $alto
        );
    }

    /* ------------------------------------------------------------------ */
    /* Texto: medidas y codificación                                       */
    /* ------------------------------------------------------------------ */

    private function anchoTexto(string $texto, float $tamano, string $estilo): float
    {
        $tabla = ($estilo === 'B') ? self::ANCHOS_NEGRITA : self::ANCHOS_NORMAL;
        $total = 0;
        $largo = strlen($texto);
        for ($i = 0; $i < $largo; $i++) {
            $total += $tabla[ord($texto[$i])] ?: 500;
        }
        return $total * $tamano / 1000;
    }

    private function recortar(string $texto, float $ancho, float $tamano, string $estilo): string
    {
        if ($this->anchoTexto($texto, $tamano, $estilo) <= $ancho) {
            return $texto;
        }
        $puntos = "\x85"; // carácter «…» en CP1252
        while ($texto !== '' && $this->anchoTexto($texto . $puntos, $tamano, $estilo) > $ancho) {
            $texto = substr($texto, 0, -1);
        }
        return $texto . $puntos;
    }

    /** Convierte el texto UTF-8 del sistema a CP1252, que es lo que usan las fuentes base. */
    private function aCp1252(string $texto): string
    {
        if (function_exists('iconv')) {
            $convertido = @iconv('UTF-8', 'CP1252//TRANSLIT', $texto);
            if ($convertido !== false) {
                return $convertido;
            }
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($texto, 'CP1252', 'UTF-8');
        }
        return $texto;
    }

    private function escapar(string $texto): string
    {
        return strtr($texto, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']);
    }

    /** Reparte el ancho útil entre las columnas según su peso. */
    private function calcularAnchos(array $columnas): array
    {
        $suma = 0;
        foreach ($columnas as $columna) {
            $suma += (float)($columna['ancho'] ?? 1);
        }
        $suma = max(0.001, $suma);

        $resultado = [];
        foreach ($columnas as $columna) {
            $columna['ancho'] = $this->anchoUtil() * ((float)($columna['ancho'] ?? 1) / $suma);
            $resultado[] = $columna;
        }
        return $resultado;
    }

    /* ------------------------------------------------------------------ */
    /* Imágenes                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Deja la imagen lista para incrustarla. Los PDF admiten JPEG directamente;
     * si llega un PNG se convierte con GD (con fondo blanco por la transparencia)
     * y se guarda en caché junto al original.
     *
     * @return array{datos:string, ancho:int, alto:int}|null
     */
    private function prepararImagen(string $ruta): ?array
    {
        if (!is_file($ruta)) {
            return null;
        }

        $info = @getimagesize($ruta);
        if (!$info) {
            return null;
        }

        if (($info['mime'] ?? '') === 'image/jpeg') {
            return ['datos' => (string)file_get_contents($ruta), 'ancho' => $info[0], 'alto' => $info[1]];
        }

        // Caché junto al original: logo.png -> logo.pdf.jpg (permite incrustar JPEG aun sin extensión GD)
        $cache = preg_replace('/\.png$/i', '', $ruta) . '.pdf.jpg';
        if (is_file($cache)) {
            $tam = @getimagesize($cache);
            if ($tam && ($tam['mime'] ?? '') === 'image/jpeg') {
                $datos = (string)file_get_contents($cache);
                return ['datos' => $datos, 'ancho' => $tam[0], 'alto' => $tam[1]];
            }
        }

        if (!function_exists('imagecreatefrompng') || ($info['mime'] ?? '') !== 'image/png') {
            return null;
        }

        $origen = @imagecreatefrompng($ruta);
        if (!$origen) {
            return null;
        }

        $ancho  = imagesx($origen);
        $alto   = imagesy($origen);
        $lienzo = imagecreatetruecolor($ancho, $alto);
        imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255));
        imagecopy($lienzo, $origen, 0, 0, 0, 0, $ancho, $alto);

        ob_start();
        imagejpeg($lienzo, null, 90);
        $datos = (string)ob_get_clean();

        imagedestroy($origen);
        imagedestroy($lienzo);

        if (is_writable(dirname($ruta))) {
            @file_put_contents($cache, $datos);
        }

        return ['datos' => $datos, 'ancho' => $ancho, 'alto' => $alto];
    }

    /* ------------------------------------------------------------------ */
    /* Ensamblado del archivo                                              */
    /* ------------------------------------------------------------------ */

    /** Devuelve el PDF como cadena binaria. */
    public function contenido(): string
    {
        if ($this->numPagina === 0) {
            $this->nuevaPagina();
        }
        $this->dibujarPie();
        $this->paginas[] = $this->flujo;
        $this->flujo = '';

        $total = count($this->paginas);
        foreach ($this->paginas as $indice => $contenido) {
            $this->paginas[$indice] = str_replace(self::ALIAS_TOTAL, (string)$total, $contenido);
        }

        $objetos = [];   // índice 1..n
        $hayLogo = $this->logo !== null;

        // 1 Catálogo · 2 Páginas · 3 Helvetica · 4 Helvetica-Bold · 5 (imagen)
        $primeraPagina = $hayLogo ? 6 : 5;
        $idsPaginas = [];
        for ($i = 0; $i < $total; $i++) {
            $idsPaginas[] = $primeraPagina + $i * 2;
        }

        $objetos[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        $objetos[2] = "<< /Type /Pages /Kids [" . implode(' ', array_map(fn($id) => "$id 0 R", $idsPaginas))
            . "] /Count $total /MediaBox [0 0 " . sprintf('%.2F %.2F', $this->ancho, $this->alto) . "] >>";
        $objetos[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objetos[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        if ($hayLogo) {
            $objetos[5] = "<< /Type /XObject /Subtype /Image /Width {$this->logo['ancho']} /Height {$this->logo['alto']}"
                . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode"
                . " /Length " . strlen($this->logo['datos']) . " >>\nstream\n" . $this->logo['datos'] . "\nendstream";
        }

        $recursos = "<< /Font << /F1 3 0 R /F2 4 0 R >>"
            . ($hayLogo ? " /XObject << /Im1 5 0 R >>" : '') . " >>";

        foreach ($this->paginas as $indice => $contenido) {
            $idPagina   = $primeraPagina + $indice * 2;
            $idContenido = $idPagina + 1;

            $comprimido = function_exists('gzcompress') ? gzcompress($contenido) : $contenido;
            $filtro     = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';

            $objetos[$idPagina] = "<< /Type /Page /Parent 2 0 R /Resources $recursos /Contents $idContenido 0 R >>";
            $objetos[$idContenido] = "<<$filtro /Length " . strlen($comprimido) . " >>\nstream\n" . $comprimido . "\nendstream";
        }

        ksort($objetos);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $posiciones = [];
        foreach ($objetos as $id => $cuerpo) {
            $posiciones[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$cuerpo\nendobj\n";
        }

        $inicioXref = strlen($pdf);
        $cantidad   = count($objetos) + 1;

        $pdf .= "xref\n0 $cantidad\n0000000000 65535 f \n";
        for ($id = 1; $id < $cantidad; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $posiciones[$id] ?? 0);
        }

        $pdf .= "trailer\n<< /Size $cantidad /Root 1 0 R"
            . " /Info << /Title (" . $this->escapar($this->aCp1252((string)($this->cfgCabecera['titulo'] ?? 'Reporte'))) . ")"
            . " /Producer (Sistema de Proteccion de Datos REA) /CreationDate (D:" . date('YmdHis') . ") >> >>\n"
            . "startxref\n$inicioXref\n%%EOF";

        return $pdf;
    }

    /** Envía el PDF al navegador como descarga. */
    public function salida(string $nombreArchivo = 'reporte.pdf', bool $descargar = true): void
    {
        $contenido = $this->contenido();

        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . ($descargar ? 'attachment' : 'inline')
                . '; filename="' . $nombreArchivo . '"');
            header('Content-Length: ' . strlen($contenido));
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');
        }

        echo $contenido;
    }
}
