<?php
session_start();
require_once 'db.php';

// Siguraduhing Admin lamang ang makakapasok
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit;
}

// 1. AJAX Endpoint para sa Live Table Refresh
if (isset($_GET['fetch_requests']) && $_GET['fetch_requests'] == '1') {
    $type = $_GET['type'] ?? 'office';
    $req_table = ($type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';

    $requests = $conn->query("
        SELECT request_group_id, requisitioner_name, department, purpose, COUNT(*) as total_items, MAX(id) as max_id
        FROM {$req_table}
        WHERE status = 'Pending'
        GROUP BY request_group_id, requisitioner_name, department, purpose
        ORDER BY max_id DESC
    ");

    if ($requests && $requests->num_rows > 0) {
        while($row = $requests->fetch_assoc()) {
            echo '<tr>';
            echo '<td class="fw-bold text-logo-blue">#' . htmlspecialchars($row['request_group_id']) . '</td>';
            echo '<td>' . htmlspecialchars($row['requisitioner_name']) . '</td>';
            echo '<td><span class="badge bg-light text-dark border">' . htmlspecialchars($row['department']) . '</span></td>';
            echo '<td><span class="badge bg-secondary rounded-pill">' . $row['total_items'] . ' item(s)</span></td>';
            echo '<td>' . htmlspecialchars($row['purpose']) . '</td>';
            echo '<td class="text-end">';
            echo '<button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openViewRequestModal(\'' . htmlspecialchars($row['request_group_id'], ENT_QUOTES) . '\', \'' . $type . '\')">';
            echo '<i class="fa-solid fa-pen-to-square me-1"></i> Review Order';
            echo '</button>';
            echo '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="6" class="text-center text-muted py-4">Walang nakabinbing Requests.</td></tr>';
    }
    exit;
}

// 2. AJAX Endpoint para sa Request Details sa Modal na may Editable Quantities
if (isset($_GET['fetch_request_details']) && $_GET['fetch_request_details'] == '1') {
    $group_id = $_GET['group_id'] ?? '';
    $type = $_GET['type'] ?? 'office';

    $req_table = ($type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';
    $item_table = ($type === 'maintenance') ? 'maintenance_items' : 'items';

    $stmt = $conn->prepare("
        SELECT r.id as req_id, r.quantity, r.item_id, r.date_needed, r.scheduled_time, i.item_name, i.unit, i.actual_stocks
        FROM {$req_table} r
        JOIN {$item_table} i ON r.item_id = i.id
        WHERE r.request_group_id = ? AND r.status = 'Pending'
    ");
    $stmt->bind_param("s", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $first_row = $result->fetch_assoc();
        $result->data_seek(0);

        echo '<div class="alert alert-info py-2 mb-3 small d-flex justify-content-between align-items-center">';
        echo '<span><i class="fa-solid fa-calendar-check me-1"></i><strong>Scheduled Date:</strong> ' . ($first_row['date_needed'] ? htmlspecialchars($first_row['date_needed']) : 'N/A') . '</span>';
        echo '<span><i class="fa-solid fa-clock me-1"></i><strong>Scheduled Time:</strong> ' . htmlspecialchars($first_row['scheduled_time'] ?? '09:00 AM - 10:00 AM') . '</span>';
        echo '</div>';

        echo '<form id="editRequestItemsForm" class="ajax-form">';
        echo '<input type="hidden" name="action_request" value="1">';
        echo '<input type="hidden" name="action" id="modal_action_type" value="Approved">';
        echo '<input type="hidden" name="group_id" value="' . htmlspecialchars($group_id) . '">';
        echo '<input type="hidden" name="type" value="' . htmlspecialchars($type) . '">';

        echo '<div class="table-responsive"><table class="table table-bordered align-middle mb-0">';
        echo '<thead class="table-light"><tr><th>Item Name</th><th>Unit</th><th>Requested Qty (Editable)</th><th>Current Stock</th></tr></thead><tbody>';
        while($row = $result->fetch_assoc()) {
            $stock_class = ($row['actual_stocks'] < $row['quantity']) ? 'text-danger fw-bold' : 'text-success fw-bold';
            echo '<tr>';
            echo '<td class="fw-semibold">' . htmlspecialchars($row['item_name']) . '</td>';
            echo '<td><span class="badge bg-light text-dark border">' . htmlspecialchars($row['unit']) . '</span></td>';
            echo '<td><input type="number" name="quantities[' . $row['req_id'] . ']" value="' . intval($row['quantity']) . '" class="form-control form-control-sm" min="1" required style="width: 100px;"></td>';
            echo '<td class="' . $stock_class . '">' . $row['actual_stocks'] . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        echo '</form>';
    } else {
        echo '<p class="text-center text-muted">Walang nakitang detalye para sa request na ito.</p>';
    }
    exit;
}

// Suriin kung AJAX request ang pumasok para sa form submissions
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

function sendResponse($message, $success = true) {
    global $is_ajax;
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $message]);
        exit;
    } else {
        $_SESSION['flash_message'] = $message;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    }
}

$message = $_SESSION['flash_message'] ?? '';
unset($_SESSION['flash_message']);

// ACTION HANDLER PARA SA BORROW REQUESTS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_borrow_request'])) {
    $req_id = intval($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($req_id > 0 && in_array($action, ['Approved', 'Returned', 'Rejected'])) {
        $stmt_up = $conn->prepare("UPDATE borrow_requests SET status = ? WHERE id = ?");
        $stmt_up->bind_param("si", $action, $req_id);
        if ($stmt_up->execute()) {
            // Deduct / Restore stock
            $stmt_get = $conn->prepare("SELECT user_id, request_group_id, item_id, quantity FROM borrow_requests WHERE id = ?");
            $stmt_get->bind_param("i", $req_id);
            $stmt_get->execute();
            $b_res = $stmt_get->get_result()->fetch_assoc();

            if ($b_res) {
                if ($action === 'Approved') {
                    $conn->query("UPDATE items SET actual_stocks = actual_stocks - " . intval($b_res['quantity']) . " WHERE id = " . intval($b_res['item_id']));
                } elseif ($action === 'Returned') {
                    $conn->query("UPDATE items SET actual_stocks = actual_stocks + " . intval($b_res['quantity']) . " WHERE id = " . intval($b_res['item_id']));
                }

                if (!empty($b_res['user_id'])) {
                    $notif_msg = "Ang iyong Borrow request (#" . $b_res['request_group_id'] . ") ay na-" . strtolower($action) . " na!";
                    $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                    $stmt_notif->bind_param("is", $b_res['user_id'], $notif_msg);
                    $stmt_notif->execute();
                }
            }

            sendResponse("Matagumpay na na-update ang Borrow request status sa $action!", true);
        } else {
            sendResponse("Nabigong i-update ang status.", false);
        }
    } else {
        sendResponse("Invalid parameters.", false);
    }
}

// ACTION HANDLER PARA SA CALENDAR SCHEDULE MANAGEMENT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_add_schedule'])) {
    $title = trim($_POST['title'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $room = trim($_POST['room'] ?? '');
    $equipment = trim($_POST['equipment'] ?? '');
    $event_date = trim($_POST['event_date'] ?? '');
    $scheduled_time = trim($_POST['scheduled_time'] ?? '');
    $details = trim($_POST['details'] ?? '');

    if (!empty($title) && !empty($event_date)) {
        $stmt_cal = $conn->prepare("INSERT INTO calendar_schedules (title, department, room, equipment, event_date, scheduled_time, details, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'Admin')");
        $stmt_cal->bind_param("sssssss", $title, $department, $room, $equipment, $event_date, $scheduled_time, $details);
        if ($stmt_cal->execute()) {
            sendResponse("Matagumpay na naidagdag ang bagong schedule sa kalendaryo!", true);
        } else {
            sendResponse("Nabigong idagdag ang schedule.", false);
        }
    } else {
        sendResponse("Paki-punan ang Pamagat (Title) at Petsa (Date).", false);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_delete_schedule'])) {
    $sched_id = intval($_POST['schedule_id'] ?? 0);
    if ($sched_id > 0) {
        $stmt_del = $conn->prepare("DELETE FROM calendar_schedules WHERE id = ?");
        $stmt_del->bind_param("i", $sched_id);
        if ($stmt_del->execute()) {
            sendResponse("Matagumpay na nabura ang schedule!", true);
        } else {
            sendResponse("Nabigong burahin ang schedule.", false);
        }
    } else {
        sendResponse("Invalid schedule ID.", false);
    }
}

// ACTION HANDLER PARA SA DOCUMENT PRINTING REQUESTS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_print_request'])) {
    $req_id = intval($_POST['request_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($req_id > 0 && in_array($action, ['Approved', 'Completed', 'Rejected'])) {
        $stmt_up = $conn->prepare("UPDATE document_printing_requests SET status = ? WHERE id = ?");
        $stmt_up->bind_param("si", $action, $req_id);
        if ($stmt_up->execute()) {
            // Notify user
            $stmt_get = $conn->prepare("SELECT user_id, request_group_id FROM document_printing_requests WHERE id = ?");
            $stmt_get->bind_param("i", $req_id);
            $stmt_get->execute();
            $p_res = $stmt_get->get_result()->fetch_assoc();

            if ($p_res && !empty($p_res['user_id'])) {
                $notif_msg = "Ang iyong Document Printing request (#" . $p_res['request_group_id'] . ") ay na-" . strtolower($action) . " na!";
                $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $stmt_notif->bind_param("is", $p_res['user_id'], $notif_msg);
                $stmt_notif->execute();
            }

            sendResponse("Matagumpay na na-update ang Document Printing request status sa $action!", true);
        } else {
            sendResponse("Nabigong i-update ang status.", false);
        }
    } else {
        sendResponse("Invalid parameters.", false);
    }
}

// APPROVE / REJECT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_request'])) {
    $group_id = trim($_POST['group_id'] ?? '');
    $action = $_POST['action'] ?? '';
    $type = $_POST['type'] ?? 'office';

    $req_table = ($type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';
    $item_table = ($type === 'maintenance') ? 'maintenance_items' : 'items';

    if ($action === 'Approved') {
        $quantities = $_POST['quantities'] ?? [];
        $can_approve = true;
        $items_to_process = [];

        foreach ($quantities as $req_id => $new_qty) {
            $new_qty = intval($new_qty);
            if ($new_qty <= 0) continue;

            $stmt_r = $conn->prepare("SELECT item_id FROM {$req_table} WHERE id = ? AND request_group_id = ?");
            $stmt_r->bind_param("is", $req_id, $group_id);
            $stmt_r->execute();
            $req_res = $stmt_r->get_result()->fetch_assoc();

            if ($req_res) {
                $item_id = intval($req_res['item_id']);

                $stmt_chk = $conn->prepare("SELECT actual_stocks, item_name FROM {$item_table} WHERE id = ?");
                $stmt_chk->bind_param("i", $item_id);
                $stmt_chk->execute();
                $check_stock = $stmt_chk->get_result()->fetch_assoc();

                if (!$check_stock || $check_stock['actual_stocks'] < $new_qty) {
                    $can_approve = false;
                    $item_name = $check_stock['item_name'] ?? 'Unknown Item';
                    sendResponse("Kulang ang stock para sa item na: " . htmlspecialchars($item_name), false);
                    break;
                }
                $items_to_process[] = ['req_id' => $req_id, 'item_id' => $item_id, 'qty' => $new_qty];
            }
        }

        if ($can_approve && !empty($items_to_process)) {
            foreach ($items_to_process as $item) {
                $stmt_up_req = $conn->prepare("UPDATE {$req_table} SET quantity = ?, status = 'Approved', approved_at = NOW() WHERE id = ?");
                $stmt_up_req->bind_param("ii", $item['qty'], $item['req_id']);
                $stmt_up_req->execute();

                $stmt_deduct = $conn->prepare("UPDATE {$item_table} SET actual_stocks = actual_stocks - ? WHERE id = ?");
                $stmt_deduct->bind_param("ii", $item['qty'], $item['item_id']);
                $stmt_deduct->execute();
            }

            $stmt_rej_rem = $conn->prepare("UPDATE {$req_table} SET status = 'Rejected' WHERE request_group_id = ? AND status = 'Pending'");
            $stmt_rej_rem->bind_param("s", $group_id);
            $stmt_rej_rem->execute();

            // Notify user
            $stmt_usr = $conn->prepare("SELECT user_id FROM {$req_table} WHERE request_group_id = ? LIMIT 1");
            $stmt_usr->bind_param("s", $group_id);
            $stmt_usr->execute();
            $usr_res = $stmt_usr->get_result()->fetch_assoc();
            if ($usr_res && !empty($usr_res['user_id'])) {
                $notif_msg = "Ang iyong order request (#" . $group_id . ") ay na-aprubahan na ng Admin!";
                $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
                $stmt_notif->bind_param("is", $usr_res['user_id'], $notif_msg);
                $stmt_notif->execute();
            }

            sendResponse("Matagumpay na na-update at na-approve ang Order ID: " . htmlspecialchars($group_id) . "!", true);
        }
    } elseif ($action === 'Rejected') {
        // Notify user before reject
        $stmt_usr = $conn->prepare("SELECT user_id FROM {$req_table} WHERE request_group_id = ? LIMIT 1");
        $stmt_usr->bind_param("s", $group_id);
        $stmt_usr->execute();
        $usr_res = $stmt_usr->get_result()->fetch_assoc();

        $stmt_rej = $conn->prepare("UPDATE {$req_table} SET status = 'Rejected' WHERE request_group_id = ? AND status = 'Pending'");
        $stmt_rej->bind_param("s", $group_id);
        $stmt_rej->execute();

        if ($usr_res && !empty($usr_res['user_id'])) {
            $notif_msg = "Ang iyong order request (#" . $group_id . ") ay na-reject.";
            $stmt_notif = $conn->prepare("INSERT INTO notifications (user_id, message) VALUES (?, ?)");
            $stmt_notif->bind_param("is", $usr_res['user_id'], $notif_msg);
            $stmt_notif->execute();
        }

        sendResponse("Na-reject ang Order ID: " . htmlspecialchars($group_id) . "!", true);
    }
}

$office_requests = $conn->query("
    SELECT request_group_id, requisitioner_name, department, purpose, COUNT(*) as total_items, MAX(id) as max_id
    FROM supply_requests
    WHERE status = 'Pending'
    GROUP BY request_group_id, requisitioner_name, department, purpose
    ORDER BY max_id DESC
");

$maint_requests = $conn->query("
    SELECT request_group_id, requisitioner_name, department, purpose, COUNT(*) as total_items, MAX(id) as max_id
    FROM maintenance_requests
    WHERE status = 'Pending'
    GROUP BY request_group_id, requisitioner_name, department, purpose
    ORDER BY max_id DESC
");

$print_requests_list = [];
$print_requests_res = $conn->query("
    SELECT id, user_id, request_group_id, requisitioner_name, department, document_file, paper_size, print_color, print_sides, binding_option, page_count, copies, total_price, purpose, date_needed, scheduled_time, status, created_at
    FROM document_printing_requests
    ORDER BY id DESC
");
if ($print_requests_res) {
    while ($p_row = $print_requests_res->fetch_assoc()) {
        $print_requests_list[] = $p_row;
    }
}

$borrow_requests_list = [];
$borrow_requests_res = $conn->query("
    SELECT r.id, r.request_group_id, r.requisitioner_name, r.department, r.quantity, r.borrow_date, r.expected_return_date, r.scheduled_time, r.purpose, r.status, r.created_at,
           IFNULL(i.item_name, r.item_name) as item_name
    FROM borrow_requests r
    LEFT JOIN items i ON r.item_id = i.id AND r.item_id > 0
    ORDER BY r.id DESC
");
if ($borrow_requests_res) {
    while ($b_row = $borrow_requests_res->fetch_assoc()) {
        $borrow_requests_list[] = $b_row;
    }
}

$res_b = $conn->query("SELECT COUNT(*) as cnt FROM borrow_requests WHERE status = 'Pending'");
$row_b = $res_b ? $res_b->fetch_assoc() : null;
$borrow_pending_count = $row_b['cnt'] ?? 0;

$res_p = $conn->query("SELECT COUNT(*) as cnt FROM document_printing_requests WHERE status = 'Pending'");
$row_p = $res_p ? $res_p->fetch_assoc() : null;
$print_pending_count = $row_p['cnt'] ?? 0;

$office_pending_count = $office_requests ? $office_requests->num_rows : 0;
$maint_pending_count = $maint_requests ? $maint_requests->num_rows : 0;

$res_o = $conn->query("SELECT COUNT(*) as cnt FROM items WHERE actual_stocks <= 0");
$row_o = $res_o ? $res_o->fetch_assoc() : null;
$office_out_of_stock = $row_o['cnt'] ?? 0;

$res_m = $conn->query("SELECT COUNT(*) as cnt FROM maintenance_items WHERE actual_stocks <= 0");
$row_m = $res_m ? $res_m->fetch_assoc() : null;
$maint_out_of_stock = $row_m['cnt'] ?? 0;

// Stock history query with filter options
$stock_cat = $_GET['stock_cat'] ?? 'all';
$stock_time = $_GET['stock_time'] ?? 'all';

$sh_sql = "SELECT * FROM stock_history WHERE 1=1";
if ($stock_cat === 'office') {
    $sh_sql .= " AND LOWER(category) = 'office'";
} elseif ($stock_cat === 'maintenance') {
    $sh_sql .= " AND LOWER(category) = 'maintenance'";
}

if ($stock_time === 'today') {
    $sh_sql .= " AND DATE(updated_at) = CURDATE()";
} elseif ($stock_time === '7days') {
    $sh_sql .= " AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($stock_time === 'month') {
    $sh_sql .= " AND MONTH(updated_at) = MONTH(CURRENT_DATE()) AND YEAR(updated_at) = YEAR(CURRENT_DATE())";
}

$sh_sql .= " ORDER BY updated_at DESC LIMIT 20";
$stock_history = $conn->query($sh_sql);

// Query for Order Request History (Office & Maintenance)
$req_hist_status = $_GET['req_status'] ?? 'all';
$req_hist_cat = $_GET['req_cat'] ?? 'all';

$all_history_requests = [];

if ($req_hist_cat === 'all' || $req_hist_cat === 'office') {
    $off_sql = "
        SELECT 'office' as type, request_group_id, requisitioner_name, department, purpose, status,
               COUNT(*) as total_items, MAX(created_at) as created_at, MAX(approved_at) as approved_at
        FROM supply_requests
        WHERE 1=1
    ";
    if ($req_hist_status !== 'all') {
        $off_sql .= " AND status = '" . $conn->real_escape_string($req_hist_status) . "'";
    }
    $off_sql .= " GROUP BY request_group_id, requisitioner_name, department, purpose, status";
    $off_res = $conn->query($off_sql);
    if ($off_res) {
        while ($r = $off_res->fetch_assoc()) $all_history_requests[] = $r;
    }
}

if ($req_hist_cat === 'all' || $req_hist_cat === 'maintenance') {
    $mnt_sql = "
        SELECT 'maintenance' as type, request_group_id, requisitioner_name, department, purpose, status,
               COUNT(*) as total_items, MAX(created_at) as created_at, MAX(approved_at) as approved_at
        FROM maintenance_requests
        WHERE 1=1
    ";
    if ($req_hist_status !== 'all') {
        $mnt_sql .= " AND status = '" . $conn->real_escape_string($req_hist_status) . "'";
    }
    $mnt_sql .= " GROUP BY request_group_id, requisitioner_name, department, purpose, status";
    $mnt_res = $conn->query($mnt_sql);
    if ($mnt_res) {
        while ($r = $mnt_res->fetch_assoc()) $all_history_requests[] = $r;
    }
}

// Sort history requests by created_at DESC
usort($all_history_requests, function($a, $b) {
    return strtotime($b['created_at']) - strtotime($a['created_at']);
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin E-Commerce Portal - SIBTECH</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin_dashboard.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1b4f9c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
</head>
<body>

<!-- E-COMMERCE ADMIN TOP BAR -->
<nav class="navbar navbar-expand-lg navbar-dark navbar-admin py-3 shadow-sm" style="background-color: #1b4f9c;">
    <div class="container-fluid px-4">
        <a class="navbar-brand fw-bold d-flex align-items-center text-white" href="admin_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-2 border-white me-2" style="width: 42px; height: 42px;">
            <div class="lh-1">
                <span class="fs-5 d-block fw-extrabold" style="letter-spacing: 0.5px;">SIBTECH ADMIN</span>
                <small class="fw-light text-white-50" style="font-size: 0.75rem;">Borrowers & Inventory Management</small>
            </div>
        </a>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a href="admin_office.php" class="btn btn-outline-light btn-sm rounded-pill px-3 fw-semibold">
                <i class="fa-solid fa-box-archive me-1"></i> Office
            </a>
            <a href="admin_maintenance.php" class="btn btn-outline-light btn-sm rounded-pill px-3 fw-semibold">
                <i class="fa-solid fa-wrench me-1"></i> Maintenance
            </a>
            <a href="logout.php" class="btn btn-light btn-sm rounded-pill px-3 text-primary fw-bold ms-1">
                <i class="fa-solid fa-right-from-bracket me-1"></i> Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div id="alert-container">
        <?php if($message): ?>
            <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i> <?= $message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
    </div>

    <!-- E-COMMERCE STATS DASHBOARD MATCHING IMAGE UI -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 rounded-4 text-white shadow-sm" style="background-color: #007bff;">
                <small class="text-uppercase fw-extrabold tracking-wide" style="font-size: 0.75rem;">OFFICE ORDERS</small>
                <h2 class="fw-extrabold mb-0 mt-1" style="font-size: 2.2rem;"><?= $office_pending_count ?></h2>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 rounded-4 text-dark shadow-sm" style="background-color: #ffc107;">
                <small class="text-uppercase fw-extrabold tracking-wide" style="font-size: 0.75rem;">MAINTENANCE ORDERS</small>
                <h2 class="fw-extrabold mb-0 mt-1" style="font-size: 2.2rem;"><?= $maint_pending_count ?></h2>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 rounded-4 text-white shadow-sm" style="background-color: #17a2b8;">
                <small class="text-uppercase fw-extrabold tracking-wide" style="font-size: 0.75rem;">BORROW REQUESTS</small>
                <h2 class="fw-extrabold mb-0 mt-1" style="font-size: 2.2rem;"><?= $borrow_pending_count ?></h2>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="p-3 rounded-4 text-white shadow-sm" style="background-color: #dc3545;">
                <small class="text-uppercase fw-extrabold tracking-wide" style="font-size: 0.75rem;">OUT OF STOCK ITEMS</small>
                <h2 class="fw-extrabold mb-0 mt-1" style="font-size: 2.2rem;"><?= $office_out_of_stock + $maint_out_of_stock ?></h2>
            </div>
        </div>
    </div>

<?php
$is_stock_hist = isset($_GET['stock_cat']) || isset($_GET['stock_time']);
$is_req_hist = isset($_GET['req_status']) || isset($_GET['req_cat']);
?>
    <!-- TABS NAVIGATION EXACTLY MATCHING IMAGE UI -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 p-2 bg-white">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <ul class="nav nav-pills gap-2" id="adminTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link <?= (!$is_stock_hist && !$is_req_hist) ? 'active' : '' ?> border-0 text-dark fw-bold px-3 py-2" id="office-req-tab" data-bs-toggle="tab" data-bs-target="#office-req" type="button">
                        <i class="fa-solid fa-cart-shopping me-2"></i>Office Orders (<?= $office_pending_count ?>)
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link border-0 text-dark fw-bold px-3 py-2" id="maint-req-tab" data-bs-toggle="tab" data-bs-target="#maint-req" type="button">
                        <i class="fa-solid fa-wrench me-2"></i>Maintenance (<?= $maint_pending_count ?>)
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link border-0 text-dark fw-bold px-3 py-2" id="borrow-req-tab" data-bs-toggle="tab" data-bs-target="#borrow-req" type="button">
                        <i class="fa-solid fa-hand-holding me-2"></i>Borrow Requests (<?= $borrow_pending_count ?>)
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link border-0 text-dark fw-bold px-3 py-2" id="print-req-tab" data-bs-toggle="tab" data-bs-target="#print-req" type="button">
                        <i class="fa-solid fa-print me-2"></i>Document Printing (<?= $print_pending_count ?>)
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link border-0 text-dark fw-bold px-3 py-2" id="calendar-tab" data-bs-toggle="tab" data-bs-target="#calendar-view" type="button">
                        <i class="fa-solid fa-calendar-days me-2"></i>Scheduling & Calendar
                    </button>
                </li>
            </ul>
            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" style="background-color: #1b4f9c;" id="user-req-hist-tab-btn" onclick="$('#user-req-hist-tab').click()">
                <i class="fa-solid fa-list-ul me-2"></i>Order History
            </button>
            <button class="d-none" id="user-req-hist-tab" data-bs-toggle="tab" data-bs-target="#user-req-hist" type="button"></button>
        </div>
    </div>

    <div class="tab-content" id="adminTabsContent">
        <!-- Tab 1: Office Requests -->
        <div class="tab-pane fade <?= (!$is_stock_hist && !$is_req_hist) ? 'show active' : '' ?>" id="office-req">
            <div class="card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-box-open text-logo-blue me-2"></i>Pending Office Supply Orders</h5>
                    <a href="admin_office.php" class="btn btn-sm btn-logo-primary rounded-pill px-3">
                        <i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Open Office Page & Inventory
                    </a>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th>
                                <th>Requisitioner</th>
                                <th>Dept</th>
                                <th>Total Items</th>
                                <th>Purpose</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="office-requests-tbody">
                            <?php if ($office_requests && $office_requests->num_rows > 0): ?>
                                <?php while($row = $office_requests->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold text-logo-blue">#<?= htmlspecialchars($row['request_group_id']) ?></td>
                                        <td><?= htmlspecialchars($row['requisitioner_name']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['department']) ?></span></td>
                                        <td><span class="badge bg-secondary rounded-pill"><?= $row['total_items'] ?> item(s)</span></td>
                                        <td><?= htmlspecialchars($row['purpose']) ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openViewRequestModal('<?= htmlspecialchars($row['request_group_id'], ENT_QUOTES) ?>', 'office')">
                                                <i class="fa-solid fa-pen-to-square me-1"></i> Review Order
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Walang nakabinbing Office Supply Orders.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Borrow Requests -->
        <div class="tab-pane fade" id="borrow-req">
            <div class="card p-4 border-0 shadow-sm rounded-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-hand-holding text-logo-blue me-2"></i>Equipment & Supply Borrow Requests</h5>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Borrow ID</th>
                                <th>Requisitioner</th>
                                <th>Borrowed Item</th>
                                <th>Qty</th>
                                <th>Schedule & Duration</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($borrow_requests_list)): ?>
                                <?php foreach ($borrow_requests_list as $b): ?>
                                    <tr>
                                        <td class="fw-bold text-logo-blue">#<?= htmlspecialchars($b['request_group_id']) ?></td>
                                        <td>
                                            <strong class="text-dark"><?= htmlspecialchars($b['requisitioner_name']) ?></strong><br>
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($b['department']) ?></span>
                                        </td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($b['item_name']) ?></td>
                                        <td><span class="badge bg-secondary rounded-pill"><?= $b['quantity'] ?></span></td>
                                        <td class="small">
                                            <strong class="text-primary"><i class="fa-solid fa-calendar-day me-1"></i>Start:</strong> <?= htmlspecialchars($b['borrow_date']) ?><br>
                                            <strong class="text-danger"><i class="fa-solid fa-calendar-check me-1"></i>Return:</strong> <?= htmlspecialchars($b['expected_return_date']) ?><br>
                                            <span class="text-muted"><i class="fa-solid fa-clock me-1"></i><?= htmlspecialchars($b['scheduled_time'] ?? '') ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $bst = $b['status'];
                                            if ($bst === 'Approved') echo '<span class="badge bg-success-subtle text-success border border-success-subtle fw-bold"><i class="fa-solid fa-circle-check me-1"></i>Approved</span>';
                                            elseif ($bst === 'Returned') echo '<span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold"><i class="fa-solid fa-rotate-left me-1"></i>Returned</span>';
                                            elseif ($bst === 'Rejected') echo '<span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold"><i class="fa-solid fa-circle-xmark me-1"></i>Rejected</span>';
                                            else echo '<span class="badge bg-warning-subtle text-warning border border-warning-subtle fw-bold text-dark"><i class="fa-solid fa-clock me-1"></i>Pending</span>';
                                            ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <?php if ($b['status'] === 'Approved' || $b['status'] === 'Returned'): ?>
                                                    <a href="print_borrow_request.php?group_id=<?= $b['request_group_id'] ?>" class="btn btn-sm btn-outline-dark rounded-pill px-2 me-1" title="Print Borrower Form"><i class="fa-solid fa-print"></i> Print</a>
                                                <?php endif; ?>
                                                <form method="POST" action="" class="ajax-form d-inline">
                                                    <input type="hidden" name="action_borrow_request" value="1">
                                                    <input type="hidden" name="request_id" value="<?= $b['id'] ?>">
                                                    <input type="hidden" name="action" value="Approved">
                                                    <button type="submit" class="btn btn-sm btn-success rounded-pill px-2 me-1" title="Approve Borrow" onclick="return confirm('I-approve ang hiram na ito?');"><i class="fa-solid fa-check"></i> Approve</button>
                                                </form>
                                                <form method="POST" action="" class="ajax-form d-inline">
                                                    <input type="hidden" name="action_borrow_request" value="1">
                                                    <input type="hidden" name="request_id" value="<?= $b['id'] ?>">
                                                    <input type="hidden" name="action" value="Returned">
                                                    <button type="submit" class="btn btn-sm btn-primary rounded-pill px-2 me-1" title="Mark Returned" onclick="return confirm('Mark as returned to inventory?');"><i class="fa-solid fa-rotate-left"></i> Returned</button>
                                                </form>
                                                <form method="POST" action="" class="ajax-form d-inline">
                                                    <input type="hidden" name="action_borrow_request" value="1">
                                                    <input type="hidden" name="request_id" value="<?= $b['id'] ?>">
                                                    <input type="hidden" name="action" value="Rejected">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2" title="Reject" onclick="return confirm('I-reject ang hiram na ito?');"><i class="fa-solid fa-xmark"></i> Reject</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Walang borrow requests.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Calendar & Scheduling -->
        <div class="tab-pane fade" id="calendar-view">
            <div class="card p-4 border-0 shadow-sm rounded-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-calendar-plus text-logo-blue me-2"></i>Magdagdag ng Bagong Whiteboard Schedule (Admin Posting)</h5>
                    <a href="user_schedule.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">
                        <i class="fa-solid fa-eye me-1"></i> View Whiteboard Schedule
                    </a>
                </div>
                <form method="POST" action="" class="ajax-form bg-light p-3 rounded-3 border">
                    <input type="hidden" name="action_add_schedule" value="1">
                    <div class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-secondary">Department / Subject Code</label>
                            <input type="text" name="department" class="form-control form-control-sm" placeholder="e.g., CRIM, HM, CBA, SAD">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-secondary">Room / Venue</label>
                            <input type="text" name="room" class="form-control form-control-sm" placeholder="e.g., Room 101, Lab A, Gym">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-secondary">Equipment / Gamit</label>
                            <input type="text" name="equipment" class="form-control form-control-sm" placeholder="e.g., Projector, Mic, Extension">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-secondary">Title / Activity *</label>
                            <input type="text" name="title" class="form-control form-control-sm" placeholder="e.g., Class Schedule" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-secondary">Event Date *</label>
                            <input type="date" name="event_date" class="form-control form-control-sm" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-bold text-secondary">Time Slot</label>
                            <input type="text" name="scheduled_time" class="form-control form-control-sm" placeholder="e.g., 7:00 AM - 12:00 PM">
                        </div>
                        <div class="col-md-12 d-flex justify-content-end mt-2">
                            <button type="submit" class="btn btn-sm btn-logo-primary rounded-pill px-4 fw-bold">
                                <i class="fa-solid fa-plus me-1"></i> Add Schedule
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Admin Posted Schedules Table -->
            <div class="card p-4 border-0 shadow-sm rounded-4 mb-4">
                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-calendar-check text-logo-blue me-2"></i>Listahan ng Admin Whiteboard Postings</h5>
                <div class="table-responsive">
                    <table class="table table-hover align-middle border">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Department</th>
                                <th>Room / Venue</th>
                                <th>Equipment / Gamit</th>
                                <th>Title / Activity</th>
                                <th>Date</th>
                                <th>Time Slot</th>
                                <th>Posted By</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $posted_cal = $conn->query("SELECT * FROM calendar_schedules ORDER BY event_date DESC, id DESC");
                            if ($posted_cal && $posted_cal->num_rows > 0):
                                while ($pcal = $posted_cal->fetch_assoc()):
                            ?>
                                <tr>
                                    <td class="fw-bold text-secondary">#<?= $pcal['id'] ?></td>
                                    <td><span class="badge bg-danger text-white fw-bold"><?= htmlspecialchars($pcal['department'] ?: 'GENERAL') ?></span></td>
                                    <td><span class="badge bg-info text-white fw-bold"><?= htmlspecialchars($pcal['room'] ?: 'N/A') ?></span></td>
                                    <td><span class="badge bg-warning text-dark fw-bold"><?= htmlspecialchars($pcal['equipment'] ?: 'N/A') ?></span></td>
                                    <td class="fw-bold text-dark"><?= htmlspecialchars($pcal['title']) ?></td>
                                    <td class="fw-bold text-primary"><i class="fa-solid fa-calendar me-1"></i><?= htmlspecialchars($pcal['event_date']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($pcal['scheduled_time'] ?: 'N/A') ?></span></td>
                                    <td class="small text-muted"><?= htmlspecialchars($pcal['created_by']) ?></td>
                                    <td class="text-end">
                                        <form method="POST" action="" class="ajax-form d-inline">
                                            <input type="hidden" name="action_delete_schedule" value="1">
                                            <input type="hidden" name="schedule_id" value="<?= $pcal['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3 fw-bold" onclick="return confirm('Sigurado ka bang burahin ang schedule na ito?');">
                                                <i class="fa-solid fa-trash me-1"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php
                                endwhile;
                            else:
                            ?>
                                <tr><td colspan="9" class="text-center text-muted py-4">Walang nai-post na admin schedules.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card p-4 border-0 shadow-sm rounded-4">
                <h5 class="fw-bold text-dark mb-3"><i class="fa-solid fa-calendar-days text-logo-blue me-2"></i>System Pickup & Borrow Schedules</h5>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Schedule Date</th>
                                <th>Time Slot</th>
                                <th>Request Type</th>
                                <th>Order / Borrow ID</th>
                                <th>Requisitioner & Dept</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $all_schedules = [];


                            // Printing schedules
                            foreach ($print_requests_list as $p) {
                                if (!empty($p['date_needed'])) {
                                    $all_schedules[] = [
                                        'date' => $p['date_needed'],
                                        'time' => $p['scheduled_time'] ?? '09:00 AM - 10:00 AM',
                                        'type' => 'Document Printing Pickup',
                                        'id' => $p['request_group_id'],
                                        'req' => $p['requisitioner_name'] . ' (' . $p['department'] . ')',
                                        'details' => 'Document Print (' . $p['paper_size'] . ', ' . $p['print_color'] . ') - Status: ' . $p['status']
                                    ];
                                }
                            }

                            usort($all_schedules, function($a, $b) {
                                return strtotime($a['date']) - strtotime($b['date']);
                            });

                            if (!empty($all_schedules)):
                                foreach ($all_schedules as $sched):
                            ?>
                                <tr>
                                    <td class="fw-bold text-primary"><i class="fa-solid fa-calendar me-1"></i><?= htmlspecialchars($sched['date']) ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($sched['time']) ?></span></td>
                                    <td><span class="badge bg-info text-white fw-bold"><?= htmlspecialchars($sched['type']) ?></span></td>
                                    <td class="fw-bold text-logo-blue">#<?= htmlspecialchars($sched['id']) ?></td>
                                    <td><?= htmlspecialchars($sched['req']) ?></td>
                                    <td class="small text-secondary"><?= htmlspecialchars($sched['details']) ?></td>
                                </tr>
                            <?php
                                endforeach;
                            else:
                            ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">Walang nakaiskedyul na mga gawain sa kasalukuyan.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 3: Document Printing Requests -->
        <div class="tab-pane fade" id="print-req">
            <div class="card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-print text-logo-blue me-2"></i>Document Printing Requests</h5>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th>
                                <th>Requisitioner</th>
                                <th>Document</th>
                                <th>Print Specifications</th>
                                <th>Scheduled Time</th>
                                <th>Total Price</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($print_requests_list)): ?>
                                <?php foreach ($print_requests_list as $p): ?>
                                    <tr>
                                        <td class="fw-bold text-logo-blue">#<?= htmlspecialchars($p['request_group_id']) ?></td>
                                        <td>
                                            <strong class="text-dark"><?= htmlspecialchars($p['requisitioner_name']) ?></strong><br>
                                            <span class="badge bg-light text-dark border"><?= htmlspecialchars($p['department']) ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($p['document_file'])): ?>
                                                <a href="uploads/<?= htmlspecialchars($p['document_file']) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-semibold">
                                                    <i class="fa-solid fa-file-pdf me-1"></i> View Document
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">No File</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">
                                            <strong>Size:</strong> <?= htmlspecialchars($p['paper_size']) ?><br>
                                            <strong>Color:</strong> <?= htmlspecialchars($p['print_color']) ?> | <strong>Sides:</strong> <?= htmlspecialchars($p['print_sides']) ?><br>
                                            <strong>Binding:</strong> <?= htmlspecialchars($p['binding_option']) ?><br>
                                            <strong>Copies:</strong> <?= $p['page_count'] ?> pgs x <?= $p['copies'] ?> copies
                                        </td>
                                        <td class="small text-nowrap">
                                            <strong class="text-primary"><i class="fa-solid fa-calendar me-1"></i><?= $p['date_needed'] ? date('Y-m-d', strtotime($p['date_needed'])) : '-' ?></strong><br>
                                            <span class="text-muted"><i class="fa-solid fa-clock me-1"></i><?= htmlspecialchars($p['scheduled_time'] ?? '09:00 AM - 10:00 AM') ?></span>
                                        </td>
                                        <td class="fw-bold text-success">₱<?= number_format($p['total_price'], 2) ?></td>
                                        <td>
                                            <?php
                                            $pst = $p['status'];
                                            if ($pst === 'Approved') echo '<span class="badge bg-success-subtle text-success border border-success-subtle fw-bold"><i class="fa-solid fa-circle-check me-1"></i>Approved</span>';
                                            elseif ($pst === 'Completed') echo '<span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold"><i class="fa-solid fa-check-double me-1"></i>Completed</span>';
                                            elseif ($pst === 'Rejected') echo '<span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold"><i class="fa-solid fa-circle-xmark me-1"></i>Rejected</span>';
                                            else echo '<span class="badge bg-warning-subtle text-warning border border-warning-subtle fw-bold text-dark"><i class="fa-solid fa-clock me-1"></i>Pending</span>';
                                            ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group">
                                                <form method="POST" action="" class="ajax-form d-inline">
                                                    <input type="hidden" name="action_print_request" value="1">
                                                    <input type="hidden" name="request_id" value="<?= $p['id'] ?>">
                                                    <input type="hidden" name="action" value="Approved">
                                                    <button type="submit" class="btn btn-sm btn-success rounded-pill px-2 me-1" title="Approve" onclick="return confirm('I-approve ang printing request na ito?');"><i class="fa-solid fa-check"></i> Approve</button>
                                                </form>
                                                <form method="POST" action="" class="ajax-form d-inline">
                                                    <input type="hidden" name="action_print_request" value="1">
                                                    <input type="hidden" name="request_id" value="<?= $p['id'] ?>">
                                                    <input type="hidden" name="action" value="Completed">
                                                    <button type="submit" class="btn btn-sm btn-primary rounded-pill px-2 me-1" title="Complete" onclick="return confirm('Mark as completed?');"><i class="fa-solid fa-check-double"></i> Complete</button>
                                                </form>
                                                <form method="POST" action="" class="ajax-form d-inline">
                                                    <input type="hidden" name="action_print_request" value="1">
                                                    <input type="hidden" name="request_id" value="<?= $p['id'] ?>">
                                                    <input type="hidden" name="action" value="Rejected">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-2" title="Reject" onclick="return confirm('I-reject ang printing request na ito?');"><i class="fa-solid fa-xmark"></i> Reject</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Walang document printing requests.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 2: Maintenance Requests -->
        <div class="tab-pane fade" id="maint-req">
            <div class="card p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-wrench text-logo-blue me-2"></i>Pending Maintenance Supply Orders</h5>
                    <a href="admin_maintenance.php" class="btn btn-sm btn-logo-primary rounded-pill px-3">
                        <i class="fa-solid fa-arrow-right-to-bracket me-1"></i> Open Maintenance Page & Inventory
                    </a>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th>
                                <th>Requisitioner</th>
                                <th>Dept</th>
                                <th>Total Items</th>
                                <th>Purpose</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="maint-requests-tbody">
                            <?php if ($maint_requests && $maint_requests->num_rows > 0): ?>
                                <?php while($row = $maint_requests->fetch_assoc()): ?>
                                    <tr>
                                        <td class="fw-bold text-logo-blue">#<?= htmlspecialchars($row['request_group_id']) ?></td>
                                        <td><?= htmlspecialchars($row['requisitioner_name']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['department']) ?></span></td>
                                        <td><span class="badge bg-secondary rounded-pill"><?= $row['total_items'] ?> item(s)</span></td>
                                        <td><?= htmlspecialchars($row['purpose']) ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openViewRequestModal('<?= htmlspecialchars($row['request_group_id'], ENT_QUOTES) ?>', 'maintenance')">
                                                <i class="fa-solid fa-pen-to-square me-1"></i> Review Order
                                            </button>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">Walang nakabinbing Maintenance Supply Orders.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 3: Request Orders History -->
        <div class="tab-pane fade <?= $is_req_hist ? 'show active' : '' ?>" id="user-req-hist">
            <div class="card p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-clock-rotate-left text-logo-blue me-2"></i>All User Request Orders History</h5>
                    <form method="GET" class="d-flex gap-2 align-items-center">
                        <select name="req_cat" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                            <option value="all" <?= $req_hist_cat === 'all' ? 'selected' : '' ?>>All Categories</option>
                            <option value="office" <?= $req_hist_cat === 'office' ? 'selected' : '' ?>>Office Supplies Only</option>
                            <option value="maintenance" <?= $req_hist_cat === 'maintenance' ? 'selected' : '' ?>>Maintenance Supplies Only</option>
                        </select>
                        <select name="req_status" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                            <option value="all" <?= $req_hist_status === 'all' ? 'selected' : '' ?>>All Statuses</option>
                            <option value="Approved" <?= $req_hist_status === 'Approved' ? 'selected' : '' ?>>Approved Only</option>
                            <option value="Pending" <?= $req_hist_status === 'Pending' ? 'selected' : '' ?>>Pending Only</option>
                            <option value="Rejected" <?= $req_hist_status === 'Rejected' ? 'selected' : '' ?>>Rejected Only</option>
                        </select>
                    </form>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Order ID</th>
                                <th>Category</th>
                                <th>Requisitioner</th>
                                <th>Dept</th>
                                <th>Total Items</th>
                                <th>Status</th>
                                <th>Date Placed</th>
                                <th class="text-end">Print / Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($all_history_requests)): ?>
                                <?php foreach ($all_history_requests as $req): ?>
                                    <?php
                                    $st = $req['status'];
                                    if ($st === 'Approved') {
                                        $status_badge = '<span class="badge bg-success-subtle text-success border border-success-subtle fw-bold"><i class="fa-solid fa-circle-check me-1"></i>Approved</span>';
                                    } elseif ($st === 'Rejected') {
                                        $status_badge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold"><i class="fa-solid fa-circle-xmark me-1"></i>Rejected</span>';
                                    } else {
                                        $status_badge = '<span class="badge bg-warning-subtle text-warning border border-warning-subtle fw-bold text-dark"><i class="fa-solid fa-clock me-1"></i>Pending</span>';
                                    }

                                    $cat_badge = ($req['type'] === 'maintenance')
                                        ? '<span class="badge bg-warning text-dark border"><i class="fa-solid fa-wrench me-1"></i>Maintenance</span>'
                                        : '<span class="badge bg-primary text-white"><i class="fa-solid fa-box-open me-1"></i>Office</span>';

                                    $print_link = ($req['type'] === 'maintenance')
                                        ? 'print_maintenance_request.php?group_id=' . urlencode($req['request_group_id'])
                                        : 'print_request.php?group_id=' . urlencode($req['request_group_id']);
                                    ?>
                                    <tr>
                                        <td class="fw-bold text-logo-blue">#<?= htmlspecialchars($req['request_group_id']) ?></td>
                                        <td><?= $cat_badge ?></td>
                                        <td class="fw-semibold text-dark"><?= htmlspecialchars($req['requisitioner_name']) ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($req['department']) ?></span></td>
                                        <td><span class="badge bg-secondary rounded-pill"><?= $req['total_items'] ?> item(s)</span></td>
                                        <td><?= $status_badge ?></td>
                                        <td class="small text-secondary"><?= date('M d, Y h:i A', strtotime($req['created_at'])) ?></td>
                                        <td class="text-end">
                                            <?php if ($st === 'Approved'): ?>
                                                <a href="<?= $print_link ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3 fw-bold">
                                                    <i class="fa-solid fa-print me-1"></i> Print Voucher
                                                </a>
                                            <?php elseif ($st === 'Pending'): ?>
                                                <button class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold" onclick="openViewRequestModal('<?= htmlspecialchars($req['request_group_id'], ENT_QUOTES) ?>', '<?= $req['type'] ?>')">
                                                    <i class="fa-solid fa-pen-to-square me-1"></i> Review
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="fa-solid fa-folder-open fs-3 d-block mb-2 text-secondary"></i>
                                        Walang nakatagong kasaysayan ng mga order requests.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab 4: Stock Update History -->
        <div class="tab-pane fade <?= $is_stock_hist ? 'show active' : '' ?>" id="stock-hist">
            <div class="card p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="fa-solid fa-clock-rotate-left text-logo-blue me-2"></i>Stock Update & Inbound History</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <form method="GET" class="d-flex gap-2">
                            <select name="stock_cat" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                                <option value="all" <?= $stock_cat === 'all' ? 'selected' : '' ?>>All Categories</option>
                                <option value="office" <?= $stock_cat === 'office' ? 'selected' : '' ?>>Office Supplies</option>
                                <option value="maintenance" <?= $stock_cat === 'maintenance' ? 'selected' : '' ?>>Maintenance Supplies</option>
                            </select>
                            <select name="stock_time" class="form-select form-select-sm fw-semibold" onchange="this.form.submit()">
                                <option value="all" <?= $stock_time === 'all' ? 'selected' : '' ?>>All Time</option>
                                <option value="today" <?= $stock_time === 'today' ? 'selected' : '' ?>>Today</option>
                                <option value="7days" <?= $stock_time === '7days' ? 'selected' : '' ?>>Past 7 Days</option>
                                <option value="month" <?= $stock_time === 'month' ? 'selected' : '' ?>>This Month</option>
                            </select>
                        </form>
                        <a href="in_stock.php" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold text-nowrap">
                            <i class="fa-solid fa-arrow-up-right-from-square me-1"></i> View Full History
                        </a>
                    </div>
                </div>
                <div class="table-responsive table-container">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Date & Time</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Previous Stock</th>
                                <th>New Stock</th>
                                <th>Change</th>
                                <th>Updated By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $count = 1;
                            if ($stock_history && $stock_history->num_rows > 0):
                                while($row = $stock_history->fetch_assoc()):
                                    $diff = $row['added_qty'];
                                    if ($diff > 0) {
                                        $diff_badge = '<span class="badge bg-success-subtle text-success border border-success-subtle fw-bold">+' . $diff . '</span>';
                                    } elseif ($diff < 0) {
                                        $diff_badge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold">' . $diff . '</span>';
                                    } else {
                                        $diff_badge = '<span class="badge bg-secondary-subtle text-secondary border fw-bold">0</span>';
                                    }

                                    $cat_badge = ($row['category'] === 'Maintenance')
                                        ? '<span class="badge bg-warning text-dark border"><i class="fa-solid fa-wrench me-1"></i>Maintenance</span>'
                                        : '<span class="badge bg-primary text-white"><i class="fa-solid fa-box-open me-1"></i>Office</span>';
                            ?>
                                <tr>
                                    <td class="text-muted small"><?= $count++ ?></td>
                                    <td class="small text-secondary"><?= date('M d, Y h:i A', strtotime($row['updated_at'])) ?></td>
                                    <td><strong class="text-dark"><?= htmlspecialchars($row['item_name']) ?></strong></td>
                                    <td><?= $cat_badge ?></td>
                                    <td class="fw-semibold text-muted"><?= $row['previous_stock'] ?></td>
                                    <td class="fw-bold fs-6 text-dark"><?= $row['new_stock'] ?></td>
                                    <td><?= $diff_badge ?></td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['updated_by']) ?></span></td>
                                </tr>
                            <?php
                                endwhile;
                            else:
                            ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">
                                        <i class="fa-solid fa-folder-open fs-3 d-block mb-2 text-secondary"></i>
                                        Walang nakatagong kasaysayan ng pag-update ng stock.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal para sa Edit & Review Request Items -->
<div class="modal fade" id="viewRequestModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header text-white" style="background-color: var(--logo-blue);">
        <h5 class="modal-title fs-6 fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit & Review Order Items</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <div id="viewRequestModalBody">
            <div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fs-3 text-primary"></i> <p class="mt-2 text-muted">Loading request details...</p></div>
        </div>
      </div>
      <div class="modal-footer border-0 bg-light justify-content-between">
        <button type="button" class="btn btn-outline-danger rounded-pill px-3" onclick="submitRejectRequest()">
            <i class="fa-solid fa-xmark me-1"></i> Reject Order
        </button>

        <button type="submit" form="editRequestItemsForm" class="btn btn-success rounded-pill px-4" onclick="$('#modal_action_type').val('Approved'); return confirm('I-approve na ang order na ito?');">
            <i class="fa-solid fa-check me-1"></i> Approve Order
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    if (window.Notification && Notification.permission !== "granted") {
        Notification.requestPermission();
    }
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(err => console.log('SW registration failed:', err));
    }
});

function openViewRequestModal(groupId, type) {
    $('#viewRequestModalBody').html('<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fs-3 text-primary"></i> <p class="mt-2 text-muted">Loading request details...</p></div>');
    new bootstrap.Modal(document.getElementById('viewRequestModal')).show();

    $.ajax({
        url: window.location.pathname + '?fetch_request_details=1&group_id=' + encodeURIComponent(groupId) + '&type=' + encodeURIComponent(type),
        type: 'GET',
        success: function(data) {
            $('#viewRequestModalBody').html(data);
        },
        error: function() {
            $('#viewRequestModalBody').html('<p class="text-center text-danger">Nabigong i-load ang mga detalye ng request.</p>');
        }
    });
}

function submitRejectRequest() {
    if (confirm('Sigurado ka bang i-reject ang buong order na ito?')) {
        $('#modal_action_type').val('Rejected');
        $('#editRequestItemsForm').submit();
    }
}

$(document).on('submit', '.ajax-form', function(e) {
    e.preventDefault();
    var form = this;
    var formData = new FormData(form);

    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        data: formData,
        dataType: 'json',
        processData: false,
        contentType: false,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        success: function(response) {
            if (typeof response === 'string') {
                try { response = JSON.parse(response); } catch(err) {}
            }
            if (response && response.success) {
                $('.modal').modal('hide');
                $('#alert-container').html(`
                    <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3 mb-4" role="alert">
                        <i class="fa-solid fa-circle-check me-2"></i> ${response.message || 'Matagumpay na na-update!'}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `);
                pollRequests();
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                var errorMsg = (response && response.message) ? response.message : 'Nagkaroon ng problema sa pag-update.';
                alert(errorMsg);
            }
        },
        error: function() {
            alert('May naganap na error sa koneksyon o server.');
        }
    });
});

let lastOfficeCount = null;
let lastMaintCount = null;

function triggerDesktopNotification(title, message) {
    if (window.Notification && Notification.permission === "granted") {
        new Notification(title, {
            body: message,
            icon: "https://cdn-icons-png.flaticon.com/512/3233/3233483.png"
        });
    }

    var audio = new Audio('https://assets.mixkit.co/active_storage/sfx/2869/2869-preview.mp3');
    audio.play().catch(e => console.log('Audio autoplay restricted by browser'));
}

function pollRequests() {
    $.ajax({
        url: window.location.pathname + '?fetch_requests=1&type=office',
        type: 'GET',
        success: function(data) {
            $('#office-requests-tbody').html(data);

            let tempDiv = $('<div>').html(data);
            let currentCount = tempDiv.find('tr').length;
            if (tempDiv.find('td[colspan]').length > 0) currentCount = 0;

            $('#office-tab-badge').text(currentCount);

            if (lastOfficeCount !== null && currentCount > lastOfficeCount) {
                triggerDesktopNotification("Bagong Office Order!", "May pumasok na bagong order para sa Office Supplies.");
            }
            lastOfficeCount = currentCount;
        }
    });

    $.ajax({
        url: window.location.pathname + '?fetch_requests=1&type=maintenance',
        type: 'GET',
        success: function(data) {
            $('#maint-requests-tbody').html(data);

            let tempDiv = $('<div>').html(data);
            let currentCount = tempDiv.find('tr').length;
            if (tempDiv.find('td[colspan]').length > 0) currentCount = 0;

            $('#maint-tab-badge').text(currentCount);

            if (lastMaintCount !== null && currentCount > lastMaintCount) {
                triggerDesktopNotification("Bagong Maintenance Order!", "May pumasok na bagong order para sa Maintenance.");
            }
            lastMaintCount = currentCount;
        }
    });
}

setInterval(pollRequests, 5000);
</script>
</body>
</html>