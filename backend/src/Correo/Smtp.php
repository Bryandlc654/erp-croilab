<?php
namespace Croilab\Correo;

/* Cliente SMTP mínimo y sin dependencias (en producción no hay Composer).
   Soporta SSL directo (465), STARTTLS (587) o sin cifrar (relés internos y
   tests), y autenticación PLAIN o LOGIN. Verifica el certificado del servidor:
   un correo con un enlace para cambiar la contraseña no puede viajar a quien
   se haga pasar por el servidor de correo. */
final class Smtp implements Correo
{
    /** @var resource|null */
    private $conexion = null;

    public function __construct(
        private readonly string $host,
        private readonly int $puerto,
        private readonly string $seguridad,    // 'ssl' | 'tls' | 'none'
        private readonly string $usuario,
        private readonly string $clave,
        private readonly string $de,
        private readonly string $deNombre = '',
        private readonly int $espera = 15,
        /* Solo para tests: abre la conexión en vez de stream_socket_client(). */
        private readonly ?\Closure $abrir = null
    ) {
        if (!in_array($seguridad, ['ssl', 'tls', 'none'], true)) throw new \InvalidArgumentException("SMTP_SEGURIDAD desconocida: $seguridad");
        if (!filter_var($de, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('MAIL_FROM no es un correo válido');
    }

    public function enviar(Mensaje $m): void
    {
        try {
            $this->conectar();
            $this->comando('MAIL FROM:<' . $this->de . '>', [250]);
            $this->comando('RCPT TO:<' . $m->para . '>', [250, 251]);
            $this->comando('DATA', [354]);
            $this->comando(self::datos($m->rfc822($this->de, $this->deNombre)) . "\r\n.", [250]);
            $this->comando('QUIT', [221]);
        } finally {
            if ($this->conexion) fclose($this->conexion);
            $this->conexion = null;
        }
    }

    /* Un punto al principio de línea se dobla (RFC 5321 §4.5.2): una línea con
       un punto solo terminaría el mensaje antes de tiempo. */
    public static function datos(string $rfc822): string
    {
        return preg_replace('/^\./m', '..', $rfc822);
    }

    private function conectar(): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->host]]);
        $dir = ($this->seguridad === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->puerto;
        $errstr = '';
        $c = $this->abrir ? ($this->abrir)($dir) : @stream_socket_client($dir, $errno, $errstr, $this->espera, STREAM_CLIENT_CONNECT, $ctx);
        if (!$c) throw new \RuntimeException("SMTP: no se puede conectar con {$this->host}:{$this->puerto} ($errstr)");
        stream_set_timeout($c, $this->espera);
        $this->conexion = $c;

        $this->esperar([220]);
        $ehlo = $this->ehlo();
        if ($this->seguridad === 'tls') {
            $this->comando('STARTTLS', [220]);
            if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('SMTP: no se ha podido cifrar la conexión (STARTTLS)');
            }
            $ehlo = $this->ehlo();   // tras STARTTLS el servidor vuelve a anunciar lo que admite
        }
        if ($this->usuario !== '') $this->autenticar($ehlo);
    }

    private function ehlo(): string
    {
        $nombre = preg_replace('/[^a-zA-Z0-9.-]/', '', (string)gethostname()) ?: 'localhost';
        return $this->comando("EHLO $nombre", [250]);
    }

    private function autenticar(string $ehlo): void
    {
        if (preg_match('/AUTH[ =][^\r\n]*PLAIN/i', $ehlo)) {
            $this->comando('AUTH PLAIN ' . base64_encode("\0{$this->usuario}\0{$this->clave}"), [235], true);
            return;
        }
        $this->comando('AUTH LOGIN', [334]);
        $this->comando(base64_encode($this->usuario), [334], true);
        $this->comando(base64_encode($this->clave), [235], true);
    }

    /** Envía una línea y comprueba el código. $secreto: no se copia al error. */
    private function comando(string $linea, array $codigos, bool $secreto = false): string
    {
        if (fwrite($this->conexion, $linea . "\r\n") === false) throw new \RuntimeException('SMTP: conexión cerrada al escribir');
        try {
            return $this->esperar($codigos);
        } catch (\RuntimeException $e) {
            $que = $secreto ? '(credenciales)' : strtok($linea, "\r\n");
            throw new \RuntimeException("SMTP: «" . substr((string)$que, 0, 60) . "» → " . $e->getMessage());
        }
    }

    /** Lee una respuesta completa (puede tener varias líneas «250-…»). */
    private function esperar(array $codigos): string
    {
        $resp = '';
        while (($l = fgets($this->conexion, 1024)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        if ($resp === '') throw new \RuntimeException('el servidor no responde');
        $cod = (int)substr($resp, 0, 3);
        if (!in_array($cod, $codigos, true)) throw new \RuntimeException(trim($resp));
        return $resp;
    }
}
