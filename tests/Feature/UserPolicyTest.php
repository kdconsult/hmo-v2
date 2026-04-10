<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\UserPolicy;

test('viewAny returns false for non-landlord', function () {
    $user = User::factory()->create(['is_landlord' => false]);

    expect((new UserPolicy)->viewAny($user))->toBeFalse();
});

test('viewAny returns true for landlord', function () {
    $user = User::factory()->create(['is_landlord' => true]);

    expect((new UserPolicy)->viewAny($user))->toBeTrue();
});

test('create returns false for non-landlord', function () {
    $user = User::factory()->create(['is_landlord' => false]);

    expect((new UserPolicy)->create($user))->toBeFalse();
});

test('create returns true for landlord', function () {
    $user = User::factory()->create(['is_landlord' => true]);

    expect((new UserPolicy)->create($user))->toBeTrue();
});

test('update returns false for non-landlord', function () {
    $user = User::factory()->create(['is_landlord' => false]);
    $model = User::factory()->create();

    expect((new UserPolicy)->update($user, $model))->toBeFalse();
});

test('update returns true for landlord', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $model = User::factory()->create();

    expect((new UserPolicy)->update($user, $model))->toBeTrue();
});

test('delete returns false for non-landlord', function () {
    $user = User::factory()->create(['is_landlord' => false]);
    $model = User::factory()->create();

    expect((new UserPolicy)->delete($user, $model))->toBeFalse();
});

test('delete returns false when landlord tries to delete themselves', function () {
    $user = User::factory()->create(['is_landlord' => true]);

    expect((new UserPolicy)->delete($user, $user))->toBeFalse();
});

test('delete returns true when landlord deletes another user', function () {
    $user = User::factory()->create(['is_landlord' => true]);
    $model = User::factory()->create();

    expect((new UserPolicy)->delete($user, $model))->toBeTrue();
});
