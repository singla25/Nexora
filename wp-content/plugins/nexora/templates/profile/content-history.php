<?php /** @var array[] $posts  rows: title, content, image, date */ ?>
<table style="width:100%; border-collapse:collapse;">
	<thead>
		<tr>
			<th style="padding:8px;">Title</th>
			<th style="padding:8px;">Date</th>
			<th style="padding:8px;">Action</th>
		</tr>
	</thead>
	<tbody>

	<?php
	if ( $posts ) :
		foreach ( $posts as $entry ) :
			?>

		<tr>
			<td style="padding:8px;"><?php echo esc_html( $entry['title'] ); ?></td>
			<td style="padding:8px;"><?php echo esc_html( $entry['date'] ); ?></td>
			<td style="padding:8px;">
				
				<button 
					class="view-content-btn"
					data-title="<?php echo esc_attr( $entry['title'] ); ?>"
					data-content="<?php echo esc_attr( $entry['content'] ); ?>"
					data-image="<?php echo esc_url( $entry['image'] ); ?>"
					data-date="<?php echo esc_attr( $entry['date'] ); ?>"
				>
					View
				</button>

			</td>
		</tr>

			<?php endforeach; else : ?>

		<tr>
			<td colspan="3" style="text-align:center;">No content found</td>
		</tr>

	<?php endif; ?>

	</tbody>
</table>
