<?php
// 1. MASTER ERROR LOGGER
ini_set('display_errors', 0); 
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_error_log.txt');
error_reporting(E_ALL);

// Log incoming Meta packet immediately to verify webhook life
file_put_contents(__DIR__ . '/meta_packet_log.txt', file_get_contents('php://input') . PHP_EOL, FILE_APPEND);

// --- DATABASE CONFIGURATION ---
$db_host = 'sql3.jnb1.host-h.net';
$db_user = 'wwwcea_bot1';
$db_pass = 'CareerExcelAcademy2026';
$db_name = 'ceabot_db';

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
$access_token = 'EAANeDzAb8iQBRSOhmrZAc3UXvt7uEquOj67ZCVKlS4ZBHxmJkIuLrWNoOTPWmdUgQcthaSVGMU8pu4vLgILge4x6ry62N0dLWrK7RoRb39ZC5ijZAZBZB8MIXGTFKslfVVQxo18cikxZB4ooSvI0f5vhidHW3PRNkZCOEVKSc5DSl6CDXgLCRBXLrBEgX2wHlVgZDZD';
$phone_number_id = '1192797650583688';
$verify_token = 'CEA_Secret_2026';

// --- DEPARTMENT ROUTING ---
$george_admin = '27849468033'; 
$george_email = 'georgem@cea.ac.za'; 
$barry_sales  = '27636896768'; 
$barry_email  = 'barryh@cea.ac.za';
$reminder = "\n\n_Reply *Human* at any time to speak to an agent._";

// --- HANDSHAKE ---
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    if (isset($_GET['hub_verify_token']) && $_GET['hub_verify_token'] === $verify_token) {
        echo $_GET['hub_challenge']; exit;
    }
}
// --- RECEIVE MESSAGE ---
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
    $msg = $data['entry'][0]['changes'][0]['value']['messages'][0];
    $from = $msg['from'];
    $name = $data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? 'Student';
    
    $payload = "";
    $incoming_content = "";

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
    // --- DETECT & SAVE IMAGES ---
    elseif (isset($msg['type']) && $msg['type'] == 'image') {
        $payload = 'ignore_action'; // Stops the bot from sending menus
        $media_id = $msg['image']['id'];
        $caption = $msg['image']['caption'] ?? '';
        
        // Use file_put_contents because we know it works on your server!
        $log_file = __DIR__ . '/image_debug_log.txt';
        file_put_contents($log_file, "--- NEW IMAGE ---\n1. Received ID: $media_id\n", FILE_APPEND);
        
        // 1. Get URL from Meta
        $meta_url = get_media_url($media_id, $access_token);
        file_put_contents($log_file, "2. Meta URL: " . ($meta_url ? "SUCCESS" : "FAIL") . "\n", FILE_APPEND);
        
        if ($meta_url) {
            // 2. Download and Save
            $local_path = "uploads/" . $media_id . ".jpg";
            save_media_to_server($meta_url, $local_path, $access_token);
            
            if (file_exists($local_path)) {
                file_put_contents($log_file, "3. Saved to: $local_path\n", FILE_APPEND);
            } else {
                file_put_contents($log_file, "3. SAVE FAILED. Check 'uploads' folder permissions.\n", FILE_APPEND);
            }
            
            // 3. Save to Database
            if ($conn) {
                $stmt = $conn->prepare("INSERT INTO messages (student_phone, sender, message_text, media_url, message_type) VALUES (?, 'student', ?, ?, 'image')");
                if ($stmt) {
                    $stmt->bind_param("sss", $from, $caption, $local_path);
                    if ($stmt->execute()) {
                        file_put_contents($log_file, "4. DB Insert SUCCESS\n", FILE_APPEND);
                    } else {
                        file_put_contents($log_file, "4. DB Insert FAILED: " . $stmt->error . "\n", FILE_APPEND);
                    }
                    $stmt->close();
                } else {
                    file_put_contents($log_file, "4. DB Prepare FAILED: " . $conn->error . "\n", FILE_APPEND);
                }
            }
        }
        
        // Keep incoming content blank so the standard text logger ignores it
        $incoming_content = ""; 
    } 
    // --- STANDARD TEXT MESSAGES ---
    else {
        $text = strtolower($msg['text']['body'] ?? '');
        $payload = ($text == 'human') ? 'talk_human' : 'start_menu';
        $incoming_content = $msg['text']['body'] ?? '';
    }
    
    // --- LOG TEXT MESSAGES TO INBOX ---
    if (!empty($incoming_content) && $conn) {
        $stmt_msg = $conn->prepare("INSERT INTO messages (student_phone, sender, message_text) VALUES (?, 'student', ?)");
        if ($stmt_msg) {
            $stmt_msg->bind_param("ss", $from, $incoming_content);
            $stmt_msg->execute();
            $stmt_msg->close();
        }
    }
    // ----------------------------------------------------

    // Update the database to store the real name every time they message

    // Update the database to store the real name every time they message
    $stmt = $conn->prepare("INSERT INTO bot_states (phone, name, state) VALUES (?, ?, 'waiting_for_human') 
                            ON DUPLICATE KEY UPDATE name = VALUES(name)");
    $stmt->bind_param("ss", $from, $name);
    $stmt->execute();
    $stmt->close();

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
            if ($conn) { $conn->query("DELETE FROM bot_states WHERE phone = '$from'"); }
            send_text($from, "🤖 *Bot reactivated for this chat.*", $access_token, $phone_number_id, "");
            exit;
        }
        exit;
    }

    // --- FUNNEL ROUTING ---
    if ($payload == 'start_menu') {
        send_main_menu($from, "Hi $name! Welcome to Career Excel Academy. Please choose an option:", $access_token, $phone_number_id, "");
    }
    
    elseif ($payload == 'view_categories') {
        send_list($from, "Course Categories", "Select a study path:", "Departments", [
            ["id" => "cat_higher", "title" => "🎓 Higher Certificates"],
            ["id" => "cat_1year", "title" => "📅 One Year Courses"],
            ["id" => "cat_short", "title" => "⏱️ Short Courses"],
            ["id" => "cat_self", "title" => "💻 Self Paced Courses"]
        ], "view_categories", $access_token, $phone_number_id, $reminder);
    }
    
    elseif ($payload == 'cat_higher') {
        send_list($from, "Higher Certificates", "Select a course:", "Courses", [
            ["id" => "opt_hc_cqs", "title" => "Quant. Surveying"], 
            ["id" => "opt_hc_fam", "title" => "Facilities & Asset Mgt"]
        ], "view_categories", $access_token, $phone_number_id, $reminder);
    }
    
    elseif ($payload == 'cat_1year') {
        send_list($from, "One Year Courses", "Select a course:", "Courses", [
            ["id" => "opt_1y_cpm", "title" => "Const. Project Mgt"],
            ["id" => "opt_1y_pd", "title" => "Property Development"]
        ], "view_categories", $access_token, $phone_number_id, $reminder);
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
            ["id" => "opt_sf_psi5", "title" => "5 Day Public Sector Inst"]
        ], "cat_short", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'sub_short_cpm') {
        send_list($from, "Const. Project Mgt", "Select an option:", "Short Courses", [
            ["id" => "opt_sc_cpm5", "title" => "5 Day CPM"],
            ["id" => "opt_sc_cpm3", "title" => "3 Day CPM"],
            ["id" => "opt_sc_cpm1", "title" => "1 Day CPM"],
            ["id" => "opt_sc_ct5", "title" => "5 Day Const. Tech"],
            ["id" => "opt_sc_cpmc5", "title" => "5 Day Meas & Control"]
        ], "cat_short", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'sub_short_prop') {
        send_list($from, "Property Development", "Select an option:", "Short Courses", [
            ["id" => "opt_sp_pd5", "title" => "5 Day Property Dev"],
            ["id" => "opt_sp_pd3", "title" => "3 Day Property Dev"],
            ["id" => "opt_sp_pd1", "title" => "1 Day Property Dev"]
        ], "cat_short", $access_token, $phone_number_id, $reminder);
    }

    elseif ($payload == 'cat_self') {
        send_list($from, "Self Paced Courses", "Select a course:", "Courses", [
            ["id" => "opt_spc_cpm", "title" => "CPM (Self Paced)"],
            ["id" => "opt_spc_fm", "title" => "FM (Self Paced)"],
            ["id" => "opt_spc_pd", "title" => "Prop Dev (Self Paced)"]
        ], "view_categories", $access_token, $phone_number_id, $reminder);
    }

    elseif (substr($payload, 0, 4) === 'opt_') {
        $info = get_course_info($payload);
        
        $message = "*Course:* " . $info['title'] . "\n\n";
        
        // --- NEW MATH REQUIREMENT CHECK ---
        if (substr($payload, 0, 7) === 'opt_hc_') {
            $message .= "⚠️ *Important Admission Requirement:*\n_You must have achieved over 40% in Pure Mathematics or Mathematical Literacy to register for this course._\n\n";
        }
        
        $message .= "*Description:* " . $info['desc'] . "\n*Price:* " . $info['price'] . "\n*Duration:* " . $info['duration'];
        
        send_course_details($from, $message, "reg_".$payload, "pdf_".$payload, "view_categories", $access_token, $phone_number_id, $reminder);
    }
    
    // --- REGISTRATION & HUMAN AGENT LOGIC ---
    elseif ($payload == 'talk_human' || substr($payload, 0, 4) === 'reg_') {
        if ($conn) {
            $conn->query("REPLACE INTO bot_states (phone, state) VALUES ('$from', 'silenced')");
        }
        
        $type = ($payload == 'talk_human') ? "Human Assistance" : "Course Registration";
        $course_code = str_replace('reg_', '', $payload);
        $inquiry = ($payload == 'talk_human') ? "Student requested a consultant." : "Registration for " . get_course_title($course_code);

       if ($payload == 'talk_human') {
            $target_admin = $barry_sales; 
            $target_email = $barry_email;
        } else {
            // Check for Higher Cert or 1-Year courses
            if (strpos($course_code, '_hc_') !== false || strpos($course_code, '_1y_') !== false) {
                $target_admin = $barry_sales; // Swapped to Barry
                $target_email = $barry_email;
            } else {
                $target_admin = $george_admin; // Swapped to George
                $target_email = $george_email;
            }
        }

        if ($from !== $target_admin) {
            send_template_alert($target_admin, $type, $name, $from, $inquiry, $access_token, $phone_number_id);
        }

        $headers = "From: bot@cea.ac.za";
        @mail($target_email, "New Lead: $name ($type)", "Type: $type\nName: $name\nPhone: $from\nInquiry: $inquiry", $headers);

        $ack = ($payload == 'talk_human') ? "I've alerted the team! 🙋‍♂️ A consultant will assist you shortly." : "🎉 Interest noted! A consultant will join this chat shortly to assist with your registration.";
        send_text($from, $ack, $access_token, $phone_number_id, "");
        
        log_lead($from, $name, $type . ": " . $inquiry);
    }

    elseif (substr($payload, 0, 4) === 'pdf_') {
        $code = str_replace('pdf_', '', $payload);
        $title = get_course_title($code);
        
        log_lead($from, $name, "DOWNLOAD: Prospectus for $title");
        send_text($from, "Fetching the prospectus for *$title*... 📄", $access_token, $phone_number_id, $reminder);
        
        $pdf_url = "https://cea.ac.za/pdfs/" . $code . ".pdf";
        send_pdf($from, $pdf_url, $title."_Prospectus.pdf", $access_token, $phone_number_id, $reminder);
    }
    
// ... after your existing elseif for interactive messages
elseif (isset($msg['type']) && $msg['type'] == 'image') {
    $media_id = $msg['image']['id'];
    $caption = $msg['image']['caption'] ?? ''; 
    
    // Log that we received an image
    error_log("1. Webhook received image with ID: " . $media_id);

    // 1. Get the direct Meta download URL
    $meta_download_url = get_media_url($media_id, $access_token);
    error_log("2. Meta Media URL: " . ($meta_download_url ? "SUCCESS" : "FAILED TO FETCH"));
    
    // 2. Download and save the file
    $local_path = "uploads/" . $media_id . ".jpg";
    if ($meta_download_url) {
        save_media_to_server($meta_download_url, $local_path, $access_token);
        if (file_exists($local_path)) {
            error_log("3. Image successfully saved to: " . $local_path);
        } else {
            error_log("3. FAILED to save image. Check 'uploads/' folder permissions.");
        }
    }

    // 3. Store the path in your database (NOW WITH message_type)
    if ($conn) {
        $stmt = $conn->prepare("INSERT INTO messages (student_phone, sender, message_text, media_url, message_type) VALUES (?, 'student', ?, ?, 'image')");
        if ($stmt) {
            $stmt->bind_param("sss", $from, $caption, $local_path);
            if ($stmt->execute()) {
                error_log("4. Database insert SUCCESS.");
            } else {
                error_log("4. Database Insert Error: " . $stmt->error);
            }
            $stmt->close();
        } else {
            error_log("4. Database Prepare Error: " . $conn->error);
        }
    }
    
    $incoming_content = "🖼️ _[Image Received]_"; 
}
}

// --- HELPER FUNCTIONS ---

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
    $payload = ["messaging_product" => "whatsapp", "to" => $to, "type" => "interactive", "interactive" => [
        "type" => "button", "body" => ["text" => $text . $rem], "action" => ["buttons" => [
            ["type" => "reply", "reply" => ["id" => "view_categories", "title" => "📚 View Courses"]],
            ["type" => "reply", "reply" => ["id" => "talk_human", "title" => "🙋‍♂️ Talk to Human"]]
        ]]
    ]];
    call_api($payload, $token, $id);
}

function send_list($to, $header, $body, $sec_title, $rows, $back_id, $token, $id, $rem) {
    $payload = ["messaging_product" => "whatsapp", "to" => $to, "type" => "interactive", "interactive" => [
        "type" => "list", "header" => ["type" => "text", "text" => $header], "body" => ["text" => $body . $rem],
        "action" => ["button" => "Open Menu", "sections" => [["title" => $sec_title, "rows" => $rows], ["title" => "Navigation", "rows" => [["id" => $back_id, "title" => "⬅️ Back"]]]]]
    ]];
    call_api($payload, $token, $id);
}

function send_course_details($to, $text, $reg, $pdf, $back, $token, $id, $rem) {
    $payload = ["messaging_product" => "whatsapp", "to" => $to, "type" => "interactive", "interactive" => [
        "type" => "button", "body" => ["text" => $text . $rem], "action" => ["buttons" => [
            ["type" => "reply", "reply" => ["id" => $reg, "title" => "✅ Register Now"]],
            ["type" => "reply", "reply" => ["id" => $pdf, "title" => "📄 View Prospectus"]],
            ["type" => "reply", "reply" => ["id" => $back, "title" => "⬅️ Back to Courses"]]
        ]]
    ]];
    call_api($payload, $token, $id);
}

function send_text($to, $text, $token, $id, $rem) {
    call_api(["messaging_product" => "whatsapp", "to" => $to, "type" => "text", "text" => ["body" => $text . $rem]], $token, $id);
}

function send_pdf($to, $url, $name, $token, $id, $rem) {
    call_api(["messaging_product" => "whatsapp", "to" => $to, "type" => "document", "document" => ["link" => $url, "filename" => $name, "caption" => "Here is your requested prospectus." . $rem]], $token, $id);
}

function call_api($payload, $token, $id) {
    $ch = curl_init("https://graph.facebook.com/v21.0/$id/messages");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_exec($ch); curl_close($ch);
}

function log_lead($phone, $name, $action) {
    global $conn;
    if ($conn) {
        try {
            $stmt = $conn->prepare("INSERT INTO leads (name, phone, action_type) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sss", $name, $phone, $action);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $e) {
            error_log("Database Insert Exception: " . $e->getMessage());
        }
    }
}

// --- DATA MATRIX ---

function get_course_title($code) {
    $titles = [
        "opt_hc_cqs" => "Higher Cert: Construction Quantity Surveying", "opt_hc_fam" => "Higher Cert: Facilities & Asset Management",
        "opt_1y_cpm" => "1 Year: Construction Project Management", "opt_1y_pd" => "1 Year: Property Development",
        "opt_sf_fm5" => "Short: 5 Day Facilities Management", "opt_sf_fm3" => "Short: 3 Day Facilities Management", "opt_sf_fm1" => "Short: 1 Day Facilities Management",
        "opt_sf_iam5" => "Short: 5 Day Immovable Asset Mgt", "opt_sf_iam3" => "Short: 3 Day Immovable Asset Mgt", "opt_sf_iam1" => "Short: 1 Day Immovable Asset Mgt",
        "opt_sf_psi5" => "Short: 5 Day Public Sector Instructor",
        "opt_sc_cpm5" => "Short: 5 Day Const Project Mgt", "opt_sc_cpm3" => "Short: 3 Day Const Project Mgt", "opt_sc_cpm1" => "Short: 1 Day Const Project Mgt",
        "opt_sc_ct5" => "Short: 5 Day Construction Technology", "opt_sc_cpmc5" => "Short: 5 Day Const Project Meas & Control",
        "opt_sp_pd5" => "Short: 5 Day Property Development", "opt_sp_pd3" => "Short: 3 Day Property Development", "opt_sp_pd1" => "Short: 1 Day Property Development",
        "opt_spc_cpm" => "Self-Paced: Construction Project Management", "opt_spc_fm" => "Self-Paced: Facilities Management", "opt_spc_pd" => "Self-Paced: Property Development"
    ];
    return isset($titles[$code]) ? $titles[$code] : "Selected Course";
}

function get_course_info($code) {
    $info = ["title" => get_course_title($code), "desc" => "Placeholder description", "price" => "TBA", "duration" => "TBA"];
    if ($code == 'opt_hc_cqs') {
        $info['desc'] = "This qualification offers an in-depth exploration of construction quantity surveying, preparing students for diverse career opportunities and professional growth in the built environment. With flexible learning pathways, the course focuses on developing skilled professionals who can expertly navigate complex investments within the sector.";
        $info['price'] = "R53 000"; $info['duration'] = "12 Months (Full Time) / 24 Months (Part Time)";
    } elseif ($code == 'opt_hc_fam') {
        $info['desc'] = "This course prepares students for entry-level to senior Facilities Management roles across the public and private sectors. Aligned with international standards and SAFMA-endorsed competencies, the qualification equips graduates to manage substantial investments in diverse settings, including construction sites.";
        $info['price'] = "R53 000"; $info['duration'] = "12 Months (Full Time) / 24 Months (Part Time)";
    } elseif ($code == 'opt_1y_cpm') {
        $info['desc'] = "This course prepares students for dynamic careers in Construction Project Management, offering diverse pathways and professional growth. The curriculum delivers specialized industry knowledge, cultivating competent professionals equipped to manage substantial investments and navigate the complexities of the built environment.";
        $info['price'] = "R53 000"; $info['duration'] = "12 Months (Full Time) / 24 Months (Part Time)";
    } elseif ($code == 'opt_1y_pd') {
        $info['desc'] = "The property development sector is a vital investment vehicle driving wealth and employment in South Africa. This one-year course addresses industry skills shortages by providing specialized training in legal, financial, and project management principles. Designed for individuals seeking comprehensive knowledge without a full-time university commitment, it equips participants for diverse career paths that contribute to both the economy and community development.";
        $info['price'] = "R53 000"; $info['duration'] = "12 Months (Full Time) / 24 Months (Part Time)";
    } elseif ($code == 'opt_sf_fm5') {
        $info['desc'] = "The 5 Day Facilities Management Programme caters to individuals in first and middle management roles within both the private and public sectors, focusing on asset and maintenance management.";
        $info['price'] = "From R17 600"; $info['duration'] = "5 Days";
    } elseif ($code == 'opt_sf_fm3') {
        $info['desc'] = "The 3 Day Short Course in Facilities Management caters to individuals in first and middle management roles, focusing on asset and maintenance management.";
        $info['price'] = "From R9 600"; $info['duration'] = "3 Days";
    } elseif ($code == 'opt_sf_fm1') {
        $info['desc'] = "This 1 Day Short Course in Facilities Management caters to individuals in first and middle management roles within both the private and public sectors, focusing on asset and maintenance management.";
        $info['price'] = "From R3 950"; $info['duration'] = "1 Day";
    } elseif ($code == 'opt_sf_iam5') {
        $info['desc'] = "The 5 Day Learning Programme in Immovable Asset Management Program provides participants with comprehensive knowledge and practical skills in immovable asset management.";
        $info['price'] = "From R17 600"; $info['duration'] = "5 Days";
    } elseif ($code == 'opt_sf_iam3') {
        $info['desc'] = "The 3 Day Short Course in Immovable Asset Management provides participants with comprehensive knowledge and practical skills in immovable asset management.";
        $info['price'] = "From R9 950"; $info['duration'] = "3 Days";
    } elseif ($code == 'opt_sf_iam1') {
        $info['desc'] = "The 1 Day Short Course in Asset Management provides participants with comprehensive knowledge and practical skills in immovable asset management.";
        $info['price'] = "From R3 950"; $info['duration'] = "1 Day";
    } elseif ($code == 'opt_sf_psi5') {
        $info['desc'] = "This course equips participants with a comprehensive understanding of infrastructure’s role in South Africa’s socio-economic development and its alignment with national goals.";
        $info['price'] = "From R17 600"; $info['duration'] = "5 Days";
    } elseif ($code == 'opt_sc_cpm5') {
        $info['desc'] = "The 5 Day Construction Project Management Course educates individuals for management positions within the construction industry by imparting project management principles.";
        $info['price'] = "From R17 600"; $info['duration'] = "5 Days";
    } elseif ($code == 'opt_sc_cpm3') {
        $info['desc'] = "The 3 Day Construction Project Management Course educates individuals for management positions within the construction industry by imparting project management principles.";
        $info['price'] = "From R9 950"; $info['duration'] = "3 Days";
    } elseif ($code == 'opt_sc_cpm1') {
        $info['desc'] = "The 1 Day Construction Project Management Course educates individuals for management positions within the construction industry by imparting project management principles.";
        $info['price'] = "From R3 950"; $info['duration'] = "1 Day";
    } elseif ($code == 'opt_sc_ct5') {
        $info['desc'] = "The 5 Day Construction Project Management Course educates individuals for management positions within the construction industry by imparting project management principles.";
        $info['price'] = "From R17 600"; $info['duration'] = "5 Days";
    } elseif ($code == 'opt_sc_cpmc5') {
        $info['desc'] = "The Construction Project Measurement and Control Course educates individuals for management positions within the construction industry by imparting project management principles.";
        $info['price'] = "From R17 600"; $info['duration'] = "5 Days";
    } elseif ($code == 'opt_sp_pd5') {
        $info['desc'] = "This 5 Day course aims to offer public and private sector practitioners and employees a comprehensive understanding of the foundational concepts essential to property development.";
        $info['price'] = "From R17 600"; $info['duration'] = "5 Days";
    } elseif ($code == 'opt_sp_pd3') {
        $info['desc'] = "This intensive course aims to offer public and private sector practitioners and employees a comprehensive understanding of the foundational concepts essential to property development.";
        $info['price'] = "From R9 600"; $info['duration'] = "3 Days";
    } elseif ($code == 'opt_sp_pd1') {
        $info['desc'] = "This intensive course aims to offer public and private sector practitioners and employees a comprehensive understanding of the foundational concepts essential to property development.";
        $info['price'] = "From R3 950"; $info['duration'] = "1 Day";
    } elseif ($code == 'opt_spc_cpm') {
        $info['desc'] = "This self-paced, three-month online course prepares participants for management roles in the construction industry using contemporary, practical methodologies. Led by experienced lecturers, the program includes 60 hours of study materials (handbooks and e-lectures delivered via email) and 20 hours of structured, assessed assignments. All comprehensive study materials are included in the course cost.";
        $info['price'] = "From R12 400"; $info['duration'] = "4 months";
    } elseif ($code == 'opt_spc_fm') {
        $info['desc'] = "The Facilities Management Programme (FMP) is an intensive, self-paced online course for first and middle management built environment practitioners. Focusing on innovative thinking and entrepreneurship, it has helped over 1,300 local and international delegates launch successful FM careers. Delivered over three months via email, the program includes 60 hours of study materials (handbooks and e-lectures) and 20 hours of structured, assessed assignments with expert feedback.";
        $info['price'] = "From R12 400"; $info['duration'] = "4 months";
    } elseif ($code == 'opt_spc_pd') {
        $info['desc'] = "This intensive, three-month online course provides public and private sector practitioners with a comprehensive foundation in property development. Designed to equip participants with the essential skills for a profitable career in the sector, the self-paced program includes 60 hours of study materials (handbooks and e-lectures delivered via email) and 20 hours of assessed assignments. All comprehensive study materials are included in the course fee.";
        $info['price'] = "From R12 400"; $info['duration'] = "4 months";
    }
    return $info;
}

// Get the direct download URL
function get_media_url($media_id, $token) {
    $ch = curl_init("https://graph.facebook.com/v21.0/$media_id");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $response['url'] ?? null;
}

// Download the file to your server
function save_media_to_server($url, $save_path, $token) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    $data = curl_exec($ch);
    curl_close($ch);
    file_put_contents($save_path, $data);
}


?>