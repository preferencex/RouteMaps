<?php

declare(strict_types=1);

namespace RouteMaps\Core\Viewer;

use RouteMaps\Core\Domain\Routes\Route;
use RouteMaps\Core\Domain\Versions\RouteSnapshot;
use RouteMaps\Core\Maps\MapSettings;
use RouteMaps\Core\Maps\MapSourceRegistry;
use RouteMaps\Core\Security\UrlPolicy;

final class ViewerPayloadFactory {
    private UrlPolicy $urlPolicy;

    public function __construct(
        private MapSourceRegistry $maps,
        private MapSettings $settings,
        private ExtensionRegistry $extensions,
        ?UrlPolicy $urlPolicy = null
    ) {
        $this->urlPolicy = $urlPolicy ?? new UrlPolicy();
    }

    public function build(Route $route, RouteSnapshot $snapshot, ViewerContext $context): ViewerPayload {
        $snapshotData = apply_filters('routemaps_viewer_snapshot', $snapshot->data(), $context);
        if (!is_array($snapshotData)) {
            $snapshotData = $snapshot->data();
        }

        $settings = $this->settings->load();
        $requestedProvider = isset($snapshotData['map_source_id']) && is_string($snapshotData['map_source_id'])
            ? sanitize_key($snapshotData['map_source_id'])
            : '';
        $primary = '' !== $requestedProvider ? $requestedProvider : (string) ($settings['primary_provider'] ?? 'pmtiles');
        $fallback = (string) ($settings['fallback_provider'] ?? 'openfreemap');
        $selection = $this->maps->resolve($primary, $fallback);
        $provider = $selection->provider();

        $capabilities = apply_filters('routemaps_viewer_capabilities', [
            'route_planner' => false,
            'offline_route_data' => false,
        ], $context);
        if (!is_array($capabilities)) {
            $capabilities = [];
        }

        $payload = new ViewerPayload([
            'route' => [
                'uuid' => $route->uuid(),
                'title' => is_string($snapshotData['title'] ?? null) ? $snapshotData['title'] : $route->title(),
                'cover_attachment_id' => $route->coverAttachmentId(),
                'version_id' => $context->versionId(),
            ],
            'geometry' => is_array($snapshotData['geometry'] ?? null) ? $snapshotData['geometry'] : [],
            'stops' => $this->listValue($snapshotData, 'stops'),
            'pois' => $this->safePois($this->listValue($snapshotData, 'pois')),
            'categories' => $this->listValue($snapshotData, 'categories'),
            'display' => is_array($snapshotData['display'] ?? null) ? $snapshotData['display'] : [],
            'viewport' => is_array($snapshotData['viewport'] ?? null) ? $snapshotData['viewport'] : [],
            'route_style' => is_array($snapshotData['route_style'] ?? null) ? $snapshotData['route_style'] : [],
            'support' => $this->safeSupport(is_array($snapshotData['support_overrides'] ?? null) ? $snapshotData['support_overrides'] : []),
            'map' => [
                'provider_id' => $provider?->get_id(),
                'style' => $provider?->get_style_definition(),
                'health' => [
                    'ok' => $selection->health()->ok(),
                    'code' => $selection->health()->code(),
                    'message' => $selection->health()->message(),
                ],
            ],
            'capabilities' => $capabilities,
        ]);

        return $this->extensions->decorate($payload, $context);
    }

    /** @param array<string,mixed> $source @return list<mixed> */
    private function listValue(array $source, string $key): array {
        $value = $source[$key] ?? [];
        return is_array($value) ? array_values($value) : [];
    }

    /** @param list<mixed> $pois @return list<mixed> */
    private function safePois(array $pois): array {
        return array_values(array_map(function (mixed $poi): mixed {
            if (!is_array($poi)) {
                return $poi;
            }
            $media = is_array($poi['media'] ?? null) ? $poi['media'] : [];
            $mainId = (int) ($media['main_attachment_id'] ?? 0);
            if ($mainId > 0) {
                $url = wp_get_attachment_image_url($mainId, 'large');
                if (is_string($url) && '' !== $url) {
                    $media['main_url'] = $url;
                }
            }
            $galleryUrls = [];
            foreach (is_array($media['gallery_attachment_ids'] ?? null) ? $media['gallery_attachment_ids'] : [] as $attachmentId) {
                $id = (int) $attachmentId;
                if ($id <= 0) {
                    continue;
                }
                $url = wp_get_attachment_image_url($id, 'large');
                if (is_string($url) && '' !== $url) {
                    $galleryUrls[] = $url;
                }
            }
            $media['gallery_urls'] = $galleryUrls;
            $poi['media'] = $media;

            $display = is_array($poi['display'] ?? null) ? $poi['display'] : [];
            if (isset($display['website']) && is_string($display['website'])) {
                $website = $this->urlPolicy->external($display['website']);
                if (null === $website) {
                    unset($display['website']);
                } else {
                    $display['website'] = $website;
                }
            }
            if (isset($display['cta']) && is_array($display['cta'])) {
                $ctaUrl = isset($display['cta']['url']) && is_string($display['cta']['url'])
                    ? $this->urlPolicy->external($display['cta']['url'])
                    : null;
                if (null === $ctaUrl) {
                    unset($display['cta']);
                } else {
                    $display['cta'] = [
                        'label' => sanitize_text_field((string) ($display['cta']['label'] ?? '')),
                        'url' => $ctaUrl,
                    ];
                }
            }
            $poi['display'] = $display;
            return $poi;
        }, $pois));
    }

    /** @param array<string,mixed> $support @return array<string,string> */
    private function safeSupport(array $support): array {
        $safe = [];
        if (isset($support['label']) && is_string($support['label'])) {
            $safe['label'] = sanitize_text_field($support['label']);
        }
        if (isset($support['email']) && is_string($support['email'])) {
            $email = sanitize_email($support['email']);
            if ('' !== $email) {
                $safe['email'] = $email;
            }
        }
        if (isset($support['phone']) && is_string($support['phone'])) {
            $safe['phone'] = sanitize_text_field($support['phone']);
        }
        if (isset($support['url']) && is_string($support['url'])) {
            $url = $this->urlPolicy->external($support['url']);
            if (null !== $url) {
                $safe['url'] = $url;
            }
        }
        return $safe;
    }
}
