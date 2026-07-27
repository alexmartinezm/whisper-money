<?php

test('authenticated sidebar layout applies the safe area inset on the scrolling shell', function () {
    $layout = file_get_contents(resource_path('js/layouts/app/app-sidebar-layout.tsx'));
    $header = file_get_contents(resource_path('js/components/app-sidebar-header.tsx'));

    expect($layout)
        ->toContain('pt-safe')
        ->toContain('pb-[calc(90px+var(--safe-area-bottom))]')
        ->toContain('md:pb-0')
        ->and($header)->not->toContain('pt-safe');
});
