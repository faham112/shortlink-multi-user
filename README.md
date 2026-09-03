# ShortLink - Multi User URL Shortener

Modern Multi-User URL Shortener with Glassmorphism UI (Red · Gray · White · Black theme), Admin Panel, **Fully Masked Links** (looks like News Website on Facebook/WhatsApp), Gallery Image Upload, and Bottom Navigation.

**Version 2.0** — New dark red theme.

Built with pure PHP + MySQL (Hostinger ready).

## Features

- Single Login Form (Admin + User same form)
- Admin Dashboard with Stats, Create Links, Manage Users, All Links
- User Dashboard - Create & manage own short links
- **Fully Masked System** – Facebook / WhatsApp / Telegram sees it as a News Website (title + description + big image)
- **Gallery Image Upload** – Upload preview image directly from phone/gallery
- Bottom Navigation (Mobile friendly)
- Glassmorphism + **Red / Gray / White / Black** theme (v2)
- Click counter, Search, Edit, Delete, Copy
- Clean short URLs: `yourdomain.com/abc123`
- Real destination URL never shown in any preview

## Default Admin Login

- **Email:** `admin@link666xx.com`
- **Password:** `admin123`

(Change after first login)

## Setup (Hostinger)

1. Upload all files to `public_html`
2. Create MySQL database
3. Import `database.sql` in phpMyAdmin
4. Copy `.env.example` to `.env` and fill your database credentials
5. Make sure `uploads/` folder exists and has permission **755**
6. Open domain → Login page

## Files

```
index.php          → Redirects to login
login.php          → Login page
dashboard.php      → User dashboard (with image upload)
admin.php          → Admin panel (with image upload)
redirect.php       → Short link + Fully Masked logic
config.php         → DB + Auth helpers
logout.php
.htaccess
database.sql
.env.example
uploads/           → Uploaded preview images
```

## How Fully Masked Works

- When WhatsApp / Facebook / Telegram / Discord opens the short link → Shows only Title + Description + Image (looks like News article)
- Real user click → Redirects to the real destination URL
- Original long URL is never revealed in any preview
- Image can be uploaded from gallery or given as URL
