import { useState, type ReactNode } from 'react'
import { NavLink, useLocation, useNavigate } from 'react-router-dom'
import {
  BarChart3,
  CalendarDays,
  CircleCheckBig,
  ClipboardCheck,
  Download,
  FileText,
  LayoutGrid,
  Link2,
  ListChecks,
  LogOut,
  Menu,
  MessageSquare,
  Moon,
  PlayCircle,
  Receipt,
  Search,
  Sun,
  X,
} from 'lucide-react'
import { useTema } from '../../../shared/lib/theme'
import { useToast } from '../../../shared/ui/useToast'
import { usePortal } from '../contexto'
import { buscar, nombreMes, vistaVisible, type Vista } from '../logica'
import { serviciosPortal } from '../textos'
import { LogoMarca, PAvatar } from './ui'
import InformeImprimible from './InformeImprimible'

type Item = { vista: Vista; label: string; icono: ReactNode; n?: number }

/* El marco del portal: raíl oscuro, barra lateral con las secciones que ve el
   cliente, cabecera con saludo, buscador, informe, PDF y tema. En ≤980px la
   barra es un cajón. */
export default function PortalLayout({ children, onSalir, arriba = 0 }: { children: ReactNode; onSalir: (() => void) | null; arriba?: number }) {
  const { datos: d, ruta, editar } = usePortal()
  const { tema, alternar } = useTema()
  const [cajon, setCajon] = useState(false)
  const [q, setQ] = useState('')
  const [mesPdf, setMesPdf] = useState<string | null>(null)
  const nav = useNavigate()
  const loc = useLocation()
  const { aviso } = useToast()

  const s = d.secciones
  const hoy = new Date().toISOString().slice(0, 10)
  const abiertas = d.tareas.filter((t) => t.estado !== 'completada').length
  const proximas = d.reuniones.filter((r) => r.fecha && r.fecha >= hoy && r.estado !== 'cancelada' && r.estado !== 'realizada').length
  const soporteAbierto = d.tickets.filter((t) => ['abierto', 'en_curso', 'esperando'].includes(t.estado)).length
  const grupos: { titulo: string; items: Item[] }[] = [
    {
      titulo: 'Tu proyecto',
      items: [
        { vista: 'inicio', label: 'Inicio', icono: <LayoutGrid /> },
        { vista: 'metricas', label: 'Métricas', icono: <BarChart3 /> },
        { vista: 'tareas', label: 'Tareas', icono: <ListChecks />, n: abiertas },
        ...(d.progreso.length || editar ? [{ vista: 'progreso' as const, label: 'Progreso', icono: <CircleCheckBig /> }] : []),
        { vista: 'reuniones', label: 'Reuniones', icono: <CalendarDays />, n: proximas },
      ],
    },
    {
      titulo: 'Documentos',
      items: [
        { vista: 'informes', label: 'Informes', icono: <FileText /> },
        { vista: 'facturas', label: 'Facturas', icono: <Receipt />, n: d.facturas.length },
      ],
    },
    {
      titulo: 'Ayuda',
      items: [
        { vista: 'soporte', label: 'Soporte', icono: <MessageSquare />, n: soporteAbierto },
        { vista: 'metodo', label: 'Método', icono: <PlayCircle /> },
        { vista: 'accesos', label: 'Accesos', icono: <Link2 /> },
        { vista: 'plan', label: 'Plan', icono: <ClipboardCheck /> },
      ],
    },
  ]

  const actual = d.cliente.actual
  const informeActual = d.informes.find((i) => i.clave === actual)
  const buscarAhora = () => {
    const dest = buscar(q, d, serviciosPortal(d.catalogo).map((x) => x.nombre))
    if (!dest) return aviso(`No encontré nada con «${q.trim()}»`, { tipo: 'plain' })
    setQ('')
    nav(dest.tipo === 'servicio' ? ruta('metodo', encodeURIComponent(dest.nombre)) : ruta(dest.vista))
  }
  const descargarPdf = () => {
    const m = new URLSearchParams(loc.search).get('mes')
    setMesPdf(m && m !== 'global' ? m : actual)
    // Se pinta el informe y luego se abre el diálogo de imprimir (Guardar como PDF).
    setTimeout(() => {
      window.print()
      setMesPdf(null)
    }, 60)
  }

  const barra = (
    <nav aria-label="Secciones" className="flex h-full flex-col">
      <div className="flex items-center gap-2.5 px-5 pt-[18px] pb-3">
        <LogoMarca nombre={d.marca.name} inicial={d.marca.initial} logo={d.marca.logo} color={d.marca.color} size={32} />
        <span className="truncate text-[15px] font-bold text-(--p-ink-strong)">{d.marca.name}</span>
        <button type="button" onClick={() => setCajon(false)} className="ml-auto rounded-lg p-1.5 text-(--p-muted) min-[981px]:hidden" aria-label="Cerrar menú">
          <X className="size-5" />
        </button>
      </div>
      <div className="flex-1 overflow-y-auto px-2.5 pb-4">
        {grupos.map((g) => {
          const items = g.items.filter((i) => vistaVisible(i.vista, s))
          if (!items.length) return null
          return (
            <div key={g.titulo} className="mt-3">
              <p className="px-2.5 pb-1.5 text-[11px] font-bold uppercase tracking-[.06em] text-(--p-muted)">{g.titulo}</p>
              {items.map((i) => (
                <NavLink
                  key={i.vista}
                  to={ruta(i.vista)}
                  end={i.vista === 'inicio'}
                  onClick={() => setCajon(false)}
                  className={({ isActive }) =>
                    `flex items-center gap-3 rounded-xl px-3 py-[9px] text-[14px] transition [&_svg]:size-[17px] ${
                      isActive ? 'bg-(--p-soft) font-bold text-(--p-ink-strong)' : 'text-(--p-ink) hover:bg-(--p-soft)'
                    }`
                  }
                >
                  <span className="text-(--p-muted)">{i.icono}</span>
                  <span className="flex-1">{i.label}</span>
                  {!!i.n && <span className="min-w-[20px] rounded-full bg-(--p-soft) px-1.5 text-center text-[11px] font-bold text-(--p-ink-strong)">{i.n}</span>}
                </NavLink>
              ))}
            </div>
          )
        })}
      </div>
      <div className="flex items-center gap-2.5 border-t border-(--p-line) px-4 py-3.5">
        <PAvatar nombre={d.cliente.name} ini={d.cliente.iniciales} size={34} color="#0ea5e9" />
        <div className="min-w-0 flex-1">
          <p className="truncate text-[13.5px] font-semibold text-(--p-ink-strong)">{d.cliente.name}</p>
          <p className="text-[11.5px] text-(--p-muted)">Área de cliente</p>
        </div>
        {onSalir && (
          <button type="button" onClick={onSalir} className="rounded-lg p-1.5 text-(--p-muted) hover:bg-(--p-soft) hover:text-(--p-ink)" aria-label="Cerrar sesión" title="Cerrar sesión">
            <LogOut className="size-[17px]" />
          </button>
        )}
      </div>
    </nav>
  )

  return (
    <div className="portal min-h-screen bg-(--p-bg) text-(--p-ink) print:min-h-0">
      <div className="flex print:hidden">
        {/* Raíl oscuro */}
        <aside className="sticky flex w-[62px] shrink-0 flex-col items-center bg-(--p-dark) py-3 max-[980px]:hidden" style={{ top: arriba, height: `calc(100vh - ${arriba}px)` }}>
          <LogoMarca nombre={d.marca.name} inicial={d.marca.initial} logo={d.marca.logo} color={d.marca.color} size={38} claro />
          <div className="mt-auto flex flex-col items-center gap-3">
            {onSalir && (
              <button type="button" onClick={onSalir} className="rounded-lg p-2 text-white/60 hover:bg-white/10 hover:text-white" aria-label="Cerrar sesión" title="Cerrar sesión">
                <LogOut className="size-[18px]" />
              </button>
            )}
            <PAvatar nombre={d.cliente.name} ini={d.cliente.iniciales} size={32} color="#0ea5e9" />
          </div>
        </aside>
        {/* Barra lateral (cajón en móvil) */}
        <aside className="sticky w-[236px] shrink-0 border-r border-(--p-line) bg-(--p-card) max-[980px]:hidden" style={{ top: arriba, height: `calc(100vh - ${arriba}px)` }}>
          {barra}
        </aside>
        {cajon && (
          <div className="fixed inset-0 z-[900] min-[981px]:hidden" role="dialog" aria-modal="true" aria-label="Menú">
            <button type="button" className="absolute inset-0 bg-black/40" aria-label="Cerrar menú" onClick={() => setCajon(false)} />
            <div className="absolute inset-y-0 left-0 w-[272px] max-w-[85vw] bg-(--p-card) shadow-2xl">{barra}</div>
          </div>
        )}

        <main className="min-w-0 flex-1 px-6 pt-5 pb-12 max-sm:px-4">
          <header className="mb-5 flex flex-wrap items-start gap-3">
            <button type="button" onClick={() => setCajon(true)} className="-ml-1 rounded-xl border border-(--p-line) bg-(--p-card) p-2 min-[981px]:hidden" aria-label="Abrir menú">
              <Menu className="size-5" />
            </button>
            <div className="min-w-0 flex-1">
              <h1 className="text-[26px] font-extrabold tracking-tight text-(--p-ink-strong) max-sm:text-[22px]">Hola, {d.cliente.saludo} 👋</h1>
              <p className="text-[14px] text-(--p-muted)">Echemos un vistazo a tu proyecto · {d.cliente.actual_etiqueta.toLowerCase()}</p>
            </div>
            <div className="flex flex-wrap items-center gap-2 max-[720px]:w-full">
              <label className="relative max-[720px]:flex-1">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-(--p-muted)" />
                <span className="sr-only">Buscar en tu proyecto</span>
                <input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  onKeyDown={(e) => e.key === 'Enter' && buscarAhora()}
                  placeholder="Buscar en tu proyecto"
                  className="h-10 w-[228px] rounded-xl border border-(--p-line) bg-(--p-card) pr-3 pl-9 text-[14px] outline-none focus:border-(--p-muted) max-[720px]:w-full max-sm:text-[16px]"
                />
              </label>
              {s.informes && (
                <NavLink to={ruta('informes')} className="inline-flex h-10 items-center gap-2 rounded-xl bg-(--p-acc) px-4 text-[14px] font-semibold text-(--p-acc-fg) hover:opacity-90 max-sm:hidden">
                  <FileText className="size-4" />
                  {informeActual ? `Informe de ${nombreMes(actual).toLowerCase()}` : 'Ver informes'}
                </NavLink>
              )}
              <button type="button" onClick={descargarPdf} className="inline-flex h-10 items-center gap-2 rounded-xl border border-(--p-line) bg-(--p-card) px-4 text-[14px] font-semibold text-(--p-ink-strong) hover:bg-(--p-soft)">
                <Download className="size-4" />
                <span className="max-sm:hidden">Descargar PDF</span>
              </button>
              <button
                type="button"
                onClick={alternar}
                className="inline-flex size-10 items-center justify-center rounded-xl border border-(--p-line) bg-(--p-card) hover:bg-(--p-soft)"
                aria-label={tema === 'dark' ? 'Modo claro' : 'Modo oscuro'}
              >
                {tema === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
              </button>
            </div>
          </header>
          {children}
        </main>
      </div>
      {mesPdf && <InformeImprimible mes={mesPdf} />}
    </div>
  )
}
