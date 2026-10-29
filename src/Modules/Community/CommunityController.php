<?php

declare(strict_types=1);

namespace App\Modules\Community;

use App\Content\Media\MediaStore;
use App\Core\Config;
use App\Core\Database;
use App\Core\Events;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Support\Validator;

final class CommunityController
{
    private PostRepository $posts;

    public function __construct()
    {
        $this->posts = new PostRepository();
    }

    /**
     * GET /v1/community/posts — « Le fil d'actualité »
     *   ?filter=discussions|polls|photos|videos  &neighborhood_id=2  &author=<user uid>  &limit=20  &cursor=<meta.next_cursor>
     */
    public function feed(Request $request): Response
    {
        $q = $request->query();
        $filters = (array) Config::get('community.filters');
        $filter = ($q['filter'] ?? '') !== '' ? (string) $q['filter'] : null;
        if ($filter !== null && !isset($filters[$filter])) {
            throw new ValidationException(['filter' => 'Filtre inconnu : ' . implode(', ', array_keys($filters)) . '.']);
        }

        $authorId = null;
        if (isset($q['author'])) {
            $authorId = (int) (Database::fetch('SELECT id FROM users WHERE uid = :u', ['u' => strtolower((string) $q['author'])])['id'] ?? -1);
        }

        $limit = max(1, min(50, (int) ($q['limit'] ?? Config::get('community.per_page', 20))));
        $hood = isset($q['neighborhood_id']) ? (int) $q['neighborhood_id'] : null;
        if ($hood === null && isset($q['neighborhood']) && $q['neighborhood'] !== '') {
            $hood = (int) (Database::fetch('SELECT id FROM neighborhoods WHERE name = :n OR slug = :s', ['n' => $q['neighborhood'], 's' => $q['neighborhood']])['id'] ?? -1);
        }
        $rows = $this->posts->feed(
            $filter !== null ? $filters[$filter][1] : null,
            $hood,
            $authorId,
            $limit,
            isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : null,
        );

        $viewerId = $request->attributes['user_id'] ?? null;
        $related = $this->posts->related($rows, $viewerId);
        $counts = $this->posts->typeCounts();
        $last = end($rows);

        return Response::success(
            array_map(static fn (array $p) => PostPresenter::post($p, $related, $viewerId), $rows),
            200,
            [
                // « Tout 3 · Discussions 1 · Sondages 1 · Photos 1 »
                'filters' => array_map(
                    static fn (string $code, array $f) => ['code' => $code, 'label' => $f[0], 'count' => array_sum(array_intersect_key($counts, array_flip($f[1])))],
                    array_keys($filters),
                    $filters,
                ),
                'all_count'   => array_sum($counts),
                'next_cursor' => count($rows) === $limit && $last ? (string) $last['id'] : null,
            ],
        );
    }

    /** GET /v1/community/posts/{id} */
    public function show(Request $request): Response
    {
        $post = $this->findOrFail((string) $request->param('id'));
        $viewerId = $request->attributes['user_id'] ?? null;

        return Response::success(PostPresenter::post($post, $this->posts->related([$post], $viewerId), $viewerId));
    }

    /**
     * POST /v1/community/posts — « Créer un post »
     * Les photos / vidéos sont envoyées avant via POST /v1/media, puis référencées par media_ids.
     */
    public function create(Request $request): Response
    {
        $limits = (array) Config::get('community.limits');
        $json = $request->json();
        // Spec v1 : { kind, text, media: [uploadId], pollOptions: [] }
        $kinds = ['text' => 'text', 'poll' => 'poll', 'photos' => 'photos', 'photoCaption' => 'photo', 'photo_caption' => 'photo', 'video' => 'video'];
        if (isset($json['kind']) && !isset($json['type'])) {
            $json['type'] = $kinds[$json['kind']] ?? $json['kind'];
        }
        $json['body'] ??= $json['text'] ?? null;
        if (isset($json['media']) && !isset($json['media_ids'])) {
            $json['media_ids'] = $json['media'];
        }
        unset($json['kind'], $json['text'], $json['media']);
        // Filtre automatique : un texte refusé n'est pas publié
        if (\App\Modules\Moderation\ContentFilter::check(is_string($json['body']) ? $json['body'] : null) !== null) {
            throw new ValidationException(['text' => 'Ce message ne respecte pas les règles de la communauté.']);
        }
        $data = Validator::validate($json, [
            'type' => 'required|in:' . implode(',', array_keys((array) Config::get('community.types'))),
            'body' => 'nullable|string|max:' . $limits['body_max'],
        ]);
        $type = $data['type'];
        $body = $data['body'] ?? null;
        $userId = $request->attributes['user_id'];

        $options = [];
        $mediaIds = [];
        $errors = [];
        $mediaInput = isset($json['media_ids']) && is_array($json['media_ids']) ? $json['media_ids'] : [];

        switch ($type) {
            case 'text':
                if ($body === null) {
                    $errors['body'] = 'Écris quelque chose.';
                }
                break;

            case 'poll':
                if ($body === null || mb_strlen($body) > 300) {
                    $errors['body'] = 'Pose ta question (300 caractères max).';
                }
                $raw = isset($json['poll_options']) && is_array($json['poll_options']) ? $json['poll_options'] : [];
                $options = array_values(array_unique(array_filter(array_map(
                    static fn ($o) => is_string($o) ? mb_substr(trim($o), 0, 80) : '',
                    $raw,
                ), static fn ($o) => $o !== '')));
                if (count($options) < $limits['poll_options_min'] || count($options) > $limits['poll_options_max']) {
                    $errors['poll_options'] = "Entre {$limits['poll_options_min']} et {$limits['poll_options_max']} réponses différentes.";
                }
                break;

            case 'photos':
            case 'photo':
            case 'video':
                [$kind, $min, $max] = match ($type) {
                    'photos' => ['image', 1, (int) $limits['photos_max']],
                    'photo'  => ['image', 1, 1],
                    'video'  => ['video', 1, 1],
                };
                if (count($mediaInput) < $min || count($mediaInput) > $max) {
                    $errors['media_ids'] = $max === 1 ? 'Ajoute un fichier.' : "Ajoute entre {$min} et {$max} photos.";
                    break;
                }
                $mediaIds = array_map(static fn ($m) => (int) $m['id'], (new MediaStore())->claim($mediaInput, $kind, $userId, 'media_ids'));
                break;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $neighborhoodId = Database::fetch('SELECT neighborhood_id FROM users WHERE id = :u', ['u' => $userId])['neighborhood_id'] ?? null;
        $postId = $this->posts->create($userId, $type, $body, $neighborhoodId === null ? null : (int) $neighborhoodId, $options, $mediaIds);
        $post = Database::fetch('SELECT uid FROM community_posts WHERE id = :id', ['id' => $postId]);

        Events::dispatch('community.post_created', ['user_id' => $userId, 'post_uid' => $post['uid'], 'type' => $type]);

        $created = $this->posts->find($post['uid']);

        return Response::success(PostPresenter::post($created, $this->posts->related([$created], $userId), $userId), 201);
    }

    /** DELETE /v1/community/posts/{id} — par son auteur */
    public function delete(Request $request): Response
    {
        $post = $this->findOrFail((string) $request->param('id'));
        if ((int) $post['user_id'] !== $request->attributes['user_id']) {
            throw HttpException::forbidden('not_author', 'Seul l\'auteur peut supprimer cette publication.');
        }
        $this->posts->delete((int) $post['id']);

        return Response::noContent();
    }

    /** PUT /v1/community/posts/{id}/like — idempotent */
    public function like(Request $request): Response
    {
        $post = $this->findOrFail((string) $request->param('id'));
        $this->posts->like((int) $post['id'], $request->attributes['user_id']);

        return $this->likeState($post['uid'], true);
    }

    /** DELETE /v1/community/posts/{id}/like */
    public function unlike(Request $request): Response
    {
        $post = $this->findOrFail((string) $request->param('id'));
        $this->posts->unlike((int) $post['id'], $request->attributes['user_id']);

        return $this->likeState($post['uid'], false);
    }

    /** PUT /v1/community/posts/{id}/vote {option_id} — un vote définitif par personne */
    public function vote(Request $request): Response
    {
        $post = $this->findOrFail((string) $request->param('id'));
        if ($post['type'] !== 'poll') {
            throw new HttpException(409, 'not_a_poll', 'Cette publication n\'est pas un sondage.');
        }
        $json = $request->json();
        // Spec v1 : { optionIndex } (position de la réponse) ; option_id reste accepté
        if (isset($json['option_index']) && is_int($json['option_index'])) {
            $json['option_id'] = (int) (Database::fetch(
                'SELECT id FROM community_poll_options WHERE post_id = :p ORDER BY position LIMIT 1 OFFSET ' . max(0, $json['option_index']), ['p' => $post['id']],
            )['id'] ?? 0);
        }
        $data = Validator::validate($json, ['option_id' => 'required|integer']);
        $option = Database::fetch('SELECT id FROM community_poll_options WHERE id = :o AND post_id = :p', ['o' => $data['option_id'], 'p' => $post['id']]);
        if ($option === null) {
            throw new ValidationException(['option_id' => 'Réponse inconnue pour ce sondage.']);
        }

        $userId = $request->attributes['user_id'];
        if (!$this->posts->vote((int) $post['id'], (int) $option['id'], $userId)) {
            throw new HttpException(409, 'already_voted', 'Tu as déjà voté à ce sondage.');
        }
        Events::dispatch('community.poll_voted', ['user_id' => $userId, 'post_uid' => $post['uid']]);

        $related = $this->posts->related([$post], $userId);

        return Response::success(PostPresenter::poll($related['options'][(int) $post['id']] ?? [], (int) $option['id'], (int) $post['user_id'] === $userId));
    }

    /** GET /v1/community/posts/{id}/comments?cursor=<id> — du plus ancien au plus récent */
    public function comments(Request $request): Response
    {
        $post = $this->findOrFail((string) $request->param('id'));
        $q = $request->query();
        $limit = max(1, min(100, (int) ($q['limit'] ?? 30)));
        $rows = $this->posts->comments((int) $post['id'], $limit, isset($q['cursor']) && ctype_digit((string) $q['cursor']) ? (int) $q['cursor'] : null);
        $viewerId = $request->attributes['user_id'] ?? null;
        $last = end($rows);

        return Response::success(
            array_map(static fn (array $c) => PostPresenter::comment($c, $viewerId), $rows),
            200,
            ['next_cursor' => count($rows) === $limit && $last ? (string) $last['id'] : null],
        );
    }

    /** POST /v1/community/posts/{id}/comments {body} */
    public function addComment(Request $request): Response
    {
        $post = $this->findOrFail((string) $request->param('id'));
        $json = $request->json();
        $json['body'] ??= $json['text'] ?? $json['message'] ?? null;   // spec v1 : « message »
        $data = Validator::validate(['body' => $json['body']], ['body' => 'required|string|min:1|max:' . Config::get('community.limits.comment_max', 500)]);
        if (\App\Modules\Moderation\ContentFilter::check($data['body']) !== null) {
            throw new ValidationException(['text' => 'Ce commentaire ne respecte pas les règles de la communauté.']);
        }
        $userId = $request->attributes['user_id'];

        $comment = $this->posts->addComment((int) $post['id'], $userId, $data['body']);
        Events::dispatch('community.comment_created', ['user_id' => $userId, 'post_uid' => $post['uid'], 'post_author_id' => (int) $post['user_id']]);

        return Response::success(PostPresenter::comment($comment, $userId), 201);
    }

    /** DELETE /v1/community/comments/{id} — par son auteur */
    public function deleteComment(Request $request): Response
    {
        if (!$this->posts->deleteComment((string) $request->param('id'), $request->attributes['user_id'])) {
            throw new HttpException(404, 'comment_not_found', 'Commentaire introuvable.');
        }

        return Response::noContent();
    }

    /** POST /v1/community/posts/{id}/report {reason, details?} */
    public function report(Request $request): Response
    {
        $post = $this->findOrFail((string) $request->param('id'));
        $data = Validator::validate($request->json(), [
            'reason'  => 'required|in:spam,harassment,hate,inappropriate,false_info,other',
            'details' => 'nullable|string|max:500',
        ]);
        if ($this->posts->report((int) $post['id'], $request->attributes['user_id'], $data['reason'], $data['details'] ?? null)) {
            \App\Core\Logger::security('Publication masquée après signalements', ['post' => $post['uid']]);
        }

        return Response::success(['reported' => true, 'message' => 'Merci, notre équipe va examiner cette publication.']);
    }

    private function likeState(string $uid, bool $liked): Response
    {
        $count = Database::fetch('SELECT likes_count FROM community_posts WHERE uid = :u', ['u' => $uid]);

        return Response::success(['id' => $uid, 'is_liked' => $liked, 'likes_count' => (int) ($count['likes_count'] ?? 0)]);
    }

    private function findOrFail(string $id): array
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) || ($post = $this->posts->find($id)) === null) {
            throw new HttpException(404, 'post_not_found', 'Publication introuvable.');
        }

        return $post;
    }
}
