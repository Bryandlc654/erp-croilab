<?php
/* Comunicación: chat del equipo (y presencia), soporte, calendario, reuniones
   y el servidor MCP. Documentado en docs/migracion/api/comunicacion.md. */

use Croilab\Google\GoogleOAuth;
use Croilab\Http\Contenedor;
use Croilab\Http\Router;
use Croilab\Modulos\Comunicacion\Calendario\CalendarioController;
use Croilab\Modulos\Comunicacion\Calendario\CalendarioServicio;
use Croilab\Modulos\Comunicacion\Calendario\GoogleFalso;
use Croilab\Modulos\Comunicacion\Calendario\GoogleCalendario;
use Croilab\Modulos\Comunicacion\Chat\Adjuntos;
use Croilab\Modulos\Comunicacion\Chat\ChatController;
use Croilab\Modulos\Comunicacion\Chat\ChatRepositorio;
use Croilab\Modulos\Comunicacion\Chat\ChatServicio;
use Croilab\Modulos\Comunicacion\Chat\Presencia;
use Croilab\Modulos\Comunicacion\Mcp\McpController;
use Croilab\Modulos\Comunicacion\Mcp\McpServidor;
use Croilab\Modulos\Comunicacion\Reuniones\ReunionesController;
use Croilab\Modulos\Comunicacion\Reuniones\ReunionesServicio;
use Croilab\Modulos\Comunicacion\Soporte\SoporteController;
use Croilab\Modulos\Comunicacion\Soporte\SoporteRepositorio;
use Croilab\Modulos\Comunicacion\Soporte\SoporteServicio;
use Croilab\Modulos\Tareas\TareasRepositorio;
use Croilab\Modulos\Tareas\TareasServicio;

return function (Router $r, Contenedor $c): void {
    /* ---------- Chat ---------- */
    $chat = new ChatController($c->unico('comunicacion.chat', fn(Contenedor $c) => new ChatServicio(
        new ChatRepositorio($c->pdo), $c->equipo(), new Presencia($c->pdo), Adjuntos::porDefecto()
    )));
    $r->get('/v1/chat/salas', [$chat, 'salas']);
    $r->post('/v1/chat/salas', [$chat, 'crear']);
    $r->get('/v1/chat/salas/{id}', [$chat, 'sala']);
    $r->patch('/v1/chat/salas/{id}', [$chat, 'renombrar']);
    $r->post('/v1/chat/salas/{id}/miembros', [$chat, 'agregarMiembro']);
    $r->delete('/v1/chat/salas/{id}/miembros/{admin}', [$chat, 'quitarMiembro']);
    $r->get('/v1/chat/salas/{id}/mensajes', [$chat, 'historial']);
    $r->post('/v1/chat/salas/{id}/mensajes', [$chat, 'enviar']);
    $r->get('/v1/chat/salas/{id}/novedades', [$chat, 'novedades']);
    $r->post('/v1/chat/salas/{id}/escribiendo', [$chat, 'escribiendo']);
    $r->post('/v1/chat/salas/{id}/leido', [$chat, 'leido']);
    $r->patch('/v1/chat/mensajes/{id}', [$chat, 'editar']);
    $r->delete('/v1/chat/mensajes/{id}', [$chat, 'borrar']);
    $r->post('/v1/chat/mensajes/{id}/reacciones', [$chat, 'reaccionar']);
    $r->get('/v1/chat/adjuntos/{id}/{indice}', [$chat, 'adjunto']);
    $r->get('/v1/chat/avisos', [$chat, 'avisos']);
    $r->get('/v1/presencia', [$chat, 'presencia']);

    /* ---------- Soporte ---------- */
    $soporte = new SoporteController(new SoporteServicio(new SoporteRepositorio($c->pdo), $c->equipo()));
    $r->get('/v1/soporte/tickets', [$soporte, 'listar']);
    $r->post('/v1/soporte/tickets', [$soporte, 'crear']);
    $r->get('/v1/soporte/tickets/{id}', [$soporte, 'ver']);
    $r->patch('/v1/soporte/tickets/{id}', [$soporte, 'actualizar']);
    $r->delete('/v1/soporte/tickets/{id}', [$soporte, 'borrar']);
    $r->post('/v1/soporte/tickets/{id}/respuestas', [$soporte, 'responder']);
    $r->post('/v1/soporte/papelera/{id}/restaurar', [$soporte, 'restaurar']);
    $r->get('/v1/soporte/clientes', [$soporte, 'clientes']);

    /* ---------- Calendario y reuniones (Google Calendar de cada persona) ---------- */
    $gcal = $c->unico('comunicacion.gcal', function (Contenedor $c) {
        /* Solo en desarrollo, y si se pide: un Google de mentira (fichero JSON) para probar sin credenciales. */
        $falso = defined('APP_ENV') && APP_ENV === 'dev' ? (string)getenv('COMUNICACION_GOOGLE_FALSO') : '';
        $google = $falso !== '' ? new GoogleOAuth(new GoogleFalso($falso)) : $c->unico('google', fn() => new GoogleOAuth());
        return new GoogleCalendario($google);
    });
    $cal = new CalendarioController(new CalendarioServicio($c->pdo, $gcal, $c->equipo()));
    $r->get('/v1/calendario', [$cal, 'datos']);
    $r->post('/v1/calendario/eventos', [$cal, 'crear']);
    $r->patch('/v1/calendario/eventos', [$cal, 'actualizar']);
    $r->delete('/v1/calendario/eventos', [$cal, 'borrar']);
    $r->get('/v1/calendario/correos', [$cal, 'correos']);

    $reu = new ReunionesController(new ReunionesServicio($c->pdo, $gcal, $c->equipo()));
    $r->get('/v1/reuniones', [$reu, 'listar']);
    $r->post('/v1/reuniones', [$reu, 'agendar']);
    $r->patch('/v1/reuniones/contacto', [$reu, 'asignar']);
    $r->get('/v1/reuniones/contactos', [$reu, 'contactos']);
    $r->get('/v1/reuniones/destinatario', [$reu, 'destinatario']);
    $r->get('/v1/reuniones/solicitudes', [$reu, 'solicitudes']);
    $r->post('/v1/reuniones/solicitudes/{id}/aprobar', [$reu, 'aprobar']);
    $r->post('/v1/reuniones/solicitudes/{id}/rechazar', [$reu, 'rechazar']);

    /* ---------- Servidor MCP (token, sin sesión) ----------
       OJO: el Kernel exige CSRF a todo POST, también a las rutas públicas; hasta
       que exima esta ruta (ver el informe del módulo), Claude recibe un 419. */
    /* El servicio de Tareas es el que registra api/rutas/tareas.php (se carga
       después, pero esto solo se pide al atender una llamada). */
    $mcp = new McpController(new McpServidor($c->pdo, fn() => $c->unico('tareas.servicio',
        fn(Contenedor $c) => new TareasServicio(new TareasRepositorio($c->pdo, $c->equipo()), $c->clientes(), $c->equipo()))));
    $r->postConToken('/v1/mcp', [$mcp, 'atender']);
    $r->get('/v1/mcp', [$mcp, 'sinFlujo'], true);
};
