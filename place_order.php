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

// --- AJAX HANDLER PARA SA SUBMIT ORDER REQUEST ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['request_supply'])) {
    header('Content-Type: application/json');

    $request_type = $_POST['request_type'] ?? 'office';
    $requisitioner_name = trim($_POST['requisitioner_name'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $purpose = trim($_POST['purpose'] ?? '');
    $date_needed = $_POST['date_needed'] ?? null;
    $scheduled_time = trim($_POST['scheduled_time'] ?? '09:00 AM - 10:00 AM');

    if ($request_type === 'document_printing') {
        try {
            $paper_size = $_POST['paper_size'] ?? 'A4';
            $print_color = $_POST['print_color'] ?? 'Black & White';
            $print_sides = $_POST['print_sides'] ?? 'Single-sided';
            $binding_option = $_POST['binding_option'] ?? 'None';
            $page_count = intval($_POST['page_count'] ?? 1);
            $copies = intval($_POST['copies'] ?? 1);
            $total_price = floatval($_POST['total_price'] ?? 0);

            $document_file = '';
            if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['document_file']['tmp_name'];
                $fileName = $_FILES['document_file']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                $allowedExtensions = ['pdf', 'doc', 'docx', 'png', 'jpg', 'jpeg'];
                if (in_array($fileExtension, $allowedExtensions)) {
                    $uploadFileDir = 'uploads/';
                    if (!is_dir($uploadFileDir)) {
                        mkdir($uploadFileDir, 0755, true);
                    }
                    $newFileName = 'DOC-' . date('YmdHis') . '-' . rand(1000, 9999) . '.' . $fileExtension;
                    $dest_path = $uploadFileDir . $newFileName;

                    if (move_uploaded_file($fileTmpPath, $dest_path)) {
                        $document_file = $newFileName;
                    } else {
                        throw new Exception("Nagkaroon ng problema sa pag-upload ng file.");
                    }
                } else {
                    throw new Exception("Hindi pinahihintulutan ang format ng file na ito. Gumamit ng PDF, DOC, DOCX, PNG, o JPG.");
                }
            } else {
                throw new Exception("Mangyaring mag-upload ng document file na ipapaprint.");
            }

            $request_group_id = 'PRT-' . date('YmdHis') . '-' . rand(100, 999);

            $stmt = $conn->prepare("INSERT INTO document_printing_requests (user_id, request_group_id, requisitioner_name, department, document_file, paper_size, print_color, print_sides, binding_option, page_count, copies, total_price, purpose, date_needed, scheduled_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("issssssssiddsss", $user_id, $request_group_id, $requisitioner_name, $department, $document_file, $paper_size, $print_color, $print_sides, $binding_option, $page_count, $copies, $total_price, $purpose, $date_needed, $scheduled_time);

            if ($stmt->execute()) {
                echo json_encode([
                    'status' => 'success',
                    'message' => "Matagumpay na naisumite ang iyong Document Printing request ($request_group_id)!",
                    'group_id' => $request_group_id
                ]);
                exit;
            } else {
                throw new Exception("Hindi naipasa sa database ang request: " . $stmt->error);
            }

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    $item_ids = $_POST['item_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];

    if (!empty($item_ids) && is_array($item_ids)) {
        if ($request_type === 'borrow') {
            $prefix = 'BRW-';
            $request_group_id = $prefix . date('YmdHis') . '-' . rand(100, 999);
            $borrow_date = $_POST['borrow_date'] ?? $date_needed ?? date('Y-m-d');
            $expected_return_date = $_POST['expected_return_date'] ?? date('Y-m-d', strtotime('+3 days'));

            $stmt = $conn->prepare("INSERT INTO borrow_requests (request_group_id, user_id, requisitioner_name, department, item_id, quantity, borrow_date, expected_return_date, scheduled_time, purpose) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $check_stock_stmt = $conn->prepare("SELECT actual_stocks, item_name FROM items WHERE id = ?");

            $inserted_count = 0;
            $conn->begin_transaction();

            try {
                foreach ($item_ids as $index => $item_id) {
                    $item_id = intval($item_id);
                    $qty = intval($quantities[$index] ?? 0);

                    if ($item_id > 0 && $qty > 0) {
                        $check_stock_stmt->bind_param("i", $item_id);
                        $check_stock_stmt->execute();
                        $chk_res = $check_stock_stmt->get_result()->fetch_assoc();

                        if (!$chk_res || $chk_res['actual_stocks'] < $qty) {
                            $iname = $chk_res['item_name'] ?? 'Selected Item';
                            throw new Exception("Kulang ang available stock para sa hihiraming item na: " . $iname);
                        }

                        $stmt->bind_param("sissiissss", $request_group_id, $user_id, $requisitioner_name, $department, $item_id, $qty, $borrow_date, $expected_return_date, $scheduled_time, $purpose);
                        $stmt->execute();
                        $inserted_count++;
                    }
                }

                if ($inserted_count > 0) {
                    $conn->commit();
                    echo json_encode([
                        'status' => 'success',
                        'message' => "Matagumpay na naisumite ang iyong Borrow request ($request_group_id)!",
                        'group_id' => $request_group_id
                    ]);
                    exit;
                } else {
                    throw new Exception("Walang valid na item na naisumite.");
                }
            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
        } else {
            $prefix = ($request_type === 'maintenance') ? 'MNT-' : 'REQ-';
            $request_group_id = $prefix . date('YmdHis') . '-' . rand(100, 999);

            $request_table = ($request_type === 'maintenance') ? 'maintenance_requests' : 'supply_requests';
            $item_table = ($request_type === 'maintenance') ? 'maintenance_items' : 'items';

            $stmt = $conn->prepare("INSERT INTO {$request_table} (request_group_id, user_id, requisitioner_name, department, item_id, quantity, purpose, date_needed, scheduled_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $check_stock_stmt = $conn->prepare("SELECT actual_stocks, item_name FROM {$item_table} WHERE id = ?");

            $inserted_count = 0;
            $conn->begin_transaction();

            try {
                foreach ($item_ids as $index => $item_id) {
                    $item_id = intval($item_id);
                    $qty = intval($quantities[$index] ?? 0);

                    if ($item_id > 0 && $qty > 0) {
                        $check_stock_stmt->bind_param("i", $item_id);
                        $check_stock_stmt->execute();
                        $chk_res = $check_stock_stmt->get_result()->fetch_assoc();

                        if (!$chk_res || $chk_res['actual_stocks'] < $qty) {
                            $iname = $chk_res['item_name'] ?? 'Selected Item';
                            throw new Exception("Kulang ang available stock para sa item na: " . $iname);
                        }

                        $stmt->bind_param("sississss", $request_group_id, $user_id, $requisitioner_name, $department, $item_id, $qty, $purpose, $date_needed, $scheduled_time);
                        $stmt->execute();
                        $inserted_count++;
                    }
                }

                if ($inserted_count > 0) {
                    $conn->commit();

                    echo json_encode([
                        'status' => 'success',
                        'message' => "Matagumpay na naisumite ang iyong " . ucfirst($request_type) . " request ($request_group_id)!",
                        'group_id' => $request_group_id
                    ]);
                    exit;
                } else {
                    throw new Exception("Walang valid na item na naisumite.");
                }

            } catch (Exception $e) {
                $conn->rollback();
                echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
                exit;
            }
        }
    }

    echo json_encode(['status' => 'error', 'message' => 'Pumili ng hindi bababa sa isang item at ilagay ang dami.']);
    exit;
}

// Fetch user info
$user_stmt = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$default_fullname = $user_stmt->get_result()->fetch_assoc()['fullname'] ?? '';

// Fetch available items
$office_items = $conn->query("SELECT * FROM items WHERE actual_stocks > 0 ORDER BY item_name ASC")->fetch_all(MYSQLI_ASSOC);
$maint_items = $conn->query("SELECT * FROM maintenance_items WHERE actual_stocks > 0 ORDER BY item_name ASC")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Place Order Request - SIBTECH</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link rel="stylesheet" href="css/place_order.css">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#1b4f9c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-ecommerce sticky-top">
    <div class="container px-4">
        <a class="navbar-brand fw-bold text-white d-flex align-items-center" href="user_dashboard.php">
            <img src="logo.jpg" alt="SIBTECH Logo" class="navbar-brand-logo rounded-circle border border-2 border-white shadow-sm me-2" style="width: 38px;">
            <span>SIBTECH SUPPLY ROOM <span class="fw-light opacity-75"></span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <a href="user_dashboard.php" class="btn btn-outline-light btn-sm fw-semibold rounded-pill px-3">
                <i class="bi bi-grid-fill me-1"></i> Supply Room
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

<div class="container py-4" style="max-width: 960px;">
    <div id="alert-box" class="alert d-none shadow-sm rounded-3 mb-4"></div>

    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
        <div class="card-header bg-logo-blue text-white p-4">
            <div class="d-flex align-items-center justify-content-between">
                <div>
                    <h4 class="fw-extrabold mb-1"><i class="bi bi-cart-check-fill me-2"></i>Place Supply Request</h4>
                    <p class="mb-0 text-white-50 small">Please complete your information below.</p>
                </div>
                <span class="badge bg-warning text-dark fw-bold px-3 py-2 rounded-pill"><i class="bi bi-shield-check me-1"></i>Official Requisition</span>
            </div>
        </div>

        <div class="card-body p-4">
            <form id="placeOrderForm" enctype="multipart/form-data">
                <input type="hidden" name="request_supply" value="1">

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-dark">Request Category</label>
                        <select name="request_type" id="request_type" class="form-select fw-semibold" onchange="onCategoryChange()">
                            <option value="office">Office Supplies Requisition</option>
                            <option value="maintenance">Maintenance Supplies Requisition</option>
                            <option value="document_printing">Document Printing Requisition</option>
                            <option value="borrow">Borrow Equipment / Items Requisition</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold text-dark">Requisitioner Full Name</label>
                        <input type="text" name="requisitioner_name" class="form-control fw-semibold" value="<?= htmlspecialchars($default_fullname) ?>" required placeholder=Full Name>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-dark">Department</label>
                        <input type="text" name="department" class="form-control fw-semibold" required placeholder=" SPMO, HR, IT">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-dark">Purpose of Request</label>
                        <input type="text" name="purpose" class="form-control fw-semibold" required placeholder="">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold text-dark">Scheduled Date Needed</label>
                        <input type="date" name="date_needed" class="form-control fw-semibold" required>
                    </div>
                    <div class="col-md-12 d-none" id="borrow_dates_section">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark"><i class="bi bi-calendar-event me-1 text-primary"></i>Borrow Start Date</label>
                                <input type="date" name="borrow_date" id="borrow_date" class="form-control fw-semibold">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold text-dark"><i class="bi bi-calendar-check me-1 text-primary"></i>Expected Return Date</label>
                                <input type="date" name="expected_return_date" id="expected_return_date" class="form-control fw-semibold">
                            </div>
                        </div>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label fw-semibold text-dark"><i class="bi bi-clock-history me-1 text-primary"></i>Scheduled Time Slot / Preferred Pickup</label>
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
                </div>

                <hr class="my-4 text-secondary opacity-25">

                <!-- DOCUMENT PRINTING SECTION -->
                <div id="printing_section" class="d-none">
                    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-printer-fill text-primary me-2"></i>Document Printing Details</h5>

                    <div class="row g-3 mb-3">
                        <div class="col-md-12">
                            <label class="form-label fw-semibold text-dark">Upload Document File (PDF, DOCX, PNG, JPG)</label>
                            <input type="file" name="document_file" id="document_file" class="form-control fw-semibold" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-dark">Paper Size</label>
                            <select name="paper_size" id="paper_size" class="form-select fw-semibold" onchange="calculatePrice()">
                                <option value="Short">Short (8.5 x 11)</option>
                                <option value="A4" selected>A4 (8.27 x 11.69)</option>
                                <option value="Long">Long (8.5 x 13)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-dark">Print Color</label>
                            <select name="print_color" id="print_color" class="form-select fw-semibold" onchange="calculatePrice()">
                                <option value="Black & White" selected>Black & White (₱2.00 / page)</option>
                                <option value="Colored">Colored (₱5.00 / page)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-dark">Print Side Option</label>
                            <select name="print_sides" id="print_sides" class="form-select fw-semibold" onchange="calculatePrice()">
                                <option value="Single-sided" selected>Single-sided</option>
                                <option value="Double-sided">Double-sided (10% discount)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-dark">Binding Option</label>
                            <select name="binding_option" id="binding_option" class="form-select fw-semibold" onchange="calculatePrice()">
                                <option value="None" selected>None (₱0.00)</option>
                                <option value="Stapled">Stapled (₱5.00)</option>
                                <option value="Ring Bound">Ring Bound (₱35.00)</option>
                                <option value="Hardbound">Hardbound (₱150.00)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-dark">Total Pages Per Copy</label>
                            <input type="number" name="page_count" id="page_count" class="form-control fw-semibold" value="1" min="1" oninput="calculatePrice()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-dark">Number of Copies</label>
                            <input type="number" name="copies" id="copies" class="form-control fw-semibold" value="1" min="1" oninput="calculatePrice()">
                        </div>
                    </div>

                    <div class="p-3 bg-light rounded-3 border d-flex align-items-center justify-content-between mb-4">
                        <span class="fw-bold text-dark fs-5"><i class="bi bi-calculator me-2 text-primary"></i>Estimated Total Price:</span>
                        <span class="fs-4 fw-extrabold text-primary" id="price_display">₱2.00</span>
                        <input type="hidden" name="total_price" id="total_price_input" value="2.00">
                    </div>
                </div>

                <!-- ADD ITEM CONTROLS -->
                <div id="supply_section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-box-seam text-primary me-2"></i>Request Items List</h5>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-pill fw-bold px-3" onclick="showAddItemModal()">
                        <i class="bi bi-plus-circle me-1"></i> Add Item
                    </button>
                </div>

                <div class="table-responsive rounded-3 border mb-4">
                    <table class="table table-hover align-middle mb-0" id="orderItemsTable">
                        <thead class="table-light">
                            <tr>
                                <th>Item Name</th>
                                <th>Unit</th>
                                <th>Available Stock</th>
                                <th style="width: 140px;">Quantity</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody id="order-items-tbody">
                            <tr id="empty-row">
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="bi bi-basket fs-3 d-block text-secondary mb-1"></i>
                                    No selected items please click<strong>Add Item</strong>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center pt-2">
                    <a href="user_dashboard.php" class="btn btn-light rounded-pill px-4 fw-bold text-dark">
                        <i class="bi bi-arrow-left me-1"></i> Back to select supply
                    </a>
                    <button type="submit" id="submitOrderBtn" class="btn btn-primary-logo btn-lg rounded-pill px-5 fw-bold shadow-sm" disabled>
                        <i class="bi bi-send-fill me-2"></i> Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL PARA SA PAGPILI NG ITEM -->
<div class="modal fade" id="addItemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-logo-blue text-white">
                <h5 class="modal-title fw-bold fs-6"><i class="bi bi-plus-circle me-2"></i>Pumili ng Item</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold text-dark">Pumili ng Available Item</label>
                    <select id="modal_item_select" class="form-select fw-semibold">
                        <!-- Populated by JS based on request_type -->
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold text-dark">Dami (Quantity)</label>
                    <input type="number" id="modal_item_qty" class="form-control fw-semibold" value="1" min="1">
                </div>
            </div>
            <div class="modal-footer bg-light border-0">
                <button type="button" class="btn btn-light rounded-pill px-3" data-bs-dismiss="modal">Kanselahin</button>
                <button type="button" class="btn btn-primary-logo rounded-pill px-4 fw-bold" onclick="addSelectedItemFromModal()">I-dagdag sa Order</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const officeItemsData = <?= json_encode($office_items) ?>;
const maintItemsData = <?= json_encode($maint_items) ?>;

let selectedItems = {};

function getActiveCatalog() {
    const type = document.getElementById('request_type').value;
    return (type === 'maintenance') ? maintItemsData : officeItemsData;
}

function calculatePrice() {
    const printColor = document.getElementById('print_color') ? document.getElementById('print_color').value : 'Black & White';
    const printSides = document.getElementById('print_sides') ? document.getElementById('print_sides').value : 'Single-sided';
    const bindingOption = document.getElementById('binding_option') ? document.getElementById('binding_option').value : 'None';
    const pageCount = Math.max(1, parseInt(document.getElementById('page_count')?.value) || 1);
    const copies = Math.max(1, parseInt(document.getElementById('copies')?.value) || 1);

    let perPageRate = (printColor === 'Colored') ? 5.00 : 2.00;
    let sideDiscount = (printSides === 'Double-sided') ? 0.90 : 1.00;

    let bindingFee = 0.00;
    if (bindingOption === 'Stapled') bindingFee = 5.00;
    else if (bindingOption === 'Ring Bound') bindingFee = 35.00;
    else if (bindingOption === 'Hardbound') bindingFee = 150.00;

    let total = ((perPageRate * sideDiscount * pageCount) + bindingFee) * copies;

    const displayElem = document.getElementById('price_display');
    const inputElem = document.getElementById('total_price_input');
    if (displayElem) displayElem.textContent = '₱' + total.toFixed(2);
    if (inputElem) inputElem.value = total.toFixed(2);
}

function onCategoryChange() {
    const category = document.getElementById('request_type').value;
    const printingSection = document.getElementById('printing_section');
    const supplySection = document.getElementById('supply_section');
    const borrowDatesSection = document.getElementById('borrow_dates_section');
    const submitBtn = document.getElementById('submitOrderBtn');

    if (borrowDatesSection) {
        if (category === 'borrow') {
            borrowDatesSection.classList.remove('d-none');
        } else {
            borrowDatesSection.classList.add('d-none');
        }
    }

    if (category === 'document_printing') {
        if (printingSection) printingSection.classList.remove('d-none');
        if (supplySection) supplySection.classList.add('d-none');
        if (submitBtn) submitBtn.disabled = false;
        calculatePrice();
    } else {
        if (printingSection) printingSection.classList.add('d-none');
        if (supplySection) supplySection.classList.remove('d-none');
        selectedItems = {};
        renderTable();
    }
}

function loadCartFromStorage() {
    try {
        const stored = localStorage.getItem('sibtech_cart');
        if (stored) {
            const parsed = JSON.parse(stored);
            if (parsed && typeof parsed === 'object') {
                selectedItems = parsed;
            }
        }
    } catch(e) {}
    renderTable();
}

function saveCartToStorage() {
    localStorage.setItem('sibtech_cart', JSON.stringify(selectedItems));
}

function renderTable() {
    const tbody = document.getElementById('order-items-tbody');
    const submitBtn = document.getElementById('submitOrderBtn');
    const keys = Object.keys(selectedItems);

    if (keys.length === 0) {
        tbody.innerHTML = `
            <tr id="empty-row">
                <td colspan="5" class="text-center text-muted py-4">
                    <i class="bi bi-basket fs-3 d-block text-secondary mb-1"></i>
                    Walang item na nakapaloob sa order. I-click ang <strong>Dagdag Item</strong> o pumili mula sa Supply Store.
                </td>
            </tr>`;
        submitBtn.disabled = true;
        saveCartToStorage();
        return;
    }

    submitBtn.disabled = false;
    let html = '';
    keys.forEach(id => {
        const item = selectedItems[id];
        html += `
            <tr>
                <input type="hidden" name="item_id[]" value="${item.id}">
                <td class="fw-semibold text-dark">${item.name}</td>
                <td><span class="badge bg-light text-dark border">${item.unit}</span></td>
                <td><span class="badge bg-success-subtle text-success border border-success-subtle fw-bold">${item.maxStock}</span></td>
                <td>
                    <input type="number" name="quantity[]" value="${item.qty}" min="1" max="${item.maxStock}" class="form-control form-control-sm text-center fw-bold" onchange="updateItemQty(${item.id}, this.value)">
                </td>
                <td class="text-end">
                    <button type="button" class="btn btn-outline-danger btn-sm rounded-pill px-3" onclick="removeItem(${item.id})">
                        <i class="bi bi-trash-fill me-1"></i> Remove
                    </button>
                </td>
            </tr>`;
    });
    tbody.innerHTML = html;
    saveCartToStorage();
}

function updateItemQty(id, val) {
    val = parseInt(val) || 1;
    if (selectedItems[id]) {
        if (val > selectedItems[id].maxStock) {
            alert(`Ang maximum available stock ay ${selectedItems[id].maxStock}`);
            val = selectedItems[id].maxStock;
        }
        selectedItems[id].qty = val;
    }
    renderTable();
}

function removeItem(id) {
    delete selectedItems[id];
    renderTable();
}

function showAddItemModal() {
    const catalog = getActiveCatalog();
    const select = document.getElementById('modal_item_select');
    select.innerHTML = '';

    if (catalog.length === 0) {
        select.innerHTML = '<option value="">Walang available na items</option>';
    } else {
        catalog.forEach(item => {
            select.innerHTML += `<option value="${item.id}">${item.item_name} (Stock: ${item.actual_stocks} ${item.unit})</option>`;
        });
    }
    document.getElementById('modal_item_qty').value = 1;
    new bootstrap.Modal(document.getElementById('addItemModal')).show();
}

function addSelectedItemFromModal() {
    const itemId = parseInt(document.getElementById('modal_item_select').value);
    const qty = parseInt(document.getElementById('modal_item_qty').value) || 1;
    const catalog = getActiveCatalog();

    const itemObj = catalog.find(i => i.id == itemId);
    if (itemObj) {
        if (selectedItems[itemId]) {
            selectedItems[itemId].qty += qty;
            if (selectedItems[itemId].qty > itemObj.actual_stocks) {
                selectedItems[itemId].qty = itemObj.actual_stocks;
            }
        } else {
            selectedItems[itemId] = {
                id: itemObj.id,
                name: itemObj.item_name,
                unit: itemObj.unit,
                qty: Math.min(qty, itemObj.actual_stocks),
                maxStock: itemObj.actual_stocks
            };
        }
        bootstrap.Modal.getInstance(document.getElementById('addItemModal')).hide();
        renderTable();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadCartFromStorage();

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(err => console.log('SW registration failed:', err));
    }
});

document.getElementById('placeOrderForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const alertBox = document.getElementById('alert-box');

    fetch('place_order.php', { method: 'POST', body: new FormData(this) })
    .then(res => res.json())
    .then(data => {
        if(data.status === 'success') {
            alertBox.className = 'alert alert-success shadow-sm rounded-3 fw-bold';
            alertBox.textContent = data.message;
            alertBox.classList.remove('d-none');

            selectedItems = {};
            localStorage.removeItem('sibtech_cart');
            renderTable();
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