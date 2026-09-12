<?php

declare(strict_types=1);

namespace RouteMaps\Core\Admin;

final class LicensesPage {
    private const PARENT_SLUG = 'routemaps-routes';
    private const MENU_SLUG = 'routemaps-licenses';
    private string $hookSuffix = '';

    public function registerHooks(): void {
        add_action('admin_menu', [$this, 'registerMenu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function registerMenu(): void {
        $hook = add_submenu_page(
            self::PARENT_SLUG,
            __('Licenças', 'routemaps'),
            __('Licenças', 'routemaps'),
            'manage_routemaps_licenses',
            self::MENU_SLUG,
            [$this, 'render']
        );
        if (is_string($hook)) {
            $this->hookSuffix = $hook;
        }
    }

    public function enqueue(string $hookSuffix): void {
        if ('' === $this->hookSuffix || $hookSuffix !== $this->hookSuffix) {
            return;
        }
        wp_enqueue_script('wp-api-fetch');
        wp_localize_script('wp-api-fetch', 'RouteMapsLicensesAdmin', [
            'restBase' => esc_url_raw(rest_url('routemaps/v1/admin/licenses')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
        wp_add_inline_script('wp-api-fetch', $this->script());
    }

    public function render(): void {
        if (!current_user_can('manage_routemaps_licenses')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Licenças RouteMaps', 'routemaps'); ?></h1>
            <div id="routemaps-licenses-admin">
                <form id="routemaps-license-filters" style="display:flex;gap:8px;flex-wrap:wrap;margin:16px 0;">
                    <input name="owner" type="number" min="1" placeholder="<?php echo esc_attr__('ID do titular', 'routemaps'); ?>">
                    <input name="email" type="search" placeholder="<?php echo esc_attr__('E-mail', 'routemaps'); ?>">
                    <input name="route" type="number" min="1" placeholder="<?php echo esc_attr__('ID da rota', 'routemaps'); ?>">
                    <input name="order" type="number" min="1" placeholder="<?php echo esc_attr__('ID da encomenda', 'routemaps'); ?>">
                    <select name="status">
                        <option value=""><?php echo esc_html__('Todos os estados', 'routemaps'); ?></option>
                        <?php foreach (['active', 'pending', 'suspended', 'expired', 'exhausted', 'revoked'] as $status) : ?>
                            <option value="<?php echo esc_attr($status); ?>"><?php echo esc_html(ucfirst($status)); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="validity">
                        <option value=""><?php echo esc_html__('Todas as validades', 'routemaps'); ?></option>
                        <option value="unlimited"><?php echo esc_html__('Ilimitada', 'routemaps'); ?></option>
                        <option value="days_from_purchase"><?php echo esc_html__('Dias após compra', 'routemaps'); ?></option>
                        <option value="days_from_first_use"><?php echo esc_html__('Dias após primeira utilização', 'routemaps'); ?></option>
                        <option value="fixed_range"><?php echo esc_html__('Intervalo fixo', 'routemaps'); ?></option>
                    </select>
                    <button class="button" type="submit"><?php echo esc_html__('Filtrar', 'routemaps'); ?></button>
                </form>
                <p id="routemaps-license-feedback" aria-live="polite"></p>
                <table class="widefat striped">
                    <thead><tr>
                        <th><?php echo esc_html__('Licença', 'routemaps'); ?></th>
                        <th><?php echo esc_html__('Cliente', 'routemaps'); ?></th>
                        <th><?php echo esc_html__('Rota', 'routemaps'); ?></th>
                        <th><?php echo esc_html__('Encomenda', 'routemaps'); ?></th>
                        <th><?php echo esc_html__('Estado', 'routemaps'); ?></th>
                        <th><?php echo esc_html__('Validade', 'routemaps'); ?></th>
                        <th><?php echo esc_html__('Política adquirida', 'routemaps'); ?></th>
                        <th><?php echo esc_html__('Aberturas', 'routemaps'); ?></th>
                        <th><?php echo esc_html__('Ações', 'routemaps'); ?></th>
                    </tr></thead>
                    <tbody id="routemaps-license-rows"></tbody>
                </table>
            </div>
        </div>
        <?php
    }

    private function script(): string {
        return <<<'JS'
(() => {
    const config = window.RouteMapsLicensesAdmin;
    const form = document.getElementById('routemaps-license-filters');
    const rows = document.getElementById('routemaps-license-rows');
    const feedback = document.getElementById('routemaps-license-feedback');
    if (!config || !form || !rows || !feedback) return;

    const request = async (url, options = {}) => {
        const response = await fetch(url, {
            ...options,
            headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce, ...(options.headers || {})}
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || 'Erro RouteMaps');
        return data;
    };

    const cell = (value) => {
        const td = document.createElement('td');
        td.textContent = value == null ? '—' : String(value);
        return td;
    };

    const actionButton = (license, action, label) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button button-small';
        button.textContent = label;
        button.addEventListener('click', async () => {
            button.disabled = true;
            try {
                await request(`${config.restBase}/${license.id}/${action}`, {method: 'POST'});
                await load();
            } catch (error) {
                feedback.textContent = error.message;
            } finally {
                button.disabled = false;
            }
        });
        return button;
    };

    const render = (data) => {
        rows.replaceChildren();
        data.items.forEach((license) => {
            const tr = document.createElement('tr');
            tr.append(cell(`#${license.id}`));
            tr.append(cell(license.owner_email || `#${license.owner_user_id}`));
            tr.append(cell(`#${license.route_id}`));
            tr.append(cell(`#${license.order_id}`));
            tr.append(cell(license.status));
            tr.append(cell(license.valid_until || license.validity_mode));
            const days = license.validity_days == null ? '—' : `${license.validity_days}d`;
            const shares = license.sharing_enabled ? `${license.max_shares} partilha(s)` : 'sem partilhas';
            tr.append(cell(`${license.validity_mode} · ${days} · ${shares}`));
            tr.append(cell(`${license.openings_used}/${license.max_openings ?? '∞'}`));
            const actions = document.createElement('td');
            actions.style.display = 'flex';
            actions.style.gap = '4px';
            if (license.stored_status === 'active' && license.status === 'active') actions.append(actionButton(license, 'suspend', 'Suspender'));
            if (license.stored_status === 'suspended') actions.append(actionButton(license, 'reactivate', 'Reativar'));
            if (license.stored_status !== 'revoked') actions.append(actionButton(license, 'revoke', 'Revogar'));
            tr.append(actions);
            rows.append(tr);
        });
        feedback.textContent = `${data.total} licença(s)`;
    };

    const load = async () => {
        feedback.textContent = 'A carregar…';
        const query = new URLSearchParams(new FormData(form));
        for (const [key, value] of [...query]) if (!value) query.delete(key);
        try { render(await request(`${config.restBase}?${query.toString()}`)); }
        catch (error) { feedback.textContent = error.message; }
    };

    form.addEventListener('submit', (event) => { event.preventDefault(); load(); });
    load();
})();
JS;
    }
}
