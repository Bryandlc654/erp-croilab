<?php
namespace Croilab\Modulos\Nav;

use Croilab\Http\Request;
use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;

/* Contadores de la barra lateral. Solo números: la lista de clientes se pide
   por páginas a /clientes cuando se despliega cada sección. */
class NavController
{
    public function __construct(
        private readonly ClientesRepositorio $clientes,
        private readonly EquipoRepositorio $equipo
    ) {}

    public function nav(Request $req): array
    {
        $acc = Acceso::actual();
        $pend = db()->query("SELECT COUNT(*) FROM tasks t WHERE t.estado <> 'completada'" . $acc->sqlTareas('t'))->fetchColumn();
        $st = db()->prepare("SELECT COUNT(*) FROM notifications WHERE admin_id = ? AND leido = 0 AND borrado = 0 AND tipo <> 'chat'
                             AND (snooze_until IS NULL OR snooze_until <= NOW())");
        $st->execute([$acc->adminId]);
        require_once __DIR__ . '/../../../admin/lib/marca.php';
        $marca = marca_agencia();
        return [
            'marca' => $marca['name'],
            'marca_info' => ['logo' => $marca['logo'], 'color' => $marca['color']],
            'pendientes' => (int)$pend,
            'no_leidas' => (int)$st->fetchColumn(),
            'clientes' => $this->clientes->contadores($acc),
            // Mensajes del chat sin leer en todas sus salas (contador del raíl).
            'chat_no_leidos' => $acc->puede('ver.chat') ? $this->chatNoLeidos($acc->adminId) : 0,
        ];
    }

    private function chatNoLeidos(int $adminId): int
    {
        try {
            return (new \Croilab\Modulos\Comunicacion\Chat\ChatRepositorio(db()))->noLeidosTotal($adminId);
        } catch (\Throwable $e) {
            return 0;   // sin las tablas del chat, el menú sigue funcionando
        }
    }

    public function equipo(Request $req): array
    {
        return ['items' => $this->equipo->activos()];
    }
}
