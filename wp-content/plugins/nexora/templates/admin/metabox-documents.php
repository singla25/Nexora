<?php /** @var array[] $docs  rows: key, label, id, url */ ?>
<?php foreach ( $docs as $doc ) : ?>

			<div class="profile-upload-box">
				<label><strong><?php echo esc_html( $doc['label'] ); ?></strong></label><br>

				<img src="<?php echo esc_url( $doc['url'] ); ?>"
					class="profile-preview"
					style="max-width:150px; display:<?php echo $doc['url'] ? 'block' : 'none'; ?>; margin-bottom:10px;">

			</div>
			<hr>
<?php endforeach; ?>
