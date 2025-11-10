# Kint for Drupal module

[Kint](https://kint-php.github.io/kint/) is a dumper in the vein of [var_dump()](https://www.php.net/function.var_dump), with keyboard controls, search, access path provision, and automatic data parsing.

This module enables Kint with configurable settings tuned for use in drupal.

## Usage

Once installed, this module enables the standard Kint dump functions `d` and `s` in both PHP and twig. Bundled Kint themes are available to select from, and you can also configure the date format.

When the module loads the module setting "Enable early dump" determines whether dumps will be visible. After authentication the user permissions will decide.

### Twig

Twig development mode must be enabled in `Configuration > Development settings` for dumps in twig templates to be visible.

### Devel

When installed alongside [Devel](https://www.drupal.org/project/devel), Kint can be used as a Devel dumper. In this mode Devel's permissions will be used. Kint has the option to override Devel's dump in `ddebug_backtrace` and provide its own.

## Requirements

This module requires the following composer dependencies:

- [kint-php/kint](https://kint-php.github.io/kint/)
- [kint-php/twig](https://github.com/kint-php/kint-twig)

## Recommended modules

[Devel](https://www.drupal.org/project/devel): Allows dumps to be stored in flash bags. Provides various blocks, pages, and functions for developers.

## Installation

Install as you would normally install a contributed Drupal module. For further information, see [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

## Configuration

- Enable "View kint output" permissions for selected roles at Administration » People » Permissions
- Configure dump settings at Administration » Configuration » Development » Kint settings
