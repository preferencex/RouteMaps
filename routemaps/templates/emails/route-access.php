<?php
/**
 * RouteMaps secure route access email.
 *
 * @var string $routeTitle
 * @var string $accessUrl
 */

defined('ABSPATH') || exit;
?>
<p><?php echo esc_html__('A sua compra foi confirmada e o roteiro já está disponível.', 'routemaps'); ?></p>
<p><strong><?php echo esc_html($routeTitle); ?></strong></p>
<p>
    <a href="<?php echo esc_url($accessUrl); ?>" style="display:inline-block;padding:12px 20px;background:#00a099;color:#ffffff;text-decoration:none;border-radius:4px;">
        <?php echo esc_html__('Abrir roteiro', 'routemaps'); ?>
    </a>
</p>
<p><?php echo esc_html__('O acesso requer autenticação na conta autorizada.', 'routemaps'); ?></p>
