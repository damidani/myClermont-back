<?php

declare(strict_types=1);

namespace App\Content\Import;

use App\Core\Config;

/**
 * Annonces d'agences immobilières au format Poliris (« annonces.csv », souvent dans un ZIP avec les photos) → cible housing.
 *
 * C'est le format d'export standard des logiciels d'agence (Hektor, Apimo, AC3, Netty, Adapt-Immo…) vers les portails :
 * l'agence ajoute simplement myClermont comme « passerelle » et le fichier est déposé / mis à disposition chaque nuit.
 *
 * Options (config/imports.php) :
 *   url            https://… (fichier .csv ou .zip) ou file:///chemin (dépôt FTP/SFTP local)
 *   headers        en-têtes HTTP éventuels
 *   postal_codes   préfixes acceptés (défaut ['63']) : les biens hors zone sont ignorés
 *   columns        corrections des numéros de colonnes si le logiciel s'écarte du standard (1 = première colonne)
 *   geocode        placer les biens au centre de leur commune / quartier (défaut true)
 *   media_dir      où copier les photos contenues dans le ZIP (défaut public/media/imports/<slug>)
 */
final class PolirisImporter implements Importer
{
    /** Numéros de colonnes Poliris (1 = première), versions 4.x. */
    public const COLUMNS = [
        'reference' => 2, 'transaction' => 3, 'type' => 4, 'postal_code' => 5, 'city' => 6, 'district' => 9,
        'price' => 11, 'rent_cc' => 13, 'surface' => 16, 'rooms' => 18, 'bedrooms' => 19, 'title' => 20,
        'description' => 21, 'available' => 22, 'charges' => 23, 'floor' => 24, 'furnished' => 26,
        'elevator' => 42, 'balcony' => 48, 'terrace' => 50, 'parking' => 53, 'cellar' => 56, 'garden' => 61,
        'photos' => [85, 86, 87, 88, 89, 90, 91, 92, 93], 'contact_phone' => 106, 'contact_name' => 107, 'contact_email' => 108,
        'energy_class' => 177,
    ];

    public function __construct(private readonly HttpClient $http = new HttpClient(timeout: 120, retries: 2), private readonly ?Geocoder $geocoder = null)
    {
    }

    public function fetch(array $options): iterable
    {
        $raw = $this->download($options);
        $zip = null;
        if (str_starts_with($raw, "PK\x03\x04")) {
            [$raw, $zip] = $this->unzip($raw);
        }
        $columns = array_replace(self::COLUMNS, (array) ($options['columns'] ?? []));
        $prefixes = (array) ($options['postal_codes'] ?? ['63']);
        $geocoder = ($options['geocode'] ?? true) ? ($this->geocoder ?? new Geocoder()) : null;
        $mediaDir = $options['media_dir'] ?? (isset($options['_source']) ? dirname(__DIR__, 3) . '/public/media/imports/' . $options['_source'] : null);

        try {
            foreach (self::rows(Text::utf8($raw)) as $row) {
                $item = self::map($row, $columns, $prefixes);
                if ($item === null) {
                    continue; // hors zone ou pas un logement (bureau, parking, terrain…)
                }
                $item['images'] = $this->images($item['images'], $zip, $mediaDir);
                if ($geocoder !== null && ($pos = $geocoder->locate($item['city'] ?? null, $item['postal_code'] ?? null, $item['district'] ?? null))) {
                    [$item['latitude'], $item['longitude']] = $pos;
                }
                yield $item;
            }
        } finally {
            $zip?->close();
        }
    }

    /**
     * Lignes du CSV Poliris : champs entre guillemets séparés par « !# ».
     *
     * @return \Generator<list<string>>
     */
    public static function rows(string $csv): \Generator
    {
        // Un descriptif peut contenir des retours à la ligne : on découpe sur « fin de champ + saut de ligne + début de champ »
        foreach (preg_split('/"\r?\n(?=")/', rtrim($csv, "\r\n")) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Retire exactement un guillemet de chaque côté (trim() mangerait aussi les champs vides en bout de ligne)
            $line = substr($line, $line[0] === '"' ? 1 : 0);
            $line = str_ends_with($line, '"') ? substr($line, 0, -1) : $line;
            $fields = explode('"!#"', $line);
            yield array_map(static fn (string $f) => trim(str_replace('""', '"', $f)), $fields);
        }
    }

    /** Ligne Poliris → format normalisé housing, ou null si le bien est ignoré. */
    public static function map(array $row, array $columns = self::COLUMNS, array $postalPrefixes = ['63']): ?array
    {
        $get = static fn (string $key): string => isset($columns[$key]) && !is_array($columns[$key]) ? (string) ($row[$columns[$key] - 1] ?? '') : '';
        $yes = static fn (string $key): bool => in_array(mb_strtoupper($get($key)), ['OUI', 'O', '1', 'TRUE', 'YES'], true);

        $transaction = match (true) {
            str_contains($t = mb_strtolower($get('transaction')), 'colocation') => 'colocation',
            str_contains($t, 'vacances') || str_contains($t, 'viager') || str_contains($t, 'cession') => null,
            str_starts_with($t, 'location') => 'rent',
            str_starts_with($t, 'vente') => 'sale',
            default => null,
        };
        $kind = mb_strtolower($get('type'));
        $rooms = (int) $get('rooms') ?: null;
        $type = match (true) {
            str_contains($kind, 'studio') => 'studio',
            str_contains($kind, 'loft') => 'loft',
            str_contains($kind, 'chambre') => 'room',
            str_contains($kind, 'maison') || str_contains($kind, 'villa') || str_contains($kind, 'propriété') => 'house',
            str_contains($kind, 'appartement') || str_contains($kind, 'duplex') => $rooms === 1 ? 'studio' : 'apartment',
            str_contains($kind, 'parking') || str_contains($kind, 'garage') || str_contains($kind, 'terrain') || str_contains($kind, 'bureau')
                || str_contains($kind, 'local') || str_contains($kind, 'commerce') || str_contains($kind, 'immeuble') || str_contains($kind, 'fonds') => null,
            default => 'other',
        };
        $postal = preg_replace('/\D/', '', $get('postal_code'));
        $inZone = $postalPrefixes === [] || array_filter($postalPrefixes, static fn ($p) => str_starts_with($postal, (string) $p));
        $price = Text::cents($get('price'));
        if ($transaction === null || $type === null || !$inZone || $price === null || $get('reference') === '') {
            return null;
        }

        $city = trim($get('city'));
        $surface = (float) str_replace(',', '.', $get('surface'));
        $description = Text::fromHtml(str_replace(["\r\n", "\n"], '<br>', $get('description')));
        $title = trim($get('title')) ?: trim(implode(' ', array_filter([
            ['apartment' => 'Appartement', 'house' => 'Maison', 'studio' => 'Studio', 'room' => 'Chambre', 'loft' => 'Loft', 'other' => 'Logement'][$type],
            $rooms && $type !== 'studio' ? $rooms . ' pièce' . ($rooms > 1 ? 's' : '') : null,
            $surface > 0 ? round($surface) . ' m²' : null,
            $city !== '' ? '· ' . mb_convert_case(mb_strtolower($city), MB_CASE_TITLE) : null,
        ])));
        $features = array_keys(array_filter([
            'elevator' => $yes('elevator'), 'balcony' => $yes('balcony'), 'terrace' => $yes('terrace'), 'parking' => $yes('parking'),
            'cellar' => $yes('cellar'), 'garden' => $yes('garden'),
        ]));
        $available = $get('available');
        $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $available) ?: \DateTimeImmutable::createFromFormat('!Y-m-d', $available);
        $energy = strtoupper(substr($get('energy_class'), 0, 1));

        return array_filter([
            'external_id'      => $get('reference'),
            'transaction'      => $transaction,
            'property_type'    => $type,
            'title'            => mb_substr($title, 0, 160),
            'description'      => $description,
            'price_cents'      => $price,
            'charges_cents'    => $transaction !== 'sale' ? Text::cents($get('charges')) : null,
            'charges_included' => $transaction !== 'sale' && $get('rent_cc') !== '' ? $yes('rent_cc') : null,
            'surface_m2'       => $surface > 0 ? round($surface, 2) : null,
            'rooms'            => $rooms,
            'bedrooms'         => (int) $get('bedrooms') ?: null,
            'floor'            => is_numeric($get('floor')) ? (int) $get('floor') : null,
            'furnished'        => $yes('furnished'),
            'energy_class'     => preg_match('/^[A-G]$/', $energy) ? $energy : null,
            'available_from'   => $date ? $date->format('Y-m-d') : null,
            'features'         => array_values(array_intersect($features, array_keys((array) Config::get('housing.features', [])))),
            'district'         => trim($get('district')) ?: null,
            'city'             => $city !== '' ? mb_convert_case(mb_strtolower($city), MB_CASE_TITLE) : null,
            'postal_code'      => strlen($postal) === 5 ? $postal : null,
            'images'           => array_values(array_filter(array_map(static fn (int $c) => trim((string) ($row[$c - 1] ?? '')), (array) $columns['photos']))),
            'contact_name'     => trim($get('contact_name')) ?: null,
            'contact_email'    => filter_var($get('contact_email'), FILTER_VALIDATE_EMAIL) ? $get('contact_email') : null,
            'contact_phone'    => trim($get('contact_phone')) ?: null,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== false);
    }

    private function download(array $options): string
    {
        $url = (string) ($options['url'] ?? '');
        if (str_starts_with($url, 'file://')) {
            $path = substr($url, 7);
            if (!is_file($path)) {
                throw new \RuntimeException("Fichier Poliris introuvable : {$path}");
            }
            return (string) file_get_contents($path);
        }

        return $this->http->get($url, (array) ($options['headers'] ?? []))['body'];
    }

    /** @return array{0: string, 1: \ZipArchive} */
    private function unzip(string $raw): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'poliris');
        file_put_contents($tmp, $raw);
        $zip = new \ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new \RuntimeException('Archive ZIP Poliris illisible.');
        }
        unlink($tmp); // l'archive reste lisible tant qu'elle est ouverte
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('/\.(csv|txt)$/i', $name) && !str_contains($name, '..')) {
                return [(string) $zip->getFromIndex($i), $zip];
            }
        }
        throw new \RuntimeException('Aucun fichier annonces.csv dans l\'archive.');
    }

    /**
     * Photos : URL HTTPS gardées telles quelles ; noms de fichiers du ZIP copiés dans public/media (vérifiés : vraies images, 8 Mo max).
     *
     * @param list<string> $photos
     * @return list<string>
     */
    private function images(array $photos, ?\ZipArchive $zip, ?string $mediaDir): array
    {
        $out = [];
        foreach ($photos as $photo) {
            if (str_starts_with($photo, 'https://')) {
                $out[] = $photo;
            } elseif (str_starts_with($photo, 'http://')) {
                $out[] = 'https://' . substr($photo, 7); // les CDN d'agences servent presque toujours aussi en HTTPS
            } elseif ($zip !== null && $mediaDir !== null && ($bytes = $zip->getFromName(basename($photo))) !== false && strlen($bytes) <= 8_000_000) {
                $ext = match (substr($bytes, 0, 3)) { "\xFF\xD8\xFF" => 'jpg', "\x89PN" => 'png', default => str_starts_with($bytes, 'RIFF') ? 'webp' : null };
                if ($ext === null) {
                    continue;
                }
                $file = sha1($bytes) . '.' . $ext; // même photo = même fichier : rien n'est réécrit d'un import à l'autre
                if (!is_file($mediaDir . '/' . $file)) {
                    @mkdir($mediaDir, 0775, true);
                    file_put_contents($mediaDir . '/' . $file, $bytes);
                }
                $out[] = Config::get('app.media_base_url') . '/imports/' . basename($mediaDir) . '/' . $file;
            }
        }

        return $out;
    }
}
