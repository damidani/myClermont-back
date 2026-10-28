<?php

declare(strict_types=1);

namespace App\Content;

use App\Cache\Cache;
use App\Core\Database;

/**
 * Provenance des contenus : publiés par myClermont, par un partenaire, ou importés automatiquement.
 *
 * Les modules joignent content_sources dans leurs requêtes avec ContentSources::SELECT
 * (colonnes préfixées source_*), puis utilisent present() / contact() pour l'API.
 */
final class ContentSources
{
    public const INTERNAL_ID = 1;

    /** À ajouter au SELECT d'un module, avec "JOIN content_sources s ON s.id = x.source_id" */
    public const SELECT = 's.slug AS source_slug, s.kind AS source_kind, s.name AS source_name, s.logo_url AS source_logo_url,
                           s.attribution AS source_attribution, s.contact_mode AS source_contact_mode';

    public static function findBySlug(string $slug): ?array
    {
        $key = 'source:' . $slug;
        if (Cache::isInMemory() && is_array($cached = Cache::store()->get($key))) {
            return $cached;
        }

        $row = Database::fetch('SELECT * FROM content_sources WHERE slug = :s', ['s' => $slug]);
        if ($row !== null && Cache::isInMemory()) {
            Cache::store()->set($key, $row, 300);
        }

        return $row;
    }

    /** Bloc "source" de l'API (null pour les contenus myClermont : rien à afficher). */
    public static function present(array $row): ?array
    {
        if (($row['source_kind'] ?? 'internal') === 'internal') {
            return null;
        }

        return [
            'slug'        => $row['source_slug'],
            'kind'        => $row['source_kind'],
            'name'        => $row['source_name'],
            'logo_url'    => $row['source_logo_url'],
            'attribution' => $row['source_attribution'],
        ];
    }

    /**
     * Ce que fait le bouton « Contacter » :
     *   form     → formulaire myClermont (le message est transmis à l'annonceur)
     *   external → ouvrir url dans le navigateur (annonce importée : on renvoie vers le site d'origine)
     */
    public static function contact(array $row): array
    {
        if (($row['source_contact_mode'] ?? 'internal') === 'external' && !empty($row['external_url'])) {
            return [
                'mode'  => 'external',
                'url'   => $row['external_url'],
                'label' => 'Contacter sur ' . $row['source_name'],
            ];
        }

        return ['mode' => 'form', 'url' => null, 'label' => 'Contacter'];
    }
}
