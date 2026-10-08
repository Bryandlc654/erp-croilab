# Rutas y reparto de la migración

Contrato común para todos los módulos. Las rutas del front son relativas a la base del router
(`VITE_BASE_PATH`). Las notificaciones guardan estas rutas en `notifications.url`; las antiguas
(`task.php?id=…`, `facturas.php?v=…`) las traduce el front con `rutaDesdeLegado()`.

## Front (React)

| Ruta | Pantalla | Módulo |
|---|---|---|
| `/inicio` | Dashboard | Trabajo |
| `/notificaciones` | Avisos (bandejas) | Trabajo |
| `/buscar?q=` | Resultados de búsqueda (y la paleta Ctrl+K) | Trabajo |
| `/tareas` | Tablero (ya existe) | Trabajo |
| `/tareas/:id` | Ficha completa de la tarea (`#c<id>` comentario, `#chk` checklist) | Trabajo |
| `/actas`, `/actas/:id` | Actas | Trabajo |
| `/clientes` (`?f=alta|baja|todos`) | Listado | Clientes |
| `/clientes/nuevo`, `/clientes/:id/editar` | Alta y edición (6 secciones) | Clientes |
| `/clientes/:id` | Ficha / hub del cliente | Clientes |
| `/clientes/tipos` | Tipos de cliente | Clientes |
| `/clientes/servicios` | Catálogo de servicios | Clientes |
| `/clientes/agencias` | Agencias colaboradoras (marca blanca) | Clientes |
| `/clientes/:id/metricas` | Conexión Google por cliente | Clientes |
| `/crm` | Contactos | CRM |
| `/crm/contactos/:id` | Ficha del contacto | CRM |
| `/crm/negocio` | Embudo (kanban) | CRM |
| `/crm/dashboard`, `/crm/reporting` | Dashboard y seguimientos | CRM |
| `/crm/listas/:id`, `/crm/importar` | Listas e importación CSV | CRM |
| `/finanzas` | Resumen mensual | Finanzas |
| `/finanzas/facturas`, `/finanzas/facturas/nueva`, `/finanzas/facturas/:id` | Facturas | Finanzas |
| `/finanzas/clientes` | Facturas por cliente | Finanzas |
| `/finanzas/programaciones` | Recurrentes | Finanzas |
| `/finanzas/contabilidad`, `/finanzas/contabilidad/analisis` | Contabilidad | Finanzas |
| `/finanzas/horas`, `/finanzas/proyectos`, `/finanzas/precios` | Horas, proyectos y calculadora de precios | Finanzas |
| `/reuniones`, `/calendario` | Reuniones y calendario (Google) | Comunicación |
| `/chat`, `/chat/:sala` | Chat del equipo | Comunicación |
| `/soporte`, `/soporte/:id` | Tickets | Comunicación |
| `/asistente` | Asistente (IA) | Comunicación |
| `/ajustes` | Agencia (datos y marca) | Equipo |
| `/ajustes/facturacion` | Emisores y series | Finanzas |
| `/ajustes/equipo`, `/ajustes/equipo/:id` | Equipo e invitaciones | Equipo |
| `/ajustes/roles` | Roles y permisos | Equipo |
| `/ajustes/boveda` | Bóveda de credenciales de clientes | Equipo |
| `/ajustes/portal/contacto`, `/ajustes/portal/videos`, `/ajustes/portal/metricas` | Ajustes del portal | Equipo |
| `/ajustes/integraciones` | Google y API | Equipo |
| `/ajustes/papelera` | Papelera | Trabajo |
| `/perfil`, `/perfil/:id` | Mi cuenta y perfiles | Equipo |
| `/registro?t=` | Alta por invitación (pública) | Equipo |
| `/portal/*` | Portal del cliente (otra sesión: clientes) | Portal |

## Backend

- Rutas en `backend/api/rutas/<modulo>.php` (una por módulo).
- Código en `backend/src/Modulos/<Modulo>/` (Controller → Servicio → Repositorio).
- Migraciones por tramos para no chocar: Clientes `0010–0019`, Trabajo `0020–0029`, CRM `0030–0039`,
  Finanzas `0040–0049`, Equipo `0050–0059`, Comunicación `0060–0069`, Portal `0070–0079`.
  Las tablas compartidas del legado ya están en `0006_tablas_legado.php`.
- Notificaciones: `backend/admin/lib/notificaciones.php` (`notif_add()` y los avisos de cada hecho).
- Front: cada módulo en `front/src/features/<modulo>/`; registra sus rutas en `front/src/app/App.tsx`
  y su barra lateral en `front/src/app/navegacion.tsx`.
