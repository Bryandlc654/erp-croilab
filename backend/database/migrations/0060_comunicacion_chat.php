<?php
/* Chat: lo que necesita el sondeo eficiente de la API nueva.

   El antiguo reenviaba en cada sondeo (cada 2,5 s) los 150 últimos mensajes
   con edición, borrado o reacciones, por si alguno había cambiado. Aquí cada
   mensaje apunta cuándo cambió por última vez (`cambiado_en`, con milésimas) y
   el sondeo pide solo lo cambiado desde su cursor: casi siempre, nada.

   Índices:
     · (room_id, cambiado_en) para ese «qué ha cambiado en esta sala».
     · (admin_id) para los no leídos de cada persona (que excluyen lo propio).
     · chat_typing (until_ts) para que la limpieza del cron no recorra la tabla. */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    Esquema::columnas($pdo, 'chat_messages', ['cambiado_en' => 'DATETIME(3) NULL DEFAULT NULL']);
    Esquema::indice($pdo, 'chat_messages', 'ix_chm_sala_cambio', ['room_id', 'cambiado_en']);
    Esquema::indice($pdo, 'chat_messages', 'ix_chm_autor', ['admin_id']);
    Esquema::indice($pdo, 'chat_typing', 'ix_cht_hasta', ['until_ts']);
    Esquema::indice($pdo, 'chat_rooms', 'ix_chr_tipo', ['type']);
};
