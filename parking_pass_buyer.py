import os
import sys
import json
import argparse
import time
import glob
import socket
from datetime import datetime, timedelta
from pathlib import Path

# Auto-install missing dependencies
try:
    import selenium
    import webdriver_manager
    import pdfplumber
    import PyPDF2
    from dotenv import load_dotenv
except ImportError:
    import subprocess
    subprocess.check_call([sys.executable, "-m", "pip", "install", "-r", "requirements.txt"])
    import selenium
    import webdriver_manager
    import pdfplumber
    import PyPDF2
    from dotenv import load_dotenv

# Try to import curl_cffi for fast API-based refetch
try:
    from curl_cffi import requests as curl_requests
    CURL_CFFI_AVAILABLE = True
except ImportError:
    curl_requests = None  # type: ignore
    CURL_CFFI_AVAILABLE = False

load_dotenv('.env')  # Load environment variables from .env file

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait, Select
from selenium.webdriver.support import expected_conditions as EC
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from webdriver_manager.chrome import ChromeDriverManager

# ====== Terminal Color Class ======
class bcolors:
    HEADER = '\033[95m'
    OKBLUE = '\033[94m'
    OKCYAN = '\033[96m'
    OKGREEN = '\033[92m'
    WARNING = '\033[93m'
    FAIL = '\033[91m'
    ENDC = '\033[0m'
    BOLD = '\033[1m'
    UNDERLINE = '\033[4m'
    
# Suppress TensorFlow logs and ChromeDriver logs
os.environ['TF_CPP_MIN_LOG_LEVEL'] = '3'

# ====== Chrome Setup ======
# Note: Headless mode is configured later after parsing args
chrome_options = Options()
chrome_options.add_argument("--start-maximized")
chrome_options.add_argument("--disable-infobars")
chrome_options.add_argument("--disable-extensions")
chrome_options.add_argument("--log-level=3")
chrome_options.add_experimental_option("excludeSwitches", ["enable-logging"])

# Set download directory to script folder
download_dir = str(Path(__file__).parent.absolute())
chrome_options.add_experimental_option("prefs", {
    "download.default_directory": download_dir,
    "download.prompt_for_download": False,
    "download.directory_upgrade": True,
    "safebrowsing.enabled": True,
    "plugins.always_open_pdf_externally": True  # Auto-download PDFs instead of opening in browser
})

# ====== Environment Variables ======
github_token = os.getenv("GITHUB_TOKEN")

# Email settings (optional)
email_from = os.getenv("EMAIL_FROM")
email_to = os.getenv("EMAIL_TO")
email_app_password = os.getenv("EMAIL_APP_PASSWORD")
email_enabled = all([email_from, email_to, email_app_password])

missing = []

if not github_token:
    missing.append("GITHUB_TOKEN")

if missing:
    print(bcolors.FAIL + f"\nERROR: Missing required environment variables: {', '.join(missing)}" + bcolors.ENDC)
    print(bcolors.FAIL + "Please set them and run again." + bcolors.ENDC)
    sys.exit(1)

# ====== Load Settings ======
def load_settings():
    """Load settings from config/settings.json with defaults."""
    settings_path = Path(__file__).parent / 'config' / 'settings.json'
    defaults = {
        "github": {
            "display_repo_path": "../parking_pass_display",
            "permit_branch": "permit"
        },
        "notifications": {
            "purchase_success": True,
            "purchase_failed": True,
            "expiry_reminder": True,
            "security_alerts": True
        }
    }

    if settings_path.exists():
        try:
            with open(settings_path, 'r') as f:
                user_settings = json.load(f)
            # Merge with defaults (deep merge for nested dicts)
            for key in defaults:
                if key in user_settings:
                    if isinstance(defaults[key], dict):
                        defaults[key].update(user_settings[key])
                    else:
                        defaults[key] = user_settings[key]
            # Also copy any keys not in defaults
            for key in user_settings:
                if key not in defaults:
                    defaults[key] = user_settings[key]
            return defaults
        except Exception as e:
            print(bcolors.WARNING + f"Warning: Could not load settings.json: {e}. Using defaults." + bcolors.ENDC)
    return defaults

def save_settings():
    """Persist the current in-memory settings dict back to config/settings.json."""
    settings_path = Path(__file__).parent / 'config' / 'settings.json'
    try:
        with open(settings_path, 'w') as f:
            json.dump(settings, f, indent=4)
        return True
    except Exception as e:
        print(bcolors.WARNING + f"Warning: Could not save settings.json: {e}" + bcolors.ENDC)
        return False

settings = load_settings()

def resolve_default_vehicle_index():
    """Look up the default vehicle plate from settings and return its index in info_cars.json.
    Falls back to index 0 if no default is set or the plate isn't found."""
    cars_path = Path(__file__).parent / 'config' / 'info_cars.json'
    try:
        with open(cars_path) as f:
            cars = json.load(f)
    except Exception:
        return 0
    default_plate = (settings.get('autobuyer') or {}).get('default_vehicle')
    if default_plate:
        for i, car in enumerate(cars):
            if car.get('plate', '').strip().upper() == default_plate.strip().upper():
                return i
    return 0


def check_autobuyer_reenable():
    """If autobuyer is disabled with a re-enable date that's passed, flip it back on."""
    ab = settings.get('autobuyer') or {}
    disabled_until = ab.get('disabled_until')
    if ab.get('enabled', True) or not disabled_until:
        return
    try:
        until_date = datetime.fromisoformat(disabled_until).date()
    except (ValueError, TypeError):
        return
    if datetime.now().date() >= until_date:
        settings['autobuyer']['enabled'] = True
        settings['autobuyer']['disabled_until'] = None
        save_settings()
        print(bcolors.OKCYAN + f"Auto-re-enabled autobuyer (disabled_until {disabled_until} has passed)" + bcolors.ENDC)

check_autobuyer_reenable()

def is_notification_enabled(notification_type):
    """Check if a notification type is enabled in settings."""
    return settings.get('notifications', {}).get(notification_type, True)

# ====== Logging Setup ======
SCRIPT_DIR = Path(__file__).parent.resolve()
LOGS_DIR = SCRIPT_DIR / 'logs'
LOGS_DIR.mkdir(exist_ok=True)

# Create a new log file for this run
RUN_TIMESTAMP = datetime.now().strftime("%Y%m%d_%H%M%S")
CURRENT_LOG_FILE = LOGS_DIR / f"run_{RUN_TIMESTAMP}.log"

def cleanup_old_logs(max_age_days=90):
    """Delete log files older than max_age_days."""
    cutoff = datetime.now() - timedelta(days=max_age_days)
    deleted_count = 0

    for log_file in LOGS_DIR.glob("run_*.log"):
        try:
            # Parse timestamp from filename: run_YYYYMMDD_HHMMSS.log
            file_date_str = log_file.stem.replace("run_", "")[:8]  # Get YYYYMMDD
            file_date = datetime.strptime(file_date_str, "%Y%m%d")

            if file_date < cutoff:
                log_file.unlink()
                deleted_count += 1
        except (ValueError, OSError):
            continue  # Skip files that don't match pattern or can't be deleted

    return deleted_count

def log_event(message, level="INFO"):
    """Log events to current run's log file with timestamp."""
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    log_line = f"[{timestamp}] [{level}] {message}\n"

    with open(CURRENT_LOG_FILE, 'a', encoding='utf-8') as f:
        f.write(log_line)

    # Also print to console for immediate feedback
    if level == "ERROR":
        print(bcolors.FAIL + log_line.strip() + bcolors.ENDC)
    elif level == "SUCCESS":
        print(bcolors.OKGREEN + log_line.strip() + bcolors.ENDC)
    else:
        print(bcolors.OKCYAN + log_line.strip() + bcolors.ENDC)

def take_error_screenshot(driver, error_name="error"):
    """Take a screenshot when an error occurs for debugging."""
    try:
        screenshot_dir = Path('error_screenshots')
        screenshot_dir.mkdir(exist_ok=True)

        timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
        screenshot_path = screenshot_dir / f"{error_name}_{timestamp}.png"

        driver.save_screenshot(str(screenshot_path))
        log_event(f"Error screenshot saved: {screenshot_path}", "ERROR")
        return screenshot_path
    except Exception as e:
        log_event(f"Failed to take screenshot: {e}", "ERROR")
        return None

# ====== Email Notification ======
def send_email_notification(subject, body, is_error=False, html_body=None, screenshot_path=None):
    """Send email notification via Gmail SMTP. Attaches log file and screenshot for errors."""
    if not email_enabled or not email_from or not email_to or not email_app_password:
        return False

    import smtplib
    from email.mime.text import MIMEText
    from email.mime.multipart import MIMEMultipart
    from email.mime.base import MIMEBase
    from email import encoders

    try:
        msg = MIMEMultipart('alternative')
        msg['From'] = email_from
        msg['To'] = email_to
        msg['Subject'] = f"{'[ERROR] ' if is_error else ''}{subject}"
        msg['X-Priority'] = '1'  # High priority
        msg['X-MSMail-Priority'] = 'High'
        msg['Importance'] = 'High'

        # Plain text version (fallback)
        msg.attach(MIMEText(body, 'plain'))

        # HTML version (if provided)
        if html_body:
            msg.attach(MIMEText(html_body, 'html'))

        # Attach current run's log file for error emails
        if is_error and CURRENT_LOG_FILE.exists():
            with open(CURRENT_LOG_FILE, 'rb') as f:
                part = MIMEBase('application', 'octet-stream')
                part.set_payload(f.read())
            encoders.encode_base64(part)
            part.add_header('Content-Disposition', f'attachment; filename="{CURRENT_LOG_FILE.name}"')
            msg.attach(part)

        # Attach screenshot if provided
        if screenshot_path and Path(screenshot_path).exists():
            with open(screenshot_path, 'rb') as f:
                part = MIMEBase('image', 'png')
                part.set_payload(f.read())
            encoders.encode_base64(part)
            part.add_header('Content-Disposition', f'attachment; filename="{Path(screenshot_path).name}"')
            msg.attach(part)

        with smtplib.SMTP_SSL('smtp.gmail.com', 465) as server:
            server.login(email_from, email_app_password)
            server.send_message(msg)

        log_event(f"Email notification sent: {subject}", "SUCCESS")
        return True
    except Exception as e:
        log_event(f"Failed to send email: {e}", "ERROR")
        return False


def check_price_change(amount_paid):
    """Check if the permit price has changed from expected. Returns (changed, expected, actual) or (False, None, None)."""
    if not amount_paid:
        return False, None, None

    expected_price = settings.get("pricing", {}).get("expected_weekly_price")
    if expected_price is None:
        return False, None, None

    # Parse the actual price (remove $ and convert to float)
    try:
        actual_price = float(amount_paid.replace("$", "").replace(",", ""))
    except (ValueError, AttributeError):
        return False, None, None

    # Check if price changed (with small tolerance for floating point)
    if abs(actual_price - expected_price) > 0.01:
        return True, expected_price, actual_price

    return False, expected_price, actual_price


def check_and_send_expiry_reminder():
    """Check if current permit is expiring soon and send reminder email if enabled."""
    if not is_notification_enabled('expiry_reminder'):
        return

    # Read permit.json to get current permit info
    permit_path = Path(__file__).parent / 'permit.json'
    if not permit_path.exists():
        return

    try:
        with open(permit_path, 'r') as f:
            permit_data = json.load(f)
    except Exception:
        return

    valid_to = permit_data.get('validTo', '')
    if not valid_to:
        return

    # Parse expiry date (format: "Jan 5, 2026")
    try:
        expiry_date = datetime.strptime(valid_to, "%b %d, %Y")
    except ValueError:
        try:
            # Try alternate format "Jan 5, 2026"
            expiry_date = datetime.strptime(valid_to, "%b %d, %Y")
        except ValueError:
            return

    # Calculate days until expiry
    now = datetime.now()
    days_remaining = (expiry_date - now).days

    # Only send reminders for 0, 1, or 2 days remaining
    if days_remaining < 0 or days_remaining > 2:
        return

    # Check if we already sent a reminder today (prevent duplicate emails)
    reminder_file = Path(__file__).parent / '.last_expiry_reminder'
    today_str = now.strftime("%Y-%m-%d")
    if reminder_file.exists():
        last_reminder = reminder_file.read_text().strip()
        if last_reminder == f"{today_str}:{days_remaining}":
            return  # Already sent reminder for this day count today

    # Build reminder email
    autobuyer_enabled = settings.get('autobuyer', {}).get('enabled', True)
    permit_number = permit_data.get('permitNumber', 'Unknown')
    plate_number = permit_data.get('plateNumber', 'Unknown')

    if days_remaining == 0:
        subject = "Parking Permit Expires TODAY"
        message = f"Your parking permit expires today ({valid_to})."
        if autobuyer_enabled:
            message += " Auto-renewal will run at 4 PM."
        else:
            message += " Auto-buyer is DISABLED - renew manually!"
    elif days_remaining == 1:
        subject = "Parking Permit Expires TOMORROW"
        message = f"Your parking permit expires tomorrow ({valid_to})."
        if autobuyer_enabled:
            message += " Auto-renewal will run tomorrow at 4 PM."
        else:
            message += " Auto-buyer is DISABLED - remember to renew!"
    else:  # 2 days
        subject = "Parking Permit Expires in 2 Days"
        message = f"Your parking permit expires in 2 days ({valid_to})."
        if autobuyer_enabled:
            message += " Auto-renewal scheduled for expiry day at 4 PM."
        else:
            message += " Auto-buyer is DISABLED."

    # Build HTML email
    autobuyer_badge = f'''<span style="background-color: {'#e8f5e9' if autobuyer_enabled else '#ffebee'}; color: {'#2e7d32' if autobuyer_enabled else '#c62828'}; padding: 4px 8px; border-radius: 4px; font-size: 12px;">Auto-buyer: {'ON' if autobuyer_enabled else 'OFF'}</span>'''

    html_body = f'''<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f5f5f5; margin: 0; padding: 20px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 500px; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="padding: 24px;">
                            <h2 style="margin: 0 0 16px; color: #f57c00;">{subject}</h2>
                            <p style="margin: 0 0 16px; color: #666;">{message}</p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #fafafa; border-radius: 6px;">
                                <tr>
                                    <td style="padding: 16px;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td style="padding: 8px 0; color: #999; font-size: 12px;">Permit Number</td>
                                                <td style="padding: 8px 0; text-align: right; font-weight: bold;">{permit_number}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #999; font-size: 12px;">Plate</td>
                                                <td style="padding: 8px 0; text-align: right; font-weight: bold;">{plate_number}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 8px 0; color: #999; font-size: 12px;">Expires</td>
                                                <td style="padding: 8px 0; text-align: right; font-weight: bold; color: #f57c00;">{valid_to}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin: 16px 0 0; text-align: center;">{autobuyer_badge}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>'''

    # Send the email
    if send_email_notification(subject, message, html_body=html_body):
        # Record that we sent this reminder
        reminder_file.write_text(f"{today_str}:{days_remaining}")
        log_event(f"Sent expiry reminder email: {days_remaining} days remaining", "SUCCESS")


def check_and_send_vehicle_switch_reminder():
    """If the next auto-buy will use a different vehicle than the current permit,
    send a heads-up email with magic-link buttons to switch which car gets the next permit.
    Idempotent per permit_number (won't re-send for the same active permit)."""
    if not is_notification_enabled('expiry_reminder'):
        return

    permit_path = Path(__file__).parent / 'permit.json'
    if not permit_path.exists():
        return
    try:
        with open(permit_path, 'r') as f:
            permit_data = json.load(f)
    except Exception:
        return

    current_plate = (permit_data.get('plateNumber') or '').strip()
    permit_number = (permit_data.get('permitNumber') or '').strip()
    valid_to = permit_data.get('validTo') or ''
    if not (current_plate and permit_number and valid_to):
        return

    default_plate = ((settings.get('autobuyer') or {}).get('default_vehicle') or '').strip()
    if not default_plate or default_plate.upper() == current_plate.upper():
        return  # no surprise switch coming

    # Load cars list to look up names
    cars_path = Path(__file__).parent / 'config' / 'info_cars.json'
    try:
        with open(cars_path) as f:
            cars = json.load(f)
    except Exception:
        return

    # Parse expiry. validTo formats seen: "Jun 16, 2026 at 11:59 PM" or "Jun 16, 2026: 23:59"
    expiry_date = None
    for fmt in ("%b %d, %Y at %I:%M %p", "%b %d, %Y"):
        try:
            expiry_date = datetime.strptime(valid_to.split(':')[0].strip() if ':' in valid_to and 'at' not in valid_to else valid_to, fmt)
            break
        except ValueError:
            continue
    if not expiry_date:
        return

    days_remaining = (expiry_date - datetime.now()).days
    if days_remaining < 0 or days_remaining > 3:
        return

    # Dedupe: one email per permit_number
    sent_file = Path(__file__).parent / '.vehicle_switch_reminder_sent'
    if sent_file.exists() and sent_file.read_text().strip() == permit_number:
        return

    def name_for(plate):
        for c in cars:
            if (c.get('plate') or '').strip().upper() == plate.strip().upper():
                return c.get('name') or plate
        return plate

    current_name = name_for(current_plate)
    default_name = name_for(default_plate)

    # HMAC-signed switch links. Secret = SETTINGS_PASS from env so PHP can verify.
    secret = (os.getenv('SETTINGS_PASS') or 'unset-secret').encode()

    def make_token(plate):
        import hmac, hashlib
        msg = f"switch:{permit_number}:{plate.strip().upper()}".encode()
        return hmac.new(secret, msg, hashlib.sha256).hexdigest()[:16]

    base_url = "https://fucktorontoparking.ca/api/switch_vehicle.php"

    # Build the buttons (default first as "Confirm", others as "Switch to")
    buttons_html = ""
    ordered = sorted(cars, key=lambda c: 0 if (c.get('plate') or '').strip().upper() == default_plate.upper() else 1)
    for c in ordered:
        plate = (c.get('plate') or '').strip()
        if not plate:
            continue
        name = c.get('name') or plate
        is_default = plate.upper() == default_plate.upper()
        token = make_token(plate)
        url = f"{base_url}?plate={plate}&token={token}"
        if is_default:
            label = f"Confirm {name} ({plate})"
            bg = "#2e7d32"
        else:
            label = f"Switch to {name} ({plate})"
            bg = "#1976d2"
        buttons_html += f'''
                            <tr>
                                <td style="padding: 6px 0;">
                                    <a href="{url}" style="display: block; padding: 12px 16px; background-color: {bg}; color: #ffffff; text-decoration: none; border-radius: 6px; text-align: center; font-weight: 600; font-size: 14px;">{label}</a>
                                </td>
                            </tr>'''

    subject = f"Auto-buyer will switch to {default_name}"
    message = (f"Your current {current_name} permit ({permit_number}) expires {valid_to}. "
               f"The auto-buyer is set to buy for {default_name} ({default_plate}) next. "
               f"To switch, click one of the buttons in this email.")

    html_body = f'''<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background-color: #f5f5f5; margin: 0; padding: 20px;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 520px; background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="padding: 24px;">
                            <h2 style="margin: 0 0 12px; color: #1976d2;">Auto-buyer will switch vehicles</h2>
                            <p style="margin: 0 0 16px; color: #555; font-size: 14px;">
                                Your current <strong>{current_name}</strong> permit ({permit_number}) expires <strong>{valid_to}</strong>.
                            </p>
                            <p style="margin: 0 0 16px; color: #555; font-size: 14px;">
                                The auto-buyer is currently set to buy for <strong>{default_name} ({default_plate})</strong> next.
                                Tap below to confirm or switch:
                            </p>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
{buttons_html}
                            </table>
                            <p style="margin: 16px 0 0; color: #999; font-size: 11px; text-align: center;">
                                Each link is single-use for this permit. After the next auto-buy, the links expire.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>'''

    if send_email_notification(subject, message, html_body=html_body):
        sent_file.write_text(permit_number)
        log_event(f"Sent vehicle switch reminder: current {current_name} -> default {default_name}", "SUCCESS")


def build_success_email_html(vehicle_name, vehicle_plate, permit_data, github_success, price_change_info=None):
    """Build a mobile-friendly HTML email for successful permit purchase (Outlook compatible)."""
    github_badge_bg = '#e8f5e9' if github_success else '#fff3e0'
    github_badge_color = '#2e7d32' if github_success else '#e65100'

    # Build price change warning HTML if applicable
    price_warning_html = ""
    if price_change_info and price_change_info[0]:  # (changed, expected, actual)
        _, expected, actual = price_change_info
        diff = actual - expected
        direction = "increased" if diff > 0 else "decreased"
        price_warning_html = f'''
                            <!-- Price Change Warning -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top: 20px;">
                                <tr>
                                    <td style="background-color: #fff3e0; padding: 15px; font-size: 14px; color: #e65100; border-left: 4px solid #ff9800;">
                                        <strong>Price Change Detected!</strong><br>
                                        Weekly permit price has {direction} from ${expected:.2f} to ${actual:.2f} ({("+" if diff > 0 else "-")}${abs(diff):.2f})
                                    </td>
                                </tr>
                            </table>'''

    return f'''<!DOCTYPE html>
<html xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <!--[if mso]>
    <style type="text/css">
        table {{border-collapse: collapse;}}
        td {{padding: 0;}}
        .dark-mode-bg {{ background-color: #ffffff !important; }}
    </style>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        :root {{ color-scheme: light; supported-color-schemes: light; }}
        body, .body {{ background-color: #f5f5f5 !important; }}
    </style>
</head>
<body class="body" style="margin: 0; padding: 0; background-color: #f5f5f5; font-family: Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f5f5f5;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="background-color: #ffffff; max-width: 600px;">
                    <!-- Header -->
                    <tr>
                        <td align="center" style="background-color: #2e7d32; padding: 30px 20px;">
                            <h1 style="margin: 0; font-size: 24px; font-weight: 600; color: #ffffff;">Permit Purchased!</h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 30px 20px;">
                            <!-- Permit Card -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f8f9fa; border-left: 4px solid #4caf50;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <span style="font-size: 12px; color: #666666; text-transform: uppercase; letter-spacing: 1px;">Permit Number</span><br>
                                        <span style="font-size: 28px; font-weight: 700; color: #1a1a1a;">{permit_data['permit_number']}</span>
                                    </td>
                                </tr>
                            </table>
                            <!-- Info Table -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top: 20px;">
                                <tr>
                                    <td style="padding: 12px 0; color: #666666; font-size: 14px; border-bottom: 1px solid #eeeeee; width: 40%;">Vehicle</td>
                                    <td style="padding: 12px 0; color: #1a1a1a; font-weight: 500; font-size: 14px; border-bottom: 1px solid #eeeeee;">{vehicle_name}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 0; color: #666666; font-size: 14px; border-bottom: 1px solid #eeeeee;">Plate</td>
                                    <td style="padding: 12px 0; color: #1a1a1a; font-weight: 500; font-size: 14px; border-bottom: 1px solid #eeeeee;">{vehicle_plate}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 0; color: #666666; font-size: 14px; border-bottom: 1px solid #eeeeee;">Valid From</td>
                                    <td style="padding: 12px 0; color: #1a1a1a; font-weight: 500; font-size: 14px; border-bottom: 1px solid #eeeeee;">{permit_data['valid_from']}</td>
                                </tr>
                                <tr>
                                    <td style="padding: 12px 0; color: #666666; font-size: 14px; border-bottom: 1px solid #eeeeee;">Valid To</td>
                                    <td style="padding: 12px 0; color: #1a1a1a; font-weight: 500; font-size: 14px; border-bottom: 1px solid #eeeeee;">{permit_data['valid_to']}</td>
                                </tr>
                            </table>
                            <!-- Status Badge -->
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-top: 20px;">
                                <tr>
                                    <td>
                                        <!--[if mso]>
                                        <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" style="height:28px;v-text-anchor:middle;width:130px;" arcsize="50%" fillcolor="{github_badge_bg}" stroke="f">
                                        <w:anchorlock/>
                                        <center style="color:{github_badge_color};font-family:Arial,sans-serif;font-size:12px;font-weight:500;">GitHub: {'Pushed' if github_success else 'Failed'}</center>
                                        </v:roundrect>
                                        <![endif]-->
                                        <!--[if !mso]><!-->
                                        <span style="display:inline-block;padding: 6px 12px; background-color: {github_badge_bg}; color: {github_badge_color}; font-size: 12px; font-weight: 500; border-radius: 20px;">
                                            GitHub: {'Pushed' if github_success else 'Failed'}
                                        </span>
                                        <!--<![endif]-->
                                    </td>
                                </tr>
                            </table>{price_warning_html}
                            <!-- Reminder -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top: 20px;">
                                <tr>
                                    <td style="background-color: #e3f2fd; padding: 15px; font-size: 14px; color: #1565c0; border-left: 4px solid #1976d2; font-weight: bold;">
                                        Plug in the permit display to update it with the new permit info.
                                    </td>
                                </tr>
                            </table>
                            <!-- Status Page Link -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top: 15px;">
                                <tr>
                                    <td align="center" style="padding: 15px;">
                                        <a href="https://ilovekitty.ca/parking?permit={permit_data['permit_number']}" style="color: #1976d2; font-size: 14px; text-decoration: none;">View Permit Status</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #999999;">
                            Toronto Parking Pass Buyer - Automated<br>
                            Sent from: {socket.gethostname()}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>'''


def build_error_email_html(title, message, vehicle_info=None):
    """Build a mobile-friendly HTML email for errors (Outlook compatible)."""
    vehicle_row = ""
    if vehicle_info:
        vehicle_row = f'''
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top: 20px;">
                                <tr>
                                    <td style="padding: 12px 0; color: #666666; font-size: 14px; border-bottom: 1px solid #eeeeee; width: 40%;">Vehicle</td>
                                    <td style="padding: 12px 0; color: #1a1a1a; font-weight: 500; font-size: 14px; border-bottom: 1px solid #eeeeee;">{vehicle_info}</td>
                                </tr>
                            </table>'''

    return f'''<!DOCTYPE html>
<html xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!--[if mso]>
    <style type="text/css">
        table {{border-collapse: collapse;}}
        td {{padding: 0;}}
    </style>
    <![endif]-->
</head>
<body style="margin: 0; padding: 0; background-color: #f5f5f5; font-family: Arial, sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f5f5f5;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="background-color: #ffffff; max-width: 600px;">
                    <!-- Header -->
                    <tr>
                        <td align="center" style="background-color: #c62828; padding: 30px 20px;">
                            <span style="font-size: 48px; color: #ffffff;">&#9888;</span>
                            <h1 style="margin: 10px 0 0 0; font-size: 24px; font-weight: 600; color: #ffffff;">{title}</h1>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 30px 20px;">
                            <!-- Error Card -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #ffebee; border-left: 4px solid #c62828;">
                                <tr>
                                    <td style="padding: 20px; color: #1a1a1a; font-size: 16px; line-height: 1.5;">
                                        {message}
                                    </td>
                                </tr>
                            </table>
                            {vehicle_row}
                            <!-- Note -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top: 20px;">
                                <tr>
                                    <td style="background-color: #fff3e0; padding: 15px; font-size: 14px; color: #e65100;">
                                        &#128206; Log file attached for debugging
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td align="center" style="background-color: #f8f9fa; padding: 20px; font-size: 12px; color: #999999;">
                            Toronto Parking Pass Buyer - Automated<br>
                            Sent from: {socket.gethostname()}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>'''

# ====== Helper Functions ======
def wait_for_xpath(driver, xpath, timeout=10, visible=False):
    try:
        wait = WebDriverWait(driver, timeout)
        if visible:
            return wait.until(EC.visibility_of_element_located((By.XPATH, xpath)))
        return wait.until(EC.element_to_be_clickable((By.XPATH, xpath)))
    except Exception as e:
        print(bcolors.FAIL + f"Timeout waiting for element: {xpath} — {e}" + bcolors.ENDC)
        return None

def fill_input_field(driver, xpath, value, label=None):
    input_box = wait_for_xpath(driver, xpath)
    if input_box:
        input_box.clear()
        input_box.send_keys(value)
        label_display = label or xpath
        # print(bcolors.OKGREEN + f"Filled {bcolors.OKCYAN}{label_display}{bcolors.OKGREEN} with {bcolors.HEADER}{value}{bcolors.ENDC}")
    else:
        print(bcolors.FAIL + f" Failed to find input field for {label or xpath}" + bcolors.ENDC)

def select_dropdown_by_text(driver, xpath, text, label=None):
    element = wait_for_xpath(driver, xpath)
    if element:
        Select(element).select_by_visible_text(text)
        # print(bcolors.OKGREEN + f"Selected {bcolors.OKCYAN}{text}{bcolors.OKGREEN} from {label or xpath}" + bcolors.ENDC)
    else:
        print(bcolors.FAIL + f"Dropdown {label or xpath} not found." + bcolors.ENDC)

def click_checkbox_if_unchecked(driver, xpath, label=""):
    checkbox = wait_for_xpath(driver, xpath)
    if checkbox:
        if not checkbox.is_selected():
            try:
                checkbox.click()
                # print(bcolors.OKGREEN + " Checkbox is now selected." + bcolors.ENDC)
            except Exception:
                driver.execute_script("arguments[0].click();", checkbox)
        else:
            print(bcolors.OKBLUE + "Checkbox was already selected." + bcolors.ENDC)
    else:
        print(bcolors.FAIL + " Checkbox not found." + bcolors.ENDC)

def extract_text_from_pdf(pdf_path):
    """Extract text content from PDF using PyPDF2 or pdfplumber."""
    text = ""

    # Try pdfplumber first (better text extraction)
    try:
        with pdfplumber.open(pdf_path) as pdf:
            for page in pdf.pages:
                page_text = page.extract_text()
                if page_text:
                    text += page_text + "\n"
        if text.strip():
            return text
    except Exception:
        pass

    # Fallback to PyPDF2
    try:
        import PyPDF2
        with open(pdf_path, 'rb') as file:
            reader = PyPDF2.PdfReader(file)
            for page in reader.pages:
                page_text = page.extract_text()
                if page_text:
                    text += page_text + "\n"
    except Exception:
        pass

    return text

def parse_permit_data(text):
    """Parse permit information from PDF text."""
    import re

    data = {
        "permit_number": None,
        "plate_number": None,
        "barcode_label": None,
        "valid_from": None,
        "valid_to": None,
        "amount_paid": None,
    }

    # Permit Number patterns
    permit_patterns = [
        r"Permit\s+no\.?\s*:\s*([A-Z0-9]+)",
        r"Permit\s+number\s*:\s*([A-Z0-9]+)",
    ]

    # License Plate patterns
    plate_patterns = [
        r"Plate\s+no\.?\s*:\s*([A-Z0-9]+)",
        r"(?:License|Licence)\s+plate\s*:\s*([A-Z0-9]+)",
    ]

    # Barcode Label pattern
    barcode_patterns = [
        r"(?:^|\n)\s*(\d{5})\s*(?:\n|$)",
        r"(\d{5})\s*\n[^\n]*Permit\s+no",
    ]

    # Date patterns
    date_patterns = [
        r"Valid\s+from\s*:\s*([A-Z][a-z]+\s+\d{1,2},?\s+\d{4}(?:\s+at\s+\d{1,2}:\d{2}\s*(?:AM|PM))?)",
        r"Valid\s+from\s*:\s*(\d{1,2}/\d{1,2}/\d{4}(?:\s+\d{1,2}:\d{2}\s*(?:AM|PM)?)?)",
    ]

    valid_to_patterns = [
        r"Valid\s+to\s*:\s*([A-Z][a-z]+\s+\d{1,2},?\s+\d{4}(?:\s+at\s+\d{1,2}:\d{2}\s*(?:AM|PM))?)",
        r"Valid\s+to\s*:\s*(\d{1,2}/\d{1,2}/\d{4}(?:\s+\d{1,2}:\d{2}\s*(?:AM|PM)?)?)",
    ]

    # Search for permit number
    for pattern in permit_patterns:
        match = re.search(pattern, text, re.IGNORECASE)
        if match:
            data["permit_number"] = match.group(1).strip()
            break

    # Search for plate number
    for pattern in plate_patterns:
        match = re.search(pattern, text, re.IGNORECASE)
        if match:
            data["plate_number"] = match.group(1).strip().upper()
            break

    # Search for barcode label
    for pattern in barcode_patterns:
        match = re.search(pattern, text, re.IGNORECASE | re.DOTALL)
        if match:
            data["barcode_label"] = match.group(1).strip()
            break

    # Search for valid from date
    for pattern in date_patterns:
        match = re.search(pattern, text, re.IGNORECASE)
        if match:
            data["valid_from"] = match.group(1).strip()
            break

    # Search for valid to date
    for pattern in valid_to_patterns:
        match = re.search(pattern, text, re.IGNORECASE)
        if match:
            data["valid_to"] = match.group(1).strip()
            break

    # Search for amount paid
    amount_patterns = [
        r"Amount\s+paid\s*:\s*\$?([\d,]+\.?\d*)",
        r"Total\s*:\s*\$?([\d,]+\.?\d*)",
    ]
    for pattern in amount_patterns:
        match = re.search(pattern, text, re.IGNORECASE)
        if match:
            data["amount_paid"] = f"${match.group(1).strip()}"
            break

    return data

def find_permit_pdf(folder):
    """Find the most recent PDF file that looks like a Toronto parking permit receipt."""
    folder = Path(folder)

    # Look for specific filename patterns
    patterns = [
        "*Temporary Parking Permit*.pdf",
        "*Parking Permit Receipt*.pdf",
        "*permit*.pdf",
        "*receipt*.pdf",
    ]

    for pattern in patterns:
        matches = list(folder.glob(pattern))
        if matches:
            return max(matches, key=lambda f: f.stat().st_mtime)

    # If no pattern match, return most recent PDF
    pdfs = list(folder.glob("*.pdf"))
    if pdfs:
        return max(pdfs, key=lambda f: f.stat().st_mtime)

    return None

def create_permit_json(permit_data, output_path, vehicle_name=None):
    """Create permit.json file from parsed permit data."""
    # Look up vehicle name from plate if not provided
    if vehicle_name is None and permit_data.get("plate_number"):
        try:
            with open('config/info_cars.json', 'r') as f:
                info_cars = json.load(f)
            for car in info_cars:
                if car.get('plate', '').upper() == permit_data['plate_number'].upper():
                    vehicle_name = car.get('name')
                    break
        except Exception:
            pass

    # Convert dates to match ESP32 format
    valid_from = permit_data["valid_from"]
    valid_to = permit_data["valid_to"]

    def convert_to_24h(time_str):
        """Convert '12:00 AM' to '00:00' or '12:00 PM' to '12:00', etc."""
        import re
        match = re.match(r'(\d{1,2}):(\d{2})\s*(AM|PM)', time_str, re.IGNORECASE)
        if match:
            hour = int(match.group(1))
            minute = match.group(2)
            ampm = match.group(3).upper()
            if ampm == 'AM':
                if hour == 12:
                    hour = 0
            else:  # PM
                if hour != 12:
                    hour += 12
            return f"{hour:02d}:{minute}"
        return time_str

    # Convert "Oct 25, 2025 at 12:00 AM" to "Oct 25, 2025: 00:00"
    if valid_from and " at " in valid_from:
        date_part, time_part = valid_from.split(" at ")
        time_24h = convert_to_24h(time_part.strip())
        valid_from = f"{date_part}: {time_24h}"

    if valid_to and " at " in valid_to:
        date_part, time_part = valid_to.split(" at ")
        time_24h = convert_to_24h(time_part.strip())
        valid_to = f"{date_part}: {time_24h}"

    # Create JSON structure
    json_data = {
        "permitNumber": permit_data["permit_number"],
        "plateNumber": permit_data["plate_number"],
        "vehicleName": vehicle_name,
        "validFrom": valid_from,
        "validTo": valid_to,
        "barcodeValue": permit_data["permit_number"][1:] if permit_data["permit_number"] else None,
        "barcodeLabel": permit_data["barcode_label"],
        "amountPaid": permit_data.get("amount_paid")
    }

    # Write to file
    with open(output_path, 'w') as f:
        json.dump(json_data, f, indent=2)

    # Append to permits history (for status page)
    history_path = Path(output_path).parent / 'permits_history.json'
    try:
        if history_path.exists():
            with open(history_path, 'r') as f:
                history = json.load(f)
        else:
            history = []

        # Only add if not already in history (check by permit number)
        if not any(p.get('permitNumber') == json_data['permitNumber'] for p in history):
            history.append(json_data)
            with open(history_path, 'w') as f:
                json.dump(history, f, indent=2)
            print(bcolors.OKCYAN + f"Added to permits history ({len(history)} total)" + bcolors.ENDC)
    except Exception as e:
        print(bcolors.WARNING + f"Could not update permits history: {e}" + bcolors.ENDC)

    print(bcolors.OKGREEN + f"\nCreated permit JSON: {output_path}" + bcolors.ENDC)
    return json_data

def archive_pdf(pdf_path, permit_number=None):
    """Move PDF to old_permits archive folder with timestamp and permit number."""
    import shutil

    # Create archive folder if it doesn't exist
    archive_dir = Path('old_permits')
    archive_dir.mkdir(exist_ok=True)

    # Generate timestamped filename, optionally with permit number for easy identification
    pdf_path = Path(pdf_path)
    timestamp = datetime.now().strftime("%Y-%m-%d_%H%M%S")
    suffix = f"_{permit_number}" if permit_number else ""
    archived_name = f"permit_{timestamp}{suffix}{pdf_path.suffix}"
    archived_path = archive_dir / archived_name

    # Move the file
    shutil.move(str(pdf_path), str(archived_path))
    print(bcolors.OKCYAN + f"Archived PDF to: {archived_path}" + bcolors.ENDC)

    return archived_path

def commit_and_push_to_github(file_path, commit_message, target_repo_path=None, target_branch=None):
    """
    Commit and push permit.json to the parking_pass_display repo.

    Args:
        file_path: Path to permit.json
        commit_message: Commit message
        target_repo_path: Path to parking_pass_display repo (from settings.json)
        target_branch: Target branch (from settings.json)
    """
    import subprocess
    import shutil

    # Use settings if not specified
    if target_repo_path is None:
        current_dir = Path(__file__).parent
        target_repo_path = current_dir / settings["github"]["display_repo_path"]

    if target_branch is None:
        target_branch = settings["github"]["permit_branch"]

    target_repo_path = Path(target_repo_path)
    original_dir = os.getcwd()

    # Convert file_path to absolute path BEFORE changing directories
    file_path = Path(file_path).resolve()

    try:
        # Check if target repo exists
        if not target_repo_path.exists():
            print(bcolors.FAIL + f"Target repo not found: {target_repo_path}" + bcolors.ENDC)
            print(bcolors.WARNING + "Please set the correct path to parking_pass_display repo" + bcolors.ENDC)
            return False

        # Change to target repo directory FIRST
        os.chdir(target_repo_path)

        try:
            # Check current branch
            result = subprocess.run(['git', 'branch', '--show-current'],
                                  capture_output=True, text=True, check=True)
            current_branch = result.stdout.strip()

            # Checkout target branch if not already on it
            if current_branch != target_branch:
                print(bcolors.OKCYAN + f"Switching to '{target_branch}' branch..." + bcolors.ENDC)

                # Stash any uncommitted changes to avoid conflicts
                stash_result = subprocess.run(['git', 'stash', 'push', '-u', '-m', 'Auto-stash before permit update'],
                                            capture_output=True, text=True, check=False)
                stashed = 'No local changes to save' not in stash_result.stdout

                # Checkout the permit branch
                subprocess.run(['git', 'checkout', target_branch], check=True)

                # Give git a moment to release file handles after checkout
                time.sleep(0.5)
            else:
                stashed = False

            # NOW copy permit.json AFTER switching branches (with retry for file locks)
            target_file = target_repo_path / 'permit.json'

            max_retries = 3
            for attempt in range(max_retries):
                try:
                    # Read source file
                    with open(file_path, 'r') as src:
                        content = src.read()

                    # Write to destination (this seems to work better than shutil.copy2 on Windows)
                    with open(target_file, 'w') as dst:
                        dst.write(content)

                    print(bcolors.OKGREEN + f"Copied permit.json to {target_repo_path}" + bcolors.ENDC)
                    break
                except (PermissionError, IOError) as e:
                    if attempt < max_retries - 1:
                        print(bcolors.WARNING + f"File locked, retrying in 1 second... (attempt {attempt + 1}/{max_retries})" + bcolors.ENDC)
                        time.sleep(1)
                    else:
                        raise  # Re-raise if all retries failed

            # Add the file
            subprocess.run(['git', 'add', 'permit.json'], check=True)

            # Check if there are STAGED changes to commit (not just untracked files)
            diff_result = subprocess.run(['git', 'diff', '--cached', '--name-only'],
                                        capture_output=True, text=True, check=True)

            if not diff_result.stdout.strip():
                print(bcolors.OKCYAN + "permit.json is already up-to-date in parking_pass_display/permit branch" + bcolors.ENDC)
                return True  # Success - no changes needed
            else:
                # Commit
                subprocess.run(['git', 'commit', '-m', commit_message], check=True)

                # Push (use GitHub token if available for authentication)
                if github_token:
                    # Get current remote URL
                    remote_result = subprocess.run(['git', 'remote', 'get-url', 'origin'],
                                                 capture_output=True, text=True, check=True)
                    remote_url = remote_result.stdout.strip()

                    # Convert to token-authenticated URL
                    if 'github.com' in remote_url:
                        if remote_url.startswith('https://'):
                            # Already HTTPS, add token
                            auth_url = remote_url.replace('https://', f'https://{github_token}@')
                        elif remote_url.startswith('git@'):
                            # Convert SSH to HTTPS with token
                            auth_url = remote_url.replace('git@github.com:', f'https://{github_token}@github.com/')
                            auth_url = auth_url.replace('.git', '')

                        # Temporarily set remote URL with token
                        subprocess.run(['git', 'remote', 'set-url', 'origin', auth_url], check=True, capture_output=True, text=True)
                        subprocess.run(['git', 'push'], check=True, capture_output=True, text=True)
                        # Restore original URL
                        subprocess.run(['git', 'remote', 'set-url', 'origin', remote_url], check=True, capture_output=True, text=True)
                    else:
                        # Not a GitHub URL, push normally
                        subprocess.run(['git', 'push'], check=True, capture_output=True, text=True)
                else:
                    # No token, use default git credentials
                    subprocess.run(['git', 'push'], check=True, capture_output=True, text=True)

                print(bcolors.OKGREEN + f"Pushed to GitHub (parking_pass_display/{target_branch}): {commit_message}" + bcolors.ENDC)
                log_event(f"Successfully pushed permit update: {commit_message}", "SUCCESS")

            # Switch back to original branch if we changed
            if current_branch and current_branch != target_branch:
                subprocess.run(['git', 'checkout', current_branch], check=False)

                # Restore stashed changes if we stashed anything
                if stashed:
                    print(bcolors.OKCYAN + "Restoring stashed changes..." + bcolors.ENDC)
                    subprocess.run(['git', 'stash', 'pop'], check=False)

            return True

        finally:
            # Always return to original directory
            os.chdir(original_dir)

    except subprocess.CalledProcessError as e:
        error_msg = f"Git operation failed: {e}"
        if e.stderr:
            error_msg += f"\nstderr: {e.stderr}"
        if e.stdout:
            error_msg += f"\nstdout: {e.stdout}"
        print(bcolors.FAIL + error_msg + bcolors.ENDC)
        log_event(error_msg, "ERROR")
        os.chdir(original_dir)
        return False
    except Exception as e:
        error_msg = f"GitHub push error: {e}"
        print(bcolors.FAIL + error_msg + bcolors.ENDC)
        log_event(error_msg, "ERROR")
        return False

# ====== API-based Refetch (Fast) ======
def refetch_permit_api(vehicle_index=None, card_index=None):
    """
    Refetch permit using direct API calls (curl_cffi).
    Much faster than Selenium (~4s vs ~20s).
    Returns (vehicle_name, vehicle_plate, pdf_path) or (None, None, None) on failure.
    """
    import re

    if not CURL_CFFI_AVAILABLE:
        print(bcolors.WARNING + "curl_cffi not available, falling back to Selenium" + bcolors.ENDC)
        return None, None, None

    # Load data
    with open('config/info_payment_cards.json', 'r') as file:
        info_payments = json.load(file)

    with open('config/info_cars.json', 'r') as file:
        info_cars = json.load(file)

    # Select vehicle
    if vehicle_index is not None:
        if 0 <= vehicle_index < len(info_cars):
            selected_vehicle = info_cars[vehicle_index]
        else:
            print(bcolors.FAIL + f"Invalid vehicle index: {vehicle_index}" + bcolors.ENDC)
            return None, None, None
    elif len(info_cars) == 1:
        selected_vehicle = info_cars[0]
    else:
        print(bcolors.FAIL + "API refetch requires --vehicle index in non-interactive mode" + bcolors.ENDC)
        return None, None, None

    # Select payment card
    if card_index is not None:
        if 0 <= card_index < len(info_payments):
            selected_payment_card = info_payments[card_index]
        else:
            print(bcolors.FAIL + f"Invalid card index: {card_index}" + bcolors.ENDC)
            return None, None, None
    elif len(info_payments) == 1:
        selected_payment_card = info_payments[0]
    else:
        print(bcolors.FAIL + "API refetch requires --card index in non-interactive mode" + bcolors.ENDC)
        return None, None, None

    last_4_digits = str(selected_payment_card["card_number"])[-4:]

    print(bcolors.OKCYAN + f"API Refetch: {selected_vehicle['name']} ({selected_vehicle['plate']})" + bcolors.ENDC)
    log_event(f"Starting API refetch for {selected_vehicle['name']} ({selected_vehicle['plate']})")

    try:
        if curl_requests is None:
            return None, None, None
        session = curl_requests.Session(impersonate="chrome")

        # Step 1: Load search page to get Struts token
        print(bcolors.OKCYAN + "  Loading search page..." + bcolors.ENDC)
        resp = session.get("https://secure.toronto.ca/wes/eTPP/searchPermit.do?back=0", timeout=30)

        if resp.status_code != 200:
            log_event(f"API refetch failed: search page returned {resp.status_code}", "ERROR")
            return None, None, None

        # Extract Struts token
        token_match = re.search(r'name="org\.apache\.struts\.taglib\.html\.TOKEN"\s+value="([^"]+)"', resp.text)
        if not token_match:
            log_event("API refetch failed: no Struts token found", "ERROR")
            return None, None, None

        struts_token = token_match.group(1)

        # Step 2: Submit search form
        print(bcolors.OKCYAN + "  Searching for permit..." + bcolors.ENDC)
        search_data = {
            "org.apache.struts.taglib.html.TOKEN": struts_token,
            "search": "search",
            "licPltNum": selected_vehicle['plate'],
            "provCode": "ON",
            "creditCardType": "V",  # Visa - TODO: detect from card type
            "creditCardNum": last_4_digits,
        }

        resp = session.post(
            "https://secure.toronto.ca/wes/eTPP/searchPermit.do",
            data=search_data,
            timeout=30
        )

        # Check if we got a PDF
        if resp.headers.get('Content-Type', '').startswith('application/pdf'):
            pdf_path = Path(download_dir) / f"permit_{selected_vehicle['plate']}_{datetime.now().strftime('%Y%m%d_%H%M%S')}.pdf"
            with open(pdf_path, 'wb') as f:
                f.write(resp.content)

            print(bcolors.OKGREEN + f"  PDF downloaded: {pdf_path}" + bcolors.ENDC)
            log_event(f"API refetch successful: {pdf_path}", "SUCCESS")

            return selected_vehicle['name'], selected_vehicle['plate'], pdf_path

        else:
            # Check for error messages
            if "No matching permit" in resp.text or "no permit" in resp.text.lower():
                print(bcolors.WARNING + "  No permit found for this plate/card combination" + bcolors.ENDC)
                log_event("API refetch: no permit found", "WARNING")
            else:
                log_event(f"API refetch failed: unexpected response (Content-Type: {resp.headers.get('Content-Type')})", "ERROR")

            return None, None, None

    except Exception as e:
        log_event(f"API refetch error: {e}", "ERROR")
        print(bcolors.FAIL + f"  API error: {e}" + bcolors.ENDC)
        return None, None, None


# ====== Refetch Permit Workflow (Selenium) ======
def refetch_permit(vehicle_index=None, card_index=None, headless=False):
    """Navigate to permit search page, enter plate + last 4 card digits, and download the PDF."""
    url = "https://secure.toronto.ca/wes/eTPP/searchPermit.do?back=0"

    # Load data
    with open('config/info_payment_cards.json', 'r') as file:
        info_payments = json.load(file)

    with open('config/info_cars.json', 'r') as file:
        info_cars = json.load(file)

    # Select vehicle
    if vehicle_index is not None:
        if 0 <= vehicle_index < len(info_cars):
            selected_vehicle = info_cars[vehicle_index]
            print(bcolors.OKGREEN + f"Using vehicle: {selected_vehicle['name']} - {selected_vehicle['plate']}" + bcolors.ENDC)
        else:
            print(bcolors.FAIL + f"Invalid vehicle index: {vehicle_index}" + bcolors.ENDC)
            return None, None
    elif len(info_cars) == 1:
        # Only one vehicle, no need to ask
        selected_vehicle = info_cars[0]
        print(bcolors.OKGREEN + f"Using vehicle: {selected_vehicle['name']} - {selected_vehicle['plate']}" + bcolors.ENDC)
    else:
        print("\n" + bcolors.WARNING + "Which vehicle's permit would you like to refetch?" + bcolors.ENDC)
        for idx, vehicle in enumerate(info_cars):
            print(f"{bcolors.WARNING}{idx + 1}. {bcolors.OKCYAN}{vehicle['name']} - {vehicle['plate']}{bcolors.ENDC}")

        while True:
            try:
                choice = int(input(bcolors.OKGREEN + "\nEnter the number for the vehicle: " + bcolors.ENDC))
                if 1 <= choice <= len(info_cars):
                    selected_vehicle = info_cars[choice - 1]
                    break
                else:
                    print(bcolors.FAIL + "Please enter a valid number from the list" + bcolors.ENDC)
            except ValueError:
                print(bcolors.WARNING + "Please enter a number" + bcolors.ENDC)

    # Select payment card (for last 4 digits)
    if card_index is not None:
        if 0 <= card_index < len(info_payments):
            selected_payment_card = info_payments[card_index]
            print(bcolors.OKGREEN + f"Using payment card: {selected_payment_card['card_name']}" + bcolors.ENDC)
        else:
            print(bcolors.FAIL + f"Invalid card index: {card_index}" + bcolors.ENDC)
            return None, None
    elif len(info_payments) == 1:
        # Only one card, no need to ask
        selected_payment_card = info_payments[0]
        print(bcolors.OKGREEN + f"Using payment card: {selected_payment_card['card_name']}" + bcolors.ENDC)
    else:
        print(bcolors.WARNING + "\nWhich card was used to purchase the permit?" + bcolors.ENDC)
        for idx, payment_card in enumerate(info_payments):
            print(f"{bcolors.WARNING}{idx + 1}. {bcolors.OKCYAN}{payment_card['card_name']}{bcolors.ENDC}")

        while True:
            try:
                choice = int(input(bcolors.OKGREEN + "\nEnter the number for the card: " + bcolors.ENDC))
                if 1 <= choice <= len(info_payments):
                    selected_payment_card = info_payments[choice - 1]
                    break
                else:
                    print(bcolors.FAIL + "Please enter a valid number from the list" + bcolors.ENDC)
            except ValueError:
                print(bcolors.WARNING + "Please enter a number" + bcolors.ENDC)

    last_4_digits = str(selected_payment_card["card_number"])[-4:]

    print(bcolors.HEADER + f"\nRefetching permit for: {bcolors.WARNING + selected_vehicle['name'] + bcolors.HEADER} with plate {bcolors.OKBLUE + selected_vehicle['plate'] + bcolors.ENDC}")
    log_event(f"Starting refetch for {selected_vehicle['name']} ({selected_vehicle['plate']})")

    # Setup WebDriver
    service = Service(ChromeDriverManager().install(), log_path='NUL')
    driver = webdriver.Chrome(service=service, options=chrome_options)

    try:
        driver.get(url)

        # Fill in plate number
        fill_input_field(driver, '//*[@id="licPltNum"]', selected_vehicle['plate'], "plate_number")

        # Select Ontario province
        select_dropdown_by_text(driver, '//*[@id="provCode"]', "ON - Ontario", "province")

        # Select card type (Visa dropdown)
        select_dropdown_by_text(driver, '//*[@id="creditCardType"]', "Visa", "card_type")

        # Fill in last 4 digits of card
        fill_input_field(driver, '//*[@id="creditCardNum"]', last_4_digits, "last_4_digits")

        # Click search/submit button
        submit_btn = wait_for_xpath(driver, '//*[@id="maincontent"]/div[2]/div/div[1]/div/div[2]/div/div/div/div[3]/button', visible=True)
        if submit_btn:
            submit_btn.click()
            print(bcolors.OKCYAN + "Searching for permit..." + bcolors.ENDC)

        # Wait for PDF to auto-download (Chrome should download it automatically now)
        print(bcolors.OKCYAN + "\nWaiting for PDF to download..." + bcolors.ENDC)

        # Wait for permit.pdf to appear (up to 30 seconds)
        pdf_found = False
        for _ in range(30):
            pdf_files = glob.glob(os.path.join(download_dir, "permit*.pdf"))
            if pdf_files:
                pdf_found = True
                print(bcolors.OKGREEN + f"PDF downloaded: {pdf_files[0]}" + bcolors.ENDC)
                break
            time.sleep(1)

        if not pdf_found:
            if headless:
                log_event("PDF not auto-downloaded in headless mode - continuing anyway", "WARNING")
            else:
                print(bcolors.WARNING + "PDF not auto-downloaded. Waiting for manual download..." + bcolors.ENDC)
                print(bcolors.HEADER + "Press " + bcolors.WARNING + "enter" + bcolors.HEADER + " when done..." + bcolors.ENDC)
                input()

        return selected_vehicle['name'], selected_vehicle['plate']

    except Exception as e:
        print(bcolors.FAIL + f"Error during refetch: {e}" + bcolors.ENDC)
        log_event(f"Refetch failed: {e}", "ERROR")
        take_error_screenshot(driver, "refetch_error")
        return None, None

    finally:
        driver.quit()

# ====== Main Automation Workflow ======
def get_parking_pass(vehicle_index=None, card_index=None, dry_run=False, headless=False):
    url = "https://secure.toronto.ca/wes/eTPP/welcome.do"

    # Load data
    with open('config/info_payment_cards.json', 'r') as file:
        info_payments = json.load(file)

    with open('config/info_addresses.json', 'r') as file:
        info_addresses = json.load(file)

    with open('config/info_cars.json', 'r') as file:
        info_cars = json.load(file)

    # Select vehicle (either from CLI arg or interactive prompt)
    if vehicle_index is not None:
        if 0 <= vehicle_index < len(info_cars):
            selected_vehicle = info_cars[vehicle_index]
            print(bcolors.OKGREEN + f"Using vehicle: {selected_vehicle['name']} - {selected_vehicle['plate']}" + bcolors.ENDC)
        else:
            print(bcolors.FAIL + f"Invalid vehicle index: {vehicle_index}" + bcolors.ENDC)
            return None, None
    elif len(info_cars) == 1:
        # Only one vehicle, no need to ask
        selected_vehicle = info_cars[0]
        print(bcolors.OKGREEN + f"Using vehicle: {selected_vehicle['name']} - {selected_vehicle['plate']}" + bcolors.ENDC)
    elif headless:
        # Headless mode with multiple vehicles - use first one
        selected_vehicle = info_cars[0]
        print(bcolors.WARNING + f"Headless mode: auto-selecting first vehicle: {selected_vehicle['name']} - {selected_vehicle['plate']}" + bcolors.ENDC)
    else:
        # Interactive mode
        print("\n" + bcolors.WARNING + "Which vehicle would you like to get a parking permit for?" + bcolors.ENDC)
        for idx, vehicle in enumerate(info_cars):
            print(f"{bcolors.WARNING}{idx + 1}. {bcolors.OKCYAN}{vehicle['name']} - {vehicle['plate']}{bcolors.ENDC}")

        print(bcolors.HEADER + "\nChoose wisely... your parking fate depends on it." + bcolors.ENDC)

        while True:
            try:
                choice = int(input(bcolors.OKGREEN + "\nEnter the number for the vehicle: " + bcolors.ENDC))
                if 1 <= choice <= len(info_cars):
                    selected_vehicle = info_cars[choice - 1]
                    break
                else:
                    print(bcolors.FAIL + "Please enter a valid number from the list" + bcolors.ENDC)
            except ValueError:
                print(bcolors.WARNING + "Please enter a number" + bcolors.ENDC)

    # Select payment card (either from CLI arg or interactive prompt)
    if card_index is not None:
        if 0 <= card_index < len(info_payments):
            selected_payment_card = info_payments[card_index]
            print(bcolors.OKGREEN + f"Using payment card: {selected_payment_card['card_name']}" + bcolors.ENDC)
        else:
            print(bcolors.FAIL + f"Invalid card index: {card_index}" + bcolors.ENDC)
            return None, None
    elif len(info_payments) == 1:
        # Only one card, no need to ask
        selected_payment_card = info_payments[0]
        print(bcolors.OKGREEN + f"Using payment card: {selected_payment_card['card_name']}" + bcolors.ENDC)
    elif headless:
        # Headless mode with multiple cards - use first one
        selected_payment_card = info_payments[0]
        print(bcolors.WARNING + f"Headless mode: auto-selecting first card: {selected_payment_card['card_name']}" + bcolors.ENDC)
    else:
        # Interactive mode
        print(bcolors.WARNING + "\nWhich card would you like to use to pay for parking permit?" + bcolors.ENDC)
        for idx, payment_card in enumerate(info_payments):
            print(f"{bcolors.WARNING}{idx + 1}. {bcolors.OKCYAN}{payment_card['card_name']}{bcolors.ENDC}")
        print(bcolors.HEADER + "\nRemember, it's not Monopoly money... or is it?" + bcolors.ENDC)

        while True:
            try:
                choice = int(input(bcolors.OKGREEN + "\nEnter the number for the card: " + bcolors.ENDC))
                if 1 <= choice <= len(info_payments):
                    selected_payment_card = info_payments[choice - 1]
                    break
                else:
                    print(bcolors.FAIL + "Please enter a valid number from the list" + bcolors.ENDC)
            except ValueError:
                print(bcolors.WARNING + "Please enter a number" + bcolors.ENDC)

    print(bcolors.HEADER + f"\nGetting parking pass for: {bcolors.WARNING + selected_vehicle['name'] + bcolors.HEADER} with plate {bcolors.OKBLUE + selected_vehicle['plate'] + bcolors.ENDC}")
    print(bcolors.OKCYAN + "\nLet's hope this car is worth it..." + bcolors.ENDC)

    # Log the purchase attempt
    log_event(f"Starting purchase for {selected_vehicle['name']} ({selected_vehicle['plate']})")

    # Setup WebDriver
    service = Service(ChromeDriverManager().install(), log_path='NUL')
    driver = webdriver.Chrome(service=service, options=chrome_options)

    try:
        driver.get(url)

        # Agree to terms
        wait_for_xpath(driver, '//*[@id="maincontent"]/div[2]/div/div[1]/div/div[3]/div/button[2]').click()

        # ===== Page 1 =====
        page_1_field_xpaths = {
            "initals": '//*[@id="initial"]',
            "surname": '//*[@id="name"]',
            "steetNumber": '//*[@id="streetNumber"]',
            "streetName": '//*[@id="streetName"]',
            "permit_duration": '//*[@id="permitType"]',
            "permit_start_date": '//*[@id="datepicker"]',
        }

        page_1_data = {
            **info_addresses,
            "permit_start_date": (datetime.now() + timedelta(days=1)).strftime("%m/%d/%Y")
        }

        for field, xpath in page_1_field_xpaths.items():
            if field == "permit_duration":
                select_dropdown_by_text(driver, xpath, page_1_data[field], field)
            elif field == "permit_start_date":
                fill_input_field(driver, xpath, page_1_data[field], field)
            else:
                fill_input_field(driver, xpath, page_1_data[field], field)

        wait_for_xpath(driver, '//*[@id="maincontent"]/div[2]/div/div[1]/div/div[2]/div/div/div/div[3]/button').click() #?? what is this for ?

        # ===== Page 2 =====
        wait_for_xpath(driver, '//*[@id="maincontent"]/div[2]/div/div[1]/div/div[2]/div/div/div/div/div[4]/button')
        try:
            WebDriverWait(driver, 10).until(
                EC.presence_of_element_located((By.XPATH, "//*[contains(text(), 'Space is available')]"))
            )
            print(bcolors.OKGREEN + "Space is available" + bcolors.ENDC)
            wait_for_xpath(driver, '//*[@id="maincontent"]/div[2]/div/div[1]/div/div[2]/div/div/div/div/div[4]/button').click()
        except:
            print(bcolors.FAIL + "Space is not available" + bcolors.ENDC)
            log_event("No parking space available", "ERROR")
            take_error_screenshot(driver, "no_space_available")
            return None, None

        # ===== Page 3 =====
        wait_for_xpath(driver, '//*[@id="maincontent"]/div[2]/div/div[1]/div/div[2]/div/div/div/div[3]/button')

        fill_input_field(driver, '//*[@id="licPltNum"]', selected_vehicle['plate'], "plate_number_1")
        fill_input_field(driver, '//*[@id="licPltNum2"]', selected_vehicle['plate'], "plate_number_2")
        select_dropdown_by_text(driver, '//*[@id="provCode"]', "ON - Ontario", "province")
        checkbox = wait_for_xpath(driver, '//*[@id="confirmVehicleSizeAndWeight"]', visible=True)
        if checkbox:
            click_checkbox_if_unchecked(driver, '//*[@id="confirmVehicleSizeAndWeight"]', "agreement checkbox")
        else:
            print(bcolors.FAIL + "Checkbox not found! Maybe it's hiding from responsibility, like you." + bcolors.ENDC)

        wait_for_xpath(driver, '//*[@id="maincontent"]/div[2]/div/div[1]/div/div[2]/div/div/div/div[3]/button').click()

        # ===== Check for "permit already issued" error =====
        time.sleep(2)  # Give page time to load
        try:
            # Check if we hit the "permit already issued" page
            permit_exists = driver.find_elements(By.XPATH, "//*[contains(text(), 'permit for this vehicle for the same period has already been issued')]")
            if permit_exists:
                print(bcolors.WARNING + "\n" + "="*60 + bcolors.ENDC)
                print(bcolors.WARNING + "A permit for this vehicle already exists for this period!" + bcolors.ENDC)
                print(bcolors.OKCYAN + "Use the --refetch option to download the existing permit." + bcolors.ENDC)
                print(bcolors.WARNING + "="*60 + "\n" + bcolors.ENDC)
                log_event(f"Permit already exists for {selected_vehicle['plate']}", "WARNING")
                return "EXISTS", None  # Special return value for permit exists
        except Exception:
            pass  # Continue if check fails

        # ===== Payment Page (iframe) =====
        iframe = wait_for_xpath(driver, '//*[@id="monerisCheckout-Frame"]')
        if iframe is None:
            print(bcolors.FAIL + "Payment page not found. The purchase may have failed." + bcolors.ENDC)
            take_error_screenshot(driver, "payment_page_not_found")
            return None, None
        driver.switch_to.frame(iframe)

        fill_input_field(driver, '//*[@id="cardholder"]', selected_payment_card["cardholder_name"], "cardholder_name")
        fill_input_field(driver, '//*[@id="pan"]', selected_payment_card["card_number"], "card_number")
        fill_input_field(driver, '//*[@id="expiry_date"]', selected_payment_card["card_expiry"], "card_expiry")
        fill_input_field(driver, '//*[@id="cvv"]', selected_payment_card["card_CVV"], "card_CVV")

        # Dry run mode: stop before payment
        if dry_run:
            print(bcolors.WARNING + "\n" + "="*60 + bcolors.ENDC)
            print(bcolors.WARNING + "DRY RUN: Stopping before payment submission" + bcolors.ENDC)
            print(bcolors.OKGREEN + "All forms filled successfully!" + bcolors.ENDC)
            print(bcolors.OKCYAN + "Payment form is ready but NOT submitted." + bcolors.ENDC)
            print(bcolors.WARNING + "="*60 + "\n" + bcolors.ENDC)
            log_event(f"Dry run completed for {selected_vehicle['name']} ({selected_vehicle['plate']})", "SUCCESS")
            # Only prompt if running interactively and not headless
            if not headless and sys.stdin.isatty():
                input(bcolors.HEADER + "Press enter to close browser..." + bcolors.ENDC)
            return "DRY_RUN", None

        # Click the Pay button
        print(bcolors.OKCYAN + "\nSubmitting payment..." + bcolors.ENDC)
        pay_btn = wait_for_xpath(driver, '//*[@id="process"]', visible=True)
        if pay_btn:
            pay_btn.click()
            print(bcolors.OKGREEN + "Payment submitted, waiting for confirmation..." + bcolors.ENDC)

        # Switch back to main content to check for payment result
        driver.switch_to.default_content()

        # Wait for payment processing and page redirect
        time.sleep(5)

        # Check for payment declined on the main Toronto page
        try:
            page_text = driver.page_source.lower()
            if "payment declined" in page_text:
                log_event("Payment declined detected on Toronto page", "ERROR")
                print(bcolors.FAIL + "Payment Declined!" + bcolors.ENDC)
                take_error_screenshot(driver, "payment_declined")
                return "PAYMENT_DECLINED", "Payment was declined."
        except Exception as e:
            log_event(f"Error checking for payment declined: {e}", "WARNING")

        # Wait for PDF to auto-download
        print(bcolors.OKCYAN + "\nWaiting for PDF to download..." + bcolors.ENDC)

        # Wait for permit.pdf to appear (up to 30 seconds for payment processing)
        pdf_found = False
        for _ in range(30):
            pdf_files = glob.glob(os.path.join(download_dir, "permit*.pdf"))
            if pdf_files:
                pdf_found = True
                print(bcolors.OKGREEN + f"PDF downloaded: {pdf_files[0]}" + bcolors.ENDC)
                break
            time.sleep(1)

        if not pdf_found:
            if headless:
                log_event("PDF not auto-downloaded in headless mode - continuing anyway", "WARNING")
            elif sys.stdin.isatty():
                print(bcolors.WARNING + "PDF not auto-downloaded. Waiting for manual confirmation..." + bcolors.ENDC)
                print(bcolors.HEADER + "Press " + bcolors.WARNING + "enter" + bcolors.HEADER + " when done..." + bcolors.ENDC)
                input()
            else:
                log_event("PDF not auto-downloaded, non-interactive mode - continuing anyway", "WARNING")

        return selected_vehicle['name'], selected_vehicle['plate']

    finally:
        driver.quit()

if __name__ == "__main__":
    # Clean up old log files (older than 90 days)
    deleted = cleanup_old_logs(max_age_days=90)
    if deleted > 0:
        log_event(f"Cleaned up {deleted} old log file(s)", "INFO")

    # Check for expiry / vehicle-switch reminders (runs every time script is invoked)
    check_and_send_expiry_reminder()
    check_and_send_vehicle_switch_reminder()

    # Parse command-line arguments
    parser = argparse.ArgumentParser(
        description='Toronto Parking Pass Buyer - Automated parking permit purchase',
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog='''
Examples:
  # Interactive mode (prompts for vehicle and card selection)
  python parking_pass_buyer.py

  # Automated mode (use first vehicle and first card)
  python parking_pass_buyer.py --vehicle 0 --card 0

  # Process existing PDF without buying (searches for latest PDF)
  python parking_pass_buyer.py --parse-only

  # Process specific PDF file
  python parking_pass_buyer.py --parse-only --pdf "path/to/permit.pdf"

  # Refetch existing permit (search by plate + card)
  python parking_pass_buyer.py --refetch --vehicle 0 --card 0

  # Headless mode for server/cron automation (no browser window)
  python parking_pass_buyer.py --vehicle 0 --card 0 --headless

  # Dry run: test all steps without making a purchase
  python parking_pass_buyer.py --vehicle 0 --card 0 --dry-run
        '''
    )

    parser.add_argument('--vehicle', type=int, help='Vehicle index (0-based, see config/info_cars.json)')
    parser.add_argument('--card', type=int, help='Payment card index (0-based, see config/info_payment_cards.json)')
    parser.add_argument('--no-github', action='store_true', help='Skip GitHub commit and push')
    parser.add_argument('--parse-only', action='store_true', help='Only parse existing PDF without buying new permit')
    parser.add_argument('--pdf', type=str, help='Path to specific PDF file to parse (use with --parse-only)')
    parser.add_argument('--refetch', action='store_true', help='Refetch existing permit (searches by plate + last 4 card digits)')
    parser.add_argument('--headless', action='store_true', help='Run Chrome in headless mode (for server/cron automation)')
    parser.add_argument('--dry-run', action='store_true', help='Test run: fill forms but stop before payment (no purchase)')
    parser.add_argument('--reminder-check', action='store_true', help='Only run reminder checks (expiry + vehicle-switch) then exit. For an early-warning cron.')

    args = parser.parse_args()

    # --reminder-check: reminders already ran above, so just exit cleanly
    if args.reminder_check:
        print(bcolors.OKCYAN + "Reminder check complete. Exiting." + bcolors.ENDC)
        sys.exit(0)

    # When no --vehicle is given in headless mode, fall back to the default from settings
    if args.vehicle is None and args.headless:
        args.vehicle = resolve_default_vehicle_index()
        print(bcolors.OKCYAN + f"Headless mode: using default vehicle index {args.vehicle} from settings" + bcolors.ENDC)
    # Single-card setup: default to index 0 in headless mode when --card isn't given
    if args.card is None and args.headless:
        args.card = 0
        print(bcolors.OKCYAN + "Headless mode: using card index 0 (only card)" + bcolors.ENDC)

    # Configure headless mode if requested
    if args.headless:
        chrome_options.add_argument("--headless=new")
        chrome_options.add_argument("--no-sandbox")
        chrome_options.add_argument("--disable-dev-shm-usage")
        chrome_options.add_argument("--disable-gpu")
        chrome_options.add_argument("--window-size=1920,1080")
        chrome_options.add_argument("--disable-blink-features=AutomationControlled")
        chrome_options.add_argument("--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")
        print(bcolors.OKCYAN + "Running in headless mode (no browser window)" + bcolors.ENDC)

    # Check if autobuyer is enabled (only for purchase flow, not parse-only or refetch)
    if not args.parse_only and not args.refetch:
        autobuyer_enabled = settings.get("autobuyer", {}).get("enabled", True)
        if not autobuyer_enabled:
            log_event("Autobuyer is disabled in settings. Exiting.", "INFO")
            print(bcolors.WARNING + "Autobuyer is disabled in settings. No permit will be purchased." + bcolors.ENDC)
            print(bcolors.OKCYAN + "To enable, visit the settings page or update config/settings.json" + bcolors.ENDC)
            sys.exit(0)

    # Parse-only mode: just process existing PDF
    if args.parse_only:
        print(bcolors.OKCYAN + "Parse-only mode: Looking for existing permit PDF..." + bcolors.ENDC)

        # Use specified PDF or search for one
        if args.pdf:
            pdf_path = Path(args.pdf)
            if not pdf_path.exists():
                print(bcolors.FAIL + f"Specified PDF not found: {pdf_path}" + bcolors.ENDC)
                sys.exit(1)
            print(bcolors.OKGREEN + f"Using specified PDF: {pdf_path}" + bcolors.ENDC)
        else:
            pdf_path = find_permit_pdf('.')
            if not pdf_path:
                print(bcolors.FAIL + "No permit PDF found in current directory." + bcolors.ENDC)
                print(bcolors.WARNING + "Use --pdf to specify a PDF file path" + bcolors.ENDC)
                sys.exit(1)
            print(bcolors.OKGREEN + f"Found PDF: {pdf_path}" + bcolors.ENDC)

        # Extract and parse
        text = extract_text_from_pdf(pdf_path)
        permit_data = parse_permit_data(text)

        # Display extracted data
        print(bcolors.OKCYAN + "\nExtracted permit data:" + bcolors.ENDC)
        for key, value in permit_data.items():
            print(f"  {key}: {value or 'NOT FOUND'}")

        # Create JSON
        if all(permit_data.values()):
            json_path = Path('permit.json')
            create_permit_json(permit_data, json_path)

            # Push to GitHub if not disabled
            github_success = True
            if not args.no_github:
                github_success = commit_and_push_to_github(json_path, f"Update permit to {permit_data['permit_number']}")

            # Always archive the PDF so it isn't stranded; GitHub push is just a sync side-effect
            if not github_success and not args.no_github:
                print(bcolors.WARNING + "GitHub push failed - archiving PDF anyway (display sync may be stale)" + bcolors.ENDC)
            archive_pdf(pdf_path, permit_data.get('permit_number'))
        else:
            print(bcolors.FAIL + "\nMissing permit data - JSON not created" + bcolors.ENDC)

        sys.exit(0)

    # Refetch mode: search for existing permit and download PDF
    if args.refetch:
        print(bcolors.OKCYAN + "Refetch mode: Searching for existing permit..." + bcolors.ENDC)

        # Try fast API-based refetch first
        pdf_path = None
        vehicle_name = None
        vehicle_plate = None

        if CURL_CFFI_AVAILABLE and args.vehicle is not None:
            print(bcolors.OKCYAN + "Trying fast API refetch..." + bcolors.ENDC)
            api_result = refetch_permit_api(
                vehicle_index=args.vehicle,
                card_index=args.card
            )
            if api_result[0] is not None:
                vehicle_name, vehicle_plate, pdf_path = api_result
            else:
                print(bcolors.WARNING + "API refetch failed, falling back to Selenium..." + bcolors.ENDC)

        # Fall back to Selenium if API didn't work
        if pdf_path is None:
            result = refetch_permit(
                vehicle_index=args.vehicle,
                card_index=args.card,
                headless=args.headless
            )

            if result is None or result[0] is None or result[1] is None:
                print(bcolors.FAIL + "Failed to refetch permit." + bcolors.ENDC)
                sys.exit(1)

            vehicle_name, vehicle_plate = result
            pdf_path = find_permit_pdf('.')

        # Process the downloaded PDF
        print(bcolors.OKCYAN + "\n\n=== Processing Permit PDF ===" + bcolors.ENDC)

        if pdf_path:
            print(bcolors.OKGREEN + f"Found PDF: {pdf_path}" + bcolors.ENDC)

            # Extract text and parse
            text = extract_text_from_pdf(pdf_path)
            permit_data = parse_permit_data(text)

            # Display extracted data
            print(bcolors.OKCYAN + "\nExtracted permit data:" + bcolors.ENDC)
            for key, value in permit_data.items():
                status = bcolors.OKGREEN if value else bcolors.FAIL
                print(f"  {status}{key}: {value or 'NOT FOUND'}{bcolors.ENDC}")

            # Create permit.json if we got all data
            if all(permit_data.values()):
                json_path = Path('permit.json')
                create_permit_json(permit_data, json_path)

                # Push to GitHub if not disabled
                github_success = True
                if not args.no_github:
                    print(bcolors.OKCYAN + "\nPushing to GitHub..." + bcolors.ENDC)
                    github_success = commit_and_push_to_github(json_path, f"Update permit to {permit_data['permit_number']}")

                if not github_success and not args.no_github:
                    print(bcolors.WARNING + "GitHub push failed - archiving PDF anyway (display sync may be stale)" + bcolors.ENDC)
                archive_pdf(pdf_path, permit_data.get('permit_number'))

                print(bcolors.OKGREEN + bcolors.UNDERLINE + "\n\nDone (refetch)" + bcolors.ENDC)
            else:
                print(bcolors.WARNING + "\nWarning: Some permit data could not be extracted. JSON file may be incomplete." + bcolors.ENDC)
                sys.exit(1)
        else:
            print(bcolors.FAIL + "No permit PDF found. Refetch failed." + bcolors.ENDC)
            sys.exit(1)

        sys.exit(0)

    # Interactive mode: ask user what they want to do
    # In headless mode, skip interactive menu and default to buy
    if args.headless:
        action = 1  # Auto-select buy in headless mode
    elif args.vehicle is None:
        print("\n" + bcolors.WARNING + "What would you like to do?" + bcolors.ENDC)
        print(f"{bcolors.WARNING}1. {bcolors.OKCYAN}Buy new parking permit{bcolors.ENDC}")
        print(f"{bcolors.WARNING}2. {bcolors.OKCYAN}Refetch existing permit{bcolors.ENDC}")

        while True:
            try:
                action = int(input(bcolors.OKGREEN + "\nEnter your choice: " + bcolors.ENDC))
                if action == 1:
                    break  # Continue to normal buy flow
                elif action == 2:
                    # Switch to refetch mode
                    result = refetch_permit(
                        vehicle_index=args.vehicle,
                        card_index=args.card,
                        headless=args.headless
                    )

                    if result is None or result[0] is None or result[1] is None:
                        print(bcolors.FAIL + "Failed to refetch permit." + bcolors.ENDC)
                        sys.exit(1)

                    vehicle_name, vehicle_plate = result

                    # Process the downloaded PDF
                    print(bcolors.OKCYAN + "\n\n=== Processing Permit PDF ===" + bcolors.ENDC)
                    pdf_path = find_permit_pdf('.')

                    if pdf_path:
                        print(bcolors.OKGREEN + f"Found PDF: {pdf_path}" + bcolors.ENDC)
                        text = extract_text_from_pdf(pdf_path)
                        permit_data = parse_permit_data(text)

                        print(bcolors.OKCYAN + "\nExtracted permit data:" + bcolors.ENDC)
                        for key, value in permit_data.items():
                            status = bcolors.OKGREEN if value else bcolors.FAIL
                            print(f"  {status}{key}: {value or 'NOT FOUND'}{bcolors.ENDC}")

                        if all(permit_data.values()):
                            json_path = Path('permit.json')
                            create_permit_json(permit_data, json_path)

                            github_success = True
                            if not args.no_github:
                                print(bcolors.OKCYAN + "\nPushing to GitHub..." + bcolors.ENDC)
                                github_success = commit_and_push_to_github(json_path, f"Update permit to {permit_data['permit_number']}")

                            if not github_success and not args.no_github:
                                print(bcolors.WARNING + "GitHub push failed - archiving PDF anyway (display sync may be stale)" + bcolors.ENDC)
                            archive_pdf(pdf_path, permit_data.get('permit_number'))

                            print(bcolors.OKGREEN + bcolors.UNDERLINE + "\n\nDone (refetch)" + bcolors.ENDC)
                        else:
                            print(bcolors.FAIL + "\nMissing permit data" + bcolors.ENDC)
                    else:
                        print(bcolors.FAIL + "No permit PDF found. Refetch failed." + bcolors.ENDC)

                    sys.exit(0)
                else:
                    print(bcolors.FAIL + "Please enter 1 or 2" + bcolors.ENDC)
            except ValueError:
                print(bcolors.WARNING + "Please enter a number" + bcolors.ENDC)

    # Normal mode: buy permit
    result = get_parking_pass(
        vehicle_index=args.vehicle,
        card_index=args.card,
        dry_run=args.dry_run,
        headless=args.headless
    )

    # Check for special return values first
    if result and result[0] == "EXISTS":
        # Permit already exists - not an error, just exit cleanly
        sys.exit(0)

    if result and result[0] == "PAYMENT_DECLINED":
        # Payment was declined
        error_details = result[1] if result[1] else "Unknown payment error"
        print(bcolors.FAIL + f"Payment declined: {error_details}" + bcolors.ENDC)

        # Find the most recent payment_declined screenshot
        screenshot_path = None
        screenshot_dir = Path('error_screenshots')
        if screenshot_dir.exists():
            screenshots = list(screenshot_dir.glob("payment_declined_*.png"))
            if screenshots:
                screenshot_path = max(screenshots, key=lambda f: f.stat().st_mtime)

        if is_notification_enabled('purchase_failed'):
            send_email_notification(
                subject="Parking Permit PAYMENT DECLINED",
                body=f"Payment was declined when trying to purchase parking permit.\n\nPlease try again manually or use a different card.",
                is_error=True,
                html_body=build_error_email_html(
                    "Payment Declined",
                    "Payment was declined when trying to purchase parking permit.<br><br>Please try again manually or use a different card."
                ),
                screenshot_path=screenshot_path
            )
        sys.exit(1)

    if result and result[0] == "DRY_RUN":
        # Dry run completed successfully - now test PDF processing with old permit
        print(bcolors.OKGREEN + "\nDry run complete - no purchase was made." + bcolors.ENDC)
        print(bcolors.OKCYAN + "\nTesting PDF processing with old permit..." + bcolors.ENDC)

        # Find most recent PDF in old_permits folder
        old_permits_dir = Path('old_permits')
        if old_permits_dir.exists():
            old_pdfs = list(old_permits_dir.glob("*.pdf"))
            if old_pdfs:
                test_pdf = max(old_pdfs, key=lambda f: f.stat().st_mtime)
                print(bcolors.OKGREEN + f"Using test PDF: {test_pdf}" + bcolors.ENDC)

                # Extract and parse
                text = extract_text_from_pdf(test_pdf)
                permit_data = parse_permit_data(text)

                # Display extracted data
                print(bcolors.OKCYAN + "\nExtracted permit data:" + bcolors.ENDC)
                for key, value in permit_data.items():
                    status = bcolors.OKGREEN if value else bcolors.FAIL
                    print(f"  {status}{key}: {value or 'NOT FOUND'}{bcolors.ENDC}")

                if all(permit_data.values()):
                    # Create JSON (but mark it as a test)
                    json_path = Path('permit.json')
                    create_permit_json(permit_data, json_path)
                    print(bcolors.WARNING + "\nDRY RUN: permit.json created locally (not pushed to GitHub)" + bcolors.ENDC)
                else:
                    print(bcolors.FAIL + "\nSome permit data could not be extracted from test PDF" + bcolors.ENDC)
            else:
                print(bcolors.WARNING + "No PDFs found in old_permits/ folder" + bcolors.ENDC)
        else:
            print(bcolors.WARNING + "old_permits/ folder not found" + bcolors.ENDC)

        sys.exit(0)

    # Check for general failure
    if result is None or result[1] is None:
        print(bcolors.FAIL + "Failed to get parking pass." + bcolors.ENDC)

        # Find the most recent error screenshot
        screenshot_path = None
        screenshot_dir = Path('error_screenshots')
        if screenshot_dir.exists():
            screenshots = list(screenshot_dir.glob("*.png"))
            if screenshots:
                screenshot_path = max(screenshots, key=lambda f: f.stat().st_mtime)

        if is_notification_enabled('purchase_failed'):
            send_email_notification(
                subject="Parking Permit FAILED",
                body="Failed to purchase parking permit.\n\nCheck the server logs for details.",
                is_error=True,
                html_body=build_error_email_html(
                    "Purchase Failed",
                    "Failed to purchase parking permit. The automation encountered an error before completing the purchase."
                ),
                screenshot_path=screenshot_path
            )
        sys.exit(1)

    vehicle_name, vehicle_plate = result

    # Process the downloaded PDF
    print(bcolors.OKCYAN + "\n\n=== Processing Permit PDF ===" + bcolors.ENDC)
    pdf_path = find_permit_pdf('.')

    if pdf_path:
        print(bcolors.OKGREEN + f"Found PDF: {pdf_path}" + bcolors.ENDC)

        # Extract text and parse (with retry)
        text = extract_text_from_pdf(pdf_path)
        permit_data = parse_permit_data(text)

        # Display extracted data
        print(bcolors.OKCYAN + "\nExtracted permit data:" + bcolors.ENDC)
        for key, value in permit_data.items():
            status = bcolors.OKGREEN if value else bcolors.FAIL
            print(f"  {status}{key}: {value or 'NOT FOUND'}{bcolors.ENDC}")

        # Retry parsing once if it failed
        if not all(permit_data.values()):
            print(bcolors.WARNING + "\nPDF parsing incomplete, retrying..." + bcolors.ENDC)
            time.sleep(2)
            text = extract_text_from_pdf(pdf_path)
            permit_data = parse_permit_data(text)

            print(bcolors.OKCYAN + "\nRetry - Extracted permit data:" + bcolors.ENDC)
            for key, value in permit_data.items():
                status = bcolors.OKGREEN if value else bcolors.FAIL
                print(f"  {status}{key}: {value or 'NOT FOUND'}{bcolors.ENDC}")

        # Create permit.json if we got all data
        if all(permit_data.values()):
            json_path = Path('permit.json')
            create_permit_json(permit_data, json_path)

            # Push to GitHub if not disabled
            github_success = True
            if not args.no_github:
                print(bcolors.OKCYAN + "\nPushing to GitHub..." + bcolors.ENDC)
                github_success = commit_and_push_to_github(json_path, f"Update permit to {permit_data['permit_number']}")

            if not github_success and not args.no_github:
                print(bcolors.WARNING + "GitHub push failed - archiving PDF anyway (display sync may be stale)" + bcolors.ENDC)
            archive_pdf(pdf_path, permit_data.get('permit_number'))

            print(bcolors.OKGREEN + bcolors.UNDERLINE + "\n\nDone" + bcolors.ENDC)

            # Send success email
            if is_notification_enabled('purchase_success'):
                price_change_info = check_price_change(permit_data.get('amount_paid'))
                if price_change_info[0]:
                    # Persist new price so subsequent permits at the same price don't re-alert
                    settings.setdefault("pricing", {})["expected_weekly_price"] = price_change_info[2]
                    save_settings()
                send_email_notification(
                    subject=f"Parking Permit Purchased - {vehicle_plate}",
                    body=f"""Parking permit successfully purchased!

Vehicle: {vehicle_name} ({vehicle_plate})
Permit Number: {permit_data['permit_number']}
Valid From: {permit_data['valid_from']}
Valid To: {permit_data['valid_to']}

GitHub Push: {'Success' if github_success else 'Failed'}
""",
                    html_body=build_success_email_html(
                        vehicle_name, vehicle_plate, permit_data,
                        github_success,
                        price_change_info=price_change_info
                    )
                )
        else:
            print(bcolors.WARNING + "\nWarning: Some permit data could not be extracted." + bcolors.ENDC)
            if is_notification_enabled('purchase_failed'):
                send_email_notification(
                    subject=f"Parking Permit Warning - {vehicle_plate}",
                    body=f"Permit was purchased but PDF parsing failed.\n\nVehicle: {vehicle_name} ({vehicle_plate})\n\nPlease check manually.",
                    is_error=True,
                    html_body=build_error_email_html(
                        "PDF Parsing Failed",
                        "Permit was purchased but the PDF could not be parsed. Please check manually.",
                        f"{vehicle_name} ({vehicle_plate})"
                    )
                )
    else:
        print(bcolors.FAIL + "No permit PDF found." + bcolors.ENDC)

        # Find the most recent error screenshot (if any)
        screenshot_path = None
        screenshot_dir = Path('error_screenshots')
        if screenshot_dir.exists():
            screenshots = list(screenshot_dir.glob("*.png"))
            if screenshots:
                screenshot_path = max(screenshots, key=lambda f: f.stat().st_mtime)

        if is_notification_enabled('purchase_failed'):
            send_email_notification(
                subject=f"Parking Permit Error - {vehicle_plate}",
                body=f"Permit may have been purchased but no PDF was found.\n\nVehicle: {vehicle_name} ({vehicle_plate})\n\nPlease check manually.",
                is_error=True,
                html_body=build_error_email_html(
                    "No PDF Found",
                    "Permit may have been purchased but no PDF was downloaded. Please check manually.",
                    f"{vehicle_name} ({vehicle_plate})"
                ),
                screenshot_path=screenshot_path
            )

    print(bcolors.HEADER + bcolors.UNDERLINE + "\n\nWhy did I waste my life making this...\n\n" + bcolors.ENDC)
    print(bcolors.OKCYAN + "Remember: Parking is temporary, but sarcasm is forever." + bcolors.ENDC)

    # Only prompt for enter in interactive mode
    if not args.headless and sys.stdin.isatty():
        input(bcolors.HEADER + "\nPress enter to exit..." + bcolors.ENDC)