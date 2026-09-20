# Pretty Client Management

A WordPress plugin providing a customizable CRM (Accounts, Contacts, Opportunities, Activities) and a powerful Projects module (Projects, RAID Log, Milestones, Time Entries, Help Tickets).

Pretty Client Management includes both an employee interface and a client portal for project management. This plugin can be used from lead intake, to tracking the sales pipeline, all the way through project management and time tracking. The system includes a contact form builder, a full admin experience, the capability to export all data, and much more. 

## Requirements

- WordPress 6.0+
- PHP 7.4+

## What's included

- **CRM** — Accounts, Contacts, Opportunities (with a pipeline board and stage history), Activities, and a report builder with drill-downs and scheduled email delivery.
- **Projects** (optional module) — Projects, Tasks, a weekly Timesheet, Time Entries, a RAID Log, Help Tickets, and project documents. Project types follow one of four process archetypes (retainer, fixed scope, time & materials, internal), each with its own stages and time rules.
- **Employee Portal** — staff work from the front end of the site (`/staff/` by default) rather than in wp-admin, with a permission model of Profiles (exactly one baseline per person) and Permission Sets (any number of additive extensions) modelled on Salesforce's own.
- **Client Portal** (optional, requires Projects) — clients get a low-privilege account to see their own project's summary, milestones, project team, and documents.
- **Email templates & sequences** — merge-field templates generated from each object's own fields, with multi-step sequences and do-not-contact enforcement.
- **Custom fields & layouts** — a custom field is a real table column, not post meta, so it works everywhere a built-in field does: filtering, sorting, the CSV/Salesforce export, and the report builder.
- **Data export** — a Salesforce-ready CSV export (standard Sales Cloud objects or an NPSP-shaped import file) with PCM-prefixed custom fields for anything holding a local id.
- **Themeable admin** — five built-in colour themes, all driven by CSS custom properties, checked against WCAG AA in every theme by an automated contrast test.

## Development

There is no build process — CSS and JS are plain files, edited directly. No npm, no Sass, no compilation step.

```bash
php tests/run.php        # PHP: models, permissions, REST routing
node tests/boot.js       # does the admin app start without throwing?
node tests/contrast.js   # WCAG contrast, every theme
node tests/record.js     # record page, read view, lookups
node tests/drawer.js     # clicking through a project and the timesheet
node tests/pm-views.js   # Projects registrations and pure helpers
node tests/prefill.js    # related-list linking
node tests/urls.js       # screen URL building, both hosts
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

Built by [Pretty Code Machine](https://prettycodemachine.com).
