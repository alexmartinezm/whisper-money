<?php

namespace App\Mcp;

use App\Enums\OAuthScope;
use App\Http\Controllers\OAuth\RegisterController;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Registrar;

/**
 * The MCP registrar, taught that the authorization server it exposes also
 * serves the public API. Bound in place of the package's Registrar, so
 * `Mcp::oauthRoutes()` runs through here.
 *
 * The protected resource metadata is per resource: `/api/v1` advertises
 * `api:use` and everything else (`/mcp/oauth`) keeps `mcp:use` alone. That is
 * a route of its own rather than an override of `protectedResourceMetadata()`:
 * the package's routes call it through `static::`, and `route:cache`, which
 * production runs, restores those closures on the package's class, so an
 * override would silently stop applying there.
 *
 * The authorization server metadata is deliberately left as the package serves
 * it, listing only `mcp:use`, unlike upstream's. It is what the live connectors
 * read: one that requested every scope listed there would ask for both surfaces
 * and be refused with `invalid_scope`. An API app finds `api:use` on the
 * `/api/v1` resource metadata instead.
 */
class OAuthRegistrar extends Registrar
{
    /**
     * Called by `Mcp::oauthRoutes()`. Registers every scope, not just
     * `mcp:use`, merging like the parent does.
     *
     * @return array<string, string>
     */
    public static function ensureMcpScope(): array
    {
        return OAuthScope::register();
    }

    /**
     * The resource metadata route goes ahead of the package's catch-all, which
     * would otherwise answer for `/api/v1` too. The registration route goes
     * after the package's, which it replaces (same method and URI): the package
     * offers no way to put a rate limit on its own.
     */
    public function oauthRoutes(string $oauthPrefix = 'oauth'): void
    {
        Route::get('/.well-known/oauth-protected-resource/api/v1/{path?}', static fn (?string $path = null) => response()->json([
            ...static::protectedResourceMetadata(rtrim("api/v1/{$path}", '/')),
            'scopes_supported' => [OAuthScope::Api->value],
        ]))
            ->where('path', '.*')
            ->name('mcp.oauth.protected-resource.api');

        parent::oauthRoutes($oauthPrefix);

        Route::post($oauthPrefix.'/register', RegisterController::class)
            ->middleware('throttle:oauth-register')
            ->name('mcp.oauth.register');
    }
}
