<?php

declare(strict_types=1);

namespace App\Modules\Wallet;

use App\Core\Config;
use App\Exceptions\HttpException;

/**
 * Fabrique un .pkpass signé (format Apple Wallet) : pass.json, images, manifest.json (SHA-1), signature PKCS#7 détachée.
 * Sans certificat configuré : 503 not_configured (l'app masque alors le bouton « Ajouter à Apple Wallet »).
 */
final class PassKit
{
    public static function configured(): bool
    {
        return is_file((string) Config::get('wallet.cert')) && is_file((string) Config::get('wallet.wwdr'));
    }

    /** @param array $pass contenu propre au type (eventTicket, coupon…) et champs de base */
    public static function build(array $pass): string
    {
        if (!self::configured()) {
            throw new HttpException(503, 'not_configured', 'Apple Wallet n\'est pas encore disponible.');
        }
        $json = json_encode($pass + [
            'formatVersion' => 1, 'passTypeIdentifier' => Config::get('wallet.pass_type_id'), 'teamIdentifier' => Config::get('wallet.team_id'),
            'organizationName' => Config::get('wallet.organization'), 'backgroundColor' => 'rgb(245,33,92)', 'foregroundColor' => 'rgb(255,255,255)', 'labelColor' => 'rgb(255,230,236)',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $files = ['pass.json' => $json];
        foreach (glob(dirname(__DIR__, 3) . '/resources/wallet/*.png') ?: [] as $img) {
            $files[basename($img)] = (string) file_get_contents($img);
        }
        $files['manifest.json'] = json_encode(array_map('sha1', $files), JSON_UNESCAPED_SLASHES);
        $files['signature'] = self::sign($files['manifest.json']);

        $tmp = tempnam(sys_get_temp_dir(), 'pkpass');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $data) {
            $zip->addFromString($name, $data);
        }
        $zip->close();
        $out = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $out;
    }

    private static function sign(string $manifest): string
    {
        $dir = sys_get_temp_dir();
        $in = tempnam($dir, 'mf');
        $outFile = tempnam($dir, 'sig');
        file_put_contents($in, $manifest);
        $pem = (string) file_get_contents((string) Config::get('wallet.cert'));
        $ok = openssl_pkcs7_sign($in, $outFile, $pem, [$pem, (string) Config::get('wallet.password')], [], PKCS7_BINARY | PKCS7_DETACHED, (string) Config::get('wallet.wwdr'));
        $smime = (string) file_get_contents($outFile);
        @unlink($in);
        @unlink($outFile);
        if (!$ok) {
            throw new HttpException(503, 'not_configured', 'Signature Apple Wallet impossible : certificat invalide.');
        }
        // S/MIME → DER : la partie signature encodée en base64
        preg_match('/filename="smime\.p7s"\s+(.+?)\s+------/s', $smime, $m);

        return (string) base64_decode(preg_replace('/\s+/', '', $m[1] ?? ''));
    }
}
