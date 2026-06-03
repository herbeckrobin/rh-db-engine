<?php

declare(strict_types=1);

namespace RhDbEngine;

/**
 * Serialisierungssicheres Search-Replace für WordPress-Daten.
 *
 * Naives str_replace() zerstoert PHP-Serialisierungs-Laengenpräfixe (z.B. `s:5:"Hallo"`).
 * Diese Klasse deserialisiert Werte rekursiv, wendet das Replace auf Strings an und
 * serialisiert wieder, so bleiben Options, Meta-Felder etc. intakt.
 */
final class SearchReplace
{
    /**
     * Ersetzt $from durch $to in $data. Akzeptiert beliebige Typen inklusive serialisierter Strings.
     *
     * @param mixed $data
     * @return mixed
     */
    public function recursiveReplace(mixed $data, string $from, string $to): mixed
    {
        if ($from === '' || $from === $to) {
            return $data;
        }

        if (is_string($data)) {
            return $this->replaceString($data, $from, $to);
        }

        if (is_array($data)) {
            $out = [];
            foreach ($data as $key => $value) {
                $out[$key] = $this->recursiveReplace($value, $from, $to);
            }
            return $out;
        }

        if (is_object($data)) {
            if ($data instanceof \stdClass) {
                $out = new \stdClass();
                foreach (get_object_vars($data) as $key => $value) {
                    $out->{$key} = $this->recursiveReplace($value, $from, $to);
                }
                return $out;
            }

            return $data;
        }

        return $data;
    }

    private function replaceString(string $value, string $from, string $to): string
    {
        if (str_contains($value, 's:') || str_contains($value, 'a:') || str_contains($value, 'O:')) {
            $unserialized = @unserialize($value, ['allowed_classes' => false]);

            if ($unserialized !== false || $value === 'b:0;') {
                $replaced = $this->recursiveReplace($unserialized, $from, $to);
                return serialize($replaced);
            }
        }

        if (!str_contains($value, $from)) {
            return $value;
        }

        return str_replace($from, $to, $value);
    }
}
