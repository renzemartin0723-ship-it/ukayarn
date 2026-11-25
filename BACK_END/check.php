<?php
declare(strict_types=1);

/** Connection check. Open: http://localhost:8000/BACK_END/check.php */

require __DIR__ . '/config.php';

function row(bool $pass, string $title, string $fix = ''): bool
{
    $color = $pass ? 'green' : 'crimson';
    echo "<p style=\"font:16px system-ui;margin:12px 0\"><b style=\"color:$color\">" . ($pass ? 'PASS' : 'FAIL') . '</b> ' . htmlspecialchars($title);
    if (!$pass && $fix !== '') {
        echo '<br><span style="color:#444">FIX: ' . htmlspecialchars($fix) . '</span>';
    }
    echo '</p>';
    return $pass;
}

echo '<h2 style="font-family:system-ui">Ukayarn connection check</h2>';
row(true, 'PHP is running (version ' . PHP_VERSION . ')');

if (!row(
    extension_loaded('pdo_mysql'),
    'PHP MySQL driver (pdo_mysql) is loaded',
    'Start the server with XAMPP PHP: double-click start.bat. php.ini in use: ' . (php_ini_loaded_file() ?: 'none')
)) {
    exit;
}

try {
    $server = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    row(true, 'Connected to MySQL');
} catch (PDOException $e) {
    row(
        false,
        'Could not connect to MySQL: ' . $e->getMessage(),
        'In XAMPP click Start next to MySQL (it must be green). If it says "Access denied", put your MySQL password in config.php (DB_PASS). If MySQL shows another port in XAMPP (like 3307), set DB_PORT in config.php.'
    );
    exit;
}

$hasDatabase = (bool) $server->query("SHOW DATABASES LIKE '" . DB_NAME . "'")->fetch();
if (!row($hasDatabase, 'Database "' . DB_NAME . '" exists', 'In phpMyAdmin click Import and import DATABASE/ukayarn.sql (it creates the database).')) {
    exit;
}

$db = db();
$missing = array_filter(
    ['users', 'items', 'orders', 'order_items', 'activity_log'],
    static fn(string $t): bool => !$db->query("SHOW TABLES LIKE '$t'")->fetch()
);
if (!row(!$missing, 'All 5 tables exist', 'Missing: ' . implode(', ', $missing) . '. Import DATABASE/ukayarn.sql again in phpMyAdmin.')) {
    exit;
}

$count = (int) $db->query('SELECT COUNT(*) FROM items')->fetchColumn();
if (row($count > 0, "Items in the shop: $count", 'The items table is empty. Import DATABASE/ukayarn.sql again.')) {
    echo '<h3 style="font-family:system-ui;color:green">Everything is connected. Open the website and press Ctrl+F5.</h3>';
}
