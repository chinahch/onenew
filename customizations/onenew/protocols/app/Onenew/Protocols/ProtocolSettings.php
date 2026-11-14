<?php

declare(strict_types=1);

namespace App\Onenew\Protocols;

/** Restore only XHTTP map fields after Laravel's associative JSON decoding.
 * Lists, including unknown empty lists, retain their original representation.
 */
final class ProtocolSettings
{
    public static function decode(?string $json): array
    {
        $settings = json_decode($json ?? '', true);
        if (!is_array($settings)) return [];
        // Raw JSON still tells us unknown {} apart from []; retain that evidence.
        if (($settings['network'] ?? null) === 'xhttp') {
            $original = json_decode($json);
            if (isset($original->network_settings)) {
                $settings['network_settings'] = self::preserveObjects($original->network_settings);
            }
        }
        return $settings;
    }

    private static function preserveObjects($value)
    {
        if ($value instanceof \stdClass) {
            $members = get_object_vars($value);
            if ($members === []) return new \stdClass();
            return array_map([self::class, 'preserveObjects'], $members);
        }
        if (is_array($value)) return array_map([self::class, 'preserveObjects'], $value);
        return $value;
    }

    public static function normalize(array $settings): array
    {
        if (($settings['network'] ?? null) !== 'xhttp') return $settings;
        if (is_array($settings['network_settings'] ?? null)) {
            $settings['network_settings'] = self::network($settings['network_settings']);
        }
        return $settings;
    }

    private static function network(array $network): array
    {
        self::emptyMap($network, 'extra');
        if (is_array($network['extra'] ?? null)) {
            $extra = $network['extra'];
            foreach (['headers', 'xmux', 'downloadSettings'] as $key) self::emptyMap($extra, $key);
            if (is_array($extra['downloadSettings'] ?? null)) {
                $download = $extra['downloadSettings'];
                foreach (['tlsSettings', 'realitySettings', 'sockopt', 'xhttpSettings'] as $key) self::emptyMap($download, $key);
                if (is_array($download['xhttpSettings'] ?? null)) $download['xhttpSettings'] = self::network($download['xhttpSettings']);
                $extra['downloadSettings'] = $download;
            }
            $network['extra'] = $extra;
        }
        return $network;
    }

    private static function emptyMap(array &$value, string $key): void
    {
        if (array_key_exists($key, $value) && $value[$key] === []) $value[$key] = new \stdClass();
    }

    /** URI extra is itself an object, including the valid empty object {}. */
    public static function encodeExtra($extra): ?string
    {
        if (!is_array($extra) && !($extra instanceof \stdClass)) return null;
        $network = self::network(['extra' => $extra]);
        return json_encode($network['extra'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
