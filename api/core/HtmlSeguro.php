<?php
/**
 * api/core/HtmlSeguro.php
 * -----------------------------------------------------------------------------
 * Saneamiento del texto enriquecido de los disclaimers.
 *
 * El texto se redacta en un editor visual y luego se muestra tal cual —sin
 * escapar— en una pantalla pública. Por eso, antes de guardarlo, se depura:
 * solo sobreviven las etiquetas y los atributos de la lista blanca, y todo lo
 * demás se descarta. Así un texto con código incrustado no puede convertirse en
 * un ataque contra quien abre el enlace.
 *
 * Se apoya en DOMDocument, que forma parte de PHP y no requiere instalar nada.
 * -----------------------------------------------------------------------------
 */

final class HtmlSeguro
{
    /** Etiquetas permitidas y, para cada una, sus atributos admitidos. */
    private const PERMITIDAS = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [],
        'u' => [], 's' => [], 'ul' => [], 'ol' => [], 'li' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'blockquote' => [], 'hr' => [],
        'a' => ['href', 'title'], 'span' => [], 'div' => [], 'small' => [],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
    ];

    /** Etiquetas cuyo contenido se elimina por completo, no solo la etiqueta. */
    private const PROHIBIDAS_CON_CONTENIDO = ['script', 'style', 'iframe', 'object', 'embed', 'form'];

    private const LARGO_MAXIMO = 60000;

    /** Devuelve el HTML depurado, listo para guardarse y mostrarse. */
    public static function limpiar(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if (mb_strlen($html) > self::LARGO_MAXIMO) {
            $html = mb_substr($html, 0, self::LARGO_MAXIMO);
        }

        if (!class_exists('DOMDocument')) {
            // Sin la extensión DOM se cae a un filtro simple, pero seguro
            return strip_tags($html, '<p><br><strong><em><u><ul><ol><li><h2><h3><h4>');
        }

        $documento = new DOMDocument('1.0', 'UTF-8');

        $anterior = libxml_use_internal_errors(true);
        // El envoltorio con charset evita que DOMDocument rompa los acentos
        $documento->loadHTML(
            '<?xml encoding="UTF-8"><div id="raiz">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        $raiz = $documento->getElementById('raiz');
        if ($raiz === null) {
            return '';
        }

        self::depurar($raiz);

        $salida = '';
        foreach ($raiz->childNodes as $hijo) {
            $salida .= $documento->saveHTML($hijo);
        }

        return trim($salida);
    }

    /** Versión en texto plano, útil para resúmenes y vistas previas. */
    public static function aTexto(string $html, int $largo = 0): string
    {
        $texto = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');

        if ($largo > 0 && mb_strlen($texto) > $largo) {
            $texto = mb_substr($texto, 0, $largo) . '…';
        }

        return $texto;
    }

    /* ------------------------------------------------------------------ */

    /** Recorre el árbol quitando lo que no está permitido. */
    private static function depurar(DOMNode $nodo): void
    {
        // Se recorre al revés porque se van eliminando nodos sobre la marcha
        for ($i = $nodo->childNodes->length - 1; $i >= 0; $i--) {
            $hijo = $nodo->childNodes->item($i);
            if ($hijo === null) {
                continue;
            }

            if ($hijo->nodeType === XML_TEXT_NODE) {
                continue;   // el texto siempre se conserva
            }

            if ($hijo->nodeType === XML_COMMENT_NODE) {
                $nodo->removeChild($hijo);
                continue;
            }

            if ($hijo->nodeType !== XML_ELEMENT_NODE || !($hijo instanceof DOMElement)) {
                $nodo->removeChild($hijo);
                continue;
            }

            $etiqueta = strtolower($hijo->nodeName);

            if (in_array($etiqueta, self::PROHIBIDAS_CON_CONTENIDO, true)) {
                $nodo->removeChild($hijo);
                continue;
            }

            if (!array_key_exists($etiqueta, self::PERMITIDAS)) {
                // Etiqueta no permitida: se conserva su contenido y se quita la etiqueta
                self::depurar($hijo);
                while ($hijo->firstChild !== null) {
                    $nodo->insertBefore($hijo->firstChild, $hijo);
                }
                $nodo->removeChild($hijo);
                continue;
            }

            self::limpiarAtributos($hijo, self::PERMITIDAS[$etiqueta]);
            self::depurar($hijo);
        }
    }

    private static function limpiarAtributos(DOMElement $elemento, array $permitidos): void
    {
        for ($i = $elemento->attributes->length - 1; $i >= 0; $i--) {
            $atributo = $elemento->attributes->item($i);
            if ($atributo === null) {
                continue;
            }

            $nombre = strtolower($atributo->nodeName);

            if (!in_array($nombre, $permitidos, true)) {
                $elemento->removeAttribute($atributo->nodeName);
                continue;
            }

            if ($nombre === 'href' && !self::enlaceSeguro((string)$atributo->nodeValue)) {
                $elemento->removeAttribute($atributo->nodeName);
            }
        }

        // Los enlaces que sobreviven se abren aparte y sin arrastrar la sesión
        if (strtolower($elemento->nodeName) === 'a' && $elemento->hasAttribute('href')) {
            $elemento->setAttribute('target', '_blank');
            $elemento->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /** Solo http, https, mailto y anclas: nada de javascript: ni data:. */
    private static function enlaceSeguro(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (str_starts_with($url, '#') || str_starts_with($url, '/')) {
            return true;
        }

        return (bool)preg_match('#^(https?://|mailto:)#i', $url);
    }
}
