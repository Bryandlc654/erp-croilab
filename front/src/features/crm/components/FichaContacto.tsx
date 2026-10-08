import { useRef, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { CalendarPlus, ChevronLeft, ChevronRight, ExternalLink, FileText, Link2, Mail, MessageCircle, NotebookPen, Paperclip, Phone, Plus, Trash2, UserCheck, X } from 'lucide-react'
import Avatar from '../../../shared/ui/Avatar'
import Button from '../../../shared/ui/Button'
import IconButton from '../../../shared/ui/IconButton'
import Modal from '../../../shared/ui/Modal'
import Select from '../../../shared/ui/Select'
import StatusPill from '../../../shared/ui/StatusPill'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { ActivityTimeline, CommentComposer, RichTextView } from '../../../shared/ui/rich'
import { url } from '../../../shared/api/client'
import { aFecha, fechaCorta } from '../../../shared/lib/formato'
import { ESTADOS_REUNION } from '../../../shared/lib/paletas'
import { useHotkeys } from '../../../shared/lib/useHotkeys'
import { useEquipo } from '../../nav/api'
import { mensajeError, useAccionFicha, useActualizarContacto, useCatalogos, useFicha, type CambioContacto } from '../api'
import { enlaceGmail, enlaceTel, enlaceWhatsapp, importeTexto, vecino } from '../logica'
import { usePermisosCrm } from '../permisos'
import type { Ficha } from '../schemas'
import { CeldaImporte, CeldaTexto } from './celdas'
import { useConvertirCliente } from './Convertir'
import { FaseBadge, FasePicker, Seccion, TipoTag } from './piezas'
import PropuestaModal from './PropuestaModal'

const FECHA_HORA = (s: string) => {
  const d = aFecha(s)
  if (!d) return ''
  const dos = (n: number) => String(n).padStart(2, '0')
  return `${dos(d.getDate())}/${dos(d.getMonth() + 1)}/${d.getFullYear()} ${dos(d.getHours())}:${dos(d.getMinutes())}`
}

const BOTON = 'inline-flex items-center gap-1.5 rounded-[10px] border border-line bg-card px-3.5 py-[9px] text-[13px] font-semibold text-ink transition-colors hover:border-line-strong hover:bg-soft [&>svg]:size-[15px]'

/* Ficha del contacto (crm_profile.php) en un modal grande sobre la tabla:
   ‹ › y las flechas del teclado recorren los contactos del listado filtrado. */
export default function FichaContacto({ id, ids, sufijo, onClose }: { id: number; ids: number[]; sufijo: string; onClose: () => void }) {
  const { data: ficha, error, isPending } = useFicha(id)
  const navigate = useNavigate()
  const ir = (paso: 1 | -1) => {
    const otro = vecino(ids, id, paso)
    if (otro) navigate(`/crm/contactos/${otro}${sufijo}`, { replace: true })
  }
  useHotkeys({ arrowleft: () => ir(-1), arrowright: () => ir(1) })
  const pos = ids.indexOf(id)

  return (
    <Modal open onClose={onClose} size="full" align="top" aria-label={ficha ? `Ficha de ${ficha.contacto.nombre}` : 'Ficha del contacto'} className="!overflow-y-auto sm:max-h-[94vh] sm:rounded-[20px]">
      {isPending && <div className="py-[60px] text-center text-[13px] text-muted">Cargando…</div>}
      {error && (
        <div className="flex items-center justify-between gap-3 px-7 py-6">
          <p className="text-[14px] text-muted">{mensajeError(error, 'Error al cargar.')}</p>
          <IconButton label="Cerrar" icon={<X />} onClick={onClose} size={34} />
        </div>
      )}
      {ficha && (
        <Contenido
          key={ficha.contacto.id}
          ficha={ficha}
          nav={
            <div className="flex shrink-0 items-center gap-1.5">
              {pos >= 0 && (
                <>
                  <IconButton label="Contacto anterior (←)" icon={<ChevronLeft />} size={34} disabled={pos <= 0} onClick={() => ir(-1)} className="rounded-[9px]" />
                  <span className="min-w-[48px] text-center text-[12.5px] font-semibold text-muted tabular-nums max-sm:hidden">
                    {pos + 1} / {ids.length}
                  </span>
                  <IconButton label="Contacto siguiente (→)" icon={<ChevronRight />} size={34} disabled={pos >= ids.length - 1} onClick={() => ir(1)} className="rounded-[9px]" />
                </>
              )}
              <button type="button" onClick={onClose} aria-label="Cerrar" className="ml-1 flex size-[34px] items-center justify-center rounded-[9px] bg-soft text-label hover:text-ink">
                <X className="size-[18px]" />
              </button>
            </div>
          }
        />
      )}
    </Modal>
  )
}

function Contenido({ ficha, nav }: { ficha: Ficha; nav: ReactNode }) {
  const c = ficha.contacto
  const { data: cat } = useCatalogos()
  const { data: equipo = [] } = useEquipo()
  const p = usePermisosCrm()
  const ed = p.editar
  const guardar = useActualizarContacto()
  const acc = useAccionFicha(c.id)
  const convertir = useConvertirCliente()
  const { confirm } = useConfirm()
  const { aviso } = useToast()
  const navigate = useNavigate()
  const [tipo, setTipo] = useState('nota')
  const [propuesta, setPropuesta] = useState(false)
  const zonaNota = useRef<HTMLDivElement>(null)
  const fichero = useRef<HTMLInputElement>(null)

  const g = (cambio: CambioContacto) => guardar.mutate({ id: c.id, cambio })
  const fallo = (e: unknown) => aviso(mensajeError(e), { tipo: 'error' })
  const subtitulo = [c.sector, c.empresa, c.servicios.join(', ')].filter(Boolean).join(' · ')
  const tipos = cat?.tipos_comentario ?? []
  const etiquetaTipo = (t: string) => tipos.find((x) => x.value === t)?.label ?? 'Nota'

  function interaccion(t: 'email' | 'llamada' | 'whatsapp', desc: string) {
    if (ed) acc.actividad.mutate({ tipo: t, descripcion: desc })
  }

  const campo = (label: string, valor: string, k: keyof CambioContacto, extra: { type?: string; list?: string } = {}) => (
    <label className="flex min-w-0 flex-col gap-0.5">
      <span className="px-2 text-[11.5px] font-semibold text-muted">{label}</span>
      <CeldaTexto value={valor} readOnly={!ed} placeholder="—" {...extra} onSave={(v) => g({ [k]: v } as CambioContacto)} className="!max-w-none" />
    </label>
  )
  const campoFact = (label: string, k: keyof Ficha['facturacion']) => (
    <label className="flex min-w-0 flex-col gap-0.5">
      <span className="px-2 text-[11.5px] font-semibold text-muted">{label}</span>
      <CeldaTexto
        value={ficha.facturacion[k]}
        readOnly={!ed}
        placeholder="—"
        onSave={(v) => acc.facturacion.mutate({ [k]: v }, { onSuccess: () => aviso('Guardado'), onError: fallo })}
        className="!max-w-none"
      />
    </label>
  )

  return (
    <div>
      {/* Cabecera fija */}
      <header className="sticky top-0 z-[3] flex items-start gap-3.5 border-b border-line bg-card px-7 py-[22px] max-sm:gap-2.5 max-sm:px-4 max-sm:py-4">
        <Avatar nombre={c.nombre} size={48} />
        <div className="min-w-0 flex-1">
          <input
            defaultValue={c.nombre}
            key={c.nombre}
            readOnly={!ed}
            aria-label="Nombre"
            maxLength={200}
            onBlur={(e) => e.target.value.trim() && e.target.value.trim() !== c.nombre && g({ nombre: e.target.value.trim() })}
            onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
            className="w-full max-w-[360px] rounded-lg bg-transparent px-1 py-0.5 text-[21px] font-[650] tracking-[-.3px] text-ink-strong focus:bg-soft focus:outline-none read-only:focus:bg-transparent"
          />
          <div className="mt-1 flex flex-wrap items-center gap-2 px-1 text-[12.5px] text-muted">
            {subtitulo && <span className="truncate">{subtitulo}</span>}
            <FasePicker fases={cat?.fases} value={c.fase} disabled={!ed} size="sm" onChange={(f) => g({ fase: f })} />
          </div>
        </div>
        {nav}
      </header>

      {/* Acciones */}
      <div className="flex flex-wrap gap-2 border-b border-line2 px-7 py-4 max-sm:px-4">
        {p.crear && (
          <button type="button" className={BOTON} onClick={() => zonaNota.current?.querySelector('textarea')?.focus()}>
            <NotebookPen /> Crear nota
          </button>
        )}
        {enlaceGmail(c.email) && (
          <a href={enlaceGmail(c.email) ?? undefined} target="_blank" rel="noopener noreferrer" className={BOTON} onClick={() => interaccion('email', 'Email abierto')}>
            <Mail /> Email
          </a>
        )}
        {enlaceTel(c.telefono) && (
          <a
            href={enlaceTel(c.telefono) ?? undefined}
            className={BOTON}
            onClick={() => {
              void navigator.clipboard?.writeText(c.telefono).catch(() => undefined)
              interaccion('llamada', 'Llamada iniciada')
            }}
          >
            <Phone /> Llamar
          </a>
        )}
        {enlaceWhatsapp(c.whatsapp || c.telefono) && (
          <a href={enlaceWhatsapp(c.whatsapp || c.telefono) ?? undefined} target="_blank" rel="noopener noreferrer" className={BOTON} onClick={() => interaccion('whatsapp', 'WhatsApp abierto')}>
            <MessageCircle /> WhatsApp
          </a>
        )}
        {p.agenda && (
          <button type="button" className={BOTON} onClick={() => navigate(`/reuniones?nuevo=1&contacto=${c.id}${c.client_id ? `&cli=${c.client_id}` : ''}`)}>
            <CalendarPlus /> Agendar reunión
          </button>
        )}
        {ficha.cliente ? (
          p.clientes && (
            <button
              type="button"
              className={`${BOTON} !border-[#bfe3cc] !bg-[#f2fbf5] !text-[#12703f] dark:!border-ok-line dark:!bg-ok-bg dark:!text-ok`}
              onClick={() => navigate(`/clientes/${ficha.cliente?.id}`)}
            >
              <UserCheck /> Ver ficha de {ficha.cliente.name}
            </button>
          )
        ) : (
          p.convertir && (
            <button type="button" className={BOTON} onClick={() => void convertir({ contacto: c.id })}>
              <UserCheck /> Convertir en cliente
            </button>
          )
        )}
      </div>

      {/* Cuerpo en dos columnas */}
      <div className="grid grid-cols-[1.1fr_1fr] max-[820px]:grid-cols-1">
        <div className="min-w-0 border-r border-line2 px-7 py-6 max-[820px]:border-r-0 max-[820px]:border-b max-sm:px-4" ref={zonaNota}>
          <Seccion titulo="Comentarios / Actualizaciones">
            {p.crear && (
              <CommentComposer
                variant="plain"
                people={equipo}
                allowFiles={false}
                placeholder="Escribe una actualización… (@ para mencionar)"
                submitLabel="Publicar"
                header={
                  <div className="mb-2 flex flex-wrap gap-1.5" role="radiogroup" aria-label="Tipo de actualización">
                    {tipos.map((t) => (
                      <button
                        key={t.value}
                        type="button"
                        role="radio"
                        aria-checked={tipo === t.value}
                        onClick={() => setTipo(t.value)}
                        className={`rounded-lg border px-[11px] py-[5px] text-[12px] font-semibold transition-colors ${
                          tipo === t.value ? 'border-ink-strong bg-ink-strong text-white dark:border-rev dark:bg-rev dark:text-rev-fg' : 'border-line bg-field text-muted hover:text-ink'
                        }`}
                      >
                        {t.label}
                      </button>
                    ))}
                  </div>
                }
                onSend={({ cuerpo }) =>
                  acc.comentar.mutateAsync({ tipo, contenido: cuerpo }).catch((e: unknown) => {
                    fallo(e)
                    throw e
                  })
                }
              />
            )}
            <ul className="mt-4 flex flex-col">
              {ficha.comentarios.length === 0 && <li className="py-3 text-[13px] text-muted">Sin comentarios todavía.</li>}
              {ficha.comentarios.map((k) => (
                <li key={k.id} className="group/com flex gap-2.5 border-t border-line2 py-3 first:border-t-0">
                  <Avatar nombre={k.autor || '?'} foto={equipo.find((x) => x.id === k.autor_id)?.foto} size={28} />
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <b className="text-[13px] font-semibold text-ink-strong">{k.autor || 'Equipo'}</b>
                      <TipoTag tipo={k.tipo} label={etiquetaTipo(k.tipo)} />
                      <span className="text-[11.5px] text-muted">{FECHA_HORA(k.fecha)}</span>
                      {k.puede_borrar && (
                        <IconButton
                          label="Borrar comentario"
                          tone="danger"
                          size={26}
                          icon={<X />}
                          className="ml-auto opacity-0 group-hover/com:opacity-100 focus-visible:opacity-100 max-sm:opacity-100"
                          onClick={async () => {
                            if (await confirm({ title: '¿Borrar el comentario?', danger: true })) acc.borrarComentario.mutate(k.id, { onError: fallo })
                          }}
                        />
                      )}
                    </div>
                    <RichTextView value={k.contenido} people={equipo} className="mt-1 text-[13.5px] text-ink" />
                  </div>
                </li>
              ))}
            </ul>
          </Seccion>
        </div>

        <div className="min-w-0 px-7 py-6 max-sm:px-4">
          <Seccion titulo="Etiquetas">
            {(cat?.etiquetas ?? []).length === 0 ? (
              <p className="text-[12.5px] text-muted">Sin etiquetas. Créalas en Contactos › Etiquetas.</p>
            ) : (
              <div className="flex flex-wrap gap-1.5">
                {(cat?.etiquetas ?? []).map((t) => {
                  const on = c.etiquetas.some((x) => x.id === t.id)
                  return (
                    <button
                      key={t.id}
                      type="button"
                      disabled={!ed}
                      aria-pressed={on}
                      onClick={() => acc.etiqueta.mutate({ tag: t.id, on: !on }, { onSuccess: () => aviso(on ? 'Etiqueta quitada' : 'Etiqueta añadida'), onError: fallo })}
                      className={`rounded-md px-2.5 py-[3px] text-[11.5px] font-bold transition-colors disabled:cursor-default ${on ? 'text-white' : 'bg-soft text-muted hover:text-ink'}`}
                      style={on ? { backgroundColor: t.color } : undefined}
                    >
                      {t.nombre}
                    </button>
                  )
                })}
              </div>
            )}
          </Seccion>

          <Seccion titulo="Datos de contacto">
            <div className="grid grid-cols-2 gap-x-3 gap-y-1.5 max-sm:grid-cols-1">
              {campo('Email', c.email, 'email', { type: 'email' })}
              {campo('Teléfono', c.telefono, 'telefono', { type: 'tel' })}
              {campo('WhatsApp', c.whatsapp, 'whatsapp', { type: 'tel' })}
              {campo('LinkedIn', c.linkedin, 'linkedin')}
              {campo('Web', c.web, 'web')}
            </div>
          </Seccion>

          <Seccion titulo="Datos comerciales">
            <div className="grid grid-cols-2 gap-x-3 gap-y-1.5 max-sm:grid-cols-1">
              {campo('Sector', c.sector, 'sector', { list: 'crm-sectores-ficha' })}
              {campo('Origen', c.origen_lead, 'origen_lead', { list: 'crm-origenes-ficha' })}
              <label className="flex min-w-0 flex-col gap-0.5">
                <span className="px-2 text-[11.5px] font-semibold text-muted">Valor (€)</span>
                <CeldaImporte value={c.valor} readOnly={!ed} placeholder="—" onSave={(v) => g({ valor: v === '' ? null : v })} className="!w-full !text-left" />
              </label>
              <label className="flex min-w-0 flex-col gap-0.5">
                <span className="px-2 text-[11.5px] font-semibold text-muted">Propietario</span>
                <Select
                  variant="inline"
                  disabled={!ed}
                  value={c.propietario_id ?? 0}
                  onChange={(v) => g({ propietario_id: v || null })}
                  options={[{ value: 0, label: 'Sin propietario' }, ...equipo.map((x) => ({ value: x.id, label: x.username }))]}
                  aria-label="Propietario"
                  className="!text-[13px]"
                />
              </label>
            </div>
            <datalist id="crm-sectores-ficha">
              {(cat?.sectores ?? []).map((s) => (
                <option key={s} value={s} />
              ))}
            </datalist>
            <datalist id="crm-origenes-ficha">
              {(cat?.origenes ?? []).map((s) => (
                <option key={s} value={s} />
              ))}
            </datalist>
          </Seccion>

          <Seccion titulo="Datos de facturación">
            <div className="grid grid-cols-2 gap-x-3 gap-y-1.5 max-sm:grid-cols-1">
              {campoFact('Razón social', 'razon_social')}
              {campoFact('CIF / NIF', 'cif')}
              {campoFact('IBAN', 'iban')}
              {campoFact('Dirección', 'direccion')}
              {campoFact('CP', 'cp')}
              {campoFact('Ciudad', 'ciudad')}
              {campoFact('Provincia', 'provincia')}
              {campoFact('País', 'pais')}
              {campoFact('Email facturación', 'email_facturacion')}
            </div>
          </Seccion>

          <Seccion
            titulo="Propuestas enviadas"
            accion={
              p.crear && (
                <Button variant="ghost" size="sm" icon={<Plus />} onClick={() => setPropuesta(true)}>
                  Añadir
                </Button>
              )
            }
          >
            {ficha.propuestas.length === 0 && <p className="text-[12.5px] text-muted">Sin propuestas.</p>}
            <ul className="flex flex-col">
              {ficha.propuestas.map((pr) => (
                <li key={pr.id} className="flex items-center gap-2 border-t border-line2 py-2 first:border-t-0">
                  <FileText className="size-4 shrink-0 text-label" />
                  <div className="min-w-0 flex-1">
                    <div className="truncate text-[13px] font-semibold text-ink-strong">{pr.nombre}</div>
                    <div className="text-[11.5px] text-muted">
                      {fechaCorta(pr.fecha_envio, '—')}
                      {pr.importe !== null && ` · ${importeTexto(pr.importe)}`}
                    </div>
                  </div>
                  {ed ? (
                    <Select
                      variant="mini"
                      value={pr.estado}
                      options={(cat?.estados_propuesta ?? []).map((e) => ({ value: e.value, label: e.label }))}
                      onChange={(v) => acc.editarPropuesta.mutate({ pid: pr.id, estado: v }, { onError: fallo })}
                      aria-label="Estado de la propuesta"
                    />
                  ) : (
                    <TipoTag tipo={pr.estado} label={cat?.estados_propuesta.find((e) => e.value === pr.estado)?.label ?? pr.estado} />
                  )}
                  {pr.url_archivo && (
                    <a href={pr.url_archivo} target="_blank" rel="noopener noreferrer" aria-label="Abrir la propuesta" className="flex size-7 items-center justify-center rounded-md text-label hover:bg-chip hover:text-ink">
                      <Link2 className="size-4" />
                    </a>
                  )}
                  {p.borrar && (
                    <IconButton
                      label="Eliminar propuesta"
                      tone="danger"
                      size={26}
                      icon={<X />}
                      onClick={async () => {
                        if (await confirm({ title: '¿Eliminar propuesta?', danger: true })) acc.borrarPropuesta.mutate(pr.id, { onError: fallo })
                      }}
                    />
                  )}
                </li>
              ))}
            </ul>
          </Seccion>

          <Seccion
            titulo="Archivos adjuntos"
            accion={
              p.crear && (
                <>
                  <Button variant="ghost" size="sm" icon={<Paperclip />} loading={acc.subirAdjunto.isPending} loadingText="Subiendo…" onClick={() => fichero.current?.click()}>
                    Subir
                  </Button>
                  <input
                    ref={fichero}
                    type="file"
                    hidden
                    onChange={(e) => {
                      const f = e.target.files?.[0]
                      e.target.value = ''
                      if (f) acc.subirAdjunto.mutate(f, { onSuccess: () => aviso('Archivo subido'), onError: fallo })
                    }}
                  />
                </>
              )
            }
          >
            {ficha.adjuntos.length === 0 && <p className="text-[12.5px] text-muted">Sin archivos.</p>}
            <ul className="flex flex-col">
              {ficha.adjuntos.map((a) => (
                <li key={a.id} className="flex items-center gap-2 border-t border-line2 py-2 first:border-t-0">
                  <Paperclip className="size-4 shrink-0 text-label" />
                  <a href={a.url ? url('/' + a.url) : undefined} target="_blank" rel="noopener noreferrer" className="min-w-0 flex-1 truncate text-[13px] font-semibold text-ink-strong hover:underline">
                    {a.nombre}
                  </a>
                  <span className="shrink-0 text-[11.5px] text-muted">{fechaCorta(a.fecha)}</span>
                  {p.borrar && (
                    <IconButton
                      label="Eliminar archivo"
                      tone="danger"
                      size={26}
                      icon={<Trash2 />}
                      onClick={async () => {
                        if (await confirm({ title: '¿Eliminar el archivo?', danger: true })) acc.borrarAdjunto.mutate(a.id, { onError: fallo })
                      }}
                    />
                  )}
                </li>
              ))}
            </ul>
          </Seccion>

          {ficha.negocios.length > 0 && (
            <Seccion titulo="Negocios">
              <ul className="flex flex-col">
                {ficha.negocios.map((n) => (
                  <li key={n.id}>
                    <button type="button" onClick={() => navigate(`/crm/negocio?${n.archivado ? 'archivados=1&' : ''}open=${n.id}`)} className="flex w-full items-center gap-2 rounded-lg px-1 py-1.5 text-left text-[13px] hover:bg-soft">
                      <span className="min-w-0 flex-1 truncate font-semibold text-ink-strong">{n.nombre}</span>
                      {n.valor !== null && <span className="font-semibold text-ok tabular-nums">{importeTexto(n.valor)}</span>}
                      <FaseBadge fases={cat?.fases} fase={n.fase} size="sm" />
                    </button>
                  </li>
                ))}
              </ul>
            </Seccion>
          )}

          {ficha.listas.length > 0 && (
            <Seccion titulo="Listas">
              <div className="flex flex-wrap gap-1.5">
                {ficha.listas.map((l) => (
                  <button key={l.id} type="button" onClick={() => navigate(`/crm/listas/${l.id}`)} className="rounded-md bg-chip px-2 py-[3px] text-[11.5px] font-semibold text-[#5c616b] hover:text-ink dark:text-ink">
                    {l.nombre}
                  </button>
                ))}
              </div>
            </Seccion>
          )}

          {ficha.reuniones.length > 0 && (
            <Seccion titulo="Reuniones">
              <ul className="flex flex-col gap-2">
                {ficha.reuniones.map((r) => {
                  const pasada = r.fecha !== null && r.fecha < new Date().toISOString().slice(0, 10)
                  const sinCerrar = pasada && r.estado === 'agendada'
                  const est = ESTADOS_REUNION[r.estado] ?? ESTADOS_REUNION.agendada
                  return (
                    <li key={r.id} className={`rounded-xl border px-3.5 py-2.5 ${sinCerrar ? 'border-dashed border-[#e0a000] bg-[#fffaf0] dark:bg-[#2a2210]' : 'border-line'}`}>
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="text-[13px] font-semibold text-ink-strong">
                          {fechaCorta(r.fecha, '—')}
                          {r.hora && ` · ${r.hora}`}
                        </span>
                        {r.titulo && <span className="truncate text-[12.5px] text-muted">{r.titulo}</span>}
                        <StatusPill color={est.color} label={est.label} size="sm" />
                      </div>
                      {r.notas && <p className="mt-1.5 text-[12.5px] whitespace-pre-line text-ink">{r.notas}</p>}
                      {r.docs.map((d) => (
                        <a key={d.url} href={d.url} target="_blank" rel="noopener noreferrer" className="mt-1 inline-flex items-center gap-1 text-[12.5px] font-semibold text-[#0071e3] hover:underline">
                          <ExternalLink className="size-3.5" /> {d.title || 'Notas de la reunión'}
                        </a>
                      ))}
                    </li>
                  )
                })}
              </ul>
            </Seccion>
          )}

          <Seccion titulo="Historial de actividad">
            <ActivityTimeline
              items={ficha.actividad.map((a) => ({ id: a.id, at: a.fecha, text: a.descripcion || a.tipo }))}
              formatDate={FECHA_HORA}
              empty={<p className="text-[12.5px] text-muted">Sin actividad registrada.</p>}
            />
          </Seccion>
        </div>
      </div>
      <PropuestaModal open={propuesta} onClose={() => setPropuesta(false)} onCrear={(d) => acc.crearPropuesta.mutateAsync(d)} />
    </div>
  )
}
