<?php
namespace Croilab\Modulos\Equipo;

use Croilab\Correo\Mensaje;

/* Textos de los correos del equipo: invitación para darse de alta y enlace
   para elegir contraseña. El antiguo no mandaba ninguno (los enlaces se
   copiaban a mano); ahora se pueden enviar además de copiar. */
final class CorreosEquipo
{
    public static function invitacion(string $para, string $marca, string $rolNombre, string $enlace, int $horas): Mensaje
    {
        $texto = "Hola:\n\nTe han invitado a unirte al panel de $marca como «{$rolNombre}».\n\n"
            . "Para crear tu cuenta, abre este enlace (vale durante $horas horas y una sola vez):\n\n$enlace\n\n"
            . "Si no esperabas esta invitación, ignora este correo.\n";
        return new Mensaje($para, "Te han invitado al panel de $marca", $texto,
            self::html('Hola:', "Te han invitado a unirte al panel de $marca como «{$rolNombre}».", 'Crear mi cuenta', $enlace,
                "El enlace vale durante $horas horas y una sola vez. Si no esperabas esta invitación, ignora este correo."));
    }

    public static function enlacePassword(string $para, string $usuario, string $marca, string $enlace, int $horas): Mensaje
    {
        $texto = "Hola, $usuario:\n\nPara entrar en el panel de $marca, elige tu contraseña en este enlace (vale durante $horas horas y una sola vez):\n\n$enlace\n\n"
            . "Si no sabes de qué va esto, avisa a quien gestiona el equipo.\n";
        return new Mensaje($para, "Elige tu contraseña de $marca", $texto,
            self::html("Hola, $usuario:", "Para entrar en el panel de $marca, elige tu contraseña.", 'Elegir mi contraseña', $enlace,
                "El enlace vale durante $horas horas y una sola vez. Si no sabes de qué va esto, avisa a quien gestiona el equipo."));
    }

    private static function html(string $saludo, string $parrafo, string $boton, string $enlace, string $nota): string
    {
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="es"><body style="margin:0;padding:24px;background:#f9fafb;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#111827">'
            . '<div style="max-width:480px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px">'
            . '<p style="margin:0 0 16px;font-size:15px">' . $e($saludo) . '</p>'
            . '<p style="margin:0 0 20px;font-size:15px;line-height:1.6">' . $e($parrafo) . '</p>'
            . '<p style="margin:0 0 24px"><a href="' . $e($enlace) . '" style="display:inline-block;background:#141518;color:#fff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:10px">' . $e($boton) . '</a></p>'
            . '<p style="margin:0 0 8px;font-size:13px;color:#6b7280;line-height:1.6">' . $e($nota) . '</p>'
            . '<p style="margin:16px 0 0;font-size:12px;color:#9ca3af;word-break:break-all">' . $e($enlace) . '</p>'
            . '</div></body></html>';
    }
}
