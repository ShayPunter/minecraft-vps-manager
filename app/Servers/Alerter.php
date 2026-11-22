<?php

namespace App\Servers;

use App\Enums\Role;
use App\Models\User;
use App\Notifications\ServerAlert;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Tells a person something needs them: a VPS kept because its world couldn't be saved,
 * a server that never came online, one that's been running for hours. Mail goes to
 * MVM_ALERT_MAIL, or to every admin when that's empty; a Discord webhook is posted too
 * when MVM_ALERT_DISCORD_WEBHOOK is set. Always logged. Never throws: an alert that fails
 * must not stop the watchdog.
 */
class Alerter
{
    public function send(string $subject, string $message): void
    {
        Log::warning("[alert] {$subject}: {$message}");

        $configured = config('minecraft.alert_mail');
        $addresses = is_string($configured) && $configured !== ''
            ? [$configured]
            : User::query()->where('role', Role::Admin)->pluck('email')->all();

        if ($addresses !== []) {
            try {
                Notification::route('mail', $addresses)->notify(new ServerAlert($subject, $message));
            } catch (Throwable $e) {
                Log::error("Couldn't mail the alert: {$e->getMessage()}");
            }
        }

        $webhook = config('minecraft.alert_discord_webhook');

        if (is_string($webhook) && $webhook !== '') {
            try {
                Http::timeout(10)->post($webhook, [
                    'content' => mb_substr("**{$subject}**\n{$message}", 0, 2000),
                    'allowed_mentions' => ['parse' => []],
                ])->throw();
            } catch (Throwable $e) {
                Log::error("Couldn't post the alert to Discord: {$e->getMessage()}");
            }
        }
    }
}
