<?php
require_once 'config/database.php';
check_auth();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Mode modifiers initialization
$edit_mode = false;
$edit_id = 0;
$form_template_name = '';
$form_campaign_id = 0;
$form_subject = '';
$form_delay_days = 0;
$form_message_body = '';
$form_track_open = 1;
$form_track_click = 1;

// --- 1. ACTION ROUTINE: EXTRACT RECORD DATA FOR LOAD EDIT TARGET ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $edit_id = intval($_GET['id']);

    $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE id = ? AND user_id = ?");
    $stmt->execute([$edit_id, $user_id]);
    $template = $stmt->fetch();

    if ($template) {
        $edit_mode = true;
        $form_template_name = $template['template_name'];
        $form_campaign_id = $template['campaign_id'];
        $form_subject = $template['subject'];
        $form_delay_days = intval($template['delay_days']);
        $form_message_body = $template['message_body'];
        $form_track_open = intval($template['track_open'] ?? 1);
        $form_track_click = intval($template['track_click'] ?? 1);
    } else {
        $error = "Requested sequence template tracking structure was missing.";
    }
}

// --- 2. CONTROLLER POST REQUEST WRITE EXECUTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $template_name = trim($_POST['template_name'] ?? '');
        $campaign_id = intval($_POST['campaign_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $delay_days = max(0, intval($_POST['delay_days'] ?? 0));

        $message_body = trim($_POST['message_body_html'] ?? '');

        $track_open = isset($_POST['track_open']) ? 1 : 0;
        $track_click = isset($_POST['track_click']) ? 1 : 0;

        $submit_id = intval($_POST['edit_id'] ?? 0);

        if (
            empty($template_name) ||
            $campaign_id <= 0 ||
            empty($subject) ||
            empty($message_body)
        ) {
            throw new Exception("All required fields must be populated.");
        }

        // HTML ONLY
        $is_html = 1;

        if ($submit_id > 0) {

            $up = $pdo->prepare("
                UPDATE email_templates 
                SET 
                    template_name = ?, 
                    campaign_id = ?, 
                    subject = ?, 
                    message_body = ?, 
                    is_html = ?, 
                    delay_days = ?, 
                    track_open = ?, 
                    track_click = ? 
                WHERE id = ? AND user_id = ?
            ");

            if (!$up->execute([
                $template_name,
                $campaign_id,
                $subject,
                $message_body,
                $is_html,
                $delay_days,
                $track_open,
                $track_click,
                $submit_id,
                $user_id
            ])) {
                throw new Exception("Failed to update record in database.");
            }

            // Sync/queue emails for any contacts who haven't received this template yet
            $queue_stmt = $pdo->prepare("
                INSERT IGNORE INTO email_queue (user_id, template_id, contact_id, scheduled_at)
                SELECT ?, ?, c.id, DATE_ADD(NOW(), INTERVAL ? DAY)
                FROM contacts c
                LEFT JOIN email_logs el ON el.template_id = ? AND el.contact_id = c.id
                WHERE c.campaign_id = ? AND c.user_id = ? AND el.id IS NULL
            ");
            $queue_stmt->execute([$user_id, $submit_id, $delay_days, $submit_id, $campaign_id, $user_id]);

            header("Location: email-templates.php?msg=updated");
            exit;

        } else {

            $ins = $pdo->prepare("
                INSERT INTO email_templates 
                (
                    user_id,
                    campaign_id,
                    template_name,
                    subject,
                    message_body,
                    is_html,
                    delay_days,
                    track_open,
                    track_click
                ) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$ins->execute([
                $user_id,
                $campaign_id,
                $template_name,
                $subject,
                $message_body,
                $is_html,
                $delay_days,
                $track_open,
                $track_click
            ])) {
                throw new Exception("Failed to insert record into database.");
            }

            $new_template_id = $pdo->lastInsertId();

            // Queue emails for existing contacts in this campaign
            $queue_stmt = $pdo->prepare("
                INSERT IGNORE INTO email_queue (user_id, template_id, contact_id, scheduled_at)
                SELECT ?, ?, c.id, DATE_ADD(NOW(), INTERVAL ? DAY)
                FROM contacts c
                LEFT JOIN email_logs el ON el.template_id = ? AND el.contact_id = c.id
                WHERE c.campaign_id = ? AND c.user_id = ? AND el.id IS NULL
            ");
            $queue_stmt->execute([$user_id, $new_template_id, $delay_days, $new_template_id, $campaign_id, $user_id]);

            header("Location: email-templates.php?msg=created");
            exit;
        }

    } catch (Exception $e) {

        $error = "Error: " . $e->getMessage();

        $form_template_name = $_POST['template_name'] ?? '';
        $form_campaign_id = $_POST['campaign_id'] ?? 0;
        $form_subject = $_POST['subject'] ?? '';
        $form_delay_days = $delay_days ?? 0;
        $form_message_body = $_POST['message_body_html'] ?? '';
        $form_track_open = $track_open ?? 1;
        $form_track_click = $track_click ?? 1;

        if ($submit_id > 0) {
            $edit_mode = true;
            $edit_id = $submit_id;
        }
    }
}

// --- 3. GATHER AUXILIARY RUNTIME STRUCT DATA ---
$camp_stmt = $pdo->prepare("
    SELECT id, campaign_name 
    FROM campaigns 
    WHERE user_id = ? 
    ORDER BY id DESC
");

$camp_stmt->execute([$user_id]);
$campaigns = $camp_stmt->fetchAll();

$fields_stmt = $pdo->prepare("
    SELECT field_label 
    FROM custom_fields 
    WHERE user_id = ? 
    ORDER BY id ASC
");

$fields_stmt->execute([$user_id]);

$global_custom_fields = $fields_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?php echo $edit_mode ? 'Edit Template' : 'Create Template'; ?> - FireMailing AI
    </title>

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet" href="assets/style.css">

    <style>

        body{
            font-family: 'Inter', sans-serif;
        }

        textarea{
            min-height: 450px;
        }

    </style>

</head>

<body class="bg-gray-50 font-sans text-slate-700 antialiased">

<div class="flex min-h-screen overflow-hidden">

    <?php require_once 'components/sidebar.php'; ?>

    <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">

        <?php require_once 'components/header.php'; ?>

        <main class="flex-grow p-6 md:p-8 space-y-6 max-w-7xl w-full mx-auto">

            <div class="flex items-center space-x-2 text-xs text-gray-400">

                <a href="email-templates.php"
                   class="hover:text-indigo-600 font-medium transition-colors">
                    Templates Ledger
                </a>

                <i class="fa-solid fa-chevron-right text-[9px]"></i>

                <span class="text-slate-700 font-semibold">
                    <?php echo $edit_mode ? 'Modify Active Strategy' : 'Construct New Automation Rule'; ?>
                </span>

            </div>

            <?php if ($error): ?>

                <div class="bg-rose-50 text-rose-600 text-xs p-4 rounded-xl border border-rose-100 shadow-sm">
                    <?php echo $error; ?>
                </div>

            <?php endif; ?>

            <div class="grid grid-cols-1 gap-6 items-start">

                <!-- LEFT -->
                <div class="w-full bg-white border border-gray-100 rounded-2xl shadow-sm p-6 space-y-4">

                   <div class="border-b border-gray-50 pb-3 flex justify-between items-center">
    
    <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2">
        <i class="fa-solid <?php echo $edit_mode ? 'fa-pen-to-square text-indigo-500' : 'fa-square-plus text-emerald-500'; ?>"></i>
        <?php echo $edit_mode ? 'Modify Active Template Settings' : 'Design Sequence Automation Node'; ?>
    </h2>

    <button
        type="button"
        onclick="openPreviewModal()"
        class="px-5 py-2.5 rounded-xl text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 transition-all"
    >
        <i class="fa-solid fa-eye mr-1"></i>
        Preview
    </button>
    
</div>

                    <form action="create-template.php"
                          method="POST"
                          id="template-builder-form"
                          class="space-y-4">

                        <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">

                        <div>

                            <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">
                                Message Workflow Identifier Name *
                            </label>

                            <input
                                    type="text"
                                    name="template_name"
                                    value="<?php echo htmlspecialchars($form_template_name); ?>"
                                    required
                                    placeholder="e.g., Lead Conversion Nurture Sequence - Day 3"
                                    class="w-full px-3.5 py-2.5 border border-gray-200 text-xs rounded-xl focus:outline-none focus:border-indigo-500 font-medium"
                            >

                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                            <div>

                                <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">
                                    Target Campaign Segment Loop *
                                </label>

                                <div class="relative">

                                    <select
                                            name="campaign_id"
                                            required
                                            class="w-full pl-3 pr-8 py-2.5 bg-gray-50/50 border border-gray-200 text-xs rounded-xl appearance-none focus:outline-none focus:border-indigo-500 font-medium"
                                    >

                                        <option value="" disabled selected>
                                            -- Choose Target Segment --
                                        </option>

                                        <?php foreach ($campaigns as $camp): ?>

                                            <option
                                                    value="<?php echo $camp['id']; ?>"
                                                <?php echo ($form_campaign_id == $camp['id']) ? 'selected' : ''; ?>
                                            >
                                                <?php echo htmlspecialchars($camp['campaign_name']); ?>
                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                    <i class="fa-solid fa-chevron-down absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-[10px] pointer-events-none"></i>

                                </div>

                            </div>

                            <div>

                                <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">
                                    Execution Delay Timeline *
                                </label>

                                <div class="relative">

                                    <input
                                            type="number"
                                            name="delay_days"
                                            min="0"
                                            value="<?php echo $form_delay_days; ?>"
                                            required
                                            class="w-full pl-3.5 pr-14 py-2.5 border border-gray-200 text-xs rounded-xl focus:outline-none focus:border-indigo-500 font-bold text-slate-800"
                                    >

                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-bold text-slate-400 uppercase tracking-wider pointer-events-none">
                                        Days
                                    </span>

                                </div>

                            </div>

                        </div>

                        <div>

                            <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">
                                Email Subject Line Formulation *
                            </label>

                            <input
                                    type="text"
                                    id="input-subject"
                                    name="subject"
                                    value="<?php echo htmlspecialchars($form_subject); ?>"
                                    required
                                    onkeyup="updateLiveViewportPreviewFrames()"
                                    placeholder="Type subject header..."
                                    class="w-full px-3.5 py-2.5 border border-gray-200 text-xs rounded-xl focus:outline-none focus:border-indigo-500 font-medium"
                            >

                        </div>

                        <!-- TRACKING -->
                        <div class="bg-slate-50 border border-slate-100 p-3 rounded-xl space-y-2">

                            <label class="block text-[10px] font-bold text-slate-400 uppercase tracking-wider">
                                Metrics Analytics & Performance Tracking
                            </label>

                            <div class="grid grid-cols-2 gap-4">

                                <label class="flex items-center gap-2 cursor-pointer select-none">

                                    <input
                                            type="checkbox"
                                            name="track_open"
                                            value="1"
                                        <?php echo ($form_track_open === 1) ? 'checked' : ''; ?>
                                            class="w-4 h-4 border-gray-300 text-indigo-600 rounded focus:ring-indigo-500 accent-indigo-600"
                                    >

                                    <span class="text-xs font-medium text-slate-600 flex items-center gap-1.5">
                                        <i class="fa-solid fa-envelope-open text-slate-400 text-[11px]"></i>
                                        Track Opens
                                    </span>

                                </label>

                                <label class="flex items-center gap-2 cursor-pointer select-none">

                                    <input
                                            type="checkbox"
                                            name="track_click"
                                            value="1"
                                        <?php echo ($form_track_click === 1) ? 'checked' : ''; ?>
                                            class="w-4 h-4 border-gray-300 text-indigo-600 rounded focus:ring-indigo-500 accent-indigo-600"
                                    >

                                    <span class="text-xs font-medium text-slate-600 flex items-center gap-1.5">
                                        <i class="fa-solid fa-arrow-pointer text-slate-400 text-[11px]"></i>
                                        Track Clicks
                                    </span>

                                </label>

                            </div>

                        </div>

                        <!-- CUSTOM FIELDS -->
                        <div class="space-y-1">

                            <label class="block text-[10px] font-bold text-indigo-500 uppercase tracking-wider">
                                Dynamic Personalization Shortcodes Tool Deck
                            </label>

                            <div class="flex flex-wrap gap-1 p-2 bg-slate-50 border border-slate-100 rounded-xl max-h-[100px] overflow-y-auto">

                                <button
                                        type="button"
                                        onclick="insertFieldTagIntoWorkspaceActiveCaret('{{email}}')"
                                        class="px-2 py-0.5 bg-white border border-gray-200 hover:border-indigo-400 rounded text-[10px] font-medium transition-all text-slate-600"
                                >
                                    Email Field
                                </button>

                                <?php foreach ($global_custom_fields as $label):

                                    $slug = strtolower(str_replace(' ', '_', trim($label)));

                                    ?>

                                    <button
                                            type="button"
                                            onclick="insertFieldTagIntoWorkspaceActiveCaret('{{<?php echo $slug; ?>}}')"
                                            class="px-2 py-0.5 bg-white border border-gray-200 hover:border-indigo-400 rounded text-[10px] font-medium transition-all text-slate-600"
                                    >
                                        <?php echo htmlspecialchars($label); ?>
                                    </button>

                                <?php endforeach; ?>

                            </div>

                        </div>

                        <!-- HTML EDITOR -->
                        <div>

                            <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">
                                HTML Email Body
                            </label>

                            <textarea
                                    id="workspace-html-body"
                                    name="message_body_html"
                                    rows="16"
                                    onkeyup="updateLiveViewportPreviewFrames()"
                                    class="w-full px-3.5 py-3 border border-indigo-200 bg-indigo-50/5 text-xs rounded-xl focus:outline-none focus:border-indigo-500 font-mono resize-none"
                            ><?php echo htmlspecialchars($form_message_body); ?></textarea>

                        </div>

                        <div class="pt-3 flex items-center justify-end gap-2 border-t border-gray-100">
                            

                            <a href="email-templates.php"
                               class="px-4 py-2 rounded-xl text-xs font-semibold bg-gray-100 hover:bg-gray-200 text-slate-500 transition-all">
                                Back to Table
                            </a>

                            <button
                                    type="submit"
                                    class="px-5 py-2.5 rounded-xl text-xs font-semibold text-white transition-all bg-indigo-600 hover:bg-indigo-700 shadow-sm shadow-indigo-100"
                            >

                                <i class="fa-solid fa-circle-check mr-1.5"></i>

                                <?php echo $edit_mode ? 'Compile Template Settings Changes' : 'Save & Arm Automation Template'; ?>

                            </button>

                        </div>

                    </form>

                </div>

             
                

            </div>

        </main>

    </div>

</div>

<script>

function insertFieldTagIntoWorkspaceActiveCaret(tag) {
    const textarea = document.getElementById('workspace-html-body');
    if (!textarea) return;

    const startPos = textarea.selectionStart;
    const endPos = textarea.selectionEnd;
    const text = textarea.value;

    // Insert the tag at current cursor position
    textarea.value = text.substring(0, startPos) + tag + text.substring(endPos);

    // Reposition cursor after the inserted tag
    textarea.focus();
    textarea.selectionStart = startPos + tag.length;
    textarea.selectionEnd = startPos + tag.length;

    // Trigger preview update
    updateLiveViewportPreviewFrames();
}

function updateLiveViewportPreviewFrames() {
    if (typeof updatePreviewModal === 'function') {
        updatePreviewModal();
    }
}

function openPreviewModal() {

    updatePreviewModal();

    document
        .getElementById('preview-modal')
        .classList.remove('hidden');
}

function closePreviewModal() {

    document
        .getElementById('preview-modal')
        .classList.add('hidden');
}

function updatePreviewModal() {

    const subject =
        document.getElementById('input-subject').value;

    const html =
        document.getElementById('workspace-html-body').value;

    document.getElementById('modal-subject').innerText =
        subject || 'No Subject';

    document.getElementById('modal-html-preview').innerHTML =
        html || '<div style="color:#999">No content available</div>';
}

function showDesktopPreview() {

    document
        .getElementById('preview-container')
        .style.maxWidth = '100%';

    document
        .getElementById('desktop-tab')
        .className =
        'px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm';

    document
        .getElementById('mobile-tab')
        .className =
        'px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm';
}

function showMobilePreview() {

    document
        .getElementById('preview-container')
        .style.maxWidth = '375px';

    document
        .getElementById('desktop-tab')
        .className =
        'px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm';

    document
        .getElementById('mobile-tab')
        .className =
        'px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm';
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
toggleBtn.addEventListener('click', function () {
    openSidebar();
});

// CLOSE when clicking overlay
overlay.addEventListener('click', function () {
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


<!-- PREVIEW MODAL -->
<div
    id="preview-modal"
    class="fixed inset-0 z-50 hidden bg-black/60 flex items-center justify-center p-4"
>

    <div class="bg-white rounded-2xl w-full max-w-7xl h-[90vh] flex flex-col overflow-hidden">

        <div class="flex items-center justify-between px-6 py-4 border-b">

            <h3 class="font-bold text-slate-800">
                Email Preview
            </h3>

            <button
                onclick="closePreviewModal()"
                class="text-gray-500 hover:text-red-500"
            >
                <i class="fa-solid fa-xmark text-xl"></i>
            </button>

        </div>

        <div class="flex gap-2 p-4 border-b">

            <button
                id="desktop-tab"
                onclick="showDesktopPreview()"
                class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm"
            >
                Desktop Preview
            </button>

            <button
                id="mobile-tab"
                onclick="showMobilePreview()"
                class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg text-sm"
            >
                Mobile Preview
            </button>

        </div>

        <div class="flex-1 overflow-auto bg-slate-100 p-6">

            <div
                id="preview-container"
                class="mx-auto bg-white rounded-xl shadow overflow-hidden max-w-full"
            >

                <div class="bg-slate-50 border-b p-4">

                    <div class="text-sm">

                        <strong>Subject:</strong>

                        <span id="modal-subject">
                            No Subject
                        </span>

                    </div>

                </div>

                <div
                    id="modal-html-preview"
                    class="p-6"
                >
                </div>

            </div>

        </div>

    </div>

</div>


</body>
</html>