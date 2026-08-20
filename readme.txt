=== CalendarCore – Event Calendar & Recurring Events ===
Contributors: xodesignworks
Tags: events, event calendar, calendar, recurring events, rsvp
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fast event calendar with free recurring events, RSVP, .ics export and time zones. No jQuery, no bloated database tables.

== Description ==

**CalendarCore is the event calendar that stays out of your site's way.** Publish an event in under a minute, drop a block on any page, and get a month, week, day and list view that load instantly — on mobile too.

The features other calendar plugins put behind a $99–$599 paywall are free here, forever: **recurring events, "Add to Calendar" .ics export, RSVP with a live attendee counter, and time-zone aware times.**

= Free, and not crippled =

* **Recurring events** — daily, weekly (pick the weekdays), monthly (same date, or "third Tuesday"), yearly. Custom intervals, skip dates, end date or number of repeats.
* **Four views** — month grid, week, day and event list, switching without a page reload.
* **RSVP without payment** — attendee form, capacity limit, live counter, duplicate protection, honeypot and rate limiting. No spam, no captcha.
* **Add to calendar** — one-click .ics download plus Google Calendar and Outlook links, and a subscribable feed of all your events.
* **Time zones done right** — every date is stored in UTC and shown in the visitor's own time zone, formatted in your site's language. Perfect for webinars and online classes. Switch it off in one click.
* **Venues and organizers** — real taxonomies, so visitors can filter events by place or host, and each venue gets its own page.
* **Two Gutenberg blocks** — *Event calendar* and *Event list*, rendered on the server so they keep working in block themes and after any editor update.
* **Dark mode and your colours** — the calendar inherits your theme palette from `theme.json`; no CSS fight, no "why is my calendar blue".

= Built for Core Web Vitals =

Most event plugins are slow for two reasons: they create wide custom database tables, and they pre-generate years of repeating dates. CalendarCore does neither.

* **6.9 KB** of CSS + JavaScript, gzipped, loaded **only on pages that actually show a calendar**.
* **Zero jQuery**, zero date libraries. Native date and time fields, `Intl.DateTimeFormat` for time zones.
* Events are a normal custom post type in `postmeta`. The plugin adds **exactly one narrow table with three data columns** (event, start, end) for repeating dates.
* Repeating dates are generated **12 months ahead at most**, and a cron job keeps that window filled in batches — so saving an event never stalls and the calendar page never crawls.
* Measured on a stress install with 500 events and 6,148 stored dates: month view renders in ~0.18 s cold and needs **zero database queries** when warm.
* Page-cache friendly (WP Rocket, W3 Total Cache, LiteSpeed): the only live fragment is the RSVP counter, which the browser fetches after the cached HTML is served.

= Made for =

Meetups and user groups, workshops and courses, webinars and online classes, concerts and gigs, conferences, sports fixtures and gym timetables, church services, community centres, farmers' markets, school and club calendars, retreats, exhibitions and gallery openings.

= Blocks, shortcodes and page builders =

Blocks: **Event calendar**, **Event list** — both with venue/organizer filters, view picker and per-page limits in the sidebar.

Shortcodes for classic themes:

* `[xodw_cc_calendar view="month"]` — full calendar with navigation
* `[xodw_cc_events limit="5"]` — upcoming events list, ideal for a sidebar or footer
* `[xodw_cc_rsvp event="123"]` — RSVP form on its own
* `[xodw_cc_add_to_calendar event="123"]` — the add-to-calendar buttons

An optional thin Elementor widget is included and appears automatically when Elementor is active.

= Privacy first =

No external services, no tracking, no phoning home, no fonts or scripts loaded from a CDN. Everything runs on your server. The Google Calendar and Outlook buttons are plain links — nothing is sent anywhere until the visitor clicks one.

= For developers =

Namespaced `XODW\CalendarCore\`, procedural helpers prefixed `xodw_cc_`, hooks named `xodw_cc_{module}_{action}`, and a modular loader you can switch off per feature.

* Reading events uses the core REST endpoint `wp/v2/xodw_cc_event`; the plugin only adds what core cannot do (`xodw-cc/v1/view`, `/occurrences`, `/rsvp`).
* Filters: `xodw_cc_recurring_dates`, `xodw_cc_recurring_horizon`, `xodw_cc_view_query_args`, `xodw_cc_render_view`, `xodw_cc_ics_export`, `xodw_cc_rsvp_form`, `xodw_cc_occurrence_data`, `xodw_cc_components`.
* Actions: `xodw_cc_recurring_generated`, `xodw_cc_rsvp_created`, `xodw_cc_meta_normalized`, `xodw_cc_cache_flushed`.
* One query wrapper for every view, with a transient cache invalidated by a single option bump.

== Installation ==

1. In your admin, go to **Plugins → Add New**, search for **CalendarCore** and click *Install now*, then *Activate*. (Or upload the ZIP under **Plugins → Add New → Upload Plugin**.)
2. Go to **Events → Add Event**, type a title, pick a date and time, publish.
3. Add the **Event calendar** block to any page — or paste `[xodw_cc_calendar]` in a classic editor.
4. Optional: **Events → Settings** to choose which views are available, the accent colour, the time-zone behaviour and the RSVP defaults.

That's it. No wizard, no account, no API key.

== Frequently Asked Questions ==

= Are recurring events really free? =

Yes, all of them: daily, weekly with selected weekdays, monthly by date or by weekday position ("last Friday"), yearly, custom intervals, skipped dates, and an end date or a number of repeats. This is the feature most calendar plugins charge for.

= Will it slow down my site? =

The stylesheet and script total 6.9 KB gzipped and are loaded only on pages that show a calendar. Nothing is enqueued on your other pages. Event queries are cached, and the calendar reads from one narrow, indexed table.

= Does it create custom database tables? =

One, and it has three data columns: `event_id`, `start_datetime`, `end_datetime`. Titles, descriptions, venues, organizers and recurrence rules all live in the standard WordPress tables, so your database stays a WordPress database — and your backups, migrations and search plugins keep working.

= How far ahead are repeating dates generated? =

Twelve months, by default and by maximum. A twice-daily cron job extends the window as time passes, 200 events per run, so nothing ever times out.

= Which time zone do visitors see? =

Dates are stored in UTC and rendered in your site's time zone. If a visitor's time zone differs, the browser rewrites the times to their local zone — in your site's language — and says which zone is shown. You can turn this off in the settings.

= Does it work with block themes and full site editing? =

Yes. Both blocks render server-side, so they work in block themes, classic themes, template parts and patterns alike.

= Does it work with Elementor, Divi or other page builders? =

Yes — use the shortcode in any builder's text or shortcode widget. Elementor also gets a native widget automatically.

= Can visitors register for an event? =

Yes. Enable RSVP on the event, set a capacity if you want one, and visitors can confirm attendance with the number of guests. Duplicate registrations are blocked, submissions are rate limited, and registrations can be held for approval. RSVP records are stored as WordPress comments of a private type, so exporting or erasing personal data works with the tools you already have.

= Does it sell tickets? =

No. CalendarCore is a calendar, not a shop: there is no payment processing in the free plugin.

= Can I import an external calendar (.ics subscription)? =

Not yet. CalendarCore exports .ics and publishes a subscribable feed; importing external calendars is planned.

= Does it work with caching plugins? =

Yes, with no extra configuration. The RSVP counter is the only dynamic part and is fetched by the browser from an uncacheable endpoint after the cached page loads.

= Is it translation ready? =

Yes. All strings use the `calendarcore` text domain and a `.pot` template ships with the plugin. Dates and weekday names follow your site language and your WordPress date format.

= Does it send any data to external servers? =

No. No tracking, no analytics, no remote fonts or scripts, no license check in the free plugin.

== Screenshots ==

1. Month view, inheriting the theme's colours and typography.
2. The event editor: native date and time fields, recurrence rules, no jQuery datepicker.
3. Single event with add-to-calendar buttons and the RSVP form.
4. Event list view with venues and organizers.
5. Month view on mobile — events collapse into dots, tap a day for details.
6. Settings, grouped by module, with everything switchable.

== Changelog ==

= 1.0.0 =
* First public release.
* Events custom post type with venue and organizer taxonomies.
* Recurring events: daily, weekly, monthly (by date or weekday position), yearly, with intervals, skip dates, end date and repeat count.
* Month, week, day and list views with no-reload navigation and a no-JavaScript fallback.
* Time-zone aware display via `Intl.DateTimeFormat`.
* .ics export per occurrence, calendar feed, Google Calendar and Outlook links.
* RSVP with capacity, live counter, duplicate protection, honeypot and rate limiting.
* Gutenberg blocks *Event calendar* and *Event list*, four shortcodes, optional Elementor widget.
* Dark mode and `theme.json` colour inheritance.
* REST namespace `xodw-cc/v1` plus computed fields on the core `wp/v2/xodw_cc_event` endpoint.

== Upgrade Notice ==

= 1.0.0 =
First public release.
