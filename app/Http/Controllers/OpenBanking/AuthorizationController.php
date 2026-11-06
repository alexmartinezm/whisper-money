<?php

namespace App\Http\Controllers\OpenBanking;

use App\Actions\OpenBanking\RepointSiblingConnections;
use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Enums\BankingSyncTrigger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Http\Requests\OpenBanking\StartAuthorizationRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\EnableBankingSessionShape;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AuthorizationController extends Controller
{
    use CreatesAccountsFromPending;
    use HandlesSubscriptionGate;

    public function __construct(private RepointSiblingConnections $repointSiblingConnections) {}

    /**
     * Start the bank authorization flow.
     */
    public function store(StartAuthorizationRequest $request, BankingProviderInterface $provider): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        if ($this->shouldBlockOpenBankingAccess($user)) {
            return $this->subscribeJsonResponse();
        }

        $validated = $request->validated();

        $redirectUrl = config('services.enablebanking.redirect_url');
        $stateToken = Str::random(40);

        $result = $provider->startAuthorization(
            $validated['aspsp_name'],
            $validated['country'],
            $redirectUrl,
            $stateToken,
        );

        $connection = $user->bankingConnections()->create([
            'provider' => BankingProvider::EnableBanking,
            'authorization_id' => $result['authorization_id'],
            'state_token' => $stateToken,
            'aspsp_name' => $validated['aspsp_name'],
            'aspsp_country' => $validated['country'],
            'aspsp_logo' => $validated['logo'] ?? null,
            'status' => BankingConnectionStatus::Pending,
        ]);

        return response()->json([
            'redirect_url' => $result['url'],
            'connection_id' => $connection->id,
        ]);
    }

    /**
     * Re-authorize an existing EnableBanking connection whose session has been revoked.
     */
    public function reauthorize(Request $request, BankingConnection $connection, BankingProviderInterface $provider): JsonResponse
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }

        if ($this->shouldBlockOpenBankingAccess($request->user())) {
            return $this->subscribeJsonResponse();
        }

        $result = $this->startReauthorization($connection, $provider);

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json([
            'redirect_url' => $result['url'],
            'connection_id' => $connection->id,
        ]);
    }

    /**
     * Start reconnect flow from email or UI links.
     */
    public function reconnect(Request $request, BankingConnection $connection, BankingProviderInterface $provider): RedirectResponse
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }

        if ($this->shouldBlockOpenBankingAccess($request->user())) {
            return $this->subscribeRedirectResponse();
        }

        $result = $this->startReauthorization($connection, $provider);

        if (isset($result['error'])) {
            return redirect()->route('settings.connections.index')
                ->with('error', $result['error']);
        }

        return redirect()->away($result['url']);
    }

    /**
     * @return array{url?: string, authorization_id?: string, error?: string}
     */
    private function startReauthorization(BankingConnection $connection, BankingProviderInterface $provider): array
    {
        if (! $connection->isEnableBanking()) {
            return ['error' => 'Only EnableBanking connections can be re-authorized.'];
        }

        // Active is in here so the user can renew a healthy consent before it
        // lapses: the alternative is a few days of broken syncing at the end of
        // every window. Which day the offer appears is the UI's and the warning
        // email's business, not this gate's.
        $renewable = $connection->isActive()
            || $connection->isExpired()
            || $connection->status === BankingConnectionStatus::Error
            || $this->isAbandonedReauthorization($connection);

        if (! $renewable) {
            return ['error' => 'Only active, error, expired or unfinished reconnects can be re-authorized.'];
        }

        $redirectUrl = config('services.enablebanking.redirect_url');
        $stateToken = Str::random(40);

        $result = $provider->startAuthorization(
            $connection->aspsp_name,
            $connection->aspsp_country,
            $redirectUrl,
            $stateToken,
        );

        $connection->update([
            'authorization_id' => $result['authorization_id'],
            'state_token' => $stateToken,
            // A healthy connection stays Active through an early renewal. Its
            // current consent still works, so syncing carries on while the user
            // is at the bank, and abandoning the flow there leaves a working
            // connection working. Only an unusable one has nothing to lose by
            // going Pending.
            'status' => $connection->isActive()
                ? BankingConnectionStatus::Active
                : BankingConnectionStatus::Pending,
            'error_message' => null,
        ]);

        return $result;
    }

    /**
     * A Pending connection that already has accounts is a re-authorization the
     * user left at the bank. No callback ever comes for an abandoned flow, and
     * the scheduled sync skips Pending, so without this it is stuck for good.
     * A first connection is Pending with no accounts, and stays out of here.
     */
    private function isAbandonedReauthorization(BankingConnection $connection): bool
    {
        return $connection->status === BankingConnectionStatus::Pending
            && $connection->accounts()->exists();
    }

    /**
     * Handle the callback from bank authorization.
     *
     * This route is intentionally unauthenticated. iOS PWAs hand the bank redirect
     * back to the system browser (Safari), where the app session does not exist, so
     * the connection is resolved from the signed state token EnableBanking echoes
     * back rather than from the logged-in session.
     *
     * That split is also a race: the return URL is back inside the PWA scope, so
     * iOS may deliver it to the app, to Safari, or to both. The context that did
     * not run the SCA reports `access_denied` seconds after the flow starts and
     * soft-deletes the connection the other one is still authorizing. So the
     * callback carrying a code the provider accepts wins whatever the arrival
     * order: a code EnableBanking honours is proof the user did authorize, and
     * nothing weaker resurrects a row. Completion burns the state token, which
     * is what keeps a finished connection out of a second callback's reach.
     */
    public function callback(Request $request, BankingProviderInterface $provider, AccountUserCurrencyService $accountUserCurrencyService): RedirectResponse|Response
    {
        $connection = $this->resolveConnectionFromState($request);
        $user = $connection ? $connection->user : auth()->user();

        if (! $user) {
            // Silent until now, which made "the bank never came back" and "it
            // came back and we dropped it" indistinguishable in the logs.
            // Presence only: the state token and the code are credentials.
            Log::warning('EnableBanking callback could not be attributed to a user', [
                'has_state' => $request->has('state'),
                'has_code' => $request->has('code'),
            ]);

            return redirect()->route('login')
                ->with('error', __('Please log back in to finish connecting your bank account.'));
        }

        if ($request->has('error')) {
            return $this->handleAuthorizationError($request, $user, $connection);
        }

        $code = $request->query('code');

        // query() hands back an array for ?code[]=, which is truthy but not a code.
        if (! $code || ! is_string($code)) {
            return $this->finishWithError($user, 'No authorization code received.');
        }

        $sessionData = $this->createProviderSession($provider, $code, $connection);

        if ($sessionData === null) {
            return $this->finishWithError($user, __('Failed to connect to your bank. Please try again.'));
        }

        $connection ??= $this->findPendingConnectionForSession($user, $sessionData);

        if (! $connection) {
            return $this->finishWithError($user, 'No pending connection found.');
        }

        // Undo the cancellation an orphaned context sent while the user was
        // still at their bank. See the race in this method's docblock.
        if ($connection->trashed()) {
            $connection->restore();
        }

        $isReconnect = $connection->accounts()->exists();

        if ($isReconnect) {
            return $this->completeReconnect($user, $connection, $sessionData, $provider);
        }

        return $this->completeFirstConnection($user, $connection, $sessionData, $accountUserCurrencyService, $provider);
    }

    /**
     * Exchange the authorization code for a provider session, or null when the
     * exchange fails. A failed exchange burns the state token with it.
     *
     * @return array<string, mixed>|null
     */
    private function createProviderSession(BankingProviderInterface $provider, string $code, ?BankingConnection $connection): ?array
    {
        try {
            return $provider->createSession($code);
        } catch (\Throwable $e) {
            // The bank belongs here as much as the message does. Without it
            // this was the one line in the flow that could not say which
            // connector had failed, so a wave from a single bank read as
            // scattered noise.
            Log::error('EnableBanking session creation failed', [
                'error' => $e->getMessage(),
                ...$connection?->logContext() ?? [],
            ]);

            if ($connection) {
                $connection->update(['state_token' => null]);
            }

            return null;
        }
    }

    /**
     * Point a connection that already has accounts at its new session and resync it.
     *
     * A throw leaves the connection, its accounts and its siblings as they were.
     * The bank has already spent the code, so a second callback could only fail:
     * the state token is burnt and the user is told, rather than left on a 500
     * with a connection still waiting for this one.
     *
     * The flash has no warning level, so a reconnect that lost an account goes
     * out as an error: a success toast would hide that something stopped syncing.
     *
     * @param  array<string, mixed>  $sessionData
     */
    private function completeReconnect(User $user, BankingConnection $connection, array $sessionData, BankingProviderInterface $provider): RedirectResponse|Response
    {
        try {
            $unlinked = DB::transaction(fn (): ?int => $this->applyReconnect($connection, $sessionData));
        } catch (\Throwable $e) {
            report($e);

            $this->revokeUnclaimedSession($provider, $connection, $sessionData);
            $connection->refresh()->update(['state_token' => null]);

            return $this->finishWithError($user, __('We could not complete the connection with your bank. Please try again later.'));
        }

        if ($unlinked === null) {
            $this->revokeUnclaimedSession($provider, $connection, $sessionData);

            return $this->finishWithError($user, __(BankingConnection::WRONG_LOGIN_ERROR));
        }

        SyncBankingConnectionJob::dispatch($connection, trigger: BankingSyncTrigger::Reconnect);

        return $unlinked === 0
            ? $this->finishRedirect('settings.connections.index', [], 'success', __('Bank account reconnected successfully.'))
            : $this->finishRedirect('settings.connections.index', [], 'error', trans_choice('{1}Reconnected, but :count account no longer syncs. Review it in Manage Accounts.|[2,*]Reconnected, but :count accounts no longer sync. Review them in Manage Accounts.', $unlinked));
    }

    /**
     * Close a session the reconnect did not end up using, so it does not stay
     * open at the provider with nothing here to revoke it later. A connection
     * holding it (a sibling that took it) keeps it. Best effort: the user's
     * outcome is already decided, and a failure here must not change it.
     *
     * @param  array<string, mixed>  $sessionData
     */
    private function revokeUnclaimedSession(BankingProviderInterface $provider, BankingConnection $connection, array $sessionData): void
    {
        $sessionId = $sessionData['session_id'] ?? null;

        try {
            if (is_string($sessionId) && ! BankingConnection::query()->where('session_id', $sessionId)->exists()) {
                $provider->revokeSession($sessionId);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to revoke an unclaimed EnableBanking session', [
                ...$connection->logContext(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Move the connection and its accounts onto the new session, or record that
     * the session covers none of them.
     *
     * That happens when the user signs in with a different login, such as the one
     * a sibling connection uses, whose accounts the sibling claims. Taking the
     * session would leave an Active connection with every account unlinked and
     * nothing syncing, so it keeps its old session and uids and the user is sent
     * back to reconnect. The sibling keeps the session: it does cover the sibling,
     * and this bank may have just revoked the one the sibling held.
     *
     * @param  array<string, mixed>  $sessionData
     * @return int|null The accounts left unlinked, or null when none was covered.
     */
    private function applyReconnect(BankingConnection $connection, array $sessionData): ?int
    {
        $siblingUids = $this->repointSiblingConnections->handle($connection, $sessionData);

        $accounts = $connection->accounts()->orderBy('created_at')->get();
        $sessionAccounts = $sessionData['accounts'];
        $matches = $this->matchSessionAccounts($accounts, $sessionAccounts, $siblingUids);

        if (! empty($sessionAccounts) && $matches === []) {
            $this->recordWrongLogin($connection);

            return null;
        }

        $connection->activateWithSession($sessionData, ['state_token' => null]);

        return empty($sessionAccounts) ? 0 : $this->refreshAccountIds($connection, $accounts, $matches);
    }

    /**
     * Leave the connection on its old session, marked for a reconnect.
     */
    private function recordWrongLogin(BankingConnection $connection): void
    {
        $connection->update([
            'status' => $connection->statusAfterFailedReconnect(),
            'error_message' => BankingConnection::WRONG_LOGIN_ERROR,
            'state_token' => null,
        ]);

        Log::warning('EnableBanking reconnect covered none of the connection\'s accounts', $connection->logContext());
    }

    /**
     * Park the fetched accounts on the connection so the user can map them.
     *
     * Onboarding skips the mapping screen: every account is created up front so the
     * user lands back on the onboarding step with data already syncing.
     *
     * @param  array<string, mixed>  $sessionData
     */
    private function completeFirstConnection(
        User $user,
        BankingConnection $connection,
        array $sessionData,
        AccountUserCurrencyService $accountUserCurrencyService,
        BankingProviderInterface $provider,
    ): RedirectResponse|Response {
        if (! is_array($sessionData['accounts'] ?? null) || $sessionData['accounts'] === []) {
            // Before the revoke and the delete, not after: those two are what
            // stop a connection stranding in `awaiting_mapping` with nothing to
            // map, and between them they destroy every trace of why it was
            // empty. The connection's own record wins the keys it shares with
            // the session body, which is how the rest of this flow logs a bank.
            Log::warning('EnableBanking session has no accounts', [
                'stage' => 'callback',
                ...EnableBankingSessionShape::describe($sessionData),
                ...$connection->logContext(),
            ]);

            $bankName = $connection->aspsp_name;

            try {
                if (is_string($sessionData['session_id'] ?? null)) {
                    $provider->revokeSession($sessionData['session_id']);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to revoke EnableBanking session without accounts', [
                    'connection_id' => $connection->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $connection->delete();

            // Distinct from the exchange failure above, because the two ask the
            // user for different things. This one succeeded: the bank was asked
            // and shared nothing, so "please try again" sends them round a loop
            // that ends here every time.
            return $this->finishWithError($user, __(':bank did not share any accounts through open banking. Not every account type is available this way — contact us if yours should be.', ['bank' => $bankName]));
        }

        $connection->update([
            'session_id' => $sessionData['session_id'],
            'status' => BankingConnectionStatus::AwaitingMapping,
            'valid_until' => $sessionData['access']['valid_until'] ?? null,
            'pending_accounts_data' => $sessionData['accounts'],
            'state_token' => null,
        ]);

        // The new session has just revoked the one any sibling still holds,
        // whether or not the user finishes mapping this connection.
        $this->repointSiblingConnections->handle($connection, $sessionData);

        if (! $user->isOnboarded()) {
            $this->createAccountsFromPending($user, $connection, $accountUserCurrencyService);
            SyncBankingConnectionJob::dispatch($connection, trigger: BankingSyncTrigger::Connect);

            return $this->finishRedirect('onboarding', ['step' => 'create-account'], 'success', 'Bank account connected successfully.');
        }

        return $this->finishRedirect('open-banking.map-accounts', ['connection' => $connection]);
    }

    /**
     * Clean up after an authorization the bank did not complete.
     *
     * A pending connection that already has accounts is a reconnect, so it is kept
     * and marked as failing. A brand new one has nothing worth keeping.
     *
     * Only the connection the state token names is touched. The old fallback -
     * the user's latest pending connection - deleted whatever it happened to
     * find: in production an error belonging to a 10:57 attempt deleted the
     * connection started at 09:41. An error we cannot attribute is still shown
     * to the user, but it takes no row down with it.
     *
     * The state token deliberately survives an error, so the winner of the race
     * in callback() can still claim this attempt.
     */
    private function handleAuthorizationError(Request $request, User $user, ?BankingConnection $connection): RedirectResponse|Response
    {
        $errorCode = $request->query('error');
        $errorDescription = $request->query('error_description');
        $errorMessage = $this->authorizationErrorMessage(
            is_string($errorCode) ? $errorCode : null,
            is_string($errorDescription) ? $errorDescription : null,
        );

        // The bank is logged because these failures are bank-specific, and the
        // connection row is about to be deleted on one of the branches below.
        Log::warning('EnableBanking authorization error', [
            'error' => $errorCode,
            'description' => $errorDescription,
            'aspsp_name' => $connection?->aspsp_name,
            'connection_id' => $connection?->id,
        ]);

        // An Active connection can only be here because the user started an
        // early renewal and did not finish it. Backing out of the bank's screen
        // is a perfectly reasonable thing to do, and the consent the connection
        // already holds is untouched, so it keeps syncing as if nothing had
        // happened. Only a connection that was already unusable is recorded as
        // failed - or dropped, when there is nothing behind it yet.
        if ($connection && ! $connection->isActive()) {
            if ($connection->accounts()->exists()) {
                $connection->update([
                    'status' => $connection->statusAfterFailedReconnect(),
                    'error_message' => $errorMessage,
                ]);
            } else {
                $connection->delete();
            }
        }

        return $this->finishWithError($user, $errorMessage);
    }

    /**
     * The message the user sees when an authorization comes back as an error.
     *
     * `access_denied` is the only code the user causes, and the only one whose
     * description is worth showing ("Cancelled by user"). The rest are ours or the
     * bank's to fix and arrive either with no description at all or with
     * untranslated prose addressed to us, so they get our own copy — worded
     * without pinning it on the bank, since `invalid_client` is our credentials.
     */
    private function authorizationErrorMessage(?string $errorCode, ?string $errorDescription): string
    {
        if ($errorCode !== 'access_denied') {
            return __('We could not complete the connection with your bank. Please try again later.');
        }

        return $errorDescription === null || $errorDescription === ''
            ? __('Authorization was denied or cancelled.')
            : $errorDescription;
    }

    /**
     * Abandon the callback with a message, sending the user wherever they came from.
     *
     * A user still onboarding has no connections screen to land on yet.
     */
    private function finishWithError(User $user, string $message): RedirectResponse|Response
    {
        return $user->isOnboarded()
            ? $this->finishRedirect('settings.connections.index', [], 'error', $message)
            : $this->finishRedirect('onboarding', ['step' => 'create-account'], 'error', $message);
    }

    /**
     * Resolve the connection a callback belongs to from the state token EnableBanking
     * echoes back. This works without a logged-in session.
     */
    private function resolveConnectionFromState(Request $request): ?BankingConnection
    {
        $stateToken = $request->query('state');

        if (! is_string($stateToken) || $stateToken === '') {
            return null;
        }

        // Trashed rows count: a cancelled attempt is exactly what the winner of
        // the race in callback() has to land on. `state_token` is unique, so a
        // trashed row still holds the index and this stays single-valued.
        return BankingConnection::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->where('state_token', $stateToken)
            ->first();
    }

    /**
     * Finish a callback, accounting for the unauthenticated PWA case.
     *
     * When the callback runs without a session (Safari), the destination routes are
     * behind auth middleware and the user is not logged in on this browser. The
     * connection has already been finalized server-side, so render a standalone page
     * telling the user to return to the app rather than bouncing them to login.
     *
     * @param  array<string, mixed>  $params
     */
    private function finishRedirect(string $route, array $params, ?string $flashKey = null, ?string $flashMessage = null): RedirectResponse|Response
    {
        if (! Auth::check()) {
            return Inertia::render('open-banking/connection-complete', [
                'status' => $flashKey === 'error' ? 'error' : 'success',
                'message' => $flashMessage ?? __('Your bank account is connected.'),
            ]);
        }

        $redirect = redirect()->route($route, $params);

        if ($flashKey !== null && $flashMessage !== null) {
            $redirect->with($flashKey, $flashMessage);
        }

        return $redirect;
    }

    /**
     * Find the pending connection that belongs to the callback session.
     *
     * Multiple reconnection flows may be pending at the same time. Never pick an
     * arbitrary latest connection, because that can attach one bank's session and
     * transactions to another bank's existing account.
     *
     * @param  array{aspsp?: array{name?: string, country?: string}, accounts?: array<int, array<string, mixed>>}  $sessionData
     */
    private function findPendingConnectionForSession(User $user, array $sessionData): ?BankingConnection
    {
        $pendingConnections = $user->bankingConnections()
            ->where('status', BankingConnectionStatus::Pending)
            ->get();

        if ($pendingConnections->isEmpty()) {
            return null;
        }

        $aspspName = $sessionData['aspsp']['name'] ?? null;
        $aspspCountry = $sessionData['aspsp']['country'] ?? null;

        if (is_string($aspspName) && is_string($aspspCountry)) {
            $matchedByInstitution = $pendingConnections
                ->first(fn (BankingConnection $connection): bool => $connection->aspsp_name === $aspspName
                    && $connection->aspsp_country === $aspspCountry);

            if ($matchedByInstitution) {
                return $matchedByInstitution;
            }
        }

        $ibans = collect($sessionData['accounts'] ?? [])
            ->map(fn (array $account): ?string => $account['account_id']['iban'] ?? null)
            ->filter()
            ->values();

        if ($ibans->isNotEmpty()) {
            $matchedByIban = $pendingConnections
                ->first(fn (BankingConnection $connection): bool => $connection->accounts()
                    ->whereIn('iban', $ibans)
                    ->exists());

            if ($matchedByIban) {
                return $matchedByIban;
            }
        }

        if ($pendingConnections->count() === 1) {
            return $pendingConnections->first();
        }

        Log::warning('Unable to disambiguate pending EnableBanking callback', [
            'user_id' => $user->id,
            'pending_connection_ids' => $pendingConnections->pluck('id')->all(),
            'aspsp_name' => is_string($aspspName) ? $aspspName : null,
            'aspsp_country' => is_string($aspspCountry) ? $aspspCountry : null,
        ]);

        return null;
    }

    /**
     * Which session account each existing account becomes after a reconnect.
     *
     * Enable Banking issues new account UIDs with every new session, so the stored
     * external_account_id values become invalid as soon as the old session expires.
     *
     * Matching strategy (in priority order):
     *   1. Match by IBAN — reliable when the account was created after the iban column existed.
     *      Currency only tells apart one IBAN listed twice, so a second round ignores it: an
     *      account created while the bank reported "XXX" holds the user's base currency, and
     *      the bank's later, real currency must not refuse its own IBAN.
     *   2. When the session lists no IBANs at all, so the bank does not return them, every
     *      account left takes a session account by position, and matching ends there.
     *   3. An account without a stored IBAN takes a session account without one, which is
     *      what a card looks like on both sides.
     *   4. An account without a stored IBAN takes any session account left. A stored IBAN the
     *      session lacks means this bank account is not in it, and taking another one would
     *      import its transactions here.
     *
     * Uids a sibling connection has already claimed by IBAN are out of every step but the
     * first, and each step takes the first session account that fits, in creation order.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<int, array<string, mixed>>  $newAccounts
     * @param  list<string>  $siblingUids
     * @return array<string, array<string, mixed>> Session accounts keyed by account id.
     */
    private function matchSessionAccounts(Collection $accounts, array $newAccounts, array $siblingUids): array
    {
        $sessionListsIbans = collect($newAccounts)->contains(fn (array $data): bool => $this->sessionIban($data) !== null);
        $unmatchedNew = collect($newAccounts)->filter(fn (array $data): bool => ! empty($data['uid']))->values();

        $sameIban = fn (Account $account, array $data): bool => $this->normalizedIban($account->iban) !== null
            && $this->normalizedIban($account->iban) === $this->sessionIban($data);

        $matches = $this->pairAccounts($accounts, $unmatchedNew, $sameIban);
        $matches += $this->pairAccounts($accounts->except(array_keys($matches)), $unmatchedNew, $sameIban, separateCurrencies: false);

        $unmatchedNew = $unmatchedNew->reject(fn (array $data) => in_array($data['uid'], $siblingUids, true))->values();

        if (! $sessionListsIbans) {
            return $matches + $this->pairAccounts($accounts->except(array_keys($matches)), $unmatchedNew, fn (): bool => true);
        }

        $withoutIban = $accounts->filter(fn (Account $account): bool => $this->normalizedIban($account->iban) === null);

        $matches += $this->pairAccounts($withoutIban, $unmatchedNew, fn (Account $account, array $data): bool => $this->sessionIban($data) === null);
        $matches += $this->pairAccounts($withoutIban->except(array_keys($matches)), $unmatchedNew, fn (): bool => true);

        return $matches;
    }

    /**
     * Give each account the first session account $accepts allows, taking it out
     * of $sessionAccounts. Two known currencies that differ do not pair unless
     * $separateCurrencies is off: a session can list one IBAN twice, once per currency.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  SupportCollection<int, array<string, mixed>>  $sessionAccounts
     * @param  callable(Account, array<string, mixed>): bool  $accepts
     * @return array<string, array<string, mixed>>
     */
    private function pairAccounts(Collection $accounts, SupportCollection &$sessionAccounts, callable $accepts, bool $separateCurrencies = true): array
    {
        $pairs = [];

        foreach ($accounts as $account) {
            $matched = $sessionAccounts->first(fn (array $data): bool => $accepts($account, $data)
                && ! ($separateCurrencies && $this->currenciesDiffer($account->currency_code, $data['currency'] ?? null)));

            if ($matched) {
                $pairs[$account->id] = $matched;
                $sessionAccounts = $sessionAccounts->reject(fn (array $data) => $data['uid'] === $matched['uid'])->values();
            }
        }

        return $pairs;
    }

    /**
     * Whether both currencies are known and differ. "XXX" is ISO 4217's "no
     * currency", which banks do send, so it counts as unknown.
     */
    private function currenciesDiffer(?string $first, ?string $second): bool
    {
        $known = collect([$first, $second])
            ->map(fn (?string $currency): string => strtoupper(trim((string) $currency)))
            ->reject(fn (string $currency): bool => $currency === '' || $currency === 'XXX');

        return $known->count() === 2 && $known->unique()->count() === 2;
    }

    /**
     * The normalized IBAN of a session account, or null when it has none.
     *
     * @param  array<string, mixed>  $sessionAccount
     */
    private function sessionIban(array $sessionAccount): ?string
    {
        return $this->normalizedIban($sessionAccount['account_id']['iban'] ?? null);
    }

    /**
     * The IBAN without spaces, upper case, or null when there is none. Not
     * ImportIban::normalize(): that one also drops an IBAN whose check digits
     * fail, which here would turn a known account into one with no IBAN.
     */
    private function normalizedIban(?string $iban): ?string
    {
        $normalized = strtoupper((string) preg_replace('/\s+/', '', (string) $iban));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Refresh external_account_id and iban on existing accounts after a reconnect.
     *
     * An account with no match is one the new session does not cover (a closed
     * account, say), so it is unlinked rather than left holding a uid of the
     * session this reconnect just replaced: the sync skips an unlinked account,
     * whereas the old uid answers CLOSED_SESSION and would expire the whole
     * connection along with the session it was just given.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array<string, array<string, mixed>>  $matches
     * @return int The accounts left unlinked.
     */
    private function refreshAccountIds(BankingConnection $connection, Collection $accounts, array $matches): int
    {
        $unlinked = 0;

        foreach ($accounts as $account) {
            $matched = $matches[$account->id] ?? null;

            if (! $matched) {
                $account->update(['external_account_id' => null]);

                Log::warning('Unlinked an EnableBanking account its new session does not return', [
                    ...$connection->logContext(),
                    'account_id' => $account->id,
                ]);

                $unlinked++;

                continue;
            }

            $account->update([
                'external_account_id' => $matched['uid'],
                'iban' => $matched['account_id']['iban'] ?? $account->iban,
            ]);
        }

        return $unlinked;
    }
}
