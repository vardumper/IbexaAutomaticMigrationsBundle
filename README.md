# IbexaAutomaticMigrationsBundle

This is a bundle for Ibexa DXP. It automatically creates migrations for content types and content type groups.
The goal is to eliminate the need to manually create migrations or test them – instead have Ibexa auto-generate them for us whenever content types change.
Migrations are created in the default locations (in `src/Migrations/Ibexa/migrations` when using ibexa/migrations or in `src/MigrationsDefinitions` when using kaliop, tanoconsulting, mrk-te open source migration bundle).

## Requirements
* Ibexa DXP >= v5.0
* Ibexa DXP >= v4.4 (untested)

## Features
* Automatically create migrations when changes are made in the admin panel
* Supports Ibexa DXP v5.x Headless, Experience and Commerce editions via `ibexa/migrations`
* Supports Ibexa DXP v5.x. Open Source Edition via `mrk-te/ibexa-migration-bundle2`

## Supported Types of Migrations
* Content Type Group (create, update, delete) 
* Content Type (create, update, delete)

## Installation

### 1. Install the bundle

```bash
composer require vardumper/ibexa-automatic-migrations-bundle:dev-main
```

### 2. Register the bundle in your `config/bundles.php`:
Remember not to activate this bundle for all environments, usually your development environment is where you want to configure things and have these changes applied elsewehere by executing migrations.

```php
return [
    // ...
    vardumper\IbexaAutomaticMigrationsBundle\IbexaAutomaticMigrationsBundle::class => ['dev' => true],
];
```

## Testing

This bundle uses [Pest](https://pestphp.com/) for testing.

### Running Tests

First, install the development dependencies:

```bash
composer install --dev
```

Then run the tests:

```bash
./vendor/bin/pest
```

### Test Structure

- `tests/Feature/DeleteMigrationTest.php` - Tests the delete migration generation functionality
- `tests/Feature/ContentTypeListenerTest.php` - Unit tests for the ContentTypeListener class

## Roadmap
* Display Migrations and their status in the amdin panel (for admin users only)
* Allow admins to execute pending migrations in the admin panel
* Support more types of migrations, not only content types are relevant, but Languages, Sections, etc.
* Allow the user to select (via admin panel and/or configuration file) which data points migrations are created for
