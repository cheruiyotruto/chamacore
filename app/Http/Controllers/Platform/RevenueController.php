<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\PlatformRevenueService;
use App\Support\GroupContext;
use Illuminate\View\View;

class RevenueController extends Controller
{
    public function index(PlatformRevenueService $revenue, GroupContext $context): View
    {
        return $context->withoutTenantScope(fn () => view('platform.revenue.index', $revenue->summary()));
    }
}
