
# Auto QA Site Migration Tests Settings

This document outlines the configuration for the example migration QA tests.

## URLs to Test

Additional URLs to be tested, with the corresponding section indicated in the value:

```php
AUTO_QA_SITE_MIGRATION::$urls_to_test = [
    '/?s=query' => 'search',
    '/robots.txt' => 'robots',
];
```

## URL Transformations

Additional URL transformations to apply to the target URLs:

```php
AUTO_QA_SITE_MIGRATION::$urls_target_transform = [
    '#^(https?://[^/]+)/author/(.+)$#i' => '$1/authors/$2',
];
```

## Test Settings

The structure for defining test settings:

### Parent Key
The section key that nested tests are related to. Multiple keys can be set, separated by commas. The `all` key applies to all sections and must be the first key.

#### Options:
- all
- author_tag
- category
- post
- post_tag
- robots
- search

### Child Key
The unique test ID.

### Nested Items
An array of test parameters.

#### Parameters Structure:
- **name**: Test name.
- **source**: The data source.
  - Options: `body`, `first_header`, `last_header`
- **selector**: Regex pattern with a named `<result>` group.
- **[selector-target]**: Regex pattern with a named `<result>` group specific to the target.
- **[callback]**: A callback function name.
  - Options:
    - `compare_case_insensitive`
    - `compare_comma_separated`
    - `compare_date`
    - `compare_gtm_data`
    - `compare_images`
    - `compare_images_count`
    - `compare_presence`
    - `compare_schemas`
    - `compare_with_regex`
    - `compare_with_transform`
- **[callback-arg-1]**: The first callback argument.
- **[callback-arg-2]**: The second callback argument.
- **[callback-arg-3]**: The third callback argument.

## Example Tests

### General (applies to all sections):

```php
AUTO_QA_SITE_MIGRATION::$tests = [
    'all' => [
        /*
         * The following examples apply to all url sections.
        */
        'http-status' => [
            'name' => 'HTTP Status Code',
            'source' => 'first_header',
            'selector' => '#^HTTP/\S+ (?<result>\d+)#im',
        ],
        'http-location' => [
            'name' => 'HTTP Redirect Destination',
            'source' => 'first_header',
            'selector' => '#^location: (?<result>.*?)\r?$#im',
        ],
        'http-content-type' => [
            'name' => 'HTTP Content-Type',
            'source' => 'last_header',
            'selector' => '#^content-type: (?<result>.*?)\r?$#im',
            'callback' => 'compare_case_insensitive',
        ],
        'title' => [
            'name' => 'Title',
            'source' => 'body',
            'selector' => '#<head>(?(?!</head>).)+?<title>(?<result>(?(?!</title>).)*?)</title>#is',
        ],
        'html-canonical' => [
            'name' => 'Link Canonical',
            'source' => 'body',
            'selector' => '#<head>(?(?!</head>).)+?<link\s(?<result>[^>]*rel=(\'|")canonical\2[^>]*?)/?>#is',
            'callback' => 'compare_with_regex',
            'callback-arg-1' => '#href=(\'|")(?<result>(?(?!\1).)*?)\1#is',
        ],
        'meta-robots' => [
            'name' => 'Meta Robots',
            // 'source' => '',
            // 'selector' => '',
        ],
        'meta-description' => [
            'name' => 'Meta Description',
            // 'source' => '',
            // 'selector' => '',
        ],
    ],
    'post' => [
        'meta-article-published' => [
            'name' => 'Meta article:published_time',
            // 'source' => '',
            // 'selector' => '',
            // 'selector-target' => '',
            // 'callback' => 'compare_date',
        ],
        'meta-article-modified' => [
            'name' => 'Meta article:modified_time',
            // 'source' => 'body',
            // 'selector' => '',
            // 'selector-target' => '',
            // 'callback' => 'compare_date',
        ],
        'meta-author' => [
            'name' => 'Meta Author',
            // 'source' => '',
            // 'selector' => '',
            // 'selector-target' => '',
            // 'callback' => 'compare_with_transform',
            // 'callback-arg-1' => '',
            // 'callback-arg-2' => '',
        ],
        'taxonomy-categories' => [
            'name' => 'Categories',
            // 'source' => '',
            // 'selector' => '',
        ],
        'taxonomy-primary-category' => [
            'name' => 'Primary Category',
            // 'source' => '',
            // 'selector' => '',
        ],
        'taxonomy-tags' => [
            'name' => 'Tags',
            // 'source' => '',
            // 'selector' => '',
        ],
        'featured-image' => [
            'name' => 'Featured Image' . PHP_EOL . 'URL (Alt Text)',
            // 'source' => '',
            // 'selector' => '',
            // 'callback' => 'compare_images',
        ],
        'post-images' => [
            'name' => 'Article Images' . PHP_EOL . 'URL (Alt Text)',
            // 'source' => '',
            // 'selector' => '',
        ],
        'post-images-count' => [
            'name' => 'Article Images Count' . PHP_EOL . 'URL (Alt Text)',
            // 'source' => '',
            // 'selector' => '',
        ],
        'post-h1' => [
            'name' => 'H1 of Article',
            // 'source' => '',
            // 'selector' => '',
        ],
    ],
    'category,author_tag' => [
        /*
         * The following examples apply to category and author_tag url sections.
        */
        'head-pagination-next' => [
            'name' => 'Pagination link (head) rel=next',
            // 'source' => '',
            // 'selector' => '',
        ],
        'head-pagination-prev' => [
            'name' => 'Pagination link (head) rel=prev',
            // 'source' => '',
            // 'selector' => '',
        ],
        'content-pagination-next' => [
            'name' => 'Pagination link (content) rel=next',
            // 'source' => '',
            // 'selector' => '',
        ],
        'content-pagination-prev' => [
            'name' => 'Pagination link (content) rel=prev',
            'source' => 'body',
            // 'source' => '',
            // 'selector' => '',
        ],
    ],
    'robots' => [
        'robots-content' => [
            'name' => 'Robots.txt',
            'source' => 'body',
            'selector' => '#(?<result>.*?)\s?$#is',
        ],
    ],
];
```
