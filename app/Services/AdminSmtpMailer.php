<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Mailable;

class AdminSmtpMailer
{
    private const MAILER_NAME = 'admin_smtp_runtime';

    /** @var list<string> */
    private const SMTP_KEYS = [
        'smtp_enabled',
        'smtp_host',
        'smtp_port',
        'smtp_username',
        'smtp_password',
        'smtp_encryption',
        'smtp_from_email',
        'smtp_from_name',
    ];

    public function __construct(
        private readonly MailFactory $mailFactory,
        private readonly MailManager $mailManager,
    ) {}

    public function sendTo(string $recipientEmail, Mailable $mailable): bool
    {
        $smtpConfig = $this->resolveSmtpConfig();
        if ($smtpConfig === null) {
            return false;
        }

        config([
            'mail.mailers.'.self::MAILER_NAME => $smtpConfig['mailer'],
        ]);

        // Make sure mail manager re-reads the runtime mailer configuration.
        $this->mailManager->forgetMailers();

        $this->mailFactory
            ->mailer(self::MAILER_NAME)
            ->to($recipientEmail)
            ->send($mailable);

        return true;
    }

    /**
     * @return array{mailer: array<string, mixed>, from_email: string, from_name: string}|null
     */
    public function resolveSmtpConfig(): ?array
    {
        /** @var array<string, string> $settings */
        $settings = Setting::query()
            ->whereIn('key', self::SMTP_KEYS)
            ->pluck('value', 'key')
            ->map(fn ($value): string => trim((string) ($value ?? '')))
            ->all();

        if (($settings['smtp_enabled'] ?? '0') !== '1') {
            return null;
        }

        $host = $settings['smtp_host'] ?? '';
        $port = $settings['smtp_port'] ?? '';
        $fromEmail = $settings['smtp_from_email'] ?? '';

        if ($host === '' || $port === '' || $fromEmail === '') {
            return null;
        }

        $fromName = $settings['smtp_from_name'] ?? config('app.name', 'Matrix');
        $encryption = strtolower($settings['smtp_encryption'] ?? 'none');
        $encryption = in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null;

        return [
            'mailer' => [
                'transport' => 'smtp',
                'host' => $host,
                'port' => (int) $port,
                'username' => $settings['smtp_username'] ?: null,
                'password' => $settings['smtp_password'] ?: null,
                'encryption' => $encryption,
                'timeout' => null,
            ],
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ];
    }
}
