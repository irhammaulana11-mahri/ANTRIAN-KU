<?php

namespace Tests\Unit;

use App\Support\DisplayMedia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DisplayMediaTest extends TestCase
{
    public static function youtubeLinks(): array
    {
        return [
            'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'video', 'dQw4w9WgXcQ'],
            'watch + parameter lain' => ['https://www.youtube.com/watch?feature=share&v=dQw4w9WgXcQ&t=42s', 'video', 'dQw4w9WgXcQ'],
            'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'video', 'dQw4w9WgXcQ'],
            'youtu.be' => ['https://youtu.be/dQw4w9WgXcQ?si=abc', 'video', 'dQw4w9WgXcQ'],
            'siaran langsung' => ['https://www.youtube.com/live/dQw4w9WgXcQ?feature=share', 'video', 'dQw4w9WgXcQ'],
            'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'video', 'dQw4w9WgXcQ'],
            'embed' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 'video', 'dQw4w9WgXcQ'],
            'playlist' => ['https://www.youtube.com/playlist?list=PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf', 'playlist', 'PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf'],
            'kanal /channel/UC → playlist semua unggahan' => ['https://www.youtube.com/channel/UCfeOWH5zFA4c8GDQrOZyoPg', 'playlist', 'UUfeOWH5zFA4c8GDQrOZyoPg'],
            'tab video kanal' => ['https://www.youtube.com/channel/UCfeOWH5zFA4c8GDQrOZyoPg/videos', 'playlist', 'UUfeOWH5zFA4c8GDQrOZyoPg'],
            'video dalam playlist' => ['https://www.youtube.com/watch?list=PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf', 'playlist', 'PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf'],
        ];
    }

    #[DataProvider('youtubeLinks')]
    public function test_recognises_youtube_links(string $url, string $kind, string $id): void
    {
        $this->assertSame(['kind' => $kind, 'id' => $id], DisplayMedia::parseYouTube($url));
    }

    public static function notYoutube(): array
    {
        return [
            'domain lain' => ['https://vimeo.com/123456789'],
            'domain mirip (phishing)' => ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'],
            'subdomain tidak dikenal' => ['https://evilyoutube.com/watch?v=dQw4w9WgXcQ'],
            'halaman kanal tanpa id video' => ['https://www.youtube.com/@kantorpusat'],
            'id terlalu pendek' => ['https://www.youtube.com/watch?v=abc'],
            'id memuat karakter berbahaya' => ['https://www.youtube.com/watch?v=dQw4w9Wg"><'],
            'beranda' => ['https://www.youtube.com/'],
            'kanal berbasis @nama' => ['https://www.youtube.com/@PTPLNPersero/videos'],
            'kanal id salah panjang' => ['https://www.youtube.com/channel/UCpendek'],
        ];
    }

    #[DataProvider('notYoutube')]
    public function test_rejects_everything_else(string $url): void
    {
        $this->assertNull(DisplayMedia::parseYouTube($url));
    }

    public function test_named_channel_links_are_detected_for_a_helpful_error(): void
    {
        $this->assertTrue(DisplayMedia::isNamedChannelLink('https://www.youtube.com/@PTPLNPersero/videos'));
        $this->assertTrue(DisplayMedia::isNamedChannelLink('https://youtube.com/c/PLN'));
        $this->assertTrue(DisplayMedia::isNamedChannelLink('https://www.youtube.com/user/pln'));
        $this->assertFalse(DisplayMedia::isNamedChannelLink('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertFalse(DisplayMedia::isNamedChannelLink('https://vimeo.com/@x'));
    }

    public function test_normalize_extracts_ids_trims_labels_and_keeps_order(): void
    {
        $result = DisplayMedia::normalize([
            ['type' => 'STREAM', 'url' => ' http://192.168.1.10/iptv/live.m3u8 ', 'label' => '  IPTV Utama  '],
            ['type' => 'YOUTUBE', 'url' => 'https://youtu.be/dQw4w9WgXcQ', 'label' => ''],
        ]);
        $this->assertSame('IPTV Utama', $result[0]['label']);
        $this->assertSame('http://192.168.1.10/iptv/live.m3u8', $result[0]['url']);
        $this->assertNull($result[0]['youtube_id']);
        $this->assertNull($result[1]['label']);
        $this->assertSame(['video', 'dQw4w9WgXcQ'], [$result[1]['youtube_kind'], $result[1]['youtube_id']]);
    }
}
