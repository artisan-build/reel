<?php

namespace App\Http\Controllers;

use App\Services\RetentionDiagnostics;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(RetentionDiagnostics $diagnostics): View
    {
        return view('dashboard', ['diagnostics' => $diagnostics->snapshot()]);
    }
}
