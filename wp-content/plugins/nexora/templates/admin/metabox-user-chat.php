<?php
/**
 * @var string  $state  no_user | no_connections | ok
 * @var array[] $rows   rows: name, conn_id, status, status_color, time, threads[] (subject, status, color)
 */
?>
<?php if ( $state === 'no_user' ) : ?>
<p>No user linked.</p>
<?php elseif ( $state === 'no_connections' ) : ?>
<p>No connections found.</p>
<?php else : ?>
<h3>💬 User Chat Overview</h3>
<table class="widefat striped" style="font-size:13px;">
<thead>
		<tr>
			<th>User</th>
			<th>Connection ID</th>
			<th>Status</th>
			<th>Connection Time</th>
			<th>Threads</th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $rows as $row ) : ?>
<tr>
<td><strong><?php echo esc_html( $row['name'] ); ?></strong></td>
<td>#<?php echo (int) $row['conn_id']; ?></td>
<td>
		<span style='color:white; background:<?php echo esc_attr( $row['status_color'] ); ?>; padding:3px 8px; border-radius:4px; font-size:12px;'>
			<?php echo esc_html( $row['status'] ); ?>
		</span>
	</td>
<td><?php echo esc_html( $row['time'] ); ?></td>
<td>
		<?php if ( $row['threads'] ) : ?>
<ul style='margin:0;'>
			<?php foreach ( $row['threads'] as $t ) : ?>
<li style='margin-bottom:5px;'>
		<strong><?php echo esc_html( $t['subject'] ); ?></strong>
		<span style='color:<?php echo esc_attr( $t['color'] ); ?>; font-weight:600; margin-left:6px;'>
			● <?php echo esc_html( $t['status'] ); ?>
		</span>
	</li>
<?php endforeach; ?>
</ul>
<?php else : ?>
<span style='color:#6b7280;'>No conversations</span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
