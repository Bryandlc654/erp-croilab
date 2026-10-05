# API v1 del ERP

Un único punto de entrada: `backend/api/index.php`. Apache reescribe `/api/v1/*` hacia él
(`backend/api/.htaccess`); en desarrollo, `php -S localhost:8000 -t backend backend/api/index.php`.

## Convenciones

- Todo responde JSON con `ok`. Éxito: `{"ok": true, ...}`. Error: `{"ok": false, "msg": "texto para el usuario", "error": "codigo"}`.
- Códigos: `400` datos mal formados · `401` sin sesión (`error: "sesion"`) · `403` sin permiso (`error: "permiso"`) ·
  `404` no existe o fuera de tu alcance · `405` método · `409` conflicto · `419` token CSRF (`error: "csrf"`) ·
  `422` validación (`error: "validacion"`, `campo` opcional) · `429` demasiados intentos · `500` interno · `503` base de datos con migraciones pendientes.
- Sesión por cookie (`croilab_portal`, `HttpOnly`, `SameSite` según `COOKIE_SAMESITE`, `Domain` según `COOKIE_DOMAIN`). Las peticiones
  `POST`, `PATCH` y `DELETE` llevan la cabecera `X-CSRF-Token` (de `/auth/csrf`, `/auth/login` o `/me`).

  Dos variables del `.env` del servidor deciden si el login funciona, y fallan por motivos distintos:

  - `COOKIE_SAMESITE` (`Lax` por defecto, `Strict` o `None`). Con `Lax` el navegador manda la cookie
    solo si el front y la API comparten **sitio**: subdominios del mismo dominio
    (`erp.croilab.com` + `api.croilab.com`) o el mismo host con puertos distintos
    (`localhost:5173` + `localhost:8000`). Si el front se sirve desde otro dominio registrable,
    hace falta `None`, que obliga a HTTPS.
  - `COOKIE_DOMAIN` (vacío por defecto). Sin `Domain`, la cookie va atada al host que la emite. Si el
    front y la API son **subdominios distintos**, hace falta `COOKIE_DOMAIN=.croilab.com`: sin él el
    navegador no lleva la cookie de la API al front y el login responde `419` con `error: "csrf"`
    aunque `SameSite` esté bien. No hace falta tocar `COOKIE_SAMESITE` en este caso.

  Si `COOKIE_DOMAIN` no contiene el dominio del host que sirve la API, se ignora (la cookie sale sin
  `Domain`) y se avisa por el log de errores: un `Domain` equivocado hace que el navegador descarte
  la cookie y el login falle sin dar la cara.
- CORS: solo orígenes de `CORS_ORIGINS` (+ `http://localhost:5173`), con credenciales.
  `CORS_ORIGINS` es **obligatorio** cuando el front no está en localhost: si el origen no está en la
  lista, el navegador rechaza el preflight del `POST /auth/login` y el login falla con
  `blocked by CORS policy` sin llegar a la API.
- Paginación: `limit` (por defecto 50, máximo 200 salvo que se indique) y `offset`. La respuesta trae
  `items`, `total`, `limit`, `offset`.
- Fechas `YYYY-MM-DD`. Ids enteros.

## Autenticación

| Método | Ruta | Cuerpo | Respuesta |
|---|---|---|---|
| GET | `/api/v1/auth/csrf` | | `{csrf}` |
| POST | `/api/v1/auth/login` | `{username, password}` | `{csrf, me}` · 401 credenciales · 429 bloqueo |
| POST | `/api/v1/auth/logout` | | `{}` |
| GET | `/api/v1/me` | | `{me, csrf}` |

### Recuperar la contraseña (públicas)

| Método | Ruta | Cuerpo / parámetros | Respuesta |
|---|---|---|---|
| POST | `/api/v1/auth/recuperar` | `{identificador}` (usuario o correo) | `{msg}` — siempre la misma, exista o no la cuenta · 429 demasiadas peticiones · 503 correo sin configurar |
| GET | `/api/v1/auth/restablecer` | `?token=` | `{username}` · 404 si el enlace no vale (caducado, usado o inventado) |
| POST | `/api/v1/auth/restablecer` | `{token, password}` | `{}` · 422 contraseña no válida (el enlace sigue valiendo) · 404 enlace no válido |

El correo lleva `FRONT_URL/restablecer?token=<64 hex>`, vale 60 minutos y una sola vez; pedir otro anula el
anterior. Solo se guarda el SHA-256 del token. Al restablecer sube `cred_ver` (se cierran todas las sesiones de
esa cuenta), se desbloquea el freno de intentos y se manda un aviso de que la contraseña ha cambiado. Los correos
se envían después de responder, para que el tiempo de respuesta no delate qué cuentas existen.

`me` = `{id, username, role, role_nombre, foto, permisos: string[]}`. `foto` es una ruta relativa al backend
(`archivo.php?d=avatars&f=...`) o `null`.

## Navegación y equipo

| Método | Ruta | Respuesta |
|---|---|---|
| GET | `/api/v1/nav` | `{marca, pendientes, no_leidas, clientes: {total, activos, inactivos}}` |
| GET | `/api/v1/equipo` | `{items: Persona[]}` (personas activas; `Persona = {id, username, foto}`) |

## Clientes

| Método | Ruta | Parámetros / cuerpo | Respuesta |
|---|---|---|---|
| GET | `/api/v1/clientes` | `q` (prefijo del nombre), `activo` (`1`/`0`), `con_listas` (`1`), `limit`, `offset` | `{items: Cliente[], total, limit, offset}` |
| GET | `/api/v1/clientes/{id}` | | `{cliente: Cliente}` (siempre con `listas`) |
| POST | `/api/v1/clientes/{id}/informe` | | `{list_id, created}` (crea la lista de informes si falta) |

`Cliente = {id, name, iniciales, activo: boolean, listas?: Lista[]}` · `Lista = {id, nombre, tipo, pend}`
(`tipo`: `tareas` | `informe`; `pend`: tareas sin completar visibles para ti).

Solo devuelve clientes dentro de tu alcance.

## Tareas

| Método | Ruta | Parámetros / cuerpo | Respuesta |
|---|---|---|---|
| GET | `/api/v1/tareas` | `view` = `all`·`mine`·`emp`·`cliente`; `emp` (id persona, con `view=emp`); `cli`, `list` (con `view=cliente`; sin `list` se usa la primera lista); `fe` (estado; vacío = sin completadas en las vistas generales, todas en la de cliente); `fr` (responsable, con `view=all`); `limit` (≤ 500, por defecto 100), `offset` | `{items: Tarea[], total, limit, offset, list_id}` |
| GET | `/api/v1/tareas/{id}` | | `{tarea: TareaDetalle}` |
| POST | `/api/v1/tareas` | `{client_id, list_id, titulo, ...campos}` | `201 {tarea: Tarea}` |
| PATCH | `/api/v1/tareas/{id}` | cualquier subconjunto de los campos editables | `{tarea: Tarea}` |
| DELETE | `/api/v1/tareas/{id}` | | `{papelera_id}` (va a la papelera) |
| POST | `/api/v1/papelera/{id}/restaurar` | | `{id}` |

Orden de `GET /tareas`: nombre del cliente, estado (`en proceso`, `pendiente`, `atemporal`, `completada`), id.

**Campos editables** (`POST`/`PATCH`): `titulo` (no vacío), `descripcion`, `estado`
(`pendiente`·`en proceso`·`atemporal`·`completada`), `prioridad` (0 Ninguna … 4 Urgente), `due_date`,
`fecha_inicio` (`YYYY-MM-DD` o `null`), `responsable_id` (id o `null`; deja a esa persona como única
asignada), `etiquetas`, `mes`, `visible_cliente` (boolean), `titulo_cliente`, `explicacion_cliente`.
En `PATCH` lo que no se envía no cambia.

```
Tarea = {
  id, client_id, list_id, titulo, estado, prioridad, responsable_id, due_date, fecha_inicio,
  visible_cliente: boolean, client_name, client_iniciales, list_name,
  asignados: Persona[]
}
TareaDetalle = Tarea & {
  descripcion, etiquetas, mes, titulo_cliente, explicacion_cliente,
  comentarios: {id, admin_id, username, cuerpo, created_at}[],
  checklist: {id, texto, done: boolean}[],
  adjuntos: {id, nombre, filename}[]
}
```

`descripcion` y `cuerpo` son HTML del editor del ERP: mostrar como texto, nunca inyectar.

## Permisos

- Ver tareas: `ver.tareas`. Crear: `tareas.crear`. Editar: `tareas.editar`. Borrar: `tareas.borrar`.
  Las tres últimas requieren además `general.editar`. Crear la lista de informes: `tareas.crear`.
- Restaurar lo que borró otra persona: `papelera.restaurar` (lo propio se puede deshacer siempre).
- Sin `alcance.todos`, cada persona solo ve las tareas de las que es responsable o asignada y los clientes de esas tareas.
