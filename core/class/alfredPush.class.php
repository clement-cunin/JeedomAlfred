<?php

/**
 * Alfred Web Push — VAPID key management, push dispatch, and notification persistence.
 *
 * Uses the "piggyback" pattern: the push request carries no encrypted payload.
 * The service worker receives the push event, fetches pending notifications from
 * /api/push.php?action=pending via a fetch_token, then displays them.
 *
 * This avoids implementing RFC 8291 (AES-128-GCM payload encryption) while still
 * being fully spec-compliant — empty pushes are valid per RFC 8030.
 *
 * VAPID signing uses ES256 (ECDSA P-256 / SHA-256) via PHP's openssl extension.
 */
class alfredPush
{
    // -------------------------------------------------------------------------
    // VAPID key management
    // -------------------------------------------------------------------------

    /**
     * Generate VAPID keys if they have not been generated yet.
     */
    public static function ensureVapidKeys(): void
    {
        if ((string) config::byKey('vapid_public_key', 'alfred') !== '') {
            return;
        }
        self::generateAndSaveVapidKeys();
    }

    /**
     * (Re-)generate and persist VAPID keys. Returns the new public key (base64url).
     * Call this when rotating keys; existing subscriptions will no longer receive pushes.
     */
    public static function generateAndSaveVapidKeys(): string
    {
        $res = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if (!$res) {
            throw new \RuntimeException('openssl_pkey_new (P-256) failed: ' . openssl_error_string());
        }
        $details = openssl_pkey_get_details($res);
        $x       = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y       = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        openssl_pkey_export($res, $pem);

        // Public key: uncompressed EC point — 0x04 || x(32) || y(32) = 65 bytes
        $publicB64 = self::b64u("\x04" . $x . $y);

        config::save('vapid_public_key',  $publicB64, 'alfred');
        config::save('vapid_private_pem', $pem,       'alfred');

        return $publicB64;
    }

    /**
     * Return the VAPID public key as base64url (for use in PushManager.subscribe).
     */
    public static function getPublicKey(): string
    {
        self::ensureVapidKeys();
        return (string) config::byKey('vapid_public_key', 'alfred');
    }

    // -------------------------------------------------------------------------
    // Push dispatch (empty push — SW fetches content via /api/push.php)
    // -------------------------------------------------------------------------

    /**
     * Send an empty push notification to the given subscription.
     *
     * @param  array  $subscription  Row from alfred_push_subscription
     * @param  string $subject       VAPID "sub" claim (mailto: or https:)
     * @return bool   true if the push service accepted the request (2xx/201)
     */
    public static function send(array $subscription, string $subject = ''): bool
    {
        self::ensureVapidKeys();

        if ($subject === '') {
            $subject = 'mailto:' . (config::byKey('notification_email', 'alfred') ?: 'admin@localhost');
        }

        $endpoint = $subscription['endpoint'];
        $jwt      = self::buildVapidJwt($endpoint, $subject);
        $pubKey   = self::getPublicKey();

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_HTTPHEADER     => [
                'Authorization: vapid t=' . $jwt . ',k=' . $pubKey,
                'TTL: 86400',
                'Content-Length: 0',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err) {
            log::add('alfred', 'warning', "alfredPush::send curl error [{$endpoint}]: {$err}");
            return false;
        }

        // 404 / 410 = subscription expired — clean up so we stop sending to dead endpoints
        if ($code === 404 || $code === 410) {
            DB::Prepare(
                'DELETE FROM `alfred_push_subscription` WHERE `endpoint` = :ep',
                [':ep' => $endpoint],
                DB::FETCH_TYPE_ROW
            );
            log::add('alfred', 'info', "alfredPush::send: subscription gone (HTTP {$code}), removed [{$endpoint}]");
            return false;
        }

        if ($code >= 400) {
            log::add('alfred', 'warning', "alfredPush::send HTTP {$code} for [{$endpoint}]");
            return false;
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Subscription CRUD
    // -------------------------------------------------------------------------

    /**
     * Upsert a subscription by endpoint. Returns the fetch_token.
     */
    public static function saveSubscription(
        int    $eqLogicId,
        string $endpoint,
        string $p256dh,
        string $authKey,
        string $userAgent = ''
    ): string {
        $existing = DB::Prepare(
            'SELECT `id`, `fetch_token` FROM `alfred_push_subscription`
             WHERE `endpoint` = :ep LIMIT 1',
            [':ep' => $endpoint],
            DB::FETCH_TYPE_ROW
        );

        if ($existing) {
            DB::Prepare(
                'UPDATE `alfred_push_subscription`
                 SET `eqLogic_id` = :eq, `p256dh_key` = :p, `auth_key` = :a, `user_agent` = :ua
                 WHERE `id` = :id',
                [':eq' => $eqLogicId, ':p' => $p256dh, ':a' => $authKey,
                 ':ua' => $userAgent, ':id' => $existing['id']],
                DB::FETCH_TYPE_ROW
            );
            return $existing['fetch_token'];
        }

        $token = bin2hex(random_bytes(32));
        DB::Prepare(
            'INSERT INTO `alfred_push_subscription`
                (`eqLogic_id`, `endpoint`, `p256dh_key`, `auth_key`, `fetch_token`, `user_agent`)
             VALUES (:eq, :ep, :p, :a, :t, :ua)',
            [':eq' => $eqLogicId, ':ep' => $endpoint, ':p' => $p256dh,
             ':a'  => $authKey,   ':t'  => $token,    ':ua' => $userAgent],
            DB::FETCH_TYPE_ROW
        );
        return $token;
    }

    public static function getSubscription(int $eqLogicId): ?array
    {
        $row = DB::Prepare(
            'SELECT * FROM `alfred_push_subscription`
             WHERE `eqLogic_id` = :eq ORDER BY `created_at` DESC LIMIT 1',
            [':eq' => $eqLogicId],
            DB::FETCH_TYPE_ROW
        );
        return $row ?: null;
    }

    public static function getSubscriptionByEndpoint(string $endpoint): ?array
    {
        $row = DB::Prepare(
            'SELECT * FROM `alfred_push_subscription` WHERE `endpoint` = :ep LIMIT 1',
            [':ep' => $endpoint],
            DB::FETCH_TYPE_ROW
        );
        return $row ?: null;
    }

    public static function getSubscriptionByToken(string $token): ?array
    {
        $row = DB::Prepare(
            'SELECT * FROM `alfred_push_subscription` WHERE `fetch_token` = :t LIMIT 1',
            [':t' => $token],
            DB::FETCH_TYPE_ROW
        );
        return $row ?: null;
    }

    public static function listSubscriptions(int $eqLogicId): array
    {
        $rows = DB::Prepare(
            'SELECT `id`, `endpoint`, `user_agent`, `created_at`
             FROM `alfred_push_subscription`
             WHERE `eqLogic_id` = :eq ORDER BY `created_at` DESC',
            [':eq' => $eqLogicId],
            DB::FETCH_TYPE_ALL
        );
        return $rows ?: [];
    }

    public static function deleteSubscriptionsForEqLogic(int $eqLogicId): void
    {
        DB::Prepare(
            'DELETE FROM `alfred_push_subscription` WHERE `eqLogic_id` = :eq',
            [':eq' => $eqLogicId],
            DB::FETCH_TYPE_ROW
        );
    }

    // -------------------------------------------------------------------------
    // Notification persistence
    // -------------------------------------------------------------------------

    /**
     * Save a notification record for later retrieval by the service worker.
     */
    public static function saveNotification(
        int    $eqLogicId,
        string $title,
        string $body      = '',
        string $sessionId = ''
    ): int {
        DB::Prepare(
            'INSERT INTO `alfred_push_notification` (`eqLogic_id`, `title`, `body`, `session_id`)
             VALUES (:eq, :title, :body, :session)',
            [':eq' => $eqLogicId, ':title' => $title, ':body' => $body, ':session' => $sessionId],
            DB::FETCH_TYPE_ROW
        );
        return (int) DB::getLastInsertId();
    }

    /**
     * Return unread notifications for the subscription identified by fetch_token.
     */
    public static function getPendingByToken(string $token): array
    {
        $sub = self::getSubscriptionByToken($token);
        if (!$sub) {
            return [];
        }
        $rows = DB::Prepare(
            'SELECT `id`, `title`, `body`, `session_id`, `created_at`
             FROM `alfred_push_notification`
             WHERE `eqLogic_id` = :eq AND `read_at` IS NULL
             ORDER BY `created_at` ASC',
            [':eq' => $sub['eqLogic_id']],
            DB::FETCH_TYPE_ALL
        );
        return $rows ?: [];
    }

    /**
     * Mark all unread notifications as read for the subscription identified by fetch_token.
     */
    public static function markAllReadByToken(string $token): void
    {
        $sub = self::getSubscriptionByToken($token);
        if (!$sub) {
            return;
        }
        DB::Prepare(
            'UPDATE `alfred_push_notification`
             SET `read_at` = NOW()
             WHERE `eqLogic_id` = :eq AND `read_at` IS NULL',
            [':eq' => $sub['eqLogic_id']],
            DB::FETCH_TYPE_ROW
        );
    }

    public static function deleteNotificationsForEqLogic(int $eqLogicId): void
    {
        DB::Prepare(
            'DELETE FROM `alfred_push_notification` WHERE `eqLogic_id` = :eq',
            [':eq' => $eqLogicId],
            DB::FETCH_TYPE_ROW
        );
    }

    // -------------------------------------------------------------------------
    // VAPID JWT — ES256 (ECDSA over P-256 with SHA-256)
    // -------------------------------------------------------------------------

    private static function buildVapidJwt(string $endpoint, string $subject): string
    {
        $parsed = parse_url($endpoint);
        $aud    = $parsed['scheme'] . '://' . $parsed['host'];
        if (!empty($parsed['port'])) {
            $aud .= ':' . $parsed['port'];
        }

        $header  = self::b64u('{"typ":"JWT","alg":"ES256"}');
        $payload = self::b64u((string) json_encode([
            'aud' => $aud,
            'exp' => time() + 43200, // 12 hours
            'sub' => $subject,
        ]));

        $data = $header . '.' . $payload;
        $pem  = (string) config::byKey('vapid_private_pem', 'alfred');

        openssl_sign($data, $derSig, $pem, OPENSSL_ALGO_SHA256);

        return $data . '.' . self::b64u(self::derToRaw($derSig));
    }

    /**
     * Convert a DER-encoded ECDSA signature to raw (r || s) 64-byte format
     * as required by the JOSE ES256 specification.
     *
     *   DER: SEQUENCE { INTEGER r, INTEGER s }
     *   Raw: r (32 bytes, big-endian) || s (32 bytes, big-endian)
     */
    private static function derToRaw(string $der): string
    {
        $offset = 1; // skip SEQUENCE tag (0x30)

        // SEQUENCE length — short form (< 128) or long form
        $lenByte = ord($der[$offset++]);
        if ($lenByte & 0x80) {
            $offset += $lenByte & 0x7f;
        }

        // INTEGER r
        $offset++;                         // skip INTEGER tag (0x02)
        $rLen    = ord($der[$offset++]);
        $r       = substr($der, $offset, $rLen);
        $offset += $rLen;

        // INTEGER s
        $offset++;                         // skip INTEGER tag (0x02)
        $sLen = ord($der[$offset++]);
        $s    = substr($der, $offset, $sLen);

        // DER encodes positive integers with a leading 0x00 when the high bit is set.
        // Strip it before padding to 32 bytes.
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        return str_pad($r, 32, "\x00", STR_PAD_LEFT)
             . str_pad($s, 32, "\x00", STR_PAD_LEFT);
    }

    // -------------------------------------------------------------------------
    // Base64url helpers (RFC 4648 §5, no padding)
    // -------------------------------------------------------------------------

    public static function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $data): string
    {
        $pad = (4 - strlen($data) % 4) % 4;
        return (string) base64_decode(strtr($data . str_repeat('=', $pad), '-_', '+/'));
    }
}
