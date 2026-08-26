<?php
// api/core/Database.php
// Única puerta de acceso a MySQL en todo el sistema.
// Las páginas ya NO abren conexiones: solo esta capa lo hace.

final class Database
{
    private static ?PDO $conexion = null;
    private static array $config = [];

    /** Carga config.php (el mismo del proyecto) una sola vez. */
    public static function config(): array
    {
        if (empty(self::$config)) {
            $ruta = dirname(__DIR__, 2) . '/config.php';
            if (!is_file($ruta)) {
                throw new RuntimeException('No se encontró config.php en la raíz del proyecto.');
            }
            self::$config = require $ruta;
        }
        return self::$config;
    }

    /** Devuelve la conexión PDO compartida (patrón singleton). */
    public static function conexion(): PDO
    {
        if (self::$conexion instanceof PDO) {
            return self::$conexion;
        }

        $config = self::config();

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            $config['db_port'],
            $config['db_name']
        );

        try {
            self::$conexion = new PDO($dsn, $config['db_user'], $config['db_pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('[API] Error de conexión: ' . $e->getMessage());
            throw new RuntimeException('No se pudo conectar a la base de datos.', 0, $e);
        }

        return self::$conexion;
    }

    /**
     * Clave secreta para firmar los tokens de la API.
     * Si config.php no define 'api_secret', se deriva de forma determinista
     * de los datos de conexión (así no hace falta modificar config.php).
     */
    public static function secreto(): string
    {
        $config = self::config();
        if (!empty($config['api_secret'])) {
            return (string)$config['api_secret'];
        }
        return hash(
            'sha256',
            'REA|' . $config['db_host'] . '|' . $config['db_name'] . '|' . $config['db_user'] . '|' . $config['db_pass']
        );
    }
}
