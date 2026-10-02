<?php
namespace Croilab\Tests\Unit;

use Croilab\Correo\Mensaje;
use Croilab\Correo\Smtp;
use PHPUnit\Framework\TestCase;

class CorreoTest extends TestCase
{
    public function testMensajeMultiparteConCabecerasCodificadas(): void
    {
        $eml = (new Mensaje('ana@ejemplo.com', 'Contraseña nueva', "Hola\nqué tal", '<p>Hola</p>'))->rfc822('erp@ejemplo.com', 'Croilab ERP');
        $this->assertStringContainsString("To: <ana@ejemplo.com>\r\n", $eml);
        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('Contraseña nueva') . '?=', $eml);
        $this->assertStringContainsString('From: =?UTF-8?B?' . base64_encode('Croilab ERP') . '?= <erp@ejemplo.com>', $eml);
        $this->assertStringContainsString('multipart/alternative', $eml);
        $this->assertStringContainsString(base64_encode("Hola\nqué tal"), str_replace("\r\n", '', $eml));
        $this->assertDoesNotMatchRegularExpression('/[^\r]\n/', $eml, 'Todos los saltos de línea son CRLF');
    }

    public function testNoSePuedenInyectarCabeceras(): void
    {
        $eml = (new Mensaje('ana@ejemplo.com', "Hola\r\nBcc: espia@malo.com", 'x'))->rfc822('erp@ejemplo.com', "ERP\r\nBcc: otro@malo.com");
        $this->assertStringNotContainsString("\r\nBcc:", $eml);
    }

    public function testRechazaDestinatarioInvalido(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Mensaje("ana@ejemplo.com\r\nBcc: x@y.z", 'a', 'b');
    }

    /**
     * Servidor de mentira sin red ni procesos: un par de sockets conectados.
     * Las respuestas del servidor se dejan escritas de antemano (el cliente las
     * lee en orden, una por comando) y al final se recoge lo que escribió.
     * @return array{0: \Closure, 1: resource}
     */
    private function servidorFalso(array $respuestas): array
    {
        [$cliente, $servidor] = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($servidor, implode("\r\n", $respuestas) . "\r\n");
        return [fn() => $cliente, $servidor];
    }

    private function recibido($servidor): string
    {
        stream_set_blocking($servidor, false);
        return (string)stream_get_contents($servidor);
    }

    private const SALUDO = ['220 falso ESMTP', '250-falso', '250-AUTH PLAIN LOGIN', '250 8BITMIME'];
    private const ENTREGA = ['250 vale', '250 vale', '354 adelante', '250 en cola', '221 adios'];

    public function testEnviaPorSmtpConAuthPlain(): void
    {
        [$abrir, $srv] = $this->servidorFalso([...self::SALUDO, '235 vale', ...self::ENTREGA]);
        $smtp = new Smtp('smtp.ejemplo.com', 587, 'none', 'usuario@ejemplo.com', 'secreto', 'erp@ejemplo.com', 'ERP', 5, $abrir);
        $smtp->enviar(new Mensaje('ana@ejemplo.com', 'Prueba', 'cuerpo'));

        $r = $this->recibido($srv);
        $this->assertStringContainsString('AUTH PLAIN ' . base64_encode("\0usuario@ejemplo.com\0secreto") . "\r\n", $r);
        $this->assertStringContainsString("MAIL FROM:<erp@ejemplo.com>\r\n", $r);
        $this->assertStringContainsString("RCPT TO:<ana@ejemplo.com>\r\n", $r);
        $this->assertStringContainsString("DATA\r\n", $r);
        $this->assertStringContainsString('To: <ana@ejemplo.com>', $r);
        $this->assertStringContainsString(base64_encode('cuerpo'), $r);
        $this->assertStringContainsString("\r\n.\r\nQUIT\r\n", $r, 'El mensaje termina con un punto solo en su línea');
    }

    public function testUsaAuthLoginSiNoHayPlain(): void
    {
        [$abrir, $srv] = $this->servidorFalso(['220 x', '250-x', '250 AUTH LOGIN', '334 VXNlcm5hbWU6', '334 UGFzc3dvcmQ6', '235 vale', ...self::ENTREGA]);
        (new Smtp('smtp.ejemplo.com', 587, 'none', 'u@ejemplo.com', 'clave', 'erp@ejemplo.com', '', 5, $abrir))->enviar(new Mensaje('ana@ejemplo.com', 'a', 'b'));
        $this->assertStringContainsString("AUTH LOGIN\r\n" . base64_encode('u@ejemplo.com') . "\r\n" . base64_encode('clave') . "\r\n", $this->recibido($srv));
    }

    public function testSinUsuarioNoSeAutentica(): void
    {
        [$abrir, $srv] = $this->servidorFalso(['220 x', '250 x', ...self::ENTREGA]);
        (new Smtp('relay.interno', 25, 'none', '', '', 'erp@ejemplo.com', '', 5, $abrir))->enviar(new Mensaje('ana@ejemplo.com', 'a', 'b'));
        $this->assertStringNotContainsString('AUTH', $this->recibido($srv));
    }

    public function testAutenticacionRechazadaEsUnErrorSinLaContrasena(): void
    {
        [$abrir, $srv] = $this->servidorFalso([...self::SALUDO, '535 credenciales incorrectas']);
        $smtp = new Smtp('smtp.ejemplo.com', 587, 'none', 'usuario@ejemplo.com', 'secreto-que-no-debe-salir', 'erp@ejemplo.com', '', 5, $abrir);
        try {
            $smtp->enviar(new Mensaje('ana@ejemplo.com', 'Prueba', 'cuerpo'));
            $this->fail('Debía fallar la autenticación');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('535', $e->getMessage());
            $this->assertStringNotContainsString('secreto', $e->getMessage());
            $this->assertStringNotContainsString(base64_encode("\0usuario@ejemplo.com\0secreto-que-no-debe-salir"), $e->getMessage());
        }
    }

    public function testDestinatarioRechazado(): void
    {
        [$abrir, $srv] = $this->servidorFalso(['220 x', '250 x', '250 vale', '550 no existe']);
        $this->expectExceptionMessageMatches('/RCPT TO.*550/');
        (new Smtp('smtp.ejemplo.com', 25, 'none', '', '', 'erp@ejemplo.com', '', 5, $abrir))->enviar(new Mensaje('nadie@ejemplo.com', 'a', 'b'));
    }

    public function testDoblaLosPuntosAlPrincipioDeLinea(): void
    {
        $this->assertSame("Subject: x\r\n\r\n..linea\r\n..\r\nfin.\r\n", Smtp::datos("Subject: x\r\n\r\n.linea\r\n.\r\nfin.\r\n"));
    }

    public function testServidorInaccesible(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no se puede conectar/');
        (new Smtp('127.0.0.1', 1, 'none', '', '', 'erp@ejemplo.com', '', 2))->enviar(new Mensaje('ana@ejemplo.com', 'a', 'b'));
    }

    public function testSeguridadDesconocida(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Smtp('smtp.ejemplo.com', 25, 'starttls', '', '', 'erp@ejemplo.com');
    }
}
