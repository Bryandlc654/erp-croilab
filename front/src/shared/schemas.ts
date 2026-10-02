import { z } from 'zod'

/* Piezas del contrato de la API (backend/API.md) que usan varias pantallas. */

export const IdSchema = z.number().int()

export const PersonaSchema = z.object({
  id: IdSchema,
  username: z.string(),
  // Ruta relativa al backend (archivo.php?d=avatars&f=...) o null.
  foto: z.string().nullable(),
})
export type Persona = z.infer<typeof PersonaSchema>

/* Respuesta paginada: {items, total, limit, offset}. */
export function paginaSchema<T extends z.ZodType>(item: T) {
  return z.object({
    items: z.array(item),
    total: z.number().int(),
    limit: z.number().int(),
    offset: z.number().int(),
  })
}

/* Siguiente offset de una lista paginada, o undefined si ya está todo cargado. */
export function siguienteOffset(p: { items: unknown[]; total: number; offset: number }) {
  const siguiente = p.offset + p.items.length
  return p.items.length > 0 && siguiente < p.total ? siguiente : undefined
}
