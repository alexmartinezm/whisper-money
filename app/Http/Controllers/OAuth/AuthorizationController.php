<?php

namespace App\Http\Controllers\OAuth;

use App\Enums\OAuthScope;
use App\Http\Controllers\OAuth\Concerns\GuardsOAuthScopes;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Scope;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport's `GET /oauth/authorize`, bound in its place, with three rules on
 * top:
 *
 * - a `*` scope is treated as the default `mcp:use` before the request is
 *   validated, so it goes through the consent check like any other request and
 *   its token carries that scope (see OAuthScope::normalizeRequested());
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

    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        AuthorizationViewResponse $viewResponse,
    ): Response|AuthorizationViewResponse {
        return parent::authorize($this->withNormalizedScope($psrRequest), $request, $psrResponse, $viewResponse);
    }

    /**
     * Anything but a string is left for the server to refuse, and a missing or
     * blank scope already gets the default from it.
     */
    private function withNormalizedScope(ServerRequestInterface $psrRequest): ServerRequestInterface
    {
        $query = $psrRequest->getQueryParams();
        $scope = $query['scope'] ?? null;

        if (! is_string($scope)) {
            return $psrRequest;
        }

        return $psrRequest->withQueryParams(['scope' => OAuthScope::normalizeRequested($scope)] + $query);
    }

    /**
     * @return Scope[]
     */
    protected function parseScopes(AuthorizationRequestInterface $authRequest): array
    {
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
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        $scopeIds = array_map(fn (Scope $scope): string => $scope->id, $scopes);

        return $user instanceof User
            && OAuthScope::fromRequested($scopeIds)->approvalRefusalFor($user) === null
            && parent::hasGrantedScopes($user, $client, $scopes);
    }

    protected function approveRequest(AuthorizationRequestInterface $authRequest, ResponseInterface $psrResponse): Response
    {
        $this->ensureUserMayApprove($authRequest, $this->guard->user());

        return parent::approveRequest($authRequest, $psrResponse);
    }
}
