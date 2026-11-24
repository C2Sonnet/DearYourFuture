<?php
declare(strict_types=1);
namespace KMM\Core;

/** Private letter, loaded only after KimiIntro::allowed has passed. Not web-served. */
final class KimiLetter {
    public const LETTER_DATE = '2026-11-23';
    private const FILE = '/resources/private/kimi-letter-20261123.md';

    public static function available():bool {
        $path=SiteState::root().self::FILE;
        return is_file($path) && !is_link($path);
    }

    public static function html(): string {
        $path = SiteState::root().self::FILE;
        if (!is_file($path) || is_link($path)) throw new \RuntimeException('Private letter unavailable');
        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '' || strlen($raw) > 150000) {
            throw new \RuntimeException('Private letter invalid');
        }
        $blocks = preg_split('/\R\h*\R/u', trim($raw));
        if (!is_array($blocks) || count($blocks) < 10) throw new \RuntimeException('Private letter format invalid');
        $out = '<article class="kimi-letter" aria-label="キミへ、手紙">';
        foreach ($blocks as $i => $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') continue;
            if ($i === 0 && str_starts_with($paragraph, '# ')) {
                $out .= '<div class="kimi-stationery-mark" aria-hidden="true">✿</div>'
                    .'<h2 id="kimi-intro-title" class="kimi-letter-title">'.Security::h(substr($paragraph, 2)).'</h2>';
                continue;
            }
            $class = match (true) {
                $paragraph === '宛先へ' => 'kimi-salutation',
                str_ends_with($paragraph, 'より') => 'kimi-signature',
                $paragraph === '2026年11月23日' => 'kimi-date',
                default => 'kimi-letter-paragraph',
            };
            $text = Security::h($paragraph);
            // Only the author's **bold** markers are supported. Never trust the file as HTML.
            $text = preg_replace('/\*\*([^*]+)\*\*/u', '<strong>$1</strong>', $text) ?? $text;
            $out .= '<p class="'.$class.'">'.nl2br($text, false).'</p>';
        }
        $out .= '<figure class="kimi-album-photo" aria-label="思い出のアルバム写真">'
            .'<picture><source media="(max-width:700px)" srcset="/assets/handmade-sweets-background-portrait.jpg">'
            .'<img src="/assets/handmade-sweets-background.jpg" alt="手作りスイーツ発表会のイラスト" loading="lazy" decoding="async"></picture>'
            .'<figcaption>キミの未来へ ・ 2026.11.23</figcaption></figure>'
            .'</article>';
        return $out;
    }
}
