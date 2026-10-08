<?php
namespace Croilab\Tests\Integracion\Trabajo;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\ClientesRepositorio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Tareas\ArchivosTarea;
use Croilab\Modulos\Tareas\FichaRepositorio;
use Croilab\Modulos\Tareas\FichaServicio;
use Croilab\Modulos\Tareas\ListasRepositorio;
use Croilab\Modulos\Tareas\ListasServicio;
use Croilab\Modulos\Tareas\TareasRepositorio;
use Croilab\Modulos\Tareas\TareasServicio;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* Ficha de la tarea (checklist, comentarios, reacciones, adjuntos, horas) y
   listas de un cliente, contra la base. Personas:
     1 duena (todo) · 2 ana (alcance limitado, edita) · 3 luis (lo ve todo, sin editar)
   Tareas: 1 de ana (Alfa) · 2 de la dueña (Beta). */
class FichaYListasTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const ANA = ['ver.tareas', 'general.editar', 'tareas.editar', 'tareas.crear', 'tareas.horas'];
    private const LUIS = ['ver.tareas', 'alcance.todos'];
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role) VALUES (1,'duena','x','owner'), (2,'ana','x','editor'), (3,'luis','x','viewer')");
        $pdo->exec("INSERT INTO clients (id, name, activo) VALUES (1,'Alfa',1), (2,'Beta',1)");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre, tipo) VALUES (1,1,'Tareas','tareas'), (2,2,'Tareas','tareas'), (3,1,'Informes','informe')");
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id, estado) VALUES (1,1,1,'De ana',2,'pendiente'), (2,2,2,'De la dueña',1,'pendiente')");
        self::$dir = sys_get_temp_dir() . '/croilab-tareas-' . bin2hex(random_bytes(4));
    }

    protected function setUp(): void
    {
        self::pdo()->exec('DELETE FROM notifications');
    }

    private function acc(int $id, array $p): Acceso
    {
        return new Acceso(self::pdo(), $id, $p);
    }

    private function tareas(): TareasServicio
    {
        $eq = new EquipoRepositorio(self::pdo());
        return new TareasServicio(new TareasRepositorio(self::pdo(), $eq), new ClientesRepositorio(self::pdo()), $eq);
    }

    private function ficha(): FichaServicio
    {
        $eq = new EquipoRepositorio(self::pdo());
        $repo = new TareasRepositorio(self::pdo(), $eq);
        return new FichaServicio($repo, new FichaRepositorio(self::pdo(), $eq), $this->tareas(), $eq, new ArchivosTarea(self::$dir));
    }

    private function listas(): ListasServicio
    {
        return new ListasServicio(new ListasRepositorio(self::pdo()), new ClientesRepositorio(self::pdo()));
    }

    private function avisos(int $a): array
    {
        $st = self::pdo()->prepare('SELECT titulo, url FROM notifications WHERE admin_id = ? ORDER BY id');
        $st->execute([$a]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function codigo(callable $f): int
    {
        try { $f(); } catch (HttpError $e) { return $e->status; }
        return 200;
    }

    /* ---------- Alcance y permisos ---------- */

    public function testLaFichaDeOtraTareaEs404YElLectorNoEscribe(): void
    {
        $ana = $this->acc(2, self::ANA);
        $this->assertSame(404, $this->codigo(fn() => $this->ficha()->ficha($ana, 2)));
        $this->assertSame(404, $this->codigo(fn() => $this->ficha()->comentar($ana, 2, ['cuerpo' => 'hola'], [])));
        $this->assertSame(404, $this->codigo(fn() => $this->ficha()->crearPunto($ana, 2, ['texto' => 'x'])));
        $luis = $this->acc(3, self::LUIS);
        $this->assertSame('De la dueña', $this->ficha()->ficha($luis, 2)['titulo']);
        $this->assertSame(403, $this->codigo(fn() => $this->ficha()->comentar($luis, 2, ['cuerpo' => 'hola'], [])));
        $this->assertSame(403, $this->codigo(fn() => $this->ficha()->fijarTiempo($luis, 2, ['horas' => 1])));
        $this->assertFalse($this->ficha()->ficha($luis, 2)['permisos']['editar']);
    }

    /* ---------- Tarea: asignados, descripción, actividad ---------- */

    public function testAsignarAVariosAvisaSoloALosNuevosYAnota(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $t = $this->tareas()->actualizar($duena, 2, ['asignados' => [2, 3]]);
        $this->assertSame(['ana', 'luis'], array_column($t['asignados'], 'username'));
        $this->assertSame(2, $t['responsable_id'], 'El primero es el responsable');
        $this->assertCount(1, $this->avisos(2));
        $this->tareas()->actualizar($duena, 2, ['asignados' => [3, 2, 1]]);
        $this->assertCount(1, $this->avisos(2), 'A ana ya se le avisó');
        $acts = array_column($this->tareas()->detalle($duena, 2)['actividad'], 'tipo');
        $this->assertContains('asignados', $acts);
        $this->assertSame(422, $this->codigo(fn() => $this->tareas()->actualizar($duena, 2, ['asignados' => [99]])));
    }

    public function testLaDescripcionGuardaRicaYPlanaYAvisaMenciones(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $this->tareas()->actualizar($duena, 2, ['descripcion' => "# Hola\n- **uno** para @ana\n[[chk:1]] hecho\n[[img:2_ab.png]]"]);
        $r = self::pdo()->query('SELECT descripcion, descripcion_rich FROM tasks WHERE id = 2')->fetch();
        $this->assertSame("Hola\n• uno para @ana\n• hecho", $r['descripcion']);
        $this->assertStringContainsString('[[chk:1]]', $r['descripcion_rich']);
        $this->assertSame('/tareas/2', $this->avisos(2)[0]['url'] ?? null);
        /* La ficha devuelve la rica para editarla. */
        $this->assertStringStartsWith('# Hola', $this->ficha()->ficha($duena, 2)['descripcion']);
    }

    public function testReordenarSoloTocaLaLista(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $nueva = $this->tareas()->crear($duena, ['client_id' => 1, 'list_id' => 1, 'titulo' => 'Segunda']);
        $this->tareas()->reordenar($duena, 1, [$nueva['id'], 1, 2]);
        $orden = self::pdo()->query('SELECT id FROM tasks WHERE list_id = 1 ORDER BY orden')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([$nueva['id'], 1], array_map('intval', $orden));
        $this->assertSame(0, (int)self::pdo()->query('SELECT orden FROM tasks WHERE id = 2')->fetchColumn(), 'La tarea de otra lista no se toca');
    }

    /* ---------- Lista de control ---------- */

    public function testChecklistConAsignadosYAvisos(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $l = $this->ficha()->crearPunto($duena, 1, ['texto' => '  Revisar   enlaces ', 'asignados' => [2]]);
        $p = end($l);
        $this->assertSame('Revisar enlaces', $p['texto']);
        $this->assertSame([2], $p['asignados']);
        $this->assertStringStartsWith('te asignó un punto', $this->avisos(2)[0]['titulo']);
        $l = $this->ficha()->cambiarPunto($duena, 1, $p['id'], ['done' => true]);
        $this->assertTrue(array_values(array_filter($l, fn($x) => $x['id'] === $p['id']))[0]['done']);
        $this->assertStringStartsWith('completó un punto', $this->avisos(2)[1]['titulo']);
        $this->ficha()->borrarPunto($duena, 1, $p['id']);
        $this->assertFalse(self::pdo()->query('SELECT 1 FROM chk_assignees WHERE chk_id = ' . $p['id'])->fetchColumn(), 'Sin asignados huérfanos');
        $this->assertSame(404, $this->codigo(fn() => $this->ficha()->cambiarPunto($duena, 2, $p['id'], ['done' => true])));
    }

    /* ---------- Comentarios ---------- */

    public function testComentarRespondeMencionaYSoloElAutorEdita(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $ana = $this->acc(2, self::ANA);
        $c1 = $this->ficha()->comentar($ana, 1, ['cuerpo' => 'Primero'], []);
        $c2 = $this->ficha()->comentar($duena, 1, ['cuerpo' => 'Vale', 'reply_to' => $c1['id'], 'checklist' => '[{"texto":"Hacer A","resp":2},{"texto":"  "}]'], []);
        $this->assertSame($c1['id'], $c2['reply_to']);
        $this->assertSame([['texto' => 'Hacer A', 'done' => false, 'resp' => 2]], $c2['checklist']);
        $titulos = array_column($this->avisos(2), 'titulo');
        $this->assertContains('te ha respondido: Vale', $titulos);
        $this->assertSame(403, $this->codigo(fn() => $this->ficha()->editarComentario($ana, 1, $c2['id'], ['cuerpo' => 'pisado'])));
        $this->assertSame(403, $this->codigo(fn() => $this->ficha()->borrarComentario($ana, 1, $c2['id'])));
        /* La checklist del comentario sí la marca cualquiera que pueda comentar. */
        $this->assertTrue($this->ficha()->marcarPuntoComentario($ana, 1, $c2['id'], 0)[0]['done']);
        $e = $this->ficha()->editarComentario($duena, 1, $c2['id'], ['cuerpo' => 'Vale, editado']);
        $this->assertTrue($e['editado']);
        $this->assertSame(422, $this->codigo(fn() => $this->ficha()->comentar($duena, 1, ['cuerpo' => '   '], [])));
        $this->ficha()->borrarComentario($duena, 1, $c2['id']);
        $this->assertSame(1, $this->ficha()->comentarios($ana, 1)['n']);
    }

    public function testCadaEmojiEsUnaReaccionDistinta(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $c = $this->ficha()->comentar($duena, 1, ['cuerpo' => 'Reacciona'], []);
        $this->ficha()->reaccionar($duena, 1, $c['id'], '👍');
        $r = $this->ficha()->reaccionar($duena, 1, $c['id'], '🔥');
        $this->assertSame(['👍', '🔥'], array_column($r, 'emoji'), 'Con general_ci 🔥 chocaba con 👍');
        $r = $this->ficha()->reaccionar($duena, 1, $c['id'], '🔥');
        $this->assertSame(['👍'], array_column($r, 'emoji'));
        $this->assertSame(422, $this->codigo(fn() => $this->ficha()->reaccionar($duena, 1, $c['id'], '<b>')));
    }

    /* ---------- Adjuntos ---------- */

    public function testAdjuntosConListaBlancaYImagenReal(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $png = tempnam(sys_get_temp_dir(), 'png');
        $im = imagecreatetruecolor(4, 4);
        imagepng($im, $png);
        $txt = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($txt, 'hola');
        $adj = $this->ficha()->adjuntar($duena, 1, [['tmp' => $png, 'nombre' => 'foto.png', 'error' => 0], ['tmp' => $txt, 'nombre' => 'nota.txt', 'error' => 0]], false);
        $this->assertSame(['foto.png', 'nota.txt'], array_column($adj, 'nombre'));
        $this->assertTrue($adj[0]['es_imagen']);
        $this->assertStringStartsWith('archivo.php?d=tasks&f=1_', $adj[0]['url']);
        $this->assertFileExists(self::$dir . '/' . $adj[0]['filename']);

        $this->assertSame(422, $this->codigo(fn() => $this->ficha()->adjuntar($duena, 1, [['tmp' => $txt, 'nombre' => 'x.php', 'error' => 0]], false)));
        $this->assertSame(422, $this->codigo(fn() => $this->ficha()->adjuntar($duena, 1, [['tmp' => $txt, 'nombre' => 'falsa.png', 'error' => 0]], false)));

        $this->ficha()->quitarAdjunto($duena, 1, $adj[0]['id']);
        $this->assertFileDoesNotExist(self::$dir . '/' . $adj[0]['filename']);
    }

    /* ---------- Horas ---------- */

    public function testHorasPorPersonaSinMoverLaFecha(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $t = $this->ficha()->fijarTiempo($duena, 1, ['horas' => '1,5', 'admin_id' => 2]);
        $this->assertSame(90, $t['total_min']);
        self::pdo()->exec("UPDATE time_entries SET fecha = '2026-01-31' WHERE task_id = 1 AND admin_id = 2");
        self::pdo()->exec("INSERT INTO time_entries (admin_id, task_id, client_id, fecha, minutos, concepto) VALUES (2, 1, 1, '2026-01-10', 45, 'Reunión con el cliente')");
        $t = $this->ficha()->fijarTiempo($duena, 1, ['horas' => '2', 'admin_id' => 2]);
        $this->assertSame(120, $t['total_min'], 'Solo cuenta la línea de la ficha');
        $this->assertSame('2026-01-31', self::pdo()->query("SELECT fecha FROM time_entries WHERE task_id = 1 AND concepto = 'Horas de la tarea'")->fetchColumn());
        $this->assertSame(45, (int)self::pdo()->query("SELECT minutos FROM time_entries WHERE concepto = 'Reunión con el cliente'")->fetchColumn(), 'Las horas de Finanzas no se tocan');
        $this->assertSame(422, $this->codigo(fn() => $this->ficha()->fijarTiempo($duena, 1, ['horas' => 'dos'])));
        $this->ficha()->fijarTiempo($duena, 1, ['horas' => '', 'admin_id' => 2]);
        $this->assertSame(0, $this->ficha()->ficha($duena, 1)['tiempo']['total_min']);
    }

    /* ---------- Listas ---------- */

    public function testListasPorDefectoInformeUnicoYClonar(): void
    {
        $duena = $this->acc(1, self::DUENA);
        self::pdo()->exec("INSERT INTO clients (id, name, activo) VALUES (9,'Nuevo',1)");
        $r = $this->listas()->crear($duena, ['client_id' => 9, 'por_defecto' => true]);
        $this->assertSame(['TAREAS', 'ESTRATEGIA', 'TAREA CLIENTE', 'INFORMES CLIENTE'], array_column($r['listas'], 'nombre'));
        $this->assertSame(422, $this->codigo(fn() => $this->listas()->crear($duena, ['client_id' => 9, 'tipo' => 'informe'])));
        $c = $this->listas()->clonar($duena, 1);
        $this->assertSame('Tareas (copia)', array_column($c['listas'], 'nombre', 'id')[$c['id']]);
        $this->assertGreaterThan(0, (int)self::pdo()->query('SELECT COUNT(*) FROM tasks WHERE list_id = ' . $c['id'])->fetchColumn());
        $ana = $this->acc(2, self::ANA);
        $this->assertSame(404, $this->codigo(fn() => $this->listas()->actualizar($ana, $r['listas'][0]['id'], ['nombre' => 'X'])), 'Lista de un cliente que no ve');
    }

    public function testInformeDelMesSeGuardaYPublica(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $i = $this->listas()->guardarInforme($duena, 3, ['mes' => 'Octubre 2026', 'texto' => 'Este mes hemos crecido.']);
        $this->assertTrue($i['publicado']);
        $i = $this->listas()->guardarInforme($duena, 3, ['mes' => 'Octubre 2026', 'texto' => 'Corregido.']);
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM tasks WHERE list_id = 3 AND titulo = 'Informe del mes'")->fetchColumn());
        $json = json_decode((string)self::pdo()->query('SELECT informes_json FROM clients WHERE id = 1')->fetchColumn(), true);
        $this->assertSame('Corregido.', $json[0]['texto'] ?? null);
        $this->assertSame(422, $this->codigo(fn() => $this->listas()->guardarInforme($duena, 1, ['mes' => 'Octubre 2026', 'texto' => 'x'])), 'No es de informes');
    }

    public function testBorrarListaYRestaurarLaDevuelveEntera(): void
    {
        $duena = $this->acc(1, self::DUENA);
        self::pdo()->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (50, 2, 'Temporal')");
        self::pdo()->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id) VALUES (50, 2, 50, 'Hija', 2)");
        self::pdo()->exec("INSERT INTO task_assignees (task_id, admin_id) VALUES (50, 2), (50, 3)");
        self::pdo()->exec("INSERT INTO task_comments (id, task_id, admin_id, cuerpo) VALUES (500, 50, 1, 'nota')");
        self::pdo()->exec("INSERT INTO task_comment_reactions (comment_id, admin_id, emoji) VALUES (500, 2, '👍')");
        self::pdo()->exec("INSERT INTO task_checklist (id, task_id, texto) VALUES (500, 50, 'p')");
        self::pdo()->exec("INSERT INTO chk_assignees (chk_id, admin_id) VALUES (500, 3)");
        $tid = $this->listas()->borrar($duena, 50);
        foreach (['tasks WHERE id = 50', 'task_assignees WHERE task_id = 50', 'task_comment_reactions WHERE comment_id = 500', 'chk_assignees WHERE chk_id = 500'] as $q) {
            $this->assertSame(0, (int)self::pdo()->query("SELECT COUNT(*) FROM $q")->fetchColumn(), $q);
        }
        $r = pap_restaurar($tid);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, (int)self::pdo()->query('SELECT COUNT(*) FROM task_assignees WHERE task_id = 50')->fetchColumn());
        $this->assertSame(1, (int)self::pdo()->query('SELECT COUNT(*) FROM task_comment_reactions WHERE comment_id = 500')->fetchColumn());
        $this->assertSame(1, (int)self::pdo()->query('SELECT COUNT(*) FROM chk_assignees WHERE chk_id = 500')->fetchColumn());
        $this->assertSame('nota', self::pdo()->query('SELECT cuerpo FROM task_comments WHERE id = 500')->fetchColumn());
    }
}
