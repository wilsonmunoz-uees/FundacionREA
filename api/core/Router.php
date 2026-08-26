<?php
// api/core/Router.php
// Enrutador simple con soporte de parámetros dinámicos: 'personas/{id}/estado'

final class Router
{
    /** @var array<string, array<int, array{patron:string, parametros:array, destino:callable|array}>> */
    private array $rutas = [];

    public function get(string $ruta, $destino): void    { $this->registrar('GET', $ruta, $destino); }
    public function post(string $ruta, $destino): void   { $this->registrar('POST', $ruta, $destino); }
    public function put(string $ruta, $destino): void    { $this->registrar('PUT', $ruta, $destino); }
    public function patch(string $ruta, $destino): void  { $this->registrar('PATCH', $ruta, $destino); }
    public function delete(string $ruta, $destino): void { $this->registrar('DELETE', $ruta, $destino); }

    private function registrar(string $metodo, string $ruta, $destino): void
    {
        $ruta = trim($ruta, '/');
        $parametros = [];

        $patron = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            function ($m) use (&$parametros) {
                $parametros[] = $m[1];
                return '([^/]+)';
            },
            $ruta
        );

        $this->rutas[$metodo][] = [
            'patron'     => '#^' . $patron . '$#',
            'parametros' => $parametros,
            'destino'    => $destino,
        ];
    }

    /**
     * Busca la ruta que corresponde y ejecuta el controlador.
     * Devuelve false si ninguna ruta coincide.
     */
    public function despachar(Request $peticion): bool
    {
        $candidatas = $this->rutas[$peticion->metodo] ?? [];

        foreach ($candidatas as $ruta) {
            if (preg_match($ruta['patron'], $peticion->ruta, $coincidencias)) {
                array_shift($coincidencias);
                $argumentos = [];
                foreach ($ruta['parametros'] as $indice => $nombre) {
                    $argumentos[$nombre] = $coincidencias[$indice] ?? null;
                }
                $this->ejecutar($ruta['destino'], $peticion, $argumentos);
                return true;
            }
        }

        // ¿La ruta existe pero con otro verbo? -> 405
        foreach ($this->rutas as $metodo => $lista) {
            if ($metodo === $peticion->metodo) {
                continue;
            }
            foreach ($lista as $ruta) {
                if (preg_match($ruta['patron'], $peticion->ruta)) {
                    Response::error("El método {$peticion->metodo} no está permitido en esta ruta.", 405);
                }
            }
        }

        return false;
    }

    /** @param callable|array{0:string,1:string} $destino */
    private function ejecutar($destino, Request $peticion, array $argumentos): void
    {
        if (is_array($destino)) {
            [$clase, $metodo] = $destino;
            $controlador = new $clase($peticion);
            $controlador->$metodo($argumentos);
            return;
        }
        $destino($peticion, $argumentos);
    }
}
