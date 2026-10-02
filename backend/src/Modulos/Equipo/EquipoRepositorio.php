<?php
namespace Croilab\Modulos\Equipo;

use PDO;

/* Personas del equipo con su foto de perfil (admin_profiles.foto). */
class EquipoRepositorio
{
    private ?array $fotos = null;
    private ?array $nombres = null;

    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array{id:int, username:string, foto:?string}> */
    public function activos(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT id, username FROM admins WHERE activo = 1 ORDER BY username') as $a) {
            $out[] = $this->persona((int)$a['id'], (string)$a['username']);
        }
        return $out;
    }

    /** Persona por id, aunque esté desactivada (sigue saliendo en sus tareas). */
    public function persona(int $id, ?string $username = null): array
    {
        $username ??= $this->nombres()[$id] ?? '';
        return ['id' => $id, 'username' => $username, 'foto' => $this->foto($id)];
    }

    public function existe(int $id): bool
    {
        return isset($this->nombres()[$id]);
    }

    /** Ruta relativa al backend; el front le antepone la URL de la API. */
    public function foto(int $adminId): ?string
    {
        if ($this->fotos === null) {
            $this->fotos = [];
            foreach ($this->pdo->query("SELECT admin_id, foto FROM admin_profiles WHERE foto IS NOT NULL AND foto <> ''") as $r) {
                $this->fotos[(int)$r['admin_id']] = 'archivo.php?d=avatars&f=' . rawurlencode(basename((string)$r['foto']));
            }
        }
        return $this->fotos[$adminId] ?? null;
    }

    /** @return array<int,string> id => username de todo el equipo */
    public function nombres(): array
    {
        if ($this->nombres === null) {
            $this->nombres = [];
            foreach ($this->pdo->query('SELECT id, username FROM admins') as $a) $this->nombres[(int)$a['id']] = (string)$a['username'];
        }
        return $this->nombres;
    }
}
