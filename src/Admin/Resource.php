<?php

declare(strict_types=1);

namespace App\Admin;

use App\Core\Config;

/**
 * Une ressource du back-office = une table + sa configuration (config/admin.php).
 * Les champs du formulaire sont déduits du schéma : type, NULL, ENUM, clé étrangère.
 */
final class Resource
{
    /** @var array<string, array> champs éditables ou affichés, dans l'ordre des colonnes */
    public array $fields = [];

    private function __construct(
        public readonly string $table,
        public readonly string $label,
        public readonly string $group,
        public readonly array $config,
        public readonly array $schema,
    ) {
        $hidden = (array) Config::get('admin.hidden_columns', []);
        $auto = (array) Config::get('admin.auto_columns', []);
        foreach ($schema['columns'] as $name => $col) {
            if (in_array($name, $hidden, true)) {
                continue;
            }
            $this->fields[$name] = $col + [
                'name'     => $name,
                'kind'     => self::kind($name, $col, $schema['foreign'][$name] ?? null),
                'fk'       => $schema['foreign'][$name] ?? null,
                'readonly' => $col['auto'] || $name === 'uid' || in_array($name, $auto, true)
                              || in_array($name, (array) ($config['readonly_columns'] ?? []), true),
            ];
        }
    }

    /** Toutes les ressources visibles pour un rôle, par groupe. @return array<string, array<string, self>> */
    public static function menu(string $role): array
    {
        $menu = [];
        foreach ((array) Config::get('admin.groups', []) as $group => $resources) {
            foreach ($resources as $table => $config) {
                $resource = self::get($table);
                if ($resource !== null && $resource->allows($role)) {
                    $menu[$group][$table] = $resource;
                }
            }
        }

        return $menu;
    }

    public static function get(string $table): ?self
    {
        foreach ((array) Config::get('admin.groups', []) as $group => $resources) {
            if (isset($resources[$table])) {
                $schema = Schema::table($table);
                return $schema === null ? null : new self($table, $resources[$table]['label'] ?? $table, $group, $resources[$table], $schema);
            }
        }

        return null;
    }

    public function allows(string $role): bool
    {
        return $role === 'admin' || in_array($role, (array) ($this->config['roles'] ?? []), true);
    }

    public function readonly(): bool
    {
        return (bool) ($this->config['readonly'] ?? false);
    }

    public function canCreate(): bool
    {
        return !$this->readonly() && ($this->config['can_create'] ?? true);
    }

    /** @return list<string> */
    public function primary(): array
    {
        return $this->schema['primary'] ?: [array_key_first($this->schema['columns'])];
    }

    /** Colonnes du tableau de liste. */
    public function listColumns(): array
    {
        $columns = $this->config['list'] ?? array_slice(array_keys($this->fields), 0, 7);

        return array_values(array_filter($columns, fn ($c) => isset($this->fields[$c])));
    }

    public function searchColumns(): array
    {
        $columns = $this->config['search']
            ?? array_values(array_intersect(['name', 'title', 'label', 'slug', 'email', 'username'], array_keys($this->fields)));

        return array_values(array_filter($columns, fn ($c) => isset($this->fields[$c])));
    }

    /** Colonne qui « nomme » une ligne (titre de la page, libellé des listes déroulantes). */
    public function titleColumn(): string
    {
        foreach (['name', 'title', 'first_name', 'username', 'label', 'email', 'slug', 'uid'] as $c) {
            if (isset($this->schema['columns'][$c])) {
                return $c;
            }
        }

        return $this->primary()[0];
    }

    /** Filtre SQL fixe de la configuration (ex. messages des conversations signalées uniquement). */
    public function scope(): ?string
    {
        return $this->config['where'] ?? null;
    }

    public function passwordColumn(): ?string
    {
        $c = $this->config['password'] ?? null;

        return $c !== null && isset($this->schema['columns'][$c]) ? $c : null;
    }

    /** Clé d'URL d'une ligne : l'id seul, ou les colonnes de la clé composée encodées. */
    public function keyOf(array $row): string
    {
        $values = array_map(static fn ($c) => (string) $row[$c], $this->primary());

        return count($values) === 1 ? $values[0] : rtrim(strtr(base64_encode(json_encode($values)), '+/', '-_'), '=');
    }

    /** @return array<string, string>|null colonne => valeur */
    public function parseKey(string $key): ?array
    {
        $primary = $this->primary();
        if (count($primary) === 1) {
            return $key === '' ? null : [$primary[0] => $key];
        }
        $values = json_decode((string) base64_decode(strtr($key, '-_', '+/'), true), true);
        if (!is_array($values) || count($values) !== count($primary) || array_filter($values, static fn ($v) => !is_string($v)) !== []) {
            return null;
        }

        return array_combine($primary, $values);
    }

    private static function kind(string $name, array $col, ?array $fk): string
    {
        return match (true) {
            $fk !== null                                         => 'relation',
            $col['bool']                                         => 'bool',
            $col['enum'] !== null && $col['type'] === 'enum'      => 'select',
            $col['type'] === 'json'                              => 'json',
            str_ends_with($name, '_cents')                       => 'money',
            in_array($col['type'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true) => 'integer',
            in_array($col['type'], ['decimal', 'float', 'double'], true) => 'decimal',
            $col['type'] === 'date'                              => 'date',
            in_array($col['type'], ['datetime', 'timestamp'], true) => 'datetime',
            $col['type'] === 'time'                              => 'time',
            in_array($col['type'], ['text', 'mediumtext', 'longtext', 'tinytext'], true) => 'textarea',
            str_ends_with($name, '_url') || $name === 'url'      => 'url',
            str_contains($name, 'email')                         => 'email',
            str_contains($name, 'color')                         => 'color',
            default                                              => 'text',
        };
    }
}
