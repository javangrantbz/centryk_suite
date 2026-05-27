<?php
require_once __DIR__ . '/DB.php';

class Audit
{
    public static function log(array $event): void
    {
        try {
            DB::pdo()->prepare(
                'INSERT INTO audit_events (
                    actor_user_id,
                    target_user_id,
                    company_id,
                    event_type,
                    summary,
                    metadata_json
                ) VALUES (
                    :actor_user_id,
                    :target_user_id,
                    :company_id,
                    :event_type,
                    :summary,
                    :metadata_json
                )'
            )->execute([
                'actor_user_id' => self::nullableInt($event['actor_user_id'] ?? null),
                'target_user_id' => self::nullableInt($event['target_user_id'] ?? null),
                'company_id' => self::nullableInt($event['company_id'] ?? null),
                'event_type' => substr((string)($event['event_type'] ?? 'unknown'), 0, 80),
                'summary' => substr((string)($event['summary'] ?? ''), 0, 255),
                'metadata_json' => self::encodeMetadata($event['metadata'] ?? []),
            ]);
        } catch (Throwable $e) {
            // Keep primary flows working even if audit persistence fails.
        }
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    private static function encodeMetadata(mixed $metadata): ?string
    {
        if (!is_array($metadata) || $metadata === []) {
            return null;
        }

        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }
}
