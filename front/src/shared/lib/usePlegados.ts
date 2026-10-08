import { useCallback, useState } from 'react'

/* null = nunca se ha guardado nada con esta clave. */
function leer(clave: string): Set<string> | null {
  try {
    const raw = localStorage.getItem(clave)
    if (raw === null) return null
    const v: unknown = JSON.parse(raw)
    return new Set(Array.isArray(v) ? v.map(String) : [])
  } catch {
    return new Set()
  }
}

/* Qué grupos están plegados, recordado en este navegador (localStorage) como
   una lista de claves. Mismo formato que 'croilab:ws_collapsed' del tablero. */
export function usePlegados(storageKey: string, iniciales: string[] = []) {
  const [plegados, setPlegados] = useState<Set<string>>(() => {
    // Los grupos que empiezan plegados («Completada») solo cuentan si nunca se ha guardado nada.
    return leer(storageKey) ?? new Set(iniciales)
  })

  const alternar = useCallback(
    (k: string) => {
      setPlegados((prev) => {
        // Se parte de lo guardado: varias tarjetas pueden compartir la misma clave.
        // Solo cambia la clave `k`; el resto se toma de lo guardado.
        const s = new Set(leer(storageKey) ?? prev)
        if (prev.has(k)) s.delete(k)
        else s.add(k)
        try {
          localStorage.setItem(storageKey, JSON.stringify([...s]))
        } catch {
          // Sin localStorage se recuerda solo mientras dura la pantalla.
        }
        return s
      })
    },
    [storageKey],
  )

  return { plegados, estaPlegado: (k: string) => plegados.has(k), alternar }
}
