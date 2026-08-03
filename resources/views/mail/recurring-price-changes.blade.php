<x-mail::message>
# {{ __('A price went up') }}

{{ __('Hi :name, these recurring charges now cost more than they used to.', ['name' => $user->name]) }}

<x-mail::table>
| {{ __('What') }} | {{ __('Was') }} | {{ __('Now') }} | {{ __('Per year') }} |
| :--- | ---: | ---: | ---: |
@foreach ($rises as $rise)
| {{ $rise->series->display_name }} | {{ \App\Support\Money::format($rise->previousAmount, $rise->series->currency_code) }} | {{ \App\Support\Money::format($rise->currentAmount, $rise->series->currency_code) }} (+{{ $rise->percentage() }}%) | +{{ \App\Support\Money::format($rise->yearlyImpact(), $rise->series->currency_code) }} |
@endforeach
</x-mail::table>

{{ __('Prices are the typical amount of the last few charges compared with the ones before them, so a one-off surcharge will not appear here.') }}

<x-mail::button :url="route('recurring.index')">
{{ __('Review your subscriptions') }}
</x-mail::button>

{{ __('You can turn these warnings off in your [notification settings](:url).', ['url' => route('notifications.index')]) }}
</x-mail::message>
