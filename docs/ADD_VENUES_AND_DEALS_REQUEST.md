Replace CITY with the city added/specified in the chat, e.g.: CITY = CITY
Replace STATE with the state added/specified in the chat, e.g.: STATE = Florida

SPOTDEALS REVIEW CHECKLIST

Before responding:

1. Check for duplicates by title and address.
2. All CSV fields must be wrapped in quotes.
3. Never modify existing rows unless requested.
4. Return venues together.
5. Return deals together.
6. ALWAYS include rollback/import/reindex drush commands.
7. Include git commands when reviewing commits.
8. Do not suggest deleting user data.
9. Validate venue title format: "Venue Name - Location".
10. No menu/order links as deals.

Let's explore these venues and see if there are deals and add them:

New rule: do not add street address using this format: #123 Thisstreet St, use this format instead: 123 Thisstreet St -- note the removal of the number symbol (#) before the 123. This is causing the editor to interpret these #123 as hex colors and that's wrong.

From now on ALWAYS add content to each venue/deal description/body field.

Let's keep digging more CITY, STATE and surrounding areas venues and deals.

Try to ALWAYS find batches of ten or more venues/deals.

DO NOT ADD DUPLICATES! You're adding some duplicated venues, like for example: You added "North Italia Orlando",  but we already had "North Italia - Orlando", same venue, same address, just a hyphen in the name is the difference, so please be extremely aware and careful of these type of naming: "venue name - city" vs "venue name city".

HIGHER PRIORITY: Find cafes, cafeterias and coffee shops in CITY, STATE and surrounding areas
2nd HIGHER PRIORITY: Find night life, happy hours, wine bars, draft beer, drinks, breweries, cigar cafes, etc.

Deals in CITY, STATE
Happy Hour in CITY, STATE
Daily Specials in CITY, STATE
Dining Deals in CITY, STATE
Nightlife Deals in CITY, STATE

Also, add "ice cream", "desserts", "bakery", "coffee" and "matcha" places.

Let's add more of these ^^ CITY, STATE deals. Let's see what you find. If needed we can use Yelp to pull specific venues from it and explore their deals (if any).

Please add new venues and deals, but do not add duplicates of what is already in venues.csv and deals.csv (attached). Before adding any new venues and deals, please scan the venues.csv and deals.csv to know which venues and deals are already there and avoid adding duplicates. Add the new venues and deals as copy/paste new rows and DO NOT edit or modify the venues.csv and deals.csv that are there ONLY as a reference.

DO NOT add duplicates. Please ALWAYS double check that there are no duplicates; compare venue titles with existing venues in venues.csv and also compare addresses too; compare new deals with existing deals in deals.csv. DO NOT add false venue/deals that doesn't exists. Avoid adding "menu" or "order/book online" type of non-deals; focus ONLY on real deals. On one of the past iterations you added duplicates and a wrong corrupted row that broke the complete data and the site; we need to avoid this to happen again.

Remember this:

field_website = main venue website
field_menu_url = menu page when available
field_cta = only real user action like order online/reserve
field_cta_title = only used when field_cta has a value

No generic menu rows in deals.csv.

No "Menu Special" rows unless the venue explicitly labels it as a special, promo, deal, happy hour, brunch, lunch special, rewards offer, event, or recurring discounted/featured offer.

Menu/order links belong only in the venue row fields.

And also remember to add the correct lat/lon coordinates to each venue based on their correct address.

Here are the venues.csv and deals.csv heading fields:

venues.csv:
title,field_address_address_line1,field_address_locality,field_address_administrative_area,field_address_postal_code,field_address_country_code,field_venue_type,field_short_description,field_phone,field_website_uri,field_website_title,field_cuisine,field_claimed_listing,field_image,image_search_hint,field_latitude,field_longitude,field_tags,field_address_line1,field_city,field_state,field_zip,field_country,field_description,field_website,field_source,field_menu_url,field_cta,field_cta_title

deals.csv:
title,field_price_offer_text,field_day_of_week,field_start_time,field_deal_category,field_venue,field_active,field_recurring,field_end_time,field_cta,field_cta_title

Please add all the new data as copy/paste additions ALWAYS. Also, ALWAYS include the rollback/import and re-index ddev drush commands. And remember that for prod the drush commands are like this: drush cr, no need to prefix the command with the /vendor path.

Remember to add the venues (not deals) with their website, menu pages and, if available, reserve/book online links.

Please, the next time put all venues together and all deals together. Also, the rollback/import/reindex ddev drush commands put them together too.

From now on ALWAYS add content to each venue/deal description/body field. From now on treat venue title matching as exact and normalize apostrophes mentally before suggesting rows. Also, let's be very careful with these venue name formats: "The place" and "Place", tese might be the same venue but pass as different thus creating unexpected duplicates. One good way to compare and confirm is checking the street address.

From now on please keep venue titles in this format: "Venue name - venue location".
