<?php
/**
 * @var array[] $threads  rows: id, users, subject, status, color, other_user, name
 */
?>
<h3><?php esc_html_e( '💬 Connection Chat Threads', 'nexora' ); ?></h3>
<?php if ( ! $threads ) : ?>
<p><?php esc_html_e( 'No threads found.', 'nexora' ); ?></p>
<?php else : ?>
<table class="widefat striped" style="font-size:13px;">
<thead>
		<tr>
			<th><?php esc_html_e( 'Thread ID', 'nexora' ); ?></th>
			<th><?php esc_html_e( 'Users', 'nexora' ); ?></th>
			<th><?php esc_html_e( 'Subject', 'nexora' ); ?></th>
			<th><?php esc_html_e( 'Status', 'nexora' ); ?></th>
			<th><?php esc_html_e( 'Action', 'nexora' ); ?></th>
		</tr>
	</thead><tbody>
	<?php foreach ( $threads as $thread ) : ?>
<tr>
<td>#<?php echo esc_html( $thread['id'] ); ?></td>
<td><?php echo esc_html( $thread['users'] ); ?></td>
<td><?php echo esc_html( $thread['subject'] ); ?></td>
<td>
		<span style="
			color:white;
			background:<?php echo esc_attr( $thread['color'] ); ?>;
			padding:3px 8px;
			border-radius:4px;
			font-size:12px;
		">
			<?php echo esc_html( $thread['status'] ); ?>
		</span>
	</td>
<td>
		<button 
			type="button"
			class="button button-primary nexora-open-chat"
			data-thread="<?php echo esc_attr( $thread['id'] ); ?>"
			data-user="<?php echo esc_attr( $thread['other_user'] ); ?>"
			data-name="<?php echo esc_attr( $thread['name'] ); ?>"
		>
			<?php esc_html_e( 'View Chat', 'nexora' ); ?>
		</button>
	</td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
