<?php
// SakayPH - Official Booking Receipt & Chauffeur Voucher
require_once __DIR__ . '/config.php';
require_login(['client', 'driver', 'admin']);

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

if ($booking_id <= 0) {
    die("Invalid Booking ID.");
}

// Fetch booking details
$booking = null;
if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, t.origin, t.destination, t.departure_time, t.vehicle_type, t.price_total,
                   c.name AS client_name, c.phone AS client_phone, c.email AS client_email,
                   d.name AS driver_name, d.phone AS driver_phone,
                   v.brand, v.model, v.plate_number, v.capacity
            FROM bookings b
            JOIN trips t ON b.trip_id = t.id
            JOIN users c ON b.client_id = c.id
            JOIN users d ON t.driver_id = d.id
            JOIN vehicles v ON d.id = v.driver_id
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

if (!$booking) {
    die("Booking not found.");
}

// Security Check: Only the client who booked, the driver, or the admin can view this receipt
if ($user_role === 'client' && $booking['client_id'] != $user_id) {
    die("Access denied.");
}
if ($user_role === 'driver' && $booking['driver_id'] != $user_id) {
    die("Access denied.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SakayPH_Receipt_#<?php echo $booking['id']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
        }
        .receipt-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.05);
            max-width: 700px;
            margin: 40px auto;
            padding: 40px;
        }
        .receipt-header {
            border-bottom: 2px dashed #e2e8f0;
            padding-bottom: 24px;
            margin-bottom: 24px;
        }
        .brand-logo {
            font-weight: 800;
            font-size: 1.8rem;
            color: #6366f1;
            letter-spacing: -0.5px;
        }
        .brand-logo span {
            color: #0f172a;
        }
        .status-badge {
            background-color: #ecfdf5;
            color: #059669;
            font-weight: 700;
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        .section-title {
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #64748b;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 6px;
            margin-bottom: 12px;
        }
        .detail-label {
            color: #64748b;
            font-size: 0.85rem;
        }
        .detail-value {
            font-weight: 600;
            color: #0f172a;
        }
        .total-box {
            background-color: #f1f5f9;
            border-radius: 12px;
            padding: 20px;
            text-align: right;
        }
        .total-amount {
            font-size: 1.8rem;
            font-weight: 800;
            color: #6366f1;
        }
        .print-btn-container {
            max-width: 700px;
            margin: 0 auto;
            text-align: center;
        }
        @media print {
            body {
                background-color: #ffffff;
            }
            .receipt-card {
                border: none;
                box-shadow: none;
                margin: 0;
                padding: 0;
                max-width: 100%;
            }
            .print-btn-container, .navbar-custom, footer {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    <div class="print-btn-container mt-4">
        <button onclick="window.print();" class="btn btn-primary px-4 py-2 rounded-3 shadow">
            <i class="bi bi-printer-fill me-2"></i>Print / Download Receipt as PDF
        </button>
        <a href="<?php echo BASE_URL . $user_role; ?>/dashboard.php" class="btn btn-outline-secondary px-4 py-2 rounded-3 ms-2">
            Back to Dashboard
        </a>
    </div>

    <div class="receipt-card">
        <!-- Receipt Header -->
        <div class="receipt-header d-flex justify-content-between align-items-center">
            <div>
                <div class="brand-logo">Sakay<span>PH</span></div>
                <p class="text-muted small mb-0">Premium Chauffeur & Private Car Rental Marketplace</p>
            </div>
            <div class="text-end">
                <span class="status-badge"><?php echo htmlspecialchars($booking['status']); ?></span>
                <p class="small text-muted mt-2 mb-0">Receipt #<?php echo $booking['id']; ?></p>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="section-title">Passenger Information</div>
                <p class="mb-1"><span class="detail-label">Name:</span> <span class="detail-value"><?php echo htmlspecialchars($booking['client_name']); ?></span></p>
                <p class="mb-1"><span class="detail-label">Phone:</span> <span class="detail-value"><?php echo htmlspecialchars($booking['client_phone']); ?></span></p>
                <p class="mb-0"><span class="detail-label">Email:</span> <span class="detail-value"><?php echo htmlspecialchars($booking['client_email']); ?></span></p>
            </div>
            <div class="col-md-6 mt-4 mt-md-0">
                <div class="section-title">Chauffeur & Vehicle Details</div>
                <p class="mb-1"><span class="detail-label">Driver Name:</span> <span class="detail-value"><?php echo htmlspecialchars($booking['driver_name']); ?></span></p>
                <p class="mb-1"><span class="detail-label">Vehicle Type:</span> <span class="detail-value"><?php echo htmlspecialchars($booking['brand'] . ' ' . $booking['model']); ?></span></p>
                <p class="mb-0"><span class="detail-label">Plate Number:</span> <span class="detail-value"><?php echo htmlspecialchars($booking['plate_number']); ?></span></p>
            </div>
        </div>

        <div class="mb-4">
            <div class="section-title">Trip Route & Schedule</div>
            <div class="card bg-light border-0 p-3 rounded-3">
                <div class="row">
                    <div class="col-md-6 mb-2 mb-md-0">
                        <small class="text-muted d-block">Origin (Pick-up point)</small>
                        <span class="fw-bold text-dark"><i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo htmlspecialchars($booking['origin']); ?></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Destination (Drop-off point)</small>
                        <span class="fw-bold text-dark"><i class="bi bi-pin-map-fill text-success me-1"></i><?php echo htmlspecialchars($booking['destination']); ?></span>
                    </div>
                </div>
                <hr class="my-2 border-secondary opacity-25">
                <div class="row">
                    <div class="col-md-6">
                        <small class="text-muted d-block">Scheduled Departure Time</small>
                        <span class="fw-bold text-dark"><i class="bi bi-calendar-event me-1 text-primary"></i><?php echo date('F d, Y - h:i A', strtotime($booking['departure_time'])); ?></span>
                    </div>
                    <div class="col-md-6">
                        <small class="text-muted d-block">Service Type</small>
                        <span class="fw-bold text-dark"><i class="bi bi-person-fill text-warning me-1"></i>Chauffeur-Driven (Chauffeur Included)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Box -->
        <div class="total-box">
            <span class="detail-label d-block mb-1">Total Amount Paid</span>
            <span class="total-amount"><?php echo format_peso($booking['amount_paid']); ?></span>
            <small class="text-muted d-block mt-1">Paid securely via SakayPH E-Wallet Gateway</small>
        </div>

        <div class="text-center mt-4">
            <p class="text-muted" style="font-size: 0.75rem;">This is a system-generated document. For inquiries, you may contact support at support@sakayph.com</p>
        </div>
    </div>

</body>
</html>
