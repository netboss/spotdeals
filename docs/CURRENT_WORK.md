# SpotDeals Current Work

**Last Updated:** 2026-07-14

This is the day-to-day working list for the project.

Use this file to track active work, production issues, and the next
tasks. Keep `PROJECT_STATUS.md` as the long-term project handbook.

------------------------------------------------------------------------

# 🔴 High Priority

## Production Operations

### Disk Usage (Critical)

Status: **PARTIALLY COMPLETE**

Completed

-   [x] Production disk monitor installed
-   [x] systemd timer enabled
-   [x] 50% warning threshold configured

Remaining

-   [ ] Email/Slack notifications
-   [ ] Investigate root cause of rapid disk growth
-   [ ] Verify long-term disk usage after translation improvements

> This issue has caused multiple production outages and remains one of
> the highest priorities.

### Infrastructure Monitoring

-   [ ] Memory alerts
-   [ ] Disk inode alerts
-   [ ] Backup failure alerts
-   [ ] SSL certificate expiry alerts
-   [ ] Failed cron alerts
-   [ ] Search indexing failure alerts

------------------------------------------------------------------------

# 🟡 Active Development

-   [ ] Complete the remaining Suggest Phase 1 tests.
-   [ ] Monitor the next approved venue claim.
-   [ ] Verify `field_primary_owner_user` is automatically populated
    during claim approval.
-   [ ] Verify newly approved venues automatically display **✓ Claimed
    business**.

------------------------------------------------------------------------

# 🟢 Recently Completed

-   [x] SD-166 --- Replace **Claim this listing** with **✓ Claimed
    business**.
-   [x] Removed duplicated claimed-state helper.
-   [x] Fixed broken empty claim `<a>` tag in deal cards.
-   [x] Suggest workflow improvements.
-   [x] Owner notification workflow improvements.

------------------------------------------------------------------------

# 📝 Notes

-   Legacy venue **Cane** required a one-time correction to
    `field_primary_owner_user`.
-   No further code changes are planned unless a newly approved claim
    fails to populate the owner automatically.
-   Keep this file short. Remove completed items or move permanent
    information to `PROJECT_STATUS.md`.
