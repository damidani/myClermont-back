<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Un module = une fonctionnalité autonome (compte, favoris, gamification...).
 * Il déclare ses routes et les événements qu'il écoute ; il est activé dans config/modules.php.
 *
 * Les modules communiquent par événements (Events::dispatch) plutôt que par appels directs :
 * le module Compte ne connaît pas la Gamification, il annonce juste « account.registered ».
 */
interface Module
{
    /**
     * Routes du module. Elles sont montées sous /v1 et exigent déjà une signature
     * d'appareil + le rate-limit appareil. Ajouter "auth" pour un utilisateur connecté.
     */
    public function routes(Router $router): void;

    /**
     * @return array<string, list<array{0: class-string, 1: string}>> événement => écouteurs [Classe, méthode]
     */
    public function listeners(): array;

    /** Initialisation (enregistrement de types de favoris, etc.). */
    public function boot(): void;
}
