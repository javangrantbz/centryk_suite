<?php

/**
 * Pay-per-event access for 'paid' channel visibility. Charges go straight to
 * OneLink using Centryk's own onelink_credentials (company-level - see
 * database/add_tv_payments.sql for why this doesn't bridge to OnePay's
 * payment_settings). 'subscription' visibility is out of scope here; see
 * the migration's own comment for why.
 */
class TvPaymentService
{
    /**
     * The OneLink "salt" parameter is NOT the salt column - see
     * OnePayWebhook::onelinkCredentialsSynced()'s own note, confirmed live:
     * onelink_uuid (assigned by OneLink at account creation) is what actually
     * authenticates, with the manually-entered salt column as a fallback for
     * companies never auto-provisioned through OneLinkProvisioning.
     */
    private static function credentialsForOrganization(int $organizationId): ?array
    {
        $stmt = db()->prepare(
            'SELECT o.terminal_id, o.token, o.salt, o.onelink_uuid, t.company_id
               FROM tv_organizations t
               JOIN onelink_credentials o ON o.company_id = t.company_id
              WHERE t.id = :org_id AND o.enabled = 1
              LIMIT 1'
        );
        $stmt->execute(['org_id' => $organizationId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $salt = $row['onelink_uuid'] !== null && $row['onelink_uuid'] !== '' ? $row['onelink_uuid'] : $row['salt'];
        if (trim((string)$row['terminal_id']) === '' || trim((string)$row['token']) === '' || trim((string)$salt) === '') {
            return null;
        }

        return [
            'terminal_id' => (string)$row['terminal_id'],
            'token' => (string)$row['token'],
            'salt' => (string)$salt,
        ];
    }

    /** Whether this organization's company can currently take paid TV access at all. */
    public static function isPaymentConfigured(int $organizationId): bool
    {
        return self::credentialsForOrganization($organizationId) !== null;
    }

    /**
     * Same request shape and endpoint as OnePay's onelink_pos_charge()
     * (app/services/payments/onelink_pos.php) - raw card data POSTed
     * server-side to OneLink's processPayment endpoint, per the decision to
     * match OnePay's existing pattern rather than build a separate
     * tokenized flow. Never store the raw card number or CVV past this call.
     */
    private static function chargeOneLink(array $creds, array $card, float $amount, string $description): array
    {
        $number = preg_replace('/\D+/', '', (string)($card['number'] ?? ''));
        if (strlen($number) < 13) {
            throw new InvalidArgumentException('Invalid card number.');
        }

        $expiry = preg_replace('/\D+/', '', (string)($card['expiry'] ?? ''));
        $expMonth = substr($expiry, 0, 2);
        $expYear = strlen($expiry) === 6 ? substr($expiry, 2, 4) : substr($expiry, 2, 2);

        $payload = [
            'card_number' => $number,
            'card_expiry_month' => $expMonth,
            'card_expiry_year' => $expYear,
            'card_cvv' => preg_replace('/\D+/', '', (string)($card['cvv'] ?? '')),
            'card_holder_name' => trim((string)($card['holder'] ?? '')),
            'amount' => round($amount, 2),
            'currency' => 'BZD',
            'description' => $description,
            'bp_token' => $creds['token'],
            'terminalID' => $creds['terminal_id'],
            'salt' => $creds['salt'],
        ];

        $json = json_encode($payload);
        $ch = curl_init('https://op.onelink.bz/processPayment');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: ' . strlen($json),
            ],
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('OneLink request failed: ' . $err);
        }

        $resp = json_decode($raw, true);
        if (!is_array($resp)) {
            throw new RuntimeException('Unexpected OneLink response.');
        }

        $brand = 'card';
        if (preg_match('/^4/', $number)) {
            $brand = 'visa';
        } elseif (preg_match('/^(5[1-5]|2[2-7])/', $number)) {
            $brand = 'mastercard';
        } elseif (preg_match('/^3[47]/', $number)) {
            $brand = 'amex';
        }

        $resp['_card_last4'] = substr($number, -4);
        $resp['_card_brand'] = $brand;
        return $resp;
    }

    /**
     * Charges a viewer for one-time access to a 'paid' event and grants
     * tv_event_access on success. Idempotent: a second call for a user who
     * already has a successful payment on record for this event returns
     * success immediately without charging again, so a double form
     * submission (or a viewer retrying after a slow response) can never
     * double-charge them.
     *
     * Access is granted ONLY from this method, and ONLY after OneLink's own
     * response confirms success - never from a client-supplied "I paid"
     * claim. api/payments/charge_for_access.php is the sole caller; the
     * same class of bug that let pay_link.php mark orders paid for free
     * must not happen here.
     *
     * @return array{success:bool,message:string}
     */
    public static function chargeForEventAccess(int $eventId, int $userId, array $card): array
    {
        $event = db()->prepare('SELECT * FROM tv_events WHERE id = :id LIMIT 1');
        $event->execute(['id' => $eventId]);
        $event = $event->fetch();
        if (!$event) {
            return ['success' => false, 'message' => 'Event not found.'];
        }

        $channel = db()->prepare('SELECT visibility FROM tv_channels WHERE id = :id LIMIT 1');
        $channel->execute(['id' => $event['channel_id']]);
        $visibility = (string)$channel->fetchColumn();
        if ($visibility !== 'paid') {
            return ['success' => false, 'message' => 'This event does not require payment.'];
        }

        $price = (float)($event['price_amount'] ?? 0);
        if ($price <= 0) {
            return ['success' => false, 'message' => 'This event has no price configured.'];
        }

        $already = db()->prepare(
            "SELECT id FROM tv_payments WHERE event_id = :eid AND user_id = :uid AND status = 'succeeded' LIMIT 1"
        );
        $already->execute(['eid' => $eventId, 'uid' => $userId]);
        if ($already->fetchColumn()) {
            self::ensureAccessGrant($eventId, $userId);
            return ['success' => true, 'message' => 'Already paid.'];
        }

        $creds = self::credentialsForOrganization((int)$event['organization_id']);
        if (!$creds) {
            return ['success' => false, 'message' => 'This organization is not set up to accept payments yet.'];
        }

        try {
            $resp = self::chargeOneLink($creds, $card, $price, 'Centryk TV: ' . $event['title']);
        } catch (Throwable $e) {
            self::recordPayment($event, $userId, $price, 'failed', null, null, null, $e->getMessage());
            return ['success' => false, 'message' => 'Payment failed: ' . $e->getMessage()];
        }

        $succeeded = !empty($resp['success']) || !empty($resp['status']) && strtolower((string)$resp['status']) === 'success';
        $reference = '';
        foreach (['reference', 'transaction_id', 'session_id', 'id'] as $k) {
            $v = trim((string)($resp[$k] ?? ''));
            if ($v !== '') {
                $reference = $v;
                break;
            }
        }

        if (!$succeeded) {
            $message = (string)($resp['message'] ?? 'Card declined.');
            self::recordPayment($event, $userId, $price, 'failed', $reference ?: null, $resp['_card_last4'] ?? null, $resp['_card_brand'] ?? null, $message);
            return ['success' => false, 'message' => $message];
        }

        self::recordPayment($event, $userId, $price, 'succeeded', $reference ?: null, $resp['_card_last4'] ?? null, $resp['_card_brand'] ?? null, null);
        self::ensureAccessGrant($eventId, $userId);

        tv_record_audit((int)$event['organization_id'], $userId, 'tv_payment_succeeded', 'event', $eventId, [
            'amount' => $price,
            'reference' => $reference,
        ]);

        return ['success' => true, 'message' => 'Payment successful.'];
    }

    private static function ensureAccessGrant(int $eventId, int $userId): void
    {
        db()->prepare(
            'INSERT IGNORE INTO tv_event_access (event_id, user_id, granted_by, created_at) VALUES (:eid, :uid, NULL, NOW())'
        )->execute(['eid' => $eventId, 'uid' => $userId]);
    }

    private static function recordPayment(array $event, int $userId, float $amount, string $status, ?string $reference, ?string $last4, ?string $brand, ?string $failureMessage): void
    {
        db()->prepare(
            'INSERT INTO tv_payments
                (organization_id, event_id, user_id, amount, currency, status, provider,
                 provider_reference, card_last4, card_brand, failure_message, created_at, updated_at)
             VALUES
                (:org_id, :event_id, :user_id, :amount, :currency, :status, "onelink",
                 :reference, :last4, :brand, :failure_message, NOW(), NOW())'
        )->execute([
            'org_id' => $event['organization_id'],
            'event_id' => $event['id'],
            'user_id' => $userId,
            'amount' => $amount,
            'currency' => $event['price_currency'] ?? 'BZD',
            'status' => $status,
            'reference' => $reference,
            'last4' => $last4,
            'brand' => $brand,
            'failure_message' => $failureMessage,
        ]);
    }
}
