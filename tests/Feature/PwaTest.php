<?php

test('service worker exists', function () {
    expect(file_exists(public_path('sw.js')))->toBeTrue();
});

test('web manifest starts at dashboard with standalone display', function () {
    $manifest = json_decode(file_get_contents(public_path('favicon/site.webmanifest')), true);

    // Standalone, not fullscreen: an installed Android PWA in standalone paints
    // the status bar from the page's theme-color meta, which follows the app's
    // appearance and changes at runtime. Fullscreen hides the status bar and
    // leaves the camera cutout as a black band at the top of the screen.
    expect($manifest['start_url'])->toBe('/dashboard')
        ->and($manifest['display'])->toBe('standalone');
});

test('the landing page detects an installed app in the display mode the manifest asks for', function () {
    $manifest = json_decode(file_get_contents(public_path('favicon/site.webmanifest')), true);

    // The landing page redirects installed users to the dashboard, and the
    // display-mode media feature only matches the mode that was actually applied.
    expect(file_get_contents(resource_path('js/pages/welcome.tsx')))
        ->toContain('(display-mode: '.$manifest['display'].')');
});

test('the landing page still detects the Android installs made under the fullscreen manifest', function () {
    // A WebAPK keeps the display mode it was minted with until Chrome updates it
    // to the current manifest, so those installs still run in fullscreen.
    expect(file_get_contents(resource_path('js/pages/welcome.tsx')))
        ->toContain('(display-mode: fullscreen)');
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

test('the colour scheme is declared before any stylesheet', function () {
    // color-scheme was only ever set from applyTheme(), once the JS bundle had
    // run; until then the page's form controls, scrollbars and canvas were drawn
    // light whatever the app theme.
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

test('the manifest has no theme colour, so Chrome keeps the navigation bar on the phone theme', function () {
    $manifest = json_decode(file_get_contents(public_path('favicon/site.webmanifest')), true);

    // A manifest is baked into the WebAPK at install time and cannot react to the
    // theme. From Chrome 156 an installed Android PWA paints its bottom navigation
    // bar with the manifest theme_color, one fixed colour whatever the app or the
    // phone is set to. Without one, Chrome keeps that bar on the phone's light or
    // dark theme, and the status bar follows the theme-color meta either way.
    expect($manifest)->not->toHaveKey('theme_color');
});

test('the manifest paints the splash with the light background', function () {
    $manifest = json_decode(file_get_contents(public_path('favicon/site.webmanifest')), true);

    // background_color only paints the splash screen, which cannot follow the
    // theme either, so it mirrors the light --background, the default.
    expect($manifest['background_color'])->toBe('#ffffff');
});
