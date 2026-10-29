<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Clermont IA (spec v1 › Clermont IA) : POST /assistant/answer.
 * La clé n'est jamais dans l'app : le serveur appelle l'API Claude d'Anthropic.
 */
return [
    'api_key'    => Env::get('ANTHROPIC_API_KEY', ''),
    'model'      => Env::get('ANTHROPIC_MODEL', 'claude-opus-5-5'),
    'effort'     => Env::get('ANTHROPIC_EFFORT', 'low'),      // réponses courtes de conversation : effort bas suffit
    'max_tokens' => 600,
    'max_steps'  => 4,                                       // appels d'outils maximum par question

    // Quotas (à valider) : 1 question découverte sans My Clermont+, 100 par jour avec
    'free_questions'  => (int) Env::get('ASSISTANT_FREE_QUESTIONS', 1),
    'plus_daily'      => (int) Env::get('ASSISTANT_PLUS_DAILY', 100),
    'cache_ttl'       => 600,    // même question dans la même zone : réponse en cache 10 min
    'retention_days'  => 30,     // questions gardées pour le débogage, sans la position
];
