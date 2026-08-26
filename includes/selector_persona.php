<?php
/**
 * includes/selector_persona.php
 * -----------------------------------------------------------------------------
 * Selección de persona con subpantalla de búsqueda.
 *
 * La lógica del componente vive en includes/selector_entidad.php, que atiende
 * también la búsqueda de usuarios del sistema. Este archivo conserva las
 * funciones que ya usan los módulos (empleados, estudiantes, proveedores,
 * consentimientos y usuarios) para que sigan funcionando sin cambios.
 *
 * Uso en cualquier formulario:
 *
 *     require_once __DIR__ . '/../includes/selector_persona.php';
 *
 *     selectorPersona([
 *         'nombre'    => 'persona_id',
 *         'etiqueta'  => 'Persona',
 *         'requerido' => true,
 *         'valor'     => $registroEditar['PersonaId'] ?? '',
 *         'texto'     => nombreCompleto($registroEditar['Apellidos'] ?? null, $registroEditar['Nombres'] ?? null),
 *     ]);
 *
 * Para elegir un usuario del sistema (bitácora de auditoría):
 *
 *     selectorUsuario([
 *         'nombre'   => 'username',
 *         'etiqueta' => 'Usuario',
 *         'valor'    => $username,
 *     ]);
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/selector_entidad.php';

/** Campo de selección de persona (ver selectorEntidad() para las opciones). */
function selectorPersona(array $opciones): void
{
    $opciones['fuente'] = 'personas';
    $opciones['vacio']  = $opciones['vacio'] ?? 'Ninguna persona seleccionada';

    // Compatibilidad: si solo se conoce el código, se muestra «Persona #N»
    $valor = trim((string)($opciones['valor'] ?? ''));
    if ($valor !== '' && $valor !== '0' && trim((string)($opciones['texto'] ?? '')) === '') {
        $opciones['texto'] = 'Persona #' . $valor;
    }

    selectorEntidad($opciones);
}

/** Campo de selección de usuario del sistema; devuelve el nombre de usuario. */
function selectorUsuario(array $opciones): void
{
    $opciones['fuente'] = 'usuarios';
    $opciones['vacio']  = $opciones['vacio'] ?? 'Todos los usuarios';

    selectorEntidad($opciones);
}

/**
 * Resuelve el texto a mostrar para una persona ya seleccionada.
 *
 * Si el registro que se está editando ya trae los datos de la persona
 * (Nombres / Apellidos / Identificacion), se usan directamente; si no —por
 * ejemplo cuando el formulario se reenvía tras un error— se consultan a la API.
 *
 * @param  int|string $personaId
 * @param  array|null $conocidos ['Nombres' => ..., 'Apellidos' => ..., 'Identificacion' => ...]
 * @return array{texto:string, detalle:string}
 */
function personaResumen($personaId, ?array $conocidos = null): array
{
    $personaId = (int)$personaId;
    if ($personaId <= 0) {
        return ['texto' => '', 'detalle' => ''];
    }

    if (!empty($conocidos['Nombres']) || !empty($conocidos['Apellidos'])) {
        return [
            'texto'   => nombreCompleto($conocidos['Apellidos'] ?? null, $conocidos['Nombres'] ?? null),
            'detalle' => trim((string)($conocidos['Identificacion'] ?? '')),
        ];
    }

    static $cache = [];
    if (!isset($cache[$personaId])) {
        $persona = apiDatos(apiGet('personas/' . $personaId), null);
        $cache[$personaId] = $persona
            ? [
                'texto'   => nombreCompleto($persona['Apellidos'] ?? null, $persona['Nombres'] ?? null),
                'detalle' => trim((string)($persona['Identificacion'] ?? '')),
            ]
            : ['texto' => 'Persona #' . $personaId, 'detalle' => ''];
    }

    return $cache[$personaId];
}

/**
 * Compatibilidad: los módulos anteriores imprimían la subpantalla con este
 * nombre. Ahora la dibuja selectorEntidadModal() (una sola vez por página).
 */
function selectorPersonaModal(): void
{
    selectorEntidadModal();
}
