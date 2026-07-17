<?php

use App\Models\User;
use App\Models\UserSetting;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

test('ai confidence threshold can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('ai-confidence-threshold.update'), [
            'ai_confidence_threshold' => 85,
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect();

    expect($user->fresh()->setting->ai_confidence_threshold)->toBe(85);
});

test('ai confidence threshold rejects values out of range', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('ai-confidence-threshold.update'), [
            'ai_confidence_threshold' => 10,
        ])
        ->assertSessionHasErrors('ai_confidence_threshold');

    $this
        ->actingAs($user)
        ->patch(route('ai-confidence-threshold.update'), [
            'ai_confidence_threshold' => 100,
        ])
        ->assertSessionHasErrors('ai_confidence_threshold');
});

test('ai confidence threshold rejects non-integer values', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->patch(route('ai-confidence-threshold.update'), [
            'ai_confidence_threshold' => 'high',
        ])
        ->assertSessionHasErrors('ai_confidence_threshold');
});

test('ai confidence threshold requires authentication', function () {
    $this->patch(route('ai-confidence-threshold.update'), [
        'ai_confidence_threshold' => 80,
    ])->assertRedirect(route('register'));
});

test('ai confidence threshold creates setting when none exists', function () {
    $user = User::factory()->create();

    expect(UserSetting::where('user_id', $user->id)->exists())->toBeFalse();

    $this->actingAs($user)
        ->patch(route('ai-confidence-threshold.update'), [
            'ai_confidence_threshold' => 60,
        ]);

    expect($user->fresh()->setting->ai_confidence_threshold)->toBe(60);
});
