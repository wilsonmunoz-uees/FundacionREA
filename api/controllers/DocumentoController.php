<?php
/**
 * api/controllers/DocumentoController.php
 * -----------------------------------------------------------------------------
 * Reglas del documento de identidad, para que las pantallas no las dupliquen.
 *
 * Devuelve qué caracteres admite cada tipo y hasta dónde llega la columna de la
 * base de datos, leída de la propia base. Los formularios lo usan para adaptar
 * el campo mientras se escribe; quien decide sigue siendo el servidor, en
 * `api/core/Documento.php`, al guardar.
 *
 * Es información de esquema, no de datos: basta con estar autenticado.
 * -----------------------------------------------------------------------------
 */

final class DocumentoController extends Controller
{
    /** GET api/documento/reglas?contexto=persona|proveedor */
    public function reglas(array $ruta = []): void
    {
        $contexto = $this->peticion->paramTexto('contexto', 'persona');

        if (!in_array($contexto, ['persona', 'proveedor'], true)) {
            $contexto = 'persona';
        }

        /* Las reglas del teléfono viajan en la misma respuesta, dentro de la
           metadata: el formulario que pregunta por el documento es siempre el
           mismo que captura el teléfono, y así no hace falta una segunda vuelta
           a la API por un dato que tampoco cambia. */
        Response::exito(
            Documento::reglasParaFormulario($this->db, $contexto),
            [
                'contexto' => $contexto,
                'telefono' => Telefono::reglasParaFormulario(),
            ]
        );
    }
}
