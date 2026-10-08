/* Lógica pura del arrastrar para reordenar (§4.7), separada del DOM para
   poder probarla. */

export type Eje = 'x' | 'y'
export type Caja = { top: number; left: number; width: number; height: number }

/* Mueve el elemento de `desde` a `hasta` (índices finales). */
export function moverElemento<T>(lista: readonly T[], desde: number, hasta: number): T[] {
  const n = lista.length
  if (desde < 0 || desde >= n || desde === hasta) return [...lista]
  const copia = [...lista]
  const [x] = copia.splice(desde, 1)
  copia.splice(Math.max(0, Math.min(hasta, n - 1)), 0, x)
  return copia
}

/* Hueco de inserción (0..n) según dónde está el puntero: antes del primer
   elemento cuya mitad aún no se ha pasado. */
export function huecoInsercion(cajas: Caja[], puntero: { x: number; y: number }, eje: Eje): number {
  for (let i = 0; i < cajas.length; i++) {
    const c = cajas[i]
    const mitad = eje === 'y' ? c.top + c.height / 2 : c.left + c.width / 2
    if ((eje === 'y' ? puntero.y : puntero.x) < mitad) return i
  }
  return cajas.length
}

/* Del hueco de inserción al índice final del elemento movido. */
export function indiceDestino(desde: number, hueco: number) {
  return hueco > desde ? hueco - 1 : hueco
}

/* Nuevo orden tras soltar `desde` en el hueco `hueco`. */
export function reordenar<T>(lista: readonly T[], desde: number, hueco: number): T[] {
  return moverElemento(lista, desde, indiceDestino(desde, hueco))
}
