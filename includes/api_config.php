<?php
// includes/api_config.php
// Configuración del cliente que consume la API REST.
//
// Por defecto la URL base se detecta sola a partir de la ubicación del
// proyecto, de modo que funciona igual en localhost y en el hosting.
// Si su servidor publica la API en otra dirección (por ejemplo un subdominio),
// descomente API_BASE_URL y escríbala a mano.

// define('API_BASE_URL', 'https://midominio.com/api');

// Tiempo máximo de espera de cada llamada, en segundos.
if (!defined('API_TIMEOUT')) {
    define('API_TIMEOUT', 20);
}

// Verificación del certificado SSL al llamar a la API por HTTPS.
// Déjelo en true en producción; póngalo en false solo si su hosting usa un
// certificado autofirmado en el entorno de pruebas.
if (!defined('API_VERIFICAR_SSL')) {
    define('API_VERIFICAR_SSL', true);
}
