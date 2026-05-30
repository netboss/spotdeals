# SpotDeals Project Status

**Last Updated:** 2025-05-30

---

# Project Overview

SpotDeals is a local restaurant, food, drink, happy hour, and deal discovery platform focused on helping users find nearby offers and venues.

## Primary Goals

1. Increase local discovery and search visibility.
2. Increase repeat visits and engagement.
3. Grow venue inventory and deal inventory.
4. Improve recommendation quality and location relevance.
5. Build sustainable traffic before investing heavily in advanced features.

---

# Development Environment

## Local Development

Uses DDEV.

Common commands:

```bash
ddev drush cr
ddev drush cim -y
ddev drush cex -y
```

Do NOT suggest:

```bash
drush cr
```

for local development.

## Production

Production server has direct Drush access.

Common commands:

```bash
drush cr
drush cim -y
drush cex -y
```

Do NOT suggest:

```bash
ddev drush cr
```

for production.

Always distinguish between local and production environments.

---

# Code Change Rules

When modifying code:

1. Use the attached files as the source of truth.
2. Modify only the requested functionality.
3. Do not remove unrelated logic.
4. Do not refactor unrelated code.
5. Return only files that actually changed.
6. Return complete files, never patches or snippets.
7. Clearly identify:

   * changed functions
   * unchanged functions
   * required Drush commands

Before generating code:

* Identify files to be modified.
* Identify functions to be modified.
* Identify functions that must remain untouched.

---

# JavaScript Rules

When returning JavaScript files:

Use:

```text
filename.js.update
```

instead of:

```text
filename.js
```

---

# Git Workflow

Deployment model:

* Feature branch
* Merge to main
* Create annotated tag
* Deploy

Example:

```bash
git tag -a prod-YYYY-MM-DD-01 -m "Production deploy"
git push origin prod-YYYY-MM-DD-01
```

## Rules

* Deploy from tags.
* Use annotated tags.
* Roll back using previous tags.
* Tags represent deployed releases.

---

# Search & Solr

## Current State

* Search API operational.
* Solr operational.
* Search index attached correctly.
* Shared Search API configuration stabilized.

## Guidance

Do NOT recommend rebuilding Search API architecture unless there is evidence of a real issue.

Prefer:

* Search relevance improvements.
* Ranking improvements.
* Query improvements.
* Index quality improvements.

---

# SEO

## Implemented

* spotdeals_seo_landing module
* `/deals/{city}`
* `/deals/{city}/{category}`
* City landing pages
* Category landing pages
* City/category landing pages
* Sitemap integration
* Search-friendly aliases

### Examples

```text
/deals/new-smyrna-beach
/deals/new-smyrna-beach/happy-hour
/deals/orlando/tacos
```

## Do NOT Suggest

* Building city landing pages
* Building category landing pages
* Building city/category SEO pages
* Creating basic location pages already implemented

## Focus Areas

* Internal linking
* Metadata
* Title optimization
* Content enrichment
* Structured data
* Search Console optimization
* Indexing analysis
* CTR improvements
* Sitemap quality
* Landing page performance

---

# Search Experience

## Implemented

* Keyword search
* Smart location search
* Near-me search
* Recommendation rotation
* Search term reset logic
* Search chips
* Brewery search chip
* Coffee search chip

## Do NOT Suggest

* Basic keyword search implementation
* Basic near-me implementation

## Focus Areas

* Relevance
* Diversity
* Location accuracy
* Recommendation quality
* Click-through improvements

---

# Near Me System

## Implemented

* Custom location ranking
* Smart recommendation logic
* Distance-aware results
* Recommendation freshness improvements

## Current Priority

Continue improving:

* Locality relevance
* Venue diversity
* Recommendation sequencing

## Do NOT Suggest

* Rebuilding the entire near-me system

---

# Voting System

## Implemented

* Upvotes
* Downvotes
* Ranking influence

## Expected Behavior

1. Positively voted content first
2. Neutral content second
3. Negatively voted content last

---

# Content Inventory

## Major Focus Area

Always prioritize:

* Acquiring new venues
* Acquiring new deals
* Improving deal quality
* Improving category coverage

## High-Value Categories

* Happy Hour
* Breweries
* Craft Beer
* Coffee
* Breakfast
* Brunch
* Wings
* Tacos
* Pizza
* BBQ
* Mexican

---

# Multilingual

## Status

Phase 1 underway.

## Implemented

* Drupal multilingual foundation
* Translation work started

## Priority Language

* Spanish

## Do NOT Suggest

* Alternative localization frameworks

## Focus Areas

* Drupal multilingual
* Translation coverage
* Translatable strings
* `t()` wrapper adoption

---

# Recommendation Engine

## Status

Planned

## Concept

### Help Me Choose

Potential inputs:

* Cuisines
* Meal type
* Deal type
* Radius

Potential outputs:

* Recommended venues
* Recommended deals
* Shuffle
* Pick one for us

## Priority Rule

Do NOT prioritize this over:

* Location accuracy
* Search quality
* Content growth

---

# Traffic Growth Priorities

## Current Priorities

1. More venue inventory
2. More deal inventory
3. Spanish expansion
4. Internal linking improvements
5. Search result quality
6. Repeat visit features
7. Existing landing page optimization

## Lower Priority

* AI features
* Social features
* Chat systems
* Loyalty systems
* Complex personalization

---

# Analytics Interpretation Rules

When discussing traffic:

Assume these already exist:

* SEO landing pages
* Near-me functionality
* Search chips
* Trending content
* Popular searches

Avoid recommending features already implemented.

Instead evaluate:

* Usage
* Engagement
* Visibility
* Retention
* Discoverability
* Conversion opportunities

---

# Product Philosophy

SpotDeals is NOT trying to compete directly with Uber Eats delivery.

## Primary Value

* Local discovery
* Deals
* Happy hours
* Dining inspiration
* Nearby recommendations

Focus on helping users decide where to eat, drink, and save money locally.

---

# Known Assistant Pitfalls To Avoid

Do NOT repeatedly suggest:

* City landing pages
* Category landing pages
* Basic SEO pages
* Basic near-me implementation
* Basic search implementation
* Rebuilding existing systems already in production

Always review the current implementation state before proposing new work.

---

# Before Making Recommendations

Assume that SpotDeals already contains substantial custom functionality.

Before proposing a feature:

1. Verify whether it already exists.
2. Review attached modules/files when provided.
3. Prefer enhancing existing systems over replacing them.
4. Focus on gaps, bottlenecks, and measurable improvements.

---

# Accounts

## Primary SpotDeals Google Account

Email:
admin@spotdeals.app

Used For:
- Google Analytics
- Google Search Console
- Google Business Profile
- YouTube
- Google Ads (future)

IMPORTANT:
Always verify which Google account is active before troubleshooting Analytics, Search Console, or Business Profile issues.

---

## Security and Update Checks

Periodically check for Drupal, Symfony, Twig, and Composer package security advisories.

### Check Composer Security Advisories

```bash
cd /var/www/spotdeals
composer audit
```

This scans all Composer-managed packages and reports known security vulnerabilities.

### Check Available Drupal Updates

```bash
drush pm:updates
```

This lists available updates for Drupal core and contributed modules.

### Notes

* `drush pm:security` is no longer available in modern Drupal/Drush installations.
* Use `composer audit` as the primary security advisory check.
* Review security advisories before major deployments.
* Prioritize updates marked:

  * Critical
  * Highly Critical
  * Remote Code Execution (RCE)
  * SQL Injection
  * Authentication Bypass
  * Cross-Site Scripting (XSS)

### Current SpotDeals Status (May 2026)

The latest audit reported vulnerabilities affecting:

* Drupal Core
* Symfony components
* Twig

Notable advisories included:

* Critical Drupal Core XSS vulnerabilities
* Highly Critical Drupal Core SQL Injection vulnerability
* Critical Twig PHP code injection vulnerability
* High severity Twig code execution vulnerability

These are resolved by updating Drupal core and its Composer-managed dependencies to the latest supported release.

### Recommended Update Process

```bash
cd /var/www/spotdeals

composer audit
drush pm:updates
```

If updates are required:

```bash
composer update drupal/core-* --with-all-dependencies
drush updatedb -y
drush cr
```

After updating:

```bash
composer audit
```

Verify that no security advisories remain.
