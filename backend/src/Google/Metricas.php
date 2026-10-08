<?php
namespace Croilab\Google;

use PDO;

/* Métricas de Google de los clientes: Search Console (visitas, apariciones,
   CTR) y GA4 (llamadas, WhatsApp, formularios, canales y países), fusionadas
   en clients.met_json por mes, que es lo que pinta el portal del cliente.

   Es el motor de admin/lib/google_metrics.php (gm_sync_client / gm_sync_all /
   gm_ga4_lista_eventos) sobre ClienteGoogle: misma forma de met_json, mismos
   nombres de mes y mismos eventos por defecto, pero con el token cifrado y sin
   leer secretos en claro. */
final class Metricas
{
    public const EVENTOS_DEFECTO = ['ll' => 'phone_call', 'wa' => 'whatsapp_click', 'fo' => 'generate_lead'];
    private const MESES = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio',
                           8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
    private const GA4 = 'https://analyticsdata.googleapis.com/v1beta/properties/';

    private ?ClienteGoogle $g = null;

    public function __construct(private readonly GoogleOAuth $oauth, private readonly PDO $pdo) {}

    public function conectado(): bool
    {
        return $this->oauth->estado(Cuenta::metricas())['conectado'];
    }

    private function g(): ClienteGoogle
    {
        return $this->g ??= $this->oauth->cliente(Cuenta::metricas());
    }

    /** Eventos por defecto (settings ga4_event_*), con los de fábrica si no hay. */
    public function eventosDefecto(): array
    {
        $s = get_settings(['ga4_event_ll', 'ga4_event_wa', 'ga4_event_fo']);
        $out = [];
        foreach (self::EVENTOS_DEFECTO as $k => $def) $out[$k] = trim($s['ga4_event_' . $k]) !== '' ? trim($s['ga4_event_' . $k]) : $def;
        return $out;
    }

    public static function nombreMes(string $ym): string
    {
        return self::MESES[(int)substr($ym, 5, 2)] ?? $ym;
    }

    /** Totales de Search Console de un rango: [clicks, impressions, ctr %]. */
    public function searchConsole(string $sitio, string $desde, string $hasta): array
    {
        $j = $this->g()->post('https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($sitio) . '/searchAnalytics/query',
            ['startDate' => $desde, 'endDate' => $hasta, 'dimensions' => []]);
        $r = $j['rows'][0] ?? [];
        return ['clicks' => (int)round($r['clicks'] ?? 0), 'impressions' => (int)round($r['impressions'] ?? 0), 'ctr' => round(($r['ctr'] ?? 0) * 100, 2)];
    }

    /** Suma de eventCount de uno o varios eventos (CSV) en un rango. */
    public function ga4Evento(string $propiedad, string $eventos, string $desde, string $hasta): int
    {
        $nombres = array_values(array_filter(array_map('trim', explode(',', $eventos)), fn($s) => $s !== ''));
        if (!$nombres) return 0;
        $j = $this->g()->post(self::GA4 . rawurlencode($propiedad) . ':runReport', [
            'dateRanges' => [['startDate' => $desde, 'endDate' => $hasta]],
            'metrics' => [['name' => 'eventCount']],
            'dimensionFilter' => ['filter' => ['fieldName' => 'eventName', 'inListFilter' => ['values' => $nombres]]],
        ]);
        return (int)round($j['rows'][0]['metricValues'][0]['value'] ?? 0);
    }

    /** Sesiones por una dimensión (canal o país): [valor => sesiones]. */
    private function ga4Reparto(string $propiedad, string $dimension, string $desde, string $hasta, int $limite): array
    {
        $j = $this->g()->post(self::GA4 . rawurlencode($propiedad) . ':runReport', [
            'dateRanges' => [['startDate' => $desde, 'endDate' => $hasta]],
            'dimensions' => [['name' => $dimension]],
            'metrics' => [['name' => 'sessions']],
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
            'limit' => $limite,
        ]);
        $out = [];
        foreach ($j['rows'] ?? [] as $r) {
            $k = (string)($r['dimensionValues'][0]['value'] ?? '');
            if ($dimension === 'countryId') {
                $k = strtoupper($k);
                if ($k === '' || $k === '(NOT SET)') continue;
            } elseif ($k === '') {
                $k = 'Otros';
            }
            $out[$k] = (int)round($r['metricValues'][0]['value'] ?? 0);
        }
        return $out;
    }

    /** Eventos de una propiedad en los últimos 90 días: [{name, n}] por volumen. */
    public function listaEventos(string $propiedad): array
    {
        $j = $this->g()->post(self::GA4 . rawurlencode($propiedad) . ':runReport', [
            'dateRanges' => [['startDate' => '90daysAgo', 'endDate' => 'today']],
            'dimensions' => [['name' => 'eventName']],
            'metrics' => [['name' => 'eventCount']],
            'orderBys' => [['metric' => ['metricName' => 'eventCount'], 'desc' => true]],
            'limit' => 100,
        ]);
        $out = [];
        foreach ($j['rows'] ?? [] as $r) {
            $n = (string)($r['dimensionValues'][0]['value'] ?? '');
            if ($n !== '') $out[] = ['name' => $n, 'n' => (int)round($r['metricValues'][0]['value'] ?? 0)];
        }
        return $out;
    }

    /** Meses por defecto: el actual y el anterior (YYYY-MM). */
    public static function mesesPorDefecto(): array
    {
        return [date('Y-m'), date('Y-m', strtotime('first day of last month'))];
    }

    /**
     * Sincroniza un cliente para los meses dados. Devuelve [ok, mensaje].
     * Un fallo de una de las fuentes no borra lo que ya había de ese mes.
     */
    public function sincronizarCliente(int $clientId, ?array $meses = null): array
    {
        $meses ??= self::mesesPorDefecto();
        $st = $this->pdo->prepare('SELECT gsc_site_url, ga4_property_id, ga4_ev_ll, ga4_ev_wa, ga4_ev_fo, met_json FROM clients WHERE id = ?');
        $st->execute([$clientId]);
        $cl = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cl) return [false, 'Cliente no encontrado.'];
        $sitio = trim((string)$cl['gsc_site_url']);
        $prop = trim((string)$cl['ga4_property_id']);
        if ($sitio === '' && $prop === '') return [false, 'Sin web ni Analytics configurados.'];
        $met = json_decode((string)$cl['met_json'], true);
        $met = is_array($met) ? $met : [];
        $def = $this->eventosDefecto();
        $ev = [];
        foreach (['ll', 'wa', 'fo'] as $k) $ev[$k] = trim((string)$cl['ga4_ev_' . $k]) !== '' ? (string)$cl['ga4_ev_' . $k] : $def[$k];

        $tocados = 0;
        $ultimo = '';
        $avisos = [];
        foreach ($meses as $ym) {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$ym)) continue;
            $desde = $ym . '-01';
            $hasta = date('Y-m-t', strtotime($desde));
            $nombre = self::nombreMes($ym);
            $e = isset($met[$nombre]) && is_array($met[$nombre]) ? $met[$nombre] : [];
            try {
                if ($sitio !== '') {
                    $sc = $this->searchConsole($sitio, $desde, $hasta);
                    $e['vi'] = $sc['clicks'];
                    $e['ap'] = $sc['impressions'];
                    $e['ctr'] = $sc['ctr'];
                }
                if ($prop !== '') {
                    foreach (['ll', 'wa', 'fo'] as $k) $e[$k] = $this->ga4Evento($prop, $ev[$k], $desde, $hasta);
                    if ($src = $this->ga4Reparto($prop, 'sessionDefaultChannelGroup', $desde, $hasta, 12)) $e['src'] = $src;
                    if ($geo = $this->ga4Reparto($prop, 'countryId', $desde, $hasta, 250)) $e['geo'] = $geo;
                }
            } catch (ErrorGoogle $err) {
                /* Sin conexión no hay nada que hacer con ningún mes. */
                if (in_array($err->codigo, ['sin_conectar', 'revocado', 'sin_configurar', 'red'], true)) return [false, $err->getMessage()];
                $avisos[] = $nombre . ': ' . $err->getMessage();
            }
            foreach (['ll', 'wa', 'fo', 'vi', 'ap', 'ctr'] as $k) $e[$k] ??= 0;   // el portal cuenta con todas
            $met[$nombre] = $e;
            $tocados++;
            $ultimo = $nombre;
        }
        if (!$tocados) return [false, 'Nada que sincronizar.'];
        $this->pdo->prepare('UPDATE clients SET met_json = ?, met_sync_at = NOW() WHERE id = ?')->execute([json_encode($met, JSON_UNESCAPED_UNICODE), $clientId]);
        $this->pdo->prepare("UPDATE clients SET actual = ? WHERE id = ? AND (actual IS NULL OR actual = '')")->execute([$ultimo, $clientId]);
        return $avisos ? [false, 'Con avisos · ' . implode(' · ', $avisos)] : [true, 'Actualizado · ' . $tocados . ' mes(es).'];
    }

    /** Todos los clientes con web o propiedad de Analytics. {ok, avisos, detalle[]} */
    public function sincronizarTodos(?array $meses = null): array
    {
        $out = ['ok' => 0, 'avisos' => 0, 'detalle' => []];
        $filas = $this->pdo->query("SELECT id, name FROM clients WHERE (gsc_site_url IS NOT NULL AND gsc_site_url <> '') OR (ga4_property_id IS NOT NULL AND ga4_property_id <> '') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filas as $c) {
            [$ok, $msg] = $this->sincronizarCliente((int)$c['id'], $meses);
            $out[$ok ? 'ok' : 'avisos']++;
            $out['detalle'][] = ['id' => (int)$c['id'], 'nombre' => (string)$c['name'], 'ok' => $ok, 'msg' => $msg];
        }
        $this->pdo->prepare("INSERT INTO settings (clave, valor) VALUES ('gm_auto_day', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([date('Y-m-d')]);
        return $out;
    }
}
