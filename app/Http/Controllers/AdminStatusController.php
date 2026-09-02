<?php

namespace App\Http\Controllers;

use App\Models\WebhookLog;
use App\Services\BackupCreator;
use App\Services\IntegrationStatusChecker;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Admin-Informationen": Live-Status aller API-Anbindungen/Schnittstellen.
 */
class AdminStatusController extends Controller
{
    public function index(IntegrationStatusChecker $checker): View
    {
        return view('admin.status', [
            'results' => $checker->checkAll(),
            // Der Webhook lässt sich nicht aktiv testen — sein Protokoll ist
            // der einzige Beleg dafür, ob FluentForms die App überhaupt erreicht.
            'webhookLogs' => WebhookLog::orderByDesc('id')->limit(20)->get(),
        ]);
    }

    /**
     * Sicherung erzeugen und sofort ausliefern. Datenbank und Uploads liegen
     * nur auf dem Server — hiermit lässt sich eine Kopie mitnehmen.
     */
    public function backup(BackupCreator $backups): BinaryFileResponse
    {
        // Eine Sicherung gleichzeitig: Das Packen von Datenbank und Uploads
        // dauert und braucht Platz. Zwei Klicks kurz hintereinander würden
        // zweimal parallel packen — der schnellste Weg, den Datenträger
        // vollzuschreiben.
        $lock = Cache::lock('admin.backup', 600);
        if (! $lock->get()) {
            abort(409, 'Es läuft bereits eine Sicherung. Bitte einen Moment warten und erneut versuchen.');
        }

        try {
            $result = $backups->create();
            $backups->pruneOlderThan();
        } finally {
            $lock->release();
        }

        return response()->download($result['path'], $result['filename'])->deleteFileAfterSend(false);
    }
}
