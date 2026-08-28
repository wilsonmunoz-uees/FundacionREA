<?php
/**
 * api/core/Password.php
 * -----------------------------------------------------------------------------
 * Política de contraseñas del sistema, en un solo lugar.
 *
 * Una contraseña vale si cumple TODO esto:
 *
 *   · al menos 8 caracteres
 *   · al menos una letra mayúscula
 *   · al menos una letra minúscula
 *   · al menos un dígito
 *
 * y además solo usa caracteres del juego permitido: letras, dígitos y los
 * especiales * ! - _  Los especiales son opcionales: suman, pero no se exigen.
 *
 * Se admite un juego cerrado a propósito. El sistema corre en hospedaje
 * compartido y las claves se escriben, se dictan por teléfono y se copian de un
 * papel; un juego corto y sin ambigüedades evita el clásico «la clave no me
 * funciona» que en realidad es un acento, una comilla curva de Word o un espacio
 * al final.
 *
 * OJO: esto valida la contraseña que se va a GRABAR. No tiene relación con
 * `Auth::VALIDAR_PASSWORD`, que decide si al iniciar sesión se comprueba el hash
 * y que sigue en `false` por indicación del proyecto.
 * -----------------------------------------------------------------------------
 */

final class Password
{
    public const LARGO_MINIMO = 8;

    /** Especiales admitidos. Opcionales: no se exige ninguno. */
    public const ESPECIALES = '*!-_';

    /** Todo lo que no esté aquí se rechaza. */
    private const PERMITIDOS = '/^[A-Za-z0-9*!\-_]+$/';

    /* ------------------------------------------------------------------ */
    /* Validación                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Devuelve la lista de incumplimientos. Vacía = la contraseña sirve.
     *
     * Se devuelven TODOS los que falten, no solo el primero: quien está creando
     * una clave prefiere enterarse de una vez de todo lo que le falta.
     *
     * @return string[]
     */
    public static function validar(string $clave): array
    {
        $errores = [];

        if (mb_strlen($clave) < self::LARGO_MINIMO) {
            $errores[] = 'La contraseña debe tener al menos ' . self::LARGO_MINIMO . ' caracteres.';
        }
        if (!preg_match('/[A-Z]/', $clave)) {
            $errores[] = 'La contraseña debe incluir al menos una letra mayúscula.';
        }
        if (!preg_match('/[a-z]/', $clave)) {
            $errores[] = 'La contraseña debe incluir al menos una letra minúscula.';
        }
        if (!preg_match('/[0-9]/', $clave)) {
            $errores[] = 'La contraseña debe incluir al menos un número.';
        }
        if ($clave !== '' && !preg_match(self::PERMITIDOS, $clave)) {
            $errores[] = 'La contraseña solo admite letras, números y los signos '
                . self::ESPECIALES . ' (estos últimos son opcionales).';
        }

        return $errores;
    }

    /** ¿Cumple la política? */
    public static function valida(string $clave): bool
    {
        return self::validar($clave) === [];
    }

    /* ------------------------------------------------------------------ */
    /* Generador                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Arma una contraseña que cumple la política.
     *
     * Deja fuera los caracteres que se confunden al leerlos —O con 0, l con 1,
     * I con l—, porque estas claves se dictan y se transcriben.
     */
    public static function generar(int $largo = 12): string
    {
        $largo = max(self::LARGO_MINIMO, min($largo, 32));

        $mayusculas = 'ABCDEFGHJKLMNPQRSTUVWXYZ';   // sin I ni O
        $minusculas = 'abcdefghijkmnpqrstuvwxyz';   // sin l ni o
        $digitos    = '23456789';                   // sin 0 ni 1
        $especiales = self::ESPECIALES;

        // Una de cada clase exigida, para que el resultado cumpla siempre.
        $clave = [
            self::unoDe($mayusculas),
            self::unoDe($minusculas),
            self::unoDe($digitos),
            self::unoDe($especiales),
        ];

        $todos = $mayusculas . $minusculas . $digitos . $especiales;
        while (count($clave) < $largo) {
            $clave[] = self::unoDe($todos);
        }

        // Barajado con aleatoriedad criptográfica: shuffle() no lo es.
        for ($i = count($clave) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$clave[$i], $clave[$j]] = [$clave[$j], $clave[$i]];
        }

        return implode('', $clave);
    }

    private static function unoDe(string $juego): string
    {
        return $juego[random_int(0, strlen($juego) - 1)];
    }

    /* ------------------------------------------------------------------ */
    /* Para las pantallas                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Las reglas en texto, para mostrarlas junto al campo y para que el
     * navegador pinte la lista de comprobación mientras se escribe.
     *
     * @return array<int, array{clave:string, texto:string, patron:string}>
     */
    public static function reglas(): array
    {
        return [
            ['clave' => 'largo',      'texto' => 'Al menos ' . self::LARGO_MINIMO . ' caracteres', 'patron' => '.{' . self::LARGO_MINIMO . ',}'],
            ['clave' => 'mayuscula',  'texto' => 'Una letra mayúscula',                            'patron' => '[A-Z]'],
            ['clave' => 'minuscula',  'texto' => 'Una letra minúscula',                            'patron' => '[a-z]'],
            ['clave' => 'digito',     'texto' => 'Un número',                                      'patron' => '[0-9]'],
            ['clave' => 'permitidos', 'texto' => 'Solo letras, números y ' . self::ESPECIALES . ' (opcionales)', 'patron' => '^[A-Za-z0-9*!\\-_]+$'],
        ];
    }
}
