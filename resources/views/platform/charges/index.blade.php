@extends('layouts.platform')

@section('title', 'Payment charges')

@section('content')
    <x-page-header title="Payment charges"
        subtitle="Platform-wide defaults. Group administrators can override any of these in their own settings." />

    <div class="mt-6">
        <x-card title="Charge rules" subtitle="Every rule is optional. Enabled rules for a payment are added together.">
            <form method="POST" action="{{ route('platform.charges.update') }}" class="space-y-4">
                @csrf
                @method('PUT')
                @include('components.payment-charge-rules', [
                    'rules' => $rules,
                    'categories' => $categories,
                    'modes' => $modes,
                    'borneBy' => $borneBy,
                    'incomeAccounts' => $incomeAccounts,
                    'canManage' => true,
                    'money' => $money,
                ])
            </form>
        </x-card>
    </div>
@endsection
