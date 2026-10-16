SD-232 Conversations Wall — first testable implementation

Copy spotdeals_conversations/ to web/modules/custom/spotdeals_conversations/
Then:
  ddev drush en spotdeals_conversations -y
  ddev drush cr

Test: https://spotdeals.ddev.site/conversations

Features: published conversation timeline, title/body topic search, city filter,
20 per page with More/Previous, links to existing conversation replies.
Does not change the Deal/Venue search or existing custom modules.

Not yet implemented: true infinite scroll, vote/share controls, combined
free-text parsing of 'pizza nsb' into topic+city, reply counts, sorting.
Those are separate milestones. No configuration import/export required to test.

Uninstall: ddev drush pm:uninstall spotdeals_conversations -y

Modal update: Conversation links use Drupal core AJAX modal. The original
canonical URL remains the fallback if JavaScript is unavailable.
Posting a reply may navigate to the full conversation page; keeping form
submission entirely inside the modal is not included in this iteration.
