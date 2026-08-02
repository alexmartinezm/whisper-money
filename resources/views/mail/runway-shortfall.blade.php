<x-mail::message>
# {{ __('Your balance runs out before payday') }}

{{ __('Hi :name, at the current rate your spendable balance dips to :amount around :date.', [
    'name' => $user->name,
    'amount' => \App\Support\Money::format($lowest['balance'], $currencyCode),
    'date' => \Carbon\CarbonImmutable::parse($lowest['date'])->translatedFormat('D, j M'),
]) }}

@if ($includesEverydaySpending)
{{ __('That figure counts your recurring charges plus what you usually spend on everything else, so it is an estimate rather than a certainty.') }}
@else
{{ __('That figure counts only the recurring charges we know about, so the real dip is likely to come sooner.') }}
@endif

<x-mail::button :url="route('recurring.index')">
{{ __('See the projection') }}
</x-mail::button>

{{ __('You can turn this warning off in your notification settings.') }}
</x-mail::message>
