<?php
/**
 * Read-only details of one import (administrators).
 *
 * @var array $entry The history entry (source, label, counts, errors).
 */
?>
<?php if ( ! $entry ) : ?>
	<p><?php esc_html_e( 'No details available.', 'nexora' ); ?></p>
<?php else : ?>
	<p>
		<strong><?php echo esc_html( 'api' === $entry['source'] ? __( 'API import', 'nexora' ) : __( 'CSV import', 'nexora' ) ); ?></strong>
		<?php echo $entry['label'] ? esc_html( '- ' . $entry['label'] ) : ''; ?>
	</p>
	<p>
		<?php
		/* translators: 1: created, 2: updated, 3: unchanged, 4: skipped. */
		echo esc_html( sprintf( __( '%1$d created, %2$d updated, %3$d unchanged, %4$d skipped', 'nexora' ), $entry['created'], $entry['updated'], $entry['unchanged'], $entry['skipped'] ) );
		?>
	</p>
	<?php if ( $entry['errors'] ) : ?>
		<ul>
			<?php foreach ( $entry['errors'] as $nx_error ) : ?>
				<li>
					<?php
					echo esc_html( ( $nx_error['line'] ? sprintf( /* translators: %d: line number. */ __( 'Line %d: ', 'nexora' ), $nx_error['line'] ) : sprintf( /* translators: %s: item reference. */ __( 'Item %s: ', 'nexora' ), $nx_error['ref'] ) ) . $nx_error['message'] );
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
<?php endif; ?>
