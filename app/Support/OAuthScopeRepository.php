<?php

namespace App\Support;

use App\Enums\OAuthScope;
use Laravel\Passport\Bridge\ScopeRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * Passport's scope repository, bound in its place, as the last stop before a
 * token or an authorization code is issued: an authorization code never ends up
 * with no scope, because `/mcp/oauth` has to keep accepting such tokens from
 * before `mcp:use` was the default (see EnsureMcpOAuthToken).
 *
 * AuthorizationController already gives every request an explicit scope; this
 * catches what bypasses it, such as a consent screen left open across a deploy.
 * A refresh is left alone on purpose: it keeps the scopes the token was issued
 * with, which is what keeps those older connections working.
 */
class OAuthScopeRepository extends ScopeRepository
{
    /**
     * @param  ScopeEntityInterface[]  $scopes
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        $finalized = parent::finalizeScopes($scopes, $grantType, $clientEntity, $userIdentifier, $authCodeId);

        if ($finalized !== [] || $grantType !== 'authorization_code') {
            return $finalized;
        }

        return array_filter([$this->getScopeEntityByIdentifier(OAuthScope::Mcp->value)]);
    }
}
