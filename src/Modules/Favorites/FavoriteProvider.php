<?php

declare(strict_types=1);

namespace App\Modules\Favorites;

/**
 * À implémenter par chaque module dont les éléments peuvent être mis en favori
 * (Lieux, Annonces, Événements...), puis à enregistrer dans son boot() :
 *
 *   FavoriteTypes::register('place', new PlaceFavoriteProvider());
 */
interface FavoriteProvider
{
    /**
     * Informations sur les éléments demandés, en une seule requête (pas de N+1).
     * Les éléments inexistants ou supprimés sont simplement absents du tableau retourné.
     *
     * @param list<string> $ids
     * @return array<string, array{tags: list<string>, summary: array}>
     *         tags    : utilisés par la gamification (ex. ['volcan', 'randonnee'])
     *         summary : aperçu renvoyé dans la liste des favoris (titre, image...)
     */
    public function find(array $ids): array;
}
