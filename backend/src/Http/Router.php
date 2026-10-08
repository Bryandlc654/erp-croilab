<?php
namespace Croilab\Http;

/* Tabla de rutas con parámetros: `/tareas/{id}` casa con `/tareas/42` y deja
   `id = 42` en $request->params. Los parámetros son siempre numéricos. */
class Router
{
    /** @var array<int, array{metodo:string, patron:string, regex:string, accion:callable, publica:bool, csrf:bool}> */
    private array $rutas = [];

    public function get(string $patron, callable $accion, bool $publica = false): void { $this->agregar('GET', $patron, $accion, $publica); }
    public function post(string $patron, callable $accion, bool $publica = false): void { $this->agregar('POST', $patron, $accion, $publica); }
    public function patch(string $patron, callable $accion): void { $this->agregar('PATCH', $patron, $accion, false); }
    public function delete(string $patron, callable $accion): void { $this->agregar('DELETE', $patron, $accion, false); }

    /**
     * POST para clientes que no son el navegador (el servidor MCP, webhooks):
     * se autentican con su propio token, no con la cookie de sesión, así que no
     * llevan CSRF. La acción NO debe usar la sesión (current_admin(),
     * Acceso::actual()): si lo hiciera, una web ajena podría usarla con la
     * cookie del usuario. Por eso es pública y sin sesión a la vez.
     */
    public function postConToken(string $patron, callable $accion): void { $this->agregar('POST', $patron, $accion, true, false); }

    private function agregar(string $metodo, string $patron, callable $accion, bool $publica, bool $csrf = true): void
    {
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>\d+)', rtrim($patron, '/') ?: '/') . '$#';
        $this->rutas[] = compact('metodo', 'patron', 'regex', 'accion', 'publica', 'csrf');
    }

    /**
     * Devuelve [accion, publica, csrf] de la ruta que casa, rellenando $req->params.
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
            return [$r['accion'], $r['publica'], $r['csrf']];
        }
        if ($permitidos) throw new HttpError(405, 'Método no permitido.', 'metodo', ['permitidos' => array_values(array_unique($permitidos))]);
        throw new HttpError(404, 'Esa ruta no existe.', 'ruta');
    }
}
