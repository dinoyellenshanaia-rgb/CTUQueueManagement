# CTU Integrated Student Services and Queue Management

A PHP/MySQL web application for student, office-staff, and administrator queue workflows. It is designed to run locally in XAMPP at `http://localhost/CTUQueueManagement/`.

## Requirements

- XAMPP with Apache, MySQL/MariaDB, PHP 8+, PDO MySQL, and `mbstring`
- A browser; Bootstrap 5 and Bootstrap Icons are loaded from their public CDNs, so an internet connection is needed to display the styled interface and icons

## Install and configure

1. Copy the `CTUQueueManagement` folder to `C:\xampp\htdocs\CTUQueueManagement`.
2. Open the XAMPP Control Panel and start **Apache** and **MySQL**.
3. Visit `http://localhost/phpmyadmin/`.
4. For a new installation, choose **Import**, select this project's `database.sql`, and run the import. It creates the `ctu_queue_management` database, tables, and five starter offices with editable sample services.
5. The default local XAMPP connection in `config/db.php` is `127.0.0.1`, database `ctu_queue_management`, user `root`, and a blank password. To use other values, set `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` in the Apache/PHP environment. Alternatively, edit those local defaults. Never place database credentials in JavaScript or commit real credentials.
6. Visit `http://localhost/CTUQueueManagement/`.
7. Create the first administrator at `http://localhost/CTUQueueManagement/setup-admin.php`. The page is one-time: it stops accepting setup after an administrator is created. Use a unique password with at least 14 characters. Keep this setup page inaccessible to other users after setup (for example, remove `setup-admin.php` from a deployed copy).
8. Log in with the administrator account. Create staff accounts, assign offices, configure office schedules, and activate/deactivate offices or services from the administrator dashboard.
9. Students can register from the landing page. Staff accounts are created by an administrator and can sign in through the regular login page.

For better local database isolation, create a dedicated MySQL user with access only to `ctu_queue_management` rather than using XAMPP's default `root` account.

## Upgrade an existing legacy database

Do not import `database.sql` over an existing installation. It is for fresh databases only. The legacy schema used `offices.office_name`, `services.service_name`, `users.password`, and a different queue-ticket layout; the current app uses the schema in `database.sql`.

1. In phpMyAdmin, select `ctu_queue_management` and use **Export** to save a full SQL backup.
2. In the database, run the two preflight `SELECT` statements at the top of `database-migration.sql`. If either returns rows, stop and resolve those duplicate records before proceeding.
3. In phpMyAdmin, choose **Import**, select `database-migration.sql`, and run it once. It renames legacy columns, adds the fields and queue counter table required by the current app, preserves ticket/user rows, and maps legacy `skipped` tickets to `No-show`.
4. Finish password migration from a Windows command prompt (not a browser):

   ```text
   C:\xampp\php\php.exe C:\xampp\htdocs\CTUQueueManagement\migrate-legacy-passwords.php
   ```

   The command hashes any legacy plain-text password values, keeps values that are already valid password hashes, then removes the old `password` column. Do not expose or run this file through a web server.
5. Reload `http://localhost/CTUQueueManagement/`. If any migration step fails, stop, restore the backup, and resolve the reported cause before retrying. The SQL migration is a one-time upgrade and is not safe to import twice.

## Smoke-test the workflows

1. Register a student and log in.
2. Select an office and service, then join. Confirm a ticket appears under **Your active tickets**.
3. Keep the student dashboard open. Queue updates and notifications poll every 15 seconds; no browser notification permission is required.
4. Create a staff account in the administrator dashboard and sign in in another browser/private window. Staff can filter by service and date, call the next student (optionally limited to a service), mark them serving, complete service, skip them to the end, or mark a called ticket no-show.
5. Confirm student queue status and notifications update, and review the completed ticket in the staff and administrator dashboards.
6. Use the administrator dashboard to deactivate a user/office/service and confirm the change takes effect on subsequent requests.

## Queue and data behavior

- Queue numbers are scoped by office and service date. Each office's counter row is locked in a transaction during ticket creation and skip/requeue operations; unique database keys provide a second duplicate safeguard.
- A generated unique key prevents a student from holding more than one `Waiting`, `Called`, or `Serving` ticket at the same office. Students may hold active tickets at different offices.
- Students may cancel only their own waiting ticket. Staff authorization is checked against the assigned office for every API action.
- Queue numbers start again on a new database service date without deleting prior tickets. Configure the database server's timezone to match the campus.
- Operating hours are not invented. Admins can configure a weekday schedule; once configured, queue joins are refused outside its open hours. If no schedule is configured, the office's explicit queue pause/resume control governs availability.
- Wait estimates appear only after the office has at least three completed transactions with usable timing data. They are an estimate, not a guarantee.
- Notifications are shown in the dashboard; no SMS or browser push service is configured.

## Troubleshooting

- **Database unavailable:** confirm MySQL is running, the database import succeeded, and `DB_HOST`, `DB_NAME`, `DB_USER`, and `DB_PASSWORD` match.
- **PDO driver missing:** in XAMPP, enable `pdo_mysql` in `php.ini` and restart Apache.
- **Registration says setup unavailable:** confirm the SQL import completed and `setup_state` exists.
- **Queue action rejected:** verify the staff member is assigned to that office and that the ticket is in the required status. Staff actions intentionally validate server-side transitions.
- **Styles/icons missing:** allow access to the Bootstrap CDN or vendor Bootstrap 5 and Bootstrap Icons locally and update the links in `includes/header.php`.
- **Apache URL gives 404:** verify the folder is directly under `C:\xampp\htdocs` and use the exact `CTUQueueManagement` path.
