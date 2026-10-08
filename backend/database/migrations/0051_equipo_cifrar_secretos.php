<?php
/* Cifra los secretos que el ERP antiguo guardaba en claro:

   · client_credentials.secreto (bóveda de credenciales de clientes), propósito
     «cred_cliente».
   · El OAuth de «Google · Métricas»: google_oauth_client_secret y
     google_oauth_refresh_token, propósito «gmet» (los de Calendar ya pasaban
     por la bóveda).

   Usa admin/lib/boveda.php (AES-256-CBC + HMAC, clave fuera de la base). Es
   idempotente: lo que ya está cifrado (prefijo bx1:) no se toca. Si el
   servidor no puede cifrar (falta openssl o la clave), no se pierde nada: se
   deja como está, se avisa en el registro y la API lo cifra al primer uso
   (boveda_leer migra en caliente y el servicio nunca guarda en claro). */

return function (PDO $pdo): void {
    require_once __DIR__ . '/../../admin/lib/boveda.php';
    if (!boveda_disponible()) {
        error_log('0051: la bóveda no puede cifrar ahora; los secretos se cifrarán al usarse.');
        return;
    }
    $sel = $pdo->query("SELECT id, secreto FROM client_credentials WHERE secreto IS NOT NULL AND secreto <> '' AND secreto NOT LIKE 'bx1:%'");
    $upd = $pdo->prepare('UPDATE client_credentials SET secreto = ? WHERE id = ?');
    foreach ($sel->fetchAll() as $r) {
        $c = boveda_cifrar((string)$r['secreto'], 'cred_cliente');
        if (is_string($c) && $c !== '') $upd->execute([$c, (int)$r['id']]);
    }
    $st = $pdo->prepare("SELECT valor FROM settings WHERE clave = ?");
    $upd = $pdo->prepare('UPDATE settings SET valor = ? WHERE clave = ?');
    foreach (['google_oauth_client_secret', 'google_oauth_refresh_token'] as $clave) {
        $st->execute([$clave]);
        $v = (string)$st->fetchColumn();
        if ($v === '' || boveda_esta_cifrada($v)) continue;
        $c = boveda_cifrar($v, 'gmet');
        if (is_string($c) && $c !== '') $upd->execute([$c, $clave]);
    }
};
