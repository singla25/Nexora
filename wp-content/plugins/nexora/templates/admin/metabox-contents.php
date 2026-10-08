<?php /** @var array[] $contents  rows: title, date, edit_url */ ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>

            <?php if ($contents): foreach ($contents as $content): ?>

                <tr>
                    <td><?php echo esc_html($content['title']); ?></td>

                    <td><?php echo esc_html($content['date']); ?></td>

                    <td>
                        <a href="<?php echo esc_url($content['edit_url']); ?>" 
                        class="button button-primary">
                        View
                        </a>
                    </td>
                </tr>

            <?php endforeach; else: ?>

                <tr>
                    <td colspan="3" style="text-align:center;">No content found</td>
                </tr>

            <?php endif; ?>

            </tbody>
        </table>

        