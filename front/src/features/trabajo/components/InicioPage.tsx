import { useMemo, useState, type ReactNode } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { BriefcaseBusiness, Calendar, CalendarDays, Check, CheckSquare, Clock, Euro, FileText, Lock, MessageCircle, PhoneCall, Receipt, Ticket, TriangleAlert, Video, Zap } from 'lucide-react'
import { useAuth } from '../../auth/useAuth'
import QuickActionCard from '../../../shared/ui/QuickActionCard'
import KpiTile, { KpiGrid } from '../../../shared/ui/KpiTile'
import Segmented from '../../../shared/ui/Segmented'
import DateTile from '../../../shared/ui/DateTile'
import AvatarStack from '../../../shared/ui/AvatarStack'
import Avatar from '../../../shared/ui/Avatar'
import { diasCalendario } from '../../../shared/lib/fechas'
import { eur, isoDia, mesNombre } from '../../../shared/lib/formato'
import { useAgenda, useInicio } from '../api'
import { hoyLargo, porDia, saludo, tarjetasPulso, type TarjetaPulso } from '../logica'
import type { Inicio, ItemCalendario, TareaInicio } from '../schemas'

const TARJETA =
  'rounded-[18px] border border-[rgba(16,19,24,.06)] bg-card px-[26px] py-6 shadow-[0_1px_2px_rgba(16,19,24,.03),0_12px_30px_-22px_rgba(16,19,24,.14)] dark:border-line max-sm:rounded-[14px] max-sm:p-[15px]'

function Cabecera({ icono, titulo, extra, enlace }: { icono: ReactNode; titulo: string; extra?: ReactNode; enlace?: { to: string; label: string } }) {
  return (
    <div className="mb-4 flex items-center justify-between gap-3">
      <h3 className="flex min-w-0 items-center gap-2 text-[16px] font-[650] text-ink-strong [&>svg]:size-4 [&>svg]:shrink-0 [&>svg]:text-muted">
        {icono}
        <span className="truncate">{titulo}</span>
        {extra}
      </h3>
      {enlace && (
        <Link to={enlace.to} className="shrink-0 text-[12.5px] font-semibold text-muted hover:text-ink">
          {enlace.label}
        </Link>
      )}
    </div>
  )
}

function Vacio({ icono, titulo, texto }: { icono: ReactNode; titulo: string; texto: ReactNode }) {
  return (
    <div className="flex min-h-[150px] flex-col items-center justify-center px-2.5 py-[22px] text-center">
      <span className="mb-4 flex size-[60px] items-center justify-center rounded-[18px] bg-soft text-label [&>svg]:size-7" aria-hidden="true">
        {icono}
      </span>
      <b className="text-[17px] font-semibold text-ink-strong">{titulo}</b>
      <p className="mt-1.5 max-w-[240px] text-[13.5px] leading-[1.6] text-muted">{texto}</p>
    </div>
  )
}

function KpiPildora({ label, valor, to }: { label: string; valor: string; to: string }) {
  return (
    <Link to={to} className="rounded-[13px] border border-line bg-card px-[18px] py-3 transition-[border-color,box-shadow] hover:border-[#dcdee2] hover:shadow-[0_10px_24px_-18px_rgba(0,0,0,.5)] dark:hover:border-line-strong">
      <span className="block text-[11px] font-semibold text-muted">{label}</span>
      <b className="mt-1 block text-[19px] font-bold tracking-[-.4px] text-ink-strong">{valor}</b>
    </Link>
  )
}

/* Panel de inicio (dashboard.php): saludo y cifras, accesos rápidos, lo de
   hoy, próximas reuniones, calendario del mes y las tareas en curso. */
export default function InicioPage() {
  const { me, can } = useAuth()
  const { data, error } = useInicio()
  const agenda = useAgenda()
  const ahora = new Date()

  const accesos = [
    { perm: 'ver.credenciales', icon: <Lock />, color: '#64748b', title: 'Bóveda de credenciales', description: 'Accesos y contraseñas de clientes', to: '/credenciales' },
    { perm: 'ver.conta', icon: <Euro />, color: '#34c759', title: 'Contabilidad', description: 'Ingresos, gastos y resultado', to: '/finanzas/contabilidad' },
    { perm: 'ver.crm', icon: <BriefcaseBusiness />, color: '#5e5ce6', title: 'CRM · Ventas', description: 'Contactos, negocios y seguimiento', to: '/crm' },
    { perm: 'ver.finanzas', icon: <FileText />, color: '#0a84ff', title: 'Facturas', description: 'Emitir y controlar cobros', to: '/finanzas/facturas' },
  ].filter((a) => can(a.perm))

  return (
    <div className="mx-auto max-w-[1400px]">
      <div className="mb-[22px] flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <h1 className="text-[28px] font-[650] tracking-[-.5px] text-ink-strong max-sm:text-[24px]">
            {saludo(ahora.getHours())}, {me?.username ?? ''} <span aria-hidden="true">👋</span>
          </h1>
          <p className="mt-1 text-[13.5px] text-muted">Aquí tienes el resumen de tu agencia.</p>
        </div>
        {data && (
          <div className="flex flex-wrap gap-2.5">
            {data.kpis.clientes_activos !== null && <KpiPildora label="Clientes activos" valor={String(data.kpis.clientes_activos)} to="/clientes" />}
            {data.kpis.cobrado_mes !== null && <KpiPildora label="Cobrado este mes" valor={eur(data.kpis.cobrado_mes / 100)} to="/finanzas/contabilidad" />}
          </div>
        )}
      </div>

      {error && <p className="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300">{error.message}</p>}

      {accesos.length > 0 && (
        <div className="mb-[18px] grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-[18px]">
          {accesos.map((a) => (
            <QuickActionCard key={a.to} icon={a.icon} color={a.color} title={a.title} description={a.description} to={a.to} />
          ))}
        </div>
      )}

      {data && <Pulso tarjetas={tarjetasPulso(data.pulso)} />}

      <div className="mb-[18px] grid grid-cols-3 gap-[18px] max-[1100px]:grid-cols-1">
        <section className={TARJETA} aria-label="Hoy">
          <Cabecera icono={<Zap />} titulo="Hoy" enlace={{ to: `/calendario?view=dia&d=${data?.hoy ?? isoDia(ahora)}`, label: 'Ver día' }} />
          <Hoy tareas={data?.hoy_tareas ?? []} eventos={agenda.data?.hoy ?? []} />
        </section>
        <section className={TARJETA} aria-label="Próximas reuniones">
          <Cabecera icono={<Calendar />} titulo="Próximas reuniones" enlace={{ to: '/reuniones', label: 'Ver todas' }} />
          {agenda.isPending ? (
            <p className="py-10 text-center text-[13px] text-muted">Cargando…</p>
          ) : !agenda.data?.conectado ? (
            <Vacio
              icono={<CalendarDays />}
              titulo={agenda.data?.error ? 'Calendario no disponible' : 'Calendario sin conectar'}
              texto={
                agenda.data?.error ?? (
                  <>
                    Conéctalo en{' '}
                    <Link to="/ajustes/integraciones" className="font-semibold text-ink hover:underline">
                      Integraciones
                    </Link>{' '}
                    para ver tus reuniones.
                  </>
                )
              }
            />
          ) : agenda.data.reuniones.length === 0 ? (
            <Vacio icono={<CalendarDays />} titulo="Sin reuniones próximas" texto="Cuando agendes una, aparecerá aquí." />
          ) : (
            <ul>
              {agenda.data.reuniones.map((r, i) => (
                <li key={i} className="flex items-center gap-3.5 border-t border-line2 py-[13px] first:border-t-0">
                  <DateTile date={r.dia} />
                  <span className="min-w-0 flex-1">
                    <a href={r.link || undefined} target="_blank" rel="noreferrer" className="block truncate text-[13.5px] font-semibold text-ink-strong hover:text-[#0071e3]">
                      {r.titulo}
                    </a>
                    <span className="block truncate text-[12px] text-muted">{[r.hora || 'todo el día', r.cliente].filter(Boolean).join(' · ')}</span>
                  </span>
                  {r.meet && <span className="shrink-0 rounded-full bg-[#e8f0fe] px-2 py-0.5 text-[10.5px] font-bold text-[#1a73e8] dark:bg-[#1a2a44] dark:text-[#8ab4f8]">Meet</span>}
                </li>
              ))}
            </ul>
          )}
        </section>
        <section className={TARJETA} aria-label="Calendario">
          <Cabecera
            icono={<CalendarDays />}
            titulo="Calendario"
            extra={<span className="rounded-full bg-[#eaf3ff] px-[11px] py-[3px] text-[11.5px] font-semibold text-[#0071e3] dark:bg-[#132238] dark:text-[#6aa9ff]">{hoyLargo(ahora)}</span>}
            enlace={{ to: '/calendario', label: 'Abrir' }}
          />
          <MiniCalendario hoy={ahora} items={[...(data?.calendario ?? []), ...(agenda.data?.calendario ?? [])]} />
        </section>
      </div>

      {data?.tareas && <TarjetaTareas tareas={data.tareas} hoy={data.hoy} />}
    </div>
  )
}

const ICONO_PULSO: Record<TarjetaPulso['id'], ReactNode> = {
  tickets: <Ticket />,
  crm: <PhoneCall />,
  cobro: <Receipt />,
  vencidas: <TriangleAlert />,
  chat: <MessageCircle />,
}

/* Lo que pide atención en los demás módulos; cada tarjeta lleva a su pantalla. */
function Pulso({ tarjetas }: { tarjetas: TarjetaPulso[] }) {
  if (!tarjetas.length) return null
  const cols = Math.min(Math.max(tarjetas.length, 2), 5) as 2 | 3 | 4 | 5
  return (
    <section aria-label="Requiere atención">
      <KpiGrid cols={cols} className="mb-[18px] gap-[18px] max-[1250px]:grid-cols-3 max-[420px]:grid-cols-2! max-sm:gap-2.5">
        {tarjetas.map((t) => (
          <KpiTile
            key={t.id}
            label={t.label}
            // Cinco por fila: la cifra un punto más pequeña y sin partirse («1.089,00 €»).
            value={<span className="block truncate text-[27px] whitespace-nowrap max-sm:text-[24px]">{t.valor}</span>}
            sub={<span className={`block truncate ${t.alerta ? 'font-semibold text-[#c0343a] dark:text-danger' : ''}`}>{t.sub}</span>}
            icon={ICONO_PULSO[t.id]}
            href={t.to}
          />
        ))}
      </KpiGrid>
    </section>
  )
}

function Hoy({ tareas, eventos }: { tareas: TareaInicio[]; eventos: (ItemCalendario & { todo_el_dia: boolean; link: string })[] }) {
  if (!tareas.length && !eventos.length) return <Vacio icono={<Check />} titulo="Día despejado" texto="Sin reuniones ni vencimientos para hoy." />
  return (
    <ul>
      {eventos.map((e, i) => (
        <li key={`e${i}`} className="flex items-center gap-3.5 border-t border-line2 py-[13px] first:border-t-0">
          <span className="flex size-[30px] shrink-0 items-center justify-center rounded-[9px] bg-[#4285F41e] text-[#4285F4]">
            <Video className="size-4" />
          </span>
          <span className="min-w-0 flex-1">
            <b className="block truncate text-[13.5px] font-semibold text-ink-strong">{e.titulo}</b>
            <span className="block text-[12px] text-muted">{e.todo_el_dia ? 'todo el día' : e.hora ? `${e.hora} · reunión` : 'reunión'}</span>
          </span>
        </li>
      ))}
      {tareas.map((t) => (
        <li key={t.id} className="flex items-center gap-3.5 border-t border-line2 py-[13px] first:border-t-0">
          <span className="flex size-[30px] shrink-0 items-center justify-center rounded-[9px] bg-[#e0a0001e] text-[#e0a000]">
            <CheckSquare className="size-4" />
          </span>
          <span className="min-w-0 flex-1">
            <Link to={`/tareas/${t.id}`} className="block truncate text-[13.5px] font-semibold text-ink-strong hover:text-[#0071e3]">
              {t.titulo}
            </Link>
            <span className="block truncate text-[12px] text-muted">vence hoy · {t.cliente}</span>
          </span>
          {t.asignados.length > 0 && <AvatarStack people={t.asignados} size={22} />}
        </li>
      ))}
    </ul>
  )
}

const SEMANA = ['L', 'M', 'X', 'J', 'V', 'S', 'D']

function MiniCalendario({ hoy, items }: { hoy: Date; items: ItemCalendario[] }) {
  const navigate = useNavigate()
  const dias = useMemo(() => diasCalendario(hoy.getFullYear(), hoy.getMonth()), [hoy])
  const mapa = useMemo(() => porDia(items), [items])
  const [encima, setEncima] = useState<string | null>(null)
  const iso = isoDia(hoy)
  return (
    <div className="relative">
      <div className="grid grid-cols-7 gap-2 text-center">
        {SEMANA.map((d) => (
          <span key={d} className="pb-1 text-[10.5px] font-bold text-label">
            {d}
          </span>
        ))}
        {dias.map((d) => {
          if (!d.delMes) return <span key={d.iso} aria-hidden="true" />
          const cosas = mapa.get(d.iso) ?? []
          const conEventos = cosas.some((c) => c.tipo === 'evento')
          const esHoy = d.iso === iso
          return (
            <button
              key={d.iso}
              type="button"
              onMouseEnter={() => setEncima(cosas.length ? d.iso : null)}
              onMouseLeave={() => setEncima(null)}
              onFocus={() => setEncima(cosas.length ? d.iso : null)}
              onBlur={() => setEncima(null)}
              onClick={() => navigate(`/calendario?view=dia&d=${d.iso}`)}
              aria-label={`${d.dia}${cosas.length ? `: ${cosas.length} cosa(s)` : ''}`}
              className={`relative flex aspect-square items-center justify-center rounded-[9px] text-[12.5px] transition-colors ${esHoy ? 'bg-[#3c4149] font-semibold text-white dark:bg-rev dark:text-rev-fg' : 'text-ink hover:bg-soft'}`}
            >
              {d.dia}
              {cosas.length > 0 && <span className={`absolute bottom-[3px] size-[5px] rounded-full ${conEventos ? 'bg-[#9aa0a8]' : 'bg-[#e0a000]'}`} aria-hidden="true" />}
            </button>
          )
        })}
      </div>
      {encima && (
        <div className="pointer-events-none absolute inset-x-0 -top-2 z-10 -translate-y-full rounded-2xl border border-white/60 bg-white/[.72] p-3.5 shadow-[0_20px_50px_-16px_rgba(16,19,24,.28)] backdrop-blur-[22px] backdrop-saturate-[1.6] dark:border-line dark:bg-[#1f1f1f]/90">
          <b className="mb-1.5 block text-[12.5px] font-semibold text-ink-strong">
            {Number(encima.slice(8))} de {mesNombre(Number(encima.slice(5, 7))).toLowerCase()}
          </b>
          <ul className="flex flex-col gap-1">
            {(mapa.get(encima) ?? []).slice(0, 6).map((c, i) => (
              <li key={i} className="flex items-center gap-2 text-[12px] text-ink">
                <span className={`size-1.5 shrink-0 rounded-full ${c.tipo === 'evento' ? 'bg-[#4285F4]' : 'bg-[#e0a000]'}`} />
                <span className="truncate">
                  {c.hora && <b className="font-semibold">{c.hora} </b>}
                  {c.titulo}
                  {c.sub && <span className="text-muted"> · {c.sub}</span>}
                </span>
              </li>
            ))}
            {(mapa.get(encima)?.length ?? 0) > 6 && <li className="text-[11.5px] text-muted">+{(mapa.get(encima)?.length ?? 0) - 6} más</li>}
          </ul>
        </div>
      )}
    </div>
  )
}

type Pestana = 'proceso' | 'atrasadas' | 'completadas'

function TarjetaTareas({ tareas, hoy }: { tareas: NonNullable<Inicio['tareas']>; hoy: string }) {
  const [p, setP] = useState<Pestana>('proceso')
  const lista = p === 'proceso' ? tareas.en_proceso : p === 'atrasadas' ? tareas.atrasadas : tareas.completadas
  const vacio = {
    proceso: ['Nada en proceso', 'No hay tareas en curso ahora mismo.'],
    atrasadas: ['Nada atrasado', 'Ninguna tarea se ha pasado de fecha.'],
    completadas: ['Nada completado aún', 'Aquí verás las tareas que vayáis terminando.'],
  }[p]
  return (
    <section className={TARJETA} aria-label="Tareas">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <h3 className="flex items-center gap-2 text-[16px] font-[650] text-ink-strong">
          <CheckSquare className="size-4 text-muted" /> Tareas
        </h3>
        <Segmented<Pestana>
          variant="pill"
          aria-label="Tareas"
          value={p}
          onChange={setP}
          items={[
            { value: 'proceso', label: 'En proceso' },
            { value: 'atrasadas', label: `Atrasadas${tareas.atrasadas_total ? ` (${tareas.atrasadas_total})` : ''}` },
            { value: 'completadas', label: 'Completadas' },
          ]}
        />
      </div>
      {lista.length === 0 ? (
        <div className="flex flex-col items-center py-6 text-center">
          <span className="mb-2.5 flex size-[38px] items-center justify-center rounded-[11px] bg-soft text-label">
            <Clock className="size-[18px]" />
          </span>
          <b className="text-[13.5px] font-bold text-ink-strong">{vacio[0]}</b>
          <span className="mt-1 text-[12.5px] text-muted">{vacio[1]}</span>
        </div>
      ) : (
        <ul className="grid grid-cols-2 gap-x-[60px] max-[900px]:grid-cols-1">
          {lista.map((t) => (
            <li key={t.id} className="flex items-center gap-3.5 border-t border-line2 py-[15px] [&:nth-child(-n+2)]:border-t-0 max-[900px]:[&:nth-child(2)]:border-t">
              {t.due_date ? (
                <DateTile date={t.due_date} tone={p === 'completadas' ? 'ok' : t.due_date < hoy ? 'past' : undefined} />
              ) : (
                <span className="flex size-11 shrink-0 items-center justify-center rounded-[10px] bg-soft text-[15px] font-bold text-label">—</span>
              )}
              <span className="min-w-0 flex-1">
                <Link to={`/tareas/${t.id}`} className="block truncate text-[13.5px] font-semibold text-ink-strong hover:text-[#0071e3]">
                  {t.titulo}
                </Link>
                <span className="block truncate text-[12px] text-muted">{t.cliente}</span>
              </span>
              {p !== 'completadas' && t.prioridad >= 3 && (
                <span className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold text-white ${t.prioridad === 4 ? 'bg-[#ef4444]' : 'bg-[#f59e0b]'}`}>{t.prioridad === 4 ? 'Urgente' : 'Alta'}</span>
              )}
              {t.asignados[0] && <Avatar nombre={t.asignados[0].username} foto={t.asignados[0].foto} size={26} />}
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
