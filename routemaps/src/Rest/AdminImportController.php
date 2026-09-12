<?php

declare(strict_types=1);

namespace RouteMaps\Core\Rest;

use InvalidArgumentException;
use JsonException;
use RouteMaps\Core\Domain\Categories\CategoryRepositoryInterface;
use RouteMaps\Core\Domain\Routes\Route;
use RouteMaps\Core\Domain\Routes\RouteDraftService;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use RouteMaps\Core\Domain\Versions\RouteVersion;
use RouteMaps\Core\Import\Exception\UnsupportedImportFormat;
use RouteMaps\Core\Import\ImportFile;
use RouteMaps\Core\Import\ImportMapping;
use RouteMaps\Core\Import\ImporterRegistry;
use RouteMaps\Core\Import\Security\ImportFileValidator;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminImportController {
    private const NAMESPACE = 'routemaps/v1';
    private const STAGING_PREFIX = 'routemaps_import_';
    private const STAGING_TTL = 1800;

    public function __construct(
        private ImportFileValidator $validator,
        private ImporterRegistry $importers,
        private RouteRepositoryInterface $routes,
        private RouteDraftService $drafts,
        private CategoryRepositoryInterface $categories
    ) {
    }

    public function registerRoutes(): void {
        register_rest_route(self::NAMESPACE, '/admin/import/inspect', [
            'methods' => 'POST',
            'callback' => [$this, 'inspect'],
            'permission_callback' => [$this, 'canEdit'],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/import/commit', [
            'methods' => 'POST',
            'callback' => [$this, 'commit'],
            'permission_callback' => [$this, 'canEdit'],
            'args' => [
                'import_id' => [
                    'type' => 'string',
                    'required' => true,
                    'pattern' => '^[0-9a-f]{32}$',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'mapping' => ['type' => 'object', 'default' => []],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/import/(?P<import_id>[0-9a-f]{32})', [
            'methods' => 'DELETE',
            'callback' => [$this, 'cancel'],
            'permission_callback' => [$this, 'canEdit'],
        ]);
    }

    public function canEdit(WP_REST_Request $request): bool|WP_Error {
        if (!is_user_logged_in()) {
            return $this->error('rest_not_logged_in', 401, __('Authentication required.', 'routemaps'));
        }
        if (!current_user_can('edit_routemaps_routes')) {
            return $this->error('rest_forbidden', 403, __('You cannot import RouteMaps routes.', 'routemaps'));
        }

        $nonce = trim((string) $request->get_header('X-WP-Nonce'));
        if ('' === $nonce) {
            $nonce = trim((string) ($request->get_param('_wpnonce') ?? ''));
        }
        if ('' === $nonce || false === wp_verify_nonce($nonce, 'wp_rest')) {
            return $this->error('rest_cookie_invalid_nonce', 403, __('Cookie check failed.', 'routemaps'));
        }

        return true;
    }

    public function inspect(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $files = $request->get_file_params();
        $upload = $files['file'] ?? null;
        if (!is_array($upload)) {
            return $this->error('import_file_required', 400);
        }
        if (UPLOAD_ERR_OK !== (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE)) {
            return $this->error('import_upload_failed', 400);
        }

        $tmpName = $upload['tmp_name'] ?? null;
        $originalName = $this->safeOriginalName((string) ($upload['name'] ?? ''));
        $clientMime = isset($upload['type']) && is_string($upload['type']) ? $upload['type'] : null;
        if (!is_string($tmpName) || '' === $tmpName || '' === $originalName) {
            return $this->error('import_upload_invalid', 400);
        }

        try {
            $file = new ImportFile($tmpName, $originalName, $clientMime);
            $this->validator->validate($file);
            $importer = $this->importers->for($file);
            $preview = $importer->inspect($file);
            $importId = $this->stage($file);
        } catch (InvalidArgumentException|UnsupportedImportFormat $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 500);
        }

        return new WP_REST_Response([
            'import_id' => $importId,
            'preview' => $preview->toArray(),
            'expires_in' => self::STAGING_TTL,
        ], 200);
    }

    public function commit(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $importId = strtolower(trim((string) ($request->get_param('import_id') ?? '')));
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $importId)) {
            return $this->error('import_id_invalid', 400);
        }

        $stage = get_transient($this->stagingKey($importId));
        if (!is_array($stage)) {
            return $this->error('import_not_found_or_expired', 404);
        }
        if ((int) ($stage['user_id'] ?? 0) !== get_current_user_id()) {
            return $this->error('import_not_owned', 403);
        }
        if ((int) ($stage['expires_at'] ?? 0) <= time()) {
            $this->deleteStage($importId, $stage);
            return $this->error('import_not_found_or_expired', 404);
        }

        try {
            $file = $this->fileFromStage($stage);
            $this->validator->validate($file);
            $importer = $this->importers->for($file);
            $preview = $importer->inspect($file);
            $mapping = $this->mapping($request->get_param('mapping'), $preview->categories());
            if ($mapping instanceof WP_Error) {
                return $mapping;
            }
            $draft = $importer->import($file, $mapping);
            $route = $this->routes->create($draft->title(), get_current_user_id());
            $version = $this->drafts->save($route->id(), $draft, get_current_user_id());
        } catch (InvalidArgumentException|UnsupportedImportFormat|JsonException $exception) {
            return $this->error($exception->getMessage(), 400);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 500);
        }

        $this->deleteStage($importId, $stage);

        return new WP_REST_Response([
            'route' => $this->routeData($route),
            'draft' => $this->versionData($version),
        ], 201);
    }

    public function cancel(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $importId = strtolower(trim((string) ($request->get_param('import_id') ?? '')));
        if (1 !== preg_match('/^[0-9a-f]{32}$/', $importId)) {
            return $this->error('import_id_invalid', 400);
        }
        $stage = get_transient($this->stagingKey($importId));
        if (!is_array($stage)) {
            return new WP_REST_Response(null, 204);
        }
        if ((int) ($stage['user_id'] ?? 0) !== get_current_user_id()) {
            return $this->error('import_not_owned', 403);
        }
        $this->deleteStage($importId, $stage);
        return new WP_REST_Response(null, 204);
    }

    private function stage(ImportFile $file): string {
        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $path = wp_tempnam('routemaps-import');
        if (!is_string($path) || '' === $path) {
            throw new RuntimeException('import_stage_create_failed');
        }
        if (!copy($file->path(), $path)) {
            @unlink($path);
            throw new RuntimeException('import_stage_copy_failed');
        }

        $importId = bin2hex(random_bytes(16));
        $metadata = [
            'path' => $path,
            'original_name' => $file->originalName(),
            'client_mime' => $file->clientMimeType(),
            'user_id' => get_current_user_id(),
            'created_at' => time(),
            'expires_at' => time() + self::STAGING_TTL,
        ];
        if (!set_transient($this->stagingKey($importId), $metadata, self::STAGING_TTL)) {
            @unlink($path);
            throw new RuntimeException('import_stage_store_failed');
        }

        return $importId;
    }

    /** @param array<string,mixed> $stage */
    private function fileFromStage(array $stage): ImportFile {
        $path = $stage['path'] ?? null;
        $name = $stage['original_name'] ?? null;
        $mime = $stage['client_mime'] ?? null;
        if (!is_string($path) || !is_string($name) || '' === $path || '' === $name || !is_file($path)) {
            throw new RuntimeException('import_staged_file_missing');
        }
        return new ImportFile($path, $name, is_string($mime) ? $mime : null);
    }

    /**
     * @param list<array<string,mixed>> $sourceCategories
     */
    private function mapping(mixed $raw, array $sourceCategories = []): ImportMapping|WP_Error {
        $raw = is_array($raw) ? $raw : [];
        $categoryMapRaw = is_array($raw['category_map'] ?? null) ? $raw['category_map'] : [];
        $categoryMap = [];
        foreach ($categoryMapRaw as $source => $categoryId) {
            $sourceName = sanitize_text_field((string) $source);
            $id = (int) $categoryId;
            if ('' === $sourceName || $id <= 0 || null === $this->categories->find($id)) {
                return $this->error('import_category_mapping_invalid', 400);
            }
            $categoryMap[$sourceName] = $id;
        }

        $allowedSources = [];
        foreach ($sourceCategories as $category) {
            if (!is_array($category) || !is_string($category['name'] ?? null)) {
                continue;
            }
            $name = sanitize_text_field((string) $category['name']);
            if ('' !== $name) {
                $allowedSources[$name] = true;
            }
        }

        $createCategories = is_array($raw['create_categories'] ?? null) ? $raw['create_categories'] : [];
        if ([] !== $createCategories && !current_user_can('manage_routemaps_pois')) {
            return $this->error('import_category_create_forbidden', 403);
        }

        foreach ($createCategories as $source) {
            $sourceName = sanitize_text_field((string) $source);
            if ('' === $sourceName || ([] !== $allowedSources && !isset($allowedSources[$sourceName]))) {
                return $this->error('import_category_mapping_invalid', 400);
            }
            if (isset($categoryMap[$sourceName])) {
                continue;
            }

            try {
                $category = $this->categories->findBySlug($sourceName);
                if (null === $category) {
                    $category = $this->categories->create($sourceName);
                }
            } catch (InvalidArgumentException|RuntimeException $exception) {
                return $this->error($exception->getMessage(), 400);
            }
            $categoryMap[$sourceName] = $category->id();
        }

        $options = is_array($raw['options'] ?? null) ? $raw['options'] : [];
        return new ImportMapping($categoryMap, $options);
    }

    /** @param array<string,mixed> $stage */
    private function deleteStage(string $importId, array $stage): void {
        $path = $stage['path'] ?? null;
        if (is_string($path) && '' !== $path && is_file($path)) {
            @unlink($path);
        }
        delete_transient($this->stagingKey($importId));
    }

    private function stagingKey(string $importId): string {
        return self::STAGING_PREFIX . $importId;
    }

    private function safeOriginalName(string $name): string {
        $name = basename(str_replace('\\', '/', $name));
        if (function_exists('sanitize_file_name')) {
            $name = sanitize_file_name($name);
        }
        return trim($name);
    }

    /** @return array<string,mixed> */
    private function routeData(Route $route): array {
        return [
            'id' => $route->id(),
            'uuid' => $route->uuid(),
            'title' => $route->title(),
            'status' => $route->status(),
        ];
    }

    /** @return array<string,mixed> */
    private function versionData(RouteVersion $version): array {
        return [
            'id' => $version->id(),
            'route_id' => $version->routeId(),
            'version_number' => $version->versionNumber(),
            'state' => $version->state(),
        ];
    }

    private function error(string $code, int $status, ?string $message = null): WP_Error {
        return new WP_Error(
            '' !== $code ? $code : 'routemaps_import_error',
            $message ?? __('The RouteMaps import request could not be completed.', 'routemaps'),
            ['status' => $status]
        );
    }
}
