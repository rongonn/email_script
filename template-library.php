<?php
require_once 'config/database.php';
check_auth();

// 12 Professional Templates
$templates = [
    ['name' => 'Modern Welcome', 'html' => '<div style="font-family:sans-serif; padding:20px; text-align:center;"><h1 style="color:#4f46e5;">Welcome!</h1><p>We are glad to have you.</p></div>'],
    ['name' => 'Order Receipt', 'html' => '<div style="font-family:sans-serif; border:1px solid #eee; padding:20px;"><h2>Order #1234</h2><p>Thank you for your purchase!</p></div>'],
    ['name' => 'Newsletter', 'html' => '<div style="font-family:sans-serif; background:#f9fafb; padding:20px;"><h3>Weekly Highlights</h3><p>Stay updated with our latest news.</p></div>'],
    ['name' => 'Flash Sale', 'html' => '<div style="font-family:sans-serif; background:#000; color:#fff; padding:30px; text-align:center;"><h1>SALE!</h1><p>Use code SAVE20</p></div>'],
    ['name' => 'Abandoned Cart', 'html' => '<div style="font-family:sans-serif; padding:20px; border-left:4px solid #f59e0b;"><h2>Forgot something?</h2><p>Your items are waiting.</p></div>'],
    ['name' => 'Webinar Invite', 'html' => '<div style="font-family:sans-serif; background:#1e293b; color:#fff; padding:30px;"><h2>Live Webinar</h2><p>Join us this Friday.</p></div>'],
    ['name' => 'Feedback Request', 'html' => '<div style="font-family:sans-serif; padding:20px; text-align:center;"><h2>How was it?</h2><p>Rate your experience.</p></div>'],
    ['name' => 'Security Alert', 'html' => '<div style="font-family:sans-serif; border:2px solid #ef4444; padding:20px;"><h3>Security Warning</h3><p>New login detected.</p></div>'],
    ['name' => 'Anniversary', 'html' => '<div style="font-family:sans-serif; background:#fef3c7; padding:40px; text-align:center;"><h1>Happy 1 Year!</h1></div>'],
    ['name' => 'Re-engagement', 'html' => '<div style="font-family:sans-serif; padding:20px;"><h2>We miss you!</h2><p>Come back for a surprise.</p></div>'],
    ['name' => 'Event Reminder', 'html' => '<div style="font-family:sans-serif; padding:20px; background:#e0f2fe;"><h2>Event Starting Soon</h2></div>'],
    ['name' => 'CEO Note', 'html' => '<div style="font-family:Georgia,serif; padding:30px;"><i>"Thank you for being part of our journey."</i><br><strong>- The Team</strong></div>']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Template Library</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <div class="flex min-h-screen">
        <?php require_once 'components/sidebar.php'; ?>
        <div class="flex-1 flex flex-col">
            <?php require_once 'components/header.php'; ?>
            
            <main class="p-8 max-w-7xl mx-auto w-full">
                <h1 class="text-xl font-bold mb-6">Readymade Templates Library</h1>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <?php foreach ($templates as $t): ?>
                    <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                        <h3 class="font-bold text-sm mb-3"><?php echo $t['name']; ?></h3>
                        <div class="h-40 border rounded-lg mb-4 overflow-hidden bg-gray-50">
                            <iframe srcdoc="<?php echo htmlspecialchars($t['html']); ?>" class="w-full h-full border-none pointer-events-none"></iframe>
                        </div>
                        <button onclick='openModal(<?php echo json_encode($t); ?>)' class="w-full bg-indigo-600 text-white py-2 rounded-lg text-xs font-semibold hover:bg-indigo-700">Preview / Edit</button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </main>
        </div>
    </div>

    <div id="modal" class="fixed inset-0 bg-white hidden z-50 flex flex-col">
        <div class="border-b p-4 flex justify-between items-center bg-gray-50">
            <h2 id="modalName" class="text-lg font-bold text-slate-800"></h2>
            <div class="flex gap-2">
                <button onclick="setDevice('desktop')" class="px-4 py-2 bg-white border rounded-lg text-xs font-bold hover:bg-indigo-50"><i class="fa-solid fa-desktop mr-1"></i> Desktop</button>
                <button onclick="setDevice('mobile')" class="px-4 py-2 bg-white border rounded-lg text-xs font-bold hover:bg-indigo-50"><i class="fa-solid fa-mobile-screen mr-1"></i> Mobile</button>
                <button onclick="copyCode()" class="px-4 py-2 bg-emerald-600 text-white rounded-lg text-xs font-bold hover:bg-emerald-700">Copy HTML Code</button>
                <button onclick="document.getElementById('modal').classList.add('hidden')" class="px-4 py-2 bg-gray-200 rounded-lg text-xs font-bold hover:bg-gray-300">Close</button>
            </div>
        </div>
        
        <div class="flex-1 overflow-auto bg-gray-100 p-8 flex justify-center">
            <div id="designArea" contenteditable="true" class="bg-white shadow-xl transition-all duration-300 p-8 min-h-[500px]" style="width: 100%; max-width: 800px;"></div>
        </div>
    </div>

    <script>
        const designArea = document.getElementById('designArea');

        function openModal(t) {
            document.getElementById('modalName').innerText = t.name;
            designArea.innerHTML = t.html;
            setDevice('desktop'); // Default to desktop
            document.getElementById('modal').classList.remove('hidden');
        }

        function setDevice(type) {
            if (type === 'mobile') {
                designArea.style.maxWidth = '375px';
            } else {
                designArea.style.maxWidth = '800px';
            }
        }

        function copyCode() {
            const finalHtml = designArea.innerHTML;
            navigator.clipboard.writeText(finalHtml).then(() => {
                alert("HTML Code copied to clipboard!");
            });
        }
    </script>
</body>
</html>