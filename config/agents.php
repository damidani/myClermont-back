<?php

declare(strict_types=1);

use App\Core\Env;

/* Canal entre assistants (src/Modules/Agents) : jeton partagé, jamais dans l'app publiée. */
return [
    'token' => Env::get('AGENTS_TOKEN', ''),
];
