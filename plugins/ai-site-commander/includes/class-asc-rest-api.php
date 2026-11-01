<?php
/**
 * REST API endpoints (/wp-json/ai-site-commander/v1/*) for AI Site Commander.
 *
 * @package AI_Site_Commander
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ASC_REST_API {

	const NAMESPACE_V1 = 'ai-site-commander/v1';

	/**
	 * Register REST API routes.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Build permission callback closure for a given scope.
	 *
	 * @param string $scope Required scope.
	 * @return callable
	 */
	private static function permission_for( $scope ) {
		return function ( WP_REST_Request $request ) use ( $scope ) {
			return ASC_Security::authorize_rest_request( $request, $scope );
		};
	}

	/**
	 * Define all REST endpoints.
	 */
	public static function register_routes() {
		// Capability manifest (authenticated read).
		register_rest_route(
			self::NAMESPACE_V1,
			'/manifest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => self::permission_for( 'read' ),
				'callback'            => array( __CLASS__, 'handle_manifest' ),
			)
		);

		// Site health & overview.
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => self::permission_for( 'read' ),
				'callback'            => function () {
					return rest_ensure_response( ASC_Engine::get_site_status() );
				},
			)
		);

		// Unified action dispatcher (ideal for MCP & AI Tool Calls).
		register_rest_route(
			self::NAMESPACE_V1,
			'/execute',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'authorize_dynamic_execute' ),
				'callback'            => array( __CLASS__, 'handle_execute' ),
			)
		);

		// Posts & Pages CRUD.
		register_rest_route(
			self::NAMESPACE_V1,
			'/posts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => self::permission_for( 'read' ),
					'callback'            => function ( WP_REST_Request $req ) {
						return rest_ensure_response( ASC_Content_Manager::list_posts( $req->get_params() ) );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => self::permission_for( 'content:write' ),
					'callback'            => function ( WP_REST_Request $req ) {
						$params = $req->get_json_params() ? $req->get_json_params() : $req->get_params();
						return rest_ensure_response(
							ASC_Engine::dispatch(
								empty( $params['id'] ) ? 'create_post' : 'update_post',
								$params,
								array( 'reason' => $params['reason'] ?? '' )
							)
						);
					},
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/posts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => self::permission_for( 'read' ),
					'callback'            => function ( WP_REST_Request $req ) {
						return rest_ensure_response( ASC_Content_Manager::get_post_detail( (int) $req['id'] ) );
					},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => self::permission_for( 'content:write' ),
					'callback'            => function ( WP_REST_Request $req ) {
						$params       = $req->get_json_params() ? $req->get_json_params() : $req->get_params();
						$params['id'] = (int) $req['id'];
						return rest_ensure_response(
							ASC_Engine::dispatch( 'update_post', $params, array( 'reason' => $params['reason'] ?? '' ) )
						);
					},
				),
			)
		);

		// SEO Metadata & Audit.
		register_rest_route(
			self::NAMESPACE_V1,
			'/seo/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => self::permission_for( 'read' ),
				'callback'            => function ( WP_REST_Request $req ) {
					return rest_ensure_response( ASC_SEO_Manager::bulk_seo_audit( $req->get_param( 'limit' ) ?: 100 ) );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/seo/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => self::permission_for( 'read' ),
					'callback'            => function ( WP_REST_Request $req ) {
						return rest_ensure_response( ASC_SEO_Manager::get_post_seo( (int) $req['id'] ) );
					},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => self::permission_for( 'seo:write' ),
					'callback'            => function ( WP_REST_Request $req ) {
						$params            = $req->get_json_params() ? $req->get_json_params() : $req->get_params();
						$params['post_id'] = (int) $req['id'];
						return rest_ensure_response(
							ASC_Engine::dispatch( 'update_seo', $params, array( 'reason' => $params['reason'] ?? '' ) )
						);
					},
				),
			)
		);

		// Media Missing Alt & Bulk Alt Fix.
		register_rest_route(
			self::NAMESPACE_V1,
			'/media/missing-alt',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => self::permission_for( 'read' ),
				'callback'            => function ( WP_REST_Request $req ) {
					return rest_ensure_response( array( 'items' => ASC_Content_Manager::list_missing_image_alts( $req->get_param( 'limit' ) ?: 50 ) ) );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/media/update-alt',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => self::permission_for( 'seo:write' ),
				'callback'            => function ( WP_REST_Request $req ) {
					$params = $req->get_json_params() ? $req->get_json_params() : $req->get_params();
					return rest_ensure_response( ASC_Engine::dispatch( 'update_image_alts', $params ) );
				},
			)
		);

		// Redirects & 404 Monitor.
		register_rest_route(
			self::NAMESPACE_V1,
			'/redirects',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => self::permission_for( 'read' ),
					'callback'            => function ( WP_REST_Request $req ) {
						return rest_ensure_response( array( 'items' => ASC_Redirect_404::list_redirects( $req->get_param( 'limit' ) ?: 100 ) ) );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => self::permission_for( 'redirects:write' ),
					'callback'            => function ( WP_REST_Request $req ) {
						$params = $req->get_json_params() ? $req->get_json_params() : $req->get_params();
						return rest_ensure_response(
							ASC_Engine::dispatch( 'save_redirect', $params, array( 'reason' => $params['reason'] ?? '' ) )
						);
					},
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/404-logs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => self::permission_for( 'read' ),
				'callback'            => function ( WP_REST_Request $req ) {
					return rest_ensure_response(
						array(
							'items' => ASC_Redirect_404::list_404_logs(
								(bool) $req->get_param( 'only_unresolved' ),
								$req->get_param( 'limit' ) ?: 100
							),
						)
					);
				},
			)
		);

		// WooCommerce Products.
		register_rest_route(
			self::NAMESPACE_V1,
			'/woocommerce/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => self::permission_for( 'read' ),
				'callback'            => function ( WP_REST_Request $req ) {
					return rest_ensure_response( ASC_Content_Manager::list_woocommerce_products( $req->get_params() ) );
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/woocommerce/products/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'permission_callback' => self::permission_for( 'woocommerce:write' ),
				'callback'            => function ( WP_REST_Request $req ) {
					$params               = $req->get_json_params() ? $req->get_json_params() : $req->get_params();
					$params['product_id'] = (int) $req['id'];
					return rest_ensure_response(
						ASC_Engine::dispatch( 'update_woo_product', $params, array( 'reason' => $params['reason'] ?? '' ) )
					);
				},
			)
		);

		// Snippets, Options & Theme Files.
		register_rest_route(
			self::NAMESPACE_V1,
			'/snippets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => self::permission_for( 'read' ),
					'callback'            => function () {
						return rest_ensure_response( array( 'items' => ASC_Code_Files::list_snippets() ) );
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'permission_callback' => self::permission_for( 'snippets:write' ),
					'callback'            => function ( WP_REST_Request $req ) {
						$params = $req->get_json_params() ? $req->get_json_params() : $req->get_params();
						return rest_ensure_response(
							ASC_Engine::dispatch( 'save_snippet', $params, array( 'reason' => $params['reason'] ?? '' ) )
						);
					},
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/theme-files',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'permission_callback' => self::permission_for( 'read' ),
					'callback'            => function ( WP_REST_Request $req ) {
						$rel = $req->get_param( 'relative_path' );
						if ( ! empty( $rel ) ) {
							return rest_ensure_response( ASC_Code_Files::read_theme_file( $rel ) );
						}
						return rest_ensure_response( ASC_Code_Files::list_theme_files() );
					},
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'permission_callback' => self::permission_for( 'files:write' ),
					'callback'            => function ( WP_REST_Request $req ) {
						$params = $req->get_json_params() ? $req->get_json_params() : $req->get_params();
						return rest_ensure_response(
							ASC_Engine::dispatch( 'write_theme_file', $params, array( 'reason' => $params['reason'] ?? '' ) )
						);
					},
				),
			)
		);

		// Snapshots & 1-Click Rollback.
		register_rest_route(
			self::NAMESPACE_V1,
			'/snapshots',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => self::permission_for( 'read' ),
				'callback'            => function ( WP_REST_Request $req ) {
					return rest_ensure_response(
						array(
							'items' => ASC_Snapshot::list_snapshots(
								$req->get_param( 'limit' ) ?: 50,
								$req->get_param( 'offset' ) ?: 0
							),
						)
					);
				},
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/snapshots/(?P<id>\d+)/rollback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => self::permission_for( 'rollback:write' ),
				'callback'            => function ( WP_REST_Request $req ) {
					return rest_ensure_response(
						ASC_Snapshot::rollback( (int) $req['id'], ASC_Security::current_actor_label() )
					);
				},
			)
		);
	}

	/**
	 * Determine required scope dynamically for /execute endpoint based on requested action.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	public static function authorize_dynamic_execute( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ? $request->get_json_params() : $request->get_params();
		$action = isset( $body['action'] ) ? sanitize_key( (string) $body['action'] ) : '';

		$scope_map = array(
			'get_status'           => 'read',
			'list_posts'           => 'read',
			'get_post'             => 'read',
			'create_post'          => 'content:write',
			'update_post'          => 'content:write',
			'save_post'            => 'content:write',
			'inject_internal_link' => 'content:write',
			'get_seo'              => 'read',
			'bulk_seo_audit'       => 'read',
			'update_seo'           => 'seo:write',
			'list_missing_alts'    => 'read',
			'update_image_alts'    => 'seo:write',
			'list_redirects'       => 'read',
			'list_404_logs'        => 'read',
			'save_redirect'        => 'redirects:write',
			'create_redirect'      => 'redirects:write',
			'delete_redirect'      => 'redirects:write',
			'list_woo_products'    => 'read',
			'update_woo_product'   => 'woocommerce:write',
			'list_snippets'        => 'read',
			'save_snippet'         => 'snippets:write',
			'get_option'           => 'read',
			'update_option'        => 'options:write',
			'list_theme_files'     => 'read',
			'read_theme_file'      => 'read',
			'write_theme_file'     => 'files:write',
			'list_snapshots'       => 'read',
			'rollback_snapshot'    => 'rollback:write',
		);

		$required_scope = isset( $scope_map[ $action ] ) ? $scope_map[ $action ] : '*';
		return ASC_Security::authorize_rest_request( $request, $required_scope );
	}

	/**
	 * Handle unified POST /execute calls.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_execute( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ? $request->get_json_params() : $request->get_params();
		$action = isset( $body['action'] ) ? sanitize_key( (string) $body['action'] ) : '';
		$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();
		$reason = isset( $body['reason'] ) ? sanitize_textarea_field( (string) $body['reason'] ) : '';
		$stage  = ! empty( $body['stage'] );

		if ( '' === $action ) {
			return new WP_Error( 'asc_missing_action', 'فیلد action در بدنه درخواست الزامی است.', array( 'status' => 400 ) );
		}

		$result = ASC_Engine::dispatch(
			$action,
			$params,
			array(
				'actor'  => ASC_Security::current_actor_label(),
				'reason' => $reason,
				'stage'  => $stage,
			)
		);

		return rest_ensure_response( $result );
	}

	/**
	 * Return machine-readable capability manifest for AI Agents & MCP servers.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_manifest() {
		return rest_ensure_response(
			array(
				'name'           => 'AI Site Commander',
				'version'        => ASC_VERSION,
				'rest_namespace' => self::NAMESPACE_V1,
				'base_url'       => rest_url( self::NAMESPACE_V1 ),
				'status'         => ASC_Engine::get_site_status(),
				'actions'        => array(
					'get_status',
					'list_posts',
					'get_post',
					'create_post',
					'update_post',
					'get_seo',
					'update_seo',
					'bulk_seo_audit',
					'inject_internal_link',
					'list_missing_alts',
					'update_image_alts',
					'list_redirects',
					'save_redirect',
					'delete_redirect',
					'list_404_logs',
					'list_woo_products',
					'update_woo_product',
					'list_snippets',
					'save_snippet',
					'get_option',
					'update_option',
					'list_theme_files',
					'read_theme_file',
					'write_theme_file',
					'list_snapshots',
					'rollback_snapshot',
				),
			)
		);
	}
}
