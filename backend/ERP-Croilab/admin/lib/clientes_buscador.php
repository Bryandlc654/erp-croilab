<?php
/* Buscador de clientes acotado, para los <select> que antes se llenaban con la
   tabla entera.
   El motivo: `SELECT id, name FROM clients ORDER BY name` acababa con un <select>
   con un <option> por cliente en el HTML. Con la base de una agencia grande eso
   son cientos de opciones en el documento, sin forma de filtrar salvo con la tecla
   del navegador, y se descargaba entero en cada carga de la pantalla.

   Aquí no se cambia el flujo: sigue siendo un <select>, pero se rellena con lo que
   encaja con lo que se teclea, siempre con un tope de filas. Si el listado crece,
   quien busca escribe; no se cargan miles de filas para enseñarlas.

   Lo usan login.php (modo equipo) y admin/workspace.php (filtro de cliente). */

if (!defined('CLIENTES_BUSCADOR_TOPE')) define('CLIENTES_BUSCADOR_TOPE', 25);

/* IDs que este admin puede ver: null = todos, [] = ninguno.
   `alcance_clientes()` devuelve null o una lista; un [] significa «no le toca
   ninguno» y hay que tratarlo como tal, no como «sin filtro». */
function clientes_alcance() {
    $ids = function_exists('alcance_clientes') ? alcance_clientes() : null;
    return is_array($ids) ? $ids : null;
}

/* Porción de WHERE que acota a los clientes del alcance. Devuelve '' si puede ver
   todos. Los ids se castean a entero porque van dentro del IN sin comillas. */
function clientes_alcance_sql() {
    $ids = clientes_alcance();
    if ($ids === null) return '';
    if (!$ids) return ' AND 1=0';   // alcance vacío: no hay ninguno que listar
    return ' AND id IN (' . implode(',', array_map('intval', $ids)) . ')';
}

/* Devuelve hasta $limite clientes que empiezan por $q (prefijo, no subcadena).
   Con $q vacío devuelve los primeros por nombre. */
function clientes_buscar($q, $limite = CLIENTES_BUSCADOR_TOPE) {
    /* Un admin sin ningún cliente asignado se ahorra hasta la consulta. El «AND 1=0»
       de clientes_alcance_sql() ya devolvería cero filas, pero eso es SECONDARIA:
       here se corta antes de preguntarle nada a la base. */
    if (clientes_alcance() === []) return [];
    $limite = max(1, (int)$limite);
    $donde = clientes_alcance_sql();
    $q = trim((string)$q);
    if ($q === '') {
        try {
            return db()->prepare('SELECT id, name FROM clients WHERE 1=1' . $donde
                . ' ORDER BY name LIMIT ' . $limite)->fetchAll();
        } catch (Exception $e) { return []; }
    }
    try {
        /* Prefijo y no subcadena a propósito: `LIKE 'algo%'` puede apoyarse en un
           índice de `name`; `LIKE '%algo%'` obligaría a leer la tabla entera en cada
           tecla. Este repositorio no crea la tabla `clients` (ya venía creada), así
           que no se puede garantizar ese índice: si algún día se nota, el sitio es
           crear `KEY clients_name (name)`.

           Los comodines que escriba quien busca se escapan, para que teclear «%» no
           se convierta en un comodín contra la base.

           Y ojo: execute() devuelve true/false, no el statement. Encadenar
           ->execute(...)->fetchAll() revienta solo al pulsar una tecla. */
        $pat = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $st = db()->prepare('SELECT id, name FROM clients WHERE name LIKE ? ESCAPE \'\\\\\''
            . $donde . ' ORDER BY name LIMIT ' . $limite);
        $st->execute([$pat]);
        return $st->fetchAll();
    } catch (Exception $e) { return []; }
}

/* Endpoint JSON del buscador. Sin estado y solo para quien ya está identificado,
   por eso es GET y no necesita CSRF. `$url` es la página desde la que se llama
   (puede estar dentro de /admin/). Devuelve 0 filas si no hay nada que pintar. */
function clientes_buscar_json($q, $url) {
    header('Content-Type: application/json; charset=utf-8');
    $salida = [];
    foreach (clientes_buscar($q) as $c) {
        $salida[] = ['id' => (int)$c['id'], 'name' => $c['name']];
    }
    echo json_encode(['ok' => 1, 'clientes' => $salida, 'tope' => CLIENTES_BUSCADOR_TOPE]);
    exit;
}

/* Rellena un <select> con lo que hay en memoria. `$actual` marca la opción
   seleccionada. Se usa para el primer pintado, antes de que el buscador actúe. */
function clientes_opciones($lista, $actual = 0) {
    $out = '';
    foreach ($lista as $c) {
        $out .= '<option value="' . (int)$c['id'] . '"'
            . ((int)$c['id'] === (int)$actual ? ' selected' : '') . '>'
            . htmlspecialchars((string)$c['name'], ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $out;
}

/* JS del buscador. Espera en la página un <input> de búsqueda y un <select>
   destino. Devuelve el nombre de la función global `clientesBuscar(q, onchange)`
   para poder engancharlo al evento que haga falta.
   Cada respuesta lleva su número y las viejas se descartan: si el tecleado va más
   rápido que la red, una respuesta lenta podría llegar después de otra más nueva y
   pintar la lista equivocada. */
function clientes_js($url, $funcionSeleccion = '') {
    $url = json_encode($url, JSON_UNESCAPED_SLASHES);
    $fn  = json_encode($funcionSeleccion, JSON_UNESCAPED_SLASHES);
    $top = (int)CLIENTES_BUSCADOR_TOPE;
    return <<<HTML
<script>
/* Buscador de clientes: rellena un <select> sin traer la tabla entera. */
var CLI_BUSCA_SEQ=0,CLI_BUSCA_TOPE=$top;
function clientesBuscar(q,alElegir){
  var sel=document.getElementById('cliSel'); if(!sel) return;
  /* hint se declara aquí y no dentro del then: el catch lo necesita y, si viviera
     dentro del then, sería otro identificador distinto y petaría al fallar. */
  var hint=document.getElementById('cliHint'), seq=++CLI_BUSCA_SEQ, previo=sel.value;
  fetch($url+'?json=1&buscar='+encodeURIComponent(q),{credentials:'same-origin'})
    .then(function(r){return r.json();})
    .then(function(j){
      if(seq!==CLI_BUSCA_SEQ) return;              /* llegó tarde: hay otra más nueva */
      var lista=(j&&j.clientes)||[];
      sel.innerHTML='';
      lista.forEach(function(c){ var o=document.createElement('option'); o.value=c.id; o.textContent=c.name; sel.appendChild(o); });
      if(previo&&lista.some(function(c){return String(c.id)===String(previo);})) sel.value=previo;
      if(typeof alElegir==='function') alElegir(lista);
      if(hint) hint.textContent = lista.length
        ? (lista.length>=CLI_BUSCA_TOPE?'Se muestran los primeros '+CLI_BUSCA_TOPE+'. Sigue escribiendo para afinar.':'')
        : 'Sin coincidencias.';
    })
    .catch(function(){ if(hint&&seq===CLI_BUSCA_SEQ) hint.textContent=''; });
}
/* Al elegir del desplegable se refleja el nombre en el campo de búsqueda, para que
   no queden dos sitios con información distinta del cliente elegido. */
function clientesReflejar(){
  var s=document.getElementById('cliSel'), q=document.getElementById('cliQ'); if(!s||!q) return;
  var o=s.options[s.selectedIndex]; if(o) q.value=o.textContent;
}
</script>
HTML;
}