<?php

declare(strict_types=1);

namespace RouteMaps\Core\Rest;

use InvalidArgumentException;
use JsonException;
use LogicException;
use RouteMaps\Core\Domain\Routes\Route;
use RouteMaps\Core\Domain\Routes\RouteDraftData;
use RouteMaps\Core\Domain\Routes\RouteDraftService;
use RouteMaps\Core\Domain\Routes\RouteDuplicateService;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use RouteMaps\Core\Domain\Versions\RoutePublisher;
use RouteMaps\Core\Domain\Versions\RouteVersion;
use RouteMaps\Core\Domain\Versions\RouteVersionRepositoryInterface;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminRoutesController {
    private const NAMESPACE = 'routemaps/v1';

    public function __construct(
        private RouteRepositoryInterface $routes,
        private RouteVersionRepositoryInterface $versions,
        private RouteDraftService $drafts,
        private RoutePublisher $publisher,
        private RouteDuplicateService $duplicator
    ) {
    }

    public function registerRoutes(): void {
        register_rest_route(self::NAMESPACE, '/admin/routes', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'canEdit'],
                'args' => [
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
                ],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'canEdit'],
                'args' => [
                    'title' => [
                        'type' => 'string',
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/routes/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'show'],
            'permission_callback' => [$this, 'canEdit'],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/routes/(?P<id>\d+)/draft', [
            'methods' => 'PUT',
            'callback' => [$this, 'saveDraft'],
            'permission_callback' => [$this, 'canEdit'],
            'args' => [
                'title' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'geometry' => ['type' => 'object', 'required' => true],
                'stops' => ['type' => 'array', 'required' => true],
                'style' => ['type' => 'object', 'default' => []],
                'viewport' => ['type' => 'object', 'default' => []],
                'support_overrides' => ['type' => 'object', 'default' => []],
                'map_source_override' => ['type' => ['string', 'null'], 'sanitize_callback' => 'sanitize_text_field'],
                'pois' => ['type' => 'array', 'default' => []],
                'categories' => ['type' => 'array', 'default' => []],
                'display' => ['type' => 'object', 'default' => []],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/routes/(?P<id>\d+)/publish', [
            'methods' => 'POST',
            'callback' => [$this, 'publish'],
            'permission_callback' => [$this, 'canPublish'],
            'args' => [
                'critical' => ['type' => 'boolean', 'default' => false],
                'summary' => ['type' => ['string', 'null'], 'sanitize_callback' => 'sanitize_textarea_field'],
            ],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/routes/(?P<id>\d+)/versions', [
            'methods' => 'GET',
            'callback' => [$this, 'versions'],
            'permission_callback' => [$this, 'canEdit'],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/routes/(?P<id>\d+)/versions/(?P<version_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'showVersion'],
            'permission_callback' => [$this, 'canEdit'],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/routes/(?P<id>\d+)/duplicate', [
            'methods' => 'POST',
            'callback' => [$this, 'duplicate'],
            'permission_callback' => [$this, 'canEdit'],
            'args' => [
                'title' => ['type' => ['string', 'null'], 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);
    }

    public function canEdit(): bool|WP_Error {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_not_logged_in', __('Authentication required.', 'routemaps'), ['status' => 401]);
        }
        if (!current_user_can('edit_routemaps_routes')) {
            return new WP_Error('rest_forbidden', __('You cannot edit RouteMaps routes.', 'routemaps'), ['status' => 403]);
        }

        return true;
    }

    public function canPublish(): bool|WP_Error {
        $edit = $this->canEdit();
        if (true !== $edit) {
            return $edit;
        }
        if (!current_user_can('publish_routemaps_routes')) {
            return new WP_Error('rest_forbidden', __('You cannot publish RouteMaps routes.', 'routemaps'), ['status' => 403]);
        }

        return true;
    }

    public function index(WP_REST_Request $request): WP_REST_Response {
        $limit = (int) ($request->get_param('per_page') ?? 100);
        $offset = (int) ($request->get_param('offset') ?? 0);
        $routes = array_map([$this, 'routeData'], $this->routes->list($limit, $offset));

        return new WP_REST_Response($routes, 200);
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $title = sanitize_text_field((string) ($request->get_param('title') ?? ''));
        if ('' === $title) {
            return $this->error('route_title_required', __('Route title is required.', 'routemaps'), 400);
        }

        try {
            $route = $this->routes->create($title, get_current_user_id());
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->domainError($exception);
        }

        return new WP_REST_Response($this->routeData($route), 201);
    }

    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $route = $this->routes->find((int) $request->get_param('id'));
        if (null === $route) {
            return $this->error('route_not_found', __('Route not found.', 'routemaps'), 404);
        }

        try {
            $data = $this->routeData($route);
            $draft = $this->versions->findDraftForRoute($route->id());
            $data['draft'] = null === $draft ? null : [
                'version' => $this->versionData($draft),
                'data' => $this->decodedSnapshot($draft),
            ];
            $editorSource = $draft;
            if (null === $editorSource && null !== $route->currentPublishedVersionId()) {
                $editorSource = $this->versions->findPublishedForRoute($route->id(), $route->currentPublishedVersionId());
            }
            $data['editor_source'] = null === $editorSource ? null : [
                'version' => $this->versionData($editorSource),
                'data' => $this->decodedSnapshot($editorSource),
            ];
        } catch (JsonException|RuntimeException $exception) {
            return $this->domainError($exception);
        }

        return new WP_REST_Response($data, 200);
    }

    public function saveDraft(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $routeId = (int) $request->get_param('id');
        if (null === $this->routes->find($routeId)) {
            return $this->error('route_not_found', __('Route not found.', 'routemaps'), 404);
        }

        try {
            $draft = $this->draftFromRequest($request);
            $version = $this->drafts->save($routeId, $draft, get_current_user_id());
        } catch (InvalidArgumentException|JsonException|RuntimeException|LogicException $exception) {
            return $this->domainError($exception);
        }

        return new WP_REST_Response($this->versionData($version), 200);
    }

    public function publish(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $critical = filter_var($request->get_param('critical') ?? false, FILTER_VALIDATE_BOOLEAN);
            $rawSummary = $request->get_param('summary');
            $summary = is_string($rawSummary) && '' !== trim($rawSummary)
                ? sanitize_textarea_field($rawSummary)
                : null;
            $version = $this->publisher->publish(
                (int) $request->get_param('id'),
                get_current_user_id(),
                $critical,
                $summary
            );
        } catch (RuntimeException|LogicException|JsonException|InvalidArgumentException $exception) {
            return $this->domainError($exception);
        }

        return new WP_REST_Response($this->versionData($version), 200);
    }

    public function versions(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $routeId = (int) $request->get_param('id');
        if (null === $this->routes->find($routeId)) {
            return $this->error('route_not_found', __('Route not found.', 'routemaps'), 404);
        }

        $items = array_map([$this, 'versionData'], $this->versions->listPublishedForRoute($routeId));
        return new WP_REST_Response(['items' => $items], 200);
    }

    public function showVersion(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $routeId = (int) $request->get_param('id');
        if (null === $this->routes->find($routeId)) {
            return $this->error('route_not_found', __('Route not found.', 'routemaps'), 404);
        }

        $version = $this->versions->findPublishedForRoute($routeId, (int) $request->get_param('version_id'));
        if (null === $version) {
            return $this->error('route_version_not_found', __('Route version not found.', 'routemaps'), 404);
        }

        try {
            $data = $this->decodedSnapshot($version);
        } catch (JsonException|RuntimeException $exception) {
            return $this->domainError($exception);
        }

        return new WP_REST_Response([
            'version' => $this->versionData($version),
            'data' => $data,
        ], 200);
    }

    public function duplicate(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $rawTitle = $request->get_param('title');
        $title = is_string($rawTitle) && '' !== trim($rawTitle) ? sanitize_text_field($rawTitle) : null;

        try {
            $route = $this->duplicator->duplicate((int) $request->get_param('id'), get_current_user_id(), $title);
        } catch (RuntimeException|InvalidArgumentException|JsonException $exception) {
            return $this->domainError($exception);
        }

        return new WP_REST_Response($this->routeData($route), 201);
    }

    private function draftFromRequest(WP_REST_Request $request): RouteDraftData {
        $params = $request->get_json_params();
        if ([] === $params) {
            $params = $request->get_params();
        }

        return new RouteDraftData(
            sanitize_text_field((string) ($params['title'] ?? '')),
            is_array($params['geometry'] ?? null) ? $params['geometry'] : [],
            $this->listOfArrays($params['stops'] ?? []),
            is_array($params['style'] ?? null) ? $params['style'] : [],
            is_array($params['viewport'] ?? null) ? $params['viewport'] : [],
            is_array($params['support_overrides'] ?? null) ? $params['support_overrides'] : [],
            $this->nullableSanitizedString($params['map_source_override'] ?? null),
            $this->listOfArrays($params['pois'] ?? []),
            $this->listOfArrays($params['categories'] ?? []),
            is_array($params['display'] ?? null) ? $params['display'] : []
        );
    }

    /** @return list<array<string,mixed>> */
    private function listOfArrays(mixed $value): array {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    private function nullableSanitizedString(mixed $value): ?string {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        return sanitize_text_field($value);
    }

    /** @return array<string,mixed> */
    private function routeData(Route $route): array {
        return [
            'id' => $route->id(),
            'uuid' => $route->uuid(),
            'title' => $route->title(),
            'slug' => $route->slug(),
            'status' => $route->status(),
            'cover_attachment_id' => $route->coverAttachmentId(),
            'current_published_version_id' => $route->currentPublishedVersionId(),
            'map_source_id' => $route->mapSourceId(),
            'created_by' => $route->createdBy(),
            'created_at' => $route->createdAt(),
            'updated_at' => $route->updatedAt(),
        ];
    }

    /** @return array<string,mixed> */
    private function versionData(RouteVersion $version): array {
        return [
            'id' => $version->id(),
            'route_id' => $version->routeId(),
            'version_number' => $version->versionNumber(),
            'state' => $version->state(),
            'is_critical' => $version->isCritical(),
            'content_hash' => $version->contentHash(),
            'change_summary' => $version->changeSummary(),
            'created_at' => $version->createdAt(),
            'published_at' => $version->publishedAt(),
        ];
    }

    /** @return array<string,mixed> */
    private function decodedSnapshot(RouteVersion $version): array {
        $data = json_decode($version->snapshotJson(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('route_snapshot_invalid');
        }
        return $data;
    }

    private function domainError(\Throwable $exception): WP_Error {
        $code = $exception->getMessage();
        $status = match ($code) {
            'route_not_found', 'route_version_not_found' => 404,
            'route_draft_not_found', 'published_version_immutable' => 409,
            default => 400,
        };

        return $this->error($code, __('The RouteMaps request could not be completed.', 'routemaps'), $status);
    }

    private function error(string $code, string $message, int $status): WP_Error {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
