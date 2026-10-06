<?php
session_start();
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once 'db.php';
require_once 'photo_config.php';

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

function fail(string $message, int $code = 200, array $extra = []): void
{
    respond(array_merge(['ok' => false, 'message' => $message], $extra), $code);
}

try {
    /* ---------- 1. ตรวจสิทธิ์ & CSRF ---------- */
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('Method not allowed', 405);
    }
    if (empty($_SESSION['student_code'])) {
        fail('เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่', 401);
    }
    $token = $_POST['csrf'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$token)) {
        fail('คำขอไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่', 403);
    }

    $student_code = (string)$_SESSION['student_code'];

    /* ---------- 2. จำกัดจำนวนครั้งการอัปโหลด ---------- */
    $now = time();
    $attempts = array_filter($_SESSION['upload_attempts'] ?? [], fn($t) => $t > $now - 3600);
    if (!empty($attempts) && max($attempts) > $now - 5) {
        fail('กรุณารอสักครู่แล้วลองใหม่อีกครั้ง', 429);
    }
    $attempts[] = $now;
    $_SESSION['upload_attempts'] = array_values($attempts);
    session_write_close();

    /* ---------- 3. ตรวจสอบและประมวลผลรูปภาพ ---------- */
    if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
        fail('ไม่พบไฟล์รูปภาพ');
    }
    $file = $_FILES['photo'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
            ? 'ไฟล์ใหญ่เกินกำหนด (ไม่เกิน ' . (MAX_FILE_BYTES / 1024 / 1024) . ' MB)'
            : 'อัปโหลดไม่สำเร็จ กรุณาลองใหม่';
        fail($msg);
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        fail('ไฟล์ไม่ถูกต้อง');
    }
    if ($file['size'] > MAX_FILE_BYTES) {
        fail('ไฟล์ใหญ่เกินกำหนด (ไม่เกิน ' . (MAX_FILE_BYTES / 1024 / 1024) . ' MB)');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        fail('รองรับเฉพาะไฟล์ JPG, PNG หรือ WebP');
    }

    $size = @getimagesize($file['tmp_name']);
    if (!$size) {
        fail('เปิดไฟล์ภาพไม่ได้');
    }
    if ($size[0] * $size[1] > MAX_PIXELS) {
        fail('ภาพมีความละเอียดสูงเกินไป');
    }

    $img = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
    if (!$img) {
        fail('เปิดไฟล์ภาพไม่ได้');
    }

    // หมุนภาพตาม EXIF
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $orientation = $exif['Orientation'] ?? 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated) {
                $img = $rotated;
            }
        }
    }

    $w = imagesx($img);
    $h = imagesy($img);
    if (min($w, $h) < MIN_SIDE_PX) {
        fail('ภาพเล็กเกินไป ด้านสั้นสุดต้องไม่ต่ำกว่า ' . MIN_SIDE_PX . ' พิกเซล');
    }
    $ratio = $w / $h;
    if ($ratio < RATIO_MIN || $ratio > RATIO_MAX) {
        fail('สัดส่วนภาพไม่ถูกต้อง ต้องเป็นภาพแนวตั้งหรือเกือบจัตุรัสแบบรูปติดบัตร');
    }

    // ย่อภาพ + บันทึกใหม่เป็น JPEG
    $scale = min(1, OUTPUT_MAX_PX / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $canvas = imagecreatetruecolor($nw, $nh);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);

    ob_start();
    imagejpeg($canvas, null, 85);
    $jpegBytes = (string)ob_get_clean();
    imagedestroy($canvas);

    /* ---------- 4. อัปโหลดขึ้น Cloudinary ---------- */
    $cloudinary_cloud_name = 'dndpjv8gm';
    $cloudinary_api_key    = '878158844658136';
    $cloudinary_api_secret = 'o3AB1G0hnTZ6E4NYLVtG8jxr63k';

    $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '', $student_code) ?: 'student';
    $public_id = $safeCode . '_' . bin2hex(random_bytes(6));
    $timestamp = time();

    $params_to_sign = [
        'folder'    => 'student_photos',
        'public_id' => $public_id,
        'timestamp' => $timestamp,
    ];
    ksort($params_to_sign);

    $to_sign = [];
    foreach ($params_to_sign as $k => $v) {
        $to_sign[] = "{$k}={$v}";
    }
    $signature = sha1(implode('&', $to_sign) . $cloudinary_api_secret);

    $post_fields = [
        'file'      => 'data:image/jpeg;base64,' . base64_encode($jpegBytes),
        'api_key'   => $cloudinary_api_key,
        'timestamp' => $timestamp,
        'signature' => $signature,
        'folder'    => 'student_photos',
        'public_id' => $public_id,
    ];

    $ch = curl_init("https://api.cloudinary.com/v1_1/{$cloudinary_cloud_name}/image/upload");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post_fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log('Cloudinary Request Error: ' . $curl_error);
        fail('อัปโหลดไฟล์ไปที่ Cloudinary ไม่สำเร็จ กรุณาลองใหม่', 500);
    }

    $cloudinary_data = json_decode((string)$response, true);
    if (!isset($cloudinary_data['secure_url'])) {
        error_log('Cloudinary API Error: ' . $response);
        fail('เซิร์ฟเวอร์รับฝากรูปปฏิเสธการเชื่อมต่อ', 500);
    }

    $finalImageUrl = $cloudinary_data['secure_url'];

    /* ---------- 5. บันทึกลงฐานข้อมูลด้วยสถานะ pending แล้วตอบกลับทันที ---------- */
    $status = 'pending';
    $note   = 'อัปโหลดสำเร็จ ระบบกำลังต่อคิวตรวจเครื่องแบบอัตโนมัติ';

    try {
        $stmt = $conn->prepare('SELECT image FROM students WHERE student_code = ? LIMIT 1');
        $stmt->bind_param('s', $student_code);
        $stmt->execute();
        $oldImage = (string)($stmt->get_result()->fetch_assoc()['image'] ?? '');
        $stmt->close();

        $stmt = $conn->prepare('UPDATE students SET image = ?, image_status = ?, image_note = ?, image_checked_at = NOW() WHERE student_code = ?');
        $stmt->bind_param('ssss', $finalImageUrl, $status, $note, $student_code);
        $stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        error_log('photo upload DB: ' . $e->getMessage());
        fail('บันทึกข้อมูลลงฐานข้อมูลไม่สำเร็จ กรุณาลองใหม่', 500);
    }

    // ลบไฟล์เก่ากรณีเคยมียู่ในเครื่อง
    if ($oldImage !== '' && strpos($oldImage, 'http') === false) {
        $oldFile = __DIR__ . '/' . $oldImage;
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    respond([
        'ok'        => true,
        'status'    => 'pending',
        'message'   => 'อัปโหลดรูปภาพเรียบร้อยแล้ว ระบบกำลังเข้าคิวตรวจเครื่องแบบอัตโนมัติ',
        'image_url' => $finalImageUrl . '?v=' . $now,
        'checks'    => [],
    ]);

} catch (\Throwable $e) {
    error_log("Photo Upload System Error: " . $e->getMessage() . " on line " . $e->getLine());
    fail('เกิดข้อผิดพลาดของระบบ: ' . $e->getMessage(), 500);
}
