<?php

namespace App\Http\Middleware;

use Laravel\Passport\AccessToken;
use Laravel\Passport\Contracts\ScopeAuthorizable;
use Laravel\Passport\Http\Middleware\CheckToken;

/**
 * Keeps other surfaces' OAuth tokens out of `/mcp/oauth`: a token must carry
 * `mcp:use`, so an `api:use` token is refused with a 403.
 *
 * The one exception to Passport's CheckToken is a token with no scopes at all.
 * Until `mcp:use` became the default scope, a connector that asked for none, or
 * for `*`, got exactly that, and those connections (and whatever they refresh
 * into, since a refresh keeps the scopes it was given) have to keep working.
 * New authorizations cannot produce one: AuthorizationController gives every
 * validated request a surface scope, and OAuthScopeRepository does the same for
 * any authorization code that gets past it, or refuses one that lost its scope.
 * A refresh cannot either: league falls back to the original scopes on a blank
 * `scope`, and refuses any it does not hold, `*` included.
 */
class EnsureMcpOAuthToken extends CheckToken
{
    protected function validate(ScopeAuthorizable $token, string ...$params): void
    {
        if ($token instanceof AccessToken && ($token->oauth_scopes ?? []) === []) {
            return;
        }

        parent::validate($token, ...$params);
    }
}
