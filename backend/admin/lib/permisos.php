<?php
/* Roles y permisos del ERP.

   Antes había tres roles escritos a mano en auth.php —owner, editor, viewer— y
   dos funciones para consultarlos: is_owner() y can_edit(). Eso daba para poco:
   o podías guardar en todo el ERP o no podías guardar en nada. No había forma de
   decir «este entra solo al CRM», ni de quitarle a alguien la contabilidad sin
   quitarle también las tareas.

   Ahora los roles viven en la tabla `roles`, se pueden crear los que hagan falta
   y cada uno lleva su lista de permisos. Los tres de siempre siguen existiendo
   con las mismas claves, así que ningún usuario cambia de rol al actualizar.

   Dos garantías que no se pueden romper:

   1. **El Dueño puede todo, siempre.** `admin.total` no se comprueba contra la
      lista: quien lo tiene pasa cualquier can(). Si un rol se guardara mal, el
      dueño no puede quedarse fuera de su propio ERP.
   2. **Tiene que quedar al menos un Dueño.** El rol `owner` no se borra y no se
      puede quitar el último administrador que lo tenga (lo comprueba la pantalla
      de permisos y team-edit.php).

   Cómo se usa:
     can('ver.crm')            → bool
     require_perm('ver.crm')   → corta la petición si no lo tiene
     perm_catalogo()           → todos los permisos, agrupados, para la pantalla

   La comprobación por pantalla es automática: perm_de_pagina() dice qué permiso
   pide cada archivo y auth.php lo aplica en require_admin(). No hace falta tocar
   las 58 páginas una por una.
*/

/* ---------- Catálogo: qué se puede permitir ----------
   Grupo => [clave => [etiqueta, explicación en una frase]]
   La clave es lo que se guarda; cámbiala y dejarás sin permiso a quien la tenga.

   ⚠️ AL AÑADIR UN PERMISO NUEVO, AÑÁDELO TAMBIÉN A perm_herencia().
   Los roles guardan una lista cerrada de permisos, así que un permiso nuevo NO
   lo tiene nadie: el día que se añade, todos los roles pierden de golpe lo que
   ese permiso pasa a controlar. perm_herencia() dice de qué permiso antiguo se
   deduce cada uno, y perm_migrar() se lo reparte a quien corresponda. Sin eso,
   ampliar esta lista rompe el ERP en silencio para todo el mundo menos el Dueño. */
function perm_catalogo() {
  return [
    'Qué módulos ve' => [
      'ver.clientes'  => ['Clientes',        'Las fichas de cliente y el panel de inicio.'],
      'ver.tareas'    => ['Tareas',          'El tablero, las listas y las tareas.'],
      'ver.proyectos' => ['Proyectos',       'Los proyectos y su rentabilidad.'],
      'ver.crm'       => ['CRM y ventas',    'Contactos, embudo y negocios.'],
      'ver.finanzas'  => ['Facturas',        'Facturas, programaciones y resumen mensual.'],
      'ver.conta'     => ['Contabilidad',    'Las cuentas y el análisis. Va aparte: se puede facturar sin ver la contabilidad.'],
      'ver.horas'     => ['Horas del equipo','Cuántas horas echa cada uno y en qué.'],
      'ver.soporte'   => ['Soporte',         'Los tickets de los clientes.'],
      'ver.chat'      => ['Chat de equipo',  'El chat interno.'],
      'ver.ia'        => ['Asistente IA',    'El asistente.'],
      'ver.agenda'    => ['Calendario',      'El calendario y las reuniones.'],
      'ver.actas'     => ['Ver Actas',       'El apartado de actas internas del equipo: notas y actas de reunión. Escribirlas/borrarlas sigue necesitando «Guardar cambios».'],
      /* Ajustes NO es una pantalla más: es la configuración del ERP entero —
         los datos fiscales, la marca blanca, los tokens de las integraciones,
         quién entra y qué puede hacer. Por eso viene apagado para todo el mundo
         menos el Dueño, y quien solo trabaja aquí no lo ve ni por el engranaje:
         a esa gente el engranaje le abre «Mi cuenta», que es lo suyo. */
      'ver.ajustes'   => ['Gestión y ajustes','La configuración del ERP: agencia, facturación, servicios, tipos, marca blanca, integraciones y papelera. Es cosa de quien administra. Quien solo trabaja aquí no lo necesita, y sin esto el engranaje le abre su cuenta.'],
      'ver.credenciales' => ['Bóveda de credenciales', 'Las contraseñas y accesos de los clientes. Va aparte de Ajustes por lo que son.'],
    ],
    'Clientes' => [
      'clientes.crear'  => ['Dar de alta clientes', 'Crear clientes nuevos y duplicarlos.'],
      'clientes.editar' => ['Editar clientes',      'Cambiar sus datos, accesos y métricas.'],
      'clientes.borrar' => ['Borrar clientes',      'Mandarlos a la papelera con todo lo suyo.'],
      'clientes.portal' => ['Publicar en su portal','Cambiar lo que el cliente ve: métricas, fases, informes.'],
    ],
    'Trabajo del día' => [
      'tareas.crear'  => ['Crear tareas',   'Añadir tareas y listas.'],
      'tareas.editar' => ['Editar tareas',  'Cambiar estado, fecha, prioridad y responsable.'],
      'tareas.borrar' => ['Borrar tareas',  'Eliminar tareas y listas.'],
      'tareas.horas'  => ['Imputar horas',  'Apuntar el tiempo dedicado, que va a la contabilidad.'],
      'soporte.responder' => ['Responder tickets', 'Contestar y cerrar los tickets de los clientes.'],
    ],
    'CRM y ventas' => [
      'crm.crear'     => ['Crear contactos y negocios', 'Añadir leads, contactos y oportunidades.'],
      'crm.editar'    => ['Editar el embudo',           'Mover de fase, asignar propietario y cambiar datos.'],
      'crm.borrar'    => ['Borrar del CRM',             'Eliminar contactos y negocios.'],
      'crm.convertir' => ['Convertir en cliente',       'Pasar un negocio ganado a cliente, con sus listas y su portal.'],
    ],
    'Dinero' => [
      'finanzas.emitir'    => ['Emitir facturas',       'Crear facturas y enviarlas.'],
      'finanzas.cobrar'    => ['Marcar cobros',         'Dar una factura por pagada. Es lo que mueve la contabilidad.'],
      'finanzas.borrar'    => ['Borrar facturas',       'Eliminar facturas emitidas.'],
      'finanzas.programar' => ['Facturas recurrentes',  'Crear y cambiar las que se emiten solas cada mes.'],
      'conta.editar'       => ['Tocar la contabilidad', 'Añadir o cambiar apuntes de gastos e ingresos.'],
      'finanzas.emisores'  => ['Datos fiscales propios','El NIF y el IBAN con los que facturáis, y quién factura.'],
    ],
    'Configuración' => [
      'ajustes.editar'       => ['Ajustes de la agencia', 'Identidad, contacto del portal, vídeos y reglas automáticas.'],
      'servicios.editar'     => ['Catálogo de servicios', 'Qué servicios ofrecéis.'],
      'tipos.editar'         => ['Tipos de cliente',      'Qué secciones ve cada tipo en su portal.'],
      'marca.editar'         => ['Marca blanca',          'Las agencias colaboradoras y con qué marca ve el portal cada cliente.'],
      'integraciones.editar' => ['Integraciones',         'Conectar n8n, Google Calendar y Claude. Incluye tokens de acceso.'],
    ],
    'Hasta dónde ve' => [
      'alcance.todos' => ['Ve todos los clientes',
        'Sin esto, solo ve los clientes en los que tiene alguna tarea asignada (y los contactos de los que es propietario). Es la forma de que cada uno vea lo suyo y nada más.'],
      'ver.importes'  => ['Ve las cantidades de dinero',
        'Sin esto, los euros salen tapados donde aparecen de pasada: el panel de inicio, la ficha del cliente y el listado. No sustituye a los permisos de Facturas y Contabilidad, que son los que dan acceso a esas pantallas.'],
    ],
    'Administración' => [
      'general.editar'    => ['Guardar cambios',      'El permiso base para escribir. Sin esto solo puede mirar, aunque tenga los demás.'],
      'equipo.gestionar'  => ['Gestionar el equipo',  'Invitar personas, cambiarles el rol y darles de baja.'],
      'roles.gestionar'   => ['Gestionar los roles',  'Crear roles y decidir qué puede hacer cada uno. Es esta misma pantalla.'],
      'papelera.restaurar'=> ['Restaurar de papelera','Recuperar algo borrado.'],
      'papelera.purgar'   => ['Vaciar la papelera',   'Borrar definitivamente lo que está en la papelera.'],
      'datos.avanzado'    => ['Datos avanzados',      'Editar las tablas de la base de datos en bruto. Muy peligroso.'],
      'admin.total'       => ['Dueño · acceso total', 'Puede todo, ahora y lo que se añada en el futuro. Reservado a quien manda.'],
    ],
  ];
}

/* De qué permiso antiguo se deduce cada uno de los nuevos.

   Es la red que impide que ampliar el catálogo deje a media plantilla sin poder
   trabajar. Cuando perm_migrar() encuentra un permiso que ningún rol conoce
   todavía, se lo da a los roles que tengan el permiso de la derecha — es decir,
   a los que ya podían hacer eso mismo antes de que existiera la casilla.

   `true` = se lo lleva todo el mundo (permisos que antes no restringían nada). */
function perm_herencia() {
  return [
    /* Hoy todo el mundo ve todos los clientes, así que este permiso lo hereda
       TODO EL MUNDO. Si no, el día que se añade, media plantilla deja de ver a
       sus clientes de golpe. Se le quita luego a quien se quiera limitar. */
    'alcance.todos' => true,
    'ver.importes'  => true,
    /* Módulos que antes no tenían casilla propia: los veía cualquiera que
       entrara al módulo del que colgaban. */
    'ver.proyectos'     => 'ver.tareas',
    'ver.horas'         => 'ver.finanzas',
    'ver.credenciales'  => 'ver.ajustes',
    /* Actas es nueva: la hereda quien podía escribir (Editor y roles de trabajo),
       no Solo lectura. El Dueño la tiene siempre por perm_todas(). */
    'ver.actas'         => 'general.editar',
    /* Acciones: antes bastaba con «puede guardar cambios». */
    'clientes.crear'       => 'general.editar',
    'clientes.editar'      => 'general.editar',
    'clientes.portal'      => 'general.editar',
    'tareas.crear'         => 'general.editar',
    'tareas.editar'        => 'general.editar',
    'tareas.borrar'        => 'general.editar',
    'tareas.horas'         => 'general.editar',
    'soporte.responder'    => 'general.editar',
    'crm.crear'            => 'general.editar',
    'crm.editar'           => 'general.editar',
    'crm.borrar'           => 'general.editar',
    'crm.convertir'        => 'general.editar',
    'finanzas.cobrar'      => 'finanzas.emitir',
    'finanzas.borrar'      => 'finanzas.emitir',
    'finanzas.programar'   => 'finanzas.emitir',
    'conta.editar'         => 'general.editar',
    'finanzas.emisores'    => 'ajustes.editar',
    'servicios.editar'     => 'ajustes.editar',
    'tipos.editar'         => 'ajustes.editar',
    'marca.editar'         => 'ajustes.editar',
    'integraciones.editar' => 'ajustes.editar',
    'papelera.restaurar'   => 'general.editar',
  ];
}

/* Los permisos que son CONFIGURACIÓN del ERP, no trabajo del día.

   Sirven para dos cosas: la migración v5, que se los quita a los roles Editor y
   Solo lectura, y la pantalla de permisos, que marca este bloque como sensible.

   El criterio para meter un permiso aquí: si al tocarlo cambia cómo funciona el
   ERP para TODOS (o quién entra en él), es configuración. Si solo cambia un dato
   de un cliente o una tarea, es trabajo. */
function perm_config() {
  return ['ver.ajustes','ajustes.editar','servicios.editar','tipos.editar','marca.editar',
          'integraciones.editar','finanzas.emisores','papelera.restaurar','papelera.purgar',
          'equipo.gestionar','roles.gestionar','datos.avanzado','admin.total'];
}

/* ---------- Qué necesita cada permiso para servir de algo ----------

   «Borrar tareas» sin «Tareas» no hace nada: no puede abrir la pantalla desde la
   que se borra. Y casi todo lo que escribe necesita además «Guardar cambios»,
   que es el permiso base de escritura de todo el ERP (can_edit()).

   Un rol con la mitad de una pareja no da un error: da algo peor, un botón que
   está ahí y no funciona, o una pantalla en blanco. Por eso:
     · la matriz avisa antes de marcar («para esto hace falta también…»),
     · y rol_guardar() lo completa igualmente en el servidor, porque el aviso del
       navegador se puede saltar y la coherencia no puede depender de eso. */
function perm_requisitos() {
  $ed = 'general.editar';
  return [
    'clientes.crear'  => ['ver.clientes', $ed],
    'clientes.editar' => ['ver.clientes', $ed],
    'clientes.borrar' => ['ver.clientes', $ed],
    'clientes.portal' => ['ver.clientes', $ed],

    'tareas.crear'  => ['ver.tareas', $ed],
    'tareas.editar' => ['ver.tareas', $ed],
    'tareas.borrar' => ['ver.tareas', $ed],
    'tareas.horas'  => ['ver.horas', $ed],
    'soporte.responder' => ['ver.soporte', $ed],

    'crm.crear'  => ['ver.crm', $ed],
    'crm.editar' => ['ver.crm', $ed],
    'crm.borrar' => ['ver.crm', $ed],
    /* Convertir un negocio crea un cliente con sus listas y su portal: hace
       falta también el módulo de clientes o acaba en una ficha que no puede ver. */
    'crm.convertir' => ['ver.crm', 'ver.clientes', $ed],

    'finanzas.emitir'    => ['ver.finanzas', $ed],
    'finanzas.cobrar'    => ['ver.finanzas', $ed],
    'finanzas.borrar'    => ['ver.finanzas', $ed],
    'finanzas.programar' => ['ver.finanzas', $ed],
    'conta.editar'       => ['ver.conta', $ed],
    'finanzas.emisores'  => ['ver.ajustes', $ed],

    'ajustes.editar'       => ['ver.ajustes', $ed],
    'servicios.editar'     => ['ver.ajustes', $ed],
    'tipos.editar'         => ['ver.ajustes', $ed],
    'marca.editar'         => ['ver.ajustes', $ed],
    'integraciones.editar' => ['ver.ajustes', $ed],
    'papelera.restaurar'   => ['ver.ajustes', $ed],
    'papelera.purgar'      => ['ver.ajustes', $ed],
    /* Estas tres tienen pantalla propia, pero se llega a ellas por el menú de
       Gestión y ajustes: sin ese módulo, el permiso existe y no hay por dónde usarlo. */
    'equipo.gestionar' => ['ver.ajustes'],
    'roles.gestionar'  => ['ver.ajustes'],
    'datos.avanzado'   => ['ver.ajustes'],
  ];
}

/* Lo que se cae si se quita un permiso: los que lo tienen como requisito. */
function perm_dependientes($clave) {
  $out = [];
  foreach (perm_requisitos() as $p => $necesita) if (in_array($clave, $necesita, true)) $out[] = $p;
  return $out;
}

/* Completa una lista de permisos con lo que le falte para ser coherente.
   Se repite hasta que no añade nada, porque un requisito puede tener requisitos. */
function perm_completar(array $permisos) {
  $req = perm_requisitos();
  for ($i = 0; $i < 6; $i++) {
    $antes = count($permisos);
    foreach ($permisos as $p)
      foreach ($req[$p] ?? [] as $nec)
        if (!in_array($nec, $permisos, true)) $permisos[] = $nec;
    if (count($permisos) === $antes) break;
  }
  return array_values(array_unique($permisos));
}

/* Reparte los permisos nuevos entre los roles que ya podían hacer eso.

   Corre sola al leer los roles. Es idempotente: solo toca un rol si le falta
   algún permiso nuevo, y deja constancia de la versión aplicada para no repetir
   el trabajo en cada carga de página. */
/* Un solo tirón, una sola vez: sacar la configuración del ERP de los roles
   Editor y Solo lectura.

   Hasta ahora los dos nacían con `ver.ajustes`, así que cualquier persona del
   equipo entraba en Ajustes y veía los datos fiscales, la marca blanca y los
   tokens de las integraciones. Eso no tiene sentido: la configuración es de
   quien administra.

   Toca SOLO esos dos roles del sistema. Los roles que haya creado el dueño no se
   tocan: si le ha dado la configuración a un «Coordinador» a propósito, se
   respeta. Y va con su propia marca en `settings`, no con la versión del
   catálogo, para que no se repita si mañana se añaden permisos nuevos. */
function perm_config_solo_admin() {
  try {
    $st = db()->prepare("SELECT valor FROM settings WHERE clave='perm_config_solo_admin'");
    $st->execute();
    if ((string)$st->fetchColumn() === '1') return;
  } catch (Exception $e) { return; }

  $config = perm_config();
  /* Y de paso la descripción, que decía «no toca el equipo ni los datos
     avanzados» cuando en realidad entraba en toda la configuración. */
  $desc = [
    'editor' => 'Trabaja con clientes, tareas, CRM y finanzas. No entra en la configuración del ERP.',
    'viewer' => 'Puede mirar el trabajo, pero no guarda ningún cambio ni entra en la configuración.',
  ];
  try {
    foreach (['editor','viewer'] as $clave) {
      $st = db()->prepare('SELECT permisos FROM roles WHERE clave=? AND sistema=1');
      $st->execute([$clave]);
      $p = jdecode((string)$st->fetchColumn(), []);
      if (!is_array($p) || !$p) continue;
      $limpio = array_values(array_diff($p, $config));
      db()->prepare('UPDATE roles SET permisos=?, descripcion=? WHERE clave=?')
          ->execute([json_encode($limpio, JSON_UNESCAPED_UNICODE), $desc[$clave], $clave]);
    }
    db()->exec("INSERT INTO settings (clave,valor) VALUES ('perm_config_solo_admin','1') ON DUPLICATE KEY UPDATE valor='1'");
  } catch (Exception $e) { error_log('perm_config_solo_admin: '.$e->getMessage()); }
}

function perm_migrar() {
  static $hecho = false; if ($hecho) return; $hecho = true;
  perm_config_solo_admin();

  /* Sube este número al añadir permisos al catálogo: es lo que dispara el
     reparto entre los roles que ya existen. */
  $version = '5';
  try {
    $st = db()->prepare("SELECT valor FROM settings WHERE clave='perm_version'");
    $st->execute();
    if ((string)$st->fetchColumn() === $version) return;
  } catch (Exception $e) { return; }

  $herencia = perm_herencia();
  try {
    foreach (db()->query('SELECT clave, permisos FROM roles')->fetchAll() as $r) {
      $p = jdecode($r['permisos'], []);
      if (!is_array($p)) continue;
      $antes = count($p);
      foreach ($herencia as $nuevo => $padre) {
        if (in_array($nuevo, $p, true)) continue;                  // ya lo tiene
        if ($padre === true || in_array($padre, $p, true)) $p[] = $nuevo;
      }
      if (count($p) !== $antes)
        db()->prepare('UPDATE roles SET permisos=? WHERE clave=?')
            ->execute([json_encode(array_values(array_unique($p)), JSON_UNESCAPED_UNICODE), $r['clave']]);
    }
    /* El Dueño se queda con todo, incluido lo que se invente en el futuro. */
    db()->prepare('UPDATE roles SET permisos=? WHERE clave=?')
        ->execute([json_encode(perm_todas(), JSON_UNESCAPED_UNICODE), 'owner']);
    db()->prepare("INSERT INTO settings (clave,valor) VALUES ('perm_version',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
        ->execute([$version]);
  } catch (Exception $e) { error_log('perm_migrar: '.$e->getMessage()); }
}

/* Todas las claves, en plano. */
function perm_todas() {
  $out = [];
  foreach (perm_catalogo() as $grupo) foreach ($grupo as $k => $v) $out[] = $k;
  return $out;
}

function perm_label($clave) {
  foreach (perm_catalogo() as $grupo) if (isset($grupo[$clave])) return $grupo[$clave][0];
  return $clave;
}

/* ---------- La tabla ---------- */
function roles_ensure() {
  static $done = false; if ($done) return; $done = true;
  try {
    db()->exec("CREATE TABLE IF NOT EXISTS roles (
      clave       VARCHAR(30) NOT NULL PRIMARY KEY,
      nombre      VARCHAR(60) NOT NULL,
      descripcion VARCHAR(255) NOT NULL DEFAULT '',
      permisos    TEXT,
      sistema     TINYINT NOT NULL DEFAULT 0,
      orden       INT NOT NULL DEFAULT 0,
      created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  } catch (Exception $e) { error_log('roles_ensure crear tabla: '.$e->getMessage()); return; }

  /* Siembra de los tres de siempre, con las mismas claves que ya tienen los
     administradores en admins.role: nadie cambia de rol al actualizar. Solo se
     insertan si faltan; si ya existen, se respeta lo que el dueño haya tocado. */
  /* Los módulos de TRABAJO. `ver.ajustes` no está aquí a propósito: la
     configuración del ERP no es un módulo de trabajo (ver perm_config()). */
  $verTodo = ['ver.clientes','ver.tareas','ver.proyectos','ver.crm','ver.finanzas','ver.conta','ver.horas','ver.soporte','ver.chat','ver.ia','ver.agenda'];
  $semilla = [
    ['owner',  'Dueño',        'Puede todo: el trabajo, la configuración, el equipo y los datos avanzados.', perm_todas(), 1, 1],
    ['editor', 'Editor',       'Trabaja con clientes, tareas, CRM y finanzas. No entra en la configuración del ERP.',
      array_merge($verTodo, ['general.editar','clientes.borrar','finanzas.emitir','alcance.todos','ver.importes','ver.actas']), 1, 2],
    ['viewer', 'Solo lectura', 'Puede mirar el trabajo, pero no guarda ningún cambio ni entra en la configuración.',
      array_merge($verTodo, ['alcance.todos','ver.importes']), 1, 3],
  ];
  foreach ($semilla as $s) {
    try {
      db()->prepare('INSERT IGNORE INTO roles (clave,nombre,descripcion,permisos,sistema,orden) VALUES (?,?,?,?,?,?)')
          ->execute([$s[0],$s[1],$s[2],json_encode($s[3], JSON_UNESCAPED_UNICODE),$s[4],$s[5]]);
    } catch (Exception $e) { error_log('roles_ensure semilla '.$s[0].': '.$e->getMessage()); }
  }
}

/* clave => ['nombre','descripcion','permisos'=>[],'sistema'=>bool,'usuarios'=>n] */
function roles_todos($recargar = false) {
  static $c = null;
  if ($recargar) { $c = null; return []; }
  if ($c !== null) return $c;
  roles_ensure();
  perm_migrar();   // reparte los permisos nuevos antes de leer nada
  $c = [];
  try {
    foreach (db()->query('SELECT * FROM roles ORDER BY orden, nombre') as $r) {
      $c[$r['clave']] = [
        'nombre'      => $r['nombre'],
        'descripcion' => $r['descripcion'],
        'permisos'    => jdecode($r['permisos'], []),
        'sistema'     => (int)$r['sistema'] === 1,
      ];
    }
  } catch (Exception $e) { error_log('roles_todos: '.$e->getMessage()); }
  /* Si la tabla no se pudo leer, el ERP no se queda sin roles: se responde con
     los tres de siempre para que nadie pierda el acceso por un fallo de base. */
  if (!$c) $c = ['owner'=>['nombre'=>'Dueño','descripcion'=>'','permisos'=>perm_todas(),'sistema'=>true]];
  return $c;
}

/* Cuánta gente tiene cada rol. Necesario para no dejar el ERP sin ningún dueño. */
function roles_uso() {
  $u = [];
  try { foreach (db()->query('SELECT role, COUNT(*) n FROM admins GROUP BY role') as $r) $u[(string)$r['role']] = (int)$r['n']; }
  catch (Exception $e) {}
  return $u;
}

/* Cuántos administradores tienen acceso total. Si es 1, a ese no se le toca. */
function roles_n_duenos() {
  $n = 0; $roles = roles_todos(); $uso = roles_uso();
  foreach ($uso as $clave => $cuantos)
    if (in_array('admin.total', $roles[$clave]['permisos'] ?? [], true)) $n += $cuantos;
  return $n;
}

/* ---------- Consulta ---------- */

/* Los permisos del usuario que está dentro ahora mismo. */
function perm_mios() {
  static $p = null;
  if ($p !== null) return $p;
  $a = function_exists('current_admin') ? current_admin() : null;
  if (!$a) return $p = [];
  $roles = roles_todos();
  $p = $roles[(string)($a['role'] ?? '')]['permisos'] ?? [];
  return $p;
}

/* ¿Puede? El acceso total pasa por encima de todo (garantía 1). */
function can($permiso) {
  $p = perm_mios();
  if (in_array('admin.total', $p, true)) return true;
  return in_array((string)$permiso, $p, true);
}

/* Corta la petición si no puede. En un fetch responde JSON; en una página
   normal, una pantalla que explica qué falta en vez de un redirect mudo. */
function require_perm($permiso, $queEs = '') {
  if (can($permiso)) return;
  $esJson = (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
         || !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
         || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
  http_response_code(403);
  if ($esJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'permiso','msg'=>'No tienes permiso para esto.']);
    exit;
  }
  perm_pantalla_denegado($queEs ?: perm_label($permiso));
}

/* Pantalla de «no tienes permiso».

   Tres cosas que tiene que hacer y antes no hacía:
     · **conservar el menú lateral**, para que se pueda seguir trabajando en vez
       de quedarse en un callejón sin salida,
     · decir exactamente qué falta y con qué rol se está entrando,
     · y dar salida: pedirle el acceso a quien puede darlo, y enlaces a lo que
       esta persona sí puede abrir.
   Un redirect mudo a index.php, que es lo que hacía el ERP antes, parece que la
   aplicación está rota. */
function perm_pantalla_denegado($queEs) {
  $a   = function_exists('current_admin') ? current_admin() : null;
  $rol = '';
  if ($a) { $roles = roles_todos(); $rol = $roles[(string)$a['role']]['nombre'] ?? (string)$a['role']; }

  /* El armazón se carga LO PRIMERO, antes incluso de atender el botón de pedir
     acceso: notif_add() vive en erp_nav.php, y las páginas llaman a
     require_admin() antes de incluirlo. Sin esto la petición se perdía en
     silencio (se respondía «hemos avisado» sin haber avisado a nadie) y la
     pantalla salía sin menú, como un aviso pelado sobre fondo blanco. */
  if (!function_exists('erp_head') && is_file(__DIR__ . '/../erp_nav.php')) {
    if (function_exists('ensure_schema')) { try { ensure_schema(); } catch (Exception $e) {} }
    require_once __DIR__ . '/../erp_nav.php';
  }

  if (!function_exists('erp_head')) {
    echo '<!doctype html><meta charset="utf-8"><title>Sin permiso</title>'
       . '<div style="font:15px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;max-width:420px;margin:18vh auto;text-align:center">'
       . '<b>No tienes permiso</b><br>Tu rol no incluye '.e($queEs).'.</div>';
    exit;
  }

  /* Dos salidas y ya: volver al inicio, y ver sus tareas si las tiene. Una fila
     de seis botones convertía una pantalla de aviso en un menú, y lo que hace
     falta aquí es salir, no elegir destino. */
  $verTareas = can('ver.tareas');

  /* El módulo activo del menú se deja tal cual: así el usuario ve dónde ha
     intentado entrar y el menú lateral sigue ahí, con sus entradas. */
  erp_head(function_exists('erp_active_for') ? erp_active_for() : '', 'Sin permiso');
  ?>
<style>
.np{max-width:430px;margin:min(14vh,120px) auto 0;text-align:center}
.np-ic{width:56px;height:56px;border-radius:17px;background:var(--soft);color:#b5b9c1;
  display:flex;align-items:center;justify-content:center;margin:0 auto 18px}
.np h1{font-size:19px;margin-bottom:8px}
.np p{color:var(--muted);font-size:13.5px;line-height:1.6;margin:0 auto;max-width:38ch}
.np p b{color:var(--ink);font-weight:600}
.np .sal{display:flex;gap:9px;justify-content:center;flex-wrap:wrap;margin-top:24px}
</style>
<div class="np">
  <div class="np-ic"><?= ic('vault',26) ?></div>
  <h1>Aquí no puedes entrar</h1>
  <p>Tu rol <b><?= e($rol ?: '—') ?></b> no incluye <b><?= e($queEs) ?></b>.<br>
     Si lo necesitas para trabajar, pídeselo a quien lleve el ERP.</p>
  <div class="sal">
    <a class="btn" href="dashboard.php"><?= ic('home',15) ?> Volver al inicio</a>
    <?php if ($verTareas): ?>
      <a class="btn ghost" href="workspace.php?view=mine"><?= ic('tasks',15) ?> Ver mis tareas</a>
    <?php endif; ?>
  </div>
</div>
  <?php
  if (function_exists('erp_foot')) erp_foot();
  exit;
}

/* ============================================================
   HASTA DÓNDE VE CADA UNO

   Con el permiso `alcance.todos` (el caso normal) se ve todo el ERP, como
   siempre. Sin él, la persona solo ve los clientes con los que trabaja de
   verdad, igual que en ClickUp: aquellos donde tiene alguna tarea asignada.

   Un cliente entra en su lista si se cumple cualquiera de estas:
     · es responsable de una tarea de ese cliente,
     · o está entre los asignados de una tarea de ese cliente (task_assignees),
     · o es propietario de un contacto de ese cliente en el CRM.

   TODO PASA POR alcance_clientes(). Si añades una pantalla que lista clientes o
   tareas, fíltrala con esto; si no, se verá todo y el permiso será mentira. Los
   sitios donde está aplicado hoy se listan en docs/06 §4.0a.
   ============================================================ */

/* ---------- Dinero a la vista ----------
   Hay euros repartidos por pantallas que no son de Finanzas: el panel de inicio,
   la ficha del cliente, el listado. Quien no tenga `ver.importes` los ve tapados
   ahí, sin que la pantalla se le rompa ni le desaparezcan las demás columnas.

   Esto NO sustituye a `ver.finanzas` ni a `ver.conta`: esos deciden si entra en
   Facturas o en Contabilidad. Este solo tapa las cifras sueltas. */
function puede_importes() {
  if (!function_exists('can')) return true;
  return can('ver.importes');
}

/* Un importe ya formateado, o unos puntos si no puede verlo.
   Uso: eur_vis(eur($total))  —  el formateo lo sigue haciendo eur(). */
function eur_vis($textoYaFormateado) {
  return puede_importes() ? $textoYaFormateado : '·····';
}

/* ¿Ve todo el ERP? */
function alcance_todo() {
  if (!function_exists('can')) return true;
  return can('alcance.todos');
}

/* IDs de los clientes que puede ver. **null = todos** (no filtrar).
   Devolver null y no la lista completa es a propósito: así quien filtra sabe
   distinguir «puede verlo todo» de «no le toca ninguno», que son cosas muy
   distintas y una lista vacía las confundiría. */
function alcance_clientes() {
  static $c = null; if ($c !== null) return $c === 'todos' ? null : $c;
  if (alcance_todo()) { $c = 'todos'; return null; }

  $yo = function_exists('current_admin') ? (int)(current_admin()['id'] ?? 0) : 0;
  if (!$yo) { $c = []; return []; }

  $ids = [];
  try {
    $sql = 'SELECT DISTINCT t.client_id FROM tasks t
            LEFT JOIN task_assignees a ON a.task_id = t.id
            WHERE t.client_id IS NOT NULL AND (t.responsable_id = ? OR a.admin_id = ?)';
    $st = db()->prepare($sql); $st->execute([$yo, $yo]);
    foreach ($st as $r) $ids[] = (int)$r['client_id'];
  } catch (Exception $e) { error_log('alcance_clientes tareas: '.$e->getMessage()); }
  try {
    $st = db()->prepare('SELECT DISTINCT client_id FROM contacts WHERE client_id IS NOT NULL AND propietario_id = ?');
    $st->execute([$yo]);
    foreach ($st as $r) $ids[] = (int)$r['client_id'];
  } catch (Exception $e) {}

  $c = array_values(array_unique(array_filter($ids)));
  return $c;
}

/* ¿Puede ver la ficha de este cliente? */
function alcance_ve_cliente($clientId) {
  $ids = alcance_clientes();
  if ($ids === null) return true;
  return in_array((int)$clientId, $ids, true);
}

/* Corta si no le toca ese cliente. Para las pantallas de ficha (client.php,
   edit.php…), que reciben el id por la URL. */
function alcance_exigir_cliente($clientId) {
  if (alcance_ve_cliente($clientId)) return;
  /* El 403 va ANTES: perm_pantalla_denegado() termina en exit, así que cualquier
     cosa puesta después no llega a ejecutarse y la respuesta se iría con un 200
     — con la pantalla de «no tienes permiso», sí, pero indistinguible de un
     acceso correcto para cualquier cosa que mire el código de estado. */
  http_response_code(403);
  if (function_exists('perm_pantalla_denegado')) perm_pantalla_denegado('ese cliente');
  exit;
}

/* Fragmento SQL para filtrar por cliente. Devuelve '' si ve todos.
   Uso:  $sql = 'SELECT … FROM clients c WHERE 1 ' . alcance_sql('c.id');
   Los ids salen de la propia base y se pasan por (int), así que la
   interpolación es segura — pero NO metas aquí nada que venga del usuario. */
function alcance_sql($columna) {
  $ids = alcance_clientes();
  if ($ids === null) return '';
  if (!$ids) return ' AND 1=0 ';                     // no le toca ninguno
  return ' AND '.$columna.' IN ('.implode(',', array_map('intval', $ids)).') ';
}

/* Filtro para TAREAS. Aquí el criterio es más fino que en el resto: no son las
   tareas de «sus» clientes, son **las que tiene asignadas**, que es como funciona
   ClickUp. Alguien puede llevar dos tareas de un cliente sin tener por qué ver
   las otras treinta de ese mismo cliente.
     $alias = alias de la tabla tasks en la consulta. */
function alcance_sql_tareas($alias = 't') {
  if (alcance_todo()) return '';
  $yo = function_exists('current_admin') ? (int)(current_admin()['id'] ?? 0) : 0;
  if (!$yo) return ' AND 1=0 ';
  $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);   // el alias lo pone el código, no el usuario
  return " AND ({$a}.responsable_id={$yo} OR EXISTS(SELECT 1 FROM task_assignees za WHERE za.task_id={$a}.id AND za.admin_id={$yo})) ";
}

/* ¿Puede abrir esta tarea? Misma regla que el listado. */
function alcance_ve_tarea($taskId) {
  if (alcance_todo()) return true;
  $yo = function_exists('current_admin') ? (int)(current_admin()['id'] ?? 0) : 0;
  if (!$yo) return false;
  try {
    $st = db()->prepare('SELECT 1 FROM tasks t LEFT JOIN task_assignees a ON a.task_id=t.id
                         WHERE t.id=? AND (t.responsable_id=? OR a.admin_id=?) LIMIT 1');
    $st->execute([(int)$taskId, $yo, $yo]);
    return (bool)$st->fetchColumn();
  } catch (Exception $e) { return false; }
}

function alcance_exigir_tarea($taskId) {
  if (alcance_ve_tarea($taskId)) return;
  http_response_code(403);
  if (function_exists('perm_pantalla_denegado')) perm_pantalla_denegado('esa tarea');
  exit;
}

/* ---------- Qué permiso pide cada pantalla ----------
   auth.php lo aplica en require_admin(), así que una página nueva queda
   protegida con solo añadirla aquí. Lo que no esté en la lista no pide permiso
   (pantallas comunes: dashboard, perfil, buscador, notificaciones…). */
function perm_de_pagina($basename) {
  static $m = null;
  if ($m === null) $m = [
    'index.php'=>'ver.clientes','client.php'=>'ver.clientes','edit.php'=>'ver.clientes',
    'duplicate.php'=>'ver.clientes','delete.php'=>'clientes.borrar','conversiones.php'=>'ver.clientes',

    'workspace.php'=>'ver.tareas','tasks.php'=>'ver.tareas','task.php'=>'ver.tareas',
    'listas.php'=>'ver.tareas','proyectos.php'=>'ver.tareas',

    /* Actas (notas internas del equipo): se ve con «Ver Actas», configurable en
       Ajustes › Roles y permisos. Escribir/editar/borrar sigue gateado con
       require_can_edit() dentro de la página. */
    'actas.php'=>'ver.actas',

    'crm.php'=>'ver.crm','crm_dashboard.php'=>'ver.crm','crm_profile.php'=>'ver.crm',
    'crm_import.php'=>'ver.crm','negocio.php'=>'ver.crm','automatizaciones.php'=>'ver.crm',
    'crm_followup_email.php'=>'ver.crm',

    'facturas.php'=>'ver.finanzas','programaciones.php'=>'ver.finanzas','fin-resumen.php'=>'ver.finanzas',
    'fin-ajustes.php'=>'ver.finanzas','fin-horas.php'=>'ver.finanzas','pricing.php'=>'ver.finanzas',
    'contabilidad.php'=>'ver.conta','contabilidad-analisis.php'=>'ver.conta',

    'support.php'=>'ver.soporte','chat.php'=>'ver.chat','ia.php'=>'ver.ia',
    'calendar.php'=>'ver.agenda','reuniones.php'=>'ver.agenda','agendar.php'=>'ver.agenda',

    'proyectos.php'=>'ver.proyectos','proyecto.php'=>'ver.proyectos','fin-horas.php'=>'ver.horas',

    'settings.php'=>'ver.ajustes','servicios.php'=>'ver.ajustes','types.php'=>'ver.ajustes',
    'type-edit.php'=>'ver.ajustes','type-delete.php'=>'ver.ajustes','agencias.php'=>'ver.ajustes',
    'integraciones.php'=>'ver.ajustes','papelera.php'=>'ver.ajustes','metricas.php'=>'ver.ajustes',
    /* La bóveda va aparte de Ajustes: son las contraseñas de los clientes. */
    'credenciales.php'=>'ver.credenciales',

    'team.php'=>'equipo.gestionar','team-edit.php'=>'equipo.gestionar','team-delete.php'=>'equipo.gestionar',
    'permisos.php'=>'roles.gestionar',
    'data.php'=>'datos.avanzado',
  ];
  return $m[$basename] ?? null;
}

/* ---------- Qué permiso pide cada ACCIÓN ----------
   Mismo mecanismo que perm_de_pagina pero para los POST: `archivo` => `action`
   => permiso. Lo aplica require_admin() leyendo $_POST['action'] o
   $_POST['section'], así que una acción queda protegida con solo apuntarla aquí,
   sin tocar la página.

   Solo se listan acciones que EXISTEN en el ERP. Un permiso que no controla nada
   es peor que no tenerlo: hace creer que has quitado algo cuando no. */
function perm_de_accion($basename, $accion) {
  static $m = null;
  if ($m === null) $m = [
    'edit.php'        => ['*'=>'clientes.editar'],
    'duplicate.php'   => ['*'=>'clientes.crear'],
    'delete.php'      => ['*'=>'clientes.borrar'],
    'save-portal.php' => ['*'=>'clientes.portal'],

    'task.php'        => ['del'=>'tareas.borrar'],
    'listas.php'      => ['del'=>'tareas.borrar', 'del_lista'=>'tareas.borrar'],
    'fin-horas.php'   => ['*'=>'tareas.horas'],

    'crm.php'         => ['del'=>'crm.borrar', 'bulk_del'=>'crm.borrar'],
    'negocio.php'     => ['del_deal'=>'crm.borrar', 'ganar'=>'crm.convertir'],

    'facturas.php'    => ['save'=>'finanzas.emitir', 'del'=>'finanzas.borrar', 'estado'=>'finanzas.cobrar', 'pagar'=>'finanzas.cobrar'],
    'programaciones.php' => ['*'=>'finanzas.programar'],
    'contabilidad.php'   => ['*'=>'conta.editar'],

    'servicios.php'   => ['*'=>'servicios.editar'],
    'type-edit.php'   => ['*'=>'tipos.editar'],
    'type-delete.php' => ['*'=>'tipos.editar'],
    'agencias.php'    => ['*'=>'marca.editar'],
    'integraciones.php'=> ['*'=>'integraciones.editar'],
    'papelera.php'    => ['restore'=>'papelera.restaurar', 'purge'=>'papelera.purgar'],

    /* settings.php usa `section=` en vez de `action=`; se resuelve igual. */
    'settings.php'    => ['emisores'=>'finanzas.emisores', 'em_del'=>'finanzas.emisores', '*'=>'ajustes.editar'],
  ];
  $pag = $m[$basename] ?? null;
  if ($pag === null) return null;
  $accion = (string)$accion;
  if ($accion !== '' && isset($pag[$accion])) return $pag[$accion];
  return $pag['*'] ?? null;
}

/* ---------- Guardado ---------- */

/* Clave nueva a partir del nombre: minúscula, sin tildes, ≤30 (el ancho de la
   columna) y nunca repetida, ni siquiera la de un rol borrado. */
function rol_slug($nombre, $usadas = []) {
  $s = (string)$nombre;
  if (function_exists('iconv')) { $t = @iconv('UTF-8','ASCII//TRANSLIT',$s); if ($t !== false) $s = $t; }
  $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/','_',$s));
  $s = trim($s,'_'); if ($s === '') $s = 'rol';
  $s = substr($s,0,30);
  $base = substr($s,0,27); $i = 2; $out = $s;
  while (in_array($out,$usadas,true)) { $out = $base.'_'.$i; $i++; if ($i > 99) { $out = 'rol_'.substr(bin2hex(random_bytes(4)),0,8); break; } }
  return $out;
}

/* Crea o actualiza un rol. Devuelve ['ok'=>bool,'msg'=>string,'clave'=>string]. */
function rol_guardar($clave, $nombre, $descripcion, array $permisos) {
  roles_ensure();
  $nombre = trim($nombre);
  if ($nombre === '') return ['ok'=>false,'msg'=>'El rol necesita un nombre.'];

  /* Solo se guardan permisos que existan: así una clave inventada por un POST
     manipulado no se cuela en la tabla. */
  $permisos = array_values(array_intersect(perm_todas(), $permisos));
  /* Y se completa con lo que haga falta para que el rol tenga sentido: un
     «Borrar tareas» sin «Tareas» deja un permiso que no se puede ejercer. Se
     hace aquí y no solo en la matriz porque el aviso del navegador se puede
     saltar, y la coherencia del rol no puede depender de eso. */
  $permisos = perm_completar($permisos);
  $roles = roles_todos();
  $clave = (string)$clave;

  if ($clave !== '' && isset($roles[$clave])) {
    /* El rol del Dueño no se puede desarmar: si se le quitara el acceso total,
       el ERP se quedaría sin nadie que pueda gestionar roles y no habría forma
       de volver atrás desde la interfaz. */
    if ($clave === 'owner' && !in_array('admin.total',$permisos,true)) $permisos[] = 'admin.total';
    /* Y no puede quedarse sin ningún dueño por quitarle el acceso total al
       último rol que lo tenía. */
    if (!in_array('admin.total',$permisos,true) && in_array('admin.total',$roles[$clave]['permisos'],true)) {
      $otros = 0;
      foreach ($roles as $k=>$r) if ($k!==$clave && in_array('admin.total',$r['permisos'],true)) $otros++;
      if (!$otros) return ['ok'=>false,'msg'=>'No puedes quitar el acceso total al único rol que lo tiene: te quedarías fuera de tu propio ERP.'];
    }
    try {
      db()->prepare('UPDATE roles SET nombre=?, descripcion=?, permisos=? WHERE clave=?')
          ->execute([$nombre, trim($descripcion), json_encode($permisos, JSON_UNESCAPED_UNICODE), $clave]);
    } catch (Exception $e) { return ['ok'=>false,'msg'=>'No se ha podido guardar el rol.']; }
    roles_todos(true);
    return ['ok'=>true,'msg'=>'Rol actualizado.','clave'=>$clave];
  }

  $clave = rol_slug($nombre, array_keys($roles));
  $orden = 10 + count($roles);
  try {
    db()->prepare('INSERT INTO roles (clave,nombre,descripcion,permisos,sistema,orden) VALUES (?,?,?,?,0,?)')
        ->execute([$clave,$nombre,trim($descripcion),json_encode($permisos, JSON_UNESCAPED_UNICODE),$orden]);
  } catch (Exception $e) { return ['ok'=>false,'msg'=>'No se ha podido crear el rol.']; }
  roles_todos(true);
  return ['ok'=>true,'msg'=>'Rol creado.','clave'=>$clave];
}

/* Le pone un rol a una persona. Vive aquí y no en la pantalla que lo usa porque
   la comprobación del último dueño no se puede quedar en una sola pantalla: si
   mañana se cambia el rol desde otro sitio y allí no se comprueba, el ERP se
   queda sin nadie que pueda gestionar roles y no hay vuelta atrás. */
function rol_asignar($uid, $clave) {
  roles_ensure();
  $uid = (int)$uid; $clave = (string)$clave;
  $roles = roles_todos();
  if (!isset($roles[$clave])) return ['ok'=>false,'msg'=>'Ese rol no existe.'];

  $rolAnt = '';
  try { $st = db()->prepare('SELECT role FROM admins WHERE id=?'); $st->execute([$uid]); $rolAnt = (string)$st->fetchColumn(); }
  catch (Exception $e) { return ['ok'=>false,'msg'=>'No se ha podido leer esa persona.']; }
  if ($rolAnt === '' && $uid <= 0) return ['ok'=>false,'msg'=>'Esa persona ya no existe.'];

  $eraDueno  = in_array('admin.total', $roles[$rolAnt]['permisos'] ?? [], true);
  $seraDueno = in_array('admin.total', $roles[$clave]['permisos'], true);
  if ($eraDueno && !$seraDueno && roles_n_duenos() <= 1)
    return ['ok'=>false,'msg'=>'Es la única persona con acceso total. Dale ese rol a alguien más antes de quitárselo.'];

  try { db()->prepare('UPDATE admins SET role=? WHERE id=?')->execute([$clave,$uid]); }
  catch (Exception $e) { return ['ok'=>false,'msg'=>'No se ha podido guardar el rol.']; }

  /* Sube la versión de credenciales. Quitarle un permiso a alguien no puede
     esperar a que caduque su sesión: si le acabas de quitar «datos.avanzado»,
     no debe poder usarlo en la sesión que ya tenía abierta. Al entrar en
     cualquier página se compara la versión y, si no coincide, cae. */
  if ($rolAnt !== $clave && function_exists('credenciales_subir')) credenciales_subir('admins', $uid);
  if (function_exists('audit_log')) audit_log('rol', 'cuenta #' . $uid . ': de "' . $rolAnt . '" a "' . $clave . '"');

  /* Si te lo has cambiado a ti mismo, la pantalla que estás viendo ya no refleja
     lo que puedes hacer: quien llama debe recargar. */
  $yo = function_exists('current_admin') ? (int)(current_admin()['id'] ?? 0) : 0;
  return ['ok'=>true,'msg'=>'Rol actualizado.','recargar'=>($uid === $yo)];
}

/* Borra un rol. No se borran los del sistema ni los que tenga alguien puesto:
   dejar a una persona con un rol que ya no existe la deja sin ningún permiso y
   sin saber por qué. */
function rol_borrar($clave) {
  roles_ensure();
  $clave = (string)$clave;
  $roles = roles_todos();
  if (!isset($roles[$clave]))       return ['ok'=>false,'msg'=>'Ese rol ya no existe.'];
  if ($roles[$clave]['sistema'])    return ['ok'=>false,'msg'=>'Los roles Dueño, Editor y Solo lectura no se pueden borrar. Sí puedes cambiarles los permisos.'];
  $uso = roles_uso();
  if (!empty($uso[$clave]))         return ['ok'=>false,'msg'=>'No se puede borrar: hay '.(int)$uso[$clave].' persona(s) con este rol. Cámbiales el rol antes.'];
  try { db()->prepare('DELETE FROM roles WHERE clave=? AND sistema=0')->execute([$clave]); }
  catch (Exception $e) { return ['ok'=>false,'msg'=>'No se ha podido borrar el rol.']; }
  roles_todos(true);
  return ['ok'=>true,'msg'=>'Rol eliminado.'];
}
