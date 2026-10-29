<?php

declare(strict_types=1);

use App\Content\ContentSources;
use App\Content\Import\ImportRunner;
use App\Content\Import\JsonFeedImporter;
use App\Modules\Housing\HousingPresenter;

function demo_listing(array $override = []): array
{
    return $override + [
        'id' => 1, 'uid' => '7d1e2f30-aaaa-4bbb-8ccc-ddddeeeeffff', 'external_url' => null,
        'transaction' => 'rent', 'property_type' => 'apartment', 'title' => 'T2 lumineux proche Jaude',
        'description' => 'Appartement lumineux.', 'price_cents' => 69000, 'charges_cents' => null, 'charges_included' => null,
        'surface_m2' => '44.00', 'rooms' => 2, 'bedrooms' => 1, 'furnished' => 0, 'floor' => null, 'energy_class' => null,
        'available_from' => null, 'features' => 'balcony,equipped_kitchen,near_tram,double_glazing,unknown',
        'district_label' => 'Centre-ville', 'neighborhood_id' => 1, 'neighborhood_name' => 'Centre-ville / Jaude',
        'city' => 'Clermont-Ferrand', 'postal_code' => null, 'latitude' => '45.777500', 'longitude' => '3.081500',
        'image_caption' => "Photos d'illustration · démo", 'notice' => 'Annonce fictive.', 'status' => 'published',
        'published_at' => '2026-10-27 22:00:00', 'cover_url' => 'https://img/1.jpg', 'image_count' => 3,
        'source_slug' => 'myclermont', 'source_kind' => 'internal', 'source_name' => 'myClermont', 'source_logo_url' => null,
        'source_attribution' => null, 'source_contact_mode' => 'internal',
    ];
}

return [
    'carte : prix, localisation, caractéristiques, atouts' => function () {
        $c = HousingPresenter::card(demo_listing());
        assert_same("690\u{00A0}€ / mois", $c['price_label']);
        assert_same(690, $c['price']);
        assert_same('Centre-ville', $c['district']);
        assert_same([44.0, 2, 1], [$c['surface'], $c['rooms'], $c['bedrooms']]);
        assert_same(['Balcon', 'Cuisine équipée', 'Proche tram', 'Double vitrage'], $c['features'], 'code inconnu ignoré');
        assert_same('Location', $c['type']);
        assert_same(null, $c['source'], 'contenu myClermont : pas de mention de source');
    },

    'prix de vente sans « / mois »' => function () {
        assert_same("245\u{202F}000\u{00A0}€", HousingPresenter::priceLabel(24500000, false));
    },

    'fiche : revenus demandés = 3 × loyer, absent pour une vente' => function () {
        $d = HousingPresenter::detail(demo_listing(), ['https://img/1.jpg']);
        assert_same("≈ 2\u{202F}070\u{00A0}€ / mois", $d['income_hint']['label']);
        assert_same(null, HousingPresenter::detail(demo_listing(['transaction' => 'sale']), [])['income_hint']);
        assert_same('form', $d['contact']['mode']);
        assert_true(!array_key_exists('contact_email', $d), 'e-mail de l\'annonceur jamais exposé');
    },

    'annonce importée : le bouton Contacter renvoie vers le site d\'origine' => function () {
        $imported = demo_listing([
            'external_url' => 'https://www.exemple-annonces.fr/annonce/123',
            'source_slug' => 'exemple', 'source_kind' => 'import', 'source_name' => 'Exemple Annonces',
            'source_attribution' => 'Annonce issue de Exemple Annonces', 'source_contact_mode' => 'external',
        ]);
        $d = HousingPresenter::detail($imported, []);
        assert_same('external', $d['contact']['mode']);
        assert_same('https://www.exemple-annonces.fr/annonce/123', $d['contact']['url']);
        assert_same('Contacter sur Exemple Annonces', $d['contact']['label']);
        assert_same('Annonce issue de Exemple Annonces', $d['source']['attribution']);
    },

    'partenaire en mode formulaire, et annonce archivée' => function () {
        $partner = demo_listing(['source_kind' => 'partner', 'source_contact_mode' => 'internal', 'external_url' => 'https://x.fr/1']);
        assert_same('form', ContentSources::contact($partner)['mode']);
        assert_same('none', HousingPresenter::detail(demo_listing(['status' => 'archived']), [])['contact']['mode']);
    },

    'import : flux JSON lu, empreinte stable quel que soit l\'ordre des clés' => function () {
        $file = sys_get_temp_dir() . '/myclermont-feed-' . getmypid() . '.json';
        file_put_contents($file, json_encode(['items' => [['external_id' => 'a1', 'title' => 'T2'], 'ignoré', ['external_id' => 'a2']]]));
        $items = iterator_to_array((new JsonFeedImporter())->fetch(['url' => 'file://' . $file]), false);
        unlink($file);
        assert_same(2, count($items));
        assert_same(ImportRunner::hash(['a' => 1, 'b' => 2]), ImportRunner::hash(['b' => 2, 'a' => 1]));
    },

    'import : URL non HTTPS refusée' => function () {
        try {
            iterator_to_array((new JsonFeedImporter())->fetch(['url' => 'http://insecure.example/feed.json']));
            throw new LogicException('aurait dû échouer');
        } catch (RuntimeException $e) {
            assert_true(str_contains($e->getMessage(), 'HTTPS'));
        }
    },

    'routes logement : identifiant invalide => 404, contact validé avant tout' => function () {
        seed_device();
        $res = app()->handle(signed_request('GET', '/v1/housing/pas-un-uuid'));
        assert_same('listing_not_found', err($res));
        $res = app()->handle(signed_request('POST', '/v1/housing/pas-un-uuid/contact', '{}'));
        assert_same('listing_not_found', err($res));
    },
];
