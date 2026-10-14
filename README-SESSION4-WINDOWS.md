# Student Services Information System - Complete Session 4 Project

This folder combines the previous complete Laravel project with the Session 4 overlay. It is intended to be extracted and run as one project; you do not need to copy the overlay manually.

## Windows quick start

Requirements: PHP 8.3+, Composer, Node.js/npm. SQLite's PDO extension must be enabled in PHP.

1. Extract this ZIP to a writable folder, for example `C:\Projects\student-services-session4-complete`.
2. Open that folder in PowerShell or Command Prompt. It must be the folder containing `artisan` and `composer.json`.
3. Run `setup-session4.bat`. It creates `.env` from `.env.example` if needed, generates an application key, applies pending migrations, and builds the frontend.
4. Start the app:

   ```powershell
   php artisan serve
   ```

5. Open `http://127.0.0.1:8000`.

If the frontend build fails due to missing or incompatible Node dependencies, run `npm install` and then `npm run build`. If Composer dependencies are missing, run `composer install`.

## Important database note

The included `database/database.sqlite` is carried over from the previous project version. `php artisan migrate` applies pending migrations without intentionally dropping existing tables. **Do not run `php artisan migrate:fresh --seed` if you want to preserve the included database or any data you add.**

If you want a clean demo database, make a backup first and use a separate copy of the project/database for that experiment.

## Test the project

From the project root:

```powershell
php artisan test
npm run build
php artisan route:list
php artisan migrate:status
```

These commands still need to be run on your Windows machine. Packaging the project does not mean that all tests have already passed.

## Demo logins

The previous project documentation uses the password `password` for seeded demo accounts. Available account names may depend on the database contents. Use only local demo accounts; change demo credentials before exposing the app to a network.

## Included changes

Session 4's overlay has been merged into the project root, including the shared UI updates, Registration-controlled expected release dates, queue polling, Admin portal/catalog pages, account deactivation, migrations, and Session 4 tests.

## Privacy and configuration

For safety, the original `.env` file was not included in this package. Create it from `.env.example` using the setup script. Do not share your own `.env` file because it may contain local credentials and application secrets.
