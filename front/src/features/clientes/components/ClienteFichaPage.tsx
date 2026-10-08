import { useState, type ReactNode } from 'react'
import { Link, useLocation, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import {
  ArrowLeft,
  BarChart3,
  CalendarPlus,
  Check,
  CheckSquare,
  Copy,
  Database,
  Euro,
  Eye,
  EyeOff,
  PencilLine,
  FileText,
  Inbox,
  KeyRound,
  LifeBuoy,
  List,
  Pencil,
  Plus,
  Receipt,
  Ticket,
  TrendingUp,
  UserRound,
  Video,
  Zap,
} from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import Card, { CardHeader } from '../../../shared/ui/Card'
import DangerZone from '../../../shared/ui/DangerZone'
import EmptyState from '../../../shared/ui/EmptyState'
import IconButton from '../../../shared/ui/IconButton'
import KpiTile, { KpiGrid } from '../../../shared/ui/KpiTile'
import ListRow from '../../../shared/ui/ListRow'
import Notice from '../../../shared/ui/Notice'
import QuickActionCard from '../../../shared/ui/QuickActionCard'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { ApiError } from '../../../shared/api/client'
import { fechaCorta } from '../../../shared/lib/formato'
import { ESTADOS_FACTURA, ESTADOS_TICKET } from '../../../shared/lib/paletas'
import { mensajeError, pedirSecreto, useBorrarCliente, useFicha, useRestablecerPassword } from '../api'
import { CATEGORIAS_CREDENCIAL, importe, PRIORIDADES_TICKET } from '../lib/etiquetas'
import type { Ficha } from '../schemas'
import { PildoraEstado } from './Etiqueta'
import { usePermisosClientes, type PermisosClientes } from './permisos'

/* Ficha / hub del cliente (client.php): cabecera, accesos rápidos, resumen y
   dos columnas de tarjetas con lo de los demás módulos. */
export default function ClienteFichaPage() {
  const id = Number(useParams().id)
  const { data: f, isLoading, error } = useFicha(Number.isInteger(id) ? id : 0)
  const [params] = useSearchParams()
  const location = useLocation()
  const p = usePermisosClientes()
  const passDuplicado = (location.state as { password?: string } | null)?.password

  if (!Number.isInteger(id) || id <= 0 || (error instanceof ApiError && error.status === 404)) {
    return (
      <EmptyState
        icon={<UserRound />}
        title="No encuentro ese cliente"
        text="El enlace no lleva a ningún cliente válido, o no tienes acceso a él."
        actions={<Button to="/clientes">Ver mis clientes</Button>}
      />
    )
  }
  if (error) return <Notice tone="error">{mensajeError(error, 'No se ha podido cargar la ficha.')}</Notice>
  if (isLoading || !f) return <p className="py-[60px] text-center text-[13.5px] text-muted">Cargando…</p>

  const c = f.cliente
  return (
    <div className="max-w-[1280px]">
      <Cabecera f={f} p={p} />

      {params.get('dup') === '1' && (
        <Notice tone="ok" title="Copia creada.">
          Cámbiale el <b>usuario</b> y la <b>contraseña</b> entrando en «Editar ficha».
          {passDuplicado && (
            <>
              {' '}
              Su contraseña del portal es <code className="rounded bg-white/60 px-1.5 py-0.5 font-mono text-[13px] font-bold dark:bg-black/30">{passDuplicado}</code>: apúntala, no se vuelve a mostrar.
            </>
          )}
        </Notice>
      )}

      <AccionesRapidas f={f} p={p} />

      <KpiGrid>
        <KpiTile
          label={`Oportunidades${f.resumen.oportunidades ? ` · ${f.resumen.oportunidades.mes}` : ''}`}
          icon={<TrendingUp />}
          value={f.resumen.oportunidades ? f.resumen.oportunidades.valor : '—'}
          sub="Llamadas, WhatsApp y formularios"
        />
        <KpiTile label="Tareas en curso" icon={<CheckSquare />} value={f.resumen.tareas_en_curso} sub="Lo que ve en su progreso" />
        <KpiTile
          label="Soporte abierto"
          icon={<LifeBuoy />}
          value={<span style={f.resumen.soporte_abierto > 0 ? { color: '#3b82f6' } : undefined}>{f.resumen.soporte_abierto}</span>}
          sub={f.resumen.soporte_abierto === 1 ? 'ticket sin cerrar' : 'tickets sin cerrar'}
        />
        <KpiTile label="Cobrado" icon={<Euro />} value={importe(f.resumen.cobrado)} sub="Facturas pagadas" />
      </KpiGrid>

      <div className="grid grid-cols-2 items-stretch gap-[18px] max-[1024px]:grid-cols-1">
        <div className="flex flex-col gap-[18px] [&>*:last-child]:flex-[1_0_auto]">
          <TarjetaTareas f={f} />
          <TarjetaFacturas f={f} />
          <TarjetaSoporte f={f} />
          <TarjetaReuniones f={f} />
          {f.contacto && <TarjetaContacto f={f} />}
        </div>
        <div className="flex flex-col gap-[18px] [&>*:last-child]:flex-[1_0_auto]">
          <TarjetaPortal f={f} p={p} />
          <TarjetaFiscales f={f} p={p} />
          {f.credenciales && <TarjetaCredenciales f={f} />}
          {(f.estado.nombre || f.estado.fases.length > 0) && <TarjetaEstado f={f} />}
          {f.plan.items.length > 0 && <TarjetaPlan f={f} />}
        </div>
      </div>

      {p.borrar && <ZonaPeligro id={c.id} name={c.name} />}
    </div>
  )
}

/* ---------- Cabecera ---------- */

function Pildora({ children }: { children: ReactNode }) {
  return <span className="inline-flex items-center rounded-full border border-line bg-field px-[11px] py-1 text-[12px] font-semibold text-ink [&_b]:text-ink-strong">{children}</span>
}

function Cabecera({ f, p }: { f: Ficha; p: PermisosClientes }) {
  const c = f.cliente
  return (
    <header className="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div className="flex min-w-0 items-center gap-4">
        <Avatar nombre={c.name} forma="cuadrado" size={54} className="!rounded-2xl text-[19px]" />
        <div className="min-w-0">
          <h1 className="text-[25px] leading-[1.2] font-[650] tracking-[-.5px] text-ink-strong max-sm:text-[22px]">{c.name}</h1>
          <div className="mt-2 flex flex-wrap gap-1.5">
            <Pildora>{c.tipo_nombre ?? 'Sin tipo'}</Pildora>
            <Pildora>
              usuario: <b className="ml-1">{c.username || '—'}</b>
            </Pildora>
            <Pildora>{c.conversiones ? 'Con métricas' : 'Solo web'}</Pildora>
            {c.actual && <Pildora>Mes: {c.actual}</Pildora>}
            {!c.activo && (
              <span className="inline-flex items-center rounded-full bg-[#feecec] px-[11px] py-1 text-[12px] font-semibold text-[#c0343a] dark:bg-danger-bg dark:text-danger">No activo</span>
            )}
          </div>
        </div>
      </div>
      <div className="flex flex-wrap gap-2">
        {/* Lo que ve el cliente en su portal; con permiso, editable en vivo. */}
        <Button variant="ghost" size="sm" to={`/clientes/${c.id}/portal`} icon={<Eye />}>
          Ver portal
        </Button>
        {p.portal && (
          <Button variant="ghost" size="sm" to={`/clientes/${c.id}/portal?editar=1`} icon={<PencilLine />}>
            Editar portal
          </Button>
        )}
        {p.avanzado && (
          <Button variant="ghost" size="sm" to={`/clientes/${c.id}/avanzado`} icon={<Database />}>
            Datos avanzados
          </Button>
        )}
        <Button variant="ghost" size="sm" to="/clientes" icon={<ArrowLeft />}>
          Clientes
        </Button>
      </div>
    </header>
  )
}

function AccionesRapidas({ f, p }: { f: Ficha; p: PermisosClientes }) {
  const id = f.cliente.id
  const acciones: { icon: ReactNode; color: string; title: string; description: string; to: string }[] = p.editar
    ? [
        { icon: <Plus />, color: '#e0a000', title: 'Nueva tarea', description: 'En su backlog', to: `/tareas?view=cliente&cli=${id}` },
        { icon: <CalendarPlus />, color: '#4285F4', title: 'Agendar reunión', description: 'Con el cliente', to: `/reuniones?cli=${id}` },
        { icon: <FileText />, color: '#0ea5e9', title: 'Informe del mes', description: 'Lo que verá en su portal', to: `/tareas?view=cliente&cli=${id}&informe=1` },
        ...(f.cliente.conversiones
          ? [{ icon: <BarChart3 />, color: '#a855f7', title: 'Métricas', description: 'Web · Analytics · conversiones', to: `/clientes/${id}/metricas` }]
          : []),
        { icon: <Receipt />, color: '#34c759', title: 'Nueva factura', description: 'Emitir y cobrar', to: `/finanzas/facturas/nueva?cli=${id}` },
        { icon: <Ticket />, color: '#ef4444', title: 'Abrir ticket', description: 'Soporte del cliente', to: `/soporte?cli=${id}` },
        { icon: <Pencil />, color: '#5e5ce6', title: 'Editar ficha', description: 'Campo a campo', to: `/clientes/${id}/editar` },
      ]
    : [
        { icon: <CheckSquare />, color: '#e0a000', title: 'Tareas', description: 'Backlog del cliente', to: `/tareas?view=cliente&cli=${id}` },
        { icon: <Receipt />, color: '#34c759', title: 'Facturas', description: 'Ver del cliente', to: `/finanzas/facturas?cli=${id}` },
        { icon: <Ticket />, color: '#ef4444', title: 'Soporte', description: 'Tickets del cliente', to: `/soporte?cli=${id}` },
      ]
  return (
    <div className="mb-6 grid grid-cols-6 gap-3 max-[1080px]:grid-cols-3 max-[600px]:grid-cols-2">
      {acciones.map((a) => (
        <QuickActionCard key={a.title} compact {...a} />
      ))}
    </div>
  )
}

/* ---------- Tarjetas ---------- */

function Tarjeta({ titulo, enlace, children }: { titulo: string; enlace?: { to: string; label: string }; children: ReactNode }) {
  return (
    <Card className="!rounded-2xl !px-7 !py-[26px] max-sm:!px-4 max-sm:!py-[18px]">
      <CardHeader
        eyebrow
        title={titulo}
        action={
          enlace && (
            <Link to={enlace.to} className="text-[12.5px] font-semibold text-[#0071e3] hover:underline dark:text-[#6aa8ff]">
              {enlace.label}
            </Link>
          )
        }
      />
      {children}
    </Card>
  )
}

function Vacio({ children }: { children: ReactNode }) {
  return <p className="py-2 text-[13px] text-muted">{children}</p>
}

function TarjetaTareas({ f }: { f: Ficha }) {
  const id = f.cliente.id
  return (
    <Tarjeta titulo="Tareas y backlog" enlace={{ to: `/tareas?view=cliente&cli=${id}`, label: 'Abrir →' }}>
      {f.listas.length === 0 ? (
        <Vacio>Sin listas de trabajo todavía.</Vacio>
      ) : (
        f.listas.map((l) => (
          <ListRow
            key={l.id}
            to={`/tareas?view=cliente&cli=${id}&list=${l.id}`}
            icon={l.tipo === 'informe' ? <Inbox /> : <List />}
            title={
              <span className="inline-flex items-center gap-1.5">
                {l.nombre}
                {l.es_cliente && <span className="rounded-[5px] bg-[#f2f3f5] px-1.5 py-0.5 text-[9.5px] font-semibold text-muted uppercase dark:bg-soft">cliente</span>}
              </span>
            }
            right={`${l.pend} abiertas · ${l.cnt}`}
          />
        ))
      )}
    </Tarjeta>
  )
}

function TarjetaFacturas({ f }: { f: Ficha }) {
  const id = f.cliente.id
  const fa = f.facturas
  return (
    <Tarjeta titulo="Facturas" enlace={{ to: `/finanzas/facturas?cli=${id}`, label: 'Abrir →' }}>
      {fa.n === 0 ? (
        <Vacio>
          Sin facturas todavía.{' '}
          <Link to={`/finanzas/facturas/nueva?cli=${id}`} className="font-semibold text-ink hover:underline">
            Emitir la primera →
          </Link>
        </Vacio>
      ) : (
        <>
          <ListRow
            icon={<Receipt />}
            title={`${fa.n} factura${fa.n === 1 ? '' : 's'}`}
            subtitle={`Cobrado ${importe(fa.cobrado)} · Pendiente ${importe(fa.pendiente)}`}
          />
          {fa.ultimas.map((x) => {
            const e = ESTADOS_FACTURA[x.estado] ?? ESTADOS_FACTURA.borrador
            return (
              <ListRow
                key={x.id}
                to={`/finanzas/facturas/${x.id}`}
                title={x.numero || `Factura #${x.id}`}
                subtitle={
                  <>
                    {fechaCorta(x.fecha, 'Sin fecha')} · <span style={{ color: e.color }} className="font-semibold">{e.label}</span>
                  </>
                }
                right={<span className="text-ink-strong tabular-nums">{importe(x.total)}</span>}
              />
            )
          })}
        </>
      )}
    </Tarjeta>
  )
}

function TarjetaSoporte({ f }: { f: Ficha }) {
  const id = f.cliente.id
  return (
    <Tarjeta titulo="Soporte" enlace={{ to: `/soporte?cli=${id}`, label: 'Abrir →' }}>
      {f.tickets.items.length === 0 ? (
        <Vacio>
          Sin tickets ·{' '}
          <Link to={`/soporte?cli=${id}`} className="font-semibold text-ink hover:underline">
            abrir uno →
          </Link>
        </Vacio>
      ) : (
        f.tickets.items.map((t) => {
          const e = ESTADOS_TICKET[t.estado] ?? ESTADOS_TICKET.abierto
          const pr = PRIORIDADES_TICKET[t.prioridad] ?? PRIORIDADES_TICKET[2]
          return (
            <ListRow
              key={t.id}
              to={`/soporte/${t.id}`}
              icon={<Ticket />}
              title={t.asunto}
              subtitle={
                <>
                  #{t.id} · <span style={{ color: pr.color }}>{pr.label}</span> · {fechaCorta(t.fecha)}
                </>
              }
              right={<PildoraEstado color={e.color}>{e.label}</PildoraEstado>}
            />
          )
        })
      )}
    </Tarjeta>
  )
}

function TarjetaReuniones({ f }: { f: Ficha }) {
  const r = f.reuniones
  return (
    <Tarjeta titulo="Reuniones" enlace={{ to: `/reuniones?cli=${f.cliente.id}`, label: 'Abrir →' }}>
      {r.solicitudes > 0 && (
        <ListRow
          to="/reuniones"
          icon={<CalendarPlus />}
          iconColor="#e0a000"
          title={`${r.solicitudes} solicitud${r.solicitudes === 1 ? '' : 'es'} desde su portal`}
          subtitle="Pendiente de confirmar"
        />
      )}
      {r.proximas.length === 0 && r.solicitudes === 0 ? (
        <Vacio>{f.cliente.contact_id || f.contacto ? 'No hay reuniones próximas.' : 'Sin contacto del CRM: sus reuniones se agendan desde el contacto.'}</Vacio>
      ) : (
        r.proximas.map((m) => (
          <ListRow
            key={m.id}
            to="/reuniones"
            icon={<Video />}
            iconColor="#4285F4"
            title={m.titulo || 'Reunión con el cliente'}
            subtitle={`${fechaCorta(m.fecha)}${m.hora ? ` · ${m.hora}` : ''}`}
          />
        ))
      )}
    </Tarjeta>
  )
}

function TarjetaContacto({ f }: { f: Ficha }) {
  const k = f.contacto!
  const datos = [k.empresa, k.email, k.telefono || k.whatsapp].filter(Boolean).join(' · ')
  return (
    <Tarjeta titulo="Contacto de origen" enlace={{ to: `/crm/contactos/${k.id}`, label: 'Abrir →' }}>
      <ListRow
        to={`/crm/contactos/${k.id}`}
        leading={<Avatar nombre={k.nombre} size={30} />}
        title={k.nombre}
        subtitle={datos || 'Sin datos de contacto'}
        right={k.origen_lead}
      />
    </Tarjeta>
  )
}

function TarjetaPortal({ f, p }: { f: Ficha; p: PermisosClientes }) {
  const { confirm } = useConfirm()
  const reset = useRestablecerPassword(f.cliente.id)
  const [nueva, setNueva] = useState<string | null>(null)

  async function restablecer() {
    const ok = await confirm({
      title: '¿Restablecer contraseña?',
      message: 'Se generará una contraseña nueva y la actual dejará de valer. Tendrás que pasársela al cliente.',
      okLabel: 'Restablecer',
    })
    if (!ok) return
    const r = await reset.mutateAsync().catch(() => null)
    if (r) setNueva(r.password)
  }

  return (
    <Tarjeta titulo="Acceso al portal">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <b className="block text-[13.5px] font-semibold text-ink-strong">Usuario: {f.cliente.username || '—'}</b>
          <span className="text-[12px] text-muted">
            Entra en el portal del cliente{f.cliente.login_email ? ` · o con Google (${f.cliente.login_email})` : ''}
          </span>
        </div>
        {p.editar && (
          <Button variant="ghost" size="sm" icon={<Zap />} onClick={() => void restablecer()} loading={reset.isPending} loadingText="Restableciendo…">
            Restablecer
          </Button>
        )}
      </div>
      {nueva && (
        <div className="mt-4 rounded-xl border border-[#cde8d5] bg-[#eef7f0] px-4 py-3 text-[13px] text-[#12603a] dark:border-ok-line dark:bg-ok-bg dark:text-ok">
          Contraseña nueva de <b>{f.cliente.username}</b>:{' '}
          <code className="ml-1 font-mono text-[18px] font-bold tracking-[1.5px] select-all">{nueva}</code>
          <p className="mt-1 text-[12px]">Apúntala y pásasela al cliente: no se vuelve a mostrar.</p>
        </div>
      )}
    </Tarjeta>
  )
}

function TarjetaFiscales({ f, p }: { f: Ficha; p: PermisosClientes }) {
  const k = f.cliente.fact
  const campos: [string, string][] = [
    ['Nombre fiscal', k.nombre || f.cliente.name],
    ['NIF / CIF', k.nif],
    ['Dirección', k.dir],
    ['Email factura', k.email],
  ]
  return (
    <Tarjeta titulo="Datos fiscales" enlace={p.editar ? { to: `/clientes/${f.cliente.id}/editar#fact`, label: 'Editar →' } : undefined}>
      <dl className="grid grid-cols-2 gap-x-6 gap-y-[18px] max-sm:grid-cols-1">
        {campos.map(([l, v]) => (
          <div key={l} className="min-w-0">
            <dt className="mb-[5px] text-[10.5px] font-[650] tracking-[.4px] text-muted uppercase">{l}</dt>
            <dd className={`text-[14px] break-words ${v ? 'font-semibold text-ink-strong' : 'text-muted'}`}>{v || '—'}</dd>
          </div>
        ))}
      </dl>
      {f.cliente.faltan_fiscales > 0 && f.facturas.n > 0 && (
        <Notice tone="warn" className="mt-4 !mb-0">
          Faltan {f.cliente.faltan_fiscales} dato{f.cliente.faltan_fiscales === 1 ? '' : 's'} de facturación y ya hay facturas emitidas.
        </Notice>
      )}
    </Tarjeta>
  )
}

function TarjetaCredenciales({ f }: { f: Ficha }) {
  const cr = f.credenciales!
  const enlace = cr.total > 6 ? { to: `/ajustes/boveda?cli=${f.cliente.id}`, label: `Ver las ${cr.total} →` } : { to: `/ajustes/boveda?cli=${f.cliente.id}`, label: 'Abrir bóveda →' }
  return (
    <Tarjeta titulo="Credenciales · acceso rápido" enlace={enlace}>
      {cr.items.length === 0 ? (
        <Vacio>
          Sin credenciales guardadas.{' '}
          <Link to={`/ajustes/boveda?cli=${f.cliente.id}`} className="font-semibold text-ink hover:underline">
            Registrar la primera →
          </Link>
        </Vacio>
      ) : (
        <div className="grid grid-cols-[repeat(auto-fill,minmax(230px,1fr))] gap-3">
          {cr.items.map((k) => (
            <TarjetaCredencial key={k.id} cliente={f.cliente.id} k={k} />
          ))}
        </div>
      )}
    </Tarjeta>
  )
}

function CampoCopiable({ etiqueta, children, onCopiar, extra }: { etiqueta: string; children: ReactNode; onCopiar?: () => void; extra?: ReactNode }) {
  return (
    <div className="mt-2 flex items-center gap-1 rounded-[11px] bg-[#f7f8fa] px-3 py-2 dark:bg-soft">
      <div className="min-w-0 flex-1">
        <span className="block text-[9.5px] font-bold tracking-[.4px] text-muted uppercase">{etiqueta}</span>
        <span className="block truncate font-mono text-[12.5px] text-ink-strong">{children}</span>
      </div>
      {extra}
      {onCopiar && <IconButton size={26} label={`Copiar ${etiqueta.toLowerCase()}`} icon={<Copy />} onClick={onCopiar} />}
    </div>
  )
}

function TarjetaCredencial({ cliente, k }: { cliente: number; k: NonNullable<Ficha['credenciales']>['items'][number] }) {
  const { aviso } = useToast()
  const [secreto, setSecreto] = useState<string | null>(null)
  const [visible, setVisible] = useState(false)
  const [copiado, setCopiado] = useState(false)

  async function leer() {
    if (secreto !== null) return secreto
    try {
      const s = await pedirSecreto(cliente, k.id)
      setSecreto(s)
      return s
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido leer la contraseña.'), { tipo: 'error' })
      return null
    }
  }

  async function copiar(texto: string | null) {
    if (texto === null) return
    try {
      await navigator.clipboard.writeText(texto)
      aviso('Copiado ✓')
      return true
    } catch {
      aviso('No se ha podido copiar.', { tipo: 'error' })
      return false
    }
  }

  return (
    <div className="rounded-[14px] border border-line p-3.5 transition-transform hover:-translate-y-0.5">
      <div className="flex items-center gap-2.5">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-soft text-muted [&>svg]:size-4" aria-hidden="true">
          <KeyRound />
        </span>
        <div className="min-w-0">
          <b className="block truncate text-[14px] font-semibold text-ink-strong">{k.titulo}</b>
          <span className="text-[9.5px] font-bold tracking-[.5px] text-muted uppercase">{CATEGORIAS_CREDENCIAL[k.categoria] ?? 'Acceso'}</span>
        </div>
      </div>
      {k.usuario && (
        <CampoCopiable etiqueta="Usuario" onCopiar={() => void copiar(k.usuario)}>
          {k.usuario}
        </CampoCopiable>
      )}
      {k.tiene_secreto && (
        <CampoCopiable
          etiqueta="Contraseña"
          onCopiar={async () => {
            if (await copiar(await leer())) {
              setCopiado(true)
              setTimeout(() => setCopiado(false), 1100)
            }
          }}
          extra={
            <IconButton
              size={26}
              label={visible ? 'Ocultar' : 'Ver'}
              icon={copiado ? <Check className="text-[#12a150]" /> : visible ? <EyeOff /> : <Eye />}
              onClick={async () => {
                if (visible) setVisible(false)
                else if ((await leer()) !== null) setVisible(true)
              }}
            />
          }
        >
          {visible && secreto !== null ? secreto : '••••••••••••'}
        </CampoCopiable>
      )}
      {k.url && /^https?:\/\//i.test(k.url) && (
        <a href={k.url} target="_blank" rel="noopener noreferrer" className="mt-2.5 inline-block text-[12.5px] font-semibold text-[#0071e3] hover:underline dark:text-[#6aa8ff]">
          Acceder al servicio
        </a>
      )}
    </div>
  )
}

function BarraFases({ fases }: { fases: Ficha['estado']['fases'] }) {
  return (
    <div className="flex gap-[5px]" role="img" aria-label={`${fases.filter((x) => x.estado !== '').length} de ${fases.length} fases`}>
      {fases.map((x, i) => (
        <span key={i} className={`h-1.5 flex-1 rounded-full ${x.estado ? 'bg-accent' : 'bg-[#eceef1] dark:bg-line-strong'}`} />
      ))}
    </div>
  )
}

function TarjetaEstado({ f }: { f: Ficha }) {
  const e = f.estado
  return (
    <Tarjeta titulo="Estado del proyecto">
      {e.nombre && <b className="mb-1 block text-[15px] font-semibold text-ink-strong">{e.nombre}</b>}
      {e.etiqueta && <span className="mb-3 inline-block rounded-full bg-ink-strong px-2.5 py-0.5 text-[11px] font-semibold text-white dark:text-[#171717]">{e.etiqueta}</span>}
      {e.fases.length > 0 && (
        <div className="mt-2">
          <BarraFases fases={e.fases} />
          <div className="mt-2 flex gap-[5px]">
            {e.fases.map((x, i) => (
              <span key={i} className={`min-w-0 flex-1 truncate text-[11.5px] ${x.estado === 'now' ? 'font-semibold text-ink-strong' : 'text-muted'}`}>
                {x.t}
              </span>
            ))}
          </div>
        </div>
      )}
      {e.siguiente && (
        <p className="mt-3 text-[13px] text-ink">
          <b className="text-ink-strong">Lo siguiente:</b> {e.siguiente}
        </p>
      )}
    </Tarjeta>
  )
}

function TarjetaPlan({ f }: { f: Ficha }) {
  return (
    <Tarjeta titulo="Plan contratado">
      {f.plan.items.map((i, n) => (
        <div key={n} className="flex items-center justify-between gap-3 border-t border-line2 py-3 text-[13.5px] first:border-t-0">
          <span className="text-ink">{i.t}</span>
          <b className="text-ink-strong tabular-nums">{i.n}</b>
        </div>
      ))}
    </Tarjeta>
  )
}

function ZonaPeligro({ id, name }: { id: number; name: string }) {
  const { confirm } = useConfirm()
  const borrar = useBorrarCliente()
  const navigate = useNavigate()
  async function pedir() {
    const ok = await confirm({
      title: `¿Eliminar a ${name}?`,
      message: 'Se borrarán sus tareas, listas y credenciales. Queda 30 días en la papelera.',
      okLabel: 'Eliminar',
      danger: true,
    })
    if (!ok) return
    const r = await borrar.mutateAsync({ id, name }).catch(() => null)
    if (r) navigate('/clientes', { replace: true })
  }
  return (
    <DangerZone
      variant="simple"
      className="mt-[26px]"
      title="¿Dar de baja a este cliente?"
      text="Se va a la papelera con sus tareas, listas, tickets y credenciales. Sus facturas y horas se conservan."
      action={
        <Button variant="danger" size="sm" onClick={() => void pedir()} loading={borrar.isPending} loadingText="Eliminando…">
          Eliminar cliente
        </Button>
      }
    />
  )
}
