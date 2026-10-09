<?php
/**
 * @var string  $state  no_user | no_connections | ok
 * @var array[] $rows   rows: name, conn_id, status, status_color, time, threads[] (subject, status, color)
 */
?>
<?php if ( 'no_user' === $state ) : ?>
<p><?php esc_html_e( 'No user linked.', 'nexora' ); ?></p>
<?php elseif ( 'no_connections' === $state ) : ?>
<p><?php esc_html_e( 'No connections found.', 'nexora' ); ?></p>
<?php else : ?>
<h3><?php esc_html_e( '💬 User Chat Overview', 'nexora' ); ?></h3>
<table class="widefat striped" style="font-size:13px;">
<thead>
		<tr>
			<th><?php esc_html_e( 'User', 'nexora' ); ?></th>
			<th><?php esc_html_e( 'Connection ID', 'nexora' ); ?></th>
			<th><?php esc_html_e( 'Status', 'nexora' ); ?></th>
			<th><?php esc_html_e( 'Connection Time', 'nexora' ); ?></th>
			<th><?php esc_html_e( 'Threads', 'nexora' ); ?></th>
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
<span style='color:#6b7280;'><?php esc_html_e( 'No conversations', 'nexora' ); ?></span>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
