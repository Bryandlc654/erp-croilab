import { useEffect, useState } from 'react'

/* El valor, pero solo cuando lleva `ms` sin cambiar: evita una petición por tecla. */
export function useDebounced<T>(valor: T, ms = 250) {
  const [estable, setEstable] = useState(valor)
  useEffect(() => {
    const t = setTimeout(() => setEstable(valor), ms)
    return () => clearTimeout(t)
  }, [valor, ms])
  return estable
}
