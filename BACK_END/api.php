<?php
declare(strict_types=1);

/**
 * Ukayarn API - one endpoint, many actions:  BACK_END/api.php?action=<name>
 * Used by index.html (customers/sellers) and admin.html (admin dashboard).
 */

require __DIR__ . '/config.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

// ---------------------------------------------------------------- helpers

function out(array $data = [], int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $code = 400): never
{
    out(['error' => $message], $code);
}

function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function currentUser(): ?array
{
    if (!isset($_SESSION['uid'])) {
        return null;
    }
    return q('SELECT * FROM users WHERE id = ?', [$_SESSION['uid']])->fetch() ?: null;
}

function needUser(): array
{
    return currentUser() ?? fail('Please log in first.', 401);
}

function needAdmin(): array
{
    $user = needUser();
    return $user['role'] === 'admin' ? $user : fail('Admins only.', 403);
}

function logActivity(?array $user, string $type, string $detail = ''): void
{
    q(
        'INSERT INTO activity_log (user_id, username, type, detail) VALUES (?, ?, ?, ?)',
        [$user['id'] ?? null, $user['username'] ?? 'guest', $type, $detail]
    );
}

function ensureAdminExists(): void
{
    if (!q("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetch()) {
        q(
            "INSERT INTO users (username, email, password_hash, role) VALUES ('admin', 'admin@ukayarn.local', ?, 'admin')",
            [password_hash('admin123', PASSWORD_DEFAULT)]
        );
    }
}

// ---------------------------------------------------------------- shapes sent to the front end

function userShape(array $u): array
{
    return [
        'id'       => (int) $u['id'],
        'username' => $u['username'],
        'email'    => $u['email'],
        'role'     => $u['role'],
        'isSeller' => (bool) $u['is_seller'],
        'name'     => $u['full_name'] ?? '',
        'phone'    => $u['phone'] ?? '',
        'addr'     => $u['address'] ?? '',
        'gender'   => $u['gender'] ?? '',
        'dob'      => $u['dob'] ?? '',
        'avatar'   => $u['avatar'] ?? '',
        'bank'     => $u['bank'] ?? '',
        'vouchers' => [['code' => 'WELCOME50', 'off' => 50, 'used' => (bool) $u['welcome_used']]],
    ];
}

function itemShape(array $r): array
{
    return [
        'id'        => (int) $r['id'],
        'sellerId'  => $r['seller_id'] === null ? null : (int) $r['seller_id'],
        'seller'    => $r['seller'] ?? 'Ukayarn Store',
        'name'      => $r['name'],
        'cat'       => $r['category'],
        'price'     => (int) $r['price'],
        'qty'       => (int) $r['quantity'],
        'size'      => $r['size'],
        'widthBust' => $r['width_bust'],
        'heightLen' => $r['height_len'],
        'cond'      => $r['cond_text'],
        'desc'      => $r['description'],
        'img'       => $r['image'] ?? '',
    ];
}

function allItems(): array
{
    $rows = q(
        'SELECT i.*, u.username AS seller FROM items i LEFT JOIN users u ON u.id = i.seller_id ORDER BY i.id'
    )->fetchAll();
    return array_map('itemShape', $rows);
}

function loadOrders(string $where = '1', array $params = []): array
{
    $rows = q(
        "SELECT o.*, u.username FROM orders o JOIN users u ON u.id = o.user_id WHERE $where ORDER BY o.id DESC",
        $params
    )->fetchAll();

    return array_map(static function (array $o): array {
        $lines = q(
            'SELECT oi.item_id AS id, i.name, oi.price, oi.quantity AS qty
             FROM order_items oi JOIN items i ON i.id = oi.item_id WHERE oi.order_id = ?',
            [$o['id']]
        )->fetchAll();

        return [
            'ref'    => $o['order_no'],
            'u'      => $o['username'],
            'items'  => array_map(
                static fn(array $l): array => ['id' => (int) $l['id'], 'name' => $l['name'], 'price' => (int) $l['price'], 'qty' => (int) $l['qty']],
                $lines
            ),
            'total'  => (int) $o['total'],
            'disc'   => (int) $o['discount'],
            'pay'    => $o['pay_method'],
            'status' => $o['status'],
            'n'      => $o['ship_name'],
            'phone'  => $o['ship_phone'],
            'a'      => $o['ship_address'],
            'at'     => $o['created_at'],
        ];
    }, $rows);
}

function startSession(array $user, string $event): never
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $user['id'];
    logActivity($user, $event);
    out(['user' => userShape($user)]);
}

// ---------------------------------------------------------------- accounts

function whoAmI(array $in): never
{
    $user = currentUser();
    out(['user' => $user ? userShape($user) : null]);
}

function signup(array $in): never
{
    $username = trim((string) ($in['username'] ?? ''));
    $email    = trim((string) ($in['email'] ?? ''));
    $password = (string) ($in['password'] ?? '');

    if ($username === '' || strlen($username) > 40 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('Enter a username and a valid email.');
    }
    if (strlen($password) < 6) {
        fail('Password needs at least 6 characters.');
    }
    if (q('SELECT 1 FROM users WHERE username = ? OR email = ?', [$username, $email])->fetch()) {
        fail('That username or email is already registered.');
    }

    q(
        'INSERT INTO users (username, email, password_hash, is_seller) VALUES (?, ?, ?, ?)',
        [$username, $email, password_hash($password, PASSWORD_DEFAULT), empty($in['seller']) ? 0 : 1]
    );
    $user = q('SELECT * FROM users WHERE id = ?', [db()->lastInsertId()])->fetch();
    startSession($user, 'signup');
}

function login(array $in, bool $adminOnly = false): never
{
    $id   = trim((string) ($in['id'] ?? ''));
    $user = q('SELECT * FROM users WHERE username = ? OR email = ?', [$id, $id])->fetch();

    if (!$user || !password_verify((string) ($in['password'] ?? ''), $user['password_hash'])) {
        fail('Wrong email/username or password. Check and try again.', 401);
    }
    if ($adminOnly && $user['role'] !== 'admin') {
        fail('This account is not an admin.', 403);
    }
    startSession($user, 'login');
}

function adminLogin(array $in): never
{
    login($in, true);
}

function logout(array $in): never
{
    logActivity(currentUser(), 'logout');
    $_SESSION = [];
    session_destroy();
    out(['ok' => true]);
}

function updateProfile(array $in): never
{
    $user = needUser();
    $columns = [
        'name' => 'full_name', 'email' => 'email', 'phone' => 'phone', 'addr' => 'address',
        'gender' => 'gender', 'dob' => 'dob', 'avatar' => 'avatar', 'bank' => 'bank',
    ];

    $sets = [];
    $values = [];
    foreach ($columns as $key => $column) {
        if (!array_key_exists($key, $in)) {
            continue;
        }
        $value = trim((string) $in[$key]);
        if ($key === 'email') {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                fail('Enter a valid email.');
            }
            if (q('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$value, $user['id']])->fetch()) {
                fail('That email is already registered.');
            }
        }
        if ($key === 'gender' && !in_array($value, ['Male', 'Female', 'Other'], true)) {
            $value = '';
        }
        if ($key === 'dob' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $value = '';
        }
        if ($key === 'avatar' && strlen($value) > 1500000) {
            fail('Profile picture is too large.');
        }
        $sets[]   = "$column = ?";
        $values[] = $value === '' ? null : $value;
    }

    if ($sets) {
        $values[] = $user['id'];
        q('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $values);
    }
    out(['user' => userShape(currentUser())]);
}

function becomeSeller(array $in): never
{
    $user = needUser();
    q('UPDATE users SET is_seller = 1 WHERE id = ?', [$user['id']]);
    out(['user' => userShape(currentUser())]);
}

// ---------------------------------------------------------------- items & stock

function listItems(array $in): never
{
    out(allItems());
}

function saveUpload(): ?string
{
    $file = $_FILES['image'] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][mime_content_type($file['tmp_name'])] ?? null;
    if ($file['error'] !== UPLOAD_ERR_OK || !$ext || $file['size'] > 2 * 1024 * 1024) {
        fail('Use a JPG, PNG or WEBP image under 2 MB.');
    }
    $dir = __DIR__ . '/uploads';
    is_dir($dir) || mkdir($dir, 0775, true);
    $name = bin2hex(random_bytes(8)) . ".$ext";
    move_uploaded_file($file['tmp_name'], "$dir/$name") || fail('Could not save the image.', 500);
    return "../BACK_END/uploads/$name";   // path as seen from FRONT_END/index.html
}

function addItem(array $in): never
{
    $user = needUser();
    if (!$user['is_seller'] && $user['role'] !== 'admin') {
        fail('Become a seller first.', 403);
    }

    $name     = trim((string) ($in['name'] ?? ''));
    $category = (string) ($in['category'] ?? '');
    $price    = (int) ($in['price'] ?? 0);
    $quantity = (int) ($in['quantity'] ?? 0);

    if ($name === '' || !in_array($category, ['Tops', 'Bottoms', 'Outerwear', 'Bags'], true)) {
        fail('Enter an item name and pick a category.');
    }
    if ($price < 1 || $quantity < 1) {
        fail('Price and quantity must be at least 1.');
    }

    q(
        'INSERT INTO items (seller_id, name, category, price, quantity, size, width_bust, height_len, cond_text, description, image)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $user['id'], $name, $category, $price, $quantity,
            trim((string) ($in['size'] ?? '')), trim((string) ($in['widthBust'] ?? '')), trim((string) ($in['heightLen'] ?? '')),
            trim((string) ($in['cond'] ?? '')), trim((string) ($in['desc'] ?? '')), saveUpload(),
        ]
    );
    out(['ok' => true]);
}

function setStock(array $in): never
{
    $user     = needUser();
    $quantity = (int) ($in['quantity'] ?? -1);
    $item     = q('SELECT * FROM items WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetch();

    if (!$item) {
        fail('Item not found.', 404);
    }
    if ($user['role'] !== 'admin' && (int) $item['seller_id'] !== (int) $user['id']) {
        fail('You can only edit your own listings.', 403);
    }
    if ($quantity < 0) {
        fail('Quantity cannot be negative.');
    }
    q('UPDATE items SET quantity = ? WHERE id = ?', [$quantity, $item['id']]);
    out(['ok' => true]);
}

// ---------------------------------------------------------------- cart & orders

function addToCart(array $in): never
{
    $item = q('SELECT name FROM items WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetch();
    if ($item) {
        logActivity(currentUser(), 'add_to_cart', $item['name'] . ' x' . max(1, (int) ($in['qty'] ?? 1)));
    }
    out(['ok' => true]);
}

function placeOrder(array $in): never
{
    $user    = needUser();
    $lines   = is_array($in['items'] ?? null) ? $in['items'] : [];
    $name    = trim((string) ($in['name'] ?? ''));
    $phone   = trim((string) ($in['phone'] ?? ''));
    $address = trim((string) ($in['address'] ?? ''));
    $pay     = ($in['pay'] ?? '') === 'COD' ? 'COD' : 'GCash';

    if (!$lines || $name === '' || $phone === '' || $address === '') {
        fail('Fill in your name, mobile number, and address.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $total = 0;
        $bought = [];
        foreach ($lines as $line) {
            $qty  = max(1, (int) ($line['qty'] ?? 1));
            $item = q('SELECT * FROM items WHERE id = ? FOR UPDATE', [(int) ($line['id'] ?? 0)])->fetch();
            if (!$item || (int) $item['quantity'] < $qty) {
                throw new RuntimeException(($item['name'] ?? 'An item') . ' is out of stock or has less quantity than you asked for.');
            }
            q('UPDATE items SET quantity = quantity - ? WHERE id = ?', [$qty, $item['id']]);
            $total   += (int) $item['price'] * $qty;
            $bought[] = [$item['id'], $qty, $item['price']];
        }

        $discount = $user['welcome_used'] ? 0 : min(50, $total);
        if ($discount > 0) {
            q('UPDATE users SET welcome_used = 1 WHERE id = ?', [$user['id']]);
        }

        // Pressing "Place order" counts as paid, so revenue is recorded right away.
        $orderNo = 'UKY-' . strtoupper(bin2hex(random_bytes(3)));
        q(
            "INSERT INTO orders (order_no, user_id, total, discount, pay_method, status, ship_name, ship_phone, ship_address)
             VALUES (?, ?, ?, ?, ?, 'To Ship', ?, ?, ?)",
            [$orderNo, $user['id'], $total - $discount, $discount, $pay, $name, $phone, $address]
        );
        $orderId = $pdo->lastInsertId();
        foreach ($bought as [$itemId, $qty, $price]) {
            q('INSERT INTO order_items (order_id, item_id, quantity, price) VALUES (?, ?, ?, ?)', [$orderId, $itemId, $qty, $price]);
        }

        q(
            "UPDATE users SET full_name = COALESCE(NULLIF(full_name, ''), ?), phone = COALESCE(NULLIF(phone, ''), ?),
             address = COALESCE(NULLIF(address, ''), ?) WHERE id = ?",
            [$name, $phone, $address, $user['id']]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail($e->getMessage(), 409);
    }

    logActivity($user, 'order', "$orderNo - PHP " . ($total - $discount));
    out(['order' => loadOrders('o.order_no = ?', [$orderNo])[0]]);
}

function myOrders(array $in): never
{
    out(['orders' => loadOrders('o.user_id = ?', [needUser()['id']])]);
}

function cancelOrder(array $in): never
{
    $user  = needUser();
    $order = q('SELECT * FROM orders WHERE order_no = ?', [(string) ($in['ref'] ?? '')])->fetch();

    if (!$order || ($user['role'] !== 'admin' && (int) $order['user_id'] !== (int) $user['id'])) {
        fail('Order not found.', 404);
    }
    if ($order['status'] === 'Cancelled') {
        fail('This order is already cancelled.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    q(
        'UPDATE items i JOIN order_items oi ON oi.item_id = i.id SET i.quantity = i.quantity + oi.quantity WHERE oi.order_id = ?',
        [$order['id']]
    );
    q("UPDATE orders SET status = 'Cancelled' WHERE id = ?", [$order['id']]);
    if ((int) $order['discount'] > 0) {
        q('UPDATE users SET welcome_used = 0 WHERE id = ?', [$order['user_id']]);
    }
    $pdo->commit();

    logActivity($user, 'cancel', $order['order_no'] . ($user['role'] === 'admin' ? ' (by admin)' : ''));
    out(['ok' => true]);
}

// ---------------------------------------------------------------- admin dashboard

function dashboard(array $in): never
{
    needAdmin();
    $stats = q(
        "SELECT
            (SELECT COALESCE(SUM(total), 0) FROM orders WHERE status <> 'Cancelled') AS revenue,
            (SELECT COUNT(*) FROM orders WHERE status <> 'Cancelled') AS orders,
            (SELECT COUNT(*) FROM orders WHERE status = 'Cancelled') AS cancelled,
            (SELECT COUNT(*) FROM users WHERE role = 'customer') AS customers,
            (SELECT COUNT(*) FROM items WHERE quantity > 0) AS in_stock,
            (SELECT COUNT(*) FROM items WHERE quantity = 0) AS out_of_stock"
    )->fetch();

    out([
        'stats'    => array_map('intval', $stats),
        'activity' => q('SELECT username, type, detail, created_at FROM activity_log ORDER BY id DESC LIMIT 100')->fetchAll(),
        'orders'   => loadOrders(),
        'items'    => allItems(),
        'users'    => q(
            "SELECT u.username, u.email, u.role, u.is_seller, u.created_at,
                    (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id AND o.status <> 'Cancelled') AS orders
             FROM users u ORDER BY u.id DESC"
        )->fetchAll(),
    ]);
}

// ---------------------------------------------------------------- router

$input = json_decode(file_get_contents('php://input') ?: '[]', true) ?: $_POST;

$routes = [
    'items' => 'listItems', 'me' => 'whoAmI', 'signup' => 'signup', 'login' => 'login',
    'admin_login' => 'adminLogin', 'logout' => 'logout', 'update_profile' => 'updateProfile',
    'become_seller' => 'becomeSeller', 'add_item' => 'addItem', 'set_stock' => 'setStock',
    'add_to_cart' => 'addToCart', 'place_order' => 'placeOrder', 'my_orders' => 'myOrders',
    'cancel_order' => 'cancelOrder', 'dashboard' => 'dashboard',
];

try {
    ensureAdminExists();
    $handler = $routes[$_GET['action'] ?? ''] ?? null;
    $handler ? $handler($input) : fail('Unknown action.', 404);
} catch (PDOException $e) {
    fail('Database error: ' . $e->getMessage(), 500);
}
