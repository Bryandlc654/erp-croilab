import { useEffect, useRef, useState, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { ChevronRight, Eye, FileUp, Link2Off, Plug, RefreshCw } from 'lucide-react'
import Breadcrumbs from '../../../shared/ui/Breadcrumbs'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import Field from '../../../shared/ui/Field'
import FormGrid from '../../../shared/ui/FormGrid'
import Notice from '../../../shared/ui/Notice'
import Switch from '../../../shared/ui/Switch'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import AjustesCabecera from '../components/AjustesCabecera'
import { CopiarCaja, ErrorCarga, Esqueleto, Insignia, LogoServicio, SetCard } from '../components/piezas'
import { useReauth } from '../components/useReauth'
import { campoDeError, clavesEquipo, mensaje, pedir, useAccion, useIntegraciones } from '../api'
import { fechaCorta } from '../logica'
import { IntegracionesRespuesta, McpRespuesta, McpUrlRespuesta, TokenRespuesta, UrlRespuesta, VacioSchema, type Integraciones } from '../schemas'

type Clave = 'api' | 'calendar' | 'metricas' | 'mcp'
const CLAVES: Clave[] = ['api', 'calendar', 'metricas', 'mcp']

/* Ajustes › Integraciones (integraciones.php): el hub con las cuatro y el
   detalle de cada una en ?i=. Al volver de Google llega ?google=ok|error. */
export default function IntegracionesPage() {
  const [params, setParams] = useSearchParams()
  const q = useIntegraciones()
  const { aviso } = useToast()
  const i = params.get('i') as Clave | null
  const actual = i && CLAVES.includes(i) ? i : null

  /* Aviso de la vuelta de Google, una sola vez, y se limpia la URL. */
  const google = params.get('google')
  const msgGoogle = params.get('msg')
  useEffect(() => {
    if (!google) return
    if (google === 'ok') aviso('¡Conectado con Google!')
    else aviso(msgGoogle || 'No se ha podido conectar con Google. Inténtalo de nuevo.', { tipo: google === 'cancelado' ? 'plain' : 'error' })
    const p = new URLSearchParams(params)
    p.delete('google')
    p.delete('msg')
    setParams(p, { replace: true })
  }, [google, msgGoogle, aviso, params, setParams])

  return (
    <div className="max-w-[1180px]">
      {actual && <Breadcrumbs items={[{ label: '← Integraciones', to: '/ajustes/integraciones' }]} />}
      {!actual && <AjustesCabecera titulo="Integraciones" sub="Conecta el ERP con tus herramientas. Cada una se configura por separado." />}
      {q.isPending ? <Esqueleto filas={4} /> : q.isError ? <ErrorCarga error={q.error} /> : actual ? <Detalle k={actual} d={q.data} /> : <Hub d={q.data} />}
    </div>
  )
}

const INFO: Record<Clave, { titulo: string; logo: ReactNode; desc: string }> = {
  api: { titulo: 'n8n / API', logo: <LogoServicio k="n8n" />, desc: 'Vuelca métricas, tareas o informes a n8n u otras herramientas con un token.' },
  calendar: { titulo: 'Google Calendar', logo: <LogoServicio k="gcal" />, desc: 'Ver tus reuniones de Google y crear/editar eventos desde el calendario del ERP.' },
  metricas: { titulo: 'Google · Métricas', logo: <LogoServicio k="google" />, desc: 'Lee de Google las visitas, apariciones (Search Console) y conversiones (Analytics) para el portal de cada cliente.' },
  mcp: { titulo: 'MCP · Claude', logo: <LogoServicio k="claude" />, desc: 'Conecta Claude para que gestione tus tareas: crear, etiquetar, cambiar estado y comentar.' },
}

function estado(k: Clave, d: Integraciones): ReactNode {
  if (k === 'api') return d.api.activa ? <Insignia tono="ok">Activa</Insignia> : <Insignia tono="off">Sin token</Insignia>
  if (k === 'calendar') return d.calendar.conectado ? <Insignia tono="ok">Conectado</Insignia> : d.calendar.configurado ? <Insignia tono="warn">Configurado</Insignia> : <Insignia tono="off">Sin configurar</Insignia>
  if (k === 'metricas') return d.metricas.conectado && !d.metricas.revocado ? <Insignia tono="ok">Conectado</Insignia> : d.metricas.configurado ? <Insignia tono="warn">Falta autorizar</Insignia> : <Insignia tono="off">Sin conectar</Insignia>
  return d.mcp.activo ? <Insignia tono="ok">Activo</Insignia> : <Insignia tono="off">Desactivado</Insignia>
}

function Hub({ d }: { d: Integraciones }) {
  return (
    <div className="space-y-4">
      {CLAVES.map((k) => (
        <Link
          key={k}
          to={`/ajustes/integraciones?i=${k}`}
          className="flex items-center gap-4 rounded-2xl border border-line bg-card px-[22px] py-5 transition-[transform,border-color,box-shadow] duration-150 hover:-translate-y-0.5 hover:border-line-strong hover:shadow-card-hover max-sm:px-4"
        >
          <span className="flex size-[52px] shrink-0 items-center justify-center rounded-[14px] border border-line bg-white">{INFO[k].logo}</span>
          <span className="min-w-0 flex-1">
            <span className="flex flex-wrap items-center gap-2.5 text-[16px] font-semibold text-ink-strong">
              {INFO[k].titulo}
              {estado(k, d)}
            </span>
            <span className="mt-1 block text-[13.5px] text-muted">{INFO[k].desc}</span>
          </span>
          <ChevronRight className="size-5 shrink-0 text-label" aria-hidden="true" />
        </Link>
      ))}
    </div>
  )
}

function Detalle({ k, d }: { k: Clave; d: Integraciones }) {
  return (
    <>
      <header className="mt-3 mb-6 flex items-center gap-4">
        <span className="flex size-[56px] items-center justify-center rounded-[15px] border border-line bg-white">{INFO[k].logo}</span>
        <div>
          <h1 className="flex flex-wrap items-center gap-2.5 text-[24px] font-semibold tracking-[-.4px] text-ink-strong">
            {INFO[k].titulo}
            {estado(k, d)}
          </h1>
          <p className="mt-1 text-[13.5px] text-muted">{INFO[k].desc}</p>
        </div>
      </header>
      {!d.puede_editar && (
        <Notice tone="info" className="mb-4">
          Para cambiar las integraciones hace falta el permiso «Integraciones».
        </Notice>
      )}
      {k === 'api' && <Api d={d} />}
      {k === 'calendar' && <Calendar d={d} />}
      {k === 'metricas' && <Metricas d={d} />}
      {k === 'mcp' && <Mcp d={d} />}
    </>
  )
}

/* ---------- n8n / API ---------- */

function Api({ d }: { d: Integraciones }) {
  const [token, setToken] = useState<string | null>(null)
  const { conReauth, dialogo } = useReauth()
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const regenerar = useAccion(() => pedir('/api/v1/integraciones/api/token', { method: 'POST', schema: TokenRespuesta }), [clavesEquipo.integraciones])
  async function ver() {
    try {
      const r = await conReauth(() => pedir('/api/v1/integraciones/api/token', { schema: TokenRespuesta }))
      if (r) setToken(r.token)
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }
  return (
    <SetCard
      titulo="Token de API"
      sub={
        <>
          Manda la cabecera <code className="font-mono">X-API-Token</code> al llamar a <code className="font-mono">/api.php</code> (n8n u otra herramienta). Es como una contraseña: si se filtra, regenéralo.
        </>
      }
    >
      {token ? (
        <CopiarCaja valor={token} etiqueta="Copiar token" />
      ) : (
        <div className="flex flex-wrap items-center gap-3 rounded-[10px] border border-line bg-soft px-3 py-2.5">
          <code className="flex-1 font-mono text-[13px] text-muted">{d.api.activa ? '•••••••••••••••••••••••••••••' : 'Sin token'}</code>
          {d.api.activa && d.puede_editar && (
            <Button size="sm" variant="ghost" icon={<Eye />} onClick={() => void ver()}>
              Ver token
            </Button>
          )}
        </div>
      )}
      {d.puede_editar && (
        <Button
          className="mt-4"
          variant="ghost"
          icon={<RefreshCw />}
          loading={regenerar.isPending}
          loadingText="Generando…"
          onClick={async () => {
            if (!(await confirm({ title: '¿Regenerar el token?', message: 'El anterior dejará de funcionar.', okLabel: 'Regenerar', danger: true }))) return
            regenerar.mutate(undefined, {
              onSuccess: (r) => {
                setToken(r.token)
                aviso('Nuevo token generado.')
              },
              onError: (e) => aviso(mensaje(e), { tipo: 'error' }),
            })
          }}
        >
          Regenerar token
        </Button>
      )}
      {dialogo}
    </SetCard>
  )
}

/* ---------- Credenciales de un proyecto de Google ---------- */

function Credenciales({ proyecto, d, conJson = false }: { proyecto: 'calendar' | 'metricas'; d: Integraciones; conJson?: boolean }) {
  const c = d[proyecto]
  const [id, setId] = useState(c.client_id)
  const [secreto, setSecreto] = useState('')
  const [err, setErr] = useState<{ campo: string | null; msg: string } | null>(null)
  const archivo = useRef<HTMLInputElement>(null)
  const { aviso } = useToast()
  const ruta = proyecto === 'calendar' ? '/api/v1/integraciones/google-calendar/credenciales' : '/api/v1/integraciones/metricas/credenciales'
  const guardar = useAccion((b: { client_id: string; client_secret: string }) => pedir(ruta, { method: 'PATCH', body: b, schema: IntegracionesRespuesta }), [clavesEquipo.integraciones])
  const subir = useAccion((fd: FormData) => pedir(ruta, { method: 'POST', form: fd, schema: IntegracionesRespuesta }), [clavesEquipo.integraciones])
  const e = (k: string) => (err?.campo === k ? err.msg : undefined)

  return (
    <form
      onSubmit={(ev) => {
        ev.preventDefault()
        setErr(null)
        guardar.mutate(
          { client_id: id, client_secret: secreto },
          {
            onSuccess: () => {
              setSecreto('')
              aviso('Credenciales guardadas.')
            },
            onError: (er) => setErr({ campo: campoDeError(er), msg: mensaje(er) }),
          },
        )
      }}
    >
      {conJson && (
        <div className="mb-4 flex flex-wrap items-center gap-3">
          <input
            ref={archivo}
            type="file"
            accept="application/json,.json"
            hidden
            onChange={(ev) => {
              const f = ev.target.files?.[0]
              if (!f) return
              const fd = new FormData()
              fd.append('archivo', f)
              subir.mutate(fd, { onSuccess: () => aviso('Credenciales guardadas.'), onError: (er) => aviso(mensaje(er), { tipo: 'error' }) })
              ev.target.value = ''
            }}
          />
          <Button variant="ghost" icon={<FileUp />} onClick={() => archivo.current?.click()} loading={subir.isPending} loadingText="Subiendo…">
            Subir el .json de Google
          </Button>
          <span className="text-[12.5px] text-muted">…o pega los dos códigos a mano:</span>
        </div>
      )}
      <fieldset disabled={!d.puede_editar} className="min-w-0">
        <FormGrid>
          <Field label="Client ID" span={6} error={e('client_id')}>
            <TextInput value={id} onChange={(x) => setId(x.target.value)} placeholder="123…apps.googleusercontent.com" autoComplete="off" />
          </Field>
          <Field label="Client Secret" span={6} error={e('client_secret')} hint={c.secreto_ilegible ? 'Hay uno guardado que no se puede leer (falta la clave de la bóveda): pon el secreto otra vez.' : c.secreto_guardado ? 'Guardado y cifrado. Escribe uno nuevo solo si quieres cambiarlo.' : 'Se guarda cifrado y no se vuelve a enseñar.'}>
            <TextInput type="password" value={secreto} onChange={(x) => setSecreto(x.target.value)} placeholder={c.secreto_guardado ? '(ya guardado)' : 'GOCSPX-…'} autoComplete="new-password" />
          </Field>
          <Field label="URI de redirección autorizada (cópiala en Google Cloud, tal cual)" span={12}>
            {d.redirect_uri ? <CopiarCaja valor={d.redirect_uri} /> : <Notice tone="warn">Falta APP_URL en la configuración del servidor: sin ella no hay dirección de vuelta.</Notice>}
          </Field>
        </FormGrid>
      </fieldset>
      {err && !err.campo && <p className="mt-3 text-[12.5px] text-[#ef4444]">{err.msg}</p>}
      {d.puede_editar && (
        <Button className="mt-4" type="submit" variant="ghost" loading={guardar.isPending} loadingText="Guardando…">
          Guardar credenciales
        </Button>
      )}
    </form>
  )
}

function Pasos({ pasos }: { pasos: ReactNode[] }) {
  return (
    <ol className="space-y-2.5">
      {pasos.map((p, i) => (
        <li key={i} className="flex gap-3 text-[13px] leading-[1.55] text-ink">
          <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-soft text-[11.5px] font-bold text-muted">{i + 1}</span>
          <span>{p}</span>
        </li>
      ))}
    </ol>
  )
}

async function irAGoogle(ruta: string, aviso: (m: string, o?: { tipo?: 'error' }) => void) {
  try {
    const r = await pedir(ruta, { schema: UrlRespuesta })
    window.location.assign(r.url)
  } catch (e) {
    aviso(mensaje(e), { tipo: 'error' })
  }
}

/* ---------- Google Calendar ---------- */

function Calendar({ d }: { d: Integraciones }) {
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const desconectar = useAccion(() => pedir('/api/v1/integraciones/google-calendar/conexion', { method: 'DELETE', schema: VacioSchema }), [clavesEquipo.integraciones])
  const c = d.calendar
  return (
    <>
      <SetCard titulo="Tu cuenta de Google" sub="Cada persona conecta su propio Google Calendar. Aquí ves y cambias la tuya.">
        {c.revocado && (
          <Notice tone="warn" className="mb-3">
            Tu conexión con Google ha caducado. Vuelve a conectarla.
          </Notice>
        )}
        <div className="flex flex-wrap items-center gap-3">
          <p className="flex-1 text-[13.5px] text-ink">{c.conectado ? <>Conectado{c.email ? <>: <b>{c.email}</b></> : null}</> : c.configurado ? 'Todavía no has conectado tu calendario.' : 'Primero hay que poner las credenciales del proyecto (abajo).'}</p>
          {c.configurado && (
            <Button icon={<Plug />} onClick={() => void irAGoogle(`/api/v1/integraciones/google-calendar/conectar?volver=${encodeURIComponent('/ajustes/integraciones?i=calendar')}`, aviso)}>
              {c.conectado ? 'Reconectar' : 'Conectar Google Calendar'}
            </Button>
          )}
          {c.conectado && (
            <Button
              variant="ghost"
              icon={<Link2Off />}
              onClick={async () => {
                if (!(await confirm({ title: '¿Desconectar Google Calendar?', message: 'Dejarás de ver tus eventos de Google en el ERP.', okLabel: 'Desconectar', danger: true }))) return
                desconectar.mutate(undefined, { onSuccess: () => aviso('Google Calendar desconectado') })
              }}
            >
              Desconectar
            </Button>
          )}
        </div>
        <p className="mt-3 text-[12px] text-label">{c.cuentas} persona(s) del equipo con su calendario conectado.</p>
      </SetCard>
      <SetCard titulo="Credenciales del proyecto" sub="Cada usuario conecta su propia cuenta desde el Calendario; aquí solo van las credenciales del proyecto (una vez). Sirven también para «Entrar con Google».">
        <Credenciales proyecto="calendar" d={d} />
      </SetCard>
      <SetCard titulo="Cómo se consiguen">
        <Pasos
          pasos={[
            'En Google Cloud Console, crea un proyecto (o usa uno) y habilita «Google Calendar API».',
            'En «Pantalla de consentimiento de OAuth» elige «Externo» y añade como usuarios de prueba los correos del equipo.',
            'En «Credenciales» crea un «ID de cliente de OAuth» de tipo «Aplicación web».',
            'Pega la URI de redirección de arriba en «URIs de redirección autorizados».',
            'Copia el Client ID y el Client Secret aquí y guarda.',
          ]}
        />
      </SetCard>
    </>
  )
}

/* ---------- Google · Métricas ---------- */

function Metricas({ d }: { d: Integraciones }) {
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const desconectar = useAccion(() => pedir('/api/v1/integraciones/metricas/conexion', { method: 'DELETE', schema: VacioSchema }), [clavesEquipo.integraciones, clavesEquipo.metricas])
  const m = d.metricas
  const conectado = m.conectado && !m.revocado
  return (
    <>
      <SetCard titulo="Estado">
        {m.revocado && (
          <Notice tone="warn" className="mb-3">
            Google ha retirado el permiso. Vuelve a conectar.
          </Notice>
        )}
        <div className="flex flex-wrap items-center gap-3">
          <p className="flex-1 text-[13.5px] text-ink">
            {conectado ? (
              <>
                Conectado con Google{m.email ? <> como <b>{m.email}</b></> : null}.{m.ultima_sync && <span className="text-muted"> Última actualización: {fechaCorta(m.ultima_sync)}.</span>}
              </>
            ) : m.configurado ? (
              'Las credenciales están puestas: falta autorizar el acceso.'
            ) : (
              'Conectar con Google se hace una sola vez: pon las credenciales y pulsa «Conectar con Google».'
            )}
          </p>
          {conectado && (
            <Button variant="ghost" to="/ajustes/portal/metricas">
              Actualizar ahora
            </Button>
          )}
          {m.configurado && d.puede_editar && (
            <Button icon={<Plug />} onClick={() => void irAGoogle('/api/v1/integraciones/metricas/conectar', aviso)}>
              {conectado ? 'Reconectar' : 'Conectar con Google'}
            </Button>
          )}
          {m.conectado && d.puede_editar && (
            <Button
              variant="ghost"
              icon={<Link2Off />}
              onClick={async () => {
                if (!(await confirm({ title: '¿Desconectar Google?', message: 'Las métricas de los clientes dejarán de actualizarse. Las credenciales se conservan.', okLabel: 'Desconectar', danger: true }))) return
                desconectar.mutate(undefined, { onSuccess: () => aviso('Se ha desconectado Google.') })
              }}
            >
              Desconectar
            </Button>
          )}
        </div>
      </SetCard>
      <SetCard titulo="Credenciales del proyecto" sub="Un «ID de cliente de OAuth» de Google (aplicación web). Puede ser el mismo proyecto que el del calendario.">
        <Credenciales proyecto="metricas" d={d} conJson />
      </SetCard>
      <SetCard titulo="Cómo se consiguen">
        <Pasos
          pasos={[
            'En Google Cloud Console, habilita «Google Search Console API» y «Google Analytics Data API».',
            'Crea (o reutiliza) un «ID de cliente de OAuth» de tipo «Aplicación web» y añade la URI de redirección de arriba.',
            'Descarga el .json y súbelo aquí, o pega el ID y el secreto.',
            'Pulsa «Conectar con Google» con la cuenta que tiene acceso a Search Console y Analytics de los clientes.',
          ]}
        />
      </SetCard>
    </>
  )
}

/* ---------- MCP ---------- */

function Mcp({ d }: { d: Integraciones }) {
  const [url, setUrl] = useState<string | null>(null)
  const { conReauth, dialogo } = useReauth()
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const activar = useAccion((enabled: boolean) => pedir('/api/v1/integraciones/mcp', { method: 'PATCH', body: { enabled }, schema: McpRespuesta }), [clavesEquipo.integraciones])
  const regenerar = useAccion(() => pedir('/api/v1/integraciones/mcp/token', { method: 'POST', schema: UrlRespuesta }), [clavesEquipo.integraciones])

  async function ver() {
    try {
      const r = await conReauth(() => pedir('/api/v1/integraciones/mcp/url', { schema: McpUrlRespuesta }))
      if (r?.url) setUrl(r.url)
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }

  return (
    <>
      <SetCard
        titulo="Conectar Claude al ERP"
        sub={
          <>
            Claude podrá listar, crear, cambiar estado/prioridad/fecha, etiquetar y comentar tareas, y consultar clientes y equipo. <b className="text-ink">Solo tareas</b>: no toca facturación, credenciales ni ajustes, y no borra nada.
          </>
        }
      >
        <Switch
          rowVariant="box"
          checked={d.mcp.activo}
          disabled={!d.puede_editar}
          saving={activar.isPending}
          onChange={(v) =>
            activar.mutate(v, {
              onSuccess: (r) => {
                if (r.url) setUrl(r.url)
                aviso(v ? 'Servidor MCP activado' : 'Servidor MCP desactivado')
              },
              onError: (e) => aviso(mensaje(e), { tipo: 'error' }),
            })
          }
          label="Activar el servidor MCP"
          description="Mientras esté apagado, nadie puede usar la URL aunque la tenga."
        />
      </SetCard>
      {d.mcp.activo && (
        <SetCard titulo="Conéctalo en Claude" sub="En Claude: Ajustes → Conectores → Añadir conector personalizado. Nombre: «Croilab ERP». URL: la de abajo.">
          {url ? (
            <CopiarCaja valor={url} etiqueta="Copiar URL" />
          ) : (
            d.puede_editar && (
              <Button variant="ghost" icon={<Eye />} onClick={() => void ver()}>
                Ver la URL del conector
              </Button>
            )
          )}
          <Notice tone="error" className="mt-4">
            Esa URL con el token es como una contraseña. Si se filtra, pulsa «Regenerar token».
          </Notice>
          {d.puede_editar && (
            <Button
              className="mt-4"
              variant="ghost"
              icon={<RefreshCw />}
              onClick={async () => {
                if (!(await confirm({ title: '¿Regenerar el token?', message: 'La URL anterior dejará de funcionar y habrá que cambiarla en Claude.', okLabel: 'Regenerar', danger: true }))) return
                regenerar.mutate(undefined, {
                  onSuccess: (r) => {
                    setUrl(r.url)
                    aviso('Token regenerado.')
                  },
                })
              }}
            >
              Regenerar token
            </Button>
          )}
        </SetCard>
      )}
      {dialogo}
      <Card padding="sm" className="text-[12.5px] text-muted">
        El servidor MCP lo atiende el módulo del asistente: si no responde todavía, la URL queda lista para cuando esté.
      </Card>
    </>
  )
}
