# ShortLink - Multi User URL Shortener

Modern Multi-User URL Shortener with Glassmorphism UI, Admin Panel, Link Masking for WhatsApp/Facebook, and Bottom Navigation.

Built with pure PHP + MySQL (Hostinger ready).

## Features

- Single Login Form (Admin + User same form)
- Admin Dashboard with Stats, Create Links, Manage Users, All Links
- User Dashboard - Create & manage own short links
- Full Link Masking (WhatsApp/Facebook shows only Title + Description + Image)
- Bottom Navigation (Mobile friendly)
- Glassmorphism + Purple theme
- Click counter, Search, Edit, Delete, Copy
- Clean short URLs: `yourdomain.com/abc123`

## Default Admin Login

- **Email:** `admin@link666xx.com`
- **Password:** `admin123`

(Change after first login)

## Setup (Hostinger)

1. Upload all files to `public_html`
2. Create MySQL database
3. Import `database.sql` in phpMyAdmin
4. Copy `.env.example` to `.env` and fill your database credentials
5. Open domain → Login page

## Files

```
index.php          → Redirects to login
login.php          → Login page
dashboard.php      → User dashboard
admin.php          → Admin panel
redirect.php       → Short link + Masking logic
config.php         → DB + Auth helpers
logout.php
.htaccess
database.sql
.env.example
```

## How Masking Works

- When WhatsApp / Facebook / Telegram opens the short link → Only Title + Description + Image is shown
- When a real user clicks → Redirects to the real destination URL
- Original long URL is never revealed in preview
