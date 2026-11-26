<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

final class StaffHomeController extends Controller
{
    /**
     * Keep the old staff launcher URL working, but send staff straight to the
     * organisation portal instead of showing a blank page.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('dashboard');
    }
}
