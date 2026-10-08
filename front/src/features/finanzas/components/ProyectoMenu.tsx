import { Archive, ArchiveRestore, Eye, Palette, Pencil, Trash2 } from 'lucide-react'
import { MenuItem, MenuLabel, MenuSeparator } from '../../../shared/ui/Menu'
import { PALETA_PROYECTOS } from '../lib/estados'

/* Opciones de un proyecto (menú ⋯ y clic derecho): ver, renombrar, color,
   archivar/activar y borrar. */
export default function ProyectoMenuItems({
  activo,
  onVer,
  onRenombrar,
  onColor,
  onArchivar,
  onBorrar,
  puede,
}: {
  activo: boolean
  onVer?: () => void
  onRenombrar: () => void
  onColor: (c: string) => void
  onArchivar: () => void
  onBorrar: () => void
  puede: boolean
}) {
  return (
    <>
      {onVer && (
        <MenuItem icon={<Eye />} onClick={onVer}>
          Ver proyecto
        </MenuItem>
      )}
      {puede && (
        <>
          <MenuItem icon={<Pencil />} onClick={onRenombrar}>
            Renombrar
          </MenuItem>
          <MenuSeparator />
          <MenuLabel>
            <span className="inline-flex items-center gap-1.5">
              <Palette className="size-3" /> Color
            </span>
          </MenuLabel>
          <div className="flex flex-wrap gap-1.5 px-2.5 pb-2">
            {PALETA_PROYECTOS.map((c) => (
              <button key={c} type="button" aria-label={`Color ${c}`} onClick={() => onColor(c)} className="size-5 rounded-md ring-offset-2 hover:ring-2 hover:ring-line-strong" style={{ backgroundColor: c }} />
            ))}
          </div>
          <MenuSeparator />
          <MenuItem icon={activo ? <Archive /> : <ArchiveRestore />} onClick={onArchivar}>
            {activo ? 'Archivar' : 'Activar'}
          </MenuItem>
          <MenuItem icon={<Trash2 />} danger onClick={onBorrar}>
            Borrar
          </MenuItem>
        </>
      )}
    </>
  )
}
