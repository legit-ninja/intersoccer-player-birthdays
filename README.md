# InterSoccer Player Birthdays

Current Version: **1.8.31**

Emails guardians **one greeting per child** before a registered player's calendar birthday, and sends the WordPress admin a digest of upcoming birthdays.

This is **not** birthday-party product bookings and **not** the Reports/Rosters camp follow-up after a party (`birthday_camp_followup`). Player profiles stay in Player Management (`intersoccer_players` / `intersoccer_get_user_players()`).

## Requirements

- WordPress 5.8+, PHP 7.4+
- WooCommerce
- Player Management

## Office UI

**Player Birthdays** in wp-admin:

- Upcoming — queue, send now, opt-out
- Templates — EN/FR/DE `wp_editor` + merge tags
- Settings — automation (off by default), lead days, digest, extra recipients, test address (lead / look-ahead max 153 days, about 5 months; defaults 7 / 60 / 21)
- Log — player UUID + user ID + year + mode (no names/emails/DOB)

Merge tags: `{{player_first_name}}`, `{{player_last_name}}`, `{{guardian_first_name}}`, `{{age_turning}}`, `{{birthday_date}}`, `{{opt_out_url}}`, `{{site_title}}`

Images (banner or signature) must use absolute `https://` URLs. Store-wide email banner: WooCommerce → Settings → Emails → Header image.

Outlook/Word paste is supported: empty spacer `<div>`/`<p>` tags are turned into line breaks so paragraph gaps survive WooCommerce email CSS. Pretty-print newlines between tags are not converted (that over-spaces HTML). `{site_title}` / `{{site_title}}` in Woo footer chrome are replaced with the site name.

Timezone: Europe/Zurich. 29 Feb in non-leap years is treated as 28 Feb.

## Tests

```bash
composer install
./vendor/bin/phpunit
```

## Deploy

Copy `deploy.local.sh.example` to `deploy.local.sh` and run `./deploy.sh` (PHPUnit is required before upload).
