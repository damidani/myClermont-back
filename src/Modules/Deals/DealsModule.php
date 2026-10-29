<?php

declare(strict_types=1);

namespace App\Modules\Deals;

use App\Core\Database;
use App\Core\Module;
use App\Core\Router;
use App\Modules\Favorites\FavoriteProvider;
use App\Modules\Favorites\FavoriteTypes;

/** Bons plans des commerçants partenaires : liste, code unique par compte, validation en caisse (spec v1). */
final class DealsModule implements Module
{
    public function routes(Router $r): void
    {
        $r->get('/deals', [DealController::class, 'index']);
        $r->get('/deals/{id}', [DealController::class, 'show']);
        $r->group(['middleware' => ['auth']], static function (Router $r): void {
            $r->post('/deals/{id}/redeem', [DealController::class, 'redeem']);
            $r->post('/deals/redemptions/{code}/validate', [DealController::class, 'validateCode'], ['role:partner']);
        });
    }

    public function listeners(): array
    {
        return [];
    }

    public function boot(): void
    {
        FavoriteTypes::register('deal', new class implements FavoriteProvider {
            public function find(array $ids): array
            {
                $ids = array_values(array_filter($ids, static fn ($i) => preg_match('/^[0-9a-f-]{36}$/', (string) $i)));
                if ($ids === []) {
                    return [];
                }
                $in = implode(', ', array_map(static fn ($i) => ':i' . $i, array_keys($ids)));
                $out = [];
                foreach (Database::fetchAll(
                    "SELECT d.*, p.uid AS place_uid FROM deals d LEFT JOIN places p ON p.id = d.place_id WHERE d.uid IN ({$in}) AND d.status = 'published' AND d.deleted_at IS NULL",
                    array_combine(array_map(static fn ($i) => 'i' . $i, array_keys($ids)), $ids),
                ) as $d) {
                    $out[$d['uid']] = ['tags' => ['bons-plans'], 'summary' => DealController::present($d) + ['subtitle' => $d['merchant']]];
                }

                return $out;
            }
        });
    }
}
