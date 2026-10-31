<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Content\Import\HttpClient;

/**
 * Vérification d'un SIRET avec l'API Recherche d'entreprises (annuaire public de l'État, sans clé).
 * null = service injoignable ou SIRET inconnu : l'équipe vérifiera depuis le back-office.
 */
final class Sirene
{
    /** @return array{name: string, naf: ?string, naf_label: ?string, active: bool}|null */
    public static function lookup(string $siret): ?array
    {
        try {
            $data = (new HttpClient(timeout: 5, retries: 0))->getJson('https://recherche-entreprises.api.gouv.fr/search?' . http_build_query(['q' => $siret, 'per_page' => 1]));
        } catch (\Throwable) {
            return null;
        }
        foreach ($data['results'] ?? [] as $company) {
            foreach ([$company['siege'] ?? [], ...($company['matching_etablissements'] ?? [])] as $e) {
                if (($e['siret'] ?? null) === $siret) {
                    return [
                        'name'      => (string) ($company['nom_raison_sociale'] ?? $company['nom_complet'] ?? ''),
                        'naf'       => $e['activite_principale'] ?? $company['activite_principale'] ?? null,
                        'naf_label' => null,
                        'active'    => ($e['etat_administratif'] ?? 'A') === 'A',
                    ];
                }
            }
        }

        return null;
    }
}
