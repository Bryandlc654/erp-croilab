<?php
namespace Croilab\Tests\Integracion\Comunicacion;

use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Comunicacion\Mcp\McpServidor;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Tareas\TareasRepositorio;
use Croilab\Modulos\Tareas\TareasServicio;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* Servidor MCP: token, protocolo JSON-RPC y herramientas, actuando como una
   persona concreta (con su alcance) y no como un superusuario anónimo. */
class McpTest extends BaseDatosTestCase
{
    private const TOKEN = 'abcdefabcdefabcdefabcdefabcdefabcdefabcd';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        roles_todos(true);
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO roles (clave, nombre, descripcion, permisos, sistema, orden) VALUES ('limitado', 'Limitado', '', '[\"ver.tareas\",\"general.editar\",\"tareas.crear\",\"tareas.editar\"]', 0, 9)");
        roles_todos(true);
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role) VALUES (1,'duena','x','owner'),(2,'laura','x','editor'),(3,'limi','x','limitado')");
        $pdo->exec("INSERT INTO clients (id, name, activo) VALUES (1, 'Alfa', 1), (2, 'Beta', 1)");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre, orden) VALUES (1, 1, 'Tareas', 0), (2, 2, 'Tareas', 0)");
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id, etiquetas) VALUES (1, 1, 1, 'Web de Alfa', 2, 'web'), (2, 2, 2, 'De limi', 3, '')");
        $pdo->exec("INSERT INTO settings (clave, valor) VALUES ('mcp_enabled', '1'), ('mcp_token', '" . self::TOKEN . "')");
    }

    private function mcp(): McpServidor
    {
        $pdo = self::pdo();
        return new McpServidor($pdo, fn() => new TareasServicio(new TareasRepositorio($pdo, new EquipoRepositorio($pdo)), new ClientesRepositorio($pdo), new EquipoRepositorio($pdo)));
    }

    private function rpc(string $metodo, array $params = [], string $token = self::TOKEN): array
    {
        return $this->mcp()->atender((string)json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => $metodo, 'params' => $params]), $token);
    }

    private function herramienta(string $nombre, array $args): array
    {
        [$st, $r] = $this->rpc('tools/call', ['name' => $nombre, 'arguments' => $args]);
        $this->assertSame(200, $st);
        $texto = $r['result']['content'][0]['text'];
        return [!empty($r['result']['isError']), str_starts_with($texto, 'Error: ') ? $texto : json_decode($texto, true)];
    }

    public function testProtocoloYToken(): void
    {
        [$st, $r] = $this->rpc('initialize', [], 'malo');
        $this->assertSame([401, -32001], [$st, $r['error']['code']]);
        [$st, $r] = $this->rpc('initialize');
        $this->assertSame('2024-11-05', $r['result']['protocolVersion']);
        $this->assertSame(7, $r['id']);
        [$st, $r] = $this->rpc('tools/list');
        $this->assertSame(['listar_clientes', 'listar_equipo', 'listar_listas', 'listar_tareas', 'crear_tarea', 'actualizar_tarea', 'etiquetar_tarea', 'comentar_tarea'], array_column($r['result']['tools'], 'name'));
        $this->assertSame([202, null], $this->mcp()->atender('{"jsonrpc":"2.0","method":"notifications/initialized"}', self::TOKEN));
        $this->assertSame(400, $this->mcp()->atender('{no es json', self::TOKEN)[0]);
        $this->assertSame(-32601, $this->rpc('borrar_todo')[1]['error']['code']);
        [$st, $lote] = $this->mcp()->atender('[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","method":"notifications/x"}]', self::TOKEN);
        $this->assertCount(1, $lote);

        self::pdo()->exec("UPDATE settings SET valor = '0' WHERE clave = 'mcp_enabled'");
        $this->assertSame(401, $this->rpc('ping')[0], 'Desactivado: nada');
        self::pdo()->exec("UPDATE settings SET valor = '1' WHERE clave = 'mcp_enabled'");
    }

    public function testHerramientasComoLaDuena(): void
    {
        $this->assertSame(1, $this->mcp()->actor(), 'Sin mcp_actor: la primera con acceso total');
        [, $c] = $this->herramienta('listar_clientes', []);
        $this->assertSame(['Alfa', 'Beta'], array_column($c, 'name'));

        [$err, $r] = $this->herramienta('crear_tarea', ['cliente' => 'alf', 'titulo' => 'Desde Claude', 'lista' => 'SEO', 'responsable' => 'laura', 'due_date' => '2030-01-01', 'prioridad' => 3]);
        $this->assertFalse($err, is_string($r) ? $r : '');
        $t = self::pdo()->query('SELECT t.client_id, t.responsable_id, t.due_date, t.prioridad, l.nombre FROM tasks t JOIN task_lists l ON l.id = t.list_id WHERE t.id = ' . $r['id'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['client_id' => 1, 'responsable_id' => 2, 'due_date' => '2030-01-01', 'prioridad' => 3, 'nombre' => 'SEO'], $t);
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM notifications WHERE admin_id = 2 AND url = '/tareas/{$r['id']}'")->fetchColumn(), 'Avisa al responsable como la pantalla');

        [$err, $r2] = $this->herramienta('crear_tarea', ['cliente' => 'Nadie', 'titulo' => 'x']);
        $this->assertSame([true, 'Error: Cliente no encontrado.'], [$err, $r2]);
        [$err] = $this->herramienta('actualizar_tarea', ['id' => 1, 'due_date' => '2030-13-45']);
        $this->assertTrue($err, 'Valida como el servicio de Tareas');
        [, $e] = $this->herramienta('etiquetar_tarea', ['id' => 1, 'etiquetas' => 'seo, web']);
        $this->assertSame('web, seo', $e['etiquetas']);
        [, $k] = $this->herramienta('comentar_tarea', ['id' => 1, 'texto' => 'Hecho']);
        $this->assertSame(1, (int)self::pdo()->query('SELECT admin_id FROM task_comments WHERE id = ' . $k['comentario_id'])->fetchColumn(), 'Comenta con su nombre');
        [, $l] = $this->herramienta('listar_tareas', ['texto' => 'Claude']);
        $this->assertSame(['Desde Claude'], array_column($l, 'titulo'));
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM audit_log WHERE accion = 'mcp.crear_tarea'")->fetchColumn());
    }

    public function testActuaConElAlcanceDeSuPersona(): void
    {
        self::pdo()->exec("INSERT INTO settings (clave, valor) VALUES ('mcp_actor', '3') ON DUPLICATE KEY UPDATE valor = '3'");
        [, $l] = $this->herramienta('listar_tareas', []);
        $this->assertSame(['De limi'], array_column($l, 'titulo'), 'limi solo ve sus tareas');
        [$err] = $this->herramienta('comentar_tarea', ['id' => 1, 'texto' => 'no puedo']);
        $this->assertTrue($err);
        [$err] = $this->herramienta('crear_tarea', ['cliente' => 'Alfa', 'titulo' => 'x']);
        $this->assertTrue($err, 'Alfa no es de su alcance');
        self::pdo()->exec("UPDATE settings SET valor = '99' WHERE clave = 'mcp_actor'");
        $this->assertSame(1, $this->mcp()->actor(), 'Una persona que no existe no vale');
    }
}
