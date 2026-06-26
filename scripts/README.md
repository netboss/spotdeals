# SpotDeals Scripts README

This directory contains maintenance, validation, debugging, and one-off data cleanup tools for SpotDeals.

Most scripts are meant to be run from the project root:

```bash
cd /var/www/html/spotdeals
```

For scripts that need Drupal services or Drupal content, use Drush:

```bash
ddev drush php:script scripts/<script-name>.php
```

For scripts that only read/write CSV files directly, plain PHP is usually enough:

```bash
php scripts/<script-name>.php
```

---

## Quick rules

### Commit these

These are reusable tools or hand-maintained override files:

```text
scripts/spotdeals_csv_validate.php
scripts/spotdeals_fill_missing_coords.php
scripts/spotdeals_geocode_overrides.csv
scripts/spotdeals_normalize_venue_titles.php
scripts/spotdeals_venue_title_overrides.csv
scripts/spotdeals_deal_venue_overrides.csv
scripts/spotdeals_near_me_rank_debug.php
scripts/spotdeals_search_query_audit.php
```

### Do not commit these

These are generated reports. They can be recreated by running the related script:

```text
scripts/spotdeals_geocode_missing.csv
scripts/venue_title_normalization_audit.csv
scripts/deal_venue_reference_audit.csv
scripts/reports/
```

Recommended `.gitignore` entries:

```gitignore
/scripts/spotdeals_geocode_missing.csv
/scripts/venue_title_normalization_audit.csv
/scripts/deal_venue_reference_audit.csv
/scripts/reports/
```

---

## 1. CSV validator

File:

```text
scripts/spotdeals_csv_validate.php
```

Purpose:

Validates the main import CSV files:

```text
web/modules/custom/spotdeals_import/data/venues.csv
web/modules/custom/spotdeals_import/data/deals.csv
```

It checks for:

- required CSV headers
- missing required values
- duplicate venue titles
- duplicate deal/title combinations
- deal references pointing to missing venues
- invalid URLs
- invalid CTA pairs
- same-address review flags
- optional formatting issues

Run:

```bash
ddev drush php:script scripts/spotdeals_csv_validate.php
```

Alternative plain PHP run:

```bash
php scripts/spotdeals_csv_validate.php
```

Strict format mode:

```bash
php scripts/spotdeals_csv_validate.php --strict-format
```

Use strict format mode when you want to see hidden formatting notes, such as deal day/time style issues.

Expected good result:

```text
Errors: 0
Warnings: 0
CSV validation passed.
```

Important:

`Review` items are not automatic failures. They are conservative flags for human review, often same-address venues that may be legitimate co-located businesses, food halls, malls, or multi-business buildings.

If `Errors` is greater than 0, fix the CSVs before importing.

---

## 2. Venue title normalization

File:

```text
scripts/spotdeals_normalize_venue_titles.php
```

Related override files:

```text
scripts/spotdeals_venue_title_overrides.csv
scripts/spotdeals_deal_venue_overrides.csv
```

Generated audit files:

```text
scripts/reports/venue_title_normalization_audit.csv
scripts/reports/deal_venue_reference_audit.csv
```

Purpose:

Normalizes venue titles to the standard format:

```text
Venue Title - Location
```

It also updates `field_venue` references in `deals.csv` so deals still point to the correct venue title after the venue rename.

This script is intentionally safe:

- Default mode is audit only.
- It does not change files unless `--apply` is passed.
- It refuses to apply if normalized venue titles would collide.
- It refuses to apply if a deal reference is ambiguous.
- It writes audit CSVs so changes can be reviewed without reading giant Git diffs.

### Audit mode

Run this first:

```bash
php scripts/spotdeals_normalize_venue_titles.php \
  --venue-overrides=scripts/spotdeals_venue_title_overrides.csv \
  --deal-overrides=scripts/spotdeals_deal_venue_overrides.csv
```

Good result:

```text
No blockers found. Rerun with --apply to update both CSVs.
```

If blockers are found, review the audit files:

```text
scripts/reports/venue_title_normalization_audit.csv
scripts/reports/deal_venue_reference_audit.csv
```

Then add row-specific fixes to the override files.

### Apply mode

Only run this after audit mode reports no blockers:

```bash
php scripts/spotdeals_normalize_venue_titles.php \
  --venue-overrides=scripts/spotdeals_venue_title_overrides.csv \
  --deal-overrides=scripts/spotdeals_deal_venue_overrides.csv \
  --apply
```

This updates:

```text
web/modules/custom/spotdeals_import/data/venues.csv
web/modules/custom/spotdeals_import/data/deals.csv
```

### Override file formats

Venue overrides:

```csv
row_number,current_title,new_title
```

Deal venue overrides:

```csv
row_number,current_field_venue,new_field_venue
```

Row numbers are CSV row numbers, including the header as row 1.

Use row-specific overrides when two venues share the same current title or when an automatic rename would create ambiguity.

### Useful options

Use custom CSV paths:

```bash
php scripts/spotdeals_normalize_venue_titles.php \
  --venues=/path/to/venues.csv \
  --deals=/path/to/deals.csv
```

Use a custom report directory:

```bash
php scripts/spotdeals_normalize_venue_titles.php \
  --report-dir=scripts/reports
```

Show help:

```bash
php scripts/spotdeals_normalize_venue_titles.php --help
```

### After applying normalization

Run the validator:

```bash
ddev drush php:script scripts/spotdeals_csv_validate.php
```

Then import and reindex:

```bash
ddev drush migrate:rollback spotdeals_deals -y
ddev drush migrate:rollback spotdeals_venues -y

ddev drush migrate:import spotdeals_venues -vvv
ddev drush migrate:import spotdeals_deals -vvv

ddev drush search-api:clear deals_solr
ddev drush search-api:index deals_solr

ddev drush cr
```

---

## 3. Fill missing venue coordinates

File:

```text
scripts/spotdeals_fill_missing_coords.php
```

Related override file:

```text
scripts/spotdeals_geocode_overrides.csv
```

Generated report:

```text
scripts/spotdeals_geocode_missing.csv
```

Purpose:

Finds venues in `venues.csv` that are missing latitude/longitude and fills them using:

1. manual overrides from `spotdeals_geocode_overrides.csv`
2. Nominatim/OpenStreetMap lookup when no override exists

Default behavior is dry run. It does not write to `venues.csv` unless `--write` is passed.

### Dry run

```bash
ddev drush php:script scripts/spotdeals_fill_missing_coords.php
```

This checks missing coordinates and reports what would be updated.

### Dry run with limit

Useful for testing only a few rows:

```bash
ddev drush php:script scripts/spotdeals_fill_missing_coords.php -- --limit=5
```

### Write changes

```bash
ddev drush php:script scripts/spotdeals_fill_missing_coords.php -- --write
```

### Write changes with contact email

Nominatim usage is safer with a contact email:

```bash
ddev drush php:script scripts/spotdeals_fill_missing_coords.php -- --write --email=you@example.com
```

You can also set an environment variable:

```bash
export SPOTDEALS_GEOCODER_EMAIL=you@example.com
ddev drush php:script scripts/spotdeals_fill_missing_coords.php -- --write
```

### Important Drush argument separator

When passing script options through `drush php:script`, use `--` before the script options:

```bash
ddev drush php:script scripts/spotdeals_fill_missing_coords.php -- --write
```

Without that separator, Drush may interpret the options itself instead of passing them to the script.

### Override CSV format

```csv
title,field_address_address_line1,field_address_locality,field_address_administrative_area,field_address_postal_code,field_latitude,field_longitude
```

The script matches overrides by:

```text
field_address_address_line1
field_address_locality
field_address_administrative_area
field_address_postal_code
```

The `title` column is mainly for human readability.

### Generated missing-coordinate report

If the script cannot fill some coordinates, it writes:

```text
scripts/spotdeals_geocode_missing.csv
```

Use that report to manually research coordinates and add them to:

```text
scripts/spotdeals_geocode_overrides.csv
```

Then rerun the script.

### After writing coordinate changes

Run:

```bash
ddev drush php:script scripts/spotdeals_csv_validate.php
```

Then import and reindex:

```bash
ddev drush migrate:rollback spotdeals_deals -y
ddev drush migrate:rollback spotdeals_venues -y

ddev drush migrate:import spotdeals_venues -vvv
ddev drush migrate:import spotdeals_deals -vvv

ddev drush search-api:clear deals_solr
ddev drush search-api:index deals_solr

ddev drush cr
```

---

## 4. Near-me ranking debug

File:

```text
scripts/spotdeals_near_me_rank_debug.php
```

Purpose:

Debugs local/near-me deal ranking using real Drupal nodes.

It loads active deal nodes, their venues, venue coordinates, text fields, cuisine/tags, freshness scores, and distance from an origin point. It then prints ranked candidates so you can understand why certain deals appear above or below others.

This script needs Drupal, so run it with Drush.

### Usage

```bash
ddev drush php:script scripts/spotdeals_near_me_rank_debug.php -- "happy hour" 29.0210019 -80.9772265 25 40
```

Argument order:

```text
query latitude longitude radius_km limit
```

Example:

```bash
ddev drush php:script scripts/spotdeals_near_me_rank_debug.php -- tacos 29.0210019 -80.9772265 25 40
```

Defaults if arguments are omitted:

```text
query: happy hour
latitude: 29.0210019
longitude: -80.9772265
radius_km: 25
limit: 40
```

Use this script when:

- Near Me results feel wrong.
- A far venue is ranking too high.
- A nearby deal is missing.
- Search relevance changed after code/data updates.
- You want to compare keyword scoring, distance scoring, and freshness scoring.

No files are modified by this script.

---

## 5. Search query audit

File:

```text
scripts/spotdeals_search_query_audit.php
```

Purpose:

Audits the Search API / Views setup for the main deals search.

It prints information about:

- Search API index config
- attached Search API server
- indexed fields relevant to text search
- Views filters
- Views sorts
- executed View result sample
- recent `spotdeals_search_debug` watchdog rows, when available

This script needs Drupal, so run it with Drush.

### Usage

```bash
ddev drush php:script scripts/spotdeals_search_query_audit.php -- "happy hour"
```

Another example:

```bash
ddev drush php:script scripts/spotdeals_search_query_audit.php -- tacos
```

If no query is provided, it defaults to:

```text
happy hour
```

Use this script when:

- Search results look wrong.
- Search API config may have drifted.
- Solr/index settings need quick verification.
- Views filters or sorts may be affecting results.
- You need a compact debugging snapshot before changing search code.

No files are modified by this script.

---

## Recommended workflows

### A. Before committing venue/deal CSV changes

Run:

```bash
ddev drush php:script scripts/spotdeals_csv_validate.php
```

You want:

```text
Errors: 0
Warnings: 0
CSV validation passed.
```

Then run import and reindex locally:

```bash
ddev drush migrate:rollback spotdeals_deals -y
ddev drush migrate:rollback spotdeals_venues -y

ddev drush migrate:import spotdeals_venues -vvv
ddev drush migrate:import spotdeals_deals -vvv

ddev drush search-api:clear deals_solr
ddev drush search-api:index deals_solr

ddev drush cr
```

### B. After adding new venues with missing coordinates

Run dry run:

```bash
ddev drush php:script scripts/spotdeals_fill_missing_coords.php
```

If the output is good, write:

```bash
ddev drush php:script scripts/spotdeals_fill_missing_coords.php -- --write --email=you@example.com
```

Validate:

```bash
ddev drush php:script scripts/spotdeals_csv_validate.php
```

Import/reindex:

```bash
ddev drush migrate:rollback spotdeals_deals -y
ddev drush migrate:rollback spotdeals_venues -y

ddev drush migrate:import spotdeals_venues -vvv
ddev drush migrate:import spotdeals_deals -vvv

ddev drush search-api:clear deals_solr
ddev drush search-api:index deals_solr

ddev drush cr
```

### C. After adding unnormalized venue titles

Audit first:

```bash
php scripts/spotdeals_normalize_venue_titles.php \
  --venue-overrides=scripts/spotdeals_venue_title_overrides.csv \
  --deal-overrides=scripts/spotdeals_deal_venue_overrides.csv
```

If no blockers:

```bash
php scripts/spotdeals_normalize_venue_titles.php \
  --venue-overrides=scripts/spotdeals_venue_title_overrides.csv \
  --deal-overrides=scripts/spotdeals_deal_venue_overrides.csv \
  --apply
```

Validate:

```bash
ddev drush php:script scripts/spotdeals_csv_validate.php
```

Import/reindex:

```bash
ddev drush migrate:rollback spotdeals_deals -y
ddev drush migrate:rollback spotdeals_venues -y

ddev drush migrate:import spotdeals_venues -vvv
ddev drush migrate:import spotdeals_deals -vvv

ddev drush search-api:clear deals_solr
ddev drush search-api:index deals_solr

ddev drush cr
```

### D. When Near Me results look wrong

Run:

```bash
ddev drush php:script scripts/spotdeals_near_me_rank_debug.php -- "happy hour" 29.0210019 -80.9772265 25 40
```

Then also run:

```bash
ddev drush php:script scripts/spotdeals_search_query_audit.php -- "happy hour"
```

Use both outputs together:

- `spotdeals_near_me_rank_debug.php` explains ranking behavior.
- `spotdeals_search_query_audit.php` explains Search API / Views configuration.

---

## Local commands

Use these after CSV changes that affect imported venue/deal content:

```bash
ddev drush migrate:rollback spotdeals_deals -y
ddev drush migrate:rollback spotdeals_venues -y

ddev drush migrate:import spotdeals_venues -vvv
ddev drush migrate:import spotdeals_deals -vvv

ddev drush search-api:clear deals_solr
ddev drush search-api:index deals_solr

ddev drush cr
```

---

## Production commands

After deploying CSV/script changes to production:

```bash
cd /var/www/spotdeals

vendor/bin/drush migrate:rollback spotdeals_deals -y
vendor/bin/drush migrate:rollback spotdeals_venues -y

vendor/bin/drush migrate:import spotdeals_venues -vvv
vendor/bin/drush migrate:import spotdeals_deals -vvv

vendor/bin/drush search-api:clear deals_solr
vendor/bin/drush search-api:index deals_solr

vendor/bin/drush cr
```

---

## Git commit guidance

Generated reports should not be committed:

```bash
git restore --staged scripts/spotdeals_geocode_missing.csv 2>/dev/null || true
git restore --staged scripts/venue_title_normalization_audit.csv 2>/dev/null || true
git restore --staged scripts/deal_venue_reference_audit.csv 2>/dev/null || true
```

Reusable scripts and override CSVs should be committed when they are part of the workflow:

```bash
git add scripts/spotdeals_csv_validate.php
git add scripts/spotdeals_fill_missing_coords.php
git add scripts/spotdeals_geocode_overrides.csv
git add scripts/spotdeals_normalize_venue_titles.php
git add scripts/spotdeals_venue_title_overrides.csv
git add scripts/spotdeals_deal_venue_overrides.csv
git add scripts/spotdeals_near_me_rank_debug.php
git add scripts/spotdeals_search_query_audit.php
```

Example commit message format:

```bash
git commit -m "SD-153: Add scripts usage README"
```

---

## Troubleshooting

### Drush does not pass script options

Use the `--` separator:

```bash
ddev drush php:script scripts/spotdeals_fill_missing_coords.php -- --write
```

### Audit files keep showing in Git

Add generated reports to `.gitignore`:

```gitignore
/scripts/spotdeals_geocode_missing.csv
/scripts/venue_title_normalization_audit.csv
/scripts/deal_venue_reference_audit.csv
/scripts/reports/
```

### Normalization script says blockers found

Do not apply yet.

Review:

```text
scripts/reports/venue_title_normalization_audit.csv
scripts/reports/deal_venue_reference_audit.csv
```

Then add row-specific mappings to:

```text
scripts/spotdeals_venue_title_overrides.csv
scripts/spotdeals_deal_venue_overrides.csv
```

Rerun audit before applying.

### Validator shows review items

Review items are informational. They often mean:

- same address with multiple valid venues
- mall/food hall/co-located businesses
- franchise locations
- address normalization edge cases

Treat `Errors` as blockers. Treat `Warnings` as likely issues. Treat `Review` as manual verification.

