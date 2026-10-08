import Button from '../../../shared/ui/Button'
import Modal, { ModalBody, ModalFooter } from '../../../shared/ui/Modal'
import { mensajeError, useResumen } from '../api'

/* «Ver email diario»: vista previa del resumen tal y como llega por correo
   (se pinta aquí con los datos; la API no manda HTML). Solo lee: no genera
   seguimientos (el antiguo los creaba al abrir la vista previa). */
export default function ResumenModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { data: r, error, isPending } = useResumen(open)
  return (
    <Modal open={open} onClose={onClose} size="xl" title="Vista previa del email diario" subtitle="Resumen de seguimientos de hoy, como lo recibe quien tiene acceso total.">
      <ModalBody className="bg-soft">
        {isPending && <p className="py-10 text-center text-[13px] text-muted">Cargando…</p>}
        {error && <p className="py-10 text-center text-[13px] text-[#e5484d]">{mensajeError(error, 'No se ha podido cargar.')}</p>}
        {r && (
          <div className="mx-auto w-full max-w-[600px] rounded-2xl bg-white p-6 text-[#22262c] shadow-[0_1px_2px_rgba(16,19,24,.06)]">
            <div className="mb-6">
              <div className="text-[11.5px] font-bold tracking-[.7px] text-[#a4a9b1] uppercase">
                {r.dia} · {r.fecha.slice(8, 10)}/{r.fecha.slice(5, 7)}/{r.fecha.slice(0, 4)}
              </div>
              <div className="mt-1 text-[23px] font-[750] tracking-[-.4px]">Seguimientos de hoy</div>
              <div className="mt-1 text-[14px] text-[#6b7079]">{r.total === 0 ? 'Nada pendiente por ahora.' : `${r.total} acción${r.total === 1 ? '' : 'es'} por hacer.`}</div>
            </div>
            {r.total === 0 && (
              <div className="rounded-[14px] border border-[#cdeede] bg-[#f0faf4] p-7 text-center text-[15px] font-[650] text-[#1a9d5b]">✓ No hay seguimientos pendientes hoy.</div>
            )}
            {r.grupos.map((g) => (
              <div key={g.canal}>
                <div className="mb-2 flex items-center gap-2">
                  <span className="size-2 rounded-full" style={{ backgroundColor: g.color }} />
                  <span className="text-[12px] font-extrabold tracking-[.5px]" style={{ color: g.color }}>
                    {g.titulo}
                  </span>
                  <span className="text-[12px] font-bold text-[#c0c4cb]">{g.items.length}</span>
                </div>
                <div className="mb-5 overflow-hidden rounded-[14px] border border-[#ececee]">
                  {g.items.map((it, i) => (
                    <div key={i} className={`px-4 py-3 ${i > 0 ? 'border-t border-[#f4f4f5]' : ''}`}>
                      <div className="text-[14.5px] font-[650]">
                        {it.nombre} {it.dato && <span className="text-[12.5px] font-medium text-[#a4a9b1]">{it.dato}</span>}
                      </div>
                      {it.sub && <div className="mt-0.5 text-[12px] text-[#a4a9b1]">{it.sub}</div>}
                      <div className="mt-1 text-[13px] leading-snug text-[#3c4149]">{it.descripcion}</div>
                    </div>
                  ))}
                </div>
              </div>
            ))}
            <div className="mt-1.5 border-t border-[#f2f2f3] pt-4 text-[11px] text-[#c0c4cb]">{r.agencia} · CRM · resumen automático diario</div>
          </div>
        )}
      </ModalBody>
      <ModalFooter>
        <Button variant="ghost" onClick={onClose}>
          Cerrar
        </Button>
      </ModalFooter>
    </Modal>
  )
}
