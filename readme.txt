=== Pretty Client Management ===
Contributors: prettycodemachine
Tags: crm, project management, client portal, agency, time tracking
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.22
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A CRM, project management and a client portal for agencies, all inside your own WordPress site, with your data in your own database.

== Description ==

Pretty Client Management (PCM) is a customizable CRM with a Project Management module, built for growing agencies. It includes a client portal your clients will love.

Everything runs inside your own WordPress site. There's no SaaS account, no per-seat pricing and no third-party scripts, and your data stays in your own database.

= CRM =

* Accounts, Contacts, Opportunities and Activities, with a drag-and-drop pipeline board and stage history.
* A dashboard and report builder. Click any number to see the records behind it.
* Scheduled reports delivered to your inbox.
* A built-in contact form that feeds straight into the CRM. A new inquiry becomes a contact matched to the right account.

= Projects =

* Projects, Tasks, Milestones, a weekly Timesheet, a RAID log and Help Tickets.
* Four delivery processes (retainer, fixed scope, time & materials and internal), each with its own stages and time rules.
* Hours are checked against the project's rules as they're entered, not at invoice time.

= Client Portal =

* Invite a contact with one click.
* Clients sign in to see their project summary, hours against budget, milestones, the project team and shared documents, and to raise help tickets.
* Rates, costs and internal notes never reach the portal.

= Built for small teams =

* **An employee portal, not wp-admin.** Staff work from a clean front-end portal. A profile plus optional permission extensions decide who can view, edit, delete or export in each area.
* **Email templates and sequences.** Merge-field templates and multi-step follow-ups that stop as soon as a reply is logged. Do Not Contact is always respected.
* **Custom fields and layouts.** Every custom field is a real database column, so you can filter, sort, report on and export it. Drag fields into place on each record's layout.
* **Portable data.** Export any object to CSV with Salesforce-ready column names.
* **Themeable and accessible.** A clean Modern look or the bold Classic one, with per-person light and dark mode. Every colour is checked against WCAG AA contrast.
* **No outside services.** Charts are drawn by the plugin itself, and fonts are bundled, so nothing loads from a CDN.

Documentation: [prettyclientmanagement.com/docs](https://prettyclientmanagement.com/docs/)

== Installation ==

1. In WordPress, go to *Plugins → Add New*, search for "Pretty Client Management", then install and activate it.
2. Open *PCM Settings* in the admin menu. Switch on the modules you need (Projects, Client Portal) and add your team.
3. If you want to try everything before adding real clients, load the optional sample data.

The [setup guide](https://prettyclientmanagement.com/quick-start/setup/) walks through each step.

== Frequently Asked Questions ==

= Where is my data stored? =

In your own WordPress database, in custom tables prefixed `pcm_crm_`. Nothing is sent to an outside service.

= Do my staff need wp-admin access? =

No. Staff get a front-end employee portal (at `/staff/` by default, which you can change). What each person can see and do is set by their profile and permission extensions.

= Can clients see rates or internal notes? =

No. The client portal only shows hours, milestones, the team, shared documents and help tickets. Rates, costs and budgets in money are never sent to it.

= What happens to my data if I delete the plugin? =

By default it is kept, so you can reinstall without losing anything. To remove everything on delete, tick *PCM Settings › Platform › Data & Uninstall* before deleting the plugin.

= Can I move my data to another CRM later? =

Yes. Every object exports to CSV, with column headers that use Salesforce field names.

== Screenshots ==

1. The pipeline board, with deals grouped by stage.
2. An account record, with tabs for its related contacts, opportunities and activities.
3. The weekly timesheet, with one row per project.
4. The client portal's project summary.
5. Milestones on the client portal's timeline.
6. Editing a staff profile's access in PCM Settings.

== Changelog ==

= 1.2.22 =
* Added: A Modern interface style, and per-person light and dark mode.

== Upgrade Notice ==

= 1.2.22 =
Adds the Modern interface style and per-person light/dark mode.
