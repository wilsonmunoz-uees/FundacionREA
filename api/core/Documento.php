<?php
/**
 * api/core/Documento.php
 * -----------------------------------------------------------------------------
 * Reglas del documento de identidad, en un solo lugar.
 *
 * Qué se acepta según el tipo:
 *
 *   CEDULA     solo dígitos, exactamente 10
 *   RUC        solo dígitos, exactamente 13
 *   PASAPORTE  letras y dígitos (los pasaportes extranjeros los mezclan),
 *              entre 6 y 12 caracteres
 *
 * Se comprueba LA FORMA, no la validez del número. La cédula y el RUC
 * ecuatorianos llevan un dígito verificador que permitiría además descartar
 * números inventados; esa comprobación se retiró a petición de la Fundación,
 * porque el padrón trae documentos de personas extranjeras y registros
 * históricos que no la superan y que sí deben poder cargarse.
 *
 * La cédula y el RUC tienen un largo EXACTO —diez y trece dígitos—, que es una
 * regla del país. El pasaporte no tiene una medida única: cada país emisor usa
 * la suya, y por eso lo que se le exige es un RANGO, de 6 a 12 caracteres, que
 * cubre los formatos en uso sin dejar pasar un campo escrito a medias.
 *
 * El largo de la COLUMNA donde el valor termina guardado se lee de la propia
 * base y actúa como último techo: si mañana alguien la estrecha por debajo de
 * estos largos, el formulario se entera solo y nunca deja escribir algo que la
 * base vaya a recortar en silencio.
 *
 * Este archivo es la autoridad del servidor. El navegador aplica las mismas
 * reglas mientras se escribe —ver assets/js/documento.js—, pero eso es una
 * comodidad para quien captura: lo que decide es esto.
 * -----------------------------------------------------------------------------
 */

final class Documento
{
    public const TIPOS = ['CEDULA', 'RUC', 'PASAPORTE'];

    /** Etiquetas para los mensajes y para los desplegables. */
    public const ETIQUETAS = [
        'CEDULA'    => 'Cédula',
        'RUC'       => 'RUC',
        'PASAPORTE' => 'Pasaporte',
    ];

    /**
     * Largos que se usan si no se puede consultar la base (por ejemplo, en una
     * instalación a medio actualizar). Son los del DDL vigente.
     */
    private const LARGO_POR_DEFECTO = [
        'persona.Identificacion' => 50,
        'proveedor.Ruc'          => 20,
    ];

    /**
     * Largo EXACTO, por lo que es el documento y no por lo que quepa en la
     * columna. El pasaporte no está aquí: no tiene una medida única.
     */
    private const LARGO_EXACTO = [
        'CEDULA' => 10,
        'RUC'    => 13,
    ];

    /**
     * Largo mínimo de los documentos que no tienen medida exacta.
     *
     * Un pasaporte de menos de 6 caracteres no existe en ningún país emisor: lo
     * que suele haber detrás es un campo que se dejó a medio escribir.
     */
    private const LARGO_MINIMO = [
        'PASAPORTE' => 6,
    ];

    /**
     * Techo propio de cada tipo. Se aplica además del de la columna, y manda el
     * más estrecho de los dos.
     */
    private const LARGO_TOPE = [
        'CEDULA'    => 10,
        'RUC'       => 13,
        'PASAPORTE' => 12,
    ];

    /** Se consulta una vez por petición: son datos de esquema, no cambian. */
    private static array $largos = [];

    /* ------------------------------------------------------------------ */
    /* Largo permitido                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Largo de una columna de texto, tal como está declarada en la base.
     *
     * @param string $tabla   p. ej. 'persona'
     * @param string $columna p. ej. 'Identificacion'
     */
    public static function largoColumna(?PDO $db, string $tabla, string $columna): int
    {
        $clave = $tabla . '.' . $columna;

        if (isset(self::$largos[$clave])) {
            return self::$largos[$clave];
        }

        $largo = self::LARGO_POR_DEFECTO[$clave] ?? 50;

        if ($db !== null) {
            try {
                $stmt = $db->prepare(
                    'SELECT CHARACTER_MAXIMUM_LENGTH
                       FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME   = ?
                        AND COLUMN_NAME  = ?'
                );
                $stmt->execute([$tabla, $columna]);
                $valor = $stmt->fetchColumn();

                if ($valor !== false && (int)$valor > 0) {
                    $largo = (int)$valor;
                }
            } catch (PDOException $e) {
                // Sin permiso sobre information_schema en algún hospedaje:
                // se sigue con el valor del DDL, que es el correcto.
                error_log('[API] No se pudo leer el largo de ' . $clave . ': ' . $e->getMessage());
            }
        }

        return self::$largos[$clave] = $largo;
    }

    /**
     * Largo máximo del documento de una persona.
     *
     * En proveedores el mismo número se copia además a `proveedor`.`Ruc`, que es
     * más corta: manda la más estrecha de las dos, porque es la que recortaría.
     */
    public static function largoMaximo(?PDO $db, string $contexto = 'persona', ?string $tipo = null): int
    {
        $largo = self::largoColumna($db, 'persona', 'Identificacion');

        if ($contexto === 'proveedor') {
            $largo = min($largo, self::largoColumna($db, 'proveedor', 'Ruc'));
        }

        if ($tipo !== null) {
            $largo = min($largo, self::LARGO_TOPE[self::tipoValido($tipo)] ?? $largo);
        }

        return $largo;
    }

    /**
     * Largo mínimo exigible al documento.
     *
     * Nunca se pide más de lo que cabe: si alguien estrechara la columna por
     * debajo del mínimo del tipo, exigirlo dejaría el campo imposible de llenar.
     */
    public static function largoMinimo(?PDO $db, string $contexto = 'persona', ?string $tipo = null): int
    {
        $tipo = $tipo === null ? '' : self::tipoValido($tipo);

        $minimo = self::LARGO_EXACTO[$tipo] ?? self::LARGO_MINIMO[$tipo] ?? 0;

        return min($minimo, self::largoMaximo($db, $contexto, $tipo ?: null));
    }

    /* ------------------------------------------------------------------ */
    /* Reglas por tipo                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * «de» + «el empleado» es «del empleado». Sin esto los mensajes salen con
     * un «de el» que delata que la frase la armó una máquina.
     */
    public static function contraer(string $etiqueta): string
    {
        return str_starts_with($etiqueta, 'el ')
            ? 'del ' . mb_substr($etiqueta, 3)
            : 'de ' . $etiqueta;
    }

    public static function tipoValido(string $tipo): string
    {
        $tipo = mb_strtoupper(trim($tipo));

        return in_array($tipo, self::TIPOS, true) ? $tipo : 'CEDULA';
    }

    /** ¿Este tipo admite letras? Solo el pasaporte. */
    public static function admiteLetras(string $tipo): bool
    {
        return self::tipoValido($tipo) === 'PASAPORTE';
    }

    /**
     * Deja el valor como debe guardarse: sin espacios, guiones ni puntos, y sin
     * letras cuando el tipo no las admite.
     */
    public static function normalizar(string $tipo, string $valor, ?PDO $db = null, string $contexto = 'persona'): string
    {
        $patron = self::admiteLetras($tipo) ? '/[^0-9A-Za-z]/' : '/[^0-9]/';
        $limpio = (string)preg_replace($patron, '', trim($valor));

        if (self::admiteLetras($tipo)) {
            $limpio = mb_strtoupper($limpio);
        }

        return mb_substr($limpio, 0, self::largoMaximo($db, $contexto, $tipo));
    }

    /**
     * Comprueba el documento y devuelve los errores encontrados.
     *
     * Recibe el valor **tal como lo escribió la persona**, no el normalizado:
     * la idea es avisar de que escribió letras en una cédula, no borrárselas sin
     * decir nada.
     *
     * @param string $etiqueta Cómo nombrar al titular ('la persona', 'el representante')
     * @return string[]
     */
    public static function validar(
        string $tipo,
        string $valorCrudo,
        string $etiqueta = 'la persona',
        ?PDO $db = null,
        string $contexto = 'persona'
    ): array {
        $tipo     = self::tipoValido($tipo);
        $nombre   = self::ETIQUETAS[$tipo];
        /* Con la etiqueta vacía los mensajes van sin complemento: es lo que
           quieren los enlaces públicos, donde quien lee es el propio titular y
           un «de la persona» sonaría a que se habla de otro. */
        $de       = $etiqueta === '' ? '' : ' ' . self::contraer($etiqueta);
        $errores  = [];
        $maximo   = self::largoMaximo($db, $contexto, $tipo);
        $limpio   = (string)preg_replace('/[\s.\-]/', '', trim($valorCrudo));

        if ($limpio === '') {
            return ['Ingrese la identificación' . $de . '.'];
        }

        if (!self::admiteLetras($tipo)) {
            if (preg_match('/[^0-9]/', $limpio)) {
                $errores[] = 'El número de ' . $nombre . $de . ' solo puede tener dígitos numéricos.';
            }
        } elseif (preg_match('/[^0-9A-Za-z]/', $limpio)) {
            $errores[] = 'El número de pasaporte' . $de . ' solo puede tener letras y números.';
        }

        /* La cédula y el RUC tienen un largo EXACTO —diez y trece dígitos—, no un
           tope. El pasaporte no tiene una medida única: se le exige un rango,
           que es lo más que puede comprobarse sin saber qué país lo emitió. */
        $exacto = self::LARGO_EXACTO[$tipo] ?? 0;
        $largo  = mb_strlen($limpio);

        if ($exacto > 0) {
            if ($largo !== $exacto) {
                $errores[] = 'El número de ' . $nombre . $de . ' debe tener exactamente '
                    . $exacto . ' dígitos.';
            }
        } else {
            $minimo = self::largoMinimo($db, $contexto, $tipo);

            if ($largo > $maximo) {
                $errores[] = 'El número de ' . $nombre . $de . ' no puede superar los '
                    . $maximo . ' caracteres.';
            } elseif ($minimo > 0 && $largo < $minimo) {
                $errores[] = 'El número de ' . $nombre . $de . ' debe tener al menos '
                    . $minimo . ' caracteres.';
            }
        }

        return $errores;
    }

    /**
     * Reglas listas para el navegador: es lo que consume assets/js/documento.js
     * a través de un atributo data- del formulario.
     *
     * @return array<string, array{patron:string, maximo:int, minimo:int, exacto:int, etiqueta:string, ayuda:string}>
     */
    public static function reglasParaFormulario(?PDO $db = null, string $contexto = 'persona'): array
    {
        $reglas = [];

        $ayudas = [
            'CEDULA' => 'Exactamente 10 dígitos, sin guiones ni espacios.',
            'RUC'    => 'Exactamente 13 dígitos, sin guiones ni espacios.',
        ];

        foreach (self::TIPOS as $tipo) {
            $letras = self::admiteLetras($tipo);
            $maximo = self::largoMaximo($db, $contexto, $tipo);
            $minimo = self::largoMinimo($db, $contexto, $tipo);
            /* En la cédula y el RUC el largo no es un tope, es una medida exacta:
               el navegador puede avisar en cuanto se completa. */
            $exacto = self::LARGO_EXACTO[$tipo] ?? 0;

            $reglas[$tipo] = [
                'patron'      => $letras ? '[^0-9A-Za-z]' : '[^0-9]',
                'maximo'      => $maximo,
                'minimo'      => $minimo,
                'exacto'      => ($exacto > 0 && $exacto <= $maximo) ? $exacto : 0,
                'etiqueta'    => self::ETIQUETAS[$tipo],
                'ayuda'       => $ayudas[$tipo]
                    ?? 'Letras y números, sin guiones ni espacios. Entre ' . $minimo
                       . ' y ' . $maximo . ' caracteres.',
            ];
        }

        return $reglas;
    }
}
