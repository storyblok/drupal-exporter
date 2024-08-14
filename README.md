# Storyblok Exporter

A Drupal module that provides a Drush command to export blog articles and migrate content to Storyblok.

## Overview

The **Storyblok Exporter** module allows you to export Drupal blog articles to the Storyblok content management system as reusable components. This can be useful for building decoupled applications that consume content from multiple sources, including Drupal.

**Note: This is a proof-of-concept module and should not be used in production environments.**

## Requirements
- Drupal 10+
- Drush 9+
- A Storyblok account with a Space, a Datasource and a Personal access token

## Drupal Installation
I recommend using `ddev` for local development, which is based on Docker.

### ddev Installation

- If you don't have a local Docker provider, [install it](https://ddev.readthedocs.io/en/stable/users/install/docker-installation/).
- [Install ddev](https://ddev.readthedocs.io/en/latest/users/install/ddev-installation/)

### Drupal installation with ddev

Use the following commands:

```bash
mkdir my-drupal-site && cd my-drupal-site
ddev config --project-type=drupal --php-version=8.3 --docroot=web
ddev start
ddev composer create drupal/recommended-project:^11
ddev composer require drush/drush
ddev config --update
ddev restart
ddev drush site:install --account-name=admin --account-pass=admin -y
ddev launch
# or automatically log in with
ddev launch $(ddev drush uli)
```

## Storyblok Exporter Installation
1. Download or clone this repository into your Drupal module directory (e.g., `/path/to/drupal/web/modules/`).

2. Enable the module by running
```bash
$ [ddev] drush en storyblok_exporter
```
Or through the Drupal admin interface.

## Module Usage
In **Storyblok**, create a new Space, take note of the **Space ID**. Then create a Datasource, and take note of the **Datasource ID**.

Then create a Block of type `Content type` with the following fields:
- title: Text
- body: Richtext
- image: Asset
- tags: Multi-Options

**Note**: it's required to have this exact field names and types because the mapping is currently fixed in the drush commmand.

Set your Storyblok `Personal access token`, the `Space ID` and the `Datasource ID` that you want to export content to in your `settings.local.php`. If this is a fresh Drupal installation, go to `web/sites/default/settings.php`, uncomment the last lines, and then create a `settings.local.php` in the same folder with the following content:

```php
$settings['STORYBLOK_OAUTH_TOKEN'] = 'your-oauth-token'; // this is the Personal access token (in Account settings in Storyblok)
$settings['STORYBLOK_SPACE_ID'] = 'your-space-id'; // this is the ID of your Space (no #)
$settings['STORYBLOK_DATASOURCE_ID'] = 'your-datasource-id'; // this is required for migrating tags
```

In **Drupal**, create some content of type `article`, fill all the fields with some content.

Finally, run the Drush command from the command-line:
```bash
$ [ddev] drush storyblok_exporter:export
```

Or you can use the short alias:
```bash
$ [ddev] drush sbe
```

There are some options for the command, check the command help.

If everything is correct, you should see something similar to the following output:
```bash
$ [ddev] drush storyblok_exporter:export
Successfully created datasource entry: drupal
Uploading image: drupal.jpg
Successfully uploaded image: drupal.jpg
Successfully migrated: Drupal is an open source CMS
Successfully created datasource entry: storyblok
Uploading image: astro-cover.webp
Successfully uploaded image: astro-cover.webp
Successfully migrated: I am switching to Storyblok and Astro
 [success] Exported 2 articles to Storyblok.
 [success] Content succesfully exported 🎉
```

The exported articles will be available as **Stories** in your Space, and you will find the attached images in your **Asset Library**.

## Contributing
Contributions are welcome! Please open an issue or submit a pull request if you have any improvements or bug fixes.

## License
This project is licensed under the MIT License.
