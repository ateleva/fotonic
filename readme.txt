=== Eleva CRM for Photographers ===
Contributors: eleva
Tags: crm, photographer, photography, gestionale, wedding
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free CRM and business manager (gestionale) for wedding and event photographers: clients, bookings, installments, calendar, memory cards, vault.

== Description ==

Eleva CRM for Photographers is a free photographer CRM and business management software (a gestionale per fotografi) built for wedding and event photographers and video makers. It keeps every client, every shoot, and every payment in one place, right inside the WordPress admin you already use, with no monthly subscription and nothing sent to a server you don't control.

You already have enough to think about on shoot day. Eleva CRM for Photographers handles the rest: who is getting married and when, what they owe and what they have paid, which memory card belongs to which shoot, and where every contract and file lives. The client details that matter most, names, contacts, home addresses, are protected by a dedicated encryption vault, so a leaked database never hands anyone a usable list of your clients.

= Features =

* Customers: keep every couple or client together, with more than one contact per booking, so a bride's email and a groom's email can both live on the same record, each with an optional gender. Full search across names, emails, phones, and addresses. Every customer page shows their full booking history with running totals.
* Services: build a catalog of the packages and add-ons you offer, then override the price or notes for any specific booking without touching the catalog.
* Works (your bookings): the hub for every job. Customer, services, event date and time, every location, every file, and every payment, on one page.
* Payments: track deposits and balances with a running list of installments. Paid, Partial, or Unpaid status is worked out for you automatically from what has actually been paid.
* Custom payment types: go beyond a simple deposit and balance and define your own installment types to match how you actually bill.
* Files: attach contracts, invoices, and reference files to any booking, or drop in a link to a Google Drive or Dropbox folder instead. Images and PDFs preview right in the browser, with zoom and full-document search.
* Dashboard: your year at a glance. Revenue booked, payments still to receive, and the next five shoots coming up.
* Monthly calendar: every booking as a colored pill on a real calendar. Click through to see the customer, date, and payment status.
* Calendar colors: tag each booking with one of twelve colors, so a glance at the calendar tells you which is which.
* Memory Cards: an inventory of your SD and CF cards, so you always know which ones are safe to format. Status moves itself: Ready, In Use, Backed Up, or Damaged, as you attach a card to a shoot and check off the backup.
* Storage: one page for your memory cards, the backup locations where you keep your files (hard drives, NAS, cloud) and a history of the shots you took and delivered. In each work you choose where its backups are saved, and a warning appears if a location is deleted.
* Delivery: write how you delivered the photos to the client: gallery link, transfer or USB stick, with the date the link expires and the gallery password. One button copies a ready message for your client, to paste into WhatsApp, a social app or an email.
* Quick Notes: a short rich-text field on every booking for the one-line reminder you need at a glance, kept separate from your full notes.
* Vault: an optional dedicated encryption layer, protected by its own password and a TOTP authenticator app, that keeps client names, contact details, addresses, notes and your service catalog unreadable in the database, and the documents attached to your bookings unreadable on the server, even to someone with direct access.
* Fingerprint unlock: open the Vault with a touch (fingerprint, face or device PIN) on browsers that support it, with your password and code always available as the fallback.
* Encrypted backup: download one encrypted archive of your entire CRM (customers, bookings, services, and files) whenever you want, protected by your Vault credentials and opened by no one but you.
* Restore a backup: bring a backup back into Eleva CRM, from your computer or, with Eleva CRM Pro, from Google Drive, on the same site or on a new one. You see what is already there, choose what to keep, and can undo it.

= No dependencies, nothing leaves your server =

Eleva CRM for Photographers doesn't require ACF, WooCommerce, or any other plugin, and makes zero outbound requests of its own: nothing is ever sent to us or to anyone else. Every byte of your data stays in your own WordPress database, on your own server.

Eleva CRM Pro is a separate, optional add-on. If you choose to connect it to Google Calendar or Google Drive, those are its own connections, made only when you turn them on, never by the free plugin.

== Installation ==

1. Download the plugin ZIP from WordPress.org.
2. Go to Plugins > Add New > Upload Plugin in your WordPress admin.
3. Upload the ZIP and click Install Now, then Activate.
4. Open CRM in the WP Admin sidebar.
5. On first launch, set your Vault master password and scan the QR code with an authenticator app (Google Authenticator, Authy, or any TOTP-compatible app).
6. Enter your Vault password and the current OTP code to unlock the CRM.

== Frequently Asked Questions ==

= Who is this CRM for? =

Wedding and event photographers and video makers who want a CRM built around how a shoot actually works: one client, one date, a handful of payments, and a stack of files, not a generic sales pipeline.

= Is there a free CRM for photographers in WordPress? =

Yes. Eleva CRM for Photographers is a free CRM that runs inside your own WordPress admin. It keeps your clients, bookings, payments, files and calendar in one place, with no monthly fee and no feature locked behind an upgrade.

= What can I manage with Eleva CRM for Photographers? =

Eleva CRM for Photographers is a business manager for your photography work. You can track customers (with more than one contact per booking), your catalog of services and prices, every booking with its date, locations and files, deposits and balances with automatic Paid, Partial or Unpaid status, a monthly calendar, and an inventory of your memory cards. Client details stay encrypted in your own database.

= Do I need a subscription? =

No. Eleva CRM for Photographers is free, with no monthly fee and no feature paywall inside the app. An optional paid add-on, Eleva CRM Pro, exists for studios that want a team (collaborators), a Kanban board, analytics, quotes with VAT and PDF, and automatic scheduled backups to Google Drive, but nothing in the free plugin nags you to upgrade.

= Does this require ACF (Advanced Custom Fields)? =

No. The plugin uses only native WordPress meta boxes and registers all custom post types itself. No third-party field plugin is required or used.

= Does this work without WooCommerce? =

Yes. The plugin is completely standalone and has no dependency on WooCommerce or any other plugin.

= What is the Vault? =

The Vault is a dedicated encryption layer for the personal details tied to your clients and bookings: names, email addresses, phone numbers, home addresses, nationality, tax ID, Instagram handles, and each booking's event address. Every one of those is encrypted with AES-256 before it is written to the database.

You set a Vault master password, independent of your WordPress login, and pair it with a TOTP authenticator app (Google Authenticator, Authy, or similar); unlocking needs both, unless you turn on fingerprint unlock on a device (see below). The password itself is never stored: it is stretched through PBKDF2 with 600,000 iterations, and the resulting key lives only in a temporary, authenticated session cookie (AES-256-GCM), never in the database. The Vault locks itself again after 15 minutes of inactivity, and on every WordPress login or logout, so a shared computer is never left open.

The Vault is optional. The first time you open the CRM it asks whether you want it. If you choose "Not now", you can turn it on later from Settings, and everything you have already entered is encrypted at that moment. Without the Vault the same data is stored as plain text in your database, and encrypted backups are not available.

Besides the personal details above, the Vault protects the titles of your clients, bookings and quotes, your notes, the notes inside a booking, the notes of your backup locations, the notes and the password of your deliveries, the titles, prices and notes of your service catalog, and the documents attached to a booking (the file and its name). With Eleva CRM Pro it also protects collaborators, suppliers, products, expense notes and quote notes. Dates, prices, payments, statistics and the titles of tasks and expenses sit outside the Vault and stay readable, and so do the title and the addresses of a booking you send to Google Calendar (Google has to read them).

= Can I unlock the Vault with my fingerprint? =

Yes, on browsers that support it (tested on Safari and Chrome on macOS). Open Settings, then Vault, then "Unlock with fingerprint", enter your password and code once, and your device asks you to confirm. From then on the lock screen opens the Vault with a touch. Your password and code always keep working, and you still need them if you lose the device or use another browser.

Good to know: it uses the check your device already has, so a fingerprint, face or device PIN, and anyone who can unlock the device can open the Vault from it. If you save passkeys in iCloud or Google, whoever controls that account can also use the passkey on other devices. It works only on the address where you turned it on, so if your site address changes, turn it on again. Resetting the Vault or recovering it with your phrase or code removes the fingerprints; changing the password keeps them. Under the hood the Vault key stays encrypted on your server and opens only with a secret that your device produces, so the server never opens it on its own, and the fingerprints are never part of a backup.

= What if I forget my Vault password? =

The plugin gives you two separate fallbacks, both set up ahead of time from the Settings page. If you still know your password but lost your authenticator app, the one-time recovery code gets you back in. If you forgot your password itself, only the recovery phrase can reset it without losing your encrypted data. Set up both, since they cover different problems. Losing your password, your authenticator, and both recovery credentials together means the data cannot be recovered by anyone, including us. That is what makes the encryption real.

= Does the plugin work on shared hosting? =

Yes. It needs PHP 7.4 or higher and WordPress 6.0 or higher, both standard on modern shared hosting.

= Where is my data stored? =

Entirely in your own WordPress database. The plugin makes no external HTTP requests and sends no data to any external server, ours or anyone else's. You own and control your data completely.

= Will the plugin slow down my site? =

No. The plugin only loads on its own admin screen inside WP Admin; it adds nothing to your public-facing site, so visitor-facing performance is unaffected.

= Can I get my data out if I stop using the plugin? =

Yes, any time, from the Settings page: download a complete encrypted backup of every customer, booking, service, and file. It is your data in a portable archive, not locked to the plugin. A standalone command-line tool that ships with the plugin opens the archive on any computer and gives you your data as readable files. You can also bring a backup back into the plugin: see How do I restore a backup?

= Does the plugin back up my data? =

Yes. From Settings, download a single encrypted archive of your entire CRM: customers, bookings, services, memory cards, and every attached file. Nothing leaves your server: the archive is built locally and saved to your own computer.

That archive is encrypted with a key protected by your Vault password before it is ever written. Nobody, including us, can open it without that password or your recovery code, and there is no back door: lose your password, recovery code, and recovery phrase together, and the archive is unrecoverable. Its protection rests on your Vault password: the minimum is 8 characters, but a longer, less guessable password is safer, so choose a strong one and keep the file private.

This backs up your CRM data, not your whole WordPress site. Themes, other plugins, and unrelated content are not included.

Automatic, scheduled backups that push this same archive to your own Google Drive are an **Eleva CRM Pro** feature. The free plugin's backup is always manual: on demand, whenever you choose to download one.

= How do I restore a backup? =

In Settings, find the Backups section and its box Restore from a backup. Choose a backup made on this site, a backup saved on Google Drive (with Eleva CRM Pro), or upload the .zip file you downloaded. Eleva CRM then compares it with what is already on your site and shows what is new, what is already there and what is different. By default it only adds what is missing and leaves everything you have alone. For each difference you choose whether to keep your version, use the one from the backup, or add the backup one as a separate copy.

Before anything is written, a safety copy of your current data is saved, and you can undo the restore until you choose to keep the result. Replace everything is also there: it removes your current data and puts the backup in its place, exactly as it was on the day of the backup. It cannot be undone with a button, so you confirm it by typing a word. To go back, restore the safety copy.

= Which password do I need to restore a backup? =

A backup made by this site opens without a password while the Vault is unlocked. Any other backup, from another site or from a Vault you have since reset, opens only with the Vault password, the recovery code or the recovery phrase that were valid on the day the backup was made: if you changed your password since, use the old one. Without one of them nobody can open it, not even us. After several wrong tries the attempts pause for a while.

On a new site, turn the Vault on first: the personal details you restore are then protected with that site's own key. If the site has no Vault you can still restore after confirming, and the details are kept as plain text until you turn the Vault on.

= What is not restored? =

The connection with Google Calendar (connect it again in Settings; events already on your calendar stay as they are), the license key, and in Eleva CRM Pro the look of your quote PDFs and your signature (set them up again). With Eleva CRM Pro active, quotes with their PDF, expenses, suppliers, collaborators, products and tasks come back too. Without Pro, the Pro data in a backup is listed as left out, and nothing is lost from the backup file.

== Eleva CRM Pro ==

Eleva CRM Pro is a separate, optional paid add-on for photographers running a small team, sending quotes to clients or wanting deeper reporting. It adds:

* Quotes: prepare numbered quotes for your clients, turn a confirmed quote into a booking in one click, and send it as a PDF by email or as a message for WhatsApp, Messenger or Instagram.
* VAT: choose whether prices include VAT, exclude it or are shown without it, with your own rate.
* Company details: your business name, address and tax details, printed on your quotes.
* Collaborators: assign second shooters and team members to a booking, and track what each one is owed.
* Kanban board: move bookings through your own workflow stages.
* Analytics: revenue, cost, and owned-vs-commissioned reporting, with period comparison.
* Products & Suppliers: manage physical products and the suppliers behind them.
* Google Calendar sync: your bookings and reminders pushed automatically to your Google Calendar.
* Automatic backups: the same encrypted archive as the free plugin, pushed to your own Google Drive on a schedule, with retention.

Pro is entirely optional. Nothing in the free plugin is locked or hidden behind an upgrade prompt.

== Privacy Policy ==

Eleva CRM for Photographers collects no personal data of its own, makes no external HTTP requests, and sends nothing to any external server. Every piece of data lives exclusively in your own WordPress database.

== Credits ==

The in-browser PDF preview is built on pdf.js by Mozilla (https://mozilla.github.io/pdf.js/), licensed under the Apache License 2.0.

== Screenshots ==

1. Photographer CRM dashboard: jobs and revenue of the year, payments still to receive, payment methods and recent works at a glance
2. Client management: customer list with several contacts per client, quick search by name, email or phone, and full booking history
3. Services catalog for photographers: packages and add-ons with a base price, reusable in every booking
4. Bookings (works): list with payment status filter and total price, and the booking form with date, locations, services and files
5. Payments and installments: deposit, balance and installments per booking, each with its own payment method, date and paid or unpaid status
6. Memory cards inventory: status Ready, In Use, Backed Up or Damaged, and the work each card belongs to
7. Backup locations: external drives, NAS and cloud with type, capacity, status and the number of works saved in each
8. Shot history: photos shot and delivered for every work, with a year filter and totals
9. Photography booking calendar: every job as a colored pill on a monthly calendar, with start and end time
10. Settings with the encrypted vault: password, authenticator app, recovery kit and fingerprint unlock for client data
11. Encrypted vault for client data: set up with a password, an authenticator app code, a recovery phrase and a recovery code, with optional fingerprint unlock
12. Trash: restore a client or service deleted by mistake within 30 days, or delete it for good
13. Restore a backup: guided five-step restore from your encrypted backup, on the same site or on a new one

== Changelog ==

= 1.7.0 =
* New: restore a backup. In Settings, under Backups, choose a backup made on this site, saved on Google Drive (with Eleva CRM Pro) or uploaded as a .zip file, and bring your data back into Eleva CRM, on the same site or on a new one.
* New: before anything is written you see what is already on the site, what is missing and what is different, and you choose what to do with each difference. By default only what is missing is added. A safety copy of your data is saved first, and you can undo the restore until you choose to keep the result.
* New: Replace everything puts the backup in place of your current data, as it was on the day of the backup. It cannot be undone with a button: to go back, restore the safety copy.
* New: a backup opens only with the Vault password, recovery code or recovery phrase that were valid on the day it was made. A backup made by this site opens without one while the Vault is unlocked. After several wrong tries the attempts pause for a while.
* New: with Eleva CRM Pro active and its license valid, quotes with their PDF, expenses, suppliers, collaborators, products and tasks are restored too. Without Pro, or without a valid license, they are not offered, they are listed as left out and nothing is lost from the backup.
* Not restored: the connection with Google Calendar (connect it again) and, in Pro, the look of the quote PDFs and the signature.
* New: the contracts and other documents you attach to a work are now kept in a private folder and encrypted by the Vault, file names included. Documents you had already uploaded are moved there, and taken out of the media library, when the Vault encrypts your existing data after the update. A document that is also used somewhere else (for example a quote logo) stays where it is and is listed in Settings, under Vault.
* New: the titles of your clients, works and quotes are now encrypted by the Vault, like your notes. You see them and search them as always, accents and capitals ignored. Searching clients also finds the name, email and phone number of their people.
* New: a work whose customer is in the Bin, or no longer exists, shows a red sign in the Works list and a notice under the customer field, so you can restore that customer or choose another one.
* Change: when a customer or a service cannot be deleted, the window now lists every work (and, with Eleva CRM Pro, every quote) that still uses it, and each one opens with a click.
* Change: the search of works looks at titles only, not at the notes inside a work (they are encrypted).
* Change: for each work with Google sync on, Google Calendar now gets the title and all the addresses (one line each, in the description), plus date, time and colour. The location field and the quick notes are no longer sent. Addresses appear on each event the next time you save the work.
* New: the Bin. Each list (customers, works, quotes and so on) shows a small Bin button as soon as something of that kind is waiting there, and Settings has a Bin card too. The Bin page lists what is in it and in how many days each item disappears by itself, and lets you restore items or delete them for good, one by one or all together (deleting several asks you to type a word). An item still used by another record, such as a customer with works, cannot be deleted until that changes.
* Fix: in the notes of a work, the cursor no longer jumps while you type.
* Fix: the PDF preview of a document did not open on some hosting plans (the CRM showed Unable to render this PDF). It now opens everywhere; downloading always worked.
* Change: while Eleva CRM Pro is switched off, the backup card now warns that a backup made at that moment leaves out your Pro data (quotes, suppliers, collaborators, expenses and tasks), instead of the vague warning about missing fields.
* Change: restoring a backup from Google Drive now says that the CRM is asking Drive for the file, instead of looking frozen for a few seconds.
* Compatibility: tested with the latest WordPress (7.1.x).
* Compatibility: works with Eleva CRM Pro 1.7.0.

= 1.6.10 =
* Fix: on some hosting plans, turning on the Vault, creating the backup keys or making a backup ended in a critical error. These steps now work on every hosting plan.
* Compatibility: tested with the latest WordPress (7.1.x).
* Compatibility: works with Eleva CRM Pro 1.6.7.

= 1.6.9 =
* Fix: after an update, a page of the CRM could fail to open with the message Failed to fetch dynamically imported module, and clearing the cache did not always help. The CRM was loading a second, older copy of itself. It now loads one current copy, so an update takes effect at the first reload.
* Change: if a page of the CRM is missing right after an update, for example in a tab that was left open, the page now reloads by itself once instead of showing an error.
* Compatibility: tested with the latest WordPress (7.1.x).
* Compatibility: works with Eleva CRM Pro 1.6.7.

= 1.6.8 =
* Change: the CRM is easier to use on a phone or a small screen. The side menu is narrower, page titles and their buttons wrap onto their own rows, the calendar shows small coloured squares and opens an event as a card at the bottom of the screen, and floating cards stay inside the screen. A screen of 13 inches or more is still the most comfortable.
* Change: the Dashboard cards fit their numbers better, with each title on one line, and the card Payment types used is now called Payment types.
* Change: in Settings, under Vault, the box Unlock with fingerprint is always listed. When the browser does not treat the page as secure (for example a site address that starts with http), it says so and asks you to open the site with a trusted https address.
* Compatibility: tested with the latest WordPress (7.1.x).
* Compatibility: works with Eleva CRM Pro 1.6.7.

= 1.6.7 =
* Fix: on a new installation the Vault screens (the first question, the setup, the recovery kit and the unlock) could be taller than the window with no way to scroll, so the buttons could not be reached and the Vault could not be set up. They now fit the window and their buttons always stay in view.
* Change: the first screen, which asks whether to protect personal data, is wider and easier to read, and says that you can unlock the Vault with a fingerprint or face on devices that have one.
* Change: the Vault screens look more tidy, with icons and the colour of your WordPress theme.
* Compatibility: tested with the latest WordPress (7.1.x).
* Compatibility: works with Eleva CRM Pro 1.6.6.

= 1.6.6 =
* New: unlock the Vault with a touch. If your computer or phone has a fingerprint reader or face unlock, turn it on in Settings, under Vault, and open the Vault without typing the password and the code. Your password and code keep working, and you still need them to turn the feature on and if you lose the device. It is tested on Safari and Chrome on macOS: other browsers show the option only when they support it.
* Security: the fingerprint uses the check your device already has (fingerprint, face or device PIN), so anyone who can unlock the device can open the Vault from it, and passkeys saved in iCloud or Google can open it on your other devices. It works only on the address where you turned it on. Resetting or recovering the Vault removes the fingerprints; changing the Vault password keeps them. They are never part of a backup.
* New: Storage. The Memory Cards entry in the menu is now Storage, with three tabs: Memory Cards, Backup Locations and Shots History.
* New: Backup Locations. List the hard drives, NAS and cloud spaces where you keep your backups, with their type, size and a note. In each work, choose where its backups are saved, and open a location to see every work saved there.
* New: if you delete a backup location that a work uses, the work shows a red warning in the Works list, and a button above the list shows only the works to fix. Open the work and choose another location.
* New: Shots. In a work, write how many photos you took and how many you delivered. The Shots History tab lists them for every work, with the totals and a filter by year.
* New: Delivery. In a work, write how you delivered the photos: a title (for example Pixieset gallery), the link, the date the link expires, a gallery password and a note. The Copy message button prepares a message for your client with the link, the password and the expiry date, ready to paste into WhatsApp, a social app or an email. For a USB stick, write it in the title and leave the link empty.
* New: every page of the app names its browser tab (for example Works), so you can tell several open tabs apart. While the Vault is locked, the tab says so.
* Change: the Payments section of a work works differently. On each installment you write the part of the services price it covers; the installments must add up to the amount shown, and the total price is worked out from them. Works you saved before show a notice: check their installments the next time you open them.
* New: long lists are split into pages: 48 rows in Works and 24 in the other lists, with Previous and Next buttons.
* New: the fields where you pick a customer, a service, a memory card or a backup location now have a search box: type a few letters and choose. Before, the customer list in a work showed only the first 100 customers.
* Fix: the Works and Customers lists showed only the first 20 records, so with more than 20 the others could not be seen. They now show every record, page by page.
* Security: the notes of a backup location, the delivery notes and the gallery password of a work are encrypted by the Vault like your other notes. After updating, unlock the Vault and let the short encryption finish.
* Change: the buttons at the top of Works and Calendar now have the same size as in the other pages.
* Compatibility: tested with the latest WordPress (7.1.x).
* Compatibility: works with Eleva CRM Pro 1.6.6, which adds the same pages to the Pro lists.

= 1.6.5 =
* New: "What's new" after an update. A small star appears next to the sections that changed, and opening it explains the change in a few words. Close it and it does not come back.
* New: a Changelog page. Click the version number at the bottom of the menu to read what changed in every version.
* New: payment types open from a button at the top of the Works list, instead of a section at the bottom.
* New: the name and the base price of a service sit on the same row, and the arrows on a price change it by one whole euro.
* Change: fields and buttons have the same height everywhere, so forms look tidier.
* Compatibility: tested with the latest WordPress (7.1.x).
* Compatibility: works with Eleva CRM Pro 1.6.5, which adds reminders, Google Calendar updates both ways and the INPS surcharge.

= 1.6.4 =
* New: the Vault is now optional. The first time you open Eleva CRM you choose between protecting personal data with the Vault and "Not now", and you can turn the Vault on later from Settings. Sites that already have a Vault see no choice.
* New: when you turn the Vault on, or update with it already on, the data you had already entered is encrypted in small steps, with a progress screen, a pause button and a floating progress bar. Closing the tab loses nothing: the work carries on where it stopped.
* Security: the notes of a work, the notes of a memory card or a service inside a work, and the titles, base prices and notes of your services are now encrypted by the Vault. They stay readable to you once you unlock it. Eleva CRM Pro adds collaborators, suppliers, products, expense notes and quote notes.
* Security: the Reset screen and the Vault texts now list everything that becomes unreadable after a reset.
* Note: the search no longer finds words inside encrypted notes, because the server cannot read them.
* New: every customer, work, service and memory card gets an internal identifier, groundwork for restoring backups later without duplicates.
* Fix: downloading an attached file works on a site without a Vault.
* Fix: a quote PDF downloads under its quote code (with the matching Eleva CRM Pro update).
* Compatibility: tested with the latest WordPress (7.1.x).

= 1.6.2 =
* Security: the Vault now locks everywhere. Before, pressing Lock, changing the Vault password or recovering with your phrase or code only cleared the cookie of the browser you were using, so a copy of that cookie, or a second computer left open, kept working until the WordPress login ended. Now each of those actions refuses every earlier session on every browser and device, the 24-hour limit is checked by the server too, and a page left open elsewhere shows the lock screen within 30 seconds. After updating, everyone unlocks the Vault once.
* Security: guessing the Vault password or a recovery code is much harder. Failed attempts are now counted for the whole Vault (5 failures in 15 minutes) and only one check runs at a time. Before, the count was kept per user and many requests sent at once could try more guesses than allowed.
* Security: the plugin's records (customers, services, works, memory cards and payment status) no longer have screens in the classic WordPress admin and can be reached only by administrators. Before, an Editor or Author could open those screens, because they followed WordPress default permissions. Everything is managed in the Eleva CRM app; other plugins and user roles are not touched.
* Security: private files are better protected. Backup file names now carry 32 random characters instead of 8, the protection files of the backups folder are put back if they go missing, and a new Site Health test ("Eleva CRM private files are protected") checks that your web server does not hand out the backups and Vault folders. On nginx servers, which ignore the .htaccess file that protects them on Apache, the test shows the rules to add. Nothing changes on Apache.
* Security: stored files can now carry a Vault encryption layer, which Eleva CRM Pro 1.6.3 uses for quote PDFs because they contain client names and addresses. Such files are decrypted only for you, with the Vault unlocked, and the offline backup tool removes the layer, so a backup gives you readable files. The Reset screen warns that these files become unreadable after a reset.
* Fix: the customer search could also match unrelated content, for example a draft page with a similar title. It now searches customers only.
* Fix: the backup texts said that the authenticator code protects the archive. It does not: the archive is protected by your Vault password alone, so a strong password matters. The Settings page, the readme and the offline tool guide now say so, and that the minimum length is 8 characters.
* Fix: a file that cannot be opened because the Vault was locked in another tab, window or device now explains that and what to do, instead of the generic "You do not have permission".
* New: every password field has a show/hide button, so a mistyped password can be checked, and the copy buttons for the recovery kit, the username and the authenticator key work in every browser.
* Change: the page text and the readme no longer suggest that a backup can be imported back into the plugin: importing is not available yet. The offline tool opens the archive and gives you your data as readable files. The plugin page description and FAQ also explain more clearly what Eleva CRM for Photographers does.
* Compatibility: tested with the latest WordPress (7.1.x). Update Eleva CRM Pro to 1.6.3 for encrypted quote PDFs.

= 1.6.1 =
* New: Vault recovery kit. After setting up the Vault (and on the first unlock after this update) a short wizard shows your recovery phrase and recovery code, asks you to retype the last characters of each to prove you saved them, and emails the kit to the site administrator address. Settings gains "New recovery kit" to replace them at any time.
* New: "Lost your password or authenticator?" now rebuilds both from your recovery phrase or code: choose a new password and scan a new authenticator QR, without losing any data.
* New: if you lost everything, a destructive reset is available from the lock screen, gated by a one-time code emailed to the site administrator and by typing RESET. In Settings the reset accepts your password and authenticator code instead.
* Fix: Vault setup could leave a live Vault with an unverified authenticator, and the recovery phrase and code were never shown. Setup now has two steps: nothing is activated until your first authenticator code is confirmed, and an interrupted setup simply starts over.
* Fix: the Vault password minimum is now 8 characters everywhere. Before, the form accepted 10 while the server required 12, so 10 or 11 characters failed with a confusing error.
* Compatibility: tested with the latest WordPress (7.1.x).

= 1.6.0 =
* New: groundwork for Quotes in Eleva CRM Pro 1.6.0: a Quotes entry in the menu and the hooks Pro uses to prepare quotes, turn confirmed ones into works, add VAT to works and generate quote PDFs. Works created from a quote show "Created from quote" and the lead source; customers list the quotes made for them.
* New: Gender (Male or Female, optional) on every person of a customer, with a Gender column in the Customers list. The Customer form now uses a compact grid.
* New: email addresses must look like name@example.com. The Customer form highlights wrong fields in red and says what to fix, and the server rejects invalid addresses with a clear error instead of silently emptying them.
* New: Settings gains a Company details section, filled in by Pro and printed in the header of the quote PDFs.
* Fix: your personal data is protected from accidental loss. A form that cannot read a saved value (for example after the Vault key changed) no longer shows it as empty and then overwrites it on save; unreadable values are kept as they are and flagged with a notice. A locked Vault refuses to save personal details in plain text and asks you to unlock it, keeping your changes in the form. The classic editor screens no longer encrypt a value twice.
* Fix: text with accents and backslashes was altered on save in customers, services, works and memory cards (for example "è" stored as "u00e8"). Everything is now saved exactly as typed.
* Fix: the admin app's inline CSS (notice hiding, no outer scrollbar, hidden footer) was never printed because it was attached to a stylesheet handle that did not exist yet. It is now applied.
* Fix: a work must have an event date; without one it was saved but never appeared in the Works list.
* Fix: browsers could keep serving an older build of the app after an update; the asset version now changes with every build.
* Compatibility: tested with the latest WordPress (7.1.x).
* i18n: new interface strings for this release are translated into Italian (it_IT).

= 1.5.0 =
* New: Total Price is now calculated automatically from the work's service rows (plus any supplier referral fees, with Eleva CRM Pro) instead of being typed by hand, so it can never drift out of sync with what the services actually cost.
* New: Work edit screen gains a Suppliers section (requires Eleva CRM Pro) for tracking referral fees owed to wedding planners, venues, and other suppliers who refer a job.
* Fix: a work saved before this change, with a total price but no service rows, keeps its existing total instead of it being reset to zero. The work form shows a notice on any such work explaining that adding a service row lets the total be calculated automatically again.
* Fix: the Calendar view could show a customer name with a wrongly HTML-encoded ampersand, and Kanban task reminders never appeared on the Calendar at all. Both were caused by this plugin and Eleva CRM Pro registering the same calendar endpoint; this plugin now steps aside when Pro is active.
* i18n: new interface strings for this release are fully translated into Italian (it_IT).

= 1.4.2 =
* Fix: cleaned up wording on several vault, recovery, and file-selection screens for clarity and consistency.
* i18n: Italian (it_IT) translation completed for a couple of messages that had never been translated, including the session idle-timeout warning and an alternate recovery option on the "forgot my authenticator" screen.

= 1.4.1 =
* New: Work edit screen gains a Reminders section (requires Eleva CRM Pro): set a date offset from the event date and it turns into a Kanban task, with optional Google Calendar sync.
* New: "Open Google Calendar" button on the Calendar page, shown once a Google Account is connected (requires Eleva CRM Pro 1.4.1+).
* Fix: Dashboard revenue now scopes each payment installment by its own date instead of its work's event date. A payment dated in a different year than its work no longer gets double-counted in one year and missed in the other.
* Fix: Google Calendar. Removing an event from the Calendar view no longer appears to succeed when the request actually failed.
* Fix: Settings page. The Vault and Backup sections' description text no longer renders in a narrow column with empty space beside it; text now fills the panel like every other section.

= 1.4.0 =
* New: encrypted CRM backup. Download a single archive of all your CRM data (customers, works, services, memory cards, and every attached file) from the Settings page, protected by your Vault password and authenticator code. Nobody, including us, can open it without them.
* New: a standalone, dependency-free command-line decrypt tool ships with the plugin, so a backup can be restored on any machine with just PHP: no WordPress or plugin install required.
* Improved: refreshed spacing, borders, and layout across every form and list screen; the sidebar now collapses to icon-only and remembers your choice; tables get a persistent scroll indicator instead of relying on the operating system's hidden scrollbar.
* Fix: the sidebar's collapse/expand button and the calendar's day-of-week column headers now translate correctly in Italian. They previously always showed in English regardless of site language.
* Note: with Eleva CRM Pro connected to Google Drive, backups also run automatically on a schedule. See the Pro changelog.

Entries for versions older than 1.4.0 were removed from this list to keep it short.

== Upgrade Notice ==

= 1.7.0 =
You can now restore a backup from Settings, with a safety copy first and an undo. Documents you attach to a work are now kept private, and so are the titles of clients, works and quotes: after updating, unlock the Vault and let the short encryption finish. Update Eleva CRM Pro to 1.7.0 too.

= 1.6.10 =
Fixes a critical error that could appear when turning on the Vault, creating the backup keys or making a backup on some hosting plans. Recommended for everyone.

= 1.6.9 =
Fixes an error that could appear when opening a page of the CRM after an update, and makes updates take effect at the first reload. Recommended for everyone.

= 1.6.8 =
Makes the CRM easier to use on a phone or a small screen and explains why the fingerprint option is off when the site address is not secure. Update Eleva CRM Pro to 1.6.7 too.

= 1.6.7 =
Fixes the Vault screens on a new installation, which could be cut off with no way to reach their buttons. Recommended if you have not set up the Vault yet.

= 1.6.6 =
Memory Cards move into a new Storage page, with backup locations, shots and delivery notes. Unlock the Vault with a touch where your browser supports it. Payments are now worked out from the installments of a work. Update Eleva CRM Pro to 1.6.6 too.

= 1.6.5 =
A star now shows what changed in each section after an update, there is a Changelog page, and forms look more even. Update Eleva CRM Pro to 1.6.5 too: reminders, two-way Google Calendar sync and the INPS surcharge need both plugins.

= 1.6.4 =
The Vault is now optional: sites without one are asked once whether to turn it on, and notes and the service catalog are now encrypted too. After updating, unlock the Vault and let the short encryption finish. Update Eleva CRM Pro too.

= 1.6.2 =
Security update: the Vault now locks on every browser and device, failed Vault attempts are limited per Vault, plugin records are reachable only from the Eleva CRM app, and backups are better protected. You unlock the Vault once after updating. Safe in-place update.

= 1.6.1 =
Adds a Vault recovery kit (phrase and code, shown, verified and emailed to the site administrator) and a way to rebuild password and authenticator from it without losing data, plus a safer two-step Vault setup. After updating, the first unlock asks you to save a new recovery kit. Safe in-place update, compatible with Eleva CRM Pro.

= 1.6.0 =
Adds the groundwork for Quotes, VAT and PDF quotes (needs Eleva CRM Pro 1.6.0), a Gender field for customers, stricter email validation, and protection against accidental loss of saved personal data. Tested with the latest WordPress. Safe in-place update: without Pro 1.6.0 nothing new shows up and everything else works as before.

= 1.5.0 =
Total Price is now calculated automatically from the services you enter, and can no longer be typed by hand. A work saved before this update with a total but no service rows keeps its existing total; the work form tells you to add a service row so it can be calculated automatically. Update Eleva CRM Pro to 1.5.0+ for supplier referral fees.

= 1.4.2 =
Cleans up wording on several screens and completes the Italian translation for a couple of previously English-only messages. Safe in-place update: no data or breaking changes.

= 1.4.1 =
New: Work Reminders section (with Eleva CRM Pro) and an "Open Google Calendar" button. Fixes dashboard revenue scoping, Google Calendar event removal, and Settings page spacing. Safe in-place update: no data or breaking changes.

= 1.4.0 =
New: download a single encrypted backup of all your CRM data from Settings, protected by your Vault password and authenticator code. Safe in-place update: no data or breaking changes. Update Eleva CRM Pro to 1.4.0+ for automatic scheduled backups to Google Drive.

= 1.3.12 =
Fix: Italian translation now covers list screens, Dashboard, and Calendar. Safe in-place update: no data or breaking changes.

= 1.3.11 =
New: Files section now supports external links and a full-size in-browser preview for images and PDFs. Safe in-place update: no data or breaking changes.

= 1.3.10 =
New: clickable title links on Customers/Services/Works/Memory Cards lists and the Dashboard. Fix: Work file downloads and the Notes field. Safe in-place update: no data or breaking changes.

= 1.3.9 =
Security: the vault now re-locks on every WordPress login/logout, so it no longer stays unlocked across sessions, and the Lock Vault button locks immediately. Safe in-place update: your vault password, authenticator and encrypted client data are unchanged. After updating, unlock the vault as usual.

= 1.3.8 =
New: Memory Cards inventory to track your SD/CF cards. Safe in-place update: your vault password, authenticator and encrypted client data stay exactly as they are. After updating, unlock the vault as usual; you will be offered an optional recovery phrase. No data migration, no data loss, no required action. Note: the vault now auto-locks after 15 minutes of inactivity.

= 1.3.7 =
Completes the Italian translation (vault recovery + payment types) and improves translation loading on older WordPress versions. No data or breaking changes.

= 1.3.4 =
Security: vault download ownership check rewritten; PBKDF2 raised to 600k iterations. See changelog for full details.

= 1.3.3 =
Adds a customer works recap table and a way to filter works by customer. No breaking changes to existing data.

= 1.3.1 =
Security: vault cookie upgraded to AES-256-GCM; nonce enforcement tightened; a file-download ID matching issue fixed. Added complete data cleanup on plugin deletion.

= 1.3.0 =
Security hardening: vault setup guard, server-secret hardening, and permission fix. Recommended for all installations. No breaking changes to existing data.

= 1.2.2 =
Adds custom payment types and WP admin theme color support. No breaking changes to existing data.

= 1.2.1 =
Consistent button styles. No breaking changes.

= 1.2.0 =
Layout fix: sidebar and main content scroll independently. No breaking changes to existing data.

= 1.1.0 =
Adds Quick Notes and Calendar Color fields to Works. No breaking changes to existing data.

= 1.0.0 =
Initial public release.
