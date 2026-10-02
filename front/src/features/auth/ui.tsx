import { useState, type InputHTMLAttributes, type ReactNode } from 'react'

/* Piezas del formulario de acceso, con las clases exactas de la maqueta
   (login.txt) para que login, recuperar y restablecer sean idénticos. */

const INPUT =
  'w-full pl-11 py-3 bg-white border border-gray-300 rounded-xl text-sm sm:text-base text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-900 focus:border-transparent transition-all shadow-sm'

export function IconoUsuario() {
  return (
    <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
      <path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
    </svg>
  )
}

const IconoCandado = (
  <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
    <path d="M7 11V7a5 5 0 0 1 10 0v4" />
  </svg>
)

type CampoProps = { id: string; etiqueta: string; icono: ReactNode } & InputHTMLAttributes<HTMLInputElement>

export function Campo({ id, etiqueta, icono, className = '', ...input }: CampoProps) {
  return (
    <div>
      <label className="block text-xs font-semibold text-gray-700 mb-2" htmlFor={id}>
        {etiqueta}
      </label>
      <div className="relative">
        <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400" aria-hidden="true">
          {icono}
        </div>
        <input id={id} className={`${INPUT} pr-4 ${className}`} {...input} />
      </div>
    </div>
  )
}

/* Contraseña con el ojo para mostrarla u ocultarla. */
export function CampoContrasena({ id, etiqueta, ...input }: Omit<CampoProps, 'icono' | 'type'>) {
  const [ver, setVer] = useState(false)
  return (
    <div>
      <label className="block text-xs font-semibold text-gray-700 mb-2" htmlFor={id}>
        {etiqueta}
      </label>
      <div className="relative">
        <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400" aria-hidden="true">
          {IconoCandado}
        </div>
        <input id={id} className={`${INPUT} pr-11`} type={ver ? 'text' : 'password'} {...input} />
        <button
          type="button"
          className="absolute inset-y-0 right-0 pr-3.5 pl-2 flex items-center text-gray-400 hover:text-gray-600 focus:outline-none focus-visible:text-gray-900"
          onClick={() => setVer((v) => !v)}
          aria-label={ver ? 'Ocultar contraseña' : 'Mostrar contraseña'}
          aria-pressed={ver}
        >
          {ver ? (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round">
              <path d="M3 3l18 18" />
              <path d="M10.584 10.587a2 2 0 002.828 2.83" />
              <path d="M9.363 5.365A9.466 9.466 0 0112 5c4.478 0 8.268 2.943 9.542 7a10.05 10.05 0 01-1.563 3.029M6.228 6.228A10.05 10.05 0 002.458 12C3.732 16.057 7.523 19 12 19a9.97 9.97 0 005.39-1.574" />
            </svg>
          ) : (
            <svg className="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round">
              <path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              <path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
          )}
        </button>
      </div>
    </div>
  )
}

export function BotonPrincipal({ children, ocupado, textoOcupado }: { children: ReactNode; ocupado: boolean; textoOcupado: string }) {
  return (
    <button
      type="submit"
      className="w-full bg-[#141518] hover:bg-black text-white font-medium py-3.5 px-4 rounded-xl transition-all duration-150 flex items-center justify-center gap-2 shadow-sm active:scale-[0.99] disabled:opacity-70 disabled:active:scale-100 cursor-pointer disabled:cursor-default"
      disabled={ocupado}
    >
      <span>{ocupado ? textoOcupado : children}</span>
      {!ocupado && (
        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.2} strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
          <line x1="5" y1="12" x2="19" y2="12" />
          <polyline points="12 5 19 12 12 19" />
        </svg>
      )}
    </button>
  )
}

const AVISOS = {
  error: 'border-red-200 bg-red-50 text-red-700',
  info: 'border-gray-200 bg-white text-gray-600',
  ok: 'border-emerald-200 bg-emerald-50 text-emerald-800',
}

export function Aviso({ tipo, children }: { tipo: keyof typeof AVISOS; children: ReactNode }) {
  return (
    <div className={`rounded-xl border px-3.5 py-2.5 text-[13.5px] leading-normal ${AVISOS[tipo]}`} role={tipo === 'error' ? 'alert' : 'status'}>
      {children}
    </div>
  )
}

/* Enlace discreto bajo el botón («¿Has olvidado tu contraseña?», «Volver…»). */
export const ENLACE = 'text-xs sm:text-sm text-gray-500 hover:text-gray-900 transition-colors cursor-pointer'
