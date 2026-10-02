import { useEffect, type ReactNode } from 'react'

/* El armazón de las pantallas de acceso (login, recuperar y restablecer la
   contraseña): panel de marca oscuro a la izquierda y el formulario en claro.
   Es la maqueta de login.txt; las tres pantallas la comparten tal cual. */

export const MARCA = 'Croilab'

const FEATURES = [
  { label: 'Gestión de clientes y proyectos', icon: <polyline points="20 6 9 17 4 12" />, width: 2.2 },
  {
    label: 'Facturación y contabilidad',
    icon: (
      <>
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
        <line x1="16" y1="2" x2="16" y2="6" />
        <line x1="8" y1="2" x2="8" y2="6" />
        <line x1="3" y1="10" x2="21" y2="10" />
      </>
    ),
    width: 2,
  },
  {
    label: 'Tareas y equipo',
    icon: (
      <>
        <path d="M9 11l3 3L22 4" />
        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" />
      </>
    ),
    width: 2,
  },
]

type Props = {
  /* Título de la pestaña del navegador. */
  pestana: string
  titulo: string
  subtitulo: ReactNode
  children: ReactNode
}

export default function AuthLayout({ pestana, titulo, subtitulo, children }: Props) {
  useEffect(() => {
    const prev = document.title
    document.title = `${MARCA} - ${pestana}`
    return () => {
      document.title = prev
    }
  }, [pestana])

  return (
    <div className="font-jakarta min-h-screen w-full bg-[#111215] text-white flex flex-col md:flex-row antialiased">
      <aside className="relative w-full md:w-[48%] lg:w-[45%] xl:w-[42%] md:min-h-screen bg-[#111216] px-8 sm:px-12 lg:px-16 py-8 sm:py-12 flex flex-col justify-between md:border-r md:border-[#1e2025] select-none">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-white flex items-center justify-center shadow-sm text-black font-extrabold text-xl leading-none" aria-hidden="true">
            {MARCA.charAt(0)}
          </div>
          <span className="text-white font-bold text-xl tracking-tight">{MARCA}</span>
        </div>

        <div className="py-8 md:py-12 my-auto max-w-md">
          <h1 className="text-4xl sm:text-[42px] font-extrabold text-white leading-[1.18] tracking-tight mb-5">
            Tu agencia, ordenada
            <br />
            en un solo sitio.
          </h1>
          <p className="text-[#9CA3AF] text-base sm:text-lg leading-relaxed font-normal">
            Clientes, facturación, tareas y equipo. Todo en el panel de gestión de {MARCA}.
          </p>
        </div>

        <ul className="hidden md:block space-y-4 pt-6 max-w-sm" role="list">
          {FEATURES.map((f) => (
            <li key={f.label} className="flex items-center gap-3.5">
              <div className="w-9 h-9 rounded-lg bg-[#1f2229] border border-[#2d313a] flex items-center justify-center shrink-0 text-gray-300" aria-hidden="true">
                <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={f.width} strokeLinecap="round" strokeLinejoin="round">
                  {f.icon}
                </svg>
              </div>
              <span className="text-sm sm:text-[15px] font-medium text-gray-200">{f.label}</span>
            </li>
          ))}
        </ul>
      </aside>

      <main className="flex-1 bg-[#F9FAFB] flex flex-col justify-between items-center px-6 sm:px-10 lg:px-16 pt-6 pb-8 sm:py-12 text-[#111827]">
        <div className="w-full" />

        <div className="w-full max-w-[420px] mx-auto pt-2 pb-8 sm:py-8">
          <div className="mb-8">
            <h2 className="text-3xl sm:text-[34px] leading-9 font-bold text-gray-950 tracking-tight mb-2">{titulo}</h2>
            <p className="text-gray-500 text-sm sm:text-[15px]">{subtitulo}</p>
          </div>
          {children}
        </div>

        <footer className="w-full text-center py-2">
          <p className="text-xs text-gray-400 font-normal">
            Panel privado · {MARCA} © {new Date().getFullYear()}
          </p>
        </footer>
      </main>
    </div>
  )
}
