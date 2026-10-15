<?php /** The published product format: a field table, the rules and a downloadable sample. */ ?>
<details class="nx-format-guide">
	<summary><?php esc_html_e( 'Format guide: what your API must answer', 'nexora' ); ?></summary>
	<ul class="nx-import-notes">
		<?php foreach ( \Nexora\Imports\Format_Guide::rules() as $nx_rule ) : ?>
			<li><?php echo esc_html( $nx_rule ); ?></li>
		<?php endforeach; ?>
	</ul>
	<table class="nx-import-table nx-format-table">
		<caption><?php esc_html_e( 'Each entry of "products"', 'nexora' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Field', 'nexora' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Required', 'nexora' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Type', 'nexora' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Notes', 'nexora' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( \Nexora\Imports\Format_Guide::fields() as $nx_field ) : ?>
				<tr>
					<td><code><?php echo esc_html( $nx_field[0] ); ?></code></td>
					<td><?php echo $nx_field[1] ? esc_html__( 'yes', 'nexora' ) : esc_html__( 'no', 'nexora' ); ?></td>
					<td><?php echo esc_html( $nx_field[2] ); ?></td>
					<td><?php echo esc_html( $nx_field[3] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="nx-import-label"><?php esc_html_e( 'Example answer', 'nexora' ); ?></p>
	<pre class="nx-format-sample"><code><?php echo esc_html( \Nexora\Imports\Format_Guide::sample_json() ); ?></code></pre>
	<p><a class="nx-format-download" download="nexora-products-sample.json" href="data:application/json;charset=utf-8,<?php echo esc_attr( rawurlencode( \Nexora\Imports\Format_Guide::sample_json() ) ); ?>"><?php esc_html_e( 'Download sample JSON', 'nexora' ); ?></a></p>
</details>
