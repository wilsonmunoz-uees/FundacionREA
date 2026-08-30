<?php
// api/controllers/PersonasController.php
// Lectura del padrón de personas de la institución y ficha 360°.
//
// `persona` es la entidad PADRE de empleados, estudiantes, representantes y
// proveedores, y NO tiene mantenimiento propio: no se crea, edita ni da de baja
// desde aquí. Sus fichas nacen desde esos módulos, desde los enlaces públicos o
// desde la Carga de Información, y la escritura vive en api/core/Padron.php.
//
// Este controlador solo lee: listados para elegir persona, la ficha 360° y el
// combo de opciones.
//
// `persona` pertenece a una institución: cada consulta y cada escritura van
// acotadas a la del token, igual que empleado, estudiante o proveedor. Dos
// instituciones pueden tener a la misma persona, cada una con su propia ficha,
// y ninguna ve la de la otra.

final class PersonasController extends Controller
{
    private const ROLES = ['SuperAdmin', 'RecursosHumanos', 'Secretaria'];
    /** Clave en includes/accesos.php */
    private const MODULO_LECTURA = 'personas_lectura';

    /** GET /api/personas?q=&estado=&excluir=&sin_usuario=&pagina=&por_pagina= */
    public function index(array $ruta = []): void
    {
        // Lectura del directorio: la usan también los módulos que eligen persona
        $this->requiereAcceso(self::MODULO_LECTURA);

        $institucionId = $this->institucion();

        $buscar = $this->like($this->peticion->paramTexto('q'));
        $where  = 'FROM persona
                    WHERE InstitucionEducativaId = ?
                      AND (Nombres LIKE ? OR Apellidos LIKE ? OR Identificacion LIKE ? OR Email LIKE ?)';
        $params = [$institucionId, $buscar, $buscar, $buscar, $buscar];

        $estadoFiltro = strtoupper($this->peticion->paramTexto('estado'));
        if (in_array($estadoFiltro, ['ACTIVO', 'INACTIVO'], true)) {
            $where   .= ' AND Estado = ?';
            $params[] = $estadoFiltro;
        }

        // Excluye una persona concreta (p. ej. el titular al elegir su representante)
        $excluir = $this->peticion->paramEntero('excluir');
        if ($excluir > 0) {
            $where   .= ' AND PersonaId <> ?';
            $params[] = $excluir;
        }

        // Solo personas que todavía no tienen cuenta de usuario en esta institución
        if ($this->peticion->paramEntero('sin_usuario') === 1) {
            $where   .= ' AND PersonaId NOT IN (SELECT PersonaId FROM usuario WHERE InstitucionEducativaId = ?)';
            $params[] = $institucionId;
        }

        $total = $this->contar("SELECT COUNT(*) total $where", $params);
        [$pagina, $porPagina, $offset] = $this->paginacion(12);

        $datos = $this->consultar(
            "SELECT * $where ORDER BY Apellidos, Nombres LIMIT $offset, $porPagina",
            $params
        );

        Response::lista($datos, $total, $pagina, $porPagina);
    }

    /**
     * GET /api/personas/opciones
     * Personas activas listas para alimentar los <select> del sistema.
     */
    public function opciones(array $ruta = []): void
    {
        $this->requiereAutenticacion();

        $datos = $this->consultar(
            "SELECT PersonaId,
                    CONCAT(Apellidos, ' ', Nombres, ' - ', COALESCE(Identificacion,'S/I')) AS etiqueta
               FROM persona
              WHERE InstitucionEducativaId = ? AND Estado = 'ACTIVO'
           ORDER BY Apellidos, Nombres",
            [$this->institucion()]
        );

        Response::exito($datos);
    }

    /** GET /api/personas/{id} */
    public function show(array $ruta): void
    {
        $this->requiereAutenticacion();

        $registro = $this->personaDe((int)$ruta['id']);
        if (!$registro) {
            Response::noEncontrado();
        }
        Response::exito($registro);
    }

    /**
     * GET /api/personas/{id}/ficha
     * Vista 360°: datos personales + vínculos institucionales + consentimientos.
     */
    public function ficha(array $ruta): void
    {
        $this->requiereAutenticacion();
        $institucionId = $this->institucion();
        $personaId     = (int)$ruta['id'];

        $persona = $this->personaDe($personaId, $institucionId);
        if (!$persona) {
            Response::noEncontrado('La persona solicitada no existe en esta institución.');
        }

        Response::exito([
            'persona'    => $persona,
            'empleado'   => $this->consultarUna(
                'SELECT * FROM empleado WHERE PersonaId = ? AND InstitucionEducativaId = ?',
                [$personaId, $institucionId]
            ),
            'estudiante' => $this->consultarUna(
                'SELECT * FROM estudiante WHERE PersonaId = ? AND InstitucionEducativaId = ?',
                [$personaId, $institucionId]
            ),
            'proveedor'  => $this->consultarUna(
                'SELECT * FROM proveedor WHERE PersonaId = ? AND InstitucionEducativaId = ?',
                [$personaId, $institucionId]
            ),
            'usuario'    => $this->consultarUna(
                'SELECT UsuarioId, Username, Email, UltimoAcceso, Estado
                   FROM usuario WHERE PersonaId = ? AND InstitucionEducativaId = ?',
                [$personaId, $institucionId]
            ),
            'consentimientos' => $this->consultar(
                'SELECT c.*, f.Nombre AS FinalidadNombre
                   FROM consentimiento c
              LEFT JOIN finalidad f ON f.FinalidadId = c.FinalidadId
                  WHERE c.PersonaId = ? AND c.InstitucionEducativaId = ?
               ORDER BY c.FechaConsentimiento DESC',
                [$personaId, $institucionId]
            ),
        ]);
    }




    /* ------------------------------------------------------------------ */

    /**
     * Lee una persona del padrón de una institución.
     *
     * Es el único punto por el que el sistema resuelve un PersonaId a su ficha:
     * así nunca se devuelve la persona de otra institución aunque el
     * identificador venga escrito a mano en la URL.
     */
    public function personaDe(int $personaId, ?int $institucionId = null): ?array
    {
        return $this->consultarUna(
            'SELECT * FROM persona WHERE PersonaId = ? AND InstitucionEducativaId = ?',
            [$personaId, $institucionId ?? $this->institucion()]
        );
    }


}
