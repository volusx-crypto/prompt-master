<?php
/**
 * WordPress Admin Dashboard (Persian RTL) for AI Search Console Autopilot.
 *
 * @package AI_GSC_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AGSC_Admin {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_oauth_callback' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_agsc_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'wp_ajax_agsc_admin_action', array( __CLASS__, 'handle_ajax_action' ) );
	}

	/**
	 * Add top-level menu in WP Admin right beneath AI Site Commander.
	 */
	public static function register_menu() {
		add_menu_page(
			'اتوپایلوت سرچ کنسول',
			'اتوپایلوت سرچ کنسول',
			'manage_options',
			'ai-gsc-autopilot',
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-area',
			59
		);
	}

	/**
	 * Handle Google OAuth 2.0 callback when redirected back from accounts.google.com.
	 */
	public static function maybe_handle_oauth_callback() {
		if ( empty( $_GET['agsc_oauth_callback'] ) || empty( $_GET['code'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $state, 'agsc_oauth_state' ) ) {
			wp_die( 'خطای امنیتی OAuth State.' );
		}

		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res  = AGSC_Google_Client::exchange_oauth_code( $code );
		$flag = is_wp_error( $res ) ? 'oauth_error' : 'oauth_connected';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'ai-gsc-autopilot',
					'tab'  => 'settings',
					$flag  => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Load admin CSS/JS on plugin screen.
	 *
	 * @param string $hook Screen hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'ai-gsc-autopilot' ) ) {
			return;
		}

		wp_enqueue_style(
			'agsc-admin-style',
			AGSC_PLUGIN_URL . 'assets/admin.css',
			array(),
			AGSC_VERSION
		);

		wp_enqueue_script(
			'agsc-admin-script',
			AGSC_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			AGSC_VERSION,
			true
		);

		wp_localize_script(
			'agsc-admin-script',
			'agscAdminData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'agsc_admin_nonce' ),
			)
		);
	}

	/**
	 * Save Google Search Console & AI configuration settings.
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز.' );
		}
		check_admin_referer( 'agsc_save_settings_nonce' );

		$current = AGSC_DB::get_settings();
		$updated = array(
			'auth_method'           => ( isset( $_POST['auth_method'] ) && 'oauth2' === $_POST['auth_method'] ) ? 'oauth2' : 'service_account',
			'property_url'          => isset( $_POST['property_url'] ) ? AGSC_Google_Client::normalize_property_url( sanitize_text_field( wp_unslash( $_POST['property_url'] ) ) ) : home_url( '/' ),
			'oauth_client_id'       => isset( $_POST['oauth_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['oauth_client_id'] ) ) : '',
			'ai_provider'           => isset( $_POST['ai_provider'] ) && in_array( $_POST['ai_provider'], array( 'openai', 'anthropic', 'gemini', 'custom' ), true ) ? sanitize_key( wp_unslash( $_POST['ai_provider'] ) ) : 'openai',
			'ai_model'              => isset( $_POST['ai_model'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_model'] ) ) : 'gpt-4o',
			'ai_custom_base_url'    => isset( $_POST['ai_custom_base_url'] ) ? esc_url_raw( wp_unslash( $_POST['ai_custom_base_url'] ) ) : '',
			'autopilot_auto_fix'    => ! empty( $_POST['autopilot_auto_fix'] ) ? 1 : 0,
			'auto_fix_safe_types'   => $current['auto_fix_safe_types'],
			'inspect_urls_per_sync' => isset( $_POST['inspect_urls_per_sync'] ) ? min( 50, max( 1, absint( $_POST['inspect_urls_per_sync'] ) ) ) : 15,
			'standalone_api_key'    => $current['standalone_api_key'],
		);

		update_option( 'agsc_settings', $updated );

		// Save encrypted secrets only when non-empty values are entered.
		if ( ! empty( $_POST['service_account_json'] ) ) {
			$raw_sa = trim( wp_unslash( $_POST['service_account_json'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			AGSC_DB::save_secret( 'service_account_json', $raw_sa );
			delete_transient( 'agsc_google_access_token' );
		}

		if ( ! empty( $_POST['oauth_client_secret'] ) ) {
			AGSC_DB::save_secret( 'oauth_client_secret', sanitize_text_field( wp_unslash( $_POST['oauth_client_secret'] ) ) );
		}

		if ( ! empty( $_POST['ai_api_key'] ) ) {
			AGSC_DB::save_secret( 'ai_api_key', sanitize_text_field( wp_unslash( $_POST['ai_api_key'] ) ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'ai-gsc-autopilot',
					'tab'   => 'settings',
					'saved' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle admin AJAX actions: sync GSC, analyze issue, execute fix, run full autopilot, inspect URL, test Google connection.
	 */
	public static function handle_ajax_action() {
		check_ajax_referer( 'agsc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'شما مجوز مدیریت این بخش را ندارید.' ), 403 );
		}

		$sub = isset( $_POST['sub_action'] ) ? sanitize_key( wp_unslash( $_POST['sub_action'] ) ) : '';

		switch ( $sub ) {
			case 'sync_now':
				$res = AGSC_Issue_Detector::sync_and_detect();
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
				}
				wp_send_json_success( $res );
				break;

			case 'analyze_issue':
				$id  = isset( $_POST['issue_id'] ) ? absint( $_POST['issue_id'] ) : 0;
				$res = AGSC_AI_Analyzer::analyze_issue( $id );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
				}
				wp_send_json_success( $res );
				break;

			case 'execute_fix':
				$id  = isset( $_POST['issue_id'] ) ? absint( $_POST['issue_id'] ) : 0;
				$res = AGSC_Remediator::execute_issue_fix( $id );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
				}
				wp_send_json_success( $res );
				break;

			case 'run_autopilot':
				$sync  = AGSC_Issue_Detector::sync_and_detect();
				$batch = AGSC_Remediator::run_autopilot_batch( 5 );
				wp_send_json_success(
					array(
						'sync'      => is_wp_error( $sync ) ? $sync->get_error_message() : $sync,
						'autopilot' => $batch,
					)
				);
				break;

			case 'inspect_url':
				$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
				if ( '' === $url ) {
					wp_send_json_error( array( 'message' => 'آدرس URL معتبر وارد کنید.' ), 400 );
				}
				$req = new WP_REST_Request( 'POST' );
				$req->set_param( 'url', $url );
				$resp = AGSC_REST_API::handle_inspect_url( $req );
				if ( is_wp_error( $resp ) ) {
					wp_send_json_error( array( 'message' => $resp->get_error_message() ), 400 );
				}
				wp_send_json_success( $resp->get_data() );
				break;

			case 'test_connection':
				$sites = AGSC_Google_Client::list_sites();
				if ( is_wp_error( $sites ) ) {
					wp_send_json_error( array( 'message' => $sites->get_error_message() ), 400 );
				}
				wp_send_json_success( $sites );
				break;

			default:
				wp_send_json_error( array( 'message' => 'درخواست نامعتبر است.' ), 400 );
		}
	}

	/**
	 * Render tabbed Persian RTL dashboard.
	 */
	public static function render_page() {
		global $wpdb;

		$tab          = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings     = AGSC_DB::get_settings();
		$status_data  = AGSC_REST_API::handle_status()->get_data();
		$type_labels  = AGSC_Issue_Detector::issue_type_labels();
		$bridge_ready = AGSC_Remediator::is_commander_bridge_ready();

		$tabs = array(
			'overview'  => 'داشبورد سلامت و عملکرد',
			'issues'    => 'مشکلات شناسایی‌شده و اصلاح خودکار (' . (int) $status_data['issues_summary']['open'] . ')',
			'analytics' => 'فرصت‌های CTR و کلمات کلیدی',
			'inspector' => 'بررسی زنده URL در گوگل',
			'settings'  => 'تنظیمات اتصال گوگل و هوش مصنوعی',
		);

		$sa_json_saved = AGSC_DB::get_secret( 'service_account_json' );
		$sa_email      = '';
		if ( '' !== $sa_json_saved ) {
			$sa_parsed = json_decode( $sa_json_saved, true );
			$sa_email  = $sa_parsed['client_email'] ?? '';
		}
		?>
		<div class="wrap agsc-wrap" dir="rtl">
			<div class="agsc-header">
				<div>
					<h1>اتوپایلوت هوشمند سرچ کنسول <span class="agsc-badge">نسخه <?php echo esc_html( AGSC_VERSION ); ?></span></h1>
					<p>دریافت خودکار خطاها و فرصت‌های Google Search Console، تحلیل ریشه‌ای با هوش مصنوعی و اجرای مستقیم اصلاحات در سایت</p>
				</div>
				<div class="agsc-header-actions">
					<button type="button" class="button" id="agsc-btn-sync">همگام‌سازی فوری با سرچ کنسول</button>
					<button type="button" class="button button-primary" id="agsc-btn-autopilot">تحلیل و اصلاح خودکار (Autopilot)</button>
				</div>
			</div>

			<?php if ( ! $bridge_ready ) : ?>
				<div class="notice notice-warning">
					<p><strong>توجه:</strong> برای اینکه اصلاحات سرچ کنسول مستقیماً همراه با بکاپ خودکار روی سایت اعمال شوند، افزونه اول (<strong>فرمانده هوشمند سایت — AI Site Commander</strong>) نیز باید فعال باشد.</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>تنظیمات اتوپایلوت سرچ کنسول با موفقیت ذخیره شد.</p></div>
			<?php endif; ?>

			<?php if ( ! empty( $_GET['oauth_connected'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>حساب Google Search Console با موفقیت از طریق OAuth 2.0 متصل شد!</p></div>
			<?php endif; ?>

			<nav class="agsc-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ai-gsc-autopilot', 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
					   class="agsc-tab <?php echo $tab === $slug ? 'is-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="agsc-panel">
				<?php if ( 'overview' === $tab ) : ?>
					<div class="agsc-status-strip">
						<span>وضعیت سرچ کنسول: <strong><?php echo $status_data['google_connected'] ? 'متصل به ' . esc_html( $settings['property_url'] ) : 'در انتظار اتصال (اسکن محلی فعال)'; ?></strong></span>
						<span>پل فرمانده هوشمند سایت: <strong><?php echo $bridge_ready ? 'متصل و آماده اجرا' : 'غیرفعال'; ?></strong></span>
						<span>موتور تحلیلگر: <strong><?php echo esc_html( strtoupper( $settings['ai_provider'] ) . ' (' . $settings['ai_model'] . ')' ); ?></strong></span>
					</div>

					<div class="agsc-grid-4">
						<div class="agsc-stat-card">
							<div class="agsc-stat-title">کلیک‌های ۲۸ روز اخیر</div>
							<div class="agsc-stat-value"><?php echo esc_html( number_format_i18n( $status_data['performance_28d']['clicks'] ) ); ?></div>
							<div class="agsc-stat-sub">نرخ کلیک میانگین: <?php echo esc_html( $status_data['performance_28d']['avg_ctr_pct'] . '%' ); ?></div>
						</div>
						<div class="agsc-stat-card">
							<div class="agsc-stat-title">نمایش در نتایج گوگل (Impressions)</div>
							<div class="agsc-stat-value"><?php echo esc_html( number_format_i18n( $status_data['performance_28d']['impressions'] ) ); ?></div>
							<div class="agsc-stat-sub">جایگاه میانگین: <?php echo esc_html( (string) $status_data['performance_28d']['avg_position'] ); ?></div>
						</div>
						<div class="agsc-stat-card agsc-card-warn">
							<div class="agsc-stat-title">مشکلات باز نیازمند اقدام</div>
							<div class="agsc-stat-value"><?php echo esc_html( (string) $status_data['issues_summary']['open'] ); ?></div>
							<div class="agsc-stat-sub"><?php echo esc_html( $status_data['issues_summary']['critical'] . ' مورد بحرانی (Critical)' ); ?></div>
						</div>
						<div class="agsc-stat-card agsc-card-ok">
							<div class="agsc-stat-title">مشکلات حل‌شده در سایت</div>
							<div class="agsc-stat-value"><?php echo esc_html( (string) $status_data['issues_summary']['fixed'] ); ?></div>
							<div class="agsc-stat-sub">همراه با Snapshot قابل بازگشت</div>
						</div>
					</div>

					<h3>گزارش آخرین همگام‌سازی‌ها و عملیات اتوپایلوت</h3>
					<?php
					$logs_table = AGSC_DB::table( 'sync_logs' );
					$sync_logs  = $wpdb->get_results( "SELECT * FROM {$logs_table} ORDER BY id DESC LIMIT 12", ARRAY_A );
					?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>زمان</th>
								<th>نوع عملیات</th>
								<th>وضعیت</th>
								<th>مشکلات کشف‌شده</th>
								<th>اصلاحات اجراشده</th>
								<th>توضیحات</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $sync_logs ) ) : ?>
								<tr><td colspan="6">هنوز همگام‌سازی انجام نشده است. روی دکمه «همگام‌سازی فوری با سرچ کنسول» در بالا کلیک کنید.</td></tr>
							<?php else : ?>
								<?php foreach ( $sync_logs as $sl ) : ?>
									<tr>
										<td><?php echo esc_html( $sl['created_at'] ); ?></td>
										<td><code><?php echo esc_html( $sl['sync_type'] ); ?></code></td>
										<td><strong><?php echo esc_html( $sl['status'] ); ?></strong></td>
										<td><?php echo (int) $sl['issues_found']; ?></td>
										<td><?php echo (int) $sl['fixes_applied']; ?></td>
										<td><?php echo esc_html( $sl['message'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

				<?php elseif ( 'issues' === $tab ) : ?>
					<h3>مرکز تحلیل مشکلات سرچ کنسول و اجرای مستقیم اصلاحات</h3>
					<?php
					$issues_table = AGSC_DB::table( 'issues' );
					$issues       = $wpdb->get_results(
						"SELECT * FROM {$issues_table} ORDER BY FIELD(status, 'analyzed', 'open', 'queued_approval', 'fixed'), FIELD(severity, 'critical', 'high', 'medium', 'low'), id DESC LIMIT 80",
						ARRAY_A
					);
					?>
					<table class="widefat striped agsc-issues-table">
						<thead>
							<tr>
								<th>شدت</th>
								<th>نوع خطا / فرصت</th>
								<th>صفحه هدف و جزئیات</th>
								<th>تحلیل ریشه‌ای هوش مصنوعی و برنامه اصلاح</th>
								<th>وضعیت</th>
								<th>عملیات مستقیم</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $issues ) ) : ?>
								<tr><td colspan="6">هیچ مشکلی ثبت نشده است. روی «همگام‌سازی فوری با سرچ کنسول» کلیک کنید تا سایت بررسی شود.</td></tr>
							<?php else : ?>
								<?php foreach ( $issues as $iss ) : ?>
									<?php $plan = ! empty( $iss['fix_plan_json'] ) ? json_decode( $iss['fix_plan_json'], true ) : null; ?>
									<tr>
										<td><span class="agsc-sev agsc-sev-<?php echo esc_attr( $iss['severity'] ); ?>"><?php echo esc_html( strtoupper( $iss['severity'] ) ); ?></span></td>
										<td><strong><?php echo esc_html( $type_labels[ $iss['issue_type'] ] ?? $iss['issue_type'] ); ?></strong></td>
										<td>
											<div><?php echo esc_html( $iss['title'] ); ?></div>
											<a href="<?php echo esc_url( $iss['url'] ); ?>" target="_blank" dir="ltr" style="font-size:12px;"><?php echo esc_html( $iss['url'] ); ?></a>
										</td>
										<td>
											<?php if ( ! empty( $iss['ai_diagnosis'] ) ) : ?>
												<p style="margin:0 0 6px 0;"><?php echo esc_html( $iss['ai_diagnosis'] ); ?></p>
												<?php if ( ! empty( $plan['actions'] ) ) : ?>
													<details>
														<summary>مشاهده گام‌های اجرایی (<?php echo count( $plan['actions'] ); ?> اقدام)</summary>
														<pre dir="ltr" style="max-height:140px;overflow:auto;background:#f8fafc;padding:8px;font-size:11px;"><?php echo esc_html( wp_json_encode( $plan['actions'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></pre>
													</details>
												<?php endif; ?>
											<?php else : ?>
												<em style="color:#64748b;">هنوز تحلیل نشده — روی «تحلیل هوشمند» یا مستقیماً «اجرای اصلاح» کلیک کنید.</em>
											<?php endif; ?>
										</td>
										<td>
											<span class="agsc-st agsc-st-<?php echo esc_attr( $iss['status'] ); ?>"><?php echo esc_html( $iss['status'] ); ?></span>
											<?php if ( ! empty( $iss['snapshot_id'] ) ) : ?>
												<div style="font-size:11px;color:#475569;">اسنپ‌شات #<?php echo (int) $iss['snapshot_id']; ?></div>
											<?php endif; ?>
										</td>
										<td>
											<?php if ( 'fixed' !== $iss['status'] ) : ?>
												<button type="button" class="button button-small agsc-btn-analyze" data-id="<?php echo (int) $iss['id']; ?>">تحلیل هوشمند</button>
												<button type="button" class="button button-primary button-small agsc-btn-fix" data-id="<?php echo (int) $iss['id']; ?>">اجرای اصلاح در سایت</button>
											<?php else : ?>
												<span style="color:#15803d;font-weight:700;">اصلاح شد ✓</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

				<?php elseif ( 'analytics' === $tab ) : ?>
					<h3>صفحات برتر و مقایسه ۲۸ روزه در سرچ کنسول</h3>
					<?php
					$an_table = AGSC_DB::table( 'analytics' );
					$pages    = $wpdb->get_results( "SELECT * FROM {$an_table} WHERE row_type = 'page' ORDER BY impressions DESC LIMIT 40", ARRAY_A );
					?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>آدرس صفحه</th>
								<th>کلیک (۲۸ روز)</th>
								<th>کلیک دوره قبل</th>
								<th>نمایش (Impressions)</th>
								<th>CTR</th>
								<th>جایگاه میانگین</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $pages ) ) : ?>
								<tr><td colspan="6">داده‌ای در کش تحلیلی نیست؛ پس از اتصال گوگل دکمه همگام‌سازی را بزنید.</td></tr>
							<?php else : ?>
								<?php foreach ( $pages as $pg ) : ?>
									<tr>
										<td><a href="<?php echo esc_url( $pg['page_url'] ); ?>" target="_blank" dir="ltr"><?php echo esc_html( $pg['page_url'] ); ?></a></td>
										<td><strong><?php echo (int) $pg['clicks']; ?></strong></td>
										<td><?php echo (int) $pg['prev_clicks']; ?></td>
										<td><?php echo (int) $pg['impressions']; ?></td>
										<td><?php echo esc_html( round( (float) $pg['ctr'] * 100, 2 ) . '%' ); ?></td>
										<td><?php echo esc_html( (string) round( (float) $pg['position'], 1 ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

				<?php elseif ( 'inspector' === $tab ) : ?>
					<div class="agsc-box">
						<h3>ابزار بررسی زنده URL در ایندکس گوگل (Google URL Inspection API)</h3>
						<p>آدرس هر صفحه‌ای از سایت را وارد کنید تا وضعیت لحظه‌ای ایندکس گوگل، کنونیکال، ربات‌ها، اسکیما و وضعیت سئوی محلی وردپرس بررسی شود:</p>
						<div style="display:flex;gap:10px;max-width:780px;">
							<input type="url" id="agsc-inspect-input" class="large-text" dir="ltr" value="<?php echo esc_attr( home_url( '/' ) ); ?>" />
							<button type="button" class="button button-primary" id="agsc-btn-inspect">بررسی زنده URL</button>
						</div>
						<pre id="agsc-inspect-output" dir="ltr" style="display:none;margin-top:16px;background:#0f172a;color:#e2e8f0;padding:16px;border-radius:8px;overflow:auto;max-height:420px;"></pre>
					</div>

				<?php elseif ( 'settings' === $tab ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="agsc_save_settings" />
						<?php wp_nonce_field( 'agsc_save_settings_nonce' ); ?>

						<h3>۱. تنظیمات اتصال به Google Search Console</h3>
						<table class="form-table">
							<tr>
								<th scope="row">آدرس Property در سرچ کنسول</th>
								<td>
									<input type="text" name="property_url" class="regular-text" dir="ltr" value="<?php echo esc_attr( $settings['property_url'] ); ?>" placeholder="sc-domain:example.com یا https://example.com/" />
									<p class="description">دقیقاً مطابق عنوان Property در سرچ کنسول (مثلاً <code>https://yoursite.com/</code> یا برای Domain Property به شکل <code>sc-domain:yoursite.com</code>).</p>
								</td>
							</tr>
							<tr>
								<th scope="row">روش احراز هویت گوگل</th>
								<td>
									<select name="auth_method" id="agsc-auth-method">
										<option value="service_account" <?php selected( 'service_account', $settings['auth_method'] ); ?>>روش ۱ (پیشنهادی و بدون دردسر): کلید JSON اکانت سرویس گوگل (Service Account)</option>
										<option value="oauth2" <?php selected( 'oauth2', $settings['auth_method'] ); ?>>روش ۲: ورود با حساب شخصی گوگل (OAuth 2.0 Client ID & Secret)</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">محتوای فایل JSON اکانت سرویس (Service Account)</th>
								<td>
									<?php if ( '' !== $sa_email ) : ?>
										<p style="color:#15803d;"><strong>✓ متصل به اکانت سرویس:</strong> <code dir="ltr"><?php echo esc_html( $sa_email ); ?></code> (حتماً این ایمیل را در Search Console → Settings → Users and permissions اضافه کنید).</p>
									<?php endif; ?>
									<textarea name="service_account_json" rows="5" class="large-text code" dir="ltr" placeholder='{"type": "service_account", "project_id": "...", "private_key": "...", "client_email": "..."}'></textarea>
									<p class="description">کلید خصوصی با رمزنگاری AES-256 در دیتابیس ذخیره می‌شود. اگر قبلاً ذخیره کرده‌اید و قصد تغییر ندارید، خالی بگذارید.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">اطلاعات OAuth 2.0 (فقط در صورت انتخاب روش ۲)</th>
								<td>
									<p><input type="text" name="oauth_client_id" class="regular-text" dir="ltr" value="<?php echo esc_attr( $settings['oauth_client_id'] ); ?>" placeholder="Google OAuth Client ID" /></p>
									<p><input type="password" name="oauth_client_secret" class="regular-text" dir="ltr" value="" placeholder="Google OAuth Client Secret (در صورت تغییر وارد کنید)" /></p>
									<p class="description">آدرس Redirect URI برای ثبت در کنسول گوگل: <code dir="ltr"><?php echo esc_html( admin_url( 'admin.php?page=ai-gsc-autopilot&agsc_oauth_callback=1' ) ); ?></code></p>
									<?php if ( ! empty( $settings['oauth_client_id'] ) ) : ?>
										<p><a class="button" href="<?php echo esc_url( AGSC_Google_Client::get_oauth_authorize_url() ); ?>">اتصال به حساب گوگل (OAuth 2.0)</a></p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row">تست زنده اتصال به گوگل</th>
								<td>
									<button type="button" class="button" id="agsc-btn-test-conn">تست اتصال و مشاهده Propertyهای مجاز</button>
									<div id="agsc-test-conn-result" style="margin-top:8px;"></div>
								</td>
							</tr>
						</table>

						<hr />
						<h3>۲. تنظیمات هوش مصنوعی تحلیلگر و اتوپایلوت</h3>
						<table class="form-table">
							<tr>
								<th scope="row">سرویس هوش مصنوعی داخلی</th>
								<td>
									<select name="ai_provider">
										<option value="openai" <?php selected( 'openai', $settings['ai_provider'] ); ?>>OpenAI (ChatGPT / GPT-4o)</option>
										<option value="anthropic" <?php selected( 'anthropic', $settings['ai_provider'] ); ?>>Anthropic (Claude)</option>
										<option value="gemini" <?php selected( 'gemini', $settings['ai_provider'] ); ?>>Google Gemini</option>
										<option value="custom" <?php selected( 'custom', $settings['ai_provider'] ); ?>>OpenRouter / DeepSeek / آدرس سفارشی سازگار با OpenAI</option>
									</select>
									<p class="description">حتی بدون کلید هوش مصنوعی داخلی نیز موتور تشخیص هوشمند افزونه فعال است و ایجنت خارجی شما (از طریق MCP / API) می‌تواند مستقیماً تحلیل و اجرا کند.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">نام مدل (Model ID)</th>
								<td>
									<input type="text" name="ai_model" class="regular-text" dir="ltr" value="<?php echo esc_attr( $settings['ai_model'] ); ?>" placeholder="gpt-4o / claude-sonnet-4-5 / gemini-2.5-flash" />
								</td>
							</tr>
							<tr>
								<th scope="row">کلید API هوش مصنوعی</th>
								<td>
									<input type="password" name="ai_api_key" class="regular-text" dir="ltr" value="" placeholder="<?php echo '' !== AGSC_DB::get_secret( 'ai_api_key' ) ? '•••••••• (ذخیره شده به صورت رمزنگاری‌شده)' : 'sk-...'; ?>" />
								</td>
							</tr>
							<tr>
								<th scope="row">Base URL سفارشی (اختیاری)</th>
								<td>
									<input type="url" name="ai_custom_base_url" class="regular-text" dir="ltr" value="<?php echo esc_attr( $settings['ai_custom_base_url'] ); ?>" placeholder="https://openrouter.ai/api/v1" />
								</td>
							</tr>
							<tr>
								<th scope="row">اصلاح خودکار زمان‌بندی‌شده (Autopilot)</th>
								<td>
									<label>
										<input type="checkbox" name="autopilot_auto_fix" value="1" <?php checked( ! empty( $settings['autopilot_auto_fix'] ) ); ?> />
										هر ۶ ساعت پس از دریافت مشکلات سرچ کنسول، اصلاحات ایمن (متا تایتل/دیسکریپشن، ریدایرکت ۴۰۴، کنونیکال، رفع noindex و اسکیما) به‌صورت خودکار همراه با Snapshot روی سایت اجرا شوند.
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row">تعداد بررسی زنده URL در هر همگام‌سازی</th>
								<td>
									<input type="number" name="inspect_urls_per_sync" value="<?php echo (int) $settings['inspect_urls_per_sync']; ?>" min="1" max="50" />
								</td>
							</tr>
						</table>

						<?php submit_button( 'ذخیره تنظیمات اتوپایلوت سرچ کنسول' ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
