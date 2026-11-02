<?php

namespace App\Support;

/**
 * Normalisasi sumber video Central Display.
 *  - STREAM  : URL http(s) langsung (IPTV HLS .m3u8, MP4/WebM, dsb.).
 *  - YOUTUBE : link video / live / shorts / playlist YouTube → disimpan sebagai id sehingga display membangun embed sendiri.
 */
final class DisplayMedia
{
    public const MAX_SOURCES = 5;

    private const YOUTUBE_HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];

    /** @return array{kind: 'video'|'playlist', id: string}|null */
    public static function parseYouTube(string $url): ?array
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($host, self::YOUTUBE_HOSTS, true)) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        $path = trim($parts['path'] ?? '', '/');
        $isId = fn (?string $value): bool => is_string($value) && preg_match('/^[A-Za-z0-9_-]{11}$/', $value) === 1;
        $isList = fn (?string $value): bool => is_string($value) && preg_match('/^[A-Za-z0-9_-]{10,64}$/', $value) === 1;

        if ($host === 'youtu.be') {
            $id = explode('/', $path)[0] ?? '';

            return $isId($id) ? ['kind' => 'video', 'id' => $id] : (isset($query['list']) && $isList($query['list']) ? ['kind' => 'playlist', 'id' => $query['list']] : null);
        }
        if ($path === 'watch' && $isId($query['v'] ?? null)) {
            return ['kind' => 'video', 'id' => $query['v']];
        }
        // Kanal berformat /channel/UC… → putar playlist "semua unggahan" kanal itu (id = "UU" + sisa id kanal), tanpa panggilan jaringan.
        if (preg_match('#^channel/UC([A-Za-z0-9_-]{22})(?:/(?:videos|featured|streams|shorts|live|playlists))?$#', $path, $match)) {
            return ['kind' => 'playlist', 'id' => 'UU'.$match[1]];
        }
        if (preg_match('#^(?:embed|live|shorts|v)/([A-Za-z0-9_-]{11})$#', $path, $match)) {
            return ['kind' => 'video', 'id' => $match[1]];
        }
        if (($path === 'playlist' || $path === 'embed/videoseries' || $path === 'watch') && $isList($query['list'] ?? null)) {
            return ['kind' => 'playlist', 'id' => $query['list']];
        }

        return null;
    }

    /** Link kanal berbasis nama (@nama, /c/…, /user/…): tidak dapat dipetakan ke id tanpa menghubungi YouTube. */
    public static function isNamedChannelLink(string $url): bool
    {
        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');

        return self::isYouTubeHost($url) && (str_starts_with($path, '@') || preg_match('#^(?:c|user)/#', $path) === 1);
    }

    public static function isYouTubeHost(string $url): bool
    {
        return in_array(strtolower((string) (parse_url($url, PHP_URL_HOST) ?? '')), self::YOUTUBE_HOSTS, true);
    }

    /**
     * @param  list<array{type: string, url: string, label?: string|null}>  $sources  sudah tervalidasi
     * @return list<array{type: string, label: string|null, url: string, youtube_kind: string|null, youtube_id: string|null}>
     */
    public static function normalize(array $sources): array
    {
        return array_values(array_map(function (array $source): array {
            $youtube = $source['type'] === 'YOUTUBE' ? self::parseYouTube($source['url']) : null;

            return ['type' => $source['type'], 'label' => isset($source['label']) && trim((string) $source['label']) !== '' ? trim($source['label']) : null, 'url' => trim($source['url']), 'youtube_kind' => $youtube['kind'] ?? null, 'youtube_id' => $youtube['id'] ?? null];
        }, $sources));
    }
}
