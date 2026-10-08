import { useAuth } from '../../auth/useAuth'

/* Qué puede hacer la persona en Finanzas. Solo para ocultar botones: la API lo
   vuelve a comprobar. Escribir exige siempre además `general.editar`. */
export function usePermisosFin() {
  const { can, me } = useAuth()
  const escribe = can('general.editar')
  return {
    yo: me?.id ?? 0,
    ver: can('ver.finanzas'),
    conta: can('ver.conta'),
    horas: can('ver.horas'),
    proyectos: can('ver.proyectos'),
    emitir: escribe && can('finanzas.emitir'),
    cobrar: escribe && can('finanzas.cobrar'),
    borrar: escribe && can('finanzas.borrar'),
    programar: escribe && can('finanzas.programar'),
    contaEditar: escribe && can('conta.editar'),
    emisores: escribe && can('finanzas.emisores'),
    verEmisores: can('finanzas.emisores'),
    imputar: escribe && can('tareas.horas'),
    escribe,
  }
}
export type PermisosFin = ReturnType<typeof usePermisosFin>
