<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\HttpException;

/** Owner lifecycle actions on a link: pause, resume, extend, revoke. */
final class ShareLinkService
{
    public function __construct(private readonly ExpirationService $expiration = new ExpirationService())
    {
    }

    public function revoke(array $link, string $reason = 'owner'): void
    {
        Database::transaction(function () use ($link, $reason) {
            $locked = $this->lock((int) $link['id']);
            if (!in_array($locked['status'], ['active', 'paused'], true)) {
                return;
            }
            $this->expiration->endLink($locked, 'revoked', $reason);
        });
        // Purge right away so revocation is also deletion.
        (new DeletionService())->purgeNow((int) $link['upload_id']);
    }

    public function pause(array $link): void
    {
        $this->transition($link, 'active', 'paused', 'link.paused');
    }

    public function resume(array $link): void
    {
        Database::transaction(function () use ($link) {
            $locked = $this->lock((int) $link['id']);
            if ($locked['status'] !== 'paused') {
                throw new HttpException(409, 'invalid_state', 'Only paused links can be resumed.');
            }
            $reason = ExpirationService::passiveEndReason($locked);
            if ($reason !== null) {
                $this->expiration->endLink($locked, 'expired', $reason);
                throw new HttpException(409, 'expired', 'This link has already expired.');
            }
            Database::update('share_links', ['status' => 'active', 'updated_at' => db_time()], 'id = ?', [$locked['id']]);
            AuditLogService::record('link.resumed', 'share_link', $locked['public_id']);
        });
    }

    /** Replaces the time rule; other rules (views, downloads) are unchanged. */
    public function updateExpiry(array $link, array $input, bool $member, int|null|false $maxSeconds = false): array
    {
        return Database::transaction(function () use ($link, $input, $member, $maxSeconds) {
            $locked = $this->lock((int) $link['id']);
            if (!in_array($locked['status'], ['active', 'paused'], true)) {
                throw new HttpException(409, 'invalid_state', 'This link has already ended.');
            }
            $current = $this->expiration->policyFor((int) $locked['id']);
            $time = $this->expiration->buildPolicy([
                'expires_in' => $input['expires_in'] ?? null,
                'expires_at' => $input['expires_at'] ?? null,
            ], $member, $maxSeconds);
            $current['expires_at'] = $time['expires_at'];
            $this->expiration->persistRules((int) $locked['id'], $current);
            AuditLogService::record('link.expiry_changed', 'share_link', $locked['public_id'], [
                'expires_at' => $current['expires_at']?->format(DATE_ATOM),
            ]);
            return $current;
        });
    }

    public function setPassword(array $link, ?string $password): void
    {
        if ($password !== null && (mb_strlen($password) < 4 || mb_strlen($password) > 200)) {
            throw HttpException::validation(['password' => 'Passwords must be 4 to 200 characters.']);
        }
        Database::update('share_links', [
            'password_hash' => $password !== null ? PasswordService::hash($password) : null,
            'access_mode' => $password !== null ? 'password' : 'open',
            'updated_at' => db_time(),
        ], 'id = ?', [$link['id']]);
        AuditLogService::record($password !== null ? 'link.password_set' : 'link.password_removed', 'share_link', $link['public_id']);
    }

    private function transition(array $link, string $from, string $to, string $event): void
    {
        Database::transaction(function () use ($link, $from, $to, $event) {
            $locked = $this->lock((int) $link['id']);
            if ($locked['status'] !== $from) {
                throw new HttpException(409, 'invalid_state', "Only $from links can be changed to $to.");
            }
            Database::update('share_links', ['status' => $to, 'updated_at' => db_time()], 'id = ?', [$locked['id']]);
            AuditLogService::record($event, 'share_link', $locked['public_id']);
        });
    }

    private function lock(int $linkId): array
    {
        $link = Database::first('SELECT * FROM share_links WHERE id = ? FOR UPDATE', [$linkId]);
        if ($link === null) {
            throw HttpException::notFound('Link not found.');
        }
        return $link;
    }
}
