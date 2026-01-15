<table align="center" style="border-collapse:collapse !important; border:none !important;">
  <tr style="border:0px none; border-top: 0px none !important;">
    <td align="center" valign="middle" style="padding:0 1rem; border:none !important;">
      <a href="https://ibexa.co" target="_blank">
        <img src="https://vardumper.github.io/extended-htmldocument/logo-ibexa.svg" style="display:block; height:75px; width:auto; max-width:300px;" alt="Ibexa Logo" />
      </a>
    </td>
    <td align="center" valign="middle" style="padding:0 1rem; border:none !important;">
      <a href="https://www.php.net/manual/de/class.dom-htmldocument.php" target="_blank">
        <img src="https://vardumper.github.io/extended-htmldocument/data-migration.png" style="display:block; height:95px; width:auto; max-width:220px;" alt="Database Migrations Icon" />
      </a>
    </td>
  </tr>
</table>
<h1 align="center">IbexaAutomaticMigrationsBundle</h1>

<p align="center" dir="auto"><a href="https://packagist.org/packages/vardumper/ibexa-automatic-migrations-bundle" rel="nofollow"><img src="https://camo.githubusercontent.com/a9698e608447f18c34de96521994c364de1fcaf63be390eefad01b7356d9c7c4/68747470733a2f2f706f7365722e707567782e6f72672f76617264756d7065722f49626578614175746f6d617469634d6967726174696f6e7342756e646c652f762f737461626c65" alt="Latest Stable Version" data-canonical-src="https://poser.pugx.org/vardumper/ibexa-automatic-migrations-bundle/v/stable" style="max-width: 100%;"></a>
<a href="https://packagist.org/packages/vardumper/ibexa-automatic-migrations-bundle" rel="nofollow"><img src="https://camo.githubusercontent.com/85c575ce287b352b37a4b95b535df0beda5138fe7ff9395210e3d8167c104950/68747470733a2f2f706f7365722e707567782e6f72672f76617264756d7065722f49626578614175746f6d617469634d6967726174696f6e7342756e646c652f646f776e6c6f616473" alt="Total Downloads" data-canonical-src="https://poser.pugx.org/vardumper/ibexa-automatic-migrations-bundle/downloads" style="max-width: 100%;"></a></p>

This is a bundle for Ibexa DXP. It automatically creates migrations for content types and content type groups.
The goal is to eliminate the need to manually create migrations or test them – instead have Ibexa auto-generate them for us whenever content types change.
Migrations are created in the default locations (in `src/Migrations/Ibexa/migrations` when using ibexa/migrations or in `src/MigrationsDefinitions` when using kaliop, tanoconsulting, mrk-te open source migration bundle).

## Requirements
* Ibexa DXP >= v5.0
* Ibexa DXP >= v4.4 (untested)
* A migrations [bundle](https://packagist.org/packages/mrk-te/ibexa-migration-bundle2) if using Ibexa OSS

## Features
* Automatically create migrations when changes are made in the admin panel
* Supports Ibexa DXP v5.x Headless, Experience and Commerce editions via `ibexa/migrations`
* Supports Ibexa DXP v5.x. Open Source Edition via `mrk-te/ibexa-migration-bundle2`

## Supported Types of Migrations
* Content Type Group 
* Content Type
* Language
* Object State Group 
* Object State
* Role
* Section
* URL Wildcards
* URL Manager

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
