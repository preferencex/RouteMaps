<?php
/**
 * Plugin Name: RouteMaps CI Mail Capture
 * Description: Test-only wp_mail capture with a minimal Mailpit-compatible HTTP surface.
 * Version: 1.0.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('ROUTEMAPS_CI_E2E') || true !== ROUTEMAPS_CI_E2E) {
    return;
}

const ROUTEMAPS_CI_MAIL_OPTION = 'routemaps_ci_mail_messages';

// Browser acceptance scenarios reuse fixture users across viewport projects.
// Bypass throttling only inside the dedicated E2E plugin; production limits are unchanged.
add_filter('routemaps_rate_limiter_pre_consume', static fn(): bool => true, 10, 4);

add_filter('pre_wp_mail', static function ($return, array $atts) {
    $messages = get_option(ROUTEMAPS_CI_MAIL_OPTION, []);
    if (!is_array($messages)) {
        $messages = [];
    }

    $to = $atts['to'] ?? [];
    $recipients = is_array($to) ? $to : preg_split('/\s*,\s*/', (string) $to);
    $recipients = array_values(array_filter(array_map(
        static fn($value): string => strtolower(trim((string) $value)),
        is_array($recipients) ? $recipients : []
    )));

    $id = (string) (count($messages) + 1);
    $messages[] = [
        'ID' => $id,
        'To' => $recipients,
        'Subject' => (string) ($atts['subject'] ?? ''),
        'HTML' => (string) ($atts['message'] ?? ''),
        'Text' => wp_strip_all_tags((string) ($atts['message'] ?? '')),
        'Created' => gmdate('c'),
    ];
    update_option(ROUTEMAPS_CI_MAIL_OPTION, $messages, false);

    return true;
}, 10, 2);

add_action('init', static function (): void {
    if (PHP_SAPI === 'cli') {
        return;
    }

    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ('/api/v1/search' !== rtrim($path, '/')) {
        if (1 !== preg_match('#^/api/v1/message/([^/]+)/?$#', $path, $match)) {
            return;
        }

        $id = rawurldecode((string) $match[1]);
        $messages = get_option(ROUTEMAPS_CI_MAIL_OPTION, []);
        foreach (is_array($messages) ? $messages : [] as $message) {
            if ((string) ($message['ID'] ?? '') !== $id) {
                continue;
            }
            nocache_headers();
            wp_send_json([
                'ID' => (string) $message['ID'],
                'HTML' => (string) ($message['HTML'] ?? ''),
                'Text' => (string) ($message['Text'] ?? ''),
                'Subject' => (string) ($message['Subject'] ?? ''),
            ]);
        }
        status_header(404);
        wp_send_json(['error' => 'message_not_found'], 404);
    }

    $query = isset($_GET['query']) ? sanitize_text_field(wp_unslash((string) $_GET['query'])) : '';
    $recipient = '';
    if (1 === preg_match('/(?:^|\s)to:([^\s]+)/i', $query, $match)) {
        $recipient = strtolower(trim((string) $match[1]));
    }

    $messages = get_option(ROUTEMAPS_CI_MAIL_OPTION, []);
    $found = [];
    foreach (is_array($messages) ? $messages : [] as $message) {
        $to = is_array($message['To'] ?? null) ? $message['To'] : [];
        if ('' !== $recipient && !in_array($recipient, $to, true)) {
            continue;
        }
        $found[] = [
            'ID' => (string) ($message['ID'] ?? ''),
            'To' => $to,
            'Subject' => (string) ($message['Subject'] ?? ''),
            'Created' => (string) ($message['Created'] ?? ''),
        ];
    }

    nocache_headers();
    wp_send_json(['messages' => array_reverse($found)]);
});
