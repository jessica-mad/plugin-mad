<?php
/**
 * Multi-Catalog Sync - Simple Log Viewer
 *
 * Access via: wp-content/plugins/plugin-mad/modules/multi-catalog-sync/view-logs.php
 */

// Load WordPress
require_once('../../../../../../wp-load.php');

// Security check - must be logged in as admin
if (!is_user_logged_in() || !current_user_can('manage_options')) {
    wp_die('Access denied. You must be an administrator to view logs.');
}

// Load logger
require_once __DIR__ . '/includes/Core/Logger.php';
$logger = new \MAD_Suite\MultiCatalogSync\Core\Logger();

// Get log files
$log_files = $logger->get_log_files();

// Get selected log file
$selected_log = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : null;

// Get number of lines
$lines = isset($_GET['lines']) ? absint($_GET['lines']) : 200;
$lines = max(50, min(5000, $lines)); // Between 50 and 5000

// Read log content
$log_content = $selected_log ? $logger->read_log($selected_log, $lines) : $logger->read_log(null, $lines);

// Auto-refresh
$auto_refresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Multi-Catalog Sync - Logs</title>
    <?php if ($auto_refresh): ?>
    <meta http-equiv="refresh" content="5">
    <?php endif; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        h1 {
            color: #4ec9b0;
            margin-bottom: 20px;
            font-size: 24px;
        }
        .controls {
            background: #252526;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        .controls label {
            color: #cccccc;
            margin-right: 5px;
        }
        .controls select,
        .controls input[type="number"] {
            background: #3c3c3c;
            color: #cccccc;
            border: 1px solid #555;
            padding: 5px 10px;
            border-radius: 3px;
        }
        .controls button,
        .controls a {
            background: #0e639c;
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 3px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .controls button:hover,
        .controls a:hover {
            background: #1177bb;
        }
        .stats {
            background: #252526;
            padding: 10px 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            color: #858585;
            font-size: 12px;
        }
        .log-viewer {
            background: #1e1e1e;
            border: 1px solid #3c3c3c;
            border-radius: 4px;
            padding: 15px;
            overflow-x: auto;
        }
        .log-line {
            line-height: 1.6;
            padding: 2px 0;
            border-bottom: 1px solid #2d2d2d;
        }
        .log-line:last-child {
            border-bottom: none;
        }
        .log-timestamp {
            color: #858585;
        }
        .log-level {
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 2px;
            display: inline-block;
            min-width: 60px;
            text-align: center;
        }
        .log-level.INFO {
            color: #4ec9b0;
            background: rgba(78, 201, 176, 0.1);
        }
        .log-level.WARNING {
            color: #dcdcaa;
            background: rgba(220, 220, 170, 0.1);
        }
        .log-level.ERROR {
            color: #f48771;
            background: rgba(244, 135, 113, 0.1);
        }
        .log-level.DEBUG {
            color: #569cd6;
            background: rgba(86, 156, 214, 0.1);
        }
        .log-message {
            color: #d4d4d4;
        }
        .empty-log {
            text-align: center;
            padding: 50px;
            color: #858585;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            margin-left: 10px;
        }
        .badge.active {
            background: #16825d;
            color: white;
        }
        .badge.inactive {
            background: #3c3c3c;
            color: #858585;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>📊 Multi-Catalog Sync - Log Viewer</h1>

    <div class="controls">
        <div>
            <label>Log File:</label>
            <select id="logFile" onchange="changeLog()">
                <?php foreach ($log_files as $file): ?>
                    <option value="<?php echo esc_attr($file['name']); ?>" <?php selected($selected_log, $file['name']); ?>>
                        <?php
                        echo esc_html($file['name']);
                        echo ' (' . size_format($file['size']) . ')';
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label>Lines:</label>
            <input type="number" id="logLines" value="<?php echo esc_attr($lines); ?>" min="50" max="5000" step="50" style="width: 80px;">
        </div>

        <button onclick="refreshLog()">🔄 Refresh</button>

        <div>
            <label>
                <input type="checkbox" id="autoRefresh" <?php checked($auto_refresh); ?> onchange="toggleAutoRefresh()">
                Auto-refresh (5s)
            </label>
            <?php if ($auto_refresh): ?>
            <span class="badge active">ON</span>
            <?php else: ?>
            <span class="badge inactive">OFF</span>
            <?php endif; ?>
        </div>

        <a href="<?php echo admin_url('admin.php?page=mad-multi-catalog-sync'); ?>">← Back to Dashboard</a>
    </div>

    <div class="stats">
        📁 Log Directory: <code><?php echo esc_html($logger->get_simple_log_path()); ?></code> |
        📊 Showing last <?php echo esc_html($lines); ?> lines |
        🕒 Last updated: <?php echo date('Y-m-d H:i:s'); ?>
    </div>

    <div class="log-viewer">
        <?php if (empty($log_content)): ?>
            <div class="empty-log">
                No log entries yet. Logs will appear here after the first synchronization.
            </div>
        <?php else: ?>
            <?php
            $log_lines = explode("\n", $log_content);
            foreach ($log_lines as $line) {
                if (empty(trim($line))) continue;

                // Parse log line: [timestamp] [level] message
                if (preg_match('/^\[([^\]]+)\]\s+\[([^\]]+)\]\s+(.+)$/', $line, $matches)) {
                    $timestamp = $matches[1];
                    $level = $matches[2];
                    $message = $matches[3];

                    echo '<div class="log-line">';
                    echo '<span class="log-timestamp">[' . esc_html($timestamp) . ']</span> ';
                    echo '<span class="log-level ' . esc_attr($level) . '">' . esc_html($level) . '</span> ';
                    echo '<span class="log-message">' . esc_html($message) . '</span>';
                    echo '</div>';
                } else {
                    // Fallback for non-standard lines
                    echo '<div class="log-line">';
                    echo '<span class="log-message">' . esc_html($line) . '</span>';
                    echo '</div>';
                }
            }
            ?>
        <?php endif; ?>
    </div>
</div>

<script>
function changeLog() {
    const file = document.getElementById('logFile').value;
    const lines = document.getElementById('logLines').value;
    const refresh = document.getElementById('autoRefresh').checked ? '1' : '0';
    window.location.href = '?file=' + file + '&lines=' + lines + '&refresh=' + refresh;
}

function refreshLog() {
    window.location.reload();
}

function toggleAutoRefresh() {
    const file = document.getElementById('logFile').value;
    const lines = document.getElementById('logLines').value;
    const refresh = document.getElementById('autoRefresh').checked ? '1' : '0';
    window.location.href = '?file=' + file + '&lines=' + lines + '&refresh=' + refresh;
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // R key - refresh
    if (e.key === 'r' || e.key === 'R') {
        if (!e.ctrlKey && !e.metaKey) {
            e.preventDefault();
            refreshLog();
        }
    }
});

// Auto-scroll to bottom on load
window.addEventListener('load', function() {
    window.scrollTo(0, document.body.scrollHeight);
});
</script>
</body>
</html>
