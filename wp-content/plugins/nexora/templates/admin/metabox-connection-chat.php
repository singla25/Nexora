<?php
/**
 * @var array[] $threads  rows: id, users, subject, status, color, other_user, name
 */
?>
<h3>💬 Connection Chat Threads</h3>
<?php if ( ! $threads ) : ?>
<p>No threads found.</p>
<?php else : ?>
<table class="widefat striped" style="font-size:13px;">
<thead>
		<tr>
			<th>Thread ID</th>
			<th>Users</th>
			<th>Subject</th>
			<th>Status</th>
			<th>Action</th>
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
			View Chat
		</button>
	</td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
