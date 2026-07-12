<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Protokolliert JEDEN Treffer auf den FluentForms-Webhook-Endpunkt — noch vor
 * jeglicher Secret-/Mapping-Logik. So lässt sich zweifelsfrei sehen, ob (und
 * was) FluentForms wirklich an die App schickt, ohne Server-Logs lesen zu müssen.
 */
class WebhookLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['secret_ok' => 'boolean'];
    }

    /** Legt einen Log-Eintrag aus dem eingehenden Request an und hält die Tabelle klein. */
    public static function record(Request $request, bool $secretOk, string $outcome): self
    {
        $log = self::create([
            'method' => $request->method(),
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'secret_ok' => $secretOk,
            'outcome' => $outcome,
            'body_snippet' => mb_substr($request->getContent() ?: '', 0, 4000),
        ]);

        // Nur die letzten 100 Einträge behalten.
        $keepFrom = self::orderByDesc('id')->skip(100)->take(1)->value('id');
        if ($keepFrom !== null) {
            self::where('id', '<=', $keepFrom)->delete();
        }

        return $log;
    }
}
