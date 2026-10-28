<?php

declare(strict_types=1);

namespace App\Admin;

use App\Core\Config;
use App\Core\Database;
use App\Exceptions\ValidationException;
use App\Security\Crypto;

/**
 * Lecture / écriture générique d'une ressource.
 * Sécurité SQL : les identifiants (tables, colonnes) viennent tous de Resource (donc du schéma),
 * les valeurs sont toujours liées en paramètres.
 */
final class Crud
{
    /** Au-delà, une clé étrangère se saisit par son id (au lieu d'une liste déroulante). */
    private const MAX_OPTIONS = 500;

    public function __construct(private readonly Resource $r)
    {
    }

    /**
     * @param array<string, string> $filters colonne => valeur exacte (ENUM, booléens, clés étrangères)
     * @return array{rows: list<array>, total: int, page: int, pages: int}
     */
    public function paginate(string $search, array $filters, ?string $sort, string $dir, int $page): array
    {
        $where = [];
        $params = [];
        if ($this->r->scope() !== null) {
            $where[] = '(' . $this->r->scope() . ')';
        }
        $words = array_slice(array_filter(preg_split('/\s+/', trim($search)) ?: []), 0, 5);
        foreach ($words as $i => $word) {
            $or = [];
            foreach ($this->r->searchColumns() as $j => $c) {
                $or[] = "`{$c}` LIKE :s{$i}_{$j}";
                $params["s{$i}_{$j}"] = '%' . addcslashes($word, '%_\\') . '%';
            }
            // Recherche par identifiant exact
            if (ctype_digit($word) && count($this->r->primary()) === 1) {
                $or[] = '`' . $this->r->primary()[0] . "` = :sid{$i}";
                $params["sid{$i}"] = (int) $word;
            }
            if ($or !== []) {
                $where[] = '(' . implode(' OR ', $or) . ')';
            }
        }
        foreach ($filters as $c => $value) {
            if (isset($this->r->fields[$c]) && $value !== '') {
                if ($value === '∅') {
                    $where[] = "`{$c}` IS NULL";
                } else {
                    $where[] = "`{$c}` = :f_{$c}";
                    $params["f_{$c}"] = $value;
                }
            }
        }
        $sql = ' FROM `' . $this->r->table . '`' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $total = (int) Database::fetch('SELECT COUNT(*) n' . $sql, $params)['n'];
        $perPage = (int) Config::get('admin.per_page', 30);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        $sort = $sort !== null && isset($this->r->fields[$sort]) ? $sort : $this->r->primary()[0];
        $dir = $dir === 'asc' ? 'ASC' : 'DESC';

        $rows = Database::fetchAll(
            "SELECT *{$sql} ORDER BY `{$sort}` {$dir} LIMIT :limit OFFSET :offset",
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public function find(array $key): ?array
    {
        [$where, $params] = $this->keyWhere($key);
        $scope = $this->r->scope() !== null ? ' AND (' . $this->r->scope() . ')' : '';

        return Database::fetch('SELECT * FROM `' . $this->r->table . "` WHERE {$where}{$scope}", $params);
    }

    /** @return string clé de la ligne créée */
    public function create(array $input): string
    {
        $data = $this->parse($input, null);
        $now = gmdate('Y-m-d H:i:s');
        foreach (['created_at', 'updated_at'] as $c) {
            if (isset($this->r->schema['columns'][$c]) && !isset($data[$c])) {
                $data[$c] = $now;
            }
        }
        if (isset($this->r->schema['columns']['uid']) && !isset($data['uid'])) {
            $data['uid'] = Crypto::uuid4();
        }
        if (isset($this->r->schema['columns']['published_at'], $data['status']) && $data['status'] === 'published' && empty($data['published_at'])) {
            $data['published_at'] = $now;
        }

        $columns = array_keys($data);
        $id = Database::insert(
            'INSERT INTO `' . $this->r->table . '` (' . implode(', ', array_map(static fn ($c) => "`{$c}`", $columns)) . ') VALUES ('
            . implode(', ', array_map(static fn ($i) => ":v{$i}", array_keys($columns))) . ')',
            array_combine(array_map(static fn ($i) => "v{$i}", array_keys($columns)), array_values($data)),
        );

        $primary = $this->r->primary();
        $row = $data;
        if (count($primary) === 1 && ($this->r->schema['columns'][$primary[0]]['auto'] ?? false)) {
            $row[$primary[0]] = $id;
        }

        return $this->r->keyOf($row);
    }

    /** @return array<string, array{0: mixed, 1: mixed}> changements effectifs (pour l'audit) */
    public function update(array $key, array $existing, array $input): array
    {
        $data = $this->parse($input, $existing);
        $changes = [];
        foreach ($data as $c => $value) {
            if ((string) ($existing[$c] ?? "\0null") !== (string) ($value ?? "\0null")) {
                $changes[$c] = [$existing[$c] ?? null, $value];
            }
        }
        if ($changes === []) {
            return [];
        }
        $set = array_intersect_key($data, $changes);
        if (isset($this->r->schema['columns']['updated_at'])) {
            $set['updated_at'] = gmdate('Y-m-d H:i:s');
        }
        if (isset($this->r->schema['columns']['published_at'], $changes['status']) && $set['status'] === 'published' && empty($existing['published_at'])) {
            $set['published_at'] = gmdate('Y-m-d H:i:s');
        }

        [$where, $params] = $this->keyWhere($key);
        $assign = [];
        foreach (array_keys($set) as $i => $c) {
            $assign[] = "`{$c}` = :u{$i}";
            $params["u{$i}"] = $set[$c];
        }
        Database::execute('UPDATE `' . $this->r->table . '` SET ' . implode(', ', $assign) . " WHERE {$where}", $params);

        return $changes;
    }

    public function delete(array $key): void
    {
        [$where, $params] = $this->keyWhere($key);
        Database::execute('DELETE FROM `' . $this->r->table . "` WHERE {$where} LIMIT 1", $params);
    }

    /**
     * Libellés des clés étrangères affichées : [colonne => [id => libellé]], en une requête par table liée.
     *
     * @param list<array> $rows
     */
    public function relationLabels(array $rows, array $columns): array
    {
        $labels = [];
        foreach ($columns as $c) {
            $fk = $this->r->fields[$c]['fk'] ?? null;
            $ids = array_values(array_unique(array_filter(array_column($rows, $c), static fn ($v) => $v !== null)));
            if ($fk === null || $ids === []) {
                continue;
            }
            $title = self::titleOf($fk['table']);
            $in = implode(', ', array_map(static fn ($i) => ":id{$i}", array_keys($ids)));
            foreach (Database::fetchAll(
                "SELECT `{$fk['column']}` k, `{$title}` t FROM `{$fk['table']}` WHERE `{$fk['column']}` IN ({$in})",
                array_combine(array_map(static fn ($i) => "id{$i}", array_keys($ids)), $ids),
            ) as $r) {
                $labels[$c][(string) $r['k']] = (string) $r['t'];
            }
        }

        return $labels;
    }

    /** Options d'une liste déroulante de clé étrangère, ou null si la table liée est trop grande. */
    public static function relationOptions(array $fk): ?array
    {
        $count = (int) Database::fetch("SELECT COUNT(*) n FROM `{$fk['table']}`")['n'];
        if ($count > self::MAX_OPTIONS) {
            return null;
        }
        $title = self::titleOf($fk['table']);
        $out = [];
        foreach (Database::fetchAll("SELECT `{$fk['column']}` k, `{$title}` t FROM `{$fk['table']}` ORDER BY `{$title}`") as $r) {
            $out[(string) $r['k']] = (string) $r['t'];
        }

        return $out;
    }

    /** Valeurs distinctes pour les filtres de liste (ENUM, booléens, petites tables liées). */
    public function filterOptions(): array
    {
        $out = [];
        foreach ($this->r->fields as $name => $f) {
            $options = match ($f['kind']) {
                'select'   => array_combine($f['enum'], $f['enum']),
                'bool'     => ['1' => 'Oui', '0' => 'Non'],
                'relation' => in_array($name, $this->r->listColumns(), true) ? self::relationOptions($f['fk']) : null,
                default    => null,
            };
            if ($options !== null && $options !== []) {
                if ($f['nullable'] && $f['kind'] === 'relation') {
                    $options['∅'] = '(aucun)';
                }
                $out[$name] = $options;
            }
        }

        return $out;
    }

    /**
     * Saisie du formulaire → valeurs SQL, avec validation.
     * $existing = null à la création.
     */
    public function parse(array $input, ?array $existing): array
    {
        $data = [];
        $errors = [];
        foreach ($this->r->fields as $name => $f) {
            if ($f['readonly']) {
                continue;
            }
            $raw = $input[$name] ?? null;
            if ($f['kind'] === 'bool' && !$f['nullable']) {
                $data[$name] = !empty($raw) ? 1 : 0;
                continue;
            }
            $raw = is_string($raw) ? trim(str_replace("\r\n", "\n", $raw)) : null;
            if ($raw === null || $raw === '') {
                if ($f['nullable']) {
                    $data[$name] = null;
                } elseif ($f['default'] !== null && $existing === null) {
                    // la valeur par défaut de la colonne s'applique
                } elseif (in_array($f['kind'], ['text', 'textarea', 'url', 'email', 'color'], true) && $f['default'] === null && $existing !== null && ($existing[$name] ?? null) === '') {
                    $data[$name] = '';
                } else {
                    $errors[$name] = 'Champ obligatoire.';
                }
                continue;
            }
            try {
                $data[$name] = $this->value($f, $raw);
            } catch (\InvalidArgumentException $e) {
                $errors[$name] = $e->getMessage();
            }
        }

        if (($pw = $this->r->passwordColumn()) !== null) {
            $new = (string) ($input['_password'] ?? '');
            if ($new !== '') {
                if (mb_strlen($new) < 10) {
                    $errors['_password'] = '10 caractères minimum.';
                } else {
                    $data[$pw] = password_hash($new, PASSWORD_ARGON2ID);
                }
            } elseif ($existing === null) {
                $errors['_password'] = 'Mot de passe obligatoire.';
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $data;
    }

    private function value(array $f, string $raw): mixed
    {
        $tz = new \DateTimeZone((string) Config::get('admin.timezone', 'Europe/Paris'));

        $value = match ($f['kind']) {
            'relation', 'integer' => preg_match('/^-?\d+$/', $raw) ? (int) $raw
                : ($f['kind'] === 'relation' && !in_array($f['type'], ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true) ? $raw
                : throw new \InvalidArgumentException('Nombre entier attendu.')),
            'money'    => is_numeric($n = str_replace([' ', "\u{00A0}", "\u{202F}", '€', ','], ['', '', '', '', '.'], $raw))
                ? (int) round((float) $n * 100) : throw new \InvalidArgumentException('Montant invalide (ex. 12,50).'),
            'decimal'  => is_numeric($n = str_replace(',', '.', $raw)) ? $n : throw new \InvalidArgumentException('Nombre attendu.'),
            'select'   => in_array($raw, $f['enum'], true) ? $raw : throw new \InvalidArgumentException('Valeur non autorisée.'),
            'date'     => \DateTimeImmutable::createFromFormat('!Y-m-d', $raw) ? $raw : throw new \InvalidArgumentException('Date invalide.'),
            'bool'     => in_array($raw, ['0', '1'], true) ? (int) $raw : throw new \InvalidArgumentException('Oui ou non.'),
            'datetime' => ($d = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', strlen($raw) === 16 ? $raw . ':00' : substr($raw, 0, 19), $tz))
                ? $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : throw new \InvalidArgumentException('Date et heure invalides.'),
            'time'     => preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $raw) ? (strlen($raw) === 5 ? $raw . ':00' : $raw) : throw new \InvalidArgumentException('Heure invalide (HH:MM).'),
            'json'     => self::json($raw),
            'url'      => filter_var($raw, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $raw) ? $raw : throw new \InvalidArgumentException('URL invalide (https://…).'),
            'email'    => filter_var($raw, FILTER_VALIDATE_EMAIL) ? mb_strtolower($raw) : throw new \InvalidArgumentException('Adresse email invalide.'),
            default    => $raw,
        };
        if (is_int($value) && $f['unsigned'] && $value < 0) {
            throw new \InvalidArgumentException('Valeur positive attendue.');
        }
        if (is_string($value) && $f['length'] !== null && $f['kind'] !== 'json' && mb_strlen($value) > $f['length']) {
            throw new \InvalidArgumentException("{$f['length']} caractères maximum.");
        }

        return $value;
    }

    private static function json(string $raw): string
    {
        try {
            return json_encode(json_decode($raw, true, 32, JSON_THROW_ON_ERROR), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('JSON invalide.');
        }
    }

    private function keyWhere(array $key): array
    {
        $where = [];
        $params = [];
        foreach (array_values($this->r->primary()) as $i => $c) {
            if (!array_key_exists($c, $key)) {
                throw new \InvalidArgumentException('Clé incomplète.');
            }
            $where[] = "`{$c}` = :k{$i}";
            $params["k{$i}"] = $key[$c];
        }

        return [implode(' AND ', $where), $params];
    }

    /** Colonne « titre » d'une table quelconque (configurée ou non dans le back-office). */
    private static function titleOf(string $table): string
    {
        $columns = Schema::table($table)['columns'] ?? [];
        foreach (['name', 'title', 'username', 'label', 'email', 'slug', 'badge_code', 'uid'] as $c) {
            if (isset($columns[$c])) {
                return $c;
            }
        }

        return (string) array_key_first($columns);
    }
}
