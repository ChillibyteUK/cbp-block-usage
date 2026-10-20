<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: CBP Block Usage
 * Description: Adds a [block_usage_table] shortcode that lists every theme block (ACF or JSX/block.json) and the pages, posts, and CPTs that use it, with a CSV export.
 * Version: 1.0.0
 * Author: Chillibyte - DS
 *
 * @package CBP_Block_Usage
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class CBP_Block_Usage_Plugin
 *
 * Discovers theme blocks (both ACF PHP blocks and JSX/block.json blocks),
 * scans published content for usage, renders a [block_usage_table]
 * shortcode, and provides a CSV export of the same data.
 *
 * @package CBP_Block_Usage
 */
class CBP_Block_Usage_Plugin {

	private const EXPORT_ACTION = 'cbp_block_usage_export';
	private const NONCE_ACTION  = 'cbp_block_usage_export';
	private const TRANSIENT_KEY = 'cbp_block_usage_map';

	/**
	 * Constructor. Registers the shortcode, export handler, and cache busting.
	 */
	public function __construct() {
		add_shortcode( 'block_usage_table', array( $this, 'render_shortcode' ) );

		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );
		add_action( 'admin_post_nopriv_' . self::EXPORT_ACTION, array( $this, 'handle_export' ) );

		add_action( 'save_post', array( $this, 'clear_cache' ) );
		add_action( 'deleted_post', array( $this, 'clear_cache' ) );
		add_action( 'switch_theme', array( $this, 'clear_cache' ) );
	}

	/**
	 * Clears the cached block usage map.
	 */
	public function clear_cache() {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Returns the theme directories to scan for a /blocks/ folder.
	 *
	 * Checks the active (child) theme first, then falls back to the
	 * parent theme if the site is using a child theme.
	 *
	 * @return string[] Absolute paths to each blocks/ directory, trailing slash included.
	 */
	private function get_blocks_dirs(): array {
		$dirs = array( trailingslashit( get_stylesheet_directory() ) . 'blocks/' );

		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$dirs[] = trailingslashit( get_template_directory() ) . 'blocks/';
		}

		return array_values( array_unique( array_filter( $dirs, 'is_dir' ) ) );
	}

	/**
	 * Discovers every registered block in the theme(s), supporting both
	 * JSX blocks (blocks/{slug}/block.json) and ACF blocks
	 * (blocks/{slug}.php, registered as acf/{slug}).
	 *
	 * @return array<string,array{name:string,slug:string,type:string}> Keyed by full block name (namespace/slug).
	 */
	private function discover_blocks(): array {
		$blocks = array();

		foreach ( $this->get_blocks_dirs() as $dir ) {
			// JSX blocks: blocks/{slug}/block.json, registered name comes straight from the file.
			foreach ( (array) glob( $dir . '*/block.json' ) as $file ) {
				$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents

				if ( empty( $data['name'] ) ) {
					continue;
				}

				$name = strtolower( $data['name'] );

				$blocks[ $name ] = array(
					'name' => $name,
					'slug' => basename( dirname( $file ) ),
					'type' => 'JSX',
				);
			}

			// ACF blocks: blocks/{slug}.php, registered as acf/{slug}.
			foreach ( (array) glob( $dir . '*.php' ) as $file ) {
				$slug = basename( $file, '.php' );
				$name = 'acf/' . strtolower( $slug );

				if ( isset( $blocks[ $name ] ) ) {
					continue;
				}

				$blocks[ $name ] = array(
					'name' => $name,
					'slug' => $slug,
					'type' => 'ACF',
				);
			}
		}

		ksort( $blocks );

		return $blocks;
	}

	/**
	 * Returns the public post types to scan for block usage, excluding attachments.
	 *
	 * @return string[] Post type names.
	 */
	private function get_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );

		return array_values( $types );
	}

	/**
	 * Builds (or returns the cached) map of block usage across the site.
	 *
	 * @return array{
	 *     blocks: array<string,array{name:string,slug:string,type:string}>,
	 *     usage: array<string,array{page: WP_Post[], post: WP_Post[], cpt: WP_Post[]}>
	 * }
	 */
	private function get_usage_map(): array {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$blocks = $this->discover_blocks();

		$usage = array_fill_keys(
			array_keys( $blocks ),
			array(
				'page' => array(),
				'post' => array(),
				'cpt'  => array(),
			)
		);

		if ( ! empty( $blocks ) ) {
			$posts = get_posts(
				array(
					'post_type'              => $this->get_post_types(),
					'posts_per_page'         => -1,
					'post_status'            => 'publish',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			foreach ( $posts as $post ) {
				preg_match_all( '/<!--\s*wp:([a-z0-9]+(?:[-_][a-z0-9]+)*\/[a-z0-9]+(?:[-_][a-z0-9]+)*)/i', $post->post_content, $matches );

				if ( empty( $matches[1] ) ) {
					continue;
				}

				$bucket = in_array( $post->post_type, array( 'page', 'post' ), true ) ? $post->post_type : 'cpt';

				foreach ( array_unique( array_map( 'strtolower', $matches[1] ) ) as $found_block ) {
					if ( isset( $usage[ $found_block ] ) ) {
						$usage[ $found_block ][ $bucket ][] = $post;
					}
				}
			}
		}

		$map = array(
			'blocks' => $blocks,
			'usage'  => $usage,
		);

		set_transient( self::TRANSIENT_KEY, $map, HOUR_IN_SECONDS );

		return $map;
	}

	/**
	 * Returns up to $limit example permalinks for a block's usage.
	 *
	 * @param array{page: WP_Post[], post: WP_Post[], cpt: WP_Post[]} $usage Usage buckets for one block.
	 * @param int                                                     $limit Maximum number of examples to return.
	 * @return string[] Permalinks.
	 */
	private function get_example_urls( array $usage, int $limit = 2 ): array {
		$all = array_merge( $usage['page'], $usage['post'], $usage['cpt'] );
		$urls = array();

		foreach ( $all as $post ) {
			$permalink = get_permalink( $post );

			if ( $permalink ) {
				$urls[] = $permalink;
			}

			if ( count( $urls ) >= $limit ) {
				break;
			}
		}

		return $urls;
	}

	/**
	 * Renders the [block_usage_table] shortcode.
	 *
	 * @return string HTML table of block usage, or a message if no blocks are found.
	 */
	public function render_shortcode(): string {
		$map = $this->get_usage_map();

		if ( empty( $map['blocks'] ) ) {
			return '<p>No blocks found.</p>';
		}

		$export_url = wp_nonce_url(
			add_query_arg( 'action', self::EXPORT_ACTION, admin_url( 'admin-post.php' ) ),
			self::NONCE_ACTION
		);

		ob_start();
		?>
		<div style="padding: 2rem;">
			<p>
				<a href="<?php echo esc_url( $export_url ); ?>" style="display: inline-block; padding: 8px 16px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 4px;">
					Download CSV
				</a>
			</p>
			<table style="width: 100%; border-collapse: collapse;">
				<thead>
					<tr style="border-bottom: 2px solid #ccc;">
						<th style="text-align: left; padding: 8px; font-weight: bold;">Block Name</th>
						<th style="text-align: left; padding: 8px; font-weight: bold;">Type</th>
						<th style="text-align: center; padding: 8px; font-weight: bold;">Pages</th>
						<th style="text-align: center; padding: 8px; font-weight: bold;">Posts</th>
						<th style="text-align: center; padding: 8px; font-weight: bold;">CPTs</th>
						<th style="text-align: left; padding: 8px; font-weight: bold;">Example URL(s)</th>
						<th style="text-align: left; padding: 8px; font-weight: bold;">Used In</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $map['blocks'] as $name => $block ) : ?>
						<?php
						$usage        = $map['usage'][ $name ];
						$all_posts    = array_merge( $usage['page'], $usage['post'], $usage['cpt'] );
						$example_urls = $this->get_example_urls( $usage );
						?>
						<tr style="border-bottom: 1px solid #eee;">
							<td style="padding: 8px; vertical-align: top;"><?php echo esc_html( $block['slug'] ); ?></td>
							<td style="padding: 8px; vertical-align: top;"><?php echo esc_html( $block['type'] ); ?></td>
							<td style="padding: 8px; text-align: center; vertical-align: top;"><?php echo esc_html( (string) count( $usage['page'] ) ); ?></td>
							<td style="padding: 8px; text-align: center; vertical-align: top;"><?php echo esc_html( (string) count( $usage['post'] ) ); ?></td>
							<td style="padding: 8px; text-align: center; vertical-align: top;"><?php echo esc_html( (string) count( $usage['cpt'] ) ); ?></td>
							<td style="padding: 8px; vertical-align: top;">
								<?php if ( empty( $example_urls ) ) : ?>
									<em style="color: #999;">Not used</em>
								<?php else : ?>
									<?php foreach ( $example_urls as $url ) : ?>
										<div><a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $url ); ?></a></div>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
							<td style="padding: 8px; vertical-align: top;">
								<?php if ( empty( $all_posts ) ) : ?>
									<em style="color: #999;">Not used</em>
								<?php else : ?>
									<ul style="margin: 0; padding-left: 20px;">
										<?php foreach ( $all_posts as $post ) : ?>
											<li>
												<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>" target="_blank">
													<?php echo esc_html( $post->post_title ); ?>
												</a>
												<span style="color: #999; font-size: 0.9em;">(<?php echo esc_html( ucfirst( $post->post_type ) ); ?>)</span>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Streams the block usage map as a CSV download.
	 */
	public function handle_export() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Invalid request.', 'cbp-block-usage' ) );
		}

		$map = $this->get_usage_map();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=block-usage-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		fputcsv( $output, array( 'Block Name', 'Type', 'Pages', 'Posts', 'CPTs', 'Total', 'Example URL 1', 'Example URL 2' ) );

		foreach ( $map['blocks'] as $name => $block ) {
			$usage   = $map['usage'][ $name ];
			$pages   = count( $usage['page'] );
			$posts   = count( $usage['post'] );
			$cpts    = count( $usage['cpt'] );
			$total   = $pages + $posts + $cpts;
			$example = $this->get_example_urls( $usage );

			fputcsv(
				$output,
				array(
					$block['slug'],
					$block['type'],
					$pages,
					$posts,
					$cpts,
					$total,
					$example[0] ?? '',
					$example[1] ?? '',
				)
			);
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}

new CBP_Block_Usage_Plugin();
