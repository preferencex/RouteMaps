<?php
/**
 * Standalone RouteMaps login template.
 */

defined('ABSPATH') || exit;

$continue = isset($_GET['continue']) ? sanitize_text_field(wp_unslash((string) $_GET['continue'])) : '';
$error = isset($_GET['error']) ? sanitize_key(wp_unslash((string) $_GET['error'])) : '';
$messages = [
    'login_csrf' => __('A sessão do formulário expirou. Tente novamente.', 'routemaps'),
    'login_invalid' => __('Preencha o utilizador e a palavra-passe.', 'routemaps'),
    'login_failed' => __('Não foi possível iniciar sessão com esses dados.', 'routemaps'),
    'login_rate_limited' => __('Foram efetuadas demasiadas tentativas. Tente novamente mais tarde.', 'routemaps'),
];
$message = $messages[$error] ?? '';
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo esc_html__('Aceder ao RouteMaps', 'routemaps'); ?></title>
    <?php wp_head(); ?>
    <style>
        html,body{margin:0;min-height:100%;background:#f4f7f8;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#173f59}
        .routemaps-login{min-height:100vh;display:grid;place-items:center;padding:24px;box-sizing:border-box}
        .routemaps-login__card{width:min(100%,400px);background:#fff;border-radius:16px;padding:32px;box-shadow:0 18px 55px rgba(23,63,89,.12)}
        .routemaps-login h1{margin:0 0 8px;font-size:28px}.routemaps-login p{color:#607581}
        .routemaps-login label{display:block;margin:18px 0 6px;font-weight:600}.routemaps-login input[type=text],.routemaps-login input[type=password]{width:100%;box-sizing:border-box;padding:12px;border:1px solid #ccd7dc;border-radius:8px;font-size:16px}
        .routemaps-login__remember{display:flex!important;gap:8px;align-items:center;font-weight:400!important}.routemaps-login button{width:100%;margin-top:20px;padding:13px;border:0;border-radius:8px;background:#00a099;color:#fff;font-size:16px;font-weight:700;cursor:pointer}.routemaps-login__error{padding:10px 12px;border-radius:8px;background:#fff0f0;color:#8d2424}
    </style>
</head>
<body>
<main class="routemaps-login">
    <section class="routemaps-login__card" aria-labelledby="routemaps-login-title">
        <h1 id="routemaps-login-title"><?php echo esc_html__('Aceda ao seu roteiro', 'routemaps'); ?></h1>
        <p><?php echo esc_html__('Entre com a conta associada ao seu acesso RouteMaps.', 'routemaps'); ?></p>
        <?php if ('' !== $message) : ?><p class="routemaps-login__error" role="alert"><?php echo esc_html($message); ?></p><?php endif; ?>
        <form method="post" action="<?php echo esc_url(home_url('/routemaps/login/')); ?>">
            <?php wp_nonce_field('routemaps_login', '_routemaps_login_nonce'); ?>
            <input type="hidden" name="continue" value="<?php echo esc_attr($continue); ?>">
            <label for="routemaps-login-user"><?php echo esc_html__('E-mail ou utilizador', 'routemaps'); ?></label>
            <input id="routemaps-login-user" name="log" type="text" autocomplete="username" required>
            <label for="routemaps-login-password"><?php echo esc_html__('Palavra-passe', 'routemaps'); ?></label>
            <input id="routemaps-login-password" name="pwd" type="password" autocomplete="current-password" required>
            <label class="routemaps-login__remember"><input name="remember" type="checkbox" value="1"> <?php echo esc_html__('Manter sessão iniciada', 'routemaps'); ?></label>
            <button type="submit"><?php echo esc_html__('Entrar', 'routemaps'); ?></button>
        </form>
    </section>
</main>
<?php wp_footer(); ?>
</body>
</html>
