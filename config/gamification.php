<?php

declare(strict_types=1);

/*
 * Règles de gamification. Tout est automatique : les modules émettent des événements,
 * le module Gamification incrémente des compteurs (stats), donne des points et débloque les badges.
 *
 * Modifier une valeur ici suffit (puis config:cache en production). Les badges déjà obtenus
 * restent acquis ; un seuil abaissé est appliqué à la prochaine action de l'utilisateur.
 */
return [

    /*
     * Points gagnés par action (clé = action enregistrée par GamificationService::record()).
     * Chaque action est idempotente : ajouter/retirer/rajouter le même favori ne rapporte qu'une fois.
     */
    'points' => [
        'account.registered'        => 50,   // inscription
        'account.profile_completed' => 20,   // bio + quartier renseignés (une seule fois)
        'account.daily_login'       => 5,    // une fois par jour calendaire (connexion ou ouverture de l'app)
        'referral.completed'        => 100,  // pour le parrain, quand un filleul s'inscrit avec son code
        'favorite.added'            => 2,    // une seule fois par élément mis en favori
        'event.participated'        => 5,    // « Je participe », une seule fois par événement
        'community.posted'          => 5,    // première publication de la journée
        'community.voted'           => 1,    // vote à un sondage (une fois par sondage)
        'classifieds.published'     => 5,    // première annonce déposée du jour
        'classifieds.given'         => 10,   // objet donné (annonce gratuite marquée « vendu / donné »)
    ],

    // Multiplicateur appliqué à tous les gains de points des membres premium
    'premium_multiplier' => 1.5,

    /*
     * Catalogue des badges.
     *   rule.stat : compteur surveillé   rule.min : seuil de déblocage
     *   points    : bonus versé au déblocage
     *
     * Compteurs disponibles :
     *   account.registered          1 dès l'inscription
     *   referrals                   nombre de filleuls inscrits
     *   favorites.total             éléments différents mis en favori (depuis toujours)
     *   favorites.type.<type>       idem par type (place, listing, event...)
     *   favorites.tag.<tag>         idem par tag fourni par le module du type (volcan, gourmand...)
     *   events.participated         événements différents auxquels l'utilisateur a participé
     *   events.category.<slug>      idem par catégorie (sport, musique...)
     *   community.posts             publications (toutes) — community.posts.<type> par format
     *   classifieds.posted          annonces déposées ; classifieds.given  objets donnés
     */
    'badges' => [
        'welcome' => [
            'name'        => 'Bienvenue à Clermont',
            'description' => 'Créer son compte myClermont.',
            'rule'        => ['stat' => 'account.registered', 'min' => 1],
            'points'      => 0,
        ],
        'curious' => [
            'name'        => 'Curieux',
            'description' => 'Ajouter 10 éléments en favoris.',
            'rule'        => ['stat' => 'favorites.total', 'min' => 10],
            'points'      => 20,
        ],
        'explorer' => [
            'name'        => 'Explorateur',
            'description' => 'Ajouter 25 lieux en favoris.',
            'rule'        => ['stat' => 'favorites.type.place', 'min' => 25],
            'points'      => 50,
        ],
        'ambassador' => [
            'name'        => 'Ambassadeur',
            'description' => 'Parrainer 3 personnes qui s\'inscrivent avec votre code.',
            'rule'        => ['stat' => 'referrals', 'min' => 3],
            'points'      => 100,
        ],
        'volcano' => [
            'name'        => 'Passion volcan',
            'description' => 'Ajouter 5 lieux liés aux volcans en favoris.',
            'rule'        => ['stat' => 'favorites.tag.volcan', 'min' => 5],
            'points'      => 30,
        ],
        'gourmand' => [
            'name'        => 'Gourmand',
            'description' => 'Ajouter 10 restaurants ou adresses gourmandes en favoris.',
            'rule'        => ['stat' => 'favorites.tag.gourmand', 'min' => 10],
            'points'      => 30,
        ],
    ],
];
