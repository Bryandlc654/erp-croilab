import { useEffect, useState } from 'react'

const KEY = 'croilab:tema'
type Tema = 'light' | 'dark'

function inicial(): Tema {
  try {
    const t = localStorage.getItem(KEY)
    if (t === 'light' || t === 'dark') return t
  } catch {
    // Sin localStorage (modo privado estricto): se usa el tema por defecto.
  }
  // Como el ERP antiguo: claro hasta que se elige el oscuro con la luna.
  return 'light'
}

/* Modo claro/oscuro: pone o quita .dark en <html> y lo recuerda en este navegador. */
export function useTema() {
  const [tema, setTema] = useState<Tema>(inicial)
  useEffect(() => {
    document.documentElement.classList.toggle('dark', tema === 'dark')
    try {
      localStorage.setItem(KEY, tema)
    } catch {
      // Si no se puede guardar, el tema dura lo que la pestaña.
    }
  }, [tema])
  return { tema, alternar: () => setTema((t) => (t === 'dark' ? 'light' : 'dark')) }
}
