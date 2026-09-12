<?php
/**
 * RouteMaps share invitation email.
 *
 * @var string $routeTitle
 * @var string $inviteUrl
 */

defined('ABSPATH') || exit;
?>
<p><?php echo esc_html__('Recebeu um convite para aceder a um roteiro RouteMaps.', 'routemaps'); ?></p>
<p><strong><?php echo esc_html($routeTitle); ?></strong></p>
<p>
    <a href="<?php echo esc_url($inviteUrl); ?>" style="display:inline-block;padding:12px 20px;background:#00a099;color:#ffffff;text-decoration:none;border-radius:4px;">
        <?php echo esc_html__('Aceitar convite', 'routemaps'); ?>
    </a>
</p>
<p><?php echo esc_html__('O convite é pessoal e deve ser aceite com uma conta que use este endereço de e-mail.', 'routemaps'); ?></p>
