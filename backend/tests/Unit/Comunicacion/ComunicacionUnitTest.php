<?php
namespace Croilab\Tests\Unit\Comunicacion;

use Croilab\Http\HttpError;
use Croilab\Modulos\Comunicacion\Calendario\Festivos;
use Croilab\Modulos\Comunicacion\Calendario\GoogleCalendario;
use Croilab\Modulos\Comunicacion\Calendario\CalendarioServicio;
use Croilab\Modulos\Comunicacion\Chat\Adjuntos;
use Croilab\Modulos\Comunicacion\Chat\ChatServicio;
use Croilab\Modulos\Comunicacion\Chat\Presencia;
use Croilab\Modulos\Comunicacion\Mcp\McpServidor;
use Croilab\Modulos\Comunicacion\Reuniones\ReunionesServicio;
use PHPUnit\Framework\TestCase;

/* Lógica pura de Comunicación: festivos, formato de eventos de Google,
   presencia, texto del chat, tipos de adjunto y token del MCP. */
class ComunicacionUnitTest extends TestCase
{
    public function testViernesSantoYFestivosDelRango(): void
    {
        $this->assertSame('2026-04-03', Festivos::viernesSanto(2026));
        $this->assertSame('2025-04-18', Festivos::viernesSanto(2025));
        $this->assertSame('2024-03-29', Festivos::viernesSanto(2024));
        $f = Festivos::entre('2026-12-01', '2027-01-07');
        $this->assertSame(['2026-12-06' => 'Constitución', '2026-12-08' => 'Inmaculada', '2026-12-25' => 'Navidad', '2027-01-01' => 'Año Nuevo', '2027-01-06' => 'Reyes'], $f);
    }

    public function testNormalizaEventosConZonaDeMadrid(): void
    {
        $e = GoogleCalendario::normalizar([
            'id' => 'abc', 'summary' => 'Reunión', 'start' => ['dateTime' => '2026-10-08T08:00:00Z'], 'end' => ['dateTime' => '2026-10-08T09:30:00Z'],
            'attendees' => [['email' => 'Yo@x.com', 'self' => true], ['email' => 'Otro@Cliente.com']],
            'hangoutLink' => 'https://meet.google.com/x', 'organizer' => ['self' => true],
            'attachments' => [['title' => 'Notas', 'fileUrl' => 'https://docs.google.com/d/1'], ['title' => 'Malo', 'fileUrl' => 'javascript:alert(1)']],
            'reminders' => ['useDefault' => false, 'overrides' => [['method' => 'popup', 'minutes' => 30]]],
            'extendedProperties' => ['private' => ['erp_meeting' => '7']], 'recurringEventId' => 'serie1',
        ]);
        $this->assertSame('2026-10-08', $e['dia']);
        $this->assertSame('10:00', $e['hora']);        // UTC+2 en octubre
        $this->assertSame('11:30', $e['hora_fin']);
        $this->assertSame(['otro@cliente.com'], $e['invitados']);
        $this->assertTrue($e['meet']);
        $this->assertTrue($e['editable']);
        $this->assertSame([['titulo' => 'Notas', 'url' => 'https://docs.google.com/d/1']], $e['docs']);
        $this->assertSame('30', $e['recordar']);
        $this->assertSame('7', $e['erp_meeting']);
        $this->assertSame('serie1', $e['serie_id']);

        $dia = GoogleCalendario::normalizar(['id' => 'b', 'start' => ['date' => '2026-12-24'], 'end' => ['date' => '2026-12-26'], 'reminders' => ['useDefault' => false]]);
        $this->assertTrue($dia['todo_el_dia']);
        $this->assertSame('2026-12-25', $dia['dia_fin']);   // fin exclusivo de Google → último día incluido
        $this->assertSame('(sin título)', $dia['titulo']);
        $this->assertSame('no', $dia['recordar']);
        $this->assertNull(GoogleCalendario::normalizar(['id' => 'c', 'start' => []]));
    }

    public function testCuerpoDelEvento(): void
    {
        [$b, $hay] = GoogleCalendario::cuerpo(['titulo' => ' Comida ', 'fecha' => '2026-10-08', 'hora' => '23:30', 'invitados' => 'a@b.com, no-vale; c@d.com',
                                                'meet' => true, 'gemini' => true, 'recur' => 'weekly', 'recordar' => '10', 'erp_meeting' => 5], false);
        $this->assertTrue($hay);
        $this->assertSame('Comida', $b['summary']);
        $this->assertSame(['dateTime' => '2026-10-08T23:30:00', 'timeZone' => 'Europe/Madrid'], $b['start']);
        $this->assertSame('2026-10-09T00:30:00', $b['end']['dateTime']);   // +60 min, cruza de día
        $this->assertSame([['email' => 'a@b.com'], ['email' => 'c@d.com']], $b['attendees']);
        $this->assertSame(['RRULE:FREQ=WEEKLY'], $b['recurrence']);
        $this->assertStringContainsString('Tomar notas por mí', $b['description']);
        $this->assertSame(['private' => ['erp_meeting' => '5']], $b['extendedProperties']);
        $this->assertSame(10, $b['reminders']['overrides'][0]['minutes']);

        [$b] = GoogleCalendario::cuerpo(['titulo' => 'Vacaciones', 'fecha' => '2026-08-01', 'fecha_fin' => '2026-08-15', 'hora' => ''], false);
        $this->assertSame(['date' => '2026-08-01'], $b['start']);
        $this->assertSame(['date' => '2026-08-16'], $b['end']);

        /* PATCH: solo lo que viene; «predeterminado» vuelve a useDefault. */
        [$b, $hay] = GoogleCalendario::cuerpo(['recordar' => ''], true);
        $this->assertSame(['reminders' => ['useDefault' => true]], $b);
        $this->assertFalse($hay);

        foreach ([[['titulo' => '', 'fecha' => '2026-01-01'], 'titulo'], [['titulo' => 'x', 'fecha' => '2026-02-30'], 'fecha'],
                  [['titulo' => 'x', 'fecha' => '2026-02-01', 'hora' => '25:00'], 'hora'], [['titulo' => 'x', 'fecha' => '2026-02-01', 'recur' => 'YEARLY'], 'recur']] as [$o, $campo]) {
            try {
                GoogleCalendario::cuerpo($o, false);
                $this->fail("Se esperaba error en $campo");
            } catch (HttpError $e) {
                $this->assertSame($campo, $e->extra['campo'] ?? '');
            }
        }
    }

    public function testDuracionYFechas(): void
    {
        $o = CalendarioServicio::opciones(['titulo' => 'x', 'fecha' => '2026-10-08', 'hora' => '23:30', 'duracion' => 90, 'meet' => 1]);
        $this->assertSame('01:00', $o['hora_fin']);
        $this->assertSame('2026-10-09', $o['fecha_fin']);
        $this->assertTrue($o['meet']);
        $this->assertSame('2026-03-05', ReunionesServicio::fechaIso('5/3/2026'));
        $this->assertSame('2026-03-05', ReunionesServicio::fechaIso('2026-03-05'));
        $this->assertSame('', ReunionesServicio::fechaIso('31/02/2026'));
    }

    public function testPresenciaComoElAntiguo(): void
    {
        $this->assertSame(['estado' => 'offline', 'texto' => 'sin conexión'], Presencia::calcular(1000, 0, 0));
        $this->assertSame(['estado' => 'offline', 'texto' => 'últ. vez hace 2 min'], Presencia::calcular(1000, 1000 - 125, 0));
        $this->assertSame(['estado' => 'offline', 'texto' => 'últ. vez hace 3 h'], Presencia::calcular(20000, 20000 - 3 * 3600, 0));
        $this->assertSame(['estado' => 'idle', 'texto' => 'ausente'], Presencia::calcular(1000, 990, 600));
        $this->assertSame(['estado' => 'online', 'texto' => 'en línea'], Presencia::calcular(1000, 990, 900));
    }

    public function testTextoDelChat(): void
    {
        $this->assertSame(['laura', 'marcos.g'], ChatServicio::menciones('Hola @Laura y @marcos.g. Mi correo es a@b.com @x'));
        $this->assertSame("línea 1\nlínea 2", ChatServicio::normalizar("  línea 1\r\nlínea 2\x07 \n"));
        $this->assertSame('uno dos…', ChatServicio::extracto("uno   dos tres cuatro", 8));
        $this->assertSame([['fn' => 'a.png', 'orig' => 'foto.png', 'img' => true, 'mime' => '', 'size' => null]], ChatServicio::leerAdjuntos('[{"fn":"a.png","orig":"foto.png","img":1},{"fn":""}]'));
        $this->assertSame([], ChatServicio::leerAdjuntos('no es json'));
    }

    public function testTipoDeAdjuntoPorContenido(): void
    {
        $dir = sys_get_temp_dir() . '/croilab-adj-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $png = "$dir/a.bin";
        imagepng(imagecreatetruecolor(4, 4), $png);
        $this->assertSame('png', Adjuntos::extensionReal($png, 'foto.jpg'));   // manda el contenido
        file_put_contents("$dir/t.txt", "hola\n");
        $this->assertSame('txt', Adjuntos::extensionReal("$dir/t.txt", 't.txt'));
        $this->assertSame('csv', Adjuntos::extensionReal("$dir/t.txt", 'datos.csv'));
        file_put_contents("$dir/x.php", "<?php echo 1; ?>\n");
        $this->assertNull(Adjuntos::extensionReal("$dir/x.php", 'x.png'));
        file_put_contents("$dir/s.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->assertNull(Adjuntos::extensionReal("$dir/s.svg", 's.svg'));
        file_put_contents("$dir/h.html", '<!doctype html><html><body>x</body></html>');
        $this->assertNull(Adjuntos::extensionReal("$dir/h.html", 'h.txt'));

        $a = new Adjuntos($dir);
        $guardados = $a->guardar([['tmp' => $png, 'nombre' => '../../foto.png']]);
        $this->assertCount(1, $guardados);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}\.png$/', $guardados[0]['fn']);
        $this->assertSame('foto.png', $guardados[0]['orig']);
        $this->assertNotNull($a->ruta($guardados[0]['fn']));
        $this->assertNull($a->ruta('../a.bin'));
        $this->assertTrue(is_file("$dir/chat/.htaccess"));
        $this->assertSame(['image/png', true], Adjuntos::comoServir($guardados[0]['fn']));
        $this->assertSame(['application/octet-stream', false], Adjuntos::comoServir('x.txt'));
        try {
            $a->guardar([['tmp' => "$dir/x.php", 'nombre' => 'x.png']]);
            $this->fail('Un PHP no puede colarse como imagen');
        } catch (HttpError $e) {
            $this->assertSame(422, $e->status);
        }
        $a->borrar($guardados);
        $this->assertNull($a->ruta($guardados[0]['fn']));
        foreach (glob("$dir/chat/{,.}*", GLOB_BRACE) ?: [] as $f) if (is_file($f)) unlink($f);
        @rmdir("$dir/chat");
        foreach (glob("$dir/*") ?: [] as $f) unlink($f);
        rmdir($dir);
    }

    public function testTokenDelMcp(): void
    {
        $this->assertSame('abc', McpServidor::tokenDe('Bearer abc', ['k' => 'otro']));
        $this->assertSame('otro', McpServidor::tokenDe('', ['k' => 'otro']));
        $this->assertSame('t2', McpServidor::tokenDe('Basic xx', ['token' => 't2']));
        $this->assertSame('', McpServidor::tokenDe('', ['k' => ['array']]));
        $this->assertCount(8, McpServidor::herramientas());
    }
}
