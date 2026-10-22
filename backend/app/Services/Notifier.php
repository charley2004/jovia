<?php

namespace App\Services;

use App\Models\MemberNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

// In-app notification for a member, plus an email when a real mailer is configured.
// Call after the database transaction that caused it has committed.
class Notifier
{
    public static function send(int $userId, string $title, string $body, ?string $link = null): void
    {
        try {
            MemberNotification::create(['user_id' => $userId, 'title' => $title, 'body' => $body, 'link' => $link]);
            if (config('mail.default') === 'log' || config('mail.default') === 'array') {
                return;
            }
            $user = User::find($userId);
            if (! $user) {
                return;
            }
            $url = $link ? rtrim(config('app.url'), '/').'/'.ltrim($link, '/') : '';
            Mail::raw("Hi {$user->first_name},\n\n{$body}".($url ? "\n\n{$url}" : '')."\n\n— Jovia Network", function ($m) use ($user, $title) {
                $m->to($user->email)->subject("Jovia Network: {$title}");
            });
        } catch (\Throwable $e) {
            Log::warning('notify failed: '.$e->getMessage());
        }
    }

    // $list = [[userId, title, body, link], ...]
    public static function sendAll(array $list): void
    {
        foreach ($list as $n) {
            self::send(...$n);
        }
    }
}
