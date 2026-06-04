<?php
// ==============================================================================
// 1. ENVIRONMENT CORE CONFIGURATION & SECURITY
// ==============================================================================
require_once 'config/database.php';
check_auth();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// --- AJAX CONTACT DETAILS VIEW HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'view_contact' && isset($_GET['id'])) {
    header('Content-Type: application/json');

    $contact_id = intval($_GET['id']);

    try {

        // Fetch contact basic info
        $contact_stmt = $pdo->prepare("
            SELECT c.*, camp.campaign_name
            FROM contacts c
            LEFT JOIN campaigns camp ON c.campaign_id = camp.id
            WHERE c.id = ? AND c.user_id = ?
        ");

        $contact_stmt->execute([$contact_id, $user_id]);
        $contact = $contact_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$contact) {
            echo json_encode([
                'success' => false,
                'error' => 'Contact not found.'
            ]);
            exit;
        }

        // Fetch custom fields
        $fields_stmt = $pdo->prepare("
            SELECT cf.field_label, cfv.field_value
            FROM contact_field_values cfv
            JOIN custom_fields cf ON cf.id = cfv.field_id
            WHERE cfv.contact_id = ?
            ORDER BY cf.id ASC
        ");

        $fields_stmt->execute([$contact_id]);
        $fields = $fields_stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'contact' => $contact,
            'fields' => $fields
        ]);
    } catch (Exception $e) {

        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }

    exit;
}

// --- 1. AJAX SENDING STATUS TOGGLE HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'toggle_status' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $contact_id = intval($_GET['id']);

    $st = $pdo->prepare("SELECT sending_status FROM contacts WHERE id = ? AND user_id = ?");
    $st->execute([$contact_id, $user_id]);
    $contact = $st->fetch();

    if ($contact) {
        $new_status = ($contact['sending_status'] === 'Active') ? 'Paused' : 'Active';
        $up = $pdo->prepare("UPDATE contacts SET sending_status = ? WHERE id = ? AND user_id = ?");
        $up->execute([$new_status, $contact_id, $user_id]);
        echo json_encode(['success' => true, 'new_status' => $new_status]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Contact tracking record missing.']);
    }
    exit;
}

// --- 2. AJAX INSTANT SINGLE MAIL DISPATCH ROUTINE (INTEGRATED WITH CONFIG/MAILER.PHP) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'dispatch_instant_email') {
    header('Content-Type: application/json');

    $email = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message_body = trim($_POST['message_body'] ?? '');
    $is_html = intval($_POST['is_html'] ?? 0);

    if (empty($email) || empty($subject) || empty($message_body)) {
        echo json_encode(['success' => false, 'error' => 'All transaction parameters (Email, Subject, Body) are required.']);
        exit;
    }

    try {
        // Core inclusion of your specific centralized mail delivery engine configuration
        require_once 'config/mailer.php';

        if (function_exists('send_authenticated_email')) {

            // 1. Fetch contact base details and custom fields from database to resolve placeholders
            $contact_stmt = $pdo->prepare("SELECT id, email FROM contacts WHERE email = ? AND user_id = ?");
            $contact_stmt->execute([$email, $user_id]);
            $contact_record = $contact_stmt->fetch(PDO::FETCH_ASSOC);

            if ($contact_record) {
                // Initialize template replacement arrays with basic properties
                $replace_pairs = [
                    '{{email}}' => $contact_record['email']
                ];

                // 2. Fetch all structural custom field values for this specific contact
                $val_stmt = $pdo->prepare("
                    SELECT cf.field_label, cfv.field_value 
                    FROM contact_field_values cfv
                    JOIN custom_fields cf ON cfv.field_id = cf.id
                    WHERE cfv.contact_id = ?
                ");
                $val_stmt->execute([$contact_record['id']]);
                $custom_values = $val_stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($custom_values as $val) {
                    // Turn "Name" into "{{name}}" or "First Name" into "{{first_name}}" to match JavaScript shortcode engine rules
                    $slug = '{{' . strtolower(str_replace(' ', '_', trim($val['field_label']))) . '}}';
                    $replace_pairs[$slug] = $val['field_value'];
                }

                // 3. Replace the shortcodes inside both Subject and Message Body
                $subject = str_replace(array_keys($replace_pairs), array_values($replace_pairs), $subject);
                $message_body = str_replace(array_keys($replace_pairs), array_values($replace_pairs), $message_body);
            }

            // --- TRACKING ENGINE INJECTION ---
            // 1. Create a log entry to get a log_id (template_id = 0 for instant mails)
            $log_stmt = $pdo->prepare("INSERT INTO email_logs (user_id, template_id, contact_id, sent_at, is_opened) VALUES (?, 0, ?, NOW(), 0)");
            $log_stmt->execute([$user_id, $contact_record['id'] ?? 0]);
            $log_id = $pdo->lastInsertId();

            if ($log_id) {
                require_once 'config/app.php';
                $base_url = APP_URL;

                // Check if the body contains Quill-escaped HTML tags (like &lt;!DOCTYPE, &lt;html, &lt;table, etc.)
                if (preg_match('/&lt;(!DOCTYPE|html|head|body|table|tr|td|div|p|span|a|style)\b/i', $message_body)) {
                    $message_body = str_replace('</p>', "\n", $message_body);
                    $message_body = str_replace('<p>', "", $message_body);
                    $message_body = preg_replace('/<br\s*\/?>/i', "\n", $message_body);
                    $message_body = html_entity_decode($message_body, ENT_QUOTES, 'UTF-8');
                    $message_body = trim($message_body);
                }

                // Helper to check if the message body contains HTML content
                $is_body_html = ($is_html == 1) || preg_match('/<(!DOCTYPE|html|head|body|table|tr|td|div|p|span|a|br|h[1-6]|style|img|link)\b/i', $message_body);

                // Convert plain text to HTML if it is not already HTML
                if (!$is_body_html) {
                    $parts = preg_split('/(\bhttps?:\/\/[^\s\r\n<>\'\"]+)/i', $message_body, -1, PREG_SPLIT_DELIM_CAPTURE);
                    $html_body = '';
                    foreach ($parts as $index => $part) {
                        if ($index % 2 === 0) {
                            $html_body .= htmlspecialchars($part, ENT_QUOTES, 'UTF-8');
                        } else {
                            $url = $part;
                            $trail = '';
                            if (preg_match('/([.,;?!]+)$/', $url, $matches)) {
                                $trail = $matches[1];
                                $url = substr($url, 0, -strlen($trail));
                            }
                            $html_body .= '<a href="' . $url . '">' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>' . htmlspecialchars($trail, ENT_QUOTES, 'UTF-8');
                        }
                    }
                    $message_body = "<!DOCTYPE html>\n<html>\n<head>\n<meta charset=\"utf-8\">\n</head>\n<body>\n" . nl2br($html_body) . "\n</body>\n</html>";
                }

                // Force is_html to 1 because we are sending an HTML email in all cases
                $is_html = 1;

                $pixel_tag = '<img src="' . $base_url . 'track_open.php?log_id=' . $log_id . '" width="1" height="1" style="display:none !important;" alt="">';

                if (strpos($message_body, '</body>') !== false) {
                    $message_body = str_replace('</body>', $pixel_tag . '</body>', $message_body);
                } else {
                    $message_body .= $pixel_tag;
                }

                if ($is_html == 1) {
                    $message_body = preg_replace_callback(
                        '/href=["\'](http[^"\']+)["\']/',
                        function ($matches) use ($log_id, $base_url) {
                            return 'href="' . $base_url . 'track_click.php?log_id=' . $log_id . '&url=' . urlencode($matches[1]) . '"';
                        },
                        $message_body
                    );
                }
            }

            // Execute the function directly using parsed parameters
            $result = send_authenticated_email($email, $subject, $message_body);

            if ($result === true) {
                echo json_encode(['success' => true, 'message' => 'Instant message successfully fired out using send_authenticated_email()!']);
            } else {
                echo json_encode(['success' => false, 'error' => 'The mailer accepted the execution but failed to dispatch. Check server logs.']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'config/mailer.php linked successfully, but the function send_authenticated_email() could not be found. Check file paths.']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Mailer execution caught an exception: ' . $e->getMessage()]);
    }
    exit;
}

// --- 3. ACTION: RECORD SCRUB DELETION ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $del_id = intval($_GET['id']);
    $stmt = $pdo->prepare("DELETE FROM contacts WHERE id = ? AND user_id = ?");
    if ($stmt->execute([$del_id, $user_id])) {
        $success = "Contact and metadata records wiped successfully.";
    } else {
        $error = "Failed to drop chosen profile entry.";
    }
}

// --- 4. FETCH CURRENT SCHEMAS FIELDS FOR PERSONALIZATION DECK ---
$fields_stmt = $pdo->prepare("SELECT field_label FROM custom_fields WHERE user_id = ? ORDER BY id ASC");
$fields_stmt->execute([$user_id]);
$global_custom_fields = $fields_stmt->fetchAll(PDO::FETCH_COLUMN);

// --- 5. SEARCH & PAGINATION SYSTEM ---
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$where_clauses = ["c.user_id = ?"];
$bindings = [$user_id];

if (!empty($search)) {
    $where_clauses[] = "(c.email LIKE ? OR EXISTS (
        SELECT 1 FROM contact_field_values cfv
        JOIN custom_fields cf ON cfv.field_id = cf.id
        WHERE cfv.contact_id = c.id
        AND cfv.field_value LIKE ?
    ))";
    $bindings[] = "%{$search}%";
    $bindings[] = "%{$search}%";
}

$where_sql = implode(" AND ", $where_clauses);

$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM contacts c WHERE {$where_sql}");
$count_stmt->execute($bindings);
$total_rows = $count_stmt->fetchColumn();
$total_pages = ceil($total_rows / $limit);

$select_sql = "SELECT c.*, camp.campaign_name
FROM contacts c
JOIN campaigns camp ON c.campaign_id = camp.id
WHERE {$where_sql}
ORDER BY c.id DESC
LIMIT {$limit} OFFSET {$offset}";

$stmt = $pdo->prepare($select_sql);
$stmt->execute($bindings);
$contacts_ledger = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Contacts Ledger - FireMailing AI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        /* Modern theme customizations over Quill standard layout inputs */
        .ql-container.ql-snow {
            border-bottom-left-radius: 0.75rem !important;
            border-bottom-right-radius: 0.75rem !important;
            border-color: #e2e8f0 !important;
            font-family: 'Inter', sans-serif !important;
            font-size: 0.75rem !important;
        }

        .ql-toolbar.ql-snow {
            border-top-left-radius: 0.75rem !important;
            border-top-right-radius: 0.75rem !important;
            border-color: #e2e8f0 !important;
            background-color: #f8fafc !important;
        }

        .ql-editor {
            min-height: 160px;
            max-height: 240px;
        }
    </style>
</head>

<body class="bg-gray-50 font-sans text-slate-700 antialiased">

    <div class="flex min-h-screen overflow-hidden">
        <?php require_once 'components/sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
            <?php require_once 'components/header.php'; ?>

            <main class="flex-grow p-6 md:p-8 space-y-6 max-w-7xl w-full mx-auto">

                <div id="dynamic-alert-container" class="hidden text-xs p-4 rounded-xl border shadow-sm transition-all animate-[fadeIn_0.3s_ease-out]"></div>

                <?php if ($error): ?>
                    <div class="bg-rose-50 text-rose-600 text-sm p-3.5 rounded-xl border border-rose-100"><i class="fa-solid fa-circle-exclamation mr-2"></i><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="bg-emerald-50 text-emerald-600 text-sm p-3.5 rounded-xl border border-emerald-100"><i class="fa-solid fa-circle-check mr-2"></i><?php echo htmlspecialchars($success); ?></div>
                <?php endif; ?>

                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <h1 class="text-xl font-bold text-slate-900 tracking-tight">Global Contacts Directory</h1>
                        <p class="text-xs text-gray-400 mt-0.5">Manage, track, analyze, and instantly dispatch messaging parameters across your audience segments.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="add-contact.php" class="inline-flex items-center px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold shadow-sm transition-all">
                            <i class="fa-solid fa-plus-circle mr-2"></i> Import or Add New Contacts
                        </a>
                    </div>
                </div>

                <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6">

                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-gray-100 pb-5 mb-5">
                        <div class="text-xs font-medium text-slate-400">
                            Showing <span class="text-slate-700 font-bold"><?php echo count($contacts_ledger); ?></span> records out of <span class="text-slate-700 font-bold"><?php echo $total_rows; ?></span> matches
                        </div>

                        <form action="all-contacts.php" method="GET" class="w-full sm:w-80 relative">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search email address, tag parameters..." class="w-full pl-9 pr-8 py-2.5 border border-gray-200 rounded-xl text-xs focus:outline-none focus:border-indigo-500 bg-gray-50/50">
                            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                            <?php if (!empty($search)): ?>
                                <a href="all-contacts.php" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-rose-500"><i class="fa-solid fa-circle-xmark text-xs"></i></a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs text-slate-600">
                            <thead>
                                <tr class="border-b border-gray-100 font-bold uppercase tracking-wider text-gray-400 bg-gray-50/40">
                                    <th class="py-3.5 px-4">Recipient Email Identity</th>
                                    <th class="py-3.5 px-4">Campaign Track Segment</th>
                                    <th class="py-3.5 px-4">Created Date</th>
                                    <th class="py-3.5 px-4">Transmission Status</th>
                                    <th class="py-3.5 px-4 text-center">Instant Message</th>
                                    <th class="py-3.5 px-4 text-right">Operational Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100/70">
                                <?php if (empty($contacts_ledger)): ?>
                                    <tr>
                                        <td colspan="6" class="py-14 text-center text-gray-400 italic">No user accounts or data fields matches your query specifications.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($contacts_ledger as $row): ?>
                                        <tr class="hover:bg-gray-50/30 transition-colors">
                                            <td class="py-3.5 px-4 font-semibold text-slate-900"><?php echo htmlspecialchars($row['email']); ?></td>
                                            <td class="py-3.5 px-4">
                                                <span class="bg-indigo-50 text-indigo-700 px-2.5 py-1 rounded-md font-semibold text-[11px] border border-indigo-100/50">
                                                    <?php echo htmlspecialchars($row['campaign_name']); ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 text-gray-400 font-medium">
                                                <?php echo isset($row['created_at']) ? date('M d, Y H:i', strtotime($row['created_at'])) : date('M d, Y'); ?>
                                            </td>
                                            <td class="py-3.5 px-4">
                                                <button onclick="toggleSendingStatus(<?php echo $row['id']; ?>, this)" class="status-btn px-2.5 py-1 rounded-lg font-bold text-[10px] uppercase transition-all tracking-wider border <?php echo ($row['sending_status'] === 'Active') ? 'bg-emerald-50 text-emerald-600 border-emerald-200' : 'bg-rose-50 text-rose-500 border-rose-200'; ?>">
                                                    <?php echo htmlspecialchars($row['sending_status']); ?>
                                                </button>
                                            </td>
                                            <td class="py-3.5 px-4 text-center">
                                                <button onclick="openQuickMessageModal('<?php echo htmlspecialchars($row['email']); ?>')" class="inline-flex w-7 h-7 rounded-lg bg-amber-50 hover:bg-amber-500 text-amber-600 hover:text-white items-center justify-center transition-all" title="Instant Direct Transmission Trigger Message">
                                                    <i class="fa-solid fa-paper-plane text-[11px]"></i>
                                                </button>
                                            </td>
                                            <td class="py-3.5 px-4 text-right space-x-1.5">
                                                <button onclick="openViewContactModal(<?php echo $row['id']; ?>)"
                                                    class="inline-flex w-7 h-7 rounded-lg bg-blue-50 hover:bg-blue-500 text-blue-500 hover:text-white items-center justify-center transition-all"
                                                    title="View Contact Details">
                                                    <i class="fa-solid fa-eye text-[11px]"></i>
                                                </button>
                                                <a href="edit-contact.php?id=<?php echo $row['id']; ?>" class="inline-flex w-7 h-7 rounded-lg bg-gray-100 hover:bg-slate-700 text-slate-600 hover:text-white items-center justify-center transition-all" title="Edit Properties"><i class="fa-solid fa-pen-to-square text-[11px]"></i></a>
                                                <a href="all-contacts.php?action=delete&id=<?php echo $row['id']; ?>" onclick="return confirm('Drop this logging target and associated metadata tables permanently?');" class="inline-flex w-7 h-7 rounded-lg bg-rose-50 hover:bg-rose-500 text-rose-500 hover:text-white items-center justify-center transition-all" title="Wipe Log Link"><i class="fa-solid fa-trash text-[11px]"></i></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="flex items-center justify-between border-t border-gray-100 pt-4 mt-4">
                            <div class="text-xs text-gray-400">
                                Page <span class="font-semibold text-slate-700"><?php echo $page; ?></span> of <span class="font-semibold text-slate-700"><?php echo $total_pages; ?></span> pages
                            </div>
                            <div class="flex items-center space-x-1">
                                <a href="all-contacts.php?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-xl border border-gray-200 text-gray-500 bg-white hover:bg-gray-50 transition-colors text-xs <?php echo $page <= 1 ? 'pointer-events-none opacity-40' : ''; ?>">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </a>

                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                for ($i = $start_page; $i <= $end_page; $i++):
                                ?>
                                    <a href="all-contacts.php?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-xl text-xs font-semibold transition-all <?php echo $i === $page ? 'bg-indigo-600 text-white shadow-sm' : 'border border-gray-200 text-slate-600 hover:bg-gray-50 bg-white'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <a href="all-contacts.php?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>" class="inline-flex items-center justify-center w-8 h-8 rounded-xl border border-gray-200 text-gray-500 bg-white hover:bg-gray-50 transition-colors text-xs <?php echo $page >= $total_pages ? 'pointer-events-none opacity-40' : ''; ?>">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </main>
        </div>
    </div>

    <div id="quick-message-modal" class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden items-center justify-center p-4">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-xl max-w-xl w-full p-6 space-y-4 transform transition-all">

            <div class="flex items-center justify-between border-b border-gray-100 pb-3">
                <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2"><i class="fa-solid fa-paper-plane text-amber-500"></i> Dispatch Single Instant Message</h3>
                <button onclick="closeQuickMessageModal()" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-times"></i></button>
            </div>

            <div class="space-y-3.5">
                <div>
                    <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">Target Account Destination</label>
                    <input type="text" id="modal-target-email" readonly class="w-full px-3.5 py-2.5 bg-gray-50 border border-gray-200 text-xs rounded-xl font-medium text-slate-500 focus:outline-none">
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">Email Subject Line *</label>
                    <input type="text" id="modal-email-subject" placeholder="Enter email subject..." class="w-full px-3.5 py-2.5 border border-gray-200 text-xs rounded-xl focus:outline-none focus:border-amber-500 font-medium">
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">Transmission Layout Format Engine</label>
                    <select id="modal-is-html-select" class="w-full px-3.5 py-2.5 bg-white border border-gray-200 text-xs rounded-xl font-medium text-slate-700 focus:outline-none focus:border-amber-500">
                        <option value="1" selected>HTML Layout Framework (Supports paragraph mappings, fonts, layout formats)</option>
                        <option value="0">Plain Text (Strips HTML nodes, matches raw unformatted characters string blocks)</option>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="block text-[10px] font-bold text-indigo-500 uppercase tracking-wider">Email Personalization Shortcodes</label>
                    <div class="flex flex-wrap gap-1.5 max-h-[56px] overflow-y-auto p-1 bg-slate-50 border border-slate-100 rounded-lg">
                        <button type="button" onclick="injectMergeTag('{{email}}')" class="px-2 py-1 bg-white border border-gray-200 hover:border-indigo-400 hover:bg-indigo-50/30 rounded text-[10px] font-medium transition-all text-slate-600">Email Address</button>
                        <?php foreach ($global_custom_fields as $label):
                            $slug = strtolower(str_replace(' ', '_', trim($label))); ?>
                            <button type="button" onclick="injectMergeTag('{{<?php echo $slug; ?>}}')" class="px-2 py-1 bg-white border border-gray-200 hover:border-indigo-400 hover:bg-indigo-50/30 rounded text-[10px] font-medium transition-all text-slate-600"><?php echo htmlspecialchars($label); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">Message Content Payload</label>
                    <div id="editor-container" class="bg-white text-xs"></div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2 border-t border-gray-100">
                <button onclick="closeQuickMessageModal()" class="px-4 py-2 text-xs font-semibold text-slate-500 hover:bg-gray-100 rounded-xl transition-all">Cancel</button>
                <button id="modal-submit-btn" onclick="executeInstantMessageDispatch()" class="px-4 py-2 text-xs font-semibold text-white bg-amber-500 hover:bg-amber-600 rounded-xl transition-all shadow-sm shadow-amber-100"><i class="fa-solid fa-paper-plane mr-1.5"></i> Fire Message</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
    <!-- VIEW CONTACT MODAL -->
    <div id="view-contact-modal"
        class="fixed inset-0 z-50 bg-slate-900/40 backdrop-blur-sm hidden items-center justify-center p-4">

        <div class="bg-white rounded-2xl border border-gray-100 shadow-xl max-w-2xl w-full">

            <!-- HEADER -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
                <h3 class="text-sm font-bold text-slate-900 flex items-center gap-2">
                    <i class="fa-solid fa-address-card text-blue-500"></i>
                    Contact Full Details
                </h3>

                <button onclick="closeViewContactModal()"
                    class="text-gray-400 hover:text-gray-600">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>

            <!-- BODY -->
            <div class="p-6 max-h-[70vh] overflow-y-auto">

                <table class="w-full text-xs border border-gray-100 rounded-xl overflow-hidden">
                    <tbody id="contact-details-table"
                        class="divide-y divide-gray-100">

                    </tbody>
                </table>

            </div>

            <!-- FOOTER -->
            <div class="px-6 py-4 border-t border-gray-100 flex justify-end">
                <button onclick="closeViewContactModal()"
                    class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-slate-700 rounded-xl text-xs font-semibold transition-all">
                    Close
                </button>
            </div>

        </div>
    </div>

    <script>
        // --- INITIALIZE INTEGRATED QUILL FRAMEWORK INSTANCE ON DOM LOAD ---
        let quillInstance;
        document.addEventListener("DOMContentLoaded", function() {
            quillInstance = new Quill('#editor-container', {
                theme: 'snow',
                placeholder: 'Compose message payload seamlessly here... Use formatting options above, or inject shortcodes to personalize execution mappings.',
                modules: {
                    toolbar: [
                        ['bold', 'italic', 'underline'],
                        [{
                            'list': 'ordered'
                        }, {
                            'list': 'bullet'
                        }],
                        ['clean']
                    ]
                }
            });
        });

        // --- STATUS TOGGLE ACTIONS ---
        function toggleSendingStatus(contactId, buttonElement) {
            buttonElement.disabled = true;
            buttonElement.style.opacity = '0.5';

            fetch(`all-contacts.php?action=toggle_status&id=${contactId}`)
                .then(res => res.json())
                .then(data => {
                    buttonElement.disabled = false;
                    buttonElement.style.opacity = '1';

                    if (data.success) {
                        buttonElement.textContent = data.new_status;
                        if (data.new_status === 'Active') {
                            buttonElement.className = "status-btn px-2.5 py-1 rounded-lg font-bold text-[10px] uppercase transition-all tracking-wider border bg-emerald-50 text-emerald-600 border-emerald-200";
                        } else {
                            buttonElement.className = "status-btn px-2.5 py-1 rounded-lg font-bold text-[10px] uppercase transition-all tracking-wider border bg-rose-50 text-rose-500 border-rose-200";
                        }
                    } else {
                        showToastNotification('Failed to modify operational mode state logic.', 'error');
                    }
                })
                .catch(() => {
                    buttonElement.disabled = false;
                    buttonElement.style.opacity = '1';
                    showToastNotification('Connection to server framework disrupted.', 'error');
                });
        }

        // --- MODAL ENGINE BEHAVIORS MANAGER ---
        function openQuickMessageModal(targetEmail) {
            document.getElementById('modal-target-email').value = targetEmail;
            const modal = document.getElementById('quick-message-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeQuickMessageModal() {
            const modal = document.getElementById('quick-message-modal');
            modal.classList.remove('flex');
            modal.classList.add('hidden');

            // Wipe structural data memory buffers clean
            document.getElementById('modal-email-subject').value = '';
            document.getElementById('modal-is-html-select').value = '1';
            if (quillInstance) {
                quillInstance.setContents([]);
            }
        }

        // Safely injects personalization tags down into active Quill editor caret target frames point positions
        function injectMergeTag(tagString) {
            if (!quillInstance) return;

            // Fetch user selection caret placement array metrics
            const selectionRange = quillInstance.getSelection(true);
            if (selectionRange) {
                // Insert shortcode text chunk directly into workspace coordinates safely
                quillInstance.insertText(selectionRange.index, tagString, 'user');
                // Advance cursor tracking cleanly behind injected custom macro placeholder tags
                quillInstance.setSelection(selectionRange.index + tagString.length, 0);
            }
        }

        // --- EXECUTE DISPATCH VIA SECURE BACKEND CONTROLLER POST ENGINE ---
        function executeInstantMessageDispatch() {
            const email = document.getElementById('modal-target-email').value;
            const subject = document.getElementById('modal-email-subject').value.trim();
            const isHtml = document.getElementById('modal-is-html-select').value;
            const submitBtn = document.getElementById('modal-submit-btn');

            // Extract compiled raw text or full semantic text architecture configurations from Quill core pipeline
            let messageBody = '';
            if (quillInstance) {
                if (isHtml === '1') {
                    // Fetch internal structural html string parameters
                    messageBody = quillInstance.getSemanticHTML().trim();
                } else {
                    // Fetch clean textual plain string matrix parameters
                    messageBody = quillInstance.getText().trim();
                }
            }

            if (!subject || !messageBody || messageBody === '<p></p>') {
                alert('Please compose both Subject and Message body contents before launching execution streams.');
                return;
            }

            // Lock button interaction metrics states
            submitBtn.disabled = true;
            submitBtn.innerHTML = "<i class='fa-solid fa-spinner fa-spin mr-1.5'></i> Routing...";

            // Pack data elements payload sets safely array encoded matrix
            const formData = new FormData();
            formData.append('action', 'dispatch_instant_email');
            formData.append('email', email);
            formData.append('subject', subject);
            formData.append('message_body', messageBody);
            formData.append('is_html', isHtml);

            fetch('all-contacts.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = "<i class='fa-solid fa-paper-plane mr-1.5'></i> Fire Message";

                    if (data.success) {
                        showToastNotification(data.message, 'success');
                        closeQuickMessageModal();
                    } else {
                        alert('Transmission Failure: ' + data.error);
                    }
                })
                .catch(() => {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = "<i class='fa-solid fa-paper-plane mr-1.5'></i> Fire Message";
                    alert('Network communication with transmission deck disrupted.');
                });
        }

        // Global asynchronous toast dynamic feedback indicator alert element logic helper code
        function showToastNotification(message, type) {
            const alertBox = document.getElementById('dynamic-alert-container');
            alertBox.innerHTML = (type === 'success' ? "<i class='fa-solid fa-circle-check mr-2 text-sm align-middle'></i>" : "<i class='fa-solid fa-circle-exclamation mr-2 text-sm align-middle'></i>") + message;

            if (type === 'success') {
                alertBox.className = "bg-emerald-50 text-emerald-600 border border-emerald-100/70 p-4 rounded-xl shadow-sm block mb-6";
            } else {
                alertBox.className = "bg-rose-50 text-rose-600 border border-rose-100/70 p-4 rounded-xl shadow-sm block mb-6";
            }

            // Clear viewport after duration
            setTimeout(() => {
                alertBox.className = "hidden";
            }, 5000);
        }
        // --- VIEW CONTACT DETAILS MODAL ---
        function openViewContactModal(contactId) {

            const modal = document.getElementById('view-contact-modal');
            const tableBody = document.getElementById('contact-details-table');

            tableBody.innerHTML = `
        <tr>
            <td colspan="2" class="text-center py-10 text-gray-400">
                <i class="fa-solid fa-spinner fa-spin mr-2"></i>
                Loading contact details...
            </td>
        </tr>
    `;

            modal.classList.remove('hidden');
            modal.classList.add('flex');

            fetch(`all-contacts.php?action=view_contact&id=${contactId}`)
                .then(res => res.json())
                .then(data => {

                    if (!data.success) {

                        tableBody.innerHTML = `
                <tr>
                    <td colspan="2" class="text-center py-10 text-rose-500">
                        ${data.error}
                    </td>
                </tr>
            `;

                        return;
                    }

                    let html = '';

                    // Basic Info
                    html += `
            <tr class="bg-slate-50">
                <td class="px-4 py-3 font-bold text-slate-700 w-48">Email</td>
                <td class="px-4 py-3 text-slate-600">${data.contact.email ?? '-'}</td>
            </tr>

            <tr>
                <td class="px-4 py-3 font-bold text-slate-700">Campaign</td>
                <td class="px-4 py-3 text-slate-600">${data.contact.campaign_name ?? '-'}</td>
            </tr>

            <tr class="bg-slate-50">
                <td class="px-4 py-3 font-bold text-slate-700">Sending Status</td>
                <td class="px-4 py-3 text-slate-600">${data.contact.sending_status ?? '-'}</td>
            </tr>

            <tr>
                <td class="px-4 py-3 font-bold text-slate-700">Created Date</td>
                <td class="px-4 py-3 text-slate-600">${data.contact.created_at ?? '-'}</td>
            </tr>
        `;

                    // Custom Fields
                    if (data.fields.length > 0) {

                        data.fields.forEach(field => {

                            html += `
                    <tr class="bg-slate-50">
                        <td class="px-4 py-3 font-bold text-slate-700">
                            ${field.field_label}
                        </td>

                        <td class="px-4 py-3 text-slate-600 break-all">
                            ${field.field_value || '-'}
                        </td>
                    </tr>
                `;
                        });

                    } else {

                        html += `
                <tr>
                    <td colspan="2"
                        class="text-center py-6 text-gray-400 italic">
                        No custom fields found.
                    </td>
                </tr>
            `;
                    }

                    tableBody.innerHTML = html;

                })
                .catch(() => {

                    tableBody.innerHTML = `
            <tr>
                <td colspan="2"
                    class="text-center py-10 text-rose-500">
                    Failed to load contact details.
                </td>
            </tr>
        `;
                });
        }

        function closeViewContactModal() {

            const modal = document.getElementById('view-contact-modal');

            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }
    </script>

    <script>
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebar-overlay');
        const toggleBtn = document.getElementById('mobile-toggle');

        function openSidebar() {
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('translate-x-0');

            overlay.classList.remove('hidden');
        }

        function closeSidebar() {
            sidebar.classList.add('-translate-x-full');
            sidebar.classList.remove('translate-x-0');

            overlay.classList.add('hidden');
        }

        // OPEN
        toggleBtn.addEventListener('click', function() {
            openSidebar();
        });

        // CLOSE when clicking overlay
        overlay.addEventListener('click', function() {
            closeSidebar();
        });

        // CLOSE when clicking a menu item (mobile UX improvement)
        document.querySelectorAll('#sidebar a').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) {
                    closeSidebar();
                }
            });
        });
    </script>
</body>

</html>