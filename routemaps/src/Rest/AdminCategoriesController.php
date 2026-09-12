<?php

declare(strict_types=1);

namespace RouteMaps\Core\Rest;

use InvalidArgumentException;
use RouteMaps\Core\Domain\Categories\Category;
use RouteMaps\Core\Domain\Categories\CategoryRepositoryInterface;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AdminCategoriesController {
    private const NAMESPACE = 'routemaps/v1';

    public function __construct(private CategoryRepositoryInterface $categories) {
    }

    public function registerRoutes(): void {
        register_rest_route(self::NAMESPACE, '/admin/categories', [
            ['methods' => 'GET', 'callback' => [$this, 'index'], 'permission_callback' => [$this, 'canManage']],
            ['methods' => 'POST', 'callback' => [$this, 'create'], 'permission_callback' => [$this, 'canManage']],
        ]);
        register_rest_route(self::NAMESPACE, '/admin/categories/(?P<id>\d+)', [
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
            return new WP_Error('rest_forbidden', __('You cannot manage RouteMaps categories.', 'routemaps'), ['status' => 403]);
        }
        return true;
    }

    public function index(WP_REST_Request $request): WP_REST_Response {
        $filters = [];
        if (null !== $request->get_param('active')) {
            $filters['active'] = filter_var($request->get_param('active'), FILTER_VALIDATE_BOOLEAN);
        }
        if (null !== $request->get_param('query')) {
            $filters['query'] = sanitize_text_field((string) $request->get_param('query'));
        }
        $result = $this->categories->search($filters, max(1, (int) ($request->get_param('page') ?? 1)), max(1, (int) ($request->get_param('per_page') ?? 50)));
        $result['items'] = array_map([$this, 'categoryData'], $result['items']);
        return new WP_REST_Response($result, 200);
    }

    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $category = $this->categories->find((int) $request->get_param('id'));
        return null === $category ? $this->error('category_not_found', 404) : new WP_REST_Response($this->categoryData($category), 200);
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $params = $this->params($request);
        $name = sanitize_text_field((string) ($params['name'] ?? ''));
        if ('' === $name) {
            return $this->error('category_name_required', 400);
        }
        try {
            $category = $this->categories->create($name, (string) ($params['icon'] ?? ''), (string) ($params['color'] ?? ''), (int) ($params['sort_order'] ?? 0), !isset($params['is_active']) || (bool) $params['is_active']);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->domainError($exception);
        }
        return new WP_REST_Response($this->categoryData($category), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');
        $existing = $this->categories->find($id);
        if (null === $existing) {
            return $this->error('category_not_found', 404);
        }
        $params = $this->params($request);
        $name = sanitize_text_field((string) ($params['name'] ?? $existing->name()));
        if ('' === $name) {
            return $this->error('category_name_required', 400);
        }
        try {
            $category = $this->categories->update(
                $id,
                $name,
                (string) ($params['icon'] ?? $existing->icon()),
                (string) ($params['color'] ?? $existing->color()),
                (int) ($params['sort_order'] ?? $existing->sortOrder()),
                isset($params['is_active']) ? (bool) $params['is_active'] : $existing->isActive()
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return $this->domainError($exception);
        }
        return new WP_REST_Response($this->categoryData($category), 200);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $category = $this->categories->setActive((int) $request->get_param('id'), false);
        } catch (RuntimeException $exception) {
            return $this->domainError($exception);
        }
        return new WP_REST_Response($this->categoryData($category), 200);
    }

    /** @return array<string,mixed> */
    private function params(WP_REST_Request $request): array {
        $params = $request->get_json_params();
        if (is_array($params) && [] !== $params) {
            return $params;
        }

        $params = $request->get_params();
        return is_array($params) ? $params : [];
    }

    /** @return array<string,mixed> */
    private function categoryData(Category $category): array {
        return [
            'id' => $category->id(), 'uuid' => $category->uuid(), 'name' => $category->name(), 'slug' => $category->slug(),
            'icon' => $category->icon(), 'color' => $category->color(), 'sort_order' => $category->sortOrder(), 'is_active' => $category->isActive(),
        ];
    }

    private function domainError(\Throwable $exception): WP_Error {
        return $this->error($exception->getMessage(), 'category_not_found' === $exception->getMessage() ? 404 : 400);
    }

    private function error(string $code, int $status): WP_Error {
        return new WP_Error($code, __('The RouteMaps category request could not be completed.', 'routemaps'), ['status' => $status]);
    }
}