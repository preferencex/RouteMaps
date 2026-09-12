<?php

declare(strict_types=1);

namespace RouteMaps\Core\Commerce\Email;

use InvalidArgumentException;
use RouteMaps\Core\Domain\Licensing\License;
use RouteMaps\Core\Domain\Routes\RouteRepositoryInterface;
use RouteMaps\Core\Domain\Sharing\LicenseUser;
use RouteMaps\Core\Support\RouteUrl;
use RuntimeException;

final class RouteShareInviteEmail {
    public function __construct(
        private RouteRepositoryInterface $routes,
        private RouteUrl $urls
    ) {
    }

    public function send(License $license, LicenseUser $member, string $plainToken): void {
        if ('guest' !== $member->role() || 'pending' !== $member->status()) {
            throw new InvalidArgumentException('share_invite_member_invalid');
        }
        $route = $this->routes->find($license->routeId());
        if (null === $route) {
            throw new InvalidArgumentException('share_invite_route_not_found');
        }
        $recipient = sanitize_email($member->email());
        if ('' === $recipient || !is_email($recipient)) {
            throw new InvalidArgumentException('share_invite_email_invalid');
        }

        $inviteUrl = $this->urls->invite($plainToken);
        $routeTitle = $route->title();
        $message = $this->render($routeTitle, $inviteUrl);
        $heading = __('Foi convidado para um roteiro RouteMaps', 'routemaps');

        if (function_exists('WC') && WC() && method_exists(WC(), 'mailer')) {
            $mailer = WC()->mailer();
            if (is_object($mailer) && method_exists($mailer, 'wrap_message')) {
                $message = (string) $mailer->wrap_message($heading, $message);
            }
        }

        $subject = sprintf(
            /* translators: %s: route title. */
            __('Convite para aceder ao roteiro "%s"', 'routemaps'),
            wp_strip_all_tags($routeTitle)
        );

        $sent = wp_mail($recipient, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
        if (!$sent) {
            throw new RuntimeException('share_invite_email_send_failed');
        }
    }

    private function render(string $routeTitle, string $inviteUrl): string {
        $template = dirname(__DIR__, 3) . '/templates/emails/share-invite.php';
        if (!is_readable($template)) {
            throw new RuntimeException('share_invite_email_template_missing');
        }
        ob_start();
        include $template;
        return (string) ob_get_clean();
    }
}
