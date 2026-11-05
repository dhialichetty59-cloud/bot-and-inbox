<?php
// 1. MASTER ERROR LOGGER
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
error_reporting(E_ALL);

$input = file_get_contents('php://input');

$account_home = '/usr/home/' . basename(dirname(__DIR__));
$config_path = $account_home . DIRECTORY_SEPARATOR . 'cea-bot-config.php';
if (!is_file($config_path) || !is_readable($config_path)) {
    error_log("Required bot configuration file is missing or unreadable at the expected private path.");
    http_response_code(500);
    exit('Service temporarily unavailable.');
}

$bot_config = require $config_path;
if (!is_array($bot_config)) {
    error_log("Bot configuration file did not return an array.");
    http_response_code(500);
    exit('Service temporarily unavailable.');
}
$required_config = [
    'database' => ['host', 'username', 'password', 'name'],
    'whatsapp' => ['access_token', 'phone_number_id', 'verify_token']
];
foreach ($required_config as $section => $keys) {
    if (!isset($bot_config[$section]) || !is_array($bot_config[$section])) {
        error_log("Required bot configuration section is missing or invalid: " . $section);
        http_response_code(500);
        exit('Service temporarily unavailable.');
    }
    foreach ($keys as $key) {
        if (!isset($bot_config[$section][$key]) || !is_string($bot_config[$section][$key]) || $bot_config[$section][$key] === '') {
            error_log("Required bot configuration key is missing or invalid: " . $section . "." . $key);
            http_response_code(500);
            exit('Service temporarily unavailable.');
        }
    }
}

$db_host = $bot_config['database']['host'];
$db_user = $bot_config['database']['username'];
$db_pass = $bot_config['database']['password'];
$db_name = $bot_config['database']['name'];

$conn = null;
try {
    mysqli_report(MYSQLI_REPORT_OFF);
    $conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        error_log("Database Connection Failed: " . $conn->connect_error);
        $conn = null;
    }
} catch (Throwable $t) {
    error_log("Database Connection Exception: " . $t->getMessage());
    $conn = null;
}

// --- CONFIGURATION ---
$access_token = $bot_config['whatsapp']['access_token'];
$phone_number_id = $bot_config['whatsapp']['phone_number_id'];
$verify_token = $bot_config['whatsapp']['verify_token'];

// --- DEPARTMENT ROUTING ---
$george_admin = '27849468033'; 
$george_email = 'georgem@cea.ac.za'; 
$barry_sales  = '27636896768'; 
$barry_email  = 'barryh@cea.ac.za';
$reminder = is_agent_available() ? "\n\n_Reply *Human* to speak to an agent._" : "";

// --- HANDSHAKE ---
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['hub_verify_token']) && $_GET['hub_verify_token'] === $verify_token) {
        echo $_GET['hub_challenge']; exit;
    }
}

// --- RECEIVE MESSAGE ---
$data = json_decode($input, true);

$webhook_messages = [];
$webhook_statuses = [];
$webhook_retry_required = false;
$entries = is_array($data) && is_array($data['entry'] ?? null) ? $data['entry'] : [];
foreach ($entries as $entry) {
    if (!is_array($entry) || !is_array($entry['changes'] ?? null)) {
        continue;
    }

    foreach ($entry['changes'] as $change) {
        if (!is_array($change) || !is_array($change['value'] ?? null)) {
            continue;
        }

        $value = $change['value'] ?? [];

        foreach ((is_array($value['messages'] ?? null) ? $value['messages'] : []) as $message) {
            if (is_array($message)) {
                $webhook_messages[] = ['message' => $message, 'value' => $value];
            }
        }

        foreach ((is_array($value['statuses'] ?? null) ? $value['statuses'] : []) as $status) {
            if (is_array($status)) {
                $webhook_statuses[] = $status;
            }
        }
    }
}

foreach ($webhook_statuses as $status_data) {
    if (empty($status_data['id']) || empty($status_data['status'])) {
        continue;
    }
    if (!$conn) {
        $webhook_retry_required = true;
        continue;
    }

    $stmt_status = $conn->prepare("UPDATE messages SET status = ? WHERE message_id = ?");
    if (!$stmt_status) {
        error_log("Unable to prepare WhatsApp status update: " . $conn->error);
        $webhook_retry_required = true;
        continue;
    }

    $stmt_status->bind_param("ss", $status_data['status'], $status_data['id']);
    if (!$stmt_status->execute()) {
        error_log("Unable to save WhatsApp status update: " . $stmt_status->error);
        $webhook_retry_required = true;
    }
    $stmt_status->close();
}

foreach ($webhook_messages as $webhook_message) {
    $msg = $webhook_message['message'];
    $value = $webhook_message['value'];
    $message_id = $msg['id'] ?? null;
    $claim_token = null;
    if (!empty($message_id)) {
        $claim_result = claim_inbound_message($message_id);
        if ($claim_result['status'] === 'duplicate') {
            continue;
        }
        if ($claim_result['status'] !== 'claimed') {
            $webhook_retry_required = true;
            continue;
        }
        $claim_token = $claim_result['claim_token'];
    } else {
        error_log("WhatsApp webhook message has no message ID; duplicate protection was not applied.");
    }

    if (empty($msg['from'])) {
        error_log("WhatsApp webhook message is missing the sender phone number.");
        if ($claim_token !== null && !complete_inbound_message($message_id, $claim_token)) {
            $webhook_retry_required = true;
        }
        continue;
    }

    $from = $msg['from'];
    $name = $value['contacts'][0]['profile']['name'] ?? 'Student';
    
    // Extract the WhatsApp Message ID
    $payload = "";
    $incoming_content = "";
    $pending_lead_request = null;

    if (isset($msg['type']) && $msg['type'] == 'interactive') {
        if (isset($msg['interactive']['button_reply'])) {
            $payload = $msg['interactive']['button_reply']['id'];
            $incoming_content = $msg['interactive']['button_reply']['title'];
        } elseif (isset($msg['interactive']['list_reply'])) {
            $payload = $msg['interactive']['list_reply']['id'];
            $incoming_content = $msg['interactive']['list_reply']['title'];
        }
    } 
    // --- DETECT DELETED MESSAGES ---
    elseif (isset($msg['type']) && $msg['type'] == 'unsupported' && isset($msg['errors'][0]['code']) && $msg['errors'][0]['code'] == 131051) {
        $payload = 'ignore_action'; 
        $incoming_content = "🚫 _[Student deleted a message]_"; 
    } 
    // --- SAVE INBOUND MEDIA TO THE STAFF INBOX ---
    elseif (in_array($msg['type'] ?? '', ['image', 'document'], true)) {
        $media_type = $msg['type'];
        $media = $msg[$media_type];
        $media_id = $media['id'] ?? '';
        $caption = trim($media['caption'] ?? '');
        $original_filename = $media_type === 'document' ? ($media['filename'] ?? 'document') : 'image.jpg';
        $safe_media_id = preg_replace('/[^a-zA-Z0-9_-]/', '', $media_id);
        $safe_filename = substr(preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($original_filename)), 0, 120);
        $relative_path = 'uploads/' . bin2hex(random_bytes(8)) . '_' . $safe_media_id . '_' . $safe_filename;
        $absolute_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative_path);
        $meta_url = $media_id !== '' ? get_media_url($media_id, $access_token) : null;

        $saved = $meta_url && save_media_to_server($meta_url, $absolute_path, $access_token);
        $logged = false;
        if ($saved && $conn) {
            $message_text = $caption !== '' ? $caption : ($media_type === 'image' ? '[Image attached]' : '[Document attached: ' . $safe_filename . ']');
            $stmt_media = $conn->prepare("INSERT INTO messages (student_phone, sender, message_text, media_url, message_type, message_id) VALUES (?, 'student', ?, ?, ?, ?)");
            if ($stmt_media) {
                $stmt_media->bind_param("sssss", $from, $message_text, $relative_path, $media_type, $message_id);
                $logged = $stmt_media->execute();
                if (!$logged) {
                    error_log("Unable to save inbound WhatsApp media to the inbox: " . $stmt_media->error);
                }
                $stmt_media->close();
            } else {
                error_log("Unable to prepare inbound WhatsApp media inbox record: " . $conn->error);
            }
        } elseif (!$saved) {
            error_log("Unable to download inbound WhatsApp media.");
        } else {
            error_log("Inbound WhatsApp media was downloaded but could not be saved to the inbox because the database is unavailable.");
        }

        if (!$logged) {
            if ($saved && file_exists($absolute_path)) {
                unlink($absolute_path);
            }
            send_text($from, "Sorry, we couldn't receive your attachment just now. Please try again later.", $access_token, $phone_number_id, "");
        } else {
            send_text(
                $from,
                "Thanks, we received your attachment and added it to the staff inbox for review. Please send images or documents only for course enquiries or applications. This number is not for job applications, so please don't send CVs.",
                $access_token,
                $phone_number_id,
                ""
            );
        }
        $payload = 'ignore_action';
        $incoming_content = '';
    }
    
    // --- STANDARD TEXT MESSAGES ---
  // --- STANDARD TEXT MESSAGES ---
    else {
        $text_body = trim($msg['text']['body'] ?? '');
        $text = strtolower($text_body);
        $incoming_content = $msg['text']['body'] ?? '';
        $payload = 'start_menu';
        $is_silenced = false;

        if ($conn) {
            $stmt = $conn->prepare("SELECT state FROM bot_states WHERE phone = ?");
            if ($stmt) {
                $stmt->bind_param("s", $from);
                $stmt->execute();
                $stmt->bind_result($db_state);

                if ($stmt->fetch() && !empty($db_state)) {
                    if (strpos($db_state, 'awaiting_lead_email:') === 0) {
                        $pending_lead_request = substr($db_state, strlen('awaiting_lead_email:'));
                        $payload = 'lead_email_submit';
                    } elseif ($db_state === 'silenced' || $db_state === 'waiting_for_human') {
                        $is_silenced = true;
                    } else {
                        $payload = $db_state;
                    }
                }
                $stmt->close();
            } else {
                error_log("Unable to prepare bot state lookup for incoming text: " . $conn->error);
            }
        }

        if ($payload !== 'lead_email_submit' && $text === 'human') {
            $payload = 'talk_human';
        } elseif ($payload !== 'lead_email_submit' && !$is_silenced) {
            send_text($from, "🤔 I didn't quite catch that. Please use the menu buttons below:", $access_token, $phone_number_id, "");
        } elseif ($is_silenced) {
            $payload = 'ignore_action';
        }
    }
    
    // --- LOG TEXT MESSAGES TO INBOX ---
    if (!empty($incoming_content) && $conn) {
        $stmt_msg = $conn->prepare("INSERT INTO messages (student_phone, sender, message_text, message_id) VALUES (?, 'student', ?, ?)");
        if ($stmt_msg) {
            $stmt_msg->bind_param("sss", $from, $incoming_content, $message_id);
            $stmt_msg->execute();
            $stmt_msg->close();
        } else {
            error_log("Inbox Database Insertion Failed: " . $conn->error);
        }
    }

// --- BULLETPROOF USER PROFILE SAVING ---
    // 1. Check if this student already exists in the database
    $user_exists = false;
    if ($conn) {
        $stmt_check = $conn->prepare("SELECT phone FROM bot_states WHERE phone = ?");
        if ($stmt_check) {
            $stmt_check->bind_param("s", $from);
            $stmt_check->execute();
            if ($stmt_check->fetch()) {
                $user_exists = true;
            }
            $stmt_check->close();
        }
        
        // 2. If they DO NOT exist, create a brand new profile
        if (!$user_exists) {
            $stmt_insert = $conn->prepare("INSERT INTO bot_states (phone, name, state) VALUES (?, ?, 'start_menu')");
            if ($stmt_insert) {
                $stmt_insert->bind_param("ss", $from, $name);
                $stmt_insert->execute();
                $stmt_insert->close();
            }
        } 
        // 3. If they DO exist, ONLY update their name if Meta provided a real one (not the fallback)
        elseif ($name !== 'Student') {
            $stmt_update = $conn->prepare("UPDATE bot_states SET name = ? WHERE phone = ?");
            if ($stmt_update) {
                $stmt_update->bind_param("ss", $name, $from);
                $stmt_update->execute();
                $stmt_update->close();
            }
        }
    }

    // --- SAVE THE USER'S LAST MENU POSITION ---
    // If they clicked a menu button, save it as their last location
    if (isset($msg['type']) && $msg['type'] == 'interactive') {
        // We do NOT want to save 'pdf_' or 'reg_' as a menu state, only navigation buttons!
        if (substr($payload, 0, 4) !== 'reg_' && substr($payload, 0, 4) !== 'pdf_') {
            if ($conn) {
                $stmt = $conn->prepare("UPDATE bot_states SET state = ? WHERE phone = ?");
                $stmt->bind_param("ss", $payload, $from);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
  

    // --- DATABASE SILENCE CHECK ---
    $state_row = null;
    if ($conn) {
        try {
            $stmt = $conn->prepare("SELECT state FROM bot_states WHERE phone = ?");
            if ($stmt) {
                $stmt->bind_param("s", $from);
                $stmt->execute();
                $stmt->bind_result($db_state);
                if ($stmt->fetch()) {
                    $state_row = ['state' => $db_state];
                }
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log("Database Query Exception: " . $e->getMessage());
        }
    }

  if ($state_row && $state_row['state'] == 'silenced') {
        $incoming_text = trim(strtolower($msg['text']['body'] ?? ''));
        if ($incoming_text == 'bot on' && ($from == $george_admin || $from == $barry_sales)) {
            // Update the state back to the menu instead of deleting the user!
            if ($conn) {
                $stmt_reactivate = $conn->prepare("UPDATE bot_states SET state = 'start_menu' WHERE phone = ?");
                if ($stmt_reactivate) {
                    $stmt_reactivate->bind_param("s", $from);
                    $stmt_reactivate->execute();
                    $stmt_reactivate->close();
                }
            }
            send_text($from, "🤖 *Bot reactivated for this chat.*", $access_token, $phone_number_id, "");
            if ($claim_token !== null && !complete_inbound_message($message_id, $claim_token)) {
                $webhook_retry_required = true;
            }
            continue;
        }

        if (!(strtolower($payload) === 'talk_human' && !is_agent_available())) {
            if ($claim_token !== null && !complete_inbound_message($message_id, $claim_token)) {
                $webhook_retry_required = true;
            }
            continue;
        }
    }

    // --- FUNNEL ROUTING ---
    if ($payload == 'start_menu') {
        send_main_menu($from, "Hi $name! Welcome to Career Excel Academy. I'm Cam, your virtual assistant. We are an education and training academy, not a recruitment agency for built-environment jobs. For roles at CEA, see https://cea.ac.za/vacancies-at-career-excel-academy/. Choose an option to explore courses:", $access_token, $phone_number_id, "");
    }
    
 elseif ($payload == 'view_categories') {
        send_list($from, "Course Categories", "Choose a course area or useful information:", "Explore", [
            ["id" => "cat_higher", "title" => "🎓 Higher Certificates"],
            ["id" => "cat_1year", "title" => "📅 One Year Courses"],
            ["id" => "cat_short", "title" => "⏱️ Short Courses"],
            ["id" => "cat_self", "title" => "💻 Flexible Online"],
            ["id" => "cat_more", "title" => "➕ More Programmes"],
            ["id" => "course_calendar", "title" => "🗓️ Course Dates"],
            ["id" => "admissions_info", "title" => "📝 Admissions & RPL"]
        ], "", $access_token, $phone_number_id, $reminder); 
    }
    
    elseif ($payload == 'cat_higher') {
        send_list($from, "Higher Certificates", "Select a course:", "Courses", [
            ["id" => "opt_hc_cqs", "title" => "Quant. Surveying"], 
            ["id" => "opt_hc_fam", "title" => "Facilities & Asset Mgt"],
            ["id" => "opt_hc_cpm", "title" => "Construction Project Mgt"]
        ], "view_categories", $access_token, $phone_number_id, $reminder);
    }
    
    elseif ($payload == 'cat_1year') {
        send_list($from, "One Year Courses", "Select a course:", "Courses", [
            ["id" => "opt_1y_pd", "title" => "Property Development"],
            ["id" => "opt_1y_cre", "title" => "Commercial Real Estate"]
        ], "view_categories", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'cat_more') {
        send_list($from, "More Programmes", "Explore additional CEA training:", "Programmes", [
            ["id" => "opt_skill_handyperson", "title" => "Assistant Handyperson"],
            ["id" => "opt_sc_shopping", "title" => "Shopping Centre Mgt"],
            ["id" => "property_course_catalog", "title" => "Property Short Courses"],
            ["id" => "online_course_catalog", "title" => "Online Course Catalogue"],
            ["id" => "cpd_course_catalog", "title" => "CPD Course Catalogue"]
        ], "view_categories", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'course_calendar') {
        send_text($from, "For the latest course dates, delivery options and upcoming intakes, see CEA's live course calendar:\nhttps://cea.ac.za/course-calendar/", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'admissions_info') {
        send_text($from, "For current entry requirements and application guidance, visit:\nAdmissions: https://cea.ac.za/admissions/\nRecognition of Prior Learning (RPL): https://cea.ac.za/recognition-of-prior-learning-rpl/\n\nRPL may provide an alternative route for applicants with relevant experience.", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'property_course_catalog') {
        send_text($from, "Explore CEA's property short courses and bundles:\nhttps://cea.ac.za/property-short-courses-deparment/", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'online_course_catalog') {
        send_text($from, "Explore CEA's certified online courses, including introductory courses and Flexible Online Learning programmes:\nhttps://cea.ac.za/self-paced-courses2/", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'cpd_course_catalog') {
        send_text($from, "Explore CEA's CPD-validated courses and available disciplines:\nhttps://cea.ac.za/cpd-courses/", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'cat_short') {
        send_list($from, "Short Courses", "Select a field:", "Fields", [
            ["id" => "sub_short_fac", "title" => "Facilities & Asset Mgt"],
            ["id" => "sub_short_cpm", "title" => "Const. Project Mgt"],
            ["id" => "sub_short_prop", "title" => "Property Development"]
        ], "cat_short", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'sub_short_fac') {
        send_list($from, "Facilities & Asset Mgt", "Select an option:", "Short Courses", [
            ["id" => "opt_sf_fm5", "title" => "5 Day Facilities Mgt"],
            ["id" => "opt_sf_fm3", "title" => "3 Day Facilities Mgt"],
            ["id" => "opt_sf_fm1", "title" => "1 Day Facilities Mgt"],
            ["id" => "opt_sf_iam5", "title" => "5 Day Immovable Asset"],
            ["id" => "opt_sf_iam3", "title" => "3 Day Immovable Asset"],
            ["id" => "opt_sf_iam1", "title" => "1 Day Immovable Asset"],
            
        ], "cat_short", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'sub_short_cpm') {
        send_list($from, "Const. Project Mgt", "Select an option:", "Short Courses", [
            ["id" => "opt_sc_cpm5", "title" => "5 Day CPM"],
            ["id" => "opt_sc_cpm3", "title" => "3 Day CPM"],
            ["id" => "opt_sc_cpm1", "title" => "1 Day CPM"],
            ["id" => "opt_sc_ct5", "title" => "5 Day Const. Tech"],
            ["id" => "opt_sc_cpmc5", "title" => "5 Day CPM Control"]
        ], "cat_short", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'sub_short_prop') {
        send_list($from, "Property & Shopping Centre", "Select an option:", "Short Courses", [
            ["id" => "opt_sp_pd5", "title" => "5 Day Property Dev"],
            ["id" => "opt_sp_pd3", "title" => "3 Day Property Dev"],
            ["id" => "opt_sp_pd1", "title" => "1 Day Property Dev"],
            ["id" => "opt_sc_shopping", "title" => "Shopping Centre Mgt"]
        ], "cat_short", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'cat_self') {
        send_list($from, "Flexible Online Learning", "Choose a programme:", "Courses", [
            ["id" => "opt_spc_cpm", "title" => "CPM - Flexible Online"],
            ["id" => "opt_spc_fm", "title" => "FM - Flexible Online"],
            ["id" => "opt_spc_pd", "title" => "Property Dev - Flex"]
        ], "view_categories", $access_token, $phone_number_id, $reminder);
    }

    elseif (in_array($payload, ['opt_hc_cpm', 'opt_1y_cre', 'opt_skill_handyperson', 'opt_sc_shopping'], true)) {
        $info = get_course_info($payload);
        $message = "*Course:* " . $info['title'] . "\n\n*Description:* " . $info['desc'] . "\n*Duration:* " . $info['duration'] . "\n*Current fees:* https://cea.ac.za/fees/\n*Upcoming dates:* https://cea.ac.za/course-calendar/";
        if (substr($payload, 0, 7) === 'opt_hc_' || substr($payload, 0, 7) === 'opt_1y_') {
            $message .= "\n\nFor current entry requirements and alternative routes, see:\nhttps://cea.ac.za/admissions/";
        }
        send_course_details($from, $message, "reg_".$payload, "pdf_".$payload, "view_categories", $access_token, $phone_number_id, $reminder);
    }

    elseif (substr($payload, 0, 4) === 'opt_') {
        $info = get_course_info($payload);
        $message = "*Course:* " . $info['title'] . "\n\n";
        
        if (substr($payload, 0, 7) === 'opt_hc_' || substr($payload, 0, 7) === 'opt_1y_') {
            $message .= "For current entry requirements and alternative routes, see:\nhttps://cea.ac.za/admissions/\n\n";
        }
        
        $message .= "*Description:* " . $info['desc'] . "\n*Duration:* " . $info['duration'] . "\n*Current fees:* https://cea.ac.za/fees/\n*Upcoming dates:* https://cea.ac.za/course-calendar/";
        send_course_details($from, $message, "reg_".$payload, "pdf_".$payload, "view_categories", $access_token, $phone_number_id, $reminder);
    }
    
    // --- APPLICATION & HUMAN AGENT LOGIC ---
    elseif ($payload == 'talk_human' || substr($payload, 0, 4) === 'reg_' || $payload === 'lead_email_submit') {
        $is_email_submission = $payload === 'lead_email_submit';
        $lead_action_payload = $is_email_submission ? $pending_lead_request : $payload;
        if ($is_email_submission && (!is_string($lead_action_payload) || !preg_match('/^(talk_human|reg_opt_[a-z0-9_]+)$/', $lead_action_payload))) {
            error_log("Lead email submission did not have a pending request.");
            send_text($from, "Sorry, we couldn't match your reply to a request. Please choose an option from the menu and try again.", $access_token, $phone_number_id, "");
            if ($claim_token !== null && !complete_inbound_message($message_id, $claim_token)) {
                $webhook_retry_required = true;
            }
            continue;
        }

        if (!$is_email_submission) {
            $pending_state = 'awaiting_lead_email:' . $lead_action_payload;
            if (!$conn) {
                send_text($from, "Sorry, we can't record your request right now. Please try again later.", $access_token, $phone_number_id, "");
                if ($claim_token !== null && !complete_inbound_message($message_id, $claim_token)) {
                    $webhook_retry_required = true;
                }
                continue;
            }
            $stmt = $conn->prepare("UPDATE bot_states SET state = ? WHERE phone = ?");
            if (!$stmt) {
                error_log("Unable to prepare lead email state update: " . $conn->error);
                send_text($from, "Sorry, we can't record your request right now. Please try again later.", $access_token, $phone_number_id, "");
                if ($claim_token !== null && !complete_inbound_message($message_id, $claim_token)) {
                    $webhook_retry_required = true;
                }
                continue;
            }
            $stmt->bind_param("ss", $pending_state, $from);
            $state_saved = $stmt->execute() && $stmt->affected_rows === 1;
            if (!$state_saved) {
                error_log("Unable to save pending lead email state: " . $stmt->error);
            }
            $stmt->close();
            if (!$state_saved) {
                send_text($from, "Sorry, we can't record your request right now. Please try again later.", $access_token, $phone_number_id, "");
            } else {
                send_text($from, "To make sure our team can follow up, please reply with your email address.", $access_token, $phone_number_id, "");
            }
            if ($claim_token !== null && !complete_inbound_message($message_id, $claim_token)) {
                $webhook_retry_required = true;
            }
            continue;
        }

        $lead_email = trim($incoming_content);
        if (!filter_var($lead_email, FILTER_VALIDATE_EMAIL) || strlen($lead_email) > 254) {
            send_text($from, "That doesn't look like a valid email address. Please reply with your email address (for example, name@example.com) so our team can follow up.", $access_token, $phone_number_id, "");
            if ($claim_token !== null && !complete_inbound_message($message_id, $claim_token)) {
                $webhook_retry_required = true;
            }
            continue;
        }

        $agent_available = is_agent_available();
        $type = ($lead_action_payload == 'talk_human') ? "Human Assistance" : "Course Application";
        $course_code = str_replace('reg_', '', $lead_action_payload);
        $inquiry = ($lead_action_payload == 'talk_human') ? "Student requested a consultant." : "Application enquiry for " . get_course_title($course_code);

        if ($lead_action_payload == 'talk_human') {
            $target_admin = $barry_sales; 
            $target_email = $barry_email;
        } else {
            if (strpos($course_code, '_hc_') !== false || strpos($course_code, '_1y_') !== false) {
                $target_admin = $george_admin; 
                $target_email = $george_email;
            } else {
                $target_admin = $barry_sales; 
                $target_email = $barry_email;
            }
        }

        if (!log_lead($from, $name, $type . ": " . $inquiry, $lead_email)) {
            send_text($from, "Sorry, we couldn't save your request just now. Please try again later.", $access_token, $phone_number_id, "");
            if ($claim_token !== null && !complete_inbound_message($message_id, $claim_token)) {
                $webhook_retry_required = true;
            }
            continue;
        }

        if ($conn) {
            $assigned_to = ($target_admin === $george_admin) ? 'george' : 'barry';
            $next_state = $agent_available ? 'silenced' : 'start_menu';
            $stmt = $conn->prepare("UPDATE bot_states SET assigned_to = ?, state = ? WHERE phone = ?");
            if ($stmt) {
                $stmt->bind_param("sss", $assigned_to, $next_state, $from);
                if (!$stmt->execute()) {
                    error_log("Unable to update bot state for a lead request: " . $stmt->error);
                }
                $stmt->close();
            } else {
                error_log("Unable to prepare bot state update for a lead request: " . $conn->error);
            }
        }

        $notification_inquiry = $inquiry . "\nEmail: " . $lead_email;
        if ($from !== $target_admin) {
            send_template_alert($target_admin, $type, $name, $from, $notification_inquiry, $access_token, $phone_number_id);
        }

        $headers = "From: bot@cea.ac.za";
        if (!mail($target_email, "New Lead: $name ($type)", "Type: $type\nName: $name\nPhone: $from\nEmail: $lead_email\nInquiry: $inquiry", $headers)) {
            error_log("Lead notification email could not be handed to the mail system for a saved $type request.");
        }

        if ($lead_action_payload === 'talk_human' && !$agent_available) {
            $ack = "Your request has been recorded. A member of our team will contact you as soon as possible. Our consultants are available Monday to Friday, 7:30 am to 4:30 pm (South Africa time).";
        } elseif ($lead_action_payload !== 'talk_human' && !$agent_available) {
            $application_url = "https://cea.ac.za/lets-get-you-started/";
            $ack = "Your interest in *" . get_course_title($course_code) . "* has been recorded. A member of our team will contact you as soon as possible. To start your application enquiry, complete CEA's online form and select this programme:\n$application_url\n\nThis form records your contact details and course interest; it does not itself submit a formal application.";
        } else {
            $ack = ($lead_action_payload == 'talk_human') ? "I've alerted the team! 🙋‍♂️ A member of our team will contact you as soon as possible." : "🎉 Your application interest has been recorded. A member of our team will contact you as soon as possible.";
        }
        send_text($from, $ack, $access_token, $phone_number_id, "");
    }

elseif (substr($payload, 0, 4) === 'pdf_') {
    $code = str_replace('pdf_', '', $payload);
    
    // 1. Safe-guard the title lookup to prevent PHP Fatal Errors
    $title = "";
    if (function_exists('get_course_title')) {
        $title = get_course_title($code);
    }
    
    // 2. Fallback title generator if the code isn't mapped in get_course_title yet
    if (empty($title)) {
        if (strpos($code, '_fm') !== false) {
            $title = "Facilities Management";
        } elseif (strpos($code, '_iam') !== false) {
            $title = "Immovable Asset Management";
        } elseif (strpos($code, '_cpm') !== false) {
            $title = "Construction Project Management";
        } elseif (strpos($code, '_pd') !== false) {
            $title = "Property Development";
        } else {
            $title = "Course";
        }
    }
    
    // Log the transaction for debugging
    $pdf_url = get_course_prospectus_url($code);
    if ($pdf_url === null) {
        $course_url = get_course_page_url($code);
        send_text($from, "A downloadable prospectus isn't currently available for *$title*. View the official course information here:\n$course_url", $access_token, $phone_number_id, $reminder);
    } else {
        log_lead($from, $name, "DOWNLOAD: Prospectus for $title ($code)");
        send_text($from, "Fetching the prospectus for *$title*... 📄", $access_token, $phone_number_id, $reminder);
        $safe_title = preg_replace("/[^a-zA-Z0-9_-]/", "_", $title);
        $safe_title = preg_replace('/__+/', '_', $safe_title);
        $final_filename = $safe_title . "_Prospectus.pdf";
        send_pdf($from, $pdf_url . "?v=" . time(), $final_filename, $access_token, $phone_number_id, $reminder);
    }

    send_post_pdf_menu($from, "reg_".$code, "view_categories", $access_token, $phone_number_id);
}

    if ($claim_token !== null && !complete_inbound_message($message_id, $claim_token)) {
        $webhook_retry_required = true;
    }
}

if ($webhook_retry_required) {
    http_response_code(503);
}

// --- HELPER FUNCTIONS ---

function claim_inbound_message($message_id) {
    global $conn;
    if (!$conn) {
        error_log("Inbound message could not be claimed because the database connection is unavailable.");
        return ['status' => 'error'];
    }

    $claim_token = bin2hex(random_bytes(16));
    $stmt = $conn->prepare("INSERT INTO webhook_message_claims (message_id, claim_token, status) VALUES (?, ?, 'processing')");
    if (!$stmt) {
        error_log("Unable to prepare inbound message claim: " . $conn->error);
        return ['status' => 'error'];
    }

    $stmt->bind_param("ss", $message_id, $claim_token);
    $inserted = $stmt->execute();
    $insert_error = $stmt->errno;
    $stmt->close();
    if ($inserted) {
        return ['status' => 'claimed', 'claim_token' => $claim_token];
    }
    if ($insert_error !== 1062) {
        error_log("Unable to claim inbound WhatsApp message (database error $insert_error).");
        return ['status' => 'error'];
    }

    $stmt = $conn->prepare("UPDATE webhook_message_claims SET claim_token = ?, updated_at = CURRENT_TIMESTAMP WHERE message_id = ? AND status = 'processing' AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 5 MINUTE)");
    if (!$stmt) {
        error_log("Unable to prepare stale inbound message claim recovery: " . $conn->error);
        return ['status' => 'error'];
    }

    $stmt->bind_param("ss", $claim_token, $message_id);
    $reclaimed = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if ($reclaimed) {
        return ['status' => 'claimed', 'claim_token' => $claim_token];
    }

    $stmt = $conn->prepare("SELECT status FROM webhook_message_claims WHERE message_id = ?");
    if (!$stmt) {
        error_log("Unable to inspect existing inbound message claim: " . $conn->error);
        return ['status' => 'error'];
    }

    $stmt->bind_param("s", $message_id);
    $stmt->execute();
    $stmt->bind_result($claim_status);
    $found = $stmt->fetch();
    $stmt->close();
    if (!$found) {
        return ['status' => 'error'];
    }

    return $claim_status === 'completed'
        ? ['status' => 'duplicate']
        : ['status' => 'busy'];
}

function complete_inbound_message($message_id, $claim_token) {
    global $conn;
    if (!$conn) {
        error_log("Unable to complete inbound message claim because the database connection is unavailable.");
        return false;
    }

    $stmt = $conn->prepare("UPDATE webhook_message_claims SET status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE message_id = ? AND claim_token = ? AND status = 'processing'");
    if (!$stmt) {
        error_log("Unable to prepare inbound message completion: " . $conn->error);
        return false;
    }

    $stmt->bind_param("ss", $message_id, $claim_token);
    $completed = $stmt->execute() && $stmt->affected_rows === 1;
    if (!$completed) {
        error_log("Unable to mark an inbound WhatsApp message as completed: " . $stmt->error);
    }
    $stmt->close();
    return $completed;
}

function send_template_alert($to, $type, $name, $phone, $inquiry, $token, $id) {
    $payload = [
        "messaging_product" => "whatsapp", "to" => $to, "type" => "template",
        "template" => ["name" => "new_lead_alert", "language" => ["code" => "en"], "components" => [[
            "type" => "body", "parameters" => [
                ["type" => "text", "text" => $type], ["type" => "text", "text" => $name],
                ["type" => "text", "text" => $phone], ["type" => "text", "text" => $inquiry]
            ]
        ]]]
    ];
    call_api($payload, $token, $id);
}

function send_main_menu($to, $text, $token, $id, $rem) {
    $buttons = [
        ["type" => "reply", "reply" => ["id" => "view_categories", "title" => "📚 View Courses"]]
    ];
    if (is_agent_available()) {
        $buttons[] = ["type" => "reply", "reply" => ["id" => "talk_human", "title" => "🙋‍♂️ Talk to Human"]];
    } else {
        $text .= "\n\nOur consultants are available Monday to Friday, 7:30 am to 4:30 pm (South Africa time).";
    }

    $payload = ["messaging_product" => "whatsapp", "to" => $to, "type" => "interactive", "interactive" => [
        "type" => "button", "body" => ["text" => $text . $rem], "action" => ["buttons" => $buttons]
    ]];
    call_api($payload, $token, $id);
}

function send_list($to, $header, $body, $sec_title, $rows, $back_id, $token, $id, $rem) {
    // 1. Build the main options section
    $sections = [["title" => $sec_title, "rows" => $rows]];
    
    // 2. Only add the "Back" section if a $back_id is actually provided!
    if (!empty($back_id)) {
        $sections[] = ["title" => "Navigation", "rows" => [["id" => $back_id, "title" => "⬅️ Back"]]];
    }

    $payload = [
        "messaging_product" => "whatsapp", 
        "to" => $to, 
        "type" => "interactive", 
        "interactive" => [
            "type" => "list", 
            "header" => ["type" => "text", "text" => $header], 
            "body" => ["text" => $body . $rem],
            "action" => [
                "button" => "Open Menu", 
                "sections" => $sections
            ]
        ]
    ];
    call_api($payload, $token, $id);
}

function send_course_details($to, $text, $reg, $pdf, $back, $token, $id, $rem) {
    $code = substr($reg, 4);
    $prospectus_title = get_course_prospectus_url($code) === null ? "🌐 Course Page" : "📄 View Prospectus";
    $payload = ["messaging_product" => "whatsapp", "to" => $to, "type" => "interactive", "interactive" => [
        "type" => "button", "body" => ["text" => $text . $rem], "action" => ["buttons" => [
            ["type" => "reply", "reply" => ["id" => $reg, "title" => "✅ Apply Now"]],
            ["type" => "reply", "reply" => ["id" => $pdf, "title" => $prospectus_title]],
            ["type" => "reply", "reply" => ["id" => $back, "title" => "⬅️ Back to Courses"]]
        ]]
    ]];
    call_api($payload, $token, $id);
}

function send_text($to, $text, $token, $id, $rem) {
    call_api(["messaging_product" => "whatsapp", "to" => $to, "type" => "text", "text" => ["body" => $text . $rem]], $token, $id);
}

function send_pdf($to, $url, $filename, $token, $phone_number_id) {
    $api_url = "https://graph.facebook.com/v21.0/$phone_number_id/messages";
    
    // The strict JSON structure required by Meta for document delivery
    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type"    => "individual",
        "to"                => $to,
        "type"              => "document",
        "document"          => [
            "link"     => $url,
            "filename" => $filename
        ]
    ];

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ]);
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer " . $token,
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // 📝 DEBUG LOG: This will create a file on your server telling us exactly why it failed
    $log_entry = date('[Y-m-d H:i:s]') . " Sent to: $to | URL: $url | HTTP Code: $http_code | Response: $response" . PHP_EOL;
    file_put_contents(__DIR__ . '/whatsapp_media_errors.log', $log_entry, FILE_APPEND);
}

function send_post_pdf_menu($to, $reg_id, $back_id, $token, $id) {
    $payload = [
        "messaging_product" => "whatsapp", 
        "to" => $to, 
        "type" => "interactive", 
        "interactive" => [
            "type" => "button", 
            "body" => ["text" => "After reading, what would you like to do next?"], 
            "action" => [
                "buttons" => [
                    ["type" => "reply", "reply" => ["id" => $back_id, "title" => "⬅️ Back to Courses"]],
                    ["type" => "reply", "reply" => ["id" => $reg_id, "title" => "✅ Apply Now"]]
                ]
            ]
        ]
    ];
    call_api($payload, $token, $id);
}

function call_api($payload, $token, $id) {
    $ch = curl_init("https://graph.facebook.com/v21.0/$id/messages");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    
    // Capture the response from Meta
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token, "Content-Type: application/json"]);
    $response = curl_exec($ch); 
    curl_close($ch);
    
    // --- NEW: SAVE OUTGOING MESSAGES TO DATABASE ---
    $res_data = json_decode($response, true);
    $meta_message_id = $res_data['messages'][0]['id'] ?? null;
    $recipient_phone = $payload['to'] ?? '';
    
    if ($meta_message_id) {
        global $conn;
        if ($conn) {
            // Figure out what text to save based on the payload type
            $msg_text = "🤖 [Bot Message]";
            if (isset($payload['text']['body'])) {
                $msg_text = $payload['text']['body'];
            } elseif (isset($payload['interactive']['body']['text'])) {
                $msg_text = $payload['interactive']['body']['text'];
            } elseif (isset($payload['template']['name'])) {
                $msg_text = "[Template: " . $payload['template']['name'] . "]";
            }
            
            $stmt = $conn->prepare("INSERT INTO messages (student_phone, sender, message_text, message_id, status) VALUES (?, 'bot', ?, ?, 'sent')");
            if ($stmt) {
                $stmt->bind_param("sss", $recipient_phone, $msg_text, $meta_message_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

function log_lead($phone, $name, $action, $email = null) {
    global $conn;
    if (!$conn) {
        error_log("Lead could not be saved because the database connection is unavailable.");
        return false;
    }

    $stmt = $conn->prepare("INSERT INTO leads (name, phone, email, action_type) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        error_log("Lead insert preparation failed: " . $conn->error);
        return false;
    }

    $stmt->bind_param("ssss", $name, $phone, $email, $action);
    $saved = $stmt->execute();
    if (!$saved) {
        error_log("Lead insert failed: " . $stmt->error);
    }
    $stmt->close();

    return $saved;
}

function is_agent_available($at = null) {
    $timezone = new DateTimeZone('Africa/Johannesburg');
    if ($at instanceof DateTimeInterface) {
        $local_time = DateTimeImmutable::createFromInterface($at)->setTimezone($timezone);
    } elseif (is_string($at)) {
        $local_time = new DateTimeImmutable($at, $timezone);
    } else {
        $local_time = new DateTimeImmutable('now', $timezone);
    }

    $weekday = (int) $local_time->format('N');
    $time = $local_time->format('H:i');
    return $weekday >= 1 && $weekday <= 5 && $time >= '07:30' && $time < '16:30';
}

function get_course_prospectus_url($code) {
    $available_codes = [
        'opt_hc_cqs', 'opt_hc_fam', 'opt_1y_cpm', 'opt_1y_pd',
        'opt_sf_fm5', 'opt_sf_fm3', 'opt_sf_fm1', 'opt_sf_iam5', 'opt_sf_iam1',
        'opt_sc_cpm5', 'opt_sc_cpm3', 'opt_sc_cpm1', 'opt_sc_ct5',
        'opt_sp_pd5', 'opt_sp_pd3', 'opt_spc_cpm', 'opt_spc_fm', 'opt_spc_pd'
    ];

    return in_array($code, $available_codes, true) ? "https://cea.ac.za/pdfs/$code.pdf" : null;
}

function get_course_page_url($code) {
    $course_pages = [
        'opt_hc_cqs' => 'https://cea.ac.za/higher-certificate-in-construction-quantity-surveying/',
        'opt_hc_fam' => 'https://cea.ac.za/higher-certificate-in-facilities-management/',
        'opt_hc_cpm' => 'https://cea.ac.za/higher-certificate-in-construction-project-management/',
        'opt_1y_cpm' => 'https://cea.ac.za/higher-certificate-in-construction-project-management/',
        'opt_1y_pd' => 'https://cea.ac.za/property-development-one-year-learning-programme/',
        'opt_1y_cre' => 'https://cea.ac.za/commercial-real-estate-specialist-course/',
        'opt_sf_fm5' => 'https://cea.ac.za/12-week-facilities-management-programme/',
        'opt_sf_fm3' => 'https://cea.ac.za/3-day-slp-in-facilities-management/',
        'opt_sf_fm1' => 'https://cea.ac.za/1-day-introduction-to-facilities-management/',
        'opt_sf_iam5' => 'https://cea.ac.za/5-day-certificate-programme/',
        'opt_sf_iam3' => 'https://cea.ac.za/5-day-certificate-programme/',
        'opt_sf_iam1' => 'https://cea.ac.za/1-day-introduction-programmes/',
        'opt_sc_cpm5' => 'https://cea.ac.za/12-week-construction-project-management-programme/',
        'opt_sc_cpm3' => 'https://cea.ac.za/3-day-slp-in-construction-project-management/',
        'opt_sc_cpm1' => 'https://cea.ac.za/1-day-introduction-to-construction-project-management/',
        'opt_sc_ct5' => 'https://cea.ac.za/5-day-construction-technology-programme/',
        'opt_sc_cpmc5' => 'https://cea.ac.za/construction-project-management-department/',
        'opt_sp_pd5' => 'https://cea.ac.za/12-week-property-development-programme/',
        'opt_sp_pd3' => 'https://cea.ac.za/property-short-courses/',
        'opt_sp_pd1' => 'https://cea.ac.za/property-short-courses/',
        'opt_spc_cpm' => 'https://cea.ac.za/construction-project-management-course-self-paced-2/',
        'opt_spc_fm' => 'https://cea.ac.za/facilities-management-course-short-course3/',
        'opt_spc_pd' => 'https://cea.ac.za/property-development-self-paced-2/',
        'opt_skill_handyperson' => 'https://cea.ac.za/assistant-handyperson-skills-programme/',
        'opt_sc_shopping' => 'https://cea.ac.za/shopping-centre-management-course/'
    ];

    return $course_pages[$code] ?? 'https://cea.ac.za/programmes/';
}

// --- DATA MATRIX ---

function get_course_title($code) {
    $titles = [
        "opt_hc_cqs" => "Higher Cert: Construction Quantity Surveying", "opt_hc_fam" => "Higher Cert: Facilities & Asset Management",
        "opt_hc_cpm" => "Higher Cert: Construction Project Management",
        "opt_1y_cpm" => "Higher Cert: Construction Project Management", "opt_1y_pd" => "1 Year: Property Development",
        "opt_1y_cre" => "Commercial Real Estate Specialist",
        "opt_skill_handyperson" => "Assistant Handyperson Skills Programme",
        "opt_sc_shopping" => "Property & Shopping Centre Management",
        "opt_sf_fm5" => "Short: 5 Day Facilities Management", "opt_sf_fm3" => "Short: 3 Day Facilities Management", "opt_sf_fm1" => "Short: 1 Day Facilities Management",
        "opt_sf_iam5" => "Short: 5 Day Immovable Asset Mgt", "opt_sf_iam3" => "Short: 3 Day Immovable Asset Mgt", "opt_sf_iam1" => "Short: 1 Day Immovable Asset Mgt",
        "opt_sc_cpm5" => "Short: 5 Day Const Project Mgt", "opt_sc_cpm3" => "Short: 3 Day Const Project Mgt", "opt_sc_cpm1" => "Short: 1 Day Const Project Mgt",
        "opt_sc_ct5" => "Short: 5 Day Construction Technology", "opt_sc_cpmc5" => "Short: 5 Day Const Project Meas & Control",
        "opt_sp_pd5" => "Short: 5 Day Property Development", "opt_sp_pd3" => "Short: 3 Day Property Development", "opt_sp_pd1" => "Short: 1 Day Property Development",
        "opt_spc_cpm" => "Flexible Online: Construction Project Management", "opt_spc_fm" => "Flexible Online: Facilities Management", "opt_spc_pd" => "Flexible Online: Property Development"
    ];
    return isset($titles[$code]) ? $titles[$code] : "Selected Course";
}

function get_course_info($code) {
    $info = ["title" => get_course_title($code), "desc" => "See the official course page for programme details.", "duration" => "See the official course page"];
    if ($code == 'opt_hc_cpm') {
        $info['desc'] = "A Higher Certificate focused on applying project management principles to construction and the South African built environment.";
        $info['duration'] = "1 year full-time or 2 years part-time; NQF Level 5, 120 credits";
    } elseif ($code == 'opt_1y_cre') {
        $info['desc'] = "A specialist programme covering commercial property dynamics, development and investment, legal frameworks, sales and asset management, market analysis, and valuation principles.";
        $info['duration'] = "1 year; NQF Level 5, 120 credits";
    } elseif ($code == 'opt_skill_handyperson') {
        $info['desc'] = "QCTO skills programme SP-210501 combining knowledge, practical application, and supervised workplace integration in property maintenance.";
        $info['duration'] = "129 days; NQF Level 3, 82 credits";
    } elseif ($code == 'opt_sc_shopping') {
        $info['desc'] = "Practical training in commercial property and shopping-centre management, including feasibility, maintenance, finance, risk, leasing, tenant relations, and marketing.";
        $info['duration'] = "5 days; NQF Level 5";
    } elseif ($code == 'opt_hc_cqs') {
        $info['desc'] = "This qualification offers an in-depth exploration of construction quantity surveying, preparing students for diverse career opportunities and professional growth in the built environment. With flexible learning pathways, the course focuses on developing skilled professionals who can expertly navigate complex investments within the sector.";
        $info['duration'] = "1 year full-time or 2 years part-time";
    } elseif ($code == 'opt_hc_fam') {
        $info['desc'] = "This course prepares students for entry-level to senior Facilities Management roles across the public and private sectors. Aligned with international standards and SAFMA-endorsed competencies, the qualification equips graduates to manage substantial investments in diverse settings, including construction sites.";
        $info['duration'] = "1 year full-time or 2 years part-time";
    } elseif ($code == 'opt_1y_cpm') {
        $info['desc'] = "This course prepares students for dynamic careers in Construction Project Management, offering diverse pathways and professional growth. The curriculum delivers specialized industry knowledge, cultivating competent professionals equipped to manage substantial investments and navigate the complexities of the built environment.";
        $info['duration'] = "1 year full-time or 2 years part-time";
    } elseif ($code == 'opt_1y_pd') {
        $info['desc'] = "The property development sector is a vital investment vehicle driving wealth and employment in South Africa. This one-year course addresses industry skills shortages by providing specialized training in legal, financial, and project management principles. Designed for individuals seeking comprehensive knowledge without a full-time university commitment, it equips participants for diverse career paths that contribute to both the economy and community development.";
        $info['duration'] = "1 year full-time or 2 years part-time";
    } elseif ($code == 'opt_sf_fm5') {
        $info['desc'] = "The 5 Day Facilities Management Programme caters to individuals in first and middle management roles within both the private and public sectors, focusing on asset and maintenance management.";
        $info['duration'] = "5 days";
    } elseif ($code == 'opt_sf_fm3') {
        $info['desc'] = "The 3 Day Short Course in Facilities Management caters to individuals in first and middle management roles, focusing on asset and maintenance management.";
        $info['duration'] = "3 days";
    } elseif ($code == 'opt_sf_fm1') {
        $info['desc'] = "This 1 Day Short Course in Facilities Management caters to individuals in first and middle management roles within both the private and public sectors, focusing on asset and maintenance management.";
        $info['duration'] = "1 day";
    } elseif ($code == 'opt_sf_iam5') {
        $info['desc'] = "The 5 Day Learning Programme in Immovable Asset Management Program provides participants with comprehensive knowledge and practical skills in immovable asset management.";
        $info['duration'] = "5 days";
    } elseif ($code == 'opt_sf_iam3') {
        $info['desc'] = "Immovable Asset Management training. See the official course page for current programme details and delivery options.";
        $info['duration'] = "3 days";
    } elseif ($code == 'opt_sf_iam1') {
        $info['desc'] = "The 1 Day Short Course in Asset Management provides participants with comprehensive knowledge and practical skills in immovable asset management.";
        $info['duration'] = "1 day";
    }  elseif ($code == 'opt_sc_cpm5') {
        $info['desc'] = "The 5 Day Construction Project Management Course educates individuals for management positions within the construction industry by imparting project management principles.";
        $info['duration'] = "5 days";
    } elseif ($code == 'opt_sc_cpm3') {
        $info['desc'] = "The 3 Day Construction Project Management Course educates individuals for management positions within the construction industry by imparting project management principles.";
        $info['duration'] = "3 days";
    } elseif ($code == 'opt_sc_cpm1') {
        $info['desc'] = "The 1 Day Construction Project Management Course educates individuals for management positions within the construction industry by imparting project management principles.";
        $info['duration'] = "1 day";
    } elseif ($code == 'opt_sc_ct5') {
        $info['desc'] = "Construction Technology training covering soil and foundations, construction methods, materials, building components, building services, sustainability, and maintenance.";
        $info['duration'] = "5 days";
    } elseif ($code == 'opt_sc_cpmc5') {
        $info['desc'] = "Construction project measurement and control training. See the official Construction Project Management department page for current course information.";
        $info['duration'] = "5 days";
    } elseif ($code == 'opt_sp_pd5') {
        $info['desc'] = "This 5 Day course aims to offer public and private sector practitioners and employees a comprehensive understanding of the foundational concepts essential to property development.";
        $info['duration'] = "5 days";
    } elseif ($code == 'opt_sp_pd3') {
        $info['desc'] = "This intensive course aims to offer public and private sector practitioners and employees a comprehensive understanding of the foundational concepts essential to property development.";
        $info['duration'] = "3 days";
    } elseif ($code == 'opt_sp_pd1') {
        $info['desc'] = "This intensive course aims to offer public and private sector practitioners and employees a comprehensive understanding of the foundational concepts essential to property development.";
        $info['duration'] = "1 day";
    } elseif ($code == 'opt_spc_cpm') {
        $info['desc'] = "A flexible online programme with course materials and structured assignments, plus a monthly one-on-one Q&A session with a course tutor for questions and assignment guidance.";
        $info['duration'] = "4 months";
    } elseif ($code == 'opt_spc_fm') {
        $info['desc'] = "A flexible online programme with course materials and structured assignments, plus a monthly one-on-one Q&A session with a course tutor for questions and assignment guidance.";
        $info['duration'] = "4 months";
    } elseif ($code == 'opt_spc_pd') {
        $info['desc'] = "A flexible online programme with course materials and structured assignments, plus a monthly one-on-one Q&A session with a course tutor for questions and assignment guidance.";
        $info['duration'] = "4 months";
    }
    return $info;
}

// Get the direct download URL from Meta
function get_media_url($media_id, $token) {
    $ch = curl_init("https://graph.facebook.com/v21.0/$media_id");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Safely follow internal graph redirects if any
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token]);
    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($response_body) || $http_code < 200 || $http_code >= 300) {
        error_log("Meta media URL lookup failed with HTTP status $http_code.");
        return null;
    }

    $response = json_decode($response_body, true);
    return is_array($response) && is_string($response['url'] ?? null) ? $response['url'] : null;
}

// Download the file safely to your server
function save_media_to_server($url, $save_path, $token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // ⚠️ CRITICAL: Forces cURL to follow Meta's CDN redirect to fetch the real file
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer " . $token]);
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($data === false) {
        error_log("Meta media download failed: " . curl_error($ch));
    }
    curl_close($ch);

    if (!is_string($data) || $http_code < 200 || $http_code >= 300) {
        error_log("Meta media download returned HTTP status $http_code.");
        return false;
    }

    $dir = dirname($save_path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log("Unable to create the uploads directory for inbound WhatsApp media.");
        return false;
    }

    if (file_put_contents($save_path, $data) === false) {
        error_log("Unable to write inbound WhatsApp media to the uploads directory.");
        return false;
    }

    return true;
}

?>