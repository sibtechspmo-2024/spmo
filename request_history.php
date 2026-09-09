<?php
require_once 'db.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'user') {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    header("Location: index.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

// --- AJAX HANDLER FOR DELETE REQUEST ---
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    header('Content-Type: application/json');
    $group_id = trim($_GET['group_id'] ?? '');
    $type = $_GET['type'] ?? '';

    if ($type === 'document_printing') {
        $table = 'document_printing_requests';
    } else if ($type === 'maintenance') {
        $table = 'maintenance_requests';
    } else {
        $table = 'supply_requests';
    }

    $stmt = $conn->prepare("DELETE FROM {$table} WHERE request_group_id = ? AND user_id = ? AND status = 'Rejected'");
    $stmt->bind_param("si", $group_id, $user_id);
    $stmt->execute();

    if ($stmt->affected_rows > 0) {
        echo json_encode(['status' => 'success', 'message' => "Ang request na $group_id ay nabura na!"]);
    } else {
        echo json_encode(['status' => 'error', 'message' => "Hindi mabura ang request."]);
    }
    exit;
}

// --- AJAX HANDLER FOR REAL-TIME POLLING ---
if (isset($_GET['action']) && $_GET['action'] === 'fetch_updates') {
    header('Content-Type: application/json');

    $office_requests_res = $conn->query("
        SELECT r.request_group_id, u.fullname AS requisitioner_name, r.department, r.purpose, r.date_needed, r.status, r.request_date,
               GROUP_CONCAT(CONCAT(IFNULL(i.item_name, 'Unknown Item'), ' (x', r.quantity, ')') SEPARATOR '<br>') AS items_summary
        FROM supply_requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN items i ON r.item_id = i.id
        WHERE r.user_id = {$user_id}
        GROUP BY r.request_group_id, u.fullname, r.department, r.purpose, r.date_needed, r.status, r.request_date
        ORDER BY r.request_date DESC
    ")->fetch_all(MYSQLI_ASSOC);

    $maint_requests_res = $conn->query("
        SELECT r.request_group_id, u.fullname AS requisitioner_name, r.department, r.purpose, r.date_needed, r.status, r.request_date,
               GROUP_CONCAT(CONCAT(IFNULL(m.item_name, 'Unknown Item'), ' (x', r.quantity, ')') SEPARATOR '<br>') AS items_summary
        FROM maintenance_requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN maintenance_items m ON r.item_id = m.id
        WHERE r.user_id = {$user_id}
        GROUP BY r.request_group_id, u.fullname, r.department, r.purpose, r.date_needed, r.status, r.request_date
        ORDER BY r.request_date DESC
    ")->fetch_all(MYSQLI_ASSOC);

    $print_requests_res = $conn->query("
        SELECT id, request_group_id, requisitioner_name, department, document_file, paper_size, print_color, print_sides, binding_option, page_count, copies, total_price, purpose, date_needed, scheduled_time, status, created_at
        FROM document_printing_requests
        WHERE user_id = {$user_id}
        ORDER BY created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);

    $borrow_requests_res = $conn->query("
        SELECT r.id, r.request_group_id, r.requisitioner_name, r.department, r.quantity, r.borrow_date, r.expected_return_date, r.scheduled_time, r.purpose, r.status, r.created_at, i.item_name
        FROM borrow_requests r
        JOIN items i ON r.item_id = i.id
        WHERE r.user_id = {$user_id}
        ORDER BY r.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);

    echo json_encode([
        'status' => 'success',
        'office' => $office_requests_res,
        'maintenance' => $maint_requests_res,
        'printing' => $print_requests_res,
        'borrow' => $borrow_requests_res
    ]);
    exit;
}

// Initial Fetch
$office_requests = $conn->query("
    SELECT r.request_group_id, u.fullname AS requisitioner_name, r.department, r.purpose, r.date_needed, r.status, r.request_date,
           GROUP_CONCAT(CONCAT(IFNULL(i.item_name, 'Unknown Item'), ' (x', r.quantity, ')') SEPARATOR '<br>') AS items_summary
    FROM supply_requests r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN items i ON r.item_id = i.id
    WHERE r.user_id = {$user_id}
    GROUP BY r.request_group_id, u.fullname, r.department, r.purpose, r.date_needed, r.status, r.request_date
    ORDER BY r.request_date DESC
");

$borrow_requests = $conn->query("
    SELECT r.id, r.request_group_id, r.requisitioner_name, r.department, r.quantity, r.borrow_date, r.expected_return_date, r.scheduled_time, r.purpose, r.status, r.created_at,
           IFNULL(i.item_name, r.item_name) as item_name
    FROM borrow_requests r
    LEFT JOIN items i ON r.item_id = i.id AND r.item_id > 0
    WHERE r.user_id = {$user_id}
    ORDER BY r.created_at DESC
");

$print_requests = $conn->query("
    SELECT id, request_group_id, requisitioner_name, department, document_file, paper_size, print_color, print_sides, binding_option, page_count, copies, total_price, purpose, date_needed, scheduled_time, status, created_at
    FROM document_printing_requests
    WHERE user_id = {$user_id}
    ORDER BY created_at DESC
");

$maint_requests = $conn->query("
    SELECT r.request_group_id, u.fullname AS requisitioner_name, r.department, r.purpose, r.date_needed, r.status, r.request_date,
           GROUP_CONCAT(CONCAT(IFNULL(m.item_name, 'Unknown Item'), ' (x', r.quantity, ')') SEPARATOR '<br>') AS items_summary
    FROM maintenance_requests r
    JOIN users u ON r.user_id = u.id
    LEFT JOIN maintenance_items m ON r.item_id = m.id
    WHERE r.user_id = {$user_id}
    GROUP BY r.request_group_id, u.fullname, r.department, r.purpose, r.date_needed, r.status, r.request_date
    ORDER BY r.request_date DESC
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - SIBTECH Store</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/request_history.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1b4f9c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-history sticky-top shadow-sm">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold text-white d-flex align-items-center" href="user_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-2 border-white">
            <div class="lh-1">
                <span class="fs-5 d-block">SIBTECH STORE</span>
                <small class="fw-light opacity-75" style="font-size: 0.72rem;">My Order History</small>
            </div>
        </a>
        <div class="d-flex align-items-center">
            <a href="user_dashboard.php" class="btn btn-outline-light btn-sm rounded-pill px-3 me-3">
                <i class="bi bi-cart-plus-fill me-1"></i> New Order
            </a>
            <a href="logout.php" class="btn btn-outline-light btn-sm rounded-pill px-3">
                <i class="bi bi-box-arrow-right me-1"></i> Logout
            </a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">
    <div id="alert-box" class="alert d-none shadow-sm rounded-3"></div>

    <div class="orders-header d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h4 class="fw-bold mb-1"><i class="bi bi-bag-check-fill text-logo-blue me-2"></i>Aking mga Inorder (My Orders)</h4>
            <p class="text-muted small mb-0">Subaybayan ang status ng inyong mga isinumiteng order para sa Office at Maintenance supplies.</p>
        </div>
        <ul class="nav nav-pills" id="requestTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active fw-bold" id="office-tab" data-bs-toggle="tab" data-bs-target="#office-requests" type="button">
                    <i class="bi bi-box-seam me-1"></i> Office Supply Orders
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-bold" id="maint-tab" data-bs-toggle="tab" data-bs-target="#maint-requests" type="button">
                    <i class="bi bi-tools me-1"></i> Maintenance Supply Orders
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-bold" id="print-tab" data-bs-toggle="tab" data-bs-target="#print-requests" type="button">
                    <i class="bi bi-printer me-1"></i> Document Printing Orders
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link fw-bold" id="borrow-tab" data-bs-toggle="tab" data-bs-target="#borrow-requests" type="button">
                    <i class="bi bi-hand-holding me-1"></i> Borrow Requests
                </button>
            </li>
        </ul>
    </div>

    <div class="tab-content" id="requestTabsContent">
        <!-- Office Table -->
        <div class="tab-pane fade show active" id="office-requests">
            <div class="card card-history">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th><th>Ordered Items</th><th>Requisitioner</th><th>Department</th>
                                <th>Purpose</th><th>Date Needed</th><th>Status</th><th>Date Requested</th><th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="office-tbody">
                            <?php if(!$office_requests || $office_requests->num_rows == 0): ?>
                                <tr class="no-data"><td colspan="9" class="text-center text-muted py-4">Walang office supply orders.</td></tr>
                            <?php else: ?>
                                <?php while($req = $office_requests->fetch_assoc()): ?>
                                    <tr id="row-<?= $req['request_group_id'] ?>">
                                        <td class="fw-bold text-nowrap text-logo-blue">#<?= htmlspecialchars($req['request_group_id']) ?></td>
                                        <td><?= $req['items_summary'] ?></td>
                                        <td><?= htmlspecialchars($req['requisitioner_name'] ?? '') ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($req['department']) ?></span></td>
                                        <td><?= htmlspecialchars($req['purpose']) ?></td>
                                        <td class="text-nowrap"><?= $req['date_needed'] ? date('Y-m-d', strtotime($req['date_needed'])) : '-' ?></td>
                                        <td>
                                            <?php
                                            $st = $req['status'];
                                            $stClass = ($st == 'Approved') ? 'order-badge-approved' : (($st == 'Rejected') ? 'order-badge-rejected' : 'order-badge-pending');
                                            ?>
                                            <span class="badge rounded-pill px-3 py-2 <?= $stClass ?>"><?= $st ?></span>
                                        </td>
                                        <td class="text-nowrap text-muted small"><?= date('Y-m-d H:i', strtotime($req['request_date'])) ?></td>
                                        <td class="text-end">
                                            <?php if($req['status'] == 'Approved'): ?>
                                                <a href="print_request.php?group_id=<?= $req['request_group_id'] ?>" class="btn btn-sm btn-logo-primary rounded-pill px-3"><i class="bi bi-printer me-1"></i>Print Form</a>
                                            <?php elseif($req['status'] == 'Rejected'): ?>
                                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3 delete-btn" onclick="deleteRequest('<?= $req['request_group_id'] ?>', 'office')"><i class="bi bi-trash me-1"></i>Delete</button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled><i class="bi bi-clock me-1"></i>Pending</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Borrow Requests Table -->
        <div class="tab-pane fade" id="borrow-requests">
            <div class="card card-history">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Borrow ID</th><th>Borrowed Item</th><th>Qty</th><th>Duration & Schedule</th><th>Requisitioner</th><th>Status</th><th>Date Requested</th><th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="borrow-tbody">
                            <?php if(!$borrow_requests || $borrow_requests->num_rows == 0): ?>
                                <tr class="no-data"><td colspan="8" class="text-center text-muted py-4">Walang borrow requests.</td></tr>
                            <?php else: ?>
                                <?php while($req = $borrow_requests->fetch_assoc()): ?>
                                    <tr id="row-<?= $req['request_group_id'] ?>">
                                        <td class="fw-bold text-nowrap text-logo-blue">#<?= htmlspecialchars($req['request_group_id']) ?></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($req['item_name']) ?></td>
                                        <td><span class="badge bg-secondary rounded-pill"><?= $req['quantity'] ?></span></td>
                                        <td class="small">
                                            <span class="text-primary fw-bold"><i class="bi bi-calendar-event me-1"></i>Start: <?= htmlspecialchars($req['borrow_date']) ?></span><br>
                                            <span class="text-danger fw-bold"><i class="bi bi-calendar-check me-1"></i>Return: <?= htmlspecialchars($req['expected_return_date']) ?></span><br>
                                            <span class="text-muted"><i class="bi bi-clock me-1"></i><?= htmlspecialchars($req['scheduled_time'] ?? '') ?></span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($req['requisitioner_name'] ?? '') ?></strong><br>
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($req['department']) ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $st = $req['status'];
                                            $stClass = ($st == 'Approved' || $st == 'Returned') ? 'order-badge-approved' : (($st == 'Rejected') ? 'order-badge-rejected' : 'order-badge-pending');
                                            ?>
                                            <span class="badge rounded-pill px-3 py-2 <?= $stClass ?>"><?= $st ?></span>
                                        </td>
                                        <td class="text-nowrap text-muted small"><?= date('Y-m-d H:i', strtotime($req['created_at'])) ?></td>
                                        <td class="text-end">
                                            <?php if($req['status'] == 'Approved' || $req['status'] == 'Returned'): ?>
                                                <a href="print_borrow_request.php?group_id=<?= $req['request_group_id'] ?>" class="btn btn-sm btn-logo-primary rounded-pill px-3"><i class="bi bi-printer me-1"></i>Print Form</a>
                                            <?php elseif($req['status'] == 'Rejected'): ?>
                                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3 delete-btn" onclick="deleteRequest('<?= $req['request_group_id'] ?>', 'borrow')"><i class="bi bi-trash me-1"></i>Delete</button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled><i class="bi bi-clock me-1"></i><?= $req['status'] ?></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Document Printing Table -->
        <div class="tab-pane fade" id="print-requests">
            <div class="card card-history">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th><th>Document File</th><th>Specs</th><th>Schedule</th><th>Total Price</th>
                                <th>Requisitioner</th><th>Status</th><th>Date Requested</th><th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="print-tbody">
                            <?php if(!$print_requests || $print_requests->num_rows == 0): ?>
                                <tr class="no-data"><td colspan="9" class="text-center text-muted py-4">Walang document printing orders.</td></tr>
                            <?php else: ?>
                                <?php while($req = $print_requests->fetch_assoc()): ?>
                                    <tr id="row-<?= $req['request_group_id'] ?>">
                                        <td class="fw-bold text-nowrap text-logo-blue">#<?= htmlspecialchars($req['request_group_id']) ?></td>
                                        <td>
                                            <?php if (!empty($req['document_file'])): ?>
                                                <a href="uploads/<?= htmlspecialchars($req['document_file']) ?>" target="_blank" class="btn btn-sm btn-light border text-primary fw-semibold"><i class="bi bi-file-earmark-text me-1"></i>View File</a>
                                            <?php else: ?>
                                                <span class="text-muted small">No File</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <strong>Size:</strong> <?= htmlspecialchars($req['paper_size']) ?><br>
                                            <strong>Color:</strong> <?= htmlspecialchars($req['print_color']) ?><br>
                                            <strong>Sides:</strong> <?= htmlspecialchars($req['print_sides']) ?><br>
                                            <strong>Binding:</strong> <?= htmlspecialchars($req['binding_option']) ?>
                                        </td>
                                        <td class="small">
                                            <span class="badge bg-light text-dark border d-block mb-1"><?= $req['page_count'] ?> pgs x <?= $req['copies'] ?> copies</span>
                                            <span class="text-primary fw-bold"><i class="bi bi-calendar-event me-1"></i><?= $req['date_needed'] ? date('Y-m-d', strtotime($req['date_needed'])) : '-' ?></span><br>
                                            <span class="text-muted"><i class="bi bi-clock me-1"></i><?= htmlspecialchars($req['scheduled_time'] ?? '09:00 AM - 10:00 AM') ?></span>
                                        </td>
                                        <td class="fw-bold text-primary">₱<?= number_format($req['total_price'], 2) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($req['requisitioner_name'] ?? '') ?></strong><br>
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($req['department']) ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $st = $req['status'];
                                            $stClass = ($st == 'Approved' || $st == 'Completed') ? 'order-badge-approved' : (($st == 'Rejected') ? 'order-badge-rejected' : 'order-badge-pending');
                                            ?>
                                            <span class="badge rounded-pill px-3 py-2 <?= $stClass ?>"><?= $st ?></span>
                                        </td>
                                        <td class="text-nowrap text-muted small"><?= date('Y-m-d H:i', strtotime($req['created_at'])) ?></td>
                                        <td class="text-end">
                                            <?php if($req['status'] == 'Rejected'): ?>
                                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3 delete-btn" onclick="deleteRequest('<?= $req['request_group_id'] ?>', 'document_printing')"><i class="bi bi-trash me-1"></i>Delete</button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled><i class="bi bi-clock me-1"></i><?= $req['status'] ?></button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Maintenance Table -->
        <div class="tab-pane fade" id="maint-requests">
            <div class="card card-history">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th><th>Ordered Items</th><th>Requisitioner</th><th>Department</th>
                                <th>Purpose</th><th>Date Needed</th><th>Status</th><th>Date Requested</th><th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="maint-tbody">
                            <?php if(!$maint_requests || $maint_requests->num_rows == 0): ?>
                                <tr class="no-data"><td colspan="9" class="text-center text-muted py-4">Walang maintenance supply orders.</td></tr>
                            <?php else: ?>
                                <?php while($req = $maint_requests->fetch_assoc()): ?>
                                    <tr id="row-<?= $req['request_group_id'] ?>">
                                        <td class="fw-bold text-nowrap text-logo-blue">#<?= htmlspecialchars($req['request_group_id']) ?></td>
                                        <td><?= $req['items_summary'] ?></td>
                                        <td><?= htmlspecialchars($req['requisitioner_name'] ?? '') ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($req['department']) ?></span></td>
                                        <td><?= htmlspecialchars($req['purpose']) ?></td>
                                        <td class="text-nowrap"><?= $req['date_needed'] ? date('Y-m-d', strtotime($req['date_needed'])) : '-' ?></td>
                                        <td>
                                            <?php
                                            $st = $req['status'];
                                            $stClass = ($st == 'Approved') ? 'order-badge-approved' : (($st == 'Rejected') ? 'order-badge-rejected' : 'order-badge-pending');
                                            ?>
                                            <span class="badge rounded-pill px-3 py-2 <?= $stClass ?>"><?= $st ?></span>
                                        </td>
                                        <td class="text-nowrap text-muted small"><?= date('Y-m-d H:i', strtotime($req['request_date'])) ?></td>
                                        <td class="text-end">
                                            <?php if($req['status'] == 'Approved'): ?>
                                                <a href="print_maintenance_request.php?group_id=<?= $req['request_group_id'] ?>" class="btn btn-sm btn-logo-primary rounded-pill px-3"><i class="bi bi-printer me-1"></i>Print Form</a>
                                            <?php elseif($req['status'] == 'Rejected'): ?>
                                                <button class="btn btn-sm btn-outline-danger rounded-pill px-3 delete-btn" onclick="deleteRequest('<?= $req['request_group_id'] ?>', 'maintenance')"><i class="bi bi-trash me-1"></i>Delete</button>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled><i class="bi bi-clock me-1"></i>Pending</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function deleteRequest(groupId, type) {
    if(!confirm("Sigurado ka bang gusto mong burahin ang order na ito?")) return;

    fetch(`request_history.php?action=delete&group_id=${groupId}&type=${type}`)
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            const row = document.getElementById(`row-${groupId}`);
            if(row) row.remove();
        } else {
            alert(data.message);
        }
    });
}

function fetchLatestData() {
    fetch('request_history.php?action=fetch_updates', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            renderTable('office-tbody', data.office, 'office', 'print_request.php');
            renderTable('maint-tbody', data.maintenance, 'maintenance', 'print_maintenance_request.php');
            renderPrintTable('print-tbody', data.printing);
            renderBorrowTable('borrow-tbody', data.borrow);
        }
    });
}

function renderBorrowTable(tbodyId, items) {
    const tbody = document.getElementById(tbodyId);
    if (!items || items.length === 0) {
        tbody.innerHTML = `<tr class="no-data"><td colspan="8" class="text-center text-muted py-4">Walang borrow requests.</td></tr>`;
        return;
    }
    let html = '';
    items.forEach(req => {
        let badgeClass = (req.status === 'Approved' || req.status === 'Returned') ? 'order-badge-approved' : (req.status === 'Rejected' ? 'order-badge-rejected' : 'order-badge-pending');
        let actionBtn = '';
        if (req.status === 'Approved' || req.status === 'Returned') {
            actionBtn = `<a href="print_borrow_request.php?group_id=${req.request_group_id}" class="btn btn-sm btn-logo-primary rounded-pill px-3"><i class="bi bi-printer me-1"></i>Print Form</a>`;
        } else if (req.status === 'Rejected') {
            actionBtn = `<button class="btn btn-sm btn-outline-danger rounded-pill px-3 delete-btn" onclick="deleteRequest('${req.request_group_id}', 'borrow')"><i class="bi bi-trash me-1"></i>Delete</button>`;
        } else {
            actionBtn = `<button class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled><i class="bi bi-clock me-1"></i>${req.status}</button>`;
        }

        html += `<tr id="row-${req.request_group_id}">
            <td class="fw-bold text-nowrap text-logo-blue">#${req.request_group_id}</td>
            <td class="fw-semibold text-dark">${req.item_name}</td>
            <td><span class="badge bg-secondary rounded-pill">${req.quantity}</span></td>
            <td class="small">
                <span class="text-primary fw-bold"><i class="bi bi-calendar-event me-1"></i>Start: ${req.borrow_date}</span><br>
                <span class="text-danger fw-bold"><i class="bi bi-calendar-check me-1"></i>Return: ${req.expected_return_date}</span><br>
                <span class="text-muted"><i class="bi bi-clock me-1"></i>${req.scheduled_time || ''}</span>
            </td>
            <td><strong>${req.requisitioner_name || ''}</strong><br><span class="badge bg-light text-dark border">${req.department}</span></td>
            <td><span class="badge rounded-pill px-3 py-2 ${badgeClass}">${req.status}</span></td>
            <td class="text-nowrap text-muted small">${req.created_at}</td>
            <td class="text-end">${actionBtn}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function renderPrintTable(tbodyId, items) {
    const tbody = document.getElementById(tbodyId);
    if (!items || items.length === 0) {
        tbody.innerHTML = `<tr class="no-data"><td colspan="9" class="text-center text-muted py-4">Walang document printing orders.</td></tr>`;
        return;
    }
    let html = '';
    items.forEach(req => {
        let badgeClass = (req.status === 'Approved' || req.status === 'Completed') ? 'order-badge-approved' : (req.status === 'Rejected' ? 'order-badge-rejected' : 'order-badge-pending');
        let actionBtn = '';
        if (req.status === 'Rejected') {
            actionBtn = `<button class="btn btn-sm btn-outline-danger rounded-pill px-3 delete-btn" onclick="deleteRequest('${req.request_group_id}', 'document_printing')"><i class="bi bi-trash me-1"></i>Delete</button>`;
        } else {
            actionBtn = `<button class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled><i class="bi bi-clock me-1"></i>${req.status}</button>`;
        }

        let fileBtn = req.document_file ? `<a href="uploads/${req.document_file}" target="_blank" class="btn btn-sm btn-light border text-primary fw-semibold"><i class="bi bi-file-earmark-text me-1"></i>View File</a>` : `<span class="text-muted small">No File</span>`;

        html += `<tr id="row-${req.request_group_id}">
            <td class="fw-bold text-nowrap text-logo-blue">#${req.request_group_id}</td>
            <td>${fileBtn}</td>
            <td class="small">
                <strong>Size:</strong> ${req.paper_size}<br>
                <strong>Color:</strong> ${req.print_color}<br>
                <strong>Sides:</strong> ${req.print_sides}<br>
                <strong>Binding:</strong> ${req.binding_option}
            </td>
            <td class="small">
                <span class="badge bg-light text-dark border d-block mb-1">${req.page_count} pgs x ${req.copies} copies</span>
                <span class="text-primary fw-bold"><i class="bi bi-calendar-event me-1"></i>${req.date_needed ? req.date_needed.split(' ')[0] : '-'}</span><br>
                <span class="text-muted"><i class="bi bi-clock me-1"></i>${req.scheduled_time || '09:00 AM - 10:00 AM'}</span>
            </td>
            <td class="fw-bold text-primary">₱${parseFloat(req.total_price).toFixed(2)}</td>
            <td><strong>${req.requisitioner_name || ''}</strong><br><span class="badge bg-light text-dark border">${req.department}</span></td>
            <td><span class="badge rounded-pill px-3 py-2 ${badgeClass}">${req.status}</span></td>
            <td class="text-nowrap text-muted small">${req.created_at}</td>
            <td class="text-end">${actionBtn}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function renderTable(tbodyId, items, type, printPage) {
    const tbody = document.getElementById(tbodyId);
    if (!items || items.length === 0) {
        tbody.innerHTML = `<tr class="no-data"><td colspan="9" class="text-center text-muted py-4">Walang ${type} supply orders.</td></tr>`;
        return;
    }
    let html = '';
    items.forEach(req => {
        let badgeClass = req.status === 'Approved' ? 'order-badge-approved' : (req.status === 'Rejected' ? 'order-badge-rejected' : 'order-badge-pending');
        let actionBtn = '';
        if (req.status === 'Approved') {
            actionBtn = `<a href="${printPage}?group_id=${req.request_group_id}" class="btn btn-sm btn-logo-primary rounded-pill px-3"><i class="bi bi-printer me-1"></i>Print Form</a>`;
        } else if (req.status === 'Rejected') {
            actionBtn = `<button class="btn btn-sm btn-outline-danger rounded-pill px-3 delete-btn" onclick="deleteRequest('${req.request_group_id}', '${type}')"><i class="bi bi-trash me-1"></i>Delete</button>`;
        } else {
            actionBtn = `<button class="btn btn-sm btn-outline-secondary rounded-pill px-3" disabled><i class="bi bi-clock me-1"></i>Pending</button>`;
        }

        html += `<tr id="row-${req.request_group_id}">
            <td class="fw-bold text-nowrap text-logo-blue">#${req.request_group_id}</td>
            <td>${req.items_summary}</td>
            <td>${req.requisitioner_name || ''}</td>
            <td><span class="badge bg-light text-dark border">${req.department}</span></td>
            <td>${req.purpose}</td>
            <td class="text-nowrap">${req.date_needed ? req.date_needed.split(' ')[0] : '-'}</td>
            <td><span class="badge rounded-pill px-3 py-2 ${badgeClass}">${req.status}</span></td>
            <td class="text-nowrap text-muted small">${req.request_date}</td>
            <td class="text-end">${actionBtn}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

setInterval(fetchLatestData, 10000);
</script>
</body>
</html>