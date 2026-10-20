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
	 * Directory names to skip while recursively scanning a theme.
	 *
	 * @var string[]
	 */
	private const SKIP_DIRS = array( 'node_modules', 'vendor', '.git', 'dist', 'build' );

	/**
	 * Returns the theme root directories to scan.
	 *
	 * Checks the active (child) theme first, then also includes the
	 * parent theme if the site is using a child theme.
	 *
	 * @return string[] Absolute theme root paths, trailing slash included.
	 */
	private function get_theme_dirs(): array {
		$dirs = array( trailingslashit( get_stylesheet_directory() ) );

		if ( get_template_directory() !== get_stylesheet_directory() ) {
			$dirs[] = trailingslashit( get_template_directory() );
		}

		return array_values( array_unique( array_filter( $dirs, 'is_dir' ) ) );
	}

	/**
	 * Converts a raw ACF block "name" value into the slug WordPress/ACF
	 * actually registers (and the slug that appears in post content as
	 * `wp:acf/{slug}`). ACF runs block names through a dash-based slugify,
	 * so `cb_hero` and `CB Latest Posts Author` both become hyphenated,
	 * lowercase slugs regardless of how they were written in the source.
	 *
	 * @param string $value Raw name value from the registration call.
	 * @return string Slugified block name.
	 */
	private function slugify( string $value ): string {
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9]+/', '-', $value );
		return trim( (string) $value, '-' );
	}

	/**
	 * Recursively iterates PHP/JSON files under a directory, skipping
	 * heavy/irrelevant folders such as node_modules and vendor.
	 *
	 * @param string $dir      Directory to scan.
	 * @param string $extension File extension to match, without the dot.
	 * @return string[] Absolute file paths.
	 */
	private function scan_files( string $dir, string $extension ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$found    = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				static function ( $file, $key, $iterator ) {
					if ( $iterator->hasChildren() ) {
						return ! in_array( $file->getFilename(), self::SKIP_DIRS, true );
					}
					return true;
				}
			)
		);

		foreach ( $iterator as $file ) {
			if ( strtolower( $file->getExtension() ) === $extension ) {
				$found[] = $file->getPathname();
			}
		}

		return $found;
	}

	/**
	 * Discovers JSX blocks by locating every block.json in the theme(s)
	 * and reading its registered "name" field directly.
	 *
	 * @param string $theme_dir Theme root directory.
	 * @return array<string,array{name:string,slug:string,type:string}>
	 */
	private function discover_jsx_blocks( string $theme_dir ): array {
		$blocks = array();

		foreach ( $this->scan_files( $theme_dir, 'json' ) as $file ) {
			if ( basename( $file ) !== 'block.json' ) {
				continue;
			}

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

		return $blocks;
	}

	/**
	 * Discovers ACF blocks by scanning every PHP file in the theme(s) for
	 * `acf_register_block()` / `acf_register_block_type()` calls and
	 * extracting the "name" argument actually passed to ACF, rather than
	 * guessing from the block's filename (the two frequently differ).
	 *
	 * @param string $theme_dir Theme root directory.
	 * @return array<string,array{name:string,slug:string,type:string}>
	 */
	private function discover_acf_blocks( string $theme_dir ): array {
		$blocks = array();

		foreach ( $this->scan_files( $theme_dir, 'php' ) as $file ) {
			$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents

			if ( ! preg_match( '/acf_register_block(?:_type)?\s*\(/', $content ) ) {
				continue;
			}

			// Split the file on each registration call so we only look for
			// the "name" belonging to that specific call.
			$calls = preg_split( '/acf_register_block(?:_type)?\s*\(/', $content );

			foreach ( $calls as $call ) {
				if ( ! preg_match( '/[\'"]name[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $call, $match ) ) {
					continue;
				}

				$slug = $this->slugify( $match[1] );

				if ( '' === $slug ) {
					continue;
				}

				$name = 'acf/' . $slug;

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

		return $blocks;
	}

	/**
	 * Discovers every registered block across the active theme (and parent
	 * theme, if applicable), supporting both JSX blocks (block.json) and
	 * ACF blocks (acf_register_block / acf_register_block_type calls),
	 * wherever in the theme they live.
	 *
	 * @return array<string,array{name:string,slug:string,type:string}> Keyed by full block name (namespace/slug).
	 */
	private function discover_blocks(): array {
		$blocks = array();

		foreach ( $this->get_theme_dirs() as $theme_dir ) {
			$blocks += $this->discover_jsx_blocks( $theme_dir );
			$blocks += $this->discover_acf_blocks( $theme_dir );
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
		// Allow ?cbp_refresh=1 to bypass the cache, e.g. while testing block
		// discovery changes without waiting for the transient to expire.
		$force_refresh = isset( $_GET['cbp_refresh'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );

			if ( is_array( $cached ) ) {
				return $cached;
			}
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

		// Don't cache an empty/failed discovery — that would otherwise lock
		// in a "No blocks found" result for an hour even after the theme or
		// plugin is fixed.
		if ( ! empty( $blocks ) ) {
			set_transient( self::TRANSIENT_KEY, $map, HOUR_IN_SECONDS );
		}

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
	 * Renders a diagnostic message when no blocks are discovered, listing
	 * the theme directories that were scanned and whether PHP/block.json
	 * files were found in them. Only visible to logged-in users who can
	 * edit posts; everyone else just sees a plain "no blocks" message.
	 *
	 * @return string HTML.
	 */
	private function render_debug_info(): string {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '<p>No blocks found.</p>';
		}

		$rows = array();

		foreach ( $this->get_theme_dirs() as $theme_dir ) {
			$php_count   = count( $this->scan_files( $theme_dir, 'php' ) );
			$block_jsons = count(
				array_filter(
					$this->scan_files( $theme_dir, 'json' ),
					static function ( $file ) {
						return basename( $file ) === 'block.json';
					}
				)
			);

			$rows[] = array(
				'dir'         => $theme_dir,
				'php_count'   => $php_count,
				'block_jsons' => $block_jsons,
			);
		}

		ob_start();
		?>
		<div style="padding: 1.5rem; border: 1px solid #ccc; background: #fafafa;">
			<p><strong>No blocks found.</strong> (This message is only visible to logged-in users who can edit posts.)</p>
			<p>Theme directories scanned:</p>
			<ul>
				<?php foreach ( $rows as $row ) : ?>
					<li>
						<code><?php echo esc_html( $row['dir'] ); ?></code> —
						<?php echo esc_html( (string) $row['php_count'] ); ?> PHP file(s),
						<?php echo esc_html( (string) $row['block_jsons'] ); ?> block.json file(s)
					</li>
				<?php endforeach; ?>
			</ul>
			<p>
				If PHP files were found but no blocks, check that blocks are registered via
				<code>acf_register_block()</code>, <code>acf_register_block_type()</code>, or a <code>block.json</code>
				<code>name</code> field. Add <code>?cbp_refresh=1</code> to this page's URL to bypass the cached result
				after making changes.
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders the [block_usage_table] shortcode.
	 *
	 * @return string HTML table of block usage, or a message if no blocks are found.
	 */
	public function render_shortcode(): string {
		$map = $this->get_usage_map();

		if ( empty( $map['blocks'] ) ) {
			return $this->render_debug_info();
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
