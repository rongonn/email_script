<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Adjust paths based on your architecture setup
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php'; // Composer
} else {
    require __DIR__ . '/../libs/PHPMailer/src/Exception.php'; // Manual fallback
    require __DIR__ . '/../libs/PHPMailer/src/PHPMailer.php';
    require __DIR__ . '/../libs/PHPMailer/src/SMTP.php';
}

/**
 * Sends an authenticated email using SMTP bindings pulled dynamically from the database
 * 
 * @param string $to Recipient Email
 * @param string $subject Email Subject
 * @param string $body HTML or Text Content
 * @return bool True on success, throws exception on failure
 */
function send_authenticated_email($to, $subject, $body, $user_id = null) {
    // Reference your global PDO database connection instance
    global $pdo; 
    
    if (!isset($pdo)) {
        throw new Exception("Database connection layer (\$pdo) is missing or uninitialized inside mailer execution scope.");
    }

    // Resolve user_id: prefer explicit param (for cron), fallback to session (for web)
    if (!$user_id) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $user_id = $_SESSION['user_id'] ?? null;
    }

    if (!$user_id) {
        throw new Exception("Unauthorized mailer invocation: No active user session or user_id identified.");
    }

    // --- 1. DYNAMIC DATA RETRIEVAL ---
    try {
        // Query strictly matched to your database table schema layout
        $stmt = $pdo->prepare("SELECT smtp_host, smtp_port, smtp_username, smtp_password, smtp_encryption, from_email, from_name FROM smtp_settings WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $smtp = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$smtp) {
            throw new Exception("No active SMTP configuration parameters found for the current logged-in user profile.");
        }

        // Exact mapping from your form controller schema attributes
        $smtp_host = $smtp['smtp_host'];
        $smtp_port = intval($smtp['smtp_port']);
        $smtp_user = $smtp['smtp_username'];
        $smtp_pass = $smtp['smtp_password'];
        $smtp_secure = strtolower(trim($smtp['smtp_encryption']));
        $from_email = $smtp['from_email'];
        $from_name = $smtp['from_name'];

    } catch (PDOException $db_err) {
        throw new Exception("Database extraction routing fault encountered: " . $db_err->getMessage());
    }

    // --- 2. PHPMailer TRANSACTION LAYER ENGINE ---
    $mail = new PHPMailer(true);

    try {
        // --- LIVE DYNAMIC SERVER CONFIGURATION ---
        $mail->isSMTP();
        $mail->Host       = $smtp_host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_user;
        $mail->Password   = $smtp_pass;
        
        // Parse and translate your dynamic encryption configurations
        if ($smtp_secure === 'ssl' || $smtp_port === 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtp_secure === 'tls' || $smtp_port === 587) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false; // Handle 'none' method parameters explicitly
        }
        
        $mail->Port = $smtp_port;

        // --- DYNAMIC SENDER & RECIPIENT BINDINGS ---
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($to);
        $mail->addReplyTo($from_email, $from_name);

        // --- CONTENT FORMATTING ---
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->AltBody = strip_tags($body); 

        // Execute transmission
        $mail->send();
        return true;

    } catch (Exception $e) {
        // Log the failure internal engine context cleanly
        error_log("Mail Delivery Failure: {$mail->ErrorInfo}");
        throw new Exception("Mail configuration engine rejected the packet stream: " . $mail->ErrorInfo);
    }
}