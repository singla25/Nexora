<?php
/**
 * The administrator's Marketplace overview. Read-only; every value is escaped here.
 *
 * @var array    $data   From Orders\Reports::overview().
 * @var array[]  $ranges Range links: label, url, current.
 * @var array    $heads  Column headings: label, url, sorted (asc, desc or empty).
 * @var callable $price  Formats an amount.
 */
$nx_t = $data['totals'];
?>
<div class="wrap nx-overview">
	<h1><?php esc_html_e( 'Marketplace overview', 'nexora' ); ?></h1>
	<p><?php esc_html_e( 'Only Processing and Completed orders count. Figures come from the amounts frozen on each order line, so a price changed later does not alter them.', 'nexora' ); ?></p>

	<ul class="subsubsub">
		<?php foreach ( $ranges as $nx_i => $nx_r ) : ?>
			<li><a href="<?php echo esc_url( $nx_r['url'] ); ?>"<?php echo $nx_r['current'] ? ' class="current" aria-current="true"' : ''; ?>><?php echo esc_html( $nx_r['label'] ); ?></a><?php echo $nx_i < count( $ranges ) - 1 ? ' |' : ''; ?></li>
		<?php endforeach; ?>
	</ul>
	<br class="clear">

	<table class="widefat striped nx-overview-totals" style="max-width:640px;margin:12px 0;">
		<tbody>
			<tr><th scope="row"><?php esc_html_e( 'Total sales', 'nexora' ); ?></th><td><?php echo esc_html( $price( $nx_t['sales'] ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Total commission', 'nexora' ); ?></th><td><?php echo esc_html( $price( $nx_t['commission'] ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Seller earnings', 'nexora' ); ?></th><td><?php echo esc_html( $price( $nx_t['earnings'] ) ); ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Units', 'nexora' ); ?></th><td><?php echo (int) $nx_t['units']; ?></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Orders (counted)', 'nexora' ); ?></th><td><?php echo (int) $nx_t['orders']; ?></td></tr>
		</tbody>
	</table>
	<p class="description"><?php esc_html_e( 'An order with products from several sellers is counted once in the total above and once for each seller below, so the order counts of the rows can add up to more. Sales, commission, earnings and units always add up to the totals.', 'nexora' ); ?></p>

	<table class="widefat striped nx-overview-sellers">
		<thead>
			<tr>
				<?php foreach ( $heads as $nx_h ) : ?>
					<th scope="col"<?php echo $nx_h['sorted'] ? ' aria-sort="' . ( 'asc' === $nx_h['sorted'] ? 'ascending' : 'descending' ) . '"' : ''; ?>>
						<a href="<?php echo esc_url( $nx_h['url'] ); ?>"><?php echo esc_html( $nx_h['label'] ); ?><?php echo $nx_h['sorted'] ? ( 'asc' === $nx_h['sorted'] ? ' &#9650;' : ' &#9660;' ) : ''; ?></a>
					</th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $data['rows'] ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No counted orders in this period.', 'nexora' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $data['rows'] as $nx_row ) : ?>
					<tr>
						<td>
							<?php if ( $nx_row['deleted'] ) : ?>
								<em><?php echo esc_html( $nx_row['name'] ); ?></em> <small>(#<?php echo (int) $nx_row['seller_id']; ?>)</small>
							<?php else : ?>
								<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $nx_row['seller_id'] . '&action=edit' ) ); ?>"><?php echo esc_html( $nx_row['name'] ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo (int) $nx_row['orders']; ?></td>
						<td><?php echo (int) $nx_row['units']; ?></td>
						<td><?php echo esc_html( $price( $nx_row['sales'] ) ); ?></td>
						<td><?php echo esc_html( $price( $nx_row['commission'] ) ); ?></td>
						<td><?php echo esc_html( $price( $nx_row['earnings'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
