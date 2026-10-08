import type { EstadoTarea } from '../lib/paletas'

/* El círculo de estado del tablero antiguo (estado_circle en workspace.php).
   Compartido: lo usan tareas, avisos, perfil y calendario. */
export default function EstadoCirculo({ estado, size = 16 }: { estado: EstadoTarea; size?: number }) {
  const common = { width: size, height: size, viewBox: '0 0 16 16', 'aria-hidden': true as const }
  if (estado === 'completada') {
    return (
      <svg {...common}>
        <circle cx="8" cy="8" r="8" fill="#12a150" />
        <path d="M4.6 8.3l2.2 2.2 4.6-4.8" fill="none" stroke="#fff" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
      </svg>
    )
  }
  if (estado === 'en proceso') {
    return (
      <svg {...common}>
        <circle cx="8" cy="8" r="6.6" fill="none" stroke="#3b82f6" strokeWidth="1.6" />
        <path d="M8 3.6A4.4 4.4 0 0 1 8 12.4z" fill="#3b82f6" />
      </svg>
    )
  }
  if (estado === 'atemporal') {
    return (
      <svg {...common}>
        <circle cx="8" cy="8" r="6.6" fill="none" stroke="#e0a000" strokeWidth="1.6" strokeDasharray="2.6 2" />
      </svg>
    )
  }
  return (
    <svg {...common}>
      <circle cx="8" cy="8" r="6.6" fill="none" stroke="#b0b4bb" strokeWidth="1.6" />
    </svg>
  )
}
