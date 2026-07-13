<?php
/**
 * Plugin Name: Wear Together Toolsuite – Admin-Benachrichtigung
 * Description: Stellt einen REST-Endpunkt (/wp-json/weartogether/v1/notify) bereit, über den die
 *              Wear Together Order Suite bei ausgefallenen Schnittstellen eine E-Mail an die
 *              WordPress-Administrator-Adresse schickt. Der eigentliche Versand läuft komplett über
 *              WordPress' eigenes wp_mail() (bzw. ein dort installiertes SMTP-Plugin wie WP Mail SMTP) —
 *              die Toolsuite selbst hat keinen Mailer und sendet nie direkt E-Mails.
 *
 * Installation: Diese Datei nach wp-content/mu-plugins/weartogether-notify.php kopieren
 *               (Ordner "mu-plugins" ggf. anlegen). mu-Plugins werden automatisch aktiv,
 *               keine Aktivierung im Plugin-Screen nötig.
 *
 * Authentifizierung: Nutzt dasselbe WordPress-Anwendungspasswort, das die Toolsuite
 *               bereits für den CPT "schule" verwendet (WP_APP_USER/WP_APP_PASSWORD in der
 *               .env der Toolsuite) — dieses Konto braucht die Capability "manage_options"
 *               (Administrator), sonst lehnt der Endpunkt ab.
 */

add_action('rest_api_init', function () {
    register_rest_route('weartogether/v1', '/notify', [
        'methods' => 'POST',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        },
        'callback' => function (WP_REST_Request $request) {
            $subject = sanitize_text_field((string) $request->get_param('subject'));
            $message = sanitize_textarea_field((string) $request->get_param('message'));

            if ($subject === '' || $message === '') {
                return new WP_Error('missing_params', 'subject und message sind erforderlich.', ['status' => 400]);
            }

            $to = get_option('admin_email');
            $sent = wp_mail($to, '[Wear Together Toolsuite] '.$subject, $message);

            return ['ok' => (bool) $sent, 'to' => $to];
        },
    ]);
});
