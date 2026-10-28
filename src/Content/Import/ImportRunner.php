<?php

declare(strict_types=1);

namespace App\Content\Import;

use App\Content\ContentSources;
use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;

/**
 * Exécute l'import d'une source : récupération, création / mise à jour, dépublication des disparus.
 *
 * Garde-fous :
 *  - verrou MySQL : deux imports de la même source ne tournent jamais en parallèle (cron + manuel)
 *  - un élément invalide est ignoré sans bloquer les autres
 *  - si la source renvoie 0 élément ou trop d'erreurs, RIEN n'est dépublié
 *    (une panne du flux ne doit pas vider l'application)
 */
final class ImportRunner
{
    /** @return array<string, mixed> résumé de l'exécution */
    public function run(string $sourceSlug): array
    {
        $config = Config::get('imports.sources.' . $sourceSlug)
            ?? throw new \InvalidArgumentException("Source non configurée dans config/imports.php : {$sourceSlug}");
        $source = ContentSources::findBySlug($sourceSlug)
            ?? throw new \InvalidArgumentException("Source absente de la table content_sources : {$sourceSlug}");
        if (!$source['is_active']) {
            throw new \RuntimeException("Source désactivée : {$sourceSlug}");
        }
        $target = ImportTargets::get($config['target'])
            ?? throw new \InvalidArgumentException("Cible d'import inconnue : {$config['target']}");

        $lock = 'myclermont:import:' . $sourceSlug;
        if ((int) (Database::fetch('SELECT GET_LOCK(:l, 0) AS ok', ['l' => $lock])['ok'] ?? 0) !== 1) {
            throw new \RuntimeException("Un import de {$sourceSlug} est déjà en cours.");
        }

        $startedAt = gmdate('Y-m-d H:i:s');
        $runId = Database::insert(
            'INSERT INTO import_runs (source_id, target, started_at) VALUES (:s, :t, :d)',
            ['s' => $source['id'], 't' => $config['target'], 'd' => $startedAt],
        );
        $stats = ['fetched' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'unpublished' => 0, 'errors' => 0];
        $messages = [];
        $status = 'success';

        try {
            /** @var Importer $importer */
            $importer = new $config['importer']();
            foreach ($importer->fetch($config['options'] ?? []) as $item) {
                $stats['fetched']++;
                try {
                    if (!isset($item['external_id']) || !is_scalar($item['external_id']) || $item['external_id'] === '') {
                        throw new \InvalidArgumentException('external_id manquant');
                    }
                    $stats[$target->upsert((int) $source['id'], $item, (bool) $source['auto_publish'], $startedAt)]++;
                } catch (\InvalidArgumentException $e) {
                    $stats['errors']++;
                    if (count($messages) < 20) {
                        $messages[] = ($item['external_id'] ?? '?') . ' : ' . $e->getMessage();
                    }
                }
            }

            $errorRate = $stats['fetched'] > 0 ? $stats['errors'] / $stats['fetched'] : 1;
            if (($config['unpublish_missing'] ?? true) && $stats['fetched'] > 0 && $errorRate < 0.2) {
                $stats['unpublished'] = $target->unpublishMissing((int) $source['id'], $startedAt);
            } elseif ($stats['fetched'] === 0 || $errorRate >= 0.2) {
                $status = 'partial';
                $messages[] = 'Dépublication ignorée (aucun élément ou trop d\'erreurs).';
            }
            if ($stats['errors'] > 0) {
                $status = 'partial';
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            $messages[] = $e->getMessage();
            Logger::error("Import {$sourceSlug} en échec : " . $e->getMessage());
        } finally {
            Database::execute(
                'UPDATE import_runs SET finished_at = :f, status = :st, fetched = :fe, created = :c, updated = :u,
                        unchanged = :un, unpublished = :up, errors = :e, message = :m WHERE id = :id',
                [
                    'f' => gmdate('Y-m-d H:i:s'), 'st' => $status, 'fe' => $stats['fetched'], 'c' => $stats['created'],
                    'u' => $stats['updated'], 'un' => $stats['unchanged'], 'up' => $stats['unpublished'],
                    'e' => $stats['errors'], 'm' => $messages ? implode("\n", $messages) : null, 'id' => $runId,
                ],
            );
            Database::execute('UPDATE content_sources SET last_import_at = :d WHERE id = :id', ['d' => gmdate('Y-m-d H:i:s'), 'id' => $source['id']]);
            Database::fetch('SELECT RELEASE_LOCK(:l)', ['l' => $lock]);
        }

        return ['source' => $sourceSlug, 'status' => $status] + $stats + ['messages' => $messages];
    }

    /** Empreinte d'un élément importé : s'il n'a pas changé, aucune écriture. */
    public static function hash(array $item): string
    {
        ksort($item);

        return sha1(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
