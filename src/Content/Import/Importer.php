<?php

declare(strict_types=1);

namespace App\Content\Import;

/**
 * Récupère les éléments d'une source externe (flux partenaire, API, open data...).
 * Chaque élément est un tableau au format normalisé de la cible (voir docs/IMPORTS.md),
 * avec obligatoirement "external_id" (identifiant stable chez la source).
 */
interface Importer
{
    /**
     * @param array<string, mixed> $options options de la source (config/imports.php)
     * @return iterable<array<string, mixed>>
     */
    public function fetch(array $options): iterable;
}
