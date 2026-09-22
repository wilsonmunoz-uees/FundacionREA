<?php
// api/controllers/SetupController.php
// Instalación inicial: crea la persona, el usuario SuperAdmin y su rol.
// Reemplaza la lógica de base de datos que antes vivía en crear_admin.php.

final class SetupController extends Controller
{
    /**
     * POST /api/setup/admin
     * Solo funciona si la institución todavía no tiene usuarios: una vez
     * creado el primer administrador, el endpoint queda cerrado.
     */
    public function crearAdmin(array $ruta = []): void
    {
        $institucionId = $this->peticion->entero('institucion_id', 1);
        $username      = $this->peticion->texto('username', 'admin');
        $password      = (string)$this->peticion->dato('password', 'admin123');
        $rolId         = $this->peticion->entero('rol_id', 1);

        $existentes = $this->contar(
            'SELECT COUNT(*) total FROM usuario WHERE InstitucionEducativaId = ?',
            [$institucionId]
        );
        if ($existentes > 0) {
            Response::error(
                'La institución ya tiene usuarios registrados. Por seguridad, este endpoint solo puede usarse en la instalación inicial.',
                409
            );
        }

        if (!$this->consultarUna('SELECT id FROM institucion_educativa WHERE id = ?', [$institucionId])) {
            Response::error('La institución educativa indicada no existe. Regístrela antes de crear el administrador.', 422);
        }

        try {
            $this->db->beginTransaction();

            $this->ejecutar(
                'INSERT INTO persona
                    (InstitucionEducativaId, TipoIdentificacion, Identificacion, Nombres, Apellidos, Email)
                 VALUES (?,?,?,?,?,?)',
                [
                    $institucionId,
                    'CEDULA',
                    $this->peticion->texto('identificacion', '0999999999'),
                    $this->peticion->texto('nombres', 'Admin'),
                    $this->peticion->texto('apellidos', 'Sistema'),
                    $this->peticion->texto('email', 'admin@fundacionrea.com'),
                ]
            );
            $personaId = (int)$this->db->lastInsertId();

            $this->ejecutar(
                'INSERT INTO usuario (InstitucionEducativaId, PersonaId, Username, PasswordHash, Estado)
                 VALUES (?,?,?,?,?)',
                [$institucionId, $personaId, $username, password_hash($password, PASSWORD_DEFAULT), 'ACTIVO']
            );
            $usuarioId = (int)$this->db->lastInsertId();

            // Asigna el rol indicado (por convención, RolId 1 = SuperAdmin)
            if ($this->consultarUna('SELECT RolId FROM rol WHERE RolId = ? AND InstitucionEducativaId = ?', [$rolId, $institucionId])) {
                $this->ejecutar(
                    'INSERT INTO usuariorol (InstitucionEducativaId, UsuarioId, RolId) VALUES (?,?,?)',
                    [$institucionId, $usuarioId, $rolId]
                );
                $rolAsignado = true;
            } else {
                $rolAsignado = false;
            }

            $this->db->commit();
        } catch (PDOException $ex) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->errorBaseDatos($ex, 'Ya existe un usuario con ese nombre.');
        }

        Response::exito([
            'PersonaId'    => $personaId,
            'UsuarioId'    => $usuarioId,
            'username'     => $username,
            'rol_asignado' => $rolAsignado,
            'mensaje'      => $rolAsignado
                ? 'Usuario SuperAdmin creado exitosamente.'
                : 'Usuario creado, pero el rol indicado no existe en esta institución: asígnelo manualmente.',
        ], [], 201);
    }
}
