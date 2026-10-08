import { useMemo, useState, type MouseEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Briefcase, Download, ExternalLink, FolderPlus, Layers, Mail, Phone, Plus, Tag, Trash2, Upload, UserCheck, UserRound, Users } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import BulkBar, { type AccionLote } from '../../../shared/ui/BulkBar'
import DateInput from '../../../shared/ui/DatePicker'
import EmptyState from '../../../shared/ui/EmptyState'
import FilterBar, { SavedViewsMenu } from '../../../shared/ui/FilterBar'
import { MenuItem, MenuLabel, MenuPanel, MenuSeparator } from '../../../shared/ui/Menu'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import Select from '../../../shared/ui/Select'
import { TextInput } from '../../../shared/ui/TextInput'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useContextMenu } from '../../../shared/ui/useContextMenu'
import { useToast } from '../../../shared/ui/useToast'
import { useHotkeys } from '../../../shared/lib/useHotkeys'
import { useEquipo } from '../../nav/api'
import {
  exportarContactos,
  mensajeError,
  useAccionNegocio,
  useAccionVistas,
  useActualizarContacto,
  useBorrarContacto,
  useCatalogos,
  useContactos,
  useListas,
  useLote,
  useRestaurar,
  useVistas,
  type CambioContacto,
} from '../api'
import { chipsFiltros, contarAvanzados, descargarTexto, enlaceGmail, enlaceTel, enlaceWhatsapp, filtrosDeParams, filtrosDeVista, importeTexto, paramsDeFiltros, RAPIDOS, vistaDeFiltros, type Filtros } from '../logica'
import { usePermisosCrm } from '../permisos'
import type { Contacto } from '../schemas'
import { useConvertirCliente } from './Convertir'
import EtiquetasModal from './EtiquetasModal'
import FichaContacto from './FichaContacto'
import NuevoContactoModal from './NuevoContactoModal'
import { FaseBadge } from './piezas'
import TablaContactos from './TablaContactos'

const ETIQUETA = 'mb-1.5 block text-[10.5px] font-bold tracking-[.5px] text-muted uppercase'

/* Contactos del CRM (crm.php): tabla editable con filtros en la URL, vistas
   guardadas, etiquetas, acciones en lote, menú por fila y la ficha encima
   (/crm/contactos/:id). Atajos: «/» busca, «n» nuevo contacto. */
export default function ContactosPage() {
  const [params, setParams] = useSearchParams()
  const { id: idRuta } = useParams()
  const abierto = Number(idRuta ?? 0)
  const navigate = useNavigate()
  const f = useMemo(() => filtrosDeParams(params), [params])
  const consulta = useContactos(f)
  const { data: cat } = useCatalogos()
  const { data: equipo = [] } = useEquipo()
  const { data: vistas = [] } = useVistas()
  const p = usePermisosCrm()
  const guardar = useActualizarContacto()
  const borrar = useBorrarContacto()
  const lote = useLote()
  const restaurar = useRestaurar()
  const vistasAcc = useAccionVistas()
  const negocios = useAccionNegocio()
  const { data: listas = [] } = useListas(p.editar)
  const convertir = useConvertirCliente()
  const { confirm, prompt } = useConfirm()
  const { aviso } = useToast()
  const cm = useContextMenu<Contacto>()
  const [nuevo, setNuevo] = useState(false)
  const [etiquetas, setEtiquetas] = useState(false)
  const [seleccion, setSeleccion] = useState<Set<number>>(new Set())

  const paginas = consulta.data?.pages ?? []
  const items = paginas.flatMap((pg) => pg.items)
  const primera = paginas[0]
  const total = primera?.total ?? 0
  const totalSin = primera?.total_sin_filtros ?? 0
  const hayFiltros = contarAvanzados(f) > 0 || !!f.q || !!f.quick
  const sufijo = (() => {
    const s = new URLSearchParams(params)
    return s.toString() ? `?${s.toString()}` : ''
  })()

  useHotkeys(
    {
      '/': (e) => {
        e.preventDefault()
        document.querySelector<HTMLInputElement>('[data-crm-buscador] input[type=search]')?.focus()
      },
      n: (e) => {
        if (!p.crear || abierto) return
        e.preventDefault()
        setNuevo(true)
      },
    },
    { enabled: !nuevo && !etiquetas },
  )

  function poner(cambios: Partial<Filtros>) {
    setParams(paramsDeFiltros({ ...f, ...cambios }, params), { replace: true })
    setSeleccion(new Set())
  }

  function limpiar() {
    setParams(paramsDeFiltros({ sort: f.sort, dir: f.dir }), { replace: true })
  }

  function ordenar(clave: string) {
    poner({ sort: clave, dir: f.sort === clave && f.dir !== 'asc' ? 'asc' : 'desc' })
  }

  function abrir(c: Contacto) {
    navigate(`/crm/contactos/${c.id}${sufijo}`)
  }

  function onGuardar(c: Contacto, cambio: CambioContacto) {
    guardar.mutate({ id: c.id, cambio })
  }

  async function pedirBorrar(c: Contacto) {
    const ok = await confirm({ title: '¿Eliminar este contacto?', message: 'Se va a la papelera con sus negocios, comentarios y archivos. Podrás deshacerlo.', danger: true })
    if (ok) borrar.mutate({ id: c.id, nombre: c.nombre })
  }

  async function crearNegocio(c: Contacto) {
    const ok = await confirm({ title: '¿Crear un negocio para este contacto?', okLabel: 'Crear negocio' })
    if (!ok) return
    negocios.crear.mutate({ contact_id: c.id }, { onSuccess: () => navigate('/crm/negocio'), onError: (e) => aviso(mensajeError(e), { tipo: 'error' }) })
  }

  async function exportar(ids?: number[]) {
    try {
      const r = await exportarContactos(ids ? { ids: ids.join(',') } : vistaDeFiltros(f))
      descargarTexto(r.nombre, r.csv)
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido exportar.'), { tipo: 'error' })
    }
  }

  async function enLote(accion: Parameters<typeof lote.mutate>[0]['accion']) {
    const ids = [...seleccion]
    try {
      const r = await lote.mutateAsync({ ids, accion })
      setSeleccion(new Set())
      if (accion.op === 'borrar') {
        const tids = r.papelera_ids ?? []
        aviso(`${r.n} contacto(s) eliminados`, {
          accion: { label: 'Deshacer', fn: () => void Promise.all(tids.map((t) => restaurar.mutateAsync(t))).then(() => aviso('Contactos restaurados')) },
        })
      } else if (accion.op === 'nueva_lista' && r.list_id) {
        aviso(`Lista «${accion.nombre}» creada con ${r.n} contacto(s)`)
      } else aviso(`${r.n} contacto(s) actualizados`)
    } catch (e) {
      aviso(mensajeError(e), { tipo: 'error' })
    }
  }

  const acciones: AccionLote[] = [
    {
      label: 'Asignar',
      icon: <UserCheck />,
      menu: (cerrar) => (
        <>
          <MenuLabel>Asignar a</MenuLabel>
          <MenuItem onSelect={() => void enLote({ op: 'asignar', propietario_id: null })}>Sin propietario</MenuItem>
          {equipo.map((x) => (
            <MenuItem key={x.id} onSelect={() => { cerrar(); void enLote({ op: 'asignar', propietario_id: x.id }) }}>
              {x.username}
            </MenuItem>
          ))}
        </>
      ),
    },
    {
      label: 'Añadir a lista',
      icon: <Layers />,
      menu: () => (
        <>
          {p.crear && (
            <MenuItem
              icon={<FolderPlus />}
              onSelect={async () => {
                const nombre = await prompt({ title: 'Nueva lista', placeholder: 'Nombre de la lista', okLabel: 'Crear lista' })
                if (nombre) void enLote({ op: 'nueva_lista', nombre })
              }}
            >
              Crear lista nueva…
            </MenuItem>
          )}
          <MenuSeparator />
          {listas.length === 0 && <div className="px-3 py-2 text-[12.5px] text-muted">Aún no has creado ninguna lista.</div>}
          {listas.map((l) => (
            <MenuItem key={l.id} onSelect={() => void enLote({ op: 'a_lista', list_id: l.id })}>
              {l.nombre}
            </MenuItem>
          ))}
        </>
      ),
    },
    {
      label: 'Etiquetar',
      icon: <Tag />,
      menu: () => (
        <>
          {(cat?.etiquetas ?? []).length === 0 && <div className="px-3 py-2 text-[12.5px] text-muted">Aún no has creado ninguna etiqueta.</div>}
          {(cat?.etiquetas ?? []).map((t) => (
            <MenuItem key={t.id} color={t.color} onSelect={() => void enLote({ op: 'etiquetar', tag_id: t.id })}>
              {t.nombre}
            </MenuItem>
          ))}
        </>
      ),
    },
    { label: 'Exportar', icon: <Download />, onClick: () => void exportar([...seleccion]) },
    ...(p.borrar
      ? [
          {
            label: 'Eliminar',
            icon: <Trash2 />,
            danger: true,
            onClick: async () => {
              const ok = await confirm({ title: `¿Eliminar ${seleccion.size} contacto(s)?`, message: 'Van a la papelera con todo lo suyo. Podrás deshacerlo.', danger: true })
              if (ok) void enLote({ op: 'borrar' })
            },
          } satisfies AccionLote,
        ]
      : []),
  ]

  const c = cm.dato
  const panelFiltros = cat && (
    <>
      <div>
        <span className={ETIQUETA}>Sector</span>
        <Select size="sm" value={f.sector} onChange={(v) => poner({ sector: v })} options={[{ value: '', label: 'Cualquiera' }, ...cat.sectores.map((s) => ({ value: s, label: s }))]} aria-label="Sector" />
      </div>
      <div>
        <span className={ETIQUETA}>Origen</span>
        <Select size="sm" value={f.origen} onChange={(v) => poner({ origen: v })} options={[{ value: '', label: 'Cualquiera' }, ...cat.origenes.map((s) => ({ value: s, label: s }))]} aria-label="Origen" />
      </div>
      <div>
        <span className={ETIQUETA}>Embudo de venta</span>
        <Select size="sm" value={f.fase} onChange={(v) => poner({ fase: v })} options={[{ value: '', label: 'Cualquiera' }, ...cat.fases.map((s) => ({ value: s.slug, label: s.nombre, color: s.color }))]} aria-label="Embudo de venta" />
      </div>
      <div>
        <span className={ETIQUETA}>Servicio</span>
        <Select size="sm" value={f.servicio} onChange={(v) => poner({ servicio: v })} options={[{ value: '', label: 'Cualquiera' }, ...cat.servicios.map((s) => ({ value: s, label: s }))]} aria-label="Servicio" />
      </div>
      <div>
        <span className={ETIQUETA}>Propietario</span>
        <Select
          size="sm"
          value={f.prop}
          onChange={(v) => poner({ prop: v })}
          options={[{ value: '', label: 'Cualquiera' }, { value: 'sin', label: 'Sin propietario' }, ...equipo.map((x) => ({ value: String(x.id), label: x.username }))]}
          aria-label="Propietario"
        />
      </div>
      <div>
        <span className={ETIQUETA}>Etiqueta</span>
        <Select size="sm" value={f.tag} onChange={(v) => poner({ tag: v })} options={[{ value: '', label: 'Cualquiera' }, ...cat.etiquetas.map((t) => ({ value: String(t.id), label: t.nombre, color: t.color }))]} aria-label="Etiqueta" />
      </div>
      <div>
        <span className={ETIQUETA}>Valor (€)</span>
        <div className="flex gap-2">
          <TextInput size="sm" defaultValue={f.vmin} key={`vmin${f.vmin}`} placeholder="mín" inputMode="decimal" onBlur={(e) => e.target.value.trim() !== f.vmin && poner({ vmin: e.target.value.trim() })} aria-label="Valor mínimo" />
          <TextInput size="sm" defaultValue={f.vmax} key={`vmax${f.vmax}`} placeholder="máx" inputMode="decimal" onBlur={(e) => e.target.value.trim() !== f.vmax && poner({ vmax: e.target.value.trim() })} aria-label="Valor máximo" />
        </div>
      </div>
      <div>
        <span className={ETIQUETA}>Creado entre</span>
        <div className="flex gap-2">
          <DateInput size="sm" value={f.fdesde || null} onChange={(v) => poner({ fdesde: v ?? '' })} placeholder="desde" aria-label="Creado desde" />
          <DateInput size="sm" value={f.fhasta || null} onChange={(v) => poner({ fhasta: v ?? '' })} placeholder="hasta" aria-label="Creado hasta" />
        </div>
      </div>
    </>
  )

  const coincidentes = primera?.negocios_coincidentes ?? []

  return (
    <div>
      <PageHeader
        title={
          <span className="inline-flex items-baseline gap-2.5">
            Contactos
            <span className="text-[14px] font-semibold tracking-normal text-muted tabular-nums">{hayFiltros ? `${total} de ${totalSin}` : totalSin}</span>
          </span>
        }
        actions={
          p.crear && (
            <>
              <Button variant="ghost" icon={<Upload />} to="/crm/importar">
                Importar
              </Button>
              <Button icon={<Plus />} onClick={() => setNuevo(true)}>
                Nuevo contacto
              </Button>
            </>
          )
        }
      />

      <div data-crm-buscador>
      <FilterBar
        search={f.q}
        onSearch={(v) => poner({ q: v })}
        searchPlaceholder="Buscar nombre, empresa, email, teléfono…"
        filtersCount={contarAvanzados(f)}
        panel={panelFiltros}
        onClearFilters={limpiar}
        quick={{ items: RAPIDOS, value: f.quick, onChange: (v) => poner({ quick: v }) }}
        active={chipsFiltros(f, cat, equipo).map((ch) => ({ key: ch.key, label: ch.label, onRemove: () => poner(Object.fromEntries(ch.quitar.map((k) => [k, '']))) }))}
        right={
          <>
            <SavedViewsMenu
              views={vistas.map((v) => ({ id: String(v.id), label: v.global ? `${v.nombre} · todos` : v.nombre }))}
              onApply={(v) => {
                const vista = vistas.find((x) => String(x.id) === v.id)
                if (vista) setParams(paramsDeFiltros(filtrosDeVista(vista.filtros)), { replace: true })
              }}
              onSave={async () => {
                const nombre = await prompt({ title: 'Guardar vista', message: 'Se guardan los filtros que tienes puestos ahora mismo.', placeholder: 'Nombre de la vista' })
                if (nombre) vistasAcc.crear.mutate({ nombre, filtros: vistaDeFiltros(f) }, { onSuccess: () => aviso('Vista guardada'), onError: (e) => aviso(mensajeError(e), { tipo: 'error' }) })
              }}
              onDelete={async (v) => {
                const vista = vistas.find((x) => String(x.id) === v.id)
                if (!vista?.puede_borrar) return aviso('Solo puedes borrar tus vistas.', { tipo: 'error' })
                if (await confirm({ title: '¿Eliminar esta vista?', danger: true })) vistasAcc.borrar.mutate(vista.id)
              }}
            />
            {p.editar && (
              <Button variant="ghost" icon={<Tag />} onClick={() => setEtiquetas(true)} className="!rounded-[11px] !py-[9px] !text-[13px]">
                Etiquetas
              </Button>
            )}
          </>
        }
        className="[&_input[type=search]]:min-w-0"
      />
      </div>

      {coincidentes.length > 0 && (
        <div className="mb-4 rounded-[14px] border border-line bg-card px-4 py-3">
          <div className="mb-2 text-[10.5px] font-bold tracking-[.5px] text-muted uppercase">Negocios que coinciden ({coincidentes.length})</div>
          <ul className="flex flex-col gap-1">
            {coincidentes.map((n) => (
              <li key={n.id}>
                <Link to={`/crm/negocio?open=${n.id}`} className="flex items-center gap-3 rounded-lg px-2 py-1.5 text-[13px] hover:bg-soft">
                  <Briefcase className="size-4 shrink-0 text-label" />
                  <span className="min-w-0 flex-1 truncate font-semibold text-ink-strong">{n.nombre}</span>
                  <span className="truncate text-muted max-sm:hidden">{n.contacto}</span>
                  {n.valor !== null && <span className="font-semibold text-ok tabular-nums">{importeTexto(n.valor)}</span>}
                  <FaseBadge fases={cat?.fases} fase={n.fase} size="sm" />
                </Link>
              </li>
            ))}
          </ul>
        </div>
      )}

      {consulta.error && <Notice tone="error">{mensajeError(consulta.error, 'No se han podido cargar los contactos.')}</Notice>}
      {consulta.isPending && <div className="py-[60px] text-center text-[13px] text-muted">Cargando…</div>}
      {primera && items.length === 0 && (
        <EmptyState
          icon={<Users />}
          title={totalSin > 0 ? 'Ningún contacto coincide' : 'Aún no hay contactos'}
          text={totalSin > 0 ? 'Prueba a quitar algún filtro o a buscar otra cosa.' : 'Aquí verás tus leads y contactos. Crea el primero con el botón «Nuevo contacto» de arriba.'}
        />
      )}
      {items.length > 0 && (
        <TablaContactos
          items={items}
          cat={cat}
          equipo={equipo}
          p={p}
          filtros={f}
          onSort={ordenar}
          seleccion={seleccion}
          onSeleccion={setSeleccion}
          onAbrir={abrir}
          onGuardar={onGuardar}
          onMenu={(e: MouseEvent, x) => cm.onContextMenu(e, x)}
          pie={
            consulta.hasNextPage ? (
              <div className="p-3 text-center">
                <Button variant="ghost" size="sm" loading={consulta.isFetchingNextPage} loadingText="Cargando…" onClick={() => void consulta.fetchNextPage()}>
                  Cargar más
                </Button>
              </div>
            ) : null
          }
        />
      )}

      <MenuPanel {...cm.panel} label={c ? `Acciones de ${c.nombre}` : 'Acciones'} minWidth={196}>
        {c && (
          <>
            <MenuLabel>{c.nombre}</MenuLabel>
            <MenuItem icon={<UserRound />} onSelect={() => abrir(c)}>
              Abrir ficha
            </MenuItem>
            {enlaceGmail(c.email) && (
              <MenuItem icon={<Mail />} onSelect={() => window.open(enlaceGmail(c.email) ?? '', '_blank', 'noopener')}>
                Enviar email
              </MenuItem>
            )}
            {enlaceTel(c.telefono) && (
              <MenuItem icon={<Phone />} onSelect={() => (window.location.href = enlaceTel(c.telefono) ?? '')}>
                Llamar
              </MenuItem>
            )}
            {enlaceWhatsapp(c.whatsapp || c.telefono) && (
              <MenuItem icon={<ExternalLink />} onSelect={() => window.open(enlaceWhatsapp(c.whatsapp || c.telefono) ?? '', '_blank', 'noopener')}>
                WhatsApp
              </MenuItem>
            )}
            {c.client_id && p.clientes && (
              <MenuItem icon={<ExternalLink />} onSelect={() => navigate(`/clientes/${c.client_id}`)}>
                Ver ficha de cliente
              </MenuItem>
            )}
            {(p.crear || p.convertir || p.borrar) && <MenuSeparator />}
            {p.crear && (
              <MenuItem icon={<Briefcase />} onSelect={() => void crearNegocio(c)}>
                Crear negocio
              </MenuItem>
            )}
            {!c.client_id && p.convertir && (
              <MenuItem icon={<UserCheck />} onSelect={() => void convertir({ contacto: c.id })}>
                Convertir en cliente
              </MenuItem>
            )}
            {p.borrar && (
              <MenuItem icon={<Trash2 />} danger onSelect={() => void pedirBorrar(c)}>
                Eliminar contacto
              </MenuItem>
            )}
          </>
        )}
      </MenuPanel>

      {p.editar && <BulkBar count={seleccion.size} actions={acciones} onClear={() => setSeleccion(new Set())} />}
      <NuevoContactoModal open={nuevo} onClose={() => setNuevo(false)} onCreado={(x) => navigate(`/crm/contactos/${x.id}${sufijo}`)} />
      <EtiquetasModal open={etiquetas} onClose={() => setEtiquetas(false)} />
      {abierto > 0 && <FichaContacto id={abierto} ids={items.map((x) => x.id)} sufijo={sufijo} onClose={() => navigate(`/crm${sufijo}`)} />}
    </div>
  )
}

