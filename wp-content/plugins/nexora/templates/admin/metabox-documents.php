<?php /** @var array[] $docs  rows: key, label, id, url */ ?>
<?php foreach ($docs as $doc): ?>

            <div class="profile-upload-box">
                <label><strong><?php echo esc_html($doc['label']); ?></strong></label><br>

                <img src="<?php echo esc_url($doc['url']); ?>"
                    class="profile-preview"
                    style="max-width:150px; display:<?php echo $doc['url'] ? 'block' : 'none'; ?>; margin-bottom:10px;">

                <input type="hidden" name="<?php echo esc_attr($doc['key']); ?>" value="<?php echo esc_attr($doc['id']); ?>">

                <button type="button" class="button upload-btn">Upload</button>
                <button type="button" class="button remove-btn" style="<?php echo $doc['url'] ? '' : 'display:none;'; ?>">Remove</button>
            </div>
            <hr>
<?php endforeach; ?>
