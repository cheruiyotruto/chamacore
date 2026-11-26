<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MfaController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\BankTransferController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BranchController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\EmployerFinanceConfirmationController;
use App\Http\Controllers\FloatPurchaseController;
use App\Http\Controllers\GovernanceController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\LoanProductController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\MeetingController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\MemberLookupController;
use App\Http\Controllers\MerryGoRoundController;
use App\Http\Controllers\MpesaAgentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentChargeController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Platform;
use App\Http\Controllers\Portal;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicJoinController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SavingsController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StaffHomeController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TellerController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\WelfareController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public marketing site
|--------------------------------------------------------------------------
| The root URL is the public product site. The authenticated dashboard keeps
| the "dashboard" route name so name-based redirects and tests stay stable.
*/
Route::get('/', [MarketingController::class, 'home'])->name('home');

Route::name('marketing.')->group(function () {
    Route::get('features', [MarketingController::class, 'features'])->name('features');
    Route::get('pricing', [MarketingController::class, 'pricing'])->name('pricing');
    Route::get('about', [MarketingController::class, 'about'])->name('about');
    Route::get('contact', [MarketingController::class, 'contact'])->name('contact');
    Route::post('contact', [MarketingController::class, 'storeContact'])
        ->middleware('throttle:10,1')
        ->name('contact.store');
});

/*
|--------------------------------------------------------------------------
| Guest authentication
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])
        ->middleware('throttle:login')
        ->name('login.store');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:login')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:password-reset')
        ->name('password.update');
});

/*
|--------------------------------------------------------------------------
| Authenticated account security
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('mfa/challenge', [MfaController::class, 'challenge'])->name('mfa.challenge');
    Route::post('mfa/challenge', [MfaController::class, 'verify'])
        ->middleware('throttle:login')
        ->name('mfa.verify');

    Route::get('mfa/setup', [MfaController::class, 'showSetup'])->name('mfa.setup');
    Route::post('mfa/enable', [MfaController::class, 'enable'])
        ->middleware('throttle:10,1')
        ->name('mfa.enable');
    Route::delete('mfa/disable', [MfaController::class, 'disable'])
        ->middleware('throttle:10,1')
        ->name('mfa.disable');
});

/*
|--------------------------------------------------------------------------
| Public member sign-up
|--------------------------------------------------------------------------
| Super admins opt groups in individually. These routes stay outside auth and
| group.context: an applicant has no tenant membership until approval.
*/
Route::name('public.join.')->group(function () {
    Route::get('join', [PublicJoinController::class, 'index'])
        ->middleware('throttle:30,1')
        ->name('index');
    Route::get('join/{group:slug}/apply', [PublicJoinController::class, 'show'])
        ->middleware('throttle:30,1')
        ->name('apply');
    Route::post('join/{group:slug}/apply', [PublicJoinController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('store');
    Route::get('join/{group:slug}/application-received', [PublicJoinController::class, 'submitted'])
        ->middleware('throttle:30,1')
        ->name('submitted');
});

/*
|--------------------------------------------------------------------------
| Public member invitations
|--------------------------------------------------------------------------
| These routes must stay outside auth and group.context: an invitee has no
| account or tenant membership until they submit the application.
*/
Route::get('join/{token}', [JoinController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('join.show');
Route::post('join/{token}', [JoinController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('join.store');
Route::get('join/{token}/submitted', [JoinController::class, 'thanks'])
    ->middleware('throttle:30,1')
    ->name('join.thanks');

/*
|--------------------------------------------------------------------------
| Employer finance office confirmation
|--------------------------------------------------------------------------
| Finance officers do not sign in. The temporary signed URL is the credential
| that authorises them to confirm or decline a stamped employer approval.
*/
Route::get('employer-finance/{loan}/confirmation', [EmployerFinanceConfirmationController::class, 'show'])
    ->middleware(['signed', 'throttle:30,1'])
    ->name('employer-finance.confirm');
Route::post('employer-finance/{loan}/confirmation', [EmployerFinanceConfirmationController::class, 'store'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('employer-finance.confirm.store');

/*
|--------------------------------------------------------------------------
| Group application (staff + member portal entry point)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'mfa', 'group.context'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('home', [StaffHomeController::class, 'index'])->middleware('group.staff')->name('staff.home');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::post('profile/switch-group/{group}', [ProfileController::class, 'switchGroup'])->name('profile.switch-group');

    /*
    |----------------------------------------------------------------------
    | Member portal
    |----------------------------------------------------------------------
    */
    Route::prefix('portal')->name('portal.')->group(function () {
        Route::get('/', [Portal\DashboardController::class, 'index'])->name('dashboard');

        Route::get('savings', [Portal\SavingsController::class, 'index'])->name('savings.index');
        Route::get('savings/{account}', [Portal\SavingsController::class, 'show'])->name('savings.show');
        Route::get('savings/{account}/statement', [Portal\SavingsController::class, 'statement'])->name('savings.statement');

        Route::get('investments', [Portal\InvestmentsController::class, 'index'])->name('investments.index');
        Route::get('investments/create', [Portal\InvestmentsController::class, 'create'])->name('investments.create');
        Route::post('investments/quote', [Portal\InvestmentsController::class, 'quote'])->name('investments.quote');
        Route::post('investments', [Portal\InvestmentsController::class, 'store'])->name('investments.store');
        Route::get('investments/{investment}', [Portal\InvestmentsController::class, 'show'])->name('investments.show');
        Route::post('investments/{investment}/withdraw', [Portal\InvestmentsController::class, 'withdraw'])->name('investments.withdraw');
        Route::get('investments/{investment}/statement', [Portal\InvestmentsController::class, 'statement'])->name('investments.statement');

        Route::get('loans', [Portal\LoansController::class, 'index'])->name('loans.index');
        Route::get('loans/create', [Portal\LoansController::class, 'create'])->name('loans.create');
        Route::post('loans/quote', [Portal\LoansController::class, 'quote'])->name('loans.quote');
        Route::post('loans', [Portal\LoansController::class, 'store'])->name('loans.store');
        Route::get('loans/{loan}', [Portal\LoansController::class, 'show'])->name('loans.show');
        Route::get('loans/{loan}/schedule', [Portal\LoansController::class, 'schedule'])->name('loans.schedule');
        Route::get('loans/{loan}/employer-finance/form', [Portal\EmployerFinanceController::class, 'form'])->name('loans.employer-finance.form');
        Route::post('loans/{loan}/employer-finance', [Portal\EmployerFinanceController::class, 'upload'])->name('loans.employer-finance.upload');
        Route::post('guarantees/{guarantor}', [Portal\LoansController::class, 'respondToGuarantee'])->name('guarantees.respond');
        Route::get('guarantees/{guarantor}/sign', [Portal\LoansController::class, 'signForm'])->name('guarantees.sign');
        Route::post('guarantees/{guarantor}/sign', [Portal\LoansController::class, 'signGuarantee'])->name('guarantees.sign.store');

        Route::get('wallet', [Portal\WalletController::class, 'show'])->name('wallet.show');
        Route::post('wallet/top-up', [Portal\WalletController::class, 'topUp'])->name('wallet.top-up');
        Route::post('wallet/transfer', [Portal\WalletController::class, 'transfer'])->name('wallet.transfer');

        Route::get('float', [Portal\FloatPurchaseController::class, 'index'])->name('float.index');
        Route::post('float', [Portal\FloatPurchaseController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('float.store');

        Route::get('pay-for-member', [Portal\OnBehalfPaymentController::class, 'index'])->name('on-behalf.index');
        Route::post('pay-for-member/savings', [Portal\OnBehalfPaymentController::class, 'storeSavings'])->name('on-behalf.savings');
        Route::post('pay-for-member/loan', [Portal\OnBehalfPaymentController::class, 'storeLoan'])->name('on-behalf.loan');
        Route::post('pay-for-member/bill', [Portal\OnBehalfPaymentController::class, 'storeBill'])->name('on-behalf.bill');
        Route::post('pay-for-member/mgr', [Portal\OnBehalfPaymentController::class, 'storeMgr'])->name('on-behalf.mgr');
        Route::post('pay-for-member/wallet', [Portal\OnBehalfPaymentController::class, 'storeWallet'])->name('on-behalf.wallet');

        Route::get('bills', [Portal\BillController::class, 'index'])->name('bills.index');
        Route::get('bills/{bill}', [Portal\BillController::class, 'show'])->name('bills.show');
        Route::post('bills/{bill}/pay-wallet', [Portal\BillController::class, 'payFromWallet'])->name('bills.pay-wallet');
        Route::post('bills/{bill}/pay-mpesa', [Portal\BillController::class, 'payWithMpesa'])->name('bills.pay-mpesa');

        Route::get('bank-transfers', [Portal\BankTransferController::class, 'index'])->name('bank-transfers.index');
        Route::post('bank-transfers', [Portal\BankTransferController::class, 'store'])->name('bank-transfers.store');

        Route::get('documents', [Portal\DocumentsController::class, 'index'])->name('documents.index');
        Route::get('documents/{document}/download', [Portal\DocumentsController::class, 'download'])->name('documents.download');

        Route::get('support', [Portal\SupportController::class, 'index'])->name('support.index');
        Route::post('support', [Portal\SupportController::class, 'store'])->name('support.store');
        Route::get('support/{ticket}', [Portal\SupportController::class, 'show'])->name('support.show');
        Route::post('support/{ticket}/reply', [Portal\SupportController::class, 'reply'])->name('support.reply');

        Route::get('merry-go-round', [Portal\MerryGoRoundController::class, 'index'])->name('mgr.index');
        Route::get('merry-go-round/{cycle}', [Portal\MerryGoRoundController::class, 'show'])->name('mgr.show');
        Route::post('merry-go-round/contributions/{contribution}/pay-wallet', [Portal\MerryGoRoundController::class, 'payFromWallet'])
            ->name('mgr.contributions.pay-wallet');
        Route::post('merry-go-round/contributions/{contribution}/pay-mpesa', [Portal\MerryGoRoundController::class, 'payWithMpesa'])
            ->name('mgr.contributions.pay-mpesa');

        Route::get('governance', [Portal\GovernanceController::class, 'index'])->name('governance.index');
        Route::post('governance/resolutions/{resolution}/vote', [Portal\GovernanceController::class, 'vote'])->name('governance.vote');

        Route::get('invitations', [Portal\InvitationController::class, 'index'])->name('invitations.index');
        Route::post('invitations', [Portal\InvitationController::class, 'store'])->name('invitations.store');
        Route::post('invitations/{invitation}/resend', [Portal\InvitationController::class, 'resend'])->name('invitations.resend');
        Route::post('invitations/{invitation}/revoke', [Portal\InvitationController::class, 'revoke'])->name('invitations.revoke');
    });

    /*
    |----------------------------------------------------------------------
    | Staff administration
    |----------------------------------------------------------------------
    */
    Route::middleware('group.staff')->group(function () {
        Route::get('members/lookup', [MemberLookupController::class, 'index'])->name('members.lookup');
        Route::get('members/{member}/identity-media/{key}', [MemberLookupController::class, 'media'])->name('members.lookup.media');
        Route::get('members/export', [MemberController::class, 'export'])->name('members.export');
        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::get('members/create', [MemberController::class, 'create'])->name('members.create');
        Route::post('members', [MemberController::class, 'store'])->name('members.store');
        Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
        Route::get('members/{member}/edit', [MemberController::class, 'edit'])->name('members.edit');
        Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::post('members/{member}/approve', [MemberController::class, 'approve'])->name('members.approve');
        Route::post('members/{member}/reject', [MemberController::class, 'reject'])->name('members.reject');
        Route::post('members/{member}/terminate', [MemberController::class, 'terminate'])->name('members.terminate');

        Route::get('savings', [SavingsController::class, 'index'])->name('savings.index');
        Route::post('savings/products', [SavingsController::class, 'storeProduct'])->name('savings.products.store');
        Route::put('savings/products/{product}', [SavingsController::class, 'updateProduct'])->name('savings.products.update');
        Route::post('savings/accounts', [SavingsController::class, 'openAccount'])->name('savings.accounts.store');
        Route::get('savings/accounts/{account}', [SavingsController::class, 'show'])->name('savings.show');
        Route::post('savings/accounts/{account}/deposit', [SavingsController::class, 'deposit'])->name('savings.deposit');
        Route::post('savings/accounts/{account}/withdraw', [SavingsController::class, 'withdraw'])->name('savings.withdraw');
        Route::get('savings/accounts/{account}/statement', [SavingsController::class, 'statement'])->name('savings.statement');

        Route::get('investments', [InvestmentController::class, 'index'])->name('investments.index');
        Route::post('investments/products', [InvestmentController::class, 'storeProduct'])->name('investments.products.store');
        Route::put('investments/products/{product}', [InvestmentController::class, 'updateProduct'])->name('investments.products.update');
        Route::post('investments/products/{product}/toggle', [InvestmentController::class, 'toggleProduct'])->name('investments.products.toggle');
        Route::post('investments/place', [InvestmentController::class, 'place'])->name('investments.place');
        Route::get('investments/{investment}', [InvestmentController::class, 'show'])->name('investments.show');
        Route::post('investments/{investment}/accrue', [InvestmentController::class, 'accrue'])->name('investments.accrue');
        Route::post('investments/{investment}/settle', [InvestmentController::class, 'settle'])->name('investments.settle');

        Route::get('loans', [LoanController::class, 'index'])->name('loans.index');

        Route::get('loan-products', [LoanProductController::class, 'index'])->name('loan-products.index');
        Route::get('loan-products/create', [LoanProductController::class, 'create'])->name('loan-products.create');
        Route::post('loan-products', [LoanProductController::class, 'store'])->name('loan-products.store');
        Route::get('loan-products/{product}/edit', [LoanProductController::class, 'edit'])->name('loan-products.edit');
        Route::put('loan-products/{product}', [LoanProductController::class, 'update'])->name('loan-products.update');
        Route::post('loan-products/{product}/toggle', [LoanProductController::class, 'toggle'])->name('loan-products.toggle');

        Route::get('loans/create', [LoanController::class, 'create'])->name('loans.create');
        Route::post('loans/quote', [LoanController::class, 'quote'])->name('loans.quote');
        Route::post('loans', [LoanController::class, 'store'])->name('loans.store');
        Route::get('loans/{loan}', [LoanController::class, 'show'])->name('loans.show');
        Route::get('loans/{loan}/schedule', [LoanController::class, 'schedule'])->name('loans.schedule');
        Route::post('loans/{loan}/approve', [LoanController::class, 'approve'])->name('loans.approve');
        Route::post('loans/{loan}/employer-finance/confirm', [LoanController::class, 'confirmEmployerFinance'])->name('loans.employer-finance.confirm');
        Route::post('loans/{loan}/disburse', [LoanController::class, 'disburse'])->name('loans.disburse');
        Route::post('loans/{loan}/repay', [LoanController::class, 'repay'])->name('loans.repay');
        Route::post('loans/{loan}/penalties', [LoanController::class, 'penalties'])->name('loans.penalties');
        Route::post('loans/{loan}/restructure', [LoanController::class, 'restructure'])->name('loans.restructure');
        Route::post('loans/{loan}/write-off', [LoanController::class, 'writeOff'])->name('loans.write-off');

        Route::get('merry-go-round', [MerryGoRoundController::class, 'index'])->name('mgr.index');
        Route::get('merry-go-round/create', [MerryGoRoundController::class, 'create'])->name('mgr.create');
        Route::post('merry-go-round', [MerryGoRoundController::class, 'store'])->name('mgr.store');
        Route::get('merry-go-round/{cycle}', [MerryGoRoundController::class, 'show'])->name('mgr.show');
        Route::get('merry-go-round/{cycle}/edit', [MerryGoRoundController::class, 'edit'])->name('mgr.edit');
        Route::patch('merry-go-round/{cycle}', [MerryGoRoundController::class, 'update'])->name('mgr.update');
        Route::post('merry-go-round/contributions/{contribution}/record', [MerryGoRoundController::class, 'recordContribution'])->name('mgr.contributions.record');
        Route::post('merry-go-round/payouts/{payout}/pay', [MerryGoRoundController::class, 'payOut'])->name('mgr.payouts.pay');
        Route::post('merry-go-round/{cycle}/mark-overdue', [MerryGoRoundController::class, 'markOverdue'])->name('mgr.mark-overdue');

        Route::get('meetings', [MeetingController::class, 'index'])->name('meetings.index');
        Route::get('meetings/create', [MeetingController::class, 'create'])->name('meetings.create');
        Route::post('meetings', [MeetingController::class, 'store'])->name('meetings.store');
        Route::get('meetings/{meeting}', [MeetingController::class, 'show'])->name('meetings.show');
        Route::post('meetings/{meeting}/attendance', [MeetingController::class, 'saveAttendance'])->name('meetings.attendance');
        Route::post('meetings/{meeting}/collections', [MeetingController::class, 'recordCollection'])->name('meetings.collections.store');
        Route::post('meetings/{meeting}/complete', [MeetingController::class, 'complete'])->name('meetings.complete');
        Route::get('meetings/{meeting}/export', [MeetingController::class, 'export'])->name('meetings.export');

        Route::get('teller', [TellerController::class, 'index'])->name('teller.index');
        Route::get('teller/members/{member}', [TellerController::class, 'member'])->name('teller.member');
        Route::post('teller/sessions', [TellerController::class, 'openSession'])->name('teller.sessions.open');
        Route::get('teller/sessions/{session}', [TellerController::class, 'showSession'])->name('teller.sessions.show');
        Route::post('teller/sessions/{session}/close', [TellerController::class, 'closeSession'])->name('teller.sessions.close');
        Route::post('teller/entries', [TellerController::class, 'post'])->name('teller.entries.store');
        Route::get('teller/cheques', [TellerController::class, 'cheques'])->name('teller.cheques.index');
        Route::post('teller/cheques/{entry}/clear', [TellerController::class, 'clearCheque'])->name('teller.cheques.clear');
        Route::post('teller/cheques/{entry}/bounce', [TellerController::class, 'bounceCheque'])->name('teller.cheques.bounce');
        Route::get('teller/entries/{entry}/receipt', [TellerController::class, 'receipt'])->name('teller.entries.receipt');

        Route::get('wallet', [WalletController::class, 'index'])->name('wallet.index');
        Route::get('wallet/reconcile', [WalletController::class, 'reconcile'])->name('wallet.reconcile');
        Route::post('wallet/reconcile', [WalletController::class, 'markReconciled'])->name('wallet.reconcile.store');
        Route::get('wallet/{wallet}', [WalletController::class, 'show'])->name('wallet.show');
        Route::post('wallet/adjust', [WalletController::class, 'adjust'])->name('wallet.adjust');
        Route::post('wallet/transfer', [WalletController::class, 'transfer'])->name('wallet.transfer');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('payments/{intent}', [PaymentController::class, 'show'])->name('payments.show');

        Route::get('bills', [BillController::class, 'index'])->name('bills.index');
        Route::get('bills/create', [BillController::class, 'create'])->name('bills.create');
        Route::post('bills', [BillController::class, 'store'])->name('bills.store');
        Route::get('bills/{bill}', [BillController::class, 'show'])->name('bills.show');
        Route::post('bills/{bill}/payments', [BillController::class, 'recordPayment'])->name('bills.payments.store');
        Route::post('bills/{bill}/cancel', [BillController::class, 'cancel'])->name('bills.cancel');

        Route::get('bank-transfers', [BankTransferController::class, 'index'])->name('bank-transfers.index');
        Route::post('bank-transfers/destinations', [BankTransferController::class, 'storeDestination'])->name('bank-transfers.destinations.store');
        Route::post('bank-transfers/destinations/{destination}/toggle', [BankTransferController::class, 'toggleDestination'])->name('bank-transfers.destinations.toggle');
        Route::put('bank-transfers/access', [BankTransferController::class, 'updateAccess'])->name('bank-transfers.access.update');
        Route::post('bank-transfers/payouts', [BankTransferController::class, 'storePayout'])->name('bank-transfers.payouts.store');
        Route::get('bank-transfers/{payout}', [BankTransferController::class, 'show'])->name('bank-transfers.show');
        Route::post('bank-transfers/{payout}/cancel', [BankTransferController::class, 'cancel'])->name('bank-transfers.cancel');

        Route::get('mpesa-agents', [MpesaAgentController::class, 'index'])->name('mpesa-agents.index');
        Route::post('mpesa-agents', [MpesaAgentController::class, 'store'])->name('mpesa-agents.store');
        Route::put('mpesa-agents/{agent}', [MpesaAgentController::class, 'update'])->name('mpesa-agents.update');
        Route::post('mpesa-agents/{agent}/toggle', [MpesaAgentController::class, 'toggle'])->name('mpesa-agents.toggle');

        Route::get('float-purchases', [FloatPurchaseController::class, 'index'])->name('float-purchases.index');
        Route::post('float-purchases/{purchase}/fulfil', [FloatPurchaseController::class, 'fulfil'])->name('float-purchases.fulfil');
        Route::post('float-purchases/{purchase}/fail', [FloatPurchaseController::class, 'fail'])->name('float-purchases.fail');
        Route::post('float-purchases/{purchase}/refresh', [FloatPurchaseController::class, 'refresh'])->name('float-purchases.refresh');

        Route::get('accounting', [AccountingController::class, 'index'])->name('accounting.index');
        Route::get('accounting/journals', [AccountingController::class, 'journals'])->name('accounting.journals');
        Route::get('accounting/journals/create', [AccountingController::class, 'createJournal'])->name('accounting.journals.create');
        Route::post('accounting/journals', [AccountingController::class, 'storeJournal'])->name('accounting.journals.store');
        Route::get('accounting/journals/{entry}', [AccountingController::class, 'showJournal'])->name('accounting.journals.show');
        Route::post('accounting/journals/{entry}/reverse', [AccountingController::class, 'reverseJournal'])->name('accounting.journals.reverse');
        Route::post('accounting/accounts', [AccountingController::class, 'storeAccount'])->name('accounting.accounts.store');
        Route::post('accounting/bank-accounts', [AccountingController::class, 'storeBankAccount'])->name('accounting.bank-accounts.store');
        Route::post('accounting/bank-accounts/{bankAccount}/reconcile', [AccountingController::class, 'reconcileBank'])->name('accounting.bank-accounts.reconcile');
        Route::get('accounting/ledger', [AccountingController::class, 'ledger'])->name('accounting.ledger');

        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
        Route::get('reports/income-statement', [ReportController::class, 'incomeStatement'])->name('reports.income-statement');
        Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
        Route::get('reports/savings', [ReportController::class, 'savings'])->name('reports.savings');
        Route::get('reports/loans', [ReportController::class, 'loans'])->name('reports.loans');
        Route::get('reports/merry-go-round', [ReportController::class, 'merryGoRound'])->name('reports.mgr');
        Route::get('reports/member-statement/{member?}', [ReportController::class, 'memberStatement'])->name('reports.member-statement');
        Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

        Route::get('documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('documents', [DocumentController::class, 'store'])->name('documents.store');
        Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
        Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::post('documents/{document}/verify', [DocumentController::class, 'verify'])->name('documents.verify');
        Route::post('documents/{document}/reject', [DocumentController::class, 'reject'])->name('documents.reject');
        Route::delete('documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::get('branches', [BranchController::class, 'index'])->name('branches.index');
        Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
        Route::put('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
        Route::post('branches/{branch}/toggle', [BranchController::class, 'toggleStatus'])->name('branches.toggle');
        Route::put('settings/group', [SettingController::class, 'updateGroup'])->name('settings.group.update');
        Route::put('settings/values', [SettingController::class, 'updateSettings'])->name('settings.values.update');
        Route::put('settings/payment-charges', [PaymentChargeController::class, 'update'])->name('settings.payment-charges.update');
        Route::put('settings/join-requirements', [SettingController::class, 'updateJoinRequirements'])->name('settings.join-requirements.update');
        Route::put('settings/employer-finance', [SettingController::class, 'updateEmployerFinance'])->name('settings.employer-finance.update');
        Route::put('settings/guarantees', [SettingController::class, 'updateGuarantees'])->name('settings.guarantees.update');
        Route::put('settings/mgr-savings', [SettingController::class, 'updateMgrSavings'])->name('settings.mgr-savings.update');
        Route::put('settings/portal-access', [SettingController::class, 'updatePortalAccess'])->name('settings.portal-access.update');
        Route::post('settings/charges', [SettingController::class, 'storeCharge'])->name('settings.charges.store');
        Route::put('settings/charges/{charge}', [SettingController::class, 'updateCharge'])->name('settings.charges.update');
        Route::delete('settings/charges/{charge}', [SettingController::class, 'destroyCharge'])->name('settings.charges.destroy');
        Route::post('settings/roles', [SettingController::class, 'storeRole'])->name('settings.roles.store');
        Route::put('settings/roles/{role}', [SettingController::class, 'updateRole'])->name('settings.roles.update');
        Route::put('settings/templates/{template}', [SettingController::class, 'updateTemplate'])->name('settings.templates.update');

        Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
        Route::post('subscription/plans/{plan}', [SubscriptionController::class, 'subscribe'])->name('subscription.subscribe');
        Route::post('subscription/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->name('subscription.cancel');
        Route::post('subscription/invoices/{invoice}/pay', [SubscriptionController::class, 'payInvoice'])->name('subscription.invoices.pay');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/send', [NotificationController::class, 'send'])->name('notifications.send');
        Route::post('notifications/reminders/run', [NotificationController::class, 'runReminders'])->name('notifications.reminders.run');
        Route::post('notifications/reminders', [NotificationController::class, 'storeRule'])->name('notifications.reminders.store');
        Route::put('notifications/reminders/{rule}', [NotificationController::class, 'updateRule'])->name('notifications.reminders.update');
        Route::post('notifications/reminders/{rule}/toggle', [NotificationController::class, 'toggleRule'])->name('notifications.reminders.toggle');
        Route::delete('notifications/reminders/{rule}', [NotificationController::class, 'destroyRule'])->name('notifications.reminders.destroy');

        Route::get('governance', [GovernanceController::class, 'index'])->name('governance.index');
        Route::post('governance/policies', [GovernanceController::class, 'storePolicy'])->name('governance.policies.store');
        Route::put('governance/policies/{policy}', [GovernanceController::class, 'updatePolicy'])->name('governance.policies.update');
        Route::post('governance/resolutions', [GovernanceController::class, 'storeResolution'])->name('governance.resolutions.store');
        Route::post('governance/resolutions/{resolution}/close', [GovernanceController::class, 'closeResolution'])->name('governance.resolutions.close');

        Route::get('crm', [CrmController::class, 'index'])->name('crm.index');
        Route::post('crm/leads', [CrmController::class, 'storeLead'])->name('crm.leads.store');
        Route::put('crm/leads/{lead}', [CrmController::class, 'updateLead'])->name('crm.leads.update');
        Route::post('crm/leads/{lead}/follow-ups', [CrmController::class, 'storeFollowUp'])->name('crm.follow-ups.store');
        Route::post('crm/follow-ups/{followUp}/complete', [CrmController::class, 'completeFollowUp'])->name('crm.follow-ups.complete');
        Route::get('crm/tickets/{ticket}', [CrmController::class, 'showTicket'])->name('crm.tickets.show');
        Route::post('crm/tickets/{ticket}/reply', [CrmController::class, 'replyTicket'])->name('crm.tickets.reply');
        Route::put('crm/tickets/{ticket}', [CrmController::class, 'updateTicket'])->name('crm.tickets.update');

        Route::get('welfare', [WelfareController::class, 'index'])->name('welfare.index');
        Route::post('welfare/funds', [WelfareController::class, 'storeFund'])->name('welfare.funds.store');
        Route::post('welfare/funds/{fund}/contribute', [WelfareController::class, 'contribute'])->name('welfare.funds.contribute');
        Route::post('welfare/funds/{fund}/claims', [WelfareController::class, 'requestClaim'])->name('welfare.claims.store');
        Route::post('welfare/claims/{claim}/approve', [WelfareController::class, 'approveClaim'])->name('welfare.claims.approve');
        Route::post('welfare/claims/{claim}/reject', [WelfareController::class, 'rejectClaim'])->name('welfare.claims.reject');
        Route::post('welfare/claims/{claim}/disburse', [WelfareController::class, 'disburseClaim'])->name('welfare.claims.disburse');
    });
});

/*
|--------------------------------------------------------------------------
| Platform administration (cross-tenant, super administrators only)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'mfa', 'super.admin'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function () {
        Route::get('/', [Platform\DashboardController::class, 'index'])->name('dashboard');

        Route::get('leads', [Platform\LeadController::class, 'index'])->name('leads.index');
        Route::put('leads/{lead}', [Platform\LeadController::class, 'update'])->name('leads.update');

        Route::get('applications', [Platform\ApplicationController::class, 'index'])->name('applications.index');
        Route::post('applications/{member}/approve', [Platform\ApplicationController::class, 'approve'])->name('applications.approve');
        Route::post('applications/{member}/reject', [Platform\ApplicationController::class, 'reject'])->name('applications.reject');

        Route::get('groups', [Platform\GroupController::class, 'index'])->name('groups.index');
        Route::get('groups/{group}', [Platform\GroupController::class, 'show'])->name('groups.show');
        Route::put('groups/{group}/public-join', [Platform\GroupController::class, 'updatePublicJoin'])->name('groups.public-join.update');
        Route::put('groups/{group}/management-mode', [Platform\GroupController::class, 'updateMode'])->name('groups.management-mode.update');
        Route::put('groups/{group}/payment-charges', [Platform\GroupController::class, 'updateCharges'])->name('groups.payment-charges.update');
        Route::put('groups/{group}/plan', [Platform\GroupController::class, 'assignPlan'])->name('groups.plan.update');
        Route::post('groups/{group}/enter', [Platform\GroupController::class, 'enter'])->name('groups.enter');
        Route::post('groups/leave', [Platform\GroupController::class, 'leave'])->name('groups.leave');
        Route::post('groups/{group}/approve', [Platform\GroupController::class, 'approve'])->name('groups.approve');
        Route::post('groups/{group}/suspend', [Platform\GroupController::class, 'suspend'])->name('groups.suspend');
        Route::post('groups/{group}/reactivate', [Platform\GroupController::class, 'reactivate'])->name('groups.reactivate');

        Route::get('plans', [Platform\PlanController::class, 'index'])->name('plans.index');
        Route::post('plans', [Platform\PlanController::class, 'store'])->name('plans.store');
        Route::put('plans/{plan}', [Platform\PlanController::class, 'update'])->name('plans.update');
        Route::post('plans/{plan}/toggle', [Platform\PlanController::class, 'toggle'])->name('plans.toggle');

        Route::get('charges', [Platform\PaymentChargeController::class, 'index'])->name('charges.index');
        Route::put('charges', [Platform\PaymentChargeController::class, 'update'])->name('charges.update');

        Route::get('currencies', [Platform\CurrencyController::class, 'index'])->name('currencies.index');
        Route::put('currencies', [Platform\CurrencyController::class, 'update'])->name('currencies.update');

        Route::get('revenue', [Platform\RevenueController::class, 'index'])->name('revenue.index');
    });
