<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function escape_html($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function now_ist(): string
{
    $dt = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    return $dt->format('d/m/Y, H:i:s');
}

function generate_submission_id(): string
{
    $dt = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $y = $dt->format('Y');
    $M = $dt->format('m');
    $d = $dt->format('d');
    $h = $dt->format('H');
    $m = $dt->format('i');
    $s = $dt->format('s');
    $r = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    return "{$y}{$M}{$d}{$h}{$m}{$s}-{$r}";
}

function generate_support_ticket_id(): string
{
    $pdo = db();
    $dt = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
    $y = substr($dt->format('Y'), -2);
    $prefix = $y . $dt->format('mdHis');

    $rows = $pdo->query('SELECT id FROM support')->fetchAll(PDO::FETCH_COLUMN);
    $maxSeq = 0;
    foreach ($rows as $rid) {
        $parts = explode('-', (string) $rid);
        $digits = $parts[1] ?? '';
        if (is_numeric($digits)) {
            $maxSeq = max($maxSeq, (int) $digits);
        }
    }
    $seq = str_pad((string) min($maxSeq + 1, 999), 3, '0', STR_PAD_LEFT);
    return "{$prefix}-{$seq}";
}

function generate_rma_number(): string
{
    $pdo = db();
    $stmt = $pdo->query('SELECT MAX(rma_number) AS m FROM requests');
    $raw = (string) ($stmt->fetch()['m'] ?? '');
    $digits = preg_replace('/\D/', '', $raw);
    $max = $digits !== '' && $digits !== '0' ? (int) $digits : 0;
    return str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
}

function parse_ist($str): int
{
    if (!$str) {
        return 0;
    }
    $s = (string) $str;
    $m = [];
    if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?:, (\d{1,2}):(\d{1,2}):(\d{1,2}))?/', $s, $m)) {
        return 0;
    }
    $day = (int) $m[1];
    $month = (int) $m[2];
    $year = (int) $m[3];
    $h = (int) ($m[4] ?? 0);
    $i = (int) ($m[5] ?? 0);
    $sec = (int) ($m[6] ?? 0);
    if (!$day || !$month || !$year) {
        return 0;
    }
    try {
        return DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $h, $i, $sec),
            new DateTimeZone('Asia/Kolkata'),
        )?->getTimestamp() ?? 0;
    } catch (Throwable) {
        return 0;
    }
}

function is_pending_flag($value): bool
{
    return trim((string) ($value ?? '')) !== '';
}

const VALID_STATUSES = ['fresh', 'pending', 'disapproved', 'closed'];

const IP_FIELDS = [
    'ipAdminNote',
    'ipReceivedDate',
    'ipDateOfInvestigation',
    'ipWarranty',
    'ipInvestigationDetails',
    'ipRepairDetails',
    'ipRepairedBy',
    'ipEstimateDate',
    'ipEstimateNumber',
    'ipEstimateAmount',
    'ipPoNoAndDate',
    'ipPoReceivedDate',
    'ipOemRmaNo',
    'ipDateOfSent',
    'ipPlatformModule',
    'ipOemQuotation',
    'ipDateOfReceivingFromOem',
    'ipDcNoAndDate',
    'ipDispatchedDate',
    'ipLrNo',
    'ipReasonForWaiting',
    'ipDeliveredDate',
    'ipAckDateFromWh',
    'ipCourierName',
    'ipRemark',
];

function reconstruct_processing_details(array $row): array
{
    $details = [];
    foreach (IP_FIELDS as $field) {
        $details[$field] = $row[$field] ?? '';
    }
    return $details;
}

function format_images(array $images): array
{
    return array_map(fn ($img) => [
        'originalName' => $img['original_name'] ?? '',
        'fileName'     => $img['file_name'] ?? '',
        'path'         => $img['path'] ?? '',
        'mimeType'     => $img['mime_type'] ?? '',
        'size'         => (int) ($img['size'] ?? 0),
    ], $images);
}

function fetch_request_images(string $id): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT original_name, file_name, path, mime_type, size FROM request_images WHERE request_id = ?');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

function fetch_support_images(string $id): array
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT original_name, file_name, path, mime_type, size FROM support_images WHERE support_id = ?');
    $stmt->execute([$id]);
    return $stmt->fetchAll();
}

function format_request(array $row): array
{
    $row = row_to_camel($row);
    $images = fetch_request_images($row['id']);
    $row['processingDetails'] = reconstruct_processing_details($row);
    $row['images'] = format_images($images);
    return $row;
}

function format_support(array $row): array
{
    $row = row_to_camel($row);
    $row['images'] = format_images(fetch_support_images($row['id']));
    return $row;
}

function camelize_key(string $key): string
{
    return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
}

function snake_key(string $key): string
{
    return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
}

function row_to_camel(array $row): array
{
    $out = [];
    foreach ($row as $k => $v) {
        $out[camelize_key($k)] = $v;
    }
    return $out;
}

function json_response($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 500): never
{
    json_response(['message' => $message], $status);
}

function body_json(): array
{
    $raw = file_get_contents('php://input');
    $data = $raw ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

/**
 * PHP does not populate $_POST/$_FILES for multipart PUT requests. Parse the raw
 * body manually so support updates sent as FormData behave like the Node version.
 */
function parse_multipart_body(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (preg_match('/boundary=(.+)$/i', $contentType, $m)) {
        $boundary = trim($m[1], '"');
    } else {
        return ['post' => [], 'files' => []];
    }

    $raw = file_get_contents('php://input') ?: '';
    $post = [];
    $files = [];

    $parts = preg_split('/--' . preg_quote($boundary, '/') . '(?:\r?\n|--)/', $raw) ?: [];
    foreach ($parts as $part) {
        $part = ltrim($part, "\r\n");
        if ($part === '' || $part === "--") {
            continue;
        }

        $sep = strpos($part, "\r\n\r\n");
        if ($sep === false) {
            continue;
        }
        $headersBlock = substr($part, 0, $sep);
        $value = substr($part, $sep + 4);
        if (substr($value, -2) === "\r\n") {
            $value = substr($value, 0, -2);
        }

        $headers = [];
        foreach (explode("\r\n", $headersBlock) as $line) {
            $c = strpos($line, ':');
            if ($c !== false) {
                $headers[strtolower(trim(substr($line, 0, $c)))] = trim(substr($line, $c + 1));
            }
        }

        if (!isset($headers['content-disposition'])) {
            continue;
        }

        $disposition = $headers['content-disposition'];
        if (preg_match('/name="([^"]*)"/', $disposition, $nm) === 0) {
            continue;
        }
        $fieldName = $nm[1];

        if (str_contains($disposition, 'filename=')) {
            preg_match('/filename="([^"]*)"/', $disposition, $fn);
            $originalName = basename($fn[1] ?? 'file');
            $mime = $headers['content-type'] ?? 'application/octet-stream';

            $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
            $fileName = time() . random_int(100, 999) . '-' . $safe;
            $dest = __DIR__ . '/uploads/' . $fileName;
            file_put_contents($dest, $value);

            $files[$fieldName][] = [
                'originalName' => $originalName,
                'fileName'     => $fileName,
                'path'         => '/uploads/' . $fileName,
                'mimeType'     => $mime,
                'size'         => strlen($value),
            ];
        } else {
            $post[$fieldName] = $value;
        }
    }

    return ['post' => $post, 'files' => $files];
}

function stored_put_files(): array
{
    $fields = parse_multipart_body();
    $files = $fields['files']['images'] ?? [];
    $out = [];
    foreach ($files as $file) {
        $out[] = [
            'originalName' => $file['originalName'],
            'fileName'     => $file['fileName'],
            'path'         => $file['path'],
            'mimeType'     => $file['mimeType'],
            'size'         => $file['size'],
        ];
    }
    return $out;
}

function stored_images_from_files(): array
{
    $out = [];
    if (empty($_FILES['images'])) {
        return $out;
    }

    $files = $_FILES['images'];

    $names  = is_array($files['name']) ? $files['name'] : [$files['name']];
    $types  = is_array($files['type']) ? $files['type'] : [$files['type']];
    $tmp    = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
    $errors = is_array($files['error']) ? $files['error'] : [$files['error']];
    $sizes  = is_array($files['size']) ? $files['size'] : [$files['size']];

    for ($i = 0, $count = count($names); $i < $count; $i++) {
        $name = $names[$i];
        $type = $types[$i];
        $tmpF = $tmp[$i];
        $err  = $errors[$i];
        $size = (int) $sizes[$i];

        if ($err !== UPLOAD_ERR_OK || !is_file($tmpF)) {
            continue;
        }

        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
        $filename = time() . random_int(100, 999) . '-' . $safe;
        $dest = __DIR__ . '/uploads/' . $filename;
        if (!move_uploaded_file($tmpF, $dest)) {
            continue;
        }

        $out[] = [
            'originalName' => basename($name),
            'fileName'     => $filename,
            'path'         => '/uploads/' . $filename,
            'mimeType'     => $type,
            'size'         => $size,
        ];
    }

    return $out;
}

function request_images_sql(array $images): array
{
    return [
        'original_name' => $images['originalName'] ?? '',
        'file_name'     => $images['fileName'] ?? '',
        'path'          => $images['path'] ?? '',
        'mime_type'     => $images['mimeType'] ?? '',
        'size'          => (int) ($images['size'] ?? 0),
    ];
}