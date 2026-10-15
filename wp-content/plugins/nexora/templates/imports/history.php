<?php
/**
 * The vendor's import history (last entries). Everything printed is escaped here.
 *
 * @var array $import_history Entries from Imports\Log_Repository::for_vendor().
 */
?>
<section class="nx-import-history">
	<h3><?php esc_html_e( 'Import history', 'nexora' ); ?></h3>
	<?php if ( ! $import_history ) : ?>
		<p class="nx-market-empty"><?php esc_html_e( 'No imports yet.', 'nexora' ); ?></p>
	<?php else : ?>
		<ul class="nx-import-list">
			<?php foreach ( $import_history as $nx_entry ) : ?>
				<li class="nx-import-entry">
					<p class="nx-import-title">
						<strong><?php echo esc_html( 'api' === $nx_entry['source'] ? __( 'API', 'nexora' ) : __( 'CSV', 'nexora' ) ); ?></strong>
						<?php echo $nx_entry['label'] ? '&middot; ' . esc_html( $nx_entry['label'] ) : ''; ?>
						<span class="nx-import-date"><?php echo esc_html( $nx_entry['date'] ? get_date_from_gmt( $nx_entry['date'], 'j M Y H:i' ) : '' ); ?></span>
					</p>
					<p class="nx-import-counts">
						<?php
						/* translators: %d: number of products. */
						echo esc_html( sprintf( __( '%d created', 'nexora' ), $nx_entry['created'] ) );
						echo ' &middot; ';
						/* translators: %d: number of products. */
						echo esc_html( sprintf( __( '%d updated', 'nexora' ), $nx_entry['updated'] ) );
						echo ' &middot; ';
						/* translators: %d: number of products. */
						echo esc_html( sprintf( __( '%d unchanged', 'nexora' ), $nx_entry['unchanged'] ) );
						echo ' &middot; ';
						/* translators: %d: number of rows. */
						echo esc_html( sprintf( __( '%d skipped', 'nexora' ), $nx_entry['skipped'] ) );
						?>
					</p>
					<?php if ( $nx_entry['errors'] ) : ?>
						<ul class="nx-import-errors">
							<?php foreach ( array_slice( $nx_entry['errors'], 0, 5 ) as $nx_error ) : ?>
								<li>
									<?php
									if ( $nx_error['line'] ) {
										/* translators: 1: line number, 2: what was wrong. */
										echo esc_html( sprintf( __( 'Line %1$d: %2$s', 'nexora' ), $nx_error['line'], $nx_error['message'] ) );
									} else {
										/* translators: 1: item reference, 2: what was wrong. */
										echo esc_html( sprintf( __( 'Item %1$s: %2$s', 'nexora' ), $nx_error['ref'], $nx_error['message'] ) );
									}
									?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
