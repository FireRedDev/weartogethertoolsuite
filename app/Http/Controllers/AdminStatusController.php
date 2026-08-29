<?php

namespace App\Http\Controllers;

use App\Models\WebhookLog;
use App\Services\BackupCreator;
use App\Services\IntegrationStatusChecker;
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
        $result = $backups->create();
        $backups->pruneOlderThan();

        return response()->download($result['path'], $result['filename'])->deleteFileAfterSend(false);
    }
}
