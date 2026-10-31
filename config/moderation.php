<?php

declare(strict_types=1);

/*
 * Filtre automatique des contenus publiés par les utilisateurs (avis, posts, commentaires, annonces) — exigé par l'App Store (règle 1.2).
 * Un contenu filtré n'est pas publié : il attend la validation d'un modérateur (back-office › signalements).
 * Les mots sont comparés sans accents ni majuscules, en mot entier.
 */
return [
    // « mot* » = toute la famille (« encul* » : enculé, enculer, enculeur…). Variantes d'écriture gérées par ContentFilter::normalize
    'blocked_words' => [
        // Insultes
        'connard', 'connasse', 'conard', 'conasse', 'connar', 'konar', 'salope', 'salop', 'salopard', 'encul*', 'enkul*', 'pute', 'putain de ta',
        'pouffiasse', 'grognasse', 'batard', 'enfoire', 'enfoiré', 'fils de pute', 'fils de chien', 'fdp', 'ntm', 'nique ta', 'niquer ta',
        'nik ta', 'ta gueule', 'ferme ta gueule', 'trou du cul', 'trouduc', 'sous-merde', 'sous merde', 'raclure', 'merdeux', 'abruti',
        'debile', 'cretin', 'tocard', 'bouffon', 'gros porc', 'grosse vache', 'casse-toi', 'va te faire',
        // Haine
        'pd', 'pede', 'pédé', 'tapette', 'tarlouze', 'gouine', 'bougnoule', 'negro', 'négro', 'negre', 'nègre', 'youpin', 'bicot', 'bamboula',
        'sale arabe', 'sale noir', 'sale juif', 'sale blanc', 'sale musulman', 'sale pd', 'sale race',
        // Menaces
        'creve', 'crève', 'je vais te tuer', 'je vais te buter', 'je vais te defoncer', 'suicide-toi', 'suicide toi', 'va mourir',
    ],
    // Domaines de liens toujours acceptés
    'allowed_domains' => ['myclermont.app', 'clermont-ferrand.fr', 'clermontmetropole.eu', 't2c.fr', 'francetravail.fr',
                          'instagram.com', 'facebook.com', 'youtube.com', 'leboncoin.fr', 'service-public.fr', 'gouv.fr'],
    // Raccourcisseurs et domaines de liens suspects
    'suspicious_domains' => ['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'is.gd', 'cutt.ly', 'rebrand.ly', 'shorturl.at', 'tiny.cc', 't.me', 'wa.me'],
    // Au-delà de ce nombre de liens, le contenu est relu
    'max_links' => 2,
];
