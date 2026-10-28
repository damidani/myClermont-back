<?php

declare(strict_types=1);

namespace App\Content\Import;

/**
 * Implémenté par chaque module qui accepte des contenus importés (agenda, logement...),
 * puis enregistré dans son boot() : ImportTargets::register('housing', new HousingImportTarget()).
 */
interface ImportTarget
{
    /**
     * Crée ou met à jour l'élément (clé : source_id + external_id).
     * Doit lever \InvalidArgumentException si l'élément est invalide (il est alors ignoré et compté en erreur).
     *
     * @return 'created'|'updated'|'unchanged'
     */
    public function upsert(int $sourceId, array $item, bool $autoPublish, string $syncedAt): string;

    /** Dépublie les éléments de la source absents du dernier import (annonce retirée chez la source). */
    public function unpublishMissing(int $sourceId, string $syncedBefore): int;
}
