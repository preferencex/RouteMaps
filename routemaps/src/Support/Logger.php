<?php

declare(strict_types=1);

namespace RouteMaps\Core\Support;

use Closure;
use Throwable;

final class Logger {
    public const DEBUG_OPTION = 'routemaps_debug_logging';
    public const RECENT_ERRORS_OPTION = 'routemaps_recent_errors';
    private const MAX_RECENT_ERRORS = 20;

    private ?object $wooLogger;
    private ?Closure $fallbackWriter;

    public function __construct(?object $wooLogger = null, ?callable $fallbackWriter = null) {
        $this->wooLogger = $wooLogger;
        $this->fallbackWriter = null !== $fallbackWriter ? Closure::fromCallable($fallbackWriter) : null;
    }

    /** @param array<string,mixed> $context */
    public function info(string $event, array $context = []): void {
        $this->log('info', $event, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $event, array $context = []): void {
        $this->log('error', $event, $context);
    }

    /** @return list<array<string,mixed>> */
    public function recentErrors(): array {
        if (!function_exists('get_option')) {
            return [];
        }
        $entries = get_option(self::RECENT_ERRORS_OPTION, []);
        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    /** @param array<string,mixed> $context */
    private function log(string $level, string $event, array $context): void {
        $event = $this->sanitizeEvent($event);
        $clean = $this->sanitizeContext($context);
        $clean['source'] = 'routemaps';

        $logger = $this->resolveWooLogger();
        if (null !== $logger && method_exists($logger, $level)) {
            $logger->{$level}($event, $clean);
        } elseif ('error' === $level && $this->debugLoggingEnabled()) {
            $writer = $this->fallbackWriter ?? static fn (string $message): bool => error_log($message);
            $encoded = function_exists('wp_json_encode')
                ? wp_json_encode(['event' => $event, 'context' => $clean], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : json_encode(['event' => $event, 'context' => $clean], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $writer('[RouteMaps] ' . (is_string($encoded) ? $encoded : $event));
        }

        if ('error' === $level) {
            $this->rememberError($event, $clean);
        }
    }

    private function resolveWooLogger(): ?object {
        if (null !== $this->wooLogger) {
            return $this->wooLogger;
        }
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            if (is_object($logger)) {
                $this->wooLogger = $logger;
                return $logger;
            }
        }
        return null;
    }

    private function debugLoggingEnabled(): bool {
        if (!function_exists('get_option')) {
            return false;
        }
        $enabled = (bool) get_option(self::DEBUG_OPTION, false);
        return function_exists('apply_filters') ? (bool) apply_filters('routemaps_debug_logging', $enabled) : $enabled;
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    private function sanitizeContext(array $context): array {
        $clean = [];
        foreach ($context as $key => $value) {
            $keyString = (string) $key;
            if ($this->isSensitiveKey($keyString)) {
                $clean[$keyString] = '[redacted]';
                continue;
            }
            $clean[$keyString] = $this->sanitizeValue($value);
        }
        return $clean;
    }

    private function isSensitiveKey(string $key): bool {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        return 1 === preg_match('/(?:token|password|authorization|secret|api_?key|email)/', $normalized);
    }

    private function sanitizeValue(mixed $value): mixed {
        if (is_array($value)) {
            /** @var array<string,mixed> $value */
            return $this->sanitizeContext($value);
        }
        if (is_string($value)) {
            return $this->scrubString($value);
        }
        if ($value instanceof Throwable) {
            return [
                'type' => get_class($value),
                'message' => $this->scrubString($value->getMessage()),
                'code' => $value->getCode(),
            ];
        }
        if (is_object($value) || is_resource($value)) {
            return '[redacted]';
        }
        return $value;
    }

    private function scrubString(string $value): string {
        $value = (string) preg_replace(
            '#(/routemaps/(?:access|invite)/)[a-f0-9]{32,128}#i',
            '$1[redacted]',
            $value
        );
        $value = (string) preg_replace(
            '/([?&](?:token|public_token|invite_token|authorization|key)=)[^&\s]+/i',
            '$1[redacted]',
            $value
        );
        $value = (string) preg_replace('/\bBearer\s+[^\s,;]+/i', 'Bearer [redacted]', $value);
        $value = (string) preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[redacted]', $value);
        return $value;
    }

    private function sanitizeEvent(string $event): string {
        $event = trim($event);
        if (function_exists('sanitize_key')) {
            $clean = sanitize_key($event);
            return '' !== $clean ? $clean : 'routemaps_event';
        }
        $clean = strtolower((string) preg_replace('/[^a-z0-9_\-]+/i', '_', $event));
        return '' !== trim($clean, '_') ? trim($clean, '_') : 'routemaps_event';
    }

    /** @param array<string,mixed> $context */
    private function rememberError(string $event, array $context): void {
        if (!function_exists('get_option') || !function_exists('update_option')) {
            return;
        }
        $entries = $this->recentErrors();
        array_unshift($entries, [
            'event' => $event,
            'context' => $context,
            'created_at' => gmdate('c'),
        ]);
        $entries = array_slice($entries, 0, self::MAX_RECENT_ERRORS);
        update_option(self::RECENT_ERRORS_OPTION, $entries, false);
    }
}
