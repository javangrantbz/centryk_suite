# Centryk Connect Expansion Ideas

Saved on August 29, 2026 as a working product reference for future planning and implementation.

## Current read on Centryk Connect

Right now `Centryk Connect` is basically a trusted business-to-business permission layer: send request, accept, then unlock cross-company sharing, with Vision Board playlist sharing as the first real use. That is a solid base. The next step is to turn "connected" into "we actually work together here."

The strongest additions are the ones that create repeat use, not just a longer feature list.

## Expansion list

1. Shared campaigns

Let one business publish a reusable campaign bundle to connected businesses: banner set, promo images, QR link, caption copy, start/end dates, optional brand rules. This is a natural extension of the current Vision Board share model, but more business-oriented than just playlists.

2. Content request workflow

Let connected businesses request assets from each other:
"Send me your latest logo pack"
"Share your September specials"
"Need 3 screen ads for our lobby TV"

This makes the relationship two-way and gives Connect a reason to be opened regularly.

3. Partner directory card

Each connected business should have a richer profile:
business summary, categories, locations, contact person, social links, approved assets, collaboration preferences. Then Connect becomes a usable partner network, not just a permission toggle.

4. Access scopes per connection

Instead of one generic "connected" state, add per-connection permissions:
`can_share_signage`
`can_request_assets`
`can_view_publications`
`can_offer_promotions`
`can_message_admins`

This gives room to expand safely without making every connection too open.

5. Partner inbox / activity feed

A shared feed for:
new share requests, accepted connections, updated campaign packs, expiring promos, removed assets, unread messages. Without a feed, users forget the feature exists.

6. Co-promotion marketplace

A business can post an offer to connected companies:
"Promote our event this week"
"Display this coupon and get reciprocal placement"
"Looking for 5 retail partners in Belize City"

That gives Connect a revenue-adjacent use case.

7. Referral tracking

If one business promotes another, give them a trackable QR/link and simple stats:
views, scans, clicks, leads, redemptions. This turns Connect from "sharing content" into "measuring partnership value."

8. Shared event distribution

For concerts, sales, launches, church events, tourism events, etc:
publish once, send to connected partners, let them accept and schedule locally. This is probably one of the easiest "meaty" wins given the existing signage/share logic.

9. Template library for connected businesses

Businesses can share ready-made templates:
TV slides, promo cards, coupons, event slides, recruitment notices, donation prompts. Templates are more reusable than raw media files.

10. Admin-to-admin messaging

Keep it lightweight and business-only:
thread per connection, thread per content request, thread per campaign. No need to build full chat. Just enough to coordinate sharing and approvals.

11. Reciprocal offers / member perks

Connected businesses can create offers visible only to partners:
staff discount, partner rate, venue rental deal, wholesale pricing. This gives Connect value even outside signage.

12. Shared resource hub

Allow connected businesses to share useful docs or assets:
menus, rate sheets, press kits, sponsor decks, logos, promo videos, printable flyers. Versioning and expiry would matter here.

13. Time-limited connection types

Examples:
`campaign partner`
`vendor`
`client`
`sister brand`
`event sponsor`

Some features can behave differently by relationship type.

14. Suggested connections

Recommend companies based on category, location, apps used, shared audiences, or mutual connections. Otherwise network features grow too slowly.

15. Cross-app unlocks

The long-term move is making Connect the trust layer for other Centryk apps:
Vision Board shares
invoice/customer referrals
event promotion
banking/payment collaboration
staff notices
shared directories

If Connect only powers one page, it stays thin. If it powers the suite, it becomes strategic.

## Recommended implementation order

If prioritizing for product impact:

1. `Connection profiles + permission scopes`
2. `Shared campaigns / event distribution`
3. `Partner inbox + notifications`
4. `Referral tracking`
5. `Content requests + lightweight messaging`

That sequence builds from the existing model without needing a full social network.

## Positioning

"Centryk Connect helps businesses build trusted partnerships, exchange approved content, and track the value of those collaborations."
