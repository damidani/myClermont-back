<?php

declare(strict_types=1);

use App\Core\Env;

/*
 * Clermont IA (spec v1 › Clermont IA) : POST /assistant/answer.
 * Moteur : Mistral AI (API Chat Completions, appel d'outils). La clé n'est jamais dans l'app.
 */
return [
    'api_key'    => Env::get('MISTRAL_API_KEY', ''),
    'model'      => Env::get('MISTRAL_MODEL', 'mistral-medium-latest'),
    // Essayés dans l'ordre si le modèle principal répond 429 (limite du forfait Mistral)
    'fallback_models' => array_values(array_filter(array_map('trim', explode(',', (string) Env::get('MISTRAL_FALLBACK_MODELS', 'mistral-small-latest,ministral-8b-latest'))))),
    'max_tokens' => 600,
    'max_steps'  => 4,                                       // appels d'outils maximum par question

    // Décision du 30/10/2026 : 1 question découverte sans compte (par appareil), puis compte gratuit ; aucun plafond quotidien
    // pour les comptes, seulement l'anti-abus de 10 questions par minute (security.php › rate_limits.ai)
    'free_questions'  => (int) Env::get('ASSISTANT_FREE_QUESTIONS', 1),
    'cache_ttl'       => 600,    // même question dans la même zone : réponse en cache 10 min
    'retention_days'  => 30,     // questions gardées pour le débogage, sans la position
];
