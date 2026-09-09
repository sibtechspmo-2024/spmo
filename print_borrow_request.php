<?php
require_once 'db.php';

// Suriin kung naka-login ang user
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$group_id = trim($_GET['group_id'] ?? '');

if (empty($group_id)) {
    die("Invalid Request ID.");
}

// Kunin ang mga detalye ng borrow request
$stmt = $conn->prepare("
    SELECT r.*, u.fullname AS user_fullname, u.username
    FROM borrow_requests r
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.request_group_id = ?
");
$stmt->bind_param("s", $group_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Walang nahanap na record para sa Request ID na ito.");
}

$items = [];
$first_row = null;

while ($row = $result->fetch_assoc()) {
    if (!$first_row) {
        $first_row = $row;
    }
    $items[] = $row;
}

function render_form_copy($copy_type, $first_row, $items) {
    $borrower_name = !empty($first_row['requisitioner_name']) ? $first_row['requisitioner_name'] : $first_row['user_fullname'];
    $department = $first_row['department'] ?? '';
    $date_req = date('Y-m-d', strtotime($first_row['created_at']));
    $usage_datetime = $first_row['borrow_date'] . ' (' . ($first_row['scheduled_time'] ?? '') . ')';
    $date_returned = $first_row['expected_return_date'] ?? '';
    $purpose = $first_row['purpose'] ?? '';
    ?>
    <div class="form-copy">
        <div class="header-container">
            <img src="logo.jpg" alt="SIBTECH Logo" class="header-logo">
            <div class="header-text">
                <h4 class="mb-0 fw-bold">SOUTHWESTERN INSTITUTE OF BUSINESS AND TECHNOLOGY, INC.</h4>
                <p class="mb-0 sub-header">NAUTICAL HIGHWAY, PANGGULAYAN, PINAMALAYAN, ORIENTAL MINDORO</p>
                <p class="mb-0 sub-header">Contact Nos.: +63917-127-8500 | +63912-448-6518</p>
            </div>
        </div>

        <div class="form-title text-center fw-bold mt-2 mb-3">
            SCHOOL PROPERTY EQUIPMENT BORROWERS FORM
        </div>

        <div class="row align-items-center mb-2">
            <div class="col-4">
                <span class="copy-badge"><?= htmlspecialchars($copy_type) ?></span>
            </div>
            <div class="col-8 text-end">
                <span class="fw-bold">DATE:</span> <span class="border-bottom-line d-inline-block px-2" style="min-width: 150px; text-align: center;"><?= htmlspecialchars($date_req) ?></span>
            </div>
        </div>

        <div class="row mb-1">
            <div class="col-7">
                <span class="fw-bold">Borrower's Name:</span> <span class="border-bottom-line d-inline-block px-2" style="min-width: 200px;"><?= htmlspecialchars($borrower_name) ?></span>
            </div>
            <div class="col-5">
                <span class="fw-bold">I.D Number:</span> <span class="border-bottom-line d-inline-block px-2" style="min-width: 120px;"><?= htmlspecialchars($first_row['user_id']) ?></span>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-12">
                <span class="fw-bold">Department:</span> <span class="border-bottom-line d-inline-block px-2" style="min-width: 250px;"><?= htmlspecialchars($department) ?></span>
            </div>
        </div>

        <div class="text-center fw-bold mb-1 font-size-sm">LIST OF EQUIPMENT</div>
        <table class="form-table w-100 mb-3">
            <thead>
                <tr class="text-center fw-bold">
                    <th style="width: 8%;">No.</th>
                    <th style="width: 50%;">Description</th>
                    <th style="width: 12%;">Quantity</th>
                    <th style="width: 12%;">Unit</th>
                    <th style="width: 18%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $max_rows = 4;
                $no = 1;
                foreach ($items as $item):
                ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td><?= htmlspecialchars($item['item_name'] ?? 'Equipment') ?></td>
                        <td class="text-center"><?= htmlspecialchars($item['quantity']) ?></td>
                        <td class="text-center">unit(s)</td>
                        <td class="text-center"><?= htmlspecialchars($item['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php for ($i = count($items); $i < $max_rows; $i++): ?>
                    <tr>
                        <td class="text-center">&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                        <td>&nbsp;</td>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>

        <div class="row mb-1">
            <div class="col-6">
                <span class="fw-bold">Date and Time of Usage:</span> <span class="border-bottom-line d-inline-block px-1" style="width: 55%;"><?= htmlspecialchars($usage_datetime) ?></span>
            </div>
            <div class="col-6">
                <span class="fw-bold">Where will the equipment stationed?</span> <span class="border-bottom-line d-inline-block px-1" style="width: 40%;"><?= htmlspecialchars($department) ?></span>
            </div>
        </div>
        <div class="row mb-3">
            <div class="col-6">
                <span class="fw-bold">Date Returned:</span> <span class="border-bottom-line d-inline-block px-1" style="width: 65%;"><?= htmlspecialchars($date_returned) ?></span>
            </div>
            <div class="col-6">
                <span class="fw-bold">Activity:</span> <span class="border-bottom-line d-inline-block px-1" style="width: 75%;"><?= htmlspecialchars($purpose) ?></span>
            </div>
        </div>

        <div class="commitment-section text-center mb-4">
            <div class="fw-bold fst-italic mb-1">Borrower's Commitment:</div>
            <div class="fst-italic small">"I will be accountable to any damage incurred in the equipment and will return the equipment promptly and int the same working condition it was borrowed."</div>
        </div>

        <div class="row mt-4 pt-2">
            <div class="col-6 text-center">
                <div class="signature-line mx-auto" style="width: 80%;"></div>
                <div class="small fw-bold">Signature of Borrower</div>
            </div>
            <div class="col-6 text-center">
                <div class="fw-bold">MARK ANTHONY M. SALAZAR</div>
                <div class="signature-line mx-auto" style="width: 80%;"></div>
                <div class="small fw-bold">Verified by: I.T AND LOGISTICS SUPERVISOR</div>
            </div>
        </div>
    </div>
    <?php
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Equipment Borrower Form - <?= htmlspecialchars($group_id) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: Arial, sans-serif;
            font-size: 11pt;
            color: #000;
        }
        .page-container {
            max-width: 850px;
            margin: 20px auto;
            background: #fff;
            padding: 25px 35px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .header-container {
            display: flex;
            align-items: center;
            justify-content: center;
            border-bottom: 2px solid #000;
            padding-bottom: 8px;
            margin-bottom: 12px;
            position: relative;
        }
        .header-logo {
            width: 70px;
            height: 70px;
            position: absolute;
            right: 10px;
            top: 0;
            object-fit: contain;
        }
        .header-text {
            text-align: center;
        }
        .sub-header {
            font-size: 8.5pt;
        }
        .form-title {
            font-size: 13pt;
            letter-spacing: 0.5px;
        }
        .copy-badge {
            border: 1.5px solid #000;
            padding: 3px 8px;
            font-weight: bold;
            font-size: 9pt;
            display: inline-block;
        }
        .border-bottom-line {
            border-bottom: 1px solid #000;
        }
        .form-table {
            border-collapse: collapse;
        }
        .form-table th, .form-table td {
            border: 1px solid #000;
            padding: 5px 8px;
            font-size: 10pt;
        }
        .signature-line {
            border-top: 1px dashed #000;
            margin-top: 25px;
            margin-bottom: 5px;
        }
        .divider-line {
            border-top: 2px dashed #444;
            margin: 30px 0;
            position: relative;
        }
        .font-size-sm {
            font-size: 10pt;
        }

        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: #fff;
            }
            .page-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
                max-width: 100%;
            }
            .divider-line {
                margin: 20px 0;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <!-- Action Buttons -->
    <div class="no-print my-3 text-end">
        <button onclick="window.print()" class="btn btn-primary px-4"><i class="bi bi-printer me-1"></i> Print Form</button>
        <a href="user_dashboard.php" class="btn btn-outline-secondary px-3 ms-2">Bumalik sa Dashboard</a>
    </div>

    <div class="page-container">
        <!-- BORROWER'S COPY -->
        <?php render_form_copy("BORROWER'S COPY", $first_row, $items); ?>

        <div class="divider-line"></div>

        <!-- SPMO COPY -->
        <?php render_form_copy("SPMO COPY", $first_row, $items); ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>