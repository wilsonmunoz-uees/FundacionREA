<?php
/**
 * api/core/Documento.php
 * -----------------------------------------------------------------------------
 * Reglas del documento de identidad, en un solo lugar.
 *
 * Qué se acepta según el tipo:
 *
 *   CEDULA     solo dígitos, hasta 10
 *   RUC        solo dígitos, hasta 13
 *   PASAPORTE  letras y dígitos (los pasaportes extranjeros los mezclan)
 *
 * El largo sale de dos sitios y manda el más estrecho:
 *
 *   · el del DOCUMENTO, que es una regla del país: la cédula ecuatoriana tiene
 *     diez dígitos y el RUC trece;
 *   · el de la COLUMNA donde el valor termina guardado, leído de la propia base.
 *     Así el formulario nunca deja escribir algo que la base vaya a recortar en
 *     silencio, y si mañana alguien estrecha la columna, se entera solo.
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
     * Provincias del Ecuador: los dos primeros dígitos de toda cédula y de todo
     * RUC. 01 a 24 son las provincias; 30 identifica a los ecuatorianos
     * registrados en consulados del exterior.
     */
    private const PROVINCIAS = [30];   // más el rango 1..24, que se comprueba aparte

    /**
     * Largos que se usan si no se puede consultar la base (por ejemplo, en una
     * instalación a medio actualizar). Son los del DDL vigente.
     */
    private const LARGO_POR_DEFECTO = [
        'persona.Identificacion' => 50,
        'proveedor.Ruc'          => 20,
    ];

    /**
     * Largo que admite cada documento, por lo que es el documento y no por lo
     * que quepa en la columna.
     *
     * El pasaporte no está: no hay un formato único internacional, así que para
     * él manda lo que admita la columna.
     */
    private const LARGO_POR_TIPO = [
        'CEDULA' => 10,
        'RUC'    => 13,
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
            $largo = min($largo, self::LARGO_POR_TIPO[self::tipoValido($tipo)] ?? $largo);
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

        if (mb_strlen($limpio) > $maximo) {
            $errores[] = 'El número de ' . $nombre . $de . ' no puede superar los '
                . $maximo . ' caracteres.';
        } elseif (mb_strlen($limpio) < 5) {
            $errores[] = 'El número de ' . $nombre . $de . ' es demasiado corto.';
        }

        /* Si ya hay algo mal en la forma, no se sigue: decirle a alguien que
           escribió letras Y que además el dígito verificador no cuadra es ruido,
           porque lo segundo es consecuencia de lo primero. */
        if ($errores !== []) {
            return $errores;
        }

        $problema = self::validarEstructura($tipo, $limpio);
        if ($problema !== null) {
            $errores[] = 'El número de ' . $nombre . $de . ' no es válido: ' . $problema;
        }

        return $errores;
    }

    /* ------------------------------------------------------------------ */
    /* Cédula y RUC ecuatorianos: la estructura, no solo el largo           */
    /* ------------------------------------------------------------------ */

    /**
     * Comprueba que el número sea realmente una cédula o un RUC del Ecuador.
     *
     * Antes bastaba con que fueran dígitos y cupieran en la columna, de modo que
     * `0000000000` o `1234567890` entraban sin protestar. Un padrón con cédulas
     * inventadas no se nota al cargarlo: se nota meses después, cuando hay que
     * responder por el consentimiento de una persona que no se puede identificar.
     *
     * @return string|null Motivo del rechazo, o null si el número es correcto.
     */
    public static function validarEstructura(string $tipo, string $numero): ?string
    {
        switch (self::tipoValido($tipo)) {
            case 'CEDULA':
                return self::problemaCedula($numero);
            case 'RUC':
                return self::problemaRuc($numero);
            default:
                return null;      // el pasaporte no tiene una forma que comprobar
        }
    }

    /** ¿Es una cédula ecuatoriana válida? */
    public static function esCedula(string $numero): bool
    {
        return self::problemaCedula($numero) === null;
    }

    /** ¿Es un RUC ecuatoriano válido? */
    public static function esRuc(string $numero): bool
    {
        return self::problemaRuc($numero) === null;
    }

    /**
     * Cédula: diez dígitos, provincia conocida, tercer dígito menor que seis y
     * dígito verificador por el algoritmo de módulo 10.
     */
    private static function problemaCedula(string $numero): ?string
    {
        if (!preg_match('/^\d{10}$/', $numero)) {
            return 'la cédula tiene exactamente 10 dígitos.';
        }

        $problema = self::problemaProvincia($numero);
        if ($problema !== null) {
            return $problema;
        }

        /* El tercer dígito distingue al tipo de registro. De 0 a 5 es una persona
           natural; 6 y 9 corresponden a entidades y solo aparecen en un RUC. */
        if ((int)$numero[2] > 5) {
            return 'el tercer dígito de una cédula de persona natural va de 0 a 5, y aquí es '
                . $numero[2] . '. Si es una empresa, elija RUC.';
        }

        if (!self::modulo10Correcto($numero)) {
            return 'el dígito verificador no corresponde. Revise que no haya un número cambiado.';
        }

        return null;
    }

    /**
     * RUC: trece dígitos, y su estructura depende del tercero, que dice de quién
     * es el registro.
     *
     *   0-5  persona natural   → los diez primeros dígitos son su cédula
     *   6    entidad pública   → verificador en la novena posición, módulo 11
     *   9    sociedad privada  → verificador en la décima posición, módulo 11
     *
     * Los tres últimos dígitos son el establecimiento: 001 la matriz, y de ahí
     * hacia arriba las sucursales. En el sector público son cuatro (0001).
     */
    private static function problemaRuc(string $numero): ?string
    {
        if (!preg_match('/^\d{13}$/', $numero)) {
            return 'el RUC tiene exactamente 13 dígitos.';
        }

        $problema = self::problemaProvincia($numero);
        if ($problema !== null) {
            return $problema;
        }

        $tercero = (int)$numero[2];

        if ($tercero <= 5) {
            // Persona natural: su RUC es la cédula más el establecimiento
            if (!self::modulo10Correcto(substr($numero, 0, 10))) {
                return 'el dígito verificador de la cédula que lleva dentro no corresponde.';
            }
            if ((int)substr($numero, 10, 3) < 1) {
                return 'los tres últimos dígitos son el establecimiento y empiezan en 001.';
            }
            return null;
        }

        if ($tercero === 6) {
            // Entidad pública: verificador en la posición 9
            if (!self::modulo11Correcto($numero, [3, 2, 7, 6, 5, 4, 3, 2], 8)) {
                return 'el dígito verificador no corresponde a un RUC del sector público.';
            }
            if ((int)substr($numero, 9, 4) < 1) {
                return 'los cuatro últimos dígitos son el establecimiento y empiezan en 0001.';
            }
            return null;
        }

        if ($tercero === 9) {
            // Sociedad privada o extranjera: verificador en la posición 10
            if (!self::modulo11Correcto($numero, [4, 3, 2, 7, 6, 5, 4, 3, 2], 9)) {
                return 'el dígito verificador no corresponde a un RUC de sociedad.';
            }
            if ((int)substr($numero, 10, 3) < 1) {
                return 'los tres últimos dígitos son el establecimiento y empiezan en 001.';
            }
            return null;
        }

        return 'el tercer dígito solo puede ser de 0 a 5 (persona natural), 6 (sector público) '
             . 'o 9 (sociedad), y aquí es ' . $tercero . '.';
    }

    /** Los dos primeros dígitos: 01 a 24, o 30 para los consulados. */
    private static function problemaProvincia(string $numero): ?string
    {
        $provincia = (int)substr($numero, 0, 2);

        if (($provincia >= 1 && $provincia <= 24) || in_array($provincia, self::PROVINCIAS, true)) {
            return null;
        }

        return 'los dos primeros dígitos son el código de provincia, del 01 al 24 '
             . '(o 30 para los registrados en el exterior), y aquí son '
             . substr($numero, 0, 2) . '.';
    }

    /**
     * Módulo 10, el de la cédula: se duplican las posiciones impares, a lo que
     * pase de 9 se le restan 9, y el verificador completa la decena.
     */
    private static function modulo10Correcto(string $diez): bool
    {
        $suma = 0;

        for ($i = 0; $i < 9; $i++) {
            $valor = (int)$diez[$i] * (($i % 2 === 0) ? 2 : 1);
            if ($valor > 9) {
                $valor -= 9;
            }
            $suma += $valor;
        }

        return ((10 - $suma % 10) % 10) === (int)$diez[9];
    }

    /**
     * Módulo 11, el de los RUC de entidades.
     *
     * @param int[] $coeficientes Uno por cada dígito que entra en la suma
     * @param int   $posicion     Índice (base 0) del dígito verificador
     */
    private static function modulo11Correcto(string $numero, array $coeficientes, int $posicion): bool
    {
        $suma = 0;

        foreach ($coeficientes as $i => $coeficiente) {
            $suma += (int)$numero[$i] * $coeficiente;
        }

        $residuo     = $suma % 11;
        $verificador = $residuo === 0 ? 0 : 11 - $residuo;

        return $verificador === (int)$numero[$posicion];
    }

    /**
     * Reglas listas para el navegador: es lo que consume assets/js/documento.js
     * a través de un atributo data- del formulario.
     *
     * @return array<string, array{patron:string, maximo:int, etiqueta:string, ayuda:string}>
     */
    public static function reglasParaFormulario(?PDO $db = null, string $contexto = 'persona'): array
    {
        $reglas = [];

        $ayudas = [
            'CEDULA' => 'Diez dígitos, sin guiones ni espacios. Se comprueba la provincia y el '
                      . 'dígito verificador.',
            'RUC'    => 'Trece dígitos, sin guiones ni espacios: el número de la empresa o de la '
                      . 'persona más el establecimiento (001 la matriz).',
        ];

        foreach (self::TIPOS as $tipo) {
            $letras = self::admiteLetras($tipo);
            $maximo = self::largoMaximo($db, $contexto, $tipo);
            /* En la cédula y el RUC el largo no es un tope, es una medida exacta:
               el navegador puede avisar en cuanto se completa. */
            $exacto = self::LARGO_POR_TIPO[$tipo] ?? 0;

            $reglas[$tipo] = [
                'patron'      => $letras ? '[^0-9A-Za-z]' : '[^0-9]',
                'maximo'      => $maximo,
                'exacto'      => ($exacto > 0 && $exacto <= $maximo) ? $exacto : 0,
                'verificador' => !$letras,   // hay dígito verificador que comprobar
                'etiqueta'    => self::ETIQUETAS[$tipo],
                'ayuda'       => $ayudas[$tipo]
                    ?? 'Letras y números, sin guiones ni espacios. Máximo ' . $maximo . ' caracteres.',
            ];
        }

        return $reglas;
    }
}
