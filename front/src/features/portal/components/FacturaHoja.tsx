import { useEffect, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { ChevronLeft, Download, Printer } from 'lucide-react'
import { ApiError } from '../../../shared/api/client'
import { fechaCorta } from '../../../shared/lib/formato'
import { useToast } from '../../../shared/ui/useToast'
import { pdfFactura, useFactura } from '../api'
import { usePortal } from '../contexto'
import { euros } from '../logica'
import { ESTADO_FACTURA } from '../textos'
import { Pastilla } from './ui'

const pct = (v: string | number) => String(Number(v)).replace('.', ',')
const cant = (v: string) => String(Number(v)).replace('.', ',')
const largo = (iso: string | null) => (iso ? fechaCorta(iso).replace(/\/(\d{2})$/, '/20$1') : '')

/* La factura del portal: la hoja imprimible (antes factura.php). Solo facturas
   emitidas del cliente; los datos salen de la hoja de Finanzas. */
export default function FacturaHoja() {
  const { origen, ruta } = usePortal()
  const { factura } = useParams()
  const [params] = useSearchParams()
  const q = useFactura(origen, Number(factura))
  const { aviso } = useToast()
  const [bajando, setBajando] = useState(false)
  const imprimir = params.get('print') === '1'
  useEffect(() => {
    if (imprimir && q.data) {
      const t = setTimeout(() => window.print(), 250)
      return () => clearTimeout(t)
    }
  }, [imprimir, q.data])

  const descargar = async () => {
    setBajando(true)
    try {
      const r = await pdfFactura(origen, Number(factura))
      const bytes = Uint8Array.from(atob(r.base64), (c) => c.charCodeAt(0))
      const a = document.createElement('a')
      a.href = URL.createObjectURL(new Blob([bytes], { type: r.mime }))
      a.download = r.nombre
      a.click()
      setTimeout(() => URL.revokeObjectURL(a.href), 2000)
    } catch (e) {
      aviso(e instanceof ApiError ? e.message : 'No se ha podido descargar el PDF.', { tipo: 'error' })
    } finally {
      setBajando(false)
    }
  }

  const barra = (extra?: React.ReactNode) => (
    <div className="mx-auto mb-4 flex max-w-[680px] items-center gap-2 print:hidden">
      <Link to={ruta('facturas')} className="inline-flex h-10 items-center gap-1.5 rounded-xl border border-(--p-line) bg-(--p-card) px-4 text-[14px] font-semibold text-(--p-ink-strong)">
        <ChevronLeft className="size-4" /> Volver
      </Link>
      <span className="flex-1" />
      {extra}
    </div>
  )

  if (q.isPending) return <div className="px-4 py-6">{barra()}</div>
  if (q.isError || !q.data) {
    return (
      <div className="px-4 py-6">
        {barra()}
        <div className="mx-auto max-w-[680px] rounded-[20px] bg-(--p-card) px-8 py-12 text-center shadow-(--p-shadow)">
          <p className="text-[18px] font-bold text-(--p-ink-strong)">No encontramos esa factura</p>
          <p className="mt-2 text-[14px] text-(--p-muted)">Puede que ya no esté disponible o que no corresponda a tu cuenta. Vuelve a tus facturas e inténtalo de nuevo.</p>
        </div>
      </div>
    )
  }
  const f = q.data
  const e = ESTADO_FACTURA[f.estado] ?? { label: f.estado, color: '#9aa0a8' }
  return (
    <div className="px-4 py-6 print:p-0">
      {barra(
        <>
          <Pastilla color={e.color} solida>
            {e.label}
          </Pastilla>
          <button type="button" onClick={() => void descargar()} disabled={bajando} className="inline-flex h-10 items-center gap-2 rounded-xl bg-(--p-acc) px-4 text-[14px] font-semibold text-(--p-acc-fg) disabled:opacity-60">
            <Download className="size-4" /> <span className="max-sm:hidden">Descargar PDF</span>
          </button>
          <button type="button" onClick={() => window.print()} className="inline-flex size-10 items-center justify-center rounded-xl border border-(--p-line) bg-(--p-card) text-(--p-ink-strong)" aria-label="Imprimir">
            <Printer className="size-4" />
          </button>
        </>,
      )}
      {/* La hoja es siempre blanca, también en modo oscuro: es un papel. */}
      <article className="mx-auto max-w-[680px] rounded-[20px] bg-white p-10 text-[#111318] shadow-(--p-shadow) max-sm:p-5 print:max-w-none print:rounded-none print:shadow-none">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 className="text-[36px] leading-none font-black tracking-tight max-sm:text-[28px]">{f.titulo}</h1>
            <span className="mt-3 inline-block rounded-full border-2 border-[#111318] px-3 py-1 text-[13px] font-bold">Nº {f.numero}</span>
          </div>
          <div className="flex flex-col items-end gap-2 text-[12.5px]">
            <span className="rounded-full border border-[#eeeeef] px-3 py-1.5">
              Fecha: <b>{largo(f.fecha)}</b>
            </span>
            {f.fecha_venc && (
              <span className="rounded-full border border-[#eeeeef] px-3 py-1.5">
                Vencimiento: <b>{largo(f.fecha_venc)}</b>
              </span>
            )}
          </div>
        </div>
        <div className="mt-7 grid overflow-hidden rounded-2xl border border-[#111318] sm:grid-cols-2">
          <div className="p-5 max-sm:border-b sm:border-r sm:border-[#111318]">
            <p className="text-[11px] font-bold tracking-wide text-[#9aa0a8] uppercase">Datos del cliente</p>
            <p className="mt-1.5 font-bold">{f.cliente.nombre}</p>
            {[f.cliente.nif, f.cliente.tel, f.cliente.dir, f.cliente.email].filter(Boolean).map((x) => (
              <p key={x} className="text-[13px] text-[#555b63]">
                {x}
              </p>
            ))}
          </div>
          <div className="p-5">
            <p className="text-[11px] font-bold tracking-wide text-[#9aa0a8] uppercase">Emitida por</p>
            <p className="mt-1.5 font-bold">{f.emisor.name || 'Croilab'}</p>
            {[f.emisor.nif, f.emisor.email, f.emisor.phone, f.emisor.dir].filter(Boolean).map((x) => (
              <p key={x} className="text-[13px] text-[#555b63]">
                {x}
              </p>
            ))}
          </div>
        </div>
        <div className="mt-7 overflow-x-auto">
          <table className="w-full min-w-[420px] text-[13.5px]">
            <thead>
              <tr className="bg-[#111318] text-[11px] tracking-wide text-white uppercase">
                <th className="rounded-l-xl px-4 py-3 text-left">Detalle</th>
                <th className="px-3 py-3 text-right">Cantidad</th>
                <th className="px-3 py-3 text-right">Precio</th>
                <th className="rounded-r-xl px-4 py-3 text-right">Total</th>
              </tr>
            </thead>
            <tbody>
              {f.lineas.map((l, i) => (
                <tr key={i} className="border-b border-[#eeeeef]">
                  <td className="px-4 py-3 font-medium">{l.concepto}</td>
                  <td className="px-3 py-3 text-right">{cant(l.cantidad)}</td>
                  <td className="px-3 py-3 text-right whitespace-nowrap">{euros(Math.round(Number(l.precio) * 100))}</td>
                  <td className="px-4 py-3 text-right whitespace-nowrap">{euros(l.importe)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="mt-5 ml-auto w-full max-w-[330px] text-[13.5px]">
          <div className="flex justify-between border-y border-[#eeeeef] py-2.5 font-bold">
            <span>Base imponible</span>
            <span>{euros(f.totales.base)}</span>
          </div>
          <div className="flex justify-between border-b border-[#eeeeef] py-2.5 text-[#555b63]">
            <span>IVA (+{pct(f.iva_pct)}%)</span>
            <span>{euros(f.totales.iva)}</span>
          </div>
          {f.totales.irpf > 0 && (
            <div className="flex justify-between border-b border-[#eeeeef] py-2.5 text-[#555b63]">
              <span>IRPF (−{pct(f.irpf_pct)}%)</span>
              <span>−{euros(f.totales.irpf)}</span>
            </div>
          )}
          <div className="mt-3 flex items-center justify-between rounded-2xl bg-[#111318] px-5 py-4 text-white">
            <span className="text-[13px] font-bold">TOTAL</span>
            <span className="text-[22px] font-black">{euros(f.totales.total)}</span>
          </div>
        </div>
        <div className="mt-7 rounded-2xl border border-[#eeeeef] p-5 text-[13px]">
          <p className="mb-2 text-[11px] font-bold tracking-wide text-[#9aa0a8] uppercase">Información de pago</p>
          {(
            [
              ['Banco', f.pago.banco],
              ['Titular', f.pago.titular],
              ['Forma de pago', f.pago.forma],
              ['Condiciones', f.pago.condiciones],
              ['IBAN', f.pago.iban],
            ] as const
          )
            .filter(([, v]) => v)
            .map(([k, v]) => (
              <div key={k} className="flex justify-between gap-3 py-0.5">
                <span className="text-[#555b63]">{k}</span>
                <span className="text-right font-medium">{v}</span>
              </div>
            ))}
        </div>
        {(f.notas_legales.length > 0 || f.notas) && (
          <div className="mt-4 text-[12.5px] whitespace-pre-wrap text-[#555b63]">
            {f.notas_legales.map((n) => (
              <p key={n}>{n}</p>
            ))}
            {f.notas && <p className="mt-1">{f.notas}</p>}
          </div>
        )}
      </article>
    </div>
  )
}
