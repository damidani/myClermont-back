<?php

declare(strict_types=1);

/*
 * Filtre automatique des contenus publiés par les utilisateurs (avis, posts, commentaires, annonces) — exigé par l'App Store (règle 1.2).
 * Un contenu filtré n'est pas publié : il attend la validation d'un modérateur (back-office › signalements).
 * Les mots sont comparés sans accents ni majuscules, en mot entier.
 */
return [
    'blocked_words' => [
        'connard', 'connasse', 'salope', 'enculé', 'encule', 'pute', 'putain de ta', 'nique ta', 'ntm', 'fdp', 'fils de pute',
        'pd', 'pédé', 'tapette', 'bougnoule', 'négro', 'youpin', 'bicot', 'sale arabe', 'sale noir', 'sale juif',
        'crève', 'je vais te tuer', 'suicide-toi',
    ],
    // Domaines de liens toujours acceptés
    'allowed_domains' => ['myclermont.fr', 'myclermont.app', 'clermont-ferrand.fr', 'clermontmetropole.eu', 't2c.fr', 'francetravail.fr',
                          'instagram.com', 'facebook.com', 'youtube.com', 'leboncoin.fr', 'service-public.fr', 'gouv.fr'],
    // Raccourcisseurs et domaines de liens suspects
    'suspicious_domains' => ['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'is.gd', 'cutt.ly', 'rebrand.ly', 'shorturl.at', 'tiny.cc', 't.me', 'wa.me'],
    // Au-delà de ce nombre de liens, le contenu est relu
    'max_links' => 2,
];
