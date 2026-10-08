import type { ReactNode } from 'react'
import { BarChart3, FileText, ListChecks, Moon, Sun } from 'lucide-react'
import { useTema } from '../../../../shared/lib/theme'
import type { Marca } from '../../schemas'
import { useClasePortal } from '../../clasePortal'
import { LogoMarca } from '../ui'

/* Pantallas de acceso del portal: panel oscuro con la marca (la de la agencia
   del enlace ?m=, si la hay) y la tarjeta a la derecha. */
export default function MarcoAcceso({ marca, children }: { marca: Marca | null; children: ReactNode }) {
  useClasePortal()
  const { tema, alternar } = useTema()
  const nombre = marca?.name ?? ''
  return (
    <div className="portal grid min-h-screen bg-(--p-bg) text-(--p-ink) md:grid-cols-[1fr_1fr]">
      <aside className="relative flex flex-col justify-between overflow-hidden bg-[radial-gradient(ellipse_at_80%_10%,#2a2d33_0%,#14161a_45%,#0f1012_100%)] px-[60px] py-14 text-white max-md:px-6 max-md:py-6">
        <div className="flex items-center gap-3">
          {marca && <LogoMarca nombre={nombre} inicial={marca.initial} logo={marca.logo} color={marca.color} size={46} claro />}
          <span className="text-[17px] font-bold">{nombre}</span>
        </div>
        <div className="max-md:hidden">
          <h2 className="max-w-[360px] text-[34px] leading-[1.15] font-extrabold tracking-tight">El estado de tu proyecto, en un solo sitio.</h2>
          <p className="mt-4 max-w-[340px] text-[15px] text-white/70">Tus métricas, el trabajo mes a mes, informes, reuniones y facturas de {nombre}.</p>
        </div>
        <ul className="flex flex-col gap-3 text-[14.5px] font-medium max-md:hidden">
          {(
            [
              [<BarChart3 key="a" />, 'Tus métricas y resultados'],
              [<ListChecks key="b" />, 'El trabajo de tu proyecto, mes a mes'],
              [<FileText key="c" />, 'Informes, reuniones y facturas'],
            ] as const
          ).map(([ic, t]) => (
            <li key={t} className="flex items-center gap-3">
              <span className="flex size-8 items-center justify-center rounded-lg border border-white/15 [&_svg]:size-4">{ic}</span>
              {t}
            </li>
          ))}
        </ul>
      </aside>
      <main className="relative flex items-center justify-center px-6 py-14 max-md:items-start max-md:py-8">
        <button
          type="button"
          onClick={alternar}
          className="absolute top-5 right-5 flex size-11 items-center justify-center rounded-xl border border-(--p-line) bg-(--p-card) text-(--p-ink-strong)"
          aria-label={tema === 'dark' ? 'Modo claro' : 'Modo oscuro'}
        >
          {tema === 'dark' ? <Sun className="size-4" /> : <Moon className="size-4" />}
        </button>
        <div className="w-full max-w-[400px]">{children}</div>
      </main>
    </div>
  )
}
