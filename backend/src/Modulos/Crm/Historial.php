<?php
namespace Croilab\Modulos\Crm;

use PDO;

/* Lo que escribe el CRM «por detrás» en cada acción (crm_lib.php del antiguo):
   el historial de actividad, la columna «Última actualización» y la fase del
   contacto según sus negocios. */
final class Historial
{
    public function __construct(private readonly PDO $pdo) {}

    public function anotar(?int $contactId, ?int $dealId, string $tipo, string $descripcion): void
    {
        $this->pdo->prepare('INSERT INTO activities (contact_id, deal_id, tipo, descripcion, fecha) VALUES (?,?,?,?,NOW())')
            ->execute([$contactId ?: null, $dealId ?: null, mb_substr($tipo, 0, 40), mb_substr($descripcion, 0, 255)]);
    }

    /** Llamada, email, WhatsApp o reunión: el contacto se ha tocado hoy. */
    public function contactado(int $contactId): void
    {
        $this->pdo->prepare('UPDATE contacts SET fecha_ultimo_contacto = CURDATE() WHERE id = ?')->execute([$contactId]);
    }

    /** «Última actualización» = el último comentario, en una línea de 80 caracteres. */
    public function sincronizarUltima(int $contactId): void
    {
        $st = $this->pdo->prepare('SELECT contenido FROM comments WHERE contact_id = ? ORDER BY fecha DESC, id DESC LIMIT 1');
        $st->execute([$contactId]);
        $c = $st->fetchColumn();
        $c = $c !== false ? mb_substr((string)preg_replace('/\s+/u', ' ', trim((string)$c)), 0, 80) : null;
        $this->pdo->prepare('UPDATE contacts SET ultima_actualizacion = ? WHERE id = ?')->execute([$c, $contactId]);
    }

    /**
     * Fase del contacto según sus negocios no archivados (crm_sync_fase_contacto):
     * manda el negocio abierto más avanzado; si no hay abiertos, ganado; si solo
     * hay perdidos, perdido; sin negocios no se toca (manda lo puesto a mano).
     * Devuelve la fase nueva o null si no cambia.
     */
    public function sincronizarFase(int $contactId, ?array $fases = null): ?string
    {
        $fases ??= Catalogos::fases($this->pdo);
        $st = $this->pdo->prepare('SELECT fase FROM deals WHERE contact_id = ? AND archivado = 0');
        $st->execute([$contactId]);
        $suyas = $st->fetchAll(PDO::FETCH_COLUMN);
        if (!$suyas) return null;

        $mejor = null;
        $ordenMejor = -1;
        $ganada = $perdida = false;
        foreach ($suyas as $f) {
            $s = $fases[$f] ?? null;
            if (!$s) continue;
            if ($s['tipo'] === 'ganada') $ganada = true;
            elseif ($s['tipo'] === 'perdida') $perdida = true;
            elseif ($s['tipo'] === 'abierta' && $s['orden'] > $ordenMejor) {
                $ordenMejor = $s['orden'];
                $mejor = $f;
            }
        }
        if ($mejor === null && $ganada) $mejor = Catalogos::slugsDeTipo($fases, 'ganada')[0] ?? null;
        if ($mejor === null && $perdida) $mejor = Catalogos::slugsDeTipo($fases, 'perdida')[0] ?? null;
        if ($mejor === null) return null;

        $st = $this->pdo->prepare('UPDATE contacts SET fase = ? WHERE id = ? AND fase <> ?');
        $st->execute([$mejor, $contactId, $mejor]);
        return $st->rowCount() > 0 ? $mejor : null;
    }
}
