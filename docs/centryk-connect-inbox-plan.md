# Centryk Connect Inbox Plan

Saved on August 30, 2026 as the proposed implementation plan for the next Connect feature.

## Goal

Make `Centryk Connect` operational instead of informational.

Today the user can:

- connect companies
- send partner requests
- share events
- share campaigns
- send admin messages

But they still have to scan several sections to figure out what needs action. The next feature should collapse that into one company-scoped inbox with clear priorities and one-click actions.

## Product decision

Build `Partner Inbox + Action Queue` as the next Connect feature.

This should become the default top section on `connections.php` for the selected company. It can later expand into dashboard summaries and notification deep links, but the first version should live where the Connect actions already exist.

## Why this is the right next feature

It uses the systems already built:

- `company_connections`
- `company_connection_requests`
- `company_connection_event_shares`
- `company_connection_campaign_shares`
- `company_connection_messages`
- notifications already pointing back to `connections.php`

It also fixes the current UX problem: Connect already has useful primitives, but not a single command center.

## First release scope

### 1. Inbox at the top of Connect

Add a new section above the current lists:

- `Needs action`
- `Recent updates`

`Needs action` is the important part. It contains items where the selected company must decide or respond.

Initial item types:

- incoming connection requests with `Accept` / `Decline`
- incoming partner requests with `Mark fulfilled` / `Decline`
- incoming shared events with `Add to calendar` / `Decline`
- incoming shared campaigns with `Accept` / `Decline`
- unread partner admin messages with `Reply`

`Recent updates` is read-only and lower priority:

- accepted connections
- fulfilled/declined partner requests
- accepted/declined/revoked shared campaigns
- accepted/declined/revoked shared events
- recent messages already read

### 2. Priority ordering

The queue should not be a generic mixed feed. It needs deterministic ordering:

1. pending connection requests
2. pending shared campaigns
3. pending shared events
4. open incoming partner requests
5. unread messages
6. everything else in recent updates

Within each bucket, newest first.

### 3. Unread state for messages

This is the only place where the current schema is thin.

Add a lightweight read model for partner messages:

- `company_connection_message_reads`
  - `id`
  - `message_id`
  - `company_id`
  - `user_id`
  - `read_at`

Purpose:

- determine whether a message is unread for the recipient company
- support a badge count
- allow a message thread to move out of `Needs action` once opened

This is better than mutating `company_connection_messages` directly because reads are recipient-side state, not message state.

### 4. Thread preview instead of flat message dump

The current message list is a reverse chronological stream. For the inbox, treat messages as `one latest item per connection`.

First release thread rule:

- thread key = `connection_id`
- show only the most recent message in the inbox card
- clicking `Reply` scrolls to that partner card and focuses the existing message box

This avoids building a new full chat UI right now.

### 5. Deep-linkable action cards

Each inbox card should carry enough metadata to jump to the related detail section lower on the page.

Examples:

- connection request card jumps to `Requests to you`
- shared campaign card jumps to `Shared campaigns to you`
- message card jumps to that connection’s admin message form

That keeps the inbox compact while reusing the current detail blocks.

## Data/API design

### Recommended backend shape

Add a new service:

- `app/services/ConnectionInboxService.php`

Responsibilities:

- collect company-scoped inbox items
- normalize them into one shared output shape
- separate `needs_action` from `recent`
- calculate badge counts

Normalized item shape:

```php
[
    'id' => 'campaign_share:14',
    'kind' => 'campaign_share',
    'priority' => 20,
    'status' => 'pending',
    'title' => 'Summer Lunch Promo',
    'body' => 'Shared by Belize Food House',
    'company_name' => 'Belize Food House',
    'company_id' => 12,
    'connection_id' => 8,
    'created_at' => '2026-08-30 10:24:00',
    'requires_action' => true,
    'actions' => ['accept', 'decline'],
    'target_anchor' => 'incomingCampaignShareList',
]
```

### New endpoint

- `public/api/connections/inbox.php`

Response shape:

```json
{
  "success": true,
  "needs_action": [],
  "recent": [],
  "counts": {
    "needs_action": 0,
    "messages_unread": 0,
    "campaigns_pending": 0,
    "events_pending": 0,
    "requests_open": 0,
    "connections_pending": 0
  }
}
```

### Existing services to reuse

- `Connections::incomingPending()`
- `ConnectionRequestService::listForCompany()`
- `ConnectionEventShareService::listForCompany()`
- `ConnectionCampaignShareService::listForCompany()`
- `ConnectionMessageService::listForCompany()`
- `ConnectionActivityService::listForCompany()`

`ConnectionActivityService` should remain the activity feed. Do not overload it into the inbox. The inbox is action-oriented and opinionated; the activity feed is historical.

## UI plan

### Layout

At the top of `public/connections.php`:

1. `Partner Inbox`
2. `Requests to you`
3. existing sections below

Inbox structure:

- summary strip with counts
- `Needs action` stack
- `Recent updates` stack

Card design:

- clear type badge: `Campaign`, `Event`, `Request`, `Message`, `Connection`
- partner company name
- short action summary
- timestamp
- one or two primary buttons only

### Action behavior

Keep actions on the same existing endpoints:

- `respond.php`
- `request_update.php`
- `event_share_update.php`
- `campaign_share_update.php`

Do not create duplicate mutation endpoints just for inbox cards.

For `Reply`:

- no modal in v1
- scroll to the connected partner card
- expand/focus the existing admin message textarea

That is enough for a first release.

## Build order

### Phase 1

- add unread-message read table
- add `ConnectionInboxService`
- add `api/connections/inbox.php`
- render top-of-page inbox on `connections.php`
- wire inbox actions to existing endpoints

### Phase 2

- mark messages read when inbox thread is opened
- add unread count badge to Connect page header
- support anchor/deep-link behavior to lower sections

### Phase 3

- dashboard summary chip: `3 partner items need attention`
- notification bell links directly into inbox-filtered Connect view
- optional per-kind filters: `all`, `requests`, `campaigns`, `events`, `messages`

## Intentional deferrals

Do not build these in the first release:

- full chat threads
- attachment uploads
- campaign usage analytics
- message reactions or typing indicators
- per-user inbox preferences
- WebSocket/live updates

They add complexity before the core action loop is proven.

## Risks

### 1. Duplicate logic across activity and inbox

Avoid by keeping separate service responsibilities:

- `ConnectionActivityService` = historical feed
- `ConnectionInboxService` = current action queue

### 2. Message unread state becoming ambiguous

Avoid by making unread recipient-side state in its own table.

### 3. Too many cards for active companies

Avoid by:

- prioritizing `needs_action`
- collapsing messages to one latest item per connection
- limiting `recent` to a short slice, e.g. 12 items

## Recommendation

Approve this feature as:

`Partner Inbox + Action Queue on connections.php, backed by a new inbox service and a small message-read table.`

That is the highest-leverage next step because it makes every Connect feature already built easier to notice and easier to use.
