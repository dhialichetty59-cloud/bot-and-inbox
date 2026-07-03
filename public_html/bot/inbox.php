<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

$db_host = 'sql3.jnb1.host-h.net';
$db_user = 'wwwcea_bot1';
$db_pass = 'CareerExcelAcademy2026';
$db_name = 'ceabot_db';

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) { die("Connection Failed: " . $conn->connect_error); }

// --- TIME FORMATTING FUNCTION ---
function formatChatDate($timestamp) {
    if (!$timestamp) return '';
    
    $time = strtotime($timestamp);
    $now = time();
    $diff_days = floor(($now - $time) / (60 * 60 * 24));
    
    $is_today = date('Y-m-d', $time) === date('Y-m-d', $now);
    $is_yesterday = date('Y-m-d', $time) === date('Y-m-d', strtotime('-1 day', $now));

    if ($is_today) {
        return date('g:i A', $time); // e.g., 10:45 AM
    } elseif ($is_yesterday) {
        return 'Yesterday';
    } elseif ($diff_days > 1 && $diff_days <= 6) {
        return $diff_days . ' days ago';
    } elseif ($diff_days >= 7 && $diff_days <= 13) {
        return 'Last Week';
    } else {
        return date('jS M', $time); // e.g., 19th Jul
    }
}

// --- DETECT ACTIVE CHAT EARLY FOR SIDEBAR HIGHLIGHTING ---
$current_chat = isset($_GET['chat']) ? $_GET['chat'] : null;

// --- AJAX HANDLER FOR STARRING CHATS ---
if (isset($_GET['action']) && $_GET['action'] === 'toggle_star' && isset($_GET['phone'])) {
    $phone = $_GET['phone'];
    $current_status = isset($_GET['status']) && $_GET['status'] == '1' ? 0 : 1;
    
    $stmt = $conn->prepare("UPDATE bot_states SET is_starred = ? WHERE phone = ?");
    $stmt->bind_param("is", $current_status, $phone);
    $stmt->execute();
    
    echo $current_status; 
    exit();
}

// ONLY run this when the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_phone = $_POST['student_phone'];
    $reply_text = isset($_POST['reply_text']) ? $_POST['reply_text'] : '';

    // Handle 'bot on' command
    if (trim($reply_text) === 'bot on') {
        $stmt = $conn->prepare("DELETE FROM bot_states WHERE phone = ?");
        $stmt->bind_param("s", $student_phone);
        $stmt->execute();
        
        $stmt = $conn->prepare("INSERT INTO messages (student_phone, sender, message_text) VALUES (?, 'agent', '[Bot has been reactivated]')");
        $stmt->bind_param("s", $student_phone);
        $stmt->execute();
        
        header("Location: inbox.php?chat=" . urlencode($student_phone));
        exit();
    } 

    $access_token = 'EAANeDzAb8iQBRSOhmrZAc3UXvt7uEquOj67ZCVKlS4ZBHxmJkIuLrWNoOTPWmdUgQcthaSVGMU8pu4vLgILge4x6ry62N0dLWrK7RoRb39ZC5ijZAZBZB8MIXGTFKslfVVQxo18cikxZB4ooSvI0f5vhidHW3PRNkZCOEVKSc5DSl6CDXgLCRBXLrBEgX2wHlVgZDZD'; 
    $phone_number_id = '1192797650583688'; 
    $url = "https://graph.facebook.com/v17.0/" . $phone_number_id . "/messages";

    $message_type = 'text';
    $media_url_db = null;
    $data = []; // WhatsApp API payload

    // --- 1. HANDLE FILE UPLOAD ---
    if (isset($_FILES['file_upload']) && $_FILES['file_upload']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/';
        
        // Create directory if it doesn't exist
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $file_tmp = $_FILES['file_upload']['tmp_name'];
        $file_name = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($_FILES['file_upload']['name']));
        $file_dest = $upload_dir . $file_name;

        if (move_uploaded_file($file_tmp, $file_dest)) {
            // Determine if image or document
            $mime_type = mime_content_type($file_dest);
            $is_image = strpos($mime_type, 'image/') === 0;
            $message_type = $is_image ? 'image' : 'document';
            $media_url_db = $file_dest; // Save relative path to DB for the UI

            // Generate absolute public URL for Meta API
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $public_file_url = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/' . $file_dest;

            $data = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $student_phone,
                'type' => $message_type,
                $message_type => [
                    'link' => $public_file_url
                ]
            ];

            // If there's text with the file, send it as a caption
            if (!empty(trim($reply_text))) {
                $data[$message_type]['caption'] = $reply_text;
            }
        }
    } 
    // --- 2. HANDLE TEXT ONLY ---
    elseif (!empty(trim($reply_text))) {
        $data = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $student_phone,
            'type' => 'text',
            'text' => ['body' => $reply_text]
        ];
    }

    // --- 3. SEND TO WHATSAPP & SAVE TO DATABASE ---
    if (!empty($data)) {
        // Send to WhatsApp
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ]);
        curl_exec($ch);
        curl_close($ch);

        // Log the message to database
        $stmt = $conn->prepare("INSERT INTO messages (student_phone, sender, message_text, message_type, media_url) VALUES (?, 'agent', ?, ?, ?)");
        $stmt->bind_param("ssss", $student_phone, $reply_text, $message_type, $media_url_db);
        $stmt->execute();
    }
    
    // Redirect to prevent form resubmission
    header("Location: inbox.php?chat=" . urlencode($student_phone));
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>CEA WhatsApp Shared Inbox</title>
    <style>
        :root {
            /* --- BRAND COLORS --- */
            --brand-dark-navy: #161645;
            --brand-blue: #2B3F99;
            --brand-gold: #ce8945;
            --brand-brown: #916230;

            /* --- UI APPLICATION --- */
            --bg-body: #f4f5f8; 
            --bg-chat: #ffffff;
            --bubble-agent: var(--brand-gold);
            --bubble-student: #f0f2f5;
            --border: #e1e4e8;
            --text-main: var(--brand-dark-navy);
            --text-muted: #667781;
            --star-color: var(--brand-gold);
        }

        body { 
            font-family: 'Segoe UI', 'Helvetica Neue', Helvetica, Arial, sans-serif; 
            display: flex; 
            flex-direction: column;
            height: 100vh; 
            margin: 0; 
            background-color: var(--bg-body); 
            color: var(--text-main); 
        }

        /* --- Top Navigation Styles --- */
        #top-nav {
            background-color: var(--brand-dark-navy);
            height: 80px; 
            display: flex;
            align-items: center;
            padding: 0 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            z-index: 10;
        }

       .nav-logo-container {
            display: flex;
            align-items: center;
            margin-right: auto;
        }

        .nav-logo {
            height: 100px;
            width: auto;
        }

        .nav-links {
            display: flex;
            gap: 30px;
            position: absolute;
            left: 50%;
            transform: translateX(-50%); 
        }

        .nav-link {
            color: #a0a5b5;
            text-decoration: none;
            font-size: 0.95em;
            font-weight: 600;
            padding: 20px 0; 
            border-bottom: 3px solid transparent;
            transition: all 0.2s ease;
        }

        .nav-link:hover { color: #ffffff; }
        .nav-link.active { color: #ffffff; border-bottom-color: var(--brand-gold); }

        /* --- Sub Navigation Breadcrumb Styles --- */
        #sub-nav {
            background-color: var(--brand-dark-navy);
            padding: 25px 40px; 
            font-size: 1em;
            font-weight: 500;
            color: var(--brand-gold);
            z-index: 9;
        }

        /* --- Main Content Wrapper --- */
        .main-container {
            display: flex;
            flex-grow: 1;
            overflow: hidden; 
        }

        /* --- Sidebar Styles --- */
        #sidebar {
            width: 380px; 
            min-width: 340px; 
            border-right: 1px solid var(--border);
            background: #ffffff;
            display: flex;
            flex-direction: column;
        }

        .sidebar-header { 
            background: #ffffff; 
            color: var(--brand-dark-navy);
            padding: 25px 40px 10px 40px;
            font-weight: 700; 
            font-size: 1.2em;
            display: flex;          
            align-items: center;   
            justify-content: space-between;
        }

        .sidebar-header-left {
            display: flex;
            align-items: center;
        }

        .chat-counter {
            background-color: var(--brand-gold);
            color: #ffffff;
            font-size: 0.65em;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: 10px;
            min-width: 14px;
            text-align: center;
        }

        .search-container { padding: 10px 40px; background: #ffffff; }

        .search-container input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 20px; 
            font-size: 0.9em;
            outline: none;
            box-sizing: border-box;
            background-color: var(--bg-body);
            transition: all 0.2s ease;
            color: var(--text-main);
        }

        .search-container input:focus {
            border-color: var(--brand-gold);
            background-color: #ffffff;
        }

        .filter-container {
            display: flex;
            padding: 0 40px 15px 40px; 
            gap: 8px;
            border-bottom: 1px solid var(--border);
        }

        .filter-btn {
            flex: 1;
            padding: 8px 0;
            border: 1px solid var(--border);
            background: var(--bg-body);
            border-radius: 20px; 
            font-size: 0.8em;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-btn.active { background: var(--brand-blue); color: white; border-color: var(--brand-gold); }

        .chat-list { flex-grow: 1; overflow-y: auto; }

        .chat-item {
            display: flex;
            align-items: center;
            border-bottom: 1px solid #f5f6f6;
            transition: background 0.2s ease;
            border-left: 4px solid transparent;
            position: relative; 
        }

        .chat-item::before {
            content: ''; 
            background-image: url('assets/welcome_logo.png'); 
            background-size: contain; 
            background-repeat: no-repeat;
            background-position: center;
            width: 60px;
            height: 60px;
            border-radius: 5px;
            display: none; 
        }
        
        .chat-item.active::before {
            display: block; 
        }

        .chat-item:hover { background: #f5f6f6; }
        .chat-item.active { background-color: #eef0f7; border-left: 4px solid var(--brand-gold); }

        .chat-link {
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            padding: 15px 10px 15px 20px; 
            text-decoration: none;
            color: var(--text-main);
            overflow: hidden;
        }
        
        .chat-name-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }

        .chat-name { font-weight: bold; white-space: nowrap; }
        
        .chat-time-sidebar {
            font-size: 0.75em;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .chat-last-message { 
            font-size: 0.8em; 
            color: var(--text-muted); 
            margin-top: 4px; 
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

       .unread-badge {
            background-color: var(--brand-gold); 
            color: white;
            font-size: 0.75em;
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 12px;
            margin-left: 10px;
            display: inline-block;
            min-width: 15px;
            text-align: center;
        }

        .chat-item[data-unread]:not([data-unread="0"]) .chat-name {
            font-weight: 800;
        }

        .star-btn {
            background: none;
            border: none;
            font-size: 1.5em;
            color: #d1d7db; 
            cursor: pointer;
            padding: 15px 40px 15px 10px;
            transition: transform 0.1s ease;
        }

        .star-btn.is-starred { color: var(--star-color); }
        .star-btn:active { transform: scale(1.2); }

        /* --- Chat Window Styles --- */
        #chat-window { 
            flex-grow: 1; 
            display: flex; 
            flex-direction: column; 
            background-color: var(--bg-chat); 
            height: 100%;
        }

        .chat-header { 
            background: #ffffff; 
            color: var(--text-main);
            padding: 20px 30px; 
            border-bottom: 1px solid var(--border); 
            font-weight: 700; 
            font-size: 1.2em;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        #msg-history { 
            flex-grow: 1; 
            overflow-y: auto; 
            padding: 30px; 
            display: flex; 
            flex-direction: column; 
            background-color: #fafbfc; 
        }

        .message { 
            padding: 12px 16px; 
            margin-bottom: 16px; 
            border-radius: 12px; 
            max-width: 65%; 
            font-size: 0.95em; 
            line-height: 1.5; 
            box-shadow: 0 1px 2px rgba(0,0,0,0.05); 
        }

        .student { 
            background: var(--bubble-student); 
            align-self: flex-start; 
            border-top-left-radius: 0; 
            color: var(--text-main);
            border: 1px solid var(--border);
        }
        
        .agent { 
            background: var(--brand-blue); 
            align-self: flex-end; 
            border-top-right-radius: 0; 
            color: #ffff;
        }
        
        .sender-name { 
            font-size: 0.8em; 
            margin-bottom: 6px; 
            font-weight: 700; 
        }
        
        .student .sender-name { color: var(--brand-blue); }
        .agent .sender-name { color: #ffff; opacity: 0.8; }
        
        .message-time {
            font-size: 0.7em;
            text-align: right;
            margin-top: 5px;
        }
        
        .student .message-time { color: var(--text-muted); }
        .agent .message-time { color: rgba(255, 255, 255, 0.7); }

        .chat-input-area { 
            background: #ffffff; 
            padding: 20px 30px; 
            display: flex; 
            align-items: center; 
            border-top: 1px solid var(--border);
        }

        .chat-input-area input[type="text"] { 
            flex-grow: 1; 
            padding: 14px 20px; 
            border: 1px solid var(--border); 
            border-radius: 24px;
            font-size: 1em; 
            outline: none;
            color: var(--text-main); 
            background-color: #f9f9fb;
            transition: all 0.2s ease;
        }
        
        .chat-input-area input[type="text"]:focus {
            border-color: var(--brand-gold);
            background-color: #ffffff;
        }

        .chat-input-area button { 
            background: var(--brand-blue); 
            color: white; 
            border: none; 
            padding: 14px 28px; 
            margin-left: 15px; 
            border-radius: 24px;
            cursor: pointer; 
            font-weight: 600; 
            font-size: 1em;
            transition: background 0.2s;
        }
        
        .chat-input-area button:hover { background: var(--brand-brown); }

        .empty-state { 
            flex-grow: 1; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            color: var(--text-muted); 
            background-color: #fafbfc;
        }
        
        .header-icon-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 5px;
            transition: color 0.2s ease, transform 0.1s ease;
        }

        .header-icon-btn:hover { color: var(--brand-gold); }
        .header-icon-btn:active { transform: scale(0.95); }
    </style>
</head>
<body>

    <div id="top-nav">
        <div class="nav-logo-container">
           <img src="assets/inbox_logo.png" alt="Career Excel Academy" class="nav-logo">
        </div>
        <div class="nav-links">
            <a href="#" class="nav-link active">All</a>
            <a href="#" class="nav-link">George</a>
            <a href="#" class="nav-link">Barry</a>
        </div>
    </div>

    <div id="sub-nav">
        <span id="breadcrumb-text">Leads / All</span>
    </div>

    <div class="main-container">
        
        <div id="sidebar">
            <?php 
            // UPDATED QUERY: Safely selects the actual text and type of the absolute latest message
            $active = $conn->query("
                SELECT b.phone, b.name, b.is_starred, 
                       (SELECT COUNT(*) FROM messages WHERE student_phone = b.phone AND sender = 'student' AND is_read = 0) as unread_count, 
                       MAX(m.timestamp) as last_msg_time,
                       (SELECT message_text FROM messages WHERE student_phone = b.phone ORDER BY timestamp DESC, id DESC LIMIT 1) as last_msg_text,
                       (SELECT message_type FROM messages WHERE student_phone = b.phone ORDER BY timestamp DESC, id DESC LIMIT 1) as last_msg_type
                FROM bot_states b 
                LEFT JOIN messages m ON b.phone = m.student_phone 
                GROUP BY b.phone 
                ORDER BY last_msg_time DESC
            ");
            $conversation_count = $active ? $active->num_rows : 0;
            ?>
            <div class="sidebar-header">
                <div class="sidebar-header-left">
                    Conversations
                    <span class="chat-counter"><?php echo $conversation_count; ?></span>
                </div>
                <button class="header-icon-btn" title="View Archived Conversations">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 8v13H3V8"></path>
                    <path d="M1 3h22v5H1z"></path>
                    <path d="M10 12h4"></path>
                </svg>
                </button>
            </div>
            
            <div class="search-container">
                <input type="text" id="chat-search" placeholder="Search conversations...">
            </div>

            <div class="filter-container">
                <button class="filter-btn active" data-filter="all">All</button>
                <button class="filter-btn" data-filter="unread">Unread</button>
                <button class="filter-btn" data-filter="starred">Starred</button>
            </div>

            <div class="chat-list" id="chat-list-container">
            <?php 
            if ($active) {
                while($row = $active->fetch_assoc()): 
                    $is_starred = isset($row['is_starred']) ? $row['is_starred'] : 0;
                    
                    $is_active = ($current_chat === $row['phone']);
                    $formatted_time = formatChatDate($row['last_msg_time']);

                    // Get last message text or fallback to media tags if text is empty
                    $last_msg = $row['last_msg_text'] ?? '';
                    if (empty(trim($last_msg)) && !empty($row['last_msg_type'])) {
                        if ($row['last_msg_type'] === 'image') {
                            $last_msg = '📷 Image';
                        } elseif ($row['last_msg_type'] === 'document') {
                            $last_msg = '📎 Document';
                        }
                    }

                    // Enforce the strict 30-character limit cut-off
                    if (mb_strlen($last_msg) > 30) {
                        $last_msg = mb_substr($last_msg, 0, 30) . '...';
                    }
                ?>
                    <div class="chat-item <?php echo $is_active ? 'active' : ''; ?>" data-unread="<?php echo $row['unread_count']; ?>" data-starred="<?php echo $is_starred; ?>">
                        <a class="chat-link" href="inbox.php?chat=<?php echo urlencode($row['phone']); ?>">
                            <div class="chat-name-row">
                                <div style="display: flex; align-items: center; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; padding-right: 5px;">
                                    <span class="chat-name"><?php echo htmlspecialchars($row['name']); ?></span>
                                    <span style="font-size: 0.8em; color: var(--text-muted); margin-left: 8px; font-weight: normal;"><?php echo htmlspecialchars($row['phone']); ?></span>
                                </div>
                                
                                <span class="unread-badge" <?php if($row['unread_count'] == 0) echo 'style="display:none;"'; ?>>
                                    <?php echo $row['unread_count']; ?>
                                </span>
                                
                                <span class="chat-time-sidebar"><?php echo $formatted_time; ?></span>
                            </div>
                            <span class="chat-last-message"><?php echo htmlspecialchars($last_msg); ?></span>
                        </a>
                        
                        <button class="star-btn <?php echo $is_starred ? 'is-starred' : ''; ?>" 
                                data-phone="<?php echo htmlspecialchars($row['phone']); ?>" 
                                data-status="<?php echo $is_starred; ?>"
                                title="Star/Unstar Chat">
                            ★
                        </button>
                    </div>
                <?php endwhile; 
            } else {
                echo "<div style='padding: 20px; color: red;'>Database execution error or query malformed.</div>";
            }
            ?>
            </div>
        </div>

        <div id="chat-window">
            <?php if ($current_chat !== null): 
                
                // Mark chat as read when opened
                $stmt_read = $conn->prepare("UPDATE bot_states SET is_unread = 0 WHERE phone = ?");
                if ($stmt_read) {
                    $stmt_read->bind_param("s", $current_chat);
                    $stmt_read->execute();
                }
                
                $conn->query("UPDATE messages SET is_read = 1 WHERE student_phone = '$current_chat' AND sender = 'student'");

                // Fetch Student Name
                $chat_name = $current_chat;
                $stmt_name = $conn->prepare("SELECT name FROM bot_states WHERE phone = ?");
                if ($stmt_name) {
                    $stmt_name->bind_param("s", $current_chat);
                    $stmt_name->execute();
                    $stmt_name->bind_result($db_name_result);
                    if ($stmt_name->fetch() && !empty($db_name_result)) {
                        $chat_name = $db_name_result;
                    }
                    $stmt_name->close();
                }

                $history = $conn->query("SELECT * FROM messages WHERE student_phone = '$current_chat' ORDER BY timestamp ASC");
            ?>
               <div class="chat-header">
                <span><?php echo htmlspecialchars($chat_name); ?></span>
                <button class="header-icon-btn" title="Archive this chat" data-phone="<?php echo htmlspecialchars($current_chat); ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 8v13H3V8"></path>
                        <path d="M1 3h22v5H1z"></path>
                        <path d="M10 12h4"></path>
                    </svg>
                </button>
                </div>
                
                <div id="msg-history">
    <?php while($msg = $history->fetch_assoc()): ?>
        <div class="message <?php echo $msg['sender']; ?>">
            <div class="sender-name"><?php echo ucfirst($msg['sender']); ?></div>
            
        <?php 
            if (!empty($msg['media_url'])): 
                if ($msg['message_type'] === 'document'): ?>
                    <a href="<?php echo htmlspecialchars($msg['media_url']); ?>" target="_blank" style="display: block; margin-bottom: 5px;">📎 Download Document</a>
                <?php else: ?>
                    <img src="<?php echo htmlspecialchars($msg['media_url']); ?>" style="max-width: 100%; border-radius: 8px; margin-bottom: 5px; display: block;">
                <?php endif; 
            endif; ?>

            <?php 
            if (!empty($msg['message_text'])): ?>
                <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['message_text'])); ?></div>
            <?php endif; ?>
            
            <div class="message-time"><?php echo date('g:i A', strtotime($msg['timestamp'])); ?></div>
        </div>
    <?php endwhile; ?>
</div>
                
<div id="image-preview-container" style="display: none; padding: 10px 30px; background: #f9f9fb; border-top: 1px solid var(--border); align-items: center;">
    <img id="image-preview" src="" style="max-height: 80px; border-radius: 8px; display: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
    <span id="file-name-preview" style="margin-left: 15px; font-size: 0.9em; color: var(--text-muted); font-weight: bold;"></span>
    <button type="button" onclick="clearPreview()" style="background: none; color: #d9534f; border: none; cursor: pointer; padding: 5px 10px; margin-left: auto; font-weight: bold;">✖ Remove File</button>
</div>

<form class="chat-input-area" method="POST" enctype="multipart/form-data" id="chat-form">
    <input type="hidden" name="student_phone" value="<?php echo htmlspecialchars($current_chat); ?>">
    
    <input type="file" name="file_upload" id="file_upload" style="display:none;" accept="image/*,.pdf,.doc,.docx">
    <button type="button" onclick="document.getElementById('file_upload').click()" title="Attach File">📎</button>
    
    <input type="text" name="reply_text" placeholder="Type a reply...">
    <button type="submit" id="send-btn">Send</button>
</form>

<script>
document.getElementById('file_upload').addEventListener('change', function() {
    const previewContainer = document.getElementById('image-preview-container');
    const previewImg = document.getElementById('image-preview');
    const fileNameSpan = document.getElementById('file-name-preview');
    
    if (this.files && this.files.length > 0) {
        const file = this.files[0];
        fileNameSpan.textContent = file.name;
        previewContainer.style.display = 'flex'; 
        
        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                previewImg.src = e.target.result;
                previewImg.style.display = 'block';
            }
            reader.readAsDataURL(file);
        } else {
            previewImg.style.display = 'none'; 
        }
    }
});

function clearPreview() {
    document.getElementById('file_upload').value = '';
    document.getElementById('image-preview-container').style.display = 'none';
    document.getElementById('image-preview').src = '';
}

document.getElementById('chat-form').addEventListener('submit', function() {
    const btn = document.getElementById('send-btn');
    btn.innerHTML = 'Sending...';
    btn.style.opacity = '0.7';
    btn.disabled = true;
});
</script>

<script>
    const urlParams = new URLSearchParams(window.location.search);
    const currentChat = urlParams.get('chat');
    const historyDiv = document.getElementById('msg-history');

    function scrollToBottom() {
        if (historyDiv) {
            historyDiv.scrollTop = historyDiv.scrollHeight;
        }
    }

    window.onload = scrollToBottom;

    if (currentChat) {
        setInterval(function() {
            fetch('inbox.php?chat=' + currentChat)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newHistory = doc.getElementById('msg-history');
                    
                    if (newHistory && historyDiv) {
                        const isScrolledToBottom = historyDiv.scrollHeight - historyDiv.clientHeight <= historyDiv.scrollTop + 50;
                        historyDiv.innerHTML = newHistory.innerHTML;
                        if (isScrolledToBottom) scrollToBottom();
                    }
                })
                .catch(error => console.error('Error:', error));
        }, 3000);
    }
</script>

          <?php else: ?>
                <div class="empty-state">
                    <img src="assets/welcome_logo.png" alt="Welcome" style="width: 150px; height: auto; margin-bottom: 20px;">
                    
                    <h2>Welcome to the CEA Shared Inbox</h2>
                    <p>Select a chat from the sidebar to start replying.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('chat-search');
    const filterBtns = document.querySelectorAll('.filter-btn');
    const historyDiv = document.getElementById('msg-history');
    
    let currentFilter = 'all';

    function applyFilters() {
        const searchText = searchInput ? searchInput.value.toLowerCase() : '';
        const chatItems = document.querySelectorAll('.chat-item'); 

        chatItems.forEach(item => {
            const textContent = item.textContent.toLowerCase();
            const isStarred = item.getAttribute('data-starred') === '1';
            const unreadCount = parseInt(item.getAttribute('data-unread') || '0', 10);
            const isUnread = unreadCount > 0; 
            
            let matchesSearch = textContent.includes(searchText);
            let matchesFilter = true;

            if (currentFilter === 'unread' && !isUnread) matchesFilter = false;
            if (currentFilter === 'starred' && !isStarred) matchesFilter = false;

            if (matchesSearch && matchesFilter) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    filterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.getAttribute('data-filter');
            applyFilters();
        });
    });
});
</script>
</body>
</html>