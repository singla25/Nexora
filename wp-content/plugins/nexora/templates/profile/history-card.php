<div class="history-card">

	<img src="<?php echo esc_url( $row['image'] ); ?>" class="history-avatar">

	<a href="<?php echo esc_url( $row['link'] ); ?>" target="_blank" class="history-username">
		<?php echo esc_html( $row['username'] ); ?>
	</a>

	<div class="history-meta">
		<div class="history-name">
			<?php echo esc_html( $row['name'] ); ?>
		</div>

		<div class="history-time">
			<?php echo esc_html( $row['date'] . ' • ' . $row['time'] ); ?>
		</div>
	</div>

	<span class="history-status <?php echo esc_attr( $row['status'] ); ?>">
		<?php echo esc_html( ucfirst( $row['status'] ) ); ?>
	</span>

</div>
