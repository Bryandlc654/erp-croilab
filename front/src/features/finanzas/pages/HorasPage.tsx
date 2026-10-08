import { useState, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { Check, Clock, Euro, Plus, Settings, Trash2, Zap } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import Checkbox from '../../../shared/ui/Checkbox'
import { DateInput } from '../../../shared/ui/DatePicker'
import Field from '../../../shared/ui/Field'
import IconButton from '../../../shared/ui/IconButton'
import Notice from '../../../shared/ui/Notice'
import Segmented from '../../../shared/ui/Segmented'
import Select from '../../../shared/ui/Select'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { fechaCorta, mesLabel, numero } from '../../../shared/lib/formato'
import { mensajeError, useAccionHoras, useHoras, usePersonasHoras } from '../api'
import CargandoFin from '../components/Cargando'
import MonthNav from '../components/MonthNav'
import { aCampo } from '../lib/cuerpos'
import { decimal, eurC, leer, pctCorto } from '../lib/importes'
import { esMes, hoyIso, ultimoDia, ymDe } from '../lib/periodos'
import { usePermisosFin } from '../lib/permisos'
import type { Horas, PersonaHoras } from '../schemas'

const hhmm = (min: number) => `${Math.floor(min / 60)} h${min % 60 ? ` ${min % 60} m` : ''}`

/* Horas del equipo (fin-horas.php): cuánto ha echado cada uno en el mes, sus
   extras manuales, su tarifa y el paso a gasto de Contabilidad. */
export default function HorasPage() {
  const p = usePermisosFin()
  const [sp, setSp] = useSearchParams()
  const u = Number(sp.get('u') ?? 0) || null
  const mesQ = sp.get('mes')
  const mes = esMes(mesQ) ? mesQ : ymDe()
  const { data, error, isLoading } = useHoras(u, mes)
  const personas = usePersonasHoras()
  const ir = (c: Record<string, string>) => setSp((x) => ({ ...Object.fromEntries(x), ...c }), { replace: true })
  if (!p.horas) return <Notice tone="error">No tienes acceso a las horas del equipo.</Notice>
  return (
    <div className="-mx-4 -my-5 min-h-[calc(100vh-56px)] bg-[#f5f5f7] px-4 py-5 md:-mx-6 md:-my-6 md:px-6 md:py-6 lg:-mx-[52px] lg:-my-9 lg:px-[52px] lg:py-9 dark:bg-page">
      {error && <Notice tone="error">{mensajeError(error, 'No se han podido cargar las horas.')}</Notice>}
      {isLoading && <CargandoFin />}
      {data && (
        <>
          <header className="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div className="flex items-center gap-3">
              <Avatar nombre={data.persona.username} size={42} />
              <div>
                <h1 className="text-[20px] leading-tight font-semibold text-ink-strong">{data.persona.username}</h1>
                <p className="text-[13px] text-muted">
                  {data.persona.tarifa_hora ? `${eurC(data.persona.tarifa_hora)} / hora` : 'Sin tarifa configurada'}
                  {data.persona.es_autonomo && ' · autónomo'}
                </p>
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-2.5">
              {data.gestor && personas.data && (
                <div className="w-[200px]">
                  <Select
                    value={data.persona.id}
                    onChange={(v) => ir({ u: String(v) })}
                    options={personas.data.items.map((x) => ({ value: x.id, label: `${x.username}${x.es_autonomo ? ' · autónomo' : ''}` }))}
                    searchable
                    aria-label="Persona"
                  />
                </div>
              )}
              <MonthNav mes={mes} onChange={(m) => ir({ mes: m })} />
            </div>
          </header>
          <Contenido key={`${data.persona.id}-${mes}`} h={data} mes={mes} />
        </>
      )}
    </div>
  )
}

function Caja({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <section className={`rounded-[20px] bg-card px-[26px] py-6 shadow-[0_1px_2px_rgba(16,19,24,.04),0_8px_24px_-18px_rgba(16,19,24,.18)] max-sm:px-4 dark:border dark:border-line ${className}`}>{children}</section>
}

function Titulo({ icono, children, extra }: { icono: ReactNode; children: ReactNode; extra?: ReactNode }) {
  return (
    <h3 className="mb-4 flex items-center gap-2 border-b border-line2 pb-3 text-[11.5px] font-bold tracking-[.5px] text-muted uppercase [&>svg]:size-3.5">
      {icono}
      {children}
      {extra !== undefined && <span className="ml-auto text-[12.5px] font-bold tracking-normal text-ink-strong normal-case">{extra}</span>}
    </h3>
  )
}

function Contenido({ h, mes }: { h: Horas; mes: string }) {
  const { aviso } = useToast()
  const { confirm } = useConfirm()
  const p = usePermisosFin()
  const acc = useAccionHoras()
  const c = h.calculo
  const yo = h.persona.id === p.yo
  const puedeExtra = p.imputar && (yo || h.gestor)
  const fin = ultimoDia(mes)

  async function volcar() {
    const ok = await confirm({
      title: '¿Pasar las horas a Contabilidad?',
      message: `Se apuntará ${eurC(h.pendiente.importe)} como gasto de «Equipo» con fecha ${fechaCorta(fin)}. Las horas quedan marcadas para no cobrarlas dos veces.`,
      okLabel: 'Apuntar el gasto',
    })
    if (!ok) return
    try {
      const r = await acc.volcar.mutateAsync({ admin_id: h.persona.id, mes })
      aviso(r.msg)
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido volcar.'), { tipo: 'error' })
    }
  }

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-3 gap-5 max-[900px]:grid-cols-1 max-sm:gap-3">
        <Caja>
          <span className="flex items-center gap-1.5 text-[11.5px] font-bold tracking-[.5px] text-muted uppercase">
            <Clock className="size-3.5" /> Horas del mes
          </span>
          <b className="mt-3 block text-[29px] font-[750] text-ink-strong tabular-nums">{numero(c.minutos / 60, 2)} h</b>
          <p className="mt-2 text-[12.5px] text-muted">
            {c.n_tareas} tarea{c.n_tareas === 1 ? '' : 's'} · {c.n_extras} extra{c.n_extras === 1 ? '' : 's'}
          </p>
        </Caja>
        <Caja>
          <span className="flex items-center gap-1.5 text-[11.5px] font-bold tracking-[.5px] text-muted uppercase">
            <Euro className="size-3.5" /> Base
          </span>
          <b className="mt-3 block text-[29px] font-[750] text-ink-strong tabular-nums">{eurC(c.base)}</b>
          <p className="mt-2 text-[12.5px] text-muted">
            {h.persona.tarifa_hora
              ? `${numero(c.minutos / 60, 2)} h × ${eurC(h.persona.tarifa_hora)}${c.extra_importe ? ` + ${eurC(c.extra_importe)} extras` : ''}`
              : 'Configura la tarifa'}
          </p>
        </Caja>
        <Caja className="bg-[#f2faf5] dark:bg-[#121c16]">
          <span className="flex items-center gap-1.5 text-[11.5px] font-bold tracking-[.5px] text-[#12854a] uppercase dark:text-ok">
            <Euro className="size-3.5" /> Total a cobrar
          </span>
          <b className="mt-3 block text-[29px] font-[750] text-[#12854a] tabular-nums dark:text-ok">{eurC(c.total)}</b>
          <div className="mt-2 flex flex-wrap gap-1.5 text-[11.5px]">
            <span className="rounded-full border border-[#cfe9d6] bg-card px-2.5 py-0.5 dark:border-ok-line">
              Base <b>{eurC(c.base)}</b>
            </span>
            {c.iva > 0 && <span className="rounded-full border border-[#cfe9d6] bg-card px-2.5 py-0.5 dark:border-ok-line">IVA {pctCorto(h.persona.iva_pct)}% +</span>}
            {c.irpf > 0 && <span className="rounded-full border border-[#cfe9d6] bg-card px-2.5 py-0.5 dark:border-ok-line">IRPF {pctCorto(h.persona.irpf_pct)}% −</span>}
          </div>
        </Caja>
      </div>

      {h.gestor && (
        <div className={`flex flex-wrap items-center gap-4 rounded-[20px] border px-5 py-4 ${h.pendiente.n ? 'border-[#f3e3b5] bg-[#fffaf0] dark:border-[#4a3a17] dark:bg-[#2a2210]' : 'border-[#cfe9d6] bg-[#f2faf5] dark:border-ok-line dark:bg-ok-bg'}`}>
          <span className={`flex size-10 items-center justify-center rounded-xl ${h.pendiente.n ? 'bg-[#fdf1d8] text-[#b7791f]' : 'bg-[#dff3e7] text-[#12854a]'}`}>
            {h.pendiente.n ? <Zap className="size-5" /> : <Check className="size-5" />}
          </span>
          <div className="min-w-0 flex-1">
            {h.pendiente.n ? (
              <>
                <b className="block text-[14px] font-semibold text-ink-strong">Estas horas todavía no están en Contabilidad</b>
                <span className="text-[12.5px] text-muted">
                  {h.pendiente.n} registro{h.pendiente.n === 1 ? '' : 's'} pendiente{h.pendiente.n === 1 ? '' : 's'} · {hhmm(h.pendiente.minutos)} · {eurC(h.pendiente.importe)} se apuntará como gasto de «Equipo» con fecha {fechaCorta(fin)}.
                </span>
              </>
            ) : h.hay_volcadas ? (
              <>
                <b className="block text-[14px] font-semibold text-ink-strong">Este mes ya está apuntado como gasto</b>
                <span className="text-[12.5px] text-muted">Las horas de {mesLabel(mes)} ya se pasaron a un gasto. Si añades más horas después, aparecerá otra vez el botón para volcar solo las nuevas.</span>
              </>
            ) : (
              <>
                <b className="block text-[14px] font-semibold text-ink-strong">Nada que volcar a Contabilidad</b>
                <span className="text-[12.5px] text-muted">En cuanto se registren horas este mes podrás pasarlas a Contabilidad de un clic.</span>
              </>
            )}
          </div>
          <div className="flex gap-2">
            {h.hay_volcadas && p.conta && (
              <Button variant="ghost" size="sm" to={`/finanzas/contabilidad?anio=${mes.slice(0, 4)}`}>
                Ver en Contabilidad
              </Button>
            )}
            {h.pendiente.n > 0 && h.puede_volcar && (
              <Button size="sm" onClick={() => void volcar()} loading={acc.volcar.isPending} loadingText="Apuntando…">
                Pasar a gasto
              </Button>
            )}
          </div>
        </div>
      )}

      <Caja>
        <Titulo icono={<Check />} extra={hhmm(h.por_tarea.reduce((s, t) => s + t.minutos, 0))}>
          Horas por tarea
        </Titulo>
        {h.por_tarea.length === 0 && (
          <p className="py-6 text-center text-[13.5px] leading-[1.6] text-muted">
            Aún no hay tiempo registrado en tareas este mes.
            <br />
            Se registra en el campo «Tiempo» de cada tarea.
          </p>
        )}
        <ul>
          {h.por_tarea.map((t) => (
            <li key={t.task_id} className="flex items-center gap-3 border-b border-line2 py-3 last:border-b-0">
              <div className="min-w-0 flex-1">
                <Link to={`/tareas/${t.task_id}`} className="block truncate text-[14px] font-semibold text-ink-strong hover:underline">
                  {t.titulo}
                </Link>
                <span className="text-[12px] text-muted">
                  {t.cliente ?? 'Sin cliente'} · {t.n} registro{t.n === 1 ? '' : 's'}
                </span>
              </div>
              <span className="text-right">
                <b className="block text-[13.5px] font-semibold text-ink-strong tabular-nums">{hhmm(t.minutos)}</b>
                <span className="text-[12px] text-muted tabular-nums">{eurC(t.importe)}</span>
              </span>
            </li>
          ))}
        </ul>
      </Caja>

      <Caja>
        <Titulo icono={<Plus />} extra={h.extras.length}>
          Extras manuales
        </Titulo>
        {h.extras.length === 0 && <p className="py-5 text-center text-[13.5px] text-muted">Sin extras este mes.</p>}
        <ul>
          {h.extras.map((x) => (
            <li key={x.id} className="flex items-center gap-3 border-b border-line2 py-3 last:border-b-0">
              <div className="min-w-0 flex-1">
                <b className="block truncate text-[14px] font-semibold text-ink-strong">{x.concepto}</b>
                <span className="text-[12px] text-muted">
                  {fechaCorta(x.fecha)}
                  {x.volcada && ' · ya en Contabilidad'}
                </span>
              </div>
              <span className="text-right text-[13.5px] tabular-nums">
                {x.importe === null ? (
                  <>
                    <b className="block font-semibold text-ink-strong">{hhmm(x.minutos)}</b>
                    <span className="text-[12px] text-muted">{eurC(x.valor)}</span>
                  </>
                ) : (
                  <b className="font-semibold text-ink-strong">{eurC(x.importe)}</b>
                )}
              </span>
              {puedeExtra && !x.volcada && (
                <IconButton
                  label="Eliminar extra"
                  tone="danger"
                  icon={<Trash2 />}
                  onClick={async () => {
                    if (!(await confirm({ title: '¿Eliminar este extra?', danger: true }))) return
                    acc.borrar.mutate(x.id, { onError: (e) => aviso(mensajeError(e, 'No se ha podido eliminar.'), { tipo: 'error' }) })
                  }}
                />
              )}
            </li>
          ))}
        </ul>
        {puedeExtra && <FormExtra persona={h.persona} mes={mes} />}
      </Caja>

      {h.gestor && <Tarifa persona={h.persona} />}
    </div>
  )
}

function FormExtra({ persona, mes }: { persona: PersonaHoras; mes: string }) {
  const { aviso } = useToast()
  const acc = useAccionHoras()
  const [concepto, setConcepto] = useState('')
  const [tipo, setTipo] = useState<'horas' | 'importe'>('horas')
  const [valor, setValor] = useState('')
  const [fecha, setFecha] = useState<string>(() => (mes === ymDe() ? hoyIso() : `${mes}-01`))
  async function anadir() {
    if (!concepto.trim() || !leer(valor) || leer(valor) === '0.00') {
      aviso('Escribe el concepto y un valor mayor que 0.', { tipo: 'error' })
      return
    }
    try {
      await acc.extra.mutateAsync({ admin_id: persona.id, concepto, tipo, valor: leer(valor), fecha })
      setConcepto('')
      setValor('')
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido añadir.'), { tipo: 'error' })
    }
  }
  return (
    <div className="mt-4 grid grid-cols-[minmax(0,1fr)_auto_110px_150px_auto] items-center gap-2.5 border-t border-line2 pt-4 max-[900px]:grid-cols-2">
      <TextInput value={concepto} onChange={(e) => setConcepto(e.target.value)} placeholder="Concepto (ej: reunión, desplazamiento…)" aria-label="Concepto" className="max-[900px]:col-span-2" />
      <Segmented
        variant="pill"
        value={tipo}
        onChange={setTipo}
        items={[
          { value: 'horas', label: 'Horas' },
          { value: 'importe', label: 'Importe €' },
        ]}
        aria-label="Tipo de extra"
      />
      <TextInput value={valor} onChange={(e) => setValor(e.target.value)} inputMode="decimal" placeholder="0" unit={tipo === 'horas' ? 'h' : '€'} aria-label="Valor" />
      <DateInput value={fecha} onChange={(v) => setFecha(v ?? hoyIso())} aria-label="Fecha" />
      <Button onClick={() => void anadir()} loading={acc.extra.isPending} loadingText="Añadiendo…">
        Añadir
      </Button>
    </div>
  )
}

function Tarifa({ persona }: { persona: PersonaHoras }) {
  const { aviso } = useToast()
  const acc = useAccionHoras()
  const [aut, setAut] = useState(persona.es_autonomo)
  const [tarifa, setTarifa] = useState(aCampo(decimal(persona.tarifa_hora)))
  const [iva, setIva] = useState(persona.iva_pct)
  const [irpf, setIrpf] = useState(persona.irpf_pct)
  return (
    <Caja>
      <Titulo icono={<Settings />}>Tarifa y fiscalidad de {persona.username}</Titulo>
      <div className="grid grid-cols-[auto_1fr_1fr_1fr_auto] items-end gap-4 max-[900px]:grid-cols-2">
        <Checkbox checked={aut} onChange={setAut} label="Autónomo por horas" className="mb-2.5 max-[900px]:col-span-2" />
        <Field label="Tarifa / hora (€)">
          <TextInput value={tarifa} inputMode="decimal" onChange={(e) => setTarifa(e.target.value)} />
        </Field>
        <Field label="IVA %">
          <TextInput value={iva} inputMode="decimal" onChange={(e) => setIva(e.target.value)} />
        </Field>
        <Field label="IRPF %">
          <TextInput value={irpf} inputMode="decimal" onChange={(e) => setIrpf(e.target.value)} />
        </Field>
        <Button
          loading={acc.tarifa.isPending}
          loadingText="Guardando…"
          onClick={async () => {
            try {
              await acc.tarifa.mutateAsync({ id: persona.id, datos: { es_autonomo: aut, tarifa_hora: leer(tarifa) ?? '0', iva_pct: leer(iva) ?? '0', irpf_pct: leer(irpf) ?? '0' } })
              aviso('Tarifa guardada.')
            } catch (e) {
              aviso(mensajeError(e, 'No se ha podido guardar.'), { tipo: 'error' })
            }
          }}
        >
          Guardar
        </Button>
      </div>
      <p className="mt-4 text-[12.5px] leading-[1.5] text-muted">
        Para un autónomo típico en España: IVA 21%, IRPF 15%. Déjalo a 0 si solo quieres horas × tarifa. Estimación orientativa, no sustituye a tu gestoría.
      </p>
    </Caja>
  )
}
