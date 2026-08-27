<?php
/**
 * includes/campos_persona.php
 * -----------------------------------------------------------------------------
 * Bloque de datos personales, compartido por los módulos que dependen de una
 * persona: empleados, estudiantes (titular y representante) y proveedores.
 *
 * `persona` es la entidad padre de todos ellos y no tiene mantenimiento propio,
 * así que cada uno captura aquí los datos y la ficha se crea o se reutiliza al
 * guardar. Tenerlo en un solo archivo evita que los tres formularios se vayan
 * separando con el tiempo.
 *
 * Uso:
 *
 *     require_once __DIR__ . '/../includes/campos_persona.php';
 *
 *     camposPersona([
 *         'titulo'    => 'Datos del empleado',
 *         'registro'  => $registroEditar,   // null al crear
 *         'correo'    => 'obligatorio',     // 'obligatorio' | 'opcional'
 *     ]);
 *
 * Para el representante de un estudiante, con el prefijo 'rep_':
 *
 *     camposPersona([
 *         'titulo'   => 'Datos del representante',
 *         'prefijo'  => 'rep_',
 *         'registro' => $registroEditar,
 *         'correo'   => 'obligatorio',
 *     ]);
 * -----------------------------------------------------------------------------
 */

/**
 * Reglas del documento de identidad, tal como las define el servidor
 * (`api/core/Documento.php`): qué caracteres admite cada tipo y hasta dónde
 * llega la columna de la base de datos.
 *
 * Se piden una sola vez por página: son datos de esquema, no de negocio.
 *
 * @param string $contexto 'persona' o 'proveedor' (su RUC tiene columna propia,
 *                         más corta, y es la que manda)
 */
function reglasDocumento(string $contexto = 'persona'): array
{
    static $cache = [];

    if (isset($cache[$contexto])) {
        return $cache[$contexto];
    }

    $reglas = apiDatos(apiGet('documento/reglas', ['contexto' => $contexto]), []);

    /* Si la API no responde, el formulario sigue siendo usable: se cae al
       criterio del DDL. El servidor valida igual al guardar. */
    if (!$reglas) {
        $reglas = [
            'CEDULA'    => ['patron' => '[^0-9]',      'maximo' => 50, 'ayuda' => 'Solo números, sin guiones ni espacios.'],
            'RUC'       => ['patron' => '[^0-9]',      'maximo' => 50, 'ayuda' => 'Solo números, sin guiones ni espacios.'],
            'PASAPORTE' => ['patron' => '[^0-9A-Za-z]','maximo' => 50, 'ayuda' => 'Letras y números, sin guiones ni espacios.'],
        ];
    }

    return $cache[$contexto] = $reglas;
}

/**
 * Imprime una sola vez el script que adapta el campo del documento al tipo
 * elegido. Se llama solo desde camposPersona().
 */
function scriptDocumento(): void
{
    static $impreso = false;

    if ($impreso) {
        return;
    }
    $impreso = true;

    echo '<script src="' . e(APP_ROOT) . 'js/documento.js" defer></script>';
}

/**
 * @param array $opciones titulo, ayuda, prefijo, registro, correo, documento
 */
function camposPersona(array $opciones): void
{
    $prefijo  = $opciones['prefijo'] ?? '';
    $registro = $opciones['registro'] ?? null;
    $titulo   = $opciones['titulo'] ?? 'Datos personales';
    $ayuda    = $opciones['ayuda'] ?? '';
    $correo   = ($opciones['correo'] ?? 'opcional') === 'obligatorio';

    /* Los datos del representante llegan con las columnas prefijadas Rep* en la
       consulta de la API (RepNombres, RepEmail...), mientras que los del titular
       vienen con su nombre normal. */
    $columna = $prefijo === 'rep_' ? 'Rep' : '';

    /** Valor a mostrar: lo que se acaba de escribir, o lo que ya estaba grabado. */
    $valor = static function (string $campo, string $columnaBd) use ($prefijo, $columna, $registro) {
        if (isset($_POST[$prefijo . $campo])) {
            return (string)$_POST[$prefijo . $campo];
        }
        return (string)($registro[$columna . $columnaBd] ?? '');
    };

    $tipos = ['CEDULA' => 'Cédula', 'RUC' => 'RUC', 'PASAPORTE' => 'Pasaporte'];
    $tipoActual = $valor('tipo_identificacion', 'TipoIdentificacion') ?: 'CEDULA';

    /* Reglas del documento —qué caracteres admite cada tipo y hasta dónde llega
       la columna de la base—, pedidas a la API para no duplicarlas aquí. */
    $reglas = reglasDocumento($opciones['documento'] ?? 'persona');
    $idCampo = $prefijo . 'identificacion';
    ?>
    <fieldset class="bloque-persona">
        <legend><?= e($titulo) ?></legend>

        <?php if ($ayuda !== ''): ?>
            <p class="form-ayuda bloque-persona-ayuda"><?= e($ayuda) ?></p>
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group" style="flex:0 1 170px;">
                <label for="<?= e($prefijo) ?>tipo_identificacion">Tipo de documento</label>
                <select name="<?= e($prefijo) ?>tipo_identificacion" id="<?= e($prefijo) ?>tipo_identificacion">
                    <?php foreach ($tipos as $clave => $etiqueta): ?>
                        <option value="<?= e($clave) ?>" <?= $tipoActual === $clave ? 'selected' : '' ?>>
                            <?= e($etiqueta) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:1 1 200px;">
                <label for="<?= e($idCampo) ?>" class="campo-requerido">Identificación</label>
                <input type="text" name="<?= e($idCampo) ?>" id="<?= e($idCampo) ?>"
                       maxlength="<?= (int)($reglas[$tipoActual]['maximo'] ?? 50) ?>" required autocomplete="off"
                       inputmode="<?= $tipoActual === 'PASAPORTE' ? 'text' : 'numeric' ?>"
                       data-tipo-campo="<?= e($prefijo) ?>tipo_identificacion"
                       data-ayuda-campo="<?= e($idCampo) ?>_ayuda"
                       data-reglas="<?= e(json_encode($reglas, JSON_UNESCAPED_UNICODE)) ?>"
                       value="<?= e($valor('identificacion', 'Identificacion')) ?>">
                <div class="form-ayuda" id="<?= e($idCampo) ?>_ayuda">
                    <?= e($reglas[$tipoActual]['ayuda'] ?? 'Solo números, sin guiones ni espacios.') ?>
                </div>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="<?= e($prefijo) ?>nombres" class="campo-requerido">Nombres</label>
                <input type="text" name="<?= e($prefijo) ?>nombres" id="<?= e($prefijo) ?>nombres"
                       maxlength="100" required value="<?= e($valor('nombres', 'Nombres')) ?>">
            </div>
            <div class="form-group">
                <label for="<?= e($prefijo) ?>apellidos" class="campo-requerido">Apellidos</label>
                <input type="text" name="<?= e($prefijo) ?>apellidos" id="<?= e($prefijo) ?>apellidos"
                       maxlength="100" required value="<?= e($valor('apellidos', 'Apellidos')) ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="<?= e($prefijo) ?>email" class="<?= $correo ? 'campo-requerido' : '' ?>">
                    Correo electrónico
                </label>
                <input type="email" name="<?= e($prefijo) ?>email" id="<?= e($prefijo) ?>email"
                       maxlength="150" <?= $correo ? 'required' : '' ?>
                       value="<?= e($valor('email', 'Email')) ?>">
                <?php if ($correo): ?>
                    <div class="form-ayuda">Es la dirección a la que llegan los avisos de consentimiento.</div>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="<?= e($prefijo) ?>telefono">Teléfono</label>
                <input type="text" name="<?= e($prefijo) ?>telefono" id="<?= e($prefijo) ?>telefono"
                       maxlength="20" value="<?= e($valor('telefono', 'Telefono')) ?>">
            </div>
        </div>
    </fieldset>
    <?php
    scriptDocumento();
}

/**
 * Recoge del formulario los campos de una persona, listos para enviarlos a la
 * API. Devuelve exactamente las claves que espera api/core/Padron.php.
 */
function datosPersonaDelFormulario(string $prefijo = ''): array
{
    $campo = static fn(string $clave): string => trim((string)($_POST[$prefijo . $clave] ?? ''));

    return [
        $prefijo . 'tipo_identificacion' => $campo('tipo_identificacion'),
        $prefijo . 'identificacion'      => $campo('identificacion'),
        $prefijo . 'nombres'             => $campo('nombres'),
        $prefijo . 'apellidos'           => $campo('apellidos'),
        $prefijo . 'email'               => $campo('email'),
        $prefijo . 'telefono'            => $campo('telefono'),
    ];
}
