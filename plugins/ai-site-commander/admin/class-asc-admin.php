<?php
/**
 * WordPress Admin Dashboard (Persian RTL) for AI Site Commander.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_Admin {

	/**
	 * Register admin menu, assets, and AJAX handlers.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_post_asc_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'wp_ajax_asc_admin_action', array( __CLASS__, 'handle_ajax_action' ) );
	}

	/**
	 * Add top-level admin menu item in WordPress dashboard.
	 */
	public static function register_menu() {
		add_menu_page(
			'فرمانده هوشمند سایت',
			'فرمانده هوشمند',
			'manage_options',
			'ai-site-commander',
			array( __CLASS__, 'render_page' ),
			'dashicons-superhero-alt',
			58
		);
	}

	/**
	 * Enqueue admin stylesheet and JS only on our plugin page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'ai-site-commander' ) ) {
			return;
		}

		wp_enqueue_style(
			'asc-admin-style',
			ASC_PLUGIN_URL . 'assets/admin.css',
			array(),
			ASC_VERSION
		);

		wp_enqueue_script(
			'asc-admin-script',
			ASC_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			ASC_VERSION,
			true
		);

		wp_localize_script(
			'asc-admin-script',
			'ascAdminData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'asc_admin_nonce' ),
				'restUrl' => esc_url_raw( rest_url( ASC_REST_API::NAMESPACE_V1 ) ),
				'siteUrl' => esc_url_raw( home_url( '/' ) ),
			)
		);
	}

	/**
	 * Handle settings form submission.
	 */
	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'دسترسی غیرمجاز.' );
		}
		check_admin_referer( 'asc_save_settings_nonce' );

		$current = ASC_DB::get_settings();
		$updated = array(
			'execution_mode'       => ( isset( $_POST['execution_mode'] ) && 'approval' === $_POST['execution_mode'] ) ? 'approval' : 'auto',
			'rate_limit_per_min'   => isset( $_POST['rate_limit_per_min'] ) ? max( 10, absint( $_POST['rate_limit_per_min'] ) ) : 120,
			'require_hmac'         => ! empty( $_POST['require_hmac'] ) ? 1 : 0,
			'ip_allowlist'         => isset( $_POST['ip_allowlist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ip_allowlist'] ) ) : '',
			'enable_404_monitor'   => ! empty( $_POST['enable_404_monitor'] ) ? 1 : 0,
			'enable_redirects'     => ! empty( $_POST['enable_redirects'] ) ? 1 : 0,
			'enable_snippets'      => ! empty( $_POST['enable_snippets'] ) ? 1 : 0,
			'enable_theme_editor'  => ! empty( $_POST['enable_theme_editor'] ) ? 1 : 0,
			'enable_native_seo'     => ! empty( $_POST['enable_native_seo'] ) ? 1 : 0,
			'safe_mode_token'       => $current['safe_mode_token'],
			'custom_robots_append'  => isset( $_POST['custom_robots_append'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_robots_append'] ) ) : '',
			'enable_cloud_bridge'   => isset( $_POST['enable_cloud_bridge'] ) ? 1 : ( isset( $_POST['bridge_spreadsheet_id'] ) ? 0 : (int) $current['enable_cloud_bridge'] ),
			'bridge_spreadsheet_id' => isset( $_POST['bridge_spreadsheet_id'] ) ? sanitize_text_field( wp_unslash( $_POST['bridge_spreadsheet_id'] ) ) : $current['bridge_spreadsheet_id'],
		);

		update_option( 'asc_settings', $updated );
		wp_safe_redirect( add_query_arg( array( 'page' => 'ai-site-commander', 'tab' => 'settings', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle admin AJAX interactions (generate key, revoke key, rollback, approve/reject queue, add redirect, add snippet).
	 */
	public static function handle_ajax_action() {
		check_ajax_referer( 'asc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'شما مجوز مدیریت این بخش را ندارید.' ), 403 );
		}

		$sub_action = isset( $_POST['sub_action'] ) ? sanitize_key( wp_unslash( $_POST['sub_action'] ) ) : '';

		switch ( $sub_action ) {
			case 'create_key':
				$label  = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : 'AI Agent';
				$scopes = isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] )
					? array_map( 'sanitize_text_field', wp_unslash( $_POST['scopes'] ) )
					: array( '*' );
				$created = ASC_Security::create_api_key( $label, $scopes );
				wp_send_json_success( $created );
				break;

			case 'revoke_key':
				$key_id = isset( $_POST['key_id'] ) ? absint( $_POST['key_id'] ) : 0;
				ASC_Security::revoke_api_key( $key_id );
				wp_send_json_success( array( 'revoked' => $key_id ) );
				break;

			case 'rollback_snapshot':
				$snap_id = isset( $_POST['snapshot_id'] ) ? absint( $_POST['snapshot_id'] ) : 0;
				$res     = ASC_Snapshot::rollback( $snap_id, 'wp-admin:' . wp_get_current_user()->user_login );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
				}
				wp_send_json_success( $res );
				break;

			case 'queue_decision':
				$queue_id = isset( $_POST['queue_id'] ) ? absint( $_POST['queue_id'] ) : 0;
				$decision = ( isset( $_POST['decision'] ) && 'reject' === $_POST['decision'] ) ? 'reject' : 'approve';
				$res      = ASC_Engine::process_queue_item( $queue_id, $decision, wp_get_current_user()->user_login );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
				}
				wp_send_json_success( $res );
				break;

			case 'save_redirect':
				$res = ASC_Redirect_404::save_redirect(
					array(
						'source_path' => isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '',
						'target_url'  => isset( $_POST['target_url'] ) ? sanitize_text_field( wp_unslash( $_POST['target_url'] ) ) : '',
						'status_code' => isset( $_POST['status_code'] ) ? absint( $_POST['status_code'] ) : 301,
						'note'        => 'ایجاد شده از پنل مدیریت',
					),
					'wp-admin'
				);
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
				}
				wp_send_json_success( $res );
				break;

			case 'delete_redirect':
				$rid = isset( $_POST['redirect_id'] ) ? absint( $_POST['redirect_id'] ) : 0;
				$res = ASC_Redirect_404::delete_redirect( $rid, 'wp-admin' );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
				}
				wp_send_json_success( $res );
				break;

			case 'save_snippet':
				$res = ASC_Code_Files::save_snippet(
					array(
						'id'           => isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0,
						'title'        => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
						'snippet_type' => isset( $_POST['snippet_type'] ) ? sanitize_key( wp_unslash( $_POST['snippet_type'] ) ) : 'css',
						'code'         => isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
						'is_active'    => isset( $_POST['is_active'] ) ? absint( $_POST['is_active'] ) : 1,
					),
					'wp-admin'
				);
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
				}
				wp_send_json_success( $res );
				break;

			case 'configure_autolinks':
				$res = ASC_Plugin_Inspector::auto_configure_autolinks_manager( 'wp-admin:' . wp_get_current_user()->user_login, true );
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
				}
				wp_send_json_success( $res );
				break;

			case 'sync_cloud_bridge':
				$res = ASC_Cloud_Bridge::sync_now();
				if ( is_wp_error( $res ) ) {
					wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
				}
				wp_send_json_success( $res );
				break;

			default:
				wp_send_json_error( array( 'message' => 'عملیات نامعتبر است.' ), 400 );
		}
	}

	/**
	 * Render complete tabbed admin interface.
	 */
	public static function render_page() {
		global $wpdb;

		$tab       = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status    = ASC_Engine::get_site_status();
		$settings  = ASC_DB::get_settings();
		$api_keys  = ASC_Security::list_api_keys();
		$snapshots = ASC_Snapshot::list_snapshots( 40, 0 );

		$tabs = array(
			'overview'  => 'پیشخوان و وضعیت اتصال',
			'conflicts' => 'تداخل‌سنج و Autolinks Manager',
			'keys'      => 'کلیدهای API و اتصال هوش مصنوعی',
			'queue'     => 'صف تایید تغییرات (' . (int) $status['counts']['pending_queue'] . ')',
			'snapshots' => 'پشتیبان‌ها و بازگشت (Rollback)',
			'redirects' => 'ریدایرکت‌ها و خطاهای ۴۰۴',
			'snippets'  => 'کدهای سفارشی و حالت امن',
			'settings'  => 'تنظیمات امنیتی و دسترسی',
		);
		?>
		<div class="wrap asc-wrap" dir="rtl">
			<div class="asc-header">
				<div>
					<h1>فرمانده هوشمند سایت <span class="asc-badge">نسخه <?php echo esc_html( ASC_VERSION ); ?></span></h1>
					<p>کنترل مستقیم و امن محتوا، سئو، ووکامرس، ریدایرکت‌ها و کدها توسط هوش مصنوعی — همراه با پشتیبان‌گیری خودکار پیش از هر تغییر</p>
				</div>
				<div class="asc-header-status">
					<span class="asc-pill <?php echo 'auto' === $settings['execution_mode'] ? 'asc-pill-green' : 'asc-pill-amber'; ?>">
						حالت اجرا: <?php echo 'auto' === $settings['execution_mode'] ? 'مستقیم + بکاپ خودکار' : 'نیازمند تایید مدیر'; ?>
					</span>
					<span class="asc-pill <?php echo $status['gsc_plugin_active'] ? 'asc-pill-green' : 'asc-pill-gray'; ?>">
						پل سرچ کنسول: <?php echo $status['gsc_plugin_active'] ? 'متصل و فعال' : 'نصب نشده / غیرفعال'; ?>
					</span>
				</div>
			</div>

			<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>تنظیمات فرمانده هوشمند سایت با موفقیت ذخیره شد.</p></div>
			<?php endif; ?>

			<nav class="asc-tabs">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'ai-site-commander', 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
					   class="asc-tab <?php echo $tab === $slug ? 'is-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="asc-panel">
				<?php if ( 'overview' === $tab ) : ?>
					<div class="asc-grid-4">
						<div class="asc-stat-card">
							<div class="asc-stat-title">محتوای منتشر شده</div>
							<div class="asc-stat-value"><?php echo esc_html( (string) ( $status['counts']['published_posts'] + $status['counts']['published_pages'] ) ); ?></div>
							<div class="asc-stat-sub"><?php echo esc_html( $status['counts']['published_posts'] . ' نوشته / ' . $status['counts']['published_pages'] . ' برگه' ); ?></div>
						</div>
						<div class="asc-stat-card">
							<div class="asc-stat-title">موتور سئوی شناسایی‌شده</div>
							<div class="asc-stat-value"><?php echo esc_html( strtoupper( $status['seo_provider'] ) ); ?></div>
							<div class="asc-stat-sub"><?php echo $status['woocommerce_active'] ? 'ووکامرس: فعال' : 'ووکامرس: غیرفعال'; ?></div>
						</div>
						<div class="asc-stat-card">
							<div class="asc-stat-title">نسخه‌های پشتیبان (Snapshots)</div>
							<div class="asc-stat-value"><?php echo esc_html( (string) $status['counts']['snapshots'] ); ?></div>
							<div class="asc-stat-sub">امکان بازگشت یک‌کلیکی تمام تغییرات</div>
						</div>
						<div class="asc-stat-card">
							<div class="asc-stat-title">ریدایرکت‌ها / خطاهای ۴۰۴</div>
							<div class="asc-stat-value"><?php echo esc_html( $status['counts']['redirects'] . ' / ' . $status['counts']['unresolved_404s'] ); ?></div>
							<div class="asc-stat-sub">ریدایرکت فعال / ۴۰۴ حل‌نشده</div>
						</div>
					</div>

					<?php
					$bridge_status = get_option( 'asc_bridge_status', array() );
					$sa_email      = ASC_Cloud_Bridge::get_service_account_email();
					$sheet_id      = ASC_Cloud_Bridge::get_spreadsheet_id();
					?>
					<div class="asc-box" style="margin:18px 0;border-right:4px solid #2563eb;">
						<h3 style="margin-top:0;">پل ارتباطی زنده دوطرفه با چت هوش مصنوعی (Volusx AI Live Bridge)</h3>
						<p style="margin:6px 0;">
							این پل به هوش مصنوعی اجازه می‌دهد دستورات سئو، لینک‌سازی، اصلاح سرچ کنسول و مدیریت سایت را مستقیماً روی <code>volusx.com</code> اجرا کند و گزارش زنده را بخواند.
						</p>
						<p style="margin:6px 0;">
							<strong>گوگل شیت متصل:</strong>
							<a href="<?php echo esc_url( 'https://docs.google.com/spreadsheets/d/' . $sheet_id . '/edit' ); ?>" target="_blank" rel="noopener noreferrer" dir="ltr">
								<code><?php echo esc_html( $sheet_id ); ?></code>
							</a>
						</p>
						<?php if ( '' !== $sa_email ) : ?>
							<p style="margin:6px 0;">
								<strong>ایمیل اکانت سرویس شناسایی‌شده از سایت شما:</strong>
								<input type="text" readonly class="regular-text code" dir="ltr" value="<?php echo esc_attr( $sa_email ); ?>" onclick="this.select();" style="width:380px;" />
								<span style="font-size:12px;color:#475569;">(کافی است این ایمیل را در چت بفرستید تا گوگل شیت به صورت خودکار با آن به اشتراک گذاشته شود!)</span>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $bridge_status['message'] ) ) : ?>
							<p style="margin:6px 0;font-weight:700;color:<?php echo ! empty( $bridge_status['ok'] ) ? '#15803d' : '#b45309'; ?>;">
								آخرین وضعیت پل ابری: <?php echo esc_html( $bridge_status['message'] ); ?>
							</p>
						<?php endif; ?>
						<p style="margin:10px 0 0;">
							<button type="button" class="button button-primary" id="asc-btn-sync-bridge">همگام‌سازی فوری با چت هوش مصنوعی (Sync Now)</button>
						</p>
						<div id="asc-bridge-sync-result" style="margin-top:8px;"></div>
					</div>

					<h3>آخرین عملیات ثبت‌شده (Audit Log)</h3>
					<?php
					$audit_table = ASC_DB::table( 'audit_logs' );
					$logs        = $wpdb->get_results( "SELECT * FROM {$audit_table} ORDER BY id DESC LIMIT 15", ARRAY_A );
					?>
					<table class="widefat striped asc-table">
						<thead>
							<tr>
								<th>زمان</th>
								<th>عامل (Actor)</th>
								<th>عملیات</th>
								<th>هدف</th>
								<th>وضعیت</th>
								<th>توضیحات</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $logs ) ) : ?>
								<tr><td colspan="6">هنوز هیچ عملیاتی ثبت نشده است. از تب «کلیدهای API» یک کلید بسازید تا هوش مصنوعی متصل شود.</td></tr>
							<?php else : ?>
								<?php foreach ( $logs as $log ) : ?>
									<tr>
										<td><?php echo esc_html( $log['created_at'] ); ?></td>
										<td><code><?php echo esc_html( $log['actor'] ); ?></code></td>
										<td><code><?php echo esc_html( $log['action_type'] ); ?></code></td>
										<td><?php echo esc_html( $log['target'] ); ?></td>
										<td><span class="asc-status-<?php echo esc_attr( $log['status'] ); ?>"><?php echo esc_html( $log['status'] ); ?></span></td>
										<td><?php echo esc_html( $log['message'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

				<?php elseif ( 'keys' === $tab ) : ?>
					<div class="asc-two-col">
						<div class="asc-box">
							<h3>ایجاد کلید API جدید برای هوش مصنوعی</h3>
							<p>این کلید به ایجنت هوش مصنوعی یا سرور MCP اجازه می‌دهد مستقیماً به سایت شما متصل شود و تغییرات را اعمال کند.</p>
							<p>
								<label><strong>عنوان کلید (مثلاً Claude / MCP Agent):</strong></label><br>
								<input type="text" id="asc-new-key-label" class="regular-text" value="Main AI Agent" />
							</p>
							<p><strong>سطح دسترسی (Scopes):</strong></p>
							<div class="asc-scopes-list">
								<?php foreach ( ASC_Security::available_scopes() as $scope_key => $scope_desc ) : ?>
									<label style="display:block;margin-bottom:6px;">
										<input type="checkbox" class="asc-scope-cb" value="<?php echo esc_attr( $scope_key ); ?>" <?php checked( '*' === $scope_key ); ?> />
										<code><?php echo esc_html( $scope_key ); ?></code> — <?php echo esc_html( $scope_desc ); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<p style="margin-top:14px;">
								<button type="button" class="button button-primary" id="asc-btn-create-key">ساخت کلید اتصال جدید</button>
							</p>
							<div id="asc-created-key-box" class="asc-secret-reveal" style="display:none;">
								<strong>کلید API شما ساخته شد (فقط همین یک‌بار نمایش داده می‌شود — کپی کنید):</strong>
								<input type="text" readonly id="asc-raw-api-key" class="large-text code" style="margin-top:8px;direction:ltr;" />
								<p style="margin-top:8px;"><strong>آدرس پایه REST API سایت شما:</strong></p>
								<code style="direction:ltr;display:block;padding:8px;background:#fff;"><?php echo esc_html( rest_url( ASC_REST_API::NAMESPACE_V1 ) ); ?></code>
							</div>
						</div>

						<div class="asc-box">
							<h3>تنظیمات آماده اتصال MCP (برای Claude / Cursor / Windsurf)</h3>
							<p>برای اتصال مستقیم هوش مصنوعی به هر دو افزونه سایت و سرچ کنسول، کافی است فایل <code>mcp-server/index.mjs</code> را در تنظیمات MCP قرار دهید:</p>
							<pre class="asc-code-block" dir="ltr">{
  "mcpServers": {
    "wordpress-commander": {
      "command": "node",
      "args": ["/path/to/mcp-server/index.mjs"],
      "env": {
        "WP_SITE_URL": "<?php echo esc_js( untrailingslashit( home_url() ) ); ?>",
        "ASC_API_KEY": "asc_live_YOUR_API_KEY_HERE"
      }
    }
  }
}</pre>
						</div>
					</div>

					<h3 style="margin-top:24px;">کلیدهای فعال</h3>
					<table class="widefat striped asc-table">
						<thead>
							<tr>
								<th>عنوان</th>
								<th>پیشوند کلید</th>
								<th>اسکوپ‌ها</th>
								<th>آخرین استفاده</th>
								<th>آخرین IP</th>
								<th>عملیات</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $api_keys ) ) : ?>
								<tr><td colspan="6">هنوز کلیدی نساخته‌اید.</td></tr>
							<?php else : ?>
								<?php foreach ( $api_keys as $k ) : ?>
									<tr>
										<td><strong><?php echo esc_html( $k['label'] ); ?></strong></td>
										<td><code dir="ltr"><?php echo esc_html( $k['key_prefix'] ); ?>...</code></td>
										<td><code><?php echo esc_html( implode( ', ', $k['scopes'] ) ); ?></code></td>
										<td><?php echo esc_html( $k['last_used_at'] ? $k['last_used_at'] : '—' ); ?></td>
										<td><code dir="ltr"><?php echo esc_html( $k['last_ip'] ? $k['last_ip'] : '—' ); ?></code></td>
										<td>
											<button type="button" class="button asc-btn-revoke-key" data-id="<?php echo (int) $k['id']; ?>">لغو و حذف</button>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

				<?php elseif ( 'queue' === $tab ) : ?>
					<h3>صف تایید تغییرات هوش مصنوعی</h3>
					<?php
					$queue_table = ASC_DB::table( 'queue' );
					$queue_items = $wpdb->get_results( "SELECT * FROM {$queue_table} ORDER BY id DESC LIMIT 50", ARRAY_A );
					?>
					<table class="widefat striped asc-table">
						<thead>
							<tr>
								<th>#</th>
								<th>عملیات</th>
								<th>توضیح تغییر</th>
								<th>درخواست‌کننده</th>
								<th>وضعیت</th>
								<th>اقدام</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $queue_items ) ) : ?>
								<tr><td colspan="6">هیچ تغییری در انتظار تایید نیست.</td></tr>
							<?php else : ?>
								<?php foreach ( $queue_items as $q ) : ?>
									<tr>
										<td>#<?php echo (int) $q['id']; ?></td>
										<td><code><?php echo esc_html( $q['action_type'] ); ?></code></td>
										<td>
											<strong><?php echo esc_html( $q['summary'] ); ?></strong>
											<details style="margin-top:4px;">
												<summary>مشاهده جزئیات فنی JSON</summary>
												<pre dir="ltr" style="max-height:140px;overflow:auto;background:#f6f7f7;padding:8px;"><?php echo esc_html( $q['payload'] ); ?></pre>
											</details>
										</td>
										<td><code><?php echo esc_html( $q['actor'] ); ?></code></td>
										<td><?php echo esc_html( $q['status'] ); ?></td>
										<td>
											<?php if ( 'pending' === $q['status'] ) : ?>
												<button type="button" class="button button-primary asc-btn-queue" data-id="<?php echo (int) $q['id']; ?>" data-decision="approve">تایید و اجرا</button>
												<button type="button" class="button asc-btn-queue" data-id="<?php echo (int) $q['id']; ?>" data-decision="reject">رد کردن</button>
											<?php else : ?>
												<span>بررسی شده</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

				<?php elseif ( 'snapshots' === $tab ) : ?>
					<h3>پشتیبان‌های خودکار و بازگشت یک‌کلیکی (Rollback)</h3>
					<p>قبل از هر تغییری که هوش مصنوعی یا افزونه سرچ کنسول روی سایت اعمال کند، وضعیت قبلی به‌صورت کامل ذخیره می‌شود تا در صورت نیاز با یک کلیک به حالت قبل برگردید.</p>
					<table class="widefat striped asc-table">
						<thead>
							<tr>
								<th>شناسه</th>
								<th>زمان</th>
								<th>نوع تغییر</th>
								<th>بخش هدف</th>
								<th>دلیل تغییر</th>
								<th>وضعیت / بازگردانی</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $snapshots ) ) : ?>
								<tr><td colspan="6">هنوز هیچ اسنپ‌شاتی ثبت نشده است.</td></tr>
							<?php else : ?>
								<?php foreach ( $snapshots as $snap ) : ?>
									<tr>
										<td>#<?php echo (int) $snap['id']; ?></td>
										<td><?php echo esc_html( $snap['created_at'] ); ?></td>
										<td><code><?php echo esc_html( $snap['action_type'] ); ?></code></td>
										<td><strong><?php echo esc_html( $snap['target_label'] ); ?></strong></td>
										<td><?php echo esc_html( (string) $snap['reason'] ); ?></td>
										<td>
											<?php if ( ! empty( $snap['rolled_back'] ) ) : ?>
												<span class="asc-pill asc-pill-gray">بازگردانی شده در <?php echo esc_html( (string) $snap['rolled_back_at'] ); ?></span>
											<?php else : ?>
												<button type="button" class="button asc-btn-rollback" data-id="<?php echo (int) $snap['id']; ?>">بازگشت به حالت قبل (Rollback)</button>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

				<?php elseif ( 'redirects' === $tab ) : ?>
					<div class="asc-two-col">
						<div class="asc-box">
							<h3>افزودن ریدایرکت جدید (301 / 302 / 410)</h3>
							<p>
								<label>مسیر مبدا (مثلاً <code>/old-post</code>):</label><br>
								<input type="text" id="asc-redir-source" class="regular-text" dir="ltr" placeholder="/old-page" />
							</p>
							<p>
								<label>آدرس مقصد (برای 410 خالی بگذارید):</label><br>
								<input type="text" id="asc-redir-target" class="regular-text" dir="ltr" placeholder="/new-page" />
							</p>
							<p>
								<label>نوع کد وضعیت:</label><br>
								<select id="asc-redir-code">
									<option value="301">301 — انتقال دائمی (بهترین برای سئو)</option>
									<option value="302">302 — انتقال موقت</option>
									<option value="410">410 — حذف دائمی محتوا (Gone)</option>
								</select>
							</p>
							<button type="button" class="button button-primary" id="asc-btn-save-redirect">ثبت ریدایرکت</button>
						</div>

						<div class="asc-box">
							<h3>پرتکرارترین خطاهای ۴۰۴ شناسایی‌شده</h3>
							<?php $logs_404 = ASC_Redirect_404::list_404_logs( true, 10 ); ?>
							<?php if ( empty( $logs_404 ) ) : ?>
								<p>هیچ خطای ۴۰۴ حل‌نشده‌ای ثبت نشده است.</p>
							<?php else : ?>
								<table class="widefat striped">
									<thead><tr><th>مسیر ۴۰۴</th><th>تعداد برخورد</th><th>عمل</th></tr></thead>
									<tbody>
										<?php foreach ( $logs_404 as $err ) : ?>
											<tr>
												<td><code dir="ltr"><?php echo esc_html( $err['request_path'] ); ?></code></td>
												<td><?php echo (int) $err['hits']; ?></td>
												<td><button type="button" class="button button-small asc-fill-404" data-path="<?php echo esc_attr( $err['request_path'] ); ?>">ریدایرکت کن</button></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
					</div>

					<h3 style="margin-top:24px;">لیست ریدایرکت‌های فعال</h3>
					<?php $redirects = ASC_Redirect_404::list_redirects( 100 ); ?>
					<table class="widefat striped asc-table">
						<thead>
							<tr>
								<th>مسیر مبدا</th>
								<th>مقصد</th>
								<th>کد</th>
								<th>تعداد ارجاع</th>
								<th>یادداشت</th>
								<th>حذف</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $redirects ) ) : ?>
								<tr><td colspan="6">هنوز قانونی ثبت نشده است.</td></tr>
							<?php else : ?>
								<?php foreach ( $redirects as $r ) : ?>
									<tr>
										<td><code dir="ltr"><?php echo esc_html( $r['source_path'] ); ?></code></td>
										<td><code dir="ltr"><?php echo esc_html( $r['target_url'] ? $r['target_url'] : '(410 Gone)' ); ?></code></td>
										<td><strong><?php echo (int) $r['status_code']; ?></strong></td>
										<td><?php echo (int) $r['hits']; ?></td>
										<td><?php echo esc_html( $r['note'] ); ?></td>
										<td><button type="button" class="button button-small asc-btn-del-redirect" data-id="<?php echo (int) $r['id']; ?>">حذف</button></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

				<?php elseif ( 'snippets' === $tab ) : ?>
					<div class="asc-box" style="margin-bottom:20px;">
						<h3>لینک اضطراری حالت امن (Safe Mode)</h3>
						<p>اگر کد سفارشی PHP باعث خطا شود، افزونه به‌طور خودکار آن قطعه کد را غیرفعال می‌کند. همچنین با این لینک اضطراری می‌توانید اجرای تمام کدها را متوقف کنید:</p>
						<code dir="ltr" style="display:block;padding:8px;background:#fff;"><?php echo esc_html( add_query_arg( 'asc_safe_mode', $settings['safe_mode_token'], admin_url( 'admin.php?page=ai-site-commander&tab=snippets' ) ) ); ?></code>
					</div>

					<div class="asc-box" style="margin-bottom:20px;">
						<h3>افزودن قطعه کد جدید (CSS / JS / PHP / HTML Head)</h3>
						<p>
							<input type="text" id="asc-snippet-title" class="regular-text" placeholder="عنوان قطعه کد (مثلاً اصلاح استایل هدر)" />
							<select id="asc-snippet-type">
								<option value="css">CSS (استایل ظاهری)</option>
								<option value="js">JavaScript (فوتر)</option>
								<option value="html_head">HTML / تگ‌های هدر (Head)</option>
								<option value="php">PHP (همراه با محافظ خطای خودکار)</option>
							</select>
						</p>
						<p>
							<textarea id="asc-snippet-code" rows="6" class="large-text code" dir="ltr" placeholder="/* Write your code here */"></textarea>
						</p>
						<button type="button" class="button button-primary" id="asc-btn-save-snippet">ذخیره و فعال‌سازی قطعه کد</button>
					</div>

					<?php $snippets = ASC_Code_Files::list_snippets(); ?>
					<table class="widefat striped asc-table">
						<thead>
							<tr>
								<th>#</th>
								<th>عنوان</th>
								<th>نوع</th>
								<th>وضعیت</th>
								<th>خطای ثبت‌شده</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $snippets ) ) : ?>
								<tr><td colspan="5">هنوز قطعه کدی ثبت نشده است.</td></tr>
							<?php else : ?>
								<?php foreach ( $snippets as $sn ) : ?>
									<tr>
										<td>#<?php echo (int) $sn['id']; ?></td>
										<td><strong><?php echo esc_html( $sn['title'] ); ?></strong></td>
										<td><code><?php echo esc_html( strtoupper( $sn['snippet_type'] ) ); ?></code></td>
										<td><?php echo ! empty( $sn['is_active'] ) ? 'فعال' : 'غیرفعال'; ?></td>
										<td><?php echo esc_html( $sn['last_error'] ? $sn['last_error'] : '—' ); ?></td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>

				<?php elseif ( 'settings' === $tab ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="asc_save_settings" />
						<?php wp_nonce_field( 'asc_save_settings_nonce' ); ?>

						<table class="form-table">
							<tr>
								<th scope="row">حالت اجرای تغییرات هوش مصنوعی</th>
								<td>
									<select name="execution_mode">
										<option value="auto" <?php selected( 'auto', $settings['execution_mode'] ); ?>>اجرای مستقیم و فوری (همراه با Snapshot خودکار برای بازگشت یک‌کلیکی)</option>
										<option value="approval" <?php selected( 'approval', $settings['execution_mode'] ); ?>>مرحله‌ای — قرار گرفتن در صف تایید مدیر سایت</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row">محدودیت نرخ درخواست (در دقیقه)</th>
								<td>
									<input type="number" name="rate_limit_per_min" value="<?php echo (int) $settings['rate_limit_per_min']; ?>" min="10" max="1000" />
								</td>
							</tr>
							<tr>
								<th scope="row">لیست سفید IP (اختیاری)</th>
								<td>
									<textarea name="ip_allowlist" rows="3" class="regular-text" dir="ltr" placeholder="مثلاً هر خط یک IP — اگر خالی باشد از هر IP با کلید API معتبر می‌پذیرد"><?php echo esc_textarea( $settings['ip_allowlist'] ); ?></textarea>
								</td>
							</tr>
							<tr>
								<th scope="row">ماژول‌های فعال</th>
								<td>
									<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="enable_redirects" value="1" <?php checked( ! empty( $settings['enable_redirects'] ) ); ?> /> فعال‌سازی موتور ریدایرکت‌های ۳۰۱/۴۱۰</label>
									<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="enable_404_monitor" value="1" <?php checked( ! empty( $settings['enable_404_monitor'] ) ); ?> /> فعال‌سازی رصدگر خودکار خطاهای ۴۰۴</label>
									<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="enable_snippets" value="1" <?php checked( ! empty( $settings['enable_snippets'] ) ); ?> /> فعال‌سازی اجرای کدهای سفارشی CSS/JS/PHP</label>
									<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="enable_theme_editor" value="1" <?php checked( ! empty( $settings['enable_theme_editor'] ) ); ?> /> اجازه ویرایش ایمن فایل‌های قالب فعال (همراه با Snapshot فایل)</label>
									<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="enable_native_seo" value="1" <?php checked( ! empty( $settings['enable_native_seo'] ) ); ?> /> متاتگ‌های بومی سئو (در صورت نصب نبودن Rank Math یا Yoast)</label>
								</td>
							</tr>
							<tr>
								<th scope="row">پل ارتباطی ابری (Google Sheets Bridge)</th>
								<td>
									<label style="display:block;margin-bottom:8px;">
										<input type="checkbox" name="enable_cloud_bridge" value="1" <?php checked( ! empty( $settings['enable_cloud_bridge'] ) ); ?> />
										فعال‌سازی اجرای خودکار دستورات چت هوش مصنوعی از طریق Google Sheets
									</label>
									<input type="text" name="bridge_spreadsheet_id" value="<?php echo esc_attr( $settings['bridge_spreadsheet_id'] ); ?>" class="regular-text code" dir="ltr" placeholder="Spreadsheet ID" />
								</td>
							</tr>
							<tr>
								<th scope="row">قوانین سفارشی انتهای <code>robots.txt</code></th>
								<td>
									<textarea name="custom_robots_append" rows="4" class="large-text code" dir="ltr" placeholder="Disallow: /search/"><?php echo esc_textarea( $settings['custom_robots_append'] ); ?></textarea>
								</td>
							</tr>
						</table>

						<?php submit_button( 'ذخیره تنظیمات فرمانده هوشمند سایت' ); ?>
					</form>

				<?php elseif ( 'conflicts' === $tab ) : ?>
					<?php $audit = ASC_Plugin_Inspector::audit_conflicts(); ?>
					<div class="asc-two-col">
						<div class="asc-box">
							<h3>وضعیت افزونه Autolinks Manager و بررسی تداخل</h3>
							<?php if ( ! empty( $audit['autolinks_detected'] ) ) : ?>
								<p style="color:#15803d;font-weight:700;">
									✓ افزونه شناسایی شد: <?php echo esc_html( $audit['autolinks_detected']['name'] . ' (v' . $audit['autolinks_detected']['version'] . ')' ); ?>
								</p>
							<?php else : ?>
								<p style="color:#b45309;font-weight:700;">
									⚠ افزونه Autolinks Manager در لیست افزونه‌های فعال یافت نشد (تعداد گزینه‌های دیتابیس شناسایی‌شده: <?php echo (int) $audit['daam_options_found']; ?>).
								</p>
							<?php endif; ?>

							<?php if ( empty( $audit['warnings'] ) ) : ?>
								<p style="color:#15803d;">✓ هیچ‌گونه تداخلی بین افزونه‌های فعال سایت شما شناسایی نشد!</p>
							<?php else : ?>
								<?php foreach ( $audit['warnings'] as $w ) : ?>
									<div style="margin-bottom:10px;padding:10px 12px;background:#fff;border-radius:8px;border-right:4px solid <?php echo 'critical' === $w['severity'] ? '#dc2626' : ( 'medium' === $w['severity'] ? '#f59e0b' : '#2563eb' ); ?>;">
										<strong><?php echo esc_html( $w['title'] ); ?></strong>
										<p style="margin:4px 0 0;font-size:12px;color:#475569;"><?php echo esc_html( $w['detail'] ); ?></p>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>

							<p style="margin-top:16px;">
								<button type="button" class="button button-primary" id="asc-btn-auto-config-autolinks">
									اعمال مجدد تنظیمات بهینه + قوانین لینک داخلی هدفمند در Autolinks Manager (همراه با Snapshot)
								</button>
							</p>
							<div id="asc-autolinks-config-result" style="margin-top:10px;"></div>

							<?php if ( ! empty( $audit['autolinks_rules'] ) ) : ?>
								<h4 style="margin-top:18px;">قوانین فعال فعلی در جدول Autolinks Manager (<?php echo (int) $audit['autolinks_rules_count']; ?> مورد)</h4>
								<table class="widefat striped" style="font-size:12px;">
									<thead>
										<tr>
											<th>کلمه کلیدی (Anchor)</th>
											<th>آدرس مقصد</th>
											<th>اولویت</th>
											<th>سقف</th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( array_slice( $audit['autolinks_rules'], 0, 25 ) as $al_row ) : ?>
											<tr>
												<td><strong><?php echo esc_html( isset( $al_row['keyword'] ) ? $al_row['keyword'] : '' ); ?></strong></td>
												<td dir="ltr" style="font-size:11px;"><code><?php echo esc_html( rawurldecode( isset( $al_row['url'] ) ? $al_row['url'] : '' ) ); ?></code></td>
												<td><?php echo esc_html( isset( $al_row['priority'] ) ? (string) $al_row['priority'] : '' ); ?></td>
												<td><?php echo esc_html( isset( $al_row['limit'] ) ? (string) $al_row['limit'] : '' ); ?></td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>

						<div class="asc-box">
							<h3>لیست افزونه‌های فعال سایت (<?php echo (int) $audit['active_plugins_count']; ?> مورد)</h3>
							<p style="font-size:12px;color:#475569;">در صورت نیاز می‌توانید این گزارش را کپی کرده و در چت ارسال کنید:</p>
							<textarea readonly rows="11" class="large-text code" dir="ltr"><?php
								foreach ( $audit['active_plugins'] as $pl ) {
									echo esc_textarea( $pl['name'] . ' [' . $pl['version'] . '] (' . $pl['file'] . ')' . "\n" );
								}
							?></textarea>
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
