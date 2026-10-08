/* Escribir en un <input>/<textarea> CONTROLADO por React desde fuera (emoji,
   mención): se usa el «setter» nativo y un evento input, así React se entera
   y llama a su onChange como si se hubiera tecleado. */
export function insertarEnCampo(el: HTMLInputElement | HTMLTextAreaElement, texto: string) {
  const ini = el.selectionStart ?? el.value.length
  const fin = el.selectionEnd ?? ini
  ponerValor(el, el.value.slice(0, ini) + texto + el.value.slice(fin), ini + texto.length)
}

export function ponerValor(el: HTMLInputElement | HTMLTextAreaElement, valor: string, cursor?: number) {
  const proto = el instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype
  Object.getOwnPropertyDescriptor(proto, 'value')?.set?.call(el, valor)
  el.dispatchEvent(new Event('input', { bubbles: true }))
  if (cursor !== undefined) {
    el.focus({ preventScroll: true })
    el.setSelectionRange(cursor, cursor)
  }
}
