<?php

namespace App\Services\Widgets;

/**
 * The version of the rules a widget's TSX is validated against
 * (resources/js/widgets/validator) and of the runtime hardening that backs
 * them (resources/js/widgets/runtime/harden-intrinsics.ts). Every widget
 * stores the version it was last checked under; one stored under an older
 * version is checked again before its module is served again
 * ({@see WidgetDefinitionService::recheck()}, `widgets:revalidate`).
 *
 * WidgetRulesVersionTest pins SourcesHash to those files: changing them
 * fails it until VERSION is bumped and the hash updated, so the rules never
 * change without the stored widgets being re-checked.
 */
final class WidgetRules
{
    /** 1: the rules widgets were first saved under. 2: fork hardening. */
    public const int VERSION = 2;

    /** sha256 of the rule sources, as WidgetRulesVersionTest computes it. */
    public const string SourcesHash = '9b7b24b77865bae107b29eaf7f52bd4530e9bcb39260bac524c32d8a3d341c7b';

    /**
     * What a widget that no longer passes the rules is served instead of its
     * module: a component that draws nothing, in the shape the compiler emits,
     * so every loader takes it and nothing can serve the old code by mistake.
     */
    public const string InertModule = 'function BlockedWidget(){return null}export{BlockedWidget as default};';

    /** The `phase` of the last_error a widget blocked by the rules carries. */
    public const string ErrorPhase = 'rules';
}
