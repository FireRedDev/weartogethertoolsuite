<?php

namespace App\Http\Controllers;

use App\Services\SchoolShop\Dashboard;
use App\Services\SchoolShop\OrderWindowExtender;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(Dashboard $dashboard, OrderWindowExtender $extender): View
    {
        // Abgelaufene Sammelbestellfenster verlängern, falls fällig — aber erst
        // NACH der Antwort. Die Verlängerung schreibt in den Schule-Eintrag,
        // und ein hängendes WordPress würde die Startseite sonst je fälliger
        // Schule bis zu einer Minute blockieren. Angezeigt wird das Ergebnis
        // des vorigen Laufs.
        $extended = $extender->lastResult();
        app()->terminating(static function () use ($extender) {
            @ignore_user_abort(true);
            $extender->runDueOpportunistically();
        });

        $groups = $dashboard->groups();

        return view('home', [
            'groups' => $groups,
            'openCount' => $dashboard->openCount($groups),
            'extended' => $extended,
        ]);
    }
}
