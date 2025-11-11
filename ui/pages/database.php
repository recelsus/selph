<?php
declare(strict_types=1);

function render_db_tables_page(string $db_name, array $tables, string $client_ip): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Database: ' . htmlspecialchars($db_name, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<link rel="stylesheet" href="' . htmlspecialchars(ui_asset_path('ui/styles/ui.css'), ENT_QUOTES, 'UTF-8') . '" media="all">'
        . '</head><body>';

    echo '<header><h1>Database ' . htmlspecialchars($db_name, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p>Client IP: ' . htmlspecialchars($client_ip, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="/">&larr; Back to overview</a></p></header>';

    echo '<main>';
    echo '<section class="panel panel-main">';
    echo '<h2>Tables</h2>';

    if ($tables === []) {
        echo '<p class="empty">No tables detected.</p>';
    } else {
        echo '<ul class="list">';
        foreach ($tables as $table) {
            $path = 'ui/db/' . rawurlencode($db_name) . '/' . rawurlencode($table);
            $url = ui_asset_path($path);
            echo '<li><div class="endpoint-entry">'
                . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="table-link">'
                . htmlspecialchars($table, ENT_QUOTES, 'UTF-8')
                . '</a>'
                . '</div></li>';
        }
        echo '</ul>';
    }

    echo '</section>';
    echo '</main></body></html>';
}

function render_table_rows_page(string $db_name, string $table, array $preview, string $client_ip): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    $columns = $preview['columns'] ?? [];
    $rows = $preview['rows'] ?? [];

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($db_name . ' / ' . $table, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<link rel="stylesheet" href="' . htmlspecialchars(ui_asset_path('ui/styles/ui.css'), ENT_QUOTES, 'UTF-8') . '" media="all">'
        . '</head><body>';

    echo '<header><h1>Table ' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p>Database: <a href="' . htmlspecialchars(ui_asset_path('ui/db/' . rawurlencode($db_name)), ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($db_name, ENT_QUOTES, 'UTF-8') . '</a></p>'
        . '<p>Client IP: ' . htmlspecialchars($client_ip, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="/">&larr; Back to overview</a></p></header>';

    echo '<main><section class="panel panel-main">';
    echo '<h2>Rows (first 50)</h2>';

    if ($rows === [] || $columns === []) {
        echo '<p class="empty">No rows available.</p>';
    } else {
        echo '<div class="table-scroll"><table><thead><tr>';
        foreach ($columns as $column) {
            echo '<th>' . htmlspecialchars($column, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $display = $value === null ? '<em>null</em>' : htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                echo '<td>' . $display . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '</section></main></body></html>';
}
