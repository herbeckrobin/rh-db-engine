<?php

declare(strict_types=1);

namespace RhDbEngine;

/**
 * Einfacher, prozessübergreifender Lock für Export- und Import-Läufe.
 *
 * Ohne ihn starten zwei Klicks zwei parallele Wiederherstellungen auf derselben Datenbank,
 * und ein Export, der während eines Imports läuft, schreibt ein Backup aus einem halb
 * ersetzten Zustand, das gültig aussieht.
 *
 * Bewusst option-basiert und nicht als Transient: ein Objekt-Cache-Flush darf einen
 * laufenden Lauf nicht schutzlos machen. Der Lock trägt eine Ablaufzeit, damit ein
 * abgestürzter Lauf die Funktion nicht dauerhaft blockiert.
 */
final class JobLock
{
    private const OPTION_PREFIX = 'rhdbe_lock_';

    /**
     * Versucht, den Lock zu übernehmen.
     *
     * @param string $name Kennung des Laufs, z.B. 'db'.
     * @param int $ttl Sekunden, nach denen ein hängengebliebener Lock verfällt.
     * @return bool true, wenn der Lock gehört; false, wenn bereits ein Lauf aktiv ist.
     */
    public static function acquire(string $name, int $ttl = 1800): bool
    {
        $option = self::option($name);
        $now = time();
        $owner = self::owner();

        // add_option legt nur an, wenn die Option noch nicht existiert. Das ist der
        // eigentliche Wettlauf-Schutz.
        if (add_option($option, ['owner' => $owner, 'expires' => $now + $ttl], '', 'no')) {
            return self::confirmOwner($option, $owner);
        }

        // Es existiert bereits ein Lock. Übernehmen ist nur erlaubt, wenn er abgelaufen ist.
        $current = get_option($option);
        if (is_array($current) && isset($current['expires']) && (int) $current['expires'] > $now) {
            return false;
        }

        update_option($option, ['owner' => $owner, 'expires' => $now + $ttl], false);

        return self::confirmOwner($option, $owner);
    }

    /**
     * Gegenprobe: steht nach dem Schreiben wirklich die eigene Kennung in der Option?
     *
     * add_option prüft vorab über den Options-Cache, ob es die Option schon gibt, und das
     * ist nicht atomar. Zwei Prozesse, die im selben Augenblick eintreffen, bekommen sonst
     * beide ein true zurück. Ein Lesen aus der Datenbank direkt danach schliesst das
     * Fenster: wer dabei seine eigene Kennung nicht mehr vorfindet, hat verloren.
     */
    private static function confirmOwner(string $option, string $owner): bool
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($option, 'options');
        }

        $current = get_option($option);

        return is_array($current) && ($current['owner'] ?? '') === $owner;
    }

    /**
     * Verlängert einen gehaltenen Lock, damit ein langer Lauf nicht unter sich selbst verfällt.
     */
    public static function refresh(string $name, int $ttl = 1800): void
    {
        update_option(self::option($name), ['owner' => self::owner(), 'expires' => time() + $ttl], false);
    }

    public static function release(string $name): void
    {
        delete_option(self::option($name));
    }

    /**
     * Wann läuft ein gehaltener Lock ab? Null, wenn keiner aktiv ist.
     */
    public static function heldUntil(string $name): ?int
    {
        $current = get_option(self::option($name));
        if (! is_array($current) || ! isset($current['expires'])) {
            return null;
        }

        $expires = (int) $current['expires'];

        return $expires > time() ? $expires : null;
    }

    private static function option(string $name): string
    {
        return self::OPTION_PREFIX . preg_replace('/[^a-z0-9_]/i', '', $name);
    }

    /**
     * Kennung dieses Prozesses. Einmal erzeugt, für die Dauer des Requests stabil, und
     * mit Zufallsanteil: zwei Prozesse in derselben Sekunde müssen unterscheidbar sein,
     * sonst wäre die Gegenprobe in confirmOwner wertlos.
     */
    private static function owner(): string
    {
        static $owner = null;

        if ($owner === null) {
            $owner = sprintf(
                '%d:%s:%s',
                function_exists('get_current_user_id') ? get_current_user_id() : 0,
                gmdate('c'),
                bin2hex(random_bytes(6))
            );
        }

        return $owner;
    }
}
