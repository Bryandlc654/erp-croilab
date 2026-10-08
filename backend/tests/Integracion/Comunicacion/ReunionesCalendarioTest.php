<?php
namespace Croilab\Tests\Integracion\Comunicacion;

use Croilab\Google\Cuenta;
use Croilab\Google\GoogleOAuth;
use Croilab\Http\HttpError;
use Croilab\Modulos\Comunicacion\Calendario\CalendarioServicio;
use Croilab\Modulos\Comunicacion\Calendario\GoogleCalendario;
use Croilab\Modulos\Comunicacion\Calendario\GoogleFalso;
use Croilab\Modulos\Comunicacion\Reuniones\ReunionesServicio;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* Calendario y reuniones contra un Google de mentira (GoogleFalso): tareas con
   alcance, eventos propios y de compañeros, agendar (CRM + Google enlazados),
   solicitudes del portal, emparejado con contactos y asignación manual.
   Personas: 1 duena (todo, Google conectado), 2 ana (Google conectado),
   3 limi (sin alcance.todos ni Google). */
class ReunionesCalendarioTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const LIMI = ['ver.agenda', 'ver.tareas', 'general.editar', 'ver.crm'];
    private static GoogleFalso $google;
    private static string $hoy;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        putenv('APP_URL=https://api.ejemplo.com');
        roles_todos(true);
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role, email) VALUES (1,'duena','x','owner','duena@ejemplo.com'),(2,'ana','x','editor',NULL),(3,'limi','x','editor',NULL)");
        $pdo->exec("INSERT INTO clients (id, name, activo, fact_email, contact_id) VALUES (1, 'Alfa', 1, 'facturas@alfa.test', 1), (2, 'Beta', 1, 'beta@beta.test', NULL)");
        $pdo->exec("INSERT INTO contacts (id, nombre, empresa, email, fase, propietario_id, client_id) VALUES (1, 'Ana Alfa', 'Alfa', 'ana@alfa.test', 'lead_nuevo', 1, 1), (2, 'Otro', '', 'otro@x.test', 'lead_nuevo', 3, NULL)");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (1, 1, 'T'), (2, 2, 'T')");
        self::$hoy = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Madrid')))->format('Y-m-d');
        $h = self::$hoy;
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id, due_date) VALUES (1, 1, 1, 'De duena', 1, '$h'), (2, 2, 2, 'De limi', 3, '$h')");

        self::$google = new GoogleFalso();
        $g = new GoogleOAuth(self::$google);
        $g->guardarCredenciales('calendar', 'id.apps.googleusercontent.com', 'secreto');
        foreach ([1 => 'duena@ejemplo.com', 2 => 'ana@ejemplo.com'] as $id => $email) {
            $_SESSION = [];
            parse_str((string)parse_url($g->urlAutorizacion(Cuenta::calendario($id)), PHP_URL_QUERY), $q);
            $r = $g->procesarVuelta(['state' => $q['state'], 'code' => $email]);
            if (!$r['ok']) throw new \RuntimeException($r['msg']);
        }
        self::$google->sembrar('ana@ejemplo.com', ['summary' => 'Formación', 'start' => ['dateTime' => "{$h}T09:00:00+02:00"], 'end' => ['dateTime' => "{$h}T10:00:00+02:00"]]);
        self::$google->sembrar('duena@ejemplo.com', ['summary' => 'Con Alfa', 'start' => ['dateTime' => "{$h}T23:00:00+02:00"], 'end' => ['dateTime' => "{$h}T23:30:00+02:00"],
                                                    'attendees' => [['email' => 'facturas@alfa.test']]]);
    }

    public static function tearDownAfterClass(): void
    {
        putenv('APP_URL');
    }

    private function acc(int $id, array $p): Acceso
    {
        return new Acceso(self::pdo(), $id, $p);
    }

    private function gcal(): GoogleCalendario
    {
        return new GoogleCalendario(new GoogleOAuth(self::$google));
    }

    private function cal(): CalendarioServicio
    {
        return new CalendarioServicio(self::pdo(), $this->gcal(), new EquipoRepositorio(self::pdo()));
    }

    private function reu(): ReunionesServicio
    {
        return new ReunionesServicio(self::pdo(), $this->gcal(), new EquipoRepositorio(self::pdo()));
    }

    public function testCalendarioConAlcanceYCompaneros(): void
    {
        $h = self::$hoy;
        $d = $this->cal()->datos($this->acc(1, self::DUENA), $h, $h, [2]);
        $this->assertSame([1, 2], array_column($d['tareas'], 'id'));
        $this->assertTrue($d['google']['conectado']);
        $this->assertSame([['id' => 2, 'username' => 'ana', 'color' => CalendarioServicio::PALETA[0]]], $d['companeros']);
        $titulos = array_column($d['eventos'], 'titulo');
        sort($titulos);
        $this->assertSame(['Con Alfa', 'Formación'], $titulos);
        $deAna = array_values(array_filter($d['eventos'], fn($e) => $e['titulo'] === 'Formación'))[0];
        $this->assertFalse($deAna['editable']);
        $this->assertSame('ana', $deAna['owner']['username']);

        /* limi no tiene alcance total: solo ve su tarea. Y sin Google, ningún evento. */
        $d = $this->cal()->datos($this->acc(3, self::LIMI), $h, $h, []);
        $this->assertSame([2], array_column($d['tareas'], 'id'));
        $this->assertFalse($d['google']['conectado']);
        $this->assertSame([], $d['eventos']);
        try {
            $this->cal()->crear($this->acc(3, self::LIMI), ['titulo' => 'x', 'fecha' => $h]);
            $this->fail('Sin Google no se crean eventos');
        } catch (HttpError $e) {
            $this->assertSame('google_sin_conectar', $e->codigo);
        }
    }

    public function testEventosCrearEditarMoverBorrar(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $c = $this->cal();
        $r = $c->crear($duena, ['titulo' => 'Comida', 'fecha' => '2030-01-10', 'hora' => '14:00', 'duracion' => 90, 'invitados' => 'x@y.test', 'meet' => true]);
        $this->assertSame('Reunión creada con Google Meet.', $r['msg']);
        $id = $r['evento']['id'];
        $this->assertSame(['14:00', '15:30', true], [$r['evento']['hora'], $r['evento']['hora_fin'], $r['evento']['meet']]);
        $post = self::$google->peticiones[array_key_last(self::$google->peticiones)];
        $this->assertStringContainsString('sendUpdates=all', $post[1]);
        $this->assertStringContainsString('conferenceDataVersion=1', $post[1]);

        $e = $c->actualizar($duena, ['id' => $id, 'titulo' => 'Comida de equipo', 'notificar' => false]);
        $this->assertSame('Comida de equipo', $e['evento']['titulo']);
        $m = $c->actualizar($duena, ['id' => $id, 'solo_fechas' => true, 'fecha' => '2030-01-11', 'hora' => '09:15', 'hora_fin' => '10:00']);
        $this->assertSame(['2030-01-11', '09:15', 'Evento movido.'], [$m['evento']['dia'], $m['evento']['hora'], $m['msg']]);
        $this->assertSame('Evento eliminado.', $c->borrar($duena, $id));
        try {
            $c->borrar($duena, $id);
            $this->fail('Borrar dos veces');
        } catch (HttpError $e) {
            $this->assertSame(404, $e->status);
        }
        /* Toda la serie desde una ocurrencia: se desplaza el inicio de la serie, no se reinicia. */
        $serie = self::$google->sembrar('duena@ejemplo.com', ['summary' => 'Semanal', 'start' => ['dateTime' => '2030-01-07T10:00:00+01:00'], 'end' => ['dateTime' => '2030-01-07T11:00:00+01:00'], 'recurrence' => ['RRULE:FREQ=WEEKLY']]);
        $s = $c->actualizar($duena, ['id' => $serie['id'], 'solo_fechas' => true, 'fecha' => '2030-01-15', 'hora' => '12:00', 'hora_fin' => '13:30',
                                     'origen' => ['fecha' => '2030-01-14', 'hora' => '10:00', 'hora_fin' => '11:00']]);
        $this->assertSame(['2030-01-08', '12:00', '13:30'], [$s['evento']['dia'], $s['evento']['hora'], $s['evento']['hora_fin']]);
        $this->assertSame([1440 + 120, 30], CalendarioServicio::desplazamiento(['fecha' => '2030-01-14', 'hora' => '10:00', 'hora_fin' => '11:00'], ['fecha' => '2030-01-15', 'hora' => '12:00', 'hora_fin' => '13:30']));
        try {
            $c->actualizar($duena, ['id' => '../../x', 'titulo' => 'x']);
            $this->fail('Id no válido');
        } catch (HttpError $e) {
            $this->assertSame(422, $e->status);
        }
    }

    public function testAgendarSolicitudesYEmparejado(): void
    {
        $duena = $this->acc(1, self::DUENA);
        $limi = $this->acc(3, self::LIMI);
        $reu = $this->reu();

        /* Agendar con contacto: reunión en el CRM con título + actividad + evento enlazado. */
        $en3 = (new \DateTimeImmutable(self::$hoy))->modify('+3 days');
        $r = $reu->agendar($duena, ['titulo' => 'Revisión', 'fecha' => $en3->format('d/m/Y'), 'hora' => '10:00', 'duracion' => 30, 'contact_id' => 1, 'invitados' => 'ana@alfa.test']);
        $this->assertSame('Reunión creada con Google Meet.', $r['msg']);
        $this->assertSame((string)$r['mid'], $r['evento']['erp_meeting']);
        $fila = self::pdo()->query('SELECT contact_id, fecha, hora, titulo, estado FROM crm_meetings WHERE id = ' . $r['mid'])->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(['contact_id' => 1, 'fecha' => $en3->format('Y-m-d'), 'hora' => '10:00', 'titulo' => 'Revisión', 'estado' => 'agendada'], $fila);
        $this->assertSame(1, (int)self::pdo()->query("SELECT COUNT(*) FROM activities WHERE contact_id = 1 AND tipo = 'reunion'")->fetchColumn());
        $this->assertSame($r['evento']['id'], $this->gcal()->deReunionErp(1, $r['mid'])['id']);

        /* limi: el contacto 1 no es suyo; y sin Google ni contacto no se guardaría nada. */
        try { $reu->agendar($limi, ['titulo' => 'x', 'fecha' => '2030-01-01', 'contact_id' => 1]); $this->fail(); } catch (HttpError $e) { $this->assertSame('contact_id', $e->extra['campo'] ?? ''); }
        try { $reu->agendar($limi, ['titulo' => 'x', 'fecha' => '2030-01-01']); $this->fail(); } catch (HttpError $e) { $this->assertSame(409, $e->status); }
        $sinGoogle = $reu->agendar($limi, ['titulo' => 'Con Otro', 'fecha' => '2030-01-01', 'contact_id' => 2]);
        $this->assertStringContainsString('Conecta Google Calendar', $sinGoogle['msg']);
        try { $reu->agendar($duena, ['titulo' => '', 'fecha' => '2030-01-01']); $this->fail(); } catch (HttpError $e) { $this->assertSame('titulo', $e->extra['campo'] ?? ''); }

        /* Solicitudes del portal: aprobar con evento invita al cliente; rechazar. */
        self::pdo()->exec("INSERT INTO portal_meeting_requests (id, client_id, fecha_deseada, franja, motivo, estado) VALUES (1, 1, '2030-02-01', 'Tarde', 'Resultados', 'pendiente'), (2, 2, NULL, '', 'Navidad', 'pendiente')");
        $this->assertSame([2], array_column($reu->solicitudes($limi), 'id'), 'limi solo ve la de su cliente (Beta)');
        $a = $reu->aprobar($duena, 1, ['evento' => ['titulo' => 'Resultados', 'fecha' => '2030-02-01', 'hora' => '17:00']]);
        $this->assertSame('Reunión agendada y avisado el cliente.', $a['msg']);
        $this->assertSame(['facturas@alfa.test'], $a['evento']['invitados']);
        $this->assertSame(['aprobada', $a['mid']], array_map(fn($v) => is_numeric($v) ? (int)$v : $v, array_values(self::pdo()->query('SELECT estado, meeting_id FROM portal_meeting_requests WHERE id = 1')->fetch(\PDO::FETCH_ASSOC))));
        $reu->rechazar($duena, 2);
        try { $reu->rechazar($duena, 2); $this->fail(); } catch (HttpError $e) { $this->assertSame(404, $e->status); }

        /* Reuniones: emparejado por correo (contacto > cliente) y asignación manual. */
        $l = $reu->listar($duena, 'me');
        $porTitulo = array_column(array_merge($l['proximas'], $l['pasadas']), null, 'titulo');
        $this->assertSame('Ana Alfa', $porTitulo['Revisión']['contacto']['nombre']);
        $this->assertSame('contacto', $porTitulo['Revisión']['emparejado']);
        $this->assertSame(['id' => 1, 'nombre' => 'Alfa'], $porTitulo['Con Alfa']['cliente']);
        $reu->asignar($duena, $porTitulo['Con Alfa']['id'], 2);
        $l = $reu->listar($duena, 'me');
        $this->assertSame('manual', array_column($l['proximas'], null, 'titulo')['Con Alfa']['emparejado']);
        try { $reu->asignar($limi, $porTitulo['Con Alfa']['id'], 1); $this->fail(); } catch (HttpError $e) { $this->assertSame(422, $e->status); }
        $this->assertSame([['id' => 1, 'username' => 'duena'], ['id' => 2, 'username' => 'ana']], $l['cuentas']);
        $equipo = $reu->listar($duena, 'all');
        $this->assertSame('all', $equipo['vista']);

        $dest = $reu->destinatario($duena, 1, 0);
        $this->assertSame(['nombre' => 'Alfa', 'email' => 'ana@alfa.test', 'contact_id' => 1, 'client_id' => 1], array_intersect_key($dest, array_flip(['nombre', 'email', 'contact_id', 'client_id'])));
        $grupos = $reu->contactos($limi, '');
        $this->assertSame(['Otro'], array_column($grupos[0]['items'], 'nombre'), 'Solo los contactos que ve');
    }
}
