import { useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Archive, ArrowLeft, Check, ExternalLink, FileText, MoreHorizontal, Pencil, Plus, Trash2, UserCheck, X } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import EmptyState from '../../../shared/ui/EmptyState'
import Menu, { MenuItem, MenuLabel, MenuSeparator } from '../../../shared/ui/Menu'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { KanbanBoard, KanbanCard } from '../../../shared/ui/rich'
import { eur0 } from '../../../shared/lib/formato'
import { tonoVencimiento } from '../../../shared/lib/fechas'
import { useEquipo } from '../../nav/api'
import { mensajeError, useAccionNegocio, useCatalogos, useFases, useNegocios, useRestaurar } from '../api'
import { columnasEmbudo, euros, tonoParado } from '../logica'
import { usePermisosCrm } from '../permisos'
import type { Negocio } from '../schemas'
import { useConvertirCliente } from './Convertir'
import FasesModal from './FasesModal'
import MotivoPerdidaModal from './MotivoPerdidaModal'
import NegocioDetalle from './NegocioDetalle'
import NuevoNegocioModal from './NuevoNegocioModal'
import { EtiquetaChip } from './piezas'

const METRICA = 'rounded-[14px] border border-line bg-card px-[18px] py-4'

/* Negocio (negocio.php): métricas y embudo kanban por fases. Arrastrar a una
   fase de «perdidos» pide el motivo; el dueño reordena las columnas por el asa. */
export default function NegocioPage() {
  const [params, setParams] = useSearchParams()
  const archivados = params.get('archivados') === '1'
  const abierto = Number(params.get('open') ?? 0)
  const { data, error, isPending } = useNegocios(archivados)
  const { data: cat } = useCatalogos()
  const { data: equipo = [] } = useEquipo()
  const p = usePermisosCrm()
  const acc = useAccionNegocio()
  const fasesAcc = useFases()
  const restaurar = useRestaurar()
  const convertir = useConvertirCliente()
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const navigate = useNavigate()
  const [nuevo, setNuevo] = useState(false)
  const [editarFases, setEditarFases] = useState(false)
  const [perder, setPerder] = useState<{ negocio: Negocio; fase?: string } | null>(null)

  const fases = useMemo(() => cat?.fases ?? [], [cat])
  const items = useMemo(() => data?.items ?? [], [data])
  const columnas = useMemo(() => columnasEmbudo(fases, items), [fases, items])
  const nombres = new Map(equipo.map((x) => [x.id, x]))
  const m = data?.metricas
  const fallo = (e: unknown) => aviso(mensajeError(e), { tipo: 'error' })

  function abrir(id: number | null) {
    const n = new URLSearchParams(params)
    if (id) n.set('open', String(id))
    else n.delete('open')
    setParams(n, { replace: true })
  }

  function mover(n: Negocio, fase: string, indice?: number) {
    const destino = fases.find((f) => f.slug === fase)
    if (destino?.tipo === 'perdida' && n.fase !== fase) return setPerder({ negocio: n, fase })
    acc.mover.mutate({ id: n.id, fase, indice }, { onSuccess: () => n.fase !== fase && aviso(`Movido a ${destino?.nombre ?? fase}`), onError: fallo })
  }

  async function ganar(n: Negocio) {
    const ok = await confirm({ title: '¿Marcar como ganado?', message: 'Pasa a la fase «Ganado» y cuenta en el importe cerrado.', okLabel: 'Marcar ganado' })
    const ganada = fases.find((f) => f.tipo === 'ganada')
    if (ok && ganada) mover(n, ganada.slug)
  }

  async function archivar(n: Negocio, si: boolean) {
    if (si && !(await confirm({ title: '¿Archivar este negocio?', message: 'Sale del tablero pero no se borra: lo tienes en el filtro de archivados.', okLabel: 'Archivar' }))) return
    acc.editar.mutate({ id: n.id, archivado: si }, { onSuccess: () => aviso(si ? 'Negocio archivado' : 'Negocio de vuelta en el tablero'), onError: fallo })
  }

  async function borrar(n: Negocio) {
    const ok = await confirm({ title: '¿Eliminar este negocio?', message: 'El negocio se guarda en la papelera 30 días por si te arrepientes.', danger: true })
    if (!ok) return
    acc.borrar.mutate(n.id, {
      onSuccess: (r) => {
        abrir(null)
        aviso('Negocio eliminado', { accion: { label: 'Deshacer', fn: () => restaurar.mutate(r.papelera_id) } })
      },
      onError: fallo,
    })
  }

  function factura(n: Negocio) {
    if (n.invoice_id) navigate(`/finanzas/facturas/${n.invoice_id}`)
    else navigate(`/finanzas/facturas/nueva?negocio=${n.id}`)
  }

  const menu = (n: Negocio) => (
    <Menu
      align="right"
      label={`Acciones de ${n.nombre}`}
      trigger={() => (
        <span className="flex size-6 items-center justify-center rounded-md text-label hover:bg-chip hover:text-ink">
          <MoreHorizontal className="size-4" />
        </span>
      )}
    >
      <MenuLabel>{n.nombre}</MenuLabel>
      <MenuItem icon={<Pencil />} onSelect={() => abrir(n.id)}>
        Editar negocio
      </MenuItem>
      {!archivados && (
        <>
          <MenuItem icon={<Check />} onSelect={() => void ganar(n)}>
            Marcar ganado
          </MenuItem>
          <MenuItem icon={<X />} onSelect={() => setPerder({ negocio: n })}>
            Marcar perdido
          </MenuItem>
          <MenuSeparator />
          <MenuLabel>Mover a fase</MenuLabel>
          {fases
            .filter((f) => f.slug !== n.fase)
            .map((f) => (
              <MenuItem key={f.slug} color={f.color} onSelect={() => mover(n, f.slug)}>
                {f.nombre}
              </MenuItem>
            ))}
        </>
      )}
      <MenuSeparator />
      <MenuItem icon={<ExternalLink />} onSelect={() => navigate(`/crm/contactos/${n.contact_id}`)}>
        Ver contacto
      </MenuItem>
      {n.client_id && p.clientes ? (
        <MenuItem icon={<UserCheck />} onSelect={() => navigate(`/clientes/${n.client_id}`)}>
          Ver cliente
        </MenuItem>
      ) : (
        p.convertir && (
          <MenuItem icon={<UserCheck />} onSelect={() => void convertir({ negocio: n.id })}>
            Convertir en cliente
          </MenuItem>
        )
      )}
      {(n.invoice_id ? p.finanzas : p.facturar) && (
        <MenuItem icon={<FileText />} onSelect={() => factura(n)}>
          {n.invoice_id ? 'Ver factura' : 'Generar factura'}
        </MenuItem>
      )}
      <MenuSeparator />
      <MenuItem icon={<Archive />} onSelect={() => void archivar(n, !n.archivado)}>
        {n.archivado ? 'Desarchivar' : 'Archivar'}
      </MenuItem>
      {p.borrar && (
        <MenuItem icon={<Trash2 />} danger onSelect={() => void borrar(n)}>
          Eliminar
        </MenuItem>
      )}
    </Menu>
  )

  const detalle = abierto ? items.find((n) => n.id === abierto) : undefined

  return (
    <div>
      <PageHeader
        title={
          <span className="flex flex-wrap items-center gap-2.5">
            {archivados ? 'Negocios archivados' : 'Negocio'}
            {!archivados && p.dueno && (
              <Button variant="ghost" size="sm" onClick={() => setEditarFases(true)} className="tracking-normal">
                Editar fases
              </Button>
            )}
            {p.editar && (
              <Button variant="ghost" size="sm" icon={archivados ? <ArrowLeft /> : undefined} to={archivados ? '/crm/negocio' : '/crm/negocio?archivados=1'} className="tracking-normal">
                {archivados ? 'Activos' : 'Archivados'}
              </Button>
            )}
          </span>
        }
        actions={
          !archivados &&
          p.crear && (
            <Button icon={<Plus />} onClick={() => setNuevo(true)}>
              Nuevo negocio
            </Button>
          )
        }
      />

      {m && (
        <div className="mb-5 grid grid-cols-5 gap-3 max-[1100px]:grid-cols-3 max-[600px]:grid-cols-2">
          {[
            ['Negocios abiertos', String(m.abiertos), 'en el embudo'],
            ['Valor pipeline', euros(m.valor_pipeline), 'bruto abierto'],
            ['Ganado (mes)', euros(m.ganado_mes), `${m.n_ganado_mes} negocio${m.n_ganado_mes === 1 ? '' : 's'}`],
            ['Tasa conversión', `${m.conversion}%`, 'ganado vs cerrado'],
            ['Ticket medio', euros(m.ticket_medio), 'negocios ganados'],
          ].map(([t, v, s]) => (
            <div key={t} className={METRICA}>
              <div className="text-[11px] font-semibold tracking-[.4px] text-muted uppercase">{t}</div>
              <div className="mt-1.5 text-[19px] font-[750] tracking-[-.4px] text-ink-strong tabular-nums">{v}</div>
              <div className="mt-0.5 text-[11.5px] text-muted">{s}</div>
            </div>
          ))}
        </div>
      )}

      {error && <Notice tone="error">{mensajeError(error, 'No se ha podido cargar el embudo.')}</Notice>}
      {isPending && <div className="py-[60px] text-center text-[13px] text-muted">Cargando…</div>}
      {data && archivados && items.length === 0 && <EmptyState icon={<Archive />} title="No hay negocios archivados" text="Los que archives desde el embudo aparecerán aquí." />}
      {data && (!archivados || items.length > 0) && (
        <KanbanBoard
          columns={archivados ? columnas.filter((c) => c.items.length > 0) : columnas}
          getItemId={(n) => n.id}
          cardLabel={(n) => `${n.nombre}${n.valor !== null ? `, ${eur0(n.valor)}` : ''}`}
          onCardClick={(n) => abrir(n.id)}
          onMove={
            p.editar && !archivados
              ? (id, col, indice) => {
                  const n = items.find((x) => x.id === id)
                  if (n) mover(n, String(col), indice)
                }
              : undefined
          }
          onReorderColumns={
            p.dueno && !archivados
              ? (ids) => fasesAcc.orden.mutate(ids.map((slug) => fases.find((f) => f.slug === slug)?.id ?? 0).filter(Boolean), { onError: fallo })
              : undefined
          }
          renderCard={(n) => {
            const tono = tonoParado(n)
            const prop = n.propietario_id ? nombres.get(n.propietario_id) : undefined
            return (
              <>
                <KanbanCard
                  title={n.nombre}
                  subtitle={[n.contacto.empresa || n.contacto.nombre, n.contacto.sector].filter(Boolean).join(' · ')}
                  value={n.valor !== null ? eur0(n.valor) : undefined}
                  chips={
                    <>
                      {n.servicio && <span className="rounded-md bg-chip px-[7px] py-px text-[10.5px] font-semibold text-[#5c616b] dark:text-ink">{n.servicio}</span>}
                      {n.etiquetas.map((e) => (
                        <EtiquetaChip key={e.id} e={e} />
                      ))}
                    </>
                  }
                  avatar={prop ? <Avatar nombre={prop.username} foto={prop.foto} size={22} /> : undefined}
                  date={n.fecha_cierre_prevista ? n.fecha_cierre_prevista.slice(8, 10) + '/' + n.fecha_cierre_prevista.slice(5, 7) : undefined}
                  dateTone={n.tipo_fase === 'abierta' ? tonoVencimiento(n.fecha_cierre_prevista) : null}
                  menu={p.editar ? menu(n) : undefined}
                />
                {tono && (
                  <div className="mt-2 flex items-center gap-2">
                    <span className={`rounded-md px-[7px] py-px text-[10.5px] font-bold ${tono === 'peligro' ? 'bg-[#fdeaec] text-[#c62a33] dark:bg-danger-bg dark:text-danger' : 'bg-[#fdf1e3] text-[#9a5410] dark:bg-[#2a2210] dark:text-warn'}`}>
                      ⏱ {n.dias_en_fase} días sin avanzar
                    </span>
                    {p.editar && (
                      <button
                        type="button"
                        data-no-drag
                        onPointerDown={(e) => e.stopPropagation()}
                        onClick={(e) => {
                          e.stopPropagation()
                          void archivar(n, true)
                        }}
                        className="text-[11px] font-semibold text-muted underline-offset-2 hover:text-ink hover:underline"
                      >
                        archivar
                      </button>
                    )}
                  </div>
                )}
              </>
            )
          }}
        />
      )}

      {detalle && (
        <NegocioDetalle
          negocio={detalle}
          onClose={() => abrir(null)}
          onGanar={() => void ganar(detalle)}
          onPerder={() => setPerder({ negocio: detalle })}
          onMover={(f) => mover(detalle, f)}
          onArchivar={() => void archivar(detalle, !detalle.archivado)}
          onBorrar={() => void borrar(detalle)}
          onConvertir={() => void convertir({ negocio: detalle.id })}
          onFactura={() => factura(detalle)}
        />
      )}
      <NuevoNegocioModal open={nuevo} onClose={() => setNuevo(false)} />
      {perder && <MotivoPerdidaModal negocio={perder.negocio} fase={perder.fase} onClose={() => setPerder(null)} />}
      {editarFases && <FasesModal onClose={() => setEditarFases(false)} />}
    </div>
  )
}
