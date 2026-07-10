<?php
declare(strict_types=1);

function render_db_tables_page(string $db_name, array $tables, string $client_ip, ?string $selected_table = null, ?array $preview = null): void
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

    echo '<main class="db-workspace">';
    echo '<aside class="panel tree-panel">';
    echo '<div class="section-title">'
        . '<h2>Tables</h2>'
        . '<button type="button" class="icon-button" data-toggle-target="create-table-form" aria-label="Create table">+</button>'
        . '</div>';
    $draft_table = isset($_GET['create_table']) && is_string($_GET['create_table'])
        ? trim($_GET['create_table'])
        : '';

    echo '<form method="get" class="stack-form collapsed" id="create-table-form">'
        . '<div class="form-row">'
        . '<input type="text" name="create_table" placeholder="New table name" required>'
        . '<button type="submit">Next</button>'
        . '</div>'
        . '</form>';

    if ($tables === []) {
        echo '<p class="empty">No tables detected.</p>';
    } else {
        echo '<ul class="tree-list">';
        foreach ($tables as $table) {
            $path = 'ui/db/' . rawurlencode($db_name) . '/' . rawurlencode($table);
            $url = ui_asset_path($path);
            $selected_class = $selected_table === $table ? ' selected' : '';
            echo '<li class="tree-item' . $selected_class . '">'
                . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="table-link">'
                . htmlspecialchars($table, ENT_QUOTES, 'UTF-8')
                . '</a>'
                . '<form method="post" onsubmit="return confirm(\'Delete this table?\');">'
                . '<input type="hidden" name="action" value="drop_table">'
                . '<input type="hidden" name="table" value="' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . '">'
                . '<button type="submit" class="danger">Delete</button>'
                . '</form>'
                . '</li>';
        }
        echo '</ul>';
    }

    echo '</aside>';
    echo '<section class="panel table-panel">';

    if ($draft_table !== '' && $selected_table === null) {
        render_table_create_section($draft_table);
    } elseif ($selected_table === null || $preview === null) {
        echo '<h2>Select a table</h2>'
            . '<p class="empty">Choose a table from the left tree or create a new one.</p>';
    } else {
        render_table_preview_section($db_name, $selected_table, $preview);
    }

    echo '</section>';
    echo '</main>'
        . '<script src="' . htmlspecialchars(ui_asset_path('ui/scripts/toggle.js'), ENT_QUOTES, 'UTF-8') . '"></script>'
        . '<script src="' . htmlspecialchars(ui_asset_path('ui/scripts/schema-editor.js'), ENT_QUOTES, 'UTF-8') . '"></script>'
        . '<script src="' . htmlspecialchars(ui_asset_path('ui/scripts/table-grid.js'), ENT_QUOTES, 'UTF-8') . '"></script>'
        . '</body></html>';
}

function render_table_create_section(string $table): void
{
    echo '<div class="table-heading">'
        . '<h2>Create ' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<a href="' . htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '', ENT_QUOTES, 'UTF-8') . '">Cancel</a>'
        . '</div>';

    echo '<form method="post" class="stack-form table-create-form">'
        . '<input type="hidden" name="action" value="create_table">'
        . '<input type="hidden" name="table" value="' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . '">'
        . '<div class="schema-editor" id="schema-editor">'
        . '<div class="schema-row schema-head"><strong>Column</strong><strong>Type</strong><span></span></div>'
        . '<div class="schema-row">'
        . '<input type="text" name="column_name[]" placeholder="column name" required>'
        . '<select name="column_type[]">'
        . '<option value="text">Text</option>'
        . '<option value="number">Number</option>'
        . '<option value="bool">Bool</option>'
        . '</select>'
        . '<button type="button" class="danger" data-remove-column>Remove</button>'
        . '</div>'
        . '</div>'
        . '<div class="form-row">'
        . '<button type="button" data-add-column="schema-editor">Add column</button>'
        . '<button type="submit">Create table</button>'
        . '</div>'
        . '</form>';
}

function render_table_preview_section(string $db_name, string $table, array $preview): void
{
    $columns = $preview['columns'] ?? [];
    $rows = $preview['rows'] ?? [];
    $schema = $preview['schema'] ?? [];

    echo '<div class="table-heading">'
        . '<h2>' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<a href="' . htmlspecialchars(ui_asset_path('ui/db/' . rawurlencode($db_name)), ENT_QUOTES, 'UTF-8') . '">Tables</a>'
        . '</div>';
    echo '<h3 class="subheading">Rows (first 50)</h3>';

    if ($columns === []) {
        echo '<p class="empty">No columns available.</p>';
    } else {
        echo '<div class="table-scroll"><table><thead><tr>';
        foreach ($columns as $column) {
            echo '<th>' . htmlspecialchars($column, ENT_QUOTES, 'UTF-8') . '</th>';
        }
        echo '</tr></thead><tbody>';

        $display_rows = array_merge($rows, [['__selph_rowid' => '']]);
        foreach ($display_rows as $row) {
            $rowid = (string) ($row['__selph_rowid'] ?? '');
            echo '<tr' . ($rowid === '' ? ' class="new-row"' : '') . '>';
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                echo '<td>' . render_editable_cell($table, $rowid, $column, $value, $schema[$column] ?? []) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}

function render_editable_cell(string $table, string $rowid, string $column, mixed $value, array $schema): string
{
    $display = $value === null ? '' : (string) $value;
    $escaped_value = htmlspecialchars($display, ENT_QUOTES, 'UTF-8');

    if (($schema['editable'] ?? false) !== true) {
        return $value === null ? '<em>null</em>' : $escaped_value;
    }

    $attrs = ' data-cell-input'
        . ' data-table="' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-rowid="' . htmlspecialchars($rowid, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-column="' . htmlspecialchars($column, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-original="' . $escaped_value . '"'
        . ' data-type="' . htmlspecialchars((string) ($schema['ui_type'] ?? 'text'), ENT_QUOTES, 'UTF-8') . '"';

    if (($schema['ui_type'] ?? 'text') === 'bool') {
        $selected_true = ((string) $value) === '1' ? ' selected' : '';
        $selected_false = ((string) $value) === '0' ? ' selected' : '';

        return '<select class="cell-input"' . $attrs . '>'
            . '<option value=""></option>'
            . '<option value="1"' . $selected_true . '>true</option>'
            . '<option value="0"' . $selected_false . '>false</option>'
            . '</select>';
    }

    $type = ($schema['ui_type'] ?? 'text') === 'number' ? 'number' : 'text';

    return '<input class="cell-input" type="' . $type . '" value="' . $escaped_value . '"' . $attrs . '>';
}
