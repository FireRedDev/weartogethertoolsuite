<?php

namespace App\Http\Controllers;

use App\Services\IntegrationStatusChecker;
use Illuminate\View\View;

/**
 * "Admin-Informationen": Live-Status aller API-Anbindungen/Schnittstellen.
 */
class AdminStatusController extends Controller
{
    public function index(IntegrationStatusChecker $checker): View
    {
        return view('admin.status', ['results' => $checker->checkAll()]);
    }
}
