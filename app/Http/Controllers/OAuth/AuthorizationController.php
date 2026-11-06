<?php

namespace App\Http\Controllers\OAuth;

use App\Enums\OAuthScope;
use App\Http\Controllers\OAuth\Concerns\GuardsOAuthScopes;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Bridge\Scope as ScopeEntity;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Scope;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport's `GET /oauth/authorize`, bound in its place, with three rules on
 * top:
 *
 * - a `*` scope is taken out of the request League validated, leaving the
 *   default `mcp:use` if nothing else remains, so it goes through the consent
 *   check like any other request and its token carries that scope;
 * - a request asking for both `mcp:use` and `api:use` is refused with
 *   `invalid_scope`, so no token ever opens both surfaces;
 * - a request the user may not approve (`api:use` without the API plan, or
 *   from the demo account) is never approved silently from an earlier grant:
 *   the consent screen shows why instead of the approve button. A client
 *   that skips the consent screen altogether gets `access_denied`.
 */
class AuthorizationController extends PassportAuthorizationController
{
    use GuardsOAuthScopes;

    /**
     * Runs on the request League validated, before it is approved or stored in
     * the session for the consent screen, so all of them read the same scopes.
     *
     * @return Scope[]
     */
    protected function parseScopes(AuthorizationRequestInterface $authRequest): array
    {
        $this->replaceWildcard($authRequest);

        if (OAuthScope::spansSurfaces($this->requestedScopeIds($authRequest))) {
            $this->failAuthorization(
                $authRequest,
                'invalid_scope',
                'Request either the mcp:use or the api:use scope, not both.',
            );
        }

        return parent::parseScopes($authRequest);
    }

    /**
     * A wildcard grants nothing here: Passport cannot name it, so the consent
     * check would see no scope at all and skip the screen, and it never reaches
     * the token. Treating it as the default keeps a connector that sends it
     * working, through the same consent check as any other request.
     */
    private function replaceWildcard(AuthorizationRequestInterface $authRequest): void
    {
        $scopes = array_values(array_filter(
            $authRequest->getScopes(),
            fn (ScopeEntityInterface $scope): bool => $scope->getIdentifier() !== '*',
        ));

        $authRequest->setScopes($scopes === [] ? [new ScopeEntity(OAuthScope::Mcp->value)] : $scopes);
    }

    /**
     * Passport answers a request with no scope by asking whether the client
     * holds any token at all, whatever it was granted, so that never counts.
     *
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        $scopeIds = array_map(fn (Scope $scope): string => $scope->id, $scopes);

        return $scopeIds !== []
            && $user instanceof User
            && OAuthScope::fromRequested($scopeIds)->approvalRefusalFor($user) === null
            && parent::hasGrantedScopes($user, $client, $scopes);
    }

    protected function approveRequest(AuthorizationRequestInterface $authRequest, ResponseInterface $psrResponse): Response
    {
        $this->ensureUserMayApprove($authRequest, $this->guard->user());

        return parent::approveRequest($authRequest, $psrResponse);
    }
}
