<?php

test('service worker exists', function () {
    expect(file_exists(public_path('sw.js')))->toBeTrue();
});

test('web manifest starts at dashboard with fullscreen display', function () {
    $manifest = json_decode(file_get_contents(public_path('favicon/site.webmanifest')), true);

    // Fullscreen so Android hides the status bar instead of painting it with the
    // manifest theme_color, which is baked at install time and cannot follow the
    // app theme. iOS does not support fullscreen and falls back to standalone.
    expect($manifest['start_url'])->toBe('/dashboard')
        ->and($manifest['display'])->toBe('fullscreen');
});

test('the landing page detects an installed app in the display mode the manifest asks for', function () {
    $manifest = json_decode(file_get_contents(public_path('favicon/site.webmanifest')), true);

    // The landing page redirects installed users to the dashboard, and the
    // display-mode media feature only matches the mode that was actually applied,
    // so it has to cover both what we ask for and the standalone iOS falls back to.
    expect(file_get_contents(resource_path('js/pages/welcome.tsx')))
        ->toContain('(display-mode: '.$manifest['display'].')')
        ->toContain('(display-mode: standalone)');
});

// The appearance cookie is written by the client and excepted from cookie
// encryption, so the tests below have to send it the same way the browser does.
test('the status bar colour follows the app theme, not the phone', function () {
    // Keyed on prefers-color-scheme, the bar tracked the phone while the app
    // tracked its own preference: a dark phone with the app on light got a
    // white app under a black bar.
    $this->withUnencryptedCookie('appearance', 'light')->get('/')
        ->assertSee('<meta name="theme-color" content="#ffffff">', false)
        ->assertDontSee('theme-color" content="#1c1c1c"', false);

    $this->withUnencryptedCookie('appearance', 'dark')->get('/')
        ->assertSee('<meta name="theme-color" content="#1c1c1c">', false)
        ->assertDontSee('theme-color" content="#ffffff"', false);

    // Only "system" still needs the pair, resolved by the phone.
    $this->withUnencryptedCookie('appearance', 'system')->get('/')
        ->assertSee('media="(prefers-color-scheme: light)"', false)
        ->assertSee('media="(prefers-color-scheme: dark)"', false);
});

test('the colour scheme is declared before any stylesheet so Android tints both bars', function () {
    // color-scheme was only ever set from applyTheme(), once the JS bundle had
    // run; until then Chrome treated the page as light and tinted the bottom
    // navigation bar - which it owns - accordingly.
    $html = $this->withUnencryptedCookie('appearance', 'dark')->get('/')->getContent();

    expect($html)->toContain('<meta name="color-scheme" content="dark">')
        ->and(strpos($html, 'name="color-scheme"'))->toBeLessThan(strpos($html, 'rel="stylesheet"'));

    // "system" leaves the choice to the phone, which is correct only there.
    expect($this->withUnencryptedCookie('appearance', 'system')->get('/')->getContent())
        ->toContain('<meta name="color-scheme" content="light dark">');
});

test('app template includes pwa meta tags and service worker registration', function () {
    $response = $this->get('/');

    $response->assertStatus(200)
        ->assertSee('apple-mobile-web-app-capable', false)
        ->assertSee('apple-mobile-web-app-status-bar-style', false)
        ->assertSee('content="default"', false)
        ->assertDontSee('black-translucent', false)
        ->assertSee('serviceWorker', false)
        ->assertSee("navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch", false)
        ->assertSee('viewport-fit=cover', false)
        ->assertSee("try {\n                    chartScheme = localStorage.getItem('chart-color-scheme')", false);
});
