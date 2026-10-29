<?php

declare(strict_types=1);

use App\Modules\Classifieds\ClassifiedPresenter;
use App\Modules\Messaging\MessagingPresenter;
use App\Modules\Messaging\MessagingService;

function demo_classified(array $override = []): array
{
    return $override + [
        'id' => 1, 'uid' => '1b2c3d4e-aaaa-4bbb-8ccc-ddddeeeeffff', 'user_id' => 11, 'external_url' => null,
        'title' => 'Don de plantes', 'description' => 'Boutures de plantes vertes.', 'price_cents' => 0, 'is_free' => 1,
        'item_condition' => 'good', 'location_label' => 'Montferrand', 'neighborhood_id' => 2, 'latitude' => null, 'longitude' => null,
        'badge_label' => 'DÉMO', 'notice' => 'Aucune transaction réelle.', 'status' => 'active',
        'published_at' => gmdate('Y-m-d H:i:s'), 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
        'category_slug' => 'dons', 'category_name' => 'Dons & gratuit', 'category_icon' => 'gift', 'category_color' => '#4A9A9A',
        'seller_uid' => '22222222-aaaa-4bbb-8ccc-ddddeeeeffff', 'seller_username' => 'Habitant_11', 'seller_since' => '2026-01-01 00:00:00',
        'cover' => null,
        'source_slug' => 'myclermont', 'source_kind' => 'internal', 'source_name' => 'myClermont', 'source_logo_url' => null,
        'source_attribution' => null, 'source_contact_mode' => 'internal',
    ];
}

return [
    'annonce : gratuit, prix, état, visuel par défaut' => function () {
        $c = ClassifiedPresenter::card(demo_classified());
        assert_same(['Gratuit', true, 'Bon état', null, 'gift'], [$c['price']['label'], $c['price']['is_free'], $c['condition']['label'], $c['cover_url'], $c['category']['icon']]);
        assert_same("90\u{00A0}€", ClassifiedPresenter::card(demo_classified(['is_free' => 0, 'price_cents' => 9000]))['price']['label']);
        assert_same("12,50\u{00A0}€", ClassifiedPresenter::priceLabel(1250));
    },

    'annonce : bouton Contacter selon la situation' => function () {
        $detail = static fn (array $o, ?int $viewer) => ClassifiedPresenter::detail(demo_classified($o), [], null, $viewer, ['active' => 1, 'sold' => 0], null)['contact']['mode'];
        assert_same('message', $detail([], 99), 'acheteur → messagerie');
        assert_same('message', $detail([], null), 'non connecté → messagerie (connexion demandée par l\'app)');
        assert_same('none', $detail([], 11), 'sa propre annonce');
        assert_same('none', $detail(['status' => 'sold'], 99), 'vendue');
        assert_same('external', $detail(['user_id' => null, 'external_url' => 'https://ressourcerie.example/a/1', 'source_kind' => 'import', 'source_contact_mode' => 'external', 'source_name' => 'Ressourcerie'], 99));
    },

    'annonce : vendeur et conseils de sécurité' => function () {
        $d = ClassifiedPresenter::detail(demo_classified(), [], null, 99, ['active' => 3, 'sold' => 2], 'conv-uid');
        assert_same(['H1', 3, 2, 'conv-uid'], [$d['seller']['initials'], $d['seller']['active_listings'], $d['seller']['sold_listings'], $d['conversation_id']]);
        assert_same(4, count($d['safety_tips']));
    },

    'messagerie : nettoyage et détection de liens' => function () {
        assert_same("Bonjour\n\nDispo ?", MessagingService::clean("  Bonjour\r\n\n\n\n\u{200B}Dispo ?\x07  "));
        assert_true(MessagingService::containsLink('Paie ici https://pay-secure.xyz/123'));
        assert_true(MessagingService::containsLink('va sur mondial-relay-paiement.com'));
        assert_true(!MessagingService::containsLink('Rendez-vous place de Jaude à 18h ?'));
    },

    'messagerie : dates à la milliseconde, message système' => function () {
        assert_same('2026-10-28T00:21:05Z', MessagingPresenter::date('2026-10-28 00:21:05.123'));
        $m = MessagingPresenter::message(['id' => 5, 'uid' => 'u', 'client_id' => null, 'sender_id' => null, 'body' => 'Annonce vendue', 'has_link' => 0, 'created_at' => '2026-10-28 00:21:05.000'], 1);
        assert_same(['system', false, null], [$m['type'], $m['is_mine'], $m['sender_id']]);
    },

    'routes : messagerie et dépôt exigent un compte, liste publique' => function () {
        seed_device();
        foreach (['GET /v1/conversations', 'GET /v1/conversations/unread-count', 'POST /v1/classifieds', 'POST /v1/classifieds/1b2c3d4e-aaaa-4bbb-8ccc-ddddeeeeffff/contact'] as $route) {
            [$m, $p] = explode(' ', $route);
            assert_same('unauthenticated', err(app()->handle(signed_request($m, $p, '{}'))), $route);
        }
        assert_same('classified_not_found', err(app()->handle(signed_request('GET', '/v1/classifieds/pas-un-id'))));
    },
];
