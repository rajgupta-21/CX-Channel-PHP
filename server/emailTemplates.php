<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

const COMPANY_INFO = [
    'name'         => 'Fastech Telecommunications (India) Pvt. Ltd.',
    'addressLines' => [
        'FASTECH PARAM',
        'EL-44, Electronic Zone, TTC Industrial Area',
        'MIDC, Mahape, Navi Mumbai - 400710',
    ],
    'phone' => '022-28353636 Ext. 112',
    'gst'   => '27AAACF4021B1ZE',
];

function instruction_pdf(): string
{
    return __DIR__ . '/../specs/instruction.pdf';
}

function esc_hl($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function display_rma($rmaNumber): string
{
    return $rmaNumber ? preg_replace('/^(?:T-|RMA-)/i', '', (string) $rmaNumber) : '';
}

function format_date($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $s = trim((string) $value);
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})/', $s, $m)) {
        return str_pad($m[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . $m[3];
    }
    return $s;
}

function mail_signature(array $opts = []): array
{
    $name = $opts['senderName'] ?? env('MAIL_SIGNER_NAME', '');
    return ['name' => $name !== '' ? $name : 'Fastech RMA Team', 'company' => COMPANY_INFO['name']];
}

function text_to_html(string $text): string
{
    $blocks = preg_split('/\n\s*\n/', $text) ?: [];
    $html = '';
    foreach ($blocks as $block) {
        if (trim($block) === '') {
            continue;
        }
        $lines = explode("\n", $block);
        $escaped = array_map(fn ($l) => esc_hl($l), $lines);
        $html .= '<p>' . implode('<br/>', $escaped) . '</p>' . "\n";
    }
    return $html;
}

function field_cell(string $label, $value): string
{
    return '<td style="padding:4px 20px 4px 0;vertical-align:top;width:50%;">'
        . '<div style="font-weight:700;font-size:11px;letter-spacing:.06em;text-transform:uppercase;margin-bottom:3px;">' . esc_hl($label) . '</div>'
        . '<div style="word-break:break-word;">' . esc_hl($value ?: '-') . '</div>'
        . '</td>';
}

function grid_html(array $cells): string
{
    $rows = '';
    for ($i = 0; $i < count($cells); $i += 2) {
        $rows .= '<tr>' . $cells[$i] . (isset($cells[$i + 1]) ? $cells[$i + 1] : '<td style="width:50%;"></td>') . '</tr>';
    }
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;border:0;">' . $rows . '</table>';
}

function h4(string $title): string
{
    return '<h4 style="margin:16px 0 6px;font-size:15px;">' . esc_hl($title) . '</h4>';
}

function para($value): string
{
    return '<p style="margin:2px 0;line-height:1.5;white-space:pre-wrap;">' . esc_hl($value ?: '-') . '</p>';
}

function raw_para(string $value): string
{
    return '<p style="margin:2px 0;line-height:1.5;">' . $value . '</p>';
}

function field_row($label1, $value1, $label2 = null, $value2 = null): string
{
    $secondPair = $label2 !== null
        ? '<td style="padding:4px 8px;font-weight:700;white-space:nowrap;">' . esc_hl($label2) . '</td>'
            . '<td style="padding:4px 0;">' . esc_hl($value2 ?: '-') . '</td>'
        : '<td colspan="2"></td>';
    return '<tr>'
        . '<td style="padding:4px 8px 4px 0;font-weight:700;white-space:nowrap;vertical-align:top;">' . esc_hl($label1) . '</td>'
        . '<td style="padding:4px 24px 4px 0;vertical-align:top;">' . esc_hl($value1 ?: '-') . '</td>'
        . $secondPair
        . '</tr>';
}

function field_row_full(string $label, $value): string
{
    return '<tr><td colspan="4" style="padding:8px 0 2px;font-weight:700;">' . esc_hl($label) . '</td></tr>'
        . '<tr><td colspan="4" style="padding:0 0 6px;white-space:pre-wrap;">' . esc_hl($value ?: '-') . '</td></tr>';
}

function field_table(array $rows): string
{
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px;border:0;">' . implode('', $rows) . '</table>';
}

function wrap_html(string $title = '', string $bodyHtml = ''): string
{
    $t = $title !== '' ? '<h3>' . esc_hl($title) . '</h3>' : '';
    return '<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
  </head>
  <body>
    ' . $t . '
    ' . $bodyHtml . '
  </body>
</html>';
}

function build_message(string $subject, string $text, bool $includeCidImage = false): array
{
    $bodyHtml = text_to_html($text);
    if ($includeCidImage) {
        $bodyHtml .= '<p><img src="cid:image001" alt="Fastech" /></p>';
    }
    return ['subject' => $subject, 'text' => $text, 'html' => wrap_html('', $bodyHtml)];
}

function build_html(string $title, string $subject, string $text, bool $includeCidImage, string $bodyHtml = ''): array
{
    $html = $bodyHtml !== '' ? $bodyHtml : text_to_html($text);
    if ($includeCidImage) {
        $html .= '<p><img src="cid:image001" alt="Fastech" /></p>';
    }
    return ['subject' => $subject, 'text' => $text, 'html' => wrap_html($title, $html)];
}

function image_attachment(): ?array
{
    $imagePath = env('MAIL_IMAGE_PATH');
    if ($imagePath === '' || !is_file($imagePath)) {
        return null;
    }
    return [
        'filename' => basename($imagePath),
        'path'     => $imagePath,
        'cid'      => 'image001',
    ];
}

function approval_attachments(): array
{
    $attachments = [];
    $pdf = instruction_pdf();
    if (is_file($pdf)) {
        $attachments[] = [
            'filename'    => 'RMA-Shipping-Instructions.pdf',
            'path'        => $pdf,
            'contentType' => 'application/pdf',
        ];
    }
    $image = image_attachment();
    if ($image !== null) {
        $attachments[] = $image;
    }
    return $attachments;
}

function request_serial_lines(array $request, string $type): array
{
    $isNarda = strtolower((string) ($request['oem'] ?? '')) === 'narda';
    $isCalibration = strtolower((string) ($request['serviceType'] ?? '')) === 'calibration';

    $lines = $isNarda
        ? [
            'Base Unit S/N: ' . ($request['serialBaseUnit'] ?? ''),
            'RF Cable S/N: ' . ($request['serialRfCable'] ?? ''),
            'Antenna S/N: ' . ($request['serialAntenna'] ?? ''),
          ]
        : ['Product Serial Number: ' . ($request['serialSingle'] ?? '')];

    if ($isCalibration) {
        $lines[] = 'PO Number: ' . ($request['poNumber'] ?? '');
        $lines[] = 'PO Date: ' . format_date($request['poDate'] ?? '');
    }

    return [$isNarda, $isCalibration, $lines];
}

function build_submission_email(array $request): array
{
    $subject = 'RMA Request Submitted Successfully ';
    [, , $serialLines] = request_serial_lines($request, '');

    $text = implode("\n", array_merge(
        ['Hello ' . ($request['name'] ?? '') . ',', ''],
        ['Your RMA request has been successfully submitted.', ''],
        [
            'Date of Submission: ' . format_date($request['createdAt'] ?? ''),
            'OEM: ' . ($request['oem'] ?? ''),
            'Service Type: ' . ($request['serviceType'] ?? ''),
            'Product Model: ' . ($request['product'] ?? ''),
        ],
        $serialLines,
        ['', 'Our team will review your request and update you once the request has been processed.', ''],
    ));

    return build_message($subject, $text, false);
}

function build_admin_submission_email(array $request): array
{
    $subject = 'RMA Request Submitted Successfully ';
    [, , $serialLines] = request_serial_lines($request, '');

    $text = implode("\n", array_merge(
        ['Hello!', ''],
        ['You have a new request for ' . ($request['oem'] ?? '') . ' ' . ($request['serviceType'] ?? ''), ''],
        [
            'Date of Submission: ' . format_date($request['createdAt'] ?? ''),
            'OEM: ' . ($request['oem'] ?? ''),
            'Service Type: ' . ($request['serviceType'] ?? ''),
            'Product Model: ' . ($request['product'] ?? ''),
        ],
        $serialLines,
        [''],
    ));

    return build_message($subject, $text, false);
}

function build_support_submission_email(array $request): array
{
    $id = $request['id'] ?? '';
    $subject = 'Support Request Submitted - Ticket ID: ' . $id;

    $text = implode("\n", [
        'Hello ' . ($request['name'] ?? '') . ',',
        '',
        'Your support request has been successfully submitted. Our team will review your request and update you once the request has been processed.',
        '',
        'Ticket ID:',
        $id,
        '',
        'Date of Submission:',
        format_date($request['createdAt'] ?? ''),
        '',
        'OEM:',
        ($request['oem'] ?? ''),
        '',
        'Service Type:',
        ($request['serviceType'] ?? 'Support'),
        '',
        'CUSTOMER DETAILS',
        '',
        'Name:',
        ($request['name'] ?? ''),
        '',
        'Contact Number:',
        ($request['phone'] ?? ''),
        '',
        'Company Name:',
        ($request['company'] ?? ''),
        '',
        'Designation:',
        ($request['designation'] ?? ''),
        '',
        'Location:',
        ($request['location'] ?? ''),
        '',
        'Email:',
        ($request['email'] ?? ''),
        '',
        'Company Address:',
        ($request['billingAddress'] ?? ''),
        '',
        'PRODUCT DETAILS',
        '',
        'Product Model:',
        ($request['product'] ?? ''),
        '',
        'Software Version:',
        ($request['softwareVersion'] ?? ''),
        '',
        'Other Product Information:',
        implode(', ', array_values(array_filter([
            $request['serialRfCable'] ?? '',
            $request['serialBaseUnit'] ?? '',
            $request['serialSingle'] ?? '',
            $request['serialAntenna'] ?? '',
        ]))) ?: '',
        '',
        'DESCRIPTION OF THE ISSUE',
        '',
        ($request['description'] ?? ''),
        '',
        'ADDITIONAL INFORMATION',
        '',
        ($request['additionalInfo'] ?? ''),
        '',
    ]);

    $bodyHtml = '<p>Hello ' . esc_hl($request['name'] ?? '') . ',</p>'
        . '<p>Your support request has been successfully submitted. Our team will review your request and update you once the request has been processed.</p>'
        . h4('Request Details')
        . grid_html([
            field_cell('Ticket ID', $id),
            field_cell('Date of Submission', format_date($request['createdAt'] ?? '')),
            field_cell('OEM', $request['oem'] ?? ''),
            field_cell('Service Type', $request['serviceType'] ?? 'Support'),
        ])
        . h4('Customer Details')
        . grid_html([
            field_cell('Name', $request['name'] ?? ''),
            field_cell('Contact Number', $request['phone'] ?? ''),
            field_cell('Company Name', $request['company'] ?? ''),
            field_cell('Designation', $request['designation'] ?? ''),
            field_cell('Location', $request['location'] ?? ''),
            field_cell('Email', $request['email'] ?? ''),
            field_cell('Company Address', $request['billingAddress'] ?? ''),
        ])
        . h4('Product Details')
        . grid_html([
            field_cell('Product Model', $request['product'] ?? ''),
            field_cell('Software Version', $request['softwareVersion'] ?? ''),
            field_cell('Other Product Information', implode(', ', array_values(array_filter([
                $request['serialRfCable'] ?? '',
                $request['serialBaseUnit'] ?? '',
                $request['serialSingle'] ?? '',
                $request['serialAntenna'] ?? '',
            ])))),
        ])
        . h4('Description of the Issue')
        . para($request['description'] ?? '')
        . h4('Additional Information')
        . para($request['additionalInfo'] ?? '');

    return build_html('Support Request Submission', $subject, $text, false, $bodyHtml);
}

function company_address_lines(): array
{
    return [
        ...COMPANY_INFO['addressLines'],
        'Te. No. ' . COMPANY_INFO['phone'],
        'Our GST No. ' . COMPANY_INFO['gst'],
    ];
}

function build_approval_email(array $request, array $opts = []): array
{
    $rma = display_rma($request['rmaNumber'] ?? '');
    $requestDate = format_date($request['createdAt'] ?? '');
    $subject = 'RMA Number - ' . $rma . ' Date of Request: ' . $requestDate;
    $statusNotes = $opts['statusNotes'] ?? ($request['ipAdminNote'] ?? '');
    $billTo = (string) ($request['billingAddress'] ?? '');
    $returnTo = (string) ($request['returnAddress'] ?? '');

    $addressLines = array_map(fn ($l) => esc_hl($l), company_address_lines());

    $text = implode("\n", [
        'Hello ' . ($request['name'] ?? '') . '!',
        'Your RMA Request has been approved and RMA Number for your request is ' . $rma . ' .',
        '',
        'Material to be sent to :',
        COMPANY_INFO['name'],
        ...company_address_lines(),
        '',
        'Date of Request: ' . $requestDate,
        'RMA Number: ' . $rma,
        'OEM: ' . ($request['oem'] ?? ''),
        'Type: ' . ($request['serviceType'] ?? ''),
        'Status: Approved',
        'Status Notes: ' . $statusNotes,
        'Description of the Issue:',
        ($request['description'] ?? ''),
        "Sender's Full Name: " . ($request['name'] ?? ''),
        "Sender's Contact No: " . ($request['phone'] ?? ''),
        "Sender's Company Name: " . ($request['company'] ?? ''),
        "Sender's Designation: " . ($request['designation'] ?? ''),
        "Sender's Location: " . ($request['location'] ?? ''),
        'Email: ' . ($request['email'] ?? ''),
        "Sender's Comapny Address: " . $billTo,
        'Product Model: ' . ($request['product'] ?? ''),
    ]);

    [$isNarda, $isCalibration, $serialLines] = request_serial_lines($request, '');
    $text .= "\n" . implode("\n", $serialLines);
    if ($isCalibration) {
        $text .= "\n" . 'Bill-to Address(if applicable): ' . $billTo;
    }
    $text .= "\n" . 'Return Address: ' . $returnTo;

    $rows = [
        field_row('Date of Request:', $requestDate),
        field_row('RMA Number:', $rma),
        field_row('OEM:', $request['oem'] ?? '', 'Type:', $request['serviceType'] ?? ''),
        field_row('Status:', 'Approved', 'Status Notes:', $statusNotes),
        field_row_full('Description of the Issue:', $request['description'] ?? ''),
        field_row("Sender's Full Name:", $request['name'] ?? ''),
        field_row("Sender's Contact No:", $request['phone'] ?? '', "Sender's Company Name:", $request['company'] ?? ''),
        field_row("Sender's Designation:", $request['designation'] ?? '', "Sender's Location:", $request['location'] ?? ''),
        field_row('Email:', $request['email'] ?? ''),
        field_row("Sender's Comapny Address:", $billTo),
        field_row('Product Model:', $request['product'] ?? ''),
    ];

    if ($isNarda) {
        $rows[] = field_row('Base Unit S/N:', $request['serialBaseUnit'] ?? '');
        $rows[] = field_row('RF Cable S/N:', $request['serialRfCable'] ?? '');
        $rows[] = field_row('Antenna S/N:', $request['serialAntenna'] ?? '');
    } else {
        $rows[] = field_row('Product Serial Number:', $request['serialSingle'] ?? '');
    }

    if ($isCalibration) {
        $rows[] = field_row('PO Number:', $request['poNumber'] ?? '');
        $rows[] = field_row('PO Date:', format_date($request['poDate'] ?? ''));
    }

    $rows[] = field_row('Bill-to Address(if applicable):', $billTo);
    $rows[] = field_row('Return Address:', $returnTo);

    $bodyHtml = '<p style="margin:0 0 12px;">Hello ' . esc_hl($request['name'] ?? '') . '!</p>'
        . '<p style="margin:0 0 16px;">Your RMA Request has been approved and RMA Number for your request is ' . esc_hl($rma) . ' .</p>'
        . '<p style="margin:0 0 4px;"><strong>Material to be sent to :</strong> ' . esc_hl(COMPANY_INFO['name']) . ',</p>'
        . raw_para(implode('<br/>', $addressLines))
        . field_table($rows);

    return [
        'subject'     => $subject,
        'text'        => $text,
        'html'        => wrap_html('', $bodyHtml),
        'attachments' => approval_attachments(),
    ];
}

function build_disapproval_email(array $request, array $opts = []): array
{
    $id = $request['id'] ?? '';
    $subject = 'RMA Request Disapproved - ' . $id;
    $reason = $request['disapprovalReason'] ?? 'No reason was provided.';
    $text = implode("\n", [
        'Hello, ' . ($request['name'] ?? '') . '.',
        'Your RMA has been disapproved',
        'Admin Note: ' . $reason,
        '',
    ]);
    return build_message($subject, $text, false);
}