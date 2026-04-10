<?php
$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once $base_path . '/includes/db.php';
require_once $base_path . '/includes/functions.php';
require_once $base_path . '/includes/auth.php';

checkSessionTimeout();

if (!isStudent()) {
    header("Location: login.php");
    exit();
}

// --- Database Migration: Create chat tables if they don't exist ---
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `conversations` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `student_user_id` int(11) NOT NULL,
          `subject` varchar(255) NOT NULL,
          `status` enum('open','closed','pending_admin','pending_student') NOT NULL DEFAULT 'open',
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `student_user_id` (`student_user_id`),
          CONSTRAINT `conversations_ibfk_1` FOREIGN KEY (`student_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `messages` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `conversation_id` int(11) NOT NULL,
          `sender_id` int(11) NOT NULL,
          `message_text` text DEFAULT NULL,
          `attachment_path` varchar(255) DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          `is_read` tinyint(1) NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          KEY `conversation_id` (`conversation_id`),
          KEY `sender_id` (`sender_id`),
          CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
          CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    die("A critical database error occurred during schema update for chat. Please contact support.");
}

$user_id = $_SESSION['user_id'];
$page_title = 'My Messages';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <?php include dirname(__DIR__) . '/includes/favicon.php'; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
<style>
    /* Chat Interface Styles */
    .chat-wrapper {
        height: 80vh;
        min-height: 600px;
        background-color: #fff;
        border-radius: 1rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        display: flex;
        flex-direction: column;
        overflow: hidden;
        border: 1px solid rgba(0,0,0,0.05);
    }

    .chat-header {
        padding: 1.25rem 1.5rem;
        background-color: #fff;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        gap: 1rem;
        z-index: 10;
    }

    .admin-avatar {
        width: 45px;
        height: 45px;
        border-radius: 50%;
        background: linear-gradient(135deg, #0d6efd, #0a58ca);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        box-shadow: 0 4px 10px rgba(13, 110, 253, 0.2);
    }

    .chat-body {
        flex-grow: 1;
        padding: 1.5rem;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        background-color: #f8f9fa;
        background-image: radial-gradient(#e9ecef 1px, transparent 1px);
        background-size: 20px 20px;
        scroll-behavior: smooth;
    }

    .message-wrapper {
        display: flex;
        flex-direction: column;
        max-width: 75%;
        animation: fadeIn 0.3s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .message-wrapper.sent {
        align-self: flex-end;
        align-items: flex-end;
    }

    .message-wrapper.received {
        align-self: flex-start;
        align-items: flex-start;
    }

    .message-bubble {
        padding: 0.85rem 1.25rem;
        border-radius: 18px;
        position: relative;
        font-size: 0.95rem;
        line-height: 1.5;
        box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        word-wrap: break-word;
    }

    .message-wrapper.sent .message-bubble {
        background: linear-gradient(135deg, #0d6efd, #0b5ed7);
        color: white;
        border-bottom-right-radius: 4px;
    }

    .message-wrapper.received .message-bubble {
        background-color: #fff;
        color: #212529;
        border-bottom-left-radius: 4px;
        border: 1px solid rgba(0,0,0,0.05);
    }

    .message-time {
        font-size: 0.7rem;
        color: #adb5bd;
        margin-top: 0.35rem;
        padding: 0 0.5rem;
    }

    .message-attachment img {
        max-width: 250px;
        border-radius: 12px;
        cursor: pointer;
        margin-top: 0.5rem;
        transition: transform 0.2s;
        border: 2px solid rgba(255,255,255,0.2);
    }
    .message-attachment img:hover { transform: scale(1.02); }

    .chat-footer {
        padding: 1.25rem;
        background-color: #fff;
        border-top: 1px solid rgba(0,0,0,0.05);
    }

    .input-group-custom {
        background-color: #f8f9fa;
        border-radius: 25px;
        padding: 0.5rem 1rem;
        display: flex;
        align-items: center;
        border: 1px solid #e9ecef;
        transition: all 0.2s;
    }
    .input-group-custom:focus-within {
        border-color: var(--bs-primary);
        background-color: #fff;
        box-shadow: 0 0 0 4px rgba(13, 110, 253, 0.1);
    }

    .chat-input {
        border: none;
        background: transparent;
        flex-grow: 1;
        padding: 0.5rem;
        outline: none;
        resize: none;
        max-height: 120px;
        font-family: inherit;
    }

    .btn-attach {
        color: #6c757d;
        padding: 0.5rem;
        border-radius: 50%;
        transition: all 0.2s;
        cursor: pointer;
    }
    .btn-attach:hover { background-color: #e9ecef; color: var(--bs-primary); }

    .btn-send {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: var(--bs-primary);
        color: white;
        border: none;
        margin-left: 0.5rem;
        transition: all 0.2s;
    }
    .btn-send:hover { transform: scale(1.05); background-color: #0b5ed7; }
    .btn-send:disabled { background-color: #adb5bd; transform: none; }

    .file-preview {
        display: none;
        padding: 0.75rem 1.5rem;
        background-color: #f1f3f5;
        border-top: 1px solid rgba(0,0,0,0.05);
        align-items: center;
        gap: 1rem;
    }
    .file-preview.active { display: flex; }
    .preview-thumb {
        height: 50px;
        width: auto;
        border-radius: 8px;
        border: 1px solid #dee2e6;
    }
</style>
</head>
<body>
<?php include 'header.php'; ?>

<main class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10 col-xl-9">
            <div class="chat-wrapper" data-aos="fade-up">
                <!-- Header -->
                <div class="chat-header">
                    <div class="admin-avatar">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold">Scholarship Admin</h5>
                        <small class="text-muted"><span class="badge bg-success rounded-pill" style="font-size: 0.6em; vertical-align: middle;"> </span> Online Support</small>
                    </div>
                </div>

                <!-- Messages Body -->
                <div class="chat-body" id="chat-messages">
                    <div class="text-center p-5 text-muted h-100 d-flex align-items-center justify-content-center flex-column">
                        <div class="spinner-border text-primary mb-3" role="status"></div>
                        <p>Connecting to secure chat...</p>
                    </div>
                </div>

                <!-- File Preview -->
                <div class="file-preview" id="filePreview">
                    <img src="" alt="Preview" class="preview-thumb" id="previewImg">
                    <div class="flex-grow-1 overflow-hidden">
                        <div class="small fw-bold text-truncate" id="fileName">image.jpg</div>
                        <div class="small text-muted">Ready to send</div>
                    </div>
                    <button class="btn btn-sm btn-outline-danger rounded-circle" onclick="clearAttachment()" style="width: 30px; height: 30px; padding: 0;"><i class="bi bi-x"></i></button>
                </div>

                <!-- Input Area -->
                <div class="chat-footer" id="chat-input-area">
                    <form id="message-form" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="send_message">
                        <input type="hidden" name="conversation_id" id="current-conversation-id">
                        
                        <div class="input-group-custom">
                            <label class="btn-attach me-2" for="attachment-input" title="Attach Image">
                                <i class="bi bi-paperclip"></i>
                            </label>
                            <input type="file" id="attachment-input" name="attachment" class="d-none" accept="image/png, image/jpeg, image/gif">
                            
                            <textarea class="chat-input" name="message" id="messageInput" rows="1" placeholder="Type a message..." oninput="autoResize(this)" disabled></textarea>
                            
                            <button class="btn-send" type="submit" id="sendBtn" disabled>
                                <i class="bi bi-send-fill"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include $base_path . '/includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.getElementById('chat-messages');
    const messageForm = document.getElementById('message-form');
    const currentConversationIdInput = document.getElementById('current-conversation-id');
    const messageInput = document.getElementById('messageInput');
    const sendButton = document.getElementById('sendBtn');
    const fileInput = document.getElementById('attachment-input');
    const filePreview = document.getElementById('filePreview');
    const previewImg = document.getElementById('previewImg');
    const fileName = document.getElementById('fileName');

    let currentConversationId = null;
    let pollingInterval;

    async function initializeChat() {
        try {
            const formData = new FormData();
            formData.append('action', 'get_or_create_student_conversation');
            
            const response = await fetch('portal_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'include',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) {
                throw new Error(`HTTP Error ${response.status}`);
            }
            const text = await response.text();
            let data;
            try {
                const jsonStart = text.indexOf('{');
                const jsonEnd = text.lastIndexOf('}');
                if (jsonStart !== -1 && jsonEnd !== -1) {
                    data = JSON.parse(text.substring(jsonStart, jsonEnd + 1));
                } else {
                    throw new Error('No JSON found');
                }
            } catch (e) {
                console.error('Server Response (Not JSON):', text);
                throw new Error('Invalid server response.');
            }

            if (data.success) {
                currentConversationId = data.conversation.id;
                currentConversationIdInput.value = currentConversationId;
                
                messageInput.disabled = false;
                checkInputState();

                renderMessages(data.messages);

                if (pollingInterval) clearInterval(pollingInterval);
                pollingInterval = setInterval(fetchMessages, 5000);
            } else {
                chatMessages.innerHTML = `<div class="text-center p-5 text-danger">${data.message || 'Could not load conversation.'}</div>`;
            }
        } catch (error) {
            console.error('Chat Init Error:', error);
            chatMessages.innerHTML = `<div class="text-center p-5 text-danger">Connection error: ${error.message}<br><button class="btn btn-sm btn-outline-danger mt-2" onclick="location.reload()">Refresh Page</button></div>`;
        }
    }

    async function fetchMessages() {
        if (!currentConversationId) return;
        try {
            const formData = new FormData();
            formData.append('action', 'get_messages');
            formData.append('conversation_id', currentConversationId);

            const response = await fetch('portal_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'include',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) {
                throw new Error(`HTTP Error ${response.status}`);
            }
            const text = await response.text();
            
            const jsonStart = text.indexOf('{');
            const jsonEnd = text.lastIndexOf('}');
            if (jsonStart === -1 || jsonEnd === -1) throw new Error('Invalid JSON');
            const data = JSON.parse(text.substring(jsonStart, jsonEnd + 1));

            if (data.success) {
                renderMessages(data.messages);
            }
        } catch (error) {
            console.error('Fetch Messages Error:', error);
        }
    }

    function renderMessages(messages) {
        if (messages.length === 0) {
            chatMessages.innerHTML = `<div class="text-center p-5 text-muted h-100 d-flex align-items-center justify-content-center flex-column">
                            <i class="bi bi-chat-dots-fill display-1 mb-3 text-secondary opacity-25"></i>
                            <h5 class="text-muted">No messages yet</h5>
                            <p class="text-muted small">Start the conversation with the admin below.</p>
                        </div>`;
            return;
        }
        chatMessages.innerHTML = '';
        messages.forEach(msg => {
            const isSent = msg.sender_id == <?php echo $user_id; ?>;
            const wrapper = document.createElement('div');
            wrapper.className = `message-wrapper ${isSent ? 'sent' : 'received'}`;
            
            let content = '';
            if (msg.message_text) {
                content += `<div>${escapeHtml(msg.message_text)}</div>`;
            }
            if (msg.attachment_path) {
                content += `<div class="message-attachment mt-2" onclick="window.open('${escapeHtml(msg.attachment_path)}', '_blank')">
                                <img src="${escapeHtml(msg.attachment_path)}" alt="Attachment">
                            </div>`;
            }
            
            wrapper.innerHTML = `
                <div class="message-bubble">
                    ${content}
                </div>
                <div class="message-time">${formatTime(msg.created_at)}</div>
            `;
            chatMessages.appendChild(wrapper);
        });
        
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    messageForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        try {
            const response = await fetch('portal_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });
            if (!response.ok) {
                throw new Error(`HTTP Error ${response.status}`);
            }
            const text = await response.text();
            
            const jsonStart = text.indexOf('{');
            const jsonEnd = text.lastIndexOf('}');
            if (jsonStart === -1 || jsonEnd === -1) throw new Error('Invalid JSON');
            const data = JSON.parse(text.substring(jsonStart, jsonEnd + 1));
            
            if (data.success) {
                this.reset();
                clearAttachment();
                autoResize(messageInput);
                await fetchMessages();
            } else {
                alert(data.message || 'Failed to send message.');
            }
        } catch (error) {
            console.error('Send Message Error:', error);
            alert('An error occurred. Please check your connection.');
        }
    });

    function escapeHtml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return unsafe
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    window.formatTime = function(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const isToday = date.toDateString() === now.toDateString();
        
        if (isToday) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } else {
            return date.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
    }

    window.autoResize = function(el) {
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
        checkInputState();
    }

    function checkInputState() {
        const hasText = messageInput.value.trim().length > 0;
        const hasFile = fileInput.files.length > 0;
        sendButton.disabled = !(hasText || hasFile);
    }

    messageInput.addEventListener('input', checkInputState);

    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                fileName.textContent = file.name;
                filePreview.classList.add('active');
                checkInputState();
            }
            reader.readAsDataURL(file);
        }
    });

    window.clearAttachment = function() {
        fileInput.value = '';
        filePreview.classList.remove('active');
        checkInputState();
    }

    initializeChat();
});
</script>
</body>
</html>