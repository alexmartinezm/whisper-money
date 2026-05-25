<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class DocumentationController extends Controller
{
    public function __invoke(?string $slug = null): Response
    {
        $slug ??= $this->defaultSlug();
        $locale = $this->locale();
        $page = $this->page($slug, $locale);
        $markdown = File::get($page['file']);

        return Inertia::render('documentation/show', [
            'document' => [
                'slug' => $slug,
                'locale' => $locale,
                'title' => $page['title'],
                'description' => $page['description'],
                'html' => $this->html($markdown, $locale),
            ],
            'navigation' => $this->navigation($slug, $locale),
            'languages' => $this->languageLinks($slug, $locale),
        ]);
    }

    private function defaultSlug(): string
    {
        $slug = config('documentation.default');

        if (! is_string($slug) || $slug === '') {
            throw new NotFoundHttpException;
        }

        return $slug;
    }

    private function locale(): string
    {
        $locale = App::currentLocale();

        if (array_key_exists($locale, $this->supportedLocales())) {
            return $locale;
        }

        return $this->fallbackLocale();
    }

    private function fallbackLocale(): string
    {
        $locale = config('documentation.fallback_locale', 'en');

        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    /**
     * @return array<string, string>
     */
    private function supportedLocales(): array
    {
        $locales = config('documentation.locales', []);

        if (! is_array($locales)) {
            return [];
        }

        return collect($locales)
            ->mapWithKeys(fn (mixed $label, string $locale): array => [$locale => (string) $label])
            ->all();
    }

    /**
     * @return array{title: string, description: string, file: string}
     */
    private function page(string $slug, string $locale): array
    {
        $page = config("documentation.pages.{$slug}");

        if (! is_array($page) || ! isset($page['title'], $page['description'], $page['file'])) {
            throw new NotFoundHttpException;
        }

        $file = $this->localizedValue($page['file'], $locale);

        if (! File::exists($file)) {
            throw new NotFoundHttpException;
        }

        return [
            'title' => $this->localizedValue($page['title'], $locale),
            'description' => $this->localizedValue($page['description'], $locale),
            'file' => $file,
        ];
    }

    private function localizedValue(mixed $value, string $locale): string
    {
        if (is_array($value)) {
            $fallbackLocale = $this->fallbackLocale();
            $localized = $value[$locale] ?? $value[$fallbackLocale] ?? null;

            if (is_string($localized)) {
                return $localized;
            }

            throw new NotFoundHttpException;
        }

        if (! is_string($value)) {
            throw new NotFoundHttpException;
        }

        return $value;
    }

    private function html(string $markdown, string $locale): string
    {
        $cardBlocks = $this->extractCardBlocks($markdown);
        $headings = $this->headings($markdown);
        $html = (string) Str::of($cardBlocks['markdown'])->markdown([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $html = $this->replaceTocPlaceholder($html, $headings, $locale);
        $html = $this->replaceCardPlaceholders($html, $cardBlocks['html']);

        return $this->addHeadingIds($html, $headings);
    }

    /**
     * @return array{markdown: string, html: array<string, string>}
     */
    private function extractCardBlocks(string $markdown): array
    {
        $cardBlocks = [];
        $output = [];
        $lines = preg_split('/\R/', $markdown) ?: [];
        $index = 0;
        $insideWrapper = false;
        $insideCard = false;
        $wrapperCards = [];
        $cardLines = [];

        foreach ($lines as $line) {
            if (! $insideWrapper && trim($line) === '<div class="cards-wrapper">') {
                $insideWrapper = true;
                $wrapperCards = [];

                continue;
            }

            if (! $insideWrapper) {
                $output[] = $line;

                continue;
            }

            if (! $insideCard && trim($line) === '<div class="card">') {
                $insideCard = true;
                $cardLines = [];

                continue;
            }

            if (trim($line) === '</div>') {
                if ($insideCard) {
                    $insideCard = false;
                    $wrapperCards[] = trim(implode("\n", $cardLines));
                    $cardLines = [];

                    continue;
                }

                $placeholder = "DOCUMENTATION_CARDS_{$index}";
                $cardBlocks[$placeholder] = $this->cardsHtml($wrapperCards);
                $output[] = $placeholder;
                $insideWrapper = false;
                $wrapperCards = [];
                $index++;

                continue;
            }

            if ($insideCard) {
                $cardLines[] = $line;
            }
        }

        return [
            'markdown' => implode("\n", $output),
            'html' => $cardBlocks,
        ];
    }

    /**
     * @param  array<int, string>  $cards
     */
    private function cardsHtml(array $cards): string
    {
        $items = collect($cards)
            ->filter()
            ->map(fn (string $card): string => '<section class="card">'.(string) Str::of($card)->markdown([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]).'</section>')
            ->implode('');

        if ($items === '') {
            return '';
        }

        return '<div class="cards-wrapper">'.$items.'</div>';
    }

    /**
     * @param  array<string, string>  $cardBlocks
     */
    private function replaceCardPlaceholders(string $html, array $cardBlocks): string
    {
        foreach ($cardBlocks as $placeholder => $cardHtml) {
            $html = str_replace(["<p>{$placeholder}</p>", $placeholder], $cardHtml, $html);
        }

        return $html;
    }

    /**
     * @return array<int, array{level: int, title: string, id: string}>
     */
    private function headings(string $markdown): array
    {
        preg_match_all('/^(#{1,6})\s+(.+?)\s*#*\s*$/m', $markdown, $matches, PREG_SET_ORDER);

        $headings = [];
        $usedSlugs = [];
        $levels = $this->tocLevels();

        foreach ($matches as $match) {
            $level = strlen($match[1]);

            if (! in_array($level, $levels, true)) {
                continue;
            }

            $title = $this->plainHeadingText($match[2]);

            $headings[] = [
                'level' => $level,
                'title' => $title,
                'id' => $this->uniqueHeadingId($title, $usedSlugs),
            ];
        }

        return $headings;
    }

    /**
     * @return array<int, int>
     */
    private function tocLevels(): array
    {
        $levels = config('documentation.toc.levels', [2, 3]);

        if (! is_array($levels)) {
            return [2, 3];
        }

        return collect($levels)
            ->map(fn (mixed $level): int => (int) $level)
            ->filter(fn (int $level): bool => $level >= 1 && $level <= 6)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function plainHeadingText(string $heading): string
    {
        $html = (string) Str::of($heading)->inlineMarkdown([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @param  array<string, int>  $usedSlugs
     */
    private function uniqueHeadingId(string $title, array &$usedSlugs): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'section';
        }

        $usedSlugs[$base] = ($usedSlugs[$base] ?? 0) + 1;

        if ($usedSlugs[$base] === 1) {
            return $base;
        }

        return "{$base}-{$usedSlugs[$base]}";
    }

    /**
     * @param  array<int, array{level: int, title: string, id: string}>  $headings
     */
    private function replaceTocPlaceholder(string $html, array $headings, string $locale): string
    {
        $placeholder = config('documentation.toc.placeholder', '{{TOC}}');

        if (! is_string($placeholder) || $placeholder === '') {
            return $html;
        }

        return str_replace(
            ["<p>{$placeholder}</p>", $placeholder],
            $this->tocHtml($headings, $locale),
            $html,
        );
    }

    /**
     * @param  array<int, array{level: int, title: string, id: string}>  $headings
     */
    private function tocHtml(array $headings, string $locale): string
    {
        if ($headings === []) {
            return '';
        }

        $items = collect($this->numberedHeadings($headings))
            ->map(fn (array $heading): string => sprintf(
                '<li class="toc-level-%d"><a href="#%s"><span class="toc-number">%s</span> %s</a></li>',
                $heading['level'],
                e($heading['id']),
                e($heading['number']),
                e($heading['title']),
            ))
            ->implode('');

        return '<nav class="documentation-toc" aria-label="Table of contents"><p>'.$this->tocTitle($locale).'</p><ol>'.$items.'</ol></nav>';
    }

    private function tocTitle(string $locale): string
    {
        return e($this->localizedValue(config('documentation.toc.title', 'On this page'), $locale));
    }

    /**
     * @param  array<int, array{level: int, title: string, id: string}>  $headings
     * @return array<int, array{level: int, title: string, id: string, number: string}>
     */
    private function numberedHeadings(array $headings): array
    {
        $levels = $this->tocLevels();
        $counts = [];

        return collect($headings)
            ->map(function (array $heading) use ($levels, &$counts): array {
                foreach ($levels as $level) {
                    if ($level > $heading['level']) {
                        unset($counts[$level]);
                    }
                }

                foreach ($levels as $level) {
                    if ($level < $heading['level'] && ! isset($counts[$level])) {
                        $counts[$level] = 1;
                    }
                }

                $counts[$heading['level']] = ($counts[$heading['level']] ?? 0) + 1;

                $number = collect($levels)
                    ->filter(fn (int $level): bool => $level <= $heading['level'] && isset($counts[$level]))
                    ->map(fn (int $level): int => $counts[$level])
                    ->implode('.');

                return [...$heading, 'number' => $number];
            })
            ->all();
    }

    /**
     * @param  array<int, array{level: int, title: string, id: string}>  $headings
     */
    private function addHeadingIds(string $html, array $headings): string
    {
        $levels = $this->tocLevels();

        if ($headings === [] || $levels === []) {
            return $html;
        }

        $levelPattern = implode('', $levels);
        $headingIndex = 0;

        return (string) preg_replace_callback(
            "/<h([{$levelPattern}])>(.*?)<\/h\\1>/s",
            function (array $match) use ($headings, &$headingIndex): string {
                $heading = $headings[$headingIndex] ?? null;
                $headingIndex++;

                if ($heading === null) {
                    return $match[0];
                }

                return sprintf(
                    '<h%d id="%s">%s</h%d>',
                    (int) $match[1],
                    e($heading['id']),
                    $match[2],
                    (int) $match[1],
                );
            },
            $html,
        );
    }

    /**
     * @return array<int, array{slug: string, title: string, url: string, active: bool}>
     */
    private function navigation(string $activeSlug, string $locale): array
    {
        $pages = config('documentation.pages', []);

        if (! is_array($pages)) {
            return [];
        }

        return collect($pages)
            ->map(fn (array $page, string $slug): array => [
                'slug' => $slug,
                'title' => $this->localizedValue($page['title'] ?? '', $locale),
                'url' => route('documentation.show', ['slug' => $slug, 'lang' => $locale], false),
                'active' => $slug === $activeSlug,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{locale: string, label: string, url: string, active: bool}>
     */
    private function languageLinks(string $slug, string $activeLocale): array
    {
        return collect($this->supportedLocales())
            ->map(fn (string $label, string $locale): array => [
                'locale' => $locale,
                'label' => $label,
                'url' => route('documentation.show', ['slug' => $slug, 'lang' => $locale], false),
                'active' => $locale === $activeLocale,
            ])
            ->values()
            ->all();
    }
}
