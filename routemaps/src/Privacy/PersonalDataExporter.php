<?php

declare(strict_types=1);

namespace RouteMaps\Core\Privacy;

use wpdb;

final class PersonalDataExporter {
    private const PAGE_SIZE = 50;

    public function __construct(private wpdb $db) {
    }

    public function registerHooks(): void {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register']);
    }

    /** @param array<string,mixed> $exporters @return array<string,mixed> */
    public function register(array $exporters): array {
        $exporters['routemaps'] = [
            'exporter_friendly_name' => __('RouteMaps', 'routemaps'),
            'callback' => [$this, 'export'],
        ];
        return $exporters;
    }

    /** @return array{data:list<array<string,mixed>>,done:bool} */
    public function export(string $emailAddress, int $page = 1): array {
        $email = strtolower(trim(sanitize_email($emailAddress)));
        $page = max(1, $page);
        if ('' === $email) {
            return ['data' => [], 'done' => true];
        }

        $user = get_user_by('email', $email);
        $userId = $user ? (int) $user->ID : 0;
        $offset = ($page - 1) * self::PAGE_SIZE;

        $ownerRows = $userId > 0 ? $this->ownerLicenseRows($userId, $offset) : [];
        $shareRows = $this->shareRows($email, $offset);
        $eventRows = $userId > 0 ? $this->eventRows($userId, $offset) : [];

        $data = [];
        foreach ($ownerRows as $row) {
            $data[] = $this->item('license-' . (int) $row['id'], [
                __('Tipo', 'routemaps') => __('Licença do titular', 'routemaps'),
                __('Licença', 'routemaps') => (string) $row['uuid'],
                __('Rota', 'routemaps') => (string) ($row['route_title'] ?? ''),
                __('Estado', 'routemaps') => (string) $row['status'],
                __('Validade', 'routemaps') => (string) $row['validity_mode'],
                __('Válida desde', 'routemaps') => (string) ($row['valid_from'] ?? ''),
                __('Válida até', 'routemaps') => (string) ($row['valid_until'] ?? ''),
                __('Primeiro acesso', 'routemaps') => (string) ($row['first_access_at'] ?? ''),
                __('Criada em', 'routemaps') => (string) $row['created_at'],
                __('Atualizada em', 'routemaps') => (string) $row['updated_at'],
            ]);
        }
        foreach ($shareRows as $row) {
            $data[] = $this->item('share-' . (int) $row['id'], [
                __('Tipo', 'routemaps') => __('Partilha de roteiro', 'routemaps'),
                __('E-mail do convite', 'routemaps') => (string) $row['email'],
                __('Licença', 'routemaps') => (string) $row['license_uuid'],
                __('Rota', 'routemaps') => (string) ($row['route_title'] ?? ''),
                __('Estado', 'routemaps') => (string) $row['status'],
                __('Criada em', 'routemaps') => (string) $row['created_at'],
                __('Atualizada em', 'routemaps') => (string) $row['updated_at'],
            ]);
        }
        foreach ($eventRows as $row) {
            $data[] = $this->item('access-event-' . (int) $row['id'], [
                __('Tipo', 'routemaps') => __('Evento de acesso', 'routemaps'),
                __('Evento', 'routemaps') => (string) $row['event_type'],
                __('Motivo', 'routemaps') => (string) ($row['reason_code'] ?? ''),
                __('Licença', 'routemaps') => (string) ($row['license_uuid'] ?? ''),
                __('Rota', 'routemaps') => (string) ($row['route_title'] ?? ''),
                __('Data', 'routemaps') => (string) $row['created_at'],
            ]);
        }

        return [
            'data' => $data,
            'done' => count($ownerRows) < self::PAGE_SIZE
                && count($shareRows) < self::PAGE_SIZE
                && count($eventRows) < self::PAGE_SIZE,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function ownerLicenseRows(int $userId, int $offset): array {
        $licenses = $this->db->prefix . 'routemaps_licenses';
        $routes = $this->db->prefix . 'routemaps_routes';
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT l.id,l.uuid,l.status,l.validity_mode,l.valid_from,l.valid_until,l.first_access_at,l.created_at,l.updated_at,r.title AS route_title
             FROM {$licenses} l
             LEFT JOIN {$routes} r ON r.id = l.route_id
             WHERE l.owner_user_id = %d
             ORDER BY l.id ASC LIMIT %d OFFSET %d",
            $userId,
            self::PAGE_SIZE,
            $offset
        ), ARRAY_A);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string,mixed>> */
    private function shareRows(string $email, int $offset): array {
        $users = $this->db->prefix . 'routemaps_license_users';
        $licenses = $this->db->prefix . 'routemaps_licenses';
        $routes = $this->db->prefix . 'routemaps_routes';
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT lu.id,lu.email,lu.status,lu.created_at,lu.updated_at,l.uuid AS license_uuid,r.title AS route_title
             FROM {$users} lu
             JOIN {$licenses} l ON l.id = lu.license_id
             LEFT JOIN {$routes} r ON r.id = l.route_id
             WHERE lu.role = 'guest' AND LOWER(lu.email) = %s
             ORDER BY lu.id ASC LIMIT %d OFFSET %d",
            $email,
            self::PAGE_SIZE,
            $offset
        ), ARRAY_A);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array<string,mixed>> */
    private function eventRows(int $userId, int $offset): array {
        $events = $this->db->prefix . 'routemaps_access_events';
        $licenses = $this->db->prefix . 'routemaps_licenses';
        $routes = $this->db->prefix . 'routemaps_routes';
        $rows = $this->db->get_results($this->db->prepare(
            "SELECT e.id,e.event_type,e.reason_code,e.created_at,l.uuid AS license_uuid,r.title AS route_title
             FROM {$events} e
             LEFT JOIN {$licenses} l ON l.id = e.license_id
             LEFT JOIN {$routes} r ON r.id = l.route_id
             WHERE e.user_id = %d
             ORDER BY e.id ASC LIMIT %d OFFSET %d",
            $userId,
            self::PAGE_SIZE,
            $offset
        ), ARRAY_A);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @param array<string,string> $values @return array<string,mixed> */
    private function item(string $itemId, array $values): array {
        $data = [];
        foreach ($values as $name => $value) {
            $data[] = ['name' => $name, 'value' => $value];
        }
        return [
            'group_id' => 'routemaps',
            'group_label' => __('RouteMaps', 'routemaps'),
            'item_id' => 'routemaps-' . $itemId,
            'data' => $data,
        ];
    }
}
