<?php
declare(strict_types=1);

function render_ui_page(array $view): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    $databases = $view['databases'] ?? [];
    $api_keys = $view['api_keys'] ?? [];
    $ip_rules = $view['ip_rules'] ?? [];
    $client_ip = $view['client_ip'] ?? '';
    $base_url = isset($view['base_url']) ? rtrim((string) $view['base_url'], '/') : null;

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Database Explorer</title>'
        . '<link rel="stylesheet" href="' . htmlspecialchars(ui_asset_path('ui/styles/ui.css'), ENT_QUOTES, 'UTF-8') . '" media="all">'
        . '</head><body>';

    echo '<header><h1>Database Explorer</h1>'
        . '<p>Client IP: ' . htmlspecialchars($client_ip, ENT_QUOTES, 'UTF-8') . '</p></header>';

    echo '<main>';
    echo '<section class="panel panel-main">';
    echo '<div class="section-title">'
        . '<h2>Databases</h2>'
        . '<button type="button" class="icon-button" data-toggle-target="create-database-form" aria-label="Create database">+</button>'
        . '</div>';
    echo '<form method="post" class="inline-form collapsed" id="create-database-form">'
        . '<input type="hidden" name="action" value="create_database">'
        . '<input type="text" name="database" placeholder="New database name" required>'
        . '<button type="submit">Create database</button>'
        . '</form>';

    if ($databases === []) {
        echo '<p class="empty">No databases found under database/.</p>';
    }

    foreach ($databases as $database) {
        $badge = $database['exists'] ? '<span class="badge ok">sqlite</span>' : '<span class="badge ng">missing</span>';
        echo '<article style="margin-bottom:12px;">';
        $db_link = ui_asset_path('ui/db/' . rawurlencode($database['name']));
        echo '<div class="item-heading">'
            . '<h3><a href="' . htmlspecialchars($db_link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($database['name'], ENT_QUOTES, 'UTF-8') . '</a>'
            . $badge . '</h3>'
            . '<form method="post" onsubmit="return confirm(\'Delete this database and all tables?\');">'
            . '<input type="hidden" name="action" value="delete_database">'
            . '<input type="hidden" name="database" value="' . htmlspecialchars($database['name'], ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" class="danger">Delete</button>'
            . '</form>'
            . '</div>';

        if ($database['endpoints'] === []) {
            echo '<p class="empty">No endpoints in schema/.</p>';
        } else {
            echo '<ul class="list">';
            foreach ($database['endpoints'] as $endpoint) {
                $endpoint_path = '/' . API_PREFIX . '/' . $database['name'] . '/' . $endpoint;
                $endpoint_url = $base_url !== null ? $base_url . $endpoint_path : $endpoint_path;
                echo '<li>'
                    . '<div style="display:flex;flex-direction:column;gap:2px;">'
                    . '<span>' . htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') . '</span>'
                    . '<small style="font-family:monospace;word-break:break-all;">'
                    . '<a href="' . htmlspecialchars($endpoint_url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener" style="text-decoration:none;color:#1976d2;">'
                    . htmlspecialchars($endpoint_url, ENT_QUOTES, 'UTF-8')
                    . '</a>'
                    . '</small>'
                    . '</div>'
                    . '</li>';
            }
            echo '</ul>';
        }

        echo '</article>';
    }
    echo '</section>';

    echo '<section class="panel panel-side">';
    echo '<h2>API Keys</h2>';
    echo '<form method="post">'
        . '<input type="hidden" name="action" value="add_api_key">'
        . '<input type="text" name="api_key" placeholder="API key (optional)">'
        . '<input type="text" name="label" placeholder="Label">'
        . '<button type="submit">Add / Generate</button>'
        . '</form>';

    if ($api_keys === []) {
        echo '<p class="empty">No API keys registered.</p>';
    } else {
        echo '<table><thead><tr><th>Key</th><th>Label</th><th>Actions</th></tr></thead><tbody>';
        foreach ($api_keys as $key) {
            echo '<tr>'
                . '<td style="font-family:monospace;">' . htmlspecialchars($key['api_key'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) ($key['label'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="actions">'
                . '<form method="post" onsubmit="return confirm(\'Delete this API key?\');">'
                . '<input type="hidden" name="action" value="delete_api_key">'
                . '<input type="hidden" name="id" value="' . (int) $key['id'] . '">'
                . '<button type="submit">Delete</button>'
                . '</form>'
                . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</section>';

    echo '<section class="panel panel-side">';
    echo '<h2>IP Rules</h2>';
    echo '<form method="post">'
        . '<input type="hidden" name="action" value="add_ip_rule">'
        . '<input type="text" name="cidr" placeholder="CIDR e.g. 192.168.1.0/24" required>'
        . '<select name="rule_action">'
        . '<option value="allow">Allow</option>'
        . '<option value="deny">Deny</option>'
        . '</select>'
        . '<button type="submit">Add</button>'
        . '</form>';

    if ($ip_rules === []) {
        echo '<p class="empty">No IP rules configured.</p>';
    } else {
        echo '<table><thead><tr><th>CIDR / IP</th><th>Action</th><th>Actions</th></tr></thead><tbody>';
        foreach ($ip_rules as $rule) {
            $rule_id = (int) $rule['id'];
            echo '<tr>'
                . '<td>' . htmlspecialchars($rule['cidr'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars($rule['action'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="actions">'
                . '<form method="post" style="display:flex;gap:6px;">'
                . '<input type="hidden" name="action" value="update_ip_rule">'
                . '<input type="hidden" name="id" value="' . $rule_id . '">'
                . '<input type="text" name="cidr" value="' . htmlspecialchars($rule['cidr'], ENT_QUOTES, 'UTF-8') . '">'
                . '<select name="rule_action">'
                . '<option value="allow"' . ($rule['action'] === 'allow' ? ' selected' : '') . '>Allow</option>'
                . '<option value="deny"' . ($rule['action'] === 'deny' ? ' selected' : '') . '>Deny</option>'
                . '</select>'
                . '<button type="submit">Update</button>'
                . '</form>'
                . '<form method="post" onsubmit="return confirm(\'Delete this rule?\');">'
                . '<input type="hidden" name="action" value="delete_ip_rule">'
                . '<input type="hidden" name="id" value="' . $rule_id . '">'
                . '<button type="submit">Delete</button>'
                . '</form>'
                . '</td>'
                . '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</section>';

    echo '</main>'
        . '<script>'
        . 'document.querySelectorAll("[data-toggle-target]").forEach(function(button){'
        . 'button.addEventListener("click",function(){'
        . 'var target=document.getElementById(button.getAttribute("data-toggle-target"));'
        . 'if(target){target.classList.toggle("collapsed");var input=target.querySelector("input[type=text]");if(input&&!target.classList.contains("collapsed")){input.focus();}}'
        . '});'
        . '});'
        . '</script>'
        . '</body></html>';
}
