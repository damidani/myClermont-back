<?php

declare(strict_types=1);

namespace App\Modules\Wallet;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;

/**
 * Cartes Apple Wallet (c06e670d), téléchargées par l'app (application/vnd.apple.pkpass) :
 *   GET /events/{id}/wallet-pass               billet de la sortie (date, lieu, position pour l'écran verrouillé, QR = lien de l'événement)
 *   GET /deals/{id}/wallet-pass                bon plan du compte (compte requis, code déjà obtenu par POST /deals/{id}/redeem) : QR = qrPayload validé en caisse
 *   GET /wallet/status                         { available } : l'app affiche le bouton seulement si true
 */
final class WalletController
{
    public function status(Request $request): Response
    {
        return Response::success(['available' => PassKit::configured()]);
    }

    public function event(Request $request): Response
    {
        $e = Database::fetch("SELECT * FROM events WHERE slug = :s AND status = 'published' AND deleted_at IS NULL", ['s' => (string) $request->param('id')])
            ?? throw new HttpException(404, 'event_not_found', 'Événement introuvable.');
        $start = gmdate('Y-m-d\TH:i:s\Z', strtotime($e['starts_at'] . ' UTC'));
        $paris = (new \DateTimeImmutable($e['starts_at'] . ' UTC'))->setTimezone(new \DateTimeZone('Europe/Paris'));
        $pass = [
            'serialNumber' => 'event-' . $e['slug'], 'description' => $e['title'], 'relevantDate' => $start,
            'eventTicket' => [
                'primaryFields' => [['key' => 'title', 'label' => 'SORTIE', 'value' => $e['title']]],
                'secondaryFields' => [['key' => 'date', 'label' => 'QUAND', 'value' => $start, 'dateStyle' => 'PKDateStyleMedium', 'timeStyle' => $e['all_day'] ? 'PKDateStyleNone' : 'PKDateStyleShort'],
                                      ['key' => 'venue', 'label' => 'OÙ', 'value' => (string) $e['venue_name']]],
                'auxiliaryFields' => array_values(array_filter([$e['price_label'] ? ['key' => 'price', 'label' => 'PRIX', 'value' => $e['price_label']] : null])),
                'backFields' => array_values(array_filter([['key' => 'address', 'label' => 'Adresse', 'value' => (string) $e['address']],
                    $e['summary'] ? ['key' => 'summary', 'label' => 'À propos', 'value' => $e['summary']] : null,
                    $e['link_url'] ? ['key' => 'url', 'label' => 'Site', 'value' => $e['link_url']] : null])),
            ],
            'barcodes' => [['format' => 'PKBarcodeFormatQR', 'message' => 'myclermont://event/' . $e['slug'], 'messageEncoding' => 'iso-8859-1', 'altText' => $paris->format('d/m H\hi')]],
        ];
        if ($e['latitude'] !== null) {
            $pass['locations'] = [['latitude' => (float) $e['latitude'], 'longitude' => (float) $e['longitude'], 'relevantText' => $e['title'] . ' commence bientôt']];
        }

        return self::file(PassKit::build($pass), 'sortie-' . $e['slug']);
    }

    public function deal(Request $request): Response
    {
        $r = Database::fetch('SELECT r.code, r.expires_at, d.uid, d.title, d.merchant, d.discount_label, d.conditions, p.latitude, p.longitude
                              FROM deal_redemptions r JOIN deals d ON d.id = r.deal_id LEFT JOIN places p ON p.id = d.place_id
                              WHERE d.uid = :d AND r.user_id = :u', ['d' => (string) $request->param('id'), 'u' => $request->attributes['user_id']])
            ?? throw new HttpException(404, 'not_found', 'Obtiens d\'abord ton code pour ce bon plan.');
        $pass = [
            'serialNumber' => 'deal-' . $r['uid'] . '-' . $r['code'], 'description' => $r['title'],
            'coupon' => [
                'primaryFields' => [['key' => 'offer', 'label' => (string) $r['merchant'], 'value' => $r['discount_label'] ?: $r['title']]],
                'secondaryFields' => [['key' => 'title', 'label' => 'BON PLAN', 'value' => $r['title']]],
                'auxiliaryFields' => array_values(array_filter([['key' => 'code', 'label' => 'CODE', 'value' => $r['code']],
                    $r['expires_at'] ? ['key' => 'exp', 'label' => 'VALABLE JUSQU\'AU', 'value' => gmdate('Y-m-d\TH:i:s\Z', strtotime($r['expires_at'] . ' UTC')), 'dateStyle' => 'PKDateStyleMedium'] : null])),
                'backFields' => array_values(array_filter([$r['conditions'] ? ['key' => 'cond', 'label' => 'Conditions', 'value' => $r['conditions']] : null])),
            ],
            // Même QR que l'écran du bon plan dans l'app : le commerçant le scanne dans son espace
            'barcodes' => [['format' => 'PKBarcodeFormatQR', 'message' => 'myclermont://deal/' . $r['uid'] . '/' . $r['code'], 'messageEncoding' => 'iso-8859-1', 'altText' => $r['code']]],
        ];
        if ($r['expires_at']) {
            $pass['expirationDate'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($r['expires_at'] . ' UTC'));
        }
        if ($r['latitude'] !== null) {
            $pass['locations'] = [['latitude' => (float) $r['latitude'], 'longitude' => (float) $r['longitude'], 'relevantText' => 'Ton bon plan ' . $r['merchant'] . ' est ici']];
        }

        return self::file(PassKit::build($pass), 'bon-plan-' . $r['code']);
    }

    private static function file(string $bytes, string $name): Response
    {
        return Response::raw($bytes, 'application/vnd.apple.pkpass')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '.pkpass"')->withHeader('Cache-Control', 'private, no-store');
    }
}
