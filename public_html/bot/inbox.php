<?php
session_start();

// --- 1. SET YOUR USERS AND PASSWORDS HERE ---
$allowed_users = [
    'barry'  => 'BarryPass2026',
    'george' => 'GeorgePass2026',
    'admin'  => '2026' // Change 'admin' to your actual name if you prefer
];

// --- 2. LOGOUT LOGIC ---
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: inbox.php");
    exit;
}

// --- 3. LOGIN LOGIC ---
$error_msg = "";
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
    $user = strtolower(trim($_POST['username']));
    $pass = $_POST['password'];

    // Check if the username exists and the password matches
    if (array_key_exists($user, $allowed_users) && $allowed_users[$user] === $pass) {
        $_SESSION['logged_in'] = true;
        $_SESSION['active_user'] = $user;
        header("Location: inbox.php"); // Refresh the page to clear the form submission
        exit;
    } else {
        $error_msg = "Incorrect username or password.";
    }
}

// --- 4. SHOW LOGIN SCREEN IF NOT LOGGED IN ---
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CEA Inbox Login</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .login-box { background: #fff; padding: 40px; border-radius: 10px; box-shadow: 0 8px 20px rgba(0,0,0,0.1); width: 100%; max-width: 320px; text-align: center; }
            .login-box h2 { color: #2B3F99; margin-top: 0; margin-bottom: 25px; }
            .login-box input { width: 90%; padding: 12px; margin: 10px 0; border: 1px solid #ccc; border-radius: 6px; font-size: 16px; }
            .login-box button { background-color: #2B3F99; color: white; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; width: 100%; font-size: 16px; font-weight: bold; margin-top: 15px; }
            .login-box button:hover { background-color: #1a2766; }
            .error { color: #d93025; background: #fce8e6; padding: 10px; border-radius: 5px; margin-bottom: 15px; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h2>CEA Inbox</h2>
            <?php if ($error_msg) echo "<div class='error'>$error_msg</div>"; ?>
            <form method="POST">
                <input type="text" name="username" placeholder="Username" required autofocus>
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit">Log In</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit; // This stops the rest of the inbox from loading!
}

$current_view = $_GET['view'] ?? 'all';

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

// --- BACKEND ACTION HANDLERS FOR ARCHIVE & STAR ---
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $phone = isset($_GET['phone']) ? $_GET['phone'] : '';
    $current_status = isset($_GET['status']) ? intval($_GET['status']) : 0;
    
    if (!empty($phone)) {
        // Calculate target status (flip 0 to 1, or 1 to 0)
        $new_status = ($current_status === 1) ? 0 : 1;

        if ($action === 'toggle_archive') {
            $stmt = $conn->prepare("UPDATE bot_states SET is_archived = ? WHERE phone = ?");
            if ($stmt) {
                $stmt->bind_param("is", $new_status, $phone);
                $stmt->execute();
                $stmt->close();
            }
            echo $new_status;
            exit; // Stop processing the rest of the page for AJAX/Fetch requests
        }
        
        if ($action === 'toggle_star') {
            $stmt = $conn->prepare("UPDATE bot_states SET is_starred = ? WHERE phone = ?");
            if ($stmt) {
                $stmt->bind_param("is", $new_status, $phone);
                $stmt->execute();
                $stmt->close();
            }
            echo $new_status; // Returns '1' or '0' back to your JavaScript
            exit; // Stop processing the rest of the page
        }
    }
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
        
        // 1. Capture the response from Meta instead of ignoring it
        $response = curl_exec($ch); 
        curl_close($ch);

        // 2. Decode the response and grab the Meta Message ID
        $response_data = json_decode($response, true);
        $meta_message_id = isset($response_data['messages'][0]['id']) ? $response_data['messages'][0]['id'] : null;

        // 3. Log the message to database WITH the message_id
        // NOTE: Make sure your 'messages' table has a 'message_id' column!
        $stmt = $conn->prepare("INSERT INTO messages (student_phone, sender, message_text, message_type, media_url, message_id) VALUES (?, 'agent', ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $student_phone, $reply_text, $message_type, $media_url_db, $meta_message_id);
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
        
        
   
        .nav-link:hover { color: #ffffff;
         transform: translateY(-2px); /* Lifts the button up slightly */
 }
    
        .nav-link.active { color: #ffffff; border-bottom-color: var(--brand-gold); 
           transform: translateY(0); /* Snaps it back down when clicked */
 
        }

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


.filter-btn:hover {
    transform: translateY(-2px); /* Lifts the button up slightly */
   
}

.filter-btn.active:active {
    transform: translateY(0); /* Snaps it back down when clicked */
   
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
    justify-content: space-between;
    gap: 10px;
    padding: 12px 15px;
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
            background-color: #ffffff; 
            
            /* 1. The Watermark Effect: Overlays a 90% solid white layer on top of the logo */
            background-image: linear-gradient(rgba(255, 255, 255, 0.90), rgba(255, 255, 255, 0.90)), url('assets/welcome_logo.png'); 
            
            /* 2. Positioned to the right: '90%' pushes it to the right, 'center' keeps it vertically middle */
            background-position: 65% center; 
            
            background-repeat: no-repeat; 
            background-size: 520px; 
            background-attachment: fixed; 
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
        
        /* Reset button styles for the new attach icon */
.chat-input-area .attach-btn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: transform 0.2s ease;
}

/* Optional: Slight hover effect for the plus button */
.chat-input-area .attach-btn:hover {
    transform: scale(1.1);
}
        
        

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
        
        /* --- Logout Button --- */
.logout-btn {
    color: #e63946; /* A clean, modern red */
    text-decoration: none;
    font-weight: bold;
    border: 2px solid #e63946;
    padding: 8px 20px;
    border-radius: 10px; /* Matches the rounded look of your other inputs */
    transition: all 0.2s ease;
    transition: transform 0.2s cubic-bezier(0.25, 1, 0.5, 1), box-shadow 0.2s ease;
    font-size: 0.9em;
}

.logout-btn:hover {
    transform: translateY(-2px); /* Lifts the button up slightly */
    box-shadow: 0 4px 12px rgba(230, 57, 70, 0.15); /* Adds a soft drop shadow beneath it */
}

.logout-btn:active {
    transform: translateY(0); /* Snaps it back down when clicked */
    box-shadow: 0 2px 4px rgba(230, 57, 70, 0.1);
}
        
    </style>
</head>
<body>

    <div id="top-nav">
        <div class="nav-logo-container">
           <img src="assets/inbox_logo.png" alt="Career Excel Academy" class="nav-logo">
        </div>
        <div class="nav-links">
            <a href="inbox.php?view=all" class="nav-link <?php echo $current_view === 'all' ? 'active' : ''; ?>">All</a>
            <a href="inbox.php?view=george" class="nav-link <?php echo $current_view === 'george' ? 'active' : ''; ?>">George</a>
            <a href="inbox.php?view=barry" class="nav-link <?php echo $current_view === 'barry' ? 'active' : ''; ?>">Barry</a>
        </div>
        
     <a href="inbox.php?logout=true" class="logout-btn">Logout</a>
    </div>



    <div id="sub-nav">
        <span id="breadcrumb-text">Leads / <?php echo ucfirst($current_view); ?></span>
    </div>

    <div class="main-container">
        
        <div id="sidebar" id="inbox-container">
           <?php 
            // 1. Set up the dynamic filter based on the top nav buttons
            $where_clause = "1=1"; // Shows everything by default
            
            if ($current_view === 'george') {
                $where_clause = "b.assigned_to = 'george'";
            } elseif ($current_view === 'barry') {
                $where_clause = "b.assigned_to = 'barry'";
            }

       // 2. Execute the filtered query
            $active = $conn->query("
                SELECT b.phone, b.name, b.is_starred, b.is_archived,
                       (SELECT COUNT(*) FROM messages WHERE student_phone = b.phone AND sender = 'student' AND is_read = 0) as unread_count, 
                       MAX(m.timestamp) as last_msg_time,
                       (SELECT message_text FROM messages WHERE student_phone = b.phone ORDER BY timestamp DESC, id DESC LIMIT 1) as last_msg_text,
                       (SELECT message_type FROM messages WHERE student_phone = b.phone ORDER BY timestamp DESC, id DESC LIMIT 1) as last_msg_type
                FROM bot_states b 
                LEFT JOIN messages m ON b.phone = m.student_phone 
                WHERE $where_clause
                GROUP BY b.phone 
                ORDER BY last_msg_time DESC
            ");;
            
            $conversation_count = $active ? $active->num_rows : 0;
            ?>
            <div class="sidebar-header">
                <div class="sidebar-header-left">
                    Conversations
                   
                </div>
             <button class="header-icon-btn" id="sidebar-archive-view-toggle" title="Toggle Archived Conversations">
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
        $is_archived = isset($row['is_archived']) ? $row['is_archived'] : 0;
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
        <div class="chat-item" 
             data-phone="<?php echo htmlspecialchars($row['phone']); ?>" 
             data-archived="<?php echo $is_archived; ?>" 
             data-unread="<?php echo $row['unread_count']; ?>" 
             data-starred="<?php echo $is_starred; ?>"
             style="<?php echo $is_archived ? 'display: none;' : 'display: flex;'; ?>">
             
            <a class="chat-link" href="inbox.php?chat=<?php echo urlencode($row['phone']); ?>">
                <div class="chat-name-row">
                    <div style="display: flex; align-items: center; padding-right: 10px; flex: 1; min-width: 0;">
                        <span class="chat-name" style="white-space: nowrap; flex-shrink: 0;">
                            <?php echo htmlspecialchars($row['name']); ?>
                        </span>
                        <span style="font-size: 0.8em; color: var(--text-muted); margin-left: 8px; font-weight: normal; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0;">
                            <?php echo htmlspecialchars($row['phone']); ?>
                        </span>
                    </div>
                    
                    <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                        <span class="unread-badge" <?php if($row['unread_count'] == 0) echo 'style="display:none;"'; ?>>
                            <?php echo $row['unread_count']; ?>
                        </span>
                        <span class="chat-time-sidebar">
                            <?php echo $formatted_time; ?>
                        </span>
                    </div>
                </div>
                <span class="chat-last-message"><?php echo htmlspecialchars($last_msg); ?></span>
            </a>
            
            <button class="star-btn <?php echo $is_starred ? 'is-starred' : ''; ?>" 
                    data-phone="<?php echo htmlspecialchars($row['phone']); ?>" 
                    data-status="<?php echo $is_starred; ?>"
                    data-starred="<?php echo $is_starred; ?>"
                    title="Star/Unstar Chat"
                    style="<?php echo $is_starred ? 'color: var(--brand-gold);' : ''; ?>">
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
                
                // --- NEW CODE: SEND READ RECEIPT TO META ---
                $msg_id_query = $conn->query("SELECT message_id FROM messages WHERE student_phone = '$current_chat' AND sender = 'student' AND message_id IS NOT NULL ORDER BY timestamp DESC LIMIT 1");
                
                if ($msg_id_query && $msg_id_query->num_rows > 0) {
                    $last_msg = $msg_id_query->fetch_assoc();
                    $last_msg_id = $last_msg['message_id']; 
                    
                    // Define your token here so this block can use it
                    $access_token = 'EAANeDzAb8iQBRSOhmrZAc3UXvt7uEquOj67ZCVKlS4ZBHxmJkIuLrWNoOTPWmdUgQcthaSVGMU8pu4vLgILge4x6ry62N0dLWrK7RoRb39ZC5ijZAZBZB8MIXGTFKslfVVQxo18cikxZB4ooSvI0f5vhidHW3PRNkZCOEVKSc5DSl6CDXgLCRBXLrBEgX2wHlVgZDZD'; 

                    $read_data = [
                        'messaging_product' => 'whatsapp',
                        'status' => 'read',
                        'message_id' => $last_msg_id
                    ];

                    $ch = curl_init("https://graph.facebook.com/v17.0/1192797650583688/messages");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($read_data));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $access_token,
                        'Content-Type: application/json'
                    ]);
                    curl_exec($ch);
                    curl_close($ch);
                }
                // --- END NEW CODE ---

          // Fetch Student Name, Archive, AND Star Status
            $chat_name = $current_chat;
            $is_chat_archived = 0; // Default to active
            $is_chat_starred = 0;  // Default to unstarred
            
            $stmt_name = $conn->prepare("SELECT name, is_archived, is_starred FROM bot_states WHERE phone = ?");
            if ($stmt_name) {
                $stmt_name->bind_param("s", $current_chat);
                $stmt_name->execute();
                $stmt_name->bind_result($db_name_result, $db_archived_result, $db_starred_result);
                if ($stmt_name->fetch()) {
                    if (!empty($db_name_result)) {
                        $chat_name = $db_name_result;
                    }
                    $is_chat_archived = $db_archived_result; 
                    $is_chat_starred = $db_starred_result;
                }
                $stmt_name->close();
            }

            // SECURED: Prepared statement to fetch chat history safely
            $stmt_hist = $conn->prepare("SELECT * FROM messages WHERE student_phone = ? ORDER BY timestamp ASC");
            $stmt_hist->bind_param("s", $current_chat);
            $stmt_hist->execute();
            $history = $stmt_hist->get_result();
            ?>
             
            <div class="chat-header">
                <span><?php echo htmlspecialchars($chat_name); ?></span>
                
                <div>
                    <!-- ARCHIVE BUTTON -->
                    <button class="header-icon-btn archive-toggle-btn"
                            title="<?php echo $is_chat_archived ? 'Unarchive this chat' : 'Archive this chat'; ?>" 
                            data-phone="<?php echo htmlspecialchars($current_chat); ?>"
                            data-archived="<?php echo $is_chat_archived; ?>"
                            style="<?php echo $is_chat_archived ? 'color: var(--brand-gold); margin-left: 10px;' : 'margin-left: 10px;'; ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 8v13H3V8"></path>
                            <path d="M1 3h22v5H1z"></path>
                            <path d="M10 12h4"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div id="msg-history">
                <?php while($msg = $history->fetch_assoc()): ?>
                    <div class="message <?php echo $msg['sender']; ?>">
                        <div class="sender-name"><?php echo ucfirst($msg['sender']); ?></div>
                        
                        <?php 
                        if (!empty($msg['media_url'])): 
                            $media_filename = basename(str_replace('\\', '/', $msg['media_url']));
                            $media_link = 'media.php?file=' . rawurlencode($media_filename);
                            if ($msg['message_type'] === 'document'): ?>
                                <a href="<?php echo htmlspecialchars($media_link); ?>" target="_blank" rel="noopener" style="display: block; margin-bottom: 5px;">📎 Download Document</a>
                            <?php else: ?>
                                <img src="<?php echo htmlspecialchars($media_link); ?>" style="max-width: 100%; border-radius: 8px; margin-bottom: 5px; display: block;">
                            <?php endif; 
                        endif; ?>

                        <?php if (!empty($msg['message_text'])): ?>
                            <div class="message-text"><?php echo nl2br(htmlspecialchars($msg['message_text'])); ?></div>
                        <?php endif; ?>
                        
                        <div class="message-time">
                            <?php echo date('g:i A', strtotime($msg['timestamp'])); ?>
                            <?php
                            if ($msg['sender'] == 'agent') {
                                if (isset($msg['status']) && $msg['status'] == 'read') {
                                    echo "<span style='color: #34b7f1; margin-left: 4px;'>✔✔</span>";
                                } elseif (isset($msg['status']) && $msg['status'] == 'delivered') {
                                    echo "<span style='color: #999; margin-left: 4px;'>✔✔</span>";
                                } else {
                                    echo "<span style='color: #999; margin-left: 4px;'>✔</span>";
                                }
                            }
                            ?>
                        </div>
                    </div>
                <?php endwhile; ?>
                <?php $stmt_hist->close(); ?>
            </div>
                        
            <div id="image-preview-container" style="display: none; padding: 10px 30px; background: #f9f9fb; border-top: 1px solid var(--border); align-items: center;">
                <img id="image-preview" src="" style="max-height: 80px; border-radius: 8px; display: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <span id="file-name-preview" style="margin-left: 15px; font-size: 0.9em; color: var(--text-muted); font-weight: bold;"></span>
                <button type="button" onclick="clearPreview()" style="background: none; color: #d9534f; border: none; cursor: pointer; padding: 5px 10px; margin-left: auto; font-weight: bold;">✖ Remove File</button>
            </div>

            <form class="chat-input-area" method="POST" enctype="multipart/form-data" id="chat-form">
                <input type="hidden" name="student_phone" value="<?php echo htmlspecialchars($current_chat); ?>">
                <input type="file" name="file_upload" id="file_upload" style="display:none;" accept="image/*,.pdf,.doc,.docx">
                
                <button type="button" class="attach-btn" onclick="document.getElementById('file_upload').click()" title="Attach File">
                    <svg width="28" height="28" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10" fill="#ffffff" stroke="#000000" stroke-width="1.5" />
                        <path d="M12 7v10M7 12h10" stroke="#007bff" stroke-width="2" stroke-linecap="round" />
                    </svg>
                </button>
                
                <input type="text" name="reply_text" class="chat-text-input" placeholder="Type a reply...">
                <button type="submit" id="send-btn" class="send-btn">Send</button>
            </form>

        <?php else: ?>
            <div class="empty-state">
                <img src="assets/welcome_logo.png" alt="Welcome" style="width: 150px; height: auto; margin-bottom: 20px;">
                <h2>Welcome to the CEA Shared Inbox</h2>
                <p>Select a chat from the sidebar to start replying.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- FILE ATTACHMENT & PREVIEW SCRIPT -->
<script>
document.getElementById('file_upload')?.addEventListener('change', function() {
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
    const fileInput = document.getElementById('file_upload');
    if (fileInput) fileInput.value = '';
    document.getElementById('image-preview-container').style.display = 'none';
    document.getElementById('image-preview').src = '';
}

document.getElementById('chat-form')?.addEventListener('submit', function() {
    const btn = document.getElementById('send-btn');
    if (btn) {
        btn.innerHTML = 'Sending...';
        btn.style.opacity = '0.7';
        btn.disabled = true;
    }
});
</script>

<!-- MASTER APPLICATION LOGIC & SINGLE SYNC ENGINE -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('chat-search');
    const filterBtns = document.querySelectorAll('.filter-btn');
    const viewArchiveBtn = document.getElementById('sidebar-archive-view-toggle');
    const historyDiv = document.getElementById('msg-history');
    
    let currentFilter = 'all';
    let showingArchived = false;

    // --- MASTER FILTER ENGINE ---
    function applyFilters() {
        const searchText = searchInput ? searchInput.value.toLowerCase().trim() : '';
        const chatItems = document.querySelectorAll('.chat-item'); 

        chatItems.forEach(item => {
            const name = item.querySelector('.chat-name')?.textContent.toLowerCase() || '';
            const phone = item.getAttribute('data-phone')?.toLowerCase() || '';
            const lastMsg = item.querySelector('.chat-last-message')?.textContent.toLowerCase() || '';
            
            const isStarred = item.getAttribute('data-starred') === '1';
            const isArchived = item.getAttribute('data-archived') === '1';
            const unreadCount = parseInt(item.getAttribute('data-unread') || '0', 10);
            const isUnread = unreadCount > 0; 

            if (showingArchived && !isArchived) { item.style.display = 'none'; return; }
            if (!showingArchived && isArchived) { item.style.display = 'none'; return; }

            if (currentFilter === 'unread' && !isUnread) { item.style.display = 'none'; return; }
            if (currentFilter === 'starred' && !isStarred) { item.style.display = 'none'; return; }

            const matchesSearch = name.includes(searchText) || phone.includes(searchText) || lastMsg.includes(searchText);
            item.style.display = matchesSearch ? 'flex' : 'none';
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

    if (viewArchiveBtn) {
        viewArchiveBtn.addEventListener('click', function() {
            showingArchived = !showingArchived;
            this.style.color = showingArchived ? 'var(--brand-gold)' : '';
            applyFilters();
        });
    }

    // --- HANDLE STARRING CHATS ---
    document.addEventListener('click', function(e) {
        const starBtn = e.target.closest('.star-btn');
        if (starBtn) {
            e.preventDefault();
            e.stopPropagation();
            
            const phone = starBtn.getAttribute('data-phone');
            const currentStatus = starBtn.getAttribute('data-starred') || starBtn.getAttribute('data-status');
            
            fetch(`inbox.php?action=toggle_star&phone=${phone}&status=${currentStatus}`)
                .then(response => response.text())
                .then(newStatus => {
                    const isNowStarred = newStatus.trim() === '1';
                    const targetState = isNowStarred ? '1' : '0';

                    starBtn.setAttribute('data-starred', targetState);
                    starBtn.setAttribute('data-status', targetState);
                    starBtn.style.color = isNowStarred ? 'var(--brand-gold)' : '';
                    
                    const parentChatItem = starBtn.closest('.chat-item');
                    if (parentChatItem) {
                        parentChatItem.setAttribute('data-starred', targetState);
                    }
                    applyFilters();
                })
                .catch(err => console.error('Error toggling star:', err));
        }
    });

    // --- HANDLE ARCHIVING CHATS ---
    const archiveBtn = document.querySelector('.chat-header .archive-toggle-btn');
    if (archiveBtn) {
        archiveBtn.addEventListener('click', function() {
            const phone = this.getAttribute('data-phone');
            const currentStatus = this.getAttribute('data-archived'); 
            
            fetch(`inbox.php?action=toggle_archive&phone=${phone}&status=${currentStatus}`)
                .then(response => response.text())
                .then(() => {
                    window.location.href = 'inbox.php'; 
                });
        });
    }

    // Auto scroll to bottom on load
    if (historyDiv) {
        historyDiv.scrollTop = historyDiv.scrollHeight;
    }

    // --- SINGLE 10-SECOND BACKGROUND SYNC ENGINE ---
    setInterval(function() {
        const urlParams = new URLSearchParams(window.location.search);
        const currentChat = urlParams.get('chat');
        const cacheBuster = new Date().getTime();

        const targetUrl = currentChat 
            ? 'inbox.php?chat=' + encodeURIComponent(currentChat) + '&t=' + cacheBuster
            : 'inbox.php?t=' + cacheBuster;

        fetch(targetUrl)
            .then(response => {
                if (!response.ok) throw new Error('HTTP status ' + response.status);
                return response.text();
            })
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');

                // Update Active Chat Messages
                const newHistory = doc.getElementById('msg-history');
                const historyDiv = document.getElementById('msg-history');
                
                if (newHistory && historyDiv) {
                    const isScrolledToBottom = historyDiv.scrollHeight - historyDiv.clientHeight <= historyDiv.scrollTop + 50;
                    historyDiv.innerHTML = newHistory.innerHTML;
                    if (isScrolledToBottom) {
                        historyDiv.scrollTop = historyDiv.scrollHeight;
                    }
                }

                // Update Sidebar Chat List
                const newSidebar = doc.querySelector('.chat-list') || doc.querySelector('.sidebar');
                const currentSidebar = document.querySelector('.chat-list') || document.querySelector('.sidebar');
                
                if (newSidebar && currentSidebar) {
                    currentSidebar.innerHTML = newSidebar.innerHTML;
                    applyFilters();
                }
            })
            .catch(error => console.error('Sync Error:', error));
    }, 10000); // Running every 10s prevents server 504 timeouts
});
</script>
</body>
</html>