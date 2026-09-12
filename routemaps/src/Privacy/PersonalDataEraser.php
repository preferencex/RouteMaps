<?php

declare(strict_types=1);

namespace RouteMaps\Core\Privacy;

use wpdb;

final class PersonalDataEraser {
    private const BATCH_SIZE = 50;

    public function __construct(private wpdb $db) {
    }

    public function registerHooks(): void {
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register']);
    }

    /** @param array<string,mixed> $erasers @return array<string,mixed> */
    public function register(array $erasers): array {
        $erasers['routemaps'] = [
            'eraser_friendly_name' => __('RouteMaps', 'routemaps'),
            'callback' => [$this, 'erase'],
        ];
        return $erasers;
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public function erase(string $emailAddress, int $page = 1): array {
        $email = strtolower(trim(sanitize_email($emailAddress)));
        if ('' === $email) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $user = get_user_by('email', $email);
        $userId = $user ? (int) $user->ID : 0;
        $guestIds = $this->guestShareIds($email);
        $eventIds = $userId > 0 ? $this->erasableEventIds($userId) : [];
        $removed = false;
        $now = current_time('mysql', true);

        $licenseUsers = $this->db->prefix . 'routemaps_license_users';
        foreach ($guestIds as $id) {
            $anonymousEmail = 'erased-' . substr(hash('sha256', $id . '|' . $email), 0, 20) . '@invalid.local';
            $updated = $this->db->update($licenseUsers, [
                'user_id' => null,
                'email' => $anonymousEmail,
                'status' => 'revoked',
                'invite_token_hash' => null,
                'updated_at' => $now,
            ], ['id' => $id], ['%d', '%s', '%s', '%s', '%s'], ['%d']);
            $removed = $removed || false !== $updated;
        }

        $events = $this->db->prefix . 'routemaps_access_events';
        foreach ($eventIds as $id) {
            $updated = $this->db->update($events, ['user_id' => null], ['id' => $id], ['%d'], ['%d']);
            $removed = $removed || false !== $updated;
        }

        $ownerCount = $userId > 0 ? $this->ownerLicenseCount($userId) : 0;
        $messages = [];
        if ($ownerCount > 0) {
            $messages[] = __('As licenças do titular associadas a encomendas WooCommerce foram conservadas de acordo com a política de retenção comercial da loja.', 'routemaps');
        }

        return [
            'items_removed' => $removed,
            'items_retained' => $ownerCount > 0,
            'messages' => $messages,
            'done' => count($guestIds) < self::BATCH_SIZE && count($eventIds) < self::BATCH_SIZE,
        ];
    }

    /** @return list<int> */
    private function guestShareIds(string $email): array {
        $table = $this->db->prefix . 'routemaps_license_users';
        $rows = $this->db->get_col($this->db->prepare(
            "SELECT id FROM {$table} WHERE role = 'guest' AND LOWER(email) = %s ORDER BY id ASC LIMIT %d",
            $email,
            self::BATCH_SIZE
        ));
        return is_array($rows) ? array_values(array_map('intval', $rows)) : [];
    }

    /** @return list<int> */
    private function erasableEventIds(int $userId): array {
        $events = $this->db->prefix . 'routemaps_access_events';
        $licenses = $this->db->prefix . 'routemaps_licenses';
        $rows = $this->db->get_col($this->db->prepare(
            "SELECT e.id
             FROM {$events} e
             LEFT JOIN {$licenses} l ON l.id = e.license_id
             WHERE e.user_id = %d AND (l.id IS NULL OR l.owner_user_id <> %d)
             ORDER BY e.id ASC LIMIT %d",
            $userId,
            $userId,
            self::BATCH_SIZE
        ));
        return is_array($rows) ? array_values(array_map('intval', $rows)) : [];
    }

    private function ownerLicenseCount(int $userId): int {
        $licenses = $this->db->prefix . 'routemaps_licenses';
        return (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$licenses} WHERE owner_user_id = %d",
            $userId
        ));
    }
}
