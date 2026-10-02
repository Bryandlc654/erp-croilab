import { z } from 'zod'
import { IdSchema } from '../../shared/schemas'

export const MeSchema = z.object({
  id: IdSchema,
  username: z.string(),
  role: z.string().nullable(),
  role_nombre: z.string().nullable(),
  foto: z.string().nullable(),
  permisos: z.array(z.string()),
})
export type Me = z.infer<typeof MeSchema>

export const CsrfRespuesta = z.object({ csrf: z.string() })
export const LoginRespuesta = z.object({ csrf: z.string(), me: MeSchema })
export const MeRespuesta = z.object({ me: MeSchema, csrf: z.string() })
export const VacioRespuesta = z.object({})

/* Recuperar la contraseña (rutas públicas). */
export const RecuperarRespuesta = z.object({ msg: z.string() })
export const ComprobarEnlaceRespuesta = z.object({ username: z.string() })
