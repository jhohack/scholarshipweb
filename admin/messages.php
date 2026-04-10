<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_trans_sid', 1);
    ini_set('session.use_only_cookies', 0);
    session_name('scholarship_admin');
    session_start();
}

$base_path = dirname(__DIR__);
require_once $base_path . '/includes/config.php';
require_once $base_path . '/includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

checkSessionTimeout();

if (!isAdmin()) {
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'staff') {
        $perms = $_SESSION['permissions'] ?? [];
        if (!in_array('messages.php', $perms)) {
            header("Location: dashboard.php");
            exit();
        }
    } else {
        header("Location: login.php");
        exit();
    }
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
$page_title = 'Student Messages';
include 'header.php';
?>

<style>
    /* --- Chat Layout & Container --- */
    .content-wrapper {
        padding: 0 !important; /* Remove default padding for full-height chat */
        height: calc(100vh - 70px); /* Adjust based on header height */
        overflow: hidden;
    }
    
    .chat-container {
        display: flex;
        height: 100%;
        background-color: #fff;
        box-shadow: 0 0 20px rgba(0,0,0,0.05);
    }

    /* --- Sidebar (Conversation List) --- */
    .chat-sidebar {
        width: 350px;
        border-right: 1px solid #edf2f9;
        display: flex;
        flex-direction: column;
        background-color: #fff;
        transition: transform 0.3s ease;
        z-index: 10;
    }

    .sidebar-header {
        padding: 1.25rem;
        border-bottom: 1px solid #edf2f9;
    }

    .search-box {
        position: relative;
    }

    .search-box input {
        padding-left: 2.5rem;
        border-radius: 20px;
        background-color: #f8f9fa;
        border: 1px solid transparent;
        transition: all 0.2s;
    }
    .search-box input:focus {
        background-color: #fff;
        border-color: var(--bs-primary);
        box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
    }

    .search-box i {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #adb5bd;
    }

    .conversation-list {
        flex-grow: 1;
        overflow-y: auto;
        padding: 0.5rem;
    }

    .conversation-item {
        display: flex;
        align-items: center;
        padding: 0.75rem 1rem;
        border-radius: 12px;
        cursor: pointer;
        transition: all 0.2s ease;
        margin-bottom: 0.25rem;
        border: 1px solid transparent;
    }

    .conversation-item:hover {
        background-color: #f8f9fa;
    }

    .conversation-item.active {
        background-color: #e7f1ff;
        border-color: #cce5ff;
    }

    .avatar-wrapper {
        position: relative;
        margin-right: 1rem;
    }

    .avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        object-fit: cover;
        border: 2px solid #fff;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    .avatar-placeholder {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: linear-gradient(135deg, #6c757d, #495057);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 1.2rem;
    }

    .status-indicator {
        position: absolute;
        bottom: 2px;
        right: 2px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: 2px solid #fff;
        background-color: #adb5bd; /* Default offline */
    }
    .status-indicator.online { background-color: #28a745; }

    .convo-info {
        flex-grow: 1;
        min-width: 0; /* Required for text-truncate */
    }

    .convo-name {
        font-weight: 600;
        color: #343a40;
        margin-bottom: 0.1rem;
        font-size: 0.95rem;
    }

    .convo-preview {
        color: #6c757d;
        font-size: 0.85rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .convo-meta {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        font-size: 0.75rem;
        color: #adb5bd;
        margin-left: 0.5rem;
    }

    .unread-badge {
        background-color: #dc3545;
        color: #fff;
        border-radius: 10px;
        padding: 0.15rem 0.5rem;
        font-size: 0.7rem;
        font-weight: 700;
        margin-top: 0.25rem;
        box-shadow: 0 2px 4px rgba(220, 53, 69, 0.3);
    }

    /* --- Main Chat Window --- */
    .chat-main {
        flex-grow: 1;
        display: flex;
        flex-direction: column;
        background-color: #fdfdfe;
        position: relative;
    }

    .chat-header {
        padding: 1rem 1.5rem;
        background-color: #fff;
        border-bottom: 1px solid #edf2f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
        z-index: 5;
    }

    .chat-messages {
        flex-grow: 1;
        padding: 1.5rem;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 1rem;
        background-image: radial-gradient(#e9ecef 1px, transparent 1px);
        background-size: 20px 20px;
    }

    /* Message Bubbles */
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
        font-size: 0.95rem;
        line-height: 1.5;
        position: relative;
        word-wrap: break-word;
        box-shadow: 0 2px 5px rgba(0,0,0,0.03);
    }

    .message-wrapper.sent .message-bubble {
        background: linear-gradient(135deg, #0d6efd, #0b5ed7);
        color: #fff;
        border-bottom-right-radius: 4px;
    }

    .message-wrapper.received .message-bubble {
        background-color: #fff;
        color: #212529;
        border: 1px solid #edf2f9;
        border-bottom-left-radius: 4px;
    }

    .message-time {
        font-size: 0.7rem;
        color: #adb5bd;
        margin-top: 0.25rem;
        padding: 0 0.5rem;
    }

    .message-attachment img {
        max-width: 250px;
        border-radius: 12px;
        cursor: pointer;
        transition: transform 0.2s;
        border: 2px solid rgba(255,255,255,0.2);
    }
    .message-attachment img:hover {
        transform: scale(1.02);
    }

    /* Input Area */
    .chat-input-area {
        padding: 1.25rem;
        background-color: #fff;
        border-top: 1px solid #edf2f9;
    }

    .input-wrapper {
        background-color: #f8f9fa;
        border-radius: 24px;
        padding: 0.5rem 1rem;
        display: flex;
        align-items: center;
        border: 1px solid #e9ecef;
        transition: border-color 0.2s;
    }

    .input-wrapper:focus-within {
        border-color: var(--bs-primary);
        background-color: #fff;
        box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.1);
    }

    .chat-input {
        border: none;
        background: transparent;
        flex-grow: 1;
        padding: 0.5rem;
        outline: none;
        resize: none;
        max-height: 100px;
        font-family: inherit;
    }

    .btn-icon {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        border: none;
        background: transparent;
        color: #6c757d;
        transition: all 0.2s;
    }

    .btn-icon:hover {
        background-color: #e9ecef;
        color: var(--bs-primary);
    }

    .btn-send {
        background-color: var(--bs-primary);
        color: #fff;
        margin-left: 0.5rem;
    }
    .btn-send:hover {
        background-color: #0b5ed7;
        color: #fff;
        transform: scale(1.05);
    }
    .btn-send:disabled {
        background-color: #adb5bd;
        transform: none;
    }

    /* File Preview */
    .file-preview {
        display: none;
        padding: 0.5rem 1.25rem;
        background-color: #f8f9fa;
        border-top: 1px solid #edf2f9;
        align-items: center;
        gap: 1rem;
    }
    .file-preview.active { display: flex; }
    .preview-thumb {
        height: 50px;
        width: auto;
        border-radius: 6px;
        border: 1px solid #dee2e6;
    }

    /* Empty State */
    .empty-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: #adb5bd;
        text-align: center;
    }
    .empty-state i {
        font-size: 4rem;
        margin-bottom: 1rem;
        opacity: 0.5;
    }

    /* Responsive */
    @media (max-width: 991.98px) {
        .chat-sidebar {
            width: 100%;
            position: absolute;
            height: 100%;
            transform: translateX(0);
        }
        .chat-main {
            width: 100%;
            position: absolute;
            height: 100%;
            transform: translateX(100%);
            transition: transform 0.3s ease;
        }
        .chat-container.chat-active .chat-sidebar {
            transform: translateX(-100%);
        }
        .chat-container.chat-active .chat-main {
            transform: translateX(0);
        }
        .back-btn { display: block !important; }
    }
    @media (min-width: 992px) {
        .back-btn { display: none !important; }
    }
</style>

<div class="chat-container" id="chatContainer">
    <!-- Sidebar -->
    <div class="chat-sidebar">
        <div class="sidebar-header">
            <h5 class="fw-bold mb-3">Messages</h5>
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" class="form-control" id="conversationSearch" placeholder="Search students...">
            </div>
        </div>
        <div class="conversation-list" id="conversation-list">
            <div class="text-center p-4 text-muted">
                <div class="spinner-border text-primary spinner-border-sm" role="status"></div>
                <p class="mt-2 small">Loading conversations...</p>
            </div>
        </div>
    </div>

    <!-- Main Chat Window -->
    <div class="chat-main">
        <!-- Chat Header -->
        <div class="chat-header" id="chatHeader" style="display: none;">
            <div class="d-flex align-items-center">
                <button class="btn btn-icon back-btn me-2" onclick="toggleChatView(false)">
                    <i class="bi bi-arrow-left fs-5"></i>
                </button>
                <div class="avatar-wrapper">
                    <img src="../public/assets/images/default-avatar.png" id="headerAvatar" class="avatar" alt="User">
                    <span class="status-indicator online"></span>
                </div>
                <div>
                    <h6 class="mb-0 fw-bold" id="headerName">Select User</h6>
                    <small class="text-muted" id="headerRole">Student</small>
                </div>
            </div>
            <div class="dropdown">
                <button class="btn btn-icon" data-bs-toggle="dropdown">
                    <i class="bi bi-three-dots-vertical"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#" id="viewProfileLink">View Profile</a></li>
                    <li><a class="dropdown-item text-danger" href="#">Archive Conversation</a></li>
                </ul>
            </div>
        </div>

        <!-- Messages Area -->
        <div class="chat-messages" id="chat-messages">
            <div class="empty-state">
                <i class="bi bi-chat-square-quote-fill"></i>
                <h4>Select a Conversation</h4>
                <p>Choose a student from the list to start messaging.</p>
            </div>
        </div>

        <!-- File Preview -->
        <div class="file-preview" id="filePreview">
            <img src="" alt="Preview" class="preview-thumb" id="previewImg">
            <div class="flex-grow-1">
                <div class="small fw-bold" id="fileName">image.jpg</div>
                <div class="small text-muted">Ready to send</div>
            </div>
            <button class="btn btn-icon text-danger" onclick="clearAttachment()">
                <i class="bi bi-x-circle-fill"></i>
            </button>
        </div>

        <!-- Input Area -->
        <div class="chat-input-area" id="chatInputArea" style="display: none;">
            <form id="message-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="conversation_id" id="current-conversation-id">
                
                <div class="input-wrapper">
                    <label class="btn btn-icon" for="attachment-input" title="Attach Image">
                        <i class="bi bi-paperclip"></i>
                    </label>
                    <input type="file" id="attachment-input" name="attachment" class="d-none" accept="image/png, image/jpeg, image/gif">
                    
                    <textarea class="chat-input" name="message" id="messageInput" rows="1" placeholder="Type a message..." oninput="autoResize(this)"></textarea>
                    
                    <button class="btn btn-icon btn-send" type="submit" id="sendBtn" disabled>
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const conversationList = document.getElementById('conversation-list');
    const chatMessages = document.getElementById('chat-messages');
    const chatInputArea = document.getElementById('chat-input-area');
    const messageForm = document.getElementById('message-form');
    const currentConversationIdInput = document.getElementById('current-conversation-id');
    const searchInput = document.getElementById('conversationSearch');

    let currentConversationId = null;
    let pollingInterval;

    async function fetchConversations() {
        try {
            const formData = new FormData();
            formData.append('action', 'get_conversations');
            formData.append('chat_context', 'sys_bridge');

            const response = await fetch('../public/portal_api.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!response.ok) {
                throw new Error(`HTTP Error ${response.status}: ${response.statusText}`);
            }

            // Get text first to debug JSON errors (common on free hosting)
            const text = await response.text();
            let data;
            try {
                // Robust JSON parsing: extract JSON substring if garbage is appended
                const jsonStart = text.indexOf('{');
                const jsonEnd = text.lastIndexOf('}');
                if (jsonStart !== -1 && jsonEnd !== -1) {
                    data = JSON.parse(text.substring(jsonStart, jsonEnd + 1));
                } else {
                    throw new Error('No JSON found');
                }
            } catch (e) {
                console.error('Server Response (Not JSON):', text);
                throw new Error('Invalid server response. Check console for details.');
            }

            if (data.success) {
                renderConversations(data.conversations);
            } else {
                conversationList.innerHTML = `<div class="text-center p-5 text-danger">${data.message || 'Failed to load.'}</div>`;
            }
        } catch (error) {
            console.error('Fetch Error:', error);
            conversationList.innerHTML = `<div class="text-center p-5 text-danger">Connection error. <br> <small>${error.message}</small></div>`;
        }
    }

    // Search Functionality
    searchInput.addEventListener('input', function(e) {
        const term = e.target.value.toLowerCase();
        const items = document.querySelectorAll('.conversation-item');
        items.forEach(item => {
            const name = item.querySelector('.convo-name').textContent.toLowerCase();
            if (name.includes(term)) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    });

    function renderConversations(conversations) {
        conversationList.innerHTML = '';
        if (conversations.length === 0) {
            conversationList.innerHTML = `
                <div class="text-center p-5 text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                    No conversations found.
                </div>`;
            return;
        }
        conversations.forEach(convo => {
            const convoEl = document.createElement('div');
            convoEl.className = `conversation-item ${convo.id == currentConversationId ? 'active' : ''}`;
            convoEl.dataset.id = convo.id;
            convoEl.dataset.avatar = convo.profile_picture_path || '';
            convoEl.dataset.name = `${convo.first_name} ${convo.last_name}`;
            convoEl.dataset.studentId = convo.student_user_id;

            const studentName = `${escapeHtml(convo.first_name)} ${escapeHtml(convo.last_name)}`;
            const avatarSrc = convo.profile_picture_path ? `../${escapeHtml(convo.profile_picture_path)}` : null;
            const avatarHtml = avatarSrc 
                ? `<img src="${avatarSrc}" class="avatar" alt="User">`
                : `<div class="avatar-placeholder">${studentName.charAt(0)}</div>`;

            convoEl.innerHTML = `
                <div class="avatar-wrapper">${avatarHtml}</div>
                <div class="convo-info">
                    <div class="convo-name">${studentName}</div>
                    <div class="convo-preview">${convo.unread_count > 0 ? '<strong class="text-dark">New message</strong>' : escapeHtml(convo.last_message || 'No messages')}</div>
                </div>
                <div class="convo-meta">
                    <span>${formatTime(convo.updated_at)}</span>
                    ${convo.unread_count > 0 ? `<span class="unread-badge">${convo.unread_count}</span>` : ''}
                </div>
            `;
            convoEl.addEventListener('click', () => selectConversation(convo.id));
            conversationList.appendChild(convoEl);
        });
    }

    async function selectConversation(id) {
        currentConversationId = id;
        currentConversationIdInput.value = id;
        
        // Update UI Active State
        document.querySelectorAll('.conversation-item').forEach(el => el.classList.remove('active'));
        const activeItem = document.querySelector(`.conversation-item[data-id='${id}']`);
        activeItem?.classList.add('active');

        // Update Header
        const name = activeItem.dataset.name;
        const avatarPath = activeItem.dataset.avatar;
        const studentId = activeItem.dataset.studentId;
        
        document.getElementById('headerName').textContent = name;
        document.getElementById('headerAvatar').src = avatarPath ? `../${avatarPath}` : '../public/assets/images/default-avatar.png';
        document.getElementById('viewProfileLink').href = `users.php?search=${encodeURIComponent(name)}`; // Simple link to users
        
        document.getElementById('chatHeader').style.display = 'flex';
        document.getElementById('chatInputArea').style.display = 'block';
        
        // Mobile Toggle
        toggleChatView(true);

        await fetchMessages();
        if (pollingInterval) clearInterval(pollingInterval);
        pollingInterval = setInterval(fetchMessages, 5000);
    }

    async function fetchMessages() {
        if (!currentConversationId) return;
        try {
            const formData = new FormData();
            formData.append('action', 'get_messages');
            formData.append('conversation_id', currentConversationId);
            formData.append('chat_context', 'sys_bridge');

            const response = await fetch('../public/portal_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'include',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!response.ok) {
                throw new Error(`HTTP Error ${response.status}`);
            }

            const text = await response.text();
            
            // Robust parsing
            const jsonStart = text.indexOf('{');
            const jsonEnd = text.lastIndexOf('}');
            if (jsonStart === -1 || jsonEnd === -1) throw new Error('Invalid JSON');
            const data = JSON.parse(text.substring(jsonStart, jsonEnd + 1));

            if (data.success) {
                renderMessages(data.messages);
            }
        } catch (error) {
            console.error('Error fetching messages:', error);
        }
    }

    function renderMessages(messages) {
        chatMessages.innerHTML = '';
        
        // Group by date logic could go here

        messages.forEach(msg => {
            const isSentByAdmin = msg.role === 'admin';
            const wrapper = document.createElement('div');
            wrapper.className = `message-wrapper ${isSentByAdmin ? 'sent' : 'received'}`;
            
            let content = '';
            if (msg.message_text) {
                content += `<div>${escapeHtml(msg.message_text)}</div>`;
            }
            if (msg.attachment_path) {
                // Note: Path is relative to the public folder
                const attachmentUrl = `../public/${escapeHtml(msg.attachment_path)}`;
                content += `<div class="message-attachment mt-2" onclick="window.open('${attachmentUrl}', '_blank')">
                                <img src="${attachmentUrl}" alt="Attachment">
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
        
        // Scroll to bottom
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    messageForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        formData.append('chat_context', 'sys_bridge');
        
        try {
            const response = await fetch('../public/portal_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP Error ${response.status}`);
            }

            const text = await response.text();
            
            // Robust parsing
            const jsonStart = text.indexOf('{');
            const jsonEnd = text.lastIndexOf('}');
            if (jsonStart === -1 || jsonEnd === -1) throw new Error('Invalid JSON');
            const data = JSON.parse(text.substring(jsonStart, jsonEnd + 1));
            
            if (data.success) {
                this.reset();
                clearAttachment();
                await fetchMessages();
                await fetchConversations();
            } else {
                alert(data.message || 'Failed to send message.');
            }
        } catch (error) {
            console.error('Error sending message:', error);
            alert('An error occurred. Please check the console.');
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

    // Helper: Format Time
    window.formatTime = function(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const isToday = date.toDateString() === now.toDateString();
        
        if (isToday) {
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } else {
            return date.toLocaleDateString([], { month: 'short', day: 'numeric' });
        }
    }

    // Helper: Auto-resize textarea
    window.autoResize = function(el) {
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
        document.getElementById('sendBtn').disabled = el.value.trim() === '';
    }

    // Helper: Mobile Toggle
    window.toggleChatView = function(showChat) {
        const container = document.getElementById('chatContainer');
        if (showChat) {
            container.classList.add('chat-active');
        } else {
            container.classList.remove('chat-active');
        }
    }

    // File Attachment Logic
    const fileInput = document.getElementById('attachment-input');
    const filePreview = document.getElementById('filePreview');
    const previewImg = document.getElementById('previewImg');
    const fileName = document.getElementById('fileName');

    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                fileName.textContent = file.name;
                filePreview.classList.add('active');
                document.getElementById('sendBtn').disabled = false;
            }
            reader.readAsDataURL(file);
        }
    });

    window.clearAttachment = function() {
        fileInput.value = '';
        filePreview.classList.remove('active');
        document.getElementById('sendBtn').disabled = document.getElementById('messageInput').value.trim() === '';
    }

    // Initial Load
    fetchConversations();
    setInterval(fetchConversations, 5000); // Refresh conversation list every 5 seconds
});
</script>

<?php include 'footer.php'; ?>