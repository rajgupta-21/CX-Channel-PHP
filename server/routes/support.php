<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../emailTemplates.php';

function support_list(): never
{
    $pdo = db();
    $email = trim((string) ($_GET['email'] ?? ''));

    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT * FROM support WHERE email = ?');
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll();
    } else {
        $rows = $pdo->query('SELECT * FROM support')->fetchAll();
    }

    $formatted = array_map('format_support', $rows);
    usort($formatted, fn ($a, $b) =>
        (int) parse_ist($b['createdAt'] ?? '') - (int) parse_ist($a['createdAt'] ?? ''));

    json_response(array_values($formatted));
}

function support_stats(): never
{
    $pdo = db();
    $support = $pdo->query('SELECT status, pending_for_customer, pending_for_fastech, pending_for_oem FROM support')->fetchAll();

    $norm = fn ($s) => strtolower((string) $s);
    $count = function ($s) use ($support, $norm) {
        return count(array_filter($support, fn ($r) => $norm($r['status']) === $s));
    };

    json_response([
        'total'             => count($support),
        'open'              => $count('open'),
        'closed'            => $count('closed'),
        'pendingFromCustomer' => count(array_filter($support, fn ($r) => is_pending_flag($r['pending_for_customer']))),
        'pendingFromFastech'  => count(array_filter($support, fn ($r) => is_pending_flag($r['pending_for_fastech']))),
        'pendingFromOem'      => count(array_filter($support, fn ($r) => is_pending_flag($r['pending_for_oem']))),
    ]);
}

function support_detail(): never
{
    $pdo = db();
    $request = find_support($pdo, route_param('id'));
    if (!$request) {
        json_response(['message' => 'Support request not found.'], 404);
    }
    json_response(['request' => format_support($request)]);
}

function support_create(): never
{
    $pdo = db();
    $body = $_POST;
    $required = ['name', 'email', 'oem', 'product'];
    $missing = array_values(array_filter($required, fn ($k) => trim((string) ($body[$k] ?? '')) === ''));
    if ($missing) {
        json_response(['message' => 'Missing required fields: ' . implode(', ', $missing) . '.'], 400);
    }

    $uploads = stored_images_from_files();
    $now = now_ist();
    $id = generate_support_ticket_id();

    $stmt = $pdo->prepare(
        'INSERT INTO support (
            id, rma_number, subject, priority, oem, service_type, product, description, name, email,
            phone, company, designation, location, software_version, serial_single, serial_base_unit, serial_rf_cable,
            serial_antenna, billing_address, return_address, cal_certificate_address, additional_info,
            status, assigned_team, assigned_name, approval_status, customer_mail_status, disapproval_reason,
            internal_note, customer_feedback, pending_for_customer, pending_for_fastech, pending_for_oem,
            created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )',
    );

    $stmt->execute([
        $id,
        '',
        (string) ($body['subject'] ?? ''),
        (string) ($body['priority'] ?? 'Medium'),
        (string) ($body['oem'] ?? ''),
        'Support',
        (string) ($body['product'] ?? ''),
        (string) ($body['description'] ?? ''),
        (string) ($body['name'] ?? ''),
        (string) ($body['email'] ?? ''),
        (string) ($body['phone'] ?? ''),
        (string) ($body['company'] ?? ''),
        (string) ($body['designation'] ?? ''),
        (string) ($body['location'] ?? ''),
        (string) ($body['softwareVersion'] ?? ''),
        (string) ($body['serialSingle'] ?? ''),
        (string) ($body['serialBaseUnit'] ?? ''),
        (string) ($body['serialRfCable'] ?? ''),
        (string) ($body['serialAntenna'] ?? ''),
        (string) ($body['billingAddress'] ?? ''),
        (string) ($body['returnAddress'] ?? ''),
        (string) ($body['calCertificateAddress'] ?? ''),
        (string) ($body['additionalInfo'] ?? ''),
        'Open',
        '', '', '', '', '', '', '', '', '', '',
        $now,
        $now,
    ]);

    foreach ($uploads as $img) {
        $data = request_images_sql($img);
        $stmt = $pdo->prepare(
            'INSERT INTO support_images (support_id, original_name, file_name, path, mime_type, size)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([$id, $data['original_name'], $data['file_name'], $data['path'], $data['mime_type'], $data['size']]);
    }

    $record = format_support(find_support($pdo, $id));

    $emails = ['team' => ['sent' => false], 'customer' => ['sent' => false]];
    $teamEmail = env('TEAM_EMAIL');

    try {
        $mail = build_admin_support_submission_email($record);
        send_mail([
            'to'      => $teamEmail,
            'subject' => $mail['subject'],
            'text'    => $mail['text'],
            'html'    => $mail['html'],
            'replyTo' => $record['email'],
        ]);
        $emails['team']['sent'] = true;
    } catch (Throwable $mailErr) {
        $emails['team']['error'] = $mailErr->getMessage();
    }

    if (!empty($record['email'])) {
        try {
            $mail = build_support_submission_email($record);
            send_mail([
                'to'      => $record['email'],
                'subject' => $mail['subject'],
                'text'    => $mail['text'],
                'html'    => $mail['html'],
                'replyTo' => $teamEmail,
            ]);
            $emails['customer']['sent'] = true;
            $stmt = $pdo->prepare('UPDATE support SET customer_mail_status = ? WHERE id = ?');
            $stmt->execute(['sent', $id]);
            $record['customerMailStatus'] = 'sent';
        } catch (Throwable $mailErr) {
            $emails['customer']['error'] = $mailErr->getMessage();
            $stmt = $pdo->prepare('UPDATE support SET customer_mail_status = ? WHERE id = ?');
            $stmt->execute(['failed', $id]);
            $record['customerMailStatus'] = 'failed';
        }
    }

    json_response([
        'message' => 'Support request submitted successfully.',
        'id'      => $id,
        'request' => $record,
        'emails'  => $emails,
    ], 201);
}

function support_update(): never
{
    $pdo = db();
    $id = route_param('id');
    $existing = find_support($pdo, $id);
    if (!$existing) {
        json_response(['message' => 'Support request not found.'], 404);
    }

    $allowed = [
        'status', 'priority', 'assignedTeam', 'assignedName', 'internalNote', 'customerFeedback',
        'pendingForCustomer', 'pendingForFastech', 'pendingForOem',
    ];

    $isMultipart = str_contains(
        $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '',
        'multipart/form-data',
    );
    if ($isMultipart) {
        $parsed = parse_multipart_body();
        $body = $parsed['post'];
    } else {
        $body = body_json();
    }
    $decision = strtolower((string) ($body['approvalDecision'] ?? ''));

    if (array_key_exists('status', $body)) {
        $allowedStatuses = ['Open', 'Closed'];
        if (!in_array($body['status'], $allowedStatuses, true)) {
            json_response(['message' => 'Invalid status. Allowed: ' . implode(', ', $allowedStatuses) . '.'], 400);
        }
    }

    if ($decision !== '' && !in_array($decision, ['ticketclosed', 'reset'], true)) {
        json_response(['message' => 'Invalid approval decision.'], 400);
    }

    $updateData = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $body)) {
            $updateData[$k] = $body[$k];
        }
    }

    if ($decision === 'ticketclosed') {
        $updateData['status'] = 'Closed';
        $updateData['approvalStatus'] = '';
        $updateData['disapprovalReason'] = '';
    } elseif ($decision === 'reset') {
        $updateData['approvalStatus'] = '';
        $updateData['disapprovalReason'] = '';
    }

    $updateData['updated_at'] = now_ist();
    apply_updates($pdo, 'support', $id, $updateData, 'id');

    $uploads = $isMultipart ? stored_put_files() : stored_images_from_files();
    foreach ($uploads as $img) {
        $data = request_images_sql($img);
        $stmt = $pdo->prepare(
            'INSERT INTO support_images (support_id, original_name, file_name, path, mime_type, size)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([$id, $data['original_name'], $data['file_name'], $data['path'], $data['mime_type'], $data['size']]);
    }

    $record = format_support(find_support($pdo, $id));

    $customerMail = ['sent' => false];
    $email = (string) ($record['email'] ?? '');

    if ($decision === 'ticketclosed' && $email === '') {
        $customerMail['error'] = 'Customer email is missing.';
        $stmt = $pdo->prepare('UPDATE support SET customer_mail_status = ? WHERE id = ?');
        $stmt->execute(['failed', $id]);
        $record['customerMailStatus'] = 'failed';
    } elseif ($decision === 'ticketclosed' && $email !== '') {
        $ticketRef = $record['id'] ?? '';
        try {
            send_mail([
                'to'      => $email,
                'subject' => 'Your support ticket has been closed',
                'text'    => "Hi {$record['name']}, your support ticket {$ticketRef} has been successfully resolved and closed. If you need any further help, please reach out to us.\n\nService@fastech-india.com\n8693888676",
                'html'    => '<p>Hi ' . escape_html($record['name']) . ',</p>'
                    . '<p>Your support ticket <strong>' . escape_html($ticketRef) . '</strong> has been successfully resolved and closed.</p>'
                    . '<p>If you need any further help, please reach out to us.</p>'
                    . '<p>Service@fastech-india.com<br/>8693888676</p>',
                'cc'      => env('CC_EMAIL'),
            ]);
            $customerMail['sent'] = true;
            $stmt = $pdo->prepare('UPDATE support SET customer_mail_status = ? WHERE id = ?');
            $stmt->execute(['sent', $id]);
            $record['customerMailStatus'] = 'sent';
        } catch (Throwable $mailErr) {
            $customerMail['error'] = $mailErr->getMessage();
            $stmt = $pdo->prepare('UPDATE support SET customer_mail_status = ? WHERE id = ?');
            $stmt->execute(['failed', $id]);
            $record['customerMailStatus'] = 'failed';
        }
    }

    json_response([
        'message' => 'Support request updated successfully.',
        'request' => $record,
        'emails'  => ['customer' => $customerMail],
    ]);
}

function support_export_csv(): never
{
    $pdo = db();
    $support = $pdo->query('SELECT * FROM support')->fetchAll();
    $formatted = array_map('format_support', $support);
    if (!$formatted) {
        json_response(['message' => 'No data to export.'], 404);
    }

    $filterStatus = trim((string) ($_GET['status'] ?? ''));
    $norm = fn ($v) => strtolower((string) ($v ?? ''));

    $rows = [];
    if ($filterStatus !== '' && $norm($filterStatus) !== 'all') {
        foreach ($formatted as $r) {
            if ($norm($r['status']) === $norm($filterStatus)) {
                $rows[] = $r;
            }
        }
    } else {
        $rows = $formatted;
    }

    usort($rows, fn ($a, $b) =>
        (int) parse_ist($b['createdAt'] ?? '') - (int) parse_ist($a['createdAt'] ?? ''));

    if (!$rows) {
        json_response(['message' => 'No data to export.'], 404);
    }

    $viewKeys = [
        'id', 'name', 'oem', 'product', 'serialSingle', 'softwareVersion', 'description', 'email', 'phone',
        'company', 'location', 'designation', 'additionalInfo', 'status', 'assignedTeam', 'assignedName',
        'internalNote', 'customerFeedback', 'createdAt', 'updatedAt',
    ];

    $heading = function ($key) {
        if ($key === 'assignedTeam') return 'Assigned To Team';
        if ($key === 'assignedName') return 'Assigned to person';
        if ($key === 'serialSingle') return 'Module S/N';
        return ucfirst((string) preg_replace('/([A-Z])/', ' $1', $key));
    };

    $headers = array_merge(array_map($heading, $viewKeys), ['Pending From', 'Uploaded Files']);
    $escape = fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"';

    $dataRows = array_map(function ($r) use ($viewKeys, $escape, $norm) {
        $cells = array_map(fn ($key) => (($r[$key] ?? '') !== '') ? $r[$key] : '-', $viewKeys);
        $cells[] = is_pending_flag($r['pendingForCustomer'])
            ? 'Pending from Customer'
            : (is_pending_flag($r['pendingForFastech'])
                ? 'Pending from Fastech'
                : (is_pending_flag($r['pendingForOem'])
                    ? 'Pending from OEM'
                    : '-'));
        $names = array_map(fn ($f) => $f['originalName'] ?: $f['fileName'], $r['images'] ?? []);
        $cells[] = $names ? implode('; ', $names) : '-';
        return implode(',', array_map($escape, $cells));
    }, $rows);

    $csv = implode(',', $headers) . "\r\n" . implode("\r\n", $dataRows);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fascal_support_' . time() . '.csv"');
    echo $csv;
    exit;
}

function support_delete(): never
{
    $pdo = db();
    $id = route_param('id');
    $stmt = $pdo->prepare('DELETE FROM support WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_response(['message' => 'Support request not found.'], 404);
    }
    json_response(['message' => 'Support request deleted.']);
}

function find_support(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM support WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}