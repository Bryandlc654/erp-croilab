<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Google\Cuenta;
use Croilab\Google\GoogleOAuth;
use Croilab\Google\Metricas;
use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* «Métricas de Google» en Ajustes (metricas.php): qué clientes tienen la web
   y Analytics configurados, «Actualizar ahora» (todos o uno) y los eventos de
   GA4 por defecto. La configuración de cada cliente (web, propiedad, eventos)
   es de la ficha del cliente (módulo Clientes). */
class MetricasAjustesServicio
{
    public function __construct(private readonly PDO $pdo, private readonly GoogleOAuth $google) {}

    private function motor(): Metricas
    {
        return new Metricas($this->google, $this->pdo);
    }

    public function resumen(Acceso $acc): array
    {
        $acc->exigir('ver.ajustes');
        $estado = $this->google->estado(Cuenta::metricas());
        $st = $this->pdo->query("SELECT id, name, activo, conversiones, gsc_site_url, ga4_property_id, ga4_ev_ll, ga4_ev_wa, ga4_ev_fo, met_sync_at
                                 FROM clients WHERE activo = 1" . $acc->sqlClientes('id') . ' ORDER BY name');
        $items = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $web = trim((string)$c['gsc_site_url']) !== '';
            $ga = trim((string)$c['ga4_property_id']) !== '';
            $items[] = [
                'id' => (int)$c['id'],
                'nombre' => (string)$c['name'],
                'web' => $web,
                'analytics' => $ga,
                'conversiones' => (int)$c['conversiones'] === 1 && $ga && (trim((string)$c['ga4_ev_ll']) . trim((string)$c['ga4_ev_wa']) . trim((string)$c['ga4_ev_fo'])) !== '',
                'sync' => $c['met_sync_at'] ? substr((string)$c['met_sync_at'], 0, 10) : null,
            ];
        }
        $ultima = $this->pdo->query('SELECT MAX(met_sync_at) FROM clients')->fetchColumn();
        return [
            'conectado' => $estado['conectado'],
            'revocado' => $estado['revocado'],
            'configurado' => $estado['configurado'],
            'ultima' => $ultima ? substr((string)$ultima, 0, 10) : null,
            'eventos' => $this->motor()->eventosDefecto(),
            'clientes' => $items,
            'con_web' => count(array_filter($items, fn($i) => $i['web'])),
            'puede_editar' => $acc->puede('general.editar'),
            'puede_ajustar' => $acc->puede('ajustes.editar'),
        ];
    }

    /** {ll, wa, fo}: nombres de evento de GA4 por defecto (CSV). */
    public function guardarEventos(Acceso $acc, array $d): array
    {
        $acc->exigir('ajustes.editar');
        foreach (['ll', 'wa', 'fo'] as $k) {
            if (!array_key_exists($k, $d)) continue;
            $v = Validar::texto($d[$k], 255, $k, 'El nombre del evento');
            $v = implode(',', array_values(array_filter(array_map('trim', explode(',', $v)), fn($s) => $s !== '')));
            if ($v !== '' && !preg_match('/^[A-Za-z0-9_,]+$/', $v)) throw HttpError::validacion('Los eventos de Analytics solo llevan letras, números y _ (sepáralos con comas).', $k);
            $this->pdo->prepare('INSERT INTO settings (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute(['ga4_event_' . $k, $v]);
        }
        if (function_exists('audit_log')) audit_log('ajuste.ga4_eventos', '');
        return $this->resumen($acc);
    }

    private function exigirConexion(): void
    {
        $e = $this->google->estado(Cuenta::metricas());
        if (!$e['conectado']) throw new HttpError(409, 'Todavía no está conectado con Google. Conéctalo en Integraciones (se hace una sola vez).', 'sin_conectar');
    }

    public function sincronizarTodos(Acceso $acc): array
    {
        $acc->exigir('ver.ajustes', 'general.editar');
        $this->exigirConexion();
        @set_time_limit(300);
        $r = $this->motor()->sincronizarTodos();
        return $r + ['msg' => 'Listo: ' . $r['ok'] . ' cliente(s) actualizados, ' . $r['avisos'] . ' con aviso.'];
    }

    public function sincronizarCliente(Acceso $acc, int $id): array
    {
        $acc->exigir('ver.ajustes', 'general.editar');
        if (!$acc->veCliente($id)) throw HttpError::noEncontrado('Cliente no encontrado.');
        $this->exigirConexion();
        [$ok, $msg] = $this->motor()->sincronizarCliente($id);
        if (!$ok) throw new HttpError(502, $msg, 'google');
        return ['msg' => 'Actualizado.'];
    }
}
