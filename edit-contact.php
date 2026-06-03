<?php

require_once 'config/database.php';
check_auth();

$user_id = $_SESSION['user_id'];

$error = '';
$success = '';

if (!isset($_GET['id'])) {
    die("Contact ID missing.");
}

$contact_id = intval($_GET['id']);


// ======================================================
// FETCH CONTACT
// ======================================================

$stmt = $pdo->prepare("
    SELECT *
    FROM contacts
    WHERE id = ? AND user_id = ?
");

$stmt->execute([$contact_id, $user_id]);

$contact = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contact) {
    die("Contact not found.");
}


// ======================================================
// FETCH CAMPAIGNS
// ======================================================

$campaign_stmt = $pdo->prepare("
    SELECT id, campaign_name
    FROM campaigns
    WHERE user_id = ?
    ORDER BY id DESC
");

$campaign_stmt->execute([$user_id]);

$campaigns = $campaign_stmt->fetchAll();


// ======================================================
// FETCH CUSTOM FIELDS
// ======================================================

$field_stmt = $pdo->prepare("
    SELECT *
    FROM custom_fields
    WHERE user_id = ?
    ORDER BY id ASC
");

$field_stmt->execute([$user_id]);

$custom_fields = $field_stmt->fetchAll();


// ======================================================
// FETCH EXISTING FIELD VALUES
// ======================================================

$existing_values_stmt = $pdo->prepare("
    SELECT *
    FROM contact_field_values
    WHERE contact_id = ?
");

$existing_values_stmt->execute([$contact_id]);

$existing_values_raw = $existing_values_stmt->fetchAll(PDO::FETCH_ASSOC);

$existing_values = [];

foreach ($existing_values_raw as $row) {
    $existing_values[$row['field_id']] = $row['field_value'];
}


// ======================================================
// UPDATE CONTACT
// ======================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $email = trim($_POST['email'] ?? '');
        $campaign_id = intval($_POST['campaign_id'] ?? 0);
        $sending_status = trim($_POST['sending_status'] ?? 'Active');

        if (empty($email)) {
            throw new Exception("Email is required.");
        }

        // UPDATE CONTACT TABLE
        $update_stmt = $pdo->prepare("
            UPDATE contacts
            SET
                email = ?,
                campaign_id = ?,
                sending_status = ?
            WHERE id = ? AND user_id = ?
        ");

        $update_stmt->execute([
            $email,
            $campaign_id,
            $sending_status,
            $contact_id,
            $user_id
        ]);


        // ==========================================
        // UPDATE CUSTOM FIELDS
        // ==========================================

        foreach ($custom_fields as $field) {

            $field_id = $field['id'];

            $field_value = trim($_POST['custom_field'][$field_id] ?? '');

            // CHECK EXISTING
            $check_stmt = $pdo->prepare("
                SELECT id
                FROM contact_field_values
                WHERE contact_id = ?
                AND field_id = ?
            ");

            $check_stmt->execute([
                $contact_id,
                $field_id
            ]);

            $existing = $check_stmt->fetch();

            if ($existing) {

                // UPDATE
                $up_stmt = $pdo->prepare("
                    UPDATE contact_field_values
                    SET field_value = ?
                    WHERE contact_id = ?
                    AND field_id = ?
                ");

                $up_stmt->execute([
                    $field_value,
                    $contact_id,
                    $field_id
                ]);

            } else {

                // INSERT
                $ins_stmt = $pdo->prepare("
                    INSERT INTO contact_field_values
                    (
                        contact_id,
                        field_id,
                        field_value
                    )
                    VALUES (?, ?, ?)
                ");

                $ins_stmt->execute([
                    $contact_id,
                    $field_id,
                    $field_value
                ]);
            }
        }

        $success = "Contact updated successfully.";

        // REFRESH DATA
        header("Location: edit-contact.php?id=".$contact_id."&success=1");
        exit;

    } catch (Exception $e) {

        $error = $e->getMessage();
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Edit Contact</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

</head>

<body class="bg-gray-50">

<div class="flex min-h-screen overflow-hidden">

    <?php require_once 'components/sidebar.php'; ?>

    <div class="flex-1 flex flex-col overflow-y-auto">

        <?php require_once 'components/header.php'; ?>

        <main class="p-6 md:p-8 max-w-5xl mx-auto w-full">

            <div class="bg-white border border-gray-100 rounded-2xl shadow-sm p-6">

                <div class="flex items-center justify-between mb-6">

                    <div>

                        <h1 class="text-xl font-bold text-slate-900">
                            Edit Contact
                        </h1>

                        <p class="text-sm text-gray-400 mt-1">
                            Update contact information and custom fields.
                        </p>

                    </div>

                    <a href="all-contacts.php"
                       class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-xl text-sm font-semibold text-slate-700 transition-all">
                        Back
                    </a>

                </div>

                <?php if ($error): ?>

                    <div class="mb-4 bg-rose-50 border border-rose-100 text-rose-600 p-4 rounded-xl text-sm">
                        <?php echo htmlspecialchars($error); ?>
                    </div>

                <?php endif; ?>

                <?php if (isset($_GET['success'])): ?>

                    <div class="mb-4 bg-emerald-50 border border-emerald-100 text-emerald-600 p-4 rounded-xl text-sm">
                        Contact updated successfully.
                    </div>

                <?php endif; ?>

                <form method="POST" class="space-y-5">

                    <!-- EMAIL -->
                    <div>

                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                            Email Address
                        </label>

                        <input
                                type="email"
                                name="email"
                                required
                                value="<?php echo htmlspecialchars($contact['email']); ?>"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500"
                        >

                    </div>


                    <!-- CAMPAIGN -->
                    <div>

                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                            Campaign
                        </label>

                        <select
                                name="campaign_id"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500"
                        >

                            <?php foreach ($campaigns as $campaign): ?>

                                <option
                                        value="<?php echo $campaign['id']; ?>"
                                    <?php echo ($campaign['id'] == $contact['campaign_id']) ? 'selected' : ''; ?>
                                >
                                    <?php echo htmlspecialchars($campaign['campaign_name']); ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- STATUS -->
                    <div>

                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                            Sending Status
                        </label>

                        <select
                                name="sending_status"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500"
                        >

                            <option value="Active"
                                <?php echo ($contact['sending_status'] === 'Active') ? 'selected' : ''; ?>>
                                Active
                            </option>

                            <option value="Paused"
                                <?php echo ($contact['sending_status'] === 'Paused') ? 'selected' : ''; ?>>
                                Paused
                            </option>

                        </select>

                    </div>


                    <!-- CUSTOM FIELDS -->
                    <div class="border-t border-gray-100 pt-6">

                        <h2 class="text-sm font-bold text-slate-800 mb-4">
                            Custom Fields
                        </h2>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                            <?php foreach ($custom_fields as $field): ?>

                                <?php
                                $field_id = $field['id'];

                                $field_value = $existing_values[$field_id] ?? '';
                                ?>

                                <div>

                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                                        <?php echo htmlspecialchars($field['field_label']); ?>
                                    </label>

                                    <input
                                            type="text"
                                            name="custom_field[<?php echo $field_id; ?>]"
                                            value="<?php echo htmlspecialchars($field_value); ?>"
                                            class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-indigo-500"
                                    >

                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>


                    <!-- SUBMIT -->
                    <div class="pt-6 border-t border-gray-100 flex justify-end">

                        <button
                                type="submit"
                                class="px-6 py-3 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-sm font-semibold shadow-sm transition-all"
                        >
                            <i class="fa-solid fa-floppy-disk mr-2"></i>
                            Save Changes
                        </button>

                    </div>

                </form>

            </div>

        </main>

    </div>

</div>
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