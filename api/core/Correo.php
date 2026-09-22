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

    /** Cuántas líneas de la conversación se conservan para diagnóstico. */
    private const DIALOGO_MAXIMO = 80;

    private array $config;
    /** Conexión SMTP reutilizada entre envíos de una misma tanda. */
    private $socket = null;
    private string $ultimoError = '';
    /** Última línea que devolvió el servidor, tal cual. */
    private string $ultimaRespuesta = '';
    /** La conversación con el servidor, sin credenciales, para diagnosticar. */
    private array $dialogo = [];
    /** Hay un MAIL FROM abierto que habría que cerrar con RSET. */
    private bool $enTransaccion = false;

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

    /** La última línea que devolvió el servidor, tal cual la mandó. */
    public function ultimaRespuesta(): string
    {
        return $this->ultimaRespuesta;
    }

    /**
     * La conversación con el servidor, línea a línea, sin las credenciales.
     *
     * Es lo que muestra la pantalla de prueba cuando el envío falla. Sin ella,
     * un fallo de SMTP deja una sola frase y hay que adivinar en qué paso
     * ocurrió; con ella se ve la orden exacta que el servidor rechazó, que es
     * lo que permite distinguir «el buzón no existe aquí» de «el destinatario
     * no existe» o «el hosting bloquea el puerto».
     *
     * @return string[]
     */
    public function dialogo(): array
    {
        return $this->dialogo;
    }

    /**
     * Anota una línea de la conversación, hasta el tope.
     *
     * Una respuesta multilínea —la del EHLO lo es siempre— se parte en un
     * renglón por línea: pegadas quedan ilegibles en la pantalla.
     */
    private function anotar(string $lado, string $texto): void
    {
        foreach (preg_split('/\r?\n/', rtrim($texto)) as $linea) {
            if (count($this->dialogo) >= self::DIALOGO_MAXIMO) {
                return;
            }
            $this->dialogo[] = $lado . ' ' . $linea;
        }
    }

    /** Dirección que aparecerá como remitente. */
    public function remitente(): string
    {
        if ($this->config['de_correo'] !== '') {
            return $this->config['de_correo'];
        }
        /* Sin remitente escrito, el usuario SMTP es la mejor apuesta: muchos
           servidores —los cPanel entre ellos— rechazan un MAIL FROM que no
           corresponda a la cuenta autenticada. Inventar
           no-responder@<dominio del sitio>, como se hacía antes, hacía que el
           servidor respondiera «sender verify failed» y el correo no salía. */
        if (str_contains($this->config['usuario'], '@')) {
            return $this->config['usuario'];
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

        if (!CorreoElectronico::esValido($para)) {
            $this->ultimoError = 'La dirección de correo no es válida: '
                               . (CorreoElectronico::problema($para) ?? '');
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
            /* QUIT se responde con 221, no con 250. Esperar 250 hacía que la
               comprobación diera el cierre por fallido y SOBRESCRIBIERA el
               error de verdad: cualquier fallo de SMTP terminaba reportándose
               como «El servidor de correo respondió: 221 … closing
               connection», que es justamente la señal de que la despedida fue
               correcta. El error real —una autenticación rechazada, por
               ejemplo— se perdía por el camino.
               Por eso se espera 221 y, además, se conserva lo que ya hubiera
               registrado: el cierre nunca debe cambiar el diagnóstico. */
            $error     = $this->ultimoError;
            $respuesta = $this->ultimaRespuesta;

            @$this->orden('QUIT', [221], 'cerrar la conexión');

            $this->ultimoError     = $error;
            $this->ultimaRespuesta = $respuesta;

            @fclose($this->socket);
            $this->socket        = null;
            $this->enTransaccion = false;
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

        if (!$this->orden('MAIL FROM:<' . $de . '>', [250], 'aceptar el remitente')) {
            return $this->abortarTransaccion();
        }
        $this->enTransaccion = true;

        if (!$this->orden('RCPT TO:<' . $para . '>', [250, 251], 'aceptar el destinatario')) {
            return $this->abortarTransaccion();
        }
        if (!$this->orden('DATA', [354], 'empezar el mensaje')) {
            return $this->abortarTransaccion();
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

        $this->anotar('C:', '(cuerpo del mensaje, ' . strlen($mensaje) . ' bytes)');
        fwrite($this->socket, $mensaje . self::SALTO . '.' . self::SALTO);

        // El punto final cierra la transacción, la acepten o no.
        $this->enTransaccion = false;

        return $this->respuestaEsperada([250], 'aceptar el mensaje');
    }

    /**
     * Deja la sesión limpia tras un fallo y devuelve false.
     *
     * Sin esto, un destinatario rechazado dejaba el MAIL FROM abierto, y el
     * servidor contestaba «503 Sender already given» a todos los correos que
     * vinieran detrás: un solo buzón inexistente tumbaba el resto de la tanda,
     * con la conexión compartida del envío masivo. Un RSET devuelve la sesión
     * al punto de partida sin tener que reconectar.
     *
     * El error que se estaba reportando se conserva: el RSET tiene su propia
     * respuesta y, si no, la pisaría —el mismo descuido que producía el 221—.
     */
    private function abortarTransaccion(): bool
    {
        if ($this->socket && $this->enTransaccion) {
            $error     = $this->ultimoError;
            $respuesta = $this->ultimaRespuesta;

            @$this->orden('RSET', [250], 'reiniciar la sesión');

            $this->ultimoError     = $error;
            $this->ultimaRespuesta = $respuesta;
        }
        $this->enTransaccion = false;

        return false;
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

        $this->anotar('·', 'Conectando con ' . $destino
            . ' (seguridad: ' . $this->config['seguridad'] . ')');

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
                . ($errorMensaje !== '' ? ' (' . $errorMensaje . ')' : '')
                . '. Compruebe el servidor y el puerto, y que el hospedaje permita salir por ese puerto.';
            $this->anotar('·', 'No se pudo abrir la conexión: '
                . ($errorMensaje !== '' ? $errorMensaje : 'sin detalle'));
            return false;
        }

        stream_set_timeout($this->socket, 20);

        if (!$this->respuestaEsperada([220], 'saludar')) {
            $this->cerrar();
            return false;
        }

        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';

        if (!$this->orden('EHLO ' . $host, [250], 'presentarse')) {
            // Servidores antiguos que no admiten EHLO
            if (!$this->orden('HELO ' . $host, [250], 'presentarse')) {
                $this->cerrar();
                return false;
            }
        }

        if ($this->config['seguridad'] === 'TLS') {
            if (!$this->orden('STARTTLS', [220], 'iniciar el cifrado')) {
                $this->cerrar();
                return false;
            }
            $metodos = STREAM_CRYPTO_METHOD_TLS_CLIENT
                     | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
                     | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $metodos |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            $cifrado = @stream_socket_enable_crypto($this->socket, true, $metodos);
            if (!$cifrado) {
                $this->ultimoError = 'No se pudo establecer el cifrado TLS con el servidor de correo.';
                $this->anotar('·', 'Falló el cifrado TLS tras STARTTLS');
                $this->cerrar();
                return false;
            }
            $this->anotar('·', 'Cifrado TLS establecido');
            // Tras STARTTLS hay que volver a saludar
            if (!$this->orden('EHLO ' . $host, [250], 'presentarse tras el cifrado')) {
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
        if ($this->orden('AUTH LOGIN', [334], 'empezar la autenticación')) {
            if (!$this->orden(base64_encode($this->config['usuario']), [334],
                              'aceptar el usuario', '(usuario, codificado)')) {
                $this->ultimoError = 'El servidor de correo no aceptó el usuario «'
                    . $this->config['usuario'] . '». Respondió: ' . $this->ultimaRespuesta;
                return false;
            }
            if (!$this->orden(base64_encode($this->config['clave']), [235],
                              'aceptar la contraseña', '(contraseña, oculta)')) {
                $this->ultimoError = $this->explicarRechazoDeClave();
                return false;
            }
            return true;
        }

        $plain = base64_encode("\0" . $this->config['usuario'] . "\0" . $this->config['clave']);
        if (!$this->orden('AUTH PLAIN ' . $plain, [235], 'autenticar',
                          'AUTH PLAIN (credenciales ocultas)')) {
            $this->ultimoError = $this->explicarRechazoDeClave();
            return false;
        }

        return true;
    }

    /**
     * Mensaje para una autenticación rechazada.
     *
     * Se separa porque es el fallo más frecuente y el que más tiempo hace
     * perder: casi siempre significa que el buzón no está en ese servidor
     * —el dominio se mudó de proveedor y nadie actualizó esta pantalla— y no
     * que la contraseña esté mal escrita.
     */
    private function explicarRechazoDeClave(): string
    {
        $pista = self::pistaDelServidor($this->ultimaRespuesta);
        if ($pista !== '') {
            // Cuando el servidor dice exactamente qué pasa, esa explicación
            // manda: la sospecha genérica del buzón equivocado sobraría.
            return 'El servidor de correo rechazó la autenticación. Respondió: '
                . $this->ultimaRespuesta . ' — ' . $pista;
        }

        return 'El servidor de correo rechazó el usuario o la contraseña. Respondió: '
            . $this->ultimaRespuesta
            . ' — compruebe que el buzón «' . $this->config['usuario'] . '» exista en '
            . $this->config['servidor'] . ' y que la contraseña sea la de ese buzón.';
    }

    /**
     * Traduce los códigos de estado que los proveedores grandes devuelven.
     *
     * Un «535 5.7.139» no le dice nada a quien administra la institución, y
     * sin embargo es el fallo más común al conectar con Microsoft 365: no es
     * que la contraseña esté mal, es que el envío por SMTP viene apagado de
     * fábrica y hay que encenderlo en el buzón. Traducirlo aquí ahorra la
     * búsqueda a ciegas que, si no, hay que hacer con el código en la mano.
     *
     * Devuelve '' cuando no se reconoce nada: es preferible callar a inventar.
     *
     * Es pública porque la usan también la pantalla de configuración y el
     * guion de consola, y no tiene sentido que cada una traduzca por su cuenta.
     */
    public static function pistaDelServidor(string $respuesta): string
    {
        $r = strtolower($respuesta);

        if (str_contains($r, 'smtpclientauthentication is disabled')) {
            /* El ajuste del BUZÓN manda sobre el de la organización, de modo que
               no hace falta encender el envío por SMTP para todos —y no conviene:
               Microsoft recomienda justo lo contrario, dejarlo apagado en general
               y abrirlo cuenta por cuenta—. La excepción son los valores
               predeterminados de seguridad: con ellos activos, SMTP queda apagado
               para todo el mundo y la casilla del buzón no basta. */
            return 'Microsoft 365 tiene apagado el envío por SMTP para esta cuenta. '
                 . 'Enciéndalo en el centro de administración: Usuarios › Usuarios activos › '
                 . 'la cuenta › pestaña Correo › Administrar aplicaciones de correo electrónico '
                 . '› marcar «SMTP autenticado». Esa casilla manda sobre el ajuste de la '
                 . 'organización, así que no hace falta encenderlo para todos. Si aun así sigue '
                 . 'fallando, compruebe que la organización no tenga activos los «valores '
                 . 'predeterminados de seguridad» de Microsoft Entra: con ellos puestos, el '
                 . 'envío por SMTP queda apagado para todas las cuentas y la casilla no basta.';
        }
        if (str_contains($r, '5.7.139')) {
            return 'Microsoft 365 rechazó la autenticación. Suele ser una de tres: el envío por '
                 . 'SMTP no está habilitado en el buzón, la cuenta tiene autenticación '
                 . 'multifactor o valores predeterminados de seguridad —que son incompatibles '
                 . 'con esta forma de conexión—, o la contraseña no es la correcta.';
        }
        if (str_contains($r, '5.7.57') || str_contains($r, 'anonymous mail')) {
            return 'El servidor exige autenticarse y no recibió usuario ni contraseña. '
                 . 'Complete esos dos campos.';
        }
        if (str_contains($r, '5.7.60') || str_contains($r, '5.7.708')
            || str_contains($r, 'send as this sender')) {
            return 'El servidor no permite enviar con ese remitente. Tiene que ser la misma '
                 . 'dirección del buzón con el que se autentica, o una que ese buzón tenga '
                 . 'permiso de usar.';
        }
        if (str_contains($r, 'application-specific password')
            || str_contains($r, 'contraseña de aplicación')) {
            return 'El proveedor exige una contraseña de aplicación, no la del buzón. '
                 . 'Genérela en la cuenta del proveedor y escríbala aquí.';
        }

        return '';
    }

    /**
     * Envía una orden SMTP y comprueba el código de respuesta.
     *
     * @param string      $queHacia    Qué se intentaba, para el mensaje de error.
     * @param string|null $comoSeAnota Qué escribir en la conversación en lugar
     *                                 de la orden: lo usan las líneas que
     *                                 llevan credenciales.
     */
    private function orden(
        string $orden,
        array $codigosEsperados = [250],
        string $queHacia = '',
        ?string $comoSeAnota = null
    ): bool {
        if (!$this->socket) {
            return false;
        }
        $this->anotar('C:', $comoSeAnota ?? $orden);
        fwrite($this->socket, $orden . self::SALTO);

        return $this->respuestaEsperada($codigosEsperados, $queHacia);
    }

    private function respuestaEsperada(array $codigos, string $queHacia = ''): bool
    {
        $respuesta = $this->leerRespuesta();

        $this->ultimaRespuesta = trim($respuesta);
        $this->anotar('S:', $this->ultimaRespuesta !== '' ? $this->ultimaRespuesta : '(sin respuesta)');

        $codigo = (int)substr($respuesta, 0, 3);

        if (in_array($codigo, $codigos, true)) {
            return true;
        }

        if ($this->ultimaRespuesta === '') {
            $this->ultimoError = $queHacia !== ''
                ? 'El servidor de correo no respondió al ' . $queHacia . '.'
                : 'El servidor de correo no respondió.';
            return false;
        }

        $this->ultimoError = $queHacia !== ''
            ? 'El servidor de correo no dejó ' . $queHacia . ' y respondió: ' . $this->ultimaRespuesta
            : 'El servidor de correo respondió: ' . $this->ultimaRespuesta;

        // Si el código es uno de los conocidos, se añade qué significa: un
        // «5.7.60» a secas obliga a buscarlo fuera para entender nada.
        $pista = self::pistaDelServidor($this->ultimaRespuesta);
        if ($pista !== '') {
            $this->ultimoError .= ' — ' . $pista;
        }

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
