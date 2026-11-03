<?php

declare(strict_types=1);

namespace App\Modules\Pro;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Exceptions\HttpException;
use App\Security\Crypto;

/**
 * Validation des inscriptions commerçant par l'équipe (ca817cb4) :
 *   a_verifier (SIRET manquant) → en_cours (attend l'équipe) ⇄ complement (question de l'équipe) → verifie | refuse.
 * Journal (history) : soumise, siret_ajoute, siret_controle, complement_demande, complement_envoye, validee, refusee.
 */
final class Verification
{
    public const REFUSALS = ['siret_invalide' => 'SIRET invalide', 'etablissement_ferme' => 'Établissement fermé', 'hors_metropole' => 'Hors de Clermont Auvergne Métropole',
                             'doublon' => 'Établissement déjà inscrit', 'non_habilite' => 'Personne non habilitée', 'activite_non_eligible' => 'Activité non éligible', 'autre' => 'Autre motif'];

    /** Communes de Clermont Auvergne Métropole */
    public const METROPOLE = ['clermont-ferrand', 'aubiere', 'aulnat', 'beaumont', 'blanzat', 'cebazat', 'chamalieres', 'chateaugay', 'cournon-d\'auvergne', 'cournon d\'auvergne',
                              'durtol', 'gerzat', 'le cendre', 'lempdes', 'nohanent', 'orcines', 'perignat-les-sarlieve', 'pont-du-chateau', 'romagnat', 'royat', 'saint-genes-champanelle'];

    /** 403 not_verified sur les actions qui publient (les brouillons restent acceptés) */
    public static function require(Request $request): void
    {
        self::requireBusiness(Pro::business($request));
    }

    public static function requireBusiness(array $b): void
    {
        if ($b['verification'] !== 'verifie') {
            throw new HttpException(403, 'not_verified', 'Ton établissement doit être validé par l\'équipe MyClermont avant de publier. Tu peux continuer à préparer tes contenus.',
                ['verification' => $b['verification']]);
        }
    }

    public static function review(array $b): array
    {
        $r = json_decode((string) ($b['verification_review'] ?? ''), true);

        return (is_array($r) ? $r : []) + ['sirene' => null, 'claim' => null, 'request' => null, 'refusal' => null, 'history' => []];
    }

    public static function save(int $businessId, array $review, ?string $status = null, bool $decided = false): void
    {
        $sets = ['verification_review = :r', 'updated_at = :d'];
        $params = ['r' => json_encode($review, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'd' => gmdate('Y-m-d H:i:s'), 'id' => $businessId];
        if ($status !== null) {
            $sets[] = 'verification = :v';
            $params['v'] = $status;
        }
        if ($status === 'en_cours') {
            $sets[] = 'verification_submitted_at = COALESCE(verification_submitted_at, :s)';
            $params['s'] = gmdate('Y-m-d H:i:s');
        }
        if ($decided) {
            $sets[] = 'verification_decided_at = :dd';
            $params['dd'] = gmdate('Y-m-d H:i:s');
        }
        Database::execute('UPDATE pro_businesses SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }

    /** Entrée du journal */
    public static function log(array &$review, string $kind, string $name, string $action, ?string $message = null, ?string $reason = null, array $attachments = []): void
    {
        $review['history'][] = array_filter(['id' => Crypto::uuid4(), 'at' => gmdate('Y-m-d\TH:i:s\Z'), 'actor' => ['kind' => $kind, 'name' => $name], 'action' => $action,
            'message' => $message, 'reason' => $reason, 'attachments' => $attachments ?: null], static fn ($v) => $v !== null);
    }

    /** Contrôle SIRENE enregistré dans la revue (SireneCheck) */
    public static function sirene(string $siret): ?array
    {
        if ($siret === '') {
            return null;
        }
        $found = Sirene::lookup($siret);

        return ['checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'found' => $found !== null, 'active' => (bool) ($found['active'] ?? false), 'legal_name' => $found['name'] ?? null,
                'naf_code' => $found['naf'] ?? null, 'naf_label' => $found['naf_label'] ?? null, 'address' => $found['address'] ?? null, 'created_on' => $found['created_on'] ?? null];
    }

    /** Signaux calculés pour l'équipe (jamais montrés au commerçant) */
    public static function signals(array $b, ?array $place, ?array $owner): array
    {
        $review = self::review($b);
        $s = $review['sirene'];
        $out = [];
        $out[] = $b['siret'] === '' ? ['key' => 'siret', 'level' => 'warn', 'label' => 'SIRET manquant', 'detail' => 'Le commerçant ne l\'a pas encore renseigné.']
            : (!($s['found'] ?? false) ? ['key' => 'siret', 'level' => 'danger', 'label' => 'SIRET introuvable', 'detail' => 'Absent de l\'annuaire des entreprises.']
            : (($s['active'] ?? false) ? ['key' => 'siret', 'level' => 'ok', 'label' => 'SIRET actif', 'detail' => (string) ($s['legal_name'] ?? '')]
            : ['key' => 'siret', 'level' => 'danger', 'label' => 'Établissement fermé', 'detail' => 'L\'annuaire indique une cessation d\'activité.']));
        $city = \App\Modules\Assistant\Nearby::plain((string) ($place['city'] ?? ''));
        $out[] = in_array($city, self::METROPOLE, true) ? ['key' => 'metropole', 'level' => 'ok', 'label' => 'Dans la métropole', 'detail' => (string) $place['city']]
            : ['key' => 'metropole', 'level' => 'warn', 'label' => 'Hors métropole ?', 'detail' => (string) ($place['city'] ?? 'Commune inconnue')];
        $dup = Database::fetch("SELECT trade_name FROM pro_businesses WHERE id <> :id AND deleted_at IS NULL AND ((siret <> '' AND siret = :s) OR trade_name = :t) LIMIT 1",
            ['id' => $b['id'], 's' => $b['siret'], 't' => $b['trade_name']]);
        $out[] = $dup ? ['key' => 'doublon', 'level' => 'danger', 'label' => 'Doublon possible', 'detail' => 'Déjà inscrit : ' . $dup['trade_name']]
            : ['key' => 'doublon', 'level' => 'ok', 'label' => 'Pas de doublon', 'detail' => ''];
        $domain = strtolower((string) substr(strrchr((string) ($owner['email'] ?? ''), '@') ?: '', 1));
        $site = strtolower((string) parse_url((string) (json_decode((string) ($place['pro'] ?? ''), true)['website'] ?? ''), PHP_URL_HOST));
        $webmail = in_array($domain, ['gmail.com', 'yahoo.fr', 'hotmail.fr', 'hotmail.com', 'outlook.fr', 'outlook.com', 'orange.fr', 'free.fr', 'sfr.fr', 'laposte.net', 'icloud.com', 'wanadoo.fr'], true);
        $out[] = $site !== '' && str_ends_with($site, $domain) ? ['key' => 'email', 'level' => 'ok', 'label' => 'E-mail du domaine du site', 'detail' => $domain]
            : ['key' => 'email', 'level' => $webmail ? 'warn' : 'ok', 'label' => $webmail ? 'Adresse personnelle' : 'E-mail professionnel', 'detail' => $domain];

        return $out;
    }

    /** Validation par l'équipe : verifie, e-mail, photos en attente de son repère transférées au commerçant */
    public static function approve(array $b, string $actor): void
    {
        if ($b['siret'] === '') {
            throw new HttpException(422, 'siret_missing', 'Le commerçant n\'a pas encore renseigné son SIRET.');
        }
        if (in_array($b['verification'], ['verifie', 'refuse'], true)) {
            throw new HttpException(409, 'already_decided', 'Cette inscription a déjà été traitée.');
        }
        $review = self::review($b);
        self::log($review, 'admin', $actor, 'validee');
        $review['request'] = null;
        self::save((int) $b['id'], $review, 'verifie', true);
        if ($b['place_id'] !== null) {
            Database::execute("UPDATE place_visitor_photos SET business_id = :b WHERE place_id = :p AND business_id IS NULL AND status = 'pending'", ['b' => $b['id'], 'p' => $b['place_id']]);
        }
        Pro::notify((int) $b['id'], 'moderation', 'Ton établissement est validé', 'Tu peux maintenant publier ta fiche, tes bons plans et tes événements.', '/fiche');
        self::mailOwner((int) $b['id'], 'Ton établissement est validé', "Bonne nouvelle : l'équipe MyClermont a validé « {$b['trade_name']} ».\nTu peux maintenant publier ta fiche, tes bons plans et tes événements depuis ton espace commerçant.");
    }

    public static function requestInfo(array $b, string $actor, string $message): void
    {
        if (in_array($b['verification'], ['verifie', 'refuse'], true)) {
            throw new HttpException(409, 'already_decided', 'Cette inscription a déjà été traitée.');
        }
        $review = self::review($b);
        $review['request'] = ['message' => $message, 'asked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'by' => $actor];
        self::log($review, 'admin', $actor, 'complement_demande', $message);
        self::save((int) $b['id'], $review, 'complement');
        Pro::notify((int) $b['id'], 'moderation', 'L\'équipe a besoin d\'un complément', $message, '/parametres');
        self::mailOwner((int) $b['id'], 'MyClermont : un complément pour valider ton établissement', "L'équipe MyClermont a une question sur « {$b['trade_name']} » :\n\n{$message}\n\nRéponds depuis ton espace commerçant (Paramètres).");
    }

    public static function reject(array $b, string $actor, string $reason, string $message): void
    {
        if (in_array($b['verification'], ['verifie', 'refuse'], true)) {
            throw new HttpException(409, 'already_decided', 'Cette inscription a déjà été traitée.');
        }
        $review = self::review($b);
        $review['refusal'] = ['reason' => $reason, 'message' => $message, 'at' => gmdate('Y-m-d\TH:i:s\Z')];
        $review['request'] = null;
        self::log($review, 'admin', $actor, 'refusee', $message !== '' ? $message : null, $reason);
        self::save((int) $b['id'], $review, 'refuse', true);
        $text = self::REFUSALS[$reason] . ($message !== '' ? " : {$message}" : '.');
        Pro::notify((int) $b['id'], 'moderation', 'Ton inscription n\'a pas été validée', $text, '/parametres');
        self::mailOwner((int) $b['id'], 'MyClermont : ton inscription n\'a pas été validée', "L'équipe MyClermont n'a pas pu valider « {$b['trade_name']} ».\nMotif : {$text}\n\nTon compte est conservé ; réponds à cet e-mail si tu penses qu'il s'agit d'une erreur.");
    }

    /** Échéance : 48 h ouvrées (lundi–vendredi) après la demande */
    public static function dueAt(?string $submittedUtc): ?string
    {
        if ($submittedUtc === null) {
            return null;
        }
        $t = (new \DateTimeImmutable($submittedUtc . ' UTC'))->setTimezone(new \DateTimeZone('Europe/Paris'));
        for ($left = 48; $left > 0; $t = $t->modify('+1 hour')) {
            if ((int) $t->format('N') <= 5) {
                $left--;
            }
        }

        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /** Lien vers la demande dans l'espace /admin du dashboard */
    public static function adminLink(array $b): string
    {
        return rtrim((string) Config::get('pro.front_url', 'https://commercant.myclermont.app'), '/') . '/admin/inscriptions?id=' . $b['uid'];
    }

    /** E-mail à l'équipe (admins du back-office) */
    public static function mailTeam(string $subject, string $body): void
    {
        foreach (Database::fetchAll("SELECT email FROM admin_users WHERE status = 'active' AND role = 'admin'") as $a) {
            Pro::mail($a['email'], $subject, $body);
        }
    }

    /** E-mail au propriétaire de l'établissement */
    public static function mailOwner(int $businessId, string $subject, string $body): void
    {
        $owner = Database::fetch("SELECT email FROM pro_members WHERE business_id = :b AND role = 'proprietaire' AND status = 'actif' LIMIT 1", ['b' => $businessId]);
        if ($owner) {
            Pro::mail($owner['email'], $subject, $body);
        }
    }
}
