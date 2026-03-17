# SpotDeals Drupal CSV import mapping

## Files
- `spotdeals_venues_import.csv`
- `spotdeals_deals_import.csv`

## Venue CSV column mapping
- `title` -> Venue title
- `field_address_address_line1` -> Address line 1
- `field_address_locality` -> City
- `field_address_administrative_area` -> State
- `field_address_postal_code` -> ZIP
- `field_address_country_code` -> Country code (`US`)
- `field_venue_type` -> Venue Type taxonomy term name(s), pipe-separated if multiple
- `field_short_description` -> Venue short description
- `field_phone` -> Venue phone
- `field_website_uri` -> Venue website URL
- `field_website_title` -> Link text
- `field_cuisine` -> Cuisine taxonomy term name(s), pipe-separated if multiple
- `field_claimed_listing` -> 0/1 boolean
- `field_image` -> blank placeholder for manual image import
- `image_search_hint` -> helper column for finding/selecting a matching image manually

## Deal CSV column mapping
- `title` -> Deal title
- `field_price_offer_text` -> Price / Offer Text
- `field_day_of_week` -> Day of Week taxonomy term(s), pipe-separated if multiple
- `field_start_time` -> Deal Time / Hours
- `field_deal_category` -> Deal Category taxonomy term
- `field_venue` -> Venue title (best for Feeds/Migrate lookup by title)
- `field_active` -> 0/1 boolean
- `field_recurring` -> 0/1 boolean

## Important assumptions
1. Venue content type machine name is `venue`
2. Deal content type machine name is `deal`
3. Address field machine name is `field_address`
4. Venue type taxonomy field is `field_venue_type`
5. Short description field is `field_short_description`
6. Deal -> Venue entity reference field is `field_venue`
7. Deal time field is still `field_start_time`
8. Price / Offer Text field is `field_price_offer_text`

## If your machine names differ
Rename the CSV headers before import.

## Import notes
### Venues
- Import Venues first
- Map the Address subfields into the Address field
- Map taxonomy-by-name for `field_venue_type` and `field_cuisine`
- Leave `field_image` blank and import/upload images later
- `image_search_hint` is for editorial use only

### Deals
- Import Deals after Venues
- Map `field_venue` by title lookup against Venue nodes
- Map taxonomy-by-name for `field_day_of_week` and `field_deal_category`

## Suggested cleanup before import
Create/verify these taxonomy terms exist:
- Venue Type: Restaurant, Bar, Brewery, Cafe
- Deal Category: Happy Hour, Early Bird, Daily Special, Breakfast Special, Lunch Special, Weekly Special, Combo, Drink Special
- Cuisine terms used in the Venue CSV

## Important note about multi-value day fields
Your Day of Week field may be single-value. If so, split multi-day deals into multiple deal rows before import.
