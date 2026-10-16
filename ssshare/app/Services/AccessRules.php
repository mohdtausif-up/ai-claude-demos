<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\HttpException;
use DateTimeImmutable;
use DateTimeZone;

/** Parses and validates the advanced access options of a share link. */
final class AccessRules
{
    public const DOWNLOAD_WINDOWS = ['10m' => 600, '1h' => 3600, '6h' => 21600, '1d' => 86400, '7d' => 604800];

    /**
     * @return array{audience:string, audience_list:?array, max_sessions:?int, download_until:?DateTimeImmutable,
     *               recipients:string[], expire_when_all_viewed:bool, starts_at:?DateTimeImmutable}
     */
    public static function fromInput(array $input, ?array $user): array
    {
        $errors = [];
        $audience = (string) ($input['audience'] ?? 'anyone');
        if ($audience === '') {
            $audience = 'anyone';
        }
        if (!in_array($audience, ['anyone', 'emails', 'domains', 'workspace'], true)) {
            $errors['audience'] = 'Choose who can open this share.';
        }

        $list = self::parseList($input['audience_list'] ?? null);
        if ($audience === 'emails') {
            $list = array_map('strtolower', $list);
            foreach ($list as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors['audience_list'] = "“$email” is not a valid email address.";
                }
            }
        } elseif ($audience === 'domains') {
            $list = array_map(static fn ($d) => strtolower(ltrim($d, '@')), $list);
            foreach ($list as $domain) {
                if (!preg_match('/^(?=.{3,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $domain)) {
                    $errors['audience_list'] = "“$domain” is not a valid domain.";
                }
            }
        }
        if (in_array($audience, ['emails', 'domains'], true) && $list === []) {
            $errors['audience_list'] = 'Add at least one ' . ($audience === 'emails' ? 'email address' : 'domain') . '.';
        }
        if (count($list) > 200) {
            $errors['audience_list'] = 'Up to 200 entries.';
        }
        if ($audience === 'workspace' && ($user === null || WorkspaceService::current($user) === null)) {
            $errors['audience'] = 'Join or create a workspace to limit a share to its members.';
        }

        $recipients = array_values(array_unique(array_map('strtolower', self::parseList($input['recipients'] ?? null))));
        foreach ($recipients as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['recipients'] = "“$email” is not a valid email address.";
            }
        }
        if (count($recipients) > 50) {
            $errors['recipients'] = 'Invite up to 50 recipients at a time.';
        }

        $maxSessions = null;
        $rawSessions = $input['max_sessions'] ?? null;
        if ($rawSessions !== null && $rawSessions !== '') {
            $maxSessions = filter_var($rawSessions, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
            if ($maxSessions === false) {
                $errors['max_sessions'] = 'Choose between 1 and 100 concurrent sessions.';
                $maxSessions = null;
            }
        }

        $downloadUntil = null;
        $window = $input['download_for'] ?? null;
        if ($window !== null && $window !== '') {
            if (!isset(self::DOWNLOAD_WINDOWS[$window])) {
                $errors['download_for'] = 'Choose a download window.';
            } else {
                $downloadUntil = now()->modify('+' . self::DOWNLOAD_WINDOWS[$window] . ' seconds');
            }
        }

        // Scheduled shares: the link exists now but only opens from this moment (members only).
        $startsAt = null;
        $rawStart = $input['starts_at'] ?? null;
        if (is_string($rawStart) && trim($rawStart) !== '') {
            try {
                $startsAt = (new DateTimeImmutable($rawStart, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
                if ($startsAt < now()->modify('+59 seconds') || $startsAt > now()->modify('+90 days')) {
                    $errors['starts_at'] = 'Choose a start between one minute and 90 days from now.';
                } elseif ($user === null) {
                    $errors['starts_at'] = 'Sign in to schedule shares.';
                }
            } catch (\Throwable) {
                $errors['starts_at'] = 'Enter a valid date and time.';
            }
        }

        $advanced = $audience !== 'anyone' || $recipients !== [] || $maxSessions !== null;
        if ($advanced && $errors === []) {
            if ($user === null) {
                $errors['audience'] = 'Sign in to use advanced access rules.';
            } elseif (!PlanService::has($user, 'advanced_access') && $audience !== 'workspace') {
                $errors['audience'] = 'Advanced access rules are available on the Pro plan.';
            }
        }

        if ($errors !== []) {
            throw HttpException::validation($errors);
        }
        return [
            'audience' => $audience,
            'audience_list' => in_array($audience, ['emails', 'domains'], true) ? $list : null,
            'max_sessions' => $maxSessions,
            'download_until' => $downloadUntil,
            'recipients' => $recipients,
            'expire_when_all_viewed' => filter_var($input['expire_when_all_viewed'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'starts_at' => $startsAt,
        ];
    }

    /** @return string[] */
    public static function parseList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_string($value)) {
            $items = preg_split('/[\s,;]+/', $value) ?: [];
        } else {
            return [];
        }
        return array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $items), static fn ($v) => $v !== ''));
    }

    /** Whether an email satisfies a link's audience list. */
    public static function emailAllowed(array $link, string $email): bool
    {
        $email = strtolower(trim($email));
        $list = json_decode((string) $link['audience_list'], true) ?: [];
        return match ($link['audience']) {
            'emails' => in_array($email, $list, true),
            'domains' => in_array(substr(strrchr($email, '@') ?: '', 1), $list, true),
            default => true,
        };
    }
}
