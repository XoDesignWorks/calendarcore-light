<?php
/**
 * Import screen for .ics files and feeds.
 *
 * @package XODW\CalendarCore
 */

namespace XODW\CalendarCore\Admin;

defined( 'ABSPATH' ) || exit;

use XODW\CalendarCore\Importer;

/**
 * Upload a file or paste a URL, preview what would happen, then import.
 * Re-importing the same calendar updates the events it created instead of
 * duplicating them, because every imported event remembers its UID.
 */
class ImportPage {

	const SLUG = 'xodw-cc-import';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_xodw_cc_import', array( $this, 'handle' ) );
	}

	/**
	 * Registers the submenu entry.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=' . XODW_CC_POST_TYPE,
			__( 'Import events', 'calendarcore' ),
			__( 'Import', 'calendarcore' ),
			'import',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'import' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$notice = isset( $_GET['xodw_cc_msg'] ) ? sanitize_text_field( rawurldecode( sanitize_text_field( wp_unslash( $_GET['xodw_cc_msg'] ) ) ) ) : '';
		$ok     = isset( $_GET['xodw_cc_ok'] ) && '1' === $_GET['xodw_cc_ok'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap xodw-cc-admin">
			<h1><?php esc_html_e( 'Import events', 'calendarcore' ); ?></h1>

			<?php if ( '' !== $notice ) : ?>
				<div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Import an iCalendar (.ics) file or feed: an export from Google Calendar, Outlook, Apple Calendar, Meetup or another WordPress calendar.', 'calendarcore' ); ?></p>

			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="xodw_cc_import" />
				<?php wp_nonce_field( 'xodw_cc_import' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="xodw-cc-ics-file"><?php esc_html_e( 'File', 'calendarcore' ); ?></label></th>
						<td>
							<input type="file" id="xodw-cc-ics-file" name="ics" accept=".ics,text/calendar" />
							<p class="description"><?php esc_html_e( 'Up to 5 MB.', 'calendarcore' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="xodw-cc-ics-url"><?php esc_html_e( 'or URL', 'calendarcore' ); ?></label></th>
						<td><input type="url" class="regular-text code" id="xodw-cc-ics-url" name="url" placeholder="https://example.com/calendar.ics" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="xodw-cc-ics-status"><?php esc_html_e( 'Create events as', 'calendarcore' ); ?></label></th>
						<td>
							<select id="xodw-cc-ics-status" name="status">
								<option value="draft"><?php esc_html_e( 'Draft — review before publishing', 'calendarcore' ); ?></option>
								<option value="pending"><?php esc_html_e( 'Pending review', 'calendarcore' ); ?></option>
								<option value="publish"><?php esc_html_e( 'Published right away', 'calendarcore' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Venues', 'calendarcore' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="venue" value="1" checked="checked" />
								<?php esc_html_e( 'Create a venue from the LOCATION field', 'calendarcore' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p>
					<button type="submit" class="button" name="mode" value="preview"><?php esc_html_e( 'Preview', 'calendarcore' ); ?></button>
					<button type="submit" class="button button-primary" name="mode" value="import"><?php esc_html_e( 'Import', 'calendarcore' ); ?></button>
				</p>
			</form>

			<h2><?php esc_html_e( 'What gets imported', 'calendarcore' ); ?></h2>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'Title, description, start and end, all day flag and the timezone of each event.', 'calendarcore' ); ?></li>
				<li><?php esc_html_e( 'Repeating events keep their rule, including skipped dates, instead of arriving as hundreds of copies.', 'calendarcore' ); ?></li>
				<li><?php esc_html_e( 'LOCATION becomes a venue, ORGANIZER becomes an organizer.', 'calendarcore' ); ?></li>
				<li><?php esc_html_e( 'Cancelled entries are ignored, and importing the same calendar again updates what it created before.', 'calendarcore' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Handles the submitted form.
	 *
	 * @return void
	 */
	public function handle() {
		check_admin_referer( 'xodw_cc_import' );

		if ( ! current_user_can( 'import' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'calendarcore' ), 403 );
		}

		$importer = new Importer();
		$content  = $this->read_input( $importer );

		if ( is_wp_error( $content ) ) {
			$this->redirect( $content->get_error_message(), false );
		}

		$events = $importer->parse( $content );

		if ( empty( $events ) ) {
			$this->redirect( __( 'No events found in that calendar.', 'calendarcore' ), false );
		}

		$dry_run = ! isset( $_POST['mode'] ) || 'import' !== sanitize_key( wp_unslash( $_POST['mode'] ) );

		$report = $importer->import(
			$events,
			array(
				'status'  => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft',
				'venue'   => ! empty( $_POST['venue'] ),
				'dry_run' => $dry_run,
			)
		);

		$message = $dry_run
			? sprintf(
				/* translators: 1: number of new events, 2: number of events that would be updated. */
				__( 'Preview: %1$d events would be created and %2$d updated. Nothing was saved yet.', 'calendarcore' ),
				$report['created'],
				$report['updated']
			)
			: sprintf(
				/* translators: 1: number of created events, 2: number of updated events, 3: number of skipped events. */
				__( 'Imported: %1$d created, %2$d updated, %3$d skipped.', 'calendarcore' ),
				$report['created'],
				$report['updated'],
				$report['skipped']
			);

		$this->redirect( $message, true );
	}

	/**
	 * Reads the uploaded file or downloads the feed.
	 *
	 * @param Importer $importer Importer instance.
	 * @return string|\WP_Error
	 */
	private function read_input( Importer $importer ) {
		$url = isset( $_POST['url'] ) ? sanitize_url( wp_unslash( $_POST['url'] ) ) : '';

		if ( empty( $_FILES['ics']['name'] ) && '' === $url ) {
			return new \WP_Error( 'xodw_cc_ics_missing', __( 'Choose a file or paste a URL first.', 'calendarcore' ) );
		}

		if ( '' !== $url && empty( $_FILES['ics']['name'] ) ) {
			return $importer->fetch( $url );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file = isset( $_FILES['ics'] ) ? $_FILES['ics'] : array();

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new \WP_Error( 'xodw_cc_ics_upload', __( 'The file could not be uploaded.', 'calendarcore' ) );
		}

		if ( ! isset( $file['size'] ) || (int) $file['size'] > Importer::MAX_BYTES ) {
			return new \WP_Error( 'xodw_cc_ics_size', __( 'That file is too large to import.', 'calendarcore' ) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';

		if ( 'ics' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new \WP_Error( 'xodw_cc_ics_type', __( 'Only .ics files can be imported.', 'calendarcore' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();

		global $wp_filesystem;

		$path    = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		$content = $wp_filesystem && $path ? $wp_filesystem->get_contents( $path ) : '';

		if ( ! is_string( $content ) || false === strpos( $content, 'BEGIN:VCALENDAR' ) ) {
			return new \WP_Error( 'xodw_cc_ics_format', __( 'That file is not an iCalendar document.', 'calendarcore' ) );
		}

		return $content;
	}

	/**
	 * Sends the admin back with a message.
	 *
	 * @param string $message Message.
	 * @param bool   $ok      Whether it went well.
	 * @return void
	 */
	private function redirect( $message, $ok ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'post_type'   => XODW_CC_POST_TYPE,
					'page'        => self::SLUG,
					'xodw_cc_msg' => rawurlencode( $message ),
					'xodw_cc_ok'  => $ok ? '1' : '0',
				),
				admin_url( 'edit.php' )
			)
		);
		exit;
	}
}
