<?php
session_start();
require_once 'db.php';

// Verification ng Session at Role
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'user') {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    header("Location: index.php");
    exit;
}

$user_id = intval($_SESSION['user_id']);

// POST Handler para sa Borrow Request
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_borrow_request'])) {
    header('Content-Type: application/json');

    try {
        $requisitioner_name = trim($_POST['requisitioner_name'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '');
        $borrow_date = $_POST['borrow_date'] ?? date('Y-m-d');
        $expected_return_date = $_POST['expected_return_date'] ?? date('Y-m-d', strtotime('+3 days'));
        $scheduled_time = trim($_POST['scheduled_time'] ?? '09:00 AM - 10:00 AM');

        $item_names = $_POST['item_name'] ?? [];
        $quantities = $_POST['quantity'] ?? [];

        if (empty($requisitioner_name) || empty($department)) {
            throw new Exception("Mangyaring punan ang iyong buong pangalan at departamento.");
        }

        if (empty($item_names) || !is_array($item_names)) {
            throw new Exception("Mangyaring maglagay ng hihiraming item.");
        }

        $request_group_id = 'BRW-' . date('YmdHis') . '-' . rand(100, 999);
        $inserted_count = 0;

        $stmt = $conn->prepare("INSERT INTO borrow_requests (request_group_id, user_id, requisitioner_name, department, item_id, item_name, quantity, borrow_date, expected_return_date, scheduled_time, purpose) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)");

        foreach ($item_names as $idx => $iname) {
            $iname = trim($iname);
            $qty = intval($quantities[$idx] ?? 1);

            if (!empty($iname) && $qty > 0) {
                $stmt->bind_param("sisssissss", $request_group_id, $user_id, $requisitioner_name, $department, $iname, $qty, $borrow_date, $expected_return_date, $scheduled_time, $purpose);
                $stmt->execute();
                $inserted_count++;
            }
        }

        if ($inserted_count > 0) {
            echo json_encode([
                'status' => 'success',
                'message' => "Matagumpay na naipasa ang iyong borrow request ($request_group_id)!",
                'group_id' => $request_group_id
            ]);
            exit;
        } else {
            throw new Exception("Maglagay ng kahit isang valid na item na hihiramin.");
        }

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch default fullname
$user_stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$default_fullname = $user_stmt->get_result()->fetch_assoc()['fullname'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrow Equipment & Items - SIBTECH</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/place_order.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1b4f9c">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-ecommerce sticky-top">
    <div class="container px-4">
        <a class="navbar-brand fw-bold text-white d-flex align-items-center" href="user_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-2 border-white shadow-sm me-2" style="width: 38px;">
            <span>SIBTECH BORROW PORTAL</span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <a href="user_dashboard.php" class="btn btn-outline-light btn-sm fw-semibold rounded-pill px-3">
                <i class="bi bi-grid-fill me-1"></i> Supply Store
            </a>
            <a href="user_schedule.php" class="btn btn-outline-light btn-sm fw-semibold rounded-pill px-3">
                <i class="bi bi-calendar-event me-1"></i> My Calendar
            </a>
            <a href="request_history.php" class="btn btn-outline-light btn-sm fw-semibold rounded-pill px-3">
                <i class="bi bi-bag-check-fill me-1"></i> My Requests
            </a>
            <a href="logout.php" class="btn btn-outline-light btn-sm fw-semibold rounded-pill px-3 ms-2">
                <i class="bi bi-box-arrow-right me-1"></i> Logout
            </a>
        </div>
    </div>
</nav>

<div class="container py-4" style="max-width: 900px;">
    <div id="alert-box" class="alert d-none shadow-sm rounded-3 mb-4"></div>

    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-logo-blue text-white p-4" style="background-color: #1b4f9c;">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h4 class="fw-extrabold mb-1"><i class="bi bi-hand-holding-box me-2"></i>Borrow Equipment / Items Request</h4>
                    <p class="mb-0 text-white-50 small">I-type at itala ang mga gamit o kagamitan na nais mong hiramin mula sa Admin.</p>
                </div>
                <span class="badge bg-warning text-dark fw-bold px-3 py-2 rounded-pill"><i class="bi bi-shield-lock me-1"></i>Official Borrower Requisition</span>
            </div>
        </div>

        <div class="card-body p-4">
            <form id="borrowForm">
                <input type="hidden" name="submit_borrow_request" value="1">

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-dark">Requisitioner Full Name</label>
                        <input type="text" name="requisitioner_name" class="form-control fw-semibold" value="<?= htmlspecialchars($default_fullname) ?>" required placeholder="Full Name">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-dark">Department / Office</label>
                        <input type="text" name="department" class="form-control fw-semibold" required placeholder="e.g. SPMO, HR, IT, Faculty">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-dark">Borrow Start Date</label>
                        <input type="date" name="borrow_date" class="form-control fw-semibold" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-dark">Expected Return Date</label>
                        <input type="date" name="expected_return_date" class="form-control fw-semibold" value="<?= date('Y-m-d', strtotime('+3 days')) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-dark">Preferred Time Slot</label>
                        <select name="scheduled_time" class="form-select fw-semibold" required>
                            <option value="08:00 AM - 09:00 AM">08:00 AM - 09:00 AM (Early Morning)</option>
                            <option value="09:00 AM - 10:00 AM" selected>09:00 AM - 10:00 AM (Morning Slot 1)</option>
                            <option value="10:00 AM - 11:00 AM">10:00 AM - 11:00 AM (Morning Slot 2)</option>
                            <option value="11:00 AM - 12:00 PM">11:00 AM - 12:00 PM (Late Morning)</option>
                            <option value="01:00 PM - 02:00 PM">01:00 PM - 02:00 PM (Early Afternoon)</option>
                            <option value="02:00 PM - 03:00 PM">02:00 PM - 03:00 PM (Afternoon Slot 1)</option>
                            <option value="03:00 PM - 04:00 PM">03:00 PM - 04:00 PM (Afternoon Slot 2)</option>
                            <option value="04:00 PM - 05:00 PM">04:00 PM - 05:00 PM (Late Afternoon)</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label fw-semibold text-dark">Purpose of Borrowing</label>
                        <input type="text" name="purpose" class="form-control fw-semibold" required placeholder="e.g. Event setup, Class presentation, Maintenance project">
                    </div>
                </div>

                <hr class="my-4 text-secondary opacity-25">

                <!-- BORROW ITEMS LIST -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-list-check text-primary me-2"></i>Mga Hihiraming Items / Equipment</h5>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill fw-bold px-3" onclick="addBorrowItemRow()">
                        <i class="bi bi-plus-circle me-1"></i> Dagdag Hihiraming Item
                    </button>
                </div>

                <div class="table-responsive rounded-3 border mb-4">
                    <table class="table table-hover align-middle mb-0" id="borrowItemsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Item / Equipment Description</th>
                                <th style="width: 140px;">Quantity</th>
                                <th class="text-end" style="width: 100px;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="borrow-items-tbody">
                            <tr>
                                <td>
                                    <input type="text" name="item_name[]" class="form-control fw-semibold" placeholder="i-type ang pangalan ng gamit (e.g. Projector, Extension Wire, Sound System)" required>
                                </td>
                                <td>
                                    <input type="number" name="quantity[]" class="form-control text-center fw-bold" value="1" min="1" required>
                                </td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="removeRow(this)">
                                        <i class="bi bi-trash-fill"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-2">
                    <a href="user_dashboard.php" class="btn btn-light rounded-pill px-4 fw-bold text-dark">
                        <i class="bi bi-arrow-left me-1"></i> Bumalik sa Dashboard
                    </a>
                    <button type="submit" id="submitBtn" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow-sm" style="background-color: #1b4f9c;">
                        <i class="bi bi-send-fill me-2"></i> Submit Borrow Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function addBorrowItemRow() {
    const tbody = document.getElementById('borrow-items-tbody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td>
            <input type="text" name="item_name[]" class="form-control fw-semibold" placeholder="i-type ang pangalan ng gamit" required>
        </td>
        <td>
            <input type="number" name="quantity[]" class="form-control text-center fw-bold" value="1" min="1" required>
        </td>
        <td class="text-end">
            <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="removeRow(this)">
                <i class="bi bi-trash-fill"></i>
            </button>
        </td>`;
    tbody.appendChild(tr);
}

function removeRow(btn) {
    const tbody = document.getElementById('borrow-items-tbody');
    if (tbody.children.length > 1) {
        btn.closest('tr').remove();
    } else {
        alert('Kailangan ng kahit isang hihiraming item.');
    }
}

document.getElementById('borrowForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const alertBox = document.getElementById('alert-box');

    fetch('borrow_items.php', { method: 'POST', body: new FormData(this) })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            alertBox.className = 'alert alert-success shadow-sm rounded-3 fw-bold';
            alertBox.textContent = data.message;
            alertBox.classList.remove('d-none');
            this.reset();
            setTimeout(() => {
                window.location.href = 'request_history.php';
            }, 1500);
        } else {
            alertBox.className = 'alert alert-danger shadow-sm rounded-3 fw-bold';
            alertBox.textContent = data.message;
            alertBox.classList.remove('d-none');
        }
    });
});
</script>
</body>
</html>