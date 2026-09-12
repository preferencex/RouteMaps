<?php
/**
 * Standalone RouteMaps viewer shell.
 */

defined('ABSPATH') || exit;

$bootstrap = isset($GLOBALS['routemaps_viewer_bootstrap']) && is_array($GLOBALS['routemaps_viewer_bootstrap'])
    ? $GLOBALS['routemaps_viewer_bootstrap']
    : [];
$assets = is_array($bootstrap['assets'] ?? null) ? $bootstrap['assets'] : [];
$styles = is_array($assets['styles'] ?? null) ? $assets['styles'] : [];
$script = is_string($assets['script'] ?? null) ? $assets['script'] : '';
$pwa = is_array($bootstrap['pwa'] ?? null) ? $bootstrap['pwa'] : [];
$manifestUrl = is_string($pwa['manifest_url'] ?? null) ? $pwa['manifest_url'] : '';
$json = wp_json_encode(
    $bootstrap,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <meta name="theme-color" content="#173f59">
    <?php if ('' !== $manifestUrl) : ?><link rel="manifest" href="<?php echo esc_url($manifestUrl); ?>"><?php endif; ?>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?php echo esc_html__('RouteMaps', 'routemaps'); ?></title>
    <?php foreach ($styles as $style) : if (is_string($style) && '' !== $style) : ?>
        <?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone viewer shell loads the compiled Vite asset directly. ?>
        <link rel="stylesheet" href="<?php echo esc_url($style); ?>">
    <?php endif; endforeach; ?>
</head>
<body class="routemaps-viewer-shell">
<div id="routemaps-viewer" role="application" aria-label="<?php echo esc_attr__('RouteMaps', 'routemaps'); ?>"></div>
<script id="routemaps-viewer-bootstrap" type="application/json"><?php echo $json ?: '{}'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX flags above. ?></script>
<?php if ('' !== $script) : ?>
<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone viewer shell loads the compiled ES module directly. ?>
<script type="module" src="<?php echo esc_url($script); ?>"></script>
<?php endif; ?>
</body>
</html>
