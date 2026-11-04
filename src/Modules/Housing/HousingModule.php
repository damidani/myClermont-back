<?php

declare(strict_types=1);

namespace App\Modules\Housing;

use App\Content\Import\ImportTargets;
use App\Core\Module;
use App\Core\Router;
use App\Modules\Favorites\FavoriteProvider;
use App\Modules\Favorites\FavoriteTypes;

/**
 * Logement : annonces location / achat / colocation, filtres, carte, fiche, formulaire de contact.
 * Les annonces peuvent venir de myClermont, d'un partenaire ou d'un import (voir docs/IMPORTS.md).
 *
 * Événement émis : housing.contact_requested {user_id|null, listing_uid, request_uid}
 */
final class HousingModule implements Module
{
    public function routes(Router $r): void
    {
        // Rubrique retirée de l'app (e3551d70, remplacée par Associations) : plus de routes publiques.
        // Les annonces des commerçants restent gérées par l'espace pro (RecruitController).
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
        ImportTargets::register('housing', new HousingImportTarget());

        FavoriteTypes::register('housing', new class implements FavoriteProvider {
            public function find(array $ids): array
            {
                $out = [];
                foreach ((new HousingRepository())->findManyByUid($ids) as $uid => $listing) {
                    $out[$uid] = ['tags' => [$listing['transaction']], 'summary' => HousingPresenter::card($listing)];
                }

                return $out;
            }
        });
    }
}
