<?php
namespace Croilab\Modulos\Clientes;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\Reautenticacion;
use Croilab\Seguridad\Acceso;
use PDO;

/* «Datos avanzados» de un cliente (lo que data.php dejaba tocar en bruto):
   los bloques JSON que no tienen pantalla propia (métricas, informes,
   servicios contratados) y algún campo suelto. Exige `datos.avanzado` y volver
   a poner la contraseña (zona «datos», 30 min).
   A diferencia de data.php, valida que cada JSON se lea y tenga la forma que
   espera el portal, y no borra nada: borrar es la papelera. La contraseña se
   confirma con POST /v1/auth/reconfirmar {zona:"datos"} (Reautenticacion). */
class AvanzadoServicio
{
    public const ZONA = 'datos';
    /* columna => tipo de raíz JSON que tiene que tener ('objeto' | 'lista'). */
    public const BLOQUES = [
        'estado_json' => 'objeto', 'plan_json' => 'objeto', 'accesos_json' => 'lista', 'tareas_json' => 'objeto',
        'met_json' => 'objeto', 'informes_json' => 'lista', 'servicios_json' => 'lista',
    ];

    public function __construct(private readonly PDO $pdo, private readonly ClientesServicio $clientes) {}

    /* «Bloquear otra vez»: cierra la zona común de reautenticación. */
    public function bloquear(): void
    {
        Reautenticacion::cerrar(self::ZONA);
    }

    public function ver(Acceso $acc, int $id): array
    {
        $this->exigir($acc);
        $c = $this->clientes->visible($acc, $id);
        $out = ['id' => (int)$c['id'], 'name' => (string)$c['name']];
        foreach (array_keys(self::BLOQUES) as $col) $out[$col] = self::bonito($c[$col]);
        $out['looker_url'] = (string)$c['looker_url'];
        $out['fact_tel'] = (string)$c['fact_tel'];
        $out['orden'] = (int)$c['orden'];
        return $out;
    }

    public function guardar(Acceso $acc, int $id, array $d): void
    {
        $this->exigir($acc);
        $acc->exigir('general.editar');
        $this->clientes->visible($acc, $id);
        $campos = [];
        foreach (self::BLOQUES as $col => $raiz) {
            if (array_key_exists($col, $d)) $campos[$col] = self::json($d[$col], $col, $raiz);
        }
        if (array_key_exists('looker_url', $d)) {
            $u = ContenidoPortal::url($d['looker_url'], 'looker_url', 'El panel de Looker tiene que ser una URL https://…', 2000);
            $campos['looker_url'] = $u === '' ? null : $u;
        }
        if (array_key_exists('fact_tel', $d)) $campos['fact_tel'] = ContenidoPortal::texto($d['fact_tel'], 40, 'fact_tel');
        if (array_key_exists('orden', $d)) {
            $o = filter_var($d['orden'], FILTER_VALIDATE_INT);
            if ($o === false) throw HttpError::validacion('El orden es un número.', 'orden');
            $campos['orden'] = $o;
        }
        if (!$campos) throw HttpError::validacion('No hay nada que cambiar.');
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($campos)));
        $this->pdo->prepare("UPDATE clients SET $set WHERE id = ?")->execute([...array_values($campos), $id]);
        if (function_exists('audit_log')) audit_log('cliente.avanzado', "#$id " . implode(',', array_keys($campos)));
    }

    private function exigir(Acceso $acc): void
    {
        $acc->exigir('ver.clientes', 'datos.avanzado');
        /* La misma confirmación de contraseña que el resto del ERP (Equipo): 403 {error:"reauth", zona:"datos"}. */
        Reautenticacion::exigir(self::ZONA);
    }

    /** Texto JSON del editor → JSON compacto a guardar. Vacío = NULL (servicios_json NULL = ve todos). */
    public static function json(mixed $v, string $col, string $raiz): ?string
    {
        if ($v !== null && !is_string($v)) throw HttpError::validacion('Tiene que ser texto JSON.', $col);
        $t = trim((string)$v);
        if ($t === '') return null;
        if (strlen($t) > 4 * 1024 * 1024) throw HttpError::validacion('Es demasiado grande.', $col);
        $d = json_decode($t, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw HttpError::validacion('No es un JSON válido: ' . json_last_error_msg() . '.', $col);
        $esLista = is_array($d) && array_is_list($d);
        if ($raiz === 'lista' && !$esLista) throw HttpError::validacion('Tiene que ser una lista JSON: [ … ].', $col);
        if ($raiz === 'objeto' && (!is_array($d) || ($d !== [] && $esLista))) throw HttpError::validacion('Tiene que ser un objeto JSON: { … }.', $col);
        /* Los bloques con pantalla propia pasan por el mismo validador que la ficha. */
        return match ($col) {
            'estado_json' => ContenidoPortal::estado($d),
            'plan_json' => ContenidoPortal::plan($d),
            'accesos_json' => ContenidoPortal::accesos($d),
            'tareas_json' => ContenidoPortal::progreso(ContenidoPortal::leerProgreso(json_encode($d))),
            default => json_encode($d === [] && $raiz === 'objeto' ? new \stdClass() : $d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }

    private static function bonito(?string $json): string
    {
        $t = trim((string)$json);
        if ($t === '') return '';
        $d = json_decode($t);
        return $d === null && json_last_error() !== JSON_ERROR_NONE ? $t : json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
