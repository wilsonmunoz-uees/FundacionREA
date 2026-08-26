<?php
/**
 * api/core/Correo.php
 * -----------------------------------------------------------------------------
 * Envío de correo sin librerías externas.
 *
 * Habla SMTP directamente sobre sockets (con STARTTLS o SSL y autenticación
 * AUTH LOGIN / PLAIN) y arma el mensaje en formato MIME multipart, de modo que
 * el correo llegue con versión HTML y versión en texto plano.
 *
 * Si la institución no tiene configurado un servidor SMTP, se intenta con la
 * función mail() de PHP. En hospedajes compartidos mail() suele estar limitado,
 * por eso se recomienda configurar SMTP desde la pantalla correspondiente.
 *
 * Uso:
 *     $correo = Correo::desdeConfiguracion($configuracion);
 *     $correo->enviar('destino@correo.com', 'Nombre', 'Asunto', $html, $texto);
 * -----------------------------------------------------------------------------
 */

final class Correo
{
    private const SALTO = "\r\n";

    private array $config;
    /** Conexión SMTP reutilizada entre envíos de una misma tanda. */
    private $socket = null;
    private string $ultimoError = '';

    private function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param array|null $config Fila de `correo_configuracion`, o null para
     *                           enviar con mail() de PHP.
     */
    public static function desdeConfiguracion(?array $config): self
    {
        return new self([
            'usar_smtp' => !empty($config) && ($config['Activo'] ?? 'NO') === 'SI'
                           && !empty($config['Servidor']),
            'servidor'  => (string)($config['Servidor'] ?? ''),
            'puerto'    => (int)($config['Puerto'] ?? 587),
            'seguridad' => strtoupper((string)($config['Seguridad'] ?? 'TLS')),
            'usuario'   => (string)($config['Usuario'] ?? ''),
            'clave'     => (string)($config['Clave'] ?? ''),
            'de_correo' => (string)($config['RemitenteCorreo'] ?? ''),
            'de_nombre' => (string)($config['RemitenteNombre'] ?? 'Red Educativa Arquidiocesana'),
        ]);
    }

    public function usaSmtp(): bool
    {
        return (bool)$this->config['usar_smtp'];
    }

    public function ultimoError(): string
    {
        return $this->ultimoError;
    }

    /** Dirección que aparecerá como remitente. */
    public function remitente(): string
    {
        if ($this->config['de_correo'] !== '') {
            return $this->config['de_correo'];
        }
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        return 'no-responder@' . preg_replace('/^www\./', '', $host);
    }

    /**
     * Envía un mensaje. Devuelve true si el servidor lo aceptó.
     * El motivo del fallo queda en ultimoError().
     */
    public function enviar(string $para, string $nombrePara, string $asunto, string $html, string $texto = ''): bool
    {
        $this->ultimoError = '';

        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) {
            $this->ultimoError = 'La dirección de correo no es válida.';
            return false;
        }

        if ($texto === '') {
            $texto = self::htmlATexto($html);
        }

        return $this->usaSmtp()
            ? $this->enviarPorSmtp($para, $nombrePara, $asunto, $html, $texto)
            : $this->enviarPorMail($para, $nombrePara, $asunto, $html, $texto);
    }

    /** Cierra la conexión SMTP al terminar una tanda de envíos. */
    public function cerrar(): void
    {
        if ($this->socket) {
            @$this->orden('QUIT');
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    public function __destruct()
    {
        $this->cerrar();
    }

    /* ------------------------------------------------------------------ */
    /* Envío con la función mail() de PHP                                  */
    /* ------------------------------------------------------------------ */

    private function enviarPorMail(string $para, string $nombrePara, string $asunto, string $html, string $texto): bool
    {
        if (!function_exists('mail')) {
            $this->ultimoError = 'El servidor no permite enviar correo con mail(). Configure un servidor SMTP.';
            return false;
        }

        $frontera = $this->frontera();
        $de       = $this->remitente();

        $cabeceras = implode(self::SALTO, [
            'From: ' . self::codificarNombre($this->config['de_nombre']) . ' <' . $de . '>',
            'Reply-To: ' . $de,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $frontera . '"',
            'X-Mailer: REA-ProteccionDatos',
        ]);

        $ok = @mail(
            $para,
            self::codificarAsunto($asunto),
            $this->cuerpoMime($frontera, $html, $texto),
            $cabeceras
        );

        if (!$ok) {
            $this->ultimoError = 'mail() rechazó el mensaje. Configure un servidor SMTP para un envío confiable.';
        }

        return $ok;
    }

    /* ------------------------------------------------------------------ */
    /* Envío por SMTP                                                      */
    /* ------------------------------------------------------------------ */

    private function enviarPorSmtp(string $para, string $nombrePara, string $asunto, string $html, string $texto): bool
    {
        if (!$this->conectar()) {
            return false;
        }

        $de = $this->remitente();

        if (!$this->orden('MAIL FROM:<' . $de . '>', [250])) {
            return false;
        }
        if (!$this->orden('RCPT TO:<' . $para . '>', [250, 251])) {
            return false;
        }
        if (!$this->orden('DATA', [354])) {
            return false;
        }

        $frontera = $this->frontera();

        $mensaje = implode(self::SALTO, [
            'Date: ' . date('r'),
            'From: ' . self::codificarNombre($this->config['de_nombre']) . ' <' . $de . '>',
            'To: ' . ($nombrePara !== '' ? self::codificarNombre($nombrePara) . ' <' . $para . '>' : $para),
            'Reply-To: ' . $de,
            'Subject: ' . self::codificarAsunto($asunto),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($_SERVER['SERVER_NAME'] ?? 'rea') . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $frontera . '"',
            'X-Mailer: REA-ProteccionDatos',
            '',
            $this->cuerpoMime($frontera, $html, $texto),
        ]);

        // Un punto al inicio de línea debe duplicarse (RFC 5321)
        $mensaje = preg_replace('/^\./m', '..', $mensaje);

        fwrite($this->socket, $mensaje . self::SALTO . '.' . self::SALTO);

        return $this->respuestaEsperada([250]);
    }

    /** Abre y autentica la conexión, reutilizándola si ya estaba abierta. */
    private function conectar(): bool
    {
        if ($this->socket && !feof($this->socket)) {
            return true;
        }

        $servidor = $this->config['servidor'];
        $puerto   = $this->config['puerto'] ?: 587;
        $destino  = ($this->config['seguridad'] === 'SSL' ? 'ssl://' : '') . $servidor . ':' . $puerto;

        $contexto = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
        ]);

        $errorNumero  = 0;
        $errorMensaje = '';
        $this->socket = @stream_socket_client(
            $destino,
            $errorNumero,
            $errorMensaje,
            15,
            STREAM_CLIENT_CONNECT,
            $contexto
        );

        if (!$this->socket) {
            $this->ultimoError = 'No se pudo conectar con ' . $servidor . ':' . $puerto
                . ($errorMensaje !== '' ? ' (' . $errorMensaje . ')' : '');
            return false;
        }

        stream_set_timeout($this->socket, 20);

        if (!$this->respuestaEsperada([220])) {
            $this->cerrar();
            return false;
        }

        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';

        if (!$this->orden('EHLO ' . $host, [250])) {
            // Servidores antiguos que no admiten EHLO
            if (!$this->orden('HELO ' . $host, [250])) {
                $this->cerrar();
                return false;
            }
        }

        if ($this->config['seguridad'] === 'TLS') {
            if (!$this->orden('STARTTLS', [220])) {
                $this->cerrar();
                return false;
            }
            $cifrado = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            );
            if (!$cifrado) {
                $this->ultimoError = 'No se pudo establecer el cifrado TLS con el servidor de correo.';
                $this->cerrar();
                return false;
            }
            // Tras STARTTLS hay que volver a saludar
            if (!$this->orden('EHLO ' . $host, [250])) {
                $this->cerrar();
                return false;
            }
        }

        if ($this->config['usuario'] !== '') {
            if (!$this->autenticar()) {
                $this->cerrar();
                return false;
            }
        }

        return true;
    }

    private function autenticar(): bool
    {
        // AUTH LOGIN es el más aceptado; si el servidor lo rechaza, se prueba PLAIN
        if ($this->orden('AUTH LOGIN', [334])) {
            if (!$this->orden(base64_encode($this->config['usuario']), [334])) {
                return false;
            }
            if (!$this->orden(base64_encode($this->config['clave']), [235])) {
                $this->ultimoError = 'El servidor de correo rechazó el usuario o la contraseña.';
                return false;
            }
            return true;
        }

        $plain = base64_encode("\0" . $this->config['usuario'] . "\0" . $this->config['clave']);
        if (!$this->orden('AUTH PLAIN ' . $plain, [235])) {
            $this->ultimoError = 'El servidor de correo rechazó el usuario o la contraseña.';
            return false;
        }

        return true;
    }

    /** Envía una orden SMTP y comprueba el código de respuesta. */
    private function orden(string $orden, array $codigosEsperados = [250]): bool
    {
        if (!$this->socket) {
            return false;
        }
        fwrite($this->socket, $orden . self::SALTO);

        return $this->respuestaEsperada($codigosEsperados);
    }

    private function respuestaEsperada(array $codigos): bool
    {
        $respuesta = $this->leerRespuesta();
        $codigo    = (int)substr($respuesta, 0, 3);

        if (in_array($codigo, $codigos, true)) {
            return true;
        }

        $this->ultimoError = trim($respuesta) !== ''
            ? 'El servidor de correo respondió: ' . trim($respuesta)
            : 'El servidor de correo no respondió.';

        return false;
    }

    /** Lee la respuesta completa, incluidas las multilínea (250-...). */
    private function leerRespuesta(): string
    {
        $respuesta = '';
        while ($this->socket && ($linea = fgets($this->socket, 1024)) !== false) {
            $respuesta .= $linea;
            // La última línea lleva un espacio tras el código: "250 Ok"
            if (strlen($linea) < 4 || $linea[3] === ' ') {
                break;
            }
            $estado = stream_get_meta_data($this->socket);
            if (!empty($estado['timed_out'])) {
                break;
            }
        }
        return $respuesta;
    }

    /* ------------------------------------------------------------------ */
    /* Armado del mensaje                                                  */
    /* ------------------------------------------------------------------ */

    private function frontera(): string
    {
        return 'rea_' . bin2hex(random_bytes(10));
    }

    private function cuerpoMime(string $frontera, string $html, string $texto): string
    {
        return implode(self::SALTO, [
            'Este mensaje está en formato MIME.',
            '',
            '--' . $frontera,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($texto), 76, self::SALTO),
            '--' . $frontera,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($html), 76, self::SALTO),
            '--' . $frontera . '--',
            '',
        ]);
    }

    /** Asunto con acentos: codificación MIME para que no llegue roto. */
    private static function codificarAsunto(string $texto): string
    {
        return '=?UTF-8?B?' . base64_encode($texto) . '?=';
    }

    private static function codificarNombre(string $texto): string
    {
        if ($texto === '') {
            return '';
        }
        return preg_match('/[\x80-\xFF]/', $texto)
            ? self::codificarAsunto($texto)
            : '"' . str_replace('"', '', $texto) . '"';
    }

    /** Versión en texto plano del cuerpo HTML, para los lectores que no lo admiten. */
    public static function htmlATexto(string $html): string
    {
        // Los enlaces se conservan como "texto: url" para que sigan siendo útiles
        $texto = preg_replace('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2: $1', $html);
        $texto = preg_replace('/<(br|\/p|\/div|\/h[1-6]|\/tr)\s*\/?>/i', "\n", (string)$texto);
        $texto = strip_tags((string)$texto);
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = preg_replace("/[ \t]+/", ' ', $texto);
        $texto = preg_replace("/\n\s*\n\s*\n+/", "\n\n", (string)$texto);

        return trim((string)$texto);
    }
}
