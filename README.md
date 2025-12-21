# ContentTypeMigrationsBundle

This is a bundle for Ibexa DXP. It automatically creates migrations for content types and content type groups.
The goal is to eliminate the need to manually create migrations or test them – instead have Ibexa auto-generate them for us whenever content types change.

## Features
* Automatically create migrations for Ibexa content types and content type groups
* Supports Ibexa DXP Headless, Experience and Commerce editions via `ibexa/migrations`
* Supports Ibexa DXP Open Source Edition via `mrk-te/ibexa-migration-bundle2`

## Installation

### 1. Install the bundle

```bash
composer require vardumper/content-type-migrations-bundle:dev-main
```

### 2. Register the bundle in your `config/bundles.php`:

```php
return [
    // ...
    vardumper\IbexaAutomaticMigrationsBundle\ContentTypeMigrationsBundle::class => ['all' => true],
];
```

## Roadmap
* Display Migrations and their status in the amdin panel (for admin users only)
* Allow admins to execute pending migrations in the admin panel