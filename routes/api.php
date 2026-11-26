<?php

use App\Http\Controllers\Api\V1;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Chama Core REST API (v1)
|--------------------------------------------------------------------------
|
| Every tenant endpoint requires a Sanctum token, an explicit X-Group-Id
| header and a token ability scoped to that group. Member privacy is enforced
| by the MemberScope and policies, not by the Flutter client.
|
*/

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [V1\AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('api.auth.login');

    Route::post('payments/mpesa/callback', V1\PaymentCallbackController::class)
        ->middleware('throttle:payments')
        ->name('api.payments.callback');

    Route::post('payouts/callback/{provider}', V1\PayoutCallbackController::class)
        ->middleware('throttle:payments')
        ->name('api.payouts.callback');

    Route::post('float/callback', V1\FloatPurchaseCallbackController::class)
        ->middleware('throttle:payments')
        ->name('api.float.callback');

    Route::middleware(['auth:sanctum', 'force.json', 'api.mfa', 'group.context', 'api.group'])->group(function () {
        Route::get('auth/me', [V1\AuthController::class, 'me'])->name('api.auth.me');
        Route::get('auth/groups', [V1\AuthController::class, 'groups'])->name('api.auth.groups');
        Route::post('auth/logout', [V1\AuthController::class, 'logout'])->name('api.auth.logout');
        Route::post('auth/password', [V1\AuthController::class, 'changePassword'])->name('api.auth.password');
        Route::post('auth/devices', [V1\AuthController::class, 'registerDevice'])->name('api.auth.devices.store');
        Route::delete('auth/devices', [V1\AuthController::class, 'removeDevice'])->name('api.auth.devices.destroy');

        Route::get('dashboard', V1\DashboardController::class)->name('api.dashboard');

        Route::get('savings', [V1\SavingsController::class, 'index'])->name('api.savings.index');
        Route::get('savings/{account}', [V1\SavingsController::class, 'show'])->name('api.savings.show');
        Route::post('savings/{account}/deposit', [V1\SavingsController::class, 'deposit'])->name('api.savings.deposit');

        Route::get('loans', [V1\LoansController::class, 'index'])->name('api.loans.index');
        Route::get('loans/products', [V1\LoansController::class, 'products'])->name('api.loans.products');
        Route::post('loans/quote', [V1\LoansController::class, 'quote'])->name('api.loans.quote');
        Route::post('loans', [V1\LoansController::class, 'store'])->name('api.loans.store');
        Route::get('loans/guarantees', [V1\LoansController::class, 'guarantees'])->name('api.loans.guarantees');
        Route::post('loans/guarantees/{guarantor}/respond', [V1\LoansController::class, 'respondToGuarantee'])->name('api.loans.guarantees.respond');
        Route::post('loans/guarantees/{guarantor}/sign', [V1\LoansController::class, 'signGuarantee'])->name('api.loans.guarantees.sign');
        Route::get('loans/{loan}', [V1\LoansController::class, 'show'])->name('api.loans.show');
        Route::get('loans/{loan}/schedule', [V1\LoansController::class, 'schedule'])->name('api.loans.schedule');
        Route::post('loans/{loan}/repay', [V1\LoansController::class, 'repay'])->name('api.loans.repay');

        Route::get('wallet', [V1\WalletController::class, 'show'])->name('api.wallet.show');
        Route::get('wallet/members', [V1\WalletController::class, 'members'])->name('api.wallet.members');
        Route::get('wallet/payments', [V1\WalletController::class, 'payments'])->name('api.wallet.payments');
        Route::post('wallet/top-up', [V1\WalletController::class, 'topUp'])->name('api.wallet.top-up');
        Route::post('wallet/transfer', [V1\WalletController::class, 'transfer'])->name('api.wallet.transfer');

        Route::get('float/packages', [V1\FloatPurchaseController::class, 'packages'])->name('api.float.packages');
        Route::get('float/agents', [V1\FloatPurchaseController::class, 'agents'])->name('api.float.agents');
        Route::get('float/quote', [V1\FloatPurchaseController::class, 'quote'])->name('api.float.quote');
        Route::get('float/purchases', [V1\FloatPurchaseController::class, 'index'])->name('api.float.purchases.index');
        Route::post('float/purchases', [V1\FloatPurchaseController::class, 'store'])->name('api.float.purchases.store');

        Route::get('bills', [V1\BillController::class, 'index'])->name('api.bills.index');
        Route::get('bills/{bill}', [V1\BillController::class, 'show'])->name('api.bills.show');
        Route::post('bills/{bill}/pay', [V1\BillController::class, 'pay'])->name('api.bills.pay');

        Route::get('payouts/access', [V1\BankTransferController::class, 'access'])->name('api.payouts.access');
        Route::put('payouts/access', [V1\BankTransferController::class, 'updateAccess'])
            ->middleware('permission:payouts.manage')
            ->name('api.payouts.access.update');
        Route::get('payouts/destinations', [V1\BankTransferController::class, 'destinations'])->name('api.payouts.destinations.index');
        Route::post('payouts/destinations', [V1\BankTransferController::class, 'storeDestination'])
            ->middleware('permission:payouts.manage')
            ->name('api.payouts.destinations.store');
        Route::get('payouts/quote', [V1\BankTransferController::class, 'quote'])->name('api.payouts.quote');
        Route::get('payouts', [V1\BankTransferController::class, 'index'])->name('api.payouts.index');
        Route::post('payouts', [V1\BankTransferController::class, 'store'])->name('api.payouts.store');
        Route::get('payouts/{payout}', [V1\BankTransferController::class, 'show'])->name('api.payouts.show');

        Route::get('payments', [V1\PaymentController::class, 'index'])->name('api.payments.index');
        Route::post('payments', [V1\PaymentController::class, 'store'])->name('api.payments.store');
        Route::get('payments/{intent}', [V1\PaymentController::class, 'show'])->name('api.payments.show');

        Route::get('merry-go-round', [V1\MerryGoRoundController::class, 'index'])->name('api.mgr.index');
        Route::get('merry-go-round/{cycle}', [V1\MerryGoRoundController::class, 'show'])->name('api.mgr.show');

        Route::get('notifications', [V1\NotificationController::class, 'index'])->name('api.notifications.index');
        Route::post('notifications/{id}/read', [V1\NotificationController::class, 'read'])->name('api.notifications.read');
        Route::post('notifications/read-all', [V1\NotificationController::class, 'readAll'])->name('api.notifications.read-all');

        Route::get('documents', [V1\DocumentController::class, 'index'])->name('api.documents.index');
        Route::get('documents/{document}/download', [V1\DocumentController::class, 'download'])->name('api.documents.download');

        Route::get('support', [V1\SupportController::class, 'index'])->name('api.support.index');
        Route::post('support', [V1\SupportController::class, 'store'])->name('api.support.store');
        Route::get('support/{ticket}', [V1\SupportController::class, 'show'])->name('api.support.show');
        Route::post('support/{ticket}/reply', [V1\SupportController::class, 'reply'])->name('api.support.reply');
    });
});
