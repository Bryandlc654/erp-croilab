import { Link } from 'react-router-dom'
import { ArrowRight, BarChart3, CheckCircle2, Clock, ListChecks, MessageCircle, Phone, ClipboardList } from 'lucide-react'
import { usePortal } from '../../contexto'
import { conAnterior, delta, miles, nombreMes, textoDelta } from '../../logica'
import Editable from '../Editable'
import { useCuenta } from '../useCuenta'
import { Eyebrow, Tarjeta } from '../ui'
import EstadoProyecto from './EstadoProyecto'

export default function Inicio() {
  const { datos: d, ruta } = usePortal()
  const enCurso = d.tareas.filter((t) => t.estado !== 'completada')
  const verMetricas = d.secciones.metricas
  return (
    <div className="flex flex-col gap-4">
      {/* Banner de tareas */}
      <Tarjeta className="flex items-center gap-3.5 px-[18px] py-3.5">
        <span className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${enCurso.length ? 'bg-(--p-soft) text-(--p-muted)' : 'bg-[#12a150]/10 text-(--p-green)'}`}>
          {enCurso.length ? <Clock className="size-5" /> : <CheckCircle2 className="size-5" />}
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-[14.5px] font-semibold text-(--p-ink-strong)">{enCurso.length ? `En curso: ${enCurso[0].titulo}` : 'Todo al día'}</p>
          <p className="text-[12.5px] text-(--p-muted)">
            {enCurso.length ? (enCurso.length === 1 ? '1 tarea en marcha' : `Tienes ${enCurso.length} tareas en marcha`) : 'No hay tareas pendientes ahora mismo'}
          </p>
        </div>
        <Link to={ruta('tareas')} className="inline-flex items-center gap-1 text-[13.5px] font-semibold whitespace-nowrap text-(--p-ink-strong) dark:text-(--p-acc)">
          Ver tareas <ArrowRight className="size-3.5" />
        </Link>
      </Tarjeta>

      {verMetricas && <Resultado />}

      <Editable bloque="estado" label="Editar estado del proyecto">
        <EstadoProyecto conEnlace />
      </Editable>

      <div>
        <Eyebrow className="mb-2.5 px-1.5">¿Qué quieres ver?</Eyebrow>
        <div className={`grid gap-3.5 ${verMetricas ? 'md:grid-cols-2' : ''}`}>
          <Tile to={ruta('tareas')} icono={<ListChecks />} titulo="Lo que hemos hecho" texto="Todo el trabajo, mes a mes." accion="Ver tareas" />
          {verMetricas && <Tile to={ruta('metricas')} icono={<BarChart3 />} titulo="Tus números" texto="Contactos y visibilidad en Google." accion="Ver métricas" />}
        </div>
      </div>
    </div>
  )
}

function Tile({ to, icono, titulo, texto, accion }: { to: string; icono: React.ReactNode; titulo: string; texto: string; accion: string }) {
  return (
    <Link to={to} className="flex items-start gap-3.5 rounded-[20px] border border-(--p-line) bg-(--p-card) p-5 shadow-(--p-shadow) transition hover:-translate-y-0.5">
      <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-(--p-acc-soft) text-(--p-ink-strong) dark:text-(--p-acc) [&_svg]:size-5">{icono}</span>
      <span>
        <b className="block text-[15px] text-(--p-ink-strong)">{titulo}</b>
        <span className="block text-[13px] text-(--p-muted)">{texto}</span>
        <span className="mt-1.5 block text-[12.5px] font-bold text-(--p-ink-strong)">{accion} →</span>
      </span>
    </Link>
  )
}

/* «Tu resultado de {mes}»: oportunidades del mes actual frente al anterior. */
function Resultado() {
  const { datos: d } = usePortal()
  const { actual, anterior } = conAnterior(d.metricas, d.cliente.actual)
  const total = actual?.total ?? 0
  const n = useCuenta(total)
  const dd = delta(total, anterior ? anterior.total : null)
  const frase =
    !actual || total === 0
      ? actual
        ? 'sin contactos todavía'
        : 'primer mes con datos'
      : dd.tipo === 'sube'
        ? `▲ ${dd.pct}% más que el mes pasado`
        : dd.tipo === 'baja'
          ? `▼ ${Math.abs(dd.pct)}% que el mes pasado`
          : dd.tipo === 'nuevo'
            ? '▲ primer mes con contactos'
            : dd.tipo === 'igual'
              ? 'igual que el mes pasado'
              : 'primer mes con datos'
  const mini: [string, React.ReactNode, number, number | null][] = [
    ['Llamadas', <Phone key="l" />, actual?.ll ?? 0, anterior ? anterior.ll : null],
    ['WhatsApp', <MessageCircle key="w" />, actual?.wa ?? 0, anterior ? anterior.wa : null],
    ['Formularios', <ClipboardList key="f" />, actual?.fo ?? 0, anterior ? anterior.fo : null],
  ]
  return (
    <Tarjeta className="p-[26px] max-sm:p-5">
      <Eyebrow>Tu resultado de {nombreMes(d.cliente.actual).toLowerCase()}</Eyebrow>
      <div className="mt-3 flex items-center gap-4">
        <span className="text-[56px] leading-none font-black tracking-tight text-(--p-ink-strong) dark:text-(--p-acc)">{miles(n)}</span>
        <div>
          <p className="text-[18px] font-bold text-(--p-ink-strong)">oportunidades de contacto</p>
          <p className={`text-[13.5px] font-semibold ${dd.tipo === 'baja' ? 'text-(--p-red)' : dd.tipo === 'sube' || dd.tipo === 'nuevo' ? 'text-(--p-green)' : 'text-(--p-muted)'}`}>{frase}</p>
        </div>
      </div>
      <p className="mt-4 max-w-[520px] text-[14.5px] text-(--p-muted)">
        Son las veces que alguien os ha llamado, escrito por WhatsApp o rellenado un formulario este mes. Es lo que de verdad os trae clientes.
      </p>
      <div className="mt-5 grid grid-cols-3 gap-3 max-sm:gap-2">
        {mini.map(([l, ic, v, a]) => {
          const x = delta(v, a)
          return (
            <div key={l} className="flex flex-col items-center rounded-2xl border border-(--p-line) bg-(--p-card2) px-2 py-4 text-center">
              <span className="flex size-9 items-center justify-center rounded-xl bg-(--p-acc) text-white dark:bg-(--p-soft) [&_svg]:size-4">{ic}</span>
              <span className="mt-2 text-[22px] font-extrabold text-(--p-ink-strong)">{miles(v)}</span>
              <span className="text-[12.5px] text-(--p-muted)">{l}</span>
              <span className={`text-[11.5px] font-bold ${x.tipo === 'baja' ? 'text-(--p-red)' : x.tipo === 'sube' || x.tipo === 'nuevo' ? 'text-(--p-green)' : 'text-(--p-muted)'}`}>{textoDelta(x)}</span>
            </div>
          )
        })}
      </div>
    </Tarjeta>
  )
}
