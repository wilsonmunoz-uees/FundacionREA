<?php
/**
 * api/core/Documento.php
 * -----------------------------------------------------------------------------
 * Reglas del documento de identidad, en un solo lugar.
 *
 * Qué se acepta según el tipo:
 *
 *   CEDULA     solo dígitos
 *   RUC        solo dígitos
 *   PASAPORTE  letras y dígitos (los pasaportes extranjeros los mezclan)
 *
 * El largo máximo NO se inventa aquí: se lee de la propia base de datos, de la
 * columna donde el valor va a terminar guardado. Así el formulario nunca deja
 * escribir algo que la base vaya a recortar en silencio, y si mañana alguien
 * amplía la columna, el formulario se entera solo.
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
    public static function largoMaximo(?PDO $db, string $contexto = 'persona'): int
    {
        $largo = self::largoColumna($db, 'persona', 'Identificacion');

        if ($contexto === 'proveedor') {
            $largo = min($largo, self::largoColumna($db, 'proveedor', 'Ruc'));
        }

        return $largo;
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

        return mb_substr($limpio, 0, self::largoMaximo($db, $contexto));
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
        $de       = ' ' . self::contraer($etiqueta);
        $errores  = [];
        $maximo   = self::largoMaximo($db, $contexto);
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

        if (mb_strlen($limpio) > $maximo) {
            $errores[] = 'El número de ' . $nombre . $de . ' no puede superar los '
                . $maximo . ' caracteres.';
        } elseif (mb_strlen($limpio) < 5) {
            $errores[] = 'El número de ' . $nombre . $de . ' es demasiado corto.';
        }

        return $errores;
    }

    /**
     * Reglas listas para el navegador: es lo que consume assets/js/documento.js
     * a través de un atributo data- del formulario.
     *
     * @return array<string, array{patron:string, maximo:int, etiqueta:string, ayuda:string}>
     */
    public static function reglasParaFormulario(?PDO $db = null, string $contexto = 'persona'): array
    {
        $maximo = self::largoMaximo($db, $contexto);
        $reglas = [];

        foreach (self::TIPOS as $tipo) {
            $letras = self::admiteLetras($tipo);

            $reglas[$tipo] = [
                'patron'   => $letras ? '[^0-9A-Za-z]' : '[^0-9]',
                'maximo'   => $maximo,
                'etiqueta' => self::ETIQUETAS[$tipo],
                'ayuda'    => $letras
                    ? 'Letras y números, sin guiones ni espacios. Máximo ' . $maximo . ' caracteres.'
                    : 'Solo números, sin guiones ni espacios. Máximo ' . $maximo . ' dígitos.',
            ];
        }

        return $reglas;
    }
}
