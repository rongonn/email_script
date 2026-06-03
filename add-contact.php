<?php
require_once 'config/database.php';
check_auth();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Default fallback tab handler state
$active_tab = $_POST['active_tab'] ?? 'import';

// --- 1. FETCH ISOLATED ACTIVE CAMPAIGNS FOR SELECTION ---
$camp_stmt = $pdo->prepare("SELECT id, campaign_name FROM campaigns WHERE user_id = ? ORDER BY id DESC");
$camp_stmt->execute([$user_id]);
$campaigns = $camp_stmt->fetchAll();

// --- 2. FETCH REGISTERED CUSTOM FIELDS SCHEMAS ---
$fields_stmt = $pdo->prepare("SELECT id, field_label FROM custom_fields WHERE user_id = ? ORDER BY id ASC");
$fields_stmt->execute([$user_id]);
$custom_fields = $fields_stmt->fetchAll();

// --- 3. INTAKE ROUTINE EXECUTION CONTROLLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campaign_id = intval($_POST['campaign_id'] ?? 0);
    
    if ($campaign_id <= 0) {
        $error = "Please choose a valid target campaign space for segment processing.";
    } else {
        
        // ==========================================
        // APPROACH A: SINGLE MANUAL RECIPIENT WRITER (WITH INLINE NEW FIELDS CREATION)
        // ==========================================
        if (isset($_POST['action']) && $_POST['action'] === 'single_add') {
            $active_tab = 'manual';
            $email = trim($_POST['email'] ?? '');
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid target email routing destination.";
            } else {
                // Verify strict Campaign-Level Isolation footprint
                $check_stmt = $pdo->prepare("SELECT id FROM contacts WHERE user_id = ? AND campaign_id = ? AND email = ?");
                $check_stmt->execute([$user_id, $campaign_id, $email]);
                
                if ($check_stmt->fetch()) {
                    $error = "The contact details [<strong>{$email}</strong>] already exist inside this chosen campaign segment.";
                } else {
                    $pdo->beginTransaction();
                    try {
                        // 1. Write root operational contact row
                        $ins = $pdo->prepare("INSERT INTO contacts (user_id, campaign_id, email, sending_status, created_at) VALUES (?, ?, ?, 'Active', NOW())");
                        $ins->execute([$user_id, $campaign_id, $email]);
                        $contact_id = $pdo->lastInsertId();

                        // Queue emails for this new contact
                        $queue_stmt = $pdo->prepare("
                            INSERT IGNORE INTO email_queue (user_id, template_id, contact_id, scheduled_at)
                            SELECT ?, et.id, ?, DATE_ADD(NOW(), INTERVAL et.delay_days DAY)
                            FROM email_templates et
                            WHERE et.campaign_id = ? AND et.user_id = ?
                        ");
                        $queue_stmt->execute([$user_id, $contact_id, $campaign_id, $user_id]);
                        
                        $val_stmt = $pdo->prepare("INSERT INTO contact_field_values (contact_id, field_id, field_value) VALUES (?, ?, ?)");

                        // 2. Process Existing Custom Field Attributes Array safely
                        if (!empty($_POST['custom_fields']) && is_array($_POST['custom_fields'])) {
                            foreach ($_POST['custom_fields'] as $field_id => $value) {
                                $trimmed_val = trim($value);
                                if ($trimmed_val !== '') {
                                    $val_stmt->execute([$contact_id, intval($field_id), $trimmed_val]);
                                }
                            }
                        }
                        
                        // 3. Process Newly Created Dynamic Fields safely inline
                        if (!empty($_POST['new_field_labels']) && is_array($_POST['new_field_labels'])) {
                            $new_labels = $_POST['new_field_labels'];
                            $new_values = $_POST['new_field_values'] ?? [];
                            
                            $create_field_stmt = $pdo->prepare("INSERT INTO custom_fields (user_id, field_label) VALUES (?, ?)");
                            
                            foreach ($new_labels as $index => $label) {
                                $trimmed_label = trim($label);
                                $trimmed_val = trim($new_values[$index] ?? '');
                                
                                if ($trimmed_label !== '' && $trimmed_val !== '') {
                                    // Check if this label already exists globally for the user to prevent duplication
                                    $check_f = $pdo->prepare("SELECT id FROM custom_fields WHERE user_id = ? AND LOWER(field_label) = LOWER(?)");
                                    $check_f->execute([$user_id, $trimmed_label]);
                                    $existing_f = $check_f->fetch();
                                    
                                    if ($existing_f) {
                                        $target_field_id = $existing_f['id'];
                                    } else {
                                        // Insert new metadata structure row schema
                                        $create_field_stmt->execute([$user_id, $trimmed_label]);
                                        $target_field_id = $pdo->lastInsertId();
                                    }
                                    
                                    // Write entry map value
                                    $val_stmt->execute([$contact_id, $target_field_id, $trimmed_val]);
                                }
                            }
                        }
                        
                        $pdo->commit();
                        $success = "Profile records and associated customized fields saved successfully targeting [{$email}].";
                        
                        // Refresh schema fields list mapping context updates
                        $fields_stmt->execute([$user_id]);
                        $custom_fields = $fields_stmt->fetchAll();
                        
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        $error = "Transaction protection failure running pipeline operations: " . $e->getMessage();
                    }
                }
            }
        }
        
        // ==========================================
        // APPROACH B: BULK CSV INTAKE ENGINE
        // ==========================================
        if (!isset($_POST['action']) && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $active_tab = 'import';
            $file_tmp = $_FILES['csv_file']['tmp_name'];
            
            if (($handle = fopen($file_tmp, "r")) !== FALSE) {
                $inserted_count = 0;
                $duplicate_count = 0;
                
                $headers = fgetcsv($handle, 1000, ",");
                $email_index = -1;
                $field_mapping = []; 
                
                if ($headers !== FALSE) {
                    foreach ($headers as $index => $header_name) {
                        $cleaned_header = strtolower(trim($header_name));
                        if ($cleaned_header === 'email') {
                            $email_index = $index;
                            continue;
                        }
                        
                        foreach ($custom_fields as $cf) {
                            $normalized_label = strtolower(str_replace(' ', '_', trim($cf['field_label'])));
                            if ($normalized_label === $cleaned_header || strtolower(trim($cf['field_label'])) === $cleaned_header) {
                                $field_mapping[$index] = $cf['id'];
                                break;
                            }
                        }
                    }
                }
                
                if ($email_index === -1) { $email_index = 0; }
                
                $check_stmt = $pdo->prepare("SELECT id FROM contacts WHERE user_id = ? AND campaign_id = ? AND email = ?");
                $insert_contact = $pdo->prepare("INSERT INTO contacts (user_id, campaign_id, email, sending_status, created_at) VALUES (?, ?, ?, 'Active', NOW())");
                $insert_value = $pdo->prepare("INSERT INTO contact_field_values (contact_id, field_id, field_value) VALUES (?, ?, ?)");
                $insert_queue = $pdo->prepare("
                    INSERT IGNORE INTO email_queue (user_id, template_id, contact_id, scheduled_at)
                    SELECT ?, et.id, ?, DATE_ADD(NOW(), INTERVAL et.delay_days DAY)
                    FROM email_templates et
                    WHERE et.campaign_id = ? AND et.user_id = ?
                ");
                
                $pdo->beginTransaction();
                try {
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        if (empty($data[$email_index])) continue;
                        
                        $email = trim($data[$email_index]);
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strtolower($email) === 'email') {
                            continue; 
                        }
                        
                        $check_stmt->execute([$user_id, $campaign_id, $email]);
                        if ($check_stmt->fetch()) {
                            $duplicate_count++;
                        } else {
                            $insert_contact->execute([$user_id, $campaign_id, $email]);
                            $new_contact_id = $pdo->lastInsertId();

                            // Queue emails for this new contact
                            $insert_queue->execute([$user_id, $new_contact_id, $campaign_id, $user_id]);
                            
                            foreach ($data as $col_idx => $col_val) {
                                if (isset($field_mapping[$col_idx]) && trim($col_val) !== '') {
                                    $insert_value->execute([$new_contact_id, $field_mapping[$col_idx], trim($col_val)]);
                                }
                            }
                            $inserted_count++;
                        }
                    }
                    $pdo->commit();
                    
                    if ($inserted_count > 0) {
                        $success = "Bulk database intake completed! Registered <strong>{$inserted_count}</strong> new tracking records.";
                        if ($duplicate_count > 0) {
                            $success .= " Ignored <strong>{$duplicate_count}</strong> existing duplicates.";
                        }
                    } else if ($duplicate_count > 0) {
                        $error = "Intake stream halted. All <strong>{$duplicate_count}</strong> target emails were rejected as duplicate entries.";
                    } else {
                        $error = "The uploaded file layout matrix was empty or failed parameter verification paths.";
                    }
                    
                } catch (Exception $ex) {
                    $pdo->rollBack();
                    $error = "Bulk critical pipeline execution error occurred: " . $ex->getMessage();
                }
                fclose($handle);
            } else {
                $error = "Failed to unlock data streams for selected CSV upload.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import and Add Contacts - FireMailing AI</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-gray-50 font-sans text-slate-700 antialiased">

    <div class="flex min-h-screen overflow-hidden">
        <?php require_once 'components/sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">
            <?php require_once 'components/header.php'; ?>

            <main class="flex-grow p-6 md:p-8 space-y-6 max-w-4xl w-full mx-auto">
                
                <!-- Page Header Nav -->
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-xl font-bold text-slate-900 tracking-tight">Audience Directory Portal</h1>
                        <p class="text-xs text-gray-400 mt-0.5">Import datasets via file tracking channels or write records manually down inline.</p>
                    </div>
                    <a href="all-contacts.php" class="inline-flex items-center text-xs font-semibold text-slate-500 hover:text-indigo-600 transition-colors">
                        <i class="fa-solid fa-arrow-left-long mr-1.5"></i> Back to Directory Ledger
                    </a>
                </div>

                <!-- Response Alert Status Bars -->
                <?php if ($error): ?>
                    <div class="bg-rose-50 text-rose-600 text-xs p-4 rounded-xl border border-rose-100/70 shadow-sm">
                        <i class="fa-solid fa-circle-exclamation mr-2 text-sm align-middle"></i><?php echo $error; ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="bg-emerald-50 text-emerald-600 text-xs p-4 rounded-xl border border-emerald-100/70 shadow-sm">
                        <i class="fa-solid fa-circle-check mr-2 text-sm align-middle"></i><?php echo $success; ?>
                    </div>
                <?php endif; ?>

                <!-- CORE DATA INGESTION SUITE FORM -->
                <form action="add-contact.php" method="POST" enctype="multipart/form-data" class="space-y-6">
                    <!-- Tracking context input layer for dynamic layout state engine persistence -->
                    <input type="hidden" name="active_tab" id="active_tab_input" value="<?php echo htmlspecialchars($active_tab); ?>">
                    
                    <!-- BASE BLOCK: STRATIFIED WORKFLOW TARGETING LAYER -->
                    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6 space-y-4">
                        <div class="border-b border-gray-50 pb-3">
                            <h2 class="text-sm font-bold text-slate-900 flex items-center gap-2"><span class="w-5 h-5 bg-indigo-50 text-indigo-600 text-[10px] rounded-full inline-flex items-center justify-center font-bold">1</span> Target Campaign Segment Isolation</h2>
                            <p class="text-[11px] text-gray-400 mt-0.5">Isolates global address metrics down safely targeting singular delivery arrays.</p>
                        </div>
                        
                        <div>
                            <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Select Destination Campaign Vector *</label>
                            <div class="relative">
                                <select name="campaign_id" required class="w-full pl-3.5 pr-10 py-3 bg-gray-50/50 border border-gray-200 text-xs font-medium rounded-xl appearance-none focus:outline-none focus:border-indigo-500 transition-all">
                                    <option value="" disabled selected>-- Choose an Isolated Campaign Queue --</option>
                                    <?php foreach ($campaigns as $camp): ?>
                                        <option value="<?php echo $camp['id']; ?>" <?php echo (isset($_POST['campaign_id']) && $_POST['campaign_id'] == $camp['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($camp['campaign_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fa-solid fa-chevron-down absolute right-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-[10px] pointer-events-none"></i>
                            </div>
                        </div>
                    </div>

                    <!-- CENTRAL WORKSPACE PANEL WITH SEGMENT NAVIGATION CONTROL -->
                    <div class="bg-white border border-gray-100 rounded-2xl shadow-sm overflow-hidden">
                        
                        <!-- TAB HEADER TRIGGER DECK -->
                        <div class="flex border-b border-gray-100 bg-gray-50/50 p-2 gap-1">
                            <button type="button" onclick="switchWorkspaceTab('import')" id="tab-trigger-import" class="flex-1 md:flex-none inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-xs font-semibold transition-all duration-200">
                                <i class="fa-solid fa-cloud-arrow-up text-sm"></i> Import Contact (CSV File)
                            </button>
                            <button type="button" onclick="switchWorkspaceTab('manual')" id="tab-trigger-manual" class="flex-1 md:flex-none inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-xl text-xs font-semibold transition-all duration-200">
                                <i class="fa-solid fa-user-plus text-sm"></i> Manually Add Contact
                            </button>
                        </div>

                        <!-- PANEL BOXES SYSTEM CONTAINER CONTENTS -->
                        <div class="p-6">
                            
                            <!-- TAB CONTAINER CHANNEL A: EXTRACTIVE PROCESSING SHEET DECK -->
                            <div id="tab-panel-import" class="space-y-6 hidden">
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900">Bulk File CSV Import Drop</h3>
                                    <p class="text-[11px] text-gray-400 mt-0.5">Upload flat relational tracking records containing matching parameter values.</p>
                                </div>
                                
                                <div class="space-y-3">
                                    <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Upload CSV Data Matrix Sheet</label>
                                    <div class="border-2 border-dashed border-gray-200 rounded-xl p-8 text-center bg-gray-50/20 hover:bg-gray-50/80 transition-all relative">
                                        <input type="file" name="csv_file" accept=".csv" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" onchange="updateFileLabel(this)">
                                        <i class="fa-solid fa-file-csv text-slate-400 text-4xl mb-2 block"></i>
                                        <span id="file-upload-label" class="text-xs text-slate-500 font-medium block">Click to browse local files or drop standard .csv matrix sheets here</span>
                                    </div>
                                    <div class="bg-amber-50/60 rounded-xl p-3.5 border border-amber-100/70 text-[11px] text-slate-600 space-y-1">
                                        <span class="font-bold text-amber-800 block"><i class="fa-solid fa-lightbulb mr-1"></i> Data Structural Pro-Tip:</span>
                                        Ensure columns inside headers resemble targeted global metrics exactly (e.g. <code>Email</code>, <code>First Name</code>) to avoid parsing drop exceptions.
                                    </div>
                                </div>

                                <div class="pt-4 border-t border-gray-50 flex justify-end">
                                    <button type="submit" class="px-6 py-3 bg-slate-900 hover:bg-slate-800 text-white rounded-xl text-xs font-semibold shadow-sm transition-all"><i class="fa-solid fa-gears mr-1.5"></i> Run Dynamic Sheet Parser</button>
                                </div>
                            </div>

                            <!-- TAB CONTAINER CHANNEL B: SINGLE RECORD SYSTEM MATRIX WRITER -->
                            <div id="tab-panel-manual" class="space-y-6 hidden">
                                <div>
                                    <h3 class="text-sm font-bold text-slate-900">Single Manual Record Entry</h3>
                                    <p class="text-[11px] text-gray-400 mt-0.5">Quickly key profile data points directly into operational routing pools.</p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                                    <!-- Primary Unique Index Identity Node Row -->
                                    <div class="md:col-span-2">
                                        <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Recipient Identity (Email) *</label>
                                        <input type="email" name="email" placeholder="john.doe@domain.com" class="w-full px-3.5 py-3 border border-gray-200 text-xs rounded-xl focus:outline-none focus:border-indigo-500 bg-gray-50/30 transition-all">
                                    </div>

                                    <!-- DYNAMIC SAVED FIELD MATRIX MAP BLOCKS -->
                                    <?php if (!empty($custom_fields)): ?>
                                        <div class="md:col-span-2 pt-2 border-t border-gray-50 space-y-3.5">
                                            <span class="block text-[10px] font-bold text-indigo-500 uppercase tracking-widest tracking-wider">Registered Extended Tracking Parameters</span>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                <?php foreach ($custom_fields as $field): ?>
                                                    <div>
                                                        <label class="block text-[11px] font-medium text-slate-600 mb-1.5"><?php echo htmlspecialchars($field['field_label']); ?></label>
                                                        <input type="text" name="custom_fields[<?php echo $field['id']; ?>]" placeholder="Enter tracking value..." class="w-full px-3.5 py-2.5 border border-gray-200 text-xs rounded-xl focus:outline-none focus:border-indigo-400 transition-all">
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <!-- REALTIME INLINE SCHEMA FIELD GENERATOR INTAKE ENGINE -->
                                    <div class="md:col-span-2 pt-4 border-t border-gray-50 space-y-3">
                                        <div class="flex items-center justify-between">
                                            <div>
                                                <span class="block text-[10px] font-bold text-emerald-600 uppercase tracking-widest tracking-wider">Dynamic Schema Extension Setup</span>
                                                <p class="text-[11px] text-gray-400">Add missing attribute parameters right here on the fly.</p>
                                            </div>
                                            <button type="button" onclick="appendDynamicInputField()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 rounded-lg text-[11px] font-bold transition-all">
                                                <i class="fa-solid fa-plus-circle"></i> Create New Custom Field
                                            </button>
                                        </div>

                                        <!-- Container Element where Javascript spawns the input couples safely down inside -->
                                        <div id="dynamic-runtime-fields-wrapper" class="space-y-3"></div>
                                    </div>
                                </div>

                                <div class="pt-4 border-t border-gray-50 flex justify-end">
                                    <button type="submit" name="action" value="single_add" class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-semibold shadow-sm transition-all"><i class="fa-solid fa-user-plus mr-1.5"></i> Save Profile Records</button>
                                </div>
                            </div>

                        </div>
                    </div>
                </form>

            </main>
        </div>
    </div>

    <!-- RUNTIME CONTROL BEHAVIOR INTERACTION CODES -->
    <script>
    // --- WORKSPACE TAB DECK ROUTING MANAGER ---
    function switchWorkspaceTab(targetTab) {
        const importTrigger = document.getElementById('tab-trigger-import');
        const manualTrigger = document.getElementById('tab-trigger-manual');
        const importPanel = document.getElementById('tab-panel-import');
        const manualPanel = document.getElementById('tab-panel-manual');
        const hiddenInput = document.getElementById('active_tab_input');

        // Reset visual state patterns 
        [importTrigger, manualTrigger].forEach(el => el.classList.remove('bg-white', 'text-indigo-600', 'shadow-sm', 'text-slate-600', 'hover:bg-gray-100/50'));
        [importPanel, manualPanel].forEach(el => el.classList.add('hidden'));

        if (targetTab === 'manual') {
            manualTrigger.classArr = manualTrigger.className += ' bg-white text-indigo-600 shadow-sm';
            importTrigger.className += ' text-slate-600 hover:bg-gray-100/50';
            manualPanel.classList.remove('hidden');
            hiddenInput.value = 'manual';
        } else {
            importTrigger.className += ' bg-white text-indigo-600 shadow-sm';
            manualTrigger.className += ' text-slate-600 hover:bg-gray-100/50';
            importPanel.classList.remove('hidden');
            hiddenInput.value = 'import';
        }
    }

    // --- INLINE INTAKE SCHEMA CODES INJECTOR ---
    function appendDynamicInputField() {
        const container = document.getElementById('dynamic-runtime-fields-wrapper');
        
        // Base node wrapper row frame
        const rowNode = document.createElement('div');
        rowNode.className = "grid grid-cols-1 md:grid-cols-12 gap-3 items-center bg-slate-50/50 border border-gray-100 rounded-xl p-3 relative group animate-[fadeIn_0.2s_ease-out]";
        
        rowNode.innerHTML = `
            <div class="md:col-span-5">
                <input type="text" name="new_field_labels[]" placeholder="Field Label (e.g. Phone Number, Age)" required class="w-full px-3 py-2 border border-gray-200 text-xs rounded-lg focus:outline-none focus:border-emerald-500 bg-white transition-all font-medium">
            </div>
            <div class="md:col-span-6">
                <input type="text" name="new_field_values[]" placeholder="Value for this contact..." required class="w-full px-3 py-2 border border-gray-200 text-xs rounded-lg focus:outline-none focus:border-emerald-500 bg-white transition-all">
            </div>
            <div class="md:col-span-1 flex justify-center">
                <button type="button" onclick="this.closest('.grid').remove()" class="text-gray-400 hover:text-rose-500 transition-colors p-1.5 text-xs" title="Remove Field Configuration">
                    <i class="fa-solid fa-trash-can text-sm"></i>
                </button>
            </div>
        `;
        
        container.appendChild(rowNode);
    }

    // Updates UI local visual labels during document upload interactions
    function updateFileLabel(inputElement) {
        const fileLabel = document.getElementById('file-upload-label');
        if (inputElement.files && inputElement.files[0]) {
            fileLabel.innerHTML = "Selected file configuration asset: <strong class='text-indigo-600'>" + inputElement.files[0].name + "</strong>";
        } else {
            fileLabel.textContent = "Click to browse local files or drop standard .csv matrix sheets here";
        }
    }

    // Initialize layout setup state directly based on server side parameters footprint configuration rules
    document.addEventListener("DOMContentLoaded", function() {
        switchWorkspaceTab('<?php echo $active_tab; ?>');
    });
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
</body>
</html>