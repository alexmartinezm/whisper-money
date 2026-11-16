<?php

use App\Services\Widgets\WidgetRules;
use Illuminate\Support\Facades\File;

/**
 * The files the widget rules live in: every non-test source of the
 * validator, and the runtime hardening that backs it.
 *
 * @return list<string> paths relative to the project, sorted
 */
function widgetRuleSources(): array
{
    $validator = collect(File::files(base_path('resources/js/widgets/validator')))
        ->map(fn (SplFileInfo $file): string => 'resources/js/widgets/validator/'.$file->getFilename())
        ->reject(fn (string $path): bool => str_contains($path, '.test.'));

    return $validator
        ->push('resources/js/widgets/runtime/harden-intrinsics.ts')
        ->sort()
        ->values()
        ->all();
}

function widgetRuleSourcesHash(): string
{
    $context = hash_init('sha256');

    foreach (widgetRuleSources() as $path) {
        hash_update($context, $path."\n".str_replace("\r\n", "\n", (string) file_get_contents(base_path($path)))."\n");
    }

    return hash_final($context);
}

it('pins the widget rules to their version', function () {
    expect(widgetRuleSourcesHash())->toBe(
        WidgetRules::SourcesHash,
        'The widget validator changed: bump WidgetRules::VERSION and update the pinned hash (WidgetRules::SourcesHash), so the widgets stored under the old rules are checked again.',
    );
});

it('covers the validator and the runtime hardening', function () {
    expect(widgetRuleSources())
        ->toContain('resources/js/widgets/validator/allowlist.ts', 'resources/js/widgets/validator/global-rules.ts', 'resources/js/widgets/runtime/harden-intrinsics.ts')
        ->not->toContain('resources/js/widgets/validator/validate-widget.test.ts');
});
