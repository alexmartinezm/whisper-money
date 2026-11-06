<?php

use App\Http\Controllers\Api\V1\AccountBalanceController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AutomationRuleController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\LabelController;
use App\Http\Controllers\Api\V1\SpaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API v1
|--------------------------------------------------------------------------
|
| Mounted at `/api/v1` under the stateless `api-v1` middleware group (see
| bootstrap/app.php): bearer tokens only, no session, cookies or CSRF, kept
| apart from the internal session API in routes/api.php. Route names are
| prefixed `api.v1.` and double as the endpoint in the usage metrics.
|
*/

Route::get('spaces', [SpaceController::class, 'index'])->name('spaces.index');

Route::whereUuid('account')->group(function (): void {
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
    Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::get('accounts/{account}/balances', [AccountBalanceController::class, 'index'])->name('accounts.balances.index');
    Route::post('accounts/{account}/balances', [AccountBalanceController::class, 'store'])->name('accounts.balances.store');
});

Route::whereUuid('category')->group(function (): void {
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::get('categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
});

Route::whereUuid('label')->group(function (): void {
    Route::get('labels', [LabelController::class, 'index'])->name('labels.index');
    Route::post('labels', [LabelController::class, 'store'])->name('labels.store');
    Route::get('labels/{label}', [LabelController::class, 'show'])->name('labels.show');
    Route::patch('labels/{label}', [LabelController::class, 'update'])->name('labels.update');
    Route::delete('labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');
});

Route::whereUuid('automation_rule')->group(function (): void {
    Route::get('automation-rules', [AutomationRuleController::class, 'index'])->name('automation-rules.index');
    Route::post('automation-rules', [AutomationRuleController::class, 'store'])->name('automation-rules.store');
    Route::get('automation-rules/{automation_rule}', [AutomationRuleController::class, 'show'])->name('automation-rules.show');
    Route::patch('automation-rules/{automation_rule}', [AutomationRuleController::class, 'update'])->name('automation-rules.update');
    Route::delete('automation-rules/{automation_rule}', [AutomationRuleController::class, 'destroy'])->name('automation-rules.destroy');
    Route::post('automation-rules/{automation_rule}/apply', [AutomationRuleController::class, 'apply'])->name('automation-rules.apply');
});
