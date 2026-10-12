---
name: nexora-auth-flow
description: How Nexora registration, login, email OTP password reset, reCAPTCHA and the post-login redirects work, and the rules for changing them safely. Use when editing src/Auth/*, the login redirects, or the reCAPTCHA behaviour.
---

# Auth flow

| Piece | File | Notes |
| --- | --- | --- |
| Registration | `src/Auth/Registration.php` | `registration_form_handle()` (read_input -> validate -> create_member) creates the WP user, a `user_profile` post, links both (`_wp_user_id` / `_profile_id`), emails the admin (`default_admin_mail`). |
| Login + OTP reset | `src/Auth/Login.php` + `src/Auth/Otp.php` | `send_otp` / `verify_otp` / `reset_password` AJAX; `handle_login()` for the form. |
| reCAPTCHA | `src/Auth/Recaptcha.php` | Keys and `recaptcha_enabled` are options. Gate every new logged-out action with it. |
| Redirects / lockout | `src/Core/Access_Control.php` | `login_redirect`, wp-admin bounce, `/login-page`. |

## Rules
- These are the only handlers with `wp_ajax_nopriv_*`. Any new logged-out endpoint needs a nonce, reCAPTCHA (when enabled) and the transient limiter `rate_limited($bucket, $limit, $window)`.
- OTP is a `random_int` code stored **hashed** (`wp_hash_password`) in user meta `reset_otp` with `otp_expiry` (10 min) and `otp_attempts`. Always clear all three via `clear_otp()` on success, expiry or too many attempts. Never log or return the code.
- Keep error messages uniform for unknown-email vs wrong-code cases to avoid account enumeration.
- Non-admins must never reach `wp-admin` / `wp-login.php`; keep AJAX, REST, cron and `admin-post.php` exempt.
- Login and registration pages share the localized global `profileData` (`ajax_url`, nonce). Don't rename it without updating both JS files.
- Mail goes through `wp_mail` (WP Mail SMTP). If you can't test email, say so.

Test by hand: register, log in, run the reset flow with a wrong code until lockout, and confirm a non-admin is bounced from `/wp-admin/`.
