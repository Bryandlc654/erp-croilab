import { useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { ChartLine, Euro, Layers, MoreHorizontal, Plus, TrendingUp } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import EmptyState from '../../../shared/ui/EmptyState'
import Menu, { MenuPanel } from '../../../shared/ui/Menu'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import Segmented from '../../../shared/ui/Segmented'
import { TextInput } from '../../../shared/ui/TextInput'
import { useContextMenu } from '../../../shared/ui/useContextMenu'
import { useToast } from '../../../shared/ui/useToast'
import { mensajeError, useProyectos } from '../api'
import CargandoFin from '../components/Cargando'
import KpiIcono from '../components/KpiIcono'
import ProyectoMenuItems from '../components/ProyectoMenu'
import { eurC } from '../lib/importes'
import { usePermisosFin } from '../lib/permisos'
import { useOpsProyecto } from '../lib/useOpsProyecto'
import type { ProyectoFila } from '../schemas'

/* Proyectos (proyectos.php): rentabilidad interna por proyecto según la caja. */
export default function ProyectosPage() {
  const p = usePermisosFin()
  const navigate = useNavigate()
  const { aviso } = useToast()
  const [sp, setSp] = useSearchParams()
  const anio = sp.get('y') || String(new Date().getFullYear())
  const { data, error, isLoading } = useProyectos(anio)
  const ops = useOpsProyecto()
  const cm = useContextMenu<ProyectoFila>()
  const [creando, setCreando] = useState(false)
  const [nombre, setNombre] = useState('')
  if (!p.proyectos) return <Notice tone="error">No tienes acceso a los proyectos.</Notice>

  async function crear() {
    if (!nombre.trim()) return
    try {
      await ops.acc.crear.mutateAsync({ nombre: nombre.trim() })
      setNombre('')
      setCreando(false)
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido crear.'), { tipo: 'error' })
    }
  }

  const menu = (x: ProyectoFila, conVer: boolean) => (
    <ProyectoMenuItems
      activo={x.activo}
      puede={p.escribe}
      onVer={conVer ? () => navigate(`/finanzas/proyectos/${x.id}`) : undefined}
      onRenombrar={() => void ops.renombrar(x)}
      onColor={(c) => void ops.color(x, c)}
      onArchivar={() => void ops.archivar(x)}
      onBorrar={() => void ops.borrar(x)}
    />
  )

  return (
    <div>
      <PageHeader
        title="Proyectos"
        lead={`Rentabilidad interna por proyecto (${anio === 'all' ? 'histórico' : anio}). Asigna facturas y gastos a un proyecto desde Facturas y Contabilidad; aquí ves lo que gana cada uno. No aparece en las facturas del cliente.`}
        actions={
          data && (
            <Segmented
              variant="pill"
              value={anio}
              onChange={(v) => setSp({ y: v }, { replace: true })}
              items={[...data.anios.map((a) => ({ value: String(a), label: String(a) })), { value: 'all', label: 'Histórico' }]}
              aria-label="Año"
            />
          )
        }
      />
      {error && <Notice tone="error">{mensajeError(error, 'No se han podido cargar los proyectos.')}</Notice>}
      {isLoading && <CargandoFin />}
      {data && (
        <>
          <div className="mb-5 grid grid-cols-3 gap-5 max-[900px]:grid-cols-1 max-sm:gap-3">
            <KpiIcono icono={<TrendingUp className="text-[#12854a]" />} label="Ingresos" valor={eurC(data.kpis.ing)} />
            <KpiIcono icono={<Euro className="text-[#e5484d]" />} label="Costes" valor={eurC(data.kpis.gas)} />
            <KpiIcono icono={<ChartLine />} label="Beneficio" valor={eurC(data.kpis.ben)} tono={data.kpis.ben >= 0 ? 'ok' : 'mal'} />
          </div>
          <section className="rounded-[20px] border border-line bg-card px-[26px] py-6 max-sm:px-4">
            <header className="mb-4 flex items-center justify-between gap-3">
              <h3 className="text-[16px] font-semibold text-ink-strong">Rentabilidad por proyecto</h3>
              {p.escribe && (
                <Button size="sm" icon={<Plus />} onClick={() => setCreando(true)}>
                  Crear proyecto
                </Button>
              )}
            </header>
            {creando && (
              <div className="mb-4 flex flex-wrap gap-2">
                <TextInput
                  autoFocus
                  className="min-w-[240px] flex-1"
                  value={nombre}
                  onChange={(e) => setNombre(e.target.value)}
                  placeholder="Nombre del proyecto (ej: Web de Cliente X)…"
                  aria-label="Nombre del proyecto"
                  onKeyDown={(e) => {
                    if (e.key === 'Enter') void crear()
                    if (e.key === 'Escape') setCreando(false)
                  }}
                />
                <Button onClick={() => void crear()} loading={ops.acc.crear.isPending} loadingText="Creando…">
                  Crear
                </Button>
                <Button variant="ghost" onClick={() => setCreando(false)}>
                  Cancelar
                </Button>
              </div>
            )}
            {data.items.length === 0 ? (
              <EmptyState variant="inline" icon={<Layers />} title="Aún no hay proyectos" text="Crea el primero y luego asígnalo a tus facturas y gastos." />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-[13.5px] max-md:hidden">
                  <thead className="text-left text-[11px] font-semibold tracking-[.5px] text-muted uppercase">
                    <tr className="border-b border-line">
                      <th className="py-2.5 pr-3">Proyecto</th>
                      <th className="px-3 py-2.5 text-right">Ingresos</th>
                      <th className="px-3 py-2.5 text-right">Costes</th>
                      <th className="px-3 py-2.5 text-right">Beneficio</th>
                      <th className="w-[180px] px-3 py-2.5">Margen</th>
                      <th className="w-10" />
                    </tr>
                  </thead>
                  <tbody>
                    {data.items.map((x) => (
                      <tr key={x.id} onContextMenu={(e) => cm.onContextMenu(e, x)} onClick={() => navigate(`/finanzas/proyectos/${x.id}`)} className="cursor-pointer border-b border-line2 hover:bg-hover-row">
                        <td className="py-3 pr-3">
                          <span className="flex items-center gap-2.5">
                            <span className="size-2.5 shrink-0 rounded-full" style={{ backgroundColor: x.color }} />
                            <b className="font-semibold text-ink-strong">{x.nombre}</b>
                            {!x.activo && <span className="rounded-md bg-chip px-1.5 py-px text-[10.5px] font-semibold text-muted">archivado</span>}
                            <span className="text-[12px] text-label">{x.nmov} mov.</span>
                          </span>
                        </td>
                        <td className="px-3 py-3 text-right tabular-nums">{eurC(x.ing)}</td>
                        <td className="px-3 py-3 text-right tabular-nums">{eurC(x.gas)}</td>
                        <td className={`px-3 py-3 text-right font-semibold tabular-nums ${x.ben >= 0 ? 'text-[#12854a] dark:text-ok' : 'text-[#e5484d] dark:text-danger'}`}>{eurC(x.ben)}</td>
                        <td className="px-3 py-3">
                          <Margen ing={x.ing} gas={x.gas} />
                        </td>
                        <td onClick={(e) => e.stopPropagation()}>
                          <Menu align="right" label="Opciones del proyecto" trigger={() => <MoreHorizontal className="size-[18px] text-label" />}>
                            {menu(x, true)}
                          </Menu>
                        </td>
                      </tr>
                    ))}
                    {data.sin_proyecto.nmov > 0 && (
                      <tr className="text-muted">
                        <td className="py-3 pr-3 italic">Sin proyecto · {data.sin_proyecto.nmov} mov.</td>
                        <td className="px-3 py-3 text-right tabular-nums">{eurC(data.sin_proyecto.ing)}</td>
                        <td className="px-3 py-3 text-right tabular-nums">{eurC(data.sin_proyecto.gas)}</td>
                        <td className="px-3 py-3 text-right tabular-nums">{eurC(data.sin_proyecto.ing - data.sin_proyecto.gas)}</td>
                        <td colSpan={2} />
                      </tr>
                    )}
                  </tbody>
                </table>
                <ul className="md:hidden">
                  {data.items.map((x) => (
                    <li key={x.id} onClick={() => navigate(`/finanzas/proyectos/${x.id}`)} className="flex cursor-pointer items-center gap-3 border-b border-line2 py-3 last:border-b-0">
                      <span className="size-2.5 shrink-0 rounded-full" style={{ backgroundColor: x.color }} />
                      <span className="min-w-0 flex-1">
                        <b className="block truncate font-semibold text-ink-strong">{x.nombre}</b>
                        <span className="text-[12px] text-muted">
                          {eurC(x.ing)} − {eurC(x.gas)}
                        </span>
                      </span>
                      <b className={`tabular-nums ${x.ben >= 0 ? 'text-[#12854a] dark:text-ok' : 'text-[#e5484d] dark:text-danger'}`}>{eurC(x.ben)}</b>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </section>
          <MenuPanel {...cm.panel} label="Opciones del proyecto">
            {cm.dato && menu(cm.dato, true)}
          </MenuPanel>
        </>
      )}
    </div>
  )
}

function Margen({ ing, gas }: { ing: number; gas: number }) {
  const total = ing + gas
  const pct = ing > 0 ? Math.round(((ing - gas) / ing) * 100) : 0
  return (
    <span className="flex items-center gap-2">
      <span className="flex h-1.5 flex-1 overflow-hidden rounded-full bg-soft">
        {total > 0 && (
          <>
            <span className="bg-[#12a150]" style={{ width: `${(ing / total) * 100}%` }} />
            <span className="bg-[#e05a4f]" style={{ width: `${(gas / total) * 100}%` }} />
          </>
        )}
      </span>
      <span className="w-10 text-right text-[12px] text-muted tabular-nums">{ing > 0 ? `${pct}%` : '—'}</span>
    </span>
  )
}
