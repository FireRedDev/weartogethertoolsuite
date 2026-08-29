<?php

namespace App\Http\Controllers;

use App\Services\SchoolShop\Dashboard;
use App\Services\SchoolShop\OrderWindowExtender;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Dashboard $dashboard, OrderWindowExtender $extender): View
    {
        // Abgelaufene Sammelbestellfenster verlängern, falls fällig. Gedrosselt
        // und fehlertolerant — greift auch ohne eingerichteten Cron, blockiert
        // die Startseite aber nie.
        $extended = $extender->runDueOpportunistically();

        $groups = $dashboard->groups();

        return view('home', [
            'groups' => $groups,
            'openCount' => $dashboard->openCount($groups),
            'extended' => $extended,
        ]);
    }
}
