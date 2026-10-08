<?php
namespace Croilab\Modulos\Finanzas;

use PDO;

/* Lectura y escritura de la tabla settings para Finanzas (emisores, series
   recordadas, interruptores del cron). Con caché por instancia: una petición
   lee muchas veces las mismas claves. */
final class Ajustes
{
    private ?array $todo = null;

    public function __construct(private readonly PDO $pdo) {}

    public function get(string $clave, string $def = ''): string
    {
        $t = $this->todo();
        return array_key_exists($clave, $t) ? $t[$clave] : $def;
    }

    public function set(string $clave, string $valor): void
    {
        $this->pdo->prepare('INSERT INTO settings (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$clave, $valor]);
        if ($this->todo !== null) $this->todo[$clave] = $valor;
    }

    public function olvidar(): void
    {
        $this->todo = null;
    }

    /** @return array<string,string> */
    private function todo(): array
    {
        if ($this->todo !== null) return $this->todo;
        $this->todo = [];
        /* Solo las claves que usa Finanzas: settings guarda también cosas grandes de otros módulos. */
        $st = $this->pdo->query("SELECT clave, valor FROM settings WHERE clave LIKE 'emisor%' OR clave LIKE 'serie\\_%' OR clave LIKE 'auto\\_%' OR clave LIKE 'fin\\_%'");
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $v]) $this->todo[(string)$k] = (string)$v;
        return $this->todo;
    }
}
