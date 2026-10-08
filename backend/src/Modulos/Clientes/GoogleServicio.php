<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Google\ErrorGoogle;
use Croilab\Google\Metricas;
use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Métricas de Google de un cliente (conversiones.php): su web en Search
   Console, su propiedad de GA4 y qué eventos cuentan como llamada, WhatsApp o
   formulario. Habla con Google a través de Croilab\Google\Metricas (Equipo),
   que guarda y descifra los tokens en la bóveda; la conexión se hace una vez
   en Ajustes › Integraciones. */
class GoogleServicio
{
    public const OBJETIVOS = ['ll', 'wa', 'fo'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ClientesServicio $clientes,
        private readonly Metricas $metricas
    ) {}

    public function ver(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.clientes');
        $c = $this->clientes->visible($acc, $id);
        $ev = [];
        foreach (self::OBJETIVOS as $k) $ev[$k] = self::listaEventos((string)($c["ga4_ev_$k"] ?? ''));
        return [
            'cliente' => ['id' => (int)$c['id'], 'name' => (string)$c['name'], 'conversiones' => (int)$c['conversiones'] === 1],
            'site' => (string)($c['gsc_site_url'] ?? ''),
            'prop' => (string)($c['ga4_property_id'] ?? ''),
            'eventos' => $ev,
            'por_defecto' => $this->metricas->eventosDefecto(),
            'conectado' => $this->metricas->conectado(),
            'sync_at' => $c['met_sync_at'] ?? null,
            'meses' => ContenidoPortal::leerMetricas($c['met_json']),
        ];
    }

    public function guardar(Acceso $acc, int $id, array $d): void
    {
        $acc->exigir('general.editar', 'clientes.editar');
        $this->clientes->visible($acc, $id);
        $campos = [];
        if (array_key_exists('site', $d)) {
            $s = ContenidoPortal::texto($d['site'], 255, 'site');
            if ($s !== '' && !preg_match('#^(https?://\S+|sc-domain:[a-z0-9.-]+)$#i', $s)) {
                throw HttpError::validacion('Pon la web igual que en Search Console: https://sucliente.com/ o sc-domain:sucliente.com', 'site');
            }
            $campos['gsc_site_url'] = $s === '' ? null : $s;
        }
        if (array_key_exists('prop', $d)) {
            $p = ContenidoPortal::texto($d['prop'], 40, 'prop');
            if ($p !== '' && !ctype_digit($p)) throw HttpError::validacion('El número de Analytics son solo números.', 'prop');
            $campos['ga4_property_id'] = $p === '' ? null : $p;
        }
        if (array_key_exists('eventos', $d)) {
            if (!is_array($d['eventos'])) throw HttpError::validacion('Formato no válido.', 'eventos');
            $usados = [];
            foreach (self::OBJETIVOS as $k) {
                $lista = $d['eventos'][$k] ?? [];
                if (!is_array($lista) || !array_is_list($lista)) throw HttpError::validacion('Formato no válido.', 'eventos');
                $limpia = [];
                foreach ($lista as $e) {
                    $e = ContenidoPortal::texto($e, 40, 'eventos');
                    if ($e === '') continue;
                    /* Nombres de evento de GA4: letras, números y _. Van luego a un filtro de la API de Google. */
                    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $e)) throw HttpError::validacion("«{$e}» no es un nombre de evento de Analytics.", 'eventos');
                    /* Un evento solo cuenta para un tipo de contacto. */
                    if (isset($usados[$e])) throw HttpError::validacion("«{$e}» está en dos tipos de contacto a la vez.", 'eventos');
                    $usados[$e] = true;
                    $limpia[] = $e;
                }
                $csv = implode(',', $limpia);
                if (mb_strlen($csv) > 255) throw HttpError::validacion('Demasiados eventos.', 'eventos');
                $campos["ga4_ev_$k"] = $csv === '' ? null : $csv;
            }
        }
        if (!$campos) throw HttpError::validacion('No hay nada que cambiar.');
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($campos)));
        $this->pdo->prepare("UPDATE clients SET $set WHERE id = ?")->execute([...array_values($campos), $id]);
        if (function_exists('audit_log')) audit_log('cliente.google', "#$id " . implode(',', array_keys($campos)));
    }

    /** Eventos de los últimos 90 días de la propiedad (la que llega o la guardada). */
    public function eventos(Acceso $acc, int $id, string $prop): array
    {
        $acc->exigir('ver.clientes');
        $c = $this->clientes->visible($acc, $id);
        $prop = $prop !== '' ? $prop : trim((string)($c['ga4_property_id'] ?? ''));
        if ($prop === '') throw HttpError::validacion('Falta el número de Analytics de este cliente.', 'prop');
        if (!ctype_digit($prop)) throw HttpError::validacion('El número de Analytics son solo números.', 'prop');
        $this->exigirConexion();
        try {
            return $this->metricas->listaEventos($prop);
        } catch (ErrorGoogle $e) {
            throw self::error($e);
        }
    }

    /** Trae ya de Google el mes actual y el anterior. */
    public function sincronizar(Acceso $acc, int $id): string
    {
        $acc->exigir('general.editar', 'clientes.editar');
        $c = $this->clientes->visible($acc, $id);
        if (trim((string)($c['gsc_site_url'] ?? '')) === '' && trim((string)($c['ga4_property_id'] ?? '')) === '') {
            throw HttpError::validacion('Pon su web o su número de Analytics antes de traer datos.', 'site');
        }
        $this->exigirConexion();
        [$ok, $msg] = $this->metricas->sincronizarCliente($id);
        /* «Con avisos»: algún mes o alguna fuente ha fallado, pero lo demás ya está guardado. */
        if (!$ok && !str_starts_with($msg, 'Con avisos')) throw new HttpError(502, 'Google no ha devuelto datos: ' . $msg, 'google');
        return $msg;
    }

    private function exigirConexion(): void
    {
        if (!$this->metricas->conectado()) {
            throw new HttpError(409, 'El ERP aún no está conectado con Google. Conéctalo una vez en Ajustes › Integraciones.', 'google_sin_conectar');
        }
    }

    /* ErrorGoogle → respuesta: sin conexión o permiso retirado es 409 (hay que ir a Integraciones); lo demás, 502. */
    private static function error(ErrorGoogle $e): HttpError
    {
        return match ($e->codigo) {
            'sin_conectar', 'sin_configurar' => new HttpError(409, 'El ERP aún no está conectado con Google. Conéctalo una vez en Ajustes › Integraciones.', 'google_sin_conectar'),
            'revocado' => new HttpError(409, 'Google ha retirado el permiso. Vuelve a conectarlo en Ajustes › Integraciones.', 'google_revocado'),
            default => new HttpError(502, $e->getMessage(), 'google'),
        };
    }

    /** CSV guardado → lista sin vacíos ni repetidos. */
    public static function listaEventos(string $csv): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', $csv)), 'strlen')));
    }
}
