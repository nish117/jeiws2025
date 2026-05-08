# JEIWS Website — CMS & Maintenance Guide

**J.E. Infrastructure Waterproofing & Services Pvt. Ltd.**

---

## Table of Contents

1. [Accessing the CMS](#1-accessing-the-cms)
2. [First-Time Setup](#2-first-time-setup)
3. [Logging In & Out](#3-logging-in--out)
4. [Managing Projects](#4-managing-projects)
5. [Changing the Admin Password](#5-changing-the-admin-password)
6. [Contact Form & Job Applications](#6-contact-form--job-applications)
7. [Updating the Careers Page](#7-updating-the-careers-page)
8. [Security Configuration](#8-security-configuration)
9. [File Structure Reference](#9-file-structure-reference)

---

## 1. Accessing the CMS

Open your browser and go to:

```
http://localhost/jeiws2025/admin/
```

> If the site is hosted live, replace `localhost/jeiws2025` with the actual domain.

---

## 2. First-Time Setup

This only needs to be done once — when the site is first deployed on a new server.

### Step 1 — Set the Setup Secret

Open `.htaccess` in the project root and set a secret value:

```
SetEnv CMS_SETUP_SECRET thisisthesecretcodeforjeiws2022
```

Write this value down. You will need it once during setup.

### Step 2 — Create the Admin Password

1. Visit `admin/login.php` in your browser.
2. A setup form appears (only shown if no password exists yet).
3. Enter the **Setup Secret** exactly as set in `.htaccess`.
4. Choose a strong password (minimum 8 characters) and confirm it.
5. Click **Set Password**.

The setup form will never appear again once the password is saved. The setup secret becomes irrelevant after this point.

---

## 3. Logging In & Out

**Login:** Visit `admin/` and enter your password.

**Logout:** Click the **Logout** button in the top-right corner of any CMS page.

Sessions expire when the browser is closed.

---

## 4. Managing Projects

### Adding a New Project

1. From the CMS dashboard, click **+ Add Project**.
2. Enter the **Title** (required) and **Description** (location, area, notes, etc.).
3. Click **Save Details**. The project is created and you are taken to its edit page.
4. Use the **Gallery** panel on the right to upload photos (JPEG, PNG, or WebP — max 10 MB each).
5. The first photo uploaded automatically becomes the **featured image** (shown on the homepage carousel).

### Editing a Project

1. From the dashboard, click **✏ Edit** on any project card.
2. Update the title or description and click **Save Details**.
3. Add or remove photos from the Gallery panel.

### Setting the Featured (Main) Image

On the project edit page, hover over any photo in the gallery and click the **⭐ star** button. The starred photo becomes the main image shown on the homepage and gallery page.

### Deleting a Photo

Hover over a photo in the gallery and click the **🗑 delete** button. This permanently removes the photo from disk.

### Deleting a Project

From the dashboard, click **🗑 Delete** on a project card and confirm. This deletes the project and all its photos permanently.

---

## 5. Changing the Admin Password

There is no "change password" form. To reset the password:

1. Delete the file `data/cms_credentials.txt`.
2. Visit `admin/login.php` — the setup form reappears.
3. Follow the [First-Time Setup](#2-first-time-setup) steps again.

> You will need the Setup Secret from `.htaccess` to complete the reset.

---

## 6. Contact Form & Job Applications

Both forms on the website send emails via Gmail SMTP.

### Configuration

SMTP credentials are stored in `config/mail.php`:

```php
return [
    'username' => 'bajra.nish@gmail.com',
    'password' => 'your-gmail-app-password',
    'to'       => 'bajra.nish@gmail.com',
    ...
];
```

### If emails stop working

Gmail App Passwords can be revoked. To generate a new one:

1. Go to [myaccount.google.com](https://myaccount.google.com) → **Security** → **2-Step Verification** → **App passwords**.
2. Create a new App Password for "Mail".
3. Copy the 16-character code into `config/mail.php` under `'password'`.

> Never use your main Gmail password here — always use an App Password.

### Where emails are received

Both the contact form (`send_email.php`) and job applications (`send_application.php`) deliver to `bajra.nish@gmail.com`. To change the recipient, update `'to'` in `config/mail.php`.

---

## 7. Updating the Careers Page

The careers page (`vacancies.html`) is a plain HTML file — edit it directly in a code editor.

### To add a new vacancy

Copy an existing `<div class="vacancy-card">` block and update the title, badge, description, and requirements list.

### Vacancy badge types

| Class | Colour | Use for |
|-------|--------|---------|
| `vacancy-badge intern` | Green | Internships |
| `vacancy-badge full-time` | Blue | Full-time roles |
| `vacancy-badge part-time` | Purple | Part-time roles |

### To update the "Open Positions" count in the hero

Find this section near the top of `vacancies.html` and update the number:

```html
<div class="stat-val">1</div>
<div class="stat-lbl">Open Position</div>
```

---

## 8. Security Configuration

### Files that must not be publicly accessible

| Path | Protection | Contains |
|------|-----------|---------|
| `config/mail.php` | `.htaccess` Deny | SMTP credentials |
| `data/cms_credentials.txt` | `.htaccess` Deny | Hashed admin password |
| `data/projects.json` | `.htaccess` Deny | Raw project data |

Both `config/` and `data/` directories have `.htaccess` files that block all direct HTTP access. Do not remove them.

### Setup Secret

Stored in the root `.htaccess` under `SetEnv CMS_SETUP_SECRET`. This only matters when `data/cms_credentials.txt` does not exist. Once the password is created, this value is no longer checked.

### Changing the Setup Secret

Open the root `.htaccess` and update the value:

```
SetEnv CMS_SETUP_SECRET new_secret_value_here
```

---

## 9. File Structure Reference

```
jeiws2025/
├── admin/                  — CMS backend (password-protected)
│   ├── api.php             — AJAX endpoint for all CMS actions
│   ├── functions.php       — Shared helpers (load/save projects, CSRF)
│   ├── index.php           — Project list dashboard
│   ├── project.php         — Add / edit project page
│   └── login.php           — Login & first-time setup
├── assets/
│   ├── project-images/     — Uploaded project photos (organised by project ID)
│   └── logo.png, favicon.png, etc.
├── config/
│   └── mail.php            — SMTP credentials (blocked from web access)
├── css/
│   └── styles.css          — All site styles
├── data/
│   ├── projects.json       — Project data (source of truth)
│   └── cms_credentials.txt — Hashed admin password
├── lib/PHPMailer/          — PHPMailer library
├── src/js/
│   ├── projects.js         — Auto-generated from projects.json by the CMS
│   ├── main.js             — Homepage carousel
│   ├── gallery.js          — Gallery page
│   └── script.js           — Site-wide JS (header, footer, scroll, etc.)
├── index.html              — Homepage
├── gallery.html            — Project gallery page
├── vacancies.html          — Careers page
├── area-converter.html     — Area unit converter tool
├── send_email.php          — Contact form mailer
├── send_application.php    — Job application mailer
└── .htaccess               — Cache rules, GZIP, SetEnv for CMS secret
```

---

*Last updated: May 2026*
