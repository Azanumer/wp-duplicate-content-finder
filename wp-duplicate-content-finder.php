<?php
/**
 * Plugin Name: Duplicate Content Finder
 * Plugin URI: https://github.com/Azanumer/wp-duplicate-content-finder
 * Description: Finds near-duplicate posts and pages using word-shingle similarity, so you can merge or trash copies that split your SEO.
 * Version: 1.0.0
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * License URI: https://opensource.org/licenses/mit-license.php
 * Text Domain: wdcf
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WDCF_VERSION', '1.0.0' );
define( 'WDCF_SETTINGS', 'wdcf_settings' );
define( 'WDCF_RESULTS', 'wdcf_results' );
define( 'WDCF_SCAN_STATE', 'wdcf_scan_state' );
define( 'WDCF_DISMISSED', 'wdcf_dismissed' );
define( 'WDCF_BATCH_SIZE', 50 );

/**
 * Default settings.
 *
 * @return array{threshold:int, min_words:int, post_types:string[]}
 */
function wdcf_default_settings() {
	return array(
		'threshold'  => 70, // Percent Jaccard similarity to count as a duplicate.
		'min_words'  => 50, // Ignore posts shorter than this.
		'post_types' => array( 'post', 'page' ),
	);
}

/**
 * Merged settings.
 *
 * @return array
 */
function wdcf_get_settings() {
	$saved = get_option( WDCF_SETTINGS, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( wdcf_default_settings(), $saved );
}

/**
 * Tokenize post content into a clean word list.
 *
 * Shortcodes, tags and HTML entities are stripped first; words of two
 * characters or fewer carry almost no signal for similarity, so they go.
 *
 * @param string $content Raw post content.
 * @return string[]
 */
function wdcf_tokenize( $content ) {
	$text = strip_shortcodes( $content );
	$text = wp_strip_all_tags( $text );
	$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
	$text = mb_strtolower( $text, 'UTF-8' );
	$words = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	if ( ! is_array( $words ) ) {
		return array();
	}
	$words = array_values( array_filter( $words, function ( $w ) {
		return mb_strlen( $w, 'UTF-8' ) > 2;
	} ) );
	return apply_filters( 'wdcf_tokenized_words', $words, $content );
}

/**
 * Build the set of 5-word shingle hashes for a word list.
 *
 * @param string[] $words Tokenized words.
 * @return string[] Unique 8-char hashes (array keys are the hashes).
 */
function wdcf_shingles( $words ) {
	$size = (int) apply_filters( 'wdcf_shingle_size', 5 );
	$size = max( 2, $size );
	$set  = array();
	$total = count( $words );
	if ( $total < $size ) {
		return $set;
	}
	for ( $i = 0; $i <= $total - $size; $i++ ) {
		$shingle = implode( ' ', array_slice( $words, $i, $size ) );
		$set[ substr( md5( $shingle ), 0, 8 ) ] = true;
	}
	return $set;
}

/**
 * Jaccard similarity between two shingle sets, as a percentage.
 *
 * @param array $a Shingle set A (hashes as keys).
 * @param array $b Shingle set B (hashes as keys).
 * @return float 0-100.
 */
function wdcf_jaccard( $a, $b ) {
	$union = count( $a ) + count( $b ) - count( array_intersect_key( $a, $b ) );
	if ( 0 === $union ) {
		return 0.0;
	}
	return ( count( array_intersect_key( $a, $b ) ) / $union ) * 100;
}

/**
 * Register the Tools page.
 */
function wdcf_register_page() {
	add_management_page(
		__( 'Duplicate Content', 'wdcf' ),
		__( 'Duplicate Content', 'wdcf' ),
		'manage_options',
		'wdcf-duplicates',
		'wdcf_render_page'
	);
}
add_action( 'admin_menu', 'wdcf_register_page' );

/**
 * Save settings (admin-post handler).
 */
function wdcf_save_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'wdcf' ) );
	}
	check_admin_referer( 'wdcf_save_settings' );

	$threshold = isset( $_POST['wdcf_threshold'] ) ? (int) $_POST['wdcf_threshold'] : 70;
	$threshold = max( 50, min( 95, $threshold ) );
	$min_words = isset( $_POST['wdcf_min_words'] ) ? (int) $_POST['wdcf_min_words'] : 50;
	$min_words = max( 10, min( 1000, $min_words ) );

	$post_types = array();
	if ( isset( $_POST['wdcf_post_types'] ) && is_array( $_POST['wdcf_post_types'] ) ) {
		$allowed = get_post_types( array( 'public' => true ), 'names' );
		foreach ( $_POST['wdcf_post_types'] as $pt ) {
			$pt = sanitize_key( $pt );
			if ( isset( $allowed[ $pt ] ) ) {
				$post_types[] = $pt;
			}
		}
	}
	if ( empty( $post_types ) ) {
		$post_types = array( 'post', 'page' );
	}

	update_option( WDCF_SETTINGS, array(
		'threshold'  => $threshold,
		'min_words'  => $min_words,
		'post_types' => $post_types,
	) );
	// Settings change the meaning of old results, so drop them.
	delete_option( WDCF_RESULTS );
	delete_option( WDCF_SCAN_STATE );

	wp_safe_redirect( add_query_arg( array(
		'page'    => 'wdcf-duplicates',
		'wdcfmsg' => 'settings',
	), admin_url( 'tools.php' ) ) );
	exit;
}
add_action( 'admin_post_wdcf_save_settings', 'wdcf_save_settings' );

/**
 * Run one scan batch (admin-post handler, auto-continues via meta refresh).
 */
function wdcf_run_scan_batch() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'wdcf' ) );
	}
	check_admin_referer( 'wdcf_scan' );

	$settings = wdcf_get_settings();
	$offset   = isset( $_GET['offset'] ) ? max( 0, (int) $_GET['offset'] ) : 0;

	$state = get_option( WDCF_SCAN_STATE, null );
	if ( ! is_array( $state ) || 0 === $offset ) {
		$state = array(
			'fingerprints' => array(),
			'processed'    => 0,
			'total'        => 0,
		);
		// Count once at the start so the progress bar is stable.
		$count_query = new WP_Query( array(
			'post_type'      => $settings['post_types'],
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		) );
		$state['total'] = (int) $count_query->found_posts;
	}

	$query = new WP_Query( array(
		'post_type'      => $settings['post_types'],
		'post_status'    => 'publish',
		'posts_per_page' => WDCF_BATCH_SIZE,
		'offset'         => $offset,
		'orderby'        => 'date',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	) );

	foreach ( $query->posts as $post ) {
		$words = wdcf_tokenize( $post->post_content );
		if ( count( $words ) < $settings['min_words'] ) {
			continue;
		}
		$state['fingerprints'][ $post->ID ] = array(
			'id'       => $post->ID,
			'title'    => $post->post_title,
			'type'     => $post->post_type,
			'date'     => $post->post_date,
			'words'    => count( $words ),
			'shingles' => wdcf_shingles( $words ),
		);
	}
	$state['processed'] = $offset + count( $query->posts );
	update_option( WDCF_SCAN_STATE, $state, false );

	$base = admin_url( 'tools.php' );
	if ( count( $query->posts ) >= WDCF_BATCH_SIZE ) {
		// More to go: bounce back to the progress page for the next batch.
		wp_safe_redirect( add_query_arg( array(
			'page'      => 'wdcf-duplicates',
			'wdcf_scan' => 'run',
			'offset'    => $state['processed'],
		), $base ) );
		exit;
	}

	// Scan finished: compare every pair and group the matches.
	$results = wdcf_compare_fingerprints( $state['fingerprints'], $settings['threshold'] );
	update_option( WDCF_RESULTS, $results, false );
	delete_option( WDCF_SCAN_STATE );

	wp_safe_redirect( add_query_arg( array(
		'page'      => 'wdcf-duplicates',
		'wdcf_scan' => 'done',
	), $base ) );
	exit;
}
add_action( 'admin_post_wdcf_scan', 'wdcf_run_scan_batch' );

/**
 * Compare all fingerprints pairwise and group matches.
 *
 * The min/max shingle-count ratio is a hard upper bound on Jaccard
 * similarity, so pairs that cannot possibly reach the threshold are
 * skipped without doing the (more expensive) set intersection.
 *
 * @param array $fingerprints Fingerprints keyed by post ID.
 * @param int   $threshold    Minimum similarity percent.
 * @return array{scanned_at:int, scanned:int, pairs:array, groups:array}
 */
function wdcf_compare_fingerprints( $fingerprints, $threshold ) {
	$ids    = array_keys( $fingerprints );
	$count  = count( $ids );
	$pairs  = array();
	$parent = array();

	$find = function ( $x ) use ( &$parent, &$find ) {
		while ( $parent[ $x ] !== $x ) {
			$parent[ $x ] = $parent[ $parent[ $x ] ];
			$x = $parent[ $x ];
		}
		return $x;
	};
	$union = function ( $a, $b ) use ( &$parent, $find ) {
		$ra = $find( $a );
		$rb = $find( $b );
		if ( $ra !== $rb ) {
			$parent[ $rb ] = $ra;
		}
	};

	foreach ( $ids as $id ) {
		$parent[ $id ] = $id;
	}

	for ( $i = 0; $i < $count; $i++ ) {
		$a = $fingerprints[ $ids[ $i ] ];
		for ( $j = $i + 1; $j < $count; $j++ ) {
			$b = $fingerprints[ $ids[ $j ] ];
			$na = count( $a['shingles'] );
			$nb = count( $b['shingles'] );
			if ( 0 === $na || 0 === $nb ) {
				continue;
			}
			// Upper bound on Jaccard: min/max. Skip hopeless pairs.
			if ( ( min( $na, $nb ) / max( $na, $nb ) ) * 100 < $threshold ) {
				continue;
			}
			$sim = wdcf_jaccard( $a['shingles'], $b['shingles'] );
			if ( $sim >= $threshold ) {
				$pairs[] = array(
					'a'          => $a['id'],
					'b'          => $b['id'],
					'similarity' => round( $sim, 1 ),
				);
				$union( $a['id'], $b['id'] );
			}
		}
	}

	// Group connected pairs; sort each group oldest-first (the keeper).
	$groups = array();
	foreach ( $ids as $id ) {
		$groups[ $find( $id ) ][] = $id;
	}
	$groups = array_values( array_filter( $groups, function ( $g ) {
		return count( $g ) > 1;
	} ) );
	foreach ( $groups as &$group ) {
		usort( $group, function ( $x, $y ) use ( $fingerprints ) {
			return strcmp( $fingerprints[ $x ]['date'], $fingerprints[ $y ]['date'] );
		} );
	}
	unset( $group );

	// Highest-similarity pair first.
	usort( $pairs, function ( $x, $y ) {
		return $y['similarity'] <=> $x['similarity'];
	} );

	return array(
		'scanned_at' => time(),
		'scanned'    => $count,
		'pairs'      => $pairs,
		'groups'     => $groups,
	);
}

/**
 * Trash selected posts (admin-post handler).
 */
function wdcf_trash_selected() {
	if ( ! current_user_can( 'delete_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'wdcf' ) );
	}
	check_admin_referer( 'wdcf_trash' );

	$trashed = 0;
	if ( isset( $_POST['wdcf_ids'] ) && is_array( $_POST['wdcf_ids'] ) ) {
		foreach ( $_POST['wdcf_ids'] as $id ) {
			$id = (int) $id;
			if ( $id > 0 && wp_trash_post( $id ) ) {
				$trashed++;
			}
		}
	}

	wp_safe_redirect( add_query_arg( array(
		'page'      => 'wdcf-duplicates',
		'wdcfmsg'   => 'trashed',
		'wdcfcount' => $trashed,
	), admin_url( 'tools.php' ) ) );
	exit;
}
add_action( 'admin_post_wdcf_trash', 'wdcf_trash_selected' );

/**
 * Dismiss a group (admin-post handler).
 */
function wdcf_dismiss_group() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'wdcf' ) );
	}
	check_admin_referer( 'wdcf_dismiss' );

	$group = isset( $_GET['group'] ) ? sanitize_text_field( wp_unslash( $_GET['group'] ) ) : '';
	if ( '' !== $group ) {
		$dismissed = get_option( WDCF_DISMISSED, array() );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}
		$dismissed[] = $group;
		update_option( WDCF_DISMISSED, array_unique( $dismissed ), false );
	}

	wp_safe_redirect( add_query_arg( array(
		'page'    => 'wdcf-duplicates',
		'wdcfmsg' => 'dismissed',
	), admin_url( 'tools.php' ) ) );
	exit;
}
add_action( 'admin_post_wdcf_dismiss', 'wdcf_dismiss_group' );

/**
 * Emit the auto-refresh tag for the in-progress scan page.
 *
 * A meta refresh in <head> keeps the scan advancing through its batches
 * without JavaScript. The target is the admin-post handler, which processes
 * one batch and bounces back here with the next offset.
 */
function wdcf_scan_refresh_meta() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || 'tools_page_wdcf-duplicates' !== $screen->id ) {
		return;
	}
	if ( ! isset( $_GET['wdcf_scan'] ) || 'run' !== sanitize_key( $_GET['wdcf_scan'] ) ) {
		return;
	}
	$offset  = isset( $_GET['offset'] ) ? max( 0, (int) $_GET['offset'] ) : 0;
	$next_url = add_query_arg( array(
		'action'   => 'wdcf_scan',
		'offset'   => $offset,
		'_wpnonce' => wp_create_nonce( 'wdcf_scan' ),
	), admin_url( 'admin-post.php' ) );
	echo '<meta http-equiv="refresh" content="0; url=' . esc_url( $next_url ) . '">' . "\n";
}
add_action( 'admin_head', 'wdcf_scan_refresh_meta' );

/**
 * Render the Tools page.
 */
function wdcf_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'wdcf' ) );
	}

	$settings  = wdcf_get_settings();
	$results   = get_option( WDCF_RESULTS, null );
	$dismissed = get_option( WDCF_DISMISSED, array() );
	if ( ! is_array( $dismissed ) ) {
		$dismissed = array();
	}
	$scan_state = isset( $_GET['wdcf_scan'] ) ? sanitize_key( $_GET['wdcf_scan'] ) : '';
	$offset     = isset( $_GET['offset'] ) ? max( 0, (int) $_GET['offset'] ) : 0;

	// Scan in progress: kick off (or continue) the next batch.
	if ( 'run' === $scan_state ) {
		$state     = get_option( WDCF_SCAN_STATE, array() );
		$processed = isset( $state['processed'] ) ? (int) $state['processed'] : $offset;
		$total     = isset( $state['total'] ) ? (int) $state['total'] : 0;
		$pct       = $total > 0 ? min( 100, round( ( $processed / $total ) * 100 ) ) : 0;
		echo '<div class="wrap"><h1>' . esc_html__( 'Duplicate Content', 'wdcf' ) . '</h1>';
		echo '<p>' . esc_html__( 'Scanning your content… this page refreshes automatically until the scan finishes.', 'wdcf' ) . '</p>';
		echo '<p>' . esc_html( sprintf(
			/* translators: %1$d posts processed, %2$d total, %3$d percent */
			__( '%1$d of %2$d posts scanned (%3$d%%)', 'wdcf' ),
			$processed, $total, $pct
		) ) . '</p>';
		echo '</div>';
		return;
	}

	$msg = isset( $_GET['wdcfmsg'] ) ? sanitize_key( $_GET['wdcfmsg'] ) : '';
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Duplicate Content', 'wdcf' ); ?></h1>

		<?php if ( 'settings' === $msg ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved. Previous scan results were cleared — run a new scan.', 'wdcf' ); ?></p></div>
		<?php elseif ( 'trashed' === $msg ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf(
				/* translators: %d number of posts trashed */
				_n( '%d post moved to Trash.', '%d posts moved to Trash.', (int) ( $_GET['wdcfcount'] ?? 0 ), 'wdcf' ),
				(int) ( $_GET['wdcfcount'] ?? 0 )
			) ); ?></p></div>
		<?php elseif ( 'dismissed' === $msg ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Group dismissed. It will reappear on the next scan.', 'wdcf' ); ?></p></div>
		<?php elseif ( 'done' === $scan_state ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Scan complete.', 'wdcf' ); ?></p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Settings', 'wdcf' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wdcf_save_settings' ); ?>
			<input type="hidden" name="action" value="wdcf_save_settings">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wdcf_threshold"><?php esc_html_e( 'Similarity threshold', 'wdcf' ); ?></label></th>
					<td>
						<input type="number" id="wdcf_threshold" name="wdcf_threshold" min="50" max="95" step="5" value="<?php echo esc_attr( $settings['threshold'] ); ?>"> %
						<p class="description"><?php esc_html_e( 'Posts at or above this similarity are reported as duplicates. 70% is a good starting point.', 'wdcf' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wdcf_min_words"><?php esc_html_e( 'Minimum words', 'wdcf' ); ?></label></th>
					<td>
						<input type="number" id="wdcf_min_words" name="wdcf_min_words" min="10" max="1000" step="10" value="<?php echo esc_attr( $settings['min_words'] ); ?>">
						<p class="description"><?php esc_html_e( 'Shorter posts are skipped — they match each other too easily.', 'wdcf' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Post types', 'wdcf' ); ?></th>
					<td>
						<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) : ?>
							<label style="display:block">
								<input type="checkbox" name="wdcf_post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, $settings['post_types'], true ) ); ?>>
								<?php echo esc_html( $pt->labels->singular_name ); ?> <code><?php echo esc_html( $pt->name ); ?></code>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'wdcf' ) ); ?>
		</form>

		<hr>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'wdcf_scan', 'offset' => 0 ), admin_url( 'admin-post.php' ) ), 'wdcf_scan' ) ); ?>">
				<?php esc_html_e( 'Scan for duplicates', 'wdcf' ); ?>
			</a>
		</p>

		<?php
		if ( ! is_array( $results ) || empty( $results['groups'] ) ) {
			if ( is_array( $results ) ) {
				echo '<p>' . esc_html( sprintf(
					/* translators: %d posts scanned */
					__( 'No duplicates found across %d scanned posts.', 'wdcf' ),
					(int) $results['scanned']
				) ) . '</p>';
			} else {
				echo '<p>' . esc_html__( 'No scan has run yet. Press "Scan for duplicates" above.', 'wdcf' ) . '</p>';
			}
			echo '</div>';
			return;
		}

		// Similarity lookup for display: pair key "smallerId:largerId".
		$sim_lookup = array();
		foreach ( $results['pairs'] as $pair ) {
			$key = min( $pair['a'], $pair['b'] ) . ':' . max( $pair['a'], $pair['b'] );
			$sim_lookup[ $key ] = $pair['similarity'];
		}

		$visible_groups = 0;
		foreach ( $results['groups'] as $group ) {
			$signature = md5( implode( ',', $group ) );
			if ( in_array( $signature, $dismissed, true ) ) {
				continue;
			}
			$visible_groups++;
		}
		echo '<p>' . esc_html( sprintf(
			/* translators: %1$d duplicate groups, %2$d posts scanned */
			_n( '%1$d duplicate group found across %2$d scanned posts.', '%1$d duplicate groups found across %2$d scanned posts.', $visible_groups, 'wdcf' ),
			$visible_groups, (int) $results['scanned']
		) ) . '</p>';
		echo '<p>' . esc_html__( 'Each group is listed oldest-first. Keep the first post and trash the rest, or dismiss the group if the overlap is intentional.', 'wdcf' ) . '</p>';

		foreach ( $results['groups'] as $group ) {
			$signature = md5( implode( ',', $group ) );
			if ( in_array( $signature, $dismissed, true ) ) {
				continue;
			}
			$dismiss_url = wp_nonce_url( add_query_arg( array(
				'action' => 'wdcf_dismiss',
				'group'  => $signature,
			), admin_url( 'admin-post.php' ) ), 'wdcf_dismiss' );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wdcf_trash' ); ?>
				<input type="hidden" name="action" value="wdcf_trash">
				<h3>
					<?php esc_html_e( 'Duplicate group', 'wdcf' ); ?>
					<a class="button button-small" style="margin-left:8px" href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss group', 'wdcf' ); ?></a>
				</h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th></th>
							<th><?php esc_html_e( 'Post', 'wdcf' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wdcf' ); ?></th>
							<th><?php esc_html_e( 'Date', 'wdcf' ); ?></th>
							<th><?php esc_html_e( 'Words', 'wdcf' ); ?></th>
							<th><?php esc_html_e( 'Similarity to keeper', 'wdcf' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$keeper = $group[0];
						foreach ( $group as $index => $post_id ) :
							$post = get_post( $post_id );
							if ( ! $post || 'trash' === $post->post_status ) {
								continue;
							}
							$key = min( $keeper, $post_id ) . ':' . max( $keeper, $post_id );
							$sim = ( 0 === $index ) ? '—' : ( isset( $sim_lookup[ $key ] ) ? $sim_lookup[ $key ] . '%' : '—' );
							?>
							<tr>
								<td>
									<?php if ( 0 !== $index ) : ?>
										<input type="checkbox" name="wdcf_ids[]" value="<?php echo esc_attr( $post_id ); ?>" checked>
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $post_id ) ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
									<?php if ( 0 === $index ) : ?>
										<strong> — <?php esc_html_e( 'keeper (oldest)', 'wdcf' ); ?></strong>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $post->post_type ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $post->post_date ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( str_word_count( wp_strip_all_tags( $post->post_content ) ) ) ); ?></td>
								<td><?php echo esc_html( $sim ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><?php submit_button( __( 'Move checked to Trash', 'wdcf' ), 'secondary', 'submit', false ); ?></p>
			</form>
			<?php
		}
		?>
	</div>
	<?php
}
