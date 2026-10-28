<?php

declare(strict_types=1);

namespace App\Content\Import;

/**
 * Importe un flux JSON au format normalisé myClermont (docs/IMPORTS.md).
 * C'est le format à demander aux partenaires (agences, office de tourisme, salles...).
 *
 * Options (config/imports.php) :
 *   url          URL du flux (HTTPS)
 *   headers      en-têtes HTTP, ex. ['Authorization: Bearer xxx']
 *   items_path   clé contenant la liste (défaut "items")
 *   timeout      secondes (défaut 30)
 */
final class JsonFeedImporter implements Importer
{
    public function fetch(array $options): iterable
    {
        $url = (string) ($options['url'] ?? '');
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'file://')) {
            throw new \RuntimeException('URL de flux invalide (HTTPS requis).');
        }

        if (str_starts_with($url, 'file://')) {
            $body = (string) file_get_contents(substr($url, 7)); // flux local (tests, dépôt FTP)
        } else {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => (int) ($options['timeout'] ?? 30),
                CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], (array) ($options['headers'] ?? [])),
                CURLOPT_USERAGENT      => 'myClermont-importer/1.0',
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($body === false || $code !== 200) {
                throw new \RuntimeException("Flux inaccessible (HTTP {$code}) {$error}");
            }
        }

        $data = json_decode((string) $body, true, 64, JSON_THROW_ON_ERROR);
        $items = $data[$options['items_path'] ?? 'items'] ?? null;
        if (!is_array($items)) {
            throw new \RuntimeException('Flux sans liste "' . ($options['items_path'] ?? 'items') . '".');
        }

        foreach ($items as $item) {
            if (is_array($item)) {
                yield $item;
            }
        }
    }
}
