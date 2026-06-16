<?php
require_once __DIR__ . '/../config.php';

// "Log in once, edit freely" session model
session_name('parking_settings');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$IDLE_LIMIT_SECONDS = 1800; // 30 min
$authed = false;
if (!empty($_SESSION['settings_auth']) && !empty($_SESSION['settings_auth_time'])) {
    if (time() - $_SESSION['settings_auth_time'] <= $IDLE_LIMIT_SECONDS) {
        $authed = true;
        $_SESSION['settings_auth_time'] = time();
    } else {
        $_SESSION = [];
        @session_destroy();
        @session_start();
        $_SESSION['flash_message'] = 'Session expired after 30 minutes idle. Log in again to edit.';
        $_SESSION['flash_type'] = 'info';
    }
}

// One-shot flash from a redirect
$message = null;
$messageType = null;
if (!empty($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Load settings
$settings = [];
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true) ?: [];
}

// Load credentials from .env
$authUser = null;
$authPass = null;
$emailFrom = null;
$emailTo = null;
$emailAppPassword = null;

if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    if (preg_match('/^SETTINGS_USER=(.+)$/m', $envContent, $m)) {
        $authUser = trim($m[1]);
    }
    if (preg_match('/^SETTINGS_PASS=(.+)$/m', $envContent, $m)) {
        $authPass = trim($m[1]);
    }
    if (preg_match('/^EMAIL_FROM="(.+?)"\r?$/m', $envContent, $m)) {
        $emailFrom = trim($m[1]);
    }
    if (preg_match('/^EMAIL_TO="(.+?)"\r?$/m', $envContent, $m)) {
        $emailTo = trim($m[1]);
    }
    if (preg_match('/^EMAIL_APP_PASSWORD="(.+?)"\r?$/m', $envContent, $m)) {
        $emailAppPassword = trim($m[1]);
    }
}

function sendSettingsEmail($to, $from, $password, $subject, $body) {
    // Use Gmail SMTP directly for reliable delivery
    $smtpServer = 'smtp.gmail.com';
    $smtpPort = 587;

    // Create email with headers
    $boundary = md5(time());
    $headers = "From: $from\r\n";
    $headers .= "Reply-To: $from\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Priority: 1\r\n";
    $headers .= "X-MSMail-Priority: High\r\n";
    $headers .= "Importance: High\r\n";

    $message = "Subject: $subject\r\n";
    $message .= $headers;
    $message .= "\r\n$body";

    // Connect to SMTP
    $socket = @fsockopen($smtpServer, $smtpPort, $errno, $errstr, 10);
    if (!$socket) return false;

    stream_set_timeout($socket, 10);

    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '220') { fclose($socket); return false; }

    // EHLO
    fputs($socket, "EHLO localhost\r\n");
    while ($line = fgets($socket, 512)) {
        if (substr($line, 3, 1) == ' ') break;
    }

    // STARTTLS
    fputs($socket, "STARTTLS\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '220') { fclose($socket); return false; }

    // Enable TLS
    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

    // EHLO again after TLS
    fputs($socket, "EHLO localhost\r\n");
    while ($line = fgets($socket, 512)) {
        if (substr($line, 3, 1) == ' ') break;
    }

    // AUTH LOGIN
    fputs($socket, "AUTH LOGIN\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '334') { fclose($socket); return false; }

    fputs($socket, base64_encode($from) . "\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '334') { fclose($socket); return false; }

    fputs($socket, base64_encode($password) . "\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '235') { fclose($socket); return false; }

    // MAIL FROM
    fputs($socket, "MAIL FROM:<$from>\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '250') { fclose($socket); return false; }

    // RCPT TO
    fputs($socket, "RCPT TO:<$to>\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '250') { fclose($socket); return false; }

    // DATA
    fputs($socket, "DATA\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '354') { fclose($socket); return false; }

    fputs($socket, $message . "\r\n.\r\n");
    $response = fgets($socket, 512);
    if (substr($response, 0, 3) != '250') { fclose($socket); return false; }

    // QUIT
    fputs($socket, "QUIT\r\n");
    fclose($socket);

    return true;
}

// Check if auth is configured
$authConfigured = $authUser && $authPass;

// Rate limiting for brute force protection
$rateLimitFile = '/tmp/parking_settings_ratelimit.json';
$maxAttempts = 5;
$lockoutMinutes = 15;

function getRateLimitData($file) {
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function saveRateLimitData($file, $data) {
    file_put_contents($file, json_encode($data));
}

function isIpBlocked($file, $ip, $maxAttempts, $lockoutMinutes) {
    $data = getRateLimitData($file);
    if (!isset($data[$ip])) return false;
    $record = $data[$ip];
    if ($record['attempts'] >= $maxAttempts) {
        $lockoutUntil = $record['last_attempt'] + ($lockoutMinutes * 60);
        if (time() < $lockoutUntil) {
            return ceil(($lockoutUntil - time()) / 60);
        }
        // Lockout expired, reset
        unset($data[$ip]);
        saveRateLimitData($file, $data);
    }
    return false;
}

function recordFailedAttempt($file, $ip) {
    $data = getRateLimitData($file);
    if (!isset($data[$ip])) {
        $data[$ip] = ['attempts' => 0, 'last_attempt' => 0];
    }
    $data[$ip]['attempts']++;
    $data[$ip]['last_attempt'] = time();
    saveRateLimitData($file, $data);
    return $data[$ip]['attempts'];
}

function clearFailedAttempts($file, $ip) {
    $data = getRateLimitData($file);
    if (isset($data[$ip])) {
        unset($data[$ip]);
        saveRateLimitData($file, $data);
    }
}

// Handle form submission
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Logout: clear session and redirect (PRG)
    if ($action === 'logout') {
        $_SESSION = [];
        @session_destroy();
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    // Login: rate-limited password verification, sets session, redirects (PRG)
    if ($action === 'login') {
        $blockedMinutes = isIpBlocked($rateLimitFile, $clientIp, $maxAttempts, $lockoutMinutes);
        if ($blockedMinutes) {
            $message = "Too many failed attempts. Try again in $blockedMinutes minute(s).";
            $messageType = 'error';
        } elseif (!$authConfigured) {
            // No SETTINGS_USER/PASS configured: log in unconditionally (initial setup)
            $_SESSION['settings_auth'] = true;
            $_SESSION['settings_auth_time'] = time();
            $_SESSION['flash_message'] = 'Edit mode enabled (no password configured).';
            $_SESSION['flash_type'] = 'info';
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        } elseif (isset($_POST['password']) && hash_equals($authPass, $_POST['password'])) {
            $_SESSION['settings_auth'] = true;
            $_SESSION['settings_auth_time'] = time();
            clearFailedAttempts($rateLimitFile, $clientIp);
            $_SESSION['flash_message'] = 'Logged in. Edit mode enabled for 30 minutes.';
            $_SESSION['flash_type'] = 'success';
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        } else {
            $attempts = recordFailedAttempt($rateLimitFile, $clientIp);
            $remaining = $maxAttempts - $attempts;
            if ($remaining > 0) {
                $message = "Incorrect password.";
            } else {
                $message = "Too many failed attempts. Locked out for $lockoutMinutes minutes.";
                if ($emailFrom && $emailTo) {
                    $userAgent = htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');
                    $referer = htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'Direct');
                    $emailSubject = "Security Alert: Settings Login Blocked";
                    $emailBody = "
                    <html>
                    <body style='font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px;'>
                        <div style='max-width: 500px; margin: 0 auto; background: white; border-radius: 8px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>
                            <h2 style='margin: 0 0 16px; color: #f44336;'>Security Alert</h2>
                            <p style='margin: 0 0 16px; color: #666;'>Someone has been <strong style='color: #f44336;'>blocked</strong> from the parking settings page after $maxAttempts failed login attempts.</p>
                            <table style='width: 100%; font-size: 12px; color: #666; border-collapse: collapse;'>
                                <tr><td style='padding: 4px 0; color: #999;'>IP Address:</td><td style='padding: 4px 0;'>$clientIp</td></tr>
                                <tr><td style='padding: 4px 0; color: #999;'>Time:</td><td style='padding: 4px 0;'>" . date('M j, Y g:i:s A T') . "</td></tr>
                                <tr><td style='padding: 4px 0; color: #999;'>Lockout:</td><td style='padding: 4px 0;'>$lockoutMinutes minutes</td></tr>
                                <tr><td style='padding: 4px 0; color: #999;'>Referrer:</td><td style='padding: 4px 0;'>$referer</td></tr>
                                <tr><td style='padding: 4px 0; color: #999; vertical-align: top;'>User Agent:</td><td style='padding: 4px 0; word-break: break-all;'>$userAgent</td></tr>
                            </table>
                        </div>
                    </body>
                    </html>";
                    sendSettingsEmail($emailTo, $emailFrom, $emailAppPassword, $emailSubject, $emailBody);
                }
            }
            $messageType = 'error';
            sleep(2);
        }
    } elseif (!$authed) {
        // Any other action requires an authenticated session
        $message = 'Please log in to make changes.';
        $messageType = 'error';
    } else {
        if ($_POST['action'] === 'save_notifications') {
            $settings['notifications'] = [
                'purchase_success' => isset($_POST['notify_purchase_success']),
                'purchase_failed' => isset($_POST['notify_purchase_failed']),
                'expiry_reminder' => isset($_POST['notify_expiry_reminder']),
                'security_alerts' => isset($_POST['notify_security_alerts'])
            ];

            if (file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT))) {
                $message = 'Email notification settings saved.';
                $messageType = 'success';
            } else {
                $message = 'Failed to save settings.';
                $messageType = 'error';
            }
        } elseif ($_POST['action'] === 'toggle_autobuyer') {
            $currentEnabled = $settings['autobuyer']['enabled'] ?? true;
            $settings['autobuyer'] = $settings['autobuyer'] ?? [];
            $settings['autobuyer']['enabled'] = !$currentEnabled;
            $newState = $settings['autobuyer']['enabled'];

            // Track an optional re-enable date when turning OFF; clear it when turning ON
            if (!$newState && !empty($_POST['disabled_until']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['disabled_until'])) {
                $settings['autobuyer']['disabled_until'] = $_POST['disabled_until'];
            } else {
                $settings['autobuyer']['disabled_until'] = null;
            }

            if (file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT))) {
                $message = 'Auto-buyer ' . ($newState ? 'enabled' : 'disabled') . '.';
                $messageType = 'success';

                // Send email notification
                if ($emailFrom && $emailTo) {
                    $stateText = $newState ? 'ENABLED' : 'DISABLED';
                    $stateColor = $newState ? '#4caf50' : '#f44336';
                    $emailSubject = "Parking Auto-buyer $stateText";
                    $emailBody = "
                    <html>
                    <body style='font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px;'>
                        <div style='max-width: 400px; margin: 0 auto; background: white; border-radius: 8px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);'>
                            <h2 style='margin: 0 0 16px; color: #333;'>Auto-buyer Setting Changed</h2>
                            <p style='margin: 0 0 16px; color: #666;'>The parking permit auto-buyer has been <strong style='color: $stateColor;'>$stateText</strong>.</p>" .
                            (($settings['autobuyer']['disabled_until'] ?? null) && !$newState
                                ? "<p style='margin: 0 0 16px; color: #666;'>It will automatically re-enable on <strong>" . date('M j, Y', strtotime($settings['autobuyer']['disabled_until'])) . "</strong>.</p>"
                                : "") .
                            "<p style='margin: 0; font-size: 12px; color: #999;'>Changed at: " . date('M j, Y g:i A') . "<br>From: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown') . "</p>
                        </div>
                    </body>
                    </html>";
                    sendSettingsEmail($emailTo, $emailFrom, $emailAppPassword, $emailSubject, $emailBody);
                }
            } else {
                $message = 'Failed to save settings.';
                $messageType = 'error';
            }
        } elseif ($_POST['action'] === 'save_default_vehicle') {
            $plate = trim($_POST['default_vehicle'] ?? '');
            $settings['autobuyer'] = $settings['autobuyer'] ?? [];
            $settings['autobuyer']['default_vehicle'] = $plate !== '' ? $plate : null;

            if (file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT))) {
                $message = 'Default vehicle saved.';
                $messageType = 'success';
            } else {
                $message = 'Failed to save settings.';
                $messageType = 'error';
            }
        }
    }
}

// Get current state
$autobuyerEnabled = $settings['autobuyer']['enabled'] ?? true;
$defaultVehiclePlate = $settings['autobuyer']['default_vehicle'] ?? null;

// Load cars for the default-vehicle dropdown
$cars = [];
if (file_exists($carsFile)) {
    $cars = json_decode(file_get_contents($carsFile), true) ?: [];
}

// Calculate expected price from permit history (most recent permit)
// $historyFile comes from config.php (auto-detects local vs prod path)
$permits = [];
if (file_exists($historyFile)) {
    $permits = json_decode(file_get_contents($historyFile), true) ?: [];
}

$latestPrice = null;
$previousPrice = null;
$priceChangeDate = null;
$priceChangeAmount = null;

if (count($permits) >= 1) {
    // Get the latest permit price
    $latestPermit = $permits[count($permits) - 1];
    $latestPrice = floatval(str_replace(['$', ','], '', $latestPermit['amountPaid'] ?? '0'));

    // Find when the price last changed by looking backwards
    for ($i = count($permits) - 2; $i >= 0; $i--) {
        $prevPermitPrice = floatval(str_replace(['$', ','], '', $permits[$i]['amountPaid'] ?? '0'));
        if ($prevPermitPrice != $latestPrice) {
            $previousPrice = $prevPermitPrice;
            // The price changed on the permit AFTER this one
            $changePermit = $permits[$i + 1];
            $priceChangeDate = $changePermit['validFrom'] ?? null;
            if ($priceChangeDate) {
                // Parse and format the date
                $priceChangeDate = preg_replace('/:\s*\d{1,2}:\d{2}$/', '', $priceChangeDate);
            }
            $priceChangeAmount = $latestPrice - $previousPrice;
            break;
        }
    }
}

// Get notification settings (default all to true)
$notifications = $settings['notifications'] ?? [];
$notifyPurchaseSuccess = $notifications['purchase_success'] ?? true;
$notifyPurchaseFailed = $notifications['purchase_failed'] ?? true;
$notifyExpiryReminder = $notifications['expiry_reminder'] ?? true;
$notifySecurityAlerts = $notifications['security_alerts'] ?? true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/svg+xml" href="<?= $urlBase ?>/static/favicon.svg">
    <title>Parking Settings</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #1a1f2e;
            color: #e2e8f0;
            min-height: 100vh;
            padding: 16px;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            padding-top: 20px;
        }
        .card {
            background: #2a3142;
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .title {
            font-size: 22px;
            font-weight: 700;
            color: #fff;
        }
        .setting-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #3a4255;
        }
        .setting-row:last-child {
            border-bottom: none;
        }
        .setting-info {
            flex: 1;
            margin-right: 20px;
        }
        .setting-label {
            font-size: 16px;
            font-weight: 500;
            color: #e2e8f0;
            margin-bottom: 4px;
        }
        .setting-desc {
            font-size: 13px;
            color: #8892a6;
        }
        .toggle-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .toggle-btn.enabled {
            background: #f44336;
            color: white;
        }
        .toggle-btn.enabled:hover {
            background: #e53935;
        }
        .toggle-btn.disabled {
            background: #4caf50;
            color: white;
        }
        .toggle-btn.disabled:hover {
            background: #43a047;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #3a4255;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #8892a6;
            font-size: 14px;
        }
        .info-value {
            color: #e2e8f0;
            font-weight: 500;
        }
        .price-up {
            color: #f44336;
        }
        .price-down {
            color: #4caf50;
        }
        .change-date {
            font-size: 11px;
            color: #8892a6;
            margin-right: 8px;
            font-weight: 400;
        }
        .message {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .message.success {
            background: #1b5e20;
            color: #a5d6a7;
            border-left: 4px solid #4caf50;
        }
        .message.error {
            background: #b71c1c;
            color: #ef9a9a;
            border-left: 4px solid #f44336;
        }
        .links {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 16px;
            padding-bottom: 20px;
        }
        .link {
            color: #64b5f6;
            text-decoration: none;
            font-size: 14px;
        }
        .link:hover {
            text-decoration: underline;
        }
        .project-links {
            display: flex;
            justify-content: center;
            gap: 16px;
            margin: 12px auto 0;
            font-size: 11px;
        }
        .project-links a {
            color: #5a6378;
            text-decoration: none;
        }
        .project-links a:hover {
            color: #8892a6;
            text-decoration: underline;
        }
        .warning {
            background: #1e2433;
            border-left: 4px solid #ff9800;
            padding: 12px 16px;
            margin-top: 16px;
            border-radius: 0 8px 8px 0;
            font-size: 13px;
            color: #ffb74d;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-badge.on {
            background: #1b5e20;
            color: #a5d6a7;
        }
        .status-badge.off {
            background: #b71c1c;
            color: #ef9a9a;
        }

        /* Modal styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal {
            background: #2a3142;
            border-radius: 16px;
            padding: 24px;
            width: 90%;
            max-width: 360px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        .modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #fff;
            margin-bottom: 8px;
        }
        .modal-desc {
            font-size: 14px;
            color: #8892a6;
            margin-bottom: 20px;
        }
        .password-wrapper {
            position: relative;
            margin-bottom: 16px;
        }
        .modal-date-wrapper {
            margin-bottom: 16px;
        }
        .modal-date-label {
            display: block;
            font-size: 13px;
            color: #8892a6;
            margin-bottom: 6px;
        }
        .modal-date-wrapper .modal-input {
            padding-right: 16px;
            color-scheme: dark;
        }
        .modal-date-hint {
            font-size: 11px;
            color: #5a6378;
            margin-top: 6px;
        }
        .vehicle-select {
            width: 100%;
            padding: 12px 16px;
            background: #1a1f2e;
            border: 1px solid #3a4255;
            border-radius: 8px;
            color: #e2e8f0;
            font-size: 14px;
            margin-bottom: 12px;
            color-scheme: dark;
        }
        /* Auth pill at top of page when logged in */
        .auth-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 14px;
            background: #1e2433;
            border: 1px solid #2a3142;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 12px;
        }
        .auth-status {
            color: #4caf50;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .auth-status::before {
            content: '';
            width: 6px; height: 6px;
            border-radius: 50%;
            background: #4caf50;
            box-shadow: 0 0 0 3px rgba(76, 175, 80, 0.2);
        }
        .logout-btn {
            background: transparent;
            border: 1px solid #3a4255;
            color: #8892a6;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
        }
        .logout-btn:hover {
            border-color: #f44336;
            color: #f44336;
        }
        /* Read-only snapshot card shown when not logged in */
        .snapshot-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #3a4255;
        }
        .snapshot-row:last-child { border-bottom: none; }
        .snapshot-label {
            font-size: 14px;
            color: #8892a6;
        }
        .snapshot-value {
            font-size: 14px;
            color: #e2e8f0;
            font-weight: 500;
            text-align: right;
        }
        .snapshot-value .small-meta {
            display: block;
            font-size: 11px;
            color: #5a6378;
            font-weight: 400;
            margin-top: 2px;
        }
        .login-btn {
            width: 100%;
            padding: 14px;
            margin-top: 8px;
            border: none;
            border-radius: 8px;
            background: #1976d2;
            color: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .login-btn:hover { background: #1565c0; }
        /* Confirm-only modal (used in edit mode where password is already established) */
        .modal-confirm-msg {
            font-size: 14px;
            color: #cbd5e1;
            margin-bottom: 18px;
            line-height: 1.5;
        }
        .vehicle-select:focus {
            outline: none;
            border-color: #64b5f6;
        }
        .modal-input {
            width: 100%;
            padding: 12px 16px;
            padding-right: 48px;
            border: 1px solid #3a4255;
            border-radius: 8px;
            background: #1a1f2e;
            color: #e2e8f0;
            font-size: 16px;
        }
        .modal-input:focus {
            outline: none;
            border-color: #64b5f6;
        }
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #8892a6;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .toggle-password:hover {
            color: #e2e8f0;
        }
        .toggle-password svg {
            width: 20px;
            height: 20px;
        }
        .modal-buttons {
            display: flex;
            gap: 12px;
        }
        .modal-btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .modal-btn.cancel {
            background: #3a4255;
            color: #e2e8f0;
        }
        .modal-btn.confirm.enable {
            background: #4caf50;
            color: white;
        }
        .modal-btn.confirm.disable {
            background: #f44336;
            color: white;
        }
        .modal-btn.confirm.save {
            background: #2196f3;
            color: white;
        }

        /* Checkbox styles */
        .checkbox-row {
            display: flex;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #3a4255;
        }
        .checkbox-row:last-child {
            border-bottom: none;
        }
        .checkbox-wrapper {
            position: relative;
            width: 20px;
            height: 20px;
            margin-right: 12px;
        }
        .checkbox-wrapper input {
            opacity: 0;
            width: 100%;
            height: 100%;
            position: absolute;
            cursor: pointer;
            z-index: 1;
        }
        .checkbox-wrapper .checkmark {
            position: absolute;
            top: 0;
            left: 0;
            width: 20px;
            height: 20px;
            background: #1a1f2e;
            border: 2px solid #3a4255;
            border-radius: 4px;
            transition: all 0.2s;
        }
        .checkbox-wrapper input:checked ~ .checkmark {
            background: #4caf50;
            border-color: #4caf50;
        }
        .checkbox-wrapper .checkmark:after {
            content: '';
            position: absolute;
            display: none;
            left: 6px;
            top: 2px;
            width: 5px;
            height: 10px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }
        .checkbox-wrapper input:checked ~ .checkmark:after {
            display: block;
        }
        .checkbox-label {
            flex: 1;
        }
        .checkbox-label-text {
            font-size: 14px;
            color: #e2e8f0;
        }
        .checkbox-label-desc {
            font-size: 12px;
            color: #8892a6;
            margin-top: 2px;
        }
        .save-btn {
            width: 100%;
            padding: 12px;
            margin-top: 16px;
            border: none;
            border-radius: 8px;
            background: #2196f3;
            color: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .save-btn:hover {
            background: #1976d2;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($authed): ?>
            <div class="auth-bar">
                <span class="auth-status">Authenticated</span>
                <form method="POST" style="margin: 0;">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="logout-btn">Logout</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <?php if (!$authed): ?>
            <!-- Read-only snapshot -->
            <?php
            $defaultVehicleName = null;
            foreach ($cars as $c) {
                if ($defaultVehiclePlate && strcasecmp($c['plate'] ?? '', $defaultVehiclePlate) === 0) {
                    $defaultVehicleName = $c['name'] ?? null;
                    break;
                }
            }
            $notifFlags = $settings['notifications'] ?? [];
            $notifEnabledCount = count(array_filter([
                $notifFlags['purchase_success'] ?? true,
                $notifFlags['purchase_failed'] ?? true,
                $notifFlags['expiry_reminder'] ?? true,
                $notifFlags['security_alerts'] ?? true,
            ]));
            $disabledUntilSnap = $settings['autobuyer']['disabled_until'] ?? null;
            $untilSnapLabel = ($disabledUntilSnap && preg_match('/^\d{4}-\d{2}-\d{2}$/', $disabledUntilSnap))
                ? date('M j, Y', strtotime($disabledUntilSnap)) : null;
            ?>
            <div class="card">
                <div class="header">
                    <span class="title">Settings</span>
                </div>
                <div class="snapshot-row">
                    <span class="snapshot-label">Auto-buyer</span>
                    <span class="snapshot-value">
                        <span class="status-badge <?= $autobuyerEnabled ? 'on' : 'off' ?>"><?= $autobuyerEnabled ? 'ON' : 'OFF' ?></span>
                        <?php if (!$autobuyerEnabled && $untilSnapLabel): ?>
                            <span class="small-meta">re-enables <?= htmlspecialchars($untilSnapLabel) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if (!empty($cars)): ?>
                <div class="snapshot-row">
                    <span class="snapshot-label">Default Vehicle</span>
                    <span class="snapshot-value">
                        <?= htmlspecialchars($defaultVehicleName ?: '—') ?>
                        <?php if ($defaultVehiclePlate): ?>
                            <span class="small-meta"><?= htmlspecialchars($defaultVehiclePlate) ?></span>
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
                <div class="snapshot-row">
                    <span class="snapshot-label">Email Notifications</span>
                    <span class="snapshot-value"><?= $notifEnabledCount ?> of 4 enabled</span>
                </div>
                <button type="button" class="login-btn" onclick="showLoginModal()">Log in to edit</button>
            </div>
        <?php else: ?>
        <div class="card">
            <div class="header">
                <span class="title">Settings</span>
                <span class="status-badge <?= $autobuyerEnabled ? 'on' : 'off' ?>">
                    Auto-buyer: <?= $autobuyerEnabled ? 'ON' : 'OFF' ?>
                </span>
            </div>

            <div class="setting-row">
                <div class="setting-info">
                    <div class="setting-label">Automatic Permit Buying</div>
                    <div class="setting-desc">When enabled, permits are purchased automatically when the current one expires.</div>
                </div>
                <button type="button" class="toggle-btn <?= $autobuyerEnabled ? 'enabled' : 'disabled' ?>" onclick="showModal()">
                    <?= $autobuyerEnabled ? 'Disable' : 'Enable' ?>
                </button>
            </div>

            <?php if (!$autobuyerEnabled): ?>
                <?php
                $disabledUntil = $settings['autobuyer']['disabled_until'] ?? null;
                $untilLabel = null;
                if ($disabledUntil && preg_match('/^\d{4}-\d{2}-\d{2}$/', $disabledUntil)) {
                    $untilLabel = date('M j, Y', strtotime($disabledUntil));
                }
                ?>
                <div class="warning">
                    <?php if ($untilLabel): ?>
                        Auto-buyer is disabled. It will automatically re-enable on <strong><?= htmlspecialchars($untilLabel) ?></strong>.
                    <?php else: ?>
                        Auto-buyer is disabled. Permits will NOT be purchased automatically until re-enabled.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($cars)): ?>
        <div class="card">
            <div class="header">
                <span class="title">Default Vehicle</span>
            </div>
            <select id="defaultVehicleSelect" class="vehicle-select">
                <?php foreach ($cars as $car): ?>
                    <option value="<?= htmlspecialchars($car['plate']) ?>" <?= ($defaultVehiclePlate && strcasecmp($car['plate'], $defaultVehiclePlate) === 0) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($car['name']) ?> (<?= htmlspecialchars($car['plate']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" id="vehicleSaveBtn" class="save-btn" onclick="showVehicleModal()" style="display: none;">Save Default Vehicle</button>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="header">
                <span class="title">Info</span>
            </div>

            <?php if ($latestPrice): ?>
            <div class="info-row">
                <span class="info-label">Current Weekly Price</span>
                <span class="info-value">$<?= number_format($latestPrice, 2) ?></span>
            </div>
            <?php if ($priceChangeAmount !== null): ?>
            <div class="info-row">
                <span class="info-label">Last Price Change</span>
                <span class="info-value">
                    <span class="change-date"><?= htmlspecialchars($priceChangeDate) ?></span>
                    <span class="<?= $priceChangeAmount > 0 ? 'price-up' : 'price-down' ?>"><?= $priceChangeAmount > 0 ? '+' : '' ?>$<?= number_format($priceChangeAmount, 2) ?></span>
                </span>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="header">
                <span class="title">Email Notifications</span>
            </div>

            <div class="checkbox-row">
                <label class="checkbox-wrapper">
                    <input type="checkbox" id="notify_purchase_success" <?= $notifyPurchaseSuccess ? 'checked' : '' ?>>
                    <span class="checkmark"></span>
                </label>
                <div class="checkbox-label">
                    <div class="checkbox-label-text">Purchase Success</div>
                    <div class="checkbox-label-desc">Email when a permit is purchased</div>
                </div>
            </div>

            <div class="checkbox-row">
                <label class="checkbox-wrapper">
                    <input type="checkbox" id="notify_purchase_failed" <?= $notifyPurchaseFailed ? 'checked' : '' ?>>
                    <span class="checkmark"></span>
                </label>
                <div class="checkbox-label">
                    <div class="checkbox-label-text">Purchase Failed</div>
                    <div class="checkbox-label-desc">Email when a purchase fails</div>
                </div>
            </div>

            <div class="checkbox-row">
                <label class="checkbox-wrapper">
                    <input type="checkbox" id="notify_expiry_reminder" <?= $notifyExpiryReminder ? 'checked' : '' ?>>
                    <span class="checkmark"></span>
                </label>
                <div class="checkbox-label">
                    <div class="checkbox-label-text">Expiry Reminder</div>
                    <div class="checkbox-label-desc">Email before permit expires</div>
                </div>
            </div>

            <div class="checkbox-row">
                <label class="checkbox-wrapper">
                    <input type="checkbox" id="notify_security_alerts" <?= $notifySecurityAlerts ? 'checked' : '' ?>>
                    <span class="checkmark"></span>
                </label>
                <div class="checkbox-label">
                    <div class="checkbox-label-text">Security Alerts</div>
                    <div class="checkbox-label-desc">Email for login attempts and blocks</div>
                </div>
            </div>

            <button type="button" id="notificationSaveBtn" class="save-btn" onclick="showNotificationModal()" style="display: none;">Save Notification Settings</button>
        </div>
        <?php endif; ?>

    </div>

    <!-- Login Modal (view-mode only) -->
    <div class="modal-overlay" id="loginModalOverlay">
        <div class="modal">
            <div class="modal-title">Log in to edit</div>
            <div class="modal-desc">Enter the settings password. You'll stay logged in for 30 minutes.</div>
            <form method="POST" id="loginForm">
                <input type="hidden" name="action" value="login">
                <div class="password-wrapper">
                    <input type="password" name="password" id="loginPasswordInput" class="modal-input" placeholder="Password" autocomplete="current-password" required autofocus>
                    <button type="button" class="toggle-password" onclick="toggleLoginPasswordVisibility()">
                        <svg id="loginEyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="modal-btn cancel" onclick="hideLoginModal()">Cancel</button>
                    <button type="submit" class="modal-btn confirm save">Log in</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Disable/Enable Auto-buyer Confirm Modal (authed only) -->
    <div class="modal-overlay" id="modalOverlay">
        <div class="modal">
            <div class="modal-title"><?= $autobuyerEnabled ? 'Disable' : 'Enable' ?> Auto-buyer</div>
            <form method="POST" id="toggleForm">
                <input type="hidden" name="action" value="toggle_autobuyer">
                <?php if ($autobuyerEnabled): ?>
                <div class="modal-confirm-msg">Disable the auto-buyer? No permits will be purchased until re-enabled.</div>
                <div class="modal-date-wrapper">
                    <label for="disabledUntilInput" class="modal-date-label">Auto-re-enable on (optional)</label>
                    <input type="date" name="disabled_until" id="disabledUntilInput" class="modal-input" min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                    <div class="modal-date-hint">Leave blank to disable indefinitely.</div>
                </div>
                <?php else: ?>
                <div class="modal-confirm-msg">Re-enable the auto-buyer? Permits will resume on the next scheduled run.</div>
                <?php endif; ?>
                <div class="modal-buttons">
                    <button type="button" class="modal-btn cancel" onclick="hideModal()">Cancel</button>
                    <button type="submit" class="modal-btn confirm <?= $autobuyerEnabled ? 'disable' : 'enable' ?>">
                        <?= $autobuyerEnabled ? 'Disable' : 'Enable' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Default Vehicle Confirm Modal (authed only) -->
    <div class="modal-overlay" id="vehicleModalOverlay">
        <div class="modal">
            <div class="modal-title">Save Default Vehicle</div>
            <form method="POST" id="vehicleForm">
                <input type="hidden" name="action" value="save_default_vehicle">
                <input type="hidden" name="default_vehicle" id="hidden_default_vehicle">
                <div class="modal-confirm-msg">Set this as the default vehicle for the next auto-buy?</div>
                <div class="modal-buttons">
                    <button type="button" class="modal-btn cancel" onclick="hideVehicleModal()">Cancel</button>
                    <button type="submit" class="modal-btn confirm save">Save</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Notification Settings Confirm Modal (authed only) -->
    <div class="modal-overlay" id="notificationModalOverlay">
        <div class="modal">
            <div class="modal-title">Save Notification Settings</div>
            <form method="POST" id="notificationForm">
                <input type="hidden" name="action" value="save_notifications">
                <input type="hidden" name="notify_purchase_success" id="hidden_purchase_success">
                <input type="hidden" name="notify_purchase_failed" id="hidden_purchase_failed">
                <input type="hidden" name="notify_expiry_reminder" id="hidden_expiry_reminder">
                <input type="hidden" name="notify_security_alerts" id="hidden_security_alerts">
                <div class="modal-confirm-msg">Save your email notification preferences?</div>
                <div class="modal-buttons">
                    <button type="button" class="modal-btn cancel" onclick="hideNotificationModal()">Cancel</button>
                    <button type="submit" class="modal-btn confirm save">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Login modal (the only password-bearing modal now)
        function showLoginModal() {
            document.getElementById('loginModalOverlay').classList.add('active');
            document.getElementById('loginPasswordInput').focus();
        }

        function hideLoginModal() {
            document.getElementById('loginModalOverlay').classList.remove('active');
            const input = document.getElementById('loginPasswordInput');
            input.type = 'password';
            input.value = '';
            updateLoginEyeIcon(false);
        }

        function toggleLoginPasswordVisibility() {
            const input = document.getElementById('loginPasswordInput');
            const isPassword = input.type === 'password';
            input.type = isPassword ? 'text' : 'password';
            updateLoginEyeIcon(isPassword);
        }

        function updateLoginEyeIcon(visible) {
            const icon = document.getElementById('loginEyeIcon');
            if (visible) {
                icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
            } else {
                icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            }
        }

        const _loginOverlay = document.getElementById('loginModalOverlay');
        if (_loginOverlay) {
            _loginOverlay.addEventListener('click', function(e) {
                if (e.target === this) hideLoginModal();
            });
        }

        // Disable/Enable auto-buyer confirmation modal (no password — authed session)
        function showModal() {
            document.getElementById('modalOverlay').classList.add('active');
        }

        function hideModal() {
            document.getElementById('modalOverlay').classList.remove('active');
        }

        // Stubs retained so any leftover handlers don't throw — no password fields anymore
        function togglePasswordVisibility() {}
        function updateEyeIcon(visible) {
            const icon = document.getElementById('eyeIcon');
            if (!icon) return;
            if (visible) {
                // Eye with slash (hidden)
                icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
            } else {
                // Normal eye (showing)
                icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            }
        }

        // Close modal on overlay click
        document.getElementById('modalOverlay').addEventListener('click', function(e) {
            if (e.target === this) hideModal();
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideModal();
                hideNotificationModal();
                hideVehicleModal();
                hideLoginModal();
            }
        });

        // Toggle Save button visibility based on whether the selection differs from the saved default
        (function () {
            const sel = document.getElementById('defaultVehicleSelect');
            const btn = document.getElementById('vehicleSaveBtn');
            if (!sel || !btn) return;
            const initial = sel.value;
            sel.addEventListener('change', () => {
                btn.style.display = (sel.value !== initial) ? '' : 'none';
            });
        })();

        // Same pattern for the Email Notifications save button: only show on actual change
        (function () {
            const btn = document.getElementById('notificationSaveBtn');
            if (!btn) return;
            const ids = ['notify_purchase_success', 'notify_purchase_failed', 'notify_expiry_reminder', 'notify_security_alerts'];
            const boxes = ids.map(id => document.getElementById(id)).filter(Boolean);
            const initial = boxes.map(b => b.checked);
            const check = () => {
                const dirty = boxes.some((b, i) => b.checked !== initial[i]);
                btn.style.display = dirty ? '' : 'none';
            };
            boxes.forEach(b => b.addEventListener('change', check));
        })();

        // Default vehicle modal functions
        function showVehicleModal() {
            document.getElementById('hidden_default_vehicle').value = document.getElementById('defaultVehicleSelect').value;
            document.getElementById('vehicleModalOverlay').classList.add('active');
        }

        function hideVehicleModal() {
            document.getElementById('vehicleModalOverlay').classList.remove('active');
        }

        document.getElementById('vehicleModalOverlay').addEventListener('click', function(e) {
            if (e.target === this) hideVehicleModal();
        });

        // Notification confirm modal — copies checkbox values into hidden inputs, then shows
        function showNotificationModal() {
            document.getElementById('hidden_purchase_success').value = document.getElementById('notify_purchase_success').checked ? '1' : '';
            document.getElementById('hidden_purchase_failed').value = document.getElementById('notify_purchase_failed').checked ? '1' : '';
            document.getElementById('hidden_expiry_reminder').value = document.getElementById('notify_expiry_reminder').checked ? '1' : '';
            document.getElementById('hidden_security_alerts').value = document.getElementById('notify_security_alerts').checked ? '1' : '';
            document.getElementById('notificationModalOverlay').classList.add('active');
        }

        function hideNotificationModal() {
            document.getElementById('notificationModalOverlay').classList.remove('active');
        }

        document.getElementById('notificationModalOverlay').addEventListener('click', function(e) {
            if (e.target === this) hideNotificationModal();
        });

        // Auto-dismiss message banner after 3 seconds
        const messageBanner = document.querySelector('.message');
        if (messageBanner) {
            setTimeout(() => {
                messageBanner.style.transition = 'opacity 0.3s, margin-top 0.3s';
                messageBanner.style.opacity = '0';
                messageBanner.style.marginTop = '-' + messageBanner.offsetHeight + 'px';
                setTimeout(() => messageBanner.remove(), 300);
            }, 3000);
        }

    </script>
    <div class="project-links">
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-buyer" target="_blank">Auto-buyer</a>
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-display" target="_blank">E-ink Display</a>
        <a href="https://github.com/VisTechProjectsOrg/parking-permit-android" target="_blank">Android App</a>
    </div>
    <?php include __DIR__ . '/../_partials/bottom_nav.php'; ?>
    <?php include __DIR__ . '/../_partials/version_banner.php'; ?>
    <?php include __DIR__ . '/../_partials/console_easter_egg.php'; ?>
</body>
</html>
