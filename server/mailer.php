<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

function send_mail(array $opts): array
{
    $to = (string) ($opts['to'] ?? '');
    if ($to === '') {
        throw new RuntimeException('Recipient email (to) is required. Set TEAM_EMAIL in server/.env.');
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = env('SMTP_HOST', 'smtp.zoho.com');
        $mail->SMTPAuth   = true;
        $mail->Username   = env('SENDER_EMAIL', env('SNEDER_EMAIL', 'undefeatedcrplayer@gmail.com'));
        $mail->Password   = env('SENDER_PASSWORD');
        $mail->SMTPSecure = (int) env('SMTP_PORT', 587) === 465
            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int) env('SMTP_PORT', 587);

        $mail->setFrom($mail->Username, 'Admin');
        $mail->addAddress($to);

        $cc = (string) ($opts['cc'] ?? '');
        if (!empty($cc)) {
            foreach (array_filter(explode(',', (string) $cc), 'trim') as $addr) {
                $mail->addCC(trim($addr));
            }
        }

        $replyTo = $opts['replyTo'] ?? '';
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }

        $mail->Subject = (string) ($opts['subject'] ?? '');
        $mail->Body    = (string) ($opts['html'] ?? '');
        $mail->CharSet = 'UTF-8';
        $isHtml = !empty($opts['html']);
        $mail->isHTML($isHtml);
        if (!$isHtml) {
            $mail->Body = (string) ($opts['text'] ?? '');
        } elseif (!empty($opts['text'])) {
            $mail->AltBody = (string) $opts['text'];
        }

        foreach ((array) ($opts['attachments'] ?? []) as $att) {
            if (!empty($att['path']) && is_file($att['path'])) {
                $mail->addAttachment(
                    $att['path'],
                    $att['filename'] ?? basename($att['path']),
                    'base64',
                    $att['contentType'] ?? '',
                    'attachment',
                    $att['cid'] ?? '',
                );
            }
        }

        $mail->send();
        return ['messageId' => $mail->getLastMessageID()];
    } catch (PHPMailer\PHPMailer\PhpMailerException $e) {
        throw new RuntimeException('Mail send failed: ' . $e->getMessage());
    }
}

function verify_mailer(): bool
{
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = env('SMTP_HOST', 'smtp.zoho.com');
    $mail->SMTPAuth   = true;
    $mail->Username   = env('SENDER_EMAIL');
    $mail->Password   = env('SENDER_PASSWORD');
    $mail->SMTPSecure = (int) env('SMTP_PORT', 587) === 465
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int) env('SMTP_PORT', 587);
    $mail->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
    $mail->Timeout = 10;

    try {
        return $mail->smtpConnect();
    } catch (PHPMailer\PHPMailer\PhpMailerException $e) {
        throw new RuntimeException('SMTP verification failed: ' . $e->getMessage());
    }
}