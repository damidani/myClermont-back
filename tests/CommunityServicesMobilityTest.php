<?php

declare(strict_types=1);

use App\Content\Media\MediaStore;
use App\Modules\Community\PostPresenter;
use App\Modules\Services\ServicePresenter;

/** JPEG minimal valide (1×1) avec un segment EXIF contenant une fausse position GPS. */
function jpeg_with_exif(): string
{
    $exif = 'Exif' . "\0\0" . 'GPSLatitude=45.7772;GPSLongitude=3.0870';
    $app1 = "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif;
    $base = base64_decode(
        '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////'
        . 'wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAA//EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AN//Z'
    );

    return substr($base, 0, 2) . $app1 . substr($base, 2);
}

return [
    'médias : type détecté sur le contenu' => function () {
        assert_same('image/jpeg', MediaStore::detect("\xFF\xD8\xFF\xE0..."));
        assert_same('image/png', MediaStore::detect("\x89PNG\r\n\x1A\n...."));
        assert_same('video/mp4', MediaStore::detect("\0\0\0\x18ftypisom...."));
        assert_same('video/quicktime', MediaStore::detect("\0\0\0\x14ftypqt  ...."));
        assert_same(null, MediaStore::detect('<?php echo 1;'));
        assert_same(null, MediaStore::detect('GIF89a...'));
    },

    'médias : position GPS (EXIF) retirée des JPEG, image intacte' => function () {
        $jpeg = jpeg_with_exif();
        assert_true(str_contains($jpeg, 'GPSLatitude'));
        $clean = MediaStore::stripJpegMetadata($jpeg);
        assert_true(!str_contains($clean, 'GPSLatitude'), 'EXIF supprimé');
        assert_true(str_contains($clean, 'JFIF'), 'APP0 conservé');
        assert_true(@getimagesizefromstring($clean) !== false, 'image toujours lisible');
    },

    'médias : métadonnées PNG retirées' => function () {
        $chunk = static fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $png = "\x89PNG\r\n\x1A\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 0, 0, 0, 0)) . $chunk('tEXt', "GPS\0" . '45.77,3.08')
            . $chunk('IDAT', gzcompress("\0\0")) . $chunk('IEND', '');
        $clean = MediaStore::stripPngMetadata($png);
        assert_true(!str_contains($clean, '45.77'));
        assert_true(str_contains($clean, 'IEND'));
    },

    'sondage (spec v1) : options, votes, myVote = index de mon choix' => function () {
        $options = [['id' => 1, 'label' => 'Concert', 'votes_count' => 3], ['id' => 2, 'label' => 'Randonnée', 'votes_count' => 1]];
        assert_same(['options' => ['Concert', 'Randonnée'], 'votes' => [3, 1], 'my_vote' => null, 'option_ids' => [1, 2]], PostPresenter::poll($options, null, false));
        assert_same(1, PostPresenter::poll($options, 2, false)['my_vote']);
    },

    'avatar : initiales et couleur stables' => function () {
        assert_same('AC', PostPresenter::author('u1', 'Alex.Clermont', 'Centre-ville', false)['initials']);
        assert_same('J', PostPresenter::author('u2', 'Julie', null, false)['initials']);
        assert_same(PostPresenter::author('u2', 'Julie', null, false)['color_index'], PostPresenter::author('u2', 'Julie', null, true)['color_index']);
    },

    'services : note vérifiée prioritaire sur la note indicative' => function () {
        $verified = ServicePresenter::rating(['rating_count' => 3, 'rating_avg' => '4.3', 'display_rating' => '4.8', 'display_rating_label' => 'démo']);
        assert_same([4.3, true, '3 avis'], [$verified['value'], $verified['is_verified'], $verified['label']]);
        $demo = ServicePresenter::rating(['rating_count' => 0, 'rating_avg' => null, 'display_rating' => '4.8', 'display_rating_label' => 'note de démonstration']);
        assert_same([4.8, false, 'note de démonstration'], [$demo['value'], $demo['is_verified'], $demo['label']]);
        assert_same(null, ServicePresenter::rating(['rating_count' => 0, 'rating_avg' => null, 'display_rating' => null, 'display_rating_label' => null]));
    },

    'communauté : publier et réagir exigent un compte' => function () {
        seed_device();
        foreach (['POST /v1/community/posts', 'POST /v1/media', 'PUT /v1/community/posts/0e8b3c4a-1111-4222-8333-444455556666/like'] as $route) {
            [$m, $p] = explode(' ', $route);
            assert_same('unauthenticated', err(app()->handle(signed_request($m, $p, '{}'))), $route);
        }
    },

    'mobilité : coordonnées validées, service non configuré => 503' => function () {
        seed_device();
        $res = app()->handle(signed_request('GET', '/v1/mobility/journeys'));
        assert_same('validation_failed', err($res));

        $req = static function (string $query) {
            $ts = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $sig = App\Security\Signature::sign(DEVICE_SECRET, App\Security\Signature::stringToSign('GET', '/v1/mobility/journeys', $query, $ts, $nonce, DEVICE_UID, ''));
            return new App\Core\Request('GET', '/v1/mobility/journeys', $query, ['x-device-id' => DEVICE_UID, 'x-device-token' => DEVICE_TOKEN, 'x-timestamp' => $ts, 'x-nonce' => $nonce, 'x-signature' => $sig], '', '127.0.0.1');
        };
        assert_same('from', app()->handle($req('from=48.85,2.35&to=45.77,3.08'))->payload['error']['details']['field'], 'Paris hors zone');
        assert_same('mobility_unavailable', err(app()->handle($req('from=45.7773,3.0816&to=45.7789,3.1001'))));
    },

    'mobilité : info trafic indique « non disponible » sans clé' => function () {
        seed_device();
        $res = app()->handle(signed_request('GET', '/v1/mobility/traffic'));
        assert_same(200, $res->status);
        assert_same(false, $res->payload['meta']['available'] ?? $res->payload['available'] ?? null);
    },
];
