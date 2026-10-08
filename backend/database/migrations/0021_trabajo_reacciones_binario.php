<?php
/* Reacciones de los comentarios: el emoji se compara byte a byte.

   Con la intercalación general (utf8mb4_general_ci) todos los emojis de 4
   bytes cuentan como iguales: poner 🔥 donde ya había un 👍 tuyo chocaba con la
   clave única y no se guardaba, y quitar 🔥 borraba el 👍. Con utf8mb4_bin cada
   emoji es distinto. Si quedaran filas que ahora fueran duplicadas no pasa
   nada: con binario dejan de serlo. */

use Croilab\Database\Esquema;

return function (PDO $pdo): void {
    if (!Esquema::tablaExiste($pdo, 'task_comment_reactions')) return;
    $st = $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_comment_reactions' AND COLUMN_NAME = 'emoji'");
    if ((string)$st->fetchColumn() === 'utf8mb4_bin') return;
    $pdo->exec('ALTER TABLE task_comment_reactions MODIFY emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
};
