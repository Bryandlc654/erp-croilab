import type { ReactNode } from 'react'

/* Una pantalla de un módulo. Cada módulo declara las suyas en
   src/features/<modulo>/rutas.tsx y App.tsx las junta: así varios módulos se
   pueden migrar a la vez sin tocar el mismo fichero (docs/migracion/RUTAS.md). */
export type RutaModulo = { path: string; element: ReactNode }
