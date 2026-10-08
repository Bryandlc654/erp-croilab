<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use PDO;

/* Numeración legal de las facturas (RD 1619/2012):
   · El número se da al EMITIR, nunca al crear el borrador: un borrador
     abandonado o borrado no deja huecos.
   · Se asigna dentro de la transacción de la emisión con la fila del contador
     bloqueada (SELECT … FOR UPDATE): dos emisiones a la vez esperan su turno y
     reciben números consecutivos; si la emisión falla, el ROLLBACK devuelve
     también el contador (sin huecos).
   · Correlativo por serie, y la serie lleva el año: se reinicia cada año.
   · Una serie es de un solo emisor (cada autónomo numera lo suyo sin huecos).
   · La fecha de expedición no puede ser anterior a la última de su serie ni
     futura: el orden de los números y el de las fechas coinciden.
   · Las rectificativas van en su propia serie («R» delante).

   Formato: {PREFIJO}-{AÑO}-{NNN} (V-2026-001), igual que el ERP antiguo. Con
   serie propia, si no lleva el año se le añade: «F» → F2026-001, «SE-» →
   SE-2026-001, «2026-» → 2026-001. */
final class Numeracion
{
    public function __construct(private readonly PDO $pdo, private readonly EmisoresServicio $emisores) {}

    /** Clave del contador = número sin el correlativo. */
    public function serie(string $emisor, string $serie, string $fecha, string $tipo = 'normal'): string
    {
        $anio = substr($fecha, 0, 4);
        $serie = strtoupper(trim($serie));
        if ($serie === '') {
            $clave = $this->emisores->prefijo($emisor) . '-' . $anio . '-';
        } else {
            $clave = str_contains($serie, $anio) ? $serie : $serie . $anio . '-';
        }
        return $tipo === 'rectificativa' ? 'R' . $clave : $clave;
    }

    public static function formato(string $serie, int $n): string
    {
        return $serie . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
    }

    /** Valida lo que el usuario escribe como serie propia. */
    public static function validarSerie(string $serie): string
    {
        $s = strtoupper(trim($serie));
        if ($s === '') return '';
        if (mb_strlen($s) > 20) throw HttpError::validacion('La serie es demasiado larga (máximo 20).', 'serie');
        if (!preg_match('/^[A-Z0-9][A-Z0-9\-\/]*$/', $s)) throw HttpError::validacion('La serie solo admite letras, números, «-» y «/».', 'serie');
        return $s;
    }

    /** El número que tendría la siguiente factura de esa serie (sin reservarlo). */
    public function prevista(string $emisor, string $serie, string $fecha, string $tipo = 'normal'): string
    {
        $clave = $this->serie($emisor, $serie, $fecha, $tipo);
        $st = $this->pdo->prepare('SELECT ultimo FROM invoice_counters WHERE serie = ?');
        $st->execute([$clave]);
        $u = $st->fetchColumn();
        $n = $u === false ? $this->maximoExistente($clave) : (int)$u;
        return self::formato($clave, $n + 1);
    }

    /**
     * Reserva el siguiente número. SOLO dentro de una transacción abierta (la
     * de la emisión): el bloqueo dura hasta su COMMIT/ROLLBACK.
     * @return array{numero:string, serie:string, correlativo:int}
     */
    public function asignar(string $emisor, string $serie, string $fecha, string $tipo = 'normal'): array
    {
        if (!$this->pdo->inTransaction()) throw new \LogicException('Numeracion::asignar() necesita una transacción abierta.');
        $clave = $this->serie($emisor, $serie, $fecha, $tipo);
        if (mb_strlen($clave) > 36) throw HttpError::validacion('La serie es demasiado larga.', 'serie');

        /* Primera vez: el contador arranca en el número más alto que ya exista
           con esa serie (facturas antiguas). INSERT IGNORE: si otra emisión lo
           acaba de crear, se usa el suyo. */
        $this->pdo->prepare('INSERT IGNORE INTO invoice_counters (serie, ultimo, emisor) VALUES (?, ?, ?)')
            ->execute([$clave, $this->maximoExistente($clave), $emisor]);
        $st = $this->pdo->prepare('SELECT ultimo, emisor FROM invoice_counters WHERE serie = ? FOR UPDATE');
        $st->execute([$clave]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
        $duenio = (string)($fila['emisor'] ?? '');
        if ($duenio !== '' && $duenio !== $emisor) {
            throw HttpError::validacion('La serie «' . $clave . '» es de ' . $this->emisores->nombre($duenio) . '. Cada emisor necesita su propia serie.', 'serie');
        }

        $hoy = date('Y-m-d');
        if ($fecha > $hoy) throw HttpError::validacion('La fecha de expedición no puede ser futura. Emítela ese día o cambia la fecha.', 'fecha');
        $st = $this->pdo->prepare('SELECT MAX(fecha) FROM invoices WHERE serie = ? AND numero IS NOT NULL');
        $st->execute([$clave]);
        $ultima = (string)$st->fetchColumn();
        if ($ultima !== '' && $fecha < $ultima) {
            throw HttpError::validacion('La fecha no puede ser anterior a la de la última factura de la serie ' . $clave . ' (' . date('d/m/Y', strtotime($ultima)) . '): la numeración tiene que ir en orden.', 'fecha');
        }

        /* Si alguien escribió a mano un número en el ERP antiguo, se salta. */
        $n = (int)($fila['ultimo'] ?? 0);
        $existe = $this->pdo->prepare('SELECT 1 FROM invoices WHERE numero = ?');
        do {
            $n++;
            $existe->execute([self::formato($clave, $n)]);
        } while ($existe->fetchColumn());
        $this->pdo->prepare('UPDATE invoice_counters SET ultimo = ?, emisor = ? WHERE serie = ?')->execute([$n, $emisor, $clave]);
        return ['numero' => self::formato($clave, $n), 'serie' => $clave, 'correlativo' => $n];
    }

    /** Fecha de la última factura emitida en esa serie (o null). */
    public function ultimaFecha(string $clave): ?string
    {
        $st = $this->pdo->prepare('SELECT MAX(fecha) FROM invoices WHERE serie = ? AND numero IS NOT NULL');
        $st->execute([$clave]);
        $v = $st->fetchColumn();
        return $v ? (string)$v : null;
    }

    private function maximoExistente(string $clave): int
    {
        $st = $this->pdo->prepare("SELECT numero FROM invoices WHERE numero LIKE ? ESCAPE '\\\\'");
        $st->execute([Validar::like($clave) . '%']);
        $max = 0;
        $re = '/^' . preg_quote($clave, '/') . '(\d+)\s*$/';
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $num) {
            if (preg_match($re, (string)$num, $m)) $max = max($max, (int)$m[1]);
        }
        return $max;
    }
}
