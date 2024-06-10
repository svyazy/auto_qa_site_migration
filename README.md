
# Auto QA Site Migration WP-CLI Command

## Description

The `auto-qa-site-migration` WP-CLI command tests and compares data from the current site with a migrated site. It provides various options to customize the testing and comparison process.

## Installation

1. Place the `auto-qa-site-migration.php` file in your WP-CLI commands directory.
2. Using the [example provided in the documentation](https://github.com/svyazy/auto_qa_site_migration/tree/main/auto-qa-site-migration.php-settings#example-tests), create a configuration file named the current domain code in upper case (e.g. `BV.php`) and place it in the [auto-qa-site-migration.php-settings](https://github.com/svyazy/auto_qa_site_migration/tree/main/auto-qa-site-migration.php-settings) directory.

## Usage

```sh
wp auto-qa-site-migration [--options]
```

### Options

- `--domain=<host>`: **Required**. Target migrated domain to test and compare with.
- `--domain-user=<user>`: User for the basic authentication on the target domain.
- `--domain-password=<password>`: Password for the basic authentication on the target domain.
- `--sections=<sections>`: URL sections to test, separated by a comma. Possible values:
  - post
  - category
  - author_tag
  - post_tag
  - search
  - robots
  - feed
- `--origin-list-url=<url>`: URL to the file containing a list of origin URLs separated by new lines. The section can be indicated after the URL separated by a comma, or provided via the `--sections` parameter otherwise.
- `--origin-data-path=<path>`: Path to the local file containing the origin data from a previous run. The `--sections` parameter is required in this case.
- `--format-output=<format>`: Show only missing origin values in target data or differences between the origin and target data when applicable. Possible values:
  - missing
  - difference
- `--batch=<number>`: The number of URLs from each source will be fetched and QAed in one batch. Default: 10.
- `--offset=<offset>`: The number of URLs to skip from the generated or loaded list. Default: 0.
- `--timeout=<seconds>`: Set the request timeout in seconds. Default: 30.
- `--sleep=<seconds>`: The number of seconds to sleep between the batches. Default: 0.

### Examples

1. Basic usage with required parameters:

   ```sh
   wp auto-qa-site-migration --domain=example.com
   ```

2. With basic authentication:

   ```sh
   wp auto-qa-site-migration --domain=example.com --domain-user=admin --domain-password=secret
   ```

3. Specifying sections to test:

   ```sh
   wp auto-qa-site-migration --domain=example.com --sections=post,category
   ```

4. Using origin list URL:

   ```sh
   wp auto-qa-site-migration --domain=example.com --origin-list-url=https://example.com/origin-urls.csv
   ```

5. Formatting output to show differences:

   ```sh
   wp auto-qa-site-migration --domain=example.com --format-output=difference
   ```
