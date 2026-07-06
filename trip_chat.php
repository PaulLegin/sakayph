<?php
// SakayPH - In-App Trip Chat Room
require_once __DIR__ . '/config.php';
require_login(['client', 'driver']);

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

if ($booking_id <= 0) {
    redirect($user_role . '/dashboard.php');
}

// 1. Ownership & Booking Validation (Anti-Bogus Protection)
$booking = null;
if ($pdo) {
    try {
        $stmt = $pdo->prepare("
            SELECT b.*, t.origin, t.destination, t.status AS trip_status, t.driver_id,
                   c.first_name AS client_name, d.first_name AS driver_name
            FROM bookings b
            JOIN trips t ON b.trip_id = t.id
            JOIN users c ON b.client_id = c.id
            JOIN users d ON t.driver_id = d.id
            WHERE b.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

// Redirect if booking doesn't exist
if (!$booking) {
    redirect($user_role . '/dashboard.php');
}

// Ensure the user is either the client or the driver of this specific booking
$is_client = ($user_id == $booking['client_id'] && $user_role === 'client');
$is_driver = ($user_id == $booking['driver_id'] && $user_role === 'driver');

if (!$is_client && !$is_driver) {
    // Bogus attempt - redirect immediately to their dashboard
    redirect($user_role . '/dashboard.php');
}

// Ensure booking is confirmed, completed, or in_progress (No chat for pending_payment/cancelled)
if (!in_array($booking['status'], ['confirmed', 'completed']) && !in_array($booking['trip_status'], ['booked', 'in_progress', 'completed'])) {
    $_SESSION['chat_error'] = 'Chat is only available for confirmed or active bookings.';
    redirect($user_role . '/dashboard.php');
}

// Mark messages as read for this user
try {
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE booking_id = ? AND sender_id != ?");
    $stmt->execute([$booking_id, $user_id]);
} catch (PDOException $e) {}

$other_party_name = $is_client ? $booking['driver_name'] : $booking['client_name'];
$trip_info = htmlspecialchars($booking['origin']) . ' to ' . htmlspecialchars($booking['destination']);
$is_chat_disabled = ($booking['status'] === 'completed' || $booking['trip_status'] === 'completed');

include_once __DIR__ . '/includes/header.php';
?>

<div class="container my-4">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            
            <!-- Chat Card Container -->
            <div class="card bg-dark border-0 shadow-lg rounded-4 overflow-hidden" style="min-height: 80vh; display: flex; flex-direction: column;">
                
                <!-- Chat Header -->
                <div class="card-header bg-primary bg-gradient border-0 p-3 d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <a href="<?php echo BASE_URL . $user_role; ?>/dashboard.php" class="text-white me-3 fs-5">
                            <i class="bi bi-arrow-left"></i>
                        </a>
                        <div>
                            <h6 class="text-white mb-0 fw-bold"><?php echo htmlspecialchars($other_party_name); ?></h6>
                            <small class="text-white-50" style="font-size: 0.75rem;"><i class="bi bi-geo-alt-fill me-1"></i><?php echo $trip_info; ?></small>
                        </div>
                    </div>
                    <div>
                        <?php if ($is_chat_disabled): ?>
                            <span class="badge bg-secondary rounded-pill">Completed (Read-Only)</span>
                        <?php else: ?>
                            <span class="badge bg-success rounded-pill">Active Trip Chat</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Chat Messages Body -->
                <div class="card-body p-3 overflow-auto flex-grow-1" id="chat-messages" style="background: rgba(15, 23, 42, 0.95); max-height: 55vh; min-height: 450px;">
                    <!-- Messages will load here dynamically via AJAX -->
                    <div class="text-center text-muted my-5" id="chat-loading">
                        <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                        <p class="small mb-0">Loading messages...</p>
                    </div>
                </div>

                <!-- Chat Input Footer -->
                <div class="card-footer bg-dark border-0 p-3" style="background: rgba(30, 41, 59, 1) !important;">
                    <?php if ($is_chat_disabled): ?>
                        <div class="alert alert-secondary text-center small mb-0 py-2 border-0 rounded-3 text-muted">
                            <i class="bi bi-lock-fill me-1"></i> This trip is completed. Chat history is read-only.
                        </div>
                    <?php else: ?>
                        <form id="chat-form" class="input-group">
                            <input type="text" id="chat-message-input" class="form-control bg-dark border-secondary text-white rounded-start-3" placeholder="Type your message here..." required autocomplete="off">
                            <button type="submit" class="btn btn-primary px-4 rounded-end-3">
                                <i class="bi bi-send-fill"></i>
                            </button>
                        </form>
                        <div id="rate-limit-warning" class="text-danger small mt-1 d-none">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Please wait before sending another message.
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </div>
    </div>
</div>

<!-- AJAX and Real-time polling Script -->
<script>
const bookingId = <?php echo $booking_id; ?>;
const currentUserId = <?php echo $user_id; ?>;
const chatMessages = document.getElementById('chat-messages');
const chatForm = document.getElementById('chat-form');
const messageInput = document.getElementById('chat-message-input');
const rateLimitWarning = document.getElementById('rate-limit-warning');

let isSending = false;
let lastMessageId = 0;

// Scroll chat window to bottom
function scrollToBottom() {
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// Format Time
function formatTime(timestamp) {
    const date = new Date(timestamp);
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

// Load messages via AJAX
function loadMessages() {
    fetch(`<?php echo BASE_URL; ?>helpers/chat_handler.php?action=fetch&booking_id=${bookingId}`)
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const loadingElement = document.getElementById('chat-loading');
                if (loadingElement) loadingElement.remove();

                let htmlContent = '';
                
                if (data.messages.length === 0) {
                    htmlContent = `
                        <div class="text-center text-muted my-5">
                            <i class="bi bi-chat-dots fs-1 mb-2"></i>
                            <p class="small mb-0">No messages yet. Send a message to start chatting!</p>
                        </div>
                    `;
                } else {
                    data.messages.forEach(msg => {
                        const isMe = msg.sender_id == currentUserId;
                        const messageAlign = isMe ? 'justify-content-end' : 'justify-content-start';
                        const bubbleClass = isMe ? 'bg-primary text-white rounded-start-4 rounded-top-4' : 'bg-secondary bg-opacity-25 text-white rounded-end-4 rounded-top-4';
                        
                        htmlContent += `
                            <div class="d-flex ${messageAlign} mb-3">
                                <div class="p-3 ${bubbleClass} max-w-75 shadow-sm" style="max-width: 80%;">
                                    <p class="mb-1 small text-break">${escapeHtml(msg.message)}</p>
                                    <div class="text-end" style="font-size: 0.65rem; opacity: 0.7;">
                                        ${formatTime(msg.created_at)}
                                        ${isMe ? (msg.is_read == 1 ? '<i class="bi bi-check2-all text-info ms-1"></i>' : '<i class="bi bi-check2 ms-1"></i>') : ''}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                }

                // Only update DOM if the content has changed to prevent scroll jumping
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = htmlContent;
                if (chatMessages.innerHTML !== tempDiv.innerHTML) {
                    chatMessages.innerHTML = htmlContent;
                    scrollToBottom();
                }
            }
        })
        .catch(err => console.error("Error loading chat messages: ", err));
}

// Escape HTML utility
function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Send Message
if (chatForm) {
    chatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const msgText = messageInput.value.trim();
        if (msgText === '' || isSending) return;

        isSending = true;
        
        fetch('<?php echo BASE_URL; ?>helpers/chat_handler.php?action=send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `booking_id=${bookingId}&message=${encodeURIComponent(msgText)}`
        })
        .then(response => response.json())
        .then(data => {
            isSending = false;
            if (data.status === 'success') {
                messageInput.value = '';
                rateLimitWarning.classList.add('d-none');
                loadMessages();
            } else if (data.status === 'rate_limit') {
                rateLimitWarning.classList.remove('d-none');
                setTimeout(() => { rateLimitWarning.classList.add('d-none'); }, 3000);
            } else {
                alert(data.message || 'Failed to send message.');
            }
        })
        .catch(err => {
            isSending = false;
            console.error("Error sending message: ", err);
        });
    });
}

// Load initially and poll every 3 seconds for near real-time chat
loadMessages();
const pollInterval = setInterval(loadMessages, 3000);

// Stop polling when page is closed or hidden
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        clearInterval(pollInterval);
    } else {
        loadMessages();
        setInterval(loadMessages, 3000);
    }
});
</script>

<?php include_once __DIR__ . '/includes/footer.php'; ?>
