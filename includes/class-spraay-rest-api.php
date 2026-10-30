<?php
/**
 * Plugin REST endpoints used by the admin UI.
 *
 * Authentication is WordPress cookie auth: requests must carry the wp_rest
 * nonce (X-WP-Nonce, added automatically by wp.apiFetch). Without a valid
 * nonce core treats the request as logged out, so the capability check below
 * fails. Every route requires manage_woocommerce.
 *
 * @package Spraay_Batch_Payouts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for spraay/v1.
 */
class Spraay_Rest_Api {

	/**
	 * Route namespace.
	 */
	const NAMESPACE_V1 = 'spraay/v1';

	/**
	 * Hook route registration.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$id_arg = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/recipients',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_recipients' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'search'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 50,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/batches',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_batch' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'batch_id' => array(
						'type'              => 'integer',
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
					'items'    => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'recipient_id' => array( 'type' => 'integer' ),
								'amount'       => array( 'type' => 'string' ),
								'wc_order_ids' => array(
									'type'  => 'array',
									'items' => array( 'type' => 'integer' ),
								),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/batches/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_batch' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_batch' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => $id_arg,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/batches/(?P<id>\d+)/estimate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'estimate_batch' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array_merge(
					$id_arg,
					array(
						'sender' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/batches/(?P<id>\d+)/approve',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'approve_batch' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array_merge(
					$id_arg,
					array(
						'confirm' => array(
							'type'     => 'boolean',
							'required' => true,
						),
					)
				),
			)
		);

		$sender_arg = array(
			'sender' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/batches/(?P<id>\d+)/prepare',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'prepare_batch' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array_merge( $id_arg, $sender_arg ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/batches/(?P<id>\d+)/submit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit_batch' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array_merge(
					$id_arg,
					$sender_arg,
					array(
						'tx_hash' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					)
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/batches/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'batch_status' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => $id_arg,
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/obligations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_obligations' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'source'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'date_from' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'date_to'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_csv' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'type' => array(
						'type'    => 'string',
						'enum'    => array( 'recipients', 'commissions' ),
						'default' => 'recipients',
					),
				),
			)
		);
	}

	/**
	 * Capability check for every route.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * GET /recipients.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_recipients( WP_REST_Request $request ) {
		$result = Spraay_Recipient::query(
			array(
				'search'   => $request['search'],
				'per_page' => min( 200, max( 1, (int) $request['per_page'] ) ),
			)
		);

		$items = array_map(
			function ( $r ) {
				return array(
					'id'             => (int) $r['id'],
					'label'          => $r['label'],
					'wallet_address' => $r['wallet_address'],
					'default_amount' => null === $r['default_amount'] ? '' : $r['default_amount'],
				);
			},
			$result['items']
		);

		return rest_ensure_response( $items );
	}

	/**
	 * POST /batches — create or replace a draft.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_batch( WP_REST_Request $request ) {
		$id = Spraay_Batch::save_draft( (array) $request['items'], (int) $request['batch_id'], get_current_user_id() );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( $this->batch_payload( $id ) );
	}

	/**
	 * GET /batches/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_batch( WP_REST_Request $request ) {
		if ( ! Spraay_Batch::get( $request['id'] ) ) {
			return new WP_Error( 'spraay_not_found', __( 'Batch not found.', 'spraay-batch-payouts-for-woocommerce' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->batch_payload( $request['id'] ) );
	}

	/**
	 * DELETE /batches/{id} — drafts only.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_batch( WP_REST_Request $request ) {
		$result = Spraay_Batch::delete( $request['id'] );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 409 ) );
			return $result;
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /batches/{id}/estimate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function estimate_batch( WP_REST_Request $request ) {
		$estimate = Spraay_Batch::estimate( $request['id'], $request['sender'] );
		if ( is_wp_error( $estimate ) ) {
			return $estimate;
		}
		$estimate['batch'] = $this->batch_payload( $request['id'] );
		return rest_ensure_response( $estimate );
	}

	/**
	 * POST /batches/{id}/approve — the merchant's explicit approval.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_batch( WP_REST_Request $request ) {
		if ( true !== $request['confirm'] ) {
			return new WP_Error( 'spraay_not_confirmed', __( 'Confirm that you have reviewed the batch.', 'spraay-batch-payouts-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$result = Spraay_Batch::approve( $request['id'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'approved' => true ) );
	}

	/**
	 * POST /batches/{id}/prepare — get the gateway's unsigned transaction.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function prepare_batch( WP_REST_Request $request ) {
		return rest_ensure_response( Spraay_Batch::prepare_execution( $request['id'], $request['sender'] ) );
	}

	/**
	 * POST /batches/{id}/submit — record the hash of the broadcast transaction.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_batch( WP_REST_Request $request ) {
		$result = Spraay_Batch::record_submission( $request['id'], $request['tx_hash'], $request['sender'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$batch                  = Spraay_Batch::get( $request['id'] );
		$result['explorer_url'] = Spraay_Plugin::explorer_tx_url( $batch['tx_hash'], $batch['chain_id'] );
		return rest_ensure_response( $result );
	}

	/**
	 * GET /batches/{id}/status — check the transaction receipt on-chain now.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function batch_status( WP_REST_Request $request ) {
		return rest_ensure_response( Spraay_Reconciliation::reconcile_batch( $request['id'] ) );
	}

	/**
	 * GET /obligations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_obligations( WP_REST_Request $request ) {
		$args = array();
		if ( ! empty( $request['date_from'] ) ) {
			$args['date_from'] = $request['date_from'];
		}
		if ( ! empty( $request['date_to'] ) ) {
			$args['date_to'] = $request['date_to'];
		}
		return rest_ensure_response( Spraay_WC_Integration::get_obligations( $request['source'], $args ) );
	}

	/**
	 * POST /import — CSV of recipients+amounts or a Product Vendors commission export.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_csv( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		$path  = Spraay_CSV_Handler::validate_upload( isset( $files['file'] ) ? $files['file'] : null );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$result = 'commissions' === $request['type']
			? Spraay_CSV_Handler::import_vendor_commissions( $path )
			: Spraay_CSV_Handler::import_recipients( $path );

		return rest_ensure_response( $result );
	}

	/**
	 * Batch + items as returned to the UI.
	 *
	 * @param int $batch_id Batch ID.
	 * @return array
	 */
	private function batch_payload( $batch_id ) {
		$batch = Spraay_Batch::get( $batch_id );
		$items = array_map(
			function ( $item ) {
				return array(
					'recipient_id'   => (int) $item['recipient_id'],
					'label'          => (string) $item['label'],
					'wallet_address' => (string) $item['wallet_address'],
					'amount'         => $item['amount'],
					'wc_order_ids'   => $item['wc_order_ids'],
				);
			},
			Spraay_Batch::get_items( $batch_id )
		);

		return array(
			'id'              => (int) $batch['id'],
			'status'          => $batch['status'],
			'total_amount'    => $batch['total_amount'],
			'fee_amount'      => $batch['fee_amount'],
			'recipient_count' => (int) $batch['recipient_count'],
			'chain_id'        => (int) $batch['chain_id'],
			'approved'        => ! empty( $batch['approved_by'] ),
			'tx_hash'         => (string) $batch['tx_hash'],
			'items'           => $items,
		);
	}
}
