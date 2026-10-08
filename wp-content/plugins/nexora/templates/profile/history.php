<?php
/** @var array[] $received  rows: status, username, name, image, date, time, link */
/** @var array[] $sent */
?>
<div class="history-wrapper">

    <!-- ===============================
        RECEIVED
    =============================== -->
    <div class="history-section">
        <h3>📥 Received Requests</h3>

        <?php if ($received): foreach ($received as $row): ?>

            <?php \Nexora\Core\View::output('profile/history-card', ['row' => $row]); ?>

        <?php endforeach; else: ?>
            <p class="history-empty">No received requests</p>
        <?php endif; ?>

    </div>

    <!-- ===============================
        SENT
    =============================== -->
    <div class="history-section">
        <h3>📤 Sent Requests</h3>

        <?php if ($sent): foreach ($sent as $row): ?>

            <?php \Nexora\Core\View::output('profile/history-card', ['row' => $row]); ?>

        <?php endforeach; else: ?>
            <p class="history-empty">No sent requests</p>
        <?php endif; ?>
    </div>
</div>
