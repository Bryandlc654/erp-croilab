<?php
/* Reacciones del chat: el emoji se compara byte a byte (como 0021 con las de
   los comentarios de tareas). Con utf8mb4_general_ci todos los emojis de 4
   bytes cuentan como iguales: reaccionar con 🔥 donde ya había un 👍 tuyo
   chocaba con la clave primaria y quitar uno borraba el otro. */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    if (!Esquema::tablaExiste($pdo, 'chat_reactions')) return;
    $st = $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_reactions' AND COLUMN_NAME = 'emoji'");
    if ((string)$st->fetchColumn() === 'utf8mb4_bin') return;
    $pdo->exec('ALTER TABLE chat_reactions MODIFY emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
};
