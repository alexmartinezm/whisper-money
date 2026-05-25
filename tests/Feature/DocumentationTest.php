<?php

use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

it('shows the default documentation page', function () {
    $this->get(route('documentation.index'))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('documentation/show')
                ->where('document.slug', 'getting-started')
                ->where('document.locale', 'en')
                ->where('document.title', 'Getting started')
                ->where('document.description', 'Learn the basic Whisper Money workflow.')
                ->where('navigation.0.active', true)
                ->where('languages.0.active', true)
                ->where('languages.1.url', '/documentation/getting-started?lang=es')
        );
});

it('shows every configured documentation page in every locale', function () {
    $pages = array_keys(config('documentation.pages'));
    $locales = array_keys(config('documentation.locales'));

    foreach ($pages as $slug) {
        foreach ($locales as $locale) {
            $this->get(route('documentation.show', ['slug' => $slug, 'lang' => $locale]))
                ->assertOk()
                ->assertInertia(
                    fn (AssertableInertia $page) => $page
                        ->component('documentation/show')
                        ->where('document.slug', $slug)
                        ->where('document.locale', $locale)
                );
        }
    }
});

it('shows the English categories documentation page', function () {
    $this->get(route('documentation.show', ['slug' => 'categories', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('documentation/show')
                ->where('document.slug', 'categories')
                ->where('document.locale', 'en')
                ->where('document.title', 'Categories')
        );
});

it('shows the Spanish categories documentation page', function () {
    $this->get(route('documentation.show', ['slug' => 'categories', 'lang' => 'es']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('documentation/show')
                ->where('document.slug', 'categories')
                ->where('document.locale', 'es')
                ->where('document.title', 'Categorías')
                ->where('document.description', 'Aprende cómo funcionan las categorías en Whisper Money.')
                ->where('languages.0.url', '/documentation/categories?lang=en')
                ->where('languages.1.active', true)
                ->where('document.html', fn (string $html): bool => str_contains($html, 'Mapa de categorías')
                    && str_contains($html, 'href="#mapa-de-categorias"')
                    && str_contains($html, '<p>En esta página</p>'))
        );
});

it('replaces the table of contents placeholder with heading links', function () {
    $this->get(route('documentation.show', ['slug' => 'categories', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('document.html', fn (string $html): bool => ! str_contains($html, '{{TOC}}')
                    && str_contains($html, '<nav class="documentation-toc"')
                    && str_contains($html, 'href="#category-map"')
                    && str_contains($html, 'href="#expense"')
                    && str_contains($html, 'language-mermaid')
                    && str_contains($html, 'flowchart TD')
                    && str_contains($html, '<div class="cards-wrapper">')
                    && str_contains($html, '<section class="card"><h3 id="expense">Expense</h3>')
                    && ! str_contains($html, '<div class="card">')
                    && str_contains($html, '<span class="toc-number">2</span> Category map')
                    && str_contains($html, '<span class="toc-number">3.1</span> Expense')
                    && str_contains($html, '<h2 id="category-map">')
                    && str_contains($html, '<h3 id="expense">')
                    && ! str_contains($html, 'href="#categories"'))
        );
});

it('uses configured heading levels for the table of contents', function () {
    config(['documentation.toc.levels' => [2]]);

    $this->get(route('documentation.show', ['slug' => 'categories', 'lang' => 'en']))
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('document.html', fn (string $html): bool => str_contains($html, 'href="#category-map"')
                    && ! str_contains($html, 'href="#expense"')
                    && str_contains($html, '<h2 id="category-map">')
                    && ! str_contains($html, '<h3 id="expense">'))
        );
});

it('returns not found for unknown documentation pages', function () {
    $this->get('/documentation/unknown')->assertNotFound();
});

it('has markdown files for all configured documentation pages and locales', function () {
    $pages = config('documentation.pages');
    $locales = array_keys(config('documentation.locales'));

    expect($pages)->toBeArray()->not->toBeEmpty();

    foreach ($pages as $page) {
        foreach ($locales as $locale) {
            expect(File::exists($page['file'][$locale]))->toBeTrue();
        }
    }
});
