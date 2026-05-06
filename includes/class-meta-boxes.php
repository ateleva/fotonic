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
	// Registration
	// ---------------------------------------------------------------------------

	/**
	 * Register meta boxes for all 3 CPTs.
	 */
	public static function register(): void {
		add_meta_box(
			'ftnc_customer_people',
			esc_html__( 'People', 'fotonic' ),
			[ __CLASS__, 'render_customer_meta_box' ],
			'ftnc_customer',
			'normal',
			'high'
		);

		add_meta_box(
			'ftnc_service_details',
			esc_html__( 'Service Details', 'fotonic' ),
			[ __CLASS__, 'render_service_meta_box' ],
			'ftnc_service',
			'normal',
			'high'
		);

		add_meta_box(
			'ftnc_work_details',
			esc_html__( 'Work Details', 'fotonic' ),
			[ __CLASS__, 'render_work_meta_box' ],
			'ftnc_work',
			'normal',
			'high'
		);
	}

	// ---------------------------------------------------------------------------
	// Customer meta box
	// ---------------------------------------------------------------------------

	/**
	 * Render the "People" meta box for ftnc_customer.
	 */
	public static function render_customer_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'ftnc_customer_save', 'ftnc_customer_nonce' );

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
						<th><?php esc_html_e( 'First Name', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Last Name', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Email', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Phone', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Nationality', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Instagram', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Address', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'TIN', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Main', 'fotonic' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="ftnc-people-rows"></tbody>
			</table>
			<p>
				<button type="button" id="ftnc-add-person" class="button">
					<?php esc_html_e( '+ Add Person', 'fotonic' ); ?>
				</button>
			</p>
			<input type="hidden" id="ftnc_people_json" name="ftnc_people_json" value="<?php echo esc_attr( $people_json ); ?>">
		</div>

		<script>
		(function() {
			var people = <?php echo $people_json; // already escaped as JSON ?>;

			function render() {
				var tbody = document.getElementById('ftnc-people-rows');
				tbody.innerHTML = '';
				people.forEach(function(p, i) {
					var tr = document.createElement('tr');
					tr.style.borderTop = '1px solid #ddd';
					tr.innerHTML = '<td><input type="text" class="regular-text" value="' + esc(p.first_name) + '" data-field="first_name" data-idx="' + i + '"></td>' +
						'<td><input type="text" class="regular-text" value="' + esc(p.last_name) + '" data-field="last_name" data-idx="' + i + '"></td>' +
						'<td><input type="email" class="regular-text" value="' + esc(p.email) + '" data-field="email" data-idx="' + i + '"></td>' +
						'<td><input type="text" class="regular-text" value="' + esc(p.phone) + '" data-field="phone" data-idx="' + i + '"></td>' +
						'<td><input type="text" class="small-text" value="' + esc(p.nationality) + '" data-field="nationality" data-idx="' + i + '"></td>' +
						'<td><input type="text" class="regular-text" value="' + esc(p.instagram_username) + '" data-field="instagram_username" data-idx="' + i + '" placeholder="@username"></td>' +
						'<td><input type="text" class="regular-text" value="' + esc(p.address) + '" data-field="address" data-idx="' + i + '"></td>' +
						'<td><input type="text" class="small-text" value="' + esc(p.tin) + '" data-field="tin" data-idx="' + i + '"></td>' +
						'<td style="text-align:center"><input type="radio" name="ftnc_is_main" value="' + i + '"' + (p.is_main ? ' checked' : '') + '></td>' +
						'<td><button type="button" class="button-link ftnc-remove-person" data-idx="' + i + '" style="color:#a00">' + <?php echo wp_json_encode( __( 'Remove', 'fotonic' ) ); ?> + '</button></td>';
					tbody.appendChild(tr);
				});
				attachListeners();
			}

			function esc(v) {
				if (!v) return '';
				return String(v).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
			}

			function sync() {
				document.getElementById('ftnc_people_json').value = JSON.stringify(people);
			}

			function attachListeners() {
				// Text field changes
				document.querySelectorAll('#ftnc-people-rows input[data-field]').forEach(function(el) {
					el.addEventListener('input', function() {
						var idx   = parseInt(this.dataset.idx, 10);
						var field = this.dataset.field;
						people[idx][field] = this.value;
						sync();
					});
				});

				// Radio is_main
				document.querySelectorAll('input[name="ftnc_is_main"]').forEach(function(el) {
					el.addEventListener('change', function() {
						var selected = parseInt(this.value, 10);
						people.forEach(function(p, i) { p.is_main = (i === selected); });
						sync();
					});
				});

				// Remove buttons
				document.querySelectorAll('.ftnc-remove-person').forEach(function(el) {
					el.addEventListener('click', function() {
						var idx = parseInt(this.dataset.idx, 10);
						if (people.length <= 1) {
							alert(<?php echo wp_json_encode( __( 'At least one person is required.', 'fotonic' ) ); ?>);
							return;
						}
						people.splice(idx, 1);
						// Ensure one is main
						if (!people.some(function(p){ return p.is_main; })) {
							people[0].is_main = true;
						}
						render();
						sync();
					});
				});
			}

			document.getElementById('ftnc-add-person').addEventListener('click', function() {
				people.push({ first_name:'', last_name:'', email:'', phone:'', nationality:'', instagram_username:'', address:'', tin:'', is_main: false });
				render();
				sync();
			});

			render();
		})();
		</script>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Service meta box
	// ---------------------------------------------------------------------------

	/**
	 * Render "Service Details" meta box for ftnc_service.
	 */
	public static function render_service_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'ftnc_service_save', 'ftnc_service_nonce' );

		$base_price = get_post_meta( $post->ID, '_ftnc_base_price', true );
		$notes      = get_post_meta( $post->ID, '_ftnc_notes', true );
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="ftnc_base_price"><?php esc_html_e( 'Base Price (€)', 'fotonic' ); ?></label>
				</th>
				<td>
					<input type="number" id="ftnc_base_price" name="ftnc_base_price"
						value="<?php echo esc_attr( $base_price ); ?>"
						step="0.01" min="0" class="regular-text">
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ftnc_notes"><?php esc_html_e( 'Notes', 'fotonic' ); ?></label>
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
		wp_nonce_field( 'ftnc_work_save', 'ftnc_work_nonce' );

		// Retrieve saved values.
		$event_date      = get_post_meta( $post->ID, '_ftnc_event_date', true );
		$event_time_from = get_post_meta( $post->ID, '_ftnc_event_time_from', true );
		$event_time_to   = get_post_meta( $post->ID, '_ftnc_event_time_to', true );
		$customer_id   = get_post_meta( $post->ID, '_ftnc_customer_id', true );
		$owner_id      = get_post_meta( $post->ID, '_ftnc_owner_id', true );
		$total_price   = get_post_meta( $post->ID, '_ftnc_total_price', true );

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

		// Current admin user.
		$current_user    = wp_get_current_user();
		$owner_display   = $current_user->display_name ?: $current_user->user_login;
		$effective_owner = $owner_id ? (int) $owner_id : $current_user->ID;

		$services_json     = wp_json_encode( $work_services );
		$files_json        = wp_json_encode( $work_files );
		$installments_json = wp_json_encode( $installments );
		$services_map_json = wp_json_encode( $services_map );

		$fieldset_style = 'border:1px solid #ccd0d4;border-radius:4px;padding:12px 16px;margin-bottom:16px;';
		$legend_style   = 'font-weight:600;font-size:13px;color:#1d2327;padding:0 6px;';
		?>
		<style>
		.ftnc-work-wrap .form-table th { width: 160px; }
		.ftnc-work-table { border-collapse: collapse; width: 100%; }
		.ftnc-work-table th, .ftnc-work-table td { padding: 6px 8px; text-align: left; vertical-align: middle; }
		.ftnc-work-table thead th { background: #f0f0f1; font-weight: 600; }
		.ftnc-work-table tbody tr { border-top: 1px solid #ddd; }
		.ftnc-status-toggle { cursor: pointer; border-radius: 12px; padding: 3px 10px; font-size: 12px; border: none; }
		.ftnc-status-paid   { background: #d4edda; color: #155724; }
		.ftnc-status-unpaid { background: #f8d7da; color: #721c24; }
		.ftnc-file-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
		.ftnc-type-default { background: #e8f4fd; color: #1565c0; }
		.ftnc-type-coupon  { background: #fff3e0; color: #e65100; }
		</style>

		<div class="ftnc-work-wrap">

		<!-- Section 1: Event Details -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '1. Event Details', 'fotonic' ); ?></legend>
			<table class="form-table">
				<tr>
					<th><label for="ftnc_event_date"><?php esc_html_e( 'Event Date', 'fotonic' ); ?></label></th>
					<td><input type="date" id="ftnc_event_date" name="ftnc_event_date" value="<?php echo esc_attr( $event_date ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="ftnc_event_time_from"><?php esc_html_e( 'Event Time From', 'fotonic' ); ?></label></th>
					<td><input type="time" id="ftnc_event_time_from" name="ftnc_event_time_from" value="<?php echo esc_attr( $event_time_from ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="ftnc_event_time_to"><?php esc_html_e( 'Event Time To', 'fotonic' ); ?></label></th>
					<td><input type="time" id="ftnc_event_time_to" name="ftnc_event_time_to" value="<?php echo esc_attr( $event_time_to ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th style="vertical-align:top;padding-top:10px;"><label><?php esc_html_e( 'Addresses', 'fotonic' ); ?></label></th>
					<td>
						<table class="widefat ftnc-work-table" id="ftnc-addresses-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Label', 'fotonic' ); ?></th>
									<th><?php esc_html_e( 'Street', 'fotonic' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody id="ftnc-addresses-rows"></tbody>
						</table>
						<p style="margin-top:6px;">
							<button type="button" id="ftnc-add-address" class="button">
								<?php esc_html_e( '+ Add Address', 'fotonic' ); ?>
							</button>
						</p>
						<input type="hidden" id="ftnc_event_addresses_json" name="ftnc_event_addresses_json" value="<?php echo esc_attr( $event_addresses_json ); ?>">
					</td>
				</tr>
			</table>
		</fieldset>

		<!-- Section 2: Customer Link -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '2. Customer', 'fotonic' ); ?></legend>
			<table class="form-table">
				<tr>
					<th><label for="ftnc_customer_id"><?php esc_html_e( 'Customer', 'fotonic' ); ?></label></th>
					<td>
						<select id="ftnc_customer_id" name="ftnc_customer_id" class="regular-text">
							<option value=""><?php esc_html_e( '— Select customer —', 'fotonic' ); ?></option>
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

		<!-- Section 3: Owner -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '3. Owner', 'fotonic' ); ?></legend>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Owner Type', 'fotonic' ); ?></th>
					<td>
						<label>
							<input type="radio" name="ftnc_owner_type" value="admin" checked>
							<?php esc_html_e( 'Admin (free plan)', 'fotonic' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Owner', 'fotonic' ); ?></th>
					<td>
						<strong><?php echo esc_html( $owner_display ); ?></strong>
						<input type="hidden" name="ftnc_owner_id" value="<?php echo esc_attr( $effective_owner ); ?>">
					</td>
				</tr>
			</table>
		</fieldset>

		<!-- Section 4: Services Included -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '4. Services Included', 'fotonic' ); ?></legend>
			<table class="widefat ftnc-work-table" id="ftnc-services-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Service', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Price Override (€)', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Notes Override', 'fotonic' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="ftnc-services-rows"></tbody>
			</table>
			<p style="margin-top:8px;">
				<select id="ftnc-service-picker" class="regular-text">
					<option value=""><?php esc_html_e( '— Add a service —', 'fotonic' ); ?></option>
					<?php if ( $services_query->have_posts() ) : ?>
						<?php foreach ( $services_query->posts as $svc ) : ?>
							<option value="<?php echo esc_attr( $svc->ID ); ?>">
								<?php echo esc_html( $svc->post_title ); ?>
							</option>
						<?php endforeach; ?>
					<?php endif; ?>
				</select>
				<button type="button" id="ftnc-add-service" class="button"><?php esc_html_e( 'Add', 'fotonic' ); ?></button>
			</p>
			<input type="hidden" id="ftnc_work_services_json" name="ftnc_work_services_json" value="<?php echo esc_attr( $services_json ); ?>">
		</fieldset>

		<!-- Section 5: Files -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '5. Files', 'fotonic' ); ?></legend>
			<div id="ftnc-files-list"></div>
			<p>
				<button type="button" id="ftnc-add-file" class="button">
					<?php esc_html_e( '+ Add File', 'fotonic' ); ?>
				</button>
			</p>
			<input type="hidden" id="ftnc_work_files_json" name="ftnc_work_files_json" value="<?php echo esc_attr( $files_json ); ?>">
		</fieldset>

		<!-- Section 6: Payments -->
		<fieldset style="<?php echo esc_attr( $fieldset_style ); ?>">
			<legend style="<?php echo esc_attr( $legend_style ); ?>"><?php esc_html_e( '6. Payments', 'fotonic' ); ?></legend>
			<table class="form-table">
				<tr>
					<th><label for="ftnc_total_price"><?php esc_html_e( 'Total Price (€)', 'fotonic' ); ?></label></th>
					<td><input type="number" id="ftnc_total_price" name="ftnc_total_price" value="<?php echo esc_attr( $total_price ); ?>" step="0.01" min="0" class="regular-text"></td>
				</tr>
			</table>
			<p><strong><?php esc_html_e( 'Installments', 'fotonic' ); ?></strong></p>
			<table class="widefat ftnc-work-table" id="ftnc-installments-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Title', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Amount (€)', 'fotonic' ); ?></th>
						<th><?php esc_html_e( 'Status', 'fotonic' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="ftnc-installments-rows"></tbody>
			</table>
			<p style="margin-top:8px;">
				<button type="button" id="ftnc-add-installment" class="button">
					<?php esc_html_e( '+ Add Installment', 'fotonic' ); ?>
				</button>
			</p>
			<input type="hidden" id="ftnc_installments_json" name="ftnc_installments_json" value="<?php echo esc_attr( $installments_json ); ?>">
		</fieldset>

		</div><!-- .ftnc-work-wrap -->

		<script>
		(function() {
			// --- Data ---
			var workServices  = <?php echo $services_json; ?>;
			var workFiles     = <?php echo $files_json; ?>;
			var installments    = <?php echo $installments_json; ?>;
			var eventAddresses  = <?php echo $event_addresses_json; ?>;
			var servicesMap     = <?php echo $services_map_json; ?>;

			function esc(v) {
				if (!v && v !== 0) return '';
				return String(v).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
			}
			function syncServices()     { document.getElementById('ftnc_work_services_json').value = JSON.stringify(workServices); }
			function syncFiles()        { document.getElementById('ftnc_work_files_json').value = JSON.stringify(workFiles); }
			function syncInstallments() { document.getElementById('ftnc_installments_json').value = JSON.stringify(installments); }
			function syncAddresses()    { document.getElementById('ftnc_event_addresses_json').value = JSON.stringify(eventAddresses); }

			// --- Addresses repeater ---
			function renderAddresses() {
				var tbody = document.getElementById('ftnc-addresses-rows');
				tbody.innerHTML = '';
				eventAddresses.forEach(function(addr, i) {
					var tr = document.createElement('tr');
					tr.innerHTML =
						'<td><input type="text" value="' + esc(addr.label) + '" data-addrfield="label" data-addridx="' + i + '" class="regular-text" placeholder="' + <?php echo wp_json_encode( __( 'e.g. Church', 'fotonic' ) ); ?> + '"></td>' +
						'<td><input type="text" value="' + esc(addr.street) + '" data-addrfield="street" data-addridx="' + i + '" class="large-text" placeholder="' + <?php echo wp_json_encode( __( 'Via Roma 1, Milano', 'fotonic' ) ); ?> + '"></td>' +
						'<td><button type="button" class="button-link ftnc-remove-address" data-addridx="' + i + '" style="color:#a00">' + <?php echo wp_json_encode( __( 'Remove', 'fotonic' ) ); ?> + '</button></td>';
					tbody.appendChild(tr);
				});
				document.querySelectorAll('#ftnc-addresses-rows input[data-addrfield]').forEach(function(el) {
					el.addEventListener('input', function() {
						var idx   = parseInt(this.dataset.addridx, 10);
						var field = this.dataset.addrfield;
						eventAddresses[idx][field] = this.value;
						syncAddresses();
					});
				});
				document.querySelectorAll('.ftnc-remove-address').forEach(function(el) {
					el.addEventListener('click', function() {
						eventAddresses.splice(parseInt(this.dataset.addridx, 10), 1);
						renderAddresses();
						syncAddresses();
					});
				});
			}

			document.getElementById('ftnc-add-address').addEventListener('click', function() {
				eventAddresses.push({ label: '', street: '' });
				renderAddresses();
				syncAddresses();
			});

			// --- Services repeater ---
			function renderServices() {
				var tbody = document.getElementById('ftnc-services-rows');
				tbody.innerHTML = '';
				workServices.forEach(function(s, i) {
					var title = servicesMap[s.service_id] ? esc(servicesMap[s.service_id].title) : '(#' + s.service_id + ')';
					var tr = document.createElement('tr');
					tr.innerHTML =
						'<td><strong>' + title + '</strong></td>' +
						'<td><input type="number" step="0.01" min="0" value="' + esc(s.price_override) + '" data-field="price_override" data-idx="' + i + '" class="small-text"></td>' +
						'<td><input type="text" value="' + esc(s.notes_override) + '" data-field="notes_override" data-idx="' + i + '" class="regular-text"></td>' +
						'<td><button type="button" class="button-link ftnc-remove-service" data-idx="' + i + '" style="color:#a00">' + <?php echo wp_json_encode( __( 'Remove', 'fotonic' ) ); ?> + '</button></td>';
					tbody.appendChild(tr);
				});
				attachServiceListeners();
			}

			function attachServiceListeners() {
				document.querySelectorAll('#ftnc-services-rows input[data-field]').forEach(function(el) {
					el.addEventListener('input', function() {
						var idx   = parseInt(this.dataset.idx, 10);
						var field = this.dataset.field;
						workServices[idx][field] = field === 'price_override' ? parseFloat(this.value) || 0 : this.value;
						syncServices();
					});
				});
				document.querySelectorAll('.ftnc-remove-service').forEach(function(el) {
					el.addEventListener('click', function() {
						workServices.splice(parseInt(this.dataset.idx, 10), 1);
						renderServices();
						syncServices();
					});
				});
			}

			document.getElementById('ftnc-add-service').addEventListener('click', function() {
				var picker = document.getElementById('ftnc-service-picker');
				var id = parseInt(picker.value, 10);
				if (!id) { return; }
				var basePrice = servicesMap[id] ? servicesMap[id].base_price : 0;
				workServices.push({ service_id: id, price_override: basePrice, notes_override: '' });
				renderServices();
				syncServices();
				picker.value = '';
			});

			// --- Files (wp.media) ---
			function renderFiles() {
				var container = document.getElementById('ftnc-files-list');
				container.innerHTML = '';
				workFiles.forEach(function(attachId, i) {
					var div = document.createElement('div');
					div.className = 'ftnc-file-row';
					div.innerHTML = '📎 ' + <?php echo wp_json_encode( __( 'Attachment ID', 'fotonic' ) ); ?> + ': <strong>' + esc(attachId) + '</strong> ' +
						'<button type="button" class="button-link ftnc-remove-file" data-idx="' + i + '" style="color:#a00">' + <?php echo wp_json_encode( __( 'Remove', 'fotonic' ) ); ?> + '</button>';
					container.appendChild(div);
				});
				document.querySelectorAll('.ftnc-remove-file').forEach(function(el) {
					el.addEventListener('click', function() {
						workFiles.splice(parseInt(this.dataset.idx, 10), 1);
						renderFiles();
						syncFiles();
					});
				});
			}

			document.getElementById('ftnc-add-file').addEventListener('click', function() {
				if (typeof wp === 'undefined' || !wp.media) {
					alert(<?php echo wp_json_encode( __( 'Media library not available.', 'fotonic' ) ); ?>);
					return;
				}
				var frame = wp.media({
					title:    <?php echo wp_json_encode( __( 'Select Files', 'fotonic' ) ); ?>,
					button:   { text: <?php echo wp_json_encode( __( 'Add to Work', 'fotonic' ) ); ?> },
					multiple: true,
				});
				frame.on('select', function() {
					var selection = frame.state().get('selection');
					selection.each(function(attachment) {
						if (workFiles.indexOf(attachment.id) === -1) {
							workFiles.push(attachment.id);
						}
					});
					renderFiles();
					syncFiles();
				});
				frame.open();
			});

			// --- Installments repeater ---
			function renderInstallments() {
				var tbody = document.getElementById('ftnc-installments-rows');
				tbody.innerHTML = '';
				installments.forEach(function(inst, i) {
					var isPaid   = inst.status === 'paid';
					var btnClass = isPaid ? 'ftnc-status-paid' : 'ftnc-status-unpaid';
					var btnLabel = isPaid ? <?php echo wp_json_encode( __( 'Paid', 'fotonic' ) ); ?> : <?php echo wp_json_encode( __( 'Unpaid', 'fotonic' ) ); ?>;
					var instType     = inst.type === 'coupon' ? 'coupon' : 'default';
					var typeLabel    = instType === 'coupon' ? <?php echo wp_json_encode( __( 'Coupon', 'fotonic' ) ); ?> : <?php echo wp_json_encode( __( 'Default', 'fotonic' ) ); ?>;
					var typeBtnClass = instType === 'coupon' ? 'ftnc-type-coupon' : 'ftnc-type-default';
					var tr = document.createElement('tr');
					tr.innerHTML =
						'<td><button type="button" class="ftnc-type-toggle ' + typeBtnClass + '" data-idx="' + i + '" style="border-radius:12px;padding:3px 10px;font-size:12px;border:none;cursor:pointer;">' + typeLabel + '</button></td>' +
						'<td><input type="text" value="' + esc(inst.title) + '" data-field="title" data-idx="' + i + '" class="regular-text"></td>' +
						'<td><input type="number" step="0.01" min="0" value="' + esc(inst.amount) + '" data-field="amount" data-idx="' + i + '" class="small-text"></td>' +
						'<td><button type="button" class="ftnc-status-toggle ' + btnClass + '" data-idx="' + i + '">' + btnLabel + '</button></td>' +
						'<td><button type="button" class="button-link ftnc-remove-installment" data-idx="' + i + '" style="color:#a00">' + <?php echo wp_json_encode( __( 'Remove', 'fotonic' ) ); ?> + '</button></td>';
					tbody.appendChild(tr);
				});
				attachInstallmentListeners();
			}

			function attachInstallmentListeners() {
				document.querySelectorAll('#ftnc-installments-rows input[data-field]').forEach(function(el) {
					el.addEventListener('input', function() {
						var idx   = parseInt(this.dataset.idx, 10);
						var field = this.dataset.field;
						installments[idx][field] = field === 'amount' ? parseFloat(this.value) || 0 : this.value;
						syncInstallments();
					});
				});
				document.querySelectorAll('.ftnc-status-toggle').forEach(function(el) {
					el.addEventListener('click', function() {
						var idx = parseInt(this.dataset.idx, 10);
						var current = installments[idx].status;
						installments[idx].status = current === 'paid' ? 'unpaid' : 'paid';
						renderInstallments();
						syncInstallments();
					});
				});
				document.querySelectorAll('.ftnc-type-toggle').forEach(function(el) {
					el.addEventListener('click', function() {
						var idx = parseInt(this.dataset.idx, 10);
						installments[idx].type = installments[idx].type === 'coupon' ? 'default' : 'coupon';
						renderInstallments();
						syncInstallments();
					});
				});
				document.querySelectorAll('.ftnc-remove-installment').forEach(function(el) {
					el.addEventListener('click', function() {
						installments.splice(parseInt(this.dataset.idx, 10), 1);
						renderInstallments();
						syncInstallments();
					});
				});
			}

			document.getElementById('ftnc-add-installment').addEventListener('click', function() {
				installments.push({ title: '', amount: 0, status: 'unpaid', type: 'default' });
				renderInstallments();
				syncInstallments();
			});

			// --- Init ---
			renderAddresses();
			renderServices();
			renderFiles();
			renderInstallments();
		})();
		</script>
		<?php
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
		if ( ! self::can_save( $post_id, $post, 'ftnc_customer_nonce', 'ftnc_customer_save' ) ) {
			return;
		}

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
				$first_name         = Fotonic_Crypto::encrypt( $first_name,         $vault_key );
				$last_name          = Fotonic_Crypto::encrypt( $last_name,          $vault_key );
				$nationality        = Fotonic_Crypto::encrypt( $nationality,        $vault_key );
				$instagram_username = Fotonic_Crypto::encrypt( $instagram_username, $vault_key );
				$address            = Fotonic_Crypto::encrypt( $address,            $vault_key );
				$tin                = Fotonic_Crypto::encrypt( $tin,                $vault_key );
				$email              = Fotonic_Crypto::deterministic_encrypt( $email, $vault_key );
				$phone              = Fotonic_Crypto::deterministic_encrypt( $phone, $vault_key );
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
		if ( ! self::can_save( $post_id, $post, 'ftnc_service_nonce', 'ftnc_service_save' ) ) {
			return;
		}

		if ( isset( $_POST['ftnc_base_price'] ) ) {
			$price = (float) $_POST['ftnc_base_price'];
			update_post_meta( $post_id, '_ftnc_base_price', $price >= 0 ? $price : 0 );
		}

		if ( isset( $_POST['ftnc_notes'] ) ) {
			update_post_meta( $post_id, '_ftnc_notes', wp_kses_post( wp_unslash( $_POST['ftnc_notes'] ) ) );
		}
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
		if ( ! self::can_save( $post_id, $post, 'ftnc_work_nonce', 'ftnc_work_save' ) ) {
			return;
		}

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

		// Section 3 — Owner.
		if ( isset( $_POST['ftnc_owner_id'] ) ) {
			update_post_meta( $post_id, '_ftnc_owner_type', 'admin' );
			update_post_meta( $post_id, '_ftnc_owner_id', (int) $_POST['ftnc_owner_id'] );
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
			$price = (float) $_POST['ftnc_total_price'];
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
				$status  = ( isset( $item['status'] ) && $item['status'] === 'paid' ) ? 'paid' : 'unpaid';
				$type    = ( isset( $item['type'] ) && $item['type'] === 'coupon' ) ? 'coupon' : 'default';
				$clean[] = [
					'title'  => sanitize_text_field( $item['title'] ?? '' ),
					'amount' => (float) ( $item['amount'] ?? 0 ),
					'status' => $status,
					'type'   => $type,
				];
			}
			update_post_meta( $post_id, '_ftnc_installments', wp_json_encode( $clean ) );
		}
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

		$search .= $wpdb->prepare(
			" OR ({$wpdb->postmeta}.meta_value LIKE %s)",
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

		$join .= " LEFT JOIN {$wpdb->postmeta} ON ({$wpdb->posts}.ID = {$wpdb->postmeta}.post_id)";

		return $join;
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	/**
	 * Common save-guard: nonce check, autosave check, capability check.
	 *
	 * @param int     $post_id    Post ID.
	 * @param WP_Post $post       Post object.
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
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		return true;
	}
}
