<?php
declare(strict_types=1);

require_once __DIR__ . '/db_pesanan.php';
require_once __DIR__ . '/common.php';

try {
    $db = connectToAppDatabase();
} catch (PDOException $exception) {
    redirectTo('setup.php');
}
$statuses = ['baru', 'diproses', 'selesai'];
$platforms = ['Website', 'WhatsApp', 'Instagram', 'TikTok Shop', 'Shopee', 'Tokopedia', 'Lainnya'];
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $statement = $db->prepare('SELECT id, username, password_hash FROM admins WHERE username = :username');
        $statement->execute(['username' => $username]);
        $admin = $statement->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            redirectTo('index.php');
        }
        $loginError = 'Username atau password tidak sesuai.';
    } elseif ($action === 'logout') {
        if (!empty($_SESSION['admin_id'])) {
            unset($_SESSION['admin_id'], $_SESSION['admin_username']);
            session_regenerate_id(true);
        }
        redirectTo('index.php');
    } elseif (empty($_SESSION['admin_id'])) {
        redirectTo('index.php');
    } else {
        try {
            if ($action === 'save_order') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $customer = trim((string) ($_POST['customer_name'] ?? ''));
                $product = trim((string) ($_POST['product_name'] ?? ''));
                $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
                $platform = trim((string) ($_POST['platform'] ?? ''));
                $orderDate = (string) ($_POST['order_date'] ?? '');
                $status = (string) ($_POST['status'] ?? 'baru');
                $date = DateTime::createFromFormat('Y-m-d', $orderDate);

                if (($id !== false && $id < 1) || $customer === '' || strlen($customer) > 150) {
                    throw new RuntimeException('Nama pelanggan wajib diisi (maksimal 150 karakter).');
                }
                if ($product === '' || strlen($product) > 150) {
                    throw new RuntimeException('Nama produk wajib diisi (maksimal 150 karakter).');
                }
                if ($quantity === false || $quantity < 1) {
                    throw new RuntimeException('Jumlah pesanan harus berupa angka minimal 1 pcs.');
                }
                if ($platform === '' || $platform === '__custom' || strlen($platform) > 80) {
                    throw new RuntimeException('Platform asal wajib dipilih atau diisi (maksimal 80 karakter).');
                }
                if (!$date || $date->format('Y-m-d') !== $orderDate) {
                    throw new RuntimeException('Tanggal pesanan tidak valid.');
                }
                if (!in_array($status, $statuses, true)) {
                    throw new RuntimeException('Status pesanan tidak valid.');
                }

                $db->beginTransaction();
                try {
                    $capacityQuery = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'capacity_max' FOR UPDATE");
                    $capacityQuery->execute();
                    $capacity = $capacityQuery->fetchColumn();
                    if ($capacity === false) {
                        throw new RuntimeException('Kapasitas belum disiapkan. Jalankan setup.php terlebih dahulu.');
                    }

                    if ($status === 'diproses') {
                        $usageSql = "SELECT COALESCE(SUM(quantity), 0) FROM orders WHERE status = 'diproses'";
                        if ($id !== false) {
                            $usageSql .= ' AND id <> :id';
                            $usageQuery = $db->prepare($usageSql);
                            $usageQuery->execute(['id' => $id]);
                        } else {
                            $usageQuery = $db->query($usageSql);
                        }
                        $used = (int) $usageQuery->fetchColumn();
                        $available = max(0, (int) $capacity - $used);
                        if ($quantity > $available) {
                            throw new RuntimeException(
                                'Kapasitas tidak mencukupi. Sisa kapasitas ' . $available . ' pcs, sedangkan pesanan ini ' . $quantity . ' pcs.'
                            );
                        }
                    }

                    $values = [
                        'customer_name' => $customer,
                        'product_name' => $product,
                        'quantity' => $quantity,
                        'platform' => $platform,
                        'order_date' => $orderDate,
                        'status' => $status,
                    ];

                    if ($id !== false) {
                        $values['id'] = $id;
                        $save = $db->prepare(
                            'UPDATE orders SET customer_name = :customer_name, product_name = :product_name,
                             quantity = :quantity, platform = :platform, order_date = :order_date, status = :status
                             WHERE id = :id'
                        );
                        $save->execute($values);
                        if ($save->rowCount() === 0) {
                            $exists = $db->prepare('SELECT COUNT(*) FROM orders WHERE id = :id');
                            $exists->execute(['id' => $id]);
                            if ((int) $exists->fetchColumn() === 0) {
                                throw new RuntimeException('Pesanan tidak ditemukan.');
                            }
                        }
                        $message = 'Perubahan pesanan berhasil disimpan.';
                    } else {
                        $orderNumber = 'PSN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
                        $save = $db->prepare(
                            'INSERT INTO orders (order_number, customer_name, product_name, quantity, platform, order_date, status)
                             VALUES (:order_number, :customer_name, :product_name, :quantity, :platform, :order_date, :status)'
                        );
                        $save->execute(['order_number' => $orderNumber] + $values);
                        $message = 'Pesanan ' . $orderNumber . ' berhasil ditambahkan.';
                    }

                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $exception;
                }
                setFlash('success', $message);
            } elseif ($action === 'delete_order') {
                $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                if ($id === false || $id < 1) {
                    throw new RuntimeException('Pesanan yang akan dihapus tidak valid.');
                }
                $delete = $db->prepare('DELETE FROM orders WHERE id = :id');
                $delete->execute(['id' => $id]);
                if ($delete->rowCount() < 1) {
                    throw new RuntimeException('Pesanan tidak ditemukan atau sudah dihapus.');
                }
                setFlash('success', 'Pesanan berhasil dihapus.');
            } elseif ($action === 'save_capacity') {
                $capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT);
                if ($capacity === false || $capacity < 1) {
                    throw new RuntimeException('Kapasitas maksimal harus berupa angka minimal 1.');
                }

                $db->beginTransaction();
                try {
                    $lock = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'capacity_max' FOR UPDATE");
                    $lock->execute();
                    $currentCapacity = $lock->fetchColumn();
                    if ($currentCapacity === false) {
                        throw new RuntimeException('Pengaturan kapasitas belum tersedia. Jalankan setup.php terlebih dahulu.');
                    }
                    $used = (int) $db->query("SELECT COALESCE(SUM(quantity), 0) FROM orders WHERE status = 'diproses'")->fetchColumn();
                    if ($capacity < $used) {
                        throw new RuntimeException('Kapasitas tidak boleh lebih kecil dari jumlah yang sedang diproses (' . $used . ' pcs).');
                    }
                    $update = $db->prepare("UPDATE settings SET setting_value = :capacity WHERE setting_key = 'capacity_max'");
                    $update->execute(['capacity' => $capacity]);
                    $db->commit();
                } catch (Throwable $exception) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $exception;
                }
                setFlash('success', 'Kapasitas maksimal berhasil diperbarui.');
            } else {
                throw new RuntimeException('Aksi tidak dikenal.');
            }
        } catch (Throwable $exception) {
            setFlash('error', $exception->getMessage());
        }
        redirectTo('index.php');
    }
}

$isLoggedIn = !empty($_SESSION['admin_id']);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (!$isLoggedIn):
    $setupRequired = false;
    try {
        $setupRequired = (int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0;
    } catch (PDOException $exception) {
        $setupRequired = true;
    }
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Admin | PesananKita</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
    <main class="auth-card">
        <a class="brand brand-center" href="index.php"><span class="brand-mark">P</span> PesananKita</a>
        <p class="eyebrow">RUANG ADMIN</p>
        <h1>Selamat datang kembali</h1>
        <p class="muted">Masuk untuk mengelola pesanan dan kapasitas produksi.</p>
        <?php if ($setupRequired): ?>
            <div class="alert alert-info">Aplikasi belum disiapkan. Buka halaman setup untuk membuat database dan akun admin.</div>
            <a class="button button-primary button-full" href="setup.php">Siapkan aplikasi</a>
        <?php else: ?>
            <?php if ($loginError !== ''): ?>
                <div class="alert alert-error"><?= escapeHtml($loginError) ?></div>
            <?php endif; ?>
            <form method="post" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <input type="hidden" name="action" value="login">
                <label>Username
                    <input type="text" name="username" autocomplete="username" required autofocus>
                </label>
                <label>Password
                    <input type="password" name="password" autocomplete="current-password" required>
                </label>
                <button class="button button-primary button-full" type="submit">Masuk ke dashboard</button>
            </form>
        <?php endif; ?>
        <p class="auth-footnote">Sistem manajemen pesanan &amp; produksi</p>
    </main>
</body>
</html>
<?php
    exit;
endif;

$stats = ['total' => 0, 'baru' => 0, 'diproses' => 0, 'selesai' => 0, 'used' => 0, 'capacity' => 0];
$stats['total'] = (int) $db->query('SELECT COUNT(*) FROM orders')->fetchColumn();
foreach ($statuses as $status) {
    $query = $db->prepare('SELECT COUNT(*) FROM orders WHERE status = :status');
    $query->execute(['status' => $status]);
    $stats[$status] = (int) $query->fetchColumn();
}
$stats['used'] = (int) $db->query("SELECT COALESCE(SUM(quantity), 0) FROM orders WHERE status = 'diproses'")->fetchColumn();
$capacityQuery = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'capacity_max'");
$stats['capacity'] = (int) ($capacityQuery->fetchColumn() ?: 0);
$stats['remaining'] = max(0, $stats['capacity'] - $stats['used']);
$usagePercent = $stats['capacity'] > 0 ? (int) round(($stats['used'] / $stats['capacity']) * 100) : 0;
$capacityState = $stats['remaining'] === 0 ? 'Penuh' : ($usagePercent >= 80 ? 'Hampir penuh' : 'Aman');
$capacityTone = $stats['remaining'] === 0 ? 'danger' : ($usagePercent >= 80 ? 'warning' : 'success');

$filter = (string) ($_GET['status'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$where = [];
$params = [];
if (in_array($filter, $statuses, true)) {
    $where[] = 'status = :status';
    $params['status'] = $filter;
}
if ($search !== '') {
    $where[] = '(order_number LIKE :search_order OR customer_name LIKE :search_customer OR product_name LIKE :search_product)';
    $searchTerm = '%' . $search . '%';
    $params['search_order'] = $searchTerm;
    $params['search_customer'] = $searchTerm;
    $params['search_product'] = $searchTerm;
}
$sql = 'SELECT * FROM orders' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY order_date DESC, id DESC';
$ordersQuery = $db->prepare($sql);
$ordersQuery->execute($params);
$orders = $ordersQuery->fetchAll();

$editOrder = null;
if (isset($_GET['edit'])) {
    $editId = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($editId !== false && $editId > 0) {
        $editQuery = $db->prepare('SELECT * FROM orders WHERE id = :id');
        $editQuery->execute(['id' => $editId]);
        $editOrder = $editQuery->fetch() ?: null;
    }
}
$detailOrder = null;
if (isset($_GET['detail'])) {
    $detailId = filter_var($_GET['detail'], FILTER_VALIDATE_INT);
    if ($detailId !== false && $detailId > 0) {
        $detailQuery = $db->prepare('SELECT * FROM orders WHERE id = :id');
        $detailQuery->execute(['id' => $detailId]);
        $detailOrder = $detailQuery->fetch() ?: null;
    }
}
$formOrder = $editOrder ?: [
    'id' => '',
    'order_number' => '',
    'customer_name' => '',
    'product_name' => '',
    'quantity' => '',
    'platform' => '',
    'order_date' => date('Y-m-d'),
    'status' => 'baru',
];
$platformIsCustom = $formOrder['platform'] !== '' && !in_array($formOrder['platform'], $platforms, true);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | PesananKita</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="app-page">
    <aside class="sidebar">
        <a class="brand" href="index.php"><span class="brand-mark">P</span><span>PesananKita</span></a>
        <div class="side-label">MENU UTAMA</div>
        <a class="side-link active" href="index.php"><span class="side-icon">▦</span> Dashboard</a>
        <a class="side-link" href="#pesanan"><span class="side-icon">▤</span> Data pesanan <span class="side-count"><?= $stats['total'] ?></span></a>
        <a class="side-link" href="#kapasitas"><span class="side-icon">◷</span> Kapasitas</a>
        <div class="sidebar-bottom">
            <div class="admin-mini"><span class="avatar"><?= escapeHtml(strtoupper(substr((string) $_SESSION['admin_username'], 0, 1))) ?></span><span><strong><?= escapeHtml((string) $_SESSION['admin_username']) ?></strong><small>Administrator</small></span></div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="logout-link" type="submit">↪ Keluar</button>
            </form>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="mobile-brand"><span class="brand-mark">P</span> PesananKita</div>
            <div><span class="breadcrumb">Manajemen /</span> <strong>Dashboard</strong></div>
            <div class="topbar-right"><span class="online-dot"></span> Sistem aktif <span class="topbar-date"><?= date('d M Y') ?></span></div>
        </header>

        <div class="page-content">
            <?php if ($flash): ?>
                <div class="alert alert-<?= escapeHtml($flash['type']) ?>" role="status"><?= escapeHtml($flash['message']) ?></div>
            <?php endif; ?>

            <section class="welcome-row">
                <div>
                    <p class="eyebrow">RINGKASAN OPERASIONAL</p>
                    <h1>Dashboard</h1>
                    <p class="muted">Pantau pesanan dan kapasitas produksi Anda hari ini.</p>
                </div>
                <a class="button button-primary" href="#form-pesanan">＋ Tambah pesanan</a>
            </section>

            <section class="stat-grid" aria-label="Ringkasan pesanan">
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-icon icon-blue">▤</span><span class="stat-tag">SEMUA WAKTU</span></div>
                    <div class="stat-value"><?= number_format($stats['total']) ?></div><div class="stat-label">Total pesanan</div>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-icon icon-purple">✦</span><span class="stat-tag">PERLU DITINJAU</span></div>
                    <div class="stat-value"><?= number_format($stats['baru']) ?></div><div class="stat-label">Pesanan baru</div>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-icon icon-orange">◷</span><span class="stat-tag">DALAM PRODUKSI</span></div>
                    <div class="stat-value"><?= number_format($stats['diproses']) ?></div><div class="stat-label">Pesanan diproses</div>
                </article>
                <article class="stat-card">
                    <div class="stat-top"><span class="stat-icon icon-green">✓</span><span class="stat-tag">TELAH DISELESAIKAN</span></div>
                    <div class="stat-value"><?= number_format($stats['selesai']) ?></div><div class="stat-label">Pesanan selesai</div>
                </article>
            </section>

            <section class="capacity-card" id="kapasitas">
                <div class="capacity-heading">
                    <div><p class="eyebrow">KONTROL PRODUKSI</p><h2>Kapasitas produksi</h2><p class="muted">Hanya jumlah pesanan berstatus <strong>Diproses</strong> yang menggunakan kapasitas.</p></div>
                    <span class="status-pill status-<?= $capacityTone ?>"><span class="status-dot"></span><?= escapeHtml($capacityState) ?></span>
                </div>
                <div class="capacity-numbers">
                    <div><strong><?= number_format($stats['capacity']) ?></strong><span>Kapasitas maksimal <small>pcs</small></span></div>
                    <div><strong><?= number_format($stats['used']) ?></strong><span>Kapasitas terpakai <small>pcs</small></span></div>
                    <div class="remaining-number"><strong><?= number_format($stats['remaining']) ?></strong><span>Kapasitas tersisa <small>pcs</small></span></div>
                </div>
                <div class="progress-track" role="progressbar" aria-label="Kapasitas terpakai" aria-valuenow="<?= min(100, $usagePercent) ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-fill progress-<?= $capacityTone ?>" style="width: <?= min(100, $usagePercent) ?>%"></div>
                </div>
                <div class="capacity-foot"><span><?= min(100, $usagePercent) ?>% kapasitas terpakai</span><span><?= number_format($stats['remaining']) ?> pcs tersedia</span></div>
                <form method="post" class="capacity-edit">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_capacity">
                    <label for="capacity-input">Atur kapasitas maksimal</label>
                    <input id="capacity-input" type="number" name="capacity" min="<?= max(1, $stats['used']) ?>" value="<?= $stats['capacity'] ?>" required>
                    <span>pcs</span>
                    <button class="button button-light button-small" type="submit">Simpan kapasitas</button>
                </form>
            </section>

            <section class="orders-section" id="pesanan">
                <div class="section-heading">
                    <div><p class="eyebrow">KELOLA OPERASIONAL</p><h2>Data pesanan</h2></div>
                    <a class="button button-primary button-small" href="#form-pesanan">＋ Pesanan baru</a>
                </div>
                <form method="get" class="filter-bar">
                    <label class="search-box"><span>⌕</span><input type="search" name="q" placeholder="Cari nomor, pelanggan, produk..." value="<?= escapeHtml($search) ?>"></label>
                    <select name="status" aria-label="Filter status">
                        <option value="">Semua status</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= escapeHtml($status) ?>" <?= $filter === $status ? 'selected' : '' ?>><?= escapeHtml(ucfirst($status)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button button-light button-small" type="submit">Filter</button>
                    <?php if ($search !== '' || $filter !== ''): ?><a class="clear-filter" href="index.php#pesanan">Reset</a><?php endif; ?>
                </form>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>NO. PESANAN</th><th>PELANGGAN &amp; PRODUK</th><th>JUMLAH</th><th>PLATFORM</th><th>TANGGAL</th><th>STATUS</th><th></th></tr></thead>
                        <tbody>
                        <?php if (!$orders): ?>
                            <tr><td colspan="7" class="empty-cell"><span class="empty-icon">▤</span><strong>Belum ada pesanan</strong><span>Tambahkan pesanan pertama untuk mulai mengelola produksi.</span></td></tr>
                        <?php else: foreach ($orders as $order): ?>
                            <tr>
                                <td><a class="order-number" href="index.php?detail=<?= (int) $order['id'] ?>#detail"><?= escapeHtml($order['order_number']) ?></a></td>
                                <td><strong><?= escapeHtml($order['customer_name']) ?></strong><span class="table-sub"><?= escapeHtml($order['product_name']) ?></span></td>
                                <td><strong><?= number_format((int) $order['quantity']) ?></strong> <span class="table-sub inline">pcs</span></td>
                                <td><?= escapeHtml($order['platform']) ?></td>
                                <td><?= date('d M Y', strtotime($order['order_date'])) ?></td>
                                <td><span class="status-pill status-<?= escapeHtml($order['status']) ?>"><span class="status-dot"></span><?= escapeHtml(ucfirst($order['status'])) ?></span></td>
                                <td class="actions-cell">
                                    <a class="icon-action" title="Lihat detail" href="index.php?detail=<?= (int) $order['id'] ?>#detail">↗</a>
                                    <a class="icon-action" title="Edit pesanan" href="index.php?edit=<?= (int) $order['id'] ?>#form-pesanan">✎</a>
                                    <form method="post" onsubmit="return confirm('Hapus pesanan <?= escapeHtml($order['order_number']) ?>?')">
                                        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                                        <input type="hidden" name="action" value="delete_order">
                                        <input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
                                        <button class="icon-action delete-action" type="submit" title="Hapus pesanan">×</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if ($detailOrder): ?>
                <section class="detail-card" id="detail">
                    <div class="section-heading"><div><p class="eyebrow">RINCIAN PESANAN</p><h2><?= escapeHtml($detailOrder['order_number']) ?></h2></div><a class="button button-light button-small" href="index.php#pesanan">Tutup detail</a></div>
                    <div class="detail-grid">
                        <div><span>Nama pelanggan</span><strong><?= escapeHtml($detailOrder['customer_name']) ?></strong></div>
                        <div><span>Produk</span><strong><?= escapeHtml($detailOrder['product_name']) ?></strong></div>
                        <div><span>Jumlah</span><strong><?= number_format((int) $detailOrder['quantity']) ?> pcs</strong></div>
                        <div><span>Platform asal</span><strong><?= escapeHtml($detailOrder['platform']) ?></strong></div>
                        <div><span>Tanggal pesanan</span><strong><?= date('d M Y', strtotime($detailOrder['order_date'])) ?></strong></div>
                        <div><span>Status</span><strong><span class="status-pill status-<?= escapeHtml($detailOrder['status']) ?>"><?= escapeHtml(ucfirst($detailOrder['status'])) ?></span></strong></div>
                    </div>
                </section>
            <?php elseif (isset($_GET['detail'])): ?>
                <section class="alert alert-error" id="detail">Pesanan tidak ditemukan.</section>
            <?php endif; ?>

            <section class="form-card" id="form-pesanan">
                <div class="section-heading"><div><p class="eyebrow"><?= $editOrder ? 'PERBARUI DATA' : 'INPUT DATA' ?></p><h2><?= $editOrder ? 'Edit pesanan' : 'Tambah pesanan' ?></h2></div><?php if ($editOrder): ?><a class="button button-light button-small" href="index.php#form-pesanan">Batal edit</a><?php endif; ?></div>
                <form method="post" class="order-form">
                    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                    <input type="hidden" name="action" value="save_order">
                    <input type="hidden" name="id" value="<?= escapeHtml((string) $formOrder['id']) ?>">
                    <label>Nomor pesanan
                        <input type="text" value="<?= $editOrder ? escapeHtml($editOrder['order_number']) : 'Dibuat otomatis saat disimpan' ?>" readonly>
                    </label>
                    <label>Nama pelanggan
                        <input type="text" name="customer_name" maxlength="150" value="<?= escapeHtml($formOrder['customer_name']) ?>" placeholder="Contoh: Nadia Putri" required>
                    </label>
                    <label>Produk
                        <input type="text" name="product_name" maxlength="150" value="<?= escapeHtml($formOrder['product_name']) ?>" placeholder="Contoh: Kaos custom" required>
                    </label>
                    <label>Jumlah (pcs)
                        <input type="number" name="quantity" min="1" value="<?= escapeHtml((string) $formOrder['quantity']) ?>" placeholder="0" required>
                    </label>
                    <label>Platform asal
                        <select name="platform" required>
                            <option value="">Pilih platform</option>
                            <?php foreach ($platforms as $platform): ?>
                                <option value="<?= escapeHtml($platform) ?>" <?= $formOrder['platform'] === $platform ? 'selected' : '' ?>><?= escapeHtml($platform) ?></option>
                            <?php endforeach; ?>
                            <option value="__custom" <?= $platformIsCustom ? 'selected' : '' ?>>Lainnya (isi sendiri)</option>
                        </select>
                        <input class="custom-platform" type="text" name="custom_platform" maxlength="80" value="<?= $platformIsCustom ? escapeHtml($formOrder['platform']) : '' ?>" placeholder="Nama platform lain" <?= $platformIsCustom ? '' : 'disabled' ?>>
                    </label>
                    <label>Tanggal pesanan
                        <input type="date" name="order_date" value="<?= escapeHtml($formOrder['order_date']) ?>" required>
                    </label>
                    <label>Status
                        <select name="status" required>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= escapeHtml($status) ?>" <?= $formOrder['status'] === $status ? 'selected' : '' ?>><?= escapeHtml(ucfirst($status)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="field-hint">Pilih “Diproses” untuk memakai kapasitas produksi.</span>
                    </label>
                    <div class="form-actions"><span class="field-hint">Pesanan berstatus diproses tidak dapat melebihi sisa kapasitas.</span><button class="button button-primary" type="submit"><?= $editOrder ? 'Simpan perubahan' : 'Simpan pesanan' ?></button></div>
                </form>
            </section>
            <footer class="page-footer">PesananKita <span>•</span> Manajemen pesanan &amp; kapasitas produksi</footer>
        </div>
    </main>
    <script>
        const platformSelect = document.querySelector('select[name="platform"]');
        const customPlatform = document.querySelector('.custom-platform');
        function updateCustomPlatform() {
            const isCustom = platformSelect.value === '__custom';
            customPlatform.disabled = !isCustom;
            customPlatform.required = isCustom;
            if (isCustom) customPlatform.focus();
        }
        platformSelect.addEventListener('change', updateCustomPlatform);
        document.querySelector('.order-form').addEventListener('submit', function (event) {
            if (platformSelect.value === '__custom') {
                if (!customPlatform.value.trim()) {
                    event.preventDefault();
                    customPlatform.focus();
                    return;
                }
                const platformName = customPlatform.value.trim();
                let customOption = Array.from(platformSelect.options).find(option => option.value === platformName);
                if (!customOption) {
                    customOption = new Option(platformName, platformName, true, true);
                    platformSelect.add(customOption);
                }
                platformSelect.value = platformName;
            }
        });
    </script>
</body>
</html>
