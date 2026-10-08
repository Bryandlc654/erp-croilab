<?php
namespace Croilab\Modulos\Finanzas;

use Croilab\Http\HttpError;
use Croilab\Modulos\Clientes\Importes;
use Croilab\Seguridad\Acceso;
use PDO;

/* Contabilidad (criterio de caja): movimientos, KPIs y análisis del año.
   Ámbito «empresa» = Hub = todos los apuntes no personales; un emisor = sus
   apuntes (personales incluidos). Solo lectura: los apuntes nacen al cobrar
   facturas, subir documentos, volcar horas o desde un proyecto. */
final class ContabilidadServicio
{
    public function __construct(private readonly PDO $pdo, private readonly EmisoresServicio $emisores) {}

    public function anios(Acceso $acc): array
    {
        $acc->exigir('ver.conta');
        $a = array_map('intval', $this->pdo->query('SELECT DISTINCT YEAR(fecha) FROM accounting WHERE fecha IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
        $a[] = (int)date('Y');
        $a = array_values(array_unique(array_filter($a)));
        rsort($a);
        return $a;
    }

    public function movimientos(Acceso $acc, string $ambito, int $anio): array
    {
        $acc->exigir('ver.conta');
        [$w, $p] = $this->filtro($acc, $ambito, $anio);
        $st = $this->pdo->prepare("SELECT a.*, i.numero AS f_numero, i.cliente_nombre AS f_cliente, " . Importes::sqlBase('i') . " AS f_base,
                u.id AS u_id, u.emisor AS u_emisor, u.tipo AS u_tipo, u.proveedor AS u_proveedor, u.filename AS u_file,
                p.nombre AS p_nombre, p.color AS p_color, c.name AS c_nombre, ad.username AS ad_nombre
            FROM accounting a
            LEFT JOIN invoices i ON i.id = a.invoice_id
            LEFT JOIN invoice_uploads u ON u.id = a.upload_id
            LEFT JOIN projects p ON p.id = a.project_id
            LEFT JOIN clients c ON c.id = a.client_id
            LEFT JOIN admins ad ON ad.id = a.admin_id
            WHERE 1=1 $w ORDER BY a.fecha DESC, a.id DESC");
        $st->execute($p);
        $items = [];
        $k = ['ing' => 0, 'ing_legal' => 0, 'ing_efectivo' => 0, 'neto_ing' => 0, 'gas' => 0, 'ded' => 0, 'gas_personal' => 0, 'gas_empresa' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $imp = Dinero::c($r['importe']);
            $efectivo = $r['metodo'] === 'efectivo' || !(int)$r['legal'];
            if ($r['tipo'] === 'ingreso') {
                $k['ing'] += $imp;
                if ($efectivo) $k['ing_efectivo'] += $imp; else $k['ing_legal'] += $imp;
                $k['neto_ing'] += $r['invoice_id'] && $r['f_base'] !== null ? Dinero::c($r['f_base']) : $imp;
            } else {
                $k['gas'] += $imp;
                if ((int)$r['deducible']) $k['ded'] += $imp;
                if ((int)$r['personal']) $k['gas_personal'] += $imp; else $k['gas_empresa'] += $imp;
            }
            $origen = $r['invoice_id'] ? 'factura' : ($r['u_id'] ? 'documento' : ($r['admin_id'] && $r['categoria'] === 'Equipo' ? 'horas' : 'manual'));
            $items[] = [
                'id' => (int)$r['id'], 'fecha' => $r['fecha'], 'tipo' => (string)$r['tipo'], 'concepto' => (string)$r['concepto'],
                'categoria' => (string)$r['categoria'], 'ambito' => (string)$r['ambito'], 'ambito_nombre' => $this->nombreAmbito((string)$r['ambito']),
                'importe' => $imp, 'metodo' => (string)$r['metodo'], 'legal' => (bool)$r['legal'], 'deducible' => (bool)$r['deducible'], 'personal' => (bool)$r['personal'],
                'project' => $r['project_id'] ? ['id' => (int)$r['project_id'], 'nombre' => (string)$r['p_nombre'], 'color' => (string)($r['p_color'] ?: '#2f6df6')] : null,
                'cliente_nombre' => $r['invoice_id'] ? (string)$r['f_cliente'] : ($r['c_nombre'] !== null ? (string)$r['c_nombre'] : null),
                'proveedor' => $r['u_id'] ? (string)$r['u_proveedor'] : null,
                'persona' => $r['ad_nombre'] !== null ? (string)$r['ad_nombre'] : null,
                'origen' => $origen,
                'factura' => $r['invoice_id'] ? ['id' => (int)$r['invoice_id'], 'numero' => $r['f_numero']] : null,
                'documento' => $r['u_id'] ? ['id' => (int)$r['u_id'], 'emisor' => (string)$r['u_emisor'], 'tipo' => (string)$r['u_tipo'], 'mes' => substr((string)$r['fecha'], 0, 7), 'archivo' => $r['u_file'] ?: null] : null,
            ];
        }
        $k['neto_benef'] = $k['neto_ing'] - $k['gas'];
        $k['bruto'] = $k['ing'] - $k['gas'];
        $k['margen'] = $k['neto_ing'] ? (int)round($k['neto_benef'] / $k['neto_ing'] * 100) : 0;
        return ['ambito' => $ambito, 'anio' => $anio, 'kpis' => $k, 'items' => $items, 'ambitos' => $this->ambitos(), 'anios' => $this->anios($acc)];
    }

    public function analisis(Acceso $acc, string $ambito, int $anio): array
    {
        $acc->exigir('ver.conta');
        [$w, $p] = $this->filtro($acc, $ambito, $anio);
        $st = $this->pdo->prepare("SELECT a.fecha, a.tipo, a.importe, a.categoria, a.metodo, a.legal, a.deducible FROM accounting a WHERE 1=1 $w");
        $st->execute($p);
        $mes = array_fill(0, 12, ['ing' => 0, 'gas' => 0]);
        $cat = [];
        $k = ['ing' => 0, 'ing_legal' => 0, 'ing_efectivo' => 0, 'gas' => 0, 'ded' => 0];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $imp = Dinero::c($r['importe']);
            $m = (int)substr((string)$r['fecha'], 5, 2) - 1;
            if ($m < 0 || $m > 11) continue;
            if ($r['tipo'] === 'ingreso') {
                $k['ing'] += $imp;
                if ($r['metodo'] === 'efectivo' || !(int)$r['legal']) $k['ing_efectivo'] += $imp; else $k['ing_legal'] += $imp;
                $mes[$m]['ing'] += $imp;
            } else {
                $k['gas'] += $imp;
                if ((int)$r['deducible']) $k['ded'] += $imp;
                $mes[$m]['gas'] += $imp;
                $c = trim((string)$r['categoria']) ?: 'Sin categoría';
                $cat[$c] = ($cat[$c] ?? 0) + $imp;
            }
        }
        $k['benef'] = $k['ing'] - $k['gas'];
        $k['margen'] = $k['ing'] ? (int)round($k['benef'] / $k['ing'] * 100) : 0;
        $tri = [];
        foreach ([0, 1, 2, 3] as $t) {
            $ing = $gas = 0;
            for ($m = $t * 3; $m < $t * 3 + 3; $m++) {
                $ing += $mes[$m]['ing'];
                $gas += $mes[$m]['gas'];
            }
            $tri[] = ['ing' => $ing, 'gas' => $gas, 'ben' => $ing - $gas, 'margen' => $ing ? (int)round(($ing - $gas) / $ing * 100) : 0];
        }
        arsort($cat);
        $categorias = [];
        foreach ($cat as $c => $v) $categorias[] = ['categoria' => (string)$c, 'total' => $v];

        /* Deducible de socios: por emisor, sin filtrar por el ámbito de la vista (como el antiguo). */
        $st = $this->pdo->prepare("SELECT ambito, COALESCE(SUM(importe), 0) t FROM accounting a WHERE a.tipo = 'gasto' AND a.deducible = 1 AND a.fecha BETWEEN ? AND ? "
                                  . Validar::sqlAlcance($acc, 'a.client_id') . ' GROUP BY ambito');
        $st->execute(["$anio-01-01", "$anio-12-31"]);
        $socios = [];
        $porAmb = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $porAmb[(string)$r['ambito']] = Dinero::c($r['t']);
        foreach ($this->emisores->lista() as $kk => $n) $socios[] = ['ambito' => $kk, 'nombre' => $n, 'total' => $porAmb[$kk] ?? 0];
        foreach ($porAmb as $kk => $v) if ($kk !== 'empresa' && !$this->emisores->existe($kk)) $socios[] = ['ambito' => $kk, 'nombre' => $kk . ' (baja)', 'total' => $v];

        return [
            'ambito' => $ambito, 'anio' => $anio, 'kpis' => $k, 'por_mes' => $mes, 'trimestres' => $tri, 'categorias' => $categorias,
            'legal_vs_efectivo' => ['legal' => $k['ing_legal'], 'efectivo' => $k['ing_efectivo'], 'ratio' => $k['ing'] ? (int)round($k['ing_legal'] / $k['ing'] * 100) : 0],
            'deducible_socios' => $socios, 'deducible_empresa' => $porAmb['empresa'] ?? 0,
            'ambitos' => $this->ambitos(), 'anios' => $this->anios($acc),
        ];
    }

    /** @return array{0:string,1:array} */
    private function filtro(Acceso $acc, string $ambito, int $anio): array
    {
        if ($anio < 2000 || $anio > 2100) throw HttpError::validacion('Año no válido.', 'anio');
        $w = ' AND a.fecha BETWEEN ? AND ?' . Validar::sqlAlcance($acc, 'a.client_id');
        $p = ["$anio-01-01", "$anio-12-31"];
        if ($ambito === '' || $ambito === 'empresa') {
            $w .= ' AND a.personal = 0';
        } else {
            $w .= ' AND a.ambito = ?';
            $p[] = $ambito;
        }
        return [$w, $p];
    }

    private function ambitos(): array
    {
        $out = [['clave' => 'empresa', 'nombre' => 'Hub']];
        foreach ($this->emisores->lista() as $k => $n) $out[] = ['clave' => $k, 'nombre' => $n];
        return $out;
    }

    private function nombreAmbito(string $a): string
    {
        if ($a === 'empresa') return 'Empresa';
        return $this->emisores->existe($a) ? $this->emisores->nombre($a) : $a . ' (baja)';
    }
}
