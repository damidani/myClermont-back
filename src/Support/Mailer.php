<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * Envoi d'e-mails texte via la fonction mail() (sendmail / msmtp / Postfix local).
 * Pour un fournisseur (Brevo, Mailjet, SES...), remplacer send() par un appel à son API :
 * le reste du code n'appelle que cette classe.
 */
final class Mailer
{
    /**
     * @param list<array{filename: string, mime: string, content: string}> $attachments pièces jointes (CV...)
     */
    public function send(string $to, string $subject, string $body, ?string $replyTo = null, array $attachments = []): bool
    {
        foreach ([$to, $replyTo] as $address) {
            // Protection contre l'injection d'en-têtes
            if ($address !== null && (preg_match('/[\r\n]/', $address) || !filter_var($address, FILTER_VALIDATE_EMAIL))) {
                throw new \InvalidArgumentException('Adresse e-mail invalide.');
            }
        }

        $from = (string) Config::get('app.mail_from', 'no-reply@myclermont.app');
        $headers = [
            'From'                      => 'myClermont <' . $from . '>',
            'MIME-Version'              => '1.0',
            'Content-Type'              => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ];
        if ($replyTo !== null) {
            $headers['Reply-To'] = $replyTo;
        }

        if ($attachments !== []) {
            $boundary = 'mc-' . bin2hex(random_bytes(12));
            $headers['Content-Type'] = 'multipart/mixed; boundary="' . $boundary . '"';
            unset($headers['Content-Transfer-Encoding']);

            $parts = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$body}\r\n";
            foreach ($attachments as $file) {
                $name = preg_replace('/[^A-Za-z0-9._ -]/', '', $file['filename']) ?: 'document.pdf';
                $parts .= "--{$boundary}\r\nContent-Type: {$file['mime']}; name=\"{$name}\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"{$name}\"\r\n\r\n"
                    . chunk_split(base64_encode($file['content'])) . "\r\n";
            }
            $body = $parts . "--{$boundary}--\r\n";
        }

        return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . $from);
    }
}
