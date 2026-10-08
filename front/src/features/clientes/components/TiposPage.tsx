import { Flag, Plus } from 'lucide-react'
import Button from '../../../shared/ui/Button'
import Card from '../../../shared/ui/Card'
import EmptyState from '../../../shared/ui/EmptyState'
import Notice from '../../../shared/ui/Notice'
import PageHeader from '../../../shared/ui/PageHeader'
import { useConfirm } from '../../../shared/ui/useConfirm'
import { mensajeError, useBorrarTipo, useTipos } from '../api'
import { SECCIONES_TIPO } from '../lib/etiquetas'
import type { Tipo } from '../schemas'
import Etiqueta from './Etiqueta'
import { usePermisosClientes } from './permisos'

/* Tipos de cliente (types.php): cada tipo decide qué secciones ve el cliente
   en su portal. */
export default function TiposPage() {
  const { data: tipos, isLoading, error } = useTipos()
  const p = usePermisosClientes()
  const { confirm } = useConfirm()
  const borrar = useBorrarTipo()

  async function eliminar(t: Tipo) {
    const ok = await confirm({
      title: 'Eliminar tipo',
      message: `¿Eliminar el tipo ${t.nombre}? ${t.uso === 1 ? 'El cliente con este tipo se quedará sin tipo.' : `Los ${t.uso} clientes con este tipo se quedarán sin tipo.`}`,
      okLabel: 'Eliminar',
      danger: true,
    })
    if (ok) borrar.mutate(t.id)
  }

  return (
    <div className="max-w-[1180px]">
      <PageHeader
        title="Tipos de cliente"
        lead="Cada tipo decide qué secciones ve el cliente en su portal. Inicio se ve siempre."
        actions={
          p.tipos && (
            <Button to="/clientes/tipos/nuevo" icon={<Plus />}>
              Nuevo tipo
            </Button>
          )
        }
      />
      {error && <Notice tone="error">{mensajeError(error, 'No se han podido cargar los tipos.')}</Notice>}
      {isLoading && <p className="py-[60px] text-center text-[13.5px] text-muted">Cargando…</p>}
      {tipos && tipos.length === 0 && (
        <EmptyState
          icon={<Flag />}
          title="Todavía no hay ningún tipo"
          text="Un tipo agrupa a los clientes que ven lo mismo en su portal. Por ejemplo «SEO completo», «Solo web» o «Mantenimiento»."
          actions={
            p.tipos && (
              <Button to="/clientes/tipos/nuevo" icon={<Plus />}>
                Crear el primer tipo
              </Button>
            )
          }
        />
      )}
      {tipos && tipos.length > 0 && (
        <Card padding="none">
          {tipos.map((t) => (
            <div key={t.id} className="flex flex-wrap items-center gap-x-6 gap-y-3 border-b border-line2 px-[22px] py-[18px] last:border-b-0 max-sm:px-4">
              <div className="w-[190px] min-w-0 max-md:w-full">
                <b className="block truncate text-[15px] font-semibold text-ink-strong">{t.nombre}</b>
                <span className="text-[12.5px] text-muted">{t.uso === 0 ? 'Sin clientes asignados' : `${t.uso} cliente${t.uso === 1 ? '' : 's'}`}</span>
              </div>
              <div className="flex min-w-0 flex-1 flex-wrap gap-1.5">
                <Etiqueta tono="on">Inicio</Etiqueta>
                {SECCIONES_TIPO.map((s) => (
                  <Etiqueta key={s.key} tono={t.secciones[s.key] ? 'on' : 'off'}>
                    {s.titulo}
                  </Etiqueta>
                ))}
              </div>
              {p.tipos && (
                <div className="flex gap-2">
                  <Button variant="ghost" size="sm" to={`/clientes/tipos/${t.id}`}>
                    Editar
                  </Button>
                  <Button variant="danger" size="sm" onClick={() => void eliminar(t)}>
                    Eliminar
                  </Button>
                </div>
              )}
            </div>
          ))}
        </Card>
      )}
    </div>
  )
}
