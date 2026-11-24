# Lead Conversion Tracking

A small WordPress plugin for lead-generation sites. It keeps the traffic source of a visitor, attaches it to form submissions, and sends one `generate_lead` event that the browser and the server can share without double counting.

This is an example and reference implementation. I wrote it to show how I approach this problem. It is not a finished product. Read the [Limits](#limits) section before you use any of it.

- Requires WordPress 6.3+ and PHP 8.1+
- No JavaScript dependencies, no build step
- License: GPL-2.0-or-later

## The problem

Two things go wrong on most lead-gen sites.

**Form leads lose their source.** A visitor arrives from an ad with `utm_source=google` and a `gclid` in the URL. They read two more pages and then fill in the contact form. The form email or CRM record says nothing about the ad. The parameters were only in the first URL.

**Browser and server events double count.** A site sends a conversion from the browser through a tag manager. Later it also sends the same conversion from the server, because browser tracking misses some of them. Without a shared id, the ad platform or the warehouse counts one lead twice.

## What the plugin does

1. **Captures attribution.** A script reads `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `gclid`, `gbraid`, `wbraid`, `fbclid` and `msclkid`, plus the landing page path and the referrer. It stores a first touch and a last touch in a first-party cookie (`lct_attr`) with a copy in `localStorage`.
2. **Fills form fields.** It writes the stored values and a generated `event_id` into hidden fields of the forms on the page.
3. **Pushes a conversion event.** After a successful submission it pushes `generate_lead` to `window.dataLayer` with the `event_id`, the form id and the attribution. It never reads form values.
4. **Uses the same id on the server.** The server reads the `event_id` from the submission, keeps it in a small log, passes it to a WordPress action, and can forward it to an HTTPS endpoint. Both sides now carry the same id, so the receiver can de-duplicate.
5. **Waits for consent.** Nothing is stored, filled, pushed, logged or forwarded before the visitor has given marketing consent.

### First touch and last touch

- First touch is written once and kept until the record expires.
- Last touch is replaced only when a page view has a campaign parameter, a click id, or a referrer from another host. A direct visit or an internal page view does not erase the last known source.
- The lifetime is set in days (default 90). It runs from the first touch and is not extended by later visits.
- Only the ten known parameters are kept. The query string of the landing page and of the referrer is dropped. Values are cut at 200 characters.

## Install

1. Copy this folder to `wp-content/plugins/lead-conversion-tracking`.
2. Activate **Lead Conversion Tracking** on the Plugins screen.
3. Open **Settings > Lead Conversion Tracking**.

The `vendor`, `node_modules` and `tests` folders are only needed for development. The plugin runs without them.

## Setup

### Settings

| Setting | Default | What it does |
| --- | --- | --- |
| Attribution lifetime (days) | 90 | How long the stored attribution is kept. 1 to 395. |
| Assume consent | off | Only used when no WP Consent API plugin is active. Leave it off unless you have a legal basis to track without asking. |
| Keep a lead log | on | Keeps event id, form id, time and attribution of the last 100 leads in one option. Shown at the bottom of the settings page. |
| Send leads to an endpoint | off | Turns on the server-side forward. |
| Endpoint URL | empty | Must start with `https://`. Anything else is rejected and forwarding is turned off. |
| Shared secret | empty | Sent in the `X-LCT-Secret` header. |
| Include hashed email | off | Adds the SHA-256 hash of the submitted email address to the server payload. |

### Consent

The plugin asks for the `marketing` category.

- **With the [WP Consent API](https://wordpress.org/plugins/wp-consent-api/) active:** the server calls `wp_has_consent( 'marketing' )` and the script calls the JavaScript function with the same name. The script also listens to the `wp_listen_for_consent_change` event, so consent given or withdrawn on the current page takes effect at once. Withdrawing consent deletes the cookie, the `localStorage` entry and the values in the hidden fields.
- **Without it:** the answer is "no consent" unless you turn on "Assume consent" or connect your consent tool:

```php
// Server side.
add_filter( 'lct_has_consent', function ( $granted, $category ) {
	return my_consent_tool_allows( 'marketing' );
}, 10, 2 );
```

```js
// Browser side, when your consent tool reports a choice.
document.dispatchEvent( new CustomEvent( 'lct:consent', { detail: { granted: true } } ) );
```

If the visitor gives consent on the landing page after it has loaded, the parameters of that page are still stored. They are held in memory until then. If the visitor moves to another page before giving consent, they are lost.

### Form fields

The script fills a field in any form when it matches one of these conventions. `<key>` is `event_id`, or `first_` / `last_` followed by a parameter name, `landing_page`, `referrer` or `ts`.

| Convention | Example |
| --- | --- |
| Field name | `<input type="hidden" name="lct_last_utm_source">` |
| Data attribute | `<input type="hidden" data-lct="first_gclid">` |
| CSS class on the input or on a wrapper around it | `<div class="lct-last_utm_campaign"><input type="hidden" ...></div>` |

Forms that match the selector list also get missing fields added as hidden inputs named `lct_<key>`. The default list is `form.wpcf7-form`, `form.wpforms-form` and `form[data-lct-form]`. Change it with the `lct_form_selectors` filter.

On the server the plugin reads the `lct_*` fields from the request. If the form sent none, it falls back to the `lct_attr` cookie.

### Contact Form 7

No setup is needed for the event. The plugin listens to the `wpcf7_mail_sent` action on the server and to the `wpcf7mailsent` DOM event in the browser.

To see the values in the notification email, add hidden fields to the form and use them as mail tags, for example `[hidden lct_last_utm_source]` and `[hidden lct_event_id]`.

### WPForms

The plugin listens to the `wpforms_process_complete` action on the server and to the `wpformsAjaxSubmitSuccess` jQuery event in the browser. WPForms triggers that event through jQuery, so the script uses the jQuery that WPForms already loads. The plugin itself does not depend on jQuery.

To keep a value inside the WPForms entry, add a Hidden Field and give it a CSS class such as `lct-last_utm_source`.

### Forms without AJAX

When a form reloads the page or redirects to a thank-you page, there is no browser event to listen to. For that case the server sets a cookie named `lct_pending` for five minutes. It holds the event id, form id and form type. The script reads it on the next page load, pushes the event once and deletes the cookie.

### Other form plugins

Report a successful submission from PHP. This runs the log, the pending cookie and the server-side forward.

```php
add_action( 'my_forms_submission_ok', function ( $form_id, $email ) {
	do_action( 'lct_form_submitted', $form_id, 'my_forms', $email );
}, 10, 2 );

add_filter( 'lct_form_selectors', function ( $selectors ) {
	$selectors[] = 'form.my-forms';
	return $selectors;
} );
```

If the form submits with AJAX, also tell the script:

```js
window.LCT.instance.trackLead( formElement, { formId: '12', formType: 'my_forms' } );
```

### Hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `lct_form_submitted` | action you call | Report a successful submission: form id, form type, email (optional). |
| `lct_lead_captured` | action | Fires with the lead record. Use it to store the event id in a CRM or entries table. |
| `lct_has_consent` | filter | Change the consent decision. |
| `lct_form_integrations` | filter | Add or remove adapter objects that implement `LCT\Integrations\Integration`. |
| `lct_form_selectors` | filter | CSS selectors of forms that get hidden fields added. |
| `lct_script_config` | filter | The config object passed to the script. |
| `lct_server_payload` | filter | Change the server payload. Return a non-array to cancel the forward. |
| `lct_forward_request_args` | filter | Change the `wp_remote_post()` arguments. |

The script also dispatches a `lct:lead` DOM event on `document` with the pushed event as `detail`.

## The dataLayer event

```js
{
  event: 'generate_lead',
  event_id: '3f2b8c1e-9a4d-4c7b-8e21-0a1b2c3d4e5f',
  form_id: '123',
  form_type: 'cf7',
  attribution: {
    first: {
      utm_source: 'google',
      utm_medium: 'cpc',
      utm_campaign: 'spring',
      gclid: 'abc123',
      landing_page: '/landing/',
      referrer: 'https://www.google.com/search',
      ts: 1790000000
    },
    last: {
      utm_source: 'newsletter',
      utm_medium: 'email',
      landing_page: '/offer/',
      ts: 1790400000
    }
  }
}
```

- Keys without a value are left out.
- `ts` is the time of the touch in Unix seconds.
- `form_type` is `cf7`, `wpforms`, or the name you pass.
- Each `event_id` is pushed once per page. After a push the form gets a new id for the next submission.
- The event id comes from the server cookie for that submission if there is one, then from the submitted hidden field, then from the form.

## The server payload

Sent with `wp_remote_post()` as a non-blocking `POST` when forwarding is on and the visitor has consented.

Headers:

```
Content-Type: application/json; charset=utf-8
X-LCT-Secret: <your shared secret>
```

Body:

```json
{
  "event_name": "generate_lead",
  "event_id": "3f2b8c1e-9a4d-4c7b-8e21-0a1b2c3d4e5f",
  "timestamp": 1790400120,
  "form_id": "123",
  "form_type": "cf7",
  "site_url": "https://example.com",
  "attribution": {
    "first": { "utm_source": "google", "gclid": "abc123", "landing_page": "/landing/", "ts": 1790000000 },
    "last": { "utm_source": "newsletter", "landing_page": "/offer/", "ts": 1790400000 }
  },
  "user_data": {
    "sha256_email": "973dfe463ec85785f5f95af5ba3906eedb2d931c24e69824a89ea65dba4e813b"
  }
}
```

- `timestamp` is the server time of the submission in Unix seconds.
- `user_data` is only present when "Include hashed email" is on and the form had a valid email address. The address is trimmed and lower-cased, then hashed with SHA-256.
- The `event_id` sent by the browser is accepted only if it is 8 to 64 characters of letters, digits, `-` and `_`. Otherwise the server creates a UUID.
- The receiver has to do the de-duplication. This plugin only makes sure both events carry the same id.

## Privacy

- Nothing is stored in the browser, written to forms, pushed to the dataLayer, logged or forwarded without marketing consent.
- The dataLayer event has no names, email addresses, phone numbers or form values. The script does not read form inputs.
- Click ids such as `gclid` are identifiers. Depending on your jurisdiction they can count as personal data. That is why they sit behind consent.
- The landing page is stored as a path. The referrer is stored without its query string.
- The plain email address is never stored or sent by this plugin. The hash is only sent when the site owner turns it on. A SHA-256 hash of an email address is still personal data. It is pseudonymous, not anonymous.
- The server-side forward is off by default and only accepts an `https://` endpoint.
- The lead log holds no form content. It can be turned off and cleared on the settings page.
- Uninstalling the plugin deletes its two options (`lct_settings`, `lct_lead_log`).

I am not a lawyer and this is not legal advice. Check your own consent setup.

## Tests

The tests do not need a WordPress install.

```bash
composer install
vendor/bin/phpunit      # PHP unit tests (PHPUnit + Brain Monkey)
vendor/bin/phpcs        # WordPress Coding Standards

npm install
npm test                # JavaScript unit tests (node:test + jsdom), Node 22+
```

The PHP tests cover settings sanitisation, event id validation, attribution sanitisation, payload building, email hashing, consent gating, the forward request, the lead log and the two adapters. The JavaScript tests cover URL parsing, first-touch and last-touch logic, consent gating, storage, hidden-field filling and the conversion event.

GitHub Actions runs both suites on every push and pull request (PHP 8.1 and 8.3, Node 22).

## Project layout

```
lead-conversion-tracking.php   Plugin header, autoloader, bootstrap
uninstall.php                  Deletes the options
assets/js/lct.js               Front-end script
src/                           PHP classes (namespace LCT)
src/Integrations/              Contact Form 7 and WPForms adapters
tests/php/                     PHPUnit tests
tests/js/                      node:test tests
```

## Limits

What is missing or not proven yet:

- **Barely tested inside WordPress.** The automated tests replace WordPress functions with stubs. By hand I activated the plugin on a local WordPress 7.1 (WordPress Playground): it loaded without errors, the script and its settings were printed on the front end, and the settings page rendered. I have not yet submitted a real form through it or run it on a hosted site.
- **Not tested against live installs of Contact Form 7 or WPForms.** The adapters follow the documented hooks and events of those plugins, and the tests simulate them. Hook behaviour can differ between versions. The tips about CF7 mail tags and WPForms hidden-field classes are also untested.
- **No GTM container.** You have to create the `generate_lead` trigger, the dataLayer variables and the tags yourself.
- **No receiving endpoint.** The server-side forward posts JSON to a URL you provide. Nothing in this repository receives, verifies or de-duplicates it.
- **Not in the WordPress.org plugin directory.** It has not been through that review.
- **The forward does not retry.** The request is non-blocking. The response is not read. A failed request is lost without a notice.
- **The shared secret is a plain header.** It is not an HMAC signature of the body. It is stored unencrypted in the options table, like most plugin settings.
- **The lead log is not a lead database.** It keeps 100 records in one option. Two submissions at the same moment can overwrite each other.
- **Browser limits apply.** Safari shortens the life of cookies and `localStorage` written by scripts, whatever lifetime you set.
- **Page caching.** Without the WP Consent API, the consent answer of the `lct_has_consent` filter is printed into the page. If that answer differs per visitor, a page cache will serve the wrong one. Use the `lct:consent` browser event in that case.
- **The pending cookie needs the same host.** If a form redirects to another domain, the event for that submission is not pushed in the browser.
- **CF7 leads are counted when the mail was sent.** A submission where the mail fails does not produce an event.
- **Multisite.** Uninstall only cleans the current site.
- **No translations are included.** All strings use the i18n functions with the `lead-conversion-tracking` text domain.
- **Attribution is simple.** First touch and last touch only. No session logic, no channel grouping, no cross-device or cross-domain handling.

## License

GPL-2.0-or-later. Copyright 2026 Mert Atalay. See [LICENSE](LICENSE).
