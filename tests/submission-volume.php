<?php
/**
 * Volume and boundary behaviour: lists, filters and XLSX exports stay correct at
 * realistic sizes, and date filters use the site's local calendar day.
 * Disposable WordPress only; tune with ADF_VOLUME_ROWS (50-5000).
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { exit( 1 ); }
function adf_volume_expect( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$rows     = (int) ( getenv( 'ADF_VOLUME_ROWS' ) ?: 300 );
$rows     = max( 50, min( 5000, $rows ) );
$original = get_current_user_id();
$admin    = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
wp_set_current_user( $admin->ID );

$form     = adf_register_form( 'volume-' . wp_generate_uuid4(), 'Volume test form' );
$marker   = 'volume-' . wp_generate_uuid4();
$ids      = array();
$export   = '';
try {
	adf_volume_expect( adf_can_export_submissions(), 'The volume test needs the administrator export capability.' );
	$started = microtime( true );
	for ( $i = 1; $i <= $rows; $i++ ) {
		$message = ( $i <= 10 ) ? 'Request ' . $marker . ' number ' . $i : 'Routine request number ' . $i;
		$id      = adf_store_submission(
			$form,
			array(
				'name'    => 'Person ' . $i,
				'email'   => 'volume' . $i . '@example.org',
				'message' => $message,
			),
			array(
				'name'    => 'Name',
				'email'   => 'E-Mail',
				'message' => 'Message',
			)
		);
		adf_volume_expect( is_int( $id ) && $id > 0, 'Seeding submission ' . $i . ' failed.' );
		$ids[] = $id;
		if ( 0 === $i % 5 ) {
			adf_apply_submission_action( $form, array( $id ), 'mark_read' );
		}
	}
	$seed_seconds = microtime( true ) - $started;

	$count = static function ( array $filters ) use ( $form ) {
		$args                  = adf_submission_query_args( $form, $filters );
		$args['posts_per_page'] = 20;
		$args['fields']         = 'ids';
		$query                 = new WP_Query( $args );
		return (int) $query->found_posts;
	};

	adf_volume_expect( $rows === $count( array() ), 'The unfiltered list must count every stored answer, expected ' . $rows . '.' );
	adf_volume_expect( 10 === $count( array( 'adf_search' => $marker ) ), 'A search must find exactly the matching answers, not more.' );
	adf_volume_expect( (int) floor( $rows / 5 ) === $count( array( 'adf_status' => 'read' ) ), 'The read filter must count exactly the marked answers.' );
	adf_volume_expect( $rows - (int) floor( $rows / 5 ) === $count( array( 'adf_status' => 'unread' ) ), 'The unread filter must count the remaining answers.' );

	$today = current_time( 'Y-m-d' );
	adf_volume_expect( $rows === $count( array( 'adf_from' => $today, 'adf_to' => $today ) ), 'A single-day range covering today must include every answer created today.' );

	// A local-midnight answer must belong to today, not to the UTC day.
	$boundary = adf_store_submission( $form, array( 'name' => 'Boundary', 'email' => 'boundary@example.org', 'message' => 'Just after local midnight' ) );
	$ids[]    = $boundary;
	wp_update_post(
		array(
			'ID'            => $boundary,
			'post_date'     => $today . ' 00:30:00',
			'post_date_gmt' => get_gmt_from_date( $today . ' 00:30:00' ),
		)
	);
	clean_post_cache( $boundary );
	adf_volume_expect( $rows + 1 === $count( array( 'adf_from' => $today, 'adf_to' => $today ) ), 'An answer at 00:30 local time must count as today.' );
	$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );
	adf_volume_expect( 0 === $count( array( 'adf_from' => $yesterday, 'adf_to' => $yesterday ) ), 'Yesterday must not contain today\'s answers.' );

	$queries_before = $GLOBALS['wpdb']->num_queries;
	$count( array( 'adf_search' => $marker ) );
	$list_queries = $GLOBALS['wpdb']->num_queries - $queries_before;
	adf_volume_expect( $list_queries <= 40, 'A filtered list page must not fan out into per-row queries, saw ' . $list_queries . '.' );

	$export_started = microtime( true );
	$export         = adf_create_submission_export( $form );
	$export_seconds = microtime( true ) - $export_started;
	adf_volume_expect( is_string( $export ) && is_file( $export ), 'A full export of ' . $rows . ' answers must succeed.' );
	$zip = new ZipArchive();
	adf_volume_expect( true === $zip->open( $export ), 'The export must be a readable XLSX archive.' );
	$sheet = (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' );
	$zip->close();
	$data_rows = substr_count( $sheet, '<row ' ) - 1;
	adf_volume_expect( $rows + 1 === $data_rows, 'The export must contain one row per answer plus the header, saw ' . $data_rows . '.' );

	WP_CLI::success(
		sprintf(
			'Volume suite: %d answers seeded in %.1fs; filters exact; export of %d rows in %.1fs; filtered list page used %d queries.',
			$rows,
			$seed_seconds,
			$data_rows,
			$export_seconds,
			$list_queries
		)
	);
} finally {
	if ( '' !== $export && is_string( $export ) && is_file( $export ) ) { unlink( $export ); }
	foreach ( $ids as $id ) { if ( get_post( $id ) ) { wp_delete_post( $id, true ); } }
	if ( get_post( $form ) ) { wp_delete_post( $form, true ); }
	wp_set_current_user( $original );
}
