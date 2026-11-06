<?php

namespace App\Support;

use App\Enums\OAuthScope;
use Laravel\Passport\Bridge\ScopeRepository;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

/**
 * Passport's scope repository, bound in its place, as the last stop before a
 * token or an authorization code is issued. `/mcp/oauth` has to keep accepting
 * tokens with no scope from before `mcp:use` was the default (see
 * EnsureMcpOAuthToken), so nothing new may come out of here with none:
 *
 * - a surface scope that did not survive is refused, on any grant. Issuing the
 *   empty set would quietly move that client onto MCP.
 * - an authorization code that never held a surface scope gets `mcp:use`.
 *   AuthorizationController already gives every request one; this catches what
 *   bypasses it, such as a consent screen left open across a deploy.
 *
 * A refresh of a token that never held a surface scope stays as it is: it keeps
 * the scopes it was issued with, which is what keeps those connections working.
 */
class OAuthScopeRepository extends ScopeRepository
{
    /**
     * @param  ScopeEntityInterface[]  $scopes
     * @return ScopeEntityInterface[]
     *
     * @throws OAuthServerException
     */
    public function finalizeScopes(
        array $scopes,
        string $grantType,
        ClientEntityInterface $clientEntity,
        ?string $userIdentifier = null,
        ?string $authCodeId = null,
    ): array {
        $finalized = parent::finalizeScopes($scopes, $grantType, $clientEntity, $userIdentifier, $authCodeId);

        if ($finalized !== []) {
            return $finalized;
        }

        foreach ($scopes as $scope) {
            if (OAuthScope::tryFrom($scope->getIdentifier()) !== null) {
                throw OAuthServerException::invalidScope($scope->getIdentifier());
            }
        }

        return $grantType === 'authorization_code'
            ? array_filter([$this->getScopeEntityByIdentifier(OAuthScope::Mcp->value)])
            : [];
    }
}
