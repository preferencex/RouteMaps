<?php

declare(strict_types=1);

namespace RouteMaps\Core\Rest;

use InvalidArgumentException;
use JsonException;
use RouteMaps\Core\Domain\Categories\CategoryRepositoryInterface;
use RouteMaps\Core\Domain\POI\Poi;
use RouteMaps\Core\Domain\POI\PoiRepositoryInterface;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminPoisController {
    private const NAMESPACE = 'routemaps/v1';

    public function __construct(
        private PoiRepositoryInterface $pois,
        private CategoryRepositoryInterface $categories
    ) {
    }

    public function registerRoutes(): void {
        register_rest_route(self::NAMESPACE, '/admin/pois', [
            ['methods' => 'GET', 'callback' => [$this, 'index'], 'permission_callback' => [$this, 'canManage']],
            ['methods' => 'POST', 'callback' => [$this, 'create'], 'permission_callback' => [$this, 'canManage']],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/pois/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [$this, 'show'], 'permission_callback' => [$this, 'canManage']],
            ['methods' => 'PUT', 'callback' => [$this, 'update'], 'permission_callback' => [$this, 'canManage']],
            ['methods' => 'DELETE', 'callback' => [$this, 'delete'], 'permission_callback' => [$this, 'canManage']],
        ]);
    }

    public function canManage(): bool|WP_Error {
        if (!is_user_logged_in()) {
            return new WP_Error('rest_not_logged_in', __('Authentication required.', 'routemaps'), ['status' => 401]);
        }
        if (!current_user_can('manage_routemaps_pois')) {
            return new WP_Error('rest_forbidden', __('You cannot manage RouteMaps POIs.', 'routemaps'), ['status' => 403]);
        }
        return true;
    }

    public function index(WP_REST_Request $request): WP_REST_Response {
        $filters = [];
        foreach (['category_id', 'status', 'query'] as $key) {
            $value = $request->get_param($key);
            if (null !== $value && '' !== $value) {
                $filters[$key] = $value;
            }
        }
        $result = $this->pois->search($filters, max(1, (int) ($request->get_param('page') ?? 1)), max(1, (int) ($request->get_param('per_page') ?? 50)));
        $result['items'] = array_map([$this, 'poiData'], $result['items']);
        return new WP_REST_Response($result, 200);
    }

    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $poi = $this->pois->find((int) $request->get_param('id'));
        if (null === $poi) {
            return $this->error('poi_not_found', 404);
        }
        return new WP_REST_Response($this->poiData($poi), 200);
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $payload = $this->validatedPayload($request);
        if ($payload instanceof WP_Error) {
            return $payload;
        }
        try {
            $poi = $this->pois->create($payload, get_current_user_id());
        } catch (InvalidArgumentException|RuntimeException|JsonException $exception) {
            return $this->domainError($exception);
        }
        return new WP_REST_Response($this->poiData($poi), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');
        if (null === $this->pois->find($id)) {
            return $this->error('poi_not_found', 404);
        }
        $payload = $this->validatedPayload($request);
        if ($payload instanceof WP_Error) {
            return $payload;
        }
        try {
            $poi = $this->pois->update($id, $payload, get_current_user_id());
        } catch (InvalidArgumentException|RuntimeException|JsonException $exception) {
            return $this->domainError($exception);
        }
        return new WP_REST_Response($this->poiData($poi), 200);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');
        try {
            $this->pois->delete($id);
        } catch (RuntimeException $exception) {
            return $this->domainError($exception);
        }
        return new WP_REST_Response(null, 204);
    }

    /** @return array<string,mixed>|WP_Error */
    private function validatedPayload(WP_REST_Request $request): array|WP_Error {
        $params = $request->get_json_params();
        if ([] === $params) {
            $params = $request->get_params();
        }
        $name = sanitize_text_field((string) ($params['name'] ?? ''));
        $categoryId = (int) ($params['category_id'] ?? 0);
        if ('' === $name) {
            return $this->error('poi_name_required', 400);
        }
        if ($categoryId <= 0 || null === $this->categories->find($categoryId)) {
            return $this->error('poi_category_required', 400);
        }
        if (!is_numeric($params['latitude'] ?? null) || (float) $params['latitude'] < -90 || (float) $params['latitude'] > 90) {
            return $this->error('invalid_poi_latitude', 400);
        }
        if (!is_numeric($params['longitude'] ?? null) || (float) $params['longitude'] < -180 || (float) $params['longitude'] > 180) {
            return $this->error('invalid_poi_longitude', 400);
        }
        $website = (string) ($params['website'] ?? '');
        if ('' !== $website && !$this->isSafeHttpUrl($website)) {
            return $this->error('invalid_poi_website', 400);
        }
        $cta = is_array($params['cta'] ?? null) ? $params['cta'] : [];
        if (isset($cta['url']) && '' !== (string) $cta['url'] && !$this->isSafeHttpUrl((string) $cta['url'])) {
            return $this->error('invalid_poi_cta_url', 400);
        }
        $mainAttachmentId = isset($params['main_attachment_id']) ? (int) $params['main_attachment_id'] : null;
        if (null !== $mainAttachmentId && $mainAttachmentId > 0 && 'attachment' !== get_post_type($mainAttachmentId)) {
            return $this->error('invalid_poi_attachment', 400);
        }
        $gallery = is_array($params['gallery'] ?? null) ? array_values($params['gallery']) : [];
        foreach ($gallery as $attachmentId) {
            if ((int) $attachmentId <= 0 || 'attachment' !== get_post_type((int) $attachmentId)) {
                return $this->error('invalid_poi_attachment', 400);
            }
        }

        return [
            'name' => $name,
            'category_id' => $categoryId,
            'latitude' => (float) $params['latitude'],
            'longitude' => (float) $params['longitude'],
            'description' => (string) ($params['description'] ?? ''),
            'address' => (string) ($params['address'] ?? ''),
            'phone' => (string) ($params['phone'] ?? ''),
            'website' => $website,
            'opening_hours' => (string) ($params['opening_hours'] ?? ''),
            'route_note' => (string) ($params['route_note'] ?? ''),
            'main_attachment_id' => $mainAttachmentId,
            'gallery' => array_map('intval', $gallery),
            'icon' => (string) ($params['icon'] ?? ''),
            'color' => (string) ($params['color'] ?? ''),
            'cta' => $cta,
            'status' => (string) ($params['status'] ?? 'active'),
        ];
    }

    private function isSafeHttpUrl(string $url): bool {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) && '' !== esc_url_raw($url);
    }

    /** @return array<string,mixed> */
    private function poiData(Poi $poi): array {
        $gallery = [];
        foreach ($poi->gallery() as $attachmentId) {
            $url = wp_get_attachment_url($attachmentId);
            $gallery[] = ['id' => $attachmentId, 'url' => is_string($url) ? $url : null];
        }
        $mainUrl = null;
        if (null !== $poi->mainAttachmentId()) {
            $url = wp_get_attachment_url($poi->mainAttachmentId());
            $mainUrl = is_string($url) ? $url : null;
        }
        return [
            'id' => $poi->id(), 'uuid' => $poi->uuid(), 'name' => $poi->name(), 'category_id' => $poi->categoryId(),
            'latitude' => $poi->latitude(), 'longitude' => $poi->longitude(), 'description' => $poi->description(), 'address' => $poi->address(),
            'phone' => $poi->phone(), 'website' => $poi->website(), 'opening_hours' => $poi->openingHours(), 'route_note' => $poi->routeNote(),
            'main_attachment_id' => $poi->mainAttachmentId(), 'main_image_url' => $mainUrl, 'gallery_attachment_ids' => $poi->gallery(), 'gallery' => $gallery,
            'icon' => $poi->icon(), 'color' => $poi->color(), 'cta' => $poi->cta(), 'status' => $poi->status(),
        ];
    }

    private function domainError(\Throwable $exception): WP_Error {
        $status = in_array($exception->getMessage(), ['poi_not_found'], true) ? 404 : 400;
        return $this->error($exception->getMessage(), $status);
    }

    private function error(string $code, int $status): WP_Error {
        return new WP_Error($code, __('The RouteMaps POI request could not be completed.', 'routemaps'), ['status' => $status]);
    }
}
