<?php

declare(strict_types=1);

namespace RouteMaps\Core\Maps;

use RouteMaps\Core\Maps\Providers\PMTilesMapSourceProvider;

final class PMTilesAssetController {
    private const QUERY_VAR = 'routemaps_pmtiles_asset';

    public function __construct(private ?PMTilesMapSourceProvider $provider = null) {
        $this->provider ??= new PMTilesMapSourceProvider(new MapSettings());
    }

    public function registerHooks(): void {
        add_action('init', [self::class, 'registerRewriteRules']);
        add_filter('query_vars', [$this, 'queryVars']);
        add_action('template_redirect', [$this, 'maybeStream']);
    }

    public static function registerRewriteRules(): void {
        add_rewrite_rule('^routemaps/maps/base\.pmtiles$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
    }

    /** @param list<string> $vars @return list<string> */
    public function queryVars(array $vars): array {
        $vars[] = self::QUERY_VAR;
        return array_values(array_unique($vars));
    }

    public function maybeStream(): void {
        if ('1' !== (string) get_query_var(self::QUERY_VAR, '')) {
            return;
        }
        $rangeHeader = null;
        if (isset($_SERVER['HTTP_RANGE'])) {
            $rangeHeader = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_RANGE']));
        }
        $this->stream($rangeHeader);
        exit;
    }

    /** @return array{0:int,1:int}|null|false null=full, false=invalid */
    public function parseRange(?string $header, int $size): array|null|false {
        if (null === $header || '' === trim($header)) {
            return null;
        }
        if ($size <= 0 || str_contains($header, ',')) {
            return false;
        }
        if (1 !== preg_match('/^bytes=(\d*)-(\d*)$/i', trim($header), $match)) {
            return false;
        }
        if ('' === $match[1] && '' === $match[2]) {
            return false;
        }
        if ('' === $match[1]) {
            $suffix = (int) $match[2];
            if ($suffix <= 0) {
                return false;
            }
            return [max(0, $size - $suffix), $size - 1];
        }

        $start = (int) $match[1];
        if ($start >= $size) {
            return false;
        }
        $end = '' === $match[2] ? $size - 1 : min((int) $match[2], $size - 1);
        if ($end < $start) {
            return false;
        }
        return [$start, $end];
    }

    private function stream(?string $rangeHeader): void {
        $path = $this->provider->configuredLocalPath();
        if (null === $path) {
            status_header(404);
            nocache_headers();
            echo 'PMTiles unavailable';
            return;
        }
        $size = filesize($path);
        if (false === $size || $size <= 0) {
            status_header(404);
            echo 'PMTiles unavailable';
            return;
        }

        $range = $this->parseRange($rangeHeader, $size);
        header('Content-Type: application/vnd.pmtiles');
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');

        if (false === $range) {
            status_header(416);
            header('Content-Range: bytes */' . $size);
            header('Content-Length: 0');
            return;
        }

        $start = 0;
        $end = $size - 1;
        if (is_array($range)) {
            [$start, $end] = $range;
            status_header(206);
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        } else {
            status_header(200);
        }
        $length = $end - $start + 1;
        header('Content-Length: ' . $length);

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            status_header(500);
            return;
        }
        try {
            if (0 !== $start) {
                fseek($handle, $start);
            }
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(1024 * 1024, $remaining));
                if (false === $chunk || '' === $chunk) {
                    break;
                }
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw PMTiles binary range response.
                $remaining -= strlen($chunk);
            }
        } finally {
            fclose($handle);
        }
    }
}
