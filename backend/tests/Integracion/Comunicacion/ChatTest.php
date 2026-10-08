<?php
namespace Croilab\Tests\Integracion\Comunicacion;

use Croilab\Http\HttpError;
use Croilab\Modulos\Comunicacion\Chat\Adjuntos;
use Croilab\Modulos\Comunicacion\Chat\ChatRepositorio;
use Croilab\Modulos\Comunicacion\Chat\ChatServicio;
use Croilab\Modulos\Comunicacion\Chat\Presencia;
use Croilab\Modulos\Comunicacion\Cron;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* Chat de punta a punta: salas, directos, grupos (y quién puede sacar a quién),
   mensajes, sondeo con cursor, reacciones, edición/borrado, adjuntos solo para
   los de la sala, avisador global y avisos de la campana.
   Personas: 1 duena (todo), 2 ana (editora), 3 lector (solo lectura), 4 baja (inactiva). */
class ChatTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const ANA = ['ver.chat', 'general.editar'];
    private const LECTOR = ['ver.chat'];
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role, activo) VALUES (1,'duena','x','owner',1),(2,'ana','x','editor',1),(3,'lector','x','viewer',1),(4,'baja','x','editor',0)");
        self::$dir = sys_get_temp_dir() . '/croilab-chat-test-' . bin2hex(random_bytes(4));
        mkdir(self::$dir);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (array_merge(glob(self::$dir . '/chat/*') ?: [], glob(self::$dir . '/chat/.htaccess') ?: [], glob(self::$dir . '/*.*') ?: []) as $f) @unlink($f);
        @rmdir(self::$dir . '/chat');
        @rmdir(self::$dir);
    }

    private function acc(int $id, array $p): Acceso
    {
        return new Acceso(self::pdo(), $id, $p);
    }

    private function chat(): ChatServicio
    {
        return new ChatServicio(new ChatRepositorio(self::pdo()), new EquipoRepositorio(self::pdo()), new Presencia(self::pdo()), new Adjuntos(self::$dir));
    }

    private function falla(callable $f, int $status): HttpError
    {
        try {
            $f();
        } catch (HttpError $e) {
            $this->assertSame($status, $e->status, $e->getMessage());
            return $e;
        }
        $this->fail("Se esperaba un error $status");
    }

    public function testDirectosGruposYPermisos(): void
    {
        $c = $this->chat();
        $duena = $this->acc(1, self::DUENA);
        $lector = $this->acc(3, self::LECTOR);

        /* Solo lectura puede abrir directos y escribir (decisión del antiguo), pero no crear grupos. */
        $dm = $c->abrirDirecto($lector, 1);
        $this->assertSame($dm, $c->abrirDirecto($duena, 3), 'El directo entre los dos es el mismo');
        $this->falla(fn() => $c->crearGrupo($lector, 'Grupo', [1]), 403);
        $this->falla(fn() => $c->abrirDirecto($duena, 4), 422);   // dado de baja
        $this->falla(fn() => $c->abrirDirecto($duena, 1), 422);   // conmigo
        $this->falla(fn() => $c->salas($this->acc(2, ['general.editar'])), 403);   // sin ver.chat

        $g = $c->crearGrupo($this->acc(2, self::ANA), 'Equipo SEO', [1, 3, 0]);
        $sala = $c->verSala($duena, $g);
        $this->assertSame('grupo', $sala['tipo']);
        $this->assertSame([1, 2, 3], array_column($sala['miembros'], 'id'));
        $this->assertSame(2, $sala['creado_por']);

        /* Sacar a otro: solo quien lo creó o quien gestiona el equipo. */
        $this->falla(fn() => $c->quitarMiembro($lector, $g, 1), 403);
        $this->assertSame([1, 2], array_column($c->quitarMiembro($duena, $g, 3)['miembros'], 'id'), 'Quien gestiona el equipo sí puede');
        $c->agregarMiembro($duena, $g, 3);
        $this->assertNull($c->quitarMiembro($lector, $g, 3), 'Salir uno mismo');
        $this->falla(fn() => $c->verSala($lector, $g), 404);   // ya no es miembro: 404
        $this->falla(fn() => $c->renombrar($duena, $dm, 'x'), 409);   // un directo no se renombra
        $this->assertSame('SEO', $c->renombrar($duena, $g, ' SEO ')['nombre']);
    }

    public function testMensajesSondeoYAvisos(): void
    {
        $c = $this->chat();
        $duena = $this->acc(1, self::DUENA);
        $ana = $this->acc(2, self::ANA);
        $g = $c->crearGrupo($ana, 'Diseño', [1]);

        $base = $c->avisos($duena, -1, true);
        $this->assertSame([], $base['mensajes']);
        $linea = $base['max'];

        $m1 = $c->enviar($ana, $g, "Hola @duena\r\n¿vemos los banners?", null);
        $this->assertSame("Hola @duena\n¿vemos los banners?", $m1['texto']);
        $this->falla(fn() => $c->enviar($ana, $g, '   ', null), 422);
        $this->falla(fn() => $c->enviar($this->acc(3, self::LECTOR), $g, 'hola', null), 404);   // no es de la sala

        /* Aviso de chat (uno vivo por sala y persona) y de mención (no silenciable). */
        $n = self::pdo()->query("SELECT tipo, titulo, url FROM notifications WHERE admin_id = 1 ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame([['tipo' => 'chat', 'titulo' => 'ha escrito en «Diseño»', 'url' => "/chat/$g"], ['tipo' => 'mencion', 'titulo' => 'te ha mencionado en «Diseño»', 'url' => "/chat/$g"]], $n);
        $c->enviar($ana, $g, 'Otro', null);
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM notifications WHERE admin_id = 1 AND tipo = 'chat'")->fetchColumn(), 'Se sustituye, no se acumula');

        /* Avisador global: lo nuevo desde la línea base. */
        $av = $c->avisos($duena, $linea, true);
        $this->assertCount(2, $av['mensajes']);
        $this->assertSame('Diseño', $av['mensajes'][0]['sala']);
        $this->assertTrue($av['mensajes'][0]['grupo']);
        $this->assertSame(2, $av['no_leidos']);
        $this->assertSame(2, $c->noLeidos(1));

        /* Abrir la sala: marca leído y quita el aviso de la campana. */
        $h = $c->historial($duena, $g, 0);
        $this->assertCount(2, $h['mensajes']);
        $this->assertFalse($h['hay_mas']);
        $this->assertSame(0, $c->noLeidos(1));
        $this->assertSame(0, (int)self::pdo()->query("SELECT COUNT(*) FROM notifications WHERE admin_id = 1 AND tipo = 'chat'")->fetchColumn());

        /* Respuesta, reacción, edición y borrado; el sondeo trae solo lo cambiado. */
        $cursor = $h['cursor'];
        $ultimo = end($h['mensajes'])['id'];
        $r = $c->enviar($duena, $g, 'Sí, a las 12', $m1['id']);
        $this->assertSame(['id' => $m1['id'], 'autor' => 'ana', 'extracto' => 'Hola @duena ¿vemos los banners?'], $r['responde_a']);
        usleep(20000);
        $c->reaccionar($duena, $m1['id'], '👍');
        $nov = $c->novedades($ana, $g, $ultimo, $cursor, true, true);
        $this->assertSame([$r['id']], array_column($nov['mensajes'], 'id'));
        $this->assertSame([$m1['id']], array_column($nov['cambios'], 'id'));
        $this->assertSame([['emoji' => '👍', 'total' => 1, 'mio' => false, 'personas' => ['duena']]], $nov['cambios'][0]['reacciones']);
        $this->assertSame($r['id'], $nov['leido_hasta'], 'duena ha leído hasta lo suyo (enviar marca como leído)');

        $this->falla(fn() => $c->editar($duena, $m1['id'], 'no es mío'), 403);
        $this->falla(fn() => $c->reaccionar($duena, $m1['id'], 'no vale'), 422);
        $e = $c->editar($ana, $m1['id'], 'Hola, ¿vemos los banners?');
        $this->assertTrue($e['editado']);
        $b = $c->borrar($ana, $m1['id']);
        $this->assertTrue($b['borrado']);
        $this->assertSame('', $b['texto']);
        $this->assertSame([], $b['reacciones']);
        $this->falla(fn() => $c->reaccionar($duena, $m1['id'], '👍'), 409);

        /* Escribiendo: aparece para los demás y caduca; enviar la borra. */
        $c->escribiendo($ana, $g);
        $this->assertSame(['ana'], array_column($c->novedades($duena, $g, 0, '', true, false)['escribiendo'], 'username'));
        $c->enviar($ana, $g, 'ya', null);
        $this->assertSame([], $c->novedades($duena, $g, 0, '', true, false)['escribiendo']);
        self::pdo()->exec('INSERT INTO chat_typing (room_id, admin_id, until_ts) VALUES (' . $g . ', 3, ' . (time() - 600) . ')');
        $this->assertSame(1, Cron::ejecutar(self::pdo())['escribiendo_borrados']);

        /* Silenciar el chat: el avisador lo dice para no sonar ni enseñar el pop-up. */
        self::pdo()->exec("INSERT INTO settings (clave, valor) VALUES ('notifmute_1', 'chat') ON DUPLICATE KEY UPDATE valor = 'chat'");
        $this->assertTrue($c->avisos($duena, $linea, true)['silenciado']);
    }

    public function testAdjuntosSoloParaLaSala(): void
    {
        $c = $this->chat();
        $duena = $this->acc(1, self::DUENA);
        $ana = $this->acc(2, self::ANA);
        $dm = $c->abrirDirecto($ana, 1);
        $png = self::$dir . '/foto.png';
        imagepng(imagecreatetruecolor(10, 10), $png);
        $m = $c->enviar($ana, $dm, '', null, [['tmp' => $png, 'nombre' => 'captura.png']]);
        $this->assertSame('captura.png', $m['adjuntos'][0]['nombre']);
        $this->assertTrue($m['adjuntos'][0]['imagen']);
        $this->assertSame("/api/v1/chat/adjuntos/{$m['id']}/0", $m['adjuntos'][0]['url']);

        [$ruta, $mime, $nombre, $enLinea] = $c->adjunto($duena, $m['id'], 0);
        $this->assertFileExists($ruta);
        $this->assertSame(['image/png', 'captura.png', true], [$mime, $nombre, $enLinea]);
        $this->falla(fn() => $c->adjunto($this->acc(3, self::LECTOR), $m['id'], 0), 404);   // no es de la sala
        $this->falla(fn() => $c->adjunto($duena, $m['id'], 5), 404);

        /* Borrar el mensaje borra el fichero. */
        $c->borrar($ana, $m['id']);
        $this->assertFileDoesNotExist($ruta);
        $this->falla(fn() => $c->adjunto($duena, $m['id'], 0), 404);

        $salas = $c->salas($duena);
        $this->assertContains($dm, array_column($salas['salas'], 'id'));
        $this->assertSame('ana', array_values(array_filter($salas['salas'], fn($s) => $s['id'] === $dm))[0]['nombre']);
        $this->assertNotContains(4, array_column($salas['personas'], 'id'), 'Las bajas no salen para escribirles');
        $this->assertArrayHasKey(2, (array)$salas['presencia']);
    }
}
