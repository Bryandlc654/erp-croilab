import { fechaLarga } from '../../../shared/lib/formato'
import { usePortal } from '../contexto'
import { conAnterior, delta, etiquetaMes, miles, type Delta } from '../logica'
import { LogoMarca } from './ui'

/* «Descargar PDF»: el informe del mes en una hoja, solo visible al imprimir
   (el navegador lo guarda como PDF). */
export default function InformeImprimible({ mes }: { mes: string }) {
  const { datos: d } = usePortal()
  const { actual, anterior } = conAnterior(d.metricas, mes)
  const prog = d.progreso.find((p) => p.clave === mes)
  const txt = (x: Delta) =>
    x.tipo === 'sube' ? `▲ +${x.pct}% vs mes anterior` : x.tipo === 'baja' ? `▼ ${x.pct}% vs mes anterior` : x.tipo === 'igual' ? 'igual que el mes anterior' : x.tipo === 'nuevo' ? '▲ nuevo' : 'mes de partida'
  const kpis: [string, number, number | null][] = actual
    ? [
        ['Oportunidades de contacto', actual.total, anterior?.total ?? null],
        ['Llamadas', actual.ll, anterior?.ll ?? null],
        ['WhatsApp', actual.wa, anterior?.wa ?? null],
        ['Formularios', actual.fo, anterior?.fo ?? null],
        ['Visitas en Google', actual.vi, anterior?.vi ?? null],
        ['Apariciones en Google', actual.ap, anterior?.ap ?? null],
      ]
    : []
  return (
    <div className="hidden bg-white p-10 text-[#22262c] print:block">
      <div className="flex items-center gap-3 border-b border-[#eeeeef] pb-4">
        <LogoMarca nombre={d.marca.name} inicial={d.marca.initial} logo={d.marca.logo} color={d.marca.color || '#1f232a'} size={36} />
        <span className="text-[15px] font-bold">{d.marca.name} · Informe mensual</span>
      </div>
      <h1 className="mt-6 text-[26px] font-extrabold">
        {etiquetaMes(mes)} — {d.cliente.saludo}
      </h1>
      {d.secciones.metricas && kpis.length > 0 && (
        <section className="mt-6">
          <h2 className="mb-3 text-[13px] font-bold tracking-wide text-[#9aa0a8] uppercase">Resultados del mes</h2>
          <div className="grid grid-cols-3 gap-3">
            {kpis.map(([l, v, a]) => (
              <div key={l} className="rounded-xl border border-[#eeeeef] p-3">
                <p className="text-[12px] text-[#9aa0a8]">{l}</p>
                <p className="text-[22px] font-extrabold">{miles(v)}</p>
                <p className="text-[11.5px]">{txt(delta(v, a))}</p>
              </div>
            ))}
          </div>
        </section>
      )}
      {d.estado.nombre && (
        <section className="mt-6">
          <h2 className="mb-2 text-[13px] font-bold tracking-wide text-[#9aa0a8] uppercase">Estado del proyecto</h2>
          <p className="font-semibold">
            {d.estado.nombre}
            {d.estado.etiqueta && ` — ${d.estado.etiqueta}`}
          </p>
          {d.estado.siguiente && (
            <p className="mt-1 text-[14px]">
              <b>Lo siguiente:</b> {d.estado.siguiente}
            </p>
          )}
        </section>
      )}
      {prog && (
        <section className="mt-6">
          <h2 className="mb-2 text-[13px] font-bold tracking-wide text-[#9aa0a8] uppercase">Trabajo de {etiquetaMes(mes).toLowerCase()}</h2>
          <ul className="space-y-1 text-[14px]">
            {prog.pendiente.map((t, i) => (
              <li key={'p' + i}>• {t.t} (en curso)</li>
            ))}
            {prog.completado.map((t, i) => (
              <li key={'c' + i}>✓ {t.t}</li>
            ))}
          </ul>
        </section>
      )}
      <p className="mt-10 border-t border-[#eeeeef] pt-3 text-[11.5px] text-[#9aa0a8]">
        Generado el {fechaLarga(new Date())} · {d.marca.name} · Área de cliente
      </p>
    </div>
  )
}
