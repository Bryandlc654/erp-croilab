/* «Cargando…» centrado como el resto del ERP. */
export default function CargandoFin({ texto = 'Cargando…' }: { texto?: string }) {
  return <p className="py-[60px] text-center text-[13.5px] text-muted">{texto}</p>
}
