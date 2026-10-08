<?php
namespace Croilab\Modulos\Crm;

use Croilab\Http\HttpError;
use Croilab\Modulos\Equipo\EquipoRepositorio;
use Croilab\Seguridad\Acceso;
use PDO;

/* Importar contactos desde CSV (crm_import.php), en dos pasos: primero se
   valida en el servidor sin escribir nada (columnas reconocidas, errores por
   fila, duplicados) y luego se importa con el mapeo elegido, todo o nada.

   Lo que corrige del antiguo: tamaño y filas máximas, valores «a la española»
   como en el resto del CRM (antes «12.5» se leía 125), aviso de duplicados por
   email o teléfono, transacción, y fases o propietarios desconocidos que
   avisan en vez de descartarse en silencio. */
final class ImportarServicio
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_FILAS = 5000;
    public const CAMPOS = ['nombre', 'empresa', 'sector', 'email', 'telefono', 'whatsapp', 'origen_lead', 'servicios', 'valor', 'fase', 'propietario'];
    private const ALIAS = [
        'nombre' => ['nombre', 'nombre completo', 'contacto', 'name'],
        'empresa' => ['empresa', 'company', 'negocio'],
        'sector' => ['sector', 'industria'],
        'email' => ['email', 'correo', 'e-mail', 'mail'],
        'telefono' => ['telefono', 'teléfono', 'tel', 'phone', 'móvil', 'movil'],
        'whatsapp' => ['whatsapp', 'wsp', 'wa'],
        'origen_lead' => ['origen', 'origen lead', 'origen_lead', 'fuente', 'source'],
        'servicios' => ['servicios', 'servicio', 'services'],
        'valor' => ['valor', 'importe', 'value', 'presupuesto'],
        'fase' => ['fase', 'embudo', 'estado', 'etapa', 'stage'],
        'propietario' => ['propietario', 'owner', 'responsable', 'asignado'],
    ];
    private const MAX_LISTA = 200;

    public function __construct(
        private readonly PDO $pdo,
        private readonly EquipoRepositorio $equipo,
        private readonly Historial $historial
    ) {}

    public function plantilla(Acceso $acc): array
    {
        $acc->exigir('ver.crm');
        return ['nombre' => 'plantilla-contactos.csv', 'csv' => Csv::generar(
            ['Nombre', 'Empresa', 'Sector', 'Email', 'Telefono', 'WhatsApp', 'Origen', 'Servicios', 'Valor', 'Fase', 'Propietario'],
            [['Ana García', 'Ejemplo SL', 'Restauración', 'ana@ejemplo.com', '600000000', '600000000', 'Referido', 'Web;SEO', '2500', 'lead_nuevo', '']]
        )];
    }

    /** $_FILES['csv'] → texto, con las comprobaciones de tamaño. */
    public function leerSubida(mixed $f): string
    {
        if (!is_array($f) || !isset($f['tmp_name'], $f['error'], $f['name']) || is_array($f['tmp_name'])) throw HttpError::validacion('Falta el archivo CSV.', 'csv');
        if (in_array((int)$f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) throw HttpError::validacion('El CSV pesa demasiado (máximo 2 MB).', 'csv');
        if ((int)$f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw HttpError::validacion('No ha llegado el archivo. Inténtalo otra vez.', 'csv');
        if (!in_array(strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)), ['csv', 'txt'], true)) throw HttpError::validacion('Tiene que ser un archivo .csv.', 'csv');
        if ((int)@filesize((string)$f['tmp_name']) > self::MAX_BYTES) throw HttpError::validacion('El CSV pesa demasiado (máximo 2 MB).', 'csv');
        return (string)file_get_contents((string)$f['tmp_name']);
    }

    /**
     * Valida (siempre) e importa (si no es prueba).
     * @param array<int,string>|null $mapeo campo por columna ('' = no importar); null = por la cabecera
     */
    public function importar(Acceso $acc, string $contenido, ?array $mapeo, bool $prueba, bool $omitirDuplicados): array
    {
        $acc->exigir('ver.crm', 'general.editar', 'crm.crear');
        if (strlen($contenido) > self::MAX_BYTES) throw HttpError::validacion('El CSV pesa demasiado (máximo 2 MB).', 'csv');
        if (trim($contenido) === '') throw HttpError::validacion('El CSV está vacío.', 'csv');
        $csv = Csv::leer($contenido, self::MAX_FILAS);
        if (count($csv['filas']) > self::MAX_FILAS) throw HttpError::validacion('Como mucho ' . self::MAX_FILAS . ' filas por archivo. Divide el CSV en varios.', 'csv');
        $cab = $csv['cabecera'];
        $map = $mapeo === null ? $this->autodetectar($cab) : $this->validarMapeo($mapeo, count($cab));
        if (!in_array('nombre', $map, true)) throw HttpError::validacion('El CSV debe tener al menos una columna «Nombre».', 'mapeo');

        $fases = Catalogos::fases($this->pdo);
        $porNombreFase = [];
        foreach ($fases as $slug => $f) $porNombreFase[mb_strtolower($f['nombre'])] = $slug;
        $usuarios = array_change_key_case(array_flip(array_map('mb_strtolower', $this->equipo->nombres())), CASE_LOWER);
        $faseDef = isset($fases['lead_nuevo']) ? 'lead_nuevo' : (Catalogos::slugsDeTipo($fases, 'abierta')[0] ?? 'lead_nuevo');

        $validas = $omitidas = $duplicados = $avisos = [];
        $vistosEmail = $vistosTel = [];
        $existe = $this->pdo->prepare('SELECT 1 FROM contacts WHERE (? <> \'\' AND email = ?) OR (? <> \'\' AND telefono = ?) LIMIT 1');
        foreach ($csv['filas'] as $i => $fila) {
            $n = $i + 2;   // la 1 es la cabecera
            $v = [];
            foreach ($map as $col => $campo) if ($campo !== '' && isset($fila[$col])) $v[$campo] = trim($fila[$col]);
            $nombre = mb_substr($v['nombre'] ?? '', 0, 200);
            if ($nombre === '') {
                $omitidas[] = ['fila' => $n, 'motivo' => 'Sin nombre'];
                continue;
            }
            $c = ['nombre' => $nombre];
            foreach (['empresa' => 200, 'sector' => 80, 'telefono' => 60, 'whatsapp' => 60, 'origen_lead' => 40] as $k => $max) {
                $x = $v[$k] ?? '';
                if (mb_strlen($x) > $max) $avisos[] = ['fila' => $n, 'msg' => "«$k» recortado a $max caracteres"];
                $c[$k] = $x === '' ? null : mb_substr($x, 0, $max);
            }
            $email = $v['email'] ?? '';
            if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160)) {
                $avisos[] = ['fila' => $n, 'msg' => "Email no válido («" . mb_substr($email, 0, 60) . "»): se deja vacío"];
                $email = '';
            }
            $c['email'] = $email === '' ? null : $email;
            $valor = $v['valor'] ?? '';
            $c['valor'] = $valor === '' ? null : Dinero::intentar($valor);
            if ($valor !== '' && $c['valor'] === null) $avisos[] = ['fila' => $n, 'msg' => "Valor no válido («" . mb_substr($valor, 0, 30) . "»): se deja vacío"];
            $svc = array_values(array_unique(array_filter(array_map(fn($s) => mb_substr(trim($s), 0, 40), preg_split('/[;,|]/', $v['servicios'] ?? '') ?: []))));
            $json = $svc ? json_encode($svc, JSON_UNESCAPED_UNICODE) : null;
            $c['servicio_json'] = $json !== null && strlen($json) <= 255 ? $json : null;
            $fase = $v['fase'] ?? '';
            $slug = $fase === '' ? $faseDef : (isset($fases[$fase]) ? $fase : ($porNombreFase[mb_strtolower($fase)] ?? null));
            if ($slug === null) {
                $avisos[] = ['fila' => $n, 'msg' => "Fase «" . mb_substr($fase, 0, 40) . "» desconocida: queda en «" . ($fases[$faseDef]['nombre'] ?? 'Lead nuevo') . '»'];
                $slug = $faseDef;
            }
            $c['fase'] = $slug;
            $prop = mb_strtolower($v['propietario'] ?? '');
            $c['propietario_id'] = null;
            if ($prop !== '') {
                if (!isset($usuarios[$prop])) $avisos[] = ['fila' => $n, 'msg' => "Propietario «" . mb_substr($prop, 0, 40) . "» desconocido: queda sin propietario"];
                elseif (!$acc->veTodo() && (int)$usuarios[$prop] !== $acc->adminId) $avisos[] = ['fila' => $n, 'msg' => 'Solo puedes importar contactos tuyos o sin propietario: queda sin propietario'];
                else $c['propietario_id'] = (int)$usuarios[$prop];
            }

            $tel = (string)($c['telefono'] ?? '');
            $em = mb_strtolower((string)($c['email'] ?? ''));
            $dup = null;
            if (($em !== '' && isset($vistosEmail[$em])) || ($tel !== '' && isset($vistosTel[$tel]))) $dup = 'Repetido dentro del archivo';
            else {
                $existe->execute([$em, $em, $tel, $tel]);
                if ($existe->fetchColumn()) $dup = 'Ya hay un contacto con ese email o teléfono';
            }
            if ($em !== '') $vistosEmail[$em] = true;
            if ($tel !== '') $vistosTel[$tel] = true;
            if ($dup !== null) $duplicados[] = ['fila' => $n, 'nombre' => $nombre, 'motivo' => $dup];
            $validas[] = ['fila' => $n, 'campos' => $c, 'duplicado' => $dup !== null];
        }

        $aImportar = array_values(array_filter($validas, fn($r) => !($omitirDuplicados && $r['duplicado'])));
        $res = [
            'cabecera' => $cab, 'mapeo' => $map, 'separador' => $csv['separador'],
            'total_filas' => count($csv['filas']), 'validas' => count($validas), 'a_importar' => count($aImportar),
            'omitidas' => array_slice($omitidas, 0, self::MAX_LISTA), 'n_omitidas' => count($omitidas),
            'duplicados' => array_slice($duplicados, 0, self::MAX_LISTA), 'n_duplicados' => count($duplicados),
            'avisos' => array_slice($avisos, 0, self::MAX_LISTA), 'n_avisos' => count($avisos),
            'muestra' => array_map(fn($r) => [
                'fila' => $r['fila'], 'nombre' => $r['campos']['nombre'], 'empresa' => (string)$r['campos']['empresa'], 'email' => (string)$r['campos']['email'],
                'telefono' => (string)$r['campos']['telefono'], 'fase' => $fases[$r['campos']['fase']]['nombre'] ?? $r['campos']['fase'],
                'valor' => Dinero::num($r['campos']['valor']), 'servicios' => implode(', ', json_decode((string)$r['campos']['servicio_json'], true) ?: []),
                'duplicado' => $r['duplicado'],
            ], array_slice($validas, 0, 8)),
            'insertados' => 0,
        ];
        if ($prueba) return $res;
        if (!$aImportar) throw HttpError::validacion('No hay ninguna fila que importar.', 'csv');

        db_tx_begin($this->pdo);
        try {
            $ins = $this->pdo->prepare('INSERT INTO contacts (nombre, empresa, sector, email, telefono, whatsapp, origen_lead, servicio_json, valor, fase, propietario_id)
                                        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($aImportar as $r) {
                $c = $r['campos'];
                $ins->execute([$c['nombre'], $c['empresa'], $c['sector'], $c['email'], $c['telefono'], $c['whatsapp'], $c['origen_lead'], $c['servicio_json'], $c['valor'], $c['fase'], $c['propietario_id']]);
                $this->historial->anotar((int)$this->pdo->lastInsertId(), null, 'creado', 'Importado desde CSV');
            }
        } catch (\Throwable $e) {
            db_tx_rollback($this->pdo);
            error_log('CRM importar CSV: ' . $e->getMessage());
            throw new HttpError(500, 'No se ha podido importar el CSV. No se ha creado ningún contacto.', 'importar');
        }
        db_tx_commit($this->pdo);
        $res['insertados'] = count($aImportar);
        return $res;
    }

    /** Campo de cada columna según su cabecera (sin repetir campo). */
    public function autodetectar(array $cab): array
    {
        $out = [];
        $usados = [];
        foreach ($cab as $i => $h) {
            $k = mb_strtolower(trim((string)$h));
            $campo = '';
            foreach (self::ALIAS as $c => $alias) {
                if (in_array($k, $alias, true) && !isset($usados[$c])) {
                    $campo = $c;
                    $usados[$c] = true;
                    break;
                }
            }
            $out[$i] = $campo;
        }
        return $out;
    }

    private function validarMapeo(array $m, int $columnas): array
    {
        $out = array_fill(0, $columnas, '');
        $usados = [];
        foreach ($m as $i => $campo) {
            if (!is_int($i) && !(is_string($i) && ctype_digit($i))) throw HttpError::validacion('Mapeo no válido.', 'mapeo');
            $i = (int)$i;
            if ($i < 0 || $i >= $columnas) continue;
            if ($campo === '' || $campo === null) continue;
            if (!is_string($campo) || !in_array($campo, self::CAMPOS, true)) throw HttpError::validacion('Campo desconocido en el mapeo.', 'mapeo');
            if (isset($usados[$campo])) throw HttpError::validacion("El campo «$campo» está en dos columnas.", 'mapeo');
            $usados[$campo] = true;
            $out[$i] = $campo;
        }
        return $out;
    }
}
