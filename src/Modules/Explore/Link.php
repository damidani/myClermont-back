<?php

declare(strict_types=1);

namespace App\Modules\Explore;

/**
 * Lien profond vers un écran de l'app. L'app associe chaque type à un écran :
 *
 *   event → fiche événement        place → fiche lieu           housing → fiche logement     job → fiche offre d'emploi
 *   classified → petite annonce    service → fiche pro          offer → exclusivité
 *   story → visionneuse            post → publication           url → navigateur
 *   screen → écran d'un module (id = agenda, housing, jobs, classifieds, services, community, mobility…)
 */
final class Link
{
    public const TYPES = ['event', 'place', 'housing', 'job', 'classified', 'service', 'offer', 'story', 'post', 'screen', 'url'];

    public static function from(?string $type, ?string $id, ?string $url): ?array
    {
        if ($type === null || !in_array($type, self::TYPES, true)) {
            return null;
        }
        if ($type === 'url') {
            return $url !== null && str_starts_with($url, 'https://') ? ['type' => 'url', 'id' => null, 'url' => $url] : null;
        }

        return $id === null ? null : ['type' => $type, 'id' => $id, 'url' => null];
    }
}
