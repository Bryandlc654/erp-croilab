import type { CSSProperties } from 'react'
import { url } from '../api/client'
import { colorDe, iniciales } from '../lib/avatar'

type Props = {
  nombre: string
  inicialesGuardadas?: string | null
  foto?: string | null
  size?: number
  /* Persona: círculo. Cliente: cuadrado redondeado. */
  forma?: 'circulo' | 'cuadrado'
  className?: string
  style?: CSSProperties
}

export default function Avatar({ nombre, inicialesGuardadas, foto, size = 24, forma = 'circulo', className = '', style }: Props) {
  const radio = forma === 'circulo' ? '9999px' : `${Math.round(size * 0.29)}px`
  return (
    <span
      aria-hidden="true"
      className={`inline-flex shrink-0 select-none items-center justify-center bg-cover bg-center font-bold text-white ${className}`}
      style={{
        width: size,
        height: size,
        borderRadius: radio,
        fontSize: Math.max(9, Math.round(size * 0.42)),
        backgroundColor: colorDe(nombre),
        backgroundImage: foto ? `url("${url('/' + foto)}")` : undefined,
        ...style,
      }}
    >
      {foto ? null : iniciales(nombre, inicialesGuardadas)}
    </span>
  )
}
