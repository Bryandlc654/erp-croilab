/* Cálculo de la posición de un flotante (§4.5): siempre `position:fixed`
   pegado al ancla, volteado hacia arriba si abajo no cabe y acotado a 8px de
   los bordes. Función pura para poder probarla sin navegador. */

export type Lado = 'bottom' | 'top'
export type Placement = 'bottom-start' | 'bottom-end' | 'top-start' | 'top-end'
export type Rect = { top: number; bottom: number; left: number; right: number; width: number; height: number }

export type Posicion = {
  left: number
  /* Solo uno de los dos: hacia arriba se ancla por `bottom` para que el panel
     crezca hacia arriba si cambia de alto (resultados que llegan después). */
  top?: number
  bottom?: number
  lado: Lado
  maxHeight: number
}

export const MARGEN = 8

export function calcularPosicion({
  ancla,
  ancho,
  alto,
  vw,
  vh,
  placement = 'bottom-start',
  offset = 4,
  flip = true,
}: {
  ancla: Rect
  ancho: number
  alto: number
  vw: number
  vh: number
  placement?: Placement
  offset?: number
  flip?: boolean
}): Posicion {
  const [preferido, alineado] = placement.split('-') as [Lado, 'start' | 'end']
  const espacioAbajo = vh - ancla.bottom - offset - MARGEN
  const espacioArriba = ancla.top - offset - MARGEN

  let lado: Lado = preferido
  if (flip) {
    // Se voltea solo si no cabe en el lado preferido y en el otro hay más sitio.
    if (preferido === 'bottom' && alto > espacioAbajo && espacioArriba > espacioAbajo) lado = 'top'
    if (preferido === 'top' && alto > espacioArriba && espacioAbajo > espacioArriba) lado = 'bottom'
  }

  const deseado = alineado === 'end' ? ancla.right - ancho : ancla.left
  const left = Math.max(MARGEN, Math.min(deseado, vw - ancho - MARGEN))

  if (lado === 'bottom') {
    return { left, top: ancla.bottom + offset, lado, maxHeight: Math.max(120, espacioAbajo) }
  }
  return { left, bottom: vh - ancla.top + offset, lado, maxHeight: Math.max(120, espacioArriba) }
}

/* Un punto (clic derecho) como rectángulo de tamaño cero. */
export function rectDePunto(x: number, y: number): Rect {
  return { top: y, bottom: y, left: x, right: x, width: 0, height: 0 }
}

/* ¿El ancla ha salido de la pantalla? Entonces el flotante se cierra. */
export function fueraDePantalla(r: Rect, vh: number, vw: number) {
  return r.bottom < 0 || r.top > vh || r.right < 0 || r.left > vw
}
