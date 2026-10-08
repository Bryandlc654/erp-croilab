import type { Tarea } from '../features/tareas/schemas'

/* Tarea válida según el contrato; cada prueba cambia solo lo que le importa. */
export function tarea(cambios: Partial<Tarea> = {}): Tarea {
  return {
    id: 1,
    client_id: 10,
    list_id: 100,
    titulo: 'Revisar la web',
    estado: 'pendiente',
    prioridad: 0,
    responsable_id: null,
    due_date: null,
    fecha_inicio: null,
    visible_cliente: false,
    mes: '',
    etiquetas: '',
    orden: 0,
    list_tipo: 'tareas',
    client_name: 'Aeternum',
    client_iniciales: 'AE',
    list_name: 'Tareas',
    asignados: [],
    ...cambios,
  }
}
