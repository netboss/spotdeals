# SpotDeals Project Status

**Last Updated:** 2026-07-12

------------------------------------------------------------------------

# Project Overview

SpotDeals is a local restaurant, food, drink, happy hour, and deal
discovery platform focused on helping users find nearby offers and
venues.

## Primary Goals

1.  Increase local discovery and search visibility.
2.  Increase repeat visits and engagement.
3.  Grow venue inventory and deal inventory.
4.  Improve recommendation quality and location relevance.
5.  Build sustainable traffic before investing heavily in advanced
    features.

# Operations & Monitoring

## Project Rule

If an operational task depends on remembering to run a command manually, it is **not finished**.

Monitoring must proactively notify administrators.

## Operations Roadmap

- [ ] Disk usage email/Slack alerts
- [ ] Memory usage alerts
- [ ] Disk inode alerts
- [ ] Backup failure alerts
- [ ] SSL certificate expiry alerts
- [ ] Failed cron alerts
- [ ] Search indexing failure alerts

## Current Production Disk Monitor Status

- [x] Production disk monitor installed.
- [x] Systemd timer enabled.
- [x] Warning threshold configured (50%).
- [ ] Automatic administrator notification (email/Slack) still pending.
- [ ] Investigate the root cause of the rapid production disk growth.

## Goal

Eliminate the need for administrators to remember routine infrastructure checks.

Every recurring operational task should either:

- run automatically,
- generate an alert,
- or both.

The system should notify administrators before outages occur instead of relying on someone remembering to SSH into the server and manually check its health.

## Current Master Action Plan

This is the primary project checklist. Use it to resume work without
reconstructing decisions from old chats.

### Status legend

-   [x] Done / live
-   \[\~\] Partially implemented or needs correction
-   [ ] Pending
-   \[!\] Product decision required
-   \[D\] Deferred until a later phase

### Foundation already completed

-   [x] Search API and Solr are operational.
-   [x] Smart location and near-me search are live.
-   [x] Recommendation behavior is implemented inside
    `spotdeals_search_smart_location`.
-   [x] Venue/deal voting and activity signals exist.
-   [x] SEO city and city/category landing pages exist.
-   [x] Public venue/deal suggestions and admin moderation exist.
-   [x] Venue claim and ownership workflow exists.
-   [x] Stripe monthly/yearly Pro subscriptions, Checkout, Billing
    Portal, and webhooks exist.
-   [x] Free-versus-Pro venue ownership gating exists.
-   [x] GA event tracking and first-party search insights exist.
-   [x] Spanish/multilingual foundation exists.

### Recommended execution order

#### Phase 1 --- Correct and document the existing owner-notification path

**Current status:** audit and Phase 1 code corrections completed.
Primary-owner notification and manual resend are verified. Remaining
recipient-fallback, no-recipient, logging, and duplicate-conversion tests
are still pending. The existing Claim this listing workflow is already
fully tested and has been live in production for months; it is not part
of the remaining Phase 1 test scope.

-   [x] Audit all active usages of `PlanTierService` and
    `ClaimEligibilityService`.
-   [x] Confirm the canonical ownership fields:
    -   `field_primary_owner_user`
    -   `field_claimed_by`
    -   `field_claim_contact_email`
    -   `field_claimant_user`
    -   `field_claim_status`
-   [x] Confirm that `field_owner`, `field_claimant`, and `field_status`
    do not exist in the exported configuration and are legacy
    references.
-   [x] Confirm that `PlanTierService` and `ClaimEligibilityService` are
    registered but have no active consumers elsewhere in the supplied
    custom code.
-   [x] Record a separate cleanup task for the two legacy services; do
    not mix that cleanup into the owner-notification correction.
-   [x] Replace the two separate notification implementations with one
    authoritative notification path.
-   [x] Resolve recipients in this order:
    1.  `field_primary_owner_user` user email
    2.  `field_claimed_by` user email
    3.  `field_claim_contact_email`
-   [x] The public suggestion submitter is never used as an
    owner-notification fallback. If the submitter is also the owner,
    they still receive the message through the canonical owner field and
    are audited as that owner type.
-   [x] Ensure the submitter is never used as a fallback or incorrectly
    recorded as the owner.
-   [x] Add owner-facing email copy and an actionable `/account/upgrade`
    CTA.
-   [x] Use `/account/upgrade` as the temporary Phase 1 email CTA until
    the owner review page is designed in Phase 3.
-   [x] Store notification recipient email, recipient source/type, last
    attempt time, attempt count, and failure reason.
-   [x] Log sent, failed, and no-recipient outcomes.
-   [x] Add a safe admin resend action that records every attempt
    without creating duplicate content.
-   [x] Normalize suggestion status handling so automatic and manual
    notifications produce the same state.
-   [x] Add server-side duplicate-conversion protection before every
    create action; do not rely only on hiding operations in the admin
    table.
-   [ ] Test automatic notification, manual resend, fallback order, mail
    failure, missing recipient, and repeated create requests locally.

**Phase 1 test tracker (updated 2026-07-11):**

The tests below cover the new gated-suggestion owner-notification code
only. They do **not** require retesting the existing Claim this listing
workflow.

1.  [x] **Configuration**
    - `free_deals_per_venue = 0`
    - `owner_notifications_enabled = true`
    - SMTP disabled locally before email testing
    - Mailpit available

2.  [x] **Primary-owner notification**
    - Venue: `Cane`
    - Suggestion: `Phase 1 Primary Owner Test`
    - `free_limit_blocked = 1`
    - `status = owner_notified`
    - `owner_notified = 1`
    - `owner_notification_recipient_type = primary_owner_user`
    - Recipient resolved from `field_primary_owner_user`
    - Mail captured in Mailpit
    - No failure reason recorded

3.  [x] **Manual resend**
    - Admin resend action completed successfully
    - Attempt count increased to `3`
    - Recipient remained unchanged
    - No failure reason recorded
    - No duplicate suggestion was created

4.  [x] **`field_claimed_by` recipient fallback**
    - This is **not** a Claim this listing test.
    - Do not create or approve another claim just for this test.
    - Use an existing test venue or temporarily adjust a local test
      venue so:
      - `field_primary_owner_user` is empty
      - `field_claimed_by` references a test user
    - Submit a gated deal suggestion.
    - Expected:
      `owner_notification_recipient_type = claimed_by_user`
    - Verified with **The Downwind Cafe** using `field_claimed_by` after clearing `field_primary_owner_user`.

5.  [x] **`field_claim_contact_email` recipient fallback**
    - Use an existing local test venue or temporarily adjust one so:
      - `field_primary_owner_user` is empty
      - `field_claimed_by` is empty
      - `field_claim_contact_email` contains a safe test address
    - Submit a gated deal suggestion.
    - Expected:
      `owner_notification_recipient_type = claim_contact_email`
    - Verified with **The Downwind Cafe** after clearing both owner fields.

6.  [ ] **No-recipient handling**
    - Use a local test venue where all three recipient sources are empty:
      - `field_primary_owner_user`
      - `field_claimed_by`
      - `field_claim_contact_email`
    - Submit a gated deal suggestion.
    - Expected:
      - suggestion saves
      - no exception
      - no email sent
      - `owner_notified = 0`
      - attempt count increments
      - failure reason is stored

7.  [ ] **Duplicate deal-conversion protection**
    - Create a deal from one eligible suggestion.
    - Attempt the same create action again.
    - Expected:
      - second request rejected
      - one deal node only
      - suggestion remains linked to the original deal

8.  [ ] **Log verification**
    - Confirm watchdog entries distinguish:
      - successful notification
      - resend
      - failed delivery
      - no-recipient outcome

9.  [ ] **Final local cleanup after remaining tests**
    - Restore `free_deals_per_venue = 1`
    - Restore the intended local notification setting
    - Reinstall/reconfigure SMTP only after Mailpit testing is complete

#### Operations & Infrastructure

- [ ] Complete production monitoring and alerting.
- [ ] Configure disk usage notifications.
- [ ] Configure memory usage notifications.
- [ ] Configure SSL certificate expiry notifications.
- [ ] Configure backup failure notifications.
- [ ] Investigate and resolve the root cause of rapid production disk usage growth.

**Current verified database state for the primary-owner test:**

```text
id: 3
venue: Cane
deal: Phase 1 Primary Owner Test
status: owner_notified
free_limit_blocked: 1
owner_notified: 1
owner_notification_recipient_type: primary_owner_user
owner_notification_attempt_count: 3
owner_notification_failure_reason: empty
```

**Important scope boundary**

The Claim this listing workflow, claim creation, claim moderation,
claim approval, and claim-related admin email were already fully tested
and have been live in production for months. Do not repeat those tests
as part of Phase 1. The remaining Phase 1 work tests only how the new
notification code falls back across existing venue ownership/contact
fields.

**Known separate Claim this listing bug discovered 2026-07-12**

-   Production example: venue `Cane`.
-   The related claim record is `Approved`.
-   The venue edit form still shows the venue-level `Claim Status` as
    `Unclaimed`.
-   Expected behavior: approving a claim must automatically synchronize
    the claimed venue's `Claim Status` to `Claimed`.
-   This is a separate Claim this listing regression and is not part of
    the current Suggest Phase 1 test pass.
-   Do not interrupt or expand the current Suggest testing to fix it.
-   After all remaining Suggest Phase 1 tests pass and local cleanup is
    complete, audit and correct the claim-approval synchronization path.
-   The future fix must be tested for both the canonical/default
    translation and translated venue edit forms so the displayed state
    is consistent.

**Claim-status rule for the remaining Suggest Phase 1 tests**

-   Leave the venue-level `Claim Status` unchanged during Tests 4-8.
-   For the current `Cane` test data, it may remain `Unclaimed` while the
    separate synchronization bug is pending.
-   Do not create, edit, approve, or reapprove a claim for these tests.
-   Do not change a claim record to `Approved` as part of these tests.
-   The recipient-fallback tests are controlled only by these existing
    venue fields:
    1. `field_primary_owner_user`
    2. `field_claimed_by`
    3. `field_claim_contact_email`
-   Each test must still use a **Deal-only suggestion matched to the
    existing venue**.

**Audit findings:**

1.  `PlanTierService` queries nonexistent `field_owner`,
    `field_claimant`, and `field_status` fields.
2.  `ClaimEligibilityService` queries the same legacy fields.
3.  The active claim workflow uses `field_primary_owner_user`,
    `field_claimed_by`, `field_claim_contact_email`,
    `field_claimant_user`, and `field_claim_status`.
4.  The public submission form and the module-level manual notification
    path currently implement recipient resolution separately and behave
    differently.
5.  The public form currently falls back to the suggestion submitter and
    can mark that outcome as `owner_notified`; this is not a reliable
    owner notification.
6.  The manual notification path currently checks only
    `field_primary_owner_user`, so it misses valid `field_claimed_by`
    and `field_claim_contact_email` fallbacks.
7.  The current email has no owner action URL. It does not currently
    contain an admin-dashboard URL, but it also does not provide a
    usable upgrade/review CTA.
8.  Automatic notification can leave the suggestion status as `new`,
    while the manual path changes it to `owner_notified`; status
    handling is inconsistent.
9.  The admin table hides create operations after publication, but the
    create controller methods do not consistently reject a repeated
    request when `created_entity_id` is already set. Server-side
    protection is still required.

**Separate legacy-service cleanup task:**

-   `PlanTierService` and `ClaimEligibilityService` reference
    `field_owner`, `field_claimant`, and `field_status`.
-   Those fields do not exist in the exported configuration.
-   Both services are registered, but no active consumers were found
    elsewhere in the supplied custom code.
-   Do not modify or remove these services as part of Phase 1. Audit and
    retire or correct them in a separate tested issue.

**Files expected to change for the Phase 1 implementation:**

-   `web/modules/custom/spotdeals_revenue/spotdeals_revenue.module`
-   `web/modules/custom/spotdeals_revenue/src/Form/SpotdealsSuggestionForm.php`
-   `web/modules/custom/spotdeals_revenue/src/Controller/SuggestionAdminController.php`
-   `web/modules/custom/spotdeals_revenue/spotdeals_revenue.install`
-   Possibly
    `web/modules/custom/spotdeals_revenue/spotdeals_revenue.routing.yml`
    only if resend behavior needs a distinct route.

**Functions/areas that must remain untouched during Phase 1:**

-   Stripe Checkout, Billing Portal, and webhook logic.
-   Claim approval and ownership assignment.
-   Suggestion venue matching and geocoding.
-   Search indexing/finalization logic.
-   Sponsored-slot groundwork.
-   Recommendation, analytics, and theme behavior.

**Completion evidence:** a claimed venue receives the blocked-suggestion
email at the correct owner address; fallback resolution is auditable;
the CTA is owner-facing; sent/failed/no-recipient outcomes are visible
to admins; resend is safe; automatic and manual flows behave
consistently; repeated create requests cannot create duplicate content.

#### Phase 2 --- Approve the Free and Pro business rules

-   \[!\] Decide the number of active owner-managed deals allowed per
    venue on Free.
-   \[!\] Decide the number of active owner-managed deals allowed per
    venue on Pro.
-   \[!\] Decide whether limits apply per venue, per owner account, or
    both.
-   \[!\] Decide whether pending/unpublished deals count.
-   [x] Recommended rule: expired deals do not count.
-   \[!\] Decide downgrade behavior for owners above the Free allowance.
-   \[D\] Defer one-time deal-credit purchases until subscription limits
    are proven.

**Recommended MVP:**

  -----------------------------------------------------------------------
  Capability                               Free                       Pro
  ------------------- ------------------------- -------------------------
  Claimed venues                              1                 Unlimited

  Active                       Decision pending          Decision pending
  owner-managed deals
  per venue

  Public suggestions                        Yes                       Yes
  accepted

  Sponsored campaigns             Not available        Future paid add-on

  Owner analytics            Basic/no dashboard        Future Pro feature
  -----------------------------------------------------------------------

**Completion evidence:** the final entitlement table and downgrade rules
are written here before code changes begin.

#### Phase 3 --- Define the gated-suggestion owner journey

-   [ ] Define the owner-facing page opened from the notification.
-   [ ] Show the suggestion, venue, current plan, allowance, and gating
    reason.
-   [ ] Allow an entitled owner to review/edit/publish.
-   [ ] Show `/account/upgrade` when the current plan blocks
    publication.
-   [ ] Allow the owner to dismiss an unwanted suggestion.
-   [ ] Define statuses such as gated, owner notified, accepted,
    dismissed, and converted.
-   [ ] Keep manual admin review as a fallback.

**Completion evidence:** agreed page flow, status transitions, email
CTA, and duplicate-prevention behavior.

#### Phase 4 --- Connect deal entitlement enforcement to Stripe Pro

-   [ ] Create one authoritative entitlement service for deal limits.
-   [ ] Read plan state from existing Stripe-synchronized user fields.
-   [ ] Apply the same rule to owner-created deals and suggestion
    conversion.
-   [ ] Keep Stripe webhooks as the source of truth.
-   [ ] Add user-facing limit and upgrade messages.
-   [ ] Test Free, Pro, canceled, past-due, unpaid, and downgraded
    accounts.
-   [ ] Do not delete or automatically unpublish existing deals on
    downgrade.

**Completion evidence:** a verified Stripe upgrade changes access; a
downgrade blocks only new publishing according to the approved rule; a
gated suggestion converts once when entitlement permits.

#### Phase 5 --- Clarify the revenue settings form

-   [ ] Rename or clarify `Free suggested deals per venue` so it cannot
    be mistaken for a Stripe plan allowance.
-   [ ] Explain prominently that a value of `0` gates every matched
    existing-venue deal suggestion.
-   [ ] Keep public-suggestion gating separate from owner-managed deal
    entitlements unless intentionally merged.
-   [ ] Warn admins if owner notifications are enabled before a complete
    owner action route exists.
-   \[D\] Keep the promoted markup slot as an experimental placeholder
    until real campaigns exist.

#### Phase 6 --- Build sponsored listings MVP

-   [ ] Create sponsored campaign records linked to a venue or deal.
-   [ ] Store owner, start/end dates, status, city, and optional
    category targeting.
-   [ ] Add clear `Sponsored` disclosure.
-   [ ] Add deterministic placement and basic rotation.
-   [ ] Record first-party impressions and clicks.
-   [ ] Begin with admin-created campaigns.
-   \[D\] Defer self-service purchasing, auctions, bidding, and
    ad-network complexity.

#### Phase 7 --- Build the owner analytics MVP

-   [ ] Restrict each owner to their own venues and deals.
-   [ ] Show venue views, deal views, outbound clicks, shares,
    suggestion activity, and sponsored performance.
-   [ ] Add 7-day and 30-day views with previous-period comparison.
-   [ ] Add a venue selector for owners with multiple venues.
-   [ ] Store only first-party events needed for the owner-facing
    product.
-   \[D\] Defer weekly emails, benchmarking, and unsupported conversion
    claims.

#### Phase 8 --- Evaluate ad and referral revenue

-   [ ] Measure whether traffic is sufficient for external ads.
-   [ ] Evaluate relevant reservation, event, delivery, and
    local-experience affiliate programs.
-   [ ] Document attribution, disclosure, privacy, and reporting
    requirements.
-   [ ] Make a written go/no-go decision.
-   \[D\] Do not implement this before subscriptions and sponsored
    listings are measurable.

#### Parallel track --- Legal and subscription readiness

-   [ ] Terms of Service.
-   [ ] Privacy Policy.
-   [ ] Business Listing Policy.
-   [ ] DMCA/copyright process.
-   [ ] Cookie disclosure/policy.
-   [ ] Business verification and claim policy.
-   [ ] Subscription/payment terms aligned with the final Pro offering.

### Immediate next task

1.  Continue only the unfinished items in the **Phase 1 test tracker**.
2.  Complete final local cleanup after all Suggest tests pass.
3.  Then fix the separate Claim this listing synchronization bug where
    an approved claim leaves the venue-level `Claim Status` as
    `Unclaimed` instead of `Claimed`.
3a. After the Phase 1 deployment, add a small UI enhancement: replace the hidden **Claim this listing** link on claimed venues with a passive **✓ Claimed** status indicator (not a button, do not expose owner identity).
4.  Do not retest or modify Claim this listing during the current
    Suggest test pass.
5.  Do not begin sponsored listings, owner analytics, referral revenue,
    or new Stripe products before Phases 1--4 are settled.

------------------------------------------------------------------------

# Development Environment

## Local Development

Uses DDEV.

Common commands:

``` bash
ddev drush cr
ddev drush cim -y
ddev drush cex -y
```

Do NOT suggest:

``` bash
drush cr
```

for local development.

## Production

Production server has direct Drush access.

Common commands:

``` bash
drush cr
drush cim -y
drush cex -y
```

Do NOT suggest:

``` bash
ddev drush cr
```

for production.

Always distinguish between local and production environments.

## Local Email Testing (IMPORTANT)

Before testing **any** email functionality locally:

1.  Disable the Drupal SMTP module.

``` bash
cd /var/www/html/spotdeals

ddev drush pm:uninstall smtp -y
ddev drush cr
```

2.  Verify Drupal is using PHP mail:

``` bash
ddev drush cget system.mail
```

Expected:

``` text
interface:
  default: php_mail
```

3.  Only then perform email testing through DDEV Mailpit.

**Never begin local email testing with the Drupal SMTP module enabled.**

This rule exists because doing so previously caused hours of unnecessary
debugging by bypassing the expected Mailpit workflow.

**Safety rule:** Never use a real customer or venue-owner email address
for local testing. Use Mailpit, a dedicated test account, or a
`.invalid` address.

------------------------------------------------------------------------

# Code Change Rules

When modifying code:

1.  Use the attached files as the source of truth.

2.  Modify only the requested functionality.

3.  Do not remove unrelated logic.

4.  Do not refactor unrelated code.

5.  Return only files that actually changed.

6.  Return complete files, never patches or snippets.

7.  Clearly identify:

    -   changed functions
    -   unchanged functions
    -   required Drush commands

Before generating code:

-   Identify files to be modified.
-   Identify functions to be modified.
-   Identify functions that must remain untouched.

------------------------------------------------------------------------

# JavaScript Rules

When returning JavaScript files:

Use:

``` text
filename.js.update
```

instead of:

``` text
filename.js
```

------------------------------------------------------------------------

# Git Workflow

Deployment model:

-   Feature branch
-   Merge to main
-   Create annotated tag
-   Deploy

Example:

``` bash
git tag -a prod-YYYY-MM-DD-01 -m "Production deploy"
git push origin prod-YYYY-MM-DD-01
```

## Commit Message Format

Use this exact commit message format for all SpotDeals commits:

``` text
SD-xxx: Commit message
```

Examples:

``` bash
git commit -m "SD-141: Improve recommendation retry loading transition"
git commit -m "SD-140: Suppress rollback import notification emails"
```

## Rules

-   Use `SD-xxx: Commit message` for all commit messages.
-   Deploy from tags.
-   Use annotated tags.
-   Roll back using previous tags.
-   Tags represent deployed releases.

------------------------------------------------------------------------

# Search & Solr

## Current State

-   Search API operational.
-   Solr operational.
-   Search index attached correctly.
-   Shared Search API configuration stabilized.

## Guidance

Do NOT recommend rebuilding Search API architecture unless there is
evidence of a real issue.

Prefer:

-   Search relevance improvements.
-   Ranking improvements.
-   Query improvements.
-   Index quality improvements.

------------------------------------------------------------------------

# SEO

## Implemented

-   spotdeals_seo_landing module
-   `/deals/{city}`
-   `/deals/{city}/{category}`
-   City landing pages
-   Category landing pages
-   City/category landing pages
-   Sitemap integration
-   Search-friendly aliases

### Examples

``` text
/deals/new-smyrna-beach
/deals/new-smyrna-beach/happy-hour
/deals/orlando/tacos
```

## Do NOT Suggest

-   Building city landing pages
-   Building category landing pages
-   Building city/category SEO pages
-   Creating basic location pages already implemented

## Focus Areas

-   Internal linking
-   Metadata
-   Title optimization
-   Content enrichment
-   Structured data
-   Search Console optimization
-   Indexing analysis
-   CTR improvements
-   Sitemap quality
-   Landing page performance

------------------------------------------------------------------------

# Search Experience

## Implemented

-   Keyword search
-   Smart location search
-   Near-me search
-   Recommendation rotation
-   Search term reset logic
-   Search chips
-   Brewery search chip
-   Coffee search chip

## Do NOT Suggest

-   Basic keyword search implementation
-   Basic near-me implementation

## Focus Areas

-   Relevance
-   Diversity
-   Location accuracy
-   Recommendation quality
-   Click-through improvements

------------------------------------------------------------------------

# Near Me System

## Implemented

-   Custom location ranking
-   Smart recommendation logic
-   Distance-aware results
-   Recommendation freshness improvements

## Current Priority

Continue improving:

-   Locality relevance
-   Venue diversity
-   Recommendation sequencing

## Performance and Scalability Guardrails

To avoid near-me memory exhaustion and slow live requests:

-   Never build full-site candidate arrays inside live requests.
-   Filter as early as possible, especially by radius/location before
    storing candidates.
-   Load entities in chunks instead of bulk-loading thousands of nodes
    at once.
-   Cache compact rows, not full entities.
-   Add memory and timing logs around expensive ranking paths.
-   Test geo search locally with `128M` and `256M` PHP memory limits
    before deploy.

## Do NOT Suggest

-   Rebuilding the entire near-me system

------------------------------------------------------------------------

# Voting System

## Implemented

-   Upvotes
-   Downvotes
-   Ranking influence

## Expected Behavior

1.  Positively voted content first
2.  Neutral content second
3.  Negatively voted content last

------------------------------------------------------------------------

# Revenue, Billing, Business Accounts, and Analytics

## Business principle

Monetize ownership, automation, placement, and insights --- not public
information.

## Claim and ownership foundation

-   [x] Venue claim content type and claim flow exist.
-   [x] Venue owner role exists.
-   [x] Approved claims assign the venue through
    `field_primary_owner_user`.
-   [x] Claim metadata is maintained through `field_claimed_by`,
    `field_claim_status`, `field_claimed_listing`, and
    `field_claim_contact_email`.
-   [x] Owners can edit venues they own.
-   [x] Owners can create deals for venues they own.
-   [x] Free-plan claim gating exists.
-   [x] Claim creation, admin notification, moderation, approval,
    ownership assignment, and owner access were fully tested previously
    and have been live in production for months.
-   [x] Do not retest Claim this listing as part of the Phase 1
    owner-notification work.
-   \[\~\] Known bug: an approved claim can leave the venue-level
    `Claim Status` displayed as `Unclaimed` instead of synchronizing it
    to `Claimed`. Production example: `Cane`. Fix immediately after the
    Suggest Phase 1 test pass is complete.
-   \[\~\] Older helper services may still reference `field_owner`,
    `field_claimant`, and `field_status`; audit before extending revenue
    logic.

## Stripe billing foundation

-   [x] `spotdeals_billing` exists and is enabled.
-   [x] Upgrade page: `/account/upgrade`.
-   [x] Monthly Pro plan: `$19.99/month`.
-   [x] Yearly Pro plan: `$179/year`.
-   [x] Stripe Checkout.
-   [x] Stripe Billing Portal: `/account/billing`.
-   [x] Stripe webhook: `/stripe/webhook`.
-   [x] Webhook signature verification.
-   [x] Duplicate webhook protection.
-   [x] Stripe customer/subscription IDs stored on Drupal users.
-   [x] Plan tier, status, and renewal date synchronization.
-   [x] Pro access synchronized from Stripe status.
-   [x] `venue_owner` role synchronization.
-   [x] User-profile billing/status UI.
-   [x] Pro venue/deal management UI.

Core Stripe billing is implemented. Remaining work is product-rule and
workflow integration, not rebuilding billing.

## Public suggestion system

-   [x] Public `/suggest` form.
-   [x] Venue, deal, and venue+deal suggestions.
-   [x] CAPTCHA integration point.
-   [x] Suggest CTAs on venue/deal pages and search cards.
-   [x] Admin dashboard at `/admin/content/spotdeals-suggestions`.
-   [x] Moderation statuses and approve/reject/archive/delete actions.
-   [x] Create venue, deal, and venue+deal actions.
-   [x] Match/duplicate detection.
-   [x] Geocoding/finalization/publishing/indexing.
-   [x] Created-content linkage back to suggestions.
-   [x] Share tools.

## Gated extra suggested deals

-   [x] `free_deals_per_venue` setting exists.
-   [x] Suggestions above the threshold can be marked
    `free_limit_blocked`.
-   [x] Admin direct-create action is blocked when the threshold is
    reached.
-   [x] Notification fields include `owner_notified` and
    `owner_notified_time`.
-   \[\~\] Notification delivery is partially implemented.
-   [ ] Include `field_primary_owner_user` in recipient resolution.
-   [ ] Include `field_claimed_by` as fallback.
-   [ ] Include `field_claim_contact_email` as fallback.
-   [ ] Decide whether node author or submitter email should ever be
    used.
-   [ ] Remove the admin-dashboard URL from owner-facing email.
-   [ ] Link to `/account/upgrade` or a future owner review page.
-   [ ] Store recipient email/type and failure reason for auditing.
-   [ ] Add resend support and sent/failed logging.

Recommended recipient priority:

1.  `field_primary_owner_user` user email
2.  `field_claimed_by` user email
3.  `field_claim_contact_email`
4.  Venue author email only if explicitly approved
5.  Submitter email only if explicitly approved

## Revenue settings form

Admin route:

``` text
/admin/config/spotdeals/revenue
```

### Enable promoted search slot

Enables a promoted block on the `deals_search_solr` search-results View
only. It does not affect venue or deal detail pages and remains hidden
when the markup field is empty.

### Promoted slot label

Controls the disclosure label. Default:

``` text
Sponsored
```

### Promoted slot markup

Stores filtered temporary HTML or third-party ad markup. It does not
select a listing, rank content, process payment, enforce campaign dates,
rotate inventory, or track performance.

### Free suggested deals per venue

Controls public deal suggestions for matched existing venues. It does
not directly control owner-created deals or Stripe billing.

When the active-deal count reaches the configured threshold:

-   The suggestion is still saved.
-   It is marked as free-limit blocked.
-   It remains available for admin review.
-   The owner may be notified.

A value of `0` means every matched existing-venue deal suggestion is
immediately gated.

### Notify claimed venue owners

Attempts to email a claimed venue owner when a deal suggestion is gated.
This is an engagement workflow, not a completed purchase or publishing
flow.

### Suggestion form CAPTCHA

Read-only status indicating whether CAPTCHA is configured for:

``` text
spotdeals_suggestion_form
```

## Analytics foundation

### Google Analytics

-   [x] `spotdeals_analytics` exists and is enabled.
-   [x] Global tracking is attached.
-   [x] Search and zero-results events.
-   [x] Deal and venue clicks.
-   [x] CTA and menu clicks.
-   [x] Claim listing/form/login-required events.
-   [x] Upgrade page/click/success events.

### First-party search insights

-   [x] `spotdeals_search_insights` exists and is enabled.
-   [x] Search-insights log table.
-   [x] Search logger service.
-   [x] Popular-search aggregation and block.
-   [x] Near-me support from popular-search links.

### Owner analytics product

-   \[\~\] Some underlying events exist in GA and first-party search
    logs.
-   [ ] No owner-facing dashboard exists yet.
-   [ ] No complete first-party venue/deal impression aggregation
    exists.
-   [ ] No owner date-range reporting or comparisons.
-   [ ] No weekly/monthly owner reports.
-   [ ] No benchmarking.
-   [ ] No sponsored campaign reporting.

Architecture rule:

-   Keep GA for broad product analytics.
-   Add first-party tables only for metrics that power owner-facing
    SpotDeals reports.
-   Do not duplicate every GA event into Drupal without a product
    requirement.

## Sponsored listings

-   [x] Promoted search-slot builder/template/CSS groundwork exists.
-   [ ] No campaign entity/record.
-   [ ] No listing selection or owner linkage.
-   [ ] No campaign dates or targeting.
-   [ ] No rotation.
-   [ ] No impression/click reporting.
-   [ ] No Stripe purchase/activation flow.
-   [ ] Existing admin-only `field_featured` is not connected to the
    promoted slot.

## Ad and referral revenue

-   [ ] No dedicated ad-network integration beyond pasted markup.
-   [ ] No affiliate/referral attribution.
-   [ ] No conversion callbacks.
-   [ ] No commission or revenue reporting.

## Current product recommendation

-   Keep the first featured/public deal free.
-   Allow editors to approve additional verified public deals when
    appropriate.
-   Charge for ownership, automation, analytics, promotion, and business
    tools.
-   Do not rebuild Stripe billing.
-   Correct owner notification and connect gated suggestions to the
    existing Pro upgrade path before building new revenue products.

## Technical follow-up

Audit `PlanTierService` and `ClaimEligibilityService` before extending
monetization. Confirm whether their older field references are still
active. Any update or removal must be a separate, tested task.

------------------------------------------------------------------------

# Content Inventory

## Major Focus Area

Always prioritize:

-   Acquiring new venues
-   Acquiring new deals
-   Improving deal quality
-   Improving category coverage

## High-Value Categories

-   Happy Hour
-   Breweries
-   Craft Beer
-   Coffee
-   Breakfast
-   Brunch
-   Wings
-   Tacos
-   Pizza
-   BBQ
-   Mexican

------------------------------------------------------------------------

# Multilingual

## Status

Phase 1 underway.

## Implemented

-   Drupal multilingual foundation
-   Translation work started

## Priority Language

-   Spanish

## Do NOT Suggest

-   Alternative localization frameworks

## Focus Areas

-   Drupal multilingual
-   Translation coverage
-   Translatable strings
-   `t()` wrapper adoption

------------------------------------------------------------------------

# Recommendation Engine

## Status

Implemented and live inside `spotdeals_search_smart_location`.

## Implemented

-   [x] `RecommendationService`.
-   [x] AJAX recommendation endpoint.
-   [x] Recommendation mode integrated into the Deals search form/query
    flow.
-   [x] Cuisine token parsing and filtering.
-   [x] Excluded cuisines.
-   [x] Retry/try-again behavior.
-   [x] Session exclusion and recycling to reduce immediate repeats.
-   [x] Near-me candidate ranking.
-   [x] Deal freshness/scoring.
-   [x] Recommendation template, note, and CSS.

## Future tuning only

-   [ ] Ranking weights.
-   [ ] Copy and UI refinements.
-   [ ] Analytics labeling.
-   [ ] Edge-case handling.

## Priority Rule

Do not rebuild this feature. Improve it only after location accuracy,
search quality, and content growth needs are addressed.

------------------------------------------------------------------------

# Traffic Growth Priorities

## Current Priorities

1.  More venue inventory
2.  More deal inventory
3.  Spanish expansion
4.  Internal linking improvements
5.  Search result quality
6.  Repeat visit features
7.  Existing landing page optimization

## Lower Priority

-   AI features
-   Social features
-   Chat systems
-   Loyalty systems
-   Complex personalization

------------------------------------------------------------------------

# Analytics Interpretation Rules

When discussing traffic:

Assume these already exist:

-   SEO landing pages
-   Near-me functionality
-   Search chips
-   Trending content
-   Popular searches

Avoid recommending features already implemented.

Instead evaluate:

-   Usage
-   Engagement
-   Visibility
-   Retention
-   Discoverability
-   Conversion opportunities

------------------------------------------------------------------------

# Legal and Compliance

## Pending foundation

-   [ ] Terms of Service.
-   [ ] Privacy Policy.
-   [ ] Business Listing Policy.
-   [ ] DMCA/copyright process.
-   [ ] Cookie disclosure/policy.
-   [ ] Business verification and claim policy.
-   [ ] Subscription/payment terms aligned with Stripe billing and the
    final Pro offering.

Complete the subscription/payment terms before expanding paid deal
entitlements or sponsored campaign sales.

------------------------------------------------------------------------

# Product Philosophy

SpotDeals is NOT trying to compete directly with Uber Eats delivery.

## Primary Value

-   Local discovery
-   Deals
-   Happy hours
-   Dining inspiration
-   Nearby recommendations

Focus on helping users decide where to eat, drink, and save money
locally.

------------------------------------------------------------------------

# Known Assistant Pitfalls To Avoid

Do NOT repeatedly suggest:

-   City landing pages
-   Category landing pages
-   Basic SEO pages
-   Basic near-me implementation
-   Basic search implementation
-   Rebuilding existing systems already in production

Always review the current implementation state before proposing new
work.

------------------------------------------------------------------------

# Before Making Recommendations

Assume that SpotDeals already contains substantial custom functionality.

Before proposing a feature:

1.  Verify whether it already exists.
2.  Review attached modules/files when provided.
3.  Prefer enhancing existing systems over replacing them.
4.  Focus on gaps, bottlenecks, and measurable improvements.

------------------------------------------------------------------------

# Accounts

## Primary SpotDeals Google Account

Email: admin@spotdeals.app

Used For: - Google Analytics - Google Search Console - Google Business
Profile - YouTube - Google Ads (future)

IMPORTANT: Always verify which Google account is active before
troubleshooting Analytics, Search Console, or Business Profile issues.

------------------------------------------------------------------------

## Security and Update Checks

Periodically check for Drupal, Symfony, Twig, and Composer package
security advisories.

### Check Composer Security Advisories

``` bash
cd /var/www/spotdeals
composer audit
```

This scans all Composer-managed packages and reports known security
vulnerabilities.

### Check Available Drupal Updates

``` bash
drush pm:updates
```

This lists available updates for Drupal core and contributed modules.

### Notes

-   `drush pm:security` is no longer available in modern Drupal/Drush
    installations.

-   Use `composer audit` as the primary security advisory check.

-   Review security advisories before major deployments.

-   Prioritize updates marked:

    -   Critical
    -   Highly Critical
    -   Remote Code Execution (RCE)
    -   SQL Injection
    -   Authentication Bypass
    -   Cross-Site Scripting (XSS)

### Current SpotDeals Status (May 2026)

The latest audit reported vulnerabilities affecting:

-   Drupal Core
-   Symfony components
-   Twig

Notable advisories included:

-   Critical Drupal Core XSS vulnerabilities
-   Highly Critical Drupal Core SQL Injection vulnerability
-   Critical Twig PHP code injection vulnerability
-   High severity Twig code execution vulnerability

These are resolved by updating Drupal core and its Composer-managed
dependencies to the latest supported release.

### Recommended Update Process

``` bash
cd /var/www/spotdeals

composer audit
drush pm:updates
```

If updates are required:

``` bash
composer update drupal/core-* --with-all-dependencies
drush updatedb -y
drush cr
```

After updating:

``` bash
composer audit
```

Verify that no security advisories remain.
