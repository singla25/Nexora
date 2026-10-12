<?php
/** @var array[] $users          rows: username, name, image, profile_link */
/** @var string  $empty_message  shown when there are no rows */
/** @var bool    $mutual         add the "Mutual" badge */
?>
<?php
if ( ! empty( $users ) ) :
	foreach ( $users as $user ) :
		?>
<div class="connection-card">

	<div class="conn-cover"></div>

	<div class="conn-avatar">
		<img src="<?php echo esc_url( $user['image'] ); ?>">
	</div>

	<div class="conn-body">

		<a href="<?php echo esc_url( $user['profile_link'] ); ?>" class="conn-username" target="_blank">
				<?php echo esc_html( $user['username'] ); ?>
		</a>

		<p class="conn-name">
				<?php echo esc_html( $user['name'] ); ?>
		</p>

			<?php if ( $mutual ) : ?>
		<span class="mutual-badge"><?php esc_html_e( 'Mutual', 'nexora' ); ?></span>
		<?php endif; ?>

	</div>
</div>
	<?php endforeach; else : ?>
<p><?php echo esc_html( $empty_message ); ?></p>
	<?php endif; ?>
