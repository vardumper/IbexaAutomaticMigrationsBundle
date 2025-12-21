# IbexaAutomaticMigrationsBundle

This is a bundle for Ibexa DXP. It automatically creates migrations for content types and content type groups.
The goal is to eliminate the need to manually create migrations or test them – instead have Ibexa auto-generate them for us whenever content types change.

## Requirements
* Ibexa DXP >= v5.0 (tested)
* Ibexa DXP >= v4.4 (untested)

## Features
* Automatically create migrations when changes are made in the administration
* Supports Ibexa DXP Headless, Experience and Commerce editions via `ibexa/migrations`
* Supports Ibexa DXP Open Source Edition via `mrk-te/ibexa-migration-bundle2`

## Supported Types of Migrations
* Content Type Group
* Content Type

## Installation

### 1. Install the bundle

```bash
composer require vardumper/ibexa-automatic-migrations-bundle:dev-main
```

### 2. Register the bundle in your `config/bundles.php`:

```php
return [
    // ...
    vardumper\IbexaAutomaticMigrationsBundle\IbexaAutomaticMigrationsBundle::class => ['all' => true],
];
```

## Roadmap
* Display Migrations and their status in the amdin panel (for admin users only)
* Allow admins to execute pending migrations in the admin panel
* Support more types of migrations, not only content types are relevant, but Languages, Sections, etc.
* Allow the user to select (via admin panel and/or configuration file) which data points migrations are created for