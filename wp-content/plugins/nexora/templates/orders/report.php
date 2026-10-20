<?php
/**
 * A seller's report (top of the Sales tab). Read-only; every value is escaped here. No buyer data exists in $data.
 *
 * @var array    $data     From Orders\Reports::seller().
 * @var array[]  $ranges   Range links: label, url, current.
 * @var array[]  $pager    Orders pagination: label, url, current.
 * @var array    $chart    Chart geometry from Orders\Report_View.
 * @var callable $currency Formats an amount.
 */
$nx_t = $data['totals'];
$nx_w = $data['awaiting'];
?>
<section class="nx-report" aria-labelledby="nx-report-title">
	<h3 id="nx-report-title"><?php esc_html_e( 'Your sales report', 'nexora' ); ?></h3>

	<nav class="nx-report-range" aria-label="<?php esc_attr_e( 'Date range', 'nexora' ); ?>">
		<?php foreach ( $ranges as $nx_r ) : ?>
			<a class="nx-report-range-link<?php echo $nx_r['current'] ? ' is-current' : ''; ?>" href="<?php echo esc_url( $nx_r['url'] ); ?>"<?php echo $nx_r['current'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $nx_r['label'] ); ?></a>
		<?php endforeach; ?>
	</nav>

	<dl class="nx-report-totals">
		<div><dt><?php esc_html_e( 'Your earnings', 'nexora' ); ?></dt><dd><?php echo esc_html( $currency( $nx_t['earnings'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Commission paid', 'nexora' ); ?></dt><dd><?php echo esc_html( $currency( $nx_t['commission'] ) ); ?></dd></div>
		<div><dt><?php esc_html_e( 'Units sold', 'nexora' ); ?></dt><dd><?php echo (int) $nx_t['units']; ?></dd></div>
		<div><dt><?php esc_html_e( 'Orders', 'nexora' ); ?></dt><dd><?php echo (int) $nx_t['orders']; ?></dd></div>
		<div><dt><?php esc_html_e( 'Sales', 'nexora' ); ?></dt><dd><?php echo esc_html( $currency( $nx_t['sales'] ) ); ?></dd></div>
		<?php if ( (float) $nx_t['reversed'] > 0 ) : ?>
			<div><dt><?php esc_html_e( 'Your share reversed by refunds (already taken off)', 'nexora' ); ?></dt><dd><?php echo esc_html( $currency( $nx_t['reversed'] ) ); ?></dd></div>
		<?php endif; ?>
	</dl>
	<p class="nx-report-awaiting">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: number of orders, 2: amount */
				_n( 'Not counted yet (pending or on hold): %1$d order, %2$s you would earn once it is paid.', 'Not counted yet (pending or on hold): %1$d orders, %2$s you would earn once they are paid.', (int) $nx_w['orders'], 'nexora' ),
				(int) $nx_w['orders'],
				$currency( $nx_w['earnings'] )
			)
		);
		?>
	</p>

	<h4><?php esc_html_e( 'Earnings over time', 'nexora' ); ?></h4>
	<?php if ( ! $chart['points'] ) : ?>
		<p class="nx-report-empty"><?php esc_html_e( 'No counted sales in this period.', 'nexora' ); ?></p>
	<?php else : ?>
		<svg class="nx-report-chart" viewBox="0 0 <?php echo (int) $chart['width']; ?> <?php echo (int) $chart['height']; ?>" role="img" aria-label="<?php esc_attr_e( 'Line chart of your earnings by day. The same numbers are in the table below.', 'nexora' ); ?>" focusable="false">
			<title><?php esc_html_e( 'Earnings by day', 'nexora' ); ?></title>
			<line x1="24" y1="<?php echo (int) $chart['height'] - 24; ?>" x2="<?php echo (int) $chart['width'] - 24; ?>" y2="<?php echo (int) $chart['height'] - 24; ?>" class="nx-report-axis"/>
			<?php if ( count( $chart['points'] ) > 1 ) : ?>
				<polyline class="nx-report-line" fill="none" points="<?php echo esc_attr( $chart['path'] ); ?>"/>
			<?php endif; ?>
			<?php foreach ( $chart['points'] as $nx_p ) : ?>
				<circle class="nx-report-dot" cx="<?php echo esc_attr( $nx_p['x'] ); ?>" cy="<?php echo esc_attr( $nx_p['y'] ); ?>" r="3"><title><?php echo esc_html( $nx_p['date'] . ': ' . $currency( $nx_p['earnings'] ) ); ?></title></circle>
			<?php endforeach; ?>
			<text x="24" y="14" class="nx-report-max"><?php echo esc_html( $currency( $chart['max'] ) ); ?></text>
		</svg>
		<details class="nx-report-fallback">
			<summary><?php esc_html_e( 'Show the same numbers as a table', 'nexora' ); ?></summary>
			<table class="nx-report-table">
				<thead><tr><th scope="col"><?php esc_html_e( 'Day', 'nexora' ); ?></th><th scope="col"><?php esc_html_e( 'Your earnings', 'nexora' ); ?></th></tr></thead>
				<tbody>
					<?php foreach ( $data['series'] as $nx_p ) : ?>
						<tr><td><?php echo esc_html( $nx_p['date'] ); ?></td><td><?php echo esc_html( $currency( $nx_p['earnings'] ) ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</details>
	<?php endif; ?>

	<h4><?php esc_html_e( 'Best sellers', 'nexora' ); ?></h4>
	<?php if ( ! $data['products'] ) : ?>
		<p class="nx-report-empty"><?php esc_html_e( 'No counted sales in this period.', 'nexora' ); ?></p>
	<?php else : ?>
		<table class="nx-report-table nx-report-products">
			<thead><tr><th scope="col"><?php esc_html_e( 'Product', 'nexora' ); ?></th><th scope="col"><?php esc_html_e( 'Units', 'nexora' ); ?></th><th scope="col"><?php esc_html_e( 'Your earnings', 'nexora' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $data['products'] as $nx_p ) : ?>
					<tr><td><?php echo esc_html( $nx_p['name'] ); ?> <small><?php echo esc_html( $nx_p['sku'] ); ?></small></td><td><?php echo (int) $nx_p['units']; ?></td><td><?php echo esc_html( $currency( $nx_p['earnings'] ) ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h4><?php esc_html_e( 'Orders in this period', 'nexora' ); ?></h4>
	<?php if ( ! $data['orders'] ) : ?>
		<p class="nx-report-empty"><?php esc_html_e( 'No orders in this period.', 'nexora' ); ?></p>
	<?php else : ?>
		<table class="nx-report-table nx-report-orders">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Order', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Your products', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Commission', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Your earning', 'nexora' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['orders'] as $nx_o ) : ?>
					<tr class="nx-report-order">
						<td>#<?php echo esc_html( $nx_o['number'] ); ?></td>
						<td><?php echo esc_html( $nx_o['date'] ); ?></td>
						<td>
							<?php echo esc_html( $nx_o['status_label'] ); ?>
							<?php if ( $nx_o['awaiting'] ) : ?>
								<small><?php esc_html_e( '(not counted yet)', 'nexora' ); ?></small>
							<?php elseif ( ! $nx_o['counted'] ) : ?>
								<small><?php esc_html_e( '(not counted)', 'nexora' ); ?></small>
							<?php endif; ?>
						</td>
						<td>
							<ul class="nx-report-lines">
								<?php foreach ( $nx_o['lines'] as $nx_l ) : ?>
									<li><?php echo esc_html( $nx_l['name'] ); ?> &times; <?php echo (int) $nx_l['qty']; ?> @ <?php echo esc_html( $currency( $nx_l['price'] ) ); ?></li>
								<?php endforeach; ?>
							</ul>
						</td>
						<td><?php echo esc_html( $currency( $nx_o['totals']['commission'] ) ); ?></td>
						<td><?php echo esc_html( $currency( $nx_o['totals']['earning'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $pager ) : ?>
			<nav class="nx-report-pager" aria-label="<?php esc_attr_e( 'Orders pages', 'nexora' ); ?>">
				<?php foreach ( $pager as $nx_pg ) : ?>
					<a class="nx-report-page<?php echo $nx_pg['current'] ? ' is-current' : ''; ?>" href="<?php echo esc_url( $nx_pg['url'] ); ?>"<?php echo $nx_pg['current'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $nx_pg['label'] ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</section>
