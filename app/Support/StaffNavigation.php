<?php

namespace App\Support;

/**
 * Shared staff navigation definition.
 *
 * The organisation portal sidebar renders from this list, so a staff section
 * is never available in one place but missing from the other.
 */
final class StaffNavigation
{
    /**
     * @return array<int, array{label: ?string, items: array<int, array{label: string, route: string, pattern: string, icon: string, permission: ?string}>}>
     */
    public static function sections(): array
    {
        return [
            ['label' => null, 'items' => [
                ['label' => 'Dashboard', 'route' => 'dashboard', 'pattern' => 'dashboard', 'icon' => 'home', 'permission' => null],
            ]],
            ['label' => 'Members', 'items' => [
                ['label' => 'Members', 'route' => 'members.index', 'pattern' => 'members.*', 'icon' => 'users', 'permission' => 'members.view'],
                ['label' => 'Member lookup', 'route' => 'members.lookup', 'pattern' => 'members.lookup', 'icon' => 'search', 'permission' => 'members.view'],
                ['label' => 'Invitations', 'route' => 'portal.invitations.index', 'pattern' => 'portal.invitations.*', 'icon' => 'users', 'permission' => 'portal.view'],
                ['label' => 'Teller', 'route' => 'teller.index', 'pattern' => 'teller.*', 'icon' => 'banknotes', 'permission' => 'teller.view'],
                ['label' => 'Cheque clearance', 'route' => 'teller.cheques.index', 'pattern' => 'teller.cheques.*', 'icon' => 'receipt', 'permission' => 'teller.view'],
                ['label' => 'Savings', 'route' => 'savings.index', 'pattern' => 'savings.*', 'icon' => 'banknotes', 'permission' => 'savings.view'],
                ['label' => 'Investments', 'route' => 'investments.index', 'pattern' => 'investments.*', 'icon' => 'trending-up', 'permission' => 'investments.view'],
                ['label' => 'Merry-go-round', 'route' => 'mgr.index', 'pattern' => 'mgr.*', 'icon' => 'calendar', 'permission' => 'mgr.view'],
            ]],
            ['label' => 'Lending', 'items' => [
                ['label' => 'Loans', 'route' => 'loans.index', 'pattern' => 'loans.*', 'icon' => 'credit-card', 'permission' => 'loans.view'],
                ['label' => 'Loan products', 'route' => 'loan-products.index', 'pattern' => 'loan-products.*', 'icon' => 'credit-card', 'permission' => 'loans.view'],
                ['label' => 'Meetings', 'route' => 'meetings.index', 'pattern' => 'meetings.*', 'icon' => 'clock', 'permission' => 'meetings.view'],
            ]],
            ['label' => 'Money', 'items' => [
                ['label' => 'Wallets', 'route' => 'wallet.index', 'pattern' => 'wallet.*', 'icon' => 'wallet', 'permission' => 'wallet.view'],
                ['label' => 'Payments', 'route' => 'payments.index', 'pattern' => 'payments.*', 'icon' => 'receipt', 'permission' => 'payments.view'],
                ['label' => 'Bills', 'route' => 'bills.index', 'pattern' => 'bills.*', 'icon' => 'receipt', 'permission' => 'bills.view'],
                ['label' => 'Bank transfers', 'route' => 'bank-transfers.index', 'pattern' => 'bank-transfers.*', 'icon' => 'banknotes', 'permission' => 'payouts.view'],
                ['label' => 'M-Pesa agents', 'route' => 'mpesa-agents.index', 'pattern' => 'mpesa-agents.*', 'icon' => 'users', 'permission' => 'float_purchases.view'],
                ['label' => 'Float purchases', 'route' => 'float-purchases.index', 'pattern' => 'float-purchases.*', 'icon' => 'wallet', 'permission' => 'float_purchases.view'],
                ['label' => 'Accounting', 'route' => 'accounting.index', 'pattern' => 'accounting.*', 'icon' => 'book', 'permission' => 'accounting.view'],
                ['label' => 'Reports', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'chart', 'permission' => 'reports.view'],
            ]],
            ['label' => 'Operations', 'items' => [
                ['label' => 'Documents', 'route' => 'documents.index', 'pattern' => 'documents.*', 'icon' => 'folder', 'permission' => 'documents.view'],
                ['label' => 'Governance', 'route' => 'governance.index', 'pattern' => 'governance.*', 'icon' => 'scale', 'permission' => 'governance.view'],
                ['label' => 'CRM', 'route' => 'crm.index', 'pattern' => 'crm.*', 'icon' => 'chat', 'permission' => 'crm.view'],
                ['label' => 'Welfare', 'route' => 'welfare.index', 'pattern' => 'welfare.*', 'icon' => 'heart', 'permission' => 'welfare.view'],
                ['label' => 'Notifications', 'route' => 'notifications.index', 'pattern' => 'notifications.*', 'icon' => 'bell', 'permission' => 'notifications.view'],
            ]],
            ['label' => 'Administration', 'items' => [
                ['label' => 'Branches', 'route' => 'branches.index', 'pattern' => 'branches.*', 'icon' => 'building', 'permission' => 'settings.view'],
                ['label' => 'Settings', 'route' => 'settings.index', 'pattern' => 'settings.*', 'icon' => 'cog', 'permission' => 'settings.view'],
                ['label' => 'Subscription', 'route' => 'subscription.show', 'pattern' => 'subscription.*', 'icon' => 'shield', 'permission' => 'subscription.view'],
            ]],
        ];
    }
}
