<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Core\Config;
use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Modules\Favorites\FavoriteRepository;

final class EventController
{
    private EventRepository $events;

    public function __construct()
    {
        $this->events = new EventRepository();
    }

    /**
     * GET /v1/events — écran « Agenda »
     *   ?category=sport  &from=2026-10-28  &to=2026-10-31  &neighborhood_id=2  &free=1  &featured=1
     *   &limit=20  &cursor=<meta.next_cursor>
     */
    public function index(Request $request): Response
    {
        $q = $request->query();
        $categories = $this->events->categoriesWithCounts();

        $category = isset($q['category']) && $q['category'] !== '' ? (string) $q['category'] : null;
        if ($category !== null && !in_array($category, array_column($categories, 'slug'), true)) {
            throw new ValidationException(['category' => 'Catégorie inconnue.']);
        }

        $max = (int) Config::get('events.max_per_page', 50);
        $limit = max(1, min($max, (int) ($q['limit'] ?? Config::get('events.per_page', 20))));

        $rows = $this->events->upcoming([
            'category'        => $category,
            'from'            => isset($q['from']) ? $this->localDayToUtc((string) $q['from'], 'from') : null,
            'to'              => isset($q['to']) ? $this->localDayToUtc((string) $q['to'], 'to', endOfDay: true) : null,
            'neighborhood_id' => isset($q['neighborhood_id']) ? (int) $q['neighborhood_id'] : null,
            'free'            => ($q['free'] ?? '') === '1',
            'featured'        => ($q['featured'] ?? '') === '1',
        ], $limit, isset($q['cursor']) ? $this->decodeCursor((string) $q['cursor']) : null);

        $flags = $this->flags($request, $rows);
        $last = end($rows);

        return Response::success(
            array_map(static fn (array $e) => EventPresenter::card($e, $flags[$e['id']] ?? []), $rows),
            200,
            [
                // Filtres de l'écran (« Tous 7 », « Sport 2 »...)
                'categories'  => $categories,
                'total'       => $category === null
                    ? array_sum(array_column($categories, 'count'))
                    : (int) (array_column($categories, 'count', 'slug')[$category] ?? 0),
                'next_cursor' => count($rows) === $limit && $last ? $this->encodeCursor($last) : null,
            ],
        );
    }

    /** GET /v1/events/categories */
    public function categories(Request $request): Response
    {
        return Response::success($this->events->categoriesWithCounts());
    }

    /** GET /v1/events/{id} — fiche (id = uuid ou slug) */
    public function show(Request $request): Response
    {
        $event = $this->findOrFail((string) $request->param('id'));
        $flags = $this->flags($request, [$event]);

        return Response::success(EventPresenter::detail($event, $this->events->schedule((int) $event['id']), $flags[$event['id']] ?? []));
    }

    /** GET /v1/events/{id}/calendar.ics — bouton « Calendrier » */
    public function calendar(Request $request): Response
    {
        $event = $this->findOrFail((string) $request->param('id'));

        return Response::raw(Ics::build($event), 'text/calendar; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $event['slug'] . '.ics"');
    }

    /** PUT /v1/events/{id}/participation — « Je participe » (idempotent) */
    public function participate(Request $request): Response
    {
        $event = $this->findOrFail((string) $request->param('id'));
        if ($event['status'] === 'cancelled') {
            throw new HttpException(409, 'event_cancelled', 'Cet événement est annulé.');
        }
        if (strtotime($event['ends_at'] . ' UTC') < time()) {
            throw new HttpException(409, 'event_ended', 'Cet événement est terminé.');
        }

        $userId = $request->attributes['user_id'];
        $result = $this->events->participate((int) $event['id'], $userId);
        if ($result === 'full') {
            throw new HttpException(409, 'event_full', 'Il n\'y a plus de place pour cet événement.');
        }
        if ($result === 'added') {
            Events::dispatch('event.participation_added', [
                'user_id' => $userId, 'event_id' => (int) $event['id'], 'event_uid' => $event['uid'],
                'category' => $event['category_slug'],
            ]);
        }

        return $this->participationState($event, true);
    }

    /** DELETE /v1/events/{id}/participation */
    public function cancelParticipation(Request $request): Response
    {
        $event = $this->findOrFail((string) $request->param('id'));
        $userId = $request->attributes['user_id'];

        if ($this->events->cancelParticipation((int) $event['id'], $userId)) {
            Events::dispatch('event.participation_removed', ['user_id' => $userId, 'event_id' => (int) $event['id']]);
        }

        return $this->participationState($event, false);
    }

    /** GET /v1/users/me/events?past=1 — « Mes événements » */
    public function mine(Request $request): Response
    {
        $past = ($request->query()['past'] ?? '') === '1';
        $rows = $this->events->participations($request->attributes['user_id'], $past, 100);
        $flags = $this->flags($request, $rows);

        return Response::success(array_map(static fn (array $e) => EventPresenter::card($e, $flags[$e['id']] ?? []), $rows));
    }

    private function participationState(array $event, bool $participating): Response
    {
        return Response::success([
            'id'                 => $event['uid'],
            'is_participating'   => $participating,
            'participants_count' => $this->events->participantsCount((int) $event['id']),
        ]);
    }

    private function findOrFail(string $idOrSlug): array
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,120}$/', $idOrSlug) || ($event = $this->events->find($idOrSlug)) === null) {
            throw new HttpException(404, 'event_not_found', 'Événement introuvable.');
        }

        return $event;
    }

    /**
     * is_favorite / is_participating pour l'utilisateur connecté, en 2 requêtes pour toute la liste.
     *
     * @return array<int, array{is_favorite: bool, is_participating: bool}>
     */
    private function flags(Request $request, array $rows): array
    {
        $userId = $request->attributes['user_id'] ?? null;
        if ($userId === null || $rows === []) {
            return [];
        }

        $favorites = (new FavoriteRepository())->filterFavorited($userId, 'event', array_column($rows, 'uid'));
        $participating = $this->events->participatingIds($userId, array_map('intval', array_column($rows, 'id')));

        $flags = [];
        foreach ($rows as $e) {
            $flags[$e['id']] = [
                'is_favorite'      => in_array($e['uid'], $favorites, true),
                'is_participating' => in_array((int) $e['id'], $participating, true),
            ];
        }

        return $flags;
    }

    /** "2026-10-28" (jour à Paris) => borne UTC */
    private function localDayToUtc(string $day, string $field, bool $endOfDay = false): string
    {
        $tz = new \DateTimeZone((string) Config::get('events.timezone', 'Europe/Paris'));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $tz);
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw new ValidationException([$field => 'Date attendue au format AAAA-MM-JJ.']);
        }
        if ($endOfDay) {
            $date = $date->modify('+1 day');
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function encodeCursor(array $row): string
    {
        return rtrim(strtr(base64_encode($row['starts_at'] . '|' . $row['id']), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: int} */
    private function decodeCursor(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($decoded === false || !preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|(\d+)$/', $decoded, $m)) {
            throw new ValidationException(['cursor' => 'Curseur invalide.']);
        }

        return [$m[1], (int) $m[2]];
    }
}
