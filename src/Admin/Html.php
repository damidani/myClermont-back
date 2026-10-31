<?php

declare(strict_types=1);

namespace App\Admin;

use App\Core\Config;

/** Rendu HTML du back-office : échappement systématique, champs de formulaire, affichage des valeurs. */
final class Html
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function url(string $path, array $query = []): string
    {
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '' && $v !== []);

        return '/admin' . $path . ($query ? '?' . http_build_query($query) : '');
    }

    /** Rend un gabarit de src/Admin/views (variables extraites, sortie mise en tampon). */
    public static function render(string $view, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require __DIR__ . '/views/' . $view . '.php';
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }

    public static function columnLabel(string $name): string
    {
        static $labels = [
            'id' => 'ID', 'uid' => 'UID', 'name' => 'Nom', 'title' => 'Titre', 'subtitle' => 'Sous-titre', 'status' => 'Statut', 'email' => 'Email',
            'username' => 'Pseudo', 'created_at' => 'Créé le', 'updated_at' => 'Modifié le', 'published_at' => 'Publié le', 'starts_at' => 'Début',
            'ends_at' => 'Fin', 'expires_at' => 'Expire le', 'description' => 'Description', 'category_id' => 'Catégorie', 'source_id' => 'Source',
            'user_id' => 'Utilisateur', 'neighborhood_id' => 'Quartier', 'price_cents' => 'Prix', 'sort_order' => 'Ordre', 'slug' => 'Slug',
            'icon' => 'Icône', 'color' => 'Couleur', 'city' => 'Ville', 'address' => 'Adresse', 'latitude' => 'Latitude', 'longitude' => 'Longitude',
            'image_url' => 'Image', 'cover_url' => 'Image de couverture', 'logo_url' => 'Logo', 'is_active' => 'Actif', 'points' => 'Points',
            'premium_until' => 'Premium jusqu\'au', 'role' => 'Rôle', 'bio' => 'Bio', 'body' => 'Texte', 'comment' => 'Commentaire', 'rating' => 'Note',
            'phone' => 'Téléphone', 'website_url' => 'Site web', 'external_url' => 'Lien d\'origine', 'external_id' => 'ID chez la source',
            'last_synced_at' => 'Dernière synchro', 'tags' => 'Tags', 'keywords' => 'Mots-clés', 'notice' => 'Avertissement', 'badge_label' => 'Badge',
            'message' => 'Message', 'reason' => 'Motif', 'details' => 'Détails', 'tagline' => 'Accroche', 'is_partner' => 'Partenaire',
            'is_must_see' => 'Incontournable', 'is_featured' => 'À la une', 'reports_count' => 'Signalements', 'last_login_at' => 'Dernière connexion',
            'first_name' => 'Prénom', 'display_name' => 'Nom affiché', 'kind' => 'Type', 'venue_name' => 'Lieu', 'app_category' => 'Catégorie (app)',
            'rating_avg' => 'Note moyenne', 'rating_count' => 'Nombre d\'avis', 'merchant' => 'Commerçant', 'category' => 'Catégorie',
            'original_cents' => 'Prix normal', 'reduced_cents' => 'Prix bon plan', 'discount_label' => 'Réduction affichée', 'exclusive_plus' => 'Réservé My Clermont+',
            'conditions' => 'Conditions', 'area' => 'Quartier', 'company_name' => 'Entreprise', 'contract' => 'Contrat', 'work_mode' => 'Rythme',
            'transaction' => 'Type d\'annonce', 'district_label' => 'Quartier', 'surface_m2' => 'Surface (m²)', 'rooms' => 'Pièces', 'bedrooms' => 'Chambres',
            'furnished' => 'Meublé', 'summary' => 'Résumé', 'type' => 'Type', 'likes_count' => 'J\'aime', 'comments_count' => 'Commentaires',
            'post_id' => 'Publication', 'place_id' => 'Lieu', 'place_key' => 'Clé du lieu (app)', 'target_type' => 'Contenu signalé', 'target_id' => 'Identifiant',
            'resolution' => 'Décision', 'platform' => 'Plateforme', 'model' => 'Modèle', 'app_version' => 'Version de l\'app', 'last_seen_at' => 'Vu le',
            'sender' => 'De', 'recipient' => 'À', 'offer_id' => 'Offre', 'listing_id' => 'Logement', 'provider_id' => 'Professionnel',
            'price_label' => 'Prix affiché', 'schedule_label' => 'Horaires', 'age_limit' => 'Âge', 'link_url' => 'Lien', 'ticket_url' => 'Billetterie',
            'all_day' => 'Toute la journée', 'subtitle' => 'Sous-titre', 'postal_code' => 'Code postal', 'gender' => 'Genre', 'pronouns' => 'Pronoms',
            'city_served' => 'Dans la métropole', 'marketing_opt_in' => 'Accepte les actualités', 'consent_version' => 'CGU acceptées',
            'notifications_enabled' => 'Notifications', 'item_condition' => 'État', 'location_label' => 'Lieu', 'is_free' => 'Gratuit',
            'max_redemptions' => 'Utilisations max.', 'redemptions_count' => 'Utilisations', 'deleted_at' => 'Supprimé le', 'starts_at_label' => 'Début',
            'claims_count' => 'Utilisations', 'partner_name' => 'Partenaire', 'code' => 'Code', 'author_user_id' => 'Proposé par', 'nature' => 'Type de nature',
            'demo_name' => 'Nom affiché', 'manager_user_id' => 'Gérant (compte)', 'partner_since' => 'Partenaire depuis', 'partner_until' => 'Partenaire jusqu\'au',
            'last_report' => 'Dernier compte rendu', 'agent' => 'Assistant', 'branch' => 'Branche', 'commit_sha' => 'Commit',
        ];

        return $labels[$name] ?? ucfirst(str_replace('_', ' ', preg_replace('/_(id|cents)$/', '', $name)));
    }

    /** Valeurs ENUM affichées en français (la valeur stockée reste inchangée). */
    public const VALUE_LABELS = [
        'published' => 'Publié', 'draft' => 'Brouillon', 'pending' => 'En attente', 'archived' => 'Archivé', 'cancelled' => 'Annulé',
        'visible' => 'Visible', 'hidden' => 'Masqué', 'deleted' => 'Supprimé', 'active' => 'Actif', 'disabled' => 'Désactivé', 'banned' => 'Banni',
        'blocked' => 'Bloqué', 'reserved' => 'Réservé', 'sold' => 'Vendu', 'expired' => 'Expiré', 'open' => 'Ouvert', 'in_progress' => 'En cours',
        'done' => 'Fait', 'rejected' => 'Refusé', 'sent' => 'Envoyé', 'failed' => 'En échec', 'queued' => 'En file', 'removed' => 'Contenu retiré',
        'dismissed' => 'Rejeté', 'user_banned' => 'Auteur banni', 'user' => 'Utilisateur', 'partner' => 'Partenaire', 'moderator' => 'Modérateur',
        'admin' => 'Administrateur', 'editor' => 'Éditeur', 'rent' => 'Location', 'sale' => 'Vente', 'colocation' => 'Colocation',
        'cdi' => 'CDI', 'cdd' => 'CDD', 'alternance' => 'Alternance', 'stage' => 'Stage', 'freelance' => 'Freelance', 'interim' => 'Intérim',
        'saisonnier' => 'Saisonnier', 'onsite' => 'Sur site', 'hybrid' => 'Hybride', 'remote' => 'Télétravail', 'text' => 'Texte', 'poll' => 'Sondage',
        'photos' => 'Photos', 'photo' => 'Photo', 'video' => 'Vidéo', 'request' => 'Demande', 'reply' => 'Réponse', 'note' => 'Note', 'bug' => 'Bug',
        'report' => 'Compte rendu', 'soiree' => 'Soirée', 'concert' => 'Concert', 'spectacle' => 'Spectacle', 'expo' => 'Expo', 'famille' => 'Famille',
        'sport' => 'Sport', 'marche' => 'Marché', 'festival' => 'Festival', 'spam' => 'Spam', 'scam' => 'Arnaque', 'offensive' => 'Injurieux',
        'harassment' => 'Harcèlement', 'inappropriate' => 'Inapproprié', 'other' => 'Autre', 'post' => 'Publication', 'comment' => 'Commentaire',
        'review' => 'Avis', 'classified' => 'Annonce', 'event' => 'Événement', 'message' => 'Message', 'new' => 'Neuf', 'like_new' => 'Comme neuf',
        'very_good' => 'Très bon état', 'good' => 'Bon état', 'fair' => 'État correct', 'for_parts' => 'À réparer', 'ios' => 'iOS', 'android' => 'Android',
        'apple' => 'Apple', 'google' => 'Google', 'grace' => 'Délai de grâce', 'refunded' => 'Remboursé', 'revoked' => 'Révoqué',
    ];

    public static function valueLabel(string $value): string
    {
        return self::VALUE_LABELS[$value] ?? $value;
    }

    /** Valeur courte pour un tableau de liste. */
    public static function display(array $f, mixed $value, array $labels = []): string
    {
        if ($value === null) {
            return '<span class="muted">—</span>';
        }

        return match ($f['kind']) {
            'bool'     => $value ? '<span class="yes">Oui</span>' : '<span class="muted">Non</span>',
            'money'    => self::e(number_format((int) $value / 100, 2, ',', "\u{202F}") . "\u{00A0}€"),
            'datetime' => self::e(self::localDate((string) $value, 'd/m/Y H:i')),
            'date'     => self::e(self::localDate((string) $value, 'd/m/Y', false)),
            'select'   => '<span class="pill pill-' . self::e($value) . '">' . self::e(self::valueLabel((string) $value)) . '</span>',
            'relation' => isset($labels[(string) $value]) ? self::e(mb_strimwidth($labels[(string) $value], 0, 40, '…')) . ' <span class="muted">#' . self::e($value) . '</span>' : '#' . self::e($value),
            'color'    => '<span class="swatch" data-color="' . self::e($value) . '"></span>' . self::e($value),
            'url'      => self::isImage($f['name']) && str_starts_with((string) $value, 'http')
                ? '<img class="thumb-sm" src="' . self::e($value) . '" alt="" loading="lazy">'
                : self::e(mb_strimwidth((string) $value, 0, 40, '…')),
            default    => self::e(mb_strimwidth(preg_replace('/\s+/', ' ', (string) $value), 0, 80, '…')),
        };
    }

    /** Date UTC de la base → heure locale du back-office. */
    public static function localDate(string $utc, string $format, bool $convert = true): string
    {
        $d = new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));

        return ($convert ? $d->setTimezone(new \DateTimeZone((string) Config::get('admin.timezone', 'Europe/Paris'))) : $d)->format($format);
    }

    /**
     * Champ de formulaire.
     *
     * @param array|null $options options d'une clé étrangère (null = saisie de l'id)
     * @param bool $raw valeur telle que saisie (formulaire renvoyé avec erreurs) : pas de conversion € / fuseau
     */
    public static function field(array $f, mixed $value, ?string $error, ?array $options, array $relationLabel = [], bool $raw = false): string
    {
        $name = $f['name'];
        $id = 'f_' . $name;
        $required = !$f['nullable'] && $f['default'] === null && $f['kind'] !== 'bool';
        $attrs = ' id="' . $id . '" name="' . self::e($name) . '"' . ($required ? ' required' : '') . ($f['readonly'] ? ' disabled' : '');
        $v = $value === null ? '' : (string) $value;

        $input = match ($f['kind']) {
            // Booléen pouvant être NULL (« non renseigné ») : une case à cocher le transformerait en « Non »
            'bool'     => $f['nullable']
                ? self::select($attrs, ['1' => 'Oui', '0' => 'Non'], $value === null || $value === '' ? '' : ((bool) $value ? '1' : '0'), true)
                : '<label class="switch"><input type="checkbox" value="1"' . $attrs . ($value ? ' checked' : '') . '><span></span></label>',
            'select'   => self::select($attrs, array_combine($f['enum'], array_map([self::class, 'valueLabel'], $f['enum'])), $v, $f['nullable'] || $value === null),
            'relation' => $options !== null
                ? self::select($attrs, $options, $v, $f['nullable'] || $value === null)
                : '<input type="number" min="1"' . $attrs . ' value="' . self::e($v) . '">'
                  . ($v !== '' && isset($relationLabel[$v]) ? ' <span class="muted">' . self::e($relationLabel[$v]) . '</span>' : ''),
            'textarea' => '<textarea rows="' . ($f['type'] === 'text' && ($f['length'] ?? 0) < 1000 ? 4 : 8) . '"' . $attrs . '>' . self::e($v) . '</textarea>',
            'json'     => '<textarea class="code" rows="8"' . $attrs . '>' . self::e($v !== '' && !$raw ? json_encode(json_decode($v), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '') . '</textarea>',
            'money'    => '<div class="suffix"><input type="text" inputmode="decimal"' . $attrs . ' value="' . ($v !== '' && !$raw ? self::e(number_format((int) $v / 100, 2, ',', '')) : self::e($v)) . '"><span>€</span></div>',
            'integer'  => '<input type="number"' . $attrs . ' value="' . self::e($v) . '"' . ($f['unsigned'] ? ' min="0"' : '') . '>',
            'decimal'  => '<input type="text" inputmode="decimal"' . $attrs . ' value="' . self::e($v) . '">',
            'date'     => '<input type="date"' . $attrs . ' value="' . self::e($v) . '">',
            'datetime' => '<input type="datetime-local" step="1"' . $attrs . ' value="' . ($v !== '' && !$raw ? self::e(self::localDate($v, 'Y-m-d\TH:i:s')) : self::e($v)) . '">',
            'time'     => '<input type="time" step="1"' . $attrs . ' value="' . self::e($v) . '">',
            'url'      => '<input type="url"' . $attrs . ' value="' . self::e($v) . '" placeholder="https://…">'
                          . (self::isImage($name) && !$f['readonly'] ? '<button type="button" class="btn small" data-upload="' . $id . '">Envoyer une image…</button>'
                          . ($v !== '' ? '<img class="thumb" src="' . self::e($v) . '" alt="" loading="lazy">' : '') : ''),
            'email'    => '<input type="email"' . $attrs . ' value="' . self::e($v) . '">',
            'color'    => '<input type="text" pattern="#[0-9A-Fa-f]{6}" placeholder="#D63A5E"' . $attrs . ' value="' . self::e($v) . '">',
            default    => '<input type="text"' . $attrs . ' value="' . self::e($v) . '"' . ($f['length'] ? ' maxlength="' . $f['length'] . '"' : '') . '>',
        };

        $hint = trim(($f['comment'] ?? '') . ($f['kind'] === 'datetime' ? ' (heure de Paris)' : ''));

        return '<div class="field' . ($error ? ' has-error' : '') . '"><label for="' . $id . '" title="' . self::e($name) . '">' . self::e(self::columnLabel($name))
            . ($required && !$f['readonly'] ? ' <span class="req">*</span>' : '') . '</label>'
            . $input . ($error ? '<p class="error">' . self::e($error) . '</p>' : '') . ($hint !== '' ? '<p class="hint">' . self::e($hint) . '</p>' : '') . '</div>';
    }

    private static function select(string $attrs, array $options, string $value, bool $empty): string
    {
        $html = '<select' . $attrs . '>' . ($empty ? '<option value="">—</option>' : '');
        foreach ($options as $k => $label) {
            $html .= '<option value="' . self::e($k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . self::e($label) . '</option>';
        }

        return $html . '</select>';
    }

    private static function isImage(string $name): bool
    {
        return (bool) preg_match('/(image|cover|logo|photo|media)_url$|^url$/', $name);
    }
}
