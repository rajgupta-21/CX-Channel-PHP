<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../mailer.php';
require_once __DIR__ . '/../emailTemplates.php';

function requests_sort(array &$rows): void
{
    usort($rows, function ($a, $b) {
        $aFresh = strtolower((string) $a['status']) === 'fresh';
        $bFresh = strtolower((string) $b['status']) === 'fresh';
        if ($aFresh && !$bFresh) return -1;
        if (!$aFresh && $bFresh) return 1;

        $aIssued = parse_ist($a['rmaIssuedAt'] ?? '');
        $bIssued = parse_ist($b['rmaIssuedAt'] ?? '');
        if ($aIssued || $bIssued) return $bIssued - $aIssued;

        return (int) parse_ist($b['createdAt'] ?? '') - (int) parse_ist($a['createdAt'] ?? '');
    });
}

function requests_list(): never
{
    $pdo = db();
    $email = trim((string) ($_GET['email'] ?? ''));

    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT * FROM requests WHERE email = ?');
        $stmt->execute([$email]);
        $rows = $stmt->fetchAll();
    } else {
        $rows = $pdo->query('SELECT * FROM requests')->fetchAll();
    }

    $formatted = array_map('format_request', $rows);
    requests_sort($formatted);
    json_response(array_values($formatted));
}

function requests_detail(): never
{
    $pdo = db();
    $request = find_request($pdo, route_param('id'));
    if (!$request) {
        json_response(['message' => 'Request not found.'], 404);
    }

    $stmt = $pdo->prepare('SELECT * FROM requests WHERE email = ? AND id <> ?');
    $stmt->execute([$request['email'], $request['id']]);
    $history = $stmt->fetchAll();

    $formattedHistory = array_map('format_request', $history);
    usort($formattedHistory, fn ($a, $b) =>
        (int) parse_ist($b['createdAt'] ?? '') - (int) parse_ist($a['createdAt'] ?? ''));

    json_response([
        'request' => format_request($request),
        'history' => array_values($formattedHistory),
    ]);
}

function requests_create(): never
{
    $pdo = db();
    $body = $_POST;
    $required = ['name', 'email', 'oem', 'serviceType', 'product'];
    $missing = array_values(array_filter($required, fn ($k) => trim((string) ($body[$k] ?? '')) === ''));
    if ($missing) {
        json_response(['message' => 'Missing required fields: ' . implode(', ', $missing) . '.'], 400);
    }

    $uploads = stored_images_from_files();

    $now = now_ist();
    $id = generate_submission_id();

    $serviceType = (string) ($body['serviceType'] ?? '');
    $imageParams = [];
    foreach ($uploads as $img) {
        $imageParams[] = request_images_sql($img);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO requests (
            id, rma_number, rma_status, rma_issued_at, pending_for_customer, pending_for_fastech,
            pending_for_oem, custom_status, approval_status, customer_mail_status, disapproval_reason,
            oem, service_type, product, description, name, email, phone, company, designation, location,
            po_number, po_date, serial_single, serial_base_unit, serial_rf_cable, serial_antenna,
            billing_address, return_address, cal_certificate_address, additional_info, status,
            customer_feedback, internal_note, created_at, updated_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?
        )',
    );

    $stmt->execute([
        $id,
        '',
        'RMA Not Received',
        '',
        '', '', '', '', '', '', '',
        (string) ($body['oem'] ?? ''),
        $serviceType,
        (string) ($body['product'] ?? ''),
        (string) ($body['description'] ?? ''),
        (string) ($body['name'] ?? ''),
        (string) ($body['email'] ?? ''),
        (string) ($body['phone'] ?? ''),
        (string) ($body['company'] ?? ''),
        (string) ($body['designation'] ?? ''),
        (string) ($body['location'] ?? ''),
        $serviceType === 'Calibration' ? (string) ($body['poNumber'] ?? '') : '',
        $serviceType === 'Calibration' ? (string) ($body['poDate'] ?? '') : '',
        (string) ($body['serialSingle'] ?? ''),
        (string) ($body['serialBaseUnit'] ?? ''),
        (string) ($body['serialRfCable'] ?? ''),
        (string) ($body['serialAntenna'] ?? ''),
        (string) ($body['billingAddress'] ?? ''),
        (string) ($body['returnAddress'] ?? ''),
        (string) ($body['calCertificateAddress'] ?? ''),
        (string) ($body['additionalInfo'] ?? ''),
        'fresh',
        '', '',
        $now,
        $now,
    ]);

    foreach ($imageParams as $img) {
        $stmt = $pdo->prepare(
            'INSERT INTO request_images (request_id, original_name, file_name, path, mime_type, size)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([$id, $img['original_name'], $img['file_name'], $img['path'], $img['mime_type'], $img['size']]);
    }

    $record = find_request($pdo, $id);
    $record = format_request($record);

    $emails = ['team' => ['sent' => false], 'customer' => ['sent' => false]];

    $teamEmail = env('TEAM_EMAIL');
    try {
        $mail = build_admin_submission_email($record);
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
            $mail = build_submission_email($record);
            send_mail([
                'to'      => $record['email'],
                'subject' => $mail['subject'],
                'text'    => $mail['text'],
                'html'    => $mail['html'],
                'replyTo' => $teamEmail,
            ]);
            $emails['customer']['sent'] = true;
            $stmt = $pdo->prepare('UPDATE requests SET customer_mail_status = ? WHERE id = ?');
            $stmt->execute(['sent', $id]);
            $record['customerMailStatus'] = 'sent';
        } catch (Throwable $mailErr) {
            $emails['customer']['error'] = $mailErr->getMessage();
            $stmt = $pdo->prepare('UPDATE requests SET customer_mail_status = ? WHERE id = ?');
            $stmt->execute(['failed', $id]);
            $record['customerMailStatus'] = 'failed';
        }
    }

    json_response([
        'message' => 'Request submitted successfully.',
        'id'      => $id,
        'request' => $record,
        'emails'  => $emails,
    ], 201);
}

function requests_update(): never
{
    $pdo = db();
    $id = route_param('id');
    $existing = find_request($pdo, $id);
    if (!$existing) {
        json_response(['message' => 'Request not found.'], 404);
    }

    $body = body_json();
    $allowed = [
        'status', 'rmaStatus', 'customerFeedback', 'internalNote', 'product', 'oem', 'serviceType',
        'pendingForCustomer', 'pendingForFastech', 'pendingForOem', 'customStatus',
    ];

    $decision = strtolower((string) ($body['approvalDecision'] ?? ''));

    if (array_key_exists('status', $body)) {
        $normalized = strtolower((string) $body['status']);
        if ($normalized !== '' && !in_array($normalized, VALID_STATUSES, true)) {
            json_response(['message' => 'Invalid status. Allowed: ' . implode(', ', VALID_STATUSES) . '.'], 400);
        }
        $body['status'] = $normalized;
    }

    if ($decision !== '' && !in_array($decision, ['approved', 'disapproved'], true)) {
        json_response(['message' => 'Invalid approval decision.'], 400);
    }

    $updateData = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $body)) {
            $updateData[$k] = $body[$k];
        }
    }

    if (isset($body['processingDetails']) && is_array($body['processingDetails'])) {
        foreach (IP_FIELDS as $field) {
            if (array_key_exists($field, $body['processingDetails'])) {
                $updateData[$field] = $body['processingDetails'][$field];
            }
        }
    }

    $record = $existing;

    if ($decision === 'approved') {
        $updateData['approvalStatus'] = 'approved';
        $updateData['status'] = 'pending';
        $updateData['disapprovalReason'] = '';
    } elseif ($decision === 'disapproved') {
        $updateData['approvalStatus'] = 'disapproved';
        $updateData['status'] = 'disapproved';
        $updateData['disapprovalReason'] = trim((string) ($body['disapprovalReason'] ?? ''));
    }

    $movingToPending = ($updateData['status'] ?? $record['status']) === 'pending'
        && (empty($record['rma_number']) || empty($record['rma_issued_at']));
    if ($movingToPending) {
        $updateData['rma_number'] = generate_rma_number();
        $updateData['rma_issued_at'] = now_ist();
    }

    $updateData['updated_at'] = now_ist();
    apply_updates($pdo, 'requests', $id, $updateData, 'id');

    $record = format_request(find_request($pdo, $id));

    $customerMail = ['sent' => false];

    if ($decision === 'approved' && !empty($record['email'])) {
        try {
            $mail = build_approval_email($record, ['senderName' => $body['senderName'] ?? '']);
            send_mail([
                'to'          => $record['email'],
                'subject'     => $mail['subject'],
                'text'        => $mail['text'],
                'html'        => $mail['html'],
                'replyTo'     => env('TEAM_EMAIL'),
                'attachments' => $mail['attachments'],
                'cc'          => env('CC_EMAIL'),
            ]);
            $customerMail['sent'] = true;
            $stmt = $pdo->prepare('UPDATE requests SET customer_mail_status = ? WHERE id = ?');
            $stmt->execute(['sent', $id]);
            $record['customerMailStatus'] = 'sent';
        } catch (Throwable $mailErr) {
            $customerMail['error'] = $mailErr->getMessage();
            $stmt = $pdo->prepare('UPDATE requests SET customer_mail_status = ? WHERE id = ?');
            $stmt->execute(['failed', $id]);
            $record['customerMailStatus'] = 'failed';
        }
    }

    if ($decision === 'disapproved' && !empty($record['email'])) {
        try {
            $mail = build_disapproval_email($record, ['senderName' => $body['senderName'] ?? '']);
            send_mail([
                'to'      => $record['email'],
                'subject' => $mail['subject'],
                'text'    => $mail['text'],
                'html'    => $mail['html'],
                'replyTo' => env('TEAM_EMAIL'),
                'cc'      => env('CC_EMAIL'),
            ]);
            $customerMail['sent'] = true;
            $stmt = $pdo->prepare('UPDATE requests SET customer_mail_status = ? WHERE id = ?');
            $stmt->execute(['sent', $id]);
            $record['customerMailStatus'] = 'sent';
        } catch (Throwable $mailErr) {
            $customerMail['error'] = $mailErr->getMessage();
            $stmt = $pdo->prepare('UPDATE requests SET customer_mail_status = ? WHERE id = ?');
            $stmt->execute(['failed', $id]);
            $record['customerMailStatus'] = 'failed';
        }
    }

    json_response([
        'message' => 'Request updated successfully.',
        'request' => $record,
        'emails'  => ['customer' => $customerMail],
    ]);
}

function requests_delete(): never
{
    $pdo = db();
    $id = route_param('id');
    $stmt = $pdo->prepare('DELETE FROM requests WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        json_response(['message' => 'Request not found.'], 404);
    }
    json_response(['message' => 'Request deleted.']);
}

function csv_val($value): string
{
    $value = (string) ($value ?? '');
    return $value === '' ? '-' : $value;
}

function requests_export_csv(): never
{
    $pdo = db();
    $db = $pdo->query('SELECT * FROM requests')->fetchAll();
    $formatted = array_map('format_request', $db);
    if (!$formatted) {
        json_response(['message' => 'No data to export.'], 404);
    }

    $displayRma = fn ($r) => $r['rmaNumber'] ? preg_replace('/^(?:T-|RMA-)/i', '', (string) $r['rmaNumber']) : "\u{2014}";
    $fmtStatus = function ($s) {
        $v = strtolower((string) ($s ?? ''));
        return $v === '' ? "\u{2014}" : ucfirst($v);
    };
    $pendingFrom = function ($r) {
        if (is_pending_flag($r['pendingForCustomer'])) return 'Pending from Customer';
        if (is_pending_flag($r['pendingForFastech'])) return 'Pending from Fastech';
        if (is_pending_flag($r['pendingForOem'])) return 'Pending from OEM';
        return "\u{2014}";
    };
    $val = fn ($r, $k) => csv_val($r[$k] ?? '');

    $baseFields = [
        ['Sr.No', fn ($r, $i) => $i + 1],
        ['RMA No', fn ($r) => $displayRma($r)],
        ['Date of RMA', fn ($r) => $r['rmaIssuedAt'] ?: '-'],
        ['OEM', fn ($r) => $val($r, 'oem')],
        ['Service Type', fn ($r) => $val($r, 'serviceType')],
        ['Product Model', fn ($r) => $val($r, 'product')],
        ['Serial Number', fn ($r) => $val($r, 'serialSingle')],
        ['Product S/N', fn ($r) => $val($r, 'serialBaseUnit')],
        ['PO Number', fn ($r) => $val($r, 'poNumber')],
        ['PO Date', fn ($r) => $val($r, 'poDate')],
        ['Antenna', fn ($r) => $val($r, 'serialAntenna')],
        ['RF Cable', fn ($r) => $val($r, 'serialRfCable')],
        ['Additional Info', fn ($r) => $val($r, 'additionalInfo')],
        ["Sender's Full Name", fn ($r) => $val($r, 'name')],
        ["Sender's Contact No", fn ($r) => $val($r, 'phone')],
        ['Company Name', fn ($r) => $val($r, 'company')],
        ['Designation of sender', fn ($r) => $val($r, 'designation')],
        ['Department of sender', fn ($r) => $val($r, 'location')],
        ['Email ID', fn ($r) => $val($r, 'email')],
        ['Approved / Disapproved', fn ($r) => $fmtStatus($r['status'])],
        ['Billing Address', fn ($r) => $val($r, 'billingAddress')],
        ['Return Address', fn ($r) => $val($r, 'returnAddress')],
        ['Address for Cal Certificate', fn ($r) => $val($r, 'calCertificateAddress')],
        ['Description of Issue', fn ($r) => $val($r, 'description')],
        ['Admin Note', fn ($r) => $r['processingDetails']['ipAdminNote'] ?: '-'],
        ['Received Date', fn ($r) => $r['processingDetails']['ipReceivedDate'] ?: '-'],
        ['Date of Investigation', fn ($r) => $r['processingDetails']['ipDateOfInvestigation'] ?: '-'],
        ['Warranty', fn ($r) => $r['processingDetails']['ipWarranty'] ?: '-'],
        ['Investigation Details', fn ($r) => $r['processingDetails']['ipInvestigationDetails'] ?: '-'],
        ['Repair Details', fn ($r) => $r['processingDetails']['ipRepairDetails'] ?: '-'],
        ['Repaired by Person', fn ($r) => $r['processingDetails']['ipRepairedBy'] ?: '-'],
        ['Estimate Date', fn ($r) => $r['processingDetails']['ipEstimateDate'] ?: '-'],
        ['Estimate Amount INR', fn ($r) => $r['processingDetails']['ipEstimateAmount'] ?: '-'],
        ['P.O. No. & Date', fn ($r) => $r['processingDetails']['ipPoNoAndDate'] ?: '-'],
        ['PO Received Date', fn ($r) => $r['processingDetails']['ipPoReceivedDate'] ?: '-'],
        ['OEM RMA No.', fn ($r) => $r['processingDetails']['ipOemRmaNo'] ?: '-'],
        ['Date of Sent', fn ($r) => $r['processingDetails']['ipDateOfSent'] ?: '-'],
        ['Platform/ Module', fn ($r) => $r['processingDetails']['ipPlatformModule'] ?: '-'],
        ['OEM Quotation', fn ($r) => $r['processingDetails']['ipOemQuotation'] ?: '-'],
        ['Date of Receving', fn ($r) => $r['processingDetails']['ipDateOfReceivingFromOem'] ?: '-'],
        ['DC No. & Date', fn ($r) => $r['processingDetails']['ipDcNoAndDate'] ?: '-'],
        ['Dispatched Date', fn ($r) => $r['processingDetails']['ipDispatchedDate'] ?: '-'],
        ['LR No.', fn ($r) => $r['processingDetails']['ipLrNo'] ?: '-'],
        ['Open / Closed', fn ($r) => $fmtStatus($r['status'])],
        ['Open Awaiting for', fn ($r) => $pendingFrom($r)],
        ['Reason for waiting', fn ($r) => $r['processingDetails']['ipReasonForWaiting'] ?: '-'],
        ['Delivered Date', fn ($r) => $r['processingDetails']['ipDeliveredDate'] ?: '-'],
        ['Ack. Date', fn ($r) => $r['processingDetails']['ipAckDateFromWh'] ?: '-'],
        ['Submission Reference', fn ($r) => $r['id'] ?: '-'],
        ['RMA Current Status', fn ($r) => $r['customStatus'] ?: '-'],
        ['RMA Status', fn ($r) => (($r['rmaStatus'] ?? '') !== '') ? $r['rmaStatus'] : 'RMA Not Received'],
        ['Name of Courier', fn ($r) => $r['processingDetails']['ipCourierName'] ?: '-'],
        ['Customer Feedback', fn ($r) => $val($r, 'customerFeedback')],
        ['Estimate Number', fn ($r) => $r['processingDetails']['ipEstimateNumber'] ?: '-'],
        ['Remark', fn ($r) => $r['processingDetails']['ipRemark'] ?: '-'],
        ['Uploaded Files', function ($r) {
            $names = array_map(fn ($f) => $f['originalName'] ?: $f['fileName'], $r['images'] ?? []);
            return $names ? implode('; ', $names) : '-';
        }],
        ['Created At', fn ($r) => $val($r, 'createdAt')],
        ['Updated At', fn ($r) => $val($r, 'updatedAt')],
        ['Disapproval Reason', fn ($r) => $val($r, 'disapprovalReason')],
        ['Company Name (Admin)', fn ($r) => $r['processingDetails']['ipCompanyName'] ?: '-'],
        ['Location (Admin)', fn ($r) => $r['processingDetails']['ipLocation'] ?: '-'],
        ['Model Number (Admin)', fn ($r) => $r['processingDetails']['ipModuleSerialNumber'] ?: '-'],
        ['Support request if any', fn ($r) => $r['processingDetails']['ipSupportRequest'] ?: '-'],
    ];

    $headers = array_map(fn ($f) => $f[0], $baseFields);
    $escape = fn ($v) => '"' . str_replace('"', '""', (string) ($v ?? '')) . '"';

    $sorted = $formatted;
    requests_sort($sorted);

    $rows = array_map(function ($r, $idx) use ($baseFields, $escape) {
        $cells = [];
        foreach ($baseFields as [, $getter]) {
            $cells[] = $escape($getter($r, $idx));
        }
        return implode(',', $cells);
    }, $sorted, array_keys($sorted));

    $csv = implode(',', $headers) . "\r\n" . implode("\r\n", $rows);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="fascal_requests_' . time() . '.csv"');
    echo $csv;
    exit;
}

function requests_stats(): never
{
    $pdo = db();
    $rows = $pdo->query('SELECT status, rma_status, pending_for_customer, pending_for_fastech, pending_for_oem FROM requests')->fetchAll();

    $countStatus = function ($s) use ($rows) {
        return count(array_filter($rows, fn ($r) => strtolower((string) ($r['status'] ?? '')) === $s));
    };
    $countRmaStatus = function ($s) use ($rows) {
        return count(array_filter($rows, fn ($r) => (string) ($r['rma_status'] ?? '') === $s));
    };

    json_response([
        'total'             => count($rows),
        'fresh'             => $countStatus('fresh'),
        'pending'           => $countStatus('pending'),
        'pendingFromCustomer' => count(array_filter($rows, fn ($r) => is_pending_flag($r['pending_for_customer']))),
        'pendingFromFastech'  => count(array_filter($rows, fn ($r) => is_pending_flag($r['pending_for_fastech']))),
        'pendingFromOem'      => count(array_filter($rows, fn ($r) => is_pending_flag($r['pending_for_oem']))),
        'disapproved'       => $countStatus('disapproved'),
        'closed'            => $countStatus('closed'),
        'approved'          => $countStatus('approved'),
        'rmaReceived'       => $countRmaStatus('RMA Received'),
        'rmaNotReceived'    => $countRmaStatus('RMA Not Received'),
    ]);
}

function find_request(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM requests WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function apply_updates(PDO $pdo, string $table, string $id, array $fields, string $idCol = 'id'): void
{
    $sets = [];
    $params = [];
    foreach ($fields as $k => $v) {
        $col = snake_key($k);
        $sets[] = "`{$col}` = ?";
        $params[] = (string) ($v ?? '');
    }
    $params[] = $id;
    $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE `{$idCol}` = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}