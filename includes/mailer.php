<?php

if (!function_exists('mailConfigurationIssues')) {
    function mailConfigurationIssues(): array
    {
        $issues = [];

        if (trim((string) SMTP_HOST) === '') {
            $issues[] = 'SMTP_HOST is missing';
        }

        if ((int) SMTP_PORT <= 0) {
            $issues[] = 'SMTP_PORT is invalid';
        }

        if (trim((string) SMTP_USER) === '') {
            $issues[] = 'SMTP_USER is missing';
        }

        if (trim((string) SMTP_PASS) === '') {
            $issues[] = 'SMTP_PASS is missing';
        }

        if (!filter_var(SMTP_FROM_EMAIL, FILTER_VALIDATE_EMAIL)) {
            $issues[] = 'SMTP_FROM_EMAIL or SMTP_USER must contain a valid sender email address';
        }

        return $issues;
    }
}

if (!function_exists('mailIsConfigured')) {
    function mailIsConfigured(): bool
    {
        return mailConfigurationIssues() === [];
    }
}

if (!function_exists('mailConfigurationErrorMessage')) {
    function mailConfigurationErrorMessage(): string
    {
        return 'Email sending is not configured yet. Please add the SMTP settings in the deployment environment.';
    }
}

if (!function_exists('configureSmtpMailer')) {
    function configureSmtpMailer($mail, ?string $fromName = null): void
    {
        $issues = mailConfigurationIssues();
        if ($issues !== []) {
            throw new RuntimeException('Mailer configuration issue: ' . implode('; ', $issues));
        }

        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port = SMTP_PORT;
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ];
        $mail->setFrom(SMTP_FROM_EMAIL, $fromName ?: SMTP_FROM_NAME);
    }
}
