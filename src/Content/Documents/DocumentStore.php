<?php

declare(strict_types=1);

namespace App\Content\Documents;

use App\Core\Config;
use App\Core\Database;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Security\Crypto;

/**
 * Stockage des documents envoyés par les utilisateurs (CV, lettres de motivation).
 *
 *  - fichiers dans storage/private (jamais servis par le serveur web)
 *  - PDF uniquement, vérifié par son contenu (signature %PDF-), pas par l'extension
 *  - un document n'est utilisable que par l'appareil qui l'a envoyé
 *  - suppression automatique : 24 h s'il n'est jamais joint, 90 jours après la candidature (RGPD)
 */
final class DocumentStore
{
    public const KINDS = ['cv', 'cover_letter'];

    public static function root(): string
    {
        return dirname(__DIR__, 3) . '/storage/private';
    }

    public function store(string $bytes, string $kind, array $device, ?int $userId, ?string $filename): array
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw new ValidationException(['kind' => 'Type de document inconnu (cv, cover_letter).']);
        }
        if ($bytes === '') {
            throw new ValidationException(['file' => 'Fichier vide.']);
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new HttpException(415, 'unsupported_document', 'Seuls les fichiers PDF sont acceptés.');
        }

        $uid = Crypto::uuid4();
        $relative = gmdate('Y/m') . '/' . $uid . '.pdf';
        $absolute = self::root() . '/' . $relative;
        if (!is_dir(dirname($absolute)) && !mkdir(dirname($absolute), 0750, true) && !is_dir(dirname($absolute))) {
            throw new \RuntimeException('Impossible de créer le dossier de stockage.');
        }
        if (file_put_contents($absolute, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new \RuntimeException('Écriture du document impossible.');
        }
        chmod($absolute, 0640);

        $filename = self::cleanFilename($filename, $kind);
        $ttl = (int) Config::get('jobs.documents.unattached_ttl_hours', 24) * 3600;

        Database::execute(
            'INSERT INTO documents (uid, kind, device_id, user_id, filename, mime, size_bytes, sha256, storage_path, expires_at, created_at)
             VALUES (:uid, :kind, :d, :u, :f, \'application/pdf\', :size, :sha, :path, :exp, :now)',
            [
                'uid' => $uid, 'kind' => $kind, 'd' => $device['id'], 'u' => $userId, 'f' => $filename, 'size' => strlen($bytes),
                'sha' => hash('sha256', $bytes), 'path' => $relative, 'exp' => gmdate('Y-m-d H:i:s', time() + $ttl), 'now' => gmdate('Y-m-d H:i:s'),
            ],
        );

        return ['id' => $uid, 'kind' => $kind, 'filename' => $filename, 'size_bytes' => strlen($bytes)];
    }

    /** Document utilisable pour une candidature : bon appareil, bon type, pas encore joint, pas expiré. */
    public function claim(string $uid, string $kind, array $device): array
    {
        $doc = Database::fetch(
            'SELECT id, uid, kind, device_id, attached_at, expires_at FROM documents WHERE uid = :u',
            ['u' => strtolower($uid)],
        );
        if ($doc === null || (int) $doc['device_id'] !== (int) $device['id'] || $doc['kind'] !== $kind
            || $doc['attached_at'] !== null || strtotime($doc['expires_at'] . ' UTC') < time()) {
            $field = $kind === 'cv' ? 'cv_document_id' : 'cover_letter_document_id';
            throw new ValidationException([$field => 'Document introuvable ou expiré : renvoyez le fichier.']);
        }

        return $doc;
    }

    public function attach(int $documentId): void
    {
        $days = (int) Config::get('jobs.documents.retention_days', 90);
        Database::execute(
            'UPDATE documents SET attached_at = :now, expires_at = :exp WHERE id = :id',
            ['now' => gmdate('Y-m-d H:i:s'), 'exp' => gmdate('Y-m-d H:i:s', time() + $days * 86400), 'id' => $documentId],
        );
    }

    /** Suppression par l'appareil qui l'a envoyé (seulement s'il n'est pas encore joint). */
    public function delete(string $uid, array $device): bool
    {
        $doc = Database::fetch('SELECT id, device_id, storage_path, attached_at FROM documents WHERE uid = :u', ['u' => strtolower($uid)]);
        if ($doc === null || (int) $doc['device_id'] !== (int) $device['id'] || $doc['attached_at'] !== null) {
            return false;
        }
        @unlink(self::root() . '/' . $doc['storage_path']);
        Database::execute('DELETE FROM documents WHERE id = :id', ['id' => $doc['id']]);

        return true;
    }

    public function read(int $documentId): ?array
    {
        $doc = Database::fetch('SELECT filename, mime, storage_path FROM documents WHERE id = :id', ['id' => $documentId]);
        if ($doc === null || !is_file($path = self::root() . '/' . $doc['storage_path'])) {
            return null;
        }

        return ['filename' => $doc['filename'], 'mime' => $doc['mime'], 'content' => (string) file_get_contents($path)];
    }

    /** Supprime fichiers et lignes expirés (cron). */
    public function purge(): int
    {
        $count = 0;
        foreach (Database::fetchAll('SELECT id, storage_path FROM documents WHERE expires_at < :now LIMIT 1000', ['now' => gmdate('Y-m-d H:i:s')]) as $doc) {
            @unlink(self::root() . '/' . $doc['storage_path']);
            Database::execute('DELETE FROM documents WHERE id = :id', ['id' => $doc['id']]);
            $count++;
        }

        return $count;
    }

    public static function cleanFilename(?string $name, string $kind): string
    {
        $base = pathinfo((string) $name, PATHINFO_FILENAME);
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base) ?: $base;
        $safe = trim((string) preg_replace('/[^A-Za-z0-9._ -]+/', '', $ascii), ' .-');

        return mb_substr($safe !== '' ? $safe : ($kind === 'cv' ? 'CV' : 'Lettre de motivation'), 0, 100) . '.pdf';
    }
}
