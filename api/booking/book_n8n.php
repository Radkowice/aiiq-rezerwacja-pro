<?php
require_once __DIR__ . '/../helpers/session.php';
start_secure_session();

date_default_timezone_set('Europe/Warsaw');

function getCalendarAvailabilityWindow(): array
{
    $today = new DateTime('today');
    $day = (int)$today->format('d');

    $startDate = clone $today;

    $currentMonthStart = new DateTime($today->format('Y-m-01'));
    $currentMonthEnd = new DateTime($today->format('Y-m-t'));

    if ($day >= 20) {
        $nextMonthStart = (clone $currentMonthStart)->modify('first day of next month');
        $nextMonthEnd = (clone $nextMonthStart)->modify('last day of this month');

        return [
            'min_date' => $startDate->format('Y-m-d'),
            'max_date' => $nextMonthEnd->format('Y-m-d'),
        ];
    }

    return [
        'min_date' => $startDate->format('Y-m-d'),
        'max_date' => $currentMonthEnd->format('Y-m-d'),
    ];
}

function getConsultationHours(): array
{
    return ['10:00','11:00','12:00'];
}

function isDateAllowed(string $date): bool
{
    $window = getCalendarAvailabilityWindow();
    return $date >= $window['min_date'] && $date <= $window['max_date'];
}

function isTimeAllowed(string $time): bool
{
    return in_array($time, getConsultationHours(), true);
}

require __DIR__ . '/PHPMailer/src/Exception.php';
require __DIR__ . '/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://kalendarz.fioncore.cloud');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

$apiToken = getenv('AIIQ_API_TOKEN');
if (!$apiToken) {
    http_response_code(500);
    echo json_encode(['error' => 'Brak konfiguracji serwera']);
    exit;
}

$clientToken = $_SERVER['HTTP_X_API_KEY'] ?? '';

if ($clientToken !== $apiToken) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Brak dostępu'
    ]);
    exit;
}
function removeAttemptsBlock($content) {
    return preg_replace(
        '/\$attemptFile\s*=.*?Zbanowano IP.*?exit;\s*}/s',
        '',
        $content
    );
}
function saveAttempts($file, $data) {
    file_put_contents($file, json_encode($data));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'status' => 'error',
        'message' => 'Method not allowed'
    ]);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$blacklistFile = __DIR__ . '/../data/blacklist.json';

if (!file_exists($blacklistFile)) {
    file_put_contents($blacklistFile, json_encode([]));
}

$blacklist = json_decode(file_get_contents($blacklistFile), true);
if (!is_array($blacklist)) {
    $blacklist = [];
}

if (in_array($ip, $blacklist, true)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'IP banned'
    ]);
    exit;
}


$logFile = __DIR__ . '/../data/attempts.log';
$now = date('Y-m-d H:i:s');

file_put_contents($logFile, "[$now] IP: $ip\n", FILE_APPEND);
$data = json_decode(file_get_contents("php://input"), true);


/**
 * RATE LIMIT
 */

$rateFile = __DIR__ . '/../data/rate_limit_book.json';

if (!file_exists($rateFile)) {
    file_put_contents($rateFile, json_encode([]));
}

$rateData = json_decode(file_get_contents($rateFile), true);
if (!is_array($rateData)) {
    $rateData = [];
}

$now = time();
$limit = 3;
$window = 60;

if (!isset($rateData[$ip]) || !is_array($rateData[$ip])) {
    $rateData[$ip] = [];
}

$rateData[$ip] = array_values(array_filter($rateData[$ip], function ($t) use ($now, $window) {
    return ($now - (int)$t) < $window;
}));

if (count($rateData[$ip]) >= $limit) {
    http_response_code(429);
    echo json_encode([
        'status' => 'error',
        'message' => 'Za dużo prób. Spróbuj za chwilę.'
    ]);
    exit;
}

$rateData[$ip][] = $now;
file_put_contents($rateFile, json_encode($rateData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX); 

/**
 * INPUT
 */
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if (
    !$input ||
    empty($input['name']) ||
    empty($input['email']) ||
    empty($input['date']) ||
    empty($input['time'])
) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Brak wymaganych danych'
    ]);
    exit;
}

$name  = trim($input['name'] ?? '');
$email = trim($input['email'] ?? '');
$phone = trim($input['phone'] ?? '');
$date  = trim($input['date'] ?? '');
$time  = trim($input['time'] ?? '');
$note  = trim($input['note'] ?? '');

$phone = preg_replace('/\D+/', '', $phone);

if (!preg_match('/^[0-9]{9}$/', $phone)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Nieprawidłowy numer telefonu (wymagane 9 cyfr)'
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Nieprawidłowy adres e-mail'
    ]);
    exit;
}

if (mb_strlen($name) > 80) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Imię i nazwisko jest za długie'
    ]);
    exit;
}

if (mb_strlen($note) > 500) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Wiadomość jest za długa'
    ]);
    exit;
}

// WALIDACJA DATY
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Nieprawidłowa data'
    ]);
    exit;
}

// WALIDACJA GODZINY
if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Nieprawidłowa godzina'
    ]);
    exit;
}

// DATA NIE W PRZESZŁOŚCI
if (strtotime($date) < strtotime(date('Y-m-d'))) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Nie można rezerwować w przeszłości'
    ]);
    exit;
}

// DOZWOLONE GODZINY
$allowedTimes = ['10:00','11:00','12:00'];

if (!in_array($time, $allowedTimes, true)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Nieprawidłowa godzina'
    ]);
    exit;
}

// FORMAT DATY YYYY-MM-DD
$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Nieprawidłowa data'
    ]);
    exit;
}

// DATA NIE W PRZESZŁOŚCI
$today = date('Y-m-d');
if ($date < $today) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Nie można rezerwować w przeszłości'
    ]);
    exit;
}

// ZAKRES KALENDARZA (miesiąc + po 20 kolejny)
if (!isDateAllowed($date)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Data poza zakresem dostępności kalendarza'
    ]);
    exit;
}

// GODZINY KONSULTACJI
if (!isTimeAllowed($time)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Konsultacje tylko w godzinach 09:00–16:00'
    ]);
    exit;
}

$name = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$note = htmlspecialchars($note, ENT_QUOTES, 'UTF-8');

/**
 * FILES
 */
$file = __DIR__ . '/../data/bookings.json';
$blockedFile = __DIR__ . '/../data/blocked.json';

if (!file_exists($file)) {
    file_put_contents($file, json_encode([]));
}

if (!file_exists($blockedFile)) {
    file_put_contents($blockedFile, json_encode([
        'blockedDates' => [],
        'blockedTimes' => []
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

$bookings = json_decode(file_get_contents($file), true);
if (!is_array($bookings)) {
    $bookings = [];
}
foreach ($bookings as $existing) {
    if (
        isset($existing['date'], $existing['time']) &&
        $existing['date'] === $input['date'] &&
        $existing['time'] === $input['time']
    ) {
        http_response_code(409);
        echo json_encode([
            'status' => 'error',
            'message' => 'Wybrany termin jest już zajęty.'
        ]);
        exit;
    }
}
$bookings = json_decode(file_get_contents($file), true);
if (!is_array($bookings)) {
    $bookings = [];
}

$blocked = json_decode(file_get_contents($blockedFile), true);

if (!is_array($blocked)) {
    $blocked = ['blockedDates' => [], 'blockedTimes' => []];
}

$blockedForDate = $blocked['blockedTimes'][$date] ?? [];

// cały dzień
if (in_array('all', $blockedForDate, true)) {
    http_response_code(409);
    echo json_encode([
        'status' => 'error',
        'message' => 'Ten dzień jest niedostępny.'
    ]);
    exit;
}

// konkretna godzina
if (in_array($time, $blockedForDate, true)) {
    http_response_code(409);
    echo json_encode([
        'status' => 'error',
        'message' => 'Ta godzina jest już zajęta.'
    ]);
    exit;
}
$bookings[] = [
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'date' => $date,
    'time' => $time,
    'note' => $note,
    'created_at' => date('Y-m-d H:i:s')
];

file_put_contents($file, json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

$blocked = json_decode(file_get_contents($blockedFile), true);
if (!is_array($blocked)) {
    $blocked = ['blockedDates' => [], 'blockedTimes' => []];
}

if (!isset($blocked['blockedTimes'][$date]) || !is_array($blocked['blockedTimes'][$date])) {
    $blocked['blockedTimes'][$date] = [];
}

if (!in_array($time, $blocked['blockedTimes'][$date], true)) {
    $blocked['blockedTimes'][$date][] = $time;
}

file_put_contents($blockedFile, json_encode($blocked, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

/**
 * MAIL
 */
$mailSent = false;
$mailError = '';

try {
    $mail = new PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = 'hosting2411400.online.pro';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'kontakt@ai-iq.pl';
    $mail->Password   = getenv('MAIL_PASSWORD') ?: '';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';

    if ($mail->Password === '') {
        throw new Exception('Brak MAIL_PASSWORD w environment');
    }

    // MAIL DO KLIENTA
    $mail->setFrom('kontakt@ai-iq.pl', 'AI-IQ Automatyzacje Agenci AI');
    $mail->addAddress($email, $name);
    $mail->isHTML(true);
    $mail->Subject = 'Potwierdzenie rezerwacji konsultacji AI-IQ';

    $mail->Body = '
    <div style="margin:0;padding:0;background:#f4f7fb;">
      <div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">
        <div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">
          <div style="font-size:42px;line-height:1;margin-bottom:12px;">📅</div>
          <h1 style="margin:0;font-size:28px;">Rezerwacja potwierdzona</h1>
          <p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">Dziękujemy za umówienie konsultacji z AI-IQ</p>
        </div>

        <div style="padding:32px 24px;">
          <p style="margin-top:0;font-size:16px;">Cześć <strong>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</strong>,</p>
          <p style="font-size:15px;line-height:1.6;">
            Twoja konsultacja została poprawnie zapisana. Poniżej znajdziesz szczegóły rezerwacji:
          </p>

          <div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">
            <p style="margin:0 0 12px 0;font-size:16px;"><strong>👤 Imię:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="margin:0 0 12px 0;font-size:16px;"><strong>📧 E-mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="margin:0 0 12px 0;font-size:16px;"><strong>📆 Data:</strong> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="margin:0;font-size:16px;"><strong>🕒 Godzina:</strong> ' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</p>
          </div>

          <p style="font-size:14px;line-height:1.6;color:#4f6478;">
            W razie pytań po prostu odpowiedz na tę wiadomość.
          </p>
        </div>

        <div style="background:#eef3f8;padding:18px 24px;font-size:12px;color:#607284;text-align:center;">
          AI-IQ • Inteligentne automatyzacje i konsultacje
        </div>
      </div>
    </div>';

    $mail->AltBody =
        "Rezerwacja potwierdzona\n\n" .
        "Imię: {$name}\n" .
        "E-mail: {$email}\n" .
        "Data: {$date}\n" .
        "Godzina: {$time}\n\n";

    $mail->send();
    $mailSent = true;

    // MAIL DO ADMINA
    $mailAdmin = new PHPMailer(true);
    $mailAdmin->isSMTP();
    $mailAdmin->Host       = 'hosting2411400.online.pro';
    $mailAdmin->SMTPAuth   = true;
    $mailAdmin->Username   = 'kontakt@ai-iq.pl';
    $mailAdmin->Password   = getenv('MAIL_PASSWORD') ?: '';
    $mailAdmin->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailAdmin->Port       = 587;
    $mailAdmin->CharSet    = 'UTF-8';

    $mailAdmin->setFrom('kontakt@ai-iq.pl', 'AI-IQ Kalendarz');
    $mailAdmin->addAddress('bok@shopfion.pl');
    $mailAdmin->isHTML(true);
    $mailAdmin->Subject = 'Nowa rezerwacja konsultacji – ' . $date . ' ' . $time;

    $mailAdmin->Body = '
    <div style="margin:0;padding:0;background:#f4f7fb;">
      <div style="max-width:640px;margin:0 auto;background:#ffffff;font-family:Arial,sans-serif;color:#17324d;">
        <div style="background:linear-gradient(135deg,#071b2d,#0f2d47);padding:32px 24px;text-align:center;color:#ffffff;">
          <div style="font-size:42px;line-height:1;margin-bottom:12px;">📩</div>
          <h1 style="margin:0;font-size:28px;">Nowa rezerwacja konsultacji</h1>
          <p style="margin:12px 0 0 0;font-size:16px;opacity:0.95;">W systemie pojawiła się nowa rezerwacja.</p>
        </div>

        <div style="padding:32px 24px;">
          <div style="background:#f7fafc;border:1px solid #d8e3ee;border-radius:14px;padding:20px;margin:24px 0;">
            <p style="margin:0 0 12px 0;font-size:16px;"><strong>👤 Imię:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="margin:0 0 12px 0;font-size:16px;"><strong>📧 E-mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="margin:0 0 12px 0;font-size:16px;"><strong>📞 Telefon:</strong> ' . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="margin:0 0 12px 0;font-size:16px;"><strong>📆 Data:</strong> ' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="margin:0 0 12px 0;font-size:16px;"><strong>🕒 Godzina:</strong> ' . htmlspecialchars($time, ENT_QUOTES, 'UTF-8') . '</p>
            <p style="margin:0;font-size:16px;"><strong>📝 Wiadomość:</strong><br>' . nl2br(htmlspecialchars($note, ENT_QUOTES, 'UTF-8')) . '</p>
          </div>
        </div>

        <div style="background:#eef3f8;padding:18px 24px;font-size:12px;color:#607284;text-align:center;">
          AI-IQ • Powiadomienie systemowe
        </div>
      </div>
    </div>';

    $mailAdmin->AltBody =
        "Nowa rezerwacja konsultacji\n\n" .
        "Imię: {$name}\n" .
        "E-mail: {$email}\n" .
        "Telefon: {$phone}\n" .
        "Data: {$date}\n" .
        "Godzina: {$time}\n" .
        "Wiadomość: {$note}\n";

    $mailAdmin->send();

} catch (Exception $e) {
    $mailError = $e->getMessage();
}


echo json_encode([
    'status' => 'success',
    'message' => 'Rezerwacja zapisana',
    'mail_sent' => $mailSent
]);
exit;
