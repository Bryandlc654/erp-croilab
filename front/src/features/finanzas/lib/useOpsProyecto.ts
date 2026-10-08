import { useConfirm } from '../../../shared/ui/useConfirm'
import { useToast } from '../../../shared/ui/useToast'
import { mensajeError, useAccionProyecto } from '../api'

/* Renombrar, color, archivar y borrar un proyecto (listado y ficha). */
export function useOpsProyecto() {
  const { aviso } = useToast()
  const { confirm, prompt } = useConfirm()
  const acc = useAccionProyecto()
  const cambiar = async (id: number, datos: Record<string, unknown>, ok: string) => {
    try {
      await acc.actualizar.mutateAsync({ id, datos })
      aviso(ok)
    } catch (e) {
      aviso(mensajeError(e, 'No se ha podido cambiar.'), { tipo: 'error' })
    }
  }
  return {
    acc,
    renombrar: async (p: { id: number; nombre: string }) => {
      const n = await prompt({ title: 'Renombrar proyecto', value: p.nombre, okLabel: 'Guardar' })
      if (n && n.trim() && n.trim() !== p.nombre) await cambiar(p.id, { nombre: n.trim() }, 'Proyecto renombrado.')
    },
    color: (p: { id: number }, color: string) => cambiar(p.id, { color }, 'Color cambiado.'),
    archivar: (p: { id: number; activo: boolean }) => cambiar(p.id, { activo: !p.activo }, p.activo ? 'Proyecto archivado.' : 'Proyecto activado.'),
    borrar: async (p: { id: number; nombre: string }) => {
      const ok = await confirm({ title: `¿Borrar el proyecto «${p.nombre}»?`, message: 'Los movimientos y facturas quedan sin proyecto (no se borran).', danger: true })
      if (!ok) return false
      try {
        await acc.borrar.mutateAsync(p.id)
        aviso('Proyecto borrado.')
        return true
      } catch (e) {
        aviso(mensajeError(e, 'No se ha podido borrar.'), { tipo: 'error' })
        return false
      }
    },
  }
}
