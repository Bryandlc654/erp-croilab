<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\ClientesServicio;
use Croilab\Seguridad\Acceso;
use PDO;

/* Lead → cliente (puentes.php, pu_lead_a_cliente). El cliente lo crea el
   servicio del módulo Clientes, para que se valide igual que un alta normal,
   con sus listas por defecto y el aviso «nuevo cliente de alta». Aquí solo se
   preparan los datos desde el contacto y su facturación y se cose el vínculo
   (clients.contact_id, contacts.client_id y deals.client_id), todo o nada.

   La contraseña del portal se devuelve una sola vez y no se guarda en claro
   ni se registra. */
final class ConversionServicio
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ContactosServicio $contactos,
        private readonly ClientesServicio $clientes,
        private readonly Historial $historial
    ) {}

    public function convertir(Acceso $acc, int $contactId, ?int $dealId = null): array
    {
        $acc->exigir('ver.crm', 'ver.clientes', 'general.editar', 'crm.convertir');
        $c = $this->contactos->visible($acc, $contactId);

        if ($c['client_id']) {
            $st = $this->pdo->prepare('SELECT id, name FROM clients WHERE id = ?');
            $st->execute([(int)$c['client_id']]);
            if ($ya = $st->fetch()) {
                return ['ya' => true, 'cliente_id' => (int)$ya['id'], 'usuario' => null, 'password' => null, 'msg' => 'Este contacto ya era el cliente «' . $ya['name'] . '».'];
            }
        }

        $st = $this->pdo->prepare('SELECT * FROM billing_data WHERE contact_id = ?');
        $st->execute([$contactId]);
        $b = $st->fetch() ?: [];
        $nombre = mb_substr(trim((string)($c['empresa'] ?: $c['nombre'])), 0, 160);
        $persona = trim((string)$c['nombre']);
        $letras = preg_replace('/[^\p{L}]/u', '', $nombre);
        $email = trim((string)(($b['email_facturacion'] ?? '') ?: ($c['email'] ?? '')));
        $dir = implode(', ', array_filter([
            trim((string)($b['direccion'] ?? '')),
            trim(trim((string)($b['cp'] ?? '')) . ' ' . trim((string)($b['ciudad'] ?? ''))),
            trim((string)($b['provincia'] ?? '')), trim((string)($b['pais'] ?? '')),
        ], fn($x) => $x !== ''));
        $password = password_generar(12);
        $usuario = $this->usuario($nombre);

        db_tx_begin($this->pdo);
        try {
            $id = $this->clientes->crear($acc, [
                'name' => $nombre,
                'username' => $usuario,
                'password' => $password,
                'saludo' => mb_substr($persona !== '' ? (preg_split('/\s+/u', $persona)[0] ?? '') : '', 0, 160),
                'iniciales' => $letras !== '' ? mb_strtoupper(mb_substr($letras, 0, 2)) : 'CL',
                'fact_nombre' => mb_substr(trim((string)($b['razon_social'] ?? '')) ?: $nombre, 0, 200),
                'fact_nif' => mb_substr(trim((string)($b['cif'] ?? '')), 0, 40),
                'fact_dir' => mb_substr($dir, 0, 300),
                'fact_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            ]);
            /* Columnas puente que el alta de Clientes no toca. */
            $this->pdo->prepare('UPDATE clients SET contact_id = ?, fact_tel = ? WHERE id = ?')
                ->execute([$contactId, mb_substr(trim((string)($c['telefono'] ?? '')), 0, 40), $id]);
            $this->pdo->prepare('UPDATE contacts SET client_id = ? WHERE id = ?')->execute([$id, $contactId]);
            $this->pdo->prepare('UPDATE deals SET client_id = ? WHERE contact_id = ?')->execute([$id, $contactId]);
            $this->historial->anotar($contactId, $dealId ?: null, 'cliente', 'Convertido en cliente: ' . $nombre);
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            if ($e instanceof HttpError) throw $e;
            error_log('CRM convertir contacto #' . $contactId . ': ' . $e->getMessage());
            throw new HttpError(500, 'No se ha podido crear el cliente.', 'convertir');
        }
        db_tx_commit($this->pdo);
        return ['ya' => false, 'cliente_id' => $id, 'usuario' => $usuario, 'password' => $password, 'msg' => 'Cliente «' . $nombre . '» creado.'];
    }

    /* Usuario del portal tecleable: sin acentos ni espacios, y único (x, x2, x3…). */
    private function usuario(string $nombre): string
    {
        $u = substr(Catalogos::ascii($nombre, ''), 0, 40) ?: 'cliente';
        $st = $this->pdo->prepare('SELECT 1 FROM clients WHERE username = ?');
        for ($n = 1, $try = $u; $n <= 500; $n++, $try = $u . $n) {
            $st->execute([$try]);
            if (!$st->fetchColumn()) return $try;
        }
        return $u . bin2hex(random_bytes(3));
    }
}
