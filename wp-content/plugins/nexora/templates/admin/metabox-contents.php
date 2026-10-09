<?php /** @var array[] $contents  rows: title, date, edit_url */ ?>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'nexora' ); ?></th>
					<th><?php esc_html_e( 'Date', 'nexora' ); ?></th>
					<th><?php esc_html_e( 'Action', 'nexora' ); ?></th>
				</tr>
			</thead>
			<tbody>

			<?php
			if ( $contents ) :
				foreach ( $contents as $content ) :
					?>

				<tr>
					<td><?php echo esc_html( $content['title'] ); ?></td>

					<td><?php echo esc_html( $content['date'] ); ?></td>

					<td>
						<a href="<?php echo esc_url( $content['edit_url'] ); ?>" 
						class="button button-primary">
						<?php esc_html_e( 'View', 'nexora' ); ?>
						</a>
					</td>
				</tr>

							<?php endforeach; else : ?>

				<tr>
					<td colspan="3" style="text-align:center;"><?php esc_html_e( 'No content found', 'nexora' ); ?></td>
				</tr>

			<?php endif; ?>

			</tbody>
		</table>

		