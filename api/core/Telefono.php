<?php
/**
 * api/core/Telefono.php
 * -----------------------------------------------------------------------------
 * Reglas del número de teléfono, en un solo lugar.
 *
 * Qué se acepta:
 *
 *   · Solo dígitos, con un signo «+» opcional AL PRINCIPIO y en ningún otro
 *     sitio: es el prefijo internacional, no un separador.
 *   · Hasta 16 caracteres contando ese «+». La recomendación E.164 fija en 15
 *     el máximo de dígitos de un número internacional; con el signo delante,
 *     dieciséis.
 *   · Espacios, guiones y paréntesis se admiten AL ESCRIBIR y se quitan al
 *     guardar: nadie debería pelearse con el formato de un teléfono.
 *
 * Este archivo es la autoridad del servidor. El navegador aplica la misma regla
 * mientras se escribe —ver js/telefono.js—, pero eso es una comodidad para quien
 * captura: lo que decide es esto.
 *
 * DOS REGLAS, NO UNA
 *
 * Todo lo anterior vale para el teléfono de una PERSONA, que es un dato
 * personal y al que se le pide ser un número marcable.
 *
 * El teléfono de una INSTITUCIÓN es otra cosa: no es el dato de nadie, es la
 * forma de contacto que la escuela publica, y viene escrita como la escriben
 * ellas —«(04) 2345-678 ext. 102», «042345678 / 0999123456», «Central 2345678»—.
 * Recortarla a dieciséis dígitos obligaba a elegir cuál de los dos números
 * guardar. Para eso están los métodos `…Contacto()` del final: hasta 50
 * caracteres, con letras admitidas, y lo único que se exige es que haya algún
 * dígito, porque un contacto telefónico sin números no es un teléfono.
 * -----------------------------------------------------------------------------
 */

final class Telefono
{
    /** Tope de caracteres del valor ya limpio, incluido el «+». */
    public const LARGO_MAXIMO = 16;

    /** Por debajo de esto no es un teléfono, es un error de captura. */
    public const LARGO_MINIMO = 7;

    /** Lo que el valor limpio debe cumplir. Lo comparte el navegador. */
    public const PATRON = '^\\+?[0-9]{' . self::LARGO_MINIMO . ',15}$';

    /**
     * Deja el número como debe guardarse.
     *
     * Se conserva el «+» solo si venía delante; cualquier otro se descarta,
     * igual que los espacios, guiones y paréntesis con que la gente separa los
     * números.
     */
    public static function normalizar(string $valor): string
    {
        $valor = trim($valor);

        if ($valor === '') {
            return '';
        }

        $internacional = str_starts_with($valor, '+');
        $digitos       = (string)preg_replace('/[^0-9]/', '', $valor);

        if ($digitos === '') {
            return '';
        }

        $limpio = ($internacional ? '+' : '') . $digitos;

        return mb_substr($limpio, 0, self::LARGO_MAXIMO);
    }

    /**
     * Comprueba el número y devuelve los errores encontrados.
     *
     * Recibe el valor **tal como lo escribió la persona**, no el normalizado:
     * la idea es avisar de que escribió letras, no borrárselas sin decir nada.
     *
     * @param string $etiqueta    Cómo nombrar al titular ('la persona', 'el representante')
     * @param bool   $obligatorio true cuando sin teléfono el registro no sirve
     * @return string[]
     */
    public static function validar(
        string $valorCrudo,
        string $etiqueta = 'la persona',
        bool $obligatorio = false
    ): array {
        $de    = ' ' . Documento::contraer($etiqueta);
        $valor = trim($valorCrudo);

        if ($valor === '') {
            return $obligatorio ? ['Ingrese el teléfono' . $de . '.'] : [];
        }

        // Los separadores de escritura no cuentan como error
        $limpio = (string)preg_replace('/[\s()\-.]/', '', $valor);

        if (str_contains(mb_substr($limpio, 1), '+')) {
            return ['El teléfono' . $de . ' solo admite el signo + al principio, como prefijo internacional.'];
        }

        $sinSigno = ltrim($limpio, '+');

        if (preg_match('/[^0-9]/', $sinSigno)) {
            return ['El teléfono' . $de . ' solo puede tener dígitos numéricos, con un + opcional al inicio.'];
        }

        $errores = [];

        if (mb_strlen($limpio) > self::LARGO_MAXIMO) {
            $errores[] = 'El teléfono' . $de . ' no puede superar los ' . self::LARGO_MAXIMO
                . ' caracteres, contando el signo +.';
        } elseif (mb_strlen($sinSigno) < self::LARGO_MINIMO) {
            $errores[] = 'El teléfono' . $de . ' es demasiado corto: necesita al menos '
                . self::LARGO_MINIMO . ' dígitos.';
        }

        return $errores;
    }

    /**
     * Reglas listas para el navegador: es lo que consume js/telefono.js a través
     * de un atributo data- del formulario.
     *
     * @return array{patron:string, maximo:int, ayuda:string}
     */
    public static function reglasParaFormulario(): array
    {
        return [
            'patron' => self::PATRON,
            'maximo' => self::LARGO_MAXIMO,
            'ayuda'  => 'Solo números, con un + opcional al inicio para el prefijo internacional. '
                      . 'Máximo ' . self::LARGO_MAXIMO . ' caracteres.',
        ];
    }

    /* ================================================================== */
    /* Teléfono de contacto de una INSTITUCIÓN                             */
    /* ================================================================== */

    /** Tope de la columna `institucion_educativa`.`telefono`. */
    public const CONTACTO_LARGO_MAXIMO = 50;

    /**
     * Lo que NO se admite: los signos que no aparecen en ningún teléfono
     * escrito y que solo llegan aquí por un copiado y pegado desafortunado.
     *
     * Se define por exclusión, y no por una lista cerrada de lo permitido,
     * porque cada institución escribe su contacto a su manera y la lista se
     * quedaría corta el primer día.
     */
    private const CONTACTO_PROHIBIDO = '/[<>{}\[\]\\\\|^~`$%&*=;"]/';

    /**
     * Deja el contacto como debe guardarse: sin espacios de sobra y recortado
     * al largo de la columna. Nada más: lo que la institución escribió es lo
     * que se guarda.
     */
    public static function normalizarContacto(string $valor): string
    {
        // Varios espacios seguidos, tabuladores o saltos de línea pasan a ser
        // un espacio: se cuelan al pegar desde una página web o un documento.
        $limpio = trim((string)preg_replace('/\s+/u', ' ', $valor));

        return mb_substr($limpio, 0, self::CONTACTO_LARGO_MAXIMO);
    }

    /**
     * Comprueba el contacto telefónico de una institución.
     *
     * @return string[]
     */
    public static function validarContacto(string $valorCrudo, bool $obligatorio = true): array
    {
        $valor = trim((string)preg_replace('/\s+/u', ' ', $valorCrudo));

        if ($valor === '') {
            return $obligatorio ? ['Ingrese el teléfono de la institución.'] : [];
        }

        $errores = [];

        if (mb_strlen($valor) > self::CONTACTO_LARGO_MAXIMO) {
            $errores[] = 'El teléfono de la institución no puede superar los '
                . self::CONTACTO_LARGO_MAXIMO . ' caracteres.';
        }

        /* Sin un solo dígito no hay a quién llamar. Es la única forma de
           distinguir un teléfono mal escrito de un campo relleno por rellenar. */
        if (!preg_match('/[0-9]/', $valor)) {
            $errores[] = 'El teléfono de la institución debe incluir al menos un número.';
        }

        if (preg_match(self::CONTACTO_PROHIBIDO, $valor)) {
            $errores[] = 'El teléfono de la institución tiene signos que no corresponden '
                . 'a un número de contacto. Use letras, números y los separadores habituales '
                . '(espacios, guiones, paréntesis, barras, puntos o comas).';
        }

        return $errores;
    }

    /**
     * Reglas del contacto institucional, listas para la pantalla.
     *
     * @return array{maximo:int, ayuda:string}
     */
    public static function reglasContacto(): array
    {
        return [
            'maximo' => self::CONTACTO_LARGO_MAXIMO,
            'ayuda'  => 'Escríbalo como lo publica la institución: puede llevar letras, '
                      . 'extensiones o más de un número. Máximo '
                      . self::CONTACTO_LARGO_MAXIMO . ' caracteres.',
        ];
    }
}
