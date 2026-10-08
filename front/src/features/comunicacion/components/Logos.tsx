import { useId, type ReactNode } from 'react'

/* Logos de Google Calendar, Meet y Gemini (los SVG de lib/logos.php del antiguo). */

export function LogoGcal({ size = 26 }: { size?: number }) {
  return (
    <svg viewBox="0 0 48 48" width={size} height={size} aria-hidden="true">
      <rect x="9" y="9" width="30" height="30" rx="5" fill="#fff" stroke="#e6e6e6" />
      <path d="M17 9h14v6H17z" fill="#4285F4" />
      <path d="M33 15h6v14h-6z" fill="#FBBC04" />
      <path d="M17 33h14v6H17z" fill="#34A853" />
      <path d="M9 15h6v14H9z" fill="#EA4335" />
      <rect x="15" y="15" width="18" height="18" fill="#fff" />
      <text x="24" y="29" fontSize="13" fontWeight="700" fill="#4285F4" textAnchor="middle" fontFamily="Arial,Helvetica,sans-serif">
        31
      </text>
    </svg>
  )
}

export function LogoMeet({ size = 18 }: { size?: number }) {
  return (
    <svg viewBox="0 0 87.5 72" width={Math.round((size * 87.5) / 72)} height={size} aria-hidden="true">
      <path fill="#00832d" d="M49.5 36l8.53 9.75 11.47 7.33 2-17.02-2-16.64-11.69 6.44z" />
      <path fill="#0066da" d="M0 51.5V66c0 3.315 2.685 6 6 6h14.5l3-10.96-3-9.54-9.95-3z" />
      <path fill="#e94235" d="M20.5 0L0 20.5l10.55 3 9.95-3 2.95-9.41z" />
      <path fill="#2684fc" d="M0 20.5h20.5v31H0z" />
      <path fill="#00ac47" d="M82.6 8.68L69.5 19.42v33.66l13.16 10.79c1.97 1.54 4.85.135 4.85-2.37V11c0-2.535-2.945-3.925-4.91-2.32z" />
      <path fill="#ffba00" d="M49.5 36v15.5H20.5V72h43c3.315 0 6-2.685 6-6V53.08z" />
      <path fill="#00ac47" d="M63.5 0h-43v20.5h29V36l20-16.58V6c0-3.315-2.685-6-6-6z" />
    </svg>
  )
}

export function LogoGemini({ size = 16 }: { size?: number }) {
  const id = useId().replace(/:/g, '')
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} aria-hidden="true">
      <defs>
        <linearGradient id={id} x1="2" y1="20" x2="22" y2="4" gradientUnits="userSpaceOnUse">
          <stop stopColor="#1BA1E3" />
          <stop offset=".3" stopColor="#5489D6" />
          <stop offset=".55" stopColor="#9B72CB" />
          <stop offset=".82" stopColor="#D96570" />
          <stop offset="1" stopColor="#F49C46" />
        </linearGradient>
      </defs>
      <path fill={`url(#${id})`} d="M12 24A14.304 14.304 0 0 0 0 12 14.304 14.304 0 0 0 12 0a14.305 14.305 0 0 0 12 12 14.305 14.305 0 0 0-12 12Z" />
    </svg>
  )
}

/* Logo dentro de su recuadro blanco (cabeceras de Reuniones y Calendario). */
export function CajaLogo({ size = 52, children }: { size?: number; children: ReactNode }) {
  return (
    <span className="inline-flex shrink-0 items-center justify-center rounded-[14px] border border-line bg-card shadow-[0_1px_2px_rgba(16,19,24,.04)]" style={{ width: size, height: size }}>
      {children}
    </span>
  )
}
