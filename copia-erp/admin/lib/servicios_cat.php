<?php
/* Catálogo de servicios de la agencia — nombre, descripción y vídeo del portal.

   El problema que resuelve: había dos listas de servicios que no se hablaban.
   Una era el catálogo editable (settings.servicios_catalogo), donde se podía
   crear «Email marketing» o lo que hiciera falta. La otra eran siete claves de
   vídeo escritas a mano —video_web, video_seo, video_sem, video_cro,
   video_tienda, video_meta— y un mapa fijo en el portal que traducía el nombre
   del servicio a una de ellas. Consecuencia: un servicio nuevo del catálogo no
   tenía forma de tener vídeo, y nadie avisaba de ello.

   Ahora el vídeo viaja dentro del propio catálogo, junto al nombre y la
   descripción. Las claves viejas se siguen leyendo como respaldo, así que lo que
   ya estaba configurado sigue saliendo igual en el portal sin tocar nada.

   Lo usan servicios.php y settings.php (apartado Vídeos) en el ERP, y
   portal-cliente/index.php para saber qué vídeo enseña cada servicio.
*/

/* Los seis de siempre, para una instalación recién hecha. */
function svc_defecto() {
  return [
    ['nombre'=>'SEO',            'desc'=>'Posicionamiento orgánico en Google.'],
    ['nombre'=>'SEM',            'desc'=>'Campañas de Google Ads.'],
    ['nombre'=>'CRO',            'desc'=>'Optimización de la conversión.'],
    ['nombre'=>'Diseño web',     'desc'=>'Diseño y desarrollo de la web.'],
    ['nombre'=>'Tiendas online', 'desc'=>'Ecommerce y tiendas.'],
    ['nombre'=>'Meta Ads',       'desc'=>'Publicidad en Instagram y Facebook.'],
  ];
}

/* Dónde estaba guardado el vídeo de cada servicio antes de que el catálogo lo
   llevara dentro. Solo se consulta como respaldo: en cuanto se guarda una vez
   desde Ajustes, el vídeo vive en el catálogo y esto deja de hacer falta.
   «Meta» y «Meta Ads» apuntan a la misma clave porque el catálogo por defecto
   dice «Meta Ads» y el mapa antiguo del portal decía «Meta». */
function svc_video_legacy() {
  return ['Diseño web'=>'video_web','SEO'=>'video_seo','SEM'=>'video_sem','CRO'=>'video_cro',
          'Tiendas online'=>'video_tienda','Meta'=>'video_meta','Meta Ads'=>'video_meta'];
}

/* El catálogo normalizado: siempre filas con nombre, desc y video. */
function svc_catalogo() {
  $raw  = function_exists('get_setting') ? get_setting('servicios_catalogo','') : '';
  $arr  = $raw ? json_decode($raw, true) : null;
  if (!is_array($arr) || !$arr) $arr = svc_defecto();

  $legacy = svc_video_legacy();
  $out = [];
  foreach ($arr as $s) {
    if (!is_array($s)) continue;
    $nom = trim((string)($s['nombre'] ?? ''));
    if ($nom === '') continue;
    $vid = trim((string)($s['video'] ?? ''));
    /* Respaldo: si esta fila todavía no tiene vídeo propio pero el servicio es
       uno de los seis antiguos, se usa el que ya estuviera configurado. */
    if ($vid === '' && isset($legacy[$nom]) && function_exists('get_setting'))
      $vid = trim((string)get_setting($legacy[$nom],''));
    $out[] = ['nombre'=>$nom, 'desc'=>trim((string)($s['desc'] ?? '')), 'video'=>$vid];
  }
  return $out ?: svc_defecto();
}

/* Guarda el catálogo. Acepta filas parciales: lo que no venga se conserva de lo
   que ya había para ese mismo servicio, para que el formulario de Servicios (que
   no edita vídeos) no borre los vídeos, y el de Vídeos (que no edita
   descripciones) no borre las descripciones. */
function svc_guardar(array $filas) {
  $previo = [];
  foreach (svc_catalogo() as $s) $previo[$s['nombre']] = $s;

  $out = [];
  foreach ($filas as $f) {
    $nom = trim((string)($f['nombre'] ?? ''));
    if ($nom === '') continue;
    $ant = $previo[$nom] ?? ['desc'=>'','video'=>''];
    $out[] = [
      'nombre' => $nom,
      'desc'   => array_key_exists('desc',  $f) ? trim((string)$f['desc'])  : $ant['desc'],
      'video'  => array_key_exists('video', $f) ? trim((string)$f['video']) : $ant['video'],
    ];
  }
  db()->prepare("INSERT INTO settings (clave,valor) VALUES ('servicios_catalogo',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
      ->execute([json_encode($out, JSON_UNESCAPED_UNICODE)]);
  return $out;
}

/* Mapa nombre-del-servicio → id de YouTube, solo con los que tienen vídeo. Es lo
   que consume el portal del cliente. Se devuelve como objeto y no como array
   para que, cuando no haya ninguno, en el JSON del portal salga {} y no []. */
function svc_videos() {
  $m = [];
  foreach (svc_catalogo() as $s) if ($s['video'] !== '') $m[$s['nombre']] = $s['video'];
  return (object)$m;
}

/* ============================================================
   QUIÉN USA CADA SERVICIO

   Cada cliente guarda los suyos en clients.servicios_json, y los guarda **por
   nombre**, no por un identificador. Eso tiene una consecuencia que no se ve
   desde la pantalla de Servicios: si renombras «SEO» a «Posicionamiento SEO»,
   todos los clientes que tenían «SEO» se quedan apuntando a un servicio que ya
   no existe y el portal les bloquea esa sección — sin un solo aviso.

   Por eso el renombrado tiene que arrastrar el cambio a los clientes, y por eso
   la pantalla enseña a cuánta gente afecta cada uno antes de tocarlo.
   ============================================================ */

/* Cuántos clientes VEN cada servicio.

   Ojo con el detalle que hace que este cálculo no sea una simple cuenta: un
   cliente con `servicios_json` vacío o nulo **no es un cliente sin servicios**,
   es un cliente sin restringir, y el portal se los enseña todos
   (`SERVICIOS==null → hasService() siempre true`, portal-cliente/index.php).
   Contar solo los que tienen lista diría «sin clientes» de un servicio que en
   realidad están viendo los veintiuno.

   Devuelve ['por'=>nombre=>n, 'abiertos'=>n, 'total'=>n]:
     por      → clientes con lista que incluyen ese servicio
     abiertos → clientes sin lista, que ven todos
     total    → clientes en total */
function svc_uso() {
  $u = ['por'=>[], 'abiertos'=>0, 'total'=>0];
  try {
    foreach (db()->query('SELECT servicios_json FROM clients') as $r) {
      $u['total']++;
      $raw = trim((string)($r['servicios_json'] ?? ''));
      $lista = $raw !== '' ? json_decode($raw, true) : null;
      if (!is_array($lista)) { $u['abiertos']++; continue; }
      foreach ($lista as $n) { $n = trim((string)$n); if ($n !== '') $u['por'][$n] = ($u['por'][$n] ?? 0) + 1; }
    }
  } catch (Exception $e) { error_log('svc_uso: '.$e->getMessage()); }
  return $u;
}

/* Cuántos clientes ven un servicio concreto: los que lo tienen en su lista más
   los que no tienen lista ninguna. */
function svc_uso_de($uso, $nombre) {
  return (int)($uso['por'][$nombre] ?? 0) + (int)($uso['abiertos'] ?? 0);
}

/* Cambia el nombre de un servicio en la ficha de todos los clientes que lo
   tengan. Devuelve a cuántos ha afectado. Si $a es cadena vacía, lo quita. */
function svc_renombrar_en_clientes($de, $a) {
  $de = trim((string)$de); $a = trim((string)$a);
  if ($de === '' || $de === $a) return 0;
  $n = 0;
  try {
    $filas = db()->query("SELECT id, servicios_json FROM clients WHERE servicios_json IS NOT NULL AND servicios_json<>''")->fetchAll();
    $upd = db()->prepare('UPDATE clients SET servicios_json=? WHERE id=?');
    foreach ($filas as $f) {
      $lista = json_decode((string)$f['servicios_json'], true);
      if (!is_array($lista) || !in_array($de, $lista, true)) continue;
      $nueva = [];
      foreach ($lista as $s) {
        $s = (string)$s;
        if ($s === $de) { if ($a !== '') $nueva[] = $a; }
        else $nueva[] = $s;
      }
      /* array_values para que siga siendo una lista JSON y no un objeto. */
      $upd->execute([json_encode(array_values(array_unique($nueva)), JSON_UNESCAPED_UNICODE), (int)$f['id']]);
      $n++;
    }
  } catch (Exception $e) { error_log('svc_renombrar_en_clientes: '.$e->getMessage()); }
  return $n;
}
