<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Seguridad\Acceso;
use PDO;

/* Datos fiscales de los clientes (clients.fact_*): los que se copian solos a
   sus facturas. Pantalla «Facturación de clientes» y selector del editor. */
final class ClientesFacturacion
{
    public function __construct(private readonly PDO $pdo) {}

    public static function item(array $c): array
    {
        return ['id' => (int)$c['id'], 'name' => (string)$c['name'],
                'fact_nombre' => (string)($c['fact_nombre'] ?? ''), 'fact_nif' => (string)($c['fact_nif'] ?? ''), 'fact_dir' => (string)($c['fact_dir'] ?? ''),
                'fact_email' => (string)($c['fact_email'] ?? ''), 'fact_tel' => (string)($c['fact_tel'] ?? ''),
                'completo' => trim((string)($c['fact_nombre'] ?? '')) !== ''];
    }

    public function listar(Acceso $acc): array
    {
        $acc->exigir('ver.finanzas');
        $st = $this->pdo->query('SELECT c.id, c.name, c.fact_nombre, c.fact_nif, c.fact_dir, c.fact_email, c.fact_tel, c.activo FROM clients c
                                 WHERE 1=1 ' . $acc->sqlClientes('c.id') . ' ORDER BY c.activo DESC, c.name');
        $items = [];
        $sin = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $i = self::item($c) + ['activo' => (bool)$c['activo']];
            if (!$i['completo']) $sin++;
            $items[] = $i;
        }
        return ['items' => $items, 'sin_datos' => $sin];
    }

    /** Sobrescribe los cinco campos (también vacíos), como el formulario antiguo. */
    public function guardar(Acceso $acc, int $id, array $d): array
    {
        Validar::escribe($acc, 'finanzas.emitir');
        if (!$acc->veCliente($id)) throw HttpError::noEncontrado('Cliente no encontrado.');
        $st = $this->pdo->prepare('SELECT id FROM clients WHERE id = ?');
        $st->execute([$id]);
        if (!$st->fetchColumn()) throw HttpError::noEncontrado('Cliente no encontrado.');
        $v = [
            Validar::texto($d, 'fact_nombre', 200), Validar::texto($d, 'fact_nif', 40), Validar::texto($d, 'fact_dir', 300),
            Validar::email($d, 'fact_email'), Validar::texto($d, 'fact_tel', 40),
        ];
        $this->pdo->prepare('UPDATE clients SET fact_nombre = ?, fact_nif = ?, fact_dir = ?, fact_email = ?, fact_tel = ? WHERE id = ?')->execute([...$v, $id]);
        $st = $this->pdo->prepare('SELECT id, name, fact_nombre, fact_nif, fact_dir, fact_email, fact_tel FROM clients WHERE id = ?');
        $st->execute([$id]);
        return self::item($st->fetch(PDO::FETCH_ASSOC));
    }
}
