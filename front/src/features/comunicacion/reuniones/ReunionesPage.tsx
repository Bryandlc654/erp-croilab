import { useMemo, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { CalendarDays, Check, ExternalLink, FileText, Pencil, Plus, Search, Trash2, UserPlus, Video, X } from 'lucide-react'
import { api } from '../../../shared/api/client'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import EmptyState from '../../../shared/ui/EmptyState'
import { MenuItem, MenuPanel } from '../../../shared/ui/Menu'
import Notice from '../../../shared/ui/Notice'
import Popover from '../../../shared/ui/Popover'
import Select from '../../../shared/ui/Select'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useContextMenu } from '../../../shared/ui/useContextMenu'
import { useToast } from '../../../shared/ui/useToast'
import { useAuth } from '../../auth/useAuth'
import AgendarReunionModal from '../AgendarReunionModal'
import { clavesCom, mensaje, urlConectarGoogle, useContactos, useReuniones } from '../api'
import { CajaLogo, LogoGcal, LogoMeet } from '../components/Logos'
import { aFecha, fechaReunion, MESES, MESES_CORTOS } from '../logica'
import { AgendarRespuesta, MsgRespuesta, VacioRespuesta, type Reunion, type ReunionesDatos, type Solicitud } from '../schemas'

type Pestana = 'proximas' | 'pasadas' | 'notas'

/* Reuniones (reuniones.php): las de Google Calendar con su cliente emparejado
   y las notas de Gemini, las solicitudes del portal y «Crear reunión».
   ?nuevo=1&cli=N&contacto=N abre el alta prellenada (la enlazan Clientes y CRM). */
export default function ReunionesPage() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const vista = params.get('u') ?? 'me'
  const q = useReuniones(vista)
  const [pestana, setPestana] = useState<Pestana>('proximas')
  const [mes, setMes] = useState('')
  const [modal, setModal] = useState<{ solicitud?: Solicitud; evento?: Reunion } | null>(null)
  const nuevo = params.get('nuevo') === '1'
  const cli = Number(params.get('cli') ?? 0) || 0
  const contacto = Number(params.get('contacto') ?? 0) || 0

  function cerrarNuevo() {
    const p = new URLSearchParams(params)
    for (const k of ['nuevo', 'cli', 'contacto']) p.delete(k)
    setParams(p, { replace: true })
  }

  if (!can('ver.agenda')) return <EmptyState icon={<Video />} title="Sin acceso" text="No tienes permiso para ver las reuniones." />
  const d = q.data
  const g = d?.google
  const conectado = !!g && g.conectado && !g.revocado && g.configurado
  const lista = d ? d[pestana] : []
  const meses = [...new Set(lista.map((r) => r.dia.slice(0, 7)))]
  const visibles = mes && meses.includes(mes) ? lista.filter((r) => r.dia.startsWith(mes)) : lista

  return (
    <div className="mx-auto max-w-[1000px]">
      <header className="mb-6 flex flex-wrap items-center gap-x-4 gap-y-3">
        <CajaLogo>
          <LogoGcal size={30} />
        </CajaLogo>
        <div className="min-w-0 flex-1">
          <h1 className="text-[26px] leading-[1.2] font-semibold tracking-[-.5px] text-ink-strong max-sm:text-[23px]">Reuniones</h1>
          <p className="mt-1 text-[14.5px] text-muted">Tus reuniones de Google en un sitio, con las notas de Gemini y para crear nuevas.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2.5">
          {d && d.cuentas.length > 1 && (
            <div className="w-[200px]">
              <Select
                aria-label="Ver reuniones de…"
                value={d.vista}
                onChange={(v) => {
                  const p = new URLSearchParams(params)
                  if (v === 'me') p.delete('u')
                  else p.set('u', v)
                  setParams(p, { replace: true })
                }}
                options={[{ value: 'all', label: 'Todo el equipo' }, { value: 'me', label: 'Solo yo' }, ...d.cuentas.map((c) => ({ value: String(c.id), label: c.username }))]}
              />
            </div>
          )}
          {conectado && d?.puede_editar && (
            <Button icon={<Plus />} onClick={() => setModal({})}>
              Crear reunión
            </Button>
          )}
        </div>
      </header>

      {q.isPending ? (
        <div className="space-y-3" aria-busy="true">
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-[86px] animate-pulse rounded-2xl bg-soft" />
          ))}
        </div>
      ) : q.isError || !d ? (
        <EmptyState title="No se han podido cargar las reuniones" text={mensaje(q.error, 'Inténtalo de nuevo en un momento.')} actions={<Button variant="ghost" onClick={() => void q.refetch()}>Reintentar</Button>} />
      ) : (
        <>
          {d.solicitudes.length > 0 && <Solicitudes d={d} onAprobar={(s) => setModal({ solicitud: s })} conectado={conectado} />}
          <AvisoGoogle d={d} />
          {d.avisos.map((a) => (
            <Notice key={a} tone="warn" className="mb-4">
              {a}
            </Notice>
          ))}
          {conectado || d.vista !== 'me' ? (
            <>
              <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
                <div className="inline-flex rounded-xl bg-[#f1f2f4] p-1 dark:bg-soft" role="tablist">
                  {(
                    [
                      ['proximas', 'Próximas'],
                      ['pasadas', 'Pasadas'],
                      ['notas', 'Notas'],
                    ] as const
                  ).map(([k, label]) => (
                    <button
                      key={k}
                      type="button"
                      role="tab"
                      aria-selected={pestana === k}
                      onClick={() => {
                        setPestana(k)
                        setMes('')
                      }}
                      className={`rounded-[9px] px-[18px] py-2 text-[13px] font-semibold transition-colors max-sm:px-3 ${pestana === k ? 'bg-card text-ink-strong shadow-[0_1px_3px_rgba(16,19,24,.12)]' : 'text-[#6b7079] hover:text-ink dark:text-muted'}`}
                    >
                      {label}
                      <span className="ml-1.5 text-[11.5px] text-label tabular-nums">{d[k].length}</span>
                    </button>
                  ))}
                </div>
                {meses.length > 1 && (
                  <div className="w-[190px]">
                    <Select
                      aria-label="Mes"
                      value={mes}
                      onChange={setMes}
                      options={[{ value: '', label: 'Todos los meses' }, ...meses.map((m) => ({ value: m, label: `${MESES[Number(m.slice(5)) - 1]} ${m.slice(0, 4)}` }))]}
                    />
                  </div>
                )}
              </div>
              <ListaReuniones key={pestana} reuniones={visibles} pestana={pestana} d={d} onEditar={(r) => setModal({ evento: r })} />
            </>
          ) : null}
        </>
      )}

      <AgendarReunionModal open={!!modal} onClose={() => setModal(null)} solicitud={modal?.solicitud} evento={modal?.evento} />
      <AgendarReunionModal open={nuevo && !modal} onClose={cerrarNuevo} cli={cli} contacto={contacto} />
    </div>
  )
}

function AvisoGoogle({ d }: { d: ReunionesDatos }) {
  const { aviso } = useToast()
  const g = d.google
  async function conectar() {
    try {
      window.location.href = await urlConectarGoogle('/reuniones')
    } catch (e) {
      aviso(mensaje(e, 'No se ha podido conectar con Google.'), { tipo: 'error' })
    }
  }
  if (g.configurado && g.conectado && !g.revocado) return null
  if (g.revocado)
    return (
      <Notice tone="warn" className="mb-5" action={<Button size="sm" variant="ghost" onClick={() => void conectar()}>Reconectar</Button>}>
        Google pide volver a conectar la cuenta.
      </Notice>
    )
  return (
    <Notice
      tone="warn"
      className="mb-5"
      action={
        g.configurado ? (
          <Button size="sm" variant="ghost" onClick={() => void conectar()}>
            Conectar Google Calendar
          </Button>
        ) : undefined
      }
    >
      {g.configurado ? (
        'Conecta tu Google Calendar para ver y crear reuniones aquí.'
      ) : (
        <>
          Conecta Google Calendar para ver y crear reuniones aquí. Ve a{' '}
          <Link to="/ajustes/integraciones" className="font-bold underline-offset-2 hover:underline">
            Integraciones
          </Link>
          .
        </>
      )}
    </Notice>
  )
}

function Solicitudes({ d, onAprobar, conectado }: { d: ReunionesDatos; onAprobar: (s: Solicitud) => void; conectado: boolean }) {
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const qc = useQueryClient()
  const recargar = () => void qc.invalidateQueries({ queryKey: clavesCom.reunionesTodo })

  async function rechazar(s: Solicitud) {
    const ok = await confirm({ title: 'Rechazar solicitud', message: `¿Rechazar la reunión que ha pedido ${s.cliente}?`, okLabel: 'Rechazar', danger: true })
    if (!ok) return
    try {
      const r = await api(`/api/v1/reuniones/solicitudes/${s.id}/rechazar`, { method: 'POST', schema: MsgRespuesta })
      aviso(r.msg)
      recargar()
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }

  async function aprobar(s: Solicitud) {
    if (conectado) return onAprobar(s)
    // Sin Google: se aprueba y queda en el CRM con la fecha que pidió.
    const ok = await confirm({ title: 'Aprobar solicitud', message: `Se agenda en el CRM para ${s.fecha_deseada ? aFecha(s.fecha_deseada).toLocaleDateString('es-ES') : 'hoy'}. Sin Google Calendar no se manda invitación.`, okLabel: 'Aprobar' })
    if (!ok) return
    try {
      const r = await api(`/api/v1/reuniones/solicitudes/${s.id}/aprobar`, { method: 'POST', body: {}, schema: AgendarRespuesta })
      aviso(r.msg)
      recargar()
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }

  return (
    <section className="mb-7">
      <h2 className="mb-3 text-[12px] font-[650] tracking-[.5px] text-muted uppercase">
        Solicitudes de reunión · {d.solicitudes.length} pendiente{d.solicitudes.length === 1 ? '' : 's'}
      </h2>
      <ul className="space-y-3">
        {d.solicitudes.map((s) => {
          const f = s.fecha_deseada ? aFecha(s.fecha_deseada) : null
          return (
            <li key={s.id} className="flex items-center gap-4 rounded-2xl border border-[#f3e3b5] bg-[#fffdf6] px-5 py-4 max-sm:flex-wrap max-sm:px-4 dark:border-[#4a3a17] dark:bg-[#1d1a10]">
              <BloqueFecha dia={f ? String(f.getDate()) : '–'} mes={f ? MESES_CORTOS[f.getMonth()] : 'día'} />
              <div className="min-w-0 flex-1">
                <p className="text-[14.5px] font-[650] text-ink-strong">{s.motivo || 'Reunión solicitada por el cliente'}</p>
                <p className="mt-0.5 text-[12.5px] text-muted">
                  <Link to={`/clientes/${s.client_id}`} className="font-semibold text-ink hover:underline">
                    {s.cliente}
                  </Link>
                  {' · '}
                  {f ? `${f.toLocaleDateString('es-ES')}${s.franja ? ` · ${s.franja}` : ''}` : s.franja || 'Sin día preferido'}
                </p>
              </div>
              {d.puede_editar && (
                <div className="flex gap-2 max-sm:w-full max-sm:justify-end">
                  <Button variant="ghost" size="sm" icon={<X />} onClick={() => void rechazar(s)}>
                    Rechazar
                  </Button>
                  <Button size="sm" icon={<Check />} onClick={() => void aprobar(s)}>
                    Aprobar
                  </Button>
                </div>
              )}
            </li>
          )
        })}
      </ul>
    </section>
  )
}

function BloqueFecha({ dia, mes }: { dia: string; mes: string }) {
  return (
    <span className="flex w-[50px] shrink-0 flex-col items-center rounded-xl border border-line bg-card py-1.5">
      <span className="text-[21px] leading-none font-[750] text-ink-strong tabular-nums">{dia}</span>
      <span className="mt-1 text-[11px] font-semibold text-muted uppercase">{mes}</span>
    </span>
  )
}

const VACIOS: Record<Pestana, [string, string]> = {
  proximas: ['No tienes reuniones próximas', 'Cuando agendes una o apruebes una solicitud, aparecerá aquí.'],
  pasadas: ['Sin reuniones pasadas', 'No hay reuniones en los últimos 90 días.'],
  notas: ['Aún no hay notas', 'Activa «Tomar notas por mí» (Gemini) en una reunión de Meet y aquí aparecerá su documento.'],
}

function ListaReuniones({ reuniones, pestana, d, onEditar }: { reuniones: Reunion[]; pestana: Pestana; d: ReunionesDatos; onEditar: (r: Reunion) => void }) {
  const cm = useContextMenu<Reunion>()
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const qc = useQueryClient()
  const [asignar, setAsignar] = useState<{ r: Reunion; el: HTMLElement } | null>(null)

  const grupos = useMemo(() => {
    const out: { mes: string; items: Reunion[] }[] = []
    for (const r of reuniones) {
      const m = r.dia.slice(0, 7)
      if (out[out.length - 1]?.mes !== m) out.push({ mes: m, items: [] })
      out[out.length - 1].items.push(r)
    }
    return out
  }, [reuniones])

  async function borrar(r: Reunion) {
    const ok = await confirm({ title: 'Borrar reunión', message: `Se elimina «${r.titulo}» de Google Calendar y se avisa a los invitados.`, okLabel: 'Borrar', danger: true })
    if (!ok) return
    try {
      await api(`/api/v1/calendario/eventos?id=${encodeURIComponent(r.id)}`, { method: 'DELETE', schema: MsgRespuesta })
      aviso('Reunión eliminada')
      void qc.invalidateQueries({ queryKey: clavesCom.reunionesTodo })
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }

  if (!reuniones.length) return <EmptyState variant="dashed" icon={<CalendarDays />} title={VACIOS[pestana][0]} text={VACIOS[pestana][1]} />
  const r = cm.dato
  return (
    <div className="motion-safe:animate-fade-up">
      {grupos.map((gr) => (
        <section key={gr.mes} className="mb-6">
          <h3 className="mb-3 flex items-center gap-3 text-[12px] font-[650] tracking-[.5px] text-muted uppercase">
            {MESES[Number(gr.mes.slice(5)) - 1]} {gr.mes.slice(0, 4)}
            <span className="h-px flex-1 bg-line" aria-hidden="true" />
          </h3>
          <ul className="space-y-3">
            {gr.items.map((x) => (
              <Tarjeta key={x.id} r={x} d={d} onMenu={(e) => cm.onContextMenu(e, x)} onAsignar={(el) => setAsignar({ r: x, el })} />
            ))}
          </ul>
        </section>
      ))}
      <MenuPanel {...cm.panel} label="Acciones de la reunión">
        {r && (
          <>
            {r.editable && d.puede_editar && (
              <MenuItem icon={<Pencil />} onSelect={() => onEditar(r)}>
                Editar reunión
              </MenuItem>
            )}
            {d.puede_editar && (
              <MenuItem icon={<UserPlus />} onSelect={() => setAsignar({ r, el: document.getElementById(`reu-${r.id}`) ?? document.body })}>
                Asignar cliente
              </MenuItem>
            )}
            {r.link && (
              <MenuItem icon={<ExternalLink />} onSelect={() => window.open(r.link, '_blank', 'noopener')}>
                Abrir en Google Calendar
              </MenuItem>
            )}
            {r.editable && d.puede_editar && (
              <MenuItem icon={<Trash2 />} danger onSelect={() => void borrar(r)}>
                Borrar reunión
              </MenuItem>
            )}
          </>
        )}
      </MenuPanel>
      {asignar && <AsignarContacto reunion={asignar.r} ancla={asignar.el} onClose={() => setAsignar(null)} />}
    </div>
  )
}

function Tarjeta({ r, d, onMenu, onAsignar }: { r: Reunion; d: ReunionesDatos; onMenu: (e: React.MouseEvent) => void; onAsignar: (el: HTMLElement) => void }) {
  const f = aFecha(r.dia)
  const btn = useRef<HTMLButtonElement>(null)
  const quien = r.contacto ? { nombre: r.contacto.nombre, sub: r.contacto.empresa || 'Contacto', to: `/crm/contactos/${r.contacto.id}` } : r.cliente ? { nombre: r.cliente.nombre, sub: 'Cliente', to: `/clientes/${r.cliente.id}` } : null
  return (
    <li
      id={`reu-${r.id}`}
      onContextMenu={onMenu}
      className="flex gap-4 rounded-2xl border border-line bg-card px-5 py-[18px] shadow-[0_1px_2px_rgba(16,19,24,.03),0_10px_26px_-20px_rgba(16,19,24,.14)] max-sm:gap-3 max-sm:px-4"
    >
      <BloqueFecha dia={String(f.getDate())} mes={MESES_CORTOS[f.getMonth()]} />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <p className="min-w-0 text-[14.5px] font-[650] text-ink-strong">{r.titulo}</p>
          {r.meet && (
            <span className="inline-flex items-center gap-1 rounded-md bg-[#e6f4ea] px-1.5 py-[2px] text-[11px] font-semibold text-[#137333] dark:bg-[#14251c] dark:text-ok">
              <LogoMeet size={11} /> Meet
            </span>
          )}
          {r.owner && (
            <span className="inline-flex items-center gap-1 text-[11.5px] text-muted">
              <Avatar nombre={r.owner.username} foto={r.owner.foto} size={16} /> {r.owner.username}
            </span>
          )}
        </div>
        <p className="mt-1 text-[12.5px] text-muted">{fechaReunion(r.dia, r.hora ? `${r.hora}${r.hora_fin ? `–${r.hora_fin}` : ''}` : 'Todo el día')}</p>
        <div className="mt-2.5 flex flex-wrap items-center gap-2">
          {quien ? (
            <Link to={quien.to} className="inline-flex items-center gap-1.5 rounded-full bg-soft px-2.5 py-1 text-[12px] font-semibold text-ink hover:bg-line" title={r.emparejado === 'manual' ? 'Asignada a mano' : 'Por el correo de un invitado'}>
              {quien.nombre}
              <span className="font-normal text-muted">· {quien.sub}</span>
            </Link>
          ) : (
            <span className="rounded-full border border-dashed border-line-strong px-2.5 py-1 text-[12px] text-label">Sin cliente</span>
          )}
          {d.puede_editar && (
            <button ref={btn} type="button" onClick={() => btn.current && onAsignar(btn.current)} className="text-[12px] font-semibold text-muted underline-offset-2 hover:text-ink hover:underline">
              {quien ? 'cambiar' : 'asignar'}
            </button>
          )}
          {r.docs.map((doc) => (
            <a key={doc.url} href={doc.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 rounded-[9px] bg-[#f2f2f3] px-2.5 py-1 text-[12px] font-semibold text-ink hover:bg-line dark:bg-soft">
              <FileText className="size-3.5 text-[#1a73e8]" /> {doc.titulo}
            </a>
          ))}
        </div>
      </div>
      <div className="flex shrink-0 flex-col items-end gap-2">
        {r.meet_url && (
          <Button size="sm" href={r.meet_url} target="_blank" rel="noopener noreferrer" icon={<Video />}>
            Unirse
          </Button>
        )}
        {r.link && (
          <a href={r.link} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-[12px] font-semibold text-muted hover:text-ink">
            Ver en Google <ExternalLink className="size-3" />
          </a>
        )}
      </div>
    </li>
  )
}

/* Popup «asignar»: buscador y contactos por tipo de fase; «✕ Sin cliente» quita la asignación. */
function AsignarContacto({ reunion, ancla, onClose }: { reunion: Reunion; ancla: HTMLElement; onClose: () => void }) {
  const [q, setQ] = useState('')
  const grupos = useContactos(q, true)
  const { aviso } = useToast()
  const qc = useQueryClient()
  const ref = useRef<HTMLElement | null>(ancla)

  async function elegir(id: number | null) {
    try {
      await api('/api/v1/reuniones/contacto', { method: 'PATCH', body: { event_id: reunion.id, contact_id: id }, schema: VacioRespuesta })
      aviso(id ? 'Reunión asignada ✓' : 'Asignación quitada')
      void qc.invalidateQueries({ queryKey: clavesCom.reunionesTodo })
      onClose()
    } catch (e) {
      aviso(mensaje(e), { tipo: 'error' })
    }
  }

  return (
    <Popover open onClose={onClose} anchor={ref} placement="bottom-start" width={320} maxHeight={380}>
      <div className="p-2">
        <label className="relative mb-1.5 block">
          <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-label" aria-hidden="true" />
          <input
            autoFocus
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Buscar contacto…"
            aria-label="Buscar contacto"
            className="w-full rounded-lg border border-line bg-field py-2 pr-2.5 pl-8 text-[13px] text-ink focus:border-accent focus:outline-none max-sm:text-[16px]"
          />
        </label>
        {(reunion.contacto || reunion.emparejado === 'manual') && (
          <button type="button" onClick={() => void elegir(null)} className="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-[13px] font-semibold text-[#c0392b] hover:bg-soft dark:text-danger">
            <X className="size-3.5" /> Sin cliente
          </button>
        )}
        {grupos.isPending ? (
          <p className="px-2.5 py-3 text-[12.5px] text-muted">Cargando…</p>
        ) : !grupos.data?.length ? (
          <p className="px-2.5 py-3 text-[12.5px] text-muted">No hay contactos{q ? ` con «${q}»` : ''}.</p>
        ) : (
          grupos.data.map((g) => (
            <div key={g.grupo}>
              <p className="px-2.5 pt-2 pb-1 text-[10.5px] font-bold tracking-[.5px] text-muted uppercase">{g.grupo}</p>
              {g.items.map((c) => (
                <button key={c.id} type="button" onClick={() => void elegir(c.id)} className={`flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left hover:bg-soft ${reunion.contacto?.id === c.id ? 'bg-soft' : ''}`}>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-[13px] font-semibold text-ink-strong">{c.nombre}</span>
                    <span className="block truncate text-[11.5px] text-muted">{[c.empresa, c.fase].filter(Boolean).join(' · ')}</span>
                  </span>
                  {reunion.contacto?.id === c.id && <Check className="size-3.5 text-ink" />}
                </button>
              ))}
            </div>
          ))
        )}
      </div>
    </Popover>
  )
}
