import type { CSSProperties } from 'react'
import { ESTADOS_VIVOS, type EstadoTarea } from '../../../shared/lib/paletas'

/* Colores del calendario: tareas con la paleta «viva» de estados; eventos con
   el color de su agenda (#4285F4 los míos, la paleta de compañeros el resto). */

export const COLOR_GOOGLE = '#4285F4'

export function colorEstado(estado: string) {
  return ESTADOS_VIVOS[estado as EstadoTarea]?.color ?? '#c8ccd2'
}

/* El color de cada agenda va en una variable: fondo tintado, borde sólido y
   texto algo más oscuro (en oscuro, más claro) para que se lea. */
export function varColor(color: string): CSSProperties {
  return { '--ev': color } as CSSProperties
}

export const TINTE_EVENTO =
  'border-l-[3px] border-l-(--ev) bg-[color-mix(in_srgb,var(--ev)_12%,transparent)] text-[color-mix(in_srgb,var(--ev)_80%,#000)] dark:bg-[color-mix(in_srgb,var(--ev)_20%,transparent)] dark:text-[color-mix(in_srgb,var(--ev)_55%,#fff)]'

/* Igual, pero opaco (bloques de la rejilla de horas: la cuadrícula no se transparenta). */
export const TINTE_BLOQUE =
  'border-l-[3px] border-l-(--ev) bg-card [background-image:linear-gradient(color-mix(in_srgb,var(--ev)_14%,transparent),color-mix(in_srgb,var(--ev)_14%,transparent))] text-[color-mix(in_srgb,var(--ev)_80%,#000)] dark:[background-image:linear-gradient(color-mix(in_srgb,var(--ev)_24%,transparent),color-mix(in_srgb,var(--ev)_24%,transparent))] dark:text-[color-mix(in_srgb,var(--ev)_55%,#fff)]'
