<?php
namespace Croilab\Modulos\Comunicacion\Chat;

use PDO;

/* Presencia del equipo (tabla chat_presence), con las reglas del antiguo:
     offline  sin latido en 65 s («últ. vez hace N min|h|d» o «sin conexión»)
     idle     con latido pero sin actividad en 5 min («ausente»)
     online   «en línea»
   El antiguo calculaba la de todo el equipo con una consulta por persona en
   cada sondeo de la sala; aquí va en una sola consulta y solo de quien hace falta. */
final class Presencia
{
    public const LATIDO = 65;
    public const INACTIVO = 300;

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Latido: «sigo aquí» (y, con $activo, «y estoy usando el ERP»). Para no
     * escribir en cada sondeo (cada pocos segundos por persona), solo cambia la
     * fila si el último latido tiene más de 15 s.
     */
    public function tocar(int $adminId, bool $activo): void
    {
        if ($adminId <= 0) return;
        $this->pdo->prepare(
            'INSERT INTO chat_presence (admin_id, last_seen, last_active) VALUES (?, NOW(), ' . ($activo ? 'NOW()' : 'NULL') . ')
             ON DUPLICATE KEY UPDATE
               last_seen = IF(last_seen IS NULL OR last_seen < NOW() - INTERVAL 15 SECOND, NOW(), last_seen)'
            . ($activo ? ', last_active = IF(last_active IS NULL OR last_active < NOW() - INTERVAL 15 SECOND, NOW(), last_active)' : '')
        )->execute([$adminId]);
    }

    /**
     * Estado de varias personas de una vez.
     * @param int[] $ids
     * @return array<int, array{estado:string, texto:string}>
     */
    public function estados(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $st = $this->pdo->prepare('SELECT admin_id, UNIX_TIMESTAMP(last_seen) ls, UNIX_TIMESTAMP(last_active) la, UNIX_TIMESTAMP(NOW()) ahora
                                   FROM chat_presence WHERE admin_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $filas = [];
        $ahora = time();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $filas[(int)$r['admin_id']] = $r;
            $ahora = (int)$r['ahora'];   // el reloj de la base, el mismo con el que se escribió
        }
        $out = [];
        foreach ($ids as $id) {
            $r = $filas[$id] ?? null;
            $out[$id] = self::calcular($ahora, (int)($r['ls'] ?? 0), (int)($r['la'] ?? 0));
        }
        return $out;
    }

    /** @return array{estado:string, texto:string} */
    public static function calcular(int $ahora, int $ultimoLatido, int $ultimaActividad): array
    {
        if (!$ultimoLatido || $ahora - $ultimoLatido > self::LATIDO) {
            return ['estado' => 'offline', 'texto' => $ultimoLatido ? 'últ. vez ' . self::hace($ahora - $ultimoLatido) : 'sin conexión'];
        }
        if (!$ultimaActividad || $ahora - $ultimaActividad > self::INACTIVO) return ['estado' => 'idle', 'texto' => 'ausente'];
        return ['estado' => 'online', 'texto' => 'en línea'];
    }

    private static function hace(int $s): string
    {
        if ($s < 3600) return 'hace ' . max(1, intdiv($s, 60)) . ' min';
        if ($s < 86400) return 'hace ' . intdiv($s, 3600) . ' h';
        return 'hace ' . intdiv($s, 86400) . ' d';
    }
}
