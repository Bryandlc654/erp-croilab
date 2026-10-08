import { useEffect, useRef, useState } from 'react'

/* Cifra que sube hasta su valor al aparecer (como el count-up del antiguo). */
export function useCuenta(objetivo: number, ms = 700) {
  const [v, setV] = useState(objetivo)
  const desde = useRef(0)
  useEffect(() => {
    if (typeof window === 'undefined' || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
      desde.current = objetivo
      return
    }
    const ini = performance.now()
    const de = desde.current
    let raf = 0
    const paso = (t: number) => {
      const k = Math.min(1, (t - ini) / ms)
      const e = 1 - Math.pow(1 - k, 3)
      setV(de + (objetivo - de) * e)
      if (k < 1) raf = requestAnimationFrame(paso)
      else desde.current = objetivo
    }
    raf = requestAnimationFrame(paso)
    return () => cancelAnimationFrame(raf)
  }, [objetivo, ms])
  return v
}
