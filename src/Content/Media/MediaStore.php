<?php

declare(strict_types=1);

namespace App\Content\Media;

use App\Core\Config;
use App\Core\Database;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;

/**
 * Photos et vidéos publiées par les utilisateurs (communauté...).
 *
 *  - stockées dans public/media/AAAA/MM/<uuid>.<ext> et servies directement par Nginx (rapide, cache 30 j)
 *  - nom de fichier aléatoire : impossible de deviner l'URL d'un média non publié
 *  - type vérifié sur le contenu (signature binaire), jamais sur l'extension
 *  - métadonnées JPEG/PNG supprimées (EXIF : position GPS, modèle d'appareil...) sans réencodage
 *  - un média non utilisé sous 24 h est supprimé
 */
final class MediaStore
{
    private const TYPES = [
        'image/jpeg'      => ['image', 'jpg'],
        'image/png'       => ['image', 'png'],
        'video/mp4'       => ['video', 'mp4'],
        'video/quicktime' => ['video', 'mov'],
    ];

    public static function root(): string
    {
        return dirname(__DIR__, 3) . '/public/media';
    }

    public static function url(string $path): string
    {
        return Config::get('app.media_base_url') . '/' . $path;
    }

    public function store(string $bytes, array $device, ?int $userId): array
    {
        $mime = self::detect($bytes)
            ?? throw new HttpException(415, 'unsupported_media', 'Formats acceptés : JPEG, PNG, MP4, MOV.');
        [$kind, $ext] = self::TYPES[$mime];

        $maxImage = (int) Config::get('community.media.max_image_bytes', 10485760);
        if ($kind === 'image' && strlen($bytes) > $maxImage) {
            throw new HttpException(413, 'payload_too_large', 'Photo trop volumineuse (10 Mo max).');
        }

        $width = $height = null;
        if ($kind === 'image') {
            $bytes = $mime === 'image/jpeg' ? self::stripJpegMetadata($bytes) : self::stripPngMetadata($bytes);
            $info = @getimagesizefromstring($bytes);
            if ($info === false) {
                throw new HttpException(415, 'unsupported_media', 'Image illisible.');
            }
            [$width, $height] = $info;
        }

        $uid = Crypto::uuid4();
        $relative = gmdate('Y/m') . '/' . $uid . '.' . $ext;
        $absolute = self::root() . '/' . $relative;
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0755, true) && !is_dir(dirname($absolute))) {
            throw new \RuntimeException('Impossible de créer le dossier des médias.');
        }
        if (file_put_contents($absolute, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new \RuntimeException('Écriture du média impossible.');
        }
        chmod($absolute, 0644);

        Database::execute(
            'INSERT INTO media (uid, kind, mime, device_id, user_id, size_bytes, width, height, path, expires_at, created_at)
             VALUES (:uid, :kind, :mime, :d, :u, :size, :w, :h, :path, :exp, :now)',
            [
                'uid' => $uid, 'kind' => $kind, 'mime' => $mime, 'd' => $device['id'], 'u' => $userId, 'size' => strlen($bytes),
                'w' => $width, 'h' => $height, 'path' => $relative, 'exp' => gmdate('Y-m-d H:i:s', time() + 86400), 'now' => gmdate('Y-m-d H:i:s'),
            ],
        );

        return ['id' => $uid, 'kind' => $kind, 'mime' => $mime, 'url' => self::url($relative), 'width' => $width, 'height' => $height, 'size_bytes' => strlen($bytes)];
    }

    /** Médias de l'utilisateur, non encore utilisés, du bon type. */
    public function claim(array $uids, string $kind, int $userId, string $field): array
    {
        $rows = [];
        foreach (array_values(array_unique($uids)) as $uid) {
            $row = is_string($uid) ? Database::fetch('SELECT id, uid, kind, user_id, attached_at, expires_at FROM media WHERE uid = :u', ['u' => strtolower($uid)]) : null;
            if ($row === null || (int) $row['user_id'] !== $userId || $row['kind'] !== $kind || $row['attached_at'] !== null
                || strtotime($row['expires_at'] . ' UTC') < time()) {
                throw new ValidationException([$field => 'Média introuvable ou expiré : renvoyez le fichier.']);
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function attach(array $mediaIds): void
    {
        foreach ($mediaIds as $id) {
            // Un média publié n'expire plus (il est supprimé avec sa publication)
            Database::execute('UPDATE media SET attached_at = :now, expires_at = \'2999-12-31 00:00:00\' WHERE id = :id', ['now' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
        }
    }

    public function deleteByIds(array $mediaIds): void
    {
        foreach ($mediaIds as $id) {
            $row = Database::fetch('SELECT path FROM media WHERE id = :id', ['id' => $id]);
            if ($row !== null) {
                @unlink(self::root() . '/' . $row['path']);
                Database::execute('DELETE FROM media WHERE id = :id', ['id' => $id]);
            }
        }
    }

    public function purgeUnattached(): int
    {
        $ids = array_column(Database::fetchAll('SELECT id FROM media WHERE attached_at IS NULL AND expires_at < :now LIMIT 1000', ['now' => gmdate('Y-m-d H:i:s')]), 'id');
        $this->deleteByIds($ids);

        return count($ids);
    }

    public static function detect(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF")                                   => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n")                              => 'image/png',
            substr($bytes, 4, 4) === 'ftyp' && substr($bytes, 8, 2) === 'qt'          => 'video/quicktime',
            substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['isom', 'iso2', 'mp41', 'mp42', 'avc1', 'M4V '], true) => 'video/mp4',
            default => null,
        };
    }

    /**
     * Retire les segments APP1..APP15 (EXIF, XMP, GPS...) et COM d'un JPEG.
     * APP0 (JFIF) est conservé ; les données d'image ne sont pas modifiées.
     * Note : l'orientation EXIF est perdue → l'app doit envoyer l'image déjà « droite »
     * (UIImage.jpegData / Bitmap.compress le font naturellement).
     */
    public static function stripJpegMetadata(string $jpeg): string
    {
        $out = "\xFF\xD8";
        $pos = 2;
        $len = strlen($jpeg);

        while ($pos + 4 <= $len) {
            if ($jpeg[$pos] !== "\xFF") {
                throw new HttpException(415, 'unsupported_media', 'JPEG invalide.');
            }
            $marker = ord($jpeg[$pos + 1]);
            if ($marker === 0xDA) { // SOS : début des données d'image, on copie tout le reste
                return $out . substr($jpeg, $pos);
            }
            if ($marker === 0xD8 || ($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01 || $marker === 0xFF) {
                $out .= substr($jpeg, $pos, 2);
                $pos += 2;
                continue;
            }
            $segmentLength = unpack('n', substr($jpeg, $pos + 2, 2))[1];
            $segment = substr($jpeg, $pos, 2 + $segmentLength);
            $isMetadata = ($marker >= 0xE1 && $marker <= 0xEF) || $marker === 0xFE;
            if (!$isMetadata) {
                $out .= $segment;
            }
            $pos += 2 + $segmentLength;
        }

        throw new HttpException(415, 'unsupported_media', 'JPEG incomplet.');
    }

    /** Retire les chunks de métadonnées d'un PNG (eXIf, tEXt, zTXt, iTXt, tIME). */
    public static function stripPngMetadata(string $png): string
    {
        $out = substr($png, 0, 8);
        $pos = 8;
        $len = strlen($png);
        while ($pos + 12 <= $len) {
            $chunkLength = unpack('N', substr($png, $pos, 4))[1];
            $type = substr($png, $pos + 4, 4);
            $total = 12 + $chunkLength;
            if (!in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) {
                $out .= substr($png, $pos, $total);
            }
            $pos += $total;
            if ($type === 'IEND') {
                return $out;
            }
        }

        throw new HttpException(415, 'unsupported_media', 'PNG incomplet.');
    }
}
