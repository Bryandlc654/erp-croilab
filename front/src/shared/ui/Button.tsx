import type { AnchorHTMLAttributes, ButtonHTMLAttributes, ReactNode, Ref } from 'react'
import { Link } from 'react-router-dom'
import { claseBoton, type ButtonSize, type ButtonVariant } from './clases'

type Comunes = {
  variant?: ButtonVariant
  size?: ButtonSize
  icon?: ReactNode
  /* Mientras guarda: desactivado y con `loadingText` («Guardando…»). */
  loading?: boolean
  loadingText?: string
  children?: ReactNode
  className?: string
}

type ComoBoton = Comunes & ButtonHTMLAttributes<HTMLButtonElement> & { to?: undefined; href?: undefined; ref?: Ref<HTMLButtonElement> }
type ComoLink = Comunes & Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & { to: string; href?: undefined; ref?: Ref<HTMLAnchorElement> }
type ComoA = Comunes & AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; to?: undefined; ref?: Ref<HTMLAnchorElement> }

/* Botón del ERP (.btn, .btn.ghost, .btn.danger, .btn.sm…). Con `to` es un
   enlace del router y con `href` un enlace normal, con el mismo aspecto. */
export default function Button(props: ComoBoton | ComoLink | ComoA) {
  const { variant, size, icon, loading = false, loadingText, children, className, ...resto } = props
  const clase = claseBoton({ variant, size, className })
  const contenido = (
    <>
      {icon}
      {loading && loadingText ? loadingText : children}
    </>
  )
  if ('to' in resto && resto.to !== undefined) {
    const { to, ...a } = resto as ComoLink
    return (
      <Link to={to} className={clase} {...a}>
        {contenido}
      </Link>
    )
  }
  if ('href' in resto && resto.href !== undefined) {
    return (
      <a className={clase} {...(resto as ComoA)}>
        {contenido}
      </a>
    )
  }
  const b = resto as ButtonHTMLAttributes<HTMLButtonElement> & { ref?: Ref<HTMLButtonElement> }
  return (
    <button type="button" {...b} disabled={b.disabled || loading} aria-busy={loading || undefined} className={clase}>
      {contenido}
    </button>
  )
}
