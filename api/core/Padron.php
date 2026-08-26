<?php
/**
 * api/core/Padron.php
 * -----------------------------------------------------------------------------
 * Alta y actualización de personas dentro del padrón de una institución.
 *
 * `persona` es la entidad PADRE de empleados, estudiantes, representantes y
 * proveedores, y no tiene mantenimiento propio: sus fichas nacen siempre desde
 * uno de esos módulos, desde los enlaces públicos o desde la PreCarga Inicial.
 *
 * Este archivo es el único lugar donde se escribe en `persona`. Antes la misma
 * lógica estaba repetida en el flujo público y en la PreCarga; tenerla aquí
 * evita que las reglas se separen con el tiempo.
 *
 * Reglas que aplica:
 *
 *   · La identificación es la llave natural DENTRO de la institución. Si ya hay
 *     una ficha con ese documento, se reutiliza y se completa; no se duplica.
 *     Por eso la misma persona puede ser empleada y, a la vez, representante de
 *     un estudiante, con una sola ficha.
 *   · Nunca se pisa un dato con un vacío: al reutilizar una ficha, el correo y
 *     el teléfono solo se sobrescriben si vienen con contenido.
 *   · Dos instituciones no comparten fichas. Todo va acotado a la que se indica.
 * -----------------------------------------------------------------------------
 */

final class Padron
{
    public const TIPOS_ID = ['CEDULA', 'RUC', 'PASAPORTE'];

    /* ------------------------------------------------------------------ */
    /* Lectura                                                             */
    /* ------------------------------------------------------------------ */

    /** Ficha de una persona por su documento, dentro de la institución. */
    public static function porIdentificacion(PDO $db, int $institucionId, string $identificacion): ?array
    {
        $stmt = $db->prepare(
            'SELECT * FROM persona
              WHERE InstitucionEducativaId = ? AND Identificacion = ?
              LIMIT 1'
        );
        $stmt->execute([$institucionId, $identificacion]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /** Ficha por identificador, dentro de la institución. */
    public static function porId(PDO $db, int $institucionId, int $personaId): ?array
    {
        $stmt = $db->prepare(
            'SELECT * FROM persona WHERE PersonaId = ? AND InstitucionEducativaId = ? LIMIT 1'
        );
        $stmt->execute([$personaId, $institucionId]);
        $fila = $stmt->fetch();

        return $fila === false ? null : $fila;
    }

    /** ¿Esta persona pertenece a esta institución? */
    public static function perteneceA(PDO $db, int $personaId, int $institucionId): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM persona WHERE PersonaId = ? AND InstitucionEducativaId = ? LIMIT 1'
        );
        $stmt->execute([$personaId, $institucionId]);

        return $stmt->fetchColumn() !== false;
    }

    /* ------------------------------------------------------------------ */
    /* Normalización y validación                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Recoge los datos personales que envía un formulario y los deja listos
     * para guardar.
     *
     * @param array  $entrada  Cuerpo de la petición
     * @param string $prefijo  'rep_' para los campos del representante
     * @return array{identificacion:string,tipo:string,nombres:string,apellidos:string,email:string,telefono:string,estado:string}
     */
    public static function normalizar(array $entrada, string $prefijo = ''): array
    {
        $campo = static function (string $clave) use ($entrada, $prefijo): string {
            return trim((string)($entrada[$prefijo . $clave] ?? ''));
        };

        $identificacion = (string)preg_replace('/[^0-9A-Za-z]/', '', $campo('identificacion'));
        $tipo           = mb_strtoupper($campo('tipo_identificacion'));

        if ($tipo === '') {
            // Se deduce por la longitud: 13 dígitos es RUC en Ecuador
            $tipo = (strlen($identificacion) === 13 && ctype_digit($identificacion)) ? 'RUC' : 'CEDULA';
        }

        $estado = mb_strtoupper($campo('estado'));

        return [
            'identificacion' => mb_substr($identificacion, 0, 50),
            'tipo'           => in_array($tipo, self::TIPOS_ID, true) ? $tipo : 'CEDULA',
            'nombres'        => mb_substr($campo('nombres'), 0, 100),
            'apellidos'      => mb_substr($campo('apellidos'), 0, 100),
            'email'          => mb_substr($campo('email'), 0, 150),
            'telefono'       => mb_substr($campo('telefono'), 0, 20),
            'estado'         => in_array($estado, ['ACTIVO', 'INACTIVO'], true) ? $estado : 'ACTIVO',
        ];
    }

    /**
     * Comprueba los datos personales y devuelve la lista de errores.
     *
     * @param string $etiqueta    Cómo nombrar a la persona en los mensajes
     *                            ('la persona', 'el representante', ...)
     * @param bool   $exigeCorreo true cuando sin correo el registro no sirve
     * @return string[]
     */
    public static function validar(array $datos, string $etiqueta = 'la persona', bool $exigeCorreo = false): array
    {
        $errores = [];
        $de      = ' de ' . $etiqueta;

        if ($datos['identificacion'] === '') {
            $errores[] = 'Ingrese la identificación' . $de . '.';
        } elseif (mb_strlen($datos['identificacion']) < 5) {
            $errores[] = 'La identificación' . $de . ' es demasiado corta.';
        }

        if ($datos['nombres'] === '')   { $errores[] = 'Ingrese los nombres' . $de . '.'; }
        if ($datos['apellidos'] === '') { $errores[] = 'Ingrese los apellidos' . $de . '.'; }

        if ($datos['email'] === '') {
            if ($exigeCorreo) {
                $errores[] = 'Ingrese el correo electrónico' . $de . '.';
            }
        } elseif (!filter_var($datos['email'], FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El correo electrónico' . $de . ' no es válido.';
        }

        return $errores;
    }

    /* ------------------------------------------------------------------ */
    /* Escritura                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Crea la ficha o actualiza la que ya existe con ese documento.
     *
     * Es la operación que usan las altas: si la persona ya está en el padrón
     * —por ejemplo, porque es representante de un estudiante y ahora se la
     * registra como empleada—, se reutiliza su ficha en lugar de duplicarla.
     *
     * @return int PersonaId
     */
    public static function crearOActualizar(PDO $db, int $institucionId, array $datos): int
    {
        $existente = self::porIdentificacion($db, $institucionId, $datos['identificacion']);

        if ($existente !== null) {
            $personaId = (int)$existente['PersonaId'];
            self::actualizar($db, $institucionId, $personaId, $datos, false);

            return $personaId;
        }

        $stmt = $db->prepare(
            'INSERT INTO persona
                (InstitucionEducativaId, TipoIdentificacion, Identificacion,
                 Nombres, Apellidos, Email, Telefono, Estado)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $institucionId,
            $datos['tipo'],
            $datos['identificacion'],
            $datos['nombres'],
            $datos['apellidos'],
            $datos['email'] !== '' ? $datos['email'] : null,
            $datos['telefono'] !== '' ? $datos['telefono'] : null,
            $datos['estado'],
        ]);

        return (int)$db->lastInsertId();
    }

    /**
     * Actualiza una ficha concreta. La usan las ediciones, donde la persona ya
     * está determinada por el vínculo que se está modificando.
     *
     * @param bool $pisarVacios true en las ediciones: si el formulario deja el
     *                          correo en blanco, es porque se quiere borrar.
     *                          false en las altas, para no perder lo que ya
     *                          constaba.
     */
    public static function actualizar(
        PDO $db,
        int $institucionId,
        int $personaId,
        array $datos,
        bool $pisarVacios = true
    ): void {
        if ($pisarVacios) {
            $sql = 'UPDATE persona
                       SET TipoIdentificacion = ?, Identificacion = ?, Nombres = ?, Apellidos = ?,
                           Email = ?, Telefono = ?, Estado = ?
                     WHERE PersonaId = ? AND InstitucionEducativaId = ?';
            $parametros = [
                $datos['tipo'], $datos['identificacion'], $datos['nombres'], $datos['apellidos'],
                $datos['email'] !== '' ? $datos['email'] : null,
                $datos['telefono'] !== '' ? $datos['telefono'] : null,
                $datos['estado'], $personaId, $institucionId,
            ];
        } else {
            // Alta sobre una ficha existente: no se borra lo que ya había
            $sql = 'UPDATE persona
                       SET TipoIdentificacion = ?, Nombres = ?, Apellidos = ?,
                           Email    = COALESCE(NULLIF(?, \'\'), Email),
                           Telefono = COALESCE(NULLIF(?, \'\'), Telefono),
                           Estado   = ?
                     WHERE PersonaId = ? AND InstitucionEducativaId = ?';
            $parametros = [
                $datos['tipo'], $datos['nombres'], $datos['apellidos'],
                $datos['email'], $datos['telefono'],
                $datos['estado'], $personaId, $institucionId,
            ];
        }

        $db->prepare($sql)->execute($parametros);
    }

    /**
     * ¿El documento ya lo tiene OTRA persona de la institución?
     * Lo consultan las ediciones antes de cambiar una identificación.
     */
    public static function documentoOcupado(PDO $db, int $institucionId, string $identificacion, int $exceptoPersonaId): bool
    {
        $stmt = $db->prepare(
            'SELECT 1 FROM persona
              WHERE InstitucionEducativaId = ? AND Identificacion = ? AND PersonaId <> ?
              LIMIT 1'
        );
        $stmt->execute([$institucionId, $identificacion, $exceptoPersonaId]);

        return $stmt->fetchColumn() !== false;
    }
}
