<?php
namespace Croilab\Tests\Integracion\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Modulos\Finanzas\Cron;
use Croilab\Modulos\Finanzas\Modulo;
use Croilab\Modulos\Finanzas\ProgramacionesServicio;
use Croilab\Modulos\Finanzas\Tx;
use Croilab\Seguridad\Acceso;
use Croilab\Tests\Integracion\BaseDatosTestCase;
use PDO;

/* Finanzas de punta a punta contra la base: numeración legal (borradores sin
   número, emisión correlativa sin huecos, orden de fechas, series por emisor),
   facturas emitidas inmutables, rectificativas y anulación, cobros y caja,
   programaciones idempotentes y con bloqueo, documentos a la papelera, horas →
   gasto, emisores, negocio → factura, permisos y alcance.
   Personas: 1 dueña (todo) · 2 ana (emite, sin cobrar, solo lo suyo) · 3 lector (solo mira).
   Clientes: 1 Alfa (tarea de ana) · 2 Beta (nada de ana). */
class FinanzasTest extends BaseDatosTestCase
{
    private const DUENA = ['admin.total'];
    private const ANA = ['ver.finanzas', 'general.editar', 'finanzas.emitir', 'ver.horas', 'tareas.horas'];
    private const LECTOR = ['ver.finanzas', 'ver.conta', 'alcance.todos'];

    private static string $carpeta;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::preparar('nueva');
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO admins (id, username, password_hash, role, tarifa_hora) VALUES (1,'duena','x','owner',30), (2,'ana','x','editor',20), (3,'lector','x','viewer',0)");
        $pdo->exec("INSERT INTO clients (id, name, username, activo, fact_nombre, fact_nif) VALUES (1,'Alfa','alfa',1,'Alfa SL','B11111111'), (2,'Beta','beta',1,'','')");
        $pdo->exec("INSERT INTO task_lists (id, client_id, nombre) VALUES (1,1,'Tareas'), (2,2,'Tareas')");
        $pdo->exec("INSERT INTO tasks (id, client_id, list_id, titulo, responsable_id, estado) VALUES (1,1,1,'De ana',2,'pendiente'), (2,2,2,'De la dueña',1,'pendiente')");
        self::$carpeta = sys_get_temp_dir() . '/croilab-fin-' . bin2hex(random_bytes(4));
        $_SESSION = [];
        self::m()->emisores()->guardar(new Acceso($pdo, 1, self::DUENA), ['emisores' => [
            ['clave' => 'victor', 'nombre' => 'Víctor', 'prefijo' => 'V', 'fiscal' => ['name' => 'Víctor P.', 'nif' => '12345678Z', 'iban' => 'ES91 2100 0418 4502 0005 1332'], 'iva' => '21', 'irpf' => '15'],
            ['clave' => 'gavi', 'nombre' => 'Gabi', 'prefijo' => 'G', 'fiscal' => ['name' => 'Gabi R.', 'nif' => '87654321X'], 'iva' => '21', 'irpf' => '0'],
            ['clave' => '', 'nombre' => 'Sin NIF', 'prefijo' => 'S', 'fiscal' => [], 'iva' => '21', 'irpf' => '0'],
        ], 'por_defecto' => 'victor']);
    }

    private static function m(): Modulo
    {
        return new Modulo(self::pdo(), self::$carpeta);
    }

    private function acc(int $id, array $p): Acceso
    {
        return new Acceso(self::pdo(), $id, $p);
    }

    private function duena(): Acceso
    {
        return $this->acc(1, self::DUENA);
    }

    private function estado(callable $f): int
    {
        try {
            $f();
        } catch (HttpError $e) {
            return $e->status;
        }
        return 200;
    }

    private function campo(callable $f): string
    {
        try {
            $f();
        } catch (HttpError $e) {
            return (string)($e->extra['campo'] ?? '');
        }
        return '';
    }

    private function borrador(array $extra = [], ?Acceso $acc = null): array
    {
        return $this->m()->facturas()->crear($acc ?? $this->duena(), $extra + [
            'emisor' => 'victor', 'client_id' => 1, 'cliente' => ['nombre' => 'Alfa SL', 'nif' => 'B11111111'],
            'lineas' => [['concepto' => 'SEO', 'cantidad' => '1', 'precio' => '100']],
        ]);
    }

    private static function anio(): string
    {
        return date('Y');
    }

    /* ---------- Numeración ---------- */

    public function testLosBorradoresNoConsumenNumeroYAlEmitirSonCorrelativosSinHuecos(): void
    {
        $f = $this->m()->facturas();
        $a = $this->borrador();
        $b = $this->borrador();
        $c = $this->borrador();
        $this->assertNull($a['numero'], 'Un borrador no tiene número');
        $f->borrar($this->duena(), $b['id']);   // borrar un borrador no deja hueco
        $ea = $f->emitir($this->duena(), $a['id']);
        $ec = $f->emitir($this->duena(), $c['id']);
        $this->assertSame('V-' . self::anio() . '-001', $ea['numero']);
        $this->assertSame('V-' . self::anio() . '-002', $ec['numero']);
        $this->assertSame('enviada', $ec['estado']);
        $this->assertSame('12345678Z', $ec['emisor_snapshot']['nif'], 'Se congela el emisor al emitir');
        $this->assertSame(409, $this->estado(fn() => $f->emitir($this->duena(), $a['id'])), 'No se emite dos veces');
        $this->assertSame('V-' . self::anio() . '-003', $f->siguienteNumero($this->duena(), 'victor', '', date('Y-m-d'), 'normal'));
    }

    public function testSerieConAnioYDeUnSoloEmisorYFechasEnOrden(): void
    {
        $f = $this->m()->facturas();
        $x = $this->borrador(['serie' => 'f']);
        $this->assertSame('F' . self::anio() . '-001', $f->emitir($this->duena(), $x['id'])['numero'], 'La serie propia lleva el año');
        $g = $this->borrador(['emisor' => 'gavi', 'serie' => 'F']);
        $this->assertSame('serie', $this->campo(fn() => $f->emitir($this->duena(), $g['id'])), 'Una serie es de un solo emisor');
        $ayer = $this->borrador(['fecha' => date('Y-m-d', strtotime('-1 day'))]);
        $this->assertSame('fecha', $this->campo(fn() => $f->emitir($this->duena(), $ayer['id'])), 'No puede ir antes que la última de la serie');
        $futura = $this->borrador(['fecha' => date('Y-m-d', strtotime('+3 days'))]);
        $this->assertSame('fecha', $this->campo(fn() => $f->emitir($this->duena(), $futura['id'])));
        $sinNif = $this->borrador(['emisor' => 'sinnif']);
        $this->assertSame('emisor', $this->campo(fn() => $f->emitir($this->duena(), $sinNif['id'])), 'Sin NIF del emisor no se emite');
        $vacio = $this->borrador(['lineas' => []]);
        $this->assertSame('lineas', $this->campo(fn() => $f->emitir($this->duena(), $vacio['id'])));
    }

    public function testUnaEmisionQueFallaNoGastaNumero(): void
    {
        $m = $this->m();
        $pdo = self::pdo();
        $clave = 'V-' . self::anio() . '-';
        $antes = (int)$pdo->query("SELECT ultimo FROM invoice_counters WHERE serie = '$clave'")->fetchColumn();
        try {
            Tx::run($pdo, function () use ($m) {
                $m->numeracion()->asignar('victor', '', date('Y-m-d'));
                throw new \RuntimeException('fallo después de numerar');
            });
        } catch (\RuntimeException $e) {
        }
        $this->assertSame($antes, (int)$pdo->query("SELECT ultimo FROM invoice_counters WHERE serie = '$clave'")->fetchColumn(), 'El ROLLBACK devuelve el contador');
    }

    /* ---------- Importes ---------- */

    public function testImportesEnCentimosConRedondeoPorLinea(): void
    {
        $x = $this->borrador(['iva_pct' => '21', 'irpf_pct' => '15', 'lineas' => [
            ['concepto' => 'Horas', 'cantidad' => '2,5', 'precio' => '33,33'],   // 83,325 → 83,33
            ['concepto' => 'SEO', 'cantidad' => '1', 'precio' => '1.234,56'],
            ['concepto' => '', 'cantidad' => '9', 'precio' => '9'],              // sin concepto no cuenta
        ]]);
        $this->assertCount(2, $x['lineas']);
        $this->assertSame(8333, $x['lineas'][0]['importe']);
        $this->assertSame(['base' => 131789, 'iva' => 27676, 'irpf' => 19768, 'total' => 139697], $x['totales']);
        $efectivo = $this->borrador(['efectivo' => true, 'iva_pct' => '21']);
        $this->assertSame(['0', '0'], [$efectivo['iva_pct'], $efectivo['irpf_pct']], 'Efectivo fuerza IVA e IRPF a 0');
        $f = $this->m()->facturas();
        $this->assertSame('iva_pct', $this->campo(fn() => $this->borrador(['iva_pct' => '150'])));
        $this->assertSame('lineas.0.precio', $this->campo(fn() => $this->borrador(['lineas' => [['concepto' => 'X', 'cantidad' => '1', 'precio' => '-5']]])));
        $this->assertSame('lineas.0.cantidad', $this->campo(fn() => $this->borrador(['lineas' => [['concepto' => 'X', 'cantidad' => '0', 'precio' => '5']]])));
        $f->borrar($this->duena(), $x['id']);
        $f->borrar($this->duena(), $efectivo['id']);
    }

    /* ---------- Emitidas: inmutables, cobro, rectificar, anular ---------- */

    public function testUnaEmitidaNoSeEditaNiSeBorra(): void
    {
        $f = $this->m()->facturas();
        $e = $f->emitir($this->duena(), $this->borrador()['id']);
        $this->assertSame(409, $this->estado(fn() => $f->actualizar($this->duena(), $e['id'], ['notas' => 'x'])));
        $this->assertSame(409, $this->estado(fn() => $f->borrar($this->duena(), $e['id'])));
        $hoja = $this->m()->hoja()->paraEquipo($this->duena(), $e['id']);
        $this->assertTrue($hoja['integra'], 'La huella cuadra');
        self::pdo()->exec("UPDATE invoice_items SET precio = 1 WHERE invoice_id = {$e['id']}");
        $this->assertFalse($this->m()->hoja()->paraEquipo($this->duena(), $e['id'])['integra'], 'Tocar la base a mano se detecta');
        self::pdo()->exec("UPDATE invoice_items SET precio = 100 WHERE invoice_id = {$e['id']}");
        $this->assertStringStartsWith('%PDF-1.4', \Croilab\Modulos\Finanzas\PdfFactura::generar($hoja));
    }

    public function testCobrarApuntaEnCajaNotificaYDescobrarLoQuita(): void
    {
        $f = $this->m()->facturas();
        $pdo = self::pdo();
        $e = $f->emitir($this->duena(), $this->borrador(['lineas' => [['concepto' => 'Web', 'cantidad' => '1', 'precio' => '1000']]])['id']);
        $ana = $this->acc(2, self::ANA);
        $this->assertSame(403, $this->estado(fn() => $f->cambiarEstado($ana, $e['id'], ['estado' => 'pagada'])), 'Cobrar exige finanzas.cobrar');
        $p = $f->cambiarEstado($this->duena(), $e['id'], ['estado' => 'pagada', 'fecha_pago' => date('Y-m-d')]);
        $apunte = $pdo->query("SELECT * FROM accounting WHERE invoice_id = {$e['id']}")->fetch();
        $this->assertSame('1060.00', $apunte['importe'], '1000 + 21% − 15%');
        $this->assertSame('victor', $apunte['ambito']);
        $this->assertSame($p['apunte_id'], (int)$apunte['id']);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE ref LIKE 'invpaid:{$e['id']}:%'")->fetchColumn());
        $f->cambiarEstado($this->duena(), $e['id'], ['estado' => 'pagada', 'fecha_pago' => date('Y-m-d', strtotime('-2 days'))]);
        $this->assertSame((int)$apunte['id'], (int)$pdo->query("SELECT id FROM accounting WHERE invoice_id = {$e['id']}")->fetchColumn(), 'Se actualiza el mismo apunte');
        $this->assertSame('fecha_pago', $this->campo(fn() => $f->cambiarEstado($this->duena(), $e['id'], ['estado' => 'pagada', 'fecha_pago' => date('Y-m-d', strtotime('+2 days'))])));
        $f->cambiarEstado($this->duena(), $e['id'], ['estado' => 'enviada']);
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM accounting WHERE invoice_id = {$e['id']}")->fetchColumn());
        $this->assertSame(409, $this->estado(fn() => $f->cambiarEstado($this->duena(), $this->borrador()['id'], ['estado' => 'pagada'])), 'Un borrador no se cobra');
    }

    public function testAnularEmiteUnaRectificativaEnSerieRYLasDosQuedanAnuladas(): void
    {
        $f = $this->m()->facturas();
        $e = $f->emitir($this->duena(), $this->borrador()['id']);
        $this->assertSame('motivo', $this->campo(fn() => $f->anular($this->duena(), $e['id'], [])));
        $a = $f->anular($this->duena(), $e['id'], ['motivo' => 'Error en el cliente']);
        $this->assertSame('anulada', $a['estado']);
        $r = $a['rectificativas'][0];
        $this->assertSame('RV-' . self::anio() . '-001', $r['numero']);
        $this->assertSame('anulada', $r['estado']);
        $this->assertSame(-$e['totales']['total'], $r['total'], 'La rectificativa compensa el total');
        $this->assertSame(409, $this->estado(fn() => $f->anular($this->duena(), $e['id'], ['motivo' => 'otra vez'])));
        $hoja = $this->m()->hoja()->paraEquipo($this->duena(), $r['id']);
        $this->assertSame('FACTURA RECTIFICATIVA', $hoja['titulo']);
        $this->assertStringContainsString($e['numero'], $hoja['notas_legales'][0]);
    }

    public function testRectificarUnaCobradaCreaUnBorradorEnNegativoQueSeEmiteEnSerieR(): void
    {
        $f = $this->m()->facturas();
        $e = $f->emitir($this->duena(), $this->borrador()['id']);
        $f->cambiarEstado($this->duena(), $e['id'], ['estado' => 'pagada']);
        $this->assertSame(409, $this->estado(fn() => $f->anular($this->duena(), $e['id'], ['motivo' => 'x'])), 'Cobrada: se rectifica, no se anula');
        $r = $f->rectificar($this->duena(), $e['id'], ['motivo' => 'Descuento pactado']);
        $this->assertSame('rectificativa', $r['tipo']);
        $this->assertNull($r['numero']);
        $this->assertSame('-1.00', $r['lineas'][0]['cantidad']);
        $r = $f->actualizar($this->duena(), $r['id'], ['lineas' => [['concepto' => 'Descuento', 'cantidad' => '1', 'precio' => '-20']]]);
        $r = $f->emitir($this->duena(), $r['id']);
        $this->assertSame('RV-' . self::anio() . '-002', $r['numero']);
        $f->cambiarEstado($this->duena(), $r['id'], ['estado' => 'pagada']);
        $this->assertSame('-21.20', self::pdo()->query("SELECT importe FROM accounting WHERE invoice_id = {$r['id']}")->fetchColumn(), 'Devolución = ingreso negativo');
        $this->assertSame(409, $this->estado(fn() => $f->rectificar($this->duena(), $r['id'], ['motivo' => 'x'])));
    }

    public function testDuplicarDaUnBorradorSinNumeroConFechaDeHoy(): void
    {
        $f = $this->m()->facturas();
        $e = $f->emitir($this->duena(), $this->borrador()['id']);
        $d = $f->duplicar($this->duena(), $e['id']);
        $this->assertSame(['borrador', null, date('Y-m-d')], [$d['estado'], $d['numero'], $d['fecha']]);
        $this->assertCount(1, $d['lineas']);
    }

    /* ---------- Permisos y alcance ---------- */

    public function testPermisosYAlcance(): void
    {
        $f = $this->m()->facturas();
        $ana = $this->acc(2, self::ANA);
        $lector = $this->acc(3, self::LECTOR);
        $beta = $this->borrador(['client_id' => 2, 'cliente' => ['nombre' => 'Beta']]);
        $this->assertSame(404, $this->estado(fn() => $f->detalle($ana, $beta['id'])), 'Fuera de su alcance es 404');
        $this->assertSame('client_id', $this->campo(fn() => $this->borrador(['client_id' => 2], $ana)));
        $ids = array_column($f->listar($ana, [])['items'], 'client_id');
        $this->assertNotContains(2, $ids);
        $this->assertContains(1, $ids);
        $sinCliente = $this->borrador(['client_id' => null, 'cliente' => ['nombre' => 'Particular']]);
        $this->assertSame(200, $this->estado(fn() => $f->detalle($ana, $sinCliente['id'])), 'Lo que no es de ningún cliente es de la empresa');
        $this->assertSame(403, $this->estado(fn() => $this->borrador([], $lector)), 'Solo mirar');
        $this->assertSame(403, $this->estado(fn() => $f->borrar($ana, $sinCliente['id'])), 'Borrar exige finanzas.borrar');
        $this->assertSame(403, $this->estado(fn() => $this->m()->contabilidad()->movimientos($ana, 'empresa', (int)self::anio())), 'Contabilidad exige ver.conta');
        $this->assertSame(200, $this->estado(fn() => $this->m()->contabilidad()->movimientos($lector, 'empresa', (int)self::anio())));
    }

    /* ---------- Programaciones ---------- */

    public function testProgramacionesRecuperanMesesSonIdempotentesYNoCorrenDosALaVez(): void
    {
        $p = $this->m()->programaciones();
        $pdo = self::pdo();
        $hace2 = date('Y-m', strtotime(date('Y-m-01') . ' -2 month'));
        $s = $p->crear($this->duena(), ['emisor' => 'gavi', 'client_id' => 1, 'cliente' => ['nombre' => 'Alfa SL'], 'dia' => 1, 'start_ym' => $hace2,
                                        'lineas' => [['concepto' => 'Cuota', 'cantidad' => '1', 'precio' => '300']], 'venc_dias' => 15]);
        $this->assertSame(36300, $s['total_mes']);

        /* Otra conexión tiene el bloqueo: no se genera nada. */
        $otra = new PDO('mysql:host=' . getenv('TEST_DB_HOST') . ';dbname=' . getenv('TEST_DB_NAME') . ';charset=utf8mb4', (string)getenv('TEST_DB_USER'), (string)getenv('TEST_DB_PASS'));
        $otra->query("SELECT GET_LOCK('" . ProgramacionesServicio::BLOQUEO . "', 0)")->fetchAll();
        $r = $p->ejecutar(1);
        $this->assertTrue($r['ocupado']);
        $this->assertSame(0, $r['generadas']);
        $otra->query("SELECT RELEASE_LOCK('" . ProgramacionesServicio::BLOQUEO . "')")->fetchAll();

        $r = $p->ejecutar(1);
        $this->assertSame(3, $r['generadas'], 'Dos meses atrasados y el actual (día 1)');
        $this->assertSame(['G-' . self::anio() . '-001', 'G-' . self::anio() . '-002', 'G-' . self::anio() . '-003'], array_column($r['facturas'], 'numero'));
        $fac = $pdo->query("SELECT fecha, periodo_ini, fecha_venc, estado FROM invoices WHERE schedule_id = {$s['id']} ORDER BY schedule_ym")->fetchAll();
        $this->assertSame(date('Y-m-d'), $fac[0]['fecha'], 'Un mes atrasado se emite hoy, no con fecha pasada');
        $this->assertSame($hace2 . '-01', $fac[0]['periodo_ini'], '…con el período del mes que cubre');
        $this->assertSame('enviada', $fac[0]['estado']);
        $this->assertNotNull($fac[0]['fecha_venc']);

        $this->assertSame(0, $p->ejecutar(1)['generadas'], 'Repetir no duplica');
        /* Aunque last_ym se quede atrás (fallo a medias), el índice único y la comprobación evitan el duplicado. */
        $pdo->exec("UPDATE invoice_schedules SET last_ym = '' WHERE id = {$s['id']}");
        $this->assertSame(0, $p->ejecutar(1)['generadas']);
        $this->assertSame(3, (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE schedule_id = {$s['id']}")->fetchColumn());
        $this->assertSame(403, $this->estado(fn() => $p->generar($this->acc(2, self::ANA))), 'Generar exige finanzas.programar');
        $p->activar($this->duena(), $s['id'], false);
        $this->assertNull($p->detalle($this->duena(), $s['id'])['proxima']);

        $cron = Cron::ejecutar($pdo);
        $this->assertSame(['invoice_recurring', 'invoice_due'], array_column($cron, 'tarea'));
        $this->assertTrue($cron[0]['ok']);
    }

    /* ---------- Documentos ---------- */

    public function testDocumentoSubidoCreaSuApunteYVaALaPapeleraConEl(): void
    {
        $d = $this->m()->documentos();
        $pdo = self::pdo();
        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n");
        $doc = $d->guardar($this->duena(), null, ['emisor' => 'victor', 'tipo' => 'gasto', 'concepto' => 'Hosting', 'proveedor' => 'Proveedor SA',
                                                  'importe' => '1.210,50', 'fecha' => date('Y-m-d'), 'personal' => '1'],
                           ['name' => 'factura.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], false);
        $this->assertSame(121050, $doc['importe']);
        $this->assertSame('pdf', $doc['tipo_archivo']);
        $this->assertTrue($doc['deducible'], 'Gasto personal = deducible del socio');
        $this->assertFileExists(self::$carpeta . '/' . $doc['archivo']);
        $ap = $pdo->query("SELECT * FROM accounting WHERE upload_id = {$doc['id']}")->fetch();
        $this->assertSame(['gasto', '1210.50', 'Hosting · Proveedor SA'], [$ap['tipo'], $ap['importe'], $ap['concepto']]);

        $txt = tempnam(sys_get_temp_dir(), 'txt');
        file_put_contents($txt, 'no soy un pdf');
        $this->assertSame('archivo', $this->campo(fn() => $d->guardar($this->duena(), null, ['emisor' => 'victor', 'tipo' => 'gasto', 'importe' => '5'],
                                                                         ['name' => 'x.pdf', 'tmp_name' => $txt, 'error' => UPLOAD_ERR_OK], false)), 'Se mira el tipo real');
        $this->assertSame(403, $this->estado(fn() => $d->guardar($this->acc(2, self::ANA), null, ['emisor' => 'victor', 'tipo' => 'gasto', 'importe' => '5'], null, false)), 'Exige conta.editar');

        $tid = $d->borrar($this->duena(), $doc['id']);
        $this->assertSame(0, (int)$pdo->query("SELECT COUNT(*) FROM accounting WHERE upload_id = {$doc['id']}")->fetchColumn());
        $this->assertTrue(pap_restaurar($tid)['ok']);
        $this->assertSame(1, (int)$pdo->query("SELECT COUNT(*) FROM accounting WHERE upload_id = {$doc['id']}")->fetchColumn(), 'Vuelve con su apunte');
    }

    /* ---------- Horas ---------- */

    public function testHorasSeVuelcanUnaSolaVezYLoVolcadoNoSeBorra(): void
    {
        $h = $this->m()->horas();
        $pdo = self::pdo();
        $mes = date('Y-m');
        $pdo->exec("INSERT INTO time_entries (admin_id, task_id, client_id, fecha, minutos, concepto) VALUES (2, 1, NULL, '" . date('Y-m-d') . "', 90, 'Horas de la tarea')");
        $ana = $this->acc(2, self::ANA);
        $h->anadirExtra($ana, ['concepto' => 'Desplazamiento', 'tipo' => 'importe', 'valor' => '12,50']);
        $pepe = $this->acc(3, ['ver.horas', 'tareas.horas', 'general.editar']);   // imputa, pero no gestiona
        $this->assertSame(403, $this->estado(fn() => $h->ver($pepe, 2, $mes)), 'Sin ser gestor solo se ve a sí mismo');
        $this->assertSame(200, $this->estado(fn() => $h->ver($pepe, null, $mes)));
        $v = $h->ver($this->duena(), 2, $mes);
        $this->assertSame(90, $v['calculo']['minutos']);
        $this->assertSame(3000 + 1250, $v['calculo']['base'], '1,5 h × 20 € + 12,50 €');
        $this->assertSame(403, $this->estado(fn() => $h->volcar($pepe, 2, $mes)), 'Volcar es del gestor');
        $this->assertSame(403, $this->estado(fn() => $h->volcar($ana, 2, $mes)), '…y exige conta.editar');
        $r = $h->volcar($this->duena(), 2, $mes);
        $this->assertSame(4250, $r['importe']);
        $this->assertSame('422', (string)$this->estado(fn() => $h->volcar($this->duena(), 2, $mes)), 'No se vuelca dos veces');
        $extra = $pdo->query("SELECT id FROM time_entries WHERE admin_id = 2 AND task_id IS NULL")->fetchColumn();
        $this->assertSame(409, $this->estado(fn() => $h->borrar($ana, (int)$extra)));
        $g = $pdo->query("SELECT categoria, ambito, admin_id, client_id FROM accounting WHERE id = {$r['id']}")->fetch();
        $this->assertSame(['Equipo', 'empresa', 2, 1], [$g['categoria'], $g['ambito'], (int)$g['admin_id'], (int)$g['client_id']]);
    }

    /* ---------- Emisores ---------- */

    public function testEmisoresConHistoricoNoSeBorranYLosPrefijosNoSeRepiten(): void
    {
        $e = $this->m()->emisores();
        $this->assertSame(409, $this->estado(fn() => $e->borrar($this->duena(), 'victor')));
        $base = fn(array $extra) => ['emisores' => [$extra + ['clave' => 'victor', 'nombre' => 'Víctor', 'prefijo' => 'V', 'fiscal' => ['nif' => '12345678Z']],
                                                    ['clave' => 'gavi', 'nombre' => 'Gabi', 'prefijo' => 'G', 'fiscal' => ['nif' => '87654321X']],
                                                    ['clave' => 'sinnif', 'nombre' => 'Sin NIF', 'prefijo' => 'S']]];
        $this->assertSame('emisores.1.prefijo', $this->campo(fn() => $e->guardar($this->duena(), $base(['prefijo' => 'G']))));
        $this->assertSame('emisores.0.iban', $this->campo(fn() => $e->guardar($this->duena(), $base(['fiscal' => ['iban' => 'ES00 1234']]))));
        $this->assertSame(403, $this->estado(fn() => $e->ajustes($this->acc(2, self::ANA))));
        self::pdo()->exec("DELETE FROM invoices WHERE emisor = 'sinnif'");   // su único borrador
        $r = $e->borrar($this->duena(), 'sinnif');
        $this->assertSame(['victor', 'gavi'], array_column($r['items'], 'clave'));
    }

    /* ---------- Negocio → factura ---------- */

    public function testNegocioPrellenaElBorradorYNoSeFacturaDosVeces(): void
    {
        $pdo = self::pdo();
        $pdo->exec("INSERT INTO contacts (id, nombre, empresa, email) VALUES (50, 'Laura', 'Gamma SL', 'laura@gamma.test')");
        $pdo->exec("INSERT INTO billing_data (contact_id, razon_social, cif, direccion, cp, ciudad) VALUES (50, 'Gamma Servicios SL', 'B22222222', 'C/ Uno 1', '28001', 'Madrid')");
        $pdo->exec("INSERT INTO deals (id, contact_id, nombre, valor, servicio, fase) VALUES (70, 50, 'Web nueva', 2500.00, 'Diseño web', 'propuesta')");
        $f = $this->m()->facturas();
        $n = $f->desdeNegocio($this->duena(), 70);
        $this->assertNull($n['ya']);
        $b = $n['borrador'];
        $this->assertSame(['Gamma Servicios SL', 'B22222222', 'C/ Uno 1, 28001 Madrid'], [$b['cliente']['nombre'], $b['cliente']['nif'], $b['cliente']['dir']]);
        $this->assertSame([['concepto' => 'Web nueva · Diseño web', 'cantidad' => '1.00', 'precio' => '2500.00']], $b['lineas']);
        $creada = $f->crear($this->duena(), $b);
        $this->assertSame(70, $creada['deal_id']);
        $this->assertSame($creada['id'], $f->desdeNegocio($this->duena(), 70)['ya']['id']);
        $this->assertSame(409, $this->estado(fn() => $f->crear($this->duena(), $b)));
    }

    /* ---------- Vistas agregadas ---------- */

    public function testResumenYHubsNoCuentanBorradores(): void
    {
        $m = $this->m();
        $antes = $m->resumen()->mensual($this->duena(), 'victor', date('Y-m'))['actual'];
        $this->borrador(['lineas' => [['concepto' => 'Borrador gordo', 'cantidad' => '1', 'precio' => '99999']]]);
        $despues = $m->resumen()->mensual($this->duena(), 'victor', date('Y-m'))['actual'];
        $this->assertSame($antes['facturado'], $despues['facturado']);
        $this->assertSame($antes['n'], $despues['n']);
        $hub = array_column($m->explorador()->hubs($this->duena())['items'], null, 'clave');
        $this->assertGreaterThan(0, $hub['victor']['n']);
        $meses = $m->explorador()->meses($this->duena(), 'victor', 'ingreso')['items'];
        $this->assertSame(date('Y-m'), $meses[0]['mes']);
        $an = $m->contabilidad()->analisis($this->duena(), 'empresa', (int)self::anio());
        $this->assertCount(12, $an['por_mes']);
        $this->assertCount(4, $an['trimestres']);
    }
}
