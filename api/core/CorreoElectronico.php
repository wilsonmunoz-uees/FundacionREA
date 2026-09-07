<?php
/**
 * api/core/CorreoElectronico.php
 * -----------------------------------------------------------------------------
 * Reglas de la dirección de correo, en un solo lugar.
 *
 * El correo no es un dato más de la ficha: es el único camino por el que el
 * sistema alcanza al titular. Por ahí va el código que comprueba su identidad,
 * la invitación a consentir y la confirmación de lo que decidió. Una dirección
 * mal escrita no falla al guardarla —falla semanas después, cuando la persona
 * no aparece en la cobertura y nadie sabe por qué—.
 *
 * Antes cada sitio comprobaba el correo a su manera, siempre con un
 * `filter_var(..., FILTER_VALIDATE_EMAIL)` suelto. Esa función es correcta pero
 * generosa: acepta `juan@localhost`, `a@b` y `x@dominio.c`, direcciones que la
 * norma admite y que ningún proveedor de correo va a entregar. Aquí se le añade
 * lo que falta para que una dirección aceptada sea una dirección a la que de
 * verdad se le puede escribir.
 *
 * Qué se exige, además de la forma general:
 *
 *   · una sola arroba, con algo a cada lado;
 *   · parte local de hasta 64 caracteres, sin puntos al principio, al final ni
 *     dos seguidos;
 *   · dominio con al menos un punto —nada de `localhost`—, cada etiqueta de 1 a
 *     63 caracteres, sin guiones en los extremos;
 *   · extensión final de 2 a 24 letras: descarta `dominio.c` y `dominio.123`.
 *
 * Este archivo es la autoridad del servidor. El navegador aplica la misma regla
 * mientras se escribe —ver js/correo.js—, pero eso es una comodidad para quien
 * captura: lo que decide es esto.
 * -----------------------------------------------------------------------------
 */

final class CorreoElectronico
{
    /** Largo de la columna `persona`.`Email`, que es la más estrecha. */
    public const LARGO_MAXIMO = 150;

    /** Largo que la norma reserva a la parte anterior a la arroba. */
    private const LARGO_LOCAL = 64;

    /**
     * Patrón para el atributo `pattern` del formulario. No sustituye a validar():
     * es lo que permite al navegador avisar antes de enviar.
     */
    public const PATRON_HTML = "[^@\\s]+@[^@\\s.]+(\\.[^@\\s.]+)*\\.[A-Za-z]{2,24}";

    public const AYUDA = 'Con la forma nombre@dominio.ext, sin espacios. Es la dirección a la que '
                       . 'el sistema le escribe.';

    /* ------------------------------------------------------------------ */
    /* Normalización                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Deja la dirección como debe guardarse: sin espacios alrededor y en
     * minúsculas.
     *
     * Pasarla entera a minúsculas evita que la misma dirección escrita de dos
     * maneras parezca dos correos distintos. La norma permite que la parte
     * anterior a la arroba distinga mayúsculas, pero ningún proveedor lo aplica
     * y quien captura escribe en mayúsculas por costumbre, no por precisión.
     *
     * Lo que NO se hace es quitar los espacios de dentro. Un espacio en medio de
     * una dirección es un error de tecleo, y borrarlo en silencio convertiría
     * «ana mora@rea.com» en una dirección distinta de la que quiso escribir
     * quien la puso, sin que nadie se enterara. Eso se avisa, no se arregla.
     */
    public static function normalizar(string $valor): string
    {
        $valor = trim($valor);

        // Algunos gestores de correo pegan la dirección como «Nombre <a@b.com>»
        if (preg_match('/^[^<>]*<([^<>]+)>$/', $valor, $coincidencia)) {
            $valor = trim($coincidencia[1]);
        }

        return mb_strtolower($valor);
    }

    /* ------------------------------------------------------------------ */
    /* Comprobación                                                        */
    /* ------------------------------------------------------------------ */

    /** ¿Se le puede escribir a esta dirección? */
    public static function esValido(?string $valor): bool
    {
        return self::problema((string)$valor) === null;
    }

    /**
     * Comprueba la dirección y devuelve los errores, listos para mostrar.
     *
     * @param string $etiqueta    Cómo nombrar al titular ('la persona', 'el representante')
     * @param bool   $obligatorio true cuando sin correo el registro no sirve
     * @return string[]
     */
    public static function validar(
        string $valor,
        string $etiqueta = 'la persona',
        bool $obligatorio = false
    ): array {
        $de     = $etiqueta === '' ? '' : ' ' . Documento::contraer($etiqueta);
        $correo = self::normalizar($valor);

        if ($correo === '') {
            return $obligatorio ? ['Ingrese el correo electrónico' . $de . '.'] : [];
        }

        $problema = self::problema($correo);

        return $problema === null
            ? []
            : ['El correo electrónico' . $de . ' no es válido: ' . $problema];
    }

    /**
     * Motivo por el que la dirección no sirve, o null si sirve.
     *
     * Se devuelve el motivo concreto y no un «no es válido» a secas: quien está
     * corrigiendo un archivo de mil filas necesita saber qué mirar.
     */
    public static function problema(string $correo): ?string
    {
        $correo = self::normalizar($correo);

        if ($correo === '') {
            return 'está vacío.';
        }

        if (mb_strlen($correo) > self::LARGO_MAXIMO) {
            return 'no puede superar los ' . self::LARGO_MAXIMO . ' caracteres.';
        }

        if (preg_match('/\s/u', $correo)) {
            return 'no puede llevar espacios.';
        }

        if (substr_count($correo, '@') !== 1) {
            return 'debe llevar una sola arroba, con la forma nombre@dominio.ext.';
        }

        [$local, $dominio] = explode('@', $correo, 2);

        if ($local === '') {
            return 'falta el nombre antes de la arroba.';
        }
        if (mb_strlen($local) > self::LARGO_LOCAL) {
            return 'la parte anterior a la arroba no puede superar los ' . self::LARGO_LOCAL
                 . ' caracteres.';
        }
        if (str_starts_with($local, '.') || str_ends_with($local, '.') || str_contains($local, '..')) {
            return 'la parte anterior a la arroba no puede empezar ni terminar en punto, '
                 . 'ni llevar dos puntos seguidos.';
        }

        if ($dominio === '') {
            return 'falta el dominio después de la arroba.';
        }
        if (!str_contains($dominio, '.')) {
            return 'al dominio «' . $dominio . '» le falta la extensión: escriba, por ejemplo, '
                 . $dominio . '.com.';
        }
        if (mb_strlen($dominio) > 255) {
            return 'el dominio es demasiado largo.';
        }

        $etiquetas = explode('.', $dominio);
        foreach ($etiquetas as $etiqueta) {
            if ($etiqueta === '') {
                return 'el dominio no puede llevar dos puntos seguidos ni terminar en punto.';
            }
            if (mb_strlen($etiqueta) > 63) {
                return 'una parte del dominio es demasiado larga.';
            }
            if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/i', $etiqueta)) {
                return 'el dominio solo admite letras, números y guiones, y los guiones no pueden '
                     . 'ir al principio ni al final de cada parte.';
            }
        }

        $extension = end($etiquetas);
        if (!preg_match('/^[a-z]{2,24}$/i', $extension)) {
            return 'la extensión «.' . $extension . '» no es válida: son de 2 a 24 letras, '
                 . 'como .com, .ec o .edu.ec.';
        }

        /* Lo anterior cubre la estructura; esto último cubre lo que la norma dice
           de los caracteres admitidos en la parte local, que es más de lo que
           conviene escribir a mano aquí. */
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return 'tiene caracteres que no se admiten en una dirección de correo.';
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /* Para el formulario                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Reglas listas para el navegador: es lo que consume js/correo.js a través
     * de un atributo data- del propio campo.
     *
     * @return array{patron:string, maximo:int, ayuda:string}
     */
    public static function reglasParaFormulario(): array
    {
        return [
            'patron' => self::PATRON_HTML,
            'maximo' => self::LARGO_MAXIMO,
            'ayuda'  => self::AYUDA,
        ];
    }
}
