=== Lead Conversion Tracking ===
Contributors: mert-atalay
Tags: conversion tracking, utm, attribution, datalayer, consent
Requires at least: 6.3
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Attaches campaign attribution to form leads and sends one de-duplicated generate_lead event. Example plugin.

== Description ==

This is an example and reference implementation. It is not in the WordPress.org plugin directory.

After the visitor gives marketing consent, the plugin:

* stores first-touch and last-touch UTM parameters and click ids in a first-party cookie,
* fills hidden form fields with that data and with an event id,
* pushes a `generate_lead` event to `window.dataLayer` after a successful submission,
* optionally posts the same event, with the same event id, to an HTTPS endpoint.

It includes adapters for Contact Form 7 and WPForms and hooks for other form plugins. See README.md for the event shapes, the hooks and the known limits.

== Installation ==

1. Copy the plugin folder to `wp-content/plugins/lead-conversion-tracking`.
2. Activate "Lead Conversion Tracking" on the Plugins screen.
3. Open Settings > Lead Conversion Tracking.

== Changelog ==

= 0.1.0 =
* First version.
