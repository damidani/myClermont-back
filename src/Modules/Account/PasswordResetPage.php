<?php

declare(strict_types=1);

namespace App\Modules\Account;

use App\Core\Database;
use App\Exceptions\ValidationException;
use App\Modules\Account\Repositories\UserRepository;
use App\Repositories\RefreshTokenRepository;

/**
 * Page web du lien « mot de passe oublié » (brief §6) : GET affiche le formulaire, POST applique la règle de l'app
 * (8 caractères, une lettre, un chiffre). Lien valable 1 h, à usage unique ; les sessions ouvertes sont fermées.
 */
final class PasswordResetPage
{
    public function run(): void
    {
        $token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
        $reset = preg_match('/^[a-f0-9]{64}$/', $token)
            ? Database::fetch('SELECT id, user_id FROM password_resets WHERE token_hash = :h AND used_at IS NULL AND expires_at > :now',
                ['h' => hash('sha256', $token), 'now' => gmdate('Y-m-d H:i:s')])
            : null;

        if ($reset === null) {
            $this->render('Lien expiré', '<p>Ce lien n\'est plus valable : il a expiré ou a déjà servi.</p><p>Redemande un lien depuis l\'app My Clermont (« Mot de passe oublié »).</p>');
            return;
        }

        $error = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $password = (string) ($_POST['password'] ?? '');
            try {
                UserProfile::checkPassword($password);
                if (!hash_equals($password, (string) ($_POST['confirm'] ?? ''))) {
                    throw new ValidationException(['confirm' => 'Les deux mots de passe ne sont pas identiques.']);
                }
                $now = gmdate('Y-m-d H:i:s');
                Database::transaction(function () use ($reset, $password, $now): void {
                    (new UserRepository())->updatePasswordHash((int) $reset['user_id'], UserRepository::hashPassword($password));
                    Database::execute('UPDATE password_resets SET used_at = :d WHERE user_id = :u AND used_at IS NULL', ['d' => $now, 'u' => $reset['user_id']]);
                });
                (new RefreshTokenRepository())->revokeAllForUser((int) $reset['user_id']);
                $this->render('Mot de passe changé', '<p>C\'est fait ! Ton nouveau mot de passe est enregistré.</p><p>Retourne dans l\'app My Clermont pour te connecter.</p>');
                return;
            } catch (ValidationException $e) {
                $error = $e->getMessage();
            }
        }

        $t = htmlspecialchars($token, ENT_QUOTES);
        $this->render('Nouveau mot de passe', ($error !== null ? '<p class="error">' . htmlspecialchars($error) . '</p>' : '') . <<<HTML
            <form method="post" action="/password/reset">
              <input type="hidden" name="token" value="{$t}">
              <label>Nouveau mot de passe<input type="password" name="password" minlength="8" maxlength="128" autocomplete="new-password" required></label>
              <label>Confirme-le<input type="password" name="confirm" minlength="8" maxlength="128" autocomplete="new-password" required></label>
              <p class="hint">8 caractères minimum, avec au moins une lettre et un chiffre.</p>
              <button type="submit">Enregistrer</button>
            </form>
            HTML);
    }

    private function render(string $title, string $body): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        $title = htmlspecialchars($title);
        echo <<<HTML
            <!doctype html>
            <html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex"><title>{$title} · My Clermont</title>
            <style>
              :root { color-scheme: light dark; --bg: #f6f5f8; --card: #fff; --text: #1d1b22; --muted: #6b6775; --accent: #7B5CFF; --err: #c62f5a; }
              @media (prefers-color-scheme: dark) { :root { --bg: #121016; --card: #1d1a23; --text: #f3f1f7; --muted: #a29dad; } }
              body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.5 -apple-system, system-ui, sans-serif; }
              main { max-width: 420px; margin: 8vh auto; padding: 28px 24px; background: var(--card); border-radius: 18px; }
              h1 { font-size: 22px; margin: 0 0 16px; } label { display: block; margin: 14px 0; font-weight: 600; }
              input { display: block; width: 100%; box-sizing: border-box; margin-top: 6px; padding: 12px; font: inherit; border: 1px solid #8885; border-radius: 10px; background: transparent; color: inherit; }
              button { width: 100%; padding: 13px; margin-top: 8px; border: 0; border-radius: 12px; background: var(--accent); color: #fff; font: inherit; font-weight: 700; }
              .hint { color: var(--muted); font-size: 14px; } .error { color: var(--err); font-weight: 600; }
              @media (max-width: 480px) { main { margin: 0; min-height: 100vh; border-radius: 0; } }
            </style></head>
            <body><main><h1>{$title}</h1>{$body}</main></body></html>
            HTML;
    }
}
