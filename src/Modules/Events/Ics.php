<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Core\Config;

/**
 * Fichier iCalendar (RFC 5545) pour le bouton « Calendrier ».
 * (L'app peut aussi créer l'événement directement via EventKit / CalendarContract avec les champs JSON.)
 */
final class Ics
{
    public static function build(array $e): string
    {
        $utc = static fn (string $d) => (new \DateTimeImmutable($d, new \DateTimeZone('UTC')))->format('Ymd\THis\Z');
        $location = implode(', ', array_filter([$e['venue_name'], $e['address'], $e['city']]));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//myClermont//Agenda//FR',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:' . $e['uid'] . '@myclermont.fr',
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            'DTSTART:' . $utc($e['starts_at']),
            'DTEND:' . $utc($e['ends_at']),
            'SUMMARY:' . self::escape($e['title']),
            'LOCATION:' . self::escape($location),
            'DESCRIPTION:' . self::escape((string) $e['description']),
            'URL:' . str_replace('{slug}', $e['slug'], (string) Config::get('events.share_url')),
        ];
        if ($e['latitude'] !== null && $e['longitude'] !== null) {
            $lines[] = 'GEO:' . $e['latitude'] . ';' . $e['longitude'];
        }
        if ($e['status'] === 'cancelled') {
            $lines[] = 'STATUS:CANCELLED';
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], $text);
    }

    /** Lignes de 75 octets max, continuation par un espace (sans couper un caractère UTF-8). */
    private static function fold(string $line): string
    {
        $out = '';
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
        }

        return $out . $line;
    }
}
