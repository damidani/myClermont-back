<?php

declare(strict_types=1);

use App\Content\Documents\DocumentStore;
use App\Core\Request;
use App\Modules\Jobs\JobPresenter;
use App\Security\Signature;

function demo_job(array $override = []): array
{
    return $override + [
        'id' => 1, 'uid' => '5a5a5a5a-1111-4222-8333-444455556666', 'external_url' => null, 'application_url' => null,
        'contract' => 'cdi', 'work_mode' => 'hybrid', 'title' => 'Développeur·se iOS SwiftUI', 'company_name' => 'Studio Volcan · Démo',
        'company_logo_url' => null, 'summary' => "Conception d'interfaces iOS.", 'description' => 'Le poste.',
        'missions' => '["Participer aux projets de l\'équipe", "Collaborer"]', 'profile' => 'Autonomie.',
        'city' => 'Clermont-Ferrand', 'neighborhood_id' => null, 'latitude' => null, 'longitude' => null,
        'salary_min_cents' => 3800000, 'salary_max_cents' => 4500000, 'salary_period' => 'year', 'salary_label' => null,
        'start_date' => null, 'duration_label' => null, 'badge_label' => 'DÉMO', 'image_caption' => "Découvre l'entreprise",
        'notice' => 'Annonce fictive.', 'cover_letter_mode' => 'optional', 'status' => 'published',
        'published_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'expires_at' => null,
        'category_slug' => 'tech', 'category_name' => 'Informatique & numérique', 'category_icon' => 'phone',
        'source_slug' => 'myclermont', 'source_kind' => 'internal', 'source_name' => 'myClermont', 'source_logo_url' => null,
        'source_attribution' => null, 'source_contact_mode' => 'internal',
    ];
}

/** Requête signée avec un corps binaire et un Content-Type. */
function signed_raw(string $method, string $path, string $query, string $body, string $contentType): Request
{
    $ts = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $sig = Signature::sign(DEVICE_SECRET, Signature::stringToSign($method, $path, $query, $ts, $nonce, DEVICE_UID, $body));

    return new Request($method, $path, $query, [
        'x-device-id' => DEVICE_UID, 'x-device-token' => DEVICE_TOKEN, 'x-timestamp' => $ts,
        'x-nonce' => $nonce, 'x-signature' => $sig, 'content-type' => $contentType,
    ], $body, '127.0.0.1');
}

return [
    'carte : contrat, rythme, salaire, NOUVEAU, catégorie' => function () {
        $c = JobPresenter::card(demo_job());
        assert_same("38\u{202F}000–45\u{202F}000\u{00A0}€ / an", $c['salary']['label']);
        assert_same(['CDI', 'Hybride', 'phone', 'DÉMO'], [$c['contract']['label'], $c['work_mode']['label'], $c['category']['icon'], $c['badge']]);
        assert_true($c['is_new'], 'publiée il y a 1 h');
        assert_true(!JobPresenter::card(demo_job(['published_at' => '2020-01-01 00:00:00']))['is_new']);
    },

    'salaires : fourchette, minimum seul, libellé libre, absent' => function () {
        assert_same("À partir de 1\u{202F}800\u{00A0}€ / mois", JobPresenter::salary(demo_job(['salary_max_cents' => null, 'salary_min_cents' => 180000, 'salary_period' => 'month']))['label']);
        assert_same('Selon profil', JobPresenter::salary(demo_job(['salary_label' => 'Selon profil']))['label']);
        assert_same("11,88\u{00A0}€ / heure", JobPresenter::salary(demo_job(['salary_min_cents' => 1188, 'salary_max_cents' => 1188, 'salary_period' => 'hour']))['label']);
        assert_same(null, JobPresenter::salary(demo_job(['salary_min_cents' => null, 'salary_max_cents' => null])));
    },

    'fiche : missions, galerie, bouton Postuler (formulaire)' => function () {
        $d = JobPresenter::detail(demo_job(), ['https://img/1.jpg']);
        assert_same(["Participer aux projets de l'équipe", 'Collaborer'], $d['missions']);
        assert_same(['form', 'required', 'optional'], [$d['apply']['mode'], $d['apply']['cv'], $d['apply']['cover_letter']]);
        assert_true(!array_key_exists('contact_email', $d), 'e-mail recruteur jamais exposé');
    },

    'Postuler : site du recruteur, source importée, offre expirée' => function () {
        assert_same('external', JobPresenter::apply(demo_job(['application_url' => 'https://careers.example/123']))['mode']);
        $imported = demo_job(['external_url' => 'https://emplois.example/o/9', 'source_kind' => 'import', 'source_contact_mode' => 'external', 'source_name' => 'Exemple Emploi']);
        assert_same(['external', 'https://emplois.example/o/9', 'Postuler sur Exemple Emploi'], array_values(array_slice(JobPresenter::apply($imported), 0, 3)));
        assert_same('none', JobPresenter::apply(demo_job(['status' => 'archived']))['mode']);
    },

    'noms de fichiers nettoyés' => function () {
        assert_same('Mon CV 2026.pdf', DocumentStore::cleanFilename('Mon CV 2026.pdf', 'cv'));
        assert_same('passwd.pdf', DocumentStore::cleanFilename('../../etc/passwd', 'cv'), 'chemin retiré');
        assert_same('Lettre de motivation.pdf', DocumentStore::cleanFilename(null, 'cover_letter'));
        assert_same('Lea Dupont.pdf', DocumentStore::cleanFilename('Léa Dupont.pdf', 'cv'));
    },

    'upload : seuls les PDF sont acceptés (signature sur les octets du fichier)' => function () {
        seed_device();
        $res = app()->handle(signed_raw('POST', '/v1/documents', 'kind=cv', 'hello', 'text/plain'));
        assert_same('unsupported_document', err($res));
        $res = app()->handle(signed_raw('POST', '/v1/documents', 'kind=cv', "MZ\x90\x00 faux pdf", 'application/pdf'));
        assert_same('unsupported_document', err($res));
        $res = app()->handle(signed_raw('POST', '/v1/documents', 'kind=photo', '%PDF-1.7', 'application/pdf'));
        assert_same('validation_failed', err($res));
    },

    'routes emploi montées' => function () {
        seed_device();
        assert_same('job_not_found', err(app()->handle(signed_request('GET', '/v1/jobs/abc'))));
        assert_same('unauthenticated', err(app()->handle(signed_request('GET', '/v1/users/me/applications'))));
    },
];
