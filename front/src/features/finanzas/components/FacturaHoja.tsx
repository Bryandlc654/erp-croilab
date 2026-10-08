import type { ReactNode } from 'react'
import { c, cantidadCorta, eurC, pctCorto } from '../lib/importes'
import { fechaLargaNum } from '../lib/periodos'
import type { Hoja } from '../schemas'
import './hoja.css'

/* La hoja de la factura: UNA plantilla para la vista previa del editor, la
   vista del equipo, la impresión (y el portal del cliente cuando llegue). Papel
   siempre blanco (colores fijos, no tokens) para que imprimir y el modo oscuro
   den lo mismo. Datos de HojaFactura::datos() del servidor. */
export default function FacturaHoja({ h, compacta = false, imprimible = false }: { h: Hoja; compacta?: boolean; imprimible?: boolean }) {
  const t = h.totales
  const rect = h.tipo === 'rectificativa'
  const conIrpf = c(h.irpf_pct) !== 0
  return (
    <article
      className={`${imprimible ? 'fin-print' : ''} w-full rounded-[18px] bg-white text-[#3c4149] ${compacta ? 'p-6 text-[11px]' : 'max-w-[680px] p-10 text-[13px] max-sm:p-5'}`}
      aria-label={`${h.titulo} ${h.numero ?? 'borrador'}`}
    >
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className={`${compacta ? 'text-[24px]' : 'text-[36px] max-sm:text-[28px]'} leading-none font-[850] tracking-[-1.3px] text-[#22262c]`}>{rect ? 'RECTIFICATIVA' : 'FACTURA'}</h2>
          <span className={`mt-2.5 inline-block rounded-full border-[1.5px] border-[#22262c] px-2.5 py-0.5 font-bold text-[#22262c] ${compacta ? 'text-[10px]' : 'text-[12px]'}`}>
            Nº {h.numero ?? (h.borrador ? 'auto' : '—')}
          </span>
        </div>
        <div className="flex flex-col items-end gap-1.5">
          {h.periodo_ini && h.periodo_fin && (
            <Chip>
              Período: <b>{fechaLargaNum(h.periodo_ini)}</b> a <b>{fechaLargaNum(h.periodo_fin)}</b>
            </Chip>
          )}
          <Chip>
            Fecha: <b>{fechaLargaNum(h.fecha)}</b>
          </Chip>
          {h.fecha_venc && (
            <Chip>
              Vencimiento: <b>{fechaLargaNum(h.fecha_venc)}</b>
            </Chip>
          )}
          {h.borrador && <span className="text-[10px] font-bold tracking-[.4px] text-[#c0392b] uppercase">Borrador · sin valor fiscal</span>}
        </div>
      </header>

      <div className={`grid grid-cols-2 overflow-hidden rounded-2xl border-[1.5px] border-[#22262c] max-sm:grid-cols-1 ${compacta ? 'mt-4' : 'mt-7'}`}>
        <Parte titulo="Datos del cliente" lineas={[h.cliente.nombre || '—', h.cliente.nif && `NIF: ${h.cliente.nif}`, h.cliente.tel, h.cliente.dir, h.cliente.email]} />
        <Parte titulo={rect ? 'Emitida por' : 'Datos autónomo'} borde lineas={[h.emisor.name, h.emisor.nif && `NIF: ${h.emisor.nif}`, h.emisor.email, h.emisor.phone, h.emisor.dir]} />
      </div>

      <table className={`w-full border-separate border-spacing-0 ${compacta ? 'mt-4' : 'mt-7'}`}>
        <thead>
          <tr className="text-[10px] font-bold tracking-[.5px] text-white uppercase">
            <th className="rounded-l-lg bg-[#111318] px-3 py-2 text-left">Detalle</th>
            <th className="bg-[#111318] px-3 py-2 text-right">Cant.</th>
            <th className="bg-[#111318] px-3 py-2 text-right">Precio</th>
            <th className="rounded-r-lg bg-[#111318] px-3 py-2 text-right">Total</th>
          </tr>
        </thead>
        <tbody>
          {h.lineas.length === 0 && (
            <tr>
              <td colSpan={4} className="px-3 py-4 text-center text-[#9aa0a8]">
                Sin líneas.
              </td>
            </tr>
          )}
          {h.lineas.map((l, i) => (
            <tr key={i} className="align-top">
              <td className="border-b border-[#f0f0f2] px-3 py-2.5 break-words whitespace-pre-wrap text-[#22262c]">{l.concepto}</td>
              <td className="border-b border-[#f0f0f2] px-3 py-2.5 text-right tabular-nums">{cantidadCorta(l.cantidad)}</td>
              <td className="border-b border-[#f0f0f2] px-3 py-2.5 text-right whitespace-nowrap tabular-nums">{eurC(c(l.precio))}</td>
              <td className="border-b border-[#f0f0f2] px-3 py-2.5 text-right font-semibold whitespace-nowrap text-[#22262c] tabular-nums">{eurC(l.importe)}</td>
            </tr>
          ))}
        </tbody>
      </table>

      <div className={`ml-auto ${compacta ? 'mt-3 w-[220px]' : 'mt-5 w-[330px] max-sm:w-full'}`}>
        <Fila a="Base imponible" b={eurC(t.base)} fuerte />
        <Fila a={`IVA (+${pctCorto(h.iva_pct)}%)`} b={eurC(t.iva)} />
        {(conIrpf || !compacta) && <Fila a={`IRPF (−${pctCorto(h.irpf_pct)}%)`} b={`−${eurC(t.irpf)}`} />}
        <div className="mt-2 flex items-center justify-between rounded-xl bg-[#111318] px-4 py-3 text-white">
          <span className="text-[12px] font-bold tracking-[.4px]">TOTAL</span>
          <b className={`${compacta ? 'text-[16px]' : 'text-[23px]'} font-[850] tabular-nums`}>{eurC(t.total)}</b>
        </div>
      </div>

      <section className={`rounded-[14px] border border-[#eeeeef] ${compacta ? 'mt-4 p-3' : 'mt-7 p-4'}`}>
        <h3 className="mb-2 text-[10px] font-bold tracking-[.5px] text-[#656a72] uppercase">Información de pago</h3>
        {h.pago.banco && <Pago a="Banco" b={h.pago.banco} />}
        <Pago a="Titular" b={h.pago.titular || '—'} />
        <Pago a="Forma de pago" b={h.pago.forma} />
        <Pago a="Vencimiento" b={`${h.pago.condiciones || 'Contado'}${h.pago.fecha_venc ? ` · ${fechaLargaNum(h.pago.fecha_venc)}` : ''}`} />
        {h.pago.iban && <Pago a="IBAN" b={h.pago.iban} />}
      </section>

      {(h.notas_legales.length > 0 || h.notas) && (
        <div className={`space-y-1.5 text-[#656a72] ${compacta ? 'mt-3 text-[10px]' : 'mt-5 text-[12px]'}`}>
          {h.notas_legales.map((n, i) => (
            <p key={i}>{n}</p>
          ))}
          {h.notas && <p className="whitespace-pre-line">{h.notas}</p>}
        </div>
      )}
    </article>
  )
}

function Chip({ children }: { children: ReactNode }) {
  return <span className="rounded-full border border-[#eeeeef] px-2.5 py-1 text-[11px] text-[#656a72] [&_b]:font-semibold [&_b]:text-[#22262c]">{children}</span>
}

function Parte({ titulo, lineas, borde = false }: { titulo: string; lineas: (string | false | null | undefined)[]; borde?: boolean }) {
  const ls = lineas.filter((l): l is string => !!l)
  return (
    <div className={`min-w-0 px-4 py-3.5 ${borde ? 'border-l-[1.5px] border-[#22262c] max-sm:border-t-[1.5px] max-sm:border-l-0' : ''}`}>
      <h3 className="mb-1.5 text-[9.5px] font-bold tracking-[.5px] text-[#656a72] uppercase">{titulo}</h3>
      {ls.map((l, i) => (
        <p key={i} className={`break-words ${i === 0 ? 'font-semibold text-[#22262c]' : ''}`}>
          {l}
        </p>
      ))}
    </div>
  )
}

function Fila({ a, b, fuerte = false }: { a: string; b: string; fuerte?: boolean }) {
  return (
    <div className={`flex justify-between py-1 ${fuerte ? 'font-semibold text-[#22262c]' : ''}`}>
      <span>{a}</span>
      <span className="tabular-nums">{b}</span>
    </div>
  )
}

function Pago({ a, b }: { a: string; b: string }) {
  return (
    <div className="flex justify-between gap-4 py-0.5">
      <span className="text-[#9aa0a8]">{a}</span>
      <b className="text-right font-semibold break-all text-[#22262c]">{b}</b>
    </div>
  )
}
