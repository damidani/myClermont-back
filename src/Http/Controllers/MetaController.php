<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cache\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Events\SpecEventController;
use App\Modules\Places\MapController;

/** GET /meta (spec v1) : enums, catégories, quartiers, version minimale de l'app, drapeaux de fonctions. Cache 1 h. */
final class MetaController
{
    public function index(Request $request): Response
    {
        $meta = Cache::store()->get('meta:v1');
        if (!is_array($meta)) {
            $cats = static fn (string $table) => array_map(
                static fn (array $r) => ['key' => $r['slug'], 'name' => $r['name'], 'icon' => $r['icon']],
                Database::fetchAll("SELECT slug, name, icon FROM {$table} ORDER BY sort_order, id"),
            );
            $meta = [
                'version'         => 1,
                'min_app_version' => Config::get('meta.min_app_version'),
                'features'        => Config::get('meta.features'),
                'enums'           => [
                    'place_categories' => MapController::CATEGORIES,
                    'nature_kinds'     => MapController::NATURE_KINDS,
                    'event_kinds'      => SpecEventController::KINDS,
                    'job_contracts'    => ['CDI', 'CDD', 'Alternance', 'Stage', 'Freelance'],
                    'job_work_modes'   => ['Sur site', 'Hybride', 'Télétravail'],
                    'housing_types'    => ['Location', 'Colocation', 'Achat'],
                    'post_kinds'       => ['text', 'poll', 'photos', 'photoCaption', 'video'],
                    'ad_conditions'    => array_values((array) Config::get('meta.classified_conditions')),
                    'favorite_types'   => ['place', 'event', 'deal', 'job', 'housing', 'service', 'classified'],
                    'report_reasons'   => ['spam', 'scam', 'offensive', 'harassment', 'inappropriate', 'other'],
                ],
                'service_categories'    => $cats('service_categories'),
                'classified_categories' => $cats('classified_categories'),
                'neighborhoods'         => array_column(Database::fetchAll('SELECT name FROM neighborhoods WHERE is_active = 1 ORDER BY sort_order'), 'name'),
                'zone'                  => MapController::ZONE,
            ];
            Cache::store()->set('meta:v1', $meta, 3600);
        }
        $meta['server_time'] = gmdate('Y-m-d\TH:i:s\Z');

        return Response::success($meta)->withHeader('Cache-Control', 'private, max-age=3600');
    }
}
