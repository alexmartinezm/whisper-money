<x-mail::message>
# {{ __('Coming up in the next few days') }}

{{ __('Hi :name, these recurring charges are due shortly.', ['name' => $user->name]) }}

<x-mail::table>
| {{ __('What') }} | {{ __('When') }} | {{ __('Amount') }} |
| :--- | :--- | ---: |
@foreach ($series as $row)
| {{ $row->display_name }} | {{ $row->next_expected_on->translatedFormat('D, j M') }} | {{ $row->amount_is_variable ? __('approx.').' ' : '' }}{{ \App\Support\Money::format($row->expected_amount, $row->currency_code) }} |
@endforeach
</x-mail::table>

{{ __('Amounts are estimates based on what these charges have cost before.') }}

<x-mail::button :url="route('recurring.index')">
{{ __('View recurring') }}
</x-mail::button>

{{ __('You can turn these reminders off in your [notification settings](:url).', ['url' => route('notifications.index')]) }}
</x-mail::message>
