<?php

WP_CLI::add_command( 'auto-qa-site-migration', 'AUTO_QA_SITE_MIGRATION' );

class AUTO_QA_SITE_MIGRATION {

    /**
     * Test data and compare results from the current site with the migrated one.
     *
     * --domain=<host>
     * : Target migrated domain to test and compare with.
     *
     * [--domain-user=<user>]
     * : User for the basic authentication on the target domain.
     *
     * [--domain-password=<password>]
     * : Password for the basic authentication on the target domain.
     *
     * [--sections=<sections>]
     * : URL sections to test, separated by comma.
     * ---
     * options:
     *   - post
     *   - category
     *   - author_tag
     *   - post_tag
     *   - search
     *   - robots
     *   - feed
     * ---
     *
     * [--origin-list-url=<url>]
     * : URL to the file containing a list of origin URLs separated by new lines.
     *   The section can be indicated after the URL separated by a comma,
     *   or has to be provided via the --sections parameter otherwise.
     *
     * [--origin-data-path=<path>]
     * : Path to the local file containing the origin data from a previous run.
     *   The --sections parameter is required in this case.
     *
     * [--format-output=<format>]
     * : Only show missing origin values in target data
     *   or difference between the origin and target data when applicable.
     * ---
     * options:
     *   - missing
     *   - difference
     * ---
     *
     * [--batch=<number>]
     * : The number of URLs from each source will be fetched and QAed in one batch.
     * ---
     * default: 10
     * ---
     *
     * [--offset=<offset>]
     * : The number of URLs to skip from the generated or loaded list.
     * ---
     * default: 0
     * ---
     *
     * [--timeout=<seconds>]
     * ---
     * default: 30
     * ---
     *
     * [--sleep=<seconds>]
     * : The number of seconds to sleep between the batches.
     * ---
     * default: 0
     * ---
     *
     * ## EXAMPLES
     *
     *     # Run QA tests using basic authentication and the generated list of URLs.
     *     $ wp auto-qa-site-migration --domain=www.foo.com --domain-user=user --domain-password=password
     *
     *     # Run QA tests using the provided list of URLs.
     *     $ wp auto-qa-site-migration --domain=www.foo.com --origin-list-url=https://www.bar.com/origin-list.csv
     *
     *     # Run QA tests using the saved origin data from a previous run for the post section.
     *     $ wp auto-qa-site-migration --domain=www.foo.com --origin-data-path=/path/to/origin-data.csv --sections=post
     *
     * @when after_wp_load
     */

    /**
     * Target domain to test and compare with.
     *
     * @var string
     */
    private string $domain;

    /**
     * Basic authentication credentials.
     *
     * @var array
     */
    private array $credentials = [];

    /**
     * Data sections to test.
     *
     * @var array
     */
    private array $sections = [];

    /**
     * Batch size for the URLs to fetch.
     *
     * @var int
     */
    private int $batch;

    /**
     * Offset for the URLs to fetch.
     *
     * @var int
     */
    private int $offset;

    /**
     * Timeout for the cURL requests.
     *
     * @var int
     */
    private int $timeout;

    /**
     * Sleep time between batch requests.
     *
     * @var int
     */
    private int $sleep;

    /**
     * Log data.
     *
     * @var array
     */
    private array $log = [];

    /**
     * URLs list to test.
     *
     * @var array
     */
    private array $urls = [];

    /**
     * URLs repeat requests count.
     *
     * @var array
     */
    private array $urls_repeat_count = [];

    /**
     * URLs error count.
     *
     * @var int
     */
    private int $urls_error = 0;

    /**
     * URLs failed tests count.
     *
     * @var int
     */
    private int $urls_failed_tests = 0;

    /**
     * Current testing URL.
     *
     * @var string
     */
    private string $url_current;

    /**
     * Format test data output.
     *
     * @var string
     */
    private string $format_output = '';

    /**
     * Whether to fetch the origin data.
     *
     * @var bool
     */
    private bool $fetch_origin_data = true;

    /**
     * Progress bar.
     *
     * @var cli\progress\Bar
     */
    private $progress;

    /**
     * List of additional tests to run (the corresponding section is indicated in the value).
     *
     * @var array
     */
    public static array $tests = [];

    /**
     * List of tests to run (the section is indicated in the key).
     *
     * @var array
     */
    public static array $urls_to_test = [];

    /**
     * List of patterns to transform the target URLs.
     *
     * @var array
     */
    public static array $urls_target_transform = [];

    /**
     * Taxonomies the URLs to test will be generated for.
     *
     * @var array
     */
    public static array $taxonomy = [
        'category',
        'author_tag',
        'post_tag',
    ];

    /**
     * Initialize the command.
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     *
     * @return void
     */
    public function __invoke( $args, $assoc_args ) {
        $file_settings = __FILE__ . '-settings/' . strtoupper( Utils::getDomainCode() ) . '.php';
        if ( ! file_exists( $file_settings ) ) {
            WP_CLI::error( "Settings file $file_settings is not found." );
        }
        require $file_settings;

        if ( empty( $assoc_args['domain'] ) || ! filter_var( $assoc_args['domain'], FILTER_VALIDATE_DOMAIN ) ) {
            WP_CLI::error( 'Target domain is not provided or invalid.' );
        }
        $this->domain = $assoc_args['domain'];

        if ( ! empty( $assoc_args['domain-user'] ) ) {
            $this->credentials['user'] = $assoc_args['domain-user'];
        }
        if ( ! empty( $assoc_args['domain-password'] ) ) {
            $this->credentials['password'] = $assoc_args['domain-password'];
        }
        if ( ! empty( $assoc_args['sections'] ) ) {
            $this->sections = $this->get_sections( $assoc_args['sections'] );
        }
        if ( ! empty( $assoc_args['origin-data-path'] ) ) {
            if ( ! is_file( $assoc_args['origin-data-path'] ) ) {
                WP_CLI::error( 'File with the provided origin data path is not found.' );
            }
            $this->get_origin_data( $assoc_args['origin-data-path'] );
        } elseif ( ! empty( $assoc_args['origin-list-url'] ) ) {
            if ( false === filter_var( $assoc_args['origin-list-url'], FILTER_VALIDATE_URL ) ) {
                WP_CLI::error( 'Provided URL to the Origin URLs list is not valid.' );
            }
            $this->get_origin_urls_list( $assoc_args['origin-list-url'] );
        } else {
            $this->generate_urls();
        }

        if (
            ! empty( $assoc_args['format-output'] )
            && in_array( $assoc_args['format-output'], [ 'missing', 'difference' ], true )
        ) {
            $this->format_output = $assoc_args['format-output'];
        }
        $this->batch = (int) ( $assoc_args['batch'] ?? 10 );
        $this->offset = (int) ( $assoc_args['offset'] ?? 0 );
        $this->timeout = (int) ( $assoc_args['timeout'] ?? 30 );
        $this->sleep = (int) ( $assoc_args['sleep'] ?? 0 );

        $this->run();
    }

    /**
     * Run the command.
     *
     * @return void
     */
    private function run(): void {
        WP_CLI::log( ':: Fetching and QAing data...' );
        $this->progress = WP_CLI\Utils\make_progress_bar( '', count( $this->urls ), 6000 );

        do {
            $urls = array_slice( $this->urls, $this->offset, $this->batch, true );

            $this->fetch_and_spread_data( $urls );
            $this->compare_test_data( $urls );

            $this->offset += $this->batch;
            $urls_count = count( $this->urls );

            if ( $this->sleep ) {
                sleep( $this->sleep );
            }
        } while ( $this->offset < $urls_count );

        $this->progress->finish();

        WP_CLI::warning( PHP_EOL . ":: {$this->urls_failed_tests} URLs were failed to pass some of the tests" );
        if ( $this->urls_error ) {
            WP_CLI::warning( ":: {$this->urls_error} URLs were failed to retrieve the data from" );
        }

        if ( ! empty( $this->log ) ) {
            WP_CLI::success( PHP_EOL . ':: Created reports:' );

            foreach ( $this->log as $log ) {
                $file_name = getcwd() . '/' . stream_get_meta_data( $log['stream'] )['uri'];
                WP_CLI::log( $file_name );
            }
        }
    }

    /**
     * Sanitize the URL path.
     *
     * @param string $url URL to sanitize.
     *
     * @return string
     */
    private function sanitize_url_path( string $url ): string {
        $url_parsed = wp_parse_url( $url );

        if ( empty( $url_parsed['path'] ) ) {
            return $url;
        }

        $path = '';
        $path_parts = explode( '/', $url_parsed['path'] );
        array_shift( $path_parts );
        foreach ( $path_parts as $path_part ) {
            $path .= '/';
            if ( ! empty( $path_part ) ) {
                // The Path part might already be urlencoded
                $path .= rawurlencode( rawurldecode( $path_part ) );
            }
        }

        $url_parsed['path'] = $path;

        return Utils::unparseUrl( $url_parsed );
    }

    /**
     * Fetches the data from the URLs and spreads the tests results.
     *
     * @param array $urls URLs to fetch the data from.
     *
     * @return void
     */
    private function fetch_and_spread_data( array &$urls ): void {
        $curl_general_options = [
            \CURLOPT_HEADER => true,
            \CURLOPT_NOBODY => false,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TCP_NODELAY => true,
            \CURLOPT_HTTP_VERSION => \CURL_HTTP_VERSION_1_1,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 5,
            \CURLOPT_TIMEOUT => $this->timeout,
        ];

        $mh = curl_multi_init();
        foreach ( $urls as $url => &$props ) {
            $endpoints = [ 'target' ];
            if ( $this->fetch_origin_data ) {
                array_unshift( $endpoints, 'origin' );
                $props = [ 'section' => $props ];
            }

            $url_curl = str_replace( [ ' ', '"', '<', '>' ], [ '%20', '%22', '%3C', '%3E' ], $url );
            $url_curl = $this->sanitize_url_path( $url_curl );

            foreach ( $endpoints as $endpoint ) {
                $curl_options = $curl_general_options;
                if ( 'target' === $endpoint ) {
                    if ( ! empty( self::$urls_target_transform ) ) {
                        foreach ( self::$urls_target_transform as $pattern => $replacement ) {
                            $url = preg_replace( $pattern, $replacement, $url );
                            $url_curl = preg_replace( $pattern, $replacement, $url_curl );
                        }
                    }

                    $url = preg_replace( '#^(https?://)' . preg_quote( \wp_parse_url( $url, PHP_URL_HOST ), '#' ) . '#i', '$1' . $this->domain, $url );
                    $url_curl = preg_replace( '#^(https?://)' . preg_quote( \wp_parse_url( $url_curl, PHP_URL_HOST ), '#' ) . '#i', '$1' . $this->domain, $url_curl );
                    $curl_options[ \CURLOPT_USERNAME ] = $this->credentials['user'] ?? null;
                    $curl_options[ \CURLOPT_PASSWORD ] = $this->credentials['password'] ?? null;
                }
                $props[ $endpoint ]['url'] = $url;
                $props[ $endpoint ]['handle'] = curl_init( $url_curl );

                foreach ( $curl_options as $opt => $value ) {
                    curl_setopt( $props[ $endpoint ]['handle'], $opt, $value );
                }

                curl_multi_add_handle( $mh, $props[ $endpoint ]['handle'] );
            }
        }

        $running = null;
        do {
            curl_multi_exec( $mh, $running );
            if ( curl_multi_errno( $mh ) ) {
                WP_CLI::error( 'curl_multi_exec error: ' . curl_multi_errno( $mh ) );
            }
        } while ( $running );

        foreach ( $urls as $url => &$props ) {
            foreach ( $endpoints as $endpoint ) {
                $props[ $endpoint ]['info'] = curl_getinfo( $props[ $endpoint ]['handle'] );
                $props[ $endpoint ]['data'] = curl_multi_getcontent( $props[ $endpoint ]['handle'] );

                if ( curl_errno( $props[ $endpoint ]['handle'] ) ) {
                    $this->log(
                        'error',
                        [
                            $props[ $endpoint ]['url'],
                            curl_error( $props[ $endpoint ]['handle'] ),
                        ]
                    );
                } elseif ( ! $props[ $endpoint ]['info']['http_code'] ) {
                    unset( $urls[ $url ] );
                    if ( ! $this->repeat_fetch_url( $url, $props['section'] ) ) {
                        $this->log(
                            'error',
                            [
                                $props[ $endpoint ]['url'],
                                'Failed to fetch data',
                            ]
                        );
                    }

                    continue ( 2 );
                } else {
                    $headers = substr( $props[ $endpoint ]['data'], 0, $props[ $endpoint ]['info']['header_size'] );
                    $headers = explode(
                        "\r\n\r\n",
                        trim( $headers )
                    );
                    $first_header = reset( $headers );
                    $last_header = end( $headers );
                    $body = substr( $props[ $endpoint ]['data'], $props[ $endpoint ]['info']['header_size'] );

                    // Get the relevant test section keys
                    $tests_relevant = array_keys( self::$tests );
                    foreach ( $tests_relevant as &$key ) {
                        if ( ! in_array( $props['section'], explode( ',', $key ) ) ) {
                            unset( $tests_relevant[ array_search( $key, $tests_relevant ) ] );
                        }
                    }
                    $tests_relevant[] = 'all';

                    $tests = [];
                    foreach ( $tests_relevant as $key ) {
                        $tests = array_merge( $tests, self::$tests[ $key ] );
                    }
                    foreach ( $tests as $test_id => $test ) {
                        $selector = ( 'target' === $endpoint && ! empty( $test['selector-target'] ) ) ? $test['selector-target'] : $test['selector'];
                        $result   = $this->get_regex_result( $selector, ${$test['source']} );

                        $props[ $endpoint ]['test'][ $test_id ] = $result;
                    }
                }

                curl_multi_remove_handle( $mh, $props[ $endpoint ]['handle'] );
            }
        }

        curl_multi_close( $mh );
    }

    /**
     * Retrieve the needed data for testing.
     *
     * @param string $pattern Regex pattern to match the data.
     * @param string $subject Data to match.
     *
     * @return string
     */
    private function get_regex_result( $pattern, $subject ): string {
        preg_match( $pattern, $subject, $matches );

        $result = $matches['result'] ?? 'N/A';
        if ( '' === $result ) {
            $result = 'EMPTY';
        }

        return $result;
    }

    /**
     * URLs data fetch repeater.
     *
     * @param string $url     URL to fetch.
     * @param string $section Section of the URL.
     *
     * @return boolean
     */
    private function repeat_fetch_url( string $url, string $section ): bool {
        $this->urls_repeat_count[ $url ] ??= 0;
        ++$this->urls_repeat_count[ $url ];
        if ( 3 < $this->urls_repeat_count[ $url ] ) {
            return false;
        }

        unset( $this->urls[ $url ] );
        $this->urls[ $url ] = $section;
        --$this->offset;

        return true;
    }

    /**
     * Tests data comparison.
     *
     * @param array $urls URLs to compare the data for.
     *
     * @return void
     */
    private function compare_test_data( array &$urls ): void {
        $test_sections = self::$tests;
        array_walk(
            $test_sections,
            function ( &$val, $key ) {
                foreach ( $val as &$item ) {
                    $item = $key;
                }
            }
        );
        $test_sections = array_merge_recursive( ...array_values( $test_sections ) );

        foreach ( $urls as $url => &$props ) {
            $this->url_current = $url;
            $tests = [];
            foreach ( $props['origin']['test'] as $test_id => &$value_origin ) {
                $section = $test_sections[ $test_id ];
                if ( is_array( $section ) ) {
                    $section = $section[ array_search( $props['section'], $section ) ?: 0 ]; // phpcs:ignore Universal.Operators.DisallowShortTernary.Found
                }

                $callback = self::$tests[ $section ][ $test_id ]['callback'] ?? null;
                $callback_arg_1 = self::$tests[ $section ][ $test_id ]['callback-arg-1'] ?? null;
                $callback_arg_2 = self::$tests[ $section ][ $test_id ]['callback-arg-2'] ?? null;
                $callback_arg_3 = self::$tests[ $section ][ $test_id ]['callback-arg-3'] ?? null;

                if ( is_callable( [ $this, $callback ] ) ) {
                    // Set the callback to the format_output version if it exists
                    if ( $this->format_output && is_callable( [ $this, $callback . '_' . $this->format_output ] ) ) {
                        $callback .= '_' . $this->format_output;

                        $name_append = ( 'missing' === $this->format_output ) ? $this->format_output . ' in target' : $this->format_output;
                        $name_append = ' (' . $name_append . ' only)';
                        if ( ! str_ends_with( self::$tests[ $section ][ $test_id ]['name'], $name_append ) ) {
                            self::$tests[ $section ][ $test_id ]['name'] .= $name_append;
                        }
                    }

                    $passed = $this->$callback( $value_origin, $props['target']['test'][ $test_id ], $callback_arg_1, $callback_arg_2, $callback_arg_3 );
                } else {
                    $passed = $this->unify_input( $value_origin ) === $this->unify_input( $props['target']['test'][ $test_id ] );
                }

                // Skip the test if the callback returned null
                if ( is_null( $passed ) ) {
                    continue;
                }

                $tests[ self::$tests[ $section ][ $test_id ]['name'] ] = [
                    'passed' => $passed,
                    'origin' => html_entity_decode( $value_origin, ENT_QUOTES | ENT_HTML5 ),
                    'target' => html_entity_decode( $props['target']['test'][ $test_id ], ENT_QUOTES | ENT_HTML5 ),
                ];
            }

            // Sort tests by passed status (failed first) and then by name
            array_multisort( array_column( $tests, 'passed' ), SORT_ASC, array_keys( $tests ), SORT_STRING, $tests );
            $this->log(
                'report',
                $tests,
                $props['origin']['url'],
                $props['target']['url']
            );

            WP_CLI::log( $url );
            $this->progress->tick();
        }
    }

    /**
     * Unified input helper.
     *
     * @param string $input Input to process.
     *
     * @return string
     */
    private function unify_input( $input ) {
        $mapping = [
            '’' => "'",  // Right single quotation mark
            '‘' => "'",  // Left single quotation mark
            '”' => '"',  // Right double quotation mark
            '“' => '"',  // Left double quotation mark
            '–' => '-',  // En dash
            '—' => '-',  // Em dash
            '…' => '...', // Ellipsis
            '«' => '"',  // Left double angle quote
            '»' => '"',  // Right double angle quote
            '„' => '"',  // Double low-9 quotation mark
            '‚' => "'",  // Single low-9 quotation mark
            '‹' => "'",  // Single left-pointing angle quote
            '›' => "'",  // Single right-pointing angle quote
            '‐' => '-',  // Hyphen
            '†' => '+',  // Dagger
            '‡' => '++', // Double dagger
            '•' => '*',  // Bullet
            '‒' => '-',  // Figure dash
            '′' => "'",  // Prime
            '″' => '"',  // Double prime
            '‵' => '`',  // Reversed prime
            '‶' => '``', // Reversed double prime
            // ' ' => ' ',  // Non-breaking space
        ];

        return strtr( html_entity_decode( $this->maybe_url( $input ), ENT_QUOTES | ENT_HTML5 ), $mapping );
    }

    /**
     * Images data extraction helper.
     *
     * @param string $content        Content to extract the images data from.
     * @param string $pattern_remove Regex pattern to remove from the content.
     *
     * @return string
     */
    private function extract_images_data( string $content, $pattern_remove = null ): string {
        if ( ! empty( $pattern_remove ) ) {
            $content = preg_replace( $pattern_remove, '', $content );
        }

        $images = '';
        preg_match_all( '#<img\b(?<img>[^>]*?\ssrc="(?<src>[^"]*?)"[^>]*?)/?>#is', $content, $matches );
        foreach ( $matches['src'] as $i => $url ) {
            $url = $this->maybe_url( $url );

            preg_match( '#\salt="(?<alt>[^"]*)"#is', $matches['img'][ $i ], $alt );
            $alt = $alt['alt'] ?? 'N/A';
            $alt = trim( $alt );
            if ( '' === $alt ) {
                $alt = 'EMPTY';
            }

            $value = "$url ($alt)";
            $images = ( empty( $images ) ) ? $value : $images . PHP_EOL . $value;
        }
        if ( '' === $images ) {
            $images = 'N/A';
        }

        return $images;
    }

    /**
     * Callback for Images comparison.
     *
     * @param string $value_origin Origin images data.
     * @param string $value_target Target images data.
     *
     * @return boolean
     */
    private function compare_images( &$value_origin, &$value_target ): bool {
        $value_origin = ( $this->fetch_origin_data ) ? $this->extract_images_data( $value_origin ) : $value_origin;
        $value_target = $this->extract_images_data( $value_target );

        return $this->unify_input( $value_origin ) === $this->unify_input( $value_target );
    }

    /**
     * Callback for Images count comparison.
     *
     * @param string $value_origin Origin images data.
     * @param string $value_target Target images data.
     * @param string $pattern      Regex pattern to remove from the content.
     *
     * @return boolean
     */
    private function compare_images_count( &$value_origin, &$value_target, $pattern ): bool {
        $value_origin = ( $this->fetch_origin_data ) ? $this->extract_images_data( $value_origin, $pattern ) : $value_origin;
        $origin = explode( PHP_EOL, $value_origin );
        $value_target = $this->extract_images_data( $value_target, $pattern );
        $target = explode( PHP_EOL, $value_target );

        return count( $origin ) === count( $target );
    }

    /**
     * Callback for Regex comparison.
     *
     * @param string $value_origin   Origin data.
     * @param string $value_target   Target data.
     * @param string $pattern        Regex pattern to match the data.
     * @param string $pattern_origin Optional regex pattern to match the origin data.
     * @param string $pattern_target Optional regex pattern to match the target data.
     *
     * @return boolean
     */
    private function compare_with_regex( &$value_origin, &$value_target, $pattern, $pattern_origin = null, $pattern_target = null ): bool {
        $value_origin = ( $this->fetch_origin_data ) ? $this->get_regex_result( $pattern, $value_origin ) : $value_origin;
        $value_target = $this->get_regex_result( $pattern, $value_target );

        if ( ! empty( $pattern_origin ) && ! empty( $pattern_target ) ) {
            return $this->compare_with_transform( $value_origin, $value_target, $pattern_origin, $pattern_target );
        }

        return $this->unify_input( $value_origin ) === $this->unify_input( $value_target );
    }

    /**
     * Callback for transformed data comparison.
     *
     * @param string $value_origin   Origin data.
     * @param string $value_target   Target data.
     * @param string $pattern_origin Regex pattern to match the origin data.
     * @param string $pattern_target Regex pattern to match the target data.
     *
     * @return boolean
     */
    private function compare_with_transform( $value_origin, $value_target, $pattern_origin, $pattern_target ): bool {
        preg_match_all( $pattern_origin, $value_origin, $match_origin );
        preg_match_all( $pattern_target, $value_target, $match_target );

        foreach ( $match_origin as $key => $value_arr ) {
            if ( is_int( $key ) ) {
                // Compare the named group values only.
                continue;
            }

            foreach ( $value_arr as $value_key => $value ) {
                $value = strtolower( $value );
                $match_target[ $key ][ $value_key ] = strtolower( $match_target[ $key ][ $value_key ] ?? '' );
                if ( $this->unify_input( json_decode( '"' . $value . '"' ) ) !== $this->unify_input( json_decode( '"' . $match_target[ $key ][ $value_key ] . '"' ) ) ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Callback for comma separated data comparison.
     *
     * @param string $value_origin Origin data.
     * @param string $value_target Target data.
     *
     * @return boolean
     */
    private function compare_comma_separated( $value_origin, $value_target ): bool {
        $value_origin = explode( ',', str_replace( ', ', ',', $value_origin ) );
        $value_target = explode( ',', str_replace( ', ', ',', $value_target ) );

        return array_diff( $value_origin, $value_target ) === array_diff( $value_target, $value_origin );
    }

    /**
     * Callback for datetime data comparison.
     *
     * @param string $value_origin Origin data.
     * @param string $value_target Target data.
     *
     * @return boolean
     */
    private function compare_date( $value_origin, $value_target ): bool {
        return is_string( $value_target ) && strtotime( $value_origin ) === strtotime( $value_target );
    }

    /**
     * Callback for case insensitive data comparison.
     *
     * @param string $value_origin Origin data.
     * @param string $value_target Target data.
     *
     * @return boolean
     */
    private function compare_case_insensitive( $value_origin, $value_target ): bool {
        return strtoupper( $value_origin ) === strtoupper( $value_target );
    }

    /**
     * Treats the value as URL for comparison.
     *
     * @param mixed $value Value to check and transform if needed.
     *
     * @return mixed
     */
    private function maybe_url( $value ) {
        if (
            filter_var( $value, FILTER_VALIDATE_URL ) &&
            in_array( \wp_parse_url( $value, PHP_URL_HOST ), [ $this->domain, \wp_parse_url( $this->url_current, PHP_URL_HOST ) ] )
        ) {
            if ( ! empty( self::$urls_target_transform ) ) {
                foreach ( self::$urls_target_transform as $pattern => $replacement ) {
                    $value = preg_replace( $pattern, $replacement, $value );
                }
            }

            $value = \wp_make_link_relative( $value );
            $value = preg_replace( '#^(?:/wp)?(?:/wp-content)?(/uploads/.*?)(?:\?auto.+)?$#i', '$1', $value );
            $value = strtok( $value, '?' );
        }

        return $value;
    }

    /**
     * Recursively gets diff between two arrays.
     *
     * @param array $array1 Origin array.
     * @param array $array2 Target array.
     *
     * @return array
     */
    private function array_recursive_diff( $array1, $array2 ) {
        $return = [];
        foreach ( $array1 as $key => $value ) {
            if ( array_key_exists( $key, $array2 ) ) {
                if ( is_array( $value ) ) {
                    $recursive_diff = is_array( $array2[ $key ] ) ? $this->array_recursive_diff( $value, $array2[ $key ] ) : $array2[ $key ];
                    if ( ! empty( $recursive_diff ) ) {
                        $return[ $key ] = $recursive_diff;
                    }
                } else {
                    if (
                        (
                            'articleBody' === $key
                            && preg_replace( '#\s+#is', '', $value ) === preg_replace( '#\s+#is', '', $array2[ $key ] )
                        ) || (
                            (
                                strtotime( $value )
                                || (
                                    is_string( $array2[ $key ] )
                                    && strtotime( $array2[ $key ] )
                                )
                            )
                            && $this->compare_date( $value, $array2[ $key ] )
                        ) || $this->maybe_url( $value ) === $this->maybe_url( $array2[ $key ] )
                    ) {
                        // Format articleBody, dates, and URLs, then skip if the values are equal
                        continue;
                    }

                    $return[ $key ] = $value;
                }
            } else {
                $return[ $key ] = $value;
            }
        }

        return $return;
    }

    /**
     * Gets decoded JSON data.
     *
     * @param string $json JSON data to decode.
     *
     * @return array
     */
    private function get_json( &$json ): array {
        $json_formatted = preg_replace( '#\,(?!\s*?[\{\[\"\'\w])#is', '', $json );
        if ( preg_match( '#^\s+\'\w+?\':#im', $json_formatted ) ) {
            $json_formatted = str_replace( "'", '"', $json_formatted );
        }
        $json_formatted = preg_replace( '#^(\s+)([^\'"]+?):#im', '$1"$2":', $json_formatted );

        $return = json_decode( preg_replace( '#\,(?!\s*?[\{\[\"\'\w])#is', '', $json_formatted ), true );
        if ( is_null( $return ) ) {
            $error = ( 'N/A' === $json || 'EMPTY' === $json ) ? $json : json_last_error_msg();
            $json = $error;
            $return = [ $error ];
        } elseif ( ! empty( $return['@context'] ) && 'https://schema.org' === $return['@context'] && ! empty( $return['@graph'] ) ) {
            array_walk_recursive(
                $return['@graph'],
                function ( &$value ) {
                    $value = $this->maybe_url( $value );
                }
            );

            /**
             * Sets new keys for decoded JSON data.
             *
             * @param array $array    The array to set the new keys for.
             * @param array $item     The array's item to set the new key for.
             * @param string $key_old The old item's key.
             *
             * @return void
             */
            $set_new_keys = function ( &$array, &$item, $key_old ) {
                if ( ! isset( $item['@type'] ) ) {
                    return;
                }
                $key_new = ( is_array( $item['@type'] ) ) ? join( '_', $item['@type'] ) : $item['@type'];
                foreach ( [ 'position', 'name', '@id' ] as $key ) {
                    if ( ! isset( $array[ $key_new ] ) ) {
                        break;
                    }
                    $item[ $key ] ??= '';
                    $key_new .= '#' . $item[ $key ];
                }

                if ( isset( $array[ $key_new ] ) ) {
                    WP_CLI::error( "Duplicate key: $key_new" );
                }

                $array[ $key_new ] = $item;
                unset( $array[ $key_old ] );
            };

            /**
             * Recursively sets new keys for decoded JSON data.
             *
             * @param array $array The array to set the new keys for.
             *
             * @return void
             */
            $array_map_recursive = function ( &$array ) use ( &$array_map_recursive, $set_new_keys ) {
                foreach ( $array as $key => &$value ) {
                    if ( is_array( $value ) ) {
                        if ( in_array( $key, [ '@graph', 'itemListElement' ], true ) ) {
                            $value_new = $value;
                            foreach ( $value_new as $key_old => $item ) {
                                $set_new_keys( $value_new, $item, $key_old );
                            }
                            $value = $value_new;
                        }

                        $array_map_recursive( $value );
                    } elseif ( 'ARTICLEBODY' === strtoupper( $key ) ) {
                        $key_new = 'articleBody';
                        if ( $key_new !== $key ) {
                            $array[ $key_new ] = $value;
                            unset( $array[ $key ] );
                        }
                    }
                }
            };
            $array_map_recursive( $return );
        }

        return $return;
    }

    /**
     * Callback for the 'difference' format output for JSON comparison.
     *
     * @param string $value_origin Origin JSON data.
     * @param string $value_target Target JSON data.
     *
     * @return bool
     */
    private function get_json_difference( &$value_origin, &$value_target ): array {
        $json_origin = $this->get_json( $value_origin );
        $json_target = $this->get_json( $value_target );

        return [
            'origin' => $this->array_recursive_diff( $json_origin, $json_target ),
            'target' => $this->array_recursive_diff( $json_target, $json_origin ),
        ];
    }

    /**
     * Callback for JSON comparison.
     *
     * @param string $value_origin Origin JSON data.
     * @param string $value_target Target JSON data.
     *
     * @return bool
     */
    private function compare_json( &$value_origin, &$value_target ): bool {
        $diff = $this->get_json_difference( $value_origin, $value_target );

        return empty( $diff['origin'] ) && empty( $diff['target'] );
    }

    /**
     * Extracts the schema.org data from the content.
     *
     * @param string $content Content to extract the data from.
     *
     * @return string
     */
    private function extract_schemas_data( string $content ): string {
        $schemas = [];
        preg_match_all( '#<script (?(?!type=).)*?type=(\'|")application/ld\+json\1[^>]*>(?<result>(?(?!</script>).+?))</script>#is', $content, $matches );
        foreach ( $matches['result'] as $key => $schema_json ) {
            $schema = json_decode( $schema_json, true );
            if ( isset( $schema[0] ) && 1 === count( $schema ) ) {
                $schema = $schema[0];
            }
            if ( 'https://schema.org' !== $schema['@context'] ) {
                unset( $matches['result'][ $key ] );
                continue;
            }

            if ( empty( $schemas ) ) {
                $schemas = [
                    '@context' => $schema['@context'],
                    '@graph' => [],
                ];
            }
            unset( $schema['@context'] );

            $graph = [];
            if ( ! empty( $schema['@graph'] ) ) {
                foreach ( $schema['@graph'] as $id => $item ) {
                    if ( is_numeric( $id ) ) {
                        $schemas['@graph'][] = $item;
                    } else {
                        $schemas['@graph'][ $id ] = $item;
                    }
                }
            } else {
                $schemas['@graph'][] = $schema;
            }
        }

        return ( empty( $schemas ) ) ? 'N/A' : json_encode( $schemas, JSON_UNESCAPED_SLASHES );
    }

    /**
     * Callback for schemas comparison.
     *
     * @param string $value_origin Origin schemas data.
     * @param string $value_target Target schemas data.
     *
     * @return bool
     */
    private function compare_schemas( &$value_origin, &$value_target ): bool {
        $value_origin = ( $this->fetch_origin_data ) ? $this->extract_schemas_data( $value_origin ) : $value_origin;
        $value_target = $this->extract_schemas_data( $value_target );

        return $this->compare_json( $value_origin, $value_target );
    }

    /**
     * Callback for the 'difference' format output for schemas comparison.
     *
     * @param string $value_origin Origin schemas data.
     * @param string $value_target Target schemas data.
     *
     * @return bool
     */
    private function compare_schemas_difference( &$value_origin, &$value_target ): bool {
        $value_origin = $this->extract_schemas_data( $value_origin );
        $value_target = $this->extract_schemas_data( $value_target );
        $diff = $this->get_json_difference( $value_origin, $value_target );

        $value_origin = ( empty( $diff['origin'] ) ) ? '' : json_encode( $diff['origin'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        $value_target = ( empty( $diff['target'] ) ) ? '' : json_encode( $diff['target'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

        return empty( $diff['origin'] ) && empty( $diff['target'] );
    }

    /**
     * Callback for GTM data comparison.
     *
     * @param string $value_origin Origin GTM data.
     * @param string $value_target Target GTM data.
     *
     * @return bool
     */
    private function compare_gtm_data( &$value_origin, &$value_target ): bool {
        return $this->compare_json( $value_origin, $value_target );
    }

    /**
     * Callback for the 'difference' format output for GTM data  comparison.
     *
     * @param string $value_origin Origin GTM data.
     * @param string $value_target Target GTM data.
     *
     * @return bool
     */
    private function compare_gtm_data_difference( &$value_origin, &$value_target ): bool {
        $diff = $this->get_json_difference( $value_origin, $value_target );

        $value_origin = ( empty( $diff['origin'] ) ) ? '' : json_encode( $diff['origin'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        $value_target = ( empty( $diff['target'] ) ) ? '' : json_encode( $diff['target'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

        return empty( $diff['origin'] ) && empty( $diff['target'] );
    }

    /**
     * Callback for the 'missing' format output for GTM data comparison.
     *
     * @param string $value_origin Origin GTM data.
     * @param string $value_target Target GTM data.
     *
     * @return bool
     */
    private function compare_gtm_data_missing( &$value_origin, &$value_target ): bool {
        $diff = $this->get_json_difference( $value_origin, $value_target );

        $value_origin = ( empty( $diff['origin'] ) ) ? '' : json_encode( $diff['origin'], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

        return empty( $diff['origin'] );
    }

    /**
     * Callback for a value presence comparison.
     *
     * @param string $value_origin Origin value.
     * @param string $value_target Target value.
     *
     * @return bool
     */
    private function compare_presence( &$value_origin, &$value_target ): bool {
        $presence_origin = ( 'N/A' === $value_origin || 'EMPTY' === $value_origin ) ? 'Missing' : 'Present';
        $presence_target = ( 'N/A' === $value_target || 'EMPTY' === $value_target ) ? 'Missing' : 'Present';

        return $presence_origin === $presence_target;
    }

    /**
     * fputcsv() wrapper.
     *
     * @param string $name      Log name.
     * @param array  $fields    Fields to write.
     * @param string $separator Optional separator.
     * @param string $enclosure Optional enclosure.
     * @param string $escape    Optional escape.
     *
     * @return void
     */
    private function fputcsv( $name, array $fields, string $separator = ',', string $enclosure = '"', string $escape = '\\' ) {
        $this->log[ $name ]['line'] ??= 0;
        ++$this->log[ $name ]['line'];

        fputcsv( $this->log[ $name ]['stream'], $fields, $separator, $enclosure, $escape );
    }

    /**
     * Log data handler.
     *
     * @param string $name       Log name.
     * @param array  $data       Data to log.
     * @param string $url_origin Original URL.
     * @param string $url_target Target URL.
     *
     * @return void
     */
    private function log( string $name, array $data, string $url_origin = '', string $url_target = '' ) {
        if ( empty( $this->log[ $name ]['stream'] ) ) {
            $file_name = 'QA_Crawl-' . strtoupper( Utils::getDomainCode() ) . '_' . date( 'Mj_H.i.s' ) . '-' . $name . '.csv'; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

            $this->log[ $name ]['stream'] = fopen( $file_name, 'w' );

            // Write UTF-8 BOM header to better recognize the report file by Excel
            fwrite( $this->log[ $name ]['stream'], "\xEF\xBB\xBF" );

            if ( 'error' === $name ) {
                $this->fputcsv( $name, [ 'URL', 'Error' ] );
            } else {
                $stored_data = $this->fetch_origin_data ? '' : ' (Stored Data)';
                $this->fputcsv(
                    $name,
                    [
                        'Original URL' . $stored_data,
                        'Migration URL',
                        'Metric',
                        'Original Value',
                        'Migration Value',
                        'Pass or Fail',
                    ]
                );
            }
        }

        if ( 'error' === $name ) {
            $this->fputcsv( $name, $data );
            ++$this->urls_error;
        } else {
            $has_failed_tests = ! empty(
                array_filter(
                    $data,
                    function ( $item ) {
                        return ! $item['passed'];
                    }
                )
            );

            foreach ( $data as $test_name => &$values ) {
                $this->fputcsv(
                    $name,
                    [
                        $url_origin,
                        $url_target,
                        $test_name,
                        $values['origin'],
                        $values['target'],
                        $values['passed'] ? 'Pass' : 'Fail',
                    ],
                    ',',
                    '"',
                    ''
                );
            }

            if ( $has_failed_tests ) {
                ++$this->urls_failed_tests;
            }
        }
    }

    /**
     * Generate URLs to test.
     *
     * @return void
     */
    private function generate_urls() {
        global $wpdb;

        WP_CLI::log( ':: Generating URLs...' );

        $sql = '';
        if ( empty( $this->sections ) || in_array( 'post', $this->sections ) ) {
            $sql .= "(SELECT `ID`, 'get_permalink' `func`, 'post' `section` FROM {$wpdb->posts}
            WHERE (`post_type` = 'post' OR `post_type` = 'page') AND (`post_status`='publish' OR `post_status`='draft' OR `post_status`='trash')
            ORDER BY `ID` DESC)";
        }
        if ( empty( $this->sections ) || count( array_intersect( self::$taxonomy, $this->sections ) ) ) {
            $taxonomy = "'" . implode( "','", empty( $this->sections ) ? self::$taxonomy : array_intersect( self::$taxonomy, $this->sections ) ) . "'";

            if ( ! empty( $sql ) ) {
                $sql .= ' UNION ';
            }
            $sql .= "(SELECT `t`.`term_id` `ID`, 'get_term_link' `func`, `tt`.`taxonomy` `section` FROM {$wpdb->terms} `t`
            LEFT JOIN {$wpdb->term_taxonomy} `tt` on `t`.`term_id` = `tt`.`term_id`
            WHERE `tt`.`taxonomy` IN ($taxonomy) GROUP BY `t`.`term_id` ORDER BY `t`.`term_id` ASC)";
        }

        $taxonomy = "'" . implode( "','", self::$taxonomy ) . "'";
        $sections = '';
        if ( ! empty( $this->sections ) ) {
            $sections = " AND tt.taxonomy IN ('" . implode( "','", $this->sections ) . "')";
        }
        if ( ! empty( $sql ) ) {
            $sql .= ' UNION ';
        }
        $sql .= "(SELECT `t`.`term_id` `ID`, 'get_term_link' `func`, `tt`.`taxonomy` `section` FROM {$wpdb->terms} `t`
        LEFT JOIN {$wpdb->term_taxonomy} `tt` on `t`.`term_id` = `tt`.`term_id`
        WHERE `tt`.`taxonomy` NOT IN ($taxonomy)$sections GROUP BY `tt`.`taxonomy` ORDER BY `t`.`term_id` ASC)";

        $items = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $progress = WP_CLI\Utils\make_progress_bar( '', $wpdb->num_rows );

        foreach ( $items as $item ) {
            $permalink = call_user_func( $item->func, (int) $item->ID );
            if ( ! \is_wp_error( $permalink ) && $permalink ) {
                // Generate production URLs regardless of the current environment
                $permalink = Utils::makeInternalLinkAbsolute( \wp_make_link_relative( $permalink ) );
                $this->urls[ $permalink ] = $item->section;

                // Generate paginated URLs for taxonomies
                if ( in_array( $item->section, self::$taxonomy ) ) {
                    $posts_on_first_page = Utils::getFirstPageCount();
                    $posts_on_other_pages = Utils::getOtherPageCount();
                    $args = [
                        'post_type' => 'post',
                        'posts_per_page' => $posts_on_first_page,
                        'tax_query' => [
                            [
                                'field' => 'term_id',
                                'taxonomy' => $item->section,
                                'terms' => $item->ID,
                            ],
                        ],
                    ];
                    $query = new \WP_Query( $args );

                    $total = max( [ 1, ceil( ( ( $query->found_posts - $posts_on_first_page ) / $posts_on_other_pages ) + 1 ) ] );
                    ++$total; // To also check non existing pages
                    for ( $i = -1; $i <= $total; $i++ ) {
                        $permalink_paginated = user_trailingslashit( rtrim( $permalink, '/' ) . '/page/' . $i );
                        $this->urls[ $permalink_paginated ] = $item->section;
                    }

                    // Generate feed URLs for taxonomies
                    $permalink_feed = user_trailingslashit( rtrim( $permalink, '/' ) . '/feed/' );
                    $this->urls[ $permalink_feed ] = 'feed';
                }
            }
            $progress->tick();
        }
        foreach ( self::$urls_to_test as $permalink => $section ) {
            if ( ! empty( $this->sections ) && ! in_array( (string) $section, $this->sections ) ) {
                continue;
            }

            $permalink = Utils::makeInternalLinkAbsolute( \wp_make_link_relative( $permalink ) );
            $this->urls[ $permalink ] = $section;
        }
        $progress->finish();

        WP_CLI::log( ':: ' . count( $this->urls ) . ' URLs have been generated.' );
    }

    /**
     * Get the origin test data from the previously generated CSV report file.
     *
     * @param string $path The path to the CSV report file.
     *
     * @return void
     */
    private function get_origin_data( $path ) {
        if ( empty( $this->sections ) ) {
            WP_CLI::error( 'The section must be provided when origin-list-url is specified.' );
        } elseif ( 1 < count( $this->sections ) ) {
            WP_CLI::error( 'The only one section must be provided when origin-data-path is specified.' );
        }

        /**
         * Recursive array search.
         *
         * @param array $array         The array to search.
         * @param string $needle_key   The key to search for.
         * @param string $needle_value The value to search for.
         *
         * @return mixed The key of the found value or null.
         */
        $array_search_recursive = function ( $array, $needle_key, $needle_value ) use ( &$array_search_recursive ) {
            foreach ( $array as $key => &$value ) {
                if ( ! is_array( $value ) ) {
                    continue;
                }

                if ( isset( $value[ $needle_key ] ) && $value[ $needle_key ] === $needle_value ) {
                    return $key;
                } else {
                    $found = $array_search_recursive( $value, $needle_key, $needle_value );
                    if ( ! empty( $found ) ) {
                        return $found;
                    }
                }
            }
        };

        $file = fopen( $path, 'r' );
        fgetcsv( $file, null, ',', '"', '' ); // Skip the header
        while ( false !== ( $data = fgetcsv( $file, null, ',', '"', '' ) ) ) {
            $test_id = $array_search_recursive( self::$tests, 'name', $data[2] );
            if ( is_null( $test_id ) ) {
                // Skip the test if it's not found
                continue;
            }
            $url = $data[0];
            $this->urls[ $url ] ??= [
                'origin' => [ 'url' => $url ],
                'section' => $this->sections[0],
            ];
            $this->urls[ $url ]['origin']['test'][ $test_id ] = $data[3];
        }
        $this->fetch_origin_data = false;

        WP_CLI::log( ':: ' . count( $this->urls ) . ' URLs have been loaded from the Origin URLs list.' );
    }

    /**
     * Get the list of origin URLs.
     *
     * @param string $url The URL to get the origin URLs list from.
     *
     * @return void
     */
    private function get_origin_urls_list( $url ) {
        $response = \wp_remote_get( $url );
        if ( \is_wp_error( $response ) ) {
            WP_CLI::error( $response->get_error_message() );
        }
        if ( 200 !== $response['http_response']->get_status() ) {
            WP_CLI::error( 'The Origin URLs list file is not available.' );
        }
        if ( ! empty( $this->sections ) ) {
            if ( 1 < count( $this->sections ) ) {
                WP_CLI::error( 'The only one section must be provided when origin-list-url is specified.' );
            }
            $section_mandatory = false;
        } else {
            $section_mandatory = true;
        }

        $data_arr = explode( PHP_EOL, $response['http_response']->get_data() );
        foreach ( $data_arr as $row ) {
            $url = str_getcsv( $row )[0];
            $section = ( $section_mandatory ) ? str_getcsv( $row )[1] : $this->sections[0];
            if ( empty( $url ) ) { // Skip empty rows
                continue;
            } elseif ( empty( $section ) ) {
                WP_CLI::error( "The section is not provided for the URL: $url" );
            }

            $this->urls[ $url ] = $section;
        }

        WP_CLI::log( ':: ' . count( $this->urls ) . ' URLs have been loaded from the Origin URLs list.' );
    }

    /**
     * Get the sections from the comma-separated string.
     *
     * @param string $sections The comma-separated string.
     *
     * @return array The sections.
     */
    private function get_sections( string $sections ): array {
        return array_map(
            function ( $i ) {
                return trim( $i );
            },
            explode( ',', $sections )
        );
    }
}
