<?php
/**
 * Fotonic Meta Boxes
 *
 * Registers and handles all native WP meta boxes for ftnc_customer,
 * ftnc_service, and ftnc_work CPTs.
 *
 * @package Fotonic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fotonic_Meta_Boxes {

	// ---------------------------------------------------------------------------
	// Registration + asset enqueue
	// ---------------------------------------------------------------------------

	/**
	 * Enqueue meta-box CSS and JS on the relevant CPT edit screens.
	 *
	 * Hooked on admin_enqueue_scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_meta_assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$cpts = [ 'ftnc_customer', 'ftnc_service', 'ftnc_work' ];
		if ( ! in_array( $screen->post_type, $cpts, true ) ) {
			return;
		}

		$base_url = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/';
		$ver      = defined( 'FOTONIC_VERSION' ) ? FOTONIC_VERSION : '1.0.0';

		// Shared CSS.
		wp_enqueue_style(
			'fotonic-meta-boxes',
			$base_url . 'meta-boxes.css',
			[],
			$ver
		);

		// Customer people repeater JS.
		if ( 'ftnc_customer' === $screen->post_type ) {
			wp_enqueue_script(
				'fotonic-meta-customer',
				$base_url . 'meta-customer.js',
				[],
				$ver,
				true
			);
		}

		// Work meta repeaters JS (needs wp-mediaelement for file picker).
		if ( 'ftnc_work' === $screen->post_type ) {
			wp_enqueue_media();
			wp_enqueue_script(
				'fotonic-meta-work',
				$base_url . 'meta-work.js',
				[ 'media-editor' ],
				$ver,
				true
			);
		}
	}

	/**
	 * Register meta boxes for all 3 CPTs.
	 */
	public static function register(): void {
		add_meta_box(
			'ftnc_customer_people',
			esc_html__( 'People', 'eleva-crm-for-photographers' ),
			[ __CLASS__, 'render_customer_meta_box' ],
			'ftnc_customer',
			'normal',
			'high'
		);

		add_meta_box(
			'ftnc_customer_works',
			esc_html__( 'Works', 'eleva-crm-for-photographers' ),
			[ __CLASS__, 'render_customer_works_meta_box' ],
			'ftnc_customer',
			'normal',
			'default'
		);

		add_meta_box(
			'ftnc_service_details',
			esc_html__( 'Service Details', 'eleva-crm-for-photographers' ),
			[ __CLASS__, 'render_service_meta_box' ],
			'ftnc_service',
			'normal',
			'high'
		);

		add_meta_box(
			'ftnc_work_details',
			esc_html__( 'Work Details', 'eleva-crm-for-photographers' ),
			[ __CLASS__, 'render_work_meta_box' ],
			'ftnc_work',
			'normal',
			'high'
		);

		add_action( 'edit_form_after_title', [ __CLASS__, 'render_work_quick_notes' ] );
	}

	public static function render_work_quick_notes( WP_Post $post ): void {
		if ( 'ftnc_work' !== $post->post_type ) {
			return;
		}
		$quick_notes = get_post_meta( $post->ID, '_ftnc_quick_notes', true );
		?>
		<div style="margin:16px 0 0;">
			<label style="display:block;font-weight:600;margin-bottom:6px;font-size:13px;color:#1d2327;">
				<?php esc_html_e( 'Quick Notes', 'eleva-crm-for-photographers' ); ?>
			</label>
			<?php
			wp_editor(
				$quick_notes ?: '',
				'ftnc_quick_notes',
				[
					'textarea_name' => 'ftnc_quick_notes',
					'media_buttons' => false,
					'teeny'         => false,
					'textarea_rows' => 5,
					'tinymce'       => true,
					'quicktags'     => true,
				]
			);
			?>
		</div>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Customer meta box
	// ---------------------------------------------------------------------------

	/**
	 * Render the "People" meta box for ftnc_customer.
	 */
	public static function render_customer_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'ftnc_save_meta', 'ftnc_meta_nonce' );

		$raw    = get_post_meta( $post->ID, '_ftnc_people', true );
		$people = [];
		if ( ! empty( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$people = $decoded;
			}
		}
		if ( empty( $people ) ) {
			$people = [
				[
					'first_name'        => '',
					'last_name'         => '',
					'email'             => '',
					'phone'             => '',
					'nationality'       => '',
					'instagram_username' => '',
					'address'           => '',
					'tin'               => '',
					'is_main'           => true,
				],
			];
		}

		$people_json = wp_json_encode( $people );
		?>
		<div id="ftnc-people-wrap">
			<table class="widefat ftnc-people-table" style="border-collapse:collapse;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'First Name', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Last Name', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Email', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Phone', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Nationality', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Instagram', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Address', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'TIN', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Main', 'eleva-crm-for-photographers' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="ftnc-people-rows"></tbody>
			</table>
			<p>
				<button type="button" id="ftnc-add-person" class="button">
					<?php esc_html_e( '+ Add Person', 'eleva-crm-for-photographers' ); ?>
				</button>
			</p>
			<input type="hidden" id="ftnc_people_json" name="ftnc_people_json" value="<?php echo esc_attr( $people_json ); ?>">
		</div>
		<?php
		// Pass PHP data to the enqueued JS via wp_localize_script.
		wp_localize_script(
			'fotonic-meta-customer',
			'FtncMetaData',
			[
				'customer' => [
					'people'             => $people,
					'i18n_remove'        => __( 'Remove', 'eleva-crm-for-photographers' ),
					'i18n_at_least_one'  => __( 'At least one person is required.', 'eleva-crm-for-photographers' ),
				],
			]
		);
	}

	// ---------------------------------------------------------------------------
	// Customer → Works recap meta box (read-only)
	// ---------------------------------------------------------------------------

	/**
	 * Render the "Works" recap meta box on a single ftnc_customer post.
	 * Lists all ftnc_work linked to this customer with totals footer.
	 */
	public static function render_customer_works_meta_box( WP_Post $post ): void {
		$customer_id = (int) $post->ID;

		$query = new WP_Query( [
			'post_type'              => 'ftnc_work',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'meta_key'               => '_ftnc_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key is indexed; query scoped to a single post type.
			'orderby'                => 'meta_value',
			'order'                  => 'DESC',
			'meta_query'             => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key is indexed; query scoped to a single post type.
				[
					'key'   => '_ftnc_customer_id',
					'value' => $customer_id,
				],
			],
			'no_found_rows'          => true,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
		] );

		$service_ids_needed = [];
		$rows               = [];
		foreach ( $query->posts as $work ) {
			$raw_services = get_post_meta( $work->ID, '_ftnc_work_services', true );
			$services_ids = [];
			if ( ! empty( $raw_services ) ) {
				$dec = json_decode( $raw_services, true );
				if ( is_array( $dec ) ) {
					foreach ( $dec as $svc ) {
						if ( isset( $svc['service_id'] ) ) {
							$sid                          = (int) $svc['service_id'];
							$services_ids[]               = $sid;
							$service_ids_needed[ $sid ]   = true;
						}
					}
				}
			}

			$raw_installments = get_post_meta( $work->ID, '_ftnc_installments', true );
			$paid_sum         = 0.0;
			if ( ! empty( $raw_installments ) ) {
				$dec = json_decode( $raw_installments, true );
				if ( is_array( $dec ) ) {
					foreach ( $dec as $inst ) {
						if ( isset( $inst['status'] ) && 'paid' === $inst['status'] ) {
							$paid_sum += (float) ( $inst['amount'] ?? 0 );
						}
					}
				}
			}

			$total_price   = (float) get_post_meta( $work->ID, '_ftnc_total_price', true );
			$event_date    = get_post_meta( $work->ID, '_ftnc_event_date', true );
			$payment_terms = wp_get_object_terms( $work->ID, 'ftnc_work_payment_status', [ 'fields' => 'slugs' ] );
			$payment_slug  = ! is_wp_error( $payment_terms ) && ! empty( $payment_terms ) ? $payment_terms[0] : 'unpaid';

			$rows[] = [
				'id'           => $work->ID,
				'title'        => $work->post_title,
				'event_date'   => $event_date,
				'services_ids' => $services_ids,
				'total_price'  => $total_price,
				'paid_sum'     => $paid_sum,
				'payment_slug' => $payment_slug,
			];
		}

		$services_titles = [];
		if ( ! empty( $service_ids_needed ) ) {
			$svc_posts = get_posts( [
				'post_type'              => 'ftnc_service',
				'post_status'            => 'any',
				'posts_per_page'         => -1,
				'post__in'               => array_map( 'intval', array_keys( $service_ids_needed ) ),
				'orderby'                => 'post__in',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			] );
			foreach ( $svc_posts as $svc ) {
				$services_titles[ $svc->ID ] = $svc->post_title;
			}
		}

		$status_labels = [
			'paid'    => __( 'Paid', 'eleva-crm-for-photographers' ),
			'partial' => __( 'Partial', 'eleva-crm-for-photographers' ),
			'unpaid'  => __( 'Unpaid', 'eleva-crm-for-photographers' ),
		];
		$status_classes = [
			'paid'    => 'ftnc-cw-paid',
			'partial' => 'ftnc-cw-partial',
			'unpaid'  => 'ftnc-cw-unpaid',
		];

		$date_format = get_option( 'date_format' );
		$count_total = count( $rows );
		$sum_total   = 0.0;
		$sum_paid    = 0.0;
		foreach ( $rows as $r ) {
			$sum_total += $r['total_price'];
			$sum_paid  += $r['paid_sum'];
		}
		$sum_unpaid = $sum_total - $sum_paid;
		?>

		<?php if ( empty( $rows ) ) : ?>
			<p class="ftnc-cw-empty"><?php esc_html_e( 'No works yet.', 'eleva-crm-for-photographers' ); ?></p>
		<?php else : ?>
			<table class="widefat ftnc-cw-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Date', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Services', 'eleva-crm-for-photographers' ); ?></th>
						<th class="num"><?php esc_html_e( 'Total Price', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Payment Status', 'eleva-crm-for-photographers' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $r ) :
						$spa_url   = admin_url( 'admin.php?page=fotonic' ) . '#/works/' . (int) $r['id'];
						$svc_names = [];
						foreach ( $r['services_ids'] as $sid ) {
							if ( isset( $services_titles[ $sid ] ) ) {
								$svc_names[] = $services_titles[ $sid ];
							}
						}
						$svc_str     = implode( ', ', $svc_names );
						$date_disp   = $r['event_date'] ? date_i18n( $date_format, strtotime( $r['event_date'] ) ) : '—';
						$badge_class = isset( $status_classes[ $r['payment_slug'] ] ) ? $status_classes[ $r['payment_slug'] ] : 'ftnc-cw-unpaid';
						$badge_label = isset( $status_labels[ $r['payment_slug'] ] ) ? $status_labels[ $r['payment_slug'] ] : $status_labels['unpaid'];
						$title_disp  = $r['title'] !== '' ? $r['title'] : __( '(no title)', 'eleva-crm-for-photographers' );
						?>
						<tr>
							<td><a href="<?php echo esc_url( $spa_url ); ?>"><?php echo esc_html( $title_disp ); ?></a></td>
							<td><?php echo esc_html( $date_disp ); ?></td>
							<td class="ftnc-cw-services"><?php echo esc_html( $svc_str ); ?></td>
							<td class="num">€ <?php echo esc_html( number_format_i18n( $r['total_price'], 2 ) ); ?></td>
							<td><span class="ftnc-cw-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="3">
							<?php
							/* translators: %d: number of works */
							printf( esc_html__( 'Total works: %d', 'eleva-crm-for-photographers' ), (int) $count_total );
							?>
						</td>
						<td class="num">€ <?php echo esc_html( number_format_i18n( $sum_total, 2 ) ); ?></td>
						<td>
							<span style="color:#155724;"><?php esc_html_e( 'Paid:', 'eleva-crm-for-photographers' ); ?> € <?php echo esc_html( number_format_i18n( $sum_paid, 2 ) ); ?></span>
							&nbsp;·&nbsp;
							<span style="color:#721c24;"><?php esc_html_e( 'Unpaid:', 'eleva-crm-for-photographers' ); ?> € <?php echo esc_html( number_format_i18n( $sum_unpaid, 2 ) ); ?></span>
						</td>
					</tr>
				</tfoot>
			</table>
		<?php endif; ?>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Service meta box
	// ---------------------------------------------------------------------------

	/**
	 * Render "Service Details" meta box for ftnc_service.
	 */
	public static function render_service_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'ftnc_save_meta', 'ftnc_meta_nonce' );

		$base_price = get_post_meta( $post->ID, '_ftnc_base_price', true );
		$notes      = get_post_meta( $post->ID, '_ftnc_notes', true );
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="ftnc_base_price"><?php esc_html_e( 'Base Price (€)', 'eleva-crm-for-photographers' ); ?></label>
				</th>
				<td>
					<input type="number" id="ftnc_base_price" name="ftnc_base_price"
						value="<?php echo esc_attr( $base_price ); ?>"
						step="0.01" min="0" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ftnc_notes"><?php esc_html_e( 'Notes', 'eleva-crm-for-photographers' ); ?></label>
				</th>
				<td>
					<?php
					wp_editor(
						$notes,
						'ftnc_notes',
						[
							'textarea_name' => 'ftnc_notes',
							'media_buttons' => false,
							'teeny'         => false,
							'textarea_rows' => 10,
							'tinymce'       => true,
							'quicktags'     => true,
						]
					);
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Work meta box
	// ---------------------------------------------------------------------------

	/**
	 * Render "Work Details" meta box for ftnc_work — 6 fieldset sections.
	 */
	public static function render_work_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'ftnc_save_meta', 'ftnc_meta_nonce' );

		// Retrieve saved values.
		$event_date      = get_post_meta( $post->ID, '_ftnc_event_date', true );
		$event_time_from = get_post_meta( $post->ID, '_ftnc_event_time_from', true );
		$event_time_to   = get_post_meta( $post->ID, '_ftnc_event_time_to', true );
		$customer_id   = get_post_meta( $post->ID, '_ftnc_customer_id', true );
		$total_price   = get_post_meta( $post->ID, '_ftnc_total_price', true );
		$color         = get_post_meta( $post->ID, '_ftnc_color', true );

		$raw_event_addresses  = get_post_meta( $post->ID, '_ftnc_event_addresses', true );
		$event_addresses      = [];
		if ( ! empty( $raw_event_addresses ) ) {
			$dec = json_decode( $raw_event_addresses, true );
			if ( is_array( $dec ) ) {
				$event_addresses = $dec;
			}
		}
		$event_addresses_json = wp_json_encode( $event_addresses );

		$raw_services     = get_post_meta( $post->ID, '_ftnc_work_services', true );
		$raw_files        = get_post_meta( $post->ID, '_ftnc_work_files', true );
		$raw_installments = get_post_meta( $post->ID, '_ftnc_installments', true );

		$work_services = [];
		if ( ! empty( $raw_services ) ) {
			$dec = json_decode( $raw_services, true );
			if ( is_array( $dec ) ) {
				$work_services = $dec;
			}
		}

		$work_files = [];
		if ( ! empty( $raw_files ) ) {
			$dec = json_decode( $raw_files, true );
			if ( is_array( $dec ) ) {
				$work_files = $dec;
			}
		}

		$installments = [];
		if ( ! empty( $raw_installments ) ) {
			$dec = json_decode( $raw_installments, true );
			if ( is_array( $dec ) ) {
				$installments = $dec;
			}
		}

		// Build customers select options.
		$customers_query = new WP_Query( [
			'post_type'      => 'ftnc_customer',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		// Build services select options.
		$services_query = new WP_Query( [
			'post_type'      => 'ftnc_service',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		// Build services map for JS (id => {title, base_price}).
		$services_map = [];
		if ( $services_query->have_posts() ) {
			foreach ( $services_query->posts as $svc ) {
				$services_map[ $svc->ID ] = [
					'title'      => $svc->post_title,
					'base_price' => (float) get_post_meta( $svc->ID, '_ftnc_base_price', true ),
				];
			}
		}

		$services_json     = wp_json_encode( $work_services );
		$files_json        = wp_json_encode( $work_files );
		$installments_json = wp_json_encode( $installments );

		$fieldset_style = 'border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px;margin-bottom:16px;';
		$legend_style   = 'font-weight:600;font-size:13px;color:#1d2327;padding:0 6px;';
		?>
		<div class="ftnc-work-wrap">

		<!-- Section 1: Event Details -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '1. Event Details', 'eleva-crm-for-photographers' ); ?></legend>
			<table class="form-table">
				<tr>
					<td colspan="2" style="padding:8px 0 0;">
						<div style="display:flex;gap:12px;">
							<div style="flex:0 0 calc(33.333% - 8px);box-sizing:border-box;">
								<label for="ftnc_event_date" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Event Date', 'eleva-crm-for-photographers' ); ?></label>
								<input type="date" id="ftnc_event_date" name="ftnc_event_date" value="<?php echo esc_attr( $event_date ); ?>" style="width:100%;">
							</div>
							<div style="flex:0 0 calc(33.333% - 8px);box-sizing:border-box;">
								<label for="ftnc_event_time_from" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Event Time From', 'eleva-crm-for-photographers' ); ?></label>
								<input type="time" id="ftnc_event_time_from" name="ftnc_event_time_from" value="<?php echo esc_attr( $event_time_from ); ?>" style="width:100%;">
							</div>
							<div style="flex:0 0 calc(33.333% - 8px);box-sizing:border-box;">
								<label for="ftnc_event_time_to" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Event Time To', 'eleva-crm-for-photographers' ); ?></label>
								<input type="time" id="ftnc_event_time_to" name="ftnc_event_time_to" value="<?php echo esc_attr( $event_time_to ); ?>" style="width:100%;">
							</div>
						</div>
					</td>
				</tr>
				<tr>
					<th style="vertical-align:top;padding-top:10px;"><label><?php esc_html_e( 'Addresses', 'eleva-crm-for-photographers' ); ?></label></th>
					<td>
						<table class="widefat ftnc-work-table" id="ftnc-addresses-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Label', 'eleva-crm-for-photographers' ); ?></th>
									<th><?php esc_html_e( 'Street', 'eleva-crm-for-photographers' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody id="ftnc-addresses-rows"></tbody>
						</table>
						<p style="margin-top:6px;">
							<button type="button" id="ftnc-add-address" class="button">
								<?php esc_html_e( '+ Add Address', 'eleva-crm-for-photographers' ); ?>
							</button>
						</p>
						<input type="hidden" id="ftnc_event_addresses_json" name="ftnc_event_addresses_json" value="<?php echo esc_attr( $event_addresses_json ); ?>">
					</td>
				</tr>
			</table>
		</fieldset>

		<!-- Section 2: Customer Link -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '2. Customer', 'eleva-crm-for-photographers' ); ?></legend>
			<table class="form-table">
				<tr>
					<th><label for="ftnc_customer_id"><?php esc_html_e( 'Customer', 'eleva-crm-for-photographers' ); ?></label></th>
					<td>
						<select id="ftnc_customer_id" name="ftnc_customer_id" class="regular-text">
							<option value=""><?php esc_html_e( '— Select customer —', 'eleva-crm-for-photographers' ); ?></option>
							<?php if ( $customers_query->have_posts() ) : ?>
								<?php foreach ( $customers_query->posts as $cust ) : ?>
									<option value="<?php echo esc_attr( $cust->ID ); ?>"<?php selected( $customer_id, $cust->ID ); ?>>
										<?php echo esc_html( $cust->post_title ); ?>
									</option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
					</td>
				</tr>
			</table>
		</fieldset>

		<!-- Section 3: Calendar Color -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '3. Calendar Color', 'eleva-crm-for-photographers' ); ?></legend>
			<p style="margin:0 0 10px;font-size:12px;color:#646970;"><?php esc_html_e( 'Choose the event card color shown in the calendar view.', 'eleva-crm-for-photographers' ); ?></p>
			<div class="ftnc-color-palette">
				<?php
				$palette = [
					''         => [ 'label' => __( 'Default', 'eleva-crm-for-photographers' ), 'bg' => '#e5e7eb', 'dashed' => true ],
					'#D50000'  => [ 'label' => __( 'Tomato', 'eleva-crm-for-photographers' ),    'bg' => '#D50000' ],
					'#E67C73'  => [ 'label' => __( 'Flamingo', 'eleva-crm-for-photographers' ),  'bg' => '#E67C73' ],
					'#F4511E'  => [ 'label' => __( 'Tangerine', 'eleva-crm-for-photographers' ), 'bg' => '#F4511E' ],
					'#F6BF26'  => [ 'label' => __( 'Banana', 'eleva-crm-for-photographers' ),    'bg' => '#F6BF26' ],
					'#33B679'  => [ 'label' => __( 'Sage', 'eleva-crm-for-photographers' ),      'bg' => '#33B679' ],
					'#0B8043'  => [ 'label' => __( 'Basil', 'eleva-crm-for-photographers' ),     'bg' => '#0B8043' ],
					'#039BE5'  => [ 'label' => __( 'Peacock', 'eleva-crm-for-photographers' ),   'bg' => '#039BE5' ],
					'#3F51B5'  => [ 'label' => __( 'Blueberry', 'eleva-crm-for-photographers' ), 'bg' => '#3F51B5' ],
					'#7986CB'  => [ 'label' => __( 'Lavender', 'eleva-crm-for-photographers' ),  'bg' => '#7986CB' ],
					'#8E24AA'  => [ 'label' => __( 'Grape', 'eleva-crm-for-photographers' ),     'bg' => '#8E24AA' ],
					'#616161'  => [ 'label' => __( 'Graphite', 'eleva-crm-for-photographers' ),  'bg' => '#616161' ],
				];
				foreach ( $palette as $hex => $info ) :
					$is_selected  = ( $color === $hex );
					$extra_border = ! empty( $info['dashed'] ) ? 'border:2px dashed #9ca3af;' : '';
					$txt_color    = ! empty( $info['dashed'] ) ? 'color:#374151;' : '';
					?>
					<label class="ftnc-color-swatch" title="<?php echo esc_attr( $info['label'] ); ?>">
						<input type="radio" name="ftnc_color" value="<?php echo esc_attr( $hex ); ?>"<?php checked( $color, $hex ); ?>>
						<span class="ftnc-color-circle" style="background:<?php echo esc_attr( $info['bg'] ); ?>;<?php echo esc_attr( $extra_border ); ?><?php echo esc_attr( $txt_color ); ?>">
							<?php echo $is_selected ? esc_html( '✓' ) : ''; ?>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>

		<?php
		/**
		 * Pro addon renders owner + collaborators fieldsets here.
		 * Hook: ftnc_work_meta_box_after
		 */
		do_action( 'ftnc_work_meta_box_after', $post );
		?>

		<!-- Section 6: Services Included -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '6. Services Included', 'eleva-crm-for-photographers' ); ?></legend>
			<table class="widefat ftnc-work-table" id="ftnc-services-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Service', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Price Override (€)', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Notes Override', 'eleva-crm-for-photographers' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="ftnc-services-rows"></tbody>
			</table>
			<p style="margin-top:8px;">
				<select id="ftnc-service-picker" class="regular-text">
					<option value=""><?php esc_html_e( '— Add a service —', 'eleva-crm-for-photographers' ); ?></option>
					<?php if ( $services_query->have_posts() ) : ?>
						<?php foreach ( $services_query->posts as $svc ) : ?>
							<option value="<?php echo esc_attr( $svc->ID ); ?>">
								<?php echo esc_html( $svc->post_title ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
				<button type="button" id="ftnc-add-service" class="button"><?php esc_html_e( 'Add', 'eleva-crm-for-photographers' ); ?></button>
			</p>
			<input type="hidden" id="ftnc_work_services_json" name="ftnc_work_services_json" value="<?php echo esc_attr( $services_json ); ?>">
		</fieldset>

		<!-- Section 7: Files -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '7. Files', 'eleva-crm-for-photographers' ); ?></legend>
			<div id="ftnc-files-list"></div>
			<p>
				<button type="button" id="ftnc-add-file" class="button">
					<?php esc_html_e( '+ Add File', 'eleva-crm-for-photographers' ); ?>
				</button>
			</p>
			<input type="hidden" id="ftnc_work_files_json" name="ftnc_work_files_json" value="<?php echo esc_attr( $files_json ); ?>">
		</fieldset>

		<!-- Section 8: Payments -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '8. Payments', 'eleva-crm-for-photographers' ); ?></legend>
			<table class="form-table">
				<tr>
					<th><label for="ftnc_total_price"><?php esc_html_e( 'Total Price (€)', 'eleva-crm-for-photographers' ); ?></label></th>
					<td><input type="number" id="ftnc_total_price" name="ftnc_total_price" value="<?php echo esc_attr( $total_price ); ?>" step="0.01" min="0" class="regular-text"></td>
				</tr>
			</table>
			<p><strong><?php esc_html_e( 'Installments', 'eleva-crm-for-photographers' ); ?></strong></p>
			<table class="widefat ftnc-work-table" id="ftnc-installments-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Title', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Amount (€)', 'eleva-crm-for-photographers' ); ?></th>
						<th><?php esc_html_e( 'Status', 'eleva-crm-for-photographers' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="ftnc-installments-rows"></tbody>
			</table>
			<p style="margin-top:8px;">
				<button type="button" id="ftnc-add-installment" class="button">
					<?php esc_html_e( '+ Add Installment', 'eleva-crm-for-photographers' ); ?>
				</button>
			</p>
			<input type="hidden" id="ftnc_installments_json" name="ftnc_installments_json" value="<?php echo esc_attr( $installments_json ); ?>">
		</fieldset>

		</div><!-- .ftnc-work-wrap -->
		<?php
		// Pass PHP data to the enqueued JS via wp_localize_script.
		wp_localize_script(
			'fotonic-meta-work',
			'FtncMetaData',
			[
				'work' => [
					'services'            => $work_services,
					'files'               => $work_files,
					'installments'        => $installments,
					'addresses'           => $event_addresses,
					'servicesMap'         => $services_map,
					'i18n_addr_label'     => __( 'e.g. Church', 'eleva-crm-for-photographers' ),
					'i18n_addr_street'    => __( 'Via Roma 1, Milano', 'eleva-crm-for-photographers' ),
					'i18n_remove'         => __( 'Remove', 'eleva-crm-for-photographers' ),
					'i18n_no_media'       => __( 'Media library not available.', 'eleva-crm-for-photographers' ),
					'i18n_media_title'    => __( 'Select Files', 'eleva-crm-for-photographers' ),
					'i18n_media_button'   => __( 'Add to Work', 'eleva-crm-for-photographers' ),
					'i18n_attach_id'      => __( 'Attachment ID', 'eleva-crm-for-photographers' ),
					'i18n_paid'           => __( 'Paid', 'eleva-crm-for-photographers' ),
					'i18n_unpaid'         => __( 'Unpaid', 'eleva-crm-for-photographers' ),
					'i18n_coupon'         => __( 'Coupon', 'eleva-crm-for-photographers' ),
					'i18n_default_type'   => __( 'Default', 'eleva-crm-for-photographers' ),
				],
			]
		);
	}

	// ---------------------------------------------------------------------------
	// Save: Customer
	// ---------------------------------------------------------------------------

	/**
	 * Save customer meta (people JSON).
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_customer( int $post_id, WP_Post $post ): void {
		if ( ! self::can_save( $post_id, $post, 'ftnc_meta_nonce', 'ftnc_save_meta' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by can_save() which calls check_admin_referer(). All inputs sanitized individually below.

		$raw = isset( $_POST['ftnc_people_json'] ) ? wp_unslash( $_POST['ftnc_people_json'] ) : '[]';

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			$decoded = [];
		}

		$vault_key = Fotonic_Vault::get_session_key();
		$encrypt   = $vault_key !== null;

		$sanitized = [];
		$has_main  = false;
		foreach ( $decoded as $person ) {
			if ( ! is_array( $person ) ) {
				continue;
			}
			$is_main    = ! empty( $person['is_main'] ) && ! $has_main;
			$has_main   = $has_main || $is_main;

			$first_name         = sanitize_text_field( $person['first_name'] ?? '' );
			$last_name          = sanitize_text_field( $person['last_name'] ?? '' );
			$email              = sanitize_email( $person['email'] ?? '' );
			$phone              = sanitize_text_field( $person['phone'] ?? '' );
			$nationality        = sanitize_text_field( $person['nationality'] ?? '' );
			$instagram_username = sanitize_text_field( $person['instagram_username'] ?? '' );
			$address            = sanitize_text_field( $person['address'] ?? '' );
			$tin                = sanitize_text_field( $person['tin'] ?? '' );

			if ( $encrypt ) {
				if ( '' !== $first_name )         { $first_name         = Fotonic_Crypto::encrypt( $first_name,         $vault_key ); }
				if ( '' !== $last_name )          { $last_name          = Fotonic_Crypto::encrypt( $last_name,          $vault_key ); }
				if ( '' !== $nationality )        { $nationality        = Fotonic_Crypto::encrypt( $nationality,        $vault_key ); }
				if ( '' !== $instagram_username ) { $instagram_username = Fotonic_Crypto::encrypt( $instagram_username, $vault_key ); }
				if ( '' !== $address )            { $address            = Fotonic_Crypto::encrypt( $address,            $vault_key ); }
				if ( '' !== $tin )                { $tin                = Fotonic_Crypto::encrypt( $tin,                $vault_key ); }
				if ( '' !== $email )              { $email              = Fotonic_Crypto::deterministic_encrypt( $email, $vault_key ); }
				if ( '' !== $phone )              { $phone              = Fotonic_Crypto::deterministic_encrypt( $phone, $vault_key ); }
			}

			$sanitized[] = [
				'first_name'         => $first_name,
				'last_name'          => $last_name,
				'email'              => $email,
				'phone'              => $phone,
				'nationality'        => $nationality,
				'instagram_username' => $instagram_username,
				'address'            => $address,
				'tin'                => $tin,
				'is_main'            => (bool) $is_main,
			];
		}

		// Ensure at least one is_main.
		if ( ! empty( $sanitized ) && ! $has_main ) {
			$sanitized[0]['is_main'] = true;
		}

		update_post_meta( $post_id, '_ftnc_people', wp_json_encode( $sanitized ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	// ---------------------------------------------------------------------------
	// Save: Service
	// ---------------------------------------------------------------------------

	/**
	 * Save service meta.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_service( int $post_id, WP_Post $post ): void {
		if ( ! self::can_save( $post_id, $post, 'ftnc_meta_nonce', 'ftnc_save_meta' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by can_save() which calls check_admin_referer(). All inputs sanitized individually below.

		if ( isset( $_POST['ftnc_base_price'] ) ) {
			$price = (float) wp_unslash( $_POST['ftnc_base_price'] );
			update_post_meta( $post_id, '_ftnc_base_price', $price >= 0 ? $price : 0 );
		}

		if ( isset( $_POST['ftnc_notes'] ) ) {
			update_post_meta( $post_id, '_ftnc_notes', wp_kses_post( wp_unslash( $_POST['ftnc_notes'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	// ---------------------------------------------------------------------------
	// Save: Work
	// ---------------------------------------------------------------------------

	/**
	 * Save work meta — all 6 sections.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_work( int $post_id, WP_Post $post ): void {
		if ( ! self::can_save( $post_id, $post, 'ftnc_meta_nonce', 'ftnc_save_meta' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified by can_save() which calls check_admin_referer(). All inputs sanitized individually below.

		$vault_key = Fotonic_Vault::get_session_key();
		$encrypt   = $vault_key !== null;

		// Section 1 — Event Details.
		if ( isset( $_POST['ftnc_event_date'] ) ) {
			$date = sanitize_text_field( wp_unslash( $_POST['ftnc_event_date'] ) );
			// Validate Y-m-d format.
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				update_post_meta( $post_id, '_ftnc_event_date', $date );
			} else {
				delete_post_meta( $post_id, '_ftnc_event_date' );
			}
		}

		if ( isset( $_POST['ftnc_event_time_from'] ) ) {
			$time = sanitize_text_field( wp_unslash( $_POST['ftnc_event_time_from'] ) );
			if ( preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', $time ) ) {
				update_post_meta( $post_id, '_ftnc_event_time_from', substr( $time, 0, 5 ) );
			} else {
				delete_post_meta( $post_id, '_ftnc_event_time_from' );
			}
		}

		if ( isset( $_POST['ftnc_event_time_to'] ) ) {
			$time = sanitize_text_field( wp_unslash( $_POST['ftnc_event_time_to'] ) );
			if ( preg_match( '/^\d{2}:\d{2}(:\d{2})?$/', $time ) ) {
				update_post_meta( $post_id, '_ftnc_event_time_to', substr( $time, 0, 5 ) );
			} else {
				delete_post_meta( $post_id, '_ftnc_event_time_to' );
			}
		}

		if ( isset( $_POST['ftnc_event_addresses_json'] ) ) {
			$raw = wp_unslash( $_POST['ftnc_event_addresses_json'] );
			$dec = json_decode( $raw, true );
			if ( ! is_array( $dec ) ) {
				$dec = [];
			}
			$clean_addresses = [];
			foreach ( $dec as $addr ) {
				if ( ! is_array( $addr ) ) {
					continue;
				}
				$label  = sanitize_text_field( $addr['label'] ?? '' );
				$street = sanitize_text_field( $addr['street'] ?? '' );
				if ( $encrypt && $street !== '' ) {
					$street = Fotonic_Crypto::encrypt( $street, $vault_key );
				}
				$clean_addresses[] = [
					'label'  => $label,
					'street' => $street,
				];
			}
			update_post_meta( $post_id, '_ftnc_event_addresses', wp_json_encode( $clean_addresses ) );
		}

		// Section 2 — Customer.
		if ( isset( $_POST['ftnc_customer_id'] ) ) {
			$cid = (int) $_POST['ftnc_customer_id'];
			if ( $cid > 0 && get_post_type( $cid ) === 'ftnc_customer' ) {
				update_post_meta( $post_id, '_ftnc_customer_id', $cid );
			} else {
				delete_post_meta( $post_id, '_ftnc_customer_id' );
			}
		}

		// Section 4 — Work Services JSON.
		if ( isset( $_POST['ftnc_work_services_json'] ) ) {
			$raw = wp_unslash( $_POST['ftnc_work_services_json'] );
			$dec = json_decode( $raw, true );
			if ( ! is_array( $dec ) ) {
				$dec = [];
			}
			$clean = [];
			foreach ( $dec as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$clean[] = [
					'service_id'     => (int) ( $item['service_id'] ?? 0 ),
					'price_override' => (float) ( $item['price_override'] ?? 0 ),
					'notes_override' => sanitize_text_field( $item['notes_override'] ?? '' ),
				];
			}
			update_post_meta( $post_id, '_ftnc_work_services', wp_json_encode( $clean ) );
		}

		// Section 5 — Files JSON.
		if ( isset( $_POST['ftnc_work_files_json'] ) ) {
			$raw = wp_unslash( $_POST['ftnc_work_files_json'] );
			$dec = json_decode( $raw, true );
			if ( ! is_array( $dec ) ) {
				$dec = [];
			}
			$clean = array_values( array_map( 'absint', $dec ) );
			$clean = array_filter( $clean );
			update_post_meta( $post_id, '_ftnc_work_files', wp_json_encode( array_values( $clean ) ) );
		}

		// Section 6 — Payments.
		if ( isset( $_POST['ftnc_total_price'] ) ) {
			$price = (float) wp_unslash( $_POST['ftnc_total_price'] );
			update_post_meta( $post_id, '_ftnc_total_price', $price >= 0 ? $price : 0 );
		}

		if ( isset( $_POST['ftnc_installments_json'] ) ) {
			$raw = wp_unslash( $_POST['ftnc_installments_json'] );
			$dec = json_decode( $raw, true );
			if ( ! is_array( $dec ) ) {
				$dec = [];
			}
			$clean = [];
			foreach ( $dec as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$status      = ( isset( $item['status'] ) && $item['status'] === 'paid' ) ? 'paid' : 'unpaid';
				$valid_slugs = Fotonic_REST_API::get_valid_payment_type_slugs();
				$fallback    = ! empty( $valid_slugs ) ? $valid_slugs[0] : 'default';
				$type        = ( isset( $item['type'] ) && in_array( $item['type'], $valid_slugs, true ) ) ? $item['type'] : $fallback;
				$raw_date = sanitize_text_field( $item['date'] ?? '' );
				$clean[]  = [
					'title'  => sanitize_text_field( $item['title'] ?? '' ),
					'amount' => (float) ( $item['amount'] ?? 0 ),
					'status' => $status,
					'type'   => $type,
					'date'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_date ) ? $raw_date : '',
				];
			}
			update_post_meta( $post_id, '_ftnc_installments', wp_json_encode( $clean ) );
		}

		// Section 7 — Calendar Color.
		if ( isset( $_POST['ftnc_color'] ) ) {
			$color = sanitize_hex_color( wp_unslash( $_POST['ftnc_color'] ) );
			if ( $color ) {
				update_post_meta( $post_id, '_ftnc_color', $color );
			} else {
				delete_post_meta( $post_id, '_ftnc_color' );
			}
		}

		// Quick Notes (rendered via edit_form_after_title, saved here).
		if ( isset( $_POST['ftnc_quick_notes'] ) ) {
			update_post_meta( $post_id, '_ftnc_quick_notes', wp_kses_post( wp_unslash( $_POST['ftnc_quick_notes'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	// ---------------------------------------------------------------------------
	// Auto-assign payment status
	// ---------------------------------------------------------------------------

	/**
	 * After saving a Work, compute and assign the payment_status taxonomy term.
	 *
	 * Priority 20 so it runs after save_work (priority 10).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function auto_assign_payment_status( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( get_post_type( $post_id ) !== 'ftnc_work' ) {
			return;
		}

		$raw = get_post_meta( $post_id, '_ftnc_installments', true );
		$installments = [];
		if ( ! empty( $raw ) ) {
			$dec = json_decode( $raw, true );
			if ( is_array( $dec ) ) {
				$installments = $dec;
			}
		}

		if ( empty( $installments ) ) {
			$term = 'unpaid';
		} else {
			$total = count( $installments );
			$paid  = count( array_filter( $installments, function ( $i ) {
				return isset( $i['status'] ) && $i['status'] === 'paid';
			} ) );

			if ( $paid === $total ) {
				$term = 'paid';
			} elseif ( $paid > 0 ) {
				$term = 'partial';
			} else {
				$term = 'unpaid';
			}
		}

		// Ensure terms exist before assigning.
		Fotonic_CPT_Registry::ensure_payment_terms();

		wp_set_object_terms( $post_id, $term, 'ftnc_work_payment_status' );
	}

	// ---------------------------------------------------------------------------
	// Memory card status sync
	// ---------------------------------------------------------------------------

	/**
	 * Sync ftnc_memory_card statuses after a work is saved via the REST API.
	 * Hooked on ftnc_after_save_work (fires after save_work_meta completes).
	 *
	 * @param int   $post_id Work post ID.
	 * @param array $body    Request body; must include '_prev_memory_cards' (raw JSON) when updating.
	 */
	public static function sync_memory_card_statuses( int $post_id, array $body = [] ): void {
		if ( ! class_exists( 'Fotonic_Memory_Card_CPT' ) ) {
			return;
		}

		$backup_done     = (bool) get_post_meta( $post_id, '_ftnc_backup_done', true );
		$formatting_done = (bool) get_post_meta( $post_id, '_ftnc_formatting_done', true );

		$current_ids = self::parse_card_ids( get_post_meta( $post_id, '_ftnc_memory_cards', true ) );
		$prev_ids    = self::parse_card_ids( $body['_prev_memory_cards'] ?? '' );

		// Free cards that were explicitly removed from the repeater.
		foreach ( array_diff( $prev_ids, $current_ids ) as $removed_id ) {
			Fotonic_Memory_Card_CPT::set_card_status( $removed_id, 'free' );
		}

		// Advance statuses for cards still in the work.
		foreach ( $current_ids as $card_id ) {
			$status = Fotonic_Memory_Card_CPT::get_card_status( $card_id );

			if ( 'free' === $status ) {
				Fotonic_Memory_Card_CPT::set_card_status( $card_id, 'in_use' );
				$status = 'in_use';
			}

			if ( $backup_done && 'in_use' === $status ) {
				Fotonic_Memory_Card_CPT::set_card_status( $card_id, 'backed_up' );
				$status = 'backed_up';
			}

			if ( $backup_done && $formatting_done && 'backed_up' === $status ) {
				Fotonic_Memory_Card_CPT::set_card_status( $card_id, 'free' );
			}
		}
	}

	private static function parse_card_ids( $raw ): array {
		if ( empty( $raw ) ) {
			return [];
		}
		$dec = json_decode( $raw, true );
		if ( ! is_array( $dec ) ) {
			return [];
		}
		return array_values( array_unique( array_filter( array_map( function ( $c ) {
			return (int) ( $c['card_id'] ?? 0 );
		}, $dec ) ) ) );
	}

	// ---------------------------------------------------------------------------
	// Customer search hooks
	// ---------------------------------------------------------------------------

	/**
	 * Extend wp_query search to also search postmeta values for ftnc_customer.
	 *
	 * @param string   $search   Current SQL search clause.
	 * @param WP_Query $wp_query WP_Query instance.
	 * @return string Modified search clause.
	 */
	public static function extend_customer_search( string $search, WP_Query $wp_query ): string {
		global $wpdb;

		if (
			! $wp_query->is_search() ||
			! isset( $wp_query->query_vars['post_type'] ) ||
			$wp_query->query_vars['post_type'] !== 'ftnc_customer' ||
			empty( $wp_query->query_vars['s'] )
		) {
			return $search;
		}

		$term = '%' . $wpdb->esc_like( $wp_query->query_vars['s'] ) . '%';

		// Scoped to the `_ftnc_people` meta key only (set in extend_customer_search_join).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->postmeta is a trusted WP core property, not user input.
		$search .= $wpdb->prepare(
			" OR (ftnc_people_pm.meta_value LIKE %s)",
			$term
		);

		return $search;
	}

	/**
	 * Add LEFT JOIN on postmeta for customer search queries.
	 *
	 * @param string   $join     Current JOIN clause.
	 * @param WP_Query $wp_query WP_Query instance.
	 * @return string Modified JOIN clause.
	 */
	public static function extend_customer_search_join( string $join, WP_Query $wp_query ): string {
		global $wpdb;

		if (
			! $wp_query->is_search() ||
			! isset( $wp_query->query_vars['post_type'] ) ||
			$wp_query->query_vars['post_type'] !== 'ftnc_customer' ||
			empty( $wp_query->query_vars['s'] )
		) {
			return $join;
		}

		// Scope the JOIN to the `_ftnc_people` meta key only so it does not span unrelated postmeta rows.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Table names from WP core globals, not user input.
		$join .= " LEFT JOIN {$wpdb->postmeta} AS ftnc_people_pm ON ({$wpdb->posts}.ID = ftnc_people_pm.post_id AND ftnc_people_pm.meta_key = '_ftnc_people')";

		return $join;
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	public static function extend_customer_search_distinct( string $distinct, WP_Query $wp_query ): string {
		if ( ! $wp_query->is_search() || ( $wp_query->query_vars['post_type'] ?? '' ) !== 'ftnc_customer' ) {
			return $distinct;
		}
		return 'DISTINCT';
	}

	/**
	 * Common save-guard: nonce check, autosave check, capability check.
	 *
	 * @param int     $post_id    Post ID.
	 * @param WP_Post $_post      Post object.
	 * @param string  $nonce_name Nonce field name.
	 * @param string  $nonce_action Nonce action.
	 * @return bool True if we should proceed with saving.
	 */
	private static function can_save( int $post_id, WP_Post $_post, string $nonce_name, string $nonce_action ): bool {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return false;
		}
		if ( ! isset( $_POST[ $nonce_name ] ) ) {
			return false;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_name ] ) ), $nonce_action ) ) {
			return false;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return true;
	}
}
