<?php
require_once __DIR__ . '/../core/Connections.php';
require_once __DIR__ . '/ConnectionActivityService.php';
require_once __DIR__ . '/ConnectionCampaignShareService.php';
require_once __DIR__ . '/ConnectionEventShareService.php';
require_once __DIR__ . '/ConnectionMessageService.php';
require_once __DIR__ . '/ConnectionRequestService.php';

class ConnectionInboxService
{
    public static function listForCompany(int $companyId): array
    {
        if ($companyId <= 0) {
            return [
                'needs_action' => [],
                'recent' => [],
                'counts' => [
                    'needs_action' => 0,
                    'messages_unread' => 0,
                    'campaigns_pending' => 0,
                    'events_pending' => 0,
                    'requests_open' => 0,
                    'connections_pending' => 0,
                ],
            ];
        }

        $needsAction = [];

        foreach (Connections::incomingPending($companyId) as $row) {
            $needsAction[] = [
                'id' => 'connection:' . (int) $row['id'],
                'kind' => 'connection',
                'priority' => 10,
                'status' => 'pending',
                'title' => 'Connection request',
                'body' => (string) ($row['company_name'] ?? ''),
                'company_name' => (string) ($row['company_name'] ?? ''),
                'company_id' => (int) ($row['company_id'] ?? 0),
                'connection_id' => (int) ($row['id'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'requires_action' => true,
                'actions' => ['accept', 'decline'],
                'target_anchor' => 'incomingList',
                'color' => '#7c3aed',
            ];
        }

        $requests = ConnectionRequestService::listForCompany($companyId);
        foreach (($requests['incoming'] ?? []) as $row) {
            if (($row['status'] ?? '') !== 'open') {
                continue;
            }
            $needsAction[] = [
                'id' => 'request:' . (int) $row['id'],
                'kind' => 'request',
                'priority' => 40,
                'status' => 'open',
                'title' => (string) ($row['subject'] ?? 'Partner request'),
                'body' => (string) ($row['requester_company_name'] ?? ''),
                'company_name' => (string) ($row['requester_company_name'] ?? ''),
                'company_id' => (int) ($row['requester_company_id'] ?? 0),
                'connection_id' => (int) ($row['connection_id'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'requires_action' => true,
                'actions' => ['fulfilled', 'decline'],
                'target_anchor' => 'incomingRequestList',
                'color' => '#0f172a',
                'meta' => [
                    'request_id' => (int) ($row['id'] ?? 0),
                    'request_type' => (string) ($row['request_type'] ?? 'general'),
                    'details' => (string) ($row['details'] ?? ''),
                ],
            ];
        }

        $events = ConnectionEventShareService::listForCompany($companyId);
        foreach (($events['incoming'] ?? []) as $row) {
            if (($row['status'] ?? '') !== 'pending') {
                continue;
            }
            $needsAction[] = [
                'id' => 'event_share:' . (int) $row['id'],
                'kind' => 'event_share',
                'priority' => 30,
                'status' => 'pending',
                'title' => (string) ($row['title'] ?? 'Shared event'),
                'body' => (string) ($row['owner_company_name'] ?? ''),
                'company_name' => (string) ($row['owner_company_name'] ?? ''),
                'company_id' => (int) ($row['owner_company_id'] ?? 0),
                'connection_id' => (int) ($row['connection_id'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'requires_action' => true,
                'actions' => ['accept', 'decline'],
                'target_anchor' => 'incomingEventShareList',
                'color' => '#14b8a6',
                'meta' => [
                    'share_id' => (int) ($row['id'] ?? 0),
                    'event_date' => (string) ($row['event_date'] ?? ''),
                    'event_type' => (string) ($row['event_type'] ?? ''),
                ],
            ];
        }

        $campaigns = ConnectionCampaignShareService::listForCompany($companyId);
        foreach (($campaigns['incoming'] ?? []) as $row) {
            if (($row['status'] ?? '') !== 'pending') {
                continue;
            }
            $needsAction[] = [
                'id' => 'campaign_share:' . (int) $row['id'],
                'kind' => 'campaign_share',
                'priority' => 20,
                'status' => 'pending',
                'title' => (string) ($row['title'] ?? 'Shared campaign'),
                'body' => (string) ($row['owner_company_name'] ?? ''),
                'company_name' => (string) ($row['owner_company_name'] ?? ''),
                'company_id' => (int) ($row['owner_company_id'] ?? 0),
                'connection_id' => (int) ($row['connection_id'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'requires_action' => true,
                'actions' => ['accept', 'decline'],
                'target_anchor' => 'incomingCampaignShareList',
                'color' => '#ec4899',
                'meta' => [
                    'share_id' => (int) ($row['id'] ?? 0),
                    'offer_text' => (string) ($row['offer_text'] ?? ''),
                ],
            ];
        }

        $messageSummary = ConnectionMessageService::unreadIncomingSummary($companyId);
        foreach (($messageSummary['threads'] ?? []) as $row) {
            $needsAction[] = [
                'id' => 'message:' . (int) $row['id'],
                'kind' => 'message',
                'priority' => 50,
                'status' => 'unread',
                'title' => 'Unread partner message',
                'body' => (string) ($row['sender_company_name'] ?? ''),
                'company_name' => (string) ($row['sender_company_name'] ?? ''),
                'company_id' => (int) ($row['sender_company_id'] ?? 0),
                'connection_id' => (int) ($row['connection_id'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'requires_action' => true,
                'actions' => ['reply'],
                'target_anchor' => 'connection-card-' . (int) ($row['connection_id'] ?? 0),
                'color' => '#f59e0b',
                'meta' => [
                    'message_id' => (int) ($row['id'] ?? 0),
                    'message' => (string) ($row['message'] ?? ''),
                    'sender_name' => (string) ($row['sender_name'] ?? ''),
                ],
            ];
        }

        usort($needsAction, static function (array $a, array $b): int {
            $p = ((int) ($a['priority'] ?? 999)) <=> ((int) ($b['priority'] ?? 999));
            if ($p !== 0) {
                return $p;
            }
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        $recent = array_values(array_filter(
            ConnectionActivityService::listForCompany($companyId, 12),
            static fn (array $item): bool => ($item['kind'] ?? '') !== 'message'
        ));

        return [
            'needs_action' => $needsAction,
            'recent' => $recent,
            'counts' => [
                'needs_action' => count($needsAction),
                'messages_unread' => (int) ($messageSummary['count'] ?? 0),
                'campaigns_pending' => count(array_filter($needsAction, static fn (array $item): bool => ($item['kind'] ?? '') === 'campaign_share')),
                'events_pending' => count(array_filter($needsAction, static fn (array $item): bool => ($item['kind'] ?? '') === 'event_share')),
                'requests_open' => count(array_filter($needsAction, static fn (array $item): bool => ($item['kind'] ?? '') === 'request')),
                'connections_pending' => count(array_filter($needsAction, static fn (array $item): bool => ($item['kind'] ?? '') === 'connection')),
            ],
        ];
    }
}
