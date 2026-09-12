<?php

declare(strict_types=1);

namespace RouteMaps\Core\Commerce\Email;

use InvalidArgumentException;
use RouteMaps\Core\Domain\Licensing\License;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use RouteMaps\Core\Support\RouteUrl;
use RuntimeException;
use WC_Order;

final class RouteAccessEmail {
    public function __construct(
        private RouteRepositoryInterface $routes,
        private RouteUrl $urls
    ) {
    }

    public function send(WC_Order $order, License $license, string $plainToken): void {
        $route = $this->routes->find($license->routeId());
        if (null === $route) {
            throw new InvalidArgumentException('route_access_route_not_found');
        }

        $recipient = sanitize_email((string) $order->get_billing_email());
        if ('' === $recipient && $license->ownerUserId() > 0) {
            $user = get_userdata($license->ownerUserId());
            $recipient = $user ? sanitize_email((string) $user->user_email) : '';
        }
        if ('' === $recipient || !is_email($recipient)) {
            throw new InvalidArgumentException('route_access_email_invalid');
        }

        $accessUrl = $this->urls->access($plainToken);
        $routeTitle = $route->title();
        $message = $this->render($routeTitle, $accessUrl);
        $heading = __('O seu roteiro já está disponível', 'routemaps');

        if (function_exists('WC') && WC() && method_exists(WC(), 'mailer')) {
            $mailer = WC()->mailer();
            if (is_object($mailer) && method_exists($mailer, 'wrap_message')) {
                $message = (string) $mailer->wrap_message($heading, $message);
            }
        }

        $subject = sprintf(
            /* translators: %s: route title. */
            __('O seu roteiro "%s" já está disponível', 'routemaps'),
            wp_strip_all_tags($routeTitle)
        );

        $sent = wp_mail(
            $recipient,
            $subject,
            $message,
            ['Content-Type: text/html; charset=UTF-8']
        );

        if (!$sent) {
            throw new RuntimeException('route_access_email_send_failed');
        }
    }

    private function render(string $routeTitle, string $accessUrl): string {
        $template = dirname(__DIR__, 3) . '/templates/emails/route-access.php';
        if (!is_readable($template)) {
            throw new RuntimeException('route_access_email_template_missing');
        }

        ob_start();
        include $template;
        return (string) ob_get_clean();
    }
}
