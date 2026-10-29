<?php

declare(strict_types=1);

namespace App\Content\Uploads;

use App\Content\Media\MediaStore;
use App\Exceptions\HttpException;

/**
 * Préparation des images et vidéos envoyées (spec v1 › Envoi de fichiers) :
 *  - images réduites à 1 600 px de large au plus et réencodées (ce qui retire toutes les métadonnées, position GPS comprise) ;
 *  - HEIC (iPhone) converti en JPEG quand l'extension Imagick est présente ;
 *  - sans GD, les métadonnées sont retirées sans réencodage (MediaStore) ;
 *  - durée des vidéos lue dans l'en-tête MP4 / MOV (atome mvhd).
 */
final class ImageProcessor
{
    public const MAX_WIDTH = 1600;

    public static function prepare(string $bytes, string $mime): string
    {
        if ($mime === 'image/heic' || self::isHeic($bytes)) {
            if (!class_exists(\Imagick::class)) {
                throw new HttpException(415, 'unsupported_media', 'Envoie la photo en JPEG (format HEIC non pris en charge par le serveur).');
            }
            $img = new \Imagick();
            $img->readImageBlob($bytes);
            $img->setImageFormat('jpeg');
            $img->stripImage();
            $bytes = $img->getImageBlob();
            $mime = 'image/jpeg';
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !function_exists('imagecreatefromstring')) {
            return $mime === 'image/webp' ? self::stripWebpMetadata($bytes) : $bytes;
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            throw new HttpException(415, 'unsupported_media', 'Image illisible.');
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w > self::MAX_WIDTH) {
            $nh = (int) round($h * self::MAX_WIDTH / $w);
            $dst = imagecreatetruecolor(self::MAX_WIDTH, $nh);
            if ($mime !== 'image/jpeg') {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, self::MAX_WIDTH, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }
        ob_start();
        match ($mime) {
            'image/png'  => imagepng($src, null, 6),
            'image/webp' => imagewebp($src, null, 82),
            default      => imagejpeg($src, null, 82),
        };
        imagedestroy($src);

        return (string) ob_get_clean();
    }

    /** Durée en secondes d'une vidéo MP4 / MOV, ou null si l'en-tête est introuvable. */
    public static function videoDuration(string $bytes): ?float
    {
        $pos = strpos($bytes, 'mvhd');
        if ($pos === false || $pos + 32 > strlen($bytes)) {
            return null;
        }
        $version = ord($bytes[$pos + 4]);
        if ($version === 1) {
            $timescale = unpack('N', substr($bytes, $pos + 24, 4))[1];
            $duration = unpack('J', substr($bytes, $pos + 28, 8))[1];
        } else {
            $timescale = unpack('N', substr($bytes, $pos + 16, 4))[1];
            $duration = unpack('N', substr($bytes, $pos + 20, 4))[1];
        }

        return $timescale > 0 ? $duration / $timescale : null;
    }

    /** Retire les blocs EXIF et XMP d'un WebP (conteneur RIFF). */
    public static function stripWebpMetadata(string $webp): string
    {
        if (substr($webp, 0, 4) !== 'RIFF' || substr($webp, 8, 4) !== 'WEBP') {
            return $webp;
        }
        $out = '';
        for ($i = 12; $i + 8 <= strlen($webp);) {
            $type = substr($webp, $i, 4);
            $len = unpack('V', substr($webp, $i + 4, 4))[1];
            $chunk = substr($webp, $i, 8 + $len + ($len & 1));
            if ($type === 'VP8X') {
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C); // drapeaux EXIF / XMP retirés
            }
            if (!in_array($type, ['EXIF', 'XMP '], true)) {
                $out .= $chunk;
            }
            $i += 8 + $len + ($len & 1);
        }

        return 'RIFF' . pack('V', strlen($out) + 4) . 'WEBP' . $out;
    }

    private static function isHeic(string $bytes): bool
    {
        return substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['heic', 'heix', 'mif1', 'msf1', 'hevc'], true);
    }
}
