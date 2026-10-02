<?php
namespace Croilab\Http;

/* Tabla de rutas con parámetros: `/tareas/{id}` casa con `/tareas/42` y deja
   `id = 42` en $request->params. Los parámetros son siempre numéricos. */
class Router
{
    /** @var array<int, array{metodo:string, patron:string, regex:string, accion:callable, publica:bool}> */
    private array $rutas = [];

    public function get(string $patron, callable $accion, bool $publica = false): void { $this->agregar('GET', $patron, $accion, $publica); }
    public function post(string $patron, callable $accion, bool $publica = false): void { $this->agregar('POST', $patron, $accion, $publica); }
    public function patch(string $patron, callable $accion): void { $this->agregar('PATCH', $patron, $accion, false); }
    public function delete(string $patron, callable $accion): void { $this->agregar('DELETE', $patron, $accion, false); }

    private function agregar(string $metodo, string $patron, callable $accion, bool $publica): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>\d+)', rtrim($patron, '/') ?: '/') . '$#';
        $this->rutas[] = compact('metodo', 'patron', 'regex', 'accion', 'publica');
    }

    /**
     * Devuelve [accion, publica] de la ruta que casa, rellenando $req->params.
     * 404 si la ruta no existe; 405 si existe con otro método.
     */
    public function resolver(Request $req): array
    {
        $ruta = rtrim($req->ruta, '/') ?: '/';
        $permitidos = [];
        foreach ($this->rutas as $r) {
            if (!preg_match($r['regex'], $ruta, $m)) continue;
            if ($r['metodo'] !== $req->metodo) { $permitidos[] = $r['metodo']; continue; }
            $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return [$r['accion'], $r['publica']];
        }
        if ($permitidos) throw new HttpError(405, 'Método no permitido.', 'metodo', ['permitidos' => array_values(array_unique($permitidos))]);
        throw new HttpError(404, 'Esa ruta no existe.', 'ruta');
    }
}
