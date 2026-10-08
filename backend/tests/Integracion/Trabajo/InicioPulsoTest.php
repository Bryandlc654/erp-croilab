<?php
namespace Croilab\Tests\Integracion\Trabajo;

use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Modulos\Trabajo\InicioServicio;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;

/* El «pulso» del inicio: tickets, CRM de hoy, cobros y chat, cada cifra con el
   mismo alcance que su pantalla y null sin permiso del módulo.
   Personas: 1 duena (todo) · 2 ana (solo el cliente Alfa) · 3 luis (sin esos módulos). */
class InicioPulsoTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const ANA = ['ver.tareas', 'ver.clientes', 'ver.soporte', 'ver.crm', 'ver.finanzas', 'ver.chat'];
    private const LUIS = ['ver.tareas', 'ver.clientes', 'alcance.todos'];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        $pdo = self::pdo();
        $hoy = date('Y-m-d');
        $ayer = date('Y-m-d', strtotime('-1 day'));
        $manana = date('Y-m-d', strtotime('+1 day'));
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role) VALUES (1,'duena','x','owner'), (2,'ana','x','editor'), (3,'luis','x','viewer')");
        $pdo->exec("INSERT INTO clients (id, name, username, activo) VALUES (1,'Alfa','alfa',1), (2,'Beta','beta',1)");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (1,1,'Tareas')");
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id, estado) VALUES (1,1,1,'De Alfa',2,'pendiente')");

        // Tickets: 2 abiertos de Alfa (uno de ana, otro sin asignar), 1 de Beta, 1 cerrado.
        $pdo->exec("INSERT INTO support_tickets (id, asunto, client_id, estado, assignee_id) VALUES
                    (1,'Web caída',1,'abierto',2), (2,'Dudas',1,'en_curso',NULL), (3,'Beta',2,'abierto',NULL), (4,'Viejo',1,'cerrado',NULL)");

        // CRM: seguimientos de hoy y de ayer de ana, uno de mañana y otro de un contacto ajeno.
        $pdo->exec("INSERT INTO contacts (id, nombre, propietario_id) VALUES (1,'Rosa',2), (2,'Pedro',1)");
        $pdo->exec("INSERT INTO follow_up_tasks (contact_id, canal, descripcion, fecha_prevista, estado) VALUES
                    (1,'llamar','Hoy','$hoy','pendiente'), (1,'email','Ayer','$ayer','pendiente'),
                    (1,'llamar','Mañana','$manana','pendiente'), (1,'llamar','Hecho','$ayer','hecho'),
                    (2,'llamar','Ajeno','$hoy','pendiente')");

        // Facturas: F-1 de Alfa enviada y vencida por fecha (sin pasar el cron),
        // F-2 de Beta vencida, un borrador sin número y una anulada que no cuentan.
        $pdo->exec("INSERT INTO invoices (id, numero, client_id, cliente_nombre, fecha, fecha_venc, estado, iva_pct, irpf_pct) VALUES
                    (1,'F-1',1,'Alfa','$ayer','$ayer','enviada',21,0), (2,'F-2',2,'Beta','$ayer','$manana','vencida',21,0),
                    (3,NULL,1,'Alfa','$hoy',NULL,'borrador',21,0), (4,'F-4',1,'Alfa','$hoy',NULL,'anulada',21,0)");
        $pdo->exec("INSERT INTO invoice_items (invoice_id, concepto, cantidad, precio) VALUES (1,'SEO',1,100), (2,'Web',2,50), (3,'X',1,999), (4,'Y',1,999)");

        // Chat: ana tiene 2 mensajes sin leer de otros y uno suyo (que no cuenta).
        $pdo->exec("INSERT INTO chat_rooms (id, name) VALUES (1,'General')");
        $pdo->exec('INSERT INTO chat_members (room_id, admin_id, last_read) VALUES (1,1,0), (1,2,0)');
        $pdo->exec("INSERT INTO chat_messages (room_id, admin_id, body) VALUES (1,1,'Hola'), (1,1,'¿Vienes?'), (1,2,'Voy')");
    }

    private function pulso(int $id, array $permisos): array
    {
        return (new InicioServicio(self::pdo(), new EquipoRepositorio(self::pdo())))->resumen(new Acceso(self::pdo(), $id, $permisos))['pulso'];
    }

    public function testLaDuenaLoVeTodo(): void
    {
        $p = $this->pulso(1, self::DUENA);
        $this->assertSame(['abiertos' => 3, 'sin_asignar' => 2, 'mios' => 0], $p['tickets']);
        // Ve todos los contactos: Rosa (hoy y ayer) + Pedro (hoy); el de mañana y el hecho no cuentan.
        $this->assertSame(['total' => 3, 'atrasados' => 1], $p['crm_hoy']);
        // F-1 121,00 € + F-2 121,00 €, las dos vencidas; el borrador y la anulada no cuentan.
        $this->assertSame(['n' => 2, 'total' => 24200], $p['cobros']['pendiente']);
        $this->assertSame(['n' => 2, 'total' => 24200], $p['cobros']['vencidas']);
        $this->assertSame(1, $p['chat_no_leidos'], 'Duena: solo el mensaje de ana');
    }

    public function testAnaSoloLoDeSuAlcanceYSinImportes(): void
    {
        $p = $this->pulso(2, self::ANA);
        $this->assertSame(['abiertos' => 2, 'sin_asignar' => 1, 'mios' => 1], $p['tickets'], 'El ticket de Beta no es de su alcance');
        $this->assertSame(['total' => 2, 'atrasados' => 1], $p['crm_hoy'], 'El seguimiento de Pedro es de otro propietario');
        $this->assertSame(['n' => 1, 'total' => null], $p['cobros']['pendiente'], 'Sin ver.importes cuenta facturas pero no enseña euros');
        $this->assertSame(['n' => 1, 'total' => null], $p['cobros']['vencidas']);
        $this->assertSame(2, $p['chat_no_leidos']);
    }

    public function testSinPermisoDelModuloNoHayCifra(): void
    {
        $this->assertSame(['tickets' => null, 'crm_hoy' => null, 'cobros' => null, 'chat_no_leidos' => null], $this->pulso(3, self::LUIS));
    }
}
