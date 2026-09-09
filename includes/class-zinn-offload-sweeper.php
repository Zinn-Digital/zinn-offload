<?php
/**
 * Moving a media library that already exists — in resumable batches.
 *
 * @package ZinnOffload
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The background pass that offloads attachments uploaded before the plugin was installed.
 *
 * ⛔⛔ **IT RESUMES; IT DOES NOT RESTART — and on a large library that is the difference
 * between working and never finishing.** The query asks for attachments with **no**
 * `ZINN_OFFLOAD_KEY_META`, so each run's own successes shrink the next run's work list. The
 * tempting alternative — `posts_per_page` with an offset, or ordering by date and starting
 * from the top — re-does its prefix on every fire: run two repeats run one's work, and the
 * attachments past the point where PHP ran out of time are reached by no run at any cadence.
 * Cranking the batch size does not fix that; only the cursor does.
 *
 * ⭐ Which is also why a throttled or failed batch costs nothing. The rows it did not manage
 * simply still have no meta, so the next tick picks them up. There is no state to repair and
 * no queue to drain.
 */
class Zinn_Offload_Sweeper {

	/**
	 * The cron hook this class owns.
	 */
	private const HOOK = 'zinn_offload_sweep';

	/**
	 * How many attachments one pass moves.
	 *
	 * ⛔ Ten, not a hundred, and the bound is PHP's rather than ours: each attachment is
	 * several files and every one is a full HTTP PUT of its bytes. A batch sized to the
	 * presign cap would routinely exceed `max_execution_time` on shared hosting, and a pass
	 * killed by the host mid-upload is a pass whose partial work still has to be safe —
	 * which it is, because the meta is stamped only after a file lands.
	 */
	private const BATCH = 10;

	/**
	 * How many attachments this site moves per pass.
	 *
	 * ⛔ Bounded at both ends whatever the stored value says. A `0` would make the sweep a
	 * no-op that looks configured, and an unbounded number would be a customer typing a
	 * timeout into their own cron job.
	 *
	 * @return int
	 */
	private static function batch_size(): int {
		$size = (int) zinn_offload_setting( 'sweep_batch', self::BATCH );
		return max( 1, min( 100, $size ) );
	}

	/**
	 * The API client.
	 *
	 * @var Zinn_Offload_Client
	 */
	private Zinn_Offload_Client $client;

	/**
	 * Constructor.
	 *
	 * @param Zinn_Offload_Client $client The API client.
	 */
	public function __construct( Zinn_Offload_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Attach the hooks and make sure the schedule exists.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	/**
	 * How many attachments are still on local disk only.
	 *
	 * ⛔⛔ **A COUNT, AND A BLIND VERSION OF THIS FUNCTION WOULD ALSO RETURN ZERO.** Zero here
	 * means "everything is offloaded", which is exactly what the settings screen reports when
	 * the query is broken, when the site has no attachments at all, and when the meta key was
	 * renamed. So the screen shows this beside the *total* attachment count, and the two
	 * together say which zero it is.
	 *
	 * @return int The number of attachments with no offload key.
	 */
	public static function remaining(): int {
		$query = new WP_Query( self::query_args( 1 ) );
		return (int) $query->found_posts;
	}

	/**
	 * The total number of attachments on this site — the control for {@see self::remaining()}.
	 *
	 * @return int The attachment count.
	 */
	public static function total(): int {
		$counts = (array) wp_count_posts( 'attachment' );
		return (int) ( $counts['inherit'] ?? 0 );
	}

	/**
	 * Offload the next batch.
	 *
	 * @return int How many attachments this pass moved.
	 */
	public function run(): int {
		if ( ! zinn_offload_is_connected() ) {
			return 0;
		}

		// ⛔ Read on the JOB, not only when the schedule is set. A customer who pauses the
		// sweep between two hourly fires must not have one more batch moved — and a cron
		// event that survives a settings change is the kind of thing that goes unnoticed
		// for months.
		if ( ! zinn_offload_setting( 'sweep_enabled', true ) ) {
			return 0;
		}

		$query = new WP_Query( self::query_args( self::batch_size() ) );
		if ( ! $query->have_posts() ) {
			$this->report();
			return 0;
		}

		$uploader = new Zinn_Offload_Uploader( $this->client );
		$moved    = 0;
		foreach ( $query->posts as $attachment_id ) {
			$metadata = wp_get_attachment_metadata( (int) $attachment_id );
			if ( ! is_array( $metadata ) ) {
				// ⛔ Stamped as done rather than left to be retried for ever. An attachment
				// with no metadata is a PDF, a stray row, or a file WordPress never processed
				// — there is nothing to offload, and leaving it in the work list would put an
				// unofloadable row at the head of every future pass and starve everything
				// behind it.
				update_post_meta( (int) $attachment_id, ZINN_OFFLOAD_KEY_META, '' );
				continue;
			}
			$uploader->offload( $metadata, (int) $attachment_id );
			if ( '' !== (string) get_post_meta( (int) $attachment_id, ZINN_OFFLOAD_KEY_META, true ) ) {
				++$moved;
			}
		}

		$this->report();
		return $moved;
	}

	/**
	 * Tell Zinn how far along this site is.
	 *
	 * @return void
	 */
	private function report(): void {
		$settings = zinn_offload_settings();
		$this->client->report( (int) $settings['objects'], (int) $settings['bytes'] );
	}

	/**
	 * The query for attachments that have not been offloaded.
	 *
	 * @param int $limit How many to fetch.
	 * @return array<string, mixed> Arguments for WP_Query.
	 */
	private static function query_args( int $limit ): array {
		return array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			// Oldest first, so a customer watching the number go down sees their archive
			// move rather than watching recent uploads they can already see get re-done.
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => false,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The cursor IS this comparison; see the class docstring.
				array(
					'key'     => ZINN_OFFLOAD_KEY_META,
					'compare' => 'NOT EXISTS',
				),
			),
		);
	}
}
