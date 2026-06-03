<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// 1. Setup Environment
ini_set('display_errors', 1);
error_reporting(E_ALL);


require_once 'config/database.php';
require_once 'config/app.php';
require_once 'config/mailer.php';
require_once 'personalization.php'; // Ensure your getPersonalizedBody function is here

// ===============================
// Dynamic Base URL (Supports Subdirectories & CLI Fallback)
// ===============================

if (isset($_SERVER['HTTP_HOST'])) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $base_url = $protocol . $host . $dir . '/';
} else {
    // Fallback for Cron CLI runs
    $base_url = APP_URL;
}

try {
    // 1. Fetch pending emails from email_queue where scheduled_at is due
    $query = "SELECT eq.id AS queue_id, eq.template_id, eq.contact_id, eq.user_id,
                     et.subject, et.message_body, et.is_html, et.track_open, et.track_click,
                     c.email AS contact_email
              FROM email_queue eq
              JOIN email_templates et ON eq.template_id = et.id
              JOIN contacts c ON eq.contact_id = c.id
              WHERE eq.scheduled_at <= NOW()
              LIMIT 100";

    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($queue as $job) {

        // 1. Try to log the attempt (INSERT IGNORE prevents duplicate errors)
        $log_stmt = $pdo->prepare("INSERT IGNORE INTO email_logs (user_id, template_id, contact_id, sent_at, is_opened) VALUES (?, ?, ?, NOW(), 0)");
        $log_stmt->execute([$job['user_id'], $job['template_id'], $job['contact_id']]);
        $log_id = $pdo->lastInsertId();

        // If log_id = 0, this was already sent before — just remove from queue and skip
        if (!$log_id) {
            $pdo->prepare("DELETE FROM email_queue WHERE id = ?")->execute([$job['queue_id']]);
            continue;
        }

        $processed_subject = getPersonalizedBody($pdo, $job['subject'], $job['contact_id']);
        $processed_body = getPersonalizedBody($pdo, $job['message_body'], $job['contact_id']);

        if ($job['track_click'] == 1) {
            
            $domain_fallback = str_replace(['http://', 'https://'], '', $base_url);
            $clean_base_url = preg_quote(rtrim($domain_fallback, '/'), '/');

            if ($job['is_html'] == 1) {
                $processed_body = preg_replace_callback(
                    '/href=["\'](https?:\/\/(?!' . $clean_base_url . ')[^"\']+)["\']/i',
                    function ($matches) use ($log_id, $base_url) {
                        return 'href="' . $base_url . 'track_click.php?log_id=' . $log_id . '&url=' . urlencode($matches[1]) . '"';
                    },
                    $processed_body
                );
            } else {
                $processed_body = preg_replace_callback(
                    '/(?<!["\'\(\[])https?:\/\/(?!' . $clean_base_url . ')[^\s\r\n]+/i',
                    function ($matches) use ($log_id, $base_url) {
                        return $base_url . 'track_click.php?log_id=' . $log_id . '&url=' . urlencode($matches[0]);
                    },
                    $processed_body
                );
            }
        }
        if ($job['track_open'] == 1 && $job['is_html'] == 1) {
            $pixel_tag = '<img src="' . $base_url . 'track_open.php?log_id=' . $log_id . '" width="1" height="1" style="display:none !important;" alt="">';

            if (strpos($processed_body, '</body>') !== false) {
                $processed_body = str_replace('</body>', $pixel_tag . '</body>', $processed_body);
            } else {
                $processed_body .= $pixel_tag;
            }
        }

        // 5. Send email
        try {
            send_authenticated_email(
                $job['contact_email'],
                $processed_subject,
                $processed_body,
                $job['user_id']
            );

            // Success: remove from queue
            $pdo->prepare("DELETE FROM email_queue WHERE id = ?")->execute([$job['queue_id']]);
        } catch (Exception $e) {
            // Mail failed: roll back the log so it retries next cron run
            $pdo->prepare("DELETE FROM email_logs WHERE id = ?")->execute([$log_id]);
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
