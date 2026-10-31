<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;

/**
 * Statistiques de la fiche (demande 8), jour par jour à l'heure de Paris.
 *
 *   GET /pro/stats?from=AAAA-MM-JJ&to=AAAA-MM-JJ      → { items: DailyStat[] }
 *   GET /pro/stats/export?from=&to=&format=csv        → CSV (Partenaires)
 *   POST /places/{placeId}/events { type }  (app)     → 204 : vue, itinéraire, appel, site (un par appareil, lieu, type et jour)
 */
final class StatsController
{
    private const TYPES = ['view', 'directions', 'call', 'website'];

    public function index(Request $request): Response
    {
        return Response::success($this->days($request));
    }

    public function export(Request $request): Response
    {
        if (!Pro::limits(Pro::business($request))['csv_export']) {
            throw Pro::planLimit('csvExport', 'L\'export CSV est réservé aux Partenaires.');
        }
        $csv = "date;vues;itineraires;appels;site;favoris;codes\n";
        foreach ($this->days($request) as $d) {
            $csv .= implode(';', [$d['date'], $d['views'], $d['directions'], $d['calls'], $d['website'], $d['favorites'], $d['codes']]) . "\n";
        }

        return Response::raw("\u{FEFF}" . $csv, 'text/csv; charset=utf-8')->withHeader('Content-Disposition', 'attachment; filename="statistiques-myclermont.csv"');
    }

    /** POST /places/{placeId}/events (app signée, sans compte) */
    public function track(Request $request): Response
    {
        $type = $request->json()['type'] ?? null;
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationException(['type' => 'Type : view, directions, call ou website.']);
        }
        $place = \App\Modules\Places\MapController::findPublished((string) $request->param('id'))
            ?? throw new HttpException(404, 'not_found', 'Ce lieu n\'existe pas ou n\'est plus en ligne.');
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Y-m-d');
        Database::execute('INSERT IGNORE INTO place_stat_hits (place_id, day, type, device_id) VALUES (:p, :d, :t, :dev)',
            ['p' => $place['id'], 'd' => $day, 't' => $type, 'dev' => $request->attributes['device']['id']]);

        return Response::noContent();
    }

    /** @return list<array{date: string, views: int, directions: int, calls: int, website: int, favorites: int, codes: int}> */
    private function days(Request $request): array
    {
        $q = $request->query();
        $tz = new \DateTimeZone('Europe/Paris');
        $today = new \DateTimeImmutable('today', $tz);
        try {
            $to = isset($q['to']) ? new \DateTimeImmutable((string) $q['to'], $tz) : $today;
            $from = isset($q['from']) ? new \DateTimeImmutable((string) $q['from'], $tz) : $to->modify('-29 days');
        } catch (\Exception) {
            throw new ValidationException(['from' => 'Dates au format AAAA-MM-JJ.']);
        }
        if ($from > $to || $from->diff($to)->days > 366) {
            throw new ValidationException(['from' => 'Période invalide (un an au plus).']);
        }
        $limit = Pro::limits(Pro::business($request))['stats_days'];
        if ($from < $today->modify('-' . $limit . ' days')) {
            throw Pro::planLimit('statsDays', "Ta formule donne accès aux {$limit} derniers jours.");
        }
        $place = Pro::place($request);
        $out = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $out[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'views' => 0, 'directions' => 0, 'calls' => 0, 'website' => 0, 'favorites' => 0, 'codes' => 0];
        }
        $params = ['p' => $place['id'], 'f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')];
        $col = ['view' => 'views', 'directions' => 'directions', 'call' => 'calls', 'website' => 'website'];
        foreach (Database::fetchAll('SELECT day, type, COUNT(*) AS n FROM place_stat_hits WHERE place_id = :p AND day BETWEEN :f AND :t GROUP BY day, type', $params) as $r) {
            $out[$r['day']][$col[$r['type']]] = (int) $r['n'];
        }
        // Heures UTC des bornes (jour de Paris)
        $utc = ['p' => $place['id'], 'f' => $from->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                't' => $to->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
        foreach (Database::fetchAll("SELECT created_at FROM favorites WHERE type = 'place' AND target_id = (SELECT uid FROM places WHERE id = :p) AND created_at >= :f AND created_at < :t",
            $utc) as $r) {
            $day = self::parisDay($r['created_at']);
            isset($out[$day]) && $out[$day]['favorites']++;
        }
        foreach (Database::fetchAll('SELECT r.validated_at FROM deal_redemptions r JOIN deals d ON d.id = r.deal_id WHERE d.place_id = :p AND r.validated_at >= :f AND r.validated_at < :t',
            $utc) as $r) {
            $day = self::parisDay($r['validated_at']);
            isset($out[$day]) && $out[$day]['codes']++;
        }
        return array_values($out);
    }

    private static function parisDay(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('Y-m-d');
    }
}
